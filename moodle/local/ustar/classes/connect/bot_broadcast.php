<?php
namespace local_ustar\connect;

defined('MOODLE_INTERNAL') || die();

/** Select and confirm an existing Academy publication in the operator's private bot dialog. */
final class bot_broadcast {
    private const PAGE = 6;

    public static function route(\stdClass $dialog, string $intent, array $input): array {
        $actor = (int)$dialog->userid;
        announcements::require_manager($actor);
        if (!announcements::enabled()) {
            return bot_store::card('Рассылки Telegram', 'Отправка рассылок закрыта.', [bot_service::home($dialog)]);
        }
        if ($intent === 'broadcast.posts') {
            bot_store::state($dialog, 'broadcast.posts');
            return self::posts($dialog, max(0, (int)($input['offset'] ?? 0)));
        }
        if ($intent === 'broadcast.open') {
            $preview = announcements::preview($actor, (int)$input['post']);
            if (!$preview['sendable']) {
                return bot_store::card('Проверьте объявление', $preview['broadcast_error'], [
                    bot_store::button($dialog, 'К публикациям', 'broadcast.posts'), bot_service::home($dialog),
                ]);
            }
            $draft = ['post' => $preview['id'], 'fingerprint' => $preview['fingerprint'], 'selected' => []];
            bot_store::state($dialog, 'broadcast.select', $draft);
            return self::select($dialog, $preview, [], 0);
        }
        if (!in_array($dialog->phase, ['broadcast.select', 'broadcast.review'], true)) {
            throw new \InvalidArgumentException('expired_bot_action');
        }
        $draft = bot_store::data($dialog);
        $preview = announcements::preview($actor, $draft['post']);
        if (!hash_equals($draft['fingerprint'], $preview['fingerprint'])) {
            throw new \InvalidArgumentException('stale_revision');
        }
        $people = array_column($preview['people'], 'name', 'id');
        $offset = max(0, (int)($input['offset'] ?? 0));
        if ($intent === 'broadcast.send') {
            if ($dialog->phase !== 'broadcast.review' || !$draft['selected']) {
                throw new \InvalidArgumentException('expired_bot_action');
            }
            $count = announcements::enqueue($actor, $draft['post'], $draft['fingerprint'], $draft['selected']);
            bot_store::state($dialog, 'menu');
            return bot_store::card('Рассылка в очереди', 'Выбрано получателей: ' . $count . '. Статусы доставки доступны в Академии.',
                [bot_store::button($dialog, 'К публикациям', 'broadcast.posts'), bot_service::home($dialog)]);
        }
        if ($intent === 'broadcast.review') {
            if ($dialog->phase !== 'broadcast.select' || !$draft['selected']) {
                throw new \InvalidArgumentException('expired_bot_action');
            }
            bot_store::state($dialog, 'broadcast.review', $draft);
            return self::review($dialog, $preview, $people, $draft['selected']);
        }
        if ($intent === 'broadcast.toggle') {
            $id = (int)$input['user'];
            if ($dialog->phase !== 'broadcast.select' || !isset($people[$id])) {
                throw new \InvalidArgumentException('stale_revision');
            }
            $draft['selected'] = in_array($id, $draft['selected'], true)
                ? array_values(array_diff($draft['selected'], [$id])) : [...$draft['selected'], $id];
        } else if ($intent === 'broadcast.all') {
            if ($dialog->phase !== 'broadcast.select') {
                throw new \InvalidArgumentException('expired_bot_action');
            }
            $draft['selected'] = $input['select'] ? array_keys($people) : [];
        } else if ($intent !== 'broadcast.select') {
            throw new \InvalidArgumentException('invalid_bot_action');
        }
        bot_store::state($dialog, 'broadcast.select', $draft);
        return self::select($dialog, $preview, $draft['selected'], $offset);
    }

    private static function posts(\stdClass $dialog, int $offset): array {
        $posts = announcements::recent((int)$dialog->userid, $offset, self::PAGE + 1);
        $buttons = [];
        foreach (array_slice($posts, 0, self::PAGE) as $post) {
            $label = trim(preg_replace('/\s+/u', ' ', $post->body));
            $buttons[] = bot_store::button($dialog, $label, 'broadcast.open', ['post' => (int)$post->id]);
        }
        $buttons = array_merge($buttons, self::pages($dialog, 'broadcast.posts', $offset, count($posts) > self::PAGE));
        $buttons[] = bot_service::home($dialog);
        return bot_store::card('Рассылки Telegram', $posts ? 'Выберите опубликованное объявление.' :
            'Опубликуйте объявление с файлами в Академии.', $buttons);
    }

    private static function pages(\stdClass $dialog, string $intent, int $offset, bool $more): array {
        $buttons = [];
        if ($offset > 0) {
            $buttons[] = bot_store::button($dialog, '← Предыдущие', $intent, ['offset' => max(0, $offset - self::PAGE)]);
        }
        if ($more) {
            $buttons[] = bot_store::button($dialog, 'Следующие →', $intent, ['offset' => $offset + self::PAGE]);
        }
        return $buttons;
    }

    private static function select(\stdClass $dialog, array $preview, array $selected, int $offset): array {
        $buttons = [];
        foreach (array_slice($preview['people'], $offset, self::PAGE) as $person) {
            $label = (in_array($person['id'], $selected, true) ? '✓ ' : '○ ') . $person['name'];
            $buttons[] = bot_store::button($dialog, $label, 'broadcast.toggle', ['user' => $person['id'], 'offset' => $offset]);
        }
        $buttons = array_merge($buttons, self::pages($dialog, 'broadcast.select', $offset,
            $preview['peoplecount'] > $offset + self::PAGE));
        if ($preview['peoplecount'] > count($selected)) {
            $buttons[] = bot_store::button($dialog, 'Выбрать всех доступных · ' . $preview['peoplecount'],
                'broadcast.all', ['select' => true, 'offset' => $offset]);
        }
        if ($selected) {
            $buttons[] = bot_store::button($dialog, 'Снять выбор', 'broadcast.all', ['select' => false, 'offset' => $offset]);
            $buttons[] = bot_store::button($dialog, 'Проверить рассылку · ' . count($selected), 'broadcast.review');
        }
        $buttons[] = bot_store::button($dialog, 'К публикациям', 'broadcast.posts');
        $text = $preview['peoplecount'] ? 'Отметьте получателей. Выбрано ' . count($selected) . ' из ' . $preview['peoplecount'] . '.' :
            'Список пуст. Проверьте доступ к публикации, привязку и настройки уведомлений Ленты.';
        return bot_store::card('Получатели рассылки', $text, $buttons);
    }

    private static function review(\stdClass $dialog, array $preview, array $people, array $selected): array {
        $names = array_map(static fn(int $id): string => $people[$id], array_slice($selected, 0, 4));
        $text = shorten_text($preview['body'], 850) . "\n\nФайлов: " . count($preview['files'])
            . "\nПолучатели: " . implode(', ', $names);
        if (count($selected) > count($names)) {
            $text .= ' и ещё ' . (count($selected) - count($names));
        }
        $text .= ".\nТекст и файлы будут отправлены в личные чаты.";
        return bot_store::card('Подтвердите рассылку', $text, [
            bot_store::button($dialog, 'Отправить · ' . count($selected), 'broadcast.send'),
            bot_store::button($dialog, 'Изменить получателей', 'broadcast.select'),
            ['label' => 'Открыть объявление', 'url' => $preview['posturl']],
            bot_store::button($dialog, 'К публикациям', 'broadcast.posts'),
        ], [], array_map(static fn(array $file): array => ['Файл', $file['name']], $preview['files']));
    }
}
