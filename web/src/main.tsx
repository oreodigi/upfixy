import { StrictMode, Suspense, lazy } from 'react';
import { createRoot } from 'react-dom/client';
import LiveApp from './LiveApp';
import './styles.css';
import './ui/theme.css';

// Live API by default; add ?demo to the URL to see the synthetic demo board.
const demo = new URLSearchParams(window.location.search).has('demo');
const App = lazy(() => import('./App'));

createRoot(document.getElementById('root')!).render(
  <StrictMode>{demo ? <Suspense fallback={null}><App /></Suspense> : <LiveApp />}</StrictMode>,
);
