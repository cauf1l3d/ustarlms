<?php
require_once(__DIR__ . '/../../config.php');
require_login();
global $USER;
$context=context_system::instance(); require_capability('local/ustar:use',$context);
$d=\local_ustar\native_data::dashboard();
$xp=(int)($d['xp']??0); $level=max(1,(int)($d['level']??1)); $next=max($xp+1,(int)($d['nextLevelXp']??($xp+1))); $pct=min(100,(int)round(($xp/$next)*100));
$badges=[]; foreach(($d['badges']??[]) as $badge)$badges[]=['name'=>(string)($badge['name']??'Награда'),'date'=>!empty($badge['dateissued'])?userdate((int)$badge['dateissued'],'%d.%m.%Y'):'','icon'=>\local_ustar\ui::icon('trophy','u-badge-card__icon')];
$scope=optional_param('rank','all',PARAM_ALPHA); if(!in_array($scope,['all','team','month'],true))$scope='all';
$rank=\local_ustar\economy::leaderboard((int)$USER->id,200,$scope==='month');
$rows=$rank['rows'];
if($scope==='team'){
    $ids=[(int)$USER->id=>true]; foreach(\local_ustar\org::horizon((int)$USER->id) as $p)$ids[(int)$p['id']]=true; foreach(\local_ustar\org::direct_reports((int)$USER->id) as $p)$ids[(int)$p['id']]=true;
    $rows=array_values(array_filter($rows,fn($r)=>isset($ids[(int)$r['userid']]))); usort($rows,fn($a,$b)=>$b['xp']<=>$a['xp']); foreach($rows as $i=>&$r)$r['rank']=$i+1; unset($r);
}
$top=array_slice($rows,0,3); foreach($top as $i=>&$r){$r['medal']=['🥇','🥈','🥉'][$i];} unset($r); $rest=array_slice($rows,3,47);
$coin=\local_ustar\economy::totals((int)$USER->id); $history=\local_ustar\economy::history((int)$USER->id,8);
$data=[
 'xp'=>$xp,'gamexp'=>(int)($d['gameXp']??0),'level'=>$level,'nextxp'=>$next,'pct'=>$pct,'activeDays30'=>(int)($d['activeDays30']??0),'completedCourses'=>(int)($d['completedCourses']??0),
 'badges'=>$badges,'hasbadges'=>!empty($badges),'trophyicon'=>\local_ustar\ui::icon('trophy','u-feature-icon'),'gameicon'=>\local_ustar\ui::icon('game','u-feature-icon'),'staricon'=>\local_ustar\ui::icon('star','u-feature-icon'),
 'gamesurl'=>(new moodle_url('/local/ustar/games.php'))->out(false),'learningurl'=>(new moodle_url('/local/ustar/home.php',['view'=>'learning']))->out(false),
 'rankallurl'=>(new moodle_url('/local/ustar/achievements.php',['rank'=>'all']))->out(false),'rankteamurl'=>(new moodle_url('/local/ustar/achievements.php',['rank'=>'team']))->out(false),'rankmonthurl'=>(new moodle_url('/local/ustar/achievements.php',['rank'=>'month']))->out(false),
 'isall'=>$scope==='all','isteam'=>$scope==='team','ismonth'=>$scope==='month','top'=>$top,'hastop'=>!empty($top),'rankrows'=>$rest,'hasrankrows'=>!empty($rest),'currentrank'=>$rank['current'],
 'coinbalance'=>$coin['balance'],'coinearned'=>$coin['earned'],'coinspent'=>$coin['spent'],'coinhistory'=>$history,'hascoinhistory'=>!empty($history),
];
$PAGE->set_context($context); $PAGE->set_url(new moodle_url('/local/ustar/achievements.php')); $PAGE->set_pagelayout('ustar'); $PAGE->set_title('Достижения | USTAR Academy'); $PAGE->set_heading('USTAR Academy');
$output=$PAGE->get_renderer('local_ustar'); echo $output->header(); echo $output->render_from_template('local_ustar/achievements',$data); echo $output->footer();
