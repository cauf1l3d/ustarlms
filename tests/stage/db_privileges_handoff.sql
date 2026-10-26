-- Disposable fixture only. Preserve bootstrap catalogs; move public app objects.
BEGIN;
SET LOCAL statement_timeout = '30s';
SET LOCAL lock_timeout = '3s';
SET LOCAL search_path = pg_catalog;
DO $fixture$
BEGIN
    IF current_database() <> 'ustar_stage1' OR current_user <> 'ustar_privilege_admin'
       OR NOT EXISTS (SELECT 1 FROM pg_roles
                      WHERE rolname='ustar_fixture' AND oid=10 AND rolsuper)
       OR EXISTS (SELECT 1 FROM pg_roles WHERE rolname='ustar_app') THEN
        RAISE EXCEPTION 'REFUSING_NON_ISOLATED_ROLE_HANDOFF';
    END IF;
    IF EXISTS (SELECT 1 FROM pg_class c JOIN pg_namespace n ON n.oid=c.relnamespace
               WHERE n.nspname='public' AND c.relowner=10
                 AND c.relkind NOT IN ('r','p','v','m','S','f','i','I')) THEN
        RAISE EXCEPTION 'UNEXPECTED_FIXTURE_PUBLIC_OBJECT';
    END IF;
END;
$fixture$;
CREATE TEMP TABLE privilege_ownership_before ON COMMIT DROP AS
    SELECT count(*)::int AS total
    FROM pg_class c JOIN pg_namespace n ON n.oid=c.relnamespace
    WHERE n.nspname='public' AND c.relowner=10;

-- Synthetic password only. Real deployment needs a separate private credential.
CREATE ROLE ustar_app LOGIN NOSUPERUSER NOCREATEROLE NOCREATEDB
    NOREPLICATION NOBYPASSRLS PASSWORD 'fixture-app-only-not-production-secret';
ALTER DATABASE ustar_stage1 OWNER TO ustar_app;
DO $ownership$
DECLARE r record;
BEGIN
    FOR r IN SELECT n.nspname,c.relname,c.relkind
             FROM pg_class c JOIN pg_namespace n ON n.oid=c.relnamespace
             WHERE n.nspname='public' AND c.relowner=10
               AND c.relkind IN ('r','p','v','m','S','f')
             ORDER BY CASE WHEN c.relkind='S' THEN 1 ELSE 0 END,c.oid
    LOOP
        EXECUTE format('ALTER %s %I.%I OWNER TO ustar_app',
            CASE r.relkind WHEN 'v' THEN 'VIEW' WHEN 'm' THEN 'MATERIALIZED VIEW'
                 WHEN 'S' THEN 'SEQUENCE' WHEN 'f' THEN 'FOREIGN TABLE' ELSE 'TABLE' END,
            r.nspname,r.relname);
    END LOOP;
END;
$ownership$;
SELECT json_build_object(
    'bootstrap_role','ustar_fixture','bootstrap_oid',10,
    'bootstrap_superuser',(SELECT rolsuper::int FROM pg_roles WHERE oid=10),
    'public_before',(SELECT total FROM privilege_ownership_before),
    'public_app_after',(SELECT count(*)::int FROM pg_class c JOIN pg_namespace n ON n.oid=c.relnamespace
                        WHERE n.nspname='public' AND c.relowner=(SELECT oid FROM pg_roles WHERE rolname='ustar_app')),
    'public_bootstrap_after',(SELECT count(*)::int FROM pg_class c JOIN pg_namespace n ON n.oid=c.relnamespace
                              WHERE n.nspname='public' AND c.relowner=10),
    'app_system_catalogs',(SELECT count(*)::int FROM pg_class c JOIN pg_namespace n ON n.oid=c.relnamespace
                           WHERE n.nspname='pg_catalog' AND c.relowner=(SELECT oid FROM pg_roles WHERE rolname='ustar_app'))
);
COMMIT;
