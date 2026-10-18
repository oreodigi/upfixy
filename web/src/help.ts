// "Help me understand" (KB-001/KB-002). The library is one versioned file
// shared with the API and alert emails: packages/core/issues/issues.json.
import library from '../../packages/core/issues/issues.json';

export interface HelpEntry {
  kind: string;
  severity: string;
  title: string;
  meaning: string;
  causes: string[];
  checks: string[];
  next: string;
}

export const HELP_VERSION: string = library.version;
export const HELP: Record<string, HelpEntry> = library.issues;

export function supportMessage(code: string, host: string, observedAt: Date): string {
  const h = HELP[code];
  return `Hi, Upfixy reported "${h?.title ?? code}" for ${host} at ${observedAt.toLocaleString('en-IN', { timeZone: 'Asia/Kolkata' })} IST. Could you please check this and let me know what you find?`;
}
