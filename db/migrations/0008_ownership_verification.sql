-- Ownership verification (D-07, approved) and the private-access gate.
-- FRD refs: AST-002/003, §14.1–14.2, AC-AST-002.2–.7, AC-AST-003.1.
--
-- * Public monitoring needs no proof (D-07): adding a website stays open.
-- * Private data and repairs need, for the exact host, either a verified,
--   unexpired DNS TXT / HTML-file claim, or a granted, unexpired, unrevoked
--   delegation carrying the needed permission.
-- * The API (upfixy_app) can create and revoke challenges but can never mark
--   one verified. Only the verification worker (upfixy_worker) can, and only
--   by reporting the exact token it observed on the live host.

-- ---------------------------------------------------------------- claims
ALTER TABLE ownership_claims
  ADD COLUMN challenge_expires_at timestamptz,
  ADD COLUMN created_by           uuid REFERENCES users(id),
  ADD COLUMN last_checked_at      timestamptz,
  ADD COLUMN last_check_result    text,
  ADD COLUMN check_count          integer NOT NULL DEFAULT 0,
  ADD COLUMN revoked_at           timestamptz;
UPDATE ownership_claims SET challenge_expires_at = created_at + interval '7 days' WHERE challenge_expires_at IS NULL;
ALTER TABLE ownership_claims ALTER COLUMN challenge_expires_at SET NOT NULL;
CREATE UNIQUE INDEX ownership_claims_one_pending ON ownership_claims(workspace_id, website_id, host, method)
  WHERE status = 'challenge_issued';

DROP POLICY oc_write ON ownership_claims;
REVOKE INSERT, UPDATE, DELETE ON ownership_claims FROM upfixy_app;
REVOKE SELECT ON ownership_claims FROM upfixy_app;
-- The nonce is shown to people who can see the website (it goes into their DNS/HTML anyway).
GRANT SELECT (id, workspace_id, website_id, host, method, nonce, status, verified_at, expires_at,
              challenge_expires_at, created_at, last_checked_at, last_check_result, check_count, revoked_at)
  ON ownership_claims TO upfixy_app;

CREATE FUNCTION create_ownership_challenge(p_website uuid, p_host text, p_method claim_method)
RETURNS TABLE(id uuid, host text, method claim_method, nonce text, challenge_expires_at timestamptz)
LANGUAGE plpgsql SECURITY DEFINER SET search_path = public, pg_temp AS $$
#variable_conflict use_column
DECLARE c uuid; claim ownership_claims;
BEGIN
  SELECT w.client_id INTO c FROM websites w WHERE w.id = p_website AND w.workspace_id = app_workspace_id();
  IF NOT FOUND OR NOT app_can_see_client(c) THEN
    RAISE EXCEPTION 'website not found' USING ERRCODE = 'P0002';
  END IF;
  IF NOT (app_role() IN ('owner', 'admin') OR (app_role() = 'client_owner' AND app_is_assigned(c))) THEN
    RAISE EXCEPTION 'not allowed to verify ownership' USING ERRCODE = '42501';
  END IF;
  IF NOT EXISTS (SELECT 1 FROM monitor_targets t WHERE t.website_id = p_website AND t.host = p_host) THEN
    RAISE EXCEPTION 'host is not monitored on this website' USING ERRCODE = '22023';
  END IF;
  IF p_method = 'dns_txt' AND (p_host LIKE '[%' OR p_host ~ '^[0-9.]+$') THEN
    RAISE EXCEPTION 'DNS verification needs a domain name, not an IP address' USING ERRCODE = '22023';
  END IF;

  -- Re-use a live pending challenge so repeated clicks don't rotate the token.
  SELECT * INTO claim FROM ownership_claims oc
   WHERE oc.workspace_id = app_workspace_id() AND oc.website_id = p_website AND oc.host = p_host
     AND oc.method = p_method AND oc.status = 'challenge_issued' AND oc.challenge_expires_at > now();
  IF NOT FOUND THEN
    UPDATE ownership_claims oc SET status = 'expired'
     WHERE oc.workspace_id = app_workspace_id() AND oc.website_id = p_website AND oc.host = p_host
       AND oc.method = p_method AND oc.status = 'challenge_issued';
    INSERT INTO ownership_claims(workspace_id, website_id, host, method, nonce, status, challenge_expires_at, created_by)
    VALUES (app_workspace_id(), p_website, p_host, p_method, encode(gen_random_bytes(24), 'hex'),
            'challenge_issued', now() + interval '7 days', app_user_id())
    RETURNING * INTO claim;
  END IF;
  RETURN QUERY SELECT claim.id, claim.host, claim.method, claim.nonce, claim.challenge_expires_at;
