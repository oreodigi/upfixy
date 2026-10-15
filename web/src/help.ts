// "Help me understand" entries (KB-001). A first few of the ≥40 issue codes
// required by KB-002; the full library will be versioned content, not code.
export interface HelpEntry {
  title: string;
  meaning: string;
  causes: string[];
  checks: string[];
  next: string;
}

export const HELP: Record<string, HelpEntry> = {
  TLS_EXPIRING: {
    title: 'SSL certificate expiring soon',
    meaning: 'The certificate that keeps visitors’ connection to your site private runs out soon. After that, browsers show a full-page security warning and most visitors leave.',
    causes: ['Automatic renewal (for example AutoSSL) is failing', 'The domain points somewhere renewal can’t verify', 'A certificate was bought manually and not renewed'],
    checks: ['Open the site in a browser and click the padlock to see the expiry date', 'Ask whether renewal is automatic on your hosting plan'],
    next: 'Ask your hosting provider to renew the certificate, or connect hosting so Upfixy can check AutoSSL status.',
  },
  HTTP_503: {
    title: 'Website temporarily unavailable (503)',
    meaning: 'The server answered, but said it can’t serve the page right now. Visitors see an error instead of your site.',
    causes: ['The hosting account hit a resource limit', 'The site is in maintenance mode', 'A recent update broke the application', 'The server is overloaded'],
    checks: ['Open the site from another network or phone', 'Ask whether anyone changed the site or plugins today'],
    next: 'We observed the 503; we can’t yet see why. Connect hosting to unlock deeper checks, or send the support message below.',
  },
  DNS_SERVFAIL: {
    title: 'DNS lookups are failing',
    meaning: 'When browsers ask where your domain lives, the answer is an error. To visitors the site looks like it doesn’t exist.',
    causes: ['Nameserver settings at the registrar are wrong', 'DNSSEC settings don’t match', 'The DNS provider is having an outage'],
    checks: ['Check that the nameservers at your registrar match your DNS provider', 'Ask whether DNS was changed recently'],
    next: 'Connect your DNS provider so Upfixy can compare the records directly.',
  },
  MONITOR_STALE: {
    title: 'No recent readings',
    meaning: 'Upfixy hasn’t received a fresh result for this site recently, so its health is unknown. This doesn’t mean the site is down.',
    causes: ['A monitoring check was delayed', 'The site is blocking our checks'],
    checks: ['Open the site yourself to see if it loads'],
    next: 'Use Refresh readings to run a new check now.',
  },
};

export function supportMessage(code: string, host: string, observedAt: Date): string {
  const h = HELP[code];
  return `Hi, Upfixy reported "${h?.title ?? code}" for ${host} at ${observedAt.toLocaleString('en-IN', { timeZone: 'Asia/Kolkata' })} IST. Could you please check this and let me know what you find?`;
}
