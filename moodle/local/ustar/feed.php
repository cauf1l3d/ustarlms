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
$PAGE->set_pagelayout('ustar');
$PAGE->requires->css(new moodle_url('/local/ustar/styles/feed.css', ['v' => '20260928-rss-stage3']));
$PAGE->requires->js(new moodle_url('/local/ustar/js/feed.js', ['v' => '20260928-rss-stage3']));
$notice = '';
$actorid = (int)$USER->id;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_sesskey();
    $ajaxlike = optional_param('ajaxlike', 0, PARAM_BOOL);
    try {
        $action = required_param('action', PARAM_ALPHA);
        if ($ajaxlike && !in_array($action, ['like', 'unlike'], true)) {
            throw new invalid_parameter_exception('Неизвестное действие.');
        }
        if ($action === 'publish' || $action === 'draft' || $action === 'repost') {
            $publisher = optional_param('publisher', 'person', PARAM_ALPHA);
            $publisherid = $publisher === 'person' ? (string)$actorid :
                ($publisher === 'academy' ? 'academy' : optional_param('departmentid', '', PARAM_ALPHANUMEXT));
            $audiencemode = optional_param('audiencemode', 'all', PARAM_ALPHA);
            if (!in_array($audiencemode, ['all', 'mydepartment', 'departments',
                    'managers', 'positions', 'people'], true)) {
                throw new invalid_parameter_exception('Неизвестная аудитория публикации.');
            }
            $audience = match ($publisher) {
                'department' => [$publisherid],
                'person', 'academy' => match ($audiencemode) {
                    'all' => ['all'],
                    'mydepartment' => $publisher === 'person'
                        ? [\local_ustar\feed_access::department_id($actorid)] : [],
                    'departments' => $publisher === 'academy'
                        ? optional_param_array('audience', [], PARAM_ALPHANUMEXT) : [],
                    'managers' => ['manager:all'],
                    'positions' => array_map(static fn($id) => 'position:' . $id,
                        optional_param_array('positions', [], PARAM_ALPHANUMEXT)),
                    'people' => array_map(static fn($id) => 'user:' . $id,
                        optional_param_array('people', [], PARAM_INT)),
                    default => [],
                },
                default => [],
            };
            $postid = \local_ustar\feed_service::create($actorid, $publisher, $publisherid,
                $audience, required_param('body', PARAM_TEXT), $action !== 'draft',
                $action === 'repost' ? required_param('sourceid', PARAM_INT) : 0,
                \local_ustar\task_files::uploaded(), required_param('requestkey', PARAM_ALPHANUMEXT));
            redirect(new moodle_url('/local/ustar/feed.php', ['postid' => $postid]));
        }
        $postid = required_param('postid', PARAM_INT);
        if ($action === 'like' || $action === 'unlike') {
            \local_ustar\feed_service::like($postid, $actorid, $action === 'like');
            if ($ajaxlike) {
                $liked = $DB->record_exists('local_ustar_feed_reactions',
                    ['postid' => $postid, 'userid' => $actorid, 'kind' => 'like']);
                $count = (int)$DB->count_records('local_ustar_feed_reactions',
                    ['postid' => $postid, 'kind' => 'like']);
                header('Content-Type: application/json; charset=utf-8');
                header('Cache-Control: no-store');
                echo json_encode(['ok' => true, 'liked' => $liked, 'count' => $count]);
                exit;
            }
        } else if ($action === 'comment') {
            \local_ustar\feed_service::comment($postid, $actorid,
                required_param('body', PARAM_TEXT), optional_param('parentid', 0, PARAM_INT));
        } else if ($action === 'edit') {
            \local_ustar\feed_service::revise($postid, $actorid, required_param('version', PARAM_INT),
                required_param('body', PARAM_TEXT), optional_param('publish', 0, PARAM_BOOL));
        } else if ($action === 'delete') {
            \local_ustar\feed_service::remove($postid, $actorid, required_param('version', PARAM_INT),
                optional_param('reason', '', PARAM_TEXT));
            redirect(new moodle_url('/local/ustar/feed.php'));
        } else if ($action === 'commentedit' || $action === 'commentdelete') {
            \local_ustar\feed_service::revise_comment(required_param('commentid', PARAM_INT),
                $postid, $actorid, required_param('version', PARAM_INT),
                optional_param('body', '', PARAM_TEXT), $action === 'commentdelete');
        } else if ($action === 'report') {
            \local_ustar\feed_service::report($postid, $actorid, required_param('reason', PARAM_TEXT));
        } else if ($action === 'savefile') {
            \local_ustar\feed_files::save_to_library($postid, $actorid,
                required_param('fileid', PARAM_INT));
            $libraryurl = new moodle_url('/local/ustar/knowledge.php',
                ['view' => 'knowledge', 'theme' => 'ustar']);
            $libraryurl->set_anchor('saved-feed-files');
            redirect($libraryurl);
        } else if ($action === 'moderate') {
            \local_ustar\feed_service::moderate($postid, $actorid,
                required_param('version', PARAM_INT), required_param('decision', PARAM_ALPHA),
                required_param('reason', PARAM_TEXT));
            redirect(new moodle_url('/local/ustar/feed.php', ['moderation' => 1]));
        } else {
            throw new invalid_parameter_exception('Неизвестное действие.');
        }
        redirect(new moodle_url('/local/ustar/feed.php', ['postid' => $postid]));
    } catch (\invalid_parameter_exception | \required_capability_exception $e) {
        $notice = $e->getMessage();
    } catch (\Throwable $e) {
        // A database or file-storage failure must never be rendered to employees.
        error_log('USTAR feed action failed: ' . get_class($e));
        $notice = 'Действие не выполнено. Повторите попытку позже.';
    }
    if ($ajaxlike) {
        http_response_code(400);
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');
        echo json_encode(['ok' => false, 'message' => $notice]);
        exit;
    }
}