END $$;

CREATE FUNCTION revoke_ownership_claim(p_claim uuid) RETURNS void
LANGUAGE plpgsql SECURITY DEFINER SET search_path = public, pg_temp AS $$
BEGIN
  IF app_role() NOT IN ('owner', 'admin') THEN
    RAISE EXCEPTION 'only owners and admins can revoke ownership proof' USING ERRCODE = '42501';
  END IF;
  UPDATE ownership_claims SET status = 'revoked', revoked_at = now()
   WHERE id = p_claim AND workspace_id = app_workspace_id() AND status IN ('challenge_issued', 'verified');
  IF NOT FOUND THEN RAISE EXCEPTION 'claim not found' USING ERRCODE = 'P0002'; END IF;
END $$;

GRANT EXECUTE ON FUNCTION create_ownership_challenge(uuid, text, claim_method), revoke_ownership_claim(uuid) TO upfixy_app;

-- Worker side. The worker fetches the challenge, looks at the live host, then
-- reports exactly what it found. Verification succeeds only if that equals
-- the expected token for THIS claim, before the challenge expires.
CREATE FUNCTION worker_claim_for_check(p_claim uuid)
RETURNS TABLE(id uuid, host text, method claim_method, nonce text, status claim_status,
              challenge_expires_at timestamptz, scheme text, port integer)
LANGUAGE sql STABLE SECURITY DEFINER SET search_path = public, pg_temp AS $$
  SELECT c.id, c.host, c.method, c.nonce, c.status, c.challenge_expires_at, t.scheme, t.port
    FROM ownership_claims c
    JOIN LATERAL (SELECT scheme, port FROM monitor_targets t
                   WHERE t.website_id = c.website_id AND t.host = c.host
                   ORDER BY (t.scheme = 'https') DESC LIMIT 1) t ON true
   WHERE c.id = p_claim
$$;

CREATE FUNCTION worker_record_claim_check(p_claim uuid, p_found text, p_detail text) RETURNS claim_status
LANGUAGE plpgsql SECURITY DEFINER SET search_path = public, pg_temp AS $$
DECLARE c ownership_claims;
BEGIN
  SELECT * INTO c FROM ownership_claims WHERE id = p_claim FOR UPDATE;
  IF NOT FOUND THEN RAISE EXCEPTION 'claim not found' USING ERRCODE = 'P0002'; END IF;
  UPDATE ownership_claims SET last_checked_at = now(), check_count = check_count + 1,
         last_check_result = left(p_detail, 300)
   WHERE id = p_claim;
  IF c.status <> 'challenge_issued' THEN RETURN c.status; END IF;
  IF c.challenge_expires_at <= now() THEN
    UPDATE ownership_claims SET status = 'expired' WHERE id = p_claim;
    RETURN 'expired';
  END IF;
  IF p_found IS NOT NULL AND p_found = 'upfixy-verify=' || c.nonce THEN
    UPDATE ownership_claims SET status = 'verified', verified_at = now(), expires_at = now() + interval '90 days'
     WHERE id = p_claim;
    RETURN 'verified';
  END IF;
  RETURN 'challenge_issued';
END $$;

GRANT EXECUTE ON FUNCTION worker_claim_for_check(uuid), worker_record_claim_check(uuid, text, text) TO upfixy_worker;

-- ----------------------------------------------------------- delegations
-- The app may request and revoke; only the worker may grant (M4: after the
-- provider connector proves the exact asset belongs to the grantor).
ALTER TABLE delegations
  ADD CONSTRAINT delegations_permissions CHECK (permissions <@ ARRAY['read', 'diagnose', 'repair']::text[]);
