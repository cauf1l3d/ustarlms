<?php
namespace {define('MOODLE_INTERNAL',true);class core_text {static function substr($s,$a,$b){return substr($s,$a,$b);}}}
namespace local_ustar {
require __DIR__.'/../../moodle/local/ustar/classes/library_quick_generator.php';
require __DIR__.'/../../moodle/local/ustar/classes/library_quick_sync.php';
$p=(object)['id'=>1,'title'=>'Bolt','attributesjson'=>'{"A":"","Material":"steel"}'];
$r=new \ReflectionMethod(library_quick_sync::class,'value_pool_for');
$pool=$r->invoke(null,$p,['A'=>['empty-wrong'],'Material'=>['steel','brass','wood','plastic']]);
$ok=in_array('brass',$pool,true)&&!in_array('empty-wrong',$pool,true);
echo ($ok?'PASS':'FAIL')." same usable attribute for correct answer and distractors\n";
exit($ok?0:1);
}
