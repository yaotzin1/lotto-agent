use rand::RngCore;
use serde::Serialize;
use std::collections::VecDeque;
use std::fs;
use std::io::{BufRead, BufReader, Read, Write};
use std::net::{TcpListener, TcpStream};
use std::path::{Path, PathBuf};
use std::process::{Child, Command, Stdio};
use std::sync::{Arc, Mutex};
use std::thread;
use std::time::{Duration, Instant, SystemTime, UNIX_EPOCH};
use tauri::{AppHandle, Emitter, Manager, RunEvent, State};

#[cfg(target_os = "windows")]
use std::os::windows::process::CommandExt;

#[cfg(target_os = "windows")]
const CREATE_NO_WINDOW: u32 = 0x08000000;

const MAX_STDERR_BYTES: usize = 4096;

#[derive(Clone, Serialize)]
pub struct BackendConfig {
    pub base_url: String,
    pub token: String,
}

#[derive(Clone, Serialize)]
#[serde(rename_all = "snake_case")]
pub enum SidecarMode {
    Sidecar,
    DevExternal,
}

#[derive(Clone, Serialize)]
#[serde(rename_all = "snake_case")]
pub enum SidecarRunState {
    Starting,
    Ready,
    Failed,
    Exited,
}

#[derive(Clone, Serialize)]
#[serde(tag = "kind")]
pub enum SidecarError {
    PhpNotFound { message: String },
    PermissionDenied { message: String },
    VersionTooOld { found: String, required: String },
    ProbeFailed { message: String },
    DataDirNotWritable { path: String, message: String },
    SpawnFailed { message: String },
    HealthTimeout { message: String },
    Exited { code: Option<i32>, signal: Option<i32>, hint: Option<String> },
    DevBackendUnreachable { url: String },
    DevBackendUnauthorized { url: String },
}

#[derive(Clone, Serialize)]
pub struct SidecarDiagnostics {
    pub mode: SidecarMode,
    pub state: SidecarRunState,
    pub php_path: Option<String>,
    pub php_source: Option<String>,
    pub php_version: Option<String>,
    pub error: Option<SidecarError>,
    pub exit_code: Option<i32>,
    pub signal: Option<i32>,
    pub stderr_tail: Vec<String>,
    pub base_url: String,
    pub data_dir: Option<String>,
    pub checked_at: u64,
}

struct SidecarState {
    config: Mutex<BackendConfig>,
    child: Mutex<Option<Child>>,
    diagnostics: Arc<Mutex<SidecarDiagnostics>>,
    stderr_buf: Arc<Mutex<VecDeque<String>>>,
}

impl SidecarState {
    fn terminate(&self) {
        if let Ok(mut guard) = self.child.lock() {
            if let Some(mut child) = guard.take() {
                let _ = child.kill();
                let _ = child.wait();
            }
        }
    }
}

fn now_unix_secs() -> u64 {
    SystemTime::now()
        .duration_since(UNIX_EPOCH)
        .map(|d| d.as_secs())
        .unwrap_or(0)
}

fn redact_token(line: &str, token: &str) -> String {
    if token.is_empty() {
        return line.to_string();
    }
    line.replace(token, "[REDACTED]")
}

fn push_stderr_line(buf: &Arc<Mutex<VecDeque<String>>>, line: String) {
    if let Ok(mut q) = buf.lock() {
        q.push_back(line);
        let mut total: usize = q.iter().map(|s| s.len() + 1).sum();
        while total > MAX_STDERR_BYTES && q.len() > 1 {
            if let Some(removed) = q.pop_front() {
                total = total.saturating_sub(removed.len() + 1);
            }
        }
    }
}

fn snapshot_stderr(buf: &Arc<Mutex<VecDeque<String>>>) -> Vec<String> {
    buf.lock()
        .map(|q| q.iter().cloned().collect())
        .unwrap_or_default()
}

