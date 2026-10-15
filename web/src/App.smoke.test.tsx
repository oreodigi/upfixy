// Render smoke test: the shell renders, lanes come from @upfixy/core, and
// honesty labels (Unknown / Not connected) are present. No browser needed.
import { it, expect } from 'vitest';
import { renderToString } from 'react-dom/server';
import App from './App';

it('renders the client board with computed lanes and honest labels', () => {
  const html = renderToString(<App />);
  expect(html).toMatch(/<h1>\d of 6 clients need attention<\/h1>/);
  for (const lane of ['Critical', 'Warning', 'Unknown', 'Needs setup', 'Healthy']) expect(html).toContain(lane);
  expect(html).toContain('restore not verified');
  expect(html).toContain('Last reading 47 min ago (stale)');   // stale is labelled, not "never checked"
  expect(html).not.toContain('Backup: ');                     // no doubled labels
  // A healthy client must not show every component as "Not connected".
  const anand = html.slice(html.indexOf('aria-label="Anand Chemicals'));
  expect(anand.slice(0, anand.indexOf('</article>'))).toContain('Resolving');
  expect(html).toContain('Help me understand');

  const lanes = Object.fromEntries(
    [...html.matchAll(/aria-label="([^",]+), (Critical|Warning|Unknown|Needs setup|Healthy)"/g)].map((m) => [m[1], m[2]]),
  );
  expect(lanes).toEqual({
    'Mitra Traders': 'Warning',        // SSL expiring on one of 3 sites
    'Kalaghar Jewels': 'Critical',     // fresh 503
    'Greenfield School': 'Unknown',    // 47-min-old readings: stale, not healthy
    'Anand Chemicals': 'Healthy',
    'Skyline Realty': 'Critical',      // DNS SERVFAIL
    'New Client Store': 'Needs setup', // never checked, monitor not active
  });
});
