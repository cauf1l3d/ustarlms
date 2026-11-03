<?php
require_once(__DIR__.'/../../config.php');
require_login();
use local_ustar\{library_access,library_offer,library_store,library_view,library_runtime,view_as,content};
$userid=(int)$USER->id;$context=context_system::instance();
$tab=optional_param('tab','mine',PARAM_ALPHA);
if (!in_array($tab,['mine','catalog','shop','checks','route'],true)) { $tab='mine'; }
$q=trim(optional_param('q','',PARAM_TEXT));$page=max(0,min(500,optional_param('page',0,PARAM_INT)));
$PAGE->set_context($context);$PAGE->set_url(new moodle_url('/local/ustar/library.php',['tab'=>$tab]));
$PAGE->set_pagelayout('ustar');$PAGE->set_title('Библиотека | USTAR Академия');$PAGE->set_heading('Библиотека');
$PAGE->requires->css(new moodle_url('/local/ustar/styles/library.css'));
if (!library_access::eligible($userid)) {
    echo $OUTPUT->header();echo $OUTPUT->render_from_template('local_ustar/library_blocked',[]);echo $OUTPUT->footer();exit;
}
$canmarketplace=library_access::can_view_marketplace($userid);
if ($tab==='shop' && !$canmarketplace) { library_access::fail('Маркетплейс пока не открыт для вашего отдела.'); }
$notice='';$noticetype='warning';
if ($_SERVER['REQUEST_METHOD']==='POST') {
    require_sesskey();library_access::require_actor($userid);
    try {
        $action=required_param('action',PARAM_ALPHA);
        if ($action==='purchase') {
            $id=required_param('offerid',PARAM_INT);
            $result=library_store::purchase($id,$userid,required_param('price',PARAM_INT),required_param('revision',PARAM_INT),required_param('nonce',PARAM_ALPHANUM));
            if ($result['ok']) { redirect(new moodle_url('/local/ustar/library.php',['tab'=>'mine']),'Доступ открыт.'); }
            $notice=match($result['reason']) {
                'insufficient_balance'=>'Недостаточно USCOIN. Баланс: '.$result['balance'].'.',
                'store_disabled'=>'Покупки пока отключены. Доступ может предоставить руководитель.',
                'offer_changed'=>'Цена или условия изменились. Обновите страницу перед покупкой.',
                default=>'Покупка не выполнена. Обновите страницу и проверьте условия доступа.',
            };
        } else if ($action==='launch') {
            $offerid=optional_param('offerid',0,PARAM_INT);$contentid=optional_param('contentid',0,PARAM_INT);
            if ($offerid) {
                if (!library_access::can_view_offer_page($offerid,$userid)) { library_access::fail('Доступ к предложению закрыт.'); }
                $state=library_access::resolve($offerid,$userid);
                if (!$state['canopen']) { library_access::fail('Доступ к материалу закрыт.'); }
                $offer=$state['offer'];
                if ($offer->itemtype==='catalog') { redirect(new moodle_url('/local/ustar/library.php',['tab'=>'catalog','product'=>$offer->itemid])); }
                if ($offer->itemtype==='assessment') { redirect(new moodle_url('/local/ustar/library_check.php',['id'=>$offer->itemid])); }
                $contentid=(int)$offer->itemid;
            }
            $url=library_runtime::launch_content($contentid,$userid);redirect($url);
        } else if ($action==='removefeedfile') {
            \local_ustar\feed_files::remove_from_library(required_param('savedid',PARAM_INT),$userid);
            redirect(new moodle_url('/local/ustar/library.php',['tab'=>'mine']));
        } else { throw new invalid_parameter_exception('Неизвестное действие.'); }
    } catch (moodle_exception $e) { $notice=$e->getMessage(); }
}
$names=['mine'=>'Мои материалы','catalog'=>'Каталог товаров','shop'=>'Маркетплейс','checks'=>'Проверки знаний','route'=>'Обязательный маршрут'];
if (!$canmarketplace) { unset($names['shop']); }
$tabs=[];foreach($names as $key=>$label) { $tabs[]=['label'=>$label,'url'=>library_view::url(['tab'=>$key]),'active'=>$tab===$key]; }
$data=['canmarketplace'=>$canmarketplace,'balance'=>\local_ustar\economy::available()?\local_ustar\economy::balance($userid):0,'notice'=>$notice,'noticetype'=>$noticetype,
    'tabs'=>$tabs,'tab'=>$tab,'q'=>$q,'libraryurl'=>library_view::url(),'sesskey'=>sesskey(),
    'canmanage'=>!view_as::active() && (library_access::can_grant($userid)||library_access::is_authoring($userid)||library_access::can_assess($userid)),
    'manageurl'=>(new moodle_url('/local/ustar/library_manage.php'))->out(false)];
$open=optional_param('open',0,PARAM_INT);
if ($open) {
    if (!library_access::can_view_offer_page($open,$userid)) { library_access::fail('Доступ к предложению закрыт.'); }
    $state=library_access::resolve($open,$userid);
    if (!$state['canview']) { library_access::fail('Материал недоступен.'); }
    $offer=$state['offer'];$data['showoffer']=true;
    $data['selectedoffer']=['id'=>$open,'title'=>$offer->title,'summary'=>$offer->summary,'open'=>$state['canopen'],
        'price'=>$offer->pricecoin,'revision'=>$offer->timemodified,'nonce'=>bin2hex(random_bytes(16)),
        'canpurchase'=>$canmarketplace && !$state['canopen'] && $state['reason']==='purchase_required' && library_store::enabled(),
        'canlaunch'=>!view_as::active() && $state['canopen'],
        'lockedtext'=>match($state['reason']) {'gate_not_passed'=>'Доступ откроется после обязательной аттестации в маршруте.',
            'assign_required'=>'Доступ предоставляет руководитель или отдел обучения.',
            'purchase_required'=>library_store::enabled()?'Откройте доступ за USCOIN.':'Покупки пока отключены. Доступ может предоставить руководитель.',default=>''}];
} else {
    $data['show'.$tab]=true;
    $data+=match($tab) {
        'mine'=>library_view::mine($userid,$q,$page),
        'catalog'=>library_view::catalog($userid,optional_param('parent',0,PARAM_INT),optional_param('product',0,PARAM_INT),$q,$page),
        'checks'=>library_view::checks($userid,$q,$page),
        'shop'=>(static function() use($userid,$q,$page){$s=library_store::storefront($userid,$q,$page);foreach($s['items'] as &$i){$i['nonce']=bin2hex(random_bytes(16));}unset($i);return ['offers'=>$s['items'],'hasoffers'=>(bool)$s['items'],'offertotal'=>$s['total'],'hasmore'=>$s['hasmore'],'storeenabled'=>$s['storeenabled']];})(),
        default=>['routemirrorurl'=>(new moodle_url('/local/ustar/route.php'))->out(false)],
    };
}
$data['nexturl']=library_view::url(['tab'=>$tab,'q'=>$q,'page'=>$page+1,'parent'=>optional_param('parent',0,PARAM_INT)]);
$data['writable']=!view_as::active();
echo $OUTPUT->header();echo $OUTPUT->render_from_template('local_ustar/library',$data);echo $OUTPUT->footer();
