import { createContext, useContext } from 'react';
import type { ApiClient, ApiMe, ApiSite, ApiTicket, ApiWorkspace } from './api';
import type { Issue } from './demo-data';

export interface WorkspaceCtx {
  me: ApiMe | null;
  workspace: ApiWorkspace | undefined;
  role: string;
  isAgency: boolean;
  isStaff: boolean;
  canVerify: boolean;
  sites: ApiSite[];
  clients: ApiClient[];
  tickets: ApiTicket[];
  loaded: boolean;
  reload: () => Promise<void>;
  openHelp: (issue: Issue, host: string) => void;
  openVerify: (site: { id: string; name: string }) => void;
  openTicket: (id: string) => void;
  report: (siteId: string, host: string) => void;
  setError: (msg: string | null) => void;
}

export const Ctx = createContext<WorkspaceCtx | null>(null);
export function useWs(): WorkspaceCtx {
  const c = useContext(Ctx);
  if (!c) throw new Error('useWs outside workspace');
  return c;
}
