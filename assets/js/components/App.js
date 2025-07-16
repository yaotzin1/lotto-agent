import React, { useState } from 'react';
import { Container, Typography, Paper, Tabs, Tab, Box } from '@material-ui/core';
import { makeStyles } from '@material-ui/core/styles';
import LottoGame from './LottoGame';
import LottoSimulation from './LottoSimulation';

const useStyles = makeStyles((theme) => ({
    root: {
        flexGrow: 1,
        marginTop: theme.spacing(4),
    },
    paper: {
        padding: theme.spacing(3),
        marginBottom: theme.spacing(3),
    },
    title: {
        marginBottom: theme.spacing(3),
        color: theme.palette.primary.main,
    },
    tabContent: {
        padding: theme.spacing(3),
    },
}));

function TabPanel(props) {
    const { children, value, index, ...other } = props;

    return (
        <div
            role="tabpanel"
            hidden={value !== index}
            id={`simple-tabpanel-${index}`}
            aria-labelledby={`simple-tab-${index}`}
            {...other}
        >
            {value === index && (
                <Box p={3}>
                    {children}
                </Box>
            )}
        </div>
    );
}

function App() {
    const classes = useStyles();
    const [tabValue, setTabValue] = useState(0);

    const handleTabChange = (event, newValue) => {
        setTabValue(newValue);
    };

    return (
        <Container maxWidth="lg" className={classes.root}>
            <Paper className={classes.paper} elevation={3}>
                <Typography variant="h3" component="h1" align="center" className={classes.title}>
                    Polish Lotto Simulator
                </Typography>
                
                <Tabs
                    value={tabValue}
                    onChange={handleTabChange}
                    indicatorColor="primary"
                    textColor="primary"
                    centered
                >
                    <Tab label="Play Lotto" />
                    <Tab label="Run Simulation" />
                </Tabs>

                <TabPanel value={tabValue} index={0}>
                    <LottoGame />
                </TabPanel>
                
                <TabPanel value={tabValue} index={1}>
                    <LottoSimulation />
                </TabPanel>
            </Paper>
        </Container>
    );
}

export default App;