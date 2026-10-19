-- AC-UI-12: screens must say when they show demonstration data.
-- Only the schema owner (dev seed scripts) can mark a workspace as demo; the API can read the
-- flag but never set or clear it, so a real workspace can't be disguised as a demo or vice versa.
ALTER TABLE workspaces ADD COLUMN is_demo boolean NOT NULL DEFAULT false;
GRANT SELECT (is_demo) ON workspaces TO upfixy_app;
