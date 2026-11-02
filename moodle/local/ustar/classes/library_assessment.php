<?php
namespace local_ustar;
defined('MOODLE_INTERNAL') || die();

/** Optional knowledge checks. The only learning write is the frozen attempt and its mark. */
final class library_assessment {
    public const TARGET_QUICK='quick';
    public const TARGET_GROUP='group';
    public const TARGET_TOPIC='topic';
    public const TARGET_SKILL='skill';
    public const STATUS_DRAFT='draft';
    public const STATUS_PUBLISHED='published';
    public const STATUS_ARCHIVED='archived';
    public const ATTEMPT_STARTED='started';
    public const ATTEMPT_SUBMITTED='submitted';

    public static function available(): bool {
        global $DB;return $DB->get_manager()->table_exists(new \xmldb_table('local_ustar_library_assessment'));
    }
    public static function get(int $id): ?\stdClass {
        global $DB;return $DB->get_record('local_ustar_library_assessment',['id'=>$id]) ?: null;
    }
    public static function can_open(object $definition,int $userid): bool {
        if (!library_access::eligible($userid) || $definition->status!=='published') { return false; }
        if (in_array($definition->targettype,[self::TARGET_QUICK,self::TARGET_GROUP],true)
                && !library_runtime::catalog_allowed((int)$definition->targetkey,$userid)) { return false; }
        $offer=library_offer::for_subject('assessment',(int)$definition->id);
        return !$offer || library_access::resolve((int)$offer->id,$userid)['canopen'];
    }
    private static function require_open(object $definition,int $userid): void {
        if (!self::can_open($definition,$userid)) { library_access::fail('Проверка недоступна. Вернитесь в Библиотеку.'); }
    }

    public static function open_attempt(int $id,int $userid): ?\stdClass {
        global $DB;
        $rows=$DB->get_records('local_ustar_library_attempt',['assessmentid'=>$id,'userid'=>$userid,'status'=>'started'],'id DESC','*',0,1);
        return $rows ? reset($rows) : null;
    }

    /** POST command only. A refresh uses open_attempt(), never creates a new attempt. */
    public static function get_or_start(int $id,int $userid): array {
        global $DB;
        library_access::require_actor($userid);
        return self::locked($id,$userid,static function() use($DB,$id,$userid): array {
            $definition=$DB->get_record('local_ustar_library_assessment',['id'=>$id],'*',MUST_EXIST);
            self::require_open($definition,$userid);
            $open=self::open_attempt($id,$userid);
            if ($open) { return ['attempt'=>$open,'questions'=>self::questions($open->questionsjson),'isfresh'=>false]; }
            $used=$DB->count_records('local_ustar_library_attempt',['assessmentid'=>$id,'userid'=>$userid,
                'versionno'=>(int)$definition->revision,'status'=>'submitted']);
            if ($used >= (int)$definition->maxattempts) { library_access::fail('Попытки этой версии исчерпаны. Обратитесь к руководителю.'); }
            $questions=self::questions($definition->questionsjson);
            shuffle($questions);$questions=array_slice($questions,0,(int)$definition->questioncount);
            if (count($questions)!==(int)$definition->questioncount || !$questions) { library_access::fail('Проверка ещё не подготовлена.'); }
            $attempt=(object)['assessmentid'=>$id,'userid'=>$userid,'versionno'=>(int)$definition->revision,
                'status'=>'started','score'=>0,'passed'=>0,'questionsjson'=>json_encode($questions,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR),
                'passpercent'=>(int)$definition->passpercent,'maxattempts'=>(int)$definition->maxattempts,
                'answersjson'=>'','submissionhash'=>'','idempotencykey'=>bin2hex(random_bytes(32)),
                'startedat'=>time(),'submittedat'=>0];
            $attempt->id=(int)$DB->insert_record('local_ustar_library_attempt',$attempt);
            return ['attempt'=>$attempt,'questions'=>$questions,'isfresh'=>true];
        });
    }