$filter = optional_param('filter', 'all', PARAM_ALPHA);
$beforetime = max(0, optional_param('beforetime', 0, PARAM_INT));
$beforeid = max(0, optional_param('beforeid', 0, PARAM_INT));
$selectedid = max(0, optional_param('postid', 0, PARAM_INT));
$compose = optional_param('compose', 0, PARAM_BOOL);
$moderation = optional_param('moderation', 0, PARAM_BOOL) &&
    \local_ustar\feed_access::can_moderate($actorid);
$departmentnames = \local_ustar\people::department_map(
    \local_ustar\structure::get(\local_ustar\structure::NAME_STRUCTURE));
if ($compose) {
    \local_ustar\feed_access::require_creator($actorid);
}
$selected = null;
if ($selectedid) {
    if (!\local_ustar\view_as::active()) {
        $candidate = $DB->get_record('local_ustar_feed_posts', ['id' => $selectedid]);
        if ($candidate && $candidate->status === 'draft'
                && ((int)$candidate->actoruserid === $actorid
                    || \local_ustar\feed_access::can_edit($actorid))) {
            $selected = $candidate;
        }
    }
    $selected = $selected ?: \local_ustar\feed_access::readable($selectedid, $actorid);
}
echo $OUTPUT->header();
echo html_writer::start_div('u-feed');
echo html_writer::start_div('u-feed__head');
echo html_writer::start_div();
echo html_writer::tag('h1', $compose ? 'Новая публикация' : ($moderation ? 'Модерация' : 'Лента'));
echo html_writer::tag('p', $compose ? 'Поделитесь новостью с коллегами.' :
    ($moderation ? 'Разберите обращения и скрытые публикации.' :
        'Новости Академии и вашей команды в одном месте.'), ['class' => 'u-feed__intro']);