fn emit_diagnostics(app: &AppHandle, diag_arc: &Arc<Mutex<SidecarDiagnostics>>) {
    if let Ok(diag) = diag_arc.lock() {
        let _ = app.emit("sidecar-status", diag.clone());
    }
}

#[tauri::command]
fn get_backend_config(state: State<'_, SidecarState>) -> BackendConfig {
    state
        .config
        .lock()
        .map(|c| c.clone())
        .unwrap_or(BackendConfig {
            base_url: "http://127.0.0.1:8000".to_string(),
            token: String::new(),
        })
}

#[tauri::command]
fn get_sidecar_diagnostics(state: State<'_, SidecarState>) -> SidecarDiagnostics {
    let stderr_tail = snapshot_stderr(&state.stderr_buf);
    state
        .diagnostics
        .lock()
        .map(|mut d| {
            d.stderr_tail = stderr_tail;
            d.clone()
        })
        .unwrap_or_else(|_| SidecarDiagnostics {
            mode: SidecarMode::Sidecar,
            state: SidecarRunState::Failed,
            php_path: None,
            php_source: None,
            php_version: None,
            error: Some(SidecarError::ProbeFailed {
                message: "Diagnostics mutex poisoned".to_string(),
            }),
            exit_code: None,
            signal: None,
            stderr_tail: vec![],
            base_url: String::new(),
            data_dir: None,
            checked_at: now_unix_secs(),
        })
}

#[tauri::command]
fn retry_sidecar(app: AppHandle, state: State<'_, SidecarState>) -> SidecarDiagnostics {
    state.terminate();
    if let Ok(mut q) = state.stderr_buf.lock() {
        q.clear();
    }

    let (port, token, base_url) = {
        let p = pick_ephemeral_port();
        let t = generate_bearer_token();
        let u = format!("http://127.0.0.1:{}", p);
        if let Ok(mut cfg) = state.config.lock() {
            cfg.base_url = u.clone();
            cfg.token = t.clone();
        }
        (p, t, u)
    };

    start_backend_pipeline(
        app,
        port,
        token,
        base_url,
        state.diagnostics.clone(),
        state.stderr_buf.clone(),
        &state.child,
        &state.config,
    );

    get_sidecar_diagnostics(state)
}

fn pick_ephemeral_port() -> u16 {
    TcpListener::bind("127.0.0.1:0")
        .and_then(|listener| listener.local_addr())
        .map(|addr| addr.port())
        .unwrap_or(18749)
}

fn generate_bearer_token() -> String {
    let mut bytes = [0u8; 32];
    rand::thread_rng().fill_bytes(&mut bytes);
    bytes.iter().map(|b| format!("{:02x}", b)).collect()
}

fn resolve_php_app_dir(app: &AppHandle) -> PathBuf {
    if let Ok(res_dir) = app.path().resource_dir() {
        let bundled = res_dir.join("php-app");
        if bundled.join("public").join("index.php").exists() {
            return bundled;
        }
    }

    PathBuf::from(env!("CARGO_MANIFEST_DIR"))
        .parent()
        .and_then(Path::parent)
        .map(Path::to_path_buf)
        .unwrap_or_else(|| PathBuf::from("../.."))
}

fn resolve_php_binary(app: &AppHandle) -> (PathBuf, String) {
    let bin_name = if cfg!(target_os = "windows") {
        "php.exe"
    } else {
        "php"
    };

    if let Ok(exe_path) = std::env::current_exe() {
        if let Some(exe_dir) = exe_path.parent() {
            let candidate = exe_dir.join(bin_name);
            if candidate.exists() {
                return (candidate, "bundled_exe_dir".to_string());
            }
        }
    }

    if let Ok(res_dir) = app.path().resource_dir() {
        let candidate = res_dir.join("binaries").join(bin_name);
        if candidate.exists() {
            return (candidate, "bundled_resource_dir".to_string());
        }
    }

    (PathBuf::from(bin_name), "system_path".to_string())
}

