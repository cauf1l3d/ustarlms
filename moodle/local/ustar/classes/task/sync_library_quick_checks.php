<?php
namespace local_ustar\task;

defined('MOODLE_INTERNAL') || die();

/**
 * Keep generated quick-checks in step with the catalog.
 *
 * Runs every 5 minutes. Catches imports (cli/import_catalog*.php) and any path
 * that bypasses catalog::save(). Idempotent: an unchanged catalog costs one
 * read pass and writes nothing.
 */
final class sync_library_quick_checks extends \core\task\scheduled_task {

    public function get_name(): string {
        return 'USTAR: проверки знаний по карточкам каталога';
    }

    public function execute(): void {
        // Honour the rollout flag: with the Library off, nothing is generated.
        if (!(bool)get_config('local_ustar', 'library_rail')) {
            mtrace('USTAR library quick sync: library_rail is off, skipped.');
            return;
        }

        $result = \local_ustar\library_quick_sync::run(false);

        if (!empty($result['locked'])) {
            mtrace('USTAR library quick sync: previous pass still running, skipped.');
            return;
        }
        mtrace('USTAR library quick sync: ' . json_encode($result));
    }
}
