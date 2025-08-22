import React from 'react';
import { createRoot } from 'react-dom/client';
import { Container, CssBaseline, Typography, Box, Button } from '@mui/material';

function App() {
  return (
    <React.StrictMode>
      <CssBaseline />
      <Container maxWidth="md">
        <Box sx={{ py: 4 }}>
          <Typography variant="h4" gutterBottom>
            Osobisty Generator Systemów v4.3 (Frontend)
          </Typography>
          <Typography gutterBottom>
            Backend i logika przeniesiona do Symfony. Ta strona ładuje zasoby przez Encore.
          </Typography>
          <Box sx={{ mt: 2, display: 'flex', gap: 2 }}>
            <Button variant="contained" href="#" disabled>Generator (UI w przygotowaniu)</Button>
            <Button variant="outlined" href="#" disabled>Weryfikator (UI w przygotowaniu)</Button>
          </Box>
        </Box>
      </Container>
    </React.StrictMode>
  );
}

const root = createRoot(document.getElementById('root')!);
root.render(<App />);
