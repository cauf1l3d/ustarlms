<?php
namespace local_ustar\event;

defined('MOODLE_INTERNAL') || die();

/** Audit event for administrator-only legacy UI session switching. */
final class legacy_ui_toggled extends \core\event\base {
    protected function init(): void {
        $this->data['crud'] = 'u';
        $this->data['edulevel'] = self::LEVEL_OTHER;
    }

    public static function get_name(): string {
        return 'USTAR legacy UI session toggled';
    }

    public function get_description(): string {
        $state = (string)($this->other['state'] ?? 'unknown');
        $theme = (string)($this->other['theme'] ?? '');
        return "User {$this->userid} changed USTAR legacy UI session state to {$state} using theme {$theme}.";
    }

    public function get_url(): \moodle_url {
        return new \moodle_url('/local/ustar/legacy.php');
    }
}
