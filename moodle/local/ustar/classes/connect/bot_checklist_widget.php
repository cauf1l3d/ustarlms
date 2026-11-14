<?php
namespace local_ustar\connect;

defined('MOODLE_INTERNAL') || die();

use local_ustar\checklist_service;
use local_ustar\checklists;
use local_ustar\structure;

/** Personal widget actions share the existing dialog store and checklist writer. */
final class bot_checklist_widget {
    public const INTENT = 'work.checklist.widget';
    private const MAX_ITEMS = 5;

    public static function in_pilot(int $userid): bool {
        global $CFG;
        return bot_widgets::in_pilot($userid, $CFG->ustar_connect_checklist_widget_userids ?? []);
    }

    public static function view(\stdClass $dialog, array $checklist, string $date, string $event): ?array {
        if (!self::in_pilot((int)$dialog->userid) || (int)$checklist['today']['revision'] !== 0) {
            return null;
        }
        $items = checklists::flat_items($checklist);
        if (!$items || count($items) > self::MAX_ITEMS) {
            return null;
        }
        $labels = [];
        foreach ($items as $item) {
            $label = trim((string)preg_replace('/\s+/u', ' ', (string)$item['title']));
            if ($label === '' || \core_text::strlen($label) > 65 || preg_match('/[\x00-\x1F\x7F]/', $label)) {
                return null;
            }
            $labels[] = $label;
        }
        // Leave room for the opaque action token inside Telegram's 1024-byte state.
        if (strlen(bot_store::json(['items' => $labels])) > 950) {
            return null;
        }
        bot_store::state($dialog, 'menu');
        $target = ['id' => (string)$checklist['id'], 'date' => $date,
            'definition' => (int)$checklist['definitionversion']];
        $action = bot_store::action($dialog, self::INTENT, $target + [
            'items' => array_keys($items), 'event' => $event, 'message' => '',
        ]);
        $view = bot_store::card((string)$checklist['title'], 'Отметьте все пункты и отправьте ответы.',
            [bot_store::button($dialog, 'Пошаговая форма', 'work.checklist', $target + ['plain' => true]),
                bot_store::button($dialog, '← К чек-листам', 'work.checklists')]);
        return bot_store::guarded($view + ['widget' => ['action' => $action, 'items' => $labels]],
            ['checklist_widget' => $target]);
    }

    /** A cached bot response is still subject to the current checklist and pilot scope. */
    public static function verify(int $userid, array $target): void {
        if (!self::in_pilot($userid)) {
            throw new \InvalidArgumentException('forbidden_scope');
        }
        try {
            bot_actor::run($userid, static function() use ($userid, $target): void {
                $payload = checklist_service::present($userid, $target['id'], $target['date']);
                if (!$payload['canedit'] || (int)$payload['current']['today']['revision'] !== 0
                        || (int)$payload['current']['definitionversion'] !== $target['definition']) {
                    throw new \InvalidArgumentException('stale_revision');
                }
            });
        } catch (\required_capability_exception $error) {
            throw new \InvalidArgumentException('forbidden_scope');
        }
    }

    public static function submit(int $userid, \stdClass $action, array $target, string $selected): array {
        if (count($target['items']) !== strlen($selected) || !preg_match('/\A[01]+\z/', $selected)) {
            return ['status' => 'stale'];
        }
        if ($target['date'] !== checklist_service::date_key()) {
            return ['status' => 'stale'];
        }
        $definition = checklists::find($target['id']);
        $position = (string)(structure::resolve_user($userid)['position']['id'] ?? '');
        if (!$definition || !checklists::applies_to($definition, $position)) {
            return ['status' => 'stale'];
        }
        if ($target['definition'] !== max(1, (int)($definition['version'] ?? 1))) {
            return ['status' => 'stale'];
        }
        if ($action->status === 'done') {
            $stored = json_decode($action->resultjson, true, 16, JSON_THROW_ON_ERROR);
            return $stored['answers'] === $selected ? $stored['receipt'] : ['status' => 'stale'];
        }
        $answers = [];
        foreach ($target['items'] as $offset => $key) {
            $answers[$key] = ['done' => $selected[$offset] === '1', 'comment' => ''];
        }
        $receipt = [];
        checklist_service::submit($userid, $target['id'], $answers, '', 'final', 0, '',
            $target['definition'], $target['date'], static function(array $result) use ($action, $selected, &$receipt): void {
                $receipt = ['status' => 'saved', 'done' => $result['done'], 'total' => $result['total']];
                bot_store::done($action, ['answers' => $selected, 'receipt' => $receipt]);
            });
        return $receipt;
    }
}
