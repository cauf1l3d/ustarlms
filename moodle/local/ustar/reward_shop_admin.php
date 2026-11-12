<?php
require_once(__DIR__.'/../../config.php');

use local_ustar\reward_shop;
use local_ustar\reward_shop_cover;

require_login();
$userid = (int)$USER->id;
if (!reward_shop::can_manage($userid)) {
    throw new moodle_exception('rewardshop_denied', 'local_ustar');
}
$section = optional_param('section', 'orders', PARAM_ALPHA);
if (!in_array($section, ['orders', 'offers', 'settings'], true)) { $section = 'orders'; }
$filter = optional_param('status', 'pending', PARAM_ALPHA);
if (!in_array($filter, ['pending', 'issued', 'rejected'], true)) { $filter = 'pending'; }
$page = max(0, min(10000, optional_param('page', 0, PARAM_INT)));
$edit = max(0, optional_param('edit', 0, PARAM_INT));
$base = new moodle_url('/local/ustar/reward_shop_admin.php');
$url = new moodle_url($base, ['section'=>$section, 'status'=>$filter, 'page'=>$page] + ($edit ? ['edit'=>$edit] : []));
$PAGE->set_context(context_system::instance());
$PAGE->set_url($url);
$PAGE->set_pagelayout('ustar');
$PAGE->set_title('Управление наградами | USTAR');
$PAGE->set_heading('Управление наградами');
$PAGE->requires->css(new moodle_url('/local/ustar/styles/ux_tools.css'));
$PAGE->requires->css(new moodle_url('/local/ustar/styles/reward_shop.css'));