fn parse_semver_triplet(raw: &str) -> Option<(u32, u32, u32)> {
    let clean = raw.trim().split(['-', '+']).next()?;
    let mut parts = clean.split('.');
    let maj = parts.next()?.parse::<u32>().ok()?;
    let min = parts.next().unwrap_or("0").parse::<u32>().ok()?;
    let pat = parts.next().unwrap_or("0").parse::<u32>().ok()?;
    Some((maj, min, pat))
}

fn probe_php_version(php_bin: &Path) -> Result<String, SidecarError> {
    let mut cmd = Command::new(php_bin);
    cmd.arg("-r").arg("echo PHP_VERSION;");

    if let Some(dir) = php_bin.parent() {
        if dir.join("php.ini").exists() {
            cmd.env("PHPRC", dir);
        }
    }

    #[cfg(target_os = "windows")]
    cmd.creation_flags(CREATE_NO_WINDOW);

    match cmd.output() {
        Ok(out) => {
            if !out.status.success() {
                let err_txt = String::from_utf8_lossy(&out.stderr).trim().to_string();
                return Err(SidecarError::ProbeFailed {
                    message: if err_txt.is_empty() {
                        format!("PHP probe exited with {:?}", out.status.code())
                    } else {
                        err_txt
                    },
                });
            }
            let version = String::from_utf8_lossy(&out.stdout).trim().to_string();
            match parse_semver_triplet(&version) {
                Some((maj, min, _)) if (maj, min) >= (8, 5) => Ok(version),
                Some(_) => Err(SidecarError::VersionTooOld {
                    found: version,
                    required: "8.5.0".to_string(),
                }),
                None => Err(SidecarError::ProbeFailed {
                    message: format!("Unrecognized PHP_VERSION output: '{}'", version),
                }),
            }
        }
        Err(e) => match e.kind() {
            std::io::ErrorKind::NotFound => Err(SidecarError::PhpNotFound {
                message: format!("PHP binary not found at {}", php_bin.display()),
            }),
            std::io::ErrorKind::PermissionDenied => Err(SidecarError::PermissionDenied {
                message: format!("Permission denied executing {}", php_bin.display()),
            }),
            _ => Err(SidecarError::ProbeFailed {
                message: e.to_string(),
            }),
        },
    }
}

fn prepare_app_data_dirs(app_data_dir: &Path, php_app_dir: &Path) -> Result<(), SidecarError> {
    for sub in [
        app_data_dir.join("data").join("draws"),
        app_data_dir.join("var").join("draw-history"),
        app_data_dir.join("var").join("cache"),
        app_data_dir.join("var").join("log"),
    ] {
        if let Err(e) = fs::create_dir_all(&sub) {
            return Err(SidecarError::DataDirNotWritable {
                path: sub.display().to_string(),
                message: e.to_string(),
            });
        }
    }

    let probe_file = app_data_dir.join(".write_probe");
    if let Err(e) = fs::write(&probe_file, b"ok") {
        return Err(SidecarError::DataDirNotWritable {
            path: app_data_dir.display().to_string(),
            message: e.to_string(),
        });
    }
    let _ = fs::remove_file(&probe_file);

    let target_seed = app_data_dir.join("data").join("lotto_draws.json");
    let bundled_seed = php_app_dir.join("data").join("lotto_draws.json");
    if !target_seed.exists() && bundled_seed.exists() {
        let _ = fs::copy(&bundled_seed, &target_seed);
    }

    Ok(())
}

enum HttpHealth {
    Ok,
    Unauthorized,
    Unreachable,
}

