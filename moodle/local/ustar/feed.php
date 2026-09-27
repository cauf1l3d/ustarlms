<?php
require_once(__DIR__ . '/../../config.php');

require_login();
\local_ustar\feed_access::require_reader((int)$USER->id);
$context = context_system::instance();
$url = new moodle_url('/local/ustar/feed.php');
$PAGE->set_url($url);
$PAGE->set_context($context);
$PAGE->set_title('Лента · USTAR Academy');
$PAGE->set_heading('Лента');
$PAGE->requires->css(new moodle_url('/local/ustar/styles/feed.css', ['v' => '20260927']));
$notice = '';
$actorid = (int)$USER->id;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_sesskey();
    try {
        $action = required_param('action', PARAM_ALPHA);
        if ($action === 'publish' || $action === 'draft' || $action === 'repost') {
            $publisher = optional_param('publisher', 'person', PARAM_ALPHA);
            $publisherid = $publisher === 'person' ? (string)$actorid :
                ($publisher === 'academy' ? 'academy' : optional_param('departmentid', '', PARAM_ALPHANUMEXT));
            $audience = $publisher === 'academy'
                ? optional_param_array('audience', ['all'], PARAM_ALPHANUMEXT)
                : [$publisher === 'department' ? $publisherid : 'all'];
            $postid = \local_ustar\feed_service::create($actorid, $publisher, $publisherid,
                $audience, required_param('body', PARAM_TEXT), $action !== 'draft',
                $action === 'repost' ? required_param('sourceid', PARAM_INT) : 0,
                \local_ustar\task_files::uploaded());
            redirect(new moodle_url('/local/ustar/feed.php', ['postid' => $postid]));
        }
        $postid = required_param('postid', PARAM_INT);
        if ($action === 'like' || $action === 'unlike') {
            \local_ustar\feed_service::like($postid, $actorid, $action === 'like');
        } else if ($action === 'comment') {
            \local_ustar\feed_service::comment($postid, $actorid,
                required_param('body', PARAM_TEXT), optional_param('parentid', 0, PARAM_INT));
        } else if ($action === 'edit') {
            \local_ustar\feed_service::revise($postid, $actorid, required_param('version', PARAM_INT),
                required_param('body', PARAM_TEXT), optional_param('publish', 0, PARAM_BOOL));
        } else if ($action === 'delete') {
            \local_ustar\feed_service::remove($postid, $actorid, required_param('version', PARAM_INT));
            redirect(new moodle_url('/local/ustar/feed.php'));
        } else if ($action === 'commentedit' || $action === 'commentdelete') {
            \local_ustar\feed_service::revise_comment(required_param('commentid', PARAM_INT),
                $postid, $actorid, required_param('version', PARAM_INT),
                optional_param('body', '', PARAM_TEXT), $action === 'commentdelete');
        } else if ($action === 'report') {
            \local_ustar\feed_service::report($postid, $actorid, required_param('reason', PARAM_TEXT));
        } else if ($action === 'moderate') {
            \local_ustar\feed_service::moderate($postid, $actorid,
                required_param('version', PARAM_INT), required_param('decision', PARAM_ALPHA),
                required_param('reason', PARAM_TEXT));
            redirect(new moodle_url('/local/ustar/feed.php', ['moderation' => 1]));
        } else {
            throw new invalid_parameter_exception('Неизвестное действие.');
        }
        redirect(new moodle_url('/local/ustar/feed.php', ['postid' => $postid]));
    } catch (\Throwable $e) {
        $notice = $e->getMessage();
    }
}

$filter = optional_param('filter', 'all', PARAM_ALPHA);
$beforetime = max(0, optional_param('beforetime', 0, PARAM_INT));
$beforeid = max(0, optional_param('beforeid', 0, PARAM_INT));
$selectedid = max(0, optional_param('postid', 0, PARAM_INT));
$selected = null;
if ($selectedid) {
    $draft = \local_ustar\view_as::active() ? null : $DB->get_record('local_ustar_feed_posts',
        ['id' => $selectedid, 'status' => 'draft', 'actoruserid' => $actorid]);
    $selected = $draft ?: \local_ustar\feed_access::readable($selectedid, $actorid);
}
$moderation = optional_param('moderation', 0, PARAM_BOOL) &&
    has_capability('local/ustar:feedmoderate', $context);
