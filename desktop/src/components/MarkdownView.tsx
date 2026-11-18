import { type ReactNode } from 'react';
import katex from 'katex';
import {
  Alert,
  Box,
  Divider,
  Link,
  Paper,
  Table,
  TableBody,
  TableCell,
  TableContainer,
  TableHead,
  TableRow,
  Typography,
} from '@mui/material';

function renderMathHtml(expr: string, displayMode: boolean): string {
  return katex.renderToString(expr.trim(), {
    displayMode,
    throwOnError: false,
    strict: false,
  });
}

function renderInline(text: string): ReactNode[] {
  const tokens: ReactNode[] = [];
  const regex = /(`[^`]+`|\$\$[^$]+\$\$|\$[^$\n]+\$|\*\*[^*]+\*\*|\*[^*]+\*|\[([^\]]+)\]\(([^)]+)\))/g;
  let lastIndex = 0;
  let match: RegExpExecArray | null;
  let key = 0;

  while ((match = regex.exec(text)) !== null) {
    if (match.index > lastIndex) {
      tokens.push(text.slice(lastIndex, match.index));
    }
    const token = match[0];
    if (token.startsWith('$$') && token.endsWith('$$')) {
      tokens.push(
        <Box
          key={key++}
          component="span"
          sx={{ display: 'inline-block', mx: 0.4, verticalAlign: 'middle' }}
          dangerouslySetInnerHTML={{ __html: renderMathHtml(token.slice(2, -2), false) }}
        />
      );
    } else if (token.startsWith('$') && token.endsWith('$')) {
      tokens.push(
        <Box
          key={key++}
          component="span"
          dangerouslySetInnerHTML={{ __html: renderMathHtml(token.slice(1, -1), false) }}
        />
      );
    } else if (token.startsWith('`')) {
      tokens.push(
        <Box
          key={key++}
          component="code"
          sx={{
            px: 0.6,
            py: 0.15,
            borderRadius: 1,
            bgcolor: 'action.hover',
            fontFamily: 'monospace',
            fontSize: '0.85em',
          }}
        >
          {token.slice(1, -1)}
        </Box>
      );
    } else if (token.startsWith('**')) {
      tokens.push(<strong key={key++}>{token.slice(2, -2)}</strong>);
    } else if (token.startsWith('*')) {
      tokens.push(<em key={key++}>{token.slice(1, -1)}</em>);
    } else if (match[2] && match[3]) {
      const href = match[3];
      tokens.push(
        href.startsWith('http') ? (
          <Link key={key++} href={href} target="_blank" rel="noreferrer">
            {match[2]}
          </Link>
        ) : (
          <strong key={key++}>{match[2]}</strong>
        )
      );
    }
    lastIndex = regex.lastIndex;
  }

  if (lastIndex < text.length) {
    tokens.push(text.slice(lastIndex));
  }
  return tokens;
}