$error = '';
$submittedoffer = [];
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    require_sesskey();
    try {
        $action = required_param('action', PARAM_ALPHA);
        if ($section === 'settings' && $action === 'configure') {
            reward_shop::configure($userid, optional_param('enabled', 0, PARAM_BOOL),
                optional_param_array('departments', [], PARAM_RAW), required_param('revision', PARAM_INT));
            redirect($url, 'Доступ к магазину обновлён.');
        }
        if ($section === 'offers' && $action === 'save') {
            $submittedoffer = [
                'revision'=>required_param('revision', PARAM_INT), 'title'=>required_param('title', PARAM_TEXT),
                'description'=>optional_param('description', '', PARAM_TEXT), 'price'=>required_param('price', PARAM_INT),
                'stock'=>required_param('stock', PARAM_INT), 'status'=>required_param('publication', PARAM_ALPHA),
            ];
            $upload = $_FILES['cover'] ?? [];
            $coverpath = null;
            if (($upload['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
                if ($upload['error'] !== UPLOAD_ERR_OK || !is_string($upload['tmp_name'] ?? null)
                        || !is_uploaded_file($upload['tmp_name'])) {
                    throw new moodle_exception('rewardshop_coverinvalid', 'local_ustar');
                }
                $coverpath = $upload['tmp_name'];
            }
            $saved = reward_shop::save_offer($userid, $edit, $submittedoffer['revision'],
                $submittedoffer['title'], $submittedoffer['description'], $submittedoffer['price'],
                $submittedoffer['stock'], $submittedoffer['status'],
                $coverpath, optional_param('removecover', 0, PARAM_BOOL));
            redirect(new moodle_url($base, ['section'=>'offers', 'edit'=>$saved->id]), 'Карточка награды сохранена.');
        }
        if ($section === 'orders' && in_array($action, ['issue', 'reject'], true)) {
            reward_shop::resolve($userid, required_param('orderid', PARAM_INT), $action === 'issue' ? 'issued' : 'rejected',
                optional_param('reason', '', PARAM_TEXT));
            redirect($url, $action === 'issue' ? 'Выдача отмечена.' : 'Заявка отклонена. USCOIN возвращены.');
        }
        throw new moodle_exception('rewardshop_invalid', 'local_ustar');
    } catch (moodle_exception $failure) {
        if (!str_starts_with($failure->errorcode, 'rewardshop_')) { throw $failure; }
        $error = $failure->getMessage();
    }
}

$data = [
    'url'=>$url->out(false), 'sesskey'=>sesskey(), 'error'=>$error,
    'achievementsurl'=>(new moodle_url('/local/ustar/achievements.php'))->out(false),
    'shopurl'=>(new moodle_url('/local/ustar/reward_shop.php'))->out(false),
    'canshop'=>reward_shop::can_view_history($userid),
    'show'.$section=>true, 'tabs'=>[],
];
foreach (['orders'=>'Заявки', 'offers'=>'Каталог наград', 'settings'=>'Доступ отделов'] as $key => $title) {
    $data['tabs'][] = ['title'=>$title, 'current'=>$section === $key, 'href'=>(new moodle_url($base, ['section'=>$key]))->out(false)];
}
if ($section === 'settings') {
    $settings = reward_shop::settings();
    $data += ['enabled'=>$settings['enabled'], 'revision'=>$settings['revision'], 'departments'=>[]];
    foreach (reward_shop::departments() as $id => $name) {
        $data['departments'][] = ['id'=>$id, 'name'=>$name, 'checked'=>in_array($id, $settings['departments'], true)];
    }
} else if ($section === 'offers') {
    $offer = $edit ? $DB->get_record(reward_shop::OFFER, ['id'=>$edit], '*', MUST_EXIST) : null;
    $current = (array)($offer ?? (object)['revision'=>0, 'stock'=>0, 'status'=>'draft']);
    $data['offer'] = (($submittedoffer['revision'] ?? -1) === (int)$current['revision'] ? $submittedoffer : []) + $current;
    $data['offer']['coverurl'] = $edit ? (reward_shop_cover::urls([$edit])[$edit] ?? '') : '';
    $data['offer']['editing'] = (bool)$edit;
    $data['createurl'] = (new moodle_url($base, ['section'=>'offers']))->out(false);
    $labels = ['draft'=>'Черновик', 'published'=>'Опубликована', 'archived'=>'Архив'];
    foreach ($labels as $status => $label) {
        $data['publication'][] = ['value'=>$status, 'label'=>$label, 'selected'=>$data['offer']['status'] === $status];
    }
    foreach ($DB->get_records(reward_shop::OFFER, [], 'id DESC', '*', $page * 25, 25) as $row) {
        $data['offers'][] = (array)$row + [
            'statuslabel'=>$labels[$row->status], 'editurl'=>(new moodle_url($base, ['section'=>'offers', 'edit'=>$row->id]))->out(false),
        ];
    }
    $data['paging'] = $OUTPUT->paging_bar($DB->count_records(reward_shop::OFFER), $page, 25, $url);
} else {
    foreach (['pending', 'issued', 'rejected'] as $status) {
        $data['filters'][] = [
            'title'=>get_string('rewardshop_order_'.$status, 'local_ustar'), 'current'=>$status === $filter,
            'href'=>(new moodle_url($base, ['section'=>'orders', 'status'=>$status]))->out(false),
        ];
    }
    $orders = $DB->get_records_sql('SELECT o.*, u.firstname, u.lastname FROM {'.reward_shop::ORDER.'} o
        LEFT JOIN {user} u ON u.id = o.userid AND u.deleted = 0
        WHERE o.status = :status ORDER BY o.id '.($filter === 'pending' ? 'ASC' : 'DESC'), ['status'=>$filter], $page * 25, 25);
    $data['hasorders'] = (bool)$orders;
    foreach ($orders as $order) {
        $data['orders'][] = (array)$order + [
            'employee'=>trim(($order->firstname ?? '').' '.($order->lastname ?? '')) ?: 'Профиль недоступен',
            'date'=>userdate((int)$order->timecreated), 'pending'=>$order->status === 'pending',
            'statuslabel'=>get_string('rewardshop_order_'.$order->status, 'local_ustar'),
            'resolveddate'=>$order->resolvedat ? userdate((int)$order->resolvedat) : '',
        ];
    }
    $data['paging'] = $OUTPUT->paging_bar($DB->count_records(reward_shop::ORDER, ['status'=>$filter]), $page, 25, $url);
}
echo $OUTPUT->header();
echo $OUTPUT->render_from_template('local_ustar/reward_shop_admin', $data);
echo $OUTPUT->footer();
