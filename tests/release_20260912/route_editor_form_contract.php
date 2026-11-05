<?php
define('MOODLE_INTERNAL',true);define('PARAM_TEXT',1);define('PARAM_ALPHANUMEXT',2);
function clean_param($s,$p){return $s;}
class invalid_parameter_exception extends Exception{}
require __DIR__.'/../../moodle/local/ustar/classes/route_studio_form.php';
use local_ustar\route_studio_form as Form;
$base=[['type'=>'cm','sourceid'=>37,'required'=>true,'label'=>'SCORM'],['type'=>'cm','sourceid'=>40,'required'=>false,'label'=>'Quiz'],['type'=>'content','sourceid'=>10,'required'=>false,'completionmode'=>'ack','label'=>'Текст']];
$input=['rows'=>[['base'=>'0','required'=>1],['base'=>'1'],['base'=>'2','completionmode'=>'ack']]];
assert(Form::decode($input,$base,[])===$base);
$input['rows'][1]['remove']=1;assert(count(Form::decode($input,$base,[]))===2);
function denied($fn){try{$fn();throw new RuntimeException('Expected rejection');}catch(invalid_parameter_exception $e){}}
denied(fn()=>Form::decode(['rows'=>[['base'=>'0']]],$base,[]));
denied(fn()=>Form::decode(['rows'=>[['base'=>'0'],['base'=>'0']]],$base,[]));
denied(fn()=>Form::decode(['rows'=>'bad'],$base,[]));
$rows=Form::rows($base);assert(count(array_filter($rows,fn($r)=>$r['existing']))===3);assert(!$rows[1]['required']);assert($rows[2]['ack']);
echo "ROUTE_EDITOR_FORM_CONTRACT PASS\n";