export function MarkdownView({ content }: { content: string }) {
  const lines = content.replace(/\r\n/g, '\n').split('\n');
  const elements: ReactNode[] = [];
  let i = 0;
  let key = 0;

  while (i < lines.length) {
    const line = lines[i];

    if (line.trim() === '') {
      i++;
      continue;
    }

    if (/^---+$/.test(line.trim())) {
      elements.push(<Divider key={key++} sx={{ my: 2 }} />);
      i++;
      continue;
    }

    if (line.trim().startsWith('$$')) {
      const trimmed = line.trim();
      if (trimmed.length > 4 && trimmed.endsWith('$$')) {
        elements.push(
          <Box
            key={key++}
            sx={{ my: 1.5, overflowX: 'auto', textAlign: 'center' }}
            dangerouslySetInnerHTML={{ __html: renderMathHtml(trimmed.slice(2, -2), true) }}
          />
        );
        i++;
        continue;
      }
      const mathLines: string[] = [trimmed.slice(2)];
      i++;
      while (i < lines.length && !lines[i].trim().endsWith('$$')) {
        mathLines.push(lines[i]);
        i++;
      }
      if (i < lines.length) {
        mathLines.push(lines[i].trim().slice(0, -2));
        i++;
      }
      elements.push(
        <Box
          key={key++}
          sx={{ my: 1.5, overflowX: 'auto', textAlign: 'center' }}
          dangerouslySetInnerHTML={{ __html: renderMathHtml(mathLines.join('\n'), true) }}
        />
      );
      continue;
    }

    if (line.startsWith('```')) {
      const codeLines: string[] = [];
      i++;
      while (i < lines.length && !lines[i].startsWith('```')) {
        codeLines.push(lines[i]);
        i++;
      }
      i++;
      elements.push(
        <Paper
          key={key++}
          variant="outlined"
          sx={{
            p: 1.5,
            my: 1.5,
            bgcolor: 'action.hover',
            overflowX: 'auto',
          }}
        >
          <Box component="pre" sx={{ m: 0, fontFamily: 'monospace', fontSize: '0.82rem' }}>
            {codeLines.join('\n')}
          </Box>
        </Paper>
      );
      continue;
    }

    const headingMatch = /^(#{1,4})\s+(.*)$/.exec(line);
    if (headingMatch) {
      const level = headingMatch[1].length;
      const variant =
        level === 1 ? 'h5' : level === 2 ? 'h6' : level === 3 ? 'subtitle1' : 'subtitle2';
      elements.push(
        <Typography
          key={key++}
          variant={variant}
          sx={{ fontWeight: 700, mt: level === 1 ? 0 : 2.5, mb: 1 }}
        >
          {renderInline(headingMatch[2])}
        </Typography>
      );
      i++;
      continue;
    }

    if (line.startsWith('>')) {
      const quoteLines: string[] = [];
      while (i < lines.length && lines[i].startsWith('>')) {
        quoteLines.push(lines[i].replace(/^>\s?/, ''));
        i++;
      }
      elements.push(
        <Alert key={key++} severity="info" variant="outlined" sx={{ my: 1.5 }}>
          <Typography variant="body2">{renderInline(quoteLines.join(' '))}</Typography>
        </Alert>
      );
      continue;
    }

    if (line.includes('|') && i + 1 < lines.length && /^\s*\|?[\s:-]+\|/.test(lines[i + 1])) {
      const parseRow = (rowLine: string) =>
        rowLine
          .trim()
          .replace(/^\|/, '')
          .replace(/\|$/, '')
          .split('|')
          .map((c) => c.trim());

      const headers = parseRow(line);
      i += 2;
      const bodyRows: string[][] = [];
      while (i < lines.length && lines[i].includes('|') && lines[i].trim() !== '') {
        bodyRows.push(parseRow(lines[i]));
        i++;
      }
      elements.push(
        <TableContainer key={key++} component={Paper} variant="outlined" sx={{ my: 2 }}>
          <Table size="small">
            <TableHead>
              <TableRow>
                {headers.map((h, idx) => (
                  <TableCell key={idx} sx={{ fontWeight: 700 }}>
                    {renderInline(h)}
                  </TableCell>
                ))}
              </TableRow>
            </TableHead>
            <TableBody>
              {bodyRows.map((r, rIdx) => (
                <TableRow key={rIdx}>
                  {headers.map((_, cIdx) => (
                    <TableCell key={cIdx}>{renderInline(r[cIdx] ?? '')}</TableCell>
                  ))}
                </TableRow>
              ))}
            </TableBody>
          </Table>
        </TableContainer>
      );
      continue;
    }

    if (/^\s*([-*]|\d+\.)\s+/.test(line)) {
      const isOrdered = /^\s*\d+\./.test(line);
      const items: string[] = [];
      while (i < lines.length && /^\s*([-*]|\d+\.)\s+/.test(lines[i])) {
        items.push(lines[i].replace(/^\s*([-*]|\d+\.)\s+/, ''));
        i++;
      }
      elements.push(
        <Box
          key={key++}
          component={isOrdered ? 'ol' : 'ul'}
          sx={{ pl: 3, my: 1, '& li': { mb: 0.5 } }}
        >
          {items.map((item, idx) => (
            <li key={idx}>
              <Typography variant="body2" component="span">
                {renderInline(item)}
              </Typography>
            </li>
          ))}
        </Box>
      );
      continue;
    }

    const paraLines: string[] = [];
    while (
      i < lines.length &&
      lines[i].trim() !== '' &&
      !lines[i].startsWith('#') &&
      !lines[i].startsWith('```') &&
      !lines[i].startsWith('>') &&
      !/^---+$/.test(lines[i].trim()) &&
      !/^\s*([-*]|\d+\.)\s+/.test(lines[i])
    ) {
      paraLines.push(lines[i]);
      i++;
    }
    elements.push(
      <Typography key={key++} variant="body2" sx={{ mb: 1.5, lineHeight: 1.65 }}>
        {renderInline(paraLines.join(' '))}
      </Typography>
    );
  }

  return <Box>{elements}</Box>;
}
