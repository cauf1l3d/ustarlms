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
            'local_ustar_completion_cycle' => [
                'fields' => [
                    ['id',$i,'10',true,true], ['userid',$i,'10',true],
                    ['pointid',$i,'10',true], ['versionid',$i,'10',true],
                    ['logicalpointid',$i,'10',true], ['cyclekey',$c,'128',true],
                    ['status',$c,'16',true,false,'confirmed'], ['completedat',$i,'10',true,false,'0'],
                    ['expiresat',$i,'10'], ['evidencejson',$t,null,true],
                    ['timecreated',$i,'10',true,false,'0'],
                ],
                'indexes' => [
                    ['cyclekey_uix',true,['cyclekey']],
                    ['user_point_time_idx',false,['userid','logicalpointid','completedat']],
                    ['user_status_idx',false,['userid','status','completedat']],
                ],
            ],
            'local_ustar_standards' => [
                'fields' => [
                    ['id',$i,'10',true,true], ['code',$c,'64',true], ['title',$c,'255',true],
                    ['description',$t], ['status',$c,'16',true,false,'draft'], ['activeversionid',$i,'10'],
                    ['ownerid',$i,'10',true,false,'0'], ['timecreated',$i,'10',true,false,'0'],
                    ['timemodified',$i,'10',true,false,'0'],
                ],
                'indexes' => [
                    ['code_uix',true,['code']], ['status_idx',false,['status']],
                    ['activeversion_idx',false,['activeversionid']],
                ],
            ],
            'local_ustar_standard_ver' => [
                'fields' => [
                    ['id',$i,'10',true,true], ['standardid',$i,'10',true],
                    ['versionno',$i,'10',true,false,'1'], ['requirementsjson',$t,null,true],
                    ['renewalpolicy',$c,'16',true,false,'keep'], ['validdays',$i,'10',true,false,'0'],
                    ['status',$c,'16',true,false,'draft'], ['effectivedate',$i,'10'],
                    ['createdby',$i,'10',true,false,'0'], ['timecreated',$i,'10',true,false,'0'],
                    ['timemodified',$i,'10',true,false,'0'],
                ],
                'indexes' => [
                    ['standard_version_uix',true,['standardid','versionno']],
                    ['standard_status_idx',false,['standardid','status','effectivedate']],
                ],
            ],
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
            'local_ustar_adaptations' => [
                'fields' => [
                    ['id',$i,'10',true,true], ['staffingrequestid',$i,'10',true],
                    ['userid',$i,'10',true], ['managerid',$i,'10',true], ['assignmentid',$i,'10',true],
                    ['positionid',$c,'64',true], ['checklistkey',$c,'64',true,false,'adaptation_standard'],
                    ['definitionversion',$i,'10',true,false,'1'], ['startdate',$c,'10',true],
                    ['plannedworkdays',$i,'3',true,false,'10'], ['status',$c,'16',true,false,'active'],
                    ['rulesjson',$t], ['createdby',$i,'10',true], ['timecreated',$i,'10',true,false,'0'],
                    ['timemodified',$i,'10',true,false,'0'], ['completedat',$i,'10'],
                ],
                'indexes' => [
                    ['staffing_request_uix',true,['staffingrequestid']],
                    ['user_status_idx',false,['userid','status']],
                    ['manager_status_idx',false,['managerid','status']],
                    ['assignment_idx',false,['assignmentid']],
                ],
            ],
            'local_ustar_check_submits' => [
                'fields' => [
                    ['id',$i,'10',true,true], ['checklistkey',$c,'64',true],
                    ['definitionversion',$i,'10',true,false,'1'], ['userid',$i,'10',true], ['assignmentid',$i,'10'], ['adaptationid',$i,'10'],
                    ['perspective',$c,'16',true], ['workdate',$c,'10',true], ['status',$c,'16',true],
                    ['answersjson',$t,null,true], ['issuesjson',$t,null,true], ['correctionofid',$i,'10'],
                    ['submittedby',$i,'10',true], ['timecreated',$i,'10',true,false,'0'],
                ],
                'indexes' => [
                    ['user_check_date_idx',false,['userid','checklistkey','workdate']],
                    ['assignment_date_idx',false,['assignmentid','workdate']], ['adaptation_date_idx',false,['adaptationid','workdate']],
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

    /** @return array<int,\xmldb_table> Economy and competition TARGET tables. */
    public static function competition_economy_definitions(): array {
        $i = XMLDB_TYPE_INTEGER;
        $c = XMLDB_TYPE_CHAR;
        $t = XMLDB_TYPE_TEXT;
        $specs = [
            'local_ustar_coin_balance' => [
                'fields' => [
                    ['id',$i,'10',true,true], ['userid',$i,'10',true], ['balance',$i,'10',true,false,'0'],
                    ['timemodified',$i,'10',true,false,'0'],
                ],
                'indexes' => [['userid_uix',true,['userid']]],
            ],
            'local_ustar_competitions' => [
                'fields' => [
                    ['id',$i,'10',true,true], ['code',$c,'64',true], ['title',$c,'255',true],
                    ['status',$c,'16',true,false,'draft'], ['audiencekind',$c,'16',true,false,'department'],
                    ['audiencevalue',$c,'64',true], ['privacy',$c,'16',true,false,'pseudonymous'],
                    ['tiepolicy',$c,'16',true,false,'shared_place'], ['startat',$i,'10',true,false,'0'],
                    ['endat',$i,'10',true,false,'0'], ['activeversionid',$i,'10'], ['ownerid',$i,'10',true,false,'0'],
                    ['timecreated',$i,'10',true,false,'0'], ['timemodified',$i,'10',true,false,'0'],
                ],
                'indexes' => [
                    ['code_uix',true,['code']], ['status_window_idx',false,['status','startat','endat']],
                    ['audience_status_idx',false,['audiencekind','audiencevalue','status']],
                ],
            ],
            'local_ustar_comp_rules' => [
                'fields' => [
                    ['id',$i,'10',true,true], ['competitionid',$i,'10',true], ['versionno',$i,'10',true,false,'1'],
                    ['rulesjson',$t,null,true], ['status',$c,'16',true,false,'draft'], ['createdby',$i,'10',true,false,'0'],
                    ['timecreated',$i,'10',true,false,'0'], ['timemodified',$i,'10',true,false,'0'],
                ],
                'indexes' => [
                    ['competition_version_uix',true,['competitionid','versionno']],
                    ['competition_status_idx',false,['competitionid','status']],
                ],
            ],
            'local_ustar_comp_participants' => [
                'fields' => [
                    ['id',$i,'10',true,true], ['competitionid',$i,'10',true], ['userid',$i,'10',true],
                    ['publiclabel',$c,'64',true], ['audiencekey',$c,'64',true], ['status',$c,'16',true,false,'active'],
                    ['joinedat',$i,'10',true,false,'0'], ['leftat',$i,'10'], ['timecreated',$i,'10',true,false,'0'],
                ],
                'indexes' => [
                    ['competition_user_uix',true,['competitionid','userid']],
                    ['competition_status_idx',false,['competitionid','status']], ['user_status_idx',false,['userid','status']],
                ],
            ],
            'local_ustar_comp_score_events' => [
                'fields' => [
                    ['id',$i,'10',true,true], ['competitionid',$i,'10',true], ['participantid',$i,'10',true],
                    ['ruleversionid',$i,'10',true], ['eventtype',$c,'32',true], ['points',$i,'10',true],
                    ['sourcekind',$c,'32',true], ['sourceid',$c,'128',true], ['idempotencykey',$c,'128',true],
                    ['occurredat',$i,'10',true,false,'0'], ['timecreated',$i,'10',true,false,'0'],
                ],
                'indexes' => [
                    ['idempotency_uix',true,['idempotencykey']],
                    ['competition_participant_idx',false,['competitionid','participantid','occurredat']],
                    ['source_idx',false,['sourcekind','sourceid']],
                ],
            ],
            'local_ustar_comp_results' => [
                'fields' => [
                    ['id',$i,'10',true,true], ['competitionid',$i,'10',true], ['participantid',$i,'10',true],
                    ['ruleversionid',$i,'10',true], ['rankno',$i,'10',true], ['points',$i,'10',true],
                    ['tiekey',$c,'32',true], ['status',$c,'16',true,false,'final'], ['finalizedat',$i,'10',true,false,'0'],
                ],
                'indexes' => [
                    ['competition_participant_uix',true,['competitionid','participantid']],
                    ['competition_rank_idx',false,['competitionid','rankno']],
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

    /** @return array<int,\xmldb_table> Stage 6 product-service tables. */
    public static function stage6_definitions(): array {
        $i = XMLDB_TYPE_INTEGER;
        $c = XMLDB_TYPE_CHAR;
        $t = XMLDB_TYPE_TEXT;
        $specs = [
            'local_ustar_content_blueprints' => [
                'fields' => [
                    ['id',$i,'10',true,true], ['contentid',$i,'10',true],
                    ['kind',$c,'16',true], ['sourcejson',$t,null,true], ['sourceversion',$i,'10',true,false,'0'],
                    ['sourcehash',$c,'64',true], ['packagestatus',$c,'16',true,false,'none'],
                    ['packagefilename',$c,'255'], ['authorid',$i,'10',true,false,'0'],
                    ['timecreated',$i,'10',true,false,'0'], ['timemodified',$i,'10',true,false,'0'],
                ],
                'indexes' => [['contentid_uix',true,['contentid']], ['kind_status_idx',false,['kind','packagestatus']]],
            ],
            'local_ustar_grade_rules' => [
                'fields' => [
                    ['id',$i,'10',true,true], ['positionid',$c,'64',true], ['fromgrade',$c,'32',true],
                    ['tograde',$c,'32',true], ['versionno',$i,'10',true,false,'1'], ['routeid',$i,'10',true],
                    ['requirementsjson',$t,null,true], ['rulehash',$c,'64',true],
                    ['status',$c,'16',true,false,'published'], ['createdby',$i,'10',true,false,'0'],
                    ['timecreated',$i,'10',true,false,'0'],
                ],
                'indexes' => [
                    ['transition_version_uix',true,['positionid','fromgrade','tograde','versionno']],
                    ['transition_status_idx',false,['positionid','fromgrade','tograde','status']],
                ],
            ],
            'local_ustar_grade_rules' => [
                'fields' => [
                    ['id',$i,'10',true,true], ['positionid',$c,'64',true], ['fromgrade',$c,'32',true],
                    ['tograde',$c,'32',true], ['versionno',$i,'10',true,false,'1'], ['routeid',$i,'10',true],
                    ['requirementsjson',$t,null,true], ['rulehash',$c,'64',true],
                    ['status',$c,'16',true,false,'published'], ['createdby',$i,'10',true,false,'0'],
                    ['timecreated',$i,'10',true,false,'0'],
                ],
                'indexes' => [
                    ['transition_version_uix',true,['positionid','fromgrade','tograde','versionno']],
                    ['transition_status_idx',false,['positionid','fromgrade','tograde','status']],
                ],
            ],
            'local_ustar_employee_grades' => [
                'fields' => [
                    ['id',$i,'10',true,true], ['userid',$i,'10',true], ['gradekey',$c,'32',true],
                    ['positionid',$c,'64',true], ['source',$c,'32',true,false,'initial'],
                    ['requestid',$i,'10'], ['timecreated',$i,'10',true,false,'0'],
                    ['timemodified',$i,'10',true,false,'0'], ['usermodified',$i,'10',true,false,'0'],
                ],
                'indexes' => [['userid_uix',true,['userid']], ['grade_position_idx',false,['gradekey','positionid']]],
            ],
            'local_ustar_grade_requests' => [
                'fields' => [
                    ['id',$i,'10',true,true], ['userid',$i,'10',true], ['fromgrade',$c,'32',true],
                    ['tograde',$c,'32',true], ['routeid',$i,'10',true], ['requirementsjson',$t,null,true],
                    ['managerid',$i,'10',true], ['status',$c,'16',true,false,'pending'],
                    ['requestkey',$c,'128',true], ['requestedat',$i,'10',true,false,'0'],
                    ['decidedat',$i,'10'], ['decisionby',$i,'10'], ['decisionreason',$t],
                    ['timecreated',$i,'10',true,false,'0'], ['timemodified',$i,'10',true,false,'0'],
                ],
                'indexes' => [
                    ['requestkey_uix',true,['requestkey']], ['user_status_idx',false,['userid','status','requestedat']],
                    ['manager_status_idx',false,['managerid','status','requestedat']],
                ],
            ],
            'local_ustar_learning_tasks' => [
                'fields' => [
                    ['id',$i,'10',true,true], ['ownerid',$i,'10',true], ['assigneeid',$i,'10',true],
                    ['assignerid',$i,'10'], ['tasktype',$c,'16',true,false,'assigned'], ['title',$c,'255',true],
                    ['description',$t], ['status',$c,'16',true,false,'assigned'], ['requirereview',$i,'1',true,false,'0'],
                    ['relatedtype',$c,'32'], ['relatedid',$i,'10'], ['privacy',$c,'16',true,false,'assigned'],
                    ['version',$i,'10',true,false,'1'], ['dueat',$i,'10'], ['completedat',$i,'10'], ['cancelledat',$i,'10'],
                    ['timecreated',$i,'10',true,false,'0'], ['timemodified',$i,'10',true,false,'0'],
                ],
                'indexes' => [
                    ['owner_privacy_idx',false,['ownerid','privacy','timemodified']],
                    ['assignee_status_idx',false,['assigneeid','status','timemodified']],
                    ['assigner_status_idx',false,['assignerid','status','timemodified']],
                ],
            ],
            'local_ustar_learning_task_events' => [
                'fields' => [
                    ['id',$i,'10',true,true], ['taskid',$i,'10',true], ['eventtype',$c,'32',true],
                    ['actorid',$i,'10',true,false,'0'], ['datajson',$t,null,true],
                    ['timecreated',$i,'10',true,false,'0'],
                ],
                'indexes' => [['task_time_idx',false,['taskid','timecreated']]],
            ],
            'local_ustar_catalog_versions' => [
                'fields' => [
                    ['id',$i,'10',true,true], ['catalogid',$i,'10',true], ['versionno',$i,'10',true],
                    ['snapshotjson',$t,null,true], ['actorid',$i,'10',true,false,'0'],
                    ['timecreated',$i,'10',true,false,'0'],
                ],
                'indexes' => [['catalog_version_uix',true,['catalogid','versionno']], ['catalog_time_idx',false,['catalogid','timecreated']]],
            ],
            'local_ustar_board_archive' => [
                'fields' => [
                    ['id',$i,'10',true,true], ['boardid',$i,'10',true], ['ownerid',$i,'10',true],
                    ['title',$c,'255',true], ['documentjson',$t,null,true], ['version',$i,'10',true,false,'1'],
                    ['sharedteam',$i,'1',true,false,'0'], ['archivedat',$i,'10',true,false,'0'],
                    ['archivedby',$i,'10',true,false,'0'], ['checksum',$c,'64',true],
                ],
                'indexes' => [['boardid_uix',true,['boardid']], ['owner_time_idx',false,['ownerid','archivedat']]],
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