fn probe_http_health(addr: &str, token: &str) -> HttpHealth {
    if let Ok(sock) = addr.parse() {
        if let Ok(mut stream) = TcpStream::connect_timeout(&sock, Duration::from_millis(500)) {
            let _ = stream.set_read_timeout(Some(Duration::from_millis(1200)));
            let auth_header = if token.is_empty() {
                String::new()
            } else {
                format!("Authorization: Bearer {}\r\n", token)
            };
            let req = format!(
                "GET /api/health HTTP/1.1\r\nHost: {}\r\n{}Connection: close\r\n\r\n",
                addr, auth_header
            );
            if stream.write_all(req.as_bytes()).is_ok() {
                let mut buf = [0u8; 512];
                if let Ok(n) = stream.read(&mut buf) {
                    let head = String::from_utf8_lossy(&buf[..n]);
                    if head.contains("200 OK") {
                        return HttpHealth::Ok;
                    }
                    if head.contains("401") {
                        return HttpHealth::Unauthorized;
                    }
                }
            }
        }
    }
    HttpHealth::Unreachable
}

fn wait_for_health(port: u16, token: &str, timeout: Duration) -> HttpHealth {
    let deadline = Instant::now() + timeout;
    let addr = format!("127.0.0.1:{}", port);

    while Instant::now() < deadline {
        match probe_http_health(&addr, token) {
            HttpHealth::Ok => return HttpHealth::Ok,
            HttpHealth::Unauthorized => return HttpHealth::Unauthorized,
            HttpHealth::Unreachable => thread::sleep(Duration::from_millis(150)),
        }
    }

    HttpHealth::Unreachable
}

#[cfg(unix)]
fn extract_signal(status: &std::process::ExitStatus) -> Option<i32> {
    use std::os::unix::process::ExitStatusExt;
    status.signal()
}

#[cfg(not(unix))]
fn extract_signal(_status: &std::process::ExitStatus) -> Option<i32> {
    None
}

fn spawn_php_sidecar(
    app: &AppHandle,
    port: u16,
    token: &str,
    diag_arc: &Arc<Mutex<SidecarDiagnostics>>,
    stderr_buf: &Arc<Mutex<VecDeque<String>>>,
) -> Option<Child> {
    let php_app_dir = resolve_php_app_dir(app);
    let (php_bin, php_source) = resolve_php_binary(app);
    let app_data_dir = app
        .path()
        .app_data_dir()
        .unwrap_or_else(|_| php_app_dir.join("var").join("desktop-appdata"));

    if let Ok(mut d) = diag_arc.lock() {
        d.php_path = Some(php_bin.display().to_string());
        d.php_source = Some(php_source);
        d.data_dir = Some(app_data_dir.display().to_string());
        d.checked_at = now_unix_secs();
    }

    if let Err(err) = prepare_app_data_dirs(&app_data_dir, &php_app_dir) {
        if let Ok(mut d) = diag_arc.lock() {
            d.state = SidecarRunState::Failed;
            d.error = Some(err);
        }
        emit_diagnostics(app, diag_arc);
        return None;
    }

    match probe_php_version(&php_bin) {
        Ok(ver) => {
            if let Ok(mut d) = diag_arc.lock() {
                d.php_version = Some(ver);
            }
        }
        Err(err) => {
            if let Ok(mut d) = diag_arc.lock() {
                d.state = SidecarRunState::Failed;
                d.error = Some(err);
            }
            emit_diagnostics(app, diag_arc);
            return None;
        }
    }

    let public_dir = php_app_dir.join("public");
    let router_script = public_dir.join("index.php");
    let bind_addr = format!("127.0.0.1:{}", port);

    let mut cmd = Command::new(&php_bin);
    cmd.current_dir(&php_app_dir);

    if let Some(dir) = php_bin.parent() {
        if dir.join("php.ini").exists() {
            cmd.arg("-c").arg(dir.join("php.ini"));
            cmd.env("PHPRC", dir);
        }
    }

    cmd.arg("-S")
        .arg(&bind_addr)
        .arg("-t")
        .arg(&public_dir)
        .arg(&router_script)
        .env("APP_ENV", "prod")
        .env("APP_DEBUG", "0")
        .env("LOTTO_DESKTOP_TOKEN", token)
        .env("LOTTO_APP_DATA_DIR", &app_data_dir)
        .env("LOTTO_ENV_FILE", app_data_dir.join(".env.desktop"))
        .env("LOTTO_DATA_DIR", app_data_dir.join("data"))
        .env("LOTTO_STORAGE_DIR", app_data_dir.join("var").join("draw-history"))
        .env("APP_CACHE_DIR", app_data_dir.join("var").join("cache"))
        .env("APP_LOG_DIR", app_data_dir.join("var").join("log"))
        .stdin(Stdio::null())
        .stdout(Stdio::piped())
        .stderr(Stdio::piped());

    #[cfg(target_os = "windows")]
    cmd.creation_flags(CREATE_NO_WINDOW);

    match cmd.spawn() {
        Ok(mut child) => {
            if let Some(stdout) = child.stdout.take() {
                let buf_clone = stderr_buf.clone();
                let tok = token.to_string();
                thread::spawn(move || {
                    let reader = BufReader::new(stdout);
                    for line in reader.lines().map_while(Result::ok) {
                        push_stderr_line(&buf_clone, redact_token(&line, &tok));
                    }
                });
            }
            if let Some(stderr) = child.stderr.take() {
                let buf_clone = stderr_buf.clone();
                let tok = token.to_string();
                thread::spawn(move || {
                    let reader = BufReader::new(stderr);
                    for line in reader.lines().map_while(Result::ok) {
                        push_stderr_line(&buf_clone, redact_token(&line, &tok));
                    }
                });
            }
            Some(child)
        }
        Err(e) => {
            if let Ok(mut d) = diag_arc.lock() {
                d.state = SidecarRunState::Failed;
                d.error = Some(SidecarError::SpawnFailed {
                    message: e.to_string(),
                });
            }
            emit_diagnostics(app, diag_arc);
            None
        }
    }
}