try {
    $page = \local_ustar\feed_query::page($actorid, $filter, $beforetime, $beforeid);
} catch (\invalid_parameter_exception $e) {
    $filter = 'all';
    $page = \local_ustar\feed_query::page($actorid);
}
$posts = $page['posts'];
if ($selected && !in_array((int)$selected->id, array_map(static fn($post) => (int)$post->id, $posts), true)) {
    array_unshift($posts, $selected);
}
$postids = array_map(static fn($post) => (int)$post->id, $posts);
$counts = [];
$liked = [];
$users = [];
if ($postids) {
    [$insql, $inparams] = $DB->get_in_or_equal($postids, SQL_PARAMS_NAMED, 'fp');
    $counts = $DB->get_records_sql("SELECT postid AS id, COUNT(id) AS total
          FROM {local_ustar_feed_reactions} WHERE postid {$insql} AND kind = :kind GROUP BY postid",
        $inparams + ['kind' => 'like']);
    $liked = $DB->get_records_sql("SELECT postid AS id FROM {local_ustar_feed_reactions}
          WHERE postid {$insql} AND userid = :userid AND kind = :kind",
        $inparams + ['userid' => $actorid, 'kind' => 'like']);
    $authorids = array_values(array_unique(array_map(static fn($post) => (int)$post->actoruserid, $posts)));
    [$usersql, $userparams] = $DB->get_in_or_equal($authorids, SQL_PARAMS_NAMED, 'fu');
    $users = $DB->get_records_select('user', "id {$usersql}", $userparams, '', 'id,firstname,lastname');
}

