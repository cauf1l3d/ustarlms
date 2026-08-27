<?php
namespace local_ustar;

defined('MOODLE_INTERNAL') || die();

/** XMLDB definitions for the Constitution-backed TARGET core. */
final class target_schema {
    /** @return array<int,\xmldb_table> */
    public static function definitions(): array {
        $i = XMLDB_TYPE_INTEGER;
        $c = XMLDB_TYPE_CHAR;
        $t = XMLDB_TYPE_TEXT;
        $specs = [
            'local_ustar_evidence_rec' => [
                'fields' => [
                    ['id',$i,'10',true,true], ['userid',$i,'10',true], ['assignmentid',$i,'10'],
                    ['skillid',$c,'64'], ['positionid',$c,'64'], ['evidencetype',$c,'32',true],
                    ['sourcekind',$c,'32',true], ['sourceid',$c,'128',true], ['outcome',$c,'16',true],
                    ['status',$c,'16',true,false,'valid'], ['idempotencykey',$c,'128',true],
                    ['detailsjson',$t,null,true], ['validfrom',$i,'10',true,false,'0'], ['expiresat',$i,'10'],
                    ['recordedby',$i,'10',true,false,'0'], ['timecreated',$i,'10',true,false,'0'],
                ],
                'indexes' => [
                    ['idempotency_uix',true,['idempotencykey']], ['user_status_exp_idx',false,['userid','status','expiresat']],
                    ['skill_position_idx',false,['skillid','positionid','status']], ['source_idx',false,['sourcekind','sourceid']],
                ],
            ],
            'local_ustar_evidence_evt' => [
                'fields' => [
                    ['id',$i,'10',true,true], ['evidenceid',$i,'10',true], ['eventtype',$c,'16',true],
                    ['reason',$t,null,true], ['replacementid',$i,'10'], ['actorid',$i,'10',true,false,'0'],
                    ['timecreated',$i,'10',true,false,'0'],
                ],
                'indexes' => [['evidence_time_idx',false,['evidenceid','timecreated']]],
            ],
            'local_ustar_gate_defs' => [
                'fields' => [
                    ['id',$i,'10',true,true], ['code',$c,'64',true], ['title',$c,'255',true],
                    ['operationkey',$c,'64',true], ['riskclass',$c,'16',true], ['policyjson',$t,null,true],
                    ['versionno',$i,'10',true,false,'1'], ['status',$c,'16',true,false,'draft'],
                    ['effectivedate',$i,'10'], ['ownerid',$i,'10',true,false,'0'],
                    ['timecreated',$i,'10',true,false,'0'], ['timemodified',$i,'10',true,false,'0'],
                ],
                'indexes' => [
                    ['code_version_uix',true,['code','versionno']],
                    ['operation_status_idx',false,['operationkey','status','effectivedate']],
                ],
            ],
            'local_ustar_gate_decisions' => [
                'fields' => [
                    ['id',$i,'10',true,true], ['gateid',$i,'10',true], ['userid',$i,'10',true],
                    ['assignmentid',$i,'10'], ['decision',$c,'16',true], ['reason',$t,null,true],
                    ['evidencejson',$t,null,true], ['validfrom',$i,'10',true,false,'0'], ['expiresat',$i,'10'],
                    ['supersedesid',$i,'10'], ['decidedby',$i,'10',true], ['timecreated',$i,'10',true,false,'0'],
                ],
                'indexes' => [
                    ['user_gate_time_idx',false,['userid','gateid','timecreated']], ['assignment_idx',false,['assignmentid']],
                ],
            ],
            'local_ustar_check_submits' => [
                'fields' => [
                    ['id',$i,'10',true,true], ['checklistkey',$c,'64',true],
                    ['definitionversion',$i,'10',true,false,'1'], ['userid',$i,'10',true], ['assignmentid',$i,'10'],
                    ['perspective',$c,'16',true], ['workdate',$c,'10',true], ['status',$c,'16',true],
                    ['answersjson',$t,null,true], ['issuesjson',$t,null,true], ['correctionofid',$i,'10'],
                    ['submittedby',$i,'10',true], ['timecreated',$i,'10',true,false,'0'],
                ],
                'indexes' => [
                    ['user_check_date_idx',false,['userid','checklistkey','workdate']],
                    ['assignment_date_idx',false,['assignmentid','workdate']],
                ],
            ],
            'local_ustar_official_tasks' => [
                'fields' => [
                    ['id',$i,'10',true,true], ['userid',$i,'10',true], ['assignmentid',$i,'10'],
                    ['sourcekind',$c,'32',true], ['sourceid',$c,'128',true], ['category',$c,'32',true],
                    ['title',$c,'255',true], ['description',$t], ['completionjson',$t,null,true],
                    ['status',$c,'16',true,false,'open'], ['ownerid',$i,'10',true], ['createdby',$i,'10',true],
                    ['dueat',$i,'10'], ['completedat',$i,'10'], ['archivedat',$i,'10'],
                    ['timecreated',$i,'10',true,false,'0'], ['timemodified',$i,'10',true,false,'0'],
                ],
                'indexes' => [
                    ['user_status_due_idx',false,['userid','status','dueat']], ['source_idx',false,['sourcekind','sourceid']],
                ],
            ],
            'local_ustar_personal_tasks' => [
                'fields' => [
                    ['id',$i,'10',true,true], ['userid',$i,'10',true], ['title',$c,'255',true],
                    ['description',$t], ['status',$c,'16',true,false,'open'], ['dueat',$i,'10'],
                    ['sharedwithjson',$t,null,true], ['timecreated',$i,'10',true,false,'0'],
                    ['timemodified',$i,'10',true,false,'0'],
                ],
                'indexes' => [['user_status_due_idx',false,['userid','status','dueat']]],
            ],
            'local_ustar_workflow_events' => [
                'fields' => [
                    ['id',$i,'10',true,true], ['entitytype',$c,'32',true], ['entityid',$i,'10',true],
                    ['eventtype',$c,'32',true], ['actorid',$i,'10',true,false,'0'], ['reason',$t],
                    ['detailsjson',$t,null,true], ['timecreated',$i,'10',true,false,'0'],
                ],
                'indexes' => [['entity_time_idx',false,['entitytype','entityid','timecreated']]],
            ],
            'local_ustar_notifications' => [
                'fields' => [
                    ['id',$i,'10',true,true], ['userid',$i,'10',true], ['severity',$c,'16',true,false,'normal'],
                    ['eventtype',$c,'64',true], ['subject',$c,'255',true], ['message',$t,null,true],
                    ['actionurl',$t], ['dueat',$i,'10'], ['status',$c,'16',true,false,'unread'],
                    ['idempotencykey',$c,'128',true], ['ackat',$i,'10'],
                    ['timecreated',$i,'10',true,false,'0'], ['timemodified',$i,'10',true,false,'0'],
                ],
                'indexes' => [
                    ['idempotency_uix',true,['idempotencykey']], ['user_status_time_idx',false,['userid','status','timecreated']],
                ],
            ],
            'local_ustar_notify_delivery' => [
                'fields' => [
                    ['id',$i,'10',true,true], ['notificationid',$i,'10',true], ['channel',$c,'16',true],
                    ['status',$c,'16',true,false,'pending'], ['attempts',$i,'5',true,false,'0'],
                    ['nextattempt',$i,'10'], ['providerref',$c,'255'], ['lasterror',$t],
                    ['timecreated',$i,'10',true,false,'0'], ['timemodified',$i,'10',true,false,'0'],
                ],
                'indexes' => [
                    ['notification_channel_uix',true,['notificationid','channel']], ['status_next_idx',false,['status','nextattempt']],
                ],
            ],
        ];

        $tables = [];
        foreach ($specs as $name => $spec) {
            $table = new \xmldb_table($name);
            foreach ($spec['fields'] as $field) {
                [$fname,$type,$length] = $field;
                $notnull = !empty($field[3]) ? XMLDB_NOTNULL : null;
                $sequence = !empty($field[4]) ? XMLDB_SEQUENCE : null;
                $default = $field[5] ?? null;
                $table->add_field($fname, $type, $length, null, $notnull, $sequence, $default);
            }
            $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
            foreach ($spec['indexes'] as [$iname,$unique,$fields]) {
                $table->add_index($iname, $unique ? XMLDB_INDEX_UNIQUE : XMLDB_INDEX_NOTUNIQUE, $fields);
            }
            $tables[] = $table;
        }
        return $tables;
    }

