<?php
// Execute the bounded reader's real SQL on a disposable in-memory database.
// Native Moodle/File API semantics are additionally exercised by material_catalog_test.
define('MOODLE_INTERNAL',true);define('SQL_PARAMS_NAMED',2);define('CONTEXT_MODULE',70);
require_once(__DIR__.'/../../moodle/local/ustar/classes/content.php');
require_once(__DIR__.'/../../moodle/local/ustar/classes/material_catalog.php');
class core_text { public static function strtolower($s) { return mb_strtolower($s); } }
final class catalog_sqlite {
    public PDO $pdo;public int $queries=0;
    public function __construct() {
        $this->pdo=new PDO('sqlite::memory:');$this->pdo->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);
        $this->pdo->sqliteCreateFunction('LOWER',static fn($v)=>mb_strtolower((string)$v),1);
        foreach ([
            'local_ustar_content'=>'id INTEGER PRIMARY KEY,title TEXT,type TEXT,status TEXT,sourcekind TEXT,parentid INT,summary TEXT,cmid INT,courseid INT',
            'course_modules'=>'id INTEGER PRIMARY KEY,module INT,visible INT','modules'=>'id INTEGER PRIMARY KEY,name TEXT',
            'course'=>'id INTEGER PRIMARY KEY,fullname TEXT,shortname TEXT,visible INT',
            'local_ustar_content_versions'=>'id INTEGER PRIMARY KEY,contentid INT,versionno INT,iscurrent INT',
            'files'=>'id INTEGER PRIMARY KEY,contextid INT,component TEXT,filearea TEXT,itemid INT,filename TEXT,sortorder INT',
            'context'=>'id INTEGER PRIMARY KEY,contextlevel INT,instanceid INT',
        ] as $table=>$schema) { $this->pdo->exec('CREATE TABLE '.$table.' ('.$schema.')'); }
    }
    public function get_records_sql($sql,$params=[],$offset=0,$limit=0) {
        $this->queries++;$sql=preg_replace('/\{([a-z_]+)\}/','$1',$sql);
        if($limit)$sql.=' LIMIT '.(int)$limit.' OFFSET '.(int)$offset;
        $s=$this->pdo->prepare($sql);$s->execute($params);$out=[];
        foreach($s->fetchAll(PDO::FETCH_OBJ) as $row)$out[array_values((array)$row)[0]]=$row;return $out;
    }
    public function count_records_sql($sql,$params) { $rows=$this->get_records_sql($sql,$params);return (int)array_values((array)reset($rows))[0]; }
    public function sql_like_escape($s) { return addcslashes($s,'_%\\'); }
    public function sql_like($field,$param,$case) { return $field.' LIKE '.$param; }
    public function get_in_or_equal($values,$style,$prefix) { $params=[];foreach(array_values($values) as $i=>$v)$params[$prefix.$i]=$v;return ['IN (:'.implode(',:',array_keys($params)).')',$params]; }
    public function insert($table,$row) { $sql='INSERT INTO '.$table.' ('.implode(',',array_keys($row)).') VALUES (:'.implode(',:',array_keys($row)).')';$this->pdo->prepare($sql)->execute($row); }
}
function check_catalog($ok,$message) { if(!$ok)throw new RuntimeException($message); }
$DB=new catalog_sqlite();
for($i=1;$i<=125;$i++)$DB->insert('local_ustar_content',['id'=>$i,'title'=>sprintf('Инструкция %03d',$i),'type'=>'document','sourcekind'=>'ustar_file','status'=>'published','parentid'=>7]);
$first=\local_ustar\material_catalog::read(['parent'=>7]);$second=\local_ustar\material_catalog::read(['parent'=>7],1);$last=\local_ustar\material_catalog::read(['parent'=>7],9999);
check_catalog(count($first['records'])===60 && $first['total']===125,'bounded first page');
check_catalog(array_key_first($second['records'])===61 && count($last['records'])===5 && $last['page']===2,'stable paging and clamp');
$detail=\local_ustar\material_catalog::read(['parent'=>999,'q'=>'none','status'=>'archived'],99,125);check_catalog(array_keys($detail['records'])===[125],'detail independent from filters and page');
$DB->insert('context',['id'=>10,'contextlevel'=>70,'instanceid'=>8]);
$DB->insert('files',['id'=>1,'contextid'=>10,'component'=>'mod_resource','filearea'=>'content','itemid'=>0,'filename'=>'a.png','sortorder'=>0]);
$DB->insert('files',['id'=>2,'contextid'=>10,'component'=>'mod_resource','filearea'=>'content','itemid'=>0,'filename'=>'Main.pdf','sortorder'=>1]);
foreach([[1,1,'Old.pdf'],[2,1,'Current.docx'],[3,0,'NotCurrent.txt']] as [$version,$current,$name]){
 $DB->insert('local_ustar_content_versions',['id'=>$version,'contentid'=>1,'versionno'=>$version,'iscurrent'=>$current]);
 $DB->insert('files',['id'=>10+$version,'contextid'=>1,'component'=>'local_ustar','filearea'=>'content_version','itemid'=>$version,'filename'=>$name,'sortorder'=>0]);
}
$records=$first['records'];$records[2]->sourcekind='moodle_cm';$records[2]->modname='resource';$records[2]->cmid=8;
$before=$DB->queries;$labels=\local_ustar\material_catalog::file_labels($records,1);
check_catalog($DB->queries-$before===2 && count($labels)===60,'batch metadata count');
check_catalog($labels[1]['filename']==='Current.docx' && $labels[1]['class']==='doc','current highest version selected');
check_catalog($labels[2]['filename']==='Main.pdf' && $labels[2]['class']==='pdf','resource main file sort order');
$DB->insert('local_ustar_content',['id'=>126,'title'=>'Инструкция 001','type'=>'document','sourcekind'=>'ustar_file','status'=>'draft']);
check_catalog(\local_ustar\material_catalog::duplicates($first['records'])===[],'editing draft is not duplicate publication');
$DB->insert('local_ustar_content',['id'=>127,'title'=>'Инструкция 001','type'=>'document','sourcekind'=>'ustar_file','status'=>'published']);
check_catalog(!empty(\local_ustar\material_catalog::duplicates($first['records'])[1]),'duplicate publication warning');
echo "MATERIAL_CATALOG_SQL_OK bounded deterministic pages independent detail batch current mainfile duplicate publication\n";