fn start_backend_pipeline(
    app: AppHandle,
    port: u16,
    token: String,
    base_url: String,
    diag_arc: Arc<Mutex<SidecarDiagnostics>>,
    stderr_buf: Arc<Mutex<VecDeque<String>>>,
    child_mutex: &Mutex<Option<Child>>,
    config_mutex: &Mutex<BackendConfig>,
) {
    #[cfg(debug_assertions)]
    {
        match probe_http_health("127.0.0.1:8000", "") {
            HttpHealth::Ok => {
                println!("[tauri-dev] Using external PHP backend at http://127.0.0.1:8000");
                if let Ok(mut cfg) = config_mutex.lock() {
                    cfg.base_url = "http://127.0.0.1:8000".to_string();
                    cfg.token = String::new();
                }
                if let Ok(mut d) = diag_arc.lock() {
                    d.mode = SidecarMode::DevExternal;
                    d.state = SidecarRunState::Ready;
                    d.base_url = "http://127.0.0.1:8000".to_string();
                    d.error = None;
                    d.checked_at = now_unix_secs();
                }
                emit_diagnostics(&app, &diag_arc);
                return;
            }
            HttpHealth::Unauthorized => {
                if let Ok(mut cfg) = config_mutex.lock() {
                    cfg.base_url = "http://127.0.0.1:8000".to_string();
                    cfg.token = String::new();
                }
                if let Ok(mut d) = diag_arc.lock() {
                    d.mode = SidecarMode::DevExternal;
                    d.state = SidecarRunState::Failed;
                    d.base_url = "http://127.0.0.1:8000".to_string();
                    d.error = Some(SidecarError::DevBackendUnauthorized {
                        url: "http://127.0.0.1:8000".to_string(),
                    });
                    d.checked_at = now_unix_secs();
                }
                emit_diagnostics(&app, &diag_arc);
                return;
            }
            HttpHealth::Unreachable => {}
        }
    }

    let spawned = spawn_php_sidecar(&app, port, &token, &diag_arc, &stderr_buf);
    if let Some(child) = spawned {
        if let Ok(mut guard) = child_mutex.lock() {
            *guard = Some(child);
        }

        let app_clone = app.clone();
        let diag_clone = diag_arc.clone();
        let stderr_clone = stderr_buf.clone();
        let tok_clone = token.clone();
        thread::spawn(move || {
            match wait_for_health(port, &tok_clone, Duration::from_secs(10)) {
                HttpHealth::Ok => {
                    if let Ok(mut d) = diag_clone.lock() {
                        d.state = SidecarRunState::Ready;
                        d.error = None;
                        d.checked_at = now_unix_secs();
                    }
                }
                HttpHealth::Unauthorized => {
                    if let Ok(mut d) = diag_clone.lock() {
                        d.state = SidecarRunState::Failed;
                        d.error = Some(SidecarError::DevBackendUnauthorized {
                            url: base_url.clone(),
                        });
                        d.checked_at = now_unix_secs();
                    }
                }
                HttpHealth::Unreachable => {
                    if let Ok(mut d) = diag_clone.lock() {
                        d.state = SidecarRunState::Failed;
                        d.stderr_tail = snapshot_stderr(&stderr_clone);
                        d.error = Some(SidecarError::HealthTimeout {
                            message: format!("PHP sidecar did not respond on {} within 10s", base_url),
                        });
                        d.checked_at = now_unix_secs();
                    }
                }
            }
            emit_diagnostics(&app_clone, &diag_clone);
        });
    } else if cfg!(debug_assertions) {
        // Sidecar failed in dev mode (e.g. local PHP < 8.5); check if external Docker :8000 came up
        if let HttpHealth::Ok = probe_http_health("127.0.0.1:8000", "") {
            if let Ok(mut cfg) = config_mutex.lock() {
                cfg.base_url = "http://127.0.0.1:8000".to_string();
                cfg.token = String::new();
            }
            if let Ok(mut d) = diag_arc.lock() {
                d.mode = SidecarMode::DevExternal;
                d.state = SidecarRunState::Ready;
                d.base_url = "http://127.0.0.1:8000".to_string();
                d.error = None;
                d.checked_at = now_unix_secs();
            }
            emit_diagnostics(&app, &diag_arc);
        }
    }

    let _ = extract_signal;
}