echo html_writer::end_div();
echo html_writer::start_div('u-feed__head-actions');
if ($compose || $moderation) {
    echo html_writer::link(new moodle_url('/local/ustar/feed.php'), '← К ленте',
        ['class' => 'u-btn u-btn--secondary']);
} else if (!\local_ustar\view_as::active() && \local_ustar\feed_access::can_create($actorid)) {
    echo html_writer::link(new moodle_url('/local/ustar/feed.php', ['compose' => 1]),
        '＋ Создать запись', ['class' => 'u-btn u-feed__create']);
}
if (\local_ustar\feed_access::can_manage($actorid)) {
    echo html_writer::link(new moodle_url('/local/ustar/feed_admin.php'),
        'Управление', ['class' => 'u-btn u-btn--secondary']);
}
echo html_writer::end_div();
echo html_writer::end_div();
if ($notice !== '') {
    echo $OUTPUT->notification(s($notice), 'notifyproblem');
}
if (!$compose && \local_ustar\feed_access::can_moderate($actorid)) {
    echo html_writer::start_div('u-feed__moderation-links');
    echo html_writer::link(new moodle_url('/local/ustar/feed.php', ['moderation' => 1]),
        'Очередь модерации', ['class' => 'u-feed__source']);
    echo ' · ';
    echo html_writer::link(new moodle_url('/local/ustar/feed.php', ['moderation' => 1, 'hidden' => 1]),
        'Скрытые публикации', ['class' => 'u-feed__source']);
    echo html_writer::end_div();
}
if ($moderation) {
    $hiddenmode = optional_param('hidden', 0, PARAM_BOOL);
    echo html_writer::tag('h2', $hiddenmode ? 'Скрытые публикации' : 'Обращения по публикациям');
    $beforepost = max(0, optional_param('beforepost', 0, PARAM_INT));
    $reportparams = $hiddenmode ? ['hidden' => 'hidden'] : ['open' => 'open'];
    $beforewhere = '';
    if ($beforepost) {
        $beforewhere = 'AND p.id < :beforepost';
        $reportparams['beforepost'] = $beforepost;
    }
    $selection = $hiddenmode ? 'p.status = :hidden' :
        'EXISTS (SELECT 1 FROM {local_ustar_feed_reports} r WHERE r.postid = p.id AND r.status = :open)';
    $reported = array_values($DB->get_records_sql("SELECT p.* FROM {local_ustar_feed_posts} p
        WHERE {$selection}
        {$beforewhere} ORDER BY p.id DESC", $reportparams, 0, 31));
    $more = count($reported) > 30;
    if ($more) { array_pop($reported); }
    $reportsbyid = [];
    if ($reported) {
        [$reportinsql, $reportinparams] = $DB->get_in_or_equal(array_map(
            static fn($post) => (int)$post->id, $reported), SQL_PARAMS_NAMED, 'mr');
        $reportsbyid = $DB->get_records_sql("SELECT postid AS id, COUNT(*) AS total,
            MAX(id) AS latestid FROM {local_ustar_feed_reports}
            WHERE postid {$reportinsql} AND status = :status GROUP BY postid",
            $reportinparams + ['status' => 'open']);
        $lastids = array_map(static fn($report) => (int)$report->latestid, $reportsbyid);
        $lastreasons = $lastids ? $DB->get_records_list('local_ustar_feed_reports',
            'id', $lastids, '', 'id,reason') : [];
        foreach ($reportsbyid as $report) {
            $report->reason = (string)($lastreasons[(int)$report->latestid]->reason ?? '');
        }
    }
    foreach ($reported as $reportedpost) {
        echo html_writer::start_tag('article', ['class' => 'u-feed__post']);
        echo html_writer::tag('h3', 'Публикация №' . (int)$reportedpost->id . ' · ' . s($reportedpost->status));
        echo html_writer::tag('p', nl2br(s((string)$reportedpost->body)));
        if (isset($reportsbyid[(int)$reportedpost->id])) {
            $report = $reportsbyid[(int)$reportedpost->id];
            echo html_writer::tag('p', 'Жалоб: ' . (int)$report->total . '. Последняя причина: ' .
                s($report->reason), ['class' => 'u-feed__source']);
        }
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
            if (($decision === 'restore') !== ($reportedpost->status === 'hidden')
                    && $decision !== 'dismiss') { continue; }
            echo html_writer::tag('button', $label, ['name' => 'decision', 'value' => $decision,
                'type' => 'submit', 'class' => 'u-btn u-btn--secondary']);
        }
        echo html_writer::end_tag('form');
        echo html_writer::end_tag('article');
    }
    if ($more && $reported) {
        $last = end($reported);
        echo html_writer::link(new moodle_url('/local/ustar/feed.php',
            ['moderation' => 1, 'hidden' => $hiddenmode, 'beforepost' => (int)$last->id]),
            'Следующие обращения',
            ['class' => 'u-btn u-feed__more']);
    }
    echo html_writer::end_div();
    echo $OUTPUT->footer();
    exit;
}
$caninteract = !\local_ustar\view_as::active();
$cancreate = $caninteract && \local_ustar\feed_access::can_create($actorid);
$canpublish = $caninteract && \local_ustar\feed_access::can_publish($actorid);
$canedit = $caninteract && \local_ustar\feed_access::can_edit($actorid);
$canwrite = $cancreate;
$managed = $canpublish ? \local_ustar\feed_access::manageable_departments($actorid) : [];
$canacademy = $canpublish && (\local_ustar\feed_access::can_manage($actorid)
    || has_capability('local/ustar:feedpublishacademy', $context));
$canaudience = $canacademy && (\local_ustar\feed_access::can_manage($actorid)
    || has_capability('local/ustar:feedsetaudience', $context));
if ($compose && $cancreate) {
    echo html_writer::start_tag('form', ['method' => 'post', 'enctype' => 'multipart/form-data',
        'class' => 'u-feed__composer']);
    echo html_writer::tag('h2', 'Расскажите о важном');
    echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'sesskey', 'value' => sesskey()]);
    echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'requestkey',
        'value' => bin2hex(random_bytes(16))]);
    echo html_writer::tag('label', 'Категория публикации', ['for' => 'feed-publisher']);
    $publishers = ['person' => 'От моего имени'];
    if ($managed) { $publishers['department'] = 'Новости подразделения'; }
    if ($canacademy) { $publishers['academy'] = 'Новости Академии'; }
    echo html_writer::select($publishers, 'publisher', 'person', false,
        ['id' => 'feed-publisher', 'class' => 'form-select']);
    echo html_writer::tag('p', 'Выберите автора и круг сотрудников, которым будет доступна запись.',
        ['class' => 'u-feed__hint']);
    if ($managed) {
        $options = [];
        foreach ($managed as $departmentid) {
            $options[$departmentid] = (string)($departmentnames[$departmentid]['name'] ?? 'Подразделение');
        }
        echo html_writer::start_div('', ['data-feed-publisher-field' => 'department']);
        echo html_writer::tag('label', 'Подразделение', ['for' => 'feed-department']);
        echo html_writer::select($options, 'departmentid', '', false,
            ['id' => 'feed-department', 'class' => 'form-select']);
        echo html_writer::end_div();
    }
    echo html_writer::start_tag('fieldset', ['class' => 'u-feed__audience',
        'data-feed-audience' => '1', 'data-feed-can-academy-audience' => $canaudience ? '1' : '0']);
    echo html_writer::tag('legend', 'Кто увидит публикацию');
    $modes = ['all' => 'Все сотрудники', 'mydepartment' => 'Моё подразделение',
        'departments' => 'Выбранные подразделения', 'managers' => 'Все руководители',
        'positions' => 'Выбранные должности', 'people' => 'Конкретные сотрудники'];
    if (\local_ustar\feed_access::department_id($actorid) === '') {
        unset($modes['mydepartment']);
    }
    foreach ($modes as $mode => $label) {
        echo html_writer::start_tag('label', ['data-feed-mode-for' => $mode === 'mydepartment' ? 'person' :
            ($mode === 'departments' ? 'academy' : 'both')]);
        echo html_writer::empty_tag('input', ['type' => 'radio', 'name' => 'audiencemode',
            'value' => $mode, 'checked' => $mode === 'all' ? 'checked' : null]);
        echo ' ' . s($label);
        echo html_writer::end_tag('label');
    }
    echo html_writer::start_div('u-feed__audience-options', ['data-feed-audience-options' => 'departments']);
    foreach ($departmentnames as $id => $department) {
        echo html_writer::start_tag('label');
        echo html_writer::empty_tag('input', ['type' => 'checkbox', 'name' => 'audience[]',
            'value' => $id]);
        echo ' ' . s((string)$department['name']);
        echo html_writer::end_tag('label');
    }
    echo html_writer::end_div();
    echo html_writer::start_div('u-feed__audience-options', ['data-feed-audience-options' => 'positions']);
    $positions = \local_ustar\people::position_map(
        \local_ustar\structure::get(\local_ustar\structure::NAME_STRUCTURE));
    foreach ($positions as $id => $position) {
        echo html_writer::start_tag('label');
        echo html_writer::empty_tag('input', ['type' => 'checkbox', 'name' => 'positions[]',
            'value' => $id]);
        echo ' ' . s((string)($position['name'] ?? $id));
        echo html_writer::end_tag('label');
    }
    echo html_writer::end_div();
    echo html_writer::start_div('u-feed__people', ['data-feed-audience-options' => 'people',
        'data-search-url' => (new moodle_url('/local/ustar/feed_people.php'))->out(false)]);
    echo html_writer::tag('label', 'Найти сотрудника', ['for' => 'feed-people-search']);
    echo html_writer::empty_tag('input', ['type' => 'search', 'id' => 'feed-people-search',
        'autocomplete' => 'off', 'placeholder' => 'Имя или логин, от 2 символов', 'class' => 'form-control']);
    echo html_writer::start_div('u-feed__people-results', ['data-feed-people-results' => '1',
        'role' => 'status', 'aria-live' => 'polite']);
    echo html_writer::end_div();
    echo html_writer::start_div('u-feed__people-selected', ['data-feed-people-selected' => '1']);
    echo html_writer::end_div();
    echo html_writer::end_div();
    echo html_writer::end_tag('fieldset');
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
    echo html_writer::end_div();
    echo $OUTPUT->footer();
    exit;
}

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
$sourceids = array_values(array_unique(array_filter(array_map(
    static fn($post) => (int)$post->sourcepostid, $posts))));
