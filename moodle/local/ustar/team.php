<?php
require_once(__DIR__ . '/../../config.php');
require_login(); global $USER;
$context=context_system::instance(); require_capability('local/ustar:use',$context);
$me=\local_ustar\org::person((int)$USER->id); $chain=\local_ustar\org::chain((int)$USER->id); $horizon=\local_ustar\org::horizon((int)$USER->id); foreach ($horizon as &$hp) { $hp['current'] = (int)$hp['id'] === (int)$USER->id; } unset($hp); $reports=\local_ustar\org::direct_reports((int)$USER->id);
$managed=[]; $avg=0; $ready=0;
try { if(has_capability('local/ustar:viewteam',$context)||is_siteadmin()) { $payload=\local_ustar\native_data::team(); $managed=$payload['team']??[]; $sum=0; foreach($managed as &$p){$sum+=(int)$p['avgProgress']; $parts=preg_split('/\s+/u',trim((string)$p['fullname']),-1,PREG_SPLIT_NO_EMPTY)?:[]; $p['initials']=\local_ustar\ui::initials((string)($parts[0]??''),(string)($parts[count($parts)-1]??'')); $p['good']=(int)$p['avgProgress']>=80; if($p['good'])$ready++;}unset($p); if($managed)$avg=(int)round($sum/count($managed)); }} catch(Throwable $e){$managed=[];}
$data=[
 'me'=>$me,'chain'=>$chain,'haschain'=>count($chain)>1,'horizon'=>$horizon,'hashorizon'=>!empty($horizon),'reports'=>$reports,'hasreports'=>!empty($reports),
 'managed'=>$managed,'hasmanaged'=>!empty($managed),'count'=>count($managed),'avg'=>$avg,'ready'=>$ready,
 'teamicon'=>\local_ustar\ui::icon('team','u-feature-icon'),'hasreporting'=>\local_ustar\org::reporting_available(),
 'executiveurl'=>has_capability('local/ustar:executive',$context)?(new moodle_url('/local/ustar/executive.php'))->out(false):'','hasexecutive'=>has_capability('local/ustar:executive',$context),
];
$PAGE->set_context($context); $PAGE->set_url(new moodle_url('/local/ustar/team.php')); $PAGE->set_pagelayout('ustar'); $PAGE->set_title('Команда | USTAR Academy'); $PAGE->set_heading('USTAR Academy');
$output=$PAGE->get_renderer('local_ustar'); echo $output->header(); echo $output->render_from_template('local_ustar/team',$data); echo $output->footer();
