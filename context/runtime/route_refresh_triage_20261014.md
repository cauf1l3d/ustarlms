# Passed assessment does not advance the employee route

**Deferred by owner14Oct:** continue Moodle major transition preparation instead.
The observations and commands below are retained for later diagnosis;they are
not the current operator step. No application fix or grade/progress mutation
has been made.

**Owner14Oct13:29MSK update:** [DB evidence](route_refresh_db_result_20261014.json)
confirms point53/version3 passed+progresscomplete+Moodlecompletion2 for all8
passing learners in the sample,including both23.5 scores. Old version2 lacks
runtime/progress;that is not a failure of version3. The prior name-filtered
query selected nothing;do not infer absent results from it. A current-page
render after this observation,current effective version and progress expiry
are not supplied. Earlier hypothesis of an unsaved pass is not supported by
this new sample. No score/progress mutation or source fix has been applied.
Fresh browser reload is the first observation;only if still pending inspect
the current version/expiry. Earlier command below is historical,do not repeat
the unsuccessful name filter or force a completion.

Owner14Oct12:33MSK screenshots were opened from the supplied scratch copies.
[Selected triage receipt](route_refresh_triage_20261014.json) separates observed
UI from the reviewed source and missing server evidence.

Material6 is complete;assessment7 reports1/3 attempts,23.5/25 with threshold17.5,
yet remains pending synchronization. The page shows6/16 completed and locks8;
the next-step button is hidden whilependingsync=true. Score existence does not
prove the route's durable progress projection was updated.

Current-main route.php and route_model.php match locally reviewed blobs.
GET explicitly usesreadonly=true;the POSTsync branch exists and uses the default
writer path withsesskey/view-as/learning eligibility checks. No claim that the
POST forgot to select writer mode is supported. Two possible failure paths were
found:learning gate silently redirects without syncing,or an assessment lifecycle
exception is caught and hidden by the route reader. Noncomplete existing progress
rows are another projection case to test if actual metadata identifies one.
None is yet proven to be this employee's server cause.

## One read-only operator diagnostic

No Moodle/PHP bootstrap or helper installation. Prefix is read from the accepted
upgrade marker and validated before interpolation. Fixed SELECT reads only
selected quiz/provider/runtime/progress/eligibility metadata withBEGIN READ ONLY,
12-second statement/2-second lock and20-second subprocess bounds.
Last12 finished nonpreview trading-hall attempts return at most24 policy rows.
Only a short numeric-user fingerprint is emitted;no names,logins,email,answers,
config text,credentials,private logs or arbitrary query arguments are output.
The fingerprint is a pseudonymous label,not a claim of cryptographic anonymity.
PSQL errors emit only return code andSQLSTATE. Five fixed source hashes compare
to the accepted application baseline;no code is executed from the checked files.

