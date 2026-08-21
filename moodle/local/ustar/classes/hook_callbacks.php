<?php
namespace local_ustar;

defined('MOODLE_INTERNAL') || die();

final class hook_callbacks {
    /**
     * Project the USTAR position into protected workspace permissions and use
     * a role-aware landing page when the login did not target a specific page.
     */
    public static function after_login_completed(\core_user\hook\after_login_completed $hook): void {
        global $USER, $SESSION, $CFG;

        if (empty($USER->id) || isguestuser($USER)) {
            return;
        }

        try {
            position_access::sync_user((int)$USER->id);
        } catch (\Throwable $e) {
            debugging('USTAR position access sync failed: ' . $e->getMessage(), DEBUG_DEVELOPER);
            return;
        }

        $wantsurl = trim((string)($SESSION->wantsurl ?? ''));
        $path = '';
        if ($wantsurl !== '') {
            $parsed = parse_url($wantsurl);
            $path = (string)($parsed['path'] ?? '');
        }

        $homeish = $wantsurl === '' || in_array($path, [
            '', '/', '/index.php', '/my/', '/my/index.php', '/login/index.php',
        ], true);

        if (!$homeish) {
            return;
        }

        $SESSION->wantsurl = (new \moodle_url(
            position_access::landing_path((int)$USER->id)
        ))->out(false);
    }
}
