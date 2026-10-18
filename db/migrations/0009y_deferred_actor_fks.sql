-- Deleting a workspace (NFR-14 workspace closure) cascaded into memberships
-- while rows that RECORD an actor (who issued a quote, who approved, who was
-- assigned, who invited) still pointed at those memberships. Those foreign
-- keys were checked immediately, mid-cascade, so the delete failed even though
-- the same statement was about to remove the referencing rows too.
-- Deferring the check to commit keeps the guarantee (no dangling actor) and
-- lets a whole workspace be removed in one statement.
ALTER TABLE quotes      ALTER CONSTRAINT quotes_workspace_id_issued_by_fkey      DEFERRABLE INITIALLY DEFERRED;
ALTER TABLE approvals   ALTER CONSTRAINT approvals_workspace_id_approved_by_fkey DEFERRABLE INITIALLY DEFERRED;
ALTER TABLE tickets     ALTER CONSTRAINT tickets_workspace_id_assignee_id_fkey   DEFERRABLE INITIALLY DEFERRED;
ALTER TABLE invitations ALTER CONSTRAINT invitations_workspace_id_invited_by_fkey DEFERRABLE INITIALLY DEFERRED;
