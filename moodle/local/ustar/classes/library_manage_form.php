<?php
namespace local_ustar;
defined('MOODLE_INTERNAL') || die();

/** Escaped-template form projections only. These values never authorise a write. */
final class library_manage_form {
    private static function scalar(array $input, string $name, mixed $default=''): string {
        $value=$input[$name]??$default;
        return is_scalar($value) ? (string)$value : '';
    }

    public static function offer(?\stdClass $record, ?array $draft): array {
        $values=$draft??(array)($record??new \stdClass());
        return [
            'id'=>(int)($record->id??0),
            'expectedmodified'=>self::scalar($values,$draft===null?'timemodified':'expectedmodified',0),
            'title'=>self::scalar($values,'title'), 'summary'=>self::scalar($values,'summary'),
            'accesspolicy'=>self::scalar($values,'accesspolicy','assigned'),
            'visibility'=>self::scalar($values,'visibility','draft'),
            'pricecoin'=>self::scalar($values,'pricecoin',0),
            'gatepointid'=>self::scalar($values,'gatepointid',0),
            'subject'=>$record ? $record->itemtype.':'.$record->itemid : self::scalar($values,'subject'),
        ];
    }

    public static function assessment(?\stdClass $record, ?array $draft): array {
        $values=$draft??(array)($record??new \stdClass());
        $target=$record ? $record->targettype.($record->targettype==='topic'?'':':'.$record->targetkey) : 'topic';
        $rows=$draft===null ? ($record ? library_assessment::questions($record->questionsjson) : array_fill(0,5,[]))
            : (is_array($draft['questions']??null) ? $draft['questions'] : []);
        return [
            'checkid'=>(int)($record->id??0),
            'checkrevision'=>self::scalar($values,$draft===null?'revision':'expectedrevision',0),
            'checktitle'=>self::scalar($values,'title'),
            'targetref'=>self::scalar($values,'targetref',$target),
            'topic'=>self::scalar($values,'topic',$record && $record->targettype==='topic'?$record->targetkey:''),
            'checkstatus'=>self::scalar($values,'status','draft'),
            'questioncount'=>self::scalar($values,'questioncount',2),
            'passpercent'=>self::scalar($values,'passpercent',100),
            'maxattempts'=>self::scalar($values,'maxattempts',3),
            'questionfields'=>self::question_fields($rows),
            'blankquestion'=>self::question_fields(['__key__'=>[]]),
            'opencheck'=>$draft!==null || $record!==null,
        ];
    }

    /** Keep option slots and correct answer untouched, even after invalid input. */
    public static function question_fields(array $rows): array {
        $fields=[];
        foreach (array_slice($rows,0,101,true) as $index=>$row) {
            $row=is_array($row)?$row:[];
            $key=preg_match('/^[0-9]{1,6}$|^__key__$/D',(string)$index) ? (string)$index : 'invalid'.count($fields);
            $correct=self::scalar($row,'correct',trim(self::scalar($row,'text'))===''?'0':'');
            $answers=is_array($row['options']??null)?$row['options']:[];
            $options=[];
            for ($i=0;$i<6;$i++) {
                $value=$answers[$i]??'';
                $options[]=['index'=>$i,'question'=>$key,'number'=>$i+1,
                    'value'=>is_scalar($value)?(string)$value:'','checked'=>$correct===(string)$i];
            }
            $fields[]=['index'=>$key,'number'=>count($fields)+1,'text'=>self::scalar($row,'text'),'options'=>$options];
        }
        return $fields;
    }
}