DROP POLICY del_write ON delegations;
REVOKE INSERT, UPDATE, DELETE ON delegations FROM upfixy_app;
CREATE POLICY del_insert ON delegations FOR INSERT TO upfixy_app
  WITH CHECK (workspace_id = app_workspace_id() AND app_role() IN ('owner', 'admin')
              AND EXISTS (SELECT 1 FROM websites w WHERE w.id = website_id));
CREATE POLICY del_revoke ON delegations FOR UPDATE TO upfixy_app
  USING (workspace_id = app_workspace_id() AND app_role() IN ('owner', 'admin'))
  WITH CHECK (workspace_id = app_workspace_id());
GRANT INSERT ON delegations TO upfixy_app;
GRANT UPDATE (status, revoked_at) ON delegations TO upfixy_app;

CREATE FUNCTION delegations_guard() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
  IF current_user = 'upfixy_app' THEN
    IF TG_OP = 'INSERT' THEN
      NEW.status := 'requested'; NEW.revoked_at := NULL;
    ELSIF NEW.status IS DISTINCT FROM OLD.status AND NEW.status <> 'revoked' THEN
      RAISE EXCEPTION 'delegations can only be revoked here' USING ERRCODE = '42501';
    ELSIF NEW.status = 'revoked' THEN
      NEW.revoked_at := coalesce(NEW.revoked_at, now());
    END IF;
  END IF;
  RETURN NEW;
END $$;
CREATE TRIGGER delegations_guard BEFORE INSERT OR UPDATE ON delegations
  FOR EACH ROW EXECUTE FUNCTION delegations_guard();

CREATE FUNCTION worker_grant_delegation(p_delegation uuid, p_expires timestamptz) RETURNS void
LANGUAGE plpgsql SECURITY DEFINER SET search_path = public, pg_temp AS $$
BEGIN
  IF p_expires > now() + interval '1 year' THEN RAISE EXCEPTION 'delegation too long'; END IF;
  UPDATE delegations SET status = 'granted', expires_at = p_expires
   WHERE id = p_delegation AND status = 'requested';
  IF NOT FOUND THEN RAISE EXCEPTION 'delegation not pending' USING ERRCODE = 'P0002'; END IF;
END $$;
GRANT EXECUTE ON FUNCTION worker_grant_delegation(uuid, timestamptz) TO upfixy_worker;

-- -------------------------------------------------------- the access gate
-- True when workspace p_ws may use private data or make changes on target
-- p_target: verified, unexpired proof for that exact host, or a granted,
-- unexpired, unrevoked delegation for the website carrying p_permission.
CREATE FUNCTION site_private_access(p_ws uuid, p_target uuid, p_permission text) RETURNS boolean
LANGUAGE sql STABLE SECURITY DEFINER SET search_path = public, pg_temp AS $$
  SELECT EXISTS (
           SELECT 1 FROM monitor_targets t
             JOIN ownership_claims c ON c.workspace_id = t.workspace_id AND c.website_id = t.website_id AND c.host = t.host
            WHERE t.id = p_target AND t.workspace_id = p_ws
              AND c.status = 'verified' AND c.expires_at > now() AND c.revoked_at IS NULL)
      OR EXISTS (
           SELECT 1 FROM monitor_targets t
             JOIN delegations d ON d.workspace_id = t.workspace_id AND d.website_id = t.website_id
            WHERE t.id = p_target AND t.workspace_id = p_ws
              AND d.status = 'granted' AND d.expires_at > now() AND d.revoked_at IS NULL
              AND p_permission = ANY (d.permissions))
$$;
GRANT EXECUTE ON FUNCTION site_private_access(uuid, uuid, text) TO upfixy_app, upfixy_worker;

-- Private (connector / user-entered) readings disappear the moment proof
-- lapses or is revoked (AC-AST-002.6). Public readings stay visible.
DROP POLICY obs_select ON observations;
CREATE POLICY obs_select ON observations FOR SELECT TO upfixy_app
  USING (workspace_id = app_workspace_id()
         AND EXISTS (SELECT 1 FROM monitor_targets t WHERE t.id = monitor_target_id)
         AND (source = 'public' OR site_private_access(workspace_id, monitor_target_id, 'read')));
