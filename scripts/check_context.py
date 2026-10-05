#!/usr/bin/env python3
"""Check current USTAR handoff pointers, source inventory, schema paths and links."""
import hashlib
import json
from pathlib import Path
import re
import subprocess
import xml.etree.ElementTree as ET
import yaml

ROOT=Path(__file__).resolve().parents[1]


def check():
    def read(path): return yaml.safe_load((ROOT/path).read_text())
    state=read('context/roadmap/STATE.yaml'); backlog=read('context/roadmap/BACKLOG.yaml')
    baseline=state['code_baseline']['commit']
    assert re.fullmatch('[0-9a-f]{40}',baseline), 'Exact application SHA required'
    assert state['code_baseline']['branch']=='main'
    assert backlog['source_baseline']==baseline
    tasks={t['id']:t for t in backlog['tasks']}
    assert len(tasks)==len(backlog['tasks']), 'Duplicate task IDs'
    assert state['next_task'] in tasks
    for t in tasks.values():
        assert set(t['depends_on']) <= tasks.keys(), t['id']
    visiting=set(); visited=set()
    def visit(key):
        assert key not in visiting, 'Task dependency cycle: '+key
        if key in visited:return
        visiting.add(key)
        for dep in tasks[key]['depends_on']: visit(dep)
        visiting.remove(key);visited.add(key)
    for key in tasks:visit(key)
    for key in state['ready_tasks']:
        assert tasks[key]['status']=='ready', key
        assert all(tasks[d]['status']=='done' for d in tasks[key]['depends_on']), key
    project=read('context/project.yaml')
    for path in state['next_agent_read']:
        assert (ROOT/path).is_file(),path
    for t in tasks.values():
        for key in ('spec','reference','runbook'):
            if key in t: assert (ROOT/t[key]).is_file(),(t['id'],key)
    # Cross-check current pointers against the dated evidence, not a live server.
    evidence_path=project['runtime_evidence']
    assert state['production']['source']==evidence_path
    evidence=read(evidence_path)
    audit_path=state['audit']['document']
    assert evidence['sources']['audit']==audit_path
    assert hashlib.sha256((ROOT/audit_path).read_bytes()).hexdigest()==evidence['sources']['audit_sha256']
    # A new candidate may precede deployment; never force production evidence
    # to claim the application source baseline being reviewed by this PR.
    assert re.fullmatch('[0-9a-f]{40}',evidence['application']['reference_commit'])
    assert state['production']['last_exact_source_manifest']['commit']==evidence['application']['reference_commit']
    assert state['production']['current_source_commit']==evidence['application']['reference_commit']
    assert state['production']['last_exact_source_manifest']['date']==evidence['application']['manifest_observed_at']
    assert state['production']['last_exact_source_manifest']['matching_files']==evidence['application']['matching_files']
    assert state['production']['current_versions']=={
        'local_ustar':evidence['application']['local_ustar'],
        'theme_ustar':evidence['application']['theme_ustar']}
    assert read('context/runtime/moodle.yaml')['production_versions']['local_ustar']==evidence['application']['local_ustar']
    for key in ('document','reconciliation','remediation_plan'):
        assert (ROOT/state['audit'][key]).is_file(),key
    assert project['next_stage']==backlog['remediation_plan']==state['audit']['remediation_plan']
    manifest=json.loads((ROOT/'context/code_map/generated/source_manifest.json').read_text())
    assert manifest['source_commit']==baseline
    for f in manifest['files']:
        path=ROOT/f['path']
        assert path.is_file() and hashlib.sha256(path.read_bytes()).hexdigest()==f['sha256'], f['path']
    paths={p for p in subprocess.check_output(['git','ls-files','--','moodle/local/ustar','moodle/theme/ustar'],cwd=ROOT,text=True).splitlines()}
    assert paths=={f['path'] for f in manifest['files']}, 'Source inventory missing/extra files'
    for component,key in [('local/ustar','local_ustar_version'),('theme/ustar','theme_ustar_version')]:
        v=int(re.search(r'\$plugin->version\s*=\s*(\d+)',(ROOT/'moodle'/component/'version.php').read_text())[1])
        assert state['code_baseline'][key]==v
    tables={t.attrib['NAME'] for t in ET.parse(ROOT/'moodle/local/ustar/db/install.xml').findall('.//TABLE')}
    assert len(tables)==manifest['counts']['tables']==read('context/code_map/database.yaml')['table_count']
    for p in (ROOT/'context/domains').glob('*.yaml'):
        data=yaml.safe_load(p.read_text())
        assert data['source_commit']==baseline,p
        assert set(data['tables']) <= tables,p
        for path in data['entry_points']+data['services']:assert (ROOT/path).is_file(),path
    for p in (ROOT/'context').rglob('*'):
        if not p.is_file() or 'archive' in p.relative_to(ROOT/'context').parts:continue
        if p.suffix in ['.yaml','.yml']:yaml.safe_load(p.read_text())
        if p.suffix=='.json':json.loads(p.read_text())
    # Check maintained entrypoints; historical reports preserve original links.
    docs=['README.md','START_HERE.md','AGENTS.md','context/CONTEXT_INDEX.md',
          'context/architecture/overview.md','context/architecture/boundaries.md',
          'context/agents/astra.md','context/code_map/README.md','context/decisions/README.md',
          'context/tasks/ACTIVE.md','context/roadmap/README.md','context/roadmap/MOBILE_CLIENT.md',
          'context/runtime/README.md','context/runtime/RELEASE_LEDGER.md','context/runtime/OPERATIONS.md',
          'context/runtime/context_refresh_report.md','context/archive/handoff_20261002/README.md']
    docs += ['harness/ustar-contextd/README.md','context/runtime/context_update_20261005.md',
             'context/runtime/MONITORING.md','context/runtime/BACKUP_RESTORE.md',
             'context/runtime/SECURITY_BASELINE.md','context/audits/README.md',
             state['audit']['reconciliation'],state['audit']['remediation_plan']]
    for name in docs:
        p=ROOT/name
        for target in re.findall(r'\]\(([^)]+)\)',p.read_text()):
            if '://' in target or target.startswith('#'):continue
            target=target.split('#',1)[0]
            assert (p.parent/target).exists(),(name,target)
    idx=json.loads((ROOT/'context/index/context_index.json').read_text())
    assert idx['root']=='context' and idx['schema_version']==2
    for f in idx['files']:
        p=ROOT/f['path']
        assert p.is_file() and hashlib.sha256(p.read_bytes()).hexdigest()==f['sha256'],f['path']
    indexed={f['path'] for f in idx['files']}
    assert set(state['audit'][key] for key in ('document','reconciliation','remediation_plan')) <= indexed
    assert evidence_path in indexed
    print(f'CONTEXT_OK source={baseline} files={len(paths)} tables={len(tables)} tasks={len(tasks)}')


if __name__=='__main__':check()
