<?php
require_once(__DIR__.'/../../config.php');

use local_ustar\economy;
use local_ustar\reward_shop;
use local_ustar\reward_shop_cover;
use local_ustar\ui;

require_login();
$context = context_system::instance();
require_capability('local/ustar:use', $context);
$userid = (int)$USER->id;
if (!reward_shop::can_view_history($userid)) {
    throw new moodle_exception('rewardshop_denied', 'local_ustar');
}
$url = new moodle_url('/local/ustar/reward_shop.php');
$PAGE->set_context($context);
$PAGE->set_url($url);
$PAGE->set_pagelayout('ustar');
$PAGE->set_title('Магазин наград | USTAR');
$PAGE->set_heading('Магазин наград');
$PAGE->requires->css(new moodle_url('/local/ustar/styles/reward_shop.css'));

$error = '';
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    require_sesskey();
    try {
        reward_shop::request($userid, required_param('offerid', PARAM_INT), required_param('revision', PARAM_INT),
            required_param('price', PARAM_INT), required_param('nonce', PARAM_RAW));
        redirect($url, 'Заявка принята. Её статус доступен в разделе «Мои заявки».');
    } catch (moodle_exception $failure) {
        if (!str_starts_with($failure->errorcode, 'rewardshop_')) { throw $failure; }
        $error = $failure->getMessage();
    }
}
$catalogpage = max(0, min(10000, optional_param('catalogpage', 0, PARAM_INT)));
$orderpage = max(0, min(10000, optional_param('orderpage', 0, PARAM_INT)));
$showcatalog = reward_shop::can_shop($userid);
$balance = economy::balance($userid);
$offers = [];
if ($showcatalog) {
    $published = $DB->get_records(reward_shop::OFFER, ['status'=>'published'], 'title ASC, id ASC', '*', $catalogpage * 24, 24);
    $covers = reward_shop_cover::urls(array_keys($published));
    foreach ($published as $offer) {
        $offers[] = (array)$offer + [
            'nonce'=>bin2hex(random_bytes(16)), 'available'=>$offer->stock > 0,
            'canrequest'=>$offer->stock > 0 && $offer->price <= $balance,
            'shortfall'=>max(0, (int)$offer->price - $balance), 'coverurl'=>$covers[$offer->id] ?? '',
        ];
    }
}
$orders = [];
foreach ($DB->get_records(reward_shop::ORDER, ['userid'=>$userid], 'id DESC', '*', $orderpage * 20, 20) as $order) {
    $orders[] = (array)$order + [
        'statuslabel'=>get_string('rewardshop_order_'.$order->status, 'local_ustar'),
        'issued'=>$order->status === 'issued', 'rejected'=>$order->status === 'rejected',
        'date'=>userdate((int)$order->timecreated), 'hasreason'=>trim((string)$order->reason) !== '',
    ];
}
$data = [
    'url'=>$url->out(false), 'sesskey'=>sesskey(), 'error'=>$error, 'balance'=>$balance,
    'achievementsurl'=>(new moodle_url('/local/ustar/achievements.php'))->out(false),
    'showcatalog'=>$showcatalog, 'offers'=>$offers, 'hasoffers'=>(bool)$offers,
    'rewardicon'=>ui::icon('trophy'),
    'orders'=>$orders, 'hasorders'=>(bool)$orders,
    'catalogpaging'=>$showcatalog ? $OUTPUT->paging_bar(
        $DB->count_records(reward_shop::OFFER, ['status'=>'published']), $catalogpage, 24,
        new moodle_url($url, ['orderpage'=>$orderpage]), 'catalogpage',
    ) : '',
    'orderpaging'=>$OUTPUT->paging_bar($DB->count_records(reward_shop::ORDER, ['userid'=>$userid]), $orderpage, 20,
        new moodle_url($url, ['catalogpage'=>$catalogpage]), 'orderpage'),
];
echo $OUTPUT->header();
echo $OUTPUT->render_from_template('local_ustar/reward_shop', $data);
echo $OUTPUT->footer();
