<?php
/** Real controller name-hydration block, counting synthetic DB; no Moodle/database/network. */
$root=dirname(__DIR__,3);$source=file_get_contents($root.'/moodle/local/ustar/route_studio.php');
$a=strpos($source,'$modulenames=[];');$b=strpos($source,'foreach ($modules as $r)', $a);
if($a===false||$b===false)throw new Exception('Source block changed');
class NameDB {public int $calls=0;public int $rows=0;function get_records_list($table,$field,$ids,...$rest){$this->calls++;$this->rows+=count($ids);return array_combine($ids,array_map(fn($id)=>(object)['id'=>$id,'name'=>$table.' '.$id],$ids));}}
$out=['scope'=>'actual module-name hydration block with synthetic catalogue; API calls/rows, not PostgreSQL latency or all Route Studio reads'];
foreach([100,1000] as $n){$DB=new NameDB();$modules=[];for($i=1;$i<=$n;$i++)$modules[]=(object)['instance'=>$i,'modname'=>$i%2?'scorm':'quiz'];eval(substr($source,$a,$b-$a));$out['samples'][]=['modules'=>$n,'types'=>2,'name_db_calls'=>$DB->calls,'metadata_rows'=>$DB->rows];}
echo json_encode($out,JSON_PRETTY_PRINT)."\n";
