-- One-time administrator setup. Collector connects only as ustar_console_monitor.
-- No application rows, query text, client addresses or role names leave this function.
\set ON_ERROR_STOP on
BEGIN;
DO $$ BEGIN
  IF NOT EXISTS (SELECT FROM pg_catalog.pg_roles WHERE rolname='ustar_console_stats') THEN
    CREATE ROLE ustar_console_stats NOLOGIN NOSUPERUSER NOCREATEDB NOCREATEROLE NOREPLICATION;
  END IF;
  IF NOT EXISTS (SELECT FROM pg_catalog.pg_roles WHERE rolname='ustar_console_monitor') THEN
    CREATE ROLE ustar_console_monitor LOGIN NOSUPERUSER NOCREATEDB NOCREATEROLE NOREPLICATION NOINHERIT CONNECTION LIMIT 2;
  END IF;
END $$;
-- Refuse to adopt pre-existing privileged/member roles.
DO $$ BEGIN
  IF EXISTS (SELECT FROM pg_catalog.pg_roles WHERE rolname IN ('ustar_console_stats','ustar_console_monitor')
             AND (rolsuper OR rolcreatedb OR rolcreaterole OR rolreplication OR rolbypassrls))
     OR EXISTS (SELECT FROM pg_catalog.pg_auth_members m JOIN pg_catalog.pg_roles r ON r.oid=m.member
                WHERE r.rolname='ustar_console_monitor') THEN
    RAISE EXCEPTION 'Unsafe existing monitor role';
  END IF;
END $$;
GRANT pg_read_all_stats TO ustar_console_stats;
CREATE SCHEMA IF NOT EXISTS ustar_console AUTHORIZATION ustar_console_stats;
REVOKE ALL ON SCHEMA ustar_console FROM PUBLIC;
CREATE OR REPLACE FUNCTION ustar_console.sample() RETURNS jsonb
LANGUAGE sql STABLE SECURITY DEFINER
SET search_path = pg_catalog, pg_temp
SET statement_timeout = '1500ms'
SET lock_timeout = '300ms'
AS $$
  SELECT jsonb_build_object(
    'connections', (SELECT count(*) FROM pg_catalog.pg_stat_activity),
    'active', count(*) FILTER (WHERE state='active' AND pid<>pg_backend_pid()),
    'idle_in_transaction', count(*) FILTER (WHERE state LIKE 'idle in transaction%'),
    'waiting', count(*) FILTER (WHERE wait_event_type='Lock'),
    'max_connections', current_setting('max_connections')::int,
    'oldest_transaction_seconds', coalesce(greatest(0,extract(epoch FROM now()-min(xact_start)
       FILTER (WHERE pid<>pg_backend_pid()))),0),
    'size_bytes', pg_database_size(current_database()))
  FROM pg_catalog.pg_stat_activity WHERE datname=current_database();
$$;
ALTER FUNCTION ustar_console.sample() OWNER TO ustar_console_stats;
REVOKE ALL ON FUNCTION ustar_console.sample() FROM PUBLIC;
GRANT CONNECT ON DATABASE moodle TO ustar_console_monitor;
GRANT USAGE ON SCHEMA ustar_console TO ustar_console_monitor;
GRANT EXECUTE ON FUNCTION ustar_console.sample() TO ustar_console_monitor;
ALTER ROLE ustar_console_monitor SET default_transaction_read_only=on;
ALTER ROLE ustar_console_monitor SET statement_timeout='1500ms';
ALTER ROLE ustar_console_monitor SET lock_timeout='300ms';
COMMIT;
