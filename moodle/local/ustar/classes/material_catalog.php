<?php
namespace local_ustar;
defined('MOODLE_INTERNAL') || die();

/** Bounded reader for the existing HR catalogue; never loads file bodies. */
final class material_catalog {
    public const PAGE_SIZE = 60;

    /** Caller retains the native HR capability guard. Detail does not inherit list filters. */
    public static function read(array $filters, int $page = 0, int $selectedid = 0): array {
        global $DB;
        $where=[];$params=[];
        if ($selectedid>0) {
            $where[]='uc.id=:selectedid';$params['selectedid']=$selectedid;
        } else {
            $type=(string)($filters['type'] ?? 'all');$status=(string)($filters['status'] ?? 'all');
            if ($type!=='' && $type!=='all') { $where[]='uc.type=:type';$params['type']=$type; }
            if (in_array($status,['draft','published','archived'],true)) { $where[]='uc.status=:status';$params['status']=$status; }
            $parent=max(0,(int)($filters['parent'] ?? 0));
            $where[]=$parent>0?'uc.parentid=:parent':'uc.parentid IS NULL';
            if ($parent>0) { $params['parent']=$parent; }
            $q=trim((string)($filters['q'] ?? ''));
            if ($q!=='') {
                $parts=[];
                foreach (['uc.title'=>'qtitle','uc.summary'=>'qsummary','cr.fullname'=>'qcourse'] as $field=>$key) {
                    $parts[]=$DB->sql_like($field,':'.$key,false);$params[$key]='%'.$DB->sql_like_escape($q).'%';
                }
                $where[]='('.implode(' OR ',$parts).')';
            }
        }
        $from='FROM {local_ustar_content} uc
            LEFT JOIN {course_modules} cm ON cm.id=uc.cmid
            LEFT JOIN {modules} m ON m.id=cm.module
            LEFT JOIN {course} cr ON cr.id=uc.courseid WHERE '.implode(' AND ',$where);
        $total=(int)$DB->count_records_sql('SELECT COUNT(1) '.$from,$params);
        $page=min(max(0,$page),max(0,(int)ceil($total/self::PAGE_SIZE)-1));
        $records=$DB->get_records_sql('SELECT uc.*,cm.visible AS cmvisible,m.name AS modname,
            cr.fullname AS coursename,cr.shortname AS courseshortname,cr.visible AS coursevisible '.$from.'
            ORDER BY CASE WHEN uc.type=\'folder\' THEN 0 ELSE 1 END,
                CASE uc.status WHEN \'published\' THEN 1 WHEN \'draft\' THEN 2 ELSE 3 END,uc.title,uc.id',
            $params,$page*self::PAGE_SIZE,self::PAGE_SIZE);
        return ['records'=>$records,'total'=>$total,'page'=>$page];
    }