$sources = [];
if ($sourceids) {
    [$sourcesql, $sourceparams] = $DB->get_in_or_equal($sourceids, SQL_PARAMS_NAMED, 'src');
    $sourceparams['status'] = 'published';
    $sourceaudience = \local_ustar\feed_access::audience_sql($actorid, 'src', $sourceparams, 'preview');
    $sources = $DB->get_records_sql("SELECT src.* FROM {local_ustar_feed_posts} src
        WHERE src.id {$sourcesql} AND src.status = :status AND {$sourceaudience}", $sourceparams);
    $posts = array_values(array_filter($posts, static fn($post) => !$post->sourcepostid ||
        isset($sources[(int)$post->sourcepostid])));
    $postids = array_map(static fn($post) => (int)$post->id, $posts);
}
$attachments = \local_ustar\feed_files::list_for_visible_posts(
    array_merge($postids, array_keys($sources)), $actorid);
$counts = [];
$liked = [];
$commentcounts = [];
$users = [];
if ($postids) {
    [$insql, $inparams] = $DB->get_in_or_equal($postids, SQL_PARAMS_NAMED, 'fp');
    $counts = $DB->get_records_sql("SELECT postid AS id, COUNT(id) AS total
          FROM {local_ustar_feed_reactions} WHERE postid {$insql} AND kind = :kind GROUP BY postid",
        $inparams + ['kind' => 'like']);
    $liked = $DB->get_records_sql("SELECT postid AS id FROM {local_ustar_feed_reactions}
          WHERE postid {$insql} AND userid = :userid AND kind = :kind",
        $inparams + ['userid' => $actorid, 'kind' => 'like']);
    $commentcounts = $DB->get_records_sql("SELECT postid AS id, COUNT(id) AS total
          FROM {local_ustar_feed_comments} WHERE postid {$insql} AND status = :visible GROUP BY postid",
        $inparams + ['visible' => 'visible']);
    $authorids = array_values(array_unique(array_map(static fn($post) => (int)$post->actoruserid,
        array_merge($posts, array_values($sources)))));
    [$usersql, $userparams] = $DB->get_in_or_equal($authorids, SQL_PARAMS_NAMED, 'fu');
    $users = $DB->get_records_select('user', "id {$usersql}", $userparams, '', 'id,firstname,lastname');
}

$rssmeta = \local_ustar\feed_rss::metadata_for_posts(array_merge($postids, array_keys($sources)));

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
$cansaveattachments = $caninteract && \local_ustar\employment::is_active($actorid)
    && \local_ustar\accounts::participates($actorid);
$academyiconurl = $OUTPUT->image_url('brand/ustar-app-icon', 'theme_ustar')->out(false);
$renderattachment = static function(array $attachment, int $sourcepostid) use ($cansaveattachments): void {
    echo html_writer::start_tag('figure', ['class' => 'u-feed__file']);
    if ($attachment['image']) {
        echo html_writer::link($attachment['url'],
            html_writer::empty_tag('img', ['src' => $attachment['url'],
                'alt' => $attachment['name'], 'loading' => 'lazy', 'class' => 'u-feed__image']),
            ['class' => 'u-feed__image-link', 'target' => '_blank', 'rel' => 'noopener']);
    } else {
        echo html_writer::tag('span', s($attachment['name']) . ' · ' .
            display_size($attachment['size']), ['class' => 'u-feed__attachment']);
    }
    echo html_writer::start_tag('figcaption', ['class' => 'u-feed__file-actions']);
    echo html_writer::link($attachment['downloadurl'], '↓ Скачать',
        ['class' => 'u-feed__file-action', 'download' => $attachment['name']]);
    if ($cansaveattachments) {
        echo html_writer::start_tag('form', ['method' => 'post', 'class' => 'u-feed__inline']);
        foreach (['sesskey' => sesskey(), 'action' => 'savefile', 'postid' => $sourcepostid,
                'fileid' => $attachment['id']] as $field => $value) {
            echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => $field, 'value' => $value]);
        }
        echo html_writer::tag('button', '＋ В мою библиотеку',
            ['type' => 'submit', 'class' => 'u-feed__file-action']);
        echo html_writer::end_tag('form');
    }
    echo html_writer::end_tag('figcaption');
    echo html_writer::end_tag('figure');
};
foreach ($posts as $post) {
    $postid = (int)$post->id;
    $author = $users[(int)$post->actoruserid] ?? null;
    $external = $post->publishertype === 'external' ? ($rssmeta[$postid] ?? null) : null;
    $name = $post->publishertype === 'academy' ? 'Академия USTAR' :
        ($post->publishertype === 'department'
            ? (string)($departmentnames[(string)$post->publisherid]['name'] ?? 'Подразделение') :
            ($post->publishertype === 'external'
                ? (string)($external->sourcename ?? 'Внешний источник')
                : ($author ? fullname($author) : 'Сотрудник')));
    echo html_writer::start_tag('article', ['class' => 'u-feed__post', 'id' => 'post-' . $postid]);
    echo html_writer::start_div('u-feed__meta');
    if ($post->publishertype === 'academy') {
        echo html_writer::tag('span', html_writer::empty_tag('img', [
            'src' => $academyiconurl,
            'alt' => '', 'class' => 'u-feed__avatar-image']),
            ['class' => 'u-feed__avatar u-feed__avatar--academy', 'aria-hidden' => 'true']);
    } else {
        echo html_writer::tag('span', s(\core_text::substr($name, 0, 1)),
            ['class' => 'u-feed__avatar', 'aria-hidden' => 'true']);
    }
    echo html_writer::start_div('u-feed__byline');
    echo html_writer::tag('strong', s($name));
    echo html_writer::tag('span', $post->publishertype === 'academy' ? 'Академия' :
        ($post->publishertype === 'department' ? 'Подразделение' :
            ($post->publishertype === 'external' ? 'Внешний источник' : 'Личный пост')),
        ['class' => 'u-feed__badge']);
    $displaytime = $post->status === 'draft' ? (int)$post->timemodified : (int)$post->publishedat;
    echo html_writer::tag('time', userdate($displaytime),
        ['datetime' => date('c', $displaytime)]);
    if ($post->publishertype !== 'external' && $post->status !== 'draft'
            && (int)$post->timemodified > (int)$post->publishedat) {
        echo html_writer::tag('span', 'Изменено');
    }
    echo html_writer::end_div();
    echo html_writer::end_div();
    $externalopen = $external && $selectedid === $postid;
    $externalcontenturl = $external
        ? (new moodle_url('/local/ustar/feed_external_content.php', ['postid' => $postid]))->out(false)
        : '';
    if ($external) {
        $internalurl = (new moodle_url('/local/ustar/feed.php',
            ['postid' => $postid, 'filter' => $filter]))->out(false) . '#post-' . $postid;
        echo html_writer::tag('h3',
            html_writer::link($internalurl, s((string)$external->title), [
                'class' => 'u-feed__external-title-button',
                'data-feed-external-toggle' => 'feed-external-' . $postid,
                'data-feed-external-content-url' => $externalcontenturl,
                'aria-expanded' => $externalopen ? 'true' : 'false',
            ]),
            ['class' => 'u-feed__external-title']);
    }
    echo html_writer::tag('div', nl2br(s((string)$post->body)), ['class' => 'u-feed__body']);
    if ($post->status === 'published' && !empty($attachments[$postid])) {
        echo html_writer::start_div('u-feed__media');
        foreach ($attachments[$postid] as $attachment) {
            $renderattachment($attachment, $postid);
        }
        echo html_writer::end_div();
    }
    if ($external) {
        $externalpayload = $externalopen
            ? \local_ustar\feed_external_content::payload($postid, $actorid)
            : null;
        echo html_writer::start_div('u-feed__external-detail', [
            'id' => 'feed-external-' . $postid,
            'hidden' => $externalopen ? null : 'hidden',
            'data-feed-external-panel' => '1',
            'data-feed-loaded' => $externalopen ? '1' : '0',
        ]);
        echo html_writer::start_div('u-feed__external-body', ['data-feed-external-body' => '1']);
        if ($externalpayload) {
            echo $externalpayload['html'];
        } else {
            echo html_writer::tag('p', 'Загрузка полного материала…', ['class' => 'u-feed__source']);
        }
        echo html_writer::end_div();
        echo html_writer::link((string)$external->externalurl,
            'Оригинал на ' . s((string)$external->sourcename) . ' ↗', [
                'class' => 'u-feed__external-origin',
                'target' => '_blank',
                'rel' => 'noopener noreferrer',
            ]);
        echo html_writer::end_div();
    }
    if ($post->sourcepostid) {
        $source = $sources[(int)$post->sourcepostid] ?? null;
        if ($source) {
            $sourceauthor = $users[(int)$source->actoruserid] ?? null;
            $sourceexternal = $source->publishertype === 'external'
                ? ($rssmeta[(int)$source->id] ?? null) : null;
            $sourcename = $source->publishertype === 'academy' ? 'Академия USTAR' :
                ($source->publishertype === 'department'
                    ? (string)($departmentnames[(string)$source->publisherid]['name'] ?? 'Подразделение') :
                    ($source->publishertype === 'external'
                        ? (string)($sourceexternal->sourcename ?? 'Внешний источник')
                        : ($sourceauthor ? fullname($sourceauthor) : 'Сотрудник')));
            echo html_writer::start_div('u-feed__repost');
            echo html_writer::tag('strong', s($sourcename));
            if ($sourceexternal) {
                echo html_writer::tag('h4', s((string)$sourceexternal->title));
            }
            echo html_writer::tag('p', nl2br(s((string)$source->body)));
            foreach ($attachments[(int)$source->id] ?? [] as $attachment) {
                $renderattachment($attachment, (int)$source->id);
            }
            echo html_writer::link(new moodle_url('/local/ustar/feed.php', ['postid' => (int)$source->id]),
                'Открыть оригинал', ['class' => 'u-feed__source']);
            echo html_writer::end_div();
        }
    }
    if ($post->status === 'draft') {
        echo html_writer::tag('p', 'Черновик · виден только вам', ['class' => 'u-feed__source']);
    }
    if ($post->status === 'published') {
        echo html_writer::start_div('u-feed__toolbar', ['role' => 'group',
            'aria-label' => 'Действия с публикацией']);
        if ($caninteract) {
            echo html_writer::start_tag('form', ['method' => 'post', 'class' => 'u-feed__inline',
                'data-feed-like' => '1', 'data-reaction-url' =>
                    (new moodle_url('/local/ustar/feed_reaction.php'))->out(false)]);
            foreach (['sesskey' => sesskey(), 'postid' => $postid] as $field => $value) {
                echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => $field, 'value' => $value]);
            }
            echo html_writer::tag('button',
                '<span aria-hidden="true">' . (isset($liked[$postid]) ? '♥' : '♡') . '</span> '
                . 'Нравится <span data-feed-count>' . (int)($counts[$postid]->total ?? 0) . '</span>',
                ['type' => 'submit', 'name' => 'action',
                    'value' => isset($liked[$postid]) ? 'unlike' : 'like',
                    'aria-pressed' => isset($liked[$postid]) ? 'true' : 'false',
                    'class' => 'u-feed__action']);
            echo html_writer::end_tag('form');
        }
        $detailurl = new moodle_url('/local/ustar/feed.php', ['postid' => $postid]);
        echo html_writer::link($detailurl->out(false) . '#discussion-' . $postid,
            '▤ Комментарии ' . (int)($commentcounts[$postid]->total ?? 0),
            ['class' => 'u-feed__action', 'data-feed-discussion-toggle' => 'discussion-' . $postid,
                'aria-controls' => 'discussion-' . $postid,
                'aria-expanded' => ($selected && (int)$selected->id === $postid) ? 'true' : 'false']);
        if ($cancreate && !$post->sourcepostid) {
            echo html_writer::link($detailurl->out(false) . '#repost-' . $postid,
                '↗ Поделиться', ['class' => 'u-feed__action']);
        }
        if ($caninteract) {
            echo html_writer::link($detailurl->out(false) . '#report-' . $postid,
                '⋯ Пожаловаться', ['class' => 'u-feed__action u-feed__action--quiet']);
        }
        echo html_writer::end_div();
    }
    if ($selected && (int)$selected->id === $postid) {
        echo html_writer::start_tag('section', ['class' => 'u-feed__detail']);
        $isowner = (int)$post->actoruserid === $actorid;
        $caneditpost = ($isowner && $cancreate) || (!$isowner && $canedit);
        $canpublishpost = $isowner ? $cancreate : $canpublish;
        if ($caneditpost) {
            echo html_writer::start_tag('details', ['class' => 'u-feed__panel',
                'open' => $post->status === 'draft' ? 'open' : null]);
            echo html_writer::tag('summary', 'Редактировать публикацию');
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
            if ($post->status === 'draft' && $canpublishpost) {
                echo html_writer::tag('button', 'Опубликовать', ['type' => 'submit', 'name' => 'publish',
                    'value' => '1', 'class' => 'u-btn u-btn--secondary']);
            }
            echo html_writer::end_div();
            echo html_writer::end_tag('form');
            if ($isowner || \local_ustar\feed_access::can_moderate($actorid)) {
                echo html_writer::start_tag('form', ['method' => 'post', 'class' => 'u-feed__inline']);
                foreach (['sesskey' => sesskey(), 'postid' => $postid,
                        'version' => (int)$post->version, 'action' => 'delete'] as $field => $value) {
                    echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => $field, 'value' => $value]);
                }
                if (!$isowner) {
                    echo html_writer::tag('label', 'Основание удаления', ['for' => 'feed-remove-' . $postid]);
                    echo html_writer::empty_tag('input', ['name' => 'reason', 'id' => 'feed-remove-' . $postid,
                        'required' => 'required', 'class' => 'form-control']);
                }
                echo html_writer::tag('button', 'Удалить публикацию', ['type' => 'submit',
                    'class' => 'u-feed__like']);
                echo html_writer::end_tag('form');
            }
            echo html_writer::end_tag('details');
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
            echo html_writer::start_tag('div', ['id' => 'discussion-' . $postid,
                'class' => 'u-feed__discussion']);
            echo html_writer::tag('h3', 'Обсуждение · ' . (int)($commentcounts[$postid]->total ?? 0));
            if (!$comments) {
                echo html_writer::tag('p', 'Пока нет комментариев. Начните обсуждение.',
                    ['class' => 'u-feed__source']);
            }
            foreach (array_slice(array_values($comments), 0, 20) as $comment) {
                $commentauthor = $commentauthors[(int)$comment->actoruserid] ?? null;
                echo html_writer::start_div('u-feed__comment');
                echo html_writer::tag('p', s($commentauthor ? fullname($commentauthor) : 'Сотрудник') .
                    ' · ' . userdate((int)$comment->timecreated), ['class' => 'u-feed__source']);
                echo html_writer::tag('p', nl2br(s((string)$comment->body)));
                if ($comment->parentid) {
                    echo html_writer::tag('small', 'Ответ на комментарий №' . (int)$comment->parentid,
                        ['class' => 'u-feed__source']);
                }
                if ($caninteract && (int)$comment->actoruserid === $actorid) {
                    echo html_writer::start_tag('details', ['class' => 'u-feed__small-panel']);
                    echo html_writer::tag('summary', 'Изменить');
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
                    echo html_writer::end_tag('details');
                }
                if ($caninteract && !$comment->parentid) {
                    echo html_writer::start_tag('details', ['class' => 'u-feed__small-panel']);
                    echo html_writer::tag('summary', 'Ответить');
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
                    echo html_writer::end_tag('details');
                }
                echo html_writer::end_div();
            }
            if (count($comments) > 20) {
                echo html_writer::link(new moodle_url('/local/ustar/feed.php',
                    ['postid' => $postid, 'comments' => $commentpage + 1]), 'Следующие комментарии');
            }
            if ($caninteract) {
                echo html_writer::start_tag('form', ['method' => 'post', 'class' => 'u-feed__comment-form']);
                echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'sesskey', 'value' => sesskey()]);
                echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'postid', 'value' => $postid]);
                echo html_writer::tag('label', 'Ваш комментарий', ['for' => 'feed-comment-' . $postid]);
                echo html_writer::tag('textarea', '', ['name' => 'body', 'id' => 'feed-comment-' . $postid,
                    'required' => 'required', 'maxlength' => 5000, 'class' => 'form-control', 'rows' => 2,
                    'placeholder' => 'Написать комментарий…']);
                echo html_writer::tag('button', 'Отправить', ['type' => 'submit',
                    'name' => 'action', 'value' => 'comment', 'class' => 'u-btn']);
                echo html_writer::end_tag('form');
            }
            echo html_writer::end_div();
            if ($caninteract) {
                echo html_writer::start_tag('details', ['id' => 'report-' . $postid,
                    'class' => 'u-feed__panel u-feed__panel--report']);
                echo html_writer::tag('summary', 'Пожаловаться на публикацию');
                echo html_writer::start_tag('form', ['method' => 'post', 'class' => 'u-feed__edit']);
                foreach (['sesskey' => sesskey(), 'action' => 'report', 'postid' => $postid] as
                        $field => $value) {
                    echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => $field, 'value' => $value]);
                }
                echo html_writer::tag('label', 'Что произошло?', ['for' => 'feed-reason-' . $postid]);
                echo html_writer::empty_tag('input', ['name' => 'reason', 'id' => 'feed-reason-' . $postid,
                    'required' => 'required', 'class' => 'form-control']);
                echo html_writer::tag('button', 'Отправить жалобу', ['type' => 'submit',
                    'class' => 'u-btn u-btn--secondary']);
                echo html_writer::end_tag('form');
                echo html_writer::end_tag('details');
                if ($cancreate && !$post->sourcepostid) {
                    echo html_writer::start_tag('details', ['id' => 'repost-' . $postid,
                        'class' => 'u-feed__panel']);
                    echo html_writer::tag('summary', 'Поделиться записью');
                    echo html_writer::start_tag('form', ['method' => 'post', 'class' => 'u-feed__edit']);
                    echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'sesskey', 'value' => sesskey()]);
                    echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'sourceid', 'value' => $postid]);
                    echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'requestkey',
                        'value' => bin2hex(random_bytes(16))]);
                    echo html_writer::tag('label', 'Добавить подпись к репосту', ['for' => 'feed-repost']);
                    echo html_writer::tag('textarea', '', ['name' => 'body', 'id' => 'feed-repost',
                        'required' => 'required', 'maxlength' => 5000, 'class' => 'form-control', 'rows' => 2]);
                    echo html_writer::tag('button', 'Поделиться от моего имени', ['type' => 'submit',
                        'name' => 'action', 'value' => 'repost', 'class' => 'u-btn u-btn--secondary']);
                    echo html_writer::end_tag('form');
                    echo html_writer::end_tag('details');
                }
            }
        }
        echo html_writer::end_tag('section');
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
