import { Box, Stack } from '@mui/material';

interface NumberBallsProps {
  numbers: number[];
  extra?: number[];
  size?: 'small' | 'medium';
}

export function NumberBalls({ numbers, extra = [], size = 'small' }: NumberBallsProps) {
  const dim = size === 'small' ? 30 : 36;
  const fontSize = size === 'small' ? '0.78rem' : '0.88rem';

  return (
    <Stack direction="row" spacing={0.75} useFlexGap sx={{ flexWrap: 'wrap', alignItems: 'center' }}>
      {numbers.map((n) => (
        <Box
          key={`n-${n}`}
          sx={{
            width: dim,
            height: dim,
            borderRadius: '50%',
            display: 'inline-flex',
            alignItems: 'center',
            justifyContent: 'center',
            fontWeight: 700,
            fontSize,
            fontVariantNumeric: 'tabular-nums',
            color: '#fff',
            background: 'radial-gradient(circle at 32% 28%, #64b5f6 0%, #1976d2 70%, #0d47a1 100%)',
            boxShadow: '0 2px 4px rgba(0,0,0,0.22)',
            userSelect: 'none',
          }}
        >
          {n}
        </Box>
      ))}
      {extra.map((n, idx) => (
        <Box
          key={`e-${idx}-${n}`}
          sx={{
            width: dim,
            height: dim,
            borderRadius: '50%',
            display: 'inline-flex',
            alignItems: 'center',
            justifyContent: 'center',
            fontWeight: 700,
            fontSize,
            fontVariantNumeric: 'tabular-nums',
            color: '#fff',
            background: 'radial-gradient(circle at 32% 28%, #ffe082 0%, #f57c00 72%, #e65100 100%)',
            boxShadow: '0 2px 4px rgba(0,0,0,0.22)',
            userSelect: 'none',
          }}
        >
          +{n}
        </Box>
      ))}
    </Stack>
  );
}