echo $OUTPUT->header();
echo html_writer::start_div('u-feed');
echo html_writer::tag('p', 'Новости Академии и вашей команды в одном месте.', ['class' => 'u-feed__intro']);
if ($notice !== '') {
    echo $OUTPUT->notification(s($notice), 'notifyproblem');
}
if (has_capability('local/ustar:feedmoderate', $context)) {
    echo html_writer::link(new moodle_url('/local/ustar/feed.php', ['moderation' => 1]),
        'Очередь модерации', ['class' => 'u-feed__source']);
}
if ($moderation) {
    echo html_writer::tag('h2', 'Обращения по публикациям');
    $reported = $DB->get_records_sql("SELECT p.* FROM {local_ustar_feed_posts} p
        WHERE EXISTS (SELECT 1 FROM {local_ustar_feed_reports} r
              WHERE r.postid = p.id AND r.status = :open)
        ORDER BY p.id DESC", ['open' => 'open'], 0, 30);
    foreach ($reported as $reportedpost) {
        echo html_writer::start_tag('article', ['class' => 'u-feed__post']);
        echo html_writer::tag('h3', 'Публикация №' . (int)$reportedpost->id . ' · ' . s($reportedpost->status));
        echo html_writer::tag('p', nl2br(s((string)$reportedpost->body)));
        echo html_writer::start_tag('form', ['method' => 'post']);
        foreach (['sesskey' => sesskey(), 'action' => 'moderate', 'postid' => $reportedpost->id,
                'version' => $reportedpost->version] as $field => $value) {
            echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => $field, 'value' => $value]);
        }
        echo html_writer::tag('label', 'Основание решения', ['for' => 'reason-' . (int)$reportedpost->id]);
        echo html_writer::empty_tag('input', ['name' => 'reason', 'id' => 'reason-' . (int)$reportedpost->id,
            'required' => 'required', 'class' => 'form-control']);
        foreach (['hide' => 'Скрыть', 'restore' => 'Восстановить', 'dismiss' => 'Отклонить жалобу'] as
                $decision => $label) {
            echo html_writer::tag('button', $label, ['name' => 'decision', 'value' => $decision,
                'type' => 'submit', 'class' => 'u-btn u-btn--secondary']);
        }
        echo html_writer::end_tag('form');
        echo html_writer::end_tag('article');
    }
    echo html_writer::end_div();
    echo $OUTPUT->footer();
    exit;
}
$caninteract = !\local_ustar\view_as::active();
$canwrite = $caninteract && has_capability('local/ustar:feedpublish', $context);
$managed = $canwrite ? \local_ustar\feed_access::manageable_departments($actorid) : [];
$canacademy = $canwrite && has_capability('local/ustar:feedpublishacademy', $context);
if ($canwrite) {
    echo html_writer::start_tag('form', ['method' => 'post', 'enctype' => 'multipart/form-data',
        'class' => 'u-feed__composer']);
    echo html_writer::tag('h2', 'Новая публикация');
    echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'sesskey', 'value' => sesskey()]);
    echo html_writer::tag('label', 'От чьего имени', ['for' => 'feed-publisher']);
    $publishers = ['person' => 'От моего имени'];
    if ($managed) { $publishers['department'] = 'От имени подразделения'; }
    if ($canacademy) { $publishers['academy'] = 'От имени Академии'; }
    echo html_writer::select($publishers, 'publisher', 'person', false,
        ['id' => 'feed-publisher', 'class' => 'form-select']);
    $names = \local_ustar\people::department_map(\local_ustar\structure::get(\local_ustar\structure::NAME_STRUCTURE));
    if ($managed) {
        $options = [];
        foreach ($managed as $departmentid) {
            $options[$departmentid] = (string)($names[$departmentid]['name'] ?? $departmentid);
        }
        echo html_writer::tag('label', 'Подразделение', ['for' => 'feed-department']);
        echo html_writer::select($options, 'departmentid', '', false,
            ['id' => 'feed-department', 'class' => 'form-select']);
    }
    if ($canacademy) {
        echo html_writer::tag('label', 'Аудитория публикации Академии',
            ['for' => 'feed-audience']);
        $audienceoptions = ['all' => 'Все сотрудники Академии'];
        foreach ($names as $id => $department) {
            $audienceoptions[$id] = (string)$department['name'];
        }
        echo html_writer::select($audienceoptions, 'audience[]', ['all'], false,
            ['id' => 'feed-audience', 'multiple' => 'multiple', 'class' => 'form-select']);
        echo html_writer::tag('small', 'Для выбранных подразделений снимите выделение «Все сотрудники».');
    }
    echo html_writer::tag('label', 'Текст публикации', ['for' => 'feed-body']);
    echo html_writer::tag('textarea', '', ['name' => 'body', 'id' => 'feed-body',
        'maxlength' => 5000, 'required' => 'required', 'class' => 'form-control', 'rows' => 4]);
    echo html_writer::tag('label', 'Изображения и файлы (до 10, по 50 МБ)', ['for' => 'feed-files']);
    echo html_writer::empty_tag('input', ['type' => 'file', 'name' => 'attachments[]',
        'id' => 'feed-files', 'multiple' => 'multiple', 'class' => 'form-control']);
    echo html_writer::start_div('u-feed__actions');
    echo html_writer::tag('button', 'Опубликовать', ['name' => 'action', 'value' => 'publish', 'type' => 'submit', 'class' => 'u-btn']);
    echo html_writer::tag('button', 'Сохранить черновик', ['name' => 'action', 'value' => 'draft', 'type' => 'submit', 'class' => 'u-btn u-btn--secondary']);
    echo html_writer::end_div();
    echo html_writer::end_tag('form');
}

