<?php
namespace local_ustar;

defined('MOODLE_INTERNAL') || die();

/** Lazy payload for one ACL-checked external article. */
final class feed_external_content {
    public static function payload(int $postid, int $userid): array {
        global $DB;

        $post = feed_access::readable($postid, $userid);
        if ((string)$post->publishertype !== 'external') {
            throw new \invalid_parameter_exception('Публикация не является внешним материалом.');
        }

        $item = $DB->get_record_sql(
            "SELECT i.id, i.contenttext, i.contenthtml, i.enrichstatus, i.enrichedat,
                    i.externalurl, s.name AS sourcename, s.resolverenabled
               FROM {local_ustar_feed_sourceitem} i
               JOIN {local_ustar_feed_sources} s ON s.id = i.sourceid
              WHERE i.postid = :postid",
            ['postid' => $postid],
            MUST_EXIST
        );

        $fulltext = trim((string)($item->contenttext ?? ''));
        $fullhtml = trim((string)($item->contenthtml ?? ''));
        $body = trim((string)$post->body);
        $status = (string)($item->enrichstatus ?? '');

        if ($fullhtml !== '') {
            $html = html_writer::tag(
                'div',
                format_text($fullhtml, FORMAT_HTML, ['filter' => false]),
                ['class' => 'u-feed__external-content']
            );
            $available = true;
        } else if ($fulltext !== '' && $fulltext !== $body) {
            $html = html_writer::tag(
                'div',
                nl2br(s($fulltext)),
                ['class' => 'u-feed__external-content']
            );
            $available = true;
        } else {
            $messages = [
                'pending' => 'Полный текст поставлен в безопасную очередь обработки.',
                'retry' => 'Полный текст временно недоступен. Следующая ограниченная попытка уже запланирована.',
                'failed' => 'Полный текст не удалось получить после ограниченного числа попыток.',
                'limited' => 'Страница источника не предоставила достаточно дополнительного текста.',
                'disabled' => 'Для этого источника расширение полного текста выключено.',
            ];
            $message = $messages[$status]
                ?? 'Источник передал только краткое описание материала.';
            $html = html_writer::tag('p', s($message), ['class' => 'u-feed__source']);
            $available = false;
        }

        return [
            'html' => $html,
            'available' => $available,
            'status' => $status,
            'enrichedat' => (int)($item->enrichedat ?? 0),
        ];
    }
}