    public static function submit(int $attemptid,int $userid,array $answers): array {
        global $DB;
        library_access::require_actor($userid);
        $initial=$DB->get_record('local_ustar_library_attempt',['id'=>$attemptid,'userid'=>$userid],'*',MUST_EXIST);
        return self::locked((int)$initial->assessmentid,$userid,static function() use($DB,$attemptid,$userid,$answers): array {
            $attempt=$DB->get_record_sql('SELECT * FROM {local_ustar_library_attempt} WHERE id=:id AND userid=:uid FOR UPDATE',
                ['id'=>$attemptid,'uid'=>$userid],MUST_EXIST);
            $definition=$DB->get_record('local_ustar_library_assessment',['id'=>(int)$attempt->assessmentid],'*',MUST_EXIST);
            self::require_open($definition,$userid);
            $questions=self::questions($attempt->questionsjson);$clean=[];$correct=0;
            $submitted=$answers;
            foreach ($questions as $i=>$q) {
                $raw=$submitted['q'.$i]??null;
                if (!is_scalar($raw) || !preg_match('/^\d+$/D',(string)$raw) || !isset($q['options'][(int)$raw])) {
                    library_access::fail('Ответьте на каждый вопрос.');
                }
                $given=(int)$raw;$clean['q'.$i]=$given;$correct+=(int)($given===$q['correct']);
            }
            $json=json_encode($clean,JSON_THROW_ON_ERROR);$hash=hash('sha256',$json);
            if ($attempt->status==='submitted') {
                if (!hash_equals((string)$attempt->submissionhash,$hash)) { library_access::fail('Ответы этой попытки уже сохранены.'); }
                return self::result($attempt,$correct,count($questions));
            }
            if ($attempt->status!=='started') { library_access::fail('Попытка закрыта.'); }
            $attempt->score=(int)round($correct/count($questions)*100);
            $attempt->passed=(int)($attempt->score >= (int)$attempt->passpercent);
            $attempt->status='submitted';$attempt->answersjson=$json;$attempt->submissionhash=$hash;$attempt->submittedat=time();
            $DB->update_record('local_ustar_library_attempt',$attempt);
            return self::result($attempt,$correct,count($questions));
        });
    }

    private static function locked(int $id,int $userid,callable $callback): mixed {
        global $DB;
        $lock=\core\lock\lock_config::get_lock_factory('local_ustar')->get_lock('library-check:'.$id.':'.$userid,10);
        if (!$lock) { library_access::fail('Ответ сейчас сохраняется. Повторите действие.'); }
        try {
            $tx=$DB->start_delegated_transaction();
            try { $result=$callback();$tx->allow_commit();return $result; }
            catch (\Throwable $e) { $tx->rollback($e);throw $e; }
        } finally { $lock->release(); }
    }
    private static function result(object $attempt,int $correct,int $total): array {
        global $DB;
        $used=$DB->count_records('local_ustar_library_attempt',['assessmentid'=>$attempt->assessmentid,'userid'=>$attempt->userid,
            'versionno'=>$attempt->versionno,'status'=>'submitted']);
        return ['passed'=>(bool)$attempt->passed,'score'=>$correct,'total'=>$total,'percent'=>(int)$attempt->score,
            'attemptsleft'=>max(0,(int)$attempt->maxattempts-$used)];
    }
    public static function best(int $id,int $userid): ?\stdClass {
        global $DB;$definition=self::get($id);if (!$definition) { return null; }
        $rows=$DB->get_records('local_ustar_library_attempt',['assessmentid'=>$id,'userid'=>$userid,
            'versionno'=>(int)$definition->revision,'status'=>'submitted'],'passed DESC,score DESC,id DESC','*',0,1);
        return $rows ? reset($rows) : null;
    }
    public static function questions(string $json): array {
        $bank=json_decode($json,true);
        if (!is_array($bank) || !$bank || count($bank)>100 || !array_is_list($bank)) { library_access::fail('Нужен набор от1 до100 вопросов.'); }
        $out=[];
        foreach ($bank as $q) {
            if (!is_array($q) || !is_string($q['text']??null) || !is_array($q['options']??null)
                    || !array_is_list($q['options']) || count($q['options'])<2 || count($q['options'])>6
                    || !is_int($q['correct']??null) || !isset($q['options'][$q['correct']])) {
                library_access::fail('У каждого вопроса нужны текст, 2–6 ответов и номер верного ответа.');
            }
            $text=trim(clean_param($q['text'],PARAM_TEXT));$options=[];
            foreach ($q['options'] as $value) {
                if (!is_string($value)) { library_access::fail('Ответы должны быть текстовыми.'); }
                $value=trim(clean_param($value,PARAM_TEXT));
                if ($value==='' || \core_text::strlen($value)>1000) { library_access::fail('Проверьте текст ответа.'); }
                $options[]=$value;
            }
            if ($text==='' || \core_text::strlen($text)>4000 || count(array_unique($options))!==count($options)) { library_access::fail('Вопрос и варианты должны быть непустыми, варианты — разными.'); }
            $out[]=['text'=>$text,'options'=>$options,'correct'=>$q['correct']];
        }
        return $out;
    }

