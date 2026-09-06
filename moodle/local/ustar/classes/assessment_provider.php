<?php
namespace local_ustar;

defined('MOODLE_INTERNAL') || die();

/**
 * Provider boundary for route assessments.
 *
 * The lifecycle owns cycles/remediation/escalation. A provider owns only the
 * mechanics of one assessment engine (Moodle Quiz today, native USTAR later).
 */
interface assessment_provider {
    /**
     * @return array{
     *   provider:string,totalattempts:int,finalizedattempts:int,pendingattempts:int,
     *   passed:bool,bestscore:float,maxscore:float,passscore:float,
     *   lastattemptid:int,lastattemptno:int,lastattemptat:int,
     *   finalized:array<int,array{attemptid:int,attemptno:int,score:float,timefinish:int,finalizedat:int}>,
     *   launchurl:string
     * }
     */
    public function inspect(int $userid, \stdClass $policy): array;

    /** Ensure this employee can use attempts 1..$limit without changing everyone else. */
    public function unlock_attempt_limit(int $userid, \stdClass $policy, int $limit): void;

    /** Employee launch target for the assessment itself. */
    public function launch_url(\stdClass $policy): string;
}
