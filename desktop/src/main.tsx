import { StrictMode } from 'react';
import { createRoot } from 'react-dom/client';
import '@apsw/gridwright/styles.css';
import 'katex/dist/katex.min.css';
import { App } from './App';

createRoot(document.getElementById('root')!).render(
  <StrictMode>
    <App />
  </StrictMode>
);