    /** Normalise editor blanks without ever shifting the correct answer to a different option. */
    public static function editor_questions(array $rows): string {
        if (count($rows)>100) { library_access::fail('Допускается не более100 вопросов.'); }
        $questions=[];
        foreach ($rows as $q) {
            if (!is_array($q) || !is_string($q['text']??null)) { library_access::fail('Проверьте текст вопроса.'); }
            if (trim($q['text'])==='') { continue; }
            if (!is_array($q['options']??null) || !is_scalar($q['correct']??null)
                    || !preg_match('/^[0-5]$/D',(string)$q['correct'])) { library_access::fail('Выберите верный ответ.'); }
            $options=[];
            foreach ($q['options'] as $value) {
                if (!is_string($value)) { library_access::fail('Ответы должны быть текстовыми.'); }
                $options[]=trim($value);
            }
            // Only trailing unused slots are removed. An empty slot in the middle is an error.
            while ($options && end($options)==='') { array_pop($options); }
            $questions[]=['text'=>$q['text'],'options'=>$options,'correct'=>(int)$q['correct']];
        }
        return json_encode(self::questions(json_encode($questions,JSON_THROW_ON_ERROR)),JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);
    }

    public static function save(int $id,array $input,int $actorid,int $expectedrevision=0): int {
        global $DB;
        library_access::require_actor($actorid);
        if (!library_access::can_assess($actorid)) { library_access::fail('Недостаточно прав для редактора проверок.'); }
        $target=(string)($input['targettype']??'topic');$key=trim(clean_param((string)($input['targetkey']??''),PARAM_TEXT));
        if (!in_array($target,['topic','skill','group'],true) || $key==='' || \core_text::strlen($key)>128) { library_access::fail('Укажите тему, навык или группу товаров.'); }
        if ($target==='group' && !library_access::catalog_active((int)$key)) { library_access::fail('Группа недоступна.'); }
        $questions=self::questions((string)($input['questionsjson']??''));
        $title=trim(clean_param((string)($input['title']??''),PARAM_TEXT));
        $count=(int)($input['questioncount']??count($questions));$pass=(int)($input['passpercent']??100);$max=(int)($input['maxattempts']??3);
        $status=(string)($input['status']??'draft');
        if ($title==='' || \core_text::strlen($title)>255 || $count<1 || $count>count($questions)
                || $pass<1 || $pass>100 || $max<1 || $max>100 || !in_array($status,['draft','published','archived'],true)) {
            library_access::fail('Проверьте название, число вопросов, порог и попытки.');
        }
        $lock=\core\lock\lock_config::get_lock_factory('local_ustar')->get_lock('library-bank:'.$id,10);
        if (!$lock) { library_access::fail('Проверка редактируется. Обновите страницу.'); }
        try {
            $tx=$DB->start_delegated_transaction();
            try {
                $old=$id ? self::get($id) : null;
                if ($id && (!$old || $old->targettype==='quick' || (int)$old->revision!==$expectedrevision)) { library_access::fail('Версия изменилась. Обновите страницу.'); }
                $record=(object)['sourcekey'=>$old->sourcekey??('manual:'.bin2hex(random_bytes(16))),
                    'targettype'=>$target,'targetkey'=>$key,'title'=>$title,'questionsjson'=>json_encode($questions,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR),
                    'questioncount'=>$count,'passpercent'=>$pass,'maxattempts'=>$max,'status'=>$status,
                    'revision'=>$old ? (int)$old->revision+1 : 1,'inputhash'=>'','timemodified'=>time(),'usermodified'=>$actorid];
                if ($id) { $record->id=$id;$DB->update_record('local_ustar_library_assessment',$record); }
                else { $record->timecreated=time();$id=(int)$DB->insert_record('local_ustar_library_assessment',$record); }
                library_offer::audit($id,'check_published',$actorid,['revision'=>$record->revision,'status'=>$status],'library_assessment');
                $tx->allow_commit();return $id;
            } catch (\Throwable $e) { $tx->rollback($e);throw $e; }
        } finally { $lock->release(); }
    }
}
