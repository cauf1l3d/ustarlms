<?php
namespace local_ustar;
defined('MOODLE_INTERNAL') || die();

/** USTAR authoring orchestrator; Moodle owns quizzes, questions, grades and attempts. */
final class assessment_authoring {
    public static function require_manage(): void {
        $context = \context_system::instance();
        if (!has_capability('local/ustar:admin', $context)) {
            require_capability('local/ustar:hrmanage', $context);
        }
        view_as::assert_writable();
    }

    public static function bank(array $ids = []): array {
        global $DB;
        self::require_manage();
        $where = '';
        $params = [];
        if ($ids) {
            [$in,$params] = $DB->get_in_or_equal($ids, SQL_PARAMS_NAMED, 'bankq');
            $where = ' AND q.id '.$in;
        }
        // Only quizzes already curated into the USTAR catalog, never an arbitrary question id.
        return $DB->get_records_sql("SELECT DISTINCT q.id,q.name,q.qtype,q.defaultmark,qv.questionbankentryid,qv.version
            FROM {question} q
            JOIN {question_versions} qv ON qv.questionid=q.id
            JOIN {question_references} qr ON qr.questionbankentryid=qv.questionbankentryid
                AND qr.component='mod_quiz' AND qr.questionarea='slot'
            JOIN {quiz_slots} s ON s.id=qr.itemid
            JOIN {course_modules} cm ON cm.instance=s.quizid
            JOIN {modules} m ON m.id=cm.module AND m.name='quiz'
            JOIN {local_ustar_content} c ON c.cmid=cm.id AND c.sourcekind='moodle_cm'
            WHERE qv.status='ready' AND q.parent=0
              AND qv.version=COALESCE(qr.version,(SELECT MAX(v.version) FROM {question_versions} v
                  WHERE v.questionbankentryid=qv.questionbankentryid AND v.status='ready'))
              AND q.qtype IN ('multichoice','essay')".$where.' ORDER BY q.id DESC', $params, 0, $ids ? 100 : 500);
    }

    public static function validate(array $input): array {
        foreach (['title','pass','minutes'] as $field) {
            if (!isset($input[$field]) || !is_scalar($input[$field])) {
                throw new \invalid_parameter_exception('Некорректные настройки аттестации.');
            }
        }
        if (!is_array($input['questions'] ?? null) || !is_array($input['bankids'] ?? null)
                || count($input['bankids'])>100) {
            throw new \invalid_parameter_exception('Некорректный состав вопросов.');
        }
        foreach ($input['bankids'] as $id) {
            if (!is_scalar($id) || !ctype_digit((string)$id) || (int)$id<=0) {
                throw new \invalid_parameter_exception('Некорректный номер вопроса.');
            }
        }
        $title = trim((string)($input['title'] ?? ''));
        $pass = (string)($input['pass'] ?? '');
        $minutes = (string)($input['minutes'] ?? '');
        if ($title === '' || \core_text::strlen($title) > 150 || !is_numeric($pass)
                || (float)$pass < 1 || (float)$pass > 100
                || !ctype_digit($minutes) || (int)$minutes > 240) {
            throw new \invalid_parameter_exception('Укажите название до 150 символов, порог 1–100% и время 0–240 минут.');
        }
        $questions = assessment_authoring_input::questions($input['questions'] ?? []);
        $ids = array_values(array_unique(array_map('intval', $input['bankids'] ?? [])));
        if (count($questions)+count($ids) < 1 || count($questions)+count($ids) > 100) {
            throw new \invalid_parameter_exception('Добавьте от 1 до 100 вопросов суммарно.');
        }
        $bank = $ids ? self::bank($ids) : [];
        if (count($bank) !== count($ids)) {throw new \invalid_parameter_exception('Один из выбранных вопросов недоступен. Обновите банк.');}
        if (count(array_unique(array_column($bank,'questionbankentryid'))) !== count($bank)) {
            throw new \invalid_parameter_exception('В одном тесте нельзя использовать две версии одного вопроса.');
        }
        if (strlen(json_encode($input,JSON_UNESCAPED_UNICODE))>524288) {
            throw new \invalid_parameter_exception('Общий объём вопросов слишком большой. Разделите аттестацию.');
        }
        return ['title'=>$title,'pass'=>(float)$pass,'minutes'=>(int)$minutes,'questions'=>$questions,'bankids'=>$ids];
    }

    public static function create(array $input, string $token): array {
        global $CFG, $DB, $USER;
        self::require_manage();
        if (!preg_match('/^[a-f0-9]{32}$/', $token)) {throw new \invalid_parameter_exception('Неверный ключ сохранения.');}
        $input = self::validate($input);
        require_once($CFG->dirroot.'/course/lib.php');
        require_once($CFG->dirroot.'/course/modlib.php');
        require_once($CFG->libdir.'/questionlib.php');
        require_once($CFG->dirroot.'/mod/quiz/locallib.php');
        $lock = \core\lock\lock_config::get_lock_factory('local_ustar_authoring')->get_lock('create:'.$USER->id.':'.$token, 10);
        if (!$lock) {throw new \moodle_exception('Сохранение уже выполняется. Повторите запрос.');}
        try {
            $existing = $DB->get_record('local_ustar_workflow_events',
                ['entitytype'=>'assessment_studio','eventtype'=>'created','actorid'=>$USER->id,'reason'=>$token]);
            $inputhash = hash('sha256', json_encode($input,JSON_UNESCAPED_UNICODE));
            if ($existing) {
                $saved = json_decode($existing->detailsjson, true, 512, JSON_THROW_ON_ERROR);
                if (($saved['inputhash'] ?? '')!==$inputhash) {
                    throw new \invalid_parameter_exception('Эта форма уже сохранена с другим составом. Откройте новую форму.');
                }
                return $saved;
            }
            $tx = $DB->start_delegated_transaction();
            try {
                // One course per assessment prevents enrolment in one test granting access to other tests.
                $course = create_course((object)['fullname'=>$input['title'],
                    'shortname'=>'USTAR-AT-'.$token, 'idnumber'=>'USTAR-AT-'.$token,
                    'category'=>\core_course_category::get_default()->id,
                    'format'=>'topics','numsections'=>1,'visible'=>1,'enablecompletion'=>1]);
                foreach (enrol_get_instances($course->id, false) as $instance) {
                    if ($instance->enrol !== 'manual') {
                        $plugin = enrol_get_plugin($instance->enrol);
                        if (!$plugin) {throw new \moodle_exception('Неизвестный способ зачисления.');}
                        $plugin->update_status($instance, ENROL_INSTANCE_DISABLED);
                    }
                }
                $manual = enrol_get_plugin('manual');
                if (!$manual) {throw new \moodle_exception('Ручное зачисление Moodle отключено.');}
                if (!$DB->record_exists('enrol', ['courseid'=>$course->id,'enrol'=>'manual','status'=>ENROL_INSTANCE_ENABLED])) {
                    $roles = get_archetype_roles('student');
                    if (!$roles) {throw new \moodle_exception('Не найдена роль обучающегося.');}
                    $manual->add_instance($course, ['status'=>ENROL_INSTANCE_ENABLED,'roleid'=>reset($roles)->id]);
                }
                $info = (object)['modulename'=>'quiz','module'=>$DB->get_field('modules','id',['name'=>'quiz'],MUST_EXIST),
                    'course'=>$course->id,'section'=>1,'name'=>$input['title'],'intro'=>'','introformat'=>FORMAT_PLAIN,
                    'visible'=>1,'visibleoncoursepage'=>1,'groupmode'=>0,'groupingid'=>0,
                    'completion'=>COMPLETION_TRACKING_AUTOMATIC,'completionusegrade'=>1,'completiongradeitemnumber'=>0,'completionpassgrade'=>1,
                    'completionview'=>0,'completionexpected'=>0,'timeopen'=>0,'timeclose'=>0,
                    'timelimit'=>$input['minutes']*60,'overduehandling'=>'autosubmit','graceperiod'=>0,
                    'preferredbehaviour'=>'deferredfeedback','attempts'=>3,'attemptonlast'=>0,
                    'grademethod'=>QUIZ_GRADEHIGHEST,'decimalpoints'=>2,'questiondecimalpoints'=>-1,
                    'questionsperpage'=>1,'shuffleanswers'=>1,'sumgrades'=>0,'grade'=>100,'gradepass'=>$input['pass'],
                    'quizpassword'=>'','subnet'=>'','browsersecurity'=>'','delay1'=>0,'delay2'=>0,
                    'showuserpicture'=>0,'showblocks'=>0,'navmethod'=>'free'];
                // Do not disclose answer keys while an assessment is open.
                foreach (['during','immediately','open','closed'] as $time) {
                    foreach (['attempt','correctness','maxmarks','marks','specificfeedback','generalfeedback','rightanswer','overallfeedback'] as $field) {
                        $info->{$field.$time} = in_array($field,['attempt','maxmarks','marks'],true) ? 1 : 0;
                    }
                }
                $info = add_moduleinfo($info, $course);
                $quiz = $DB->get_record('quiz',['id'=>$info->instance],'*',MUST_EXIST);
                $quiz->cmid = $info->coursemodule;
                $context = \context_module::instance($quiz->cmid);
                $topcategory = question_get_top_category($context->id, true);
                $categoryid = $DB->insert_record('question_categories', (object)[
                    'contextid'=>$context->id,'name'=>'Банк: '.$input['title'], 'info'=>'','infoformat'=>FORMAT_PLAIN,
                    'stamp'=>make_unique_id_code(),'parent'=>$topcategory->id,'sortorder'=>999]);
                $ids = $input['bankids'];
                foreach ($input['questions'] as $row) {$ids[] = self::save_question($row, $categoryid);}
                foreach ($ids as $qid) {
                    if (!quiz_add_quiz_question($qid, $quiz)) {throw new \moodle_exception('Вопрос добавлен дважды.');}
                }
                // Pin every slot through the core API: later edits in the bank cannot silently change this test.
                $quizobj = \mod_quiz\quiz_settings::create($quiz->id);
                $structure = \mod_quiz\structure::create_for_quiz($quizobj);
                $refs = $DB->get_records_sql("SELECT s.id,qr.questionbankentryid FROM {quiz_slots} s
                    JOIN {question_references} qr ON qr.itemid=s.id AND qr.component='mod_quiz' AND qr.questionarea='slot'
                    WHERE s.quizid=:quizid", ['quizid'=>$quiz->id]);
                $versions = [];
                foreach ($DB->get_records_list('question_versions','questionid',$ids) as $v) {
                    $versions[(int)$v->questionbankentryid]=(int)$v->version;
                }
                foreach ($refs as $ref) {
                    if (!isset($versions[(int)$ref->questionbankentryid])) {
                        throw new \moodle_exception('Не удалось зафиксировать версию вопроса.');
                    }
                    $structure->update_slot_version((int)$ref->id,$versions[(int)$ref->questionbankentryid]);
                }
                $quizobj->get_grade_calculator()->recompute_quiz_sumgrades();
                $result = content::import_moodle_cm((int)$quiz->cmid,(int)$USER->id);
                $result['quizid'] = (int)$quiz->id;
                $result['inputhash'] = $inputhash;
                $DB->insert_record('local_ustar_workflow_events',(object)[
                    'entitytype'=>'assessment_studio','entityid'=>$quiz->cmid,'eventtype'=>'created',
                    'actorid'=>$USER->id,'reason'=>$token,'detailsjson'=>json_encode($result,JSON_UNESCAPED_UNICODE),
                    'timecreated'=>time()]);
                $tx->allow_commit();
                return $result;
            } catch (\Throwable $e) {$tx->rollback($e);}
        } finally {$lock->release();}
    }

    private static function save_question(array $row, int $categoryid): int {
        $editor = static fn($text)=>['text'=>$text,'format'=>FORMAT_PLAIN,'itemid'=>0];
        $type = $row['type'] === 'choice' ? 'multichoice' : 'essay';
        $q = (object)['qtype'=>$type];
        $form = (object)['category'=>(string)$categoryid,'name'=>\core_text::substr($row['text'],0,150),
            'questiontext'=>$editor($row['text']),'generalfeedback'=>$editor($row['feedback']),
            'defaultmark'=>(float)$row['points'],'penalty'=>0,'status'=>'ready'];
        if ($type === 'multichoice') {
            $form->single=1; $form->shuffleanswers=1; $form->answernumbering='abc';
            $form->showstandardinstruction=0; $form->shownumcorrect=0;
            foreach (['correctfeedback','partiallycorrectfeedback','incorrectfeedback'] as $f) {$form->$f=$editor('');}
            $form->answer=[]; $form->fraction=[]; $form->feedback=[];
            for ($i=1;$i<=4;$i++) {
                if ($row['option'.$i] === '') {continue;}
                $form->answer[]=$editor($row['option'.$i]);
                $form->fraction[]=(int)$row['correct']===$i ? 1 : 0;
                $form->feedback[]=$editor('');
            }
        } else {
            $form->responseformat='editor'; $form->responserequired=1; $form->responsefieldlines=10;
            $form->attachments=0; $form->attachmentsrequired=0; $form->maxbytes=0;
            $form->filetypeslist=''; $form->graderinfo=$editor(''); $form->responsetemplate=$editor('');
            $form->minwordlimitenabled=0; $form->maxwordlimitenabled=0;
        }
        $saved = \question_bank::get_qtype($type)->save_question($q,$form);
        if (empty($saved->id) || !empty($saved->errors)) {throw new \moodle_exception('Не удалось сохранить вопрос.');}
        return (int)$saved->id;
    }
}
