<?php
namespace local_ustar\connect;
defined('MOODLE_INTERNAL') || die();

/** Native owner-session adapter. Does not authenticate passwords or create a Moodle session. */
final class link_management {
    /** Public metadata only. The bot ID must match the provider verifier's configured tenant. */
    private static function channel(): ?array {
        global $CFG;
        $channels = $CFG->ustar_connect_channels ?? [];
        $value = is_array($channels) ? ($channels['telegram'] ?? null) : null;
        if (!is_array($value)) { return null; }
        $keys = array_keys($value); sort($keys);
        if ($keys !== ['tenant', 'username'] || !is_string($value['tenant']) || !is_string($value['username'])
                || !preg_match('/\A[1-9][0-9]{0,19}\z/', $value['tenant'])
                || !preg_match('/\A[A-Za-z][A-Za-z0-9_]{1,28}[Bb][Oo][Tt]\z/', $value['username'])) {
            return null;
        }
        return $value;
    }

    public static function handle(string $method, bool $https, #[\SensitiveParameter] array $input): array {
        global $USER;
        if (!$https) { throw new \InvalidArgumentException('https_required'); }
        if (!in_array($method, ['GET', 'POST'], true) || ($method === 'GET' && $input !== [])) {
            throw new \InvalidArgumentException('invalid_request');
        }
        // Also gates disabled/configuration views: no guest/service/view-as identity disclosure.
        $links = identity_service::owned_links();
        $channel = self::channel();
        $view = ['enabled' => $channel !== null, 'links' => $links, 'challenge' => null, 'confirmed' => false,
            'preferences' => notification_preferences::for_user((int)$USER->id),
            'deliveries' => notification_delivery::own_summary(), 'preferences_saved' => false];
        if ($method === 'GET') { return $view; }
        if (!is_string($input['sesskey'] ?? null) || !confirm_sesskey($input['sesskey'])) {
            throw new \InvalidArgumentException('invalid_sesskey');
        }
        $action = $input['action'] ?? '';
        if (!in_array($action, ['issue', 'preview', 'confirm', 'revoke', 'preferences'], true)) {
            throw new \InvalidArgumentException('invalid_request');
        }
        if ($action === 'preferences') {
            if (!is_array($input['preferences'] ?? null)) { throw new \InvalidArgumentException('invalid_preferences'); }
            $view['preferences'] = notification_preferences::save_own($input['preferences']);
            $view['preferences_saved'] = true;
        } else if ($action === 'issue') {
            if (!$channel) { throw new \InvalidArgumentException('channel_unavailable'); }
            $challenge = identity_service::issue('telegram', $channel['tenant']);
            $view['challenge'] = $challenge + ['claimed' => false, 'subject' => '',
                'launch_url' => 'https://t.me/'.$channel['username'].'?startapp='.$challenge['code']];
        } else if ($action === 'preview' || $action === 'confirm') {
            $code = $input['code'] ?? '';
            if (!is_string($code)) { throw new \InvalidArgumentException('invalid_link_code'); }
            if ($action === 'confirm') {
                identity_service::confirm($code);
                $view['confirmed'] = true;
            } else {
                $preview = identity_service::preview($code);
                $url = $channel && $channel['tenant'] === $preview['tenant']
                    ? 'https://t.me/'.$channel['username'].'?startapp='.$code : '';
                $view['challenge'] = $preview + ['code' => $code, 'launch_url' => $url];
            }
        } else {
            $raw = $input['linkid'] ?? '';
            if (!is_string($raw) || !preg_match('/\A[1-9][0-9]{0,18}\z/', $raw)
                    || filter_var($raw, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) === false) {
                throw new \InvalidArgumentException('invalid_request');
            }
            identity_service::revoke((int)$raw);
        }
        $view['links'] = identity_service::owned_links();
        return $view;
    }
}