```bash
sudo -n python3 -I -B - <<'PY'
import hashlib, json, re, subprocess
from pathlib import Path

prefix = json.loads(Path("/var/lib/ustar-production-patch-20261012/switch-active.json").read_text())["prefix"]
assert re.fullmatch(r"[A-Za-z][A-Za-z0-9_]{0,31}", prefix)
root = Path("/opt/ustar/data/moodle/public/public/local/ustar")
if not root.is_dir():
    root = Path("/opt/ustar/data/moodle/public/local/ustar")
expected = {"route.php":"fdd869670e1eaaa2f208d9bbcadf190dd2e12d507b83769b83a9fe732b5fbdd0","classes/route_model.php":"75833f955a0e609f18172b50ce23aec260e89a8247f9dfb7fb1c5f5c891e6bee","classes/assessment_lifecycle.php":"27e618e18be58e5db0cd5a1a3477b5b4a12209143241cc144aaabaa4a15c544d","classes/moodle_quiz_assessment_provider.php":"5716ec20c26a69584c8dd6379a74619e0a4eedd4a17e6fd9392cfe7adf86807c","templates/route.mustache":"a97c3ce085c404a07a5fac1ef7d0db8ec31adcabd44397a29e1354dd56f11368"}
print("SOURCE_MATCH=" + json.dumps({name: (root / name).is_file() and not (root / name).is_symlink() and hashlib.sha256((root / name).read_bytes()).hexdigest() == value for name, value in expected.items()}))
query = """
BEGIN READ ONLY;
SET LOCAL statement_timeout='12s';
SET LOCAL lock_timeout='2s';
WITH recent AS (
 SELECT a.id,a.userid,a.quiz,a.sumgrades,a.timestart,a.timefinish,q.grade,q.sumgrades AS maximum
 FROM @@quiz_attempts a JOIN @@quiz q ON q.id=a.quiz
 WHERE a.preview=0 AND a.state='finished' AND q.name ILIKE '%Торгового зала%'
 ORDER BY a.timefinish DESC,a.id DESC LIMIT 12
)
SELECT json_build_object(
 'user_key',substr(md5(a.userid::text),1,8),
 'score',round((a.sumgrades*a.grade/NULLIF(a.maximum,0))::numeric,2),
 'passscore',gi.gradepass,'point',p.pointid,'version',p.versionid,
 'current_version',(SELECT v2.id FROM @@local_ustar_route_versions v2 WHERE v2.pointid=p.pointid AND v2.status='published' AND COALESCE(v2.effectivedate,0)<=extract(epoch FROM now()) ORDER BY v2.versionno DESC,v2.id DESC LIMIT 1),
 'after_version_start',a.timestart>=COALESCE(v.effectivedate,0),
 'runtime_status',r.status,'progress_status',pr.status,
 'progress_expired',COALESCE(pr.expiresat>0 AND pr.expiresat<extract(epoch FROM now()),false),
 'employment',COALESCE(e.status,'active'),
 'account_type',(SELECT d.data FROM @@user_info_data d JOIN @@user_info_field f ON f.id=d.fieldid WHERE d.userid=a.userid AND f.shortname='ustar_account_type'),
 'suspended',u.suspended,'deleted',u.deleted,
 'siteadmin',COALESCE((SELECT strpos(','||c.value||',',','||a.userid::text||',')>0 FROM @@config c WHERE c.name='siteadmins'),false))
FROM recent a
JOIN @@user u ON u.id=a.userid
JOIN @@course_modules cm ON cm.instance=a.quiz AND cm.deletioninprogress=0
JOIN @@modules m ON m.id=cm.module AND m.name='quiz'
LEFT JOIN @@grade_items gi ON gi.itemtype='mod' AND gi.itemmodule='quiz' AND gi.iteminstance=a.quiz AND gi.itemnumber=0
LEFT JOIN @@local_ustar_assess_policy p ON p.providerkind='moodle_quiz' AND p.providerref='cm:'||cm.id::text AND p.active=1
LEFT JOIN @@local_ustar_route_versions v ON v.id=p.versionid
LEFT JOIN @@local_ustar_assess_runtime r ON r.userid=a.userid AND r.pointid=p.pointid AND r.versionid=p.versionid
LEFT JOIN @@local_ustar_route_progress pr ON pr.userid=a.userid AND pr.pointid=p.pointid AND pr.versionid=p.versionid
LEFT JOIN @@local_ustar_employment e ON e.userid=a.userid
ORDER BY a.timefinish DESC,a.id DESC,p.id DESC LIMIT 24;
COMMIT;
""".replace("@@",prefix)
result = subprocess.run(["docker","exec","-i","ustar_postgres","sh","-c",'exec psql -X -q -A -t -v ON_ERROR_STOP=1 -v VERBOSITY=sqlstate -U "$POSTGRES_USER" -d "$POSTGRES_DB"'],input=query,text=True,capture_output=True,timeout=20)
if result.returncode:
    print("SQL_DIAGNOSTIC_EXIT=" + str(result.returncode))
    print("SQLSTATE=" + ",".join(re.findall(r"\b[0-9A-Z]{5}\b",result.stderr)))
else:
    print(result.stdout.strip() or "NO_MATCHING_ATTEMPTS")
PY
```

The marker is the accepted production518 switch marker,not a state mutation.
Current-source hashes,DB data and actualPOST request/exception remain unobserved
until an owner diagnostic receipt is received. Do not force completion,change
grades/reset attempts/roles or repeat a successful quiz to make the button appear.
Do not issue a speculative source fix or claim a release/CI PASS for this symptom.
Prepare the focused verified fix after locating the actual failure path.
Prior host147/native runtime acceptance remains closed;the prepared5.3 asset
download was not issued/activated when this bug became the user's priority.