echo html_writer::start_tag('nav', ['class' => 'u-feed__filters', 'aria-label' => 'Фильтр ленты']);
foreach (['all' => 'Все доступные', 'academy' => 'Академия', 'department' => 'Моё подразделение',
    'mine' => 'Мои публикации'] as $key => $label) {
    if ($key === 'mine' && \local_ustar\view_as::active()) { continue; }
    echo html_writer::link(new moodle_url('/local/ustar/feed.php', ['filter' => $key]), $label,
        ['class' => $filter === $key ? 'is-active' : '', 'aria-current' => $filter === $key ? 'page' : null]);
}
echo html_writer::end_tag('nav');
if ($filter === 'mine') {
    $draftpage = max(0, min(10000, optional_param('draftpage', 0, PARAM_INT)));
    $drafts = array_values($DB->get_records('local_ustar_feed_posts',
        ['actoruserid' => $actorid, 'status' => 'draft'], 'id DESC',
        'id,body,timemodified', $draftpage * 20, 21));
    if ($drafts) {
        echo html_writer::start_tag('section', ['class' => 'u-feed__drafts']);
        echo html_writer::tag('h2', 'Мои черновики');
        foreach (array_slice($drafts, 0, 20) as $draft) {
            echo html_writer::link(new moodle_url('/local/ustar/feed.php',
                ['postid' => (int)$draft->id, 'filter' => 'mine']),
                s(\core_text::substr((string)$draft->body, 0, 110)) . ' · ' .
                userdate((int)$draft->timemodified), ['class' => 'u-feed__draft']);
        }
        if (count($drafts) > 20) {
            echo html_writer::link(new moodle_url('/local/ustar/feed.php',
                ['filter' => 'mine', 'draftpage' => $draftpage + 1]), 'Ещё черновики',
                ['class' => 'u-feed__source']);
        }
        echo html_writer::end_tag('section');
    }
}
foreach ($posts as $post) {
    $postid = (int)$post->id;
    $author = $users[(int)$post->actoruserid] ?? null;
    $name = $post->publishertype === 'academy' ? 'Академия USTAR' :
        ($post->publishertype === 'department' ? (string)$post->publisherid :
            ($author ? fullname($author) : 'Сотрудник'));
    echo html_writer::start_tag('article', ['class' => 'u-feed__post', 'id' => 'post-' . $postid]);
    echo html_writer::start_div('u-feed__meta');
    echo html_writer::tag('strong', s($name));
    echo html_writer::tag('time', userdate((int)$post->publishedat),
        ['datetime' => date('c', (int)$post->publishedat)]);
    if ((int)$post->timemodified > (int)$post->publishedat) {
        echo html_writer::tag('span', 'Изменено');
    }
    echo html_writer::end_div();
    echo html_writer::tag('div', nl2br(s((string)$post->body)), ['class' => 'u-feed__body']);
    if ($post->status === 'published' && $selected && (int)$selected->id === $postid) {
        foreach (\local_ustar\feed_files::list_for($postid, $actorid) as $attachment) {
            if ($attachment['image']) {
                echo html_writer::empty_tag('img', ['src' => $attachment['url'],
                    'alt' => $attachment['name'], 'loading' => 'lazy', 'class' => 'u-feed__image']);
                continue;
            }
            echo html_writer::link($attachment['url'], s($attachment['name']),
                ['class' => 'u-feed__attachment', 'download' => $attachment['name']]);
        }
    }
    if ($post->sourcepostid) {
        echo html_writer::link(new moodle_url('/local/ustar/feed.php', ['postid' => (int)$post->sourcepostid]),
            'Исходная публикация', ['class' => 'u-feed__source']);
    }
    if ($post->status === 'draft') {
        echo html_writer::tag('p', 'Черновик · виден только вам', ['class' => 'u-feed__source']);
    }
    echo html_writer::start_tag('form', ['method' => 'post', 'class' => 'u-feed__inline']);
    echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'sesskey', 'value' => sesskey()]);
    echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'postid', 'value' => $postid]);
    if ($caninteract && $post->status === 'published') {
        echo html_writer::tag('button', (isset($liked[$postid]) ? '♥ Нравится' : '♡ Нравится')
            . ' · ' . (int)($counts[$postid]->total ?? 0),
            ['type' => 'submit', 'name' => 'action', 'value' => isset($liked[$postid]) ? 'unlike' : 'like',
                'class' => 'u-feed__like']);
    }
    echo html_writer::end_tag('form');
    if ($post->status === 'published') {
        echo html_writer::link(new moodle_url('/local/ustar/feed.php', ['postid' => $postid]),
            'Обсуждение и репост', ['class' => 'u-feed__source']);
    }
    if ($selected && (int)$selected->id === $postid) {
        if ($canwrite && (int)$post->actoruserid === $actorid) {
            echo html_writer::start_tag('form', ['method' => 'post', 'class' => 'u-feed__edit']);
            foreach (['sesskey' => sesskey(), 'postid' => $postid,
                    'version' => (int)$post->version] as $field => $value) {
                echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => $field, 'value' => $value]);
            }
            echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'action', 'value' => 'edit']);
            echo html_writer::tag('label', 'Изменить публикацию', ['for' => 'feed-edit-' . $postid]);
            echo html_writer::tag('textarea', s((string)$post->body), ['name' => 'body',
                'id' => 'feed-edit-' . $postid, 'required' => 'required', 'maxlength' => 5000,
                'class' => 'form-control', 'rows' => 3]);
            echo html_writer::start_div('u-feed__actions');
            echo html_writer::tag('button', 'Сохранить', ['type' => 'submit', 'class' => 'u-btn']);
            if ($post->status === 'draft') {
                echo html_writer::tag('button', 'Опубликовать', ['type' => 'submit', 'name' => 'publish',
                    'value' => '1', 'class' => 'u-btn u-btn--secondary']);
            }
            echo html_writer::end_div();
            echo html_writer::end_tag('form');
            echo html_writer::start_tag('form', ['method' => 'post', 'class' => 'u-feed__inline']);
            foreach (['sesskey' => sesskey(), 'postid' => $postid,
                    'version' => (int)$post->version, 'action' => 'delete'] as $field => $value) {
                echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => $field, 'value' => $value]);
            }
            echo html_writer::tag('button', 'Удалить публикацию', ['type' => 'submit',
                'class' => 'u-feed__like']);
            echo html_writer::end_tag('form');
        }
        if ($post->status === 'published') {
            $commentpage = max(0, min(10000, optional_param('comments', 0, PARAM_INT)));
            $comments = $DB->get_records('local_ustar_feed_comments',
                ['postid' => $postid, 'status' => 'visible'], 'id DESC', '*', $commentpage * 20, 21);
            $commentauthors = [];
            if ($comments) {
                $ids = array_values(array_unique(array_map(static fn($row) => (int)$row->actoruserid, $comments)));
                [$sql, $params] = $DB->get_in_or_equal($ids, SQL_PARAMS_NAMED, 'cu');
                $commentauthors = $DB->get_records_select('user', "id {$sql}", $params,
                    '', 'id,firstname,lastname');
            }
            echo html_writer::tag('h3', 'Обсуждение');
            foreach (array_slice(array_values($comments), 0, 20) as $comment) {
                $commentauthor = $commentauthors[(int)$comment->actoruserid] ?? null;
                echo html_writer::tag('p', s($commentauthor ? fullname($commentauthor) : 'Сотрудник') .
                    ' · ' . userdate((int)$comment->timecreated), ['class' => 'u-feed__source']);
                echo html_writer::tag('p', nl2br(s((string)$comment->body)));
                if ($comment->parentid) {
                    echo html_writer::tag('small', 'Ответ на комментарий №' . (int)$comment->parentid,
                        ['class' => 'u-feed__source']);
                }
                if ($caninteract && (int)$comment->actoruserid === $actorid) {
                    echo html_writer::start_tag('form', ['method' => 'post', 'class' => 'u-feed__edit']);
                    foreach (['sesskey' => sesskey(), 'postid' => $postid,
                            'commentid' => $comment->id, 'version' => $comment->version] as $field => $value) {
                        echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => $field, 'value' => $value]);
                    }
                    echo html_writer::tag('label', 'Изменить комментарий', ['for' => 'comment-' . (int)$comment->id]);
                    echo html_writer::tag('textarea', s((string)$comment->body), ['name' => 'body',
                        'id' => 'comment-' . (int)$comment->id, 'class' => 'form-control', 'rows' => 2]);
                    echo html_writer::tag('button', 'Сохранить', ['name' => 'action', 'value' => 'commentedit',
                        'type' => 'submit', 'class' => 'u-btn u-btn--secondary']);
                    echo html_writer::tag('button', 'Удалить', ['name' => 'action', 'value' => 'commentdelete',
                        'type' => 'submit', 'class' => 'u-feed__like', 'formnovalidate' => 'formnovalidate']);
                    echo html_writer::end_tag('form');
                }
                if ($caninteract && !$comment->parentid) {
                    echo html_writer::start_tag('form', ['method' => 'post', 'class' => 'u-feed__edit']);
                    foreach (['sesskey' => sesskey(), 'postid' => $postid,
                            'parentid' => $comment->id, 'action' => 'comment'] as $field => $value) {
                        echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => $field, 'value' => $value]);
                    }
                    echo html_writer::tag('label', 'Ответить', ['for' => 'reply-' . (int)$comment->id]);
                    echo html_writer::empty_tag('input', ['name' => 'body', 'id' => 'reply-' . (int)$comment->id,
                        'required' => 'required', 'maxlength' => 5000, 'class' => 'form-control']);
                    echo html_writer::tag('button', 'Ответить', ['type' => 'submit', 'class' => 'u-feed__like']);
                    echo html_writer::end_tag('form');
                }
            }
            if (count($comments) > 20) {
                echo html_writer::link(new moodle_url('/local/ustar/feed.php',
                    ['postid' => $postid, 'comments' => $commentpage + 1]), 'Следующие комментарии');
            }
            if ($caninteract) {
                echo html_writer::start_tag('form', ['method' => 'post', 'class' => 'u-feed__edit']);
                foreach (['sesskey' => sesskey(), 'action' => 'report', 'postid' => $postid] as
                        $field => $value) {
                    echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => $field, 'value' => $value]);
                }
                echo html_writer::tag('label', 'Сообщить о проблеме с публикацией', ['for' => 'feed-reason']);
                echo html_writer::empty_tag('input', ['name' => 'reason', 'id' => 'feed-reason',
                    'required' => 'required', 'class' => 'form-control']);
                echo html_writer::tag('button', 'Отправить жалобу', ['type' => 'submit',
                    'class' => 'u-btn u-btn--secondary']);
                echo html_writer::end_tag('form');
                echo html_writer::start_tag('form', ['method' => 'post', 'class' => 'u-feed__edit']);
                echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'sesskey', 'value' => sesskey()]);
                echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'postid', 'value' => $postid]);
                echo html_writer::tag('label', 'Ваш комментарий', ['for' => 'feed-comment']);
                echo html_writer::tag('textarea', '', ['name' => 'body', 'id' => 'feed-comment',
                    'required' => 'required', 'maxlength' => 5000, 'class' => 'form-control', 'rows' => 2]);
                echo html_writer::tag('button', 'Отправить комментарий', ['type' => 'submit',
                    'name' => 'action', 'value' => 'comment', 'class' => 'u-btn']);
                echo html_writer::end_tag('form');
                if ($canwrite && !$post->sourcepostid) {
                    echo html_writer::start_tag('form', ['method' => 'post', 'class' => 'u-feed__edit']);
                    echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'sesskey', 'value' => sesskey()]);
                    echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'sourceid', 'value' => $postid]);
                    echo html_writer::tag('label', 'Добавить подпись к репосту', ['for' => 'feed-repost']);
                    echo html_writer::tag('textarea', '', ['name' => 'body', 'id' => 'feed-repost',
                        'required' => 'required', 'maxlength' => 5000, 'class' => 'form-control', 'rows' => 2]);
                    echo html_writer::tag('button', 'Поделиться от моего имени', ['type' => 'submit',
                        'name' => 'action', 'value' => 'repost', 'class' => 'u-btn u-btn--secondary']);
                    echo html_writer::end_tag('form');
                }
            }
        }
    }
    echo html_writer::end_tag('article');
}
if (!$posts) {
    echo html_writer::tag('p', 'Пока нет публикаций в этом разделе.', ['class' => 'u-feed__empty']);
}
if ($page['hasmore']) {
    echo html_writer::link(new moodle_url('/local/ustar/feed.php', ['filter' => $filter,
        'beforetime' => $page['nexttime'], 'beforeid' => $page['nextid']]), 'Показать ещё',
        ['class' => 'u-btn u-feed__more']);
}
echo html_writer::end_div();
echo $OUTPUT->footer();
