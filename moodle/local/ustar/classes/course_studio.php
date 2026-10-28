<?php
namespace local_ustar;
defined('MOODLE_INTERNAL') || die();

/** Shared authoring over the canonical material blueprint, never a second course catalogue. */
final class course_studio {
    public const ASSET_AREA = 'course_studio_asset';
    public static function require_author(int $actorid, bool $write = false): void {
        global $USER;
        if ((int)$USER->id !== $actorid || !material_studio::can_manage($actorid)) {
            throw new \required_capability_exception(\context_system::instance(), 'local/ustar:hrmanage', 'nopermissions', '');
        }
        if ($write) { view_as::assert_writable(); }
    }
    private static function rows(): array {
        global $DB;
        return $DB->get_records_sql('SELECT b.*, c.timemodified AS contentmodified, c.status, c.cmid, c.courseid
            FROM {local_ustar_content_blueprints} b JOIN {local_ustar_content} c ON c.id=b.contentid
            WHERE b.kind = :kind ORDER BY b.id', ['kind' => 'scorm']);
    }
    private static function find(string $code): ?\stdClass {
        foreach (self::rows() as $row) {
            $s = json_decode($row->sourcejson);
            if (($s->visualproject->data->code ?? null) === $code) { return $row; }
        }
        return null;
    }
    private static function result(\stdClass $row, bool $include = true): array {
        $source = json_decode($row->sourcejson);
        $p = $source->visualproject;
        $out = ['code' => $p->data->code, 'title' => $p->data->title, 'contentid' => (int)$row->contentid,
            'revision' => (int)$row->sourceversion, 'contentmodified' => (int)$row->contentmodified,
            'status' => $row->status, 'fingerprint' => $source->fingerprint,
            'updatedAt' => gmdate('c', (int)$row->timemodified), 'cmid' => (int)$row->cmid];
        if ($include) { $out['course'] = $p; }
        return $out;
    }
    public static function listing(int $actorid): array {
        self::require_author($actorid);
        $out=[];
        foreach (self::rows() as $row) {
            $s=json_decode($row->sourcejson);
            if (isset($s->visualproject)) { $out[]=self::result($row,false); }
        }
        return $out;
    }
    public static function get(string $code, int $actorid): array {
        self::require_author($actorid);
        $row=self::find($code);
        if (!$row) { throw new \invalid_parameter_exception('Проект не найден.'); }
        return self::result($row);
    }
    public static function asset(string $hash, int $actorid): ?\stored_file {
        self::require_author($actorid);
        if (!preg_match('/^[a-f0-9]{64}$/D',$hash)) { throw new \invalid_parameter_exception('Некорректный SHA256.'); }
        return get_file_storage()->get_file(\context_system::instance()->id,'local_ustar',self::ASSET_AREA,0,'/',$hash) ?: null;
    }
    public static function put_asset(string $hash, string $bytes, string $type, int $actorid): array {
        global $DB;
        self::require_author($actorid,true);
        if (!hash_equals(hash('sha256',$bytes),$hash)) { throw new \invalid_parameter_exception('SHA256 ресурса не совпал.'); }
        course_studio_project::asset_type($bytes,$type);
        $lock=\core\lock\lock_config::get_lock_factory('local_ustar_content')->get_lock('studioasset:'.$hash,10);
        if (!$lock) { throw new course_studio_conflict(); }
        try {
            $file=self::asset($hash,$actorid);
            if (!$file) {
                // Bound orphan uploads too; immutable shared assets are retained for source history.
                $used=(int)$DB->get_field_sql('SELECT COALESCE(SUM(filesize),0) FROM {files}
                    WHERE component=:c AND filearea=:a AND userid=:u', ['c'=>'local_ustar','a'=>self::ASSET_AREA,'u'=>$actorid]);
                if ($used+strlen($bytes)>2*1024*1024*1024) { throw new \invalid_parameter_exception('Квота ресурсов автора 2 ГБ исчерпана.'); }
                $file=get_file_storage()->create_file_from_string((object)['contextid'=>\context_system::instance()->id,
                    'component'=>'local_ustar','filearea'=>self::ASSET_AREA,'itemid'=>0,'filepath'=>'/','filename'=>$hash,
                    'userid'=>$actorid,'mimetype'=>$type],$bytes);
            }
            return ['hash'=>$hash,'size'=>(int)$file->get_filesize(),'type'=>$file->get_mimetype()];
        } finally { $lock->release(); }
    }
    /** Serialize every material/source writer in the existing canonical lock order. */
    private static function write_lock(int $actorid, callable $operation): mixed {
        self::require_author($actorid,true);
        $lock=\core\lock\lock_config::get_lock_factory('local_ustar_content')->get_lock('hierarchy',10);
        if (!$lock) { throw new course_studio_conflict(); }
        try { return $operation(); } finally { $lock->release(); }
    }
    public static function save(\stdClass $p,int $base,int $modified,string $mutation,int $actorid): array {
        return self::write_lock($actorid,static fn()=>self::store($p,$base,$modified,$mutation,$actorid));
    }
    /** Entry point for the source adapter; keeps copy/event/audience in one transaction. */
    public static function open_material(int $id,int $actorid,?\stdClass $project=null,string $token=''): array {
        return self::write_lock($actorid,static fn()=>course_studio_material::open_locked($id,$actorid,$project,$token,
            static fn($p)=>self::store($p,0,0,'material-open-'.bin2hex(random_bytes(12)),$actorid)));
    }
    private static function store(\stdClass $p,int $base,int $modified,string $mutation,int $actorid): array {
        global $DB;
        self::require_author($actorid,true);
        if (!material_studio::available()) { throw new \invalid_parameter_exception('Сначала обновите Академию.'); }
        course_studio_project::validate($p);
        if (!preg_match('/^[a-zA-Z0-9_-]{1,80}$/D',$mutation)) { throw new \invalid_parameter_exception('Некорректный ключ запроса.'); }
        foreach ((array)$p->assets as $a) {
            $file=self::asset($a->hash,$actorid);
            if (!$file || (int)$file->get_filesize()!==$a->size || $file->get_mimetype()!==$a->type) {
                throw new \invalid_parameter_exception('Сначала загрузите все ресурсы курса.');
            }
        }
        $factory=\core\lock\lock_config::get_lock_factory('local_ustar_content');
        $lock=null;$tx=null;
        try {
            $row=self::find($p->data->code);
            $fingerprint=course_studio_project::fingerprint($p);
            if ($row) {
                $lock=$factory->get_lock('studio:'.$row->contentid,10);
                if (!$lock) { throw new course_studio_conflict(); }
            }
            $tx=$DB->start_delegated_transaction();
            if ($row) {
                $content=$DB->get_record_sql('SELECT * FROM {local_ustar_content} WHERE id=:id FOR UPDATE', ['id'=>$row->contentid],MUST_EXIST);
                $blueprint=$DB->get_record_sql('SELECT * FROM {local_ustar_content_blueprints} WHERE id=:id FOR UPDATE',['id'=>$row->id],MUST_EXIST);
                $source=json_decode($blueprint->sourcejson);
                // Exact accepted payload is idempotent even after a metadata edit; never modifies that metadata.
                if (($source->fingerprint ?? '')===$fingerprint) { $tx->allow_commit();return self::get($p->data->code,$actorid); }
                if ($content->status!==content::STATUS_DRAFT || $base!==(int)$blueprint->sourceversion
                        || $modified!==(int)$content->timemodified) { throw new course_studio_conflict(); }
                $content->title=$p->data->title;$content->timemodified=max(time(),$modified+1);$content->usermodified=$actorid;
                $DB->update_record('local_ustar_content',$content);
            } else {
                if ($base!==0) { throw new course_studio_conflict(); }
                $id=$DB->insert_record('local_ustar_content',(object)['parentid'=>null,'type'=>'scorm',
                    'title'=>$p->data->title,'summary'=>'','category'=>'learning','status'=>'draft',
                    'sourcekind'=>content::SOURCE_EXTERNAL,'courseid'=>null,'cmid'=>null,'externalurl'=>'',
                    'owneruserid'=>$actorid,'ackrequired'=>0,'publishedat'=>null,'sortorder'=>0,
                    'timecreated'=>time(),'timemodified'=>time(),'usermodified'=>$actorid]);
                // A draft has no learner access until explicit publication through the existing audience editor.
                $DB->insert_record('local_ustar_content_access',(object)['contentid'=>$id,'scopetype'=>'all','scopeid'=>null,
                    'active'=>1,'timecreated'=>time(),'createdby'=>$actorid]);
                $blueprint=(object)['contentid'=>$id,'kind'=>'scorm','sourceversion'=>0,'timecreated'=>time(),
                    'packagestatus'=>'none','packagefilename'=>null];
            }
            $source=(object)['body'=>'','outline'=>'','questions'=>[],'pages'=>[],'passscore'=>80,
                'visualproject'=>$p,'fingerprint'=>$fingerprint,'mutation'=>$mutation];
            $blueprint->sourcejson=course_studio_project::encode($source);
            $blueprint->sourceversion++;$blueprint->sourcehash=hash('sha256',$blueprint->sourcejson);
            $blueprint->timemodified=time();$blueprint->authorid=$actorid;
            if (empty($blueprint->id)) { $blueprint->id=$DB->insert_record('local_ustar_content_blueprints',$blueprint); }
            else { $DB->update_record('local_ustar_content_blueprints',$blueprint); }
            $DB->insert_record('local_ustar_workflow_events',(object)['entitytype'=>'studio_material','entityid'=>$blueprint->contentid,
                'eventtype'=>'studio_material_saved','actorid'=>$actorid,'reason'=>null,
                'detailsjson'=>course_studio_project::encode(['sourceversion'=>$blueprint->sourceversion,'sourcehash'=>$blueprint->sourcehash,
                    'title'=>$p->data->title,'summary'=>'','kind'=>'scorm','source'=>$source]),'timecreated'=>time()]);
            $tx->allow_commit();return self::get($p->data->code,$actorid);
        } catch (\Throwable $e) { if ($tx) { $tx->rollback($e); } throw $e; }
        finally { if ($lock) { $lock->release(); } }
    }
    public static function prepare(string $code,int $revision,int $modified,int $actorid): array {
        self::require_author($actorid,true);
        $item=self::get($code,$actorid);
        if ($item['revision']!==$revision) { throw new course_studio_conflict(); }
        // A matching runtime is a successful retry, including after its content timestamp changed.
        if (material_studio::runtime_ready($item['contentid'])) { return $item; }
        if ($item['status']!=='draft' || $item['contentmodified']!==$modified) { throw new course_studio_conflict(); }
        $path=tempnam(sys_get_temp_dir(),'ustar-studio-');
        if ($path===false) { throw new \moodle_exception('cannotcreatetempdir'); }
        try {
            course_studio_package::build($item['course'],$path,$actorid);
            studio_scorm_runtime::import($item['contentid'],$actorid,$path,'ustar-'.$item['contentid'].'-v'.$revision.'.zip',$revision,$modified);
            return self::get($code,$actorid);
        } finally { @unlink($path); }
    }
}
