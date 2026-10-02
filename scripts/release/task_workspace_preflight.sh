#!/usr/bin/env bash
# Read-only PR #63 -> task workspace preflight. No dump, repair, sync or reconciliation.
set -Eeuo pipefail
MOODLE="${USTAR_MOODLE_CONTAINER:-ustar_moodle}"
sudo docker exec -i -u www-data "$MOODLE" php <<'PHP'
<?php
 define('CLI_SCRIPT', true);
 require '/var/www/html/config.php';
 $version = (string)get_config('local_ustar', 'version');
 echo 'LOCAL_USTAR_VERSION=', $version, PHP_EOL;
 if ($version !== '2026092907') { fwrite(STDERR, "BASE_VERSION_MISMATCH\n"); exit(20); }
 $dbman = $DB->get_manager();
 foreach (['local_ustar_learning_tasks', 'local_ustar_learning_task_events', 'local_ustar_adaptations',
     'local_ustar_check_submits', 'local_ustar_workflow_events', 'local_ustar_staff_places',
     'local_ustar_assignments'] as $name) {
     if (!$dbman->table_exists(new xmldb_table($name))) { fwrite(STDERR, "MISSING_TABLE={$name}\n"); exit(21); }
     echo "TABLE_OK={$name}\n";
 }
 echo 'MAINTENANCE=', empty($CFG->maintenance_enabled) ? 'OFF' : 'ON', PHP_EOL;
 echo "TASK_WORKSPACE_PREFLIGHT=OK\n";
PHP