#[cfg_attr(mobile, tauri::mobile_entry_point)]
pub fn run() {
    let port = pick_ephemeral_port();
    let token = generate_bearer_token();
    let base_url = format!("http://127.0.0.1:{}", port);

    let initial_diag = SidecarDiagnostics {
        mode: SidecarMode::Sidecar,
        state: SidecarRunState::Starting,
        php_path: None,
        php_source: None,
        php_version: None,
        error: None,
        exit_code: None,
        signal: None,
        stderr_tail: vec![],
        base_url: base_url.clone(),
        data_dir: None,
        checked_at: now_unix_secs(),
    };

    let state = SidecarState {
        config: Mutex::new(BackendConfig {
            base_url: base_url.clone(),
            token: token.clone(),
        }),
        child: Mutex::new(None),
        diagnostics: Arc::new(Mutex::new(initial_diag)),
        stderr_buf: Arc::new(Mutex::new(VecDeque::new())),
    };

    let app = tauri::Builder::default()
        .manage(state)
        .setup(move |app| {
            let handle = app.handle().clone();
            let sidecar_state = handle.state::<SidecarState>();
            start_backend_pipeline(
                handle.clone(),
                port,
                token,
                base_url,
                sidecar_state.diagnostics.clone(),
                sidecar_state.stderr_buf.clone(),
                &sidecar_state.child,
                &sidecar_state.config,
            );
            Ok(())
        })
        .invoke_handler(tauri::generate_handler![
            get_backend_config,
            get_sidecar_diagnostics,
            retry_sidecar
        ])
        .build(tauri::generate_context!())
        .expect("error while building Lotto Agent Tauri application");

    app.run(|app_handle, event| {
        if let RunEvent::Exit = event {
            if let Some(state) = app_handle.try_state::<SidecarState>() {
                state.terminate();
            }
        }
    });
}
