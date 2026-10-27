<?php
namespace local_ustar;
defined('MOODLE_INTERNAL') || die();

/** Author-only bridge from the canonical catalogue to a reusable editable draft. */
final class course_studio_material {
    /** Catalogue projection only: no per-row SQL or file reads. */
    public static function supported(\stdClass $item,string $filename=''): bool {
        if (in_array($item->type,['folder','assessment','quiz'],true) || $item->status==='archived') { return false; }
        return in_array($item->type,['course','scorm'],true)
            || in_array($item->modname ?? '',['page','book'],true)
            || in_array(strtolower(pathinfo($filename,PATHINFO_EXTENSION)),
                ['pdf','docx','zip','rcproject','json','txt','md','html','htm','png','jpg','jpeg','gif','webp'],true);
    }
    public static function open(int $id,int $actorid): array {
        return course_studio::open_material($id,$actorid);
    }
    public static function complete(int $id,\stdClass $project,string $token,int $actorid): array {
        return course_studio::open_material($id,$actorid,$project,$token);
    }
    private static function record(int $id,bool $lock=false): \stdClass {
        global $DB;
        $row=$DB->get_record_sql('SELECT * FROM {local_ustar_content} WHERE id=:id'.($lock?' FOR UPDATE':''),['id'=>$id]);
        if (!$row || in_array($row->type,['folder','assessment','quiz'],true) || $row->status==='archived') {
            throw new \invalid_parameter_exception('Этот материал недоступен для редактирования в Студии курсов.');
        }
        return $row;
    }
    private static function blueprint(int $id): ?\stdClass {
        global $DB;
        return $DB->get_record('local_ustar_content_blueprints',['contentid'=>$id]) ?: null;
    }
    private static function linked(int $id,int $actorid): ?array {
        global $DB;
        $rows=$DB->get_records_sql('SELECT e.id,e.detailsjson FROM {local_ustar_workflow_events} e
            JOIN {local_ustar_content} c ON c.id=e.entityid
            WHERE e.entitytype=:type AND e.eventtype=:event AND c.status=:status
              AND '.$DB->sql_compare_text('e.reason').'=:source ORDER BY e.id DESC',
            ['type'=>'studio_material_open','event'=>'draft_created','status'=>'draft','source'=>(string)$id],0,1);
        if (!$rows) { return null; }
        $details=json_decode(reset($rows)->detailsjson);
        if (!is_object($details) || !is_string($details->code ?? null)) {
            throw new \invalid_parameter_exception('Связь черновика повреждена. Откройте материал через каталог.');
        }
        return course_studio::get($details->code,$actorid);
    }
    private static function result(array $project,\stdClass $source): array {
        return ['kind'=>'project','code'=>$project['code'],'contentid'=>$project['contentid'],
            'origin'=>['id'=>(int)$source->id,'title'=>$source->title,'published'=>$source->status==='published',
                'editingCopy'=>$project['contentid']!==(int)$source->id]];
    }
    /** @internal Invoked only under course_studio's canonical hierarchy lock. */
    public static function open_locked(int $id,int $actorid,?\stdClass $project,string $token,callable $store): array {
        global $DB;
        course_studio::require_author($actorid,true);
        $tx=$DB->start_delegated_transaction();
        try {
            $source=self::record($id,true);$blueprint=self::blueprint($id);
            $data=$blueprint?json_decode($blueprint->sourcejson):null;
            if ($blueprint && !is_object($data)) { throw new \invalid_parameter_exception('У материала повреждён редактируемый источник. Откройте сохранённый пакет или документ.'); }
            if ($source->status==='draft' && isset($data->visualproject)) {
                $answer=self::result(course_studio::get($data->visualproject->data->code,$actorid),$source);
                $tx->allow_commit();return $answer;
            }
            if ($linked=self::linked($id,$actorid)) {
                $tx->allow_commit();return self::result($linked,$source);
            }
            $code='MATERIAL-'.$id.'-'.bin2hex(random_bytes(8));
            if (isset($data->visualproject)) {
                $project=json_decode(course_studio_project::encode($data->visualproject));
            } else {
                $prepared=self::source($source,$blueprint,$data,$code,$actorid);
                if ($prepared['kind']==='file') {
                    if ($project===null) { $tx->allow_commit();return $prepared; }
                    if (!preg_match('/^[a-f0-9]{64}$/D',$token) || !hash_equals($prepared['sourceToken'],$token)) {
                        throw new course_studio_conflict('Источник изменился. Снова откройте материал из каталога.');
                    }
                } else { $project=$prepared['project']; }
            }
            if (!is_object($project->data ?? null)) { throw new \invalid_parameter_exception('Некорректный проект материала.'); }
            $project->data->code=$code;$project->data->title=$source->title;$project->revision=0;
            course_studio_project::validate($project);
            $saved=$store($project);
            // New draft inherits placement and audience; it never defaults a restricted source to "all".
            $draft=$DB->get_record('local_ustar_content',['id'=>$saved['contentid']], '*',MUST_EXIST);
            $draft->parentid=$source->parentid;$draft->summary=$source->summary;$draft->ackrequired=$source->ackrequired;
            $DB->update_record('local_ustar_content',$draft);
            $DB->delete_records('local_ustar_content_access',['contentid'=>$draft->id]);
            foreach ($DB->get_records('local_ustar_content_access',['contentid'=>$id,'active'=>1]) as $access) {
                unset($access->id);$access->contentid=$draft->id;$access->createdby=$actorid;$access->timecreated=time();
                $DB->insert_record('local_ustar_content_access',$access);
            }
            $DB->insert_record('local_ustar_workflow_events',(object)['entitytype'=>'studio_material_open',
                'entityid'=>$draft->id,'eventtype'=>'draft_created','actorid'=>$actorid,'reason'=>(string)$id,
                'detailsjson'=>course_studio_project::encode(['sourcecontentid'=>$id,'code'=>$code,
                    'draftcontentid'=>(int)$draft->id,'sourceversion'=>(int)($blueprint->sourceversion ?? 0)]),'timecreated'=>time()]);
            $tx->allow_commit();return self::result(course_studio::get($code,$actorid),$source);
        } catch (\Throwable $e) { $tx->rollback($e);throw $e; }
    }
    private static function module(\stdClass $source): ?\stdClass {
        global $DB;
        if ($source->sourcekind!==content::SOURCE_MOODLE || !$source->cmid) { return null; }
        $row=$DB->get_record_sql('SELECT cm.id,cm.instance,cm.course,m.name FROM {course_modules} cm
            JOIN {modules} m ON m.id=cm.module WHERE cm.id=:id AND cm.deletioninprogress=0',['id'=>$source->cmid]);
        if (!$row || ((int)$source->courseid && (int)$source->courseid!==(int)$row->course)) {
            throw new \invalid_parameter_exception('Исходная активность Moodle недоступна.');
        }
        return $row;
    }
    private static function file_for(\stdClass $source,?\stdClass $blueprint,int $actorid): ?\stored_file {
        if ($source->sourcekind===content::SOURCE_FILE) {
            $v=content::current_version((int)$source->id);
            if (!$v) { return null; }
            $files=get_file_storage()->get_area_files(\context_system::instance()->id,'local_ustar','content_version',
                $v->id,'id ASC',false);
            return $files?reset($files):null;
        }
        if ($blueprint && $blueprint->kind==='scorm' && $blueprint->packagestatus==='imported') {
            return material_studio::package_file((int)$blueprint->id,$actorid);
        }
        $module=self::module($source);
        if (!$module || !in_array($module->name,['resource','scorm'],true)) { return null; }
        $context=\context_module::instance((int)$module->id,IGNORE_MISSING);
        if (!$context) { return null; }
        $files=get_file_storage()->get_area_files($context->id,'mod_'.$module->name,
            $module->name==='resource'?'content':'package',0,'sortorder DESC,id ASC',false);
        return $files?reset($files):null;
    }
    public static function file(int $id,int $actorid): \stored_file {
        course_studio::require_author($actorid);
        $source=self::record($id);$file=self::file_for($source,self::blueprint($id),$actorid);
        if (!$file) { throw new \invalid_parameter_exception('Исходный файл материала недоступен.'); }
        self::file_descriptor($source,$file);return $file;
    }
    private static function file_descriptor(\stdClass $source,\stored_file $file): array {
        $format=strtolower(pathinfo($file->get_filename(),PATHINFO_EXTENSION));
        $types=['pdf'=>'pdf','docx'=>'word','zip'=>'scorm','rcproject'=>'project','json'=>'project',
            'txt'=>'text','md'=>'text','html'=>'html','htm'=>'html','png'=>'image','jpg'=>'image','jpeg'=>'image','gif'=>'image','webp'=>'image'];
        if (!isset($types[$format]) || (int)$file->get_filesize()>($format==='zip'?100:25)*1024*1024) {
            throw new \invalid_parameter_exception('Этот формат нельзя преобразовать в курс. Откройте исходный материал или импортируйте поддерживаемый проект.');
        }
        return ['kind'=>'file','format'=>$types[$format],'filename'=>$file->get_filename(),'size'=>(int)$file->get_filesize(),
            'sourceToken'=>hash('sha256',json_encode([$source->id,$source->timemodified,$file->get_contenthash()])),
            'origin'=>['id'=>(int)$source->id,'title'=>$source->title,'published'=>$source->status==='published','editingCopy'=>true]];
    }
    private static function native_package(\stored_file $file,\stdClass $source,array $pages): bool {
        if (!$pages) { return false; }
        $path=tempnam(sys_get_temp_dir(),'ustar-source-');
        try {
            $file->copy_content_to($path);$zip=new \ZipArchive();
            if ($zip->open($path)!==true) { return false; }
            try {
                $stat=$zip->statName('index.html');
                if (!$stat || (int)$stat['size']>8*1024*1024) { return false; }
                $html=$zip->getFromName('index.html');
                // Catalogue metadata may have been renamed after the package was built.
                // Still require the entire file to match our generator and exact pages.
                $title=is_string($html) && preg_match('~<title>([^<]*)</title>~s',$html,$match)
                    ? html_entity_decode($match[1],ENT_QUOTES|ENT_HTML5,'UTF-8') : $source->title;
                return $html===studio_scorm_package::html(['title'=>$title,'pages'=>$pages]);
            } finally { $zip->close(); }
        } finally { if ($path) { @unlink($path); } }
    }
    private static function source(\stdClass $source,?\stdClass $blueprint,?\stdClass $data,string $code,int $actorid): array {
        global $DB,$CFG;
        $locations=[];$pages=[];$file=self::file_for($source,$blueprint,$actorid);
        if ($blueprint && in_array($blueprint->kind,['course','scorm'],true) && $data) {
            $pages=array_map(static fn($p)=>(array)$p,(array)($data->pages ?? []));
            if (!$pages && trim(strip_tags((string)($data->body ?? '')))!=='') {
                $pages=[['title'=>$source->title,'body'=>(string)$data->body]];
            }
            if ($file && !self::native_package($file,$source,$pages)) { return self::file_descriptor($source,$file); }
        } else if ($module=self::module($source)) {
            $context=\context_module::instance((int)$module->id);
            if ($module->name==='page') {
                $page=$DB->get_record('page',['id'=>$module->instance], 'id,name,content',MUST_EXIST);
                $locations[]=['context'=>$context->id,'component'=>'mod_page','area'=>'content','item'=>0,'base'=>'/'];
                $prefix=$CFG->wwwroot.'/pluginfile.php/'.$context->id.'/mod_page/content/0/';
                $pages=[['title'=>$page->name,'body'=>str_replace('@@PLUGINFILE@@/',$prefix,$page->content)]];
            } else if ($module->name==='book') {
                $chapters=$DB->get_records('book_chapters',['bookid'=>$module->instance],'pagenum ASC','id,title,content',0,501);
                foreach ($chapters as $chapter) {
                    $locations[]=['context'=>$context->id,'component'=>'mod_book','area'=>'chapter','item'=>(int)$chapter->id,'base'=>'/'];
                    $prefix=$CFG->wwwroot.'/pluginfile.php/'.$context->id.'/mod_book/chapter/'.$chapter->id.'/';
                    $pages[]=['title'=>$chapter->title,'body'=>str_replace('@@PLUGINFILE@@/',$prefix,$chapter->content)];
                }
            }
        }
        if (!$pages && $file) {
            $descriptor=self::file_descriptor($source,$file);
            if ($descriptor['format']==='html' && $file->get_filesize()<=2000000) {
                $locations[]=['context'=>$file->get_contextid(),'component'=>$file->get_component(),
                    'area'=>$file->get_filearea(),'item'=>$file->get_itemid(),'base'=>$file->get_filepath()];
                $pages=[['title'=>$source->title,'body'=>$file->get_content()]];
            } else { return $descriptor; }
        }
        if (!$pages) { throw new \invalid_parameter_exception('У материала нет поддерживаемого редактируемого источника. Для тестов используйте редактор аттестаций.'); }
        $cache=[];
        $resolve=static function(string $src) use (&$cache,$locations,$actorid,$CFG): array {
            $key=hash('sha256',$src);if (isset($cache[$key])) { return $cache[$key]; }
            if (preg_match('~^data:(image/(?:png|jpeg|gif|webp));base64,([A-Za-z0-9+/=\r\n]+)$~D',$src,$m)) {
                $bytes=base64_decode($m[2],true);$type=$m[1];
            } else {
                $file=null;$prefix=$CFG->wwwroot.'/pluginfile.php/';
                foreach ($locations as $location) {
                    $base=$prefix.$location['context'].'/'.$location['component'].'/'.$location['area'].'/'.$location['item'].'/';
                    if (str_starts_with($src,$base)) { $relative=explode('?',substr($src,strlen($base)),2)[0];$path='/'; }
                    else if (!preg_match('~^(?:[a-z][a-z0-9+.-]*:|//|/)~i',$src)) { $relative=$src;$path=$location['base']; }
                    else { continue; }
                    $parts=explode('/',rawurldecode($relative));
                    if (in_array('..',$parts,true) || str_contains($relative,'\\') || str_contains($relative,"\0")) { continue; }
                    $name=array_pop($parts);$path.=($parts?implode('/',$parts).'/':'');
                    $file=get_file_storage()->get_file($location['context'],$location['component'],$location['area'],$location['item'],$path,$name);
                    if ($file) { break; }
                }
                if (!$file || $file->get_filesize()>25*1024*1024) {
                    throw new \invalid_parameter_exception('Изображение не принадлежит этому материалу или недоступно. Исходный материал сохранён.');
                }
                $bytes=$file->get_content();$type=(@getimagesizefromstring($bytes))['mime'] ?? '';
            }
            if (!is_string($bytes)) { throw new \invalid_parameter_exception('Повреждено изображение материала.'); }
            if (!in_array($type,['image/png','image/jpeg','image/gif','image/webp'],true)) { throw new \invalid_parameter_exception('Для студии преобразуйте изображение в PNG, JPEG, GIF или WebP.'); }
            $hash=hash('sha256',$bytes);$meta=course_studio::put_asset($hash,$bytes,$type,$actorid);
            $extension=['image/png'=>'png','image/jpeg'=>'jpg','image/gif'=>'gif','image/webp'=>'webp'][$type];
            return $cache[$key]=['path'=>'assets/material-'.$hash.'.'.$extension,
                'asset'=>(object)['type'=>$type,'hash'=>$hash,'size'=>$meta['size']]];
        };
        return ['kind'=>'native','project'=>course_studio_source::from_pages($source->title,$code,$pages,$resolve)];
    }
}
