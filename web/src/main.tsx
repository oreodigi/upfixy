import { StrictMode } from 'react';
import { createRoot } from 'react-dom/client';
import App from './App';
import LiveApp from './LiveApp';
import './styles.css';

// Live API by default; add ?demo to the URL to see the synthetic demo board.
const demo = new URLSearchParams(window.location.search).has('demo');

createRoot(document.getElementById('root')!).render(
  <StrictMode>{demo ? <App /> : <LiveApp />}</StrictMode>,
);
