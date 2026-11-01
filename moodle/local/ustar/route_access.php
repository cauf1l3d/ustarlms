<?php
require_once(__DIR__.'/../../config.php');
require_login();
require_capability('local/ustar:use', context_system::instance());
\local_ustar\view_as::assert_writable();
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { throw new invalid_parameter_exception('POST required'); }
require_sesskey();
$pointid = required_param('pointid',PARAM_INT);
$versionid = required_param('versionid',PARAM_INT);
$windowid = required_param('accessid',PARAM_INT);
if (optional_param('confirmed',0,PARAM_BOOL)) {
    \local_ustar\route_point_access::advance((int)$USER->id,$pointid,$versionid,$windowid);
    redirect(\local_ustar\route_continue::next_url((int)$USER->id));
}
// No-JavaScript confirmation: the first POST never closes access.
$PAGE->set_context(context_system::instance());
$PAGE->set_url(new moodle_url('/local/ustar/route_access.php'));
$PAGE->set_pagelayout('ustar');
$PAGE->set_title('Переход к следующему шагу');
echo $OUTPUT->header();
$version = \local_ustar\route_model::current_published_version($pointid);
if (!$version || (int)$version->id !== $versionid) { throw new moodle_exception('Версия точки изменилась. Обновите маршрут.'); }
$warning = \local_ustar\route_point_access::policy($version)['repeat'] === 'close'
    ? 'После перехода этот материал будет недоступен для повторного изучения из этой точки. Результат сохранится. Перейти дальше?'
    : 'Изучение засчитано. Перейти к следующему шагу? Материал останется доступным для повторения.';
echo $OUTPUT->confirm($warning,new single_button(new moodle_url('/local/ustar/route_access.php',[
    'pointid'=>$pointid,'versionid'=>$versionid,'accessid'=>$windowid,'confirmed'=>1,'sesskey'=>sesskey()]),'Перейти дальше','post'),
    new moodle_url('/local/ustar/route.php'));
echo $OUTPUT->footer();
