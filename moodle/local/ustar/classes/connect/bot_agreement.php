<?php
namespace local_ustar\connect;

defined('MOODLE_INTERNAL') || die();

use core_privacy\local\sitepolicy\default_handler;
use core_privacy\local\sitepolicy\manager;
use tool_policy\api;
use tool_policy\policy_version;
use tool_policy\privacy\local\sitepolicy\handler;

/** The bot presents the Moodle policy and records the employee's response through Moodle's API. */
final class bot_agreement {
    private const PAGE_SIZE = 1250;

    /** Return missing versions, or a reason the site configuration cannot be used in chat. */
    public static function pending(int $userid, bool $invited): array {
        $manager = new manager();
        $classname = ltrim($manager->get_handler_classname(), '\\');
        if ($classname !== handler::class) {
            $native = $classname === default_handler::class;
            $blocked = $invited || ((!$native || $manager->is_defined())
                && !\core_user::get_user($userid, 'id,policyagreed', MUST_EXIST)->policyagreed);
            return ['versions' => [], 'blocked' => $blocked];
        }
        $versions = array_values(array_map('intval', api::get_current_versions_ids(policy_version::AUDIENCE_LOGGEDIN)));
        if (!$versions) {
            return ['versions' => [], 'blocked' => $invited];
        }
        $accepted = api::get_user_acceptances($userid, $versions);
        $pending = [];
        foreach ($versions as $version) {
            $response = $accepted[$version] ?? null;
            if ($response === null || (!(bool)$response->status
                    && api::get_agreement_optional($version) == policy_version::AGREEMENT_COMPULSORY)) {
                $pending[] = $version;
            }
        }
        return ['versions' => $pending, 'blocked' => false];
    }

    public static function is_required(array $pending): bool {
        return $pending['blocked'] || $pending['versions'] !== [];
    }

    public static function view(\stdClass $dialog, array $pending, int $page = 0): array {
        if ($pending['blocked'] || ($pending['versions'] && !api::can_accept_policies($pending['versions']))) {
            return bot_store::card('Пользовательское соглашение',
                'Попросите администратора проверить публикацию соглашения и право его принять.',
                [bot_store::button($dialog, 'Проверить доступ', 'menu')]) + ['plain' => true];
        }
        if (!$pending['versions']) {
            return [];
        }
        $versionid = $pending['versions'][0];
        $version = api::get_policy_version($versionid);
        $document = self::document($version);
        if ($document['text'] === '') {
            return bot_store::card('Пользовательское соглашение',
                'Текст опубликованного соглашения недоступен в чате. Обратитесь к администратору.',
                [bot_store::button($dialog, 'Проверить доступ', 'menu')]) + ['plain' => true];
        }
        $pages = (int)ceil(\core_text::strlen($document['text']) / self::PAGE_SIZE);
        $page = max(0, min($pages - 1, $page));
        $target = ['version' => $versionid, 'stamp' => $document['stamp']];
        $buttons = [];
        if ($page > 0) {
            $buttons[] = bot_store::button($dialog, '← Предыдущая страница', 'agreement.page', $target + ['page' => $page - 1]);
        }
        if ($page + 1 < $pages) {
            $buttons[] = bot_store::button($dialog, 'Далее →', 'agreement.page', $target + ['page' => $page + 1]);
        } else {
            $buttons[] = bot_store::button($dialog, 'Принять соглашение', 'agreement.accept', $target);
            if ($version->optional == policy_version::AGREEMENT_OPTIONAL) {
                $buttons[] = bot_store::button($dialog, 'Отклонить', 'agreement.decline', $target);
            }
        }
        return bot_store::card($version->name, "Редакция {$version->revision} · " . ($page + 1) . "/{$pages}\n\n"
            . \core_text::substr($document['text'], $page * self::PAGE_SIZE, self::PAGE_SIZE), $buttons) + ['plain' => true];
    }

    public static function route(\stdClass $dialog, array $pending, string $intent, array $data): array {
        if ($pending['blocked'] || ($pending['versions'][0] ?? null) !== ($data['version'] ?? null)) {
            return self::view($dialog, $pending);
        }
        $version = api::get_policy_version($data['version']);
        if (!in_array((int)$version->id, array_map('intval',
                api::get_current_versions_ids(policy_version::AUDIENCE_LOGGEDIN)), true)) {
            return self::view($dialog, self::pending((int)$dialog->userid, true));
        }
        $document = self::document($version);
        if ($document['text'] === '' || !hash_equals($document['stamp'], $data['stamp'] ?? '')) {
            return self::view($dialog, $pending);
        }
        if ($intent === 'agreement.page') {
            return self::view($dialog, $pending, (int)$data['page']);
        }
        if ($intent === 'agreement.accept') {
            api::accept_policies($version->id, null, 'USTAR Connect Telegram', current_language());
        } else if ($intent === 'agreement.decline' && $version->optional == policy_version::AGREEMENT_OPTIONAL) {
            api::decline_policies($version->id, null, 'USTAR Connect Telegram', current_language());
        } else {
            throw new \InvalidArgumentException('invalid_bot_action');
        }
        bot_store::state($dialog, 'agreement');
        return [];
    }

    private static function document(\stdClass $version): array {
        // Plain pages preserve the agreement's wording. Embedded files or visuals require a text edition.
        $text = preg_match('~<(?:img|iframe|video|audio|object|embed|svg)\b|@@PLUGINFILE@@|/pluginfile\.php~i', $version->content)
            ? '' : trim(html_to_text($version->content, 75, true));
        $stamp = hash('sha256', bot_store::json([$version->id, $version->name, $version->revision,
            $version->optional, $version->content]));
        return ['text' => $text, 'stamp' => $stamp];
    }
}
