<?php
// Disposable synthetic stage only. Never shipped in the owner source overlay.
define('CLI_SCRIPT',true);require('/stage/moodle/config.php');
if ($CFG->prefix!=='stage_' || getenv('USTAR_STAGE_PREFIX')!=='stage_') { throw new coding_exception('isolated route editor fixture required'); }
$path='/artifacts/route-editor-fixture.json';$mode=$argv[1]??'create';
if ($mode!=='create') {
    $f=json_decode(file_get_contents($path),true,512,JSON_THROW_ON_ERROR);$p=$DB->get_record('local_ustar_route_points',['id'=>$f['point']], '*',MUST_EXIST);
    $expected=$mode==='hidden'?0:1;
    if ((int)$p->active!==$expected || $DB->count_records('local_ustar_route_versions',['pointid'=>$p->id])!==2) { throw new coding_exception('Visibility/version history mismatch'); }
    $latest=\local_ustar\route_model::latest_version((int)$p->id);
    if ($latest->status!=='draft' || \local_ustar\route_model::requirements_for_version($latest)!==$f['requirements']) { throw new coding_exception('Draft requirements changed'); }
    if ((int)\local_ustar\route_model::current_published_version((int)$p->id)->id!==$f['published']) { throw new coding_exception('Published version replaced by draft'); }
    echo "ROUTE_EDITOR_HTTP_STATE=$mode:PASS\n";exit;
}
$now=time();$actor=(int)get_admin()->id;
$family=(int)$DB->insert_record('local_ustar_route_families',(object)['familykey'=>'http_route_editor','name'=>'HTTP Editor Family','active'=>1,'timecreated'=>$now,'timemodified'=>$now]);
$route=(int)$DB->insert_record('local_ustar_routes',(object)['positionid'=>null,'familyid'=>$family,'routekind'=>'parent','name'=>'HTTP Editor Route','active'=>1,'timecreated'=>$now,'timemodified'=>$now]);
$DB->set_field('local_ustar_route_families','parentrouteid',$route,['id'=>$family]);
$point=(int)$DB->insert_record('local_ustar_route_points',(object)['routeid'=>$route,'pointkey'=>'http_route_editor','phase'=>'adaptation','sortorder'=>10,'active'=>1,'timecreated'=>$now,'timemodified'=>$now]);
$requirements=[['type'=>'native','sourcekey'=>\local_ustar\native_learning::TEAM_STRUCTURE,'required'=>true,'label'=>'Команда'],['type'=>'previous_adaptation','required'=>false,'label'=>'Предыдущие шаги']];
$v=\local_ustar\route_model::create_version($point,['title'=>'HTTP editor point','status'=>'published','requirements'=>$requirements],$actor);
file_put_contents($path,json_encode(['family'=>$family,'point'=>$point,'published'=>(int)$v->id,'requirements'=>$requirements]));
echo "$family $point";