    /** @return array<int,\xmldb_table> Private, versioned development-profile data. */
    public static function development_assessment_definitions(): array {
        $i = XMLDB_TYPE_INTEGER;
        $c = XMLDB_TYPE_CHAR;
        $t = XMLDB_TYPE_TEXT;
        $specs = [
            'local_ustar_dev_assess' => [
                'fields' => [
                    ['id', $i, '10', true, true], ['assessmentkey', $c, '64', true],
                    ['title', $c, '255', true], ['summary', $t], ['sensitivity', $c, '16', true, false, 'private'],
                    ['active', $i, '1', true, false, '1'], ['timecreated', $i, '10', true, false, '0'],
                    ['timemodified', $i, '10', true, false, '0'], ['usermodified', $i, '10', true, false, '0'],
                ],
                'indexes' => [['assessmentkey_uix', true, ['assessmentkey']], ['active_idx', false, ['active']]],
            ],
            'local_ustar_dev_assess_ver' => [
                'fields' => [
                    ['id', $i, '10', true, true], ['assessmentid', $i, '10', true], ['versionno', $i, '10', true, false, '1'],
                    ['intro', $t], ['questionsjson', $t, null, true], ['resultsjson', $t, null, true],
                    ['status', $c, '16', true, false, 'draft'], ['timecreated', $i, '10', true, false, '0'],
                    ['timemodified', $i, '10', true, false, '0'], ['usermodified', $i, '10', true, false, '0'],
                ],
                'indexes' => [
                    ['assessment_version_uix', true, ['assessmentid', 'versionno']],
                    ['assessment_status_idx', false, ['assessmentid', 'status']],
                ],
            ],
            'local_ustar_dev_assess_try' => [
                'fields' => [
                    ['id', $i, '10', true, true], ['assessmentid', $i, '10', true], ['versionid', $i, '10', true],
                    ['userid', $i, '10', true], ['idempotencykey', $c, '128', true], ['status', $c, '16', true, false, 'submitted'],
                    ['answersjson', $t, null, true], ['resultjson', $t, null, true], ['startedat', $i, '10', true, false, '0'],
                    ['submittedat', $i, '10', true, false, '0'], ['timecreated', $i, '10', true, false, '0'],
                    ['timemodified', $i, '10', true, false, '0'],
                ],
                'indexes' => [
                    ['idempotency_uix', true, ['userid', 'idempotencykey']],
                    ['user_assessment_time_idx', false, ['userid', 'assessmentid', 'submittedat']],
                    ['user_version_time_idx', false, ['userid', 'versionid', 'submittedat']],
                ],
            ],
        ];
        $tables = [];
        foreach ($specs as $name => $spec) {
            $table = new \xmldb_table($name);
            foreach ($spec['fields'] as $field) {
                [$fname, $type, $length] = $field;
                $table->add_field($fname, $type, $length, null, !empty($field[3]) ? XMLDB_NOTNULL : null,
                    !empty($field[4]) ? XMLDB_SEQUENCE : null, $field[5] ?? null);
            }
            $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
            foreach ($spec['indexes'] as [$iname, $unique, $fields]) {
                $table->add_index($iname, $unique ? XMLDB_INDEX_UNIQUE : XMLDB_INDEX_NOTUNIQUE, $fields);
            }
            $tables[] = $table;
        }
        return $tables;
    }
}