    /** At most two metadata queries regardless of the number of displayed rows. */
    public static function file_labels(array $records, int $contextid): array {
        global $DB;
        $native=[];$modules=[];$names=[];
        foreach ($records as $record) {
            if ($record->sourcekind===content::SOURCE_FILE) { $native[]=(int)$record->id; }
            if ($record->sourcekind===content::SOURCE_MOODLE && ($record->modname ?? '')==='resource' && !empty($record->cmid)) {
                $modules[]=(int)$record->cmid;
            }
        }
        if ($native) {
            [$in,$params]=$DB->get_in_or_equal(array_unique($native),SQL_PARAMS_NAMED,'native');
            $params['contextid']=$contextid;
            $rows=$DB->get_records_sql("SELECT v.contentid AS id,f.filename
                FROM {local_ustar_content_versions} v
                LEFT JOIN {files} f ON f.itemid=v.id AND f.contextid=:contextid
                    AND f.component='local_ustar' AND f.filearea='content_version' AND f.filename<>'.'
                    AND NOT EXISTS (SELECT 1 FROM {files} ff WHERE ff.contextid=f.contextid
                        AND ff.component=f.component AND ff.filearea=f.filearea AND ff.itemid=f.itemid
                        AND ff.filename<>'.' AND ff.id<f.id)
                WHERE v.contentid $in AND v.iscurrent=1
                    AND NOT EXISTS (SELECT 1 FROM {local_ustar_content_versions} vv
                        WHERE vv.contentid=v.contentid AND vv.iscurrent=1
                        AND (vv.versionno>v.versionno OR (vv.versionno=v.versionno AND vv.id>v.id)))",$params);
            foreach ($rows as $row) { $names[(int)$row->id]=(string)($row->filename ?? ''); }
        }
        if ($modules) {
            [$in,$params]=$DB->get_in_or_equal(array_unique($modules),SQL_PARAMS_NAMED,'module');
            $params['level']=CONTEXT_MODULE;
            $rows=$DB->get_records_sql("SELECT ctx.instanceid AS id,f.filename
                FROM {context} ctx JOIN {files} f ON f.contextid=ctx.id
                    AND f.component='mod_resource' AND f.filearea='content' AND f.itemid=0 AND f.filename<>'.'
                WHERE ctx.contextlevel=:level AND ctx.instanceid $in
                    AND NOT EXISTS (SELECT 1 FROM {files} ff WHERE ff.contextid=f.contextid
                        AND ff.component=f.component AND ff.filearea=f.filearea AND ff.itemid=f.itemid AND ff.filename<>'.'
                        AND (ff.sortorder>f.sortorder OR (ff.sortorder=f.sortorder AND ff.id<f.id)))",$params);
            foreach ($records as $record) {
                if ($record->sourcekind===content::SOURCE_MOODLE && ($record->modname ?? '')==='resource') {
                    $names[(int)$record->id]=(string)($rows[(int)$record->cmid]->filename ?? '');
                }
            }
        }
        $out=[];
        foreach ($records as $record) { $out[(int)$record->id]=self::visual($record,$names[(int)$record->id] ?? ''); }
        return $out;
    }

    /** Only duplicate publications need a warning; linked editing drafts are intentional. */
    public static function duplicates(array $records): array {
        global $DB;
        $titles=[];
        foreach ($records as $r) { if ($r->status==='published') { $titles[]=\core_text::strtolower(trim($r->title)); } }
        if (!$titles) { return []; }
        [$in,$params]=$DB->get_in_or_equal(array_unique($titles),SQL_PARAMS_NAMED,'title');
        $rows=$DB->get_records_sql("SELECT MIN(id) AS id,type,LOWER(TRIM(title)) AS normalized,COUNT(1) AS copies
            FROM {local_ustar_content} WHERE status='published' AND LOWER(TRIM(title)) $in
            GROUP BY type,LOWER(TRIM(title)) HAVING COUNT(1)>1",$params);
        $keys=[];foreach ($rows as $row) { $keys[$row->type.'|'.$row->normalized]=true; }
        $out=[];foreach ($records as $r) {
            if ($r->status==='published' && isset($keys[$r->type.'|'.\core_text::strtolower(trim($r->title))])) { $out[(int)$r->id]=true; }
        }
        return $out;
    }

    public static function visual(\stdClass $record, string $filename = ''): array {
        $extensions=[
            'pdf'=>['PDF','pdf'],'doc'=>['DOC','doc'],'docx'=>['DOCX','doc'],
            'xls'=>['XLS','xls'],'xlsx'=>['XLSX','xls'],'csv'=>['CSV','xls'],
            'ppt'=>['PPT','ppt'],'pptx'=>['PPTX','ppt'],'zip'=>['ZIP','archive'],'rar'=>['RAR','archive'],'7z'=>['7Z','archive'],
            'jpg'=>['JPG','image'],'jpeg'=>['JPG','image'],'png'=>['PNG','image'],'gif'=>['GIF','image'],'webp'=>['WEBP','image'],'svg'=>['SVG','image'],
            'mp4'=>['MP4','video'],'webm'=>['WEBM','video'],'mov'=>['MOV','video'],'mkv'=>['MKV','video'],
            'mp3'=>['MP3','audio'],'wav'=>['WAV','audio'],'m4a'=>['M4A','audio'],'ogg'=>['OGG','audio'],
            'txt'=>['TXT','text'],'md'=>['MD','text'],'html'=>['HTML','web'],'htm'=>['HTML','web'],
            'json'=>['JSON','data'],'xml'=>['XML','data'],'rcproject'=>['ПРОЕКТ','scorm'],'rcworkspace'=>['ПРОЕКТ','scorm'],
        ];
        $modules=['scorm'=>['SCORM','scorm'],'quiz'=>['ТЕСТ','quiz'],'page'=>['СТРАНИЦА','page'],'lesson'=>['УРОК','lesson'],
            'forum'=>['ФОРУМ','forum'],'data'=>['БАЗА','data'],'database'=>['БАЗА','data'],'url'=>['ССЫЛКА','link'],
            'assign'=>['ЗАДАНИЕ','assignment'],'book'=>['КНИГА','book'],'h5pactivity'=>['H5P','interactive'],'resource'=>['ФАЙЛ','file']];
        $types=['folder'=>['ПАПКА','folder'],'document'=>['ФАЙЛ','file'],'article'=>['СТАТЬЯ','page'],'quiz'=>['ТЕСТ','quiz'],
            'scorm'=>['SCORM','scorm'],'lesson'=>['УРОК','lesson'],'forum'=>['ФОРУМ','forum'],'database'=>['БАЗА','data'],
            'video'=>['ВИДЕО','video'],'link'=>['ССЫЛКА','link'],'assignment'=>['ЗАДАНИЕ','assignment'],
            'interactive'=>['ИНТЕРАКТИВ','interactive'],'collection'=>['ПОДБОРКА','collection']];
        $extension=strtolower(pathinfo($filename,PATHINFO_EXTENSION));$module=strtolower(trim((string)($record->modname ?? '')));
        [$label,$class]=$record->type==='folder'?$types['folder']:($extensions[$extension] ?? $modules[$module] ?? $types[$record->type] ?? ['ФАЙЛ','file']);
        return ['label'=>$label,'class'=>$class,'filename'=>$filename];
    }
}
