import { useState } from 'react';
import {
  Box,
  Card,
  CardContent,
  CardHeader,
  Divider,
  List,
  ListItemButton,
  ListItemText,
} from '@mui/material';
import desktopManualMd from '../../../docs/DESKTOP_MANUAL.md?raw';
import howToPlayMd from '../../../docs/HOW_TO_PLAY.md?raw';
import strategyAnalysisMd from '../../../docs/STRATEGY_ANALYSIS.md?raw';
import desktopSpecMd from '../../../docs/desktop-spec.md?raw';
import type { Translations } from '../i18n';
import { MarkdownView } from './MarkdownView';

interface ManualDoc {
  id: string;
  title: string;
  subtitle: string;
  content: string;
}

interface ManualTabProps {
  tr: Translations;
}

export function ManualTab({ tr }: ManualTabProps) {
  const docs: ManualDoc[] = [
    {
      id: 'desktop-manual',
      title: tr.manualDocDesktopTitle,
      subtitle: 'docs/DESKTOP_MANUAL.md',
      content: desktopManualMd,
    },
    {
      id: 'how-to-play',
      title: tr.manualDocHowToPlayTitle,
      subtitle: 'docs/HOW_TO_PLAY.md',
      content: howToPlayMd,
    },
    {
      id: 'strategy-analysis',
      title: tr.manualDocStrategyTitle,
      subtitle: 'docs/STRATEGY_ANALYSIS.md',
      content: strategyAnalysisMd,
    },
    {
      id: 'desktop-spec',
      title: tr.manualDocSpecTitle,
      subtitle: 'docs/desktop-spec.md',
      content: desktopSpecMd,
    },
  ];

  const [activeId, setActiveId] = useState<string>('desktop-manual');
  const current = docs.find((d) => d.id === activeId) ?? docs[0];

  return (
    <Box
      sx={{
        display: 'grid',
        gridTemplateColumns: { xs: '1fr', md: '280px 1fr' },
        gap: 2.5,
        alignItems: 'start',
      }}
    >
      <Card variant="outlined">
        <CardHeader title={tr.manualSidebarTitle} titleTypographyProps={{ variant: 'subtitle1', fontWeight: 600 }} />
        <Divider />
        <List disablePadding>
          {docs.map((doc) => (
            <ListItemButton
              key={doc.id}
              selected={doc.id === current.id}
              onClick={() => setActiveId(doc.id)}
            >
              <ListItemText primary={doc.title} secondary={doc.subtitle} />
            </ListItemButton>
          ))}
        </List>
      </Card>

      <Card variant="outlined">
        <CardHeader
          title={current.title}
          subheader={current.subtitle}
          titleTypographyProps={{ variant: 'h6' }}
        />
        <Divider />
        <CardContent>
          <MarkdownView content={current.content} />
        </CardContent>
      </Card>
    </Box>
  );
}
