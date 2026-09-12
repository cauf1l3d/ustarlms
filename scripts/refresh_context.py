#!/usr/bin/env python3
"""Build a new, uncommitted audit snapshot; preserve existing context changes."""
import argparse
from datetime import datetime, timezone
import hashlib
import json
from pathlib import Path
import subprocess
from collect_runtime import collect
from production_manifest import audit, BASELINE, git


def write_json(path, value):
    path.parent.mkdir(parents=True, exist_ok=True)
    with path.open('x') as f:
        json.dump(value, f, ensure_ascii=False, indent=2)
        f.write('\n')


def main():
    p = argparse.ArgumentParser(description=__doc__)
    p.add_argument('--repo', type=Path, default=Path(__file__).resolve().parents[1])
    p.add_argument('--output-dir', type=Path, required=True)
    p.add_argument('--commit', default=BASELINE)
    a = p.parse_args()
    repo, out = a.repo.resolve(), a.output_dir.resolve()
    if out.is_relative_to(repo):
        raise ValueError('output_must_be_outside_repository')
    out.mkdir(parents=True, exist_ok=False)
    # Preserve both staged and unstaged changes, without applying or committing.
    for label, args in [('working', ('diff','--binary')), ('staged', ('diff','--cached','--binary'))]:
        (out/(label+'.patch')).write_bytes(git(repo,*args))
    (out/'git_status.txt').write_bytes(git(repo,'status','--short'))
    (out/'untracked_paths.txt').write_bytes(git(repo,'ls-files','--others','--exclude-standard'))
    runtime = collect(repo,out)
    context = out/'context'
    write_json(context/'runtime/git_state.yaml', {'kind':'git_source','measured_at':runtime['measured_at'], **runtime['git_source']})
    write_json(context/'runtime/docker.yaml', {'kind':'production_runtime','measured_at':runtime['measured_at'], 'containers':runtime['production_runtime']})
    write_json(context/'runtime/moodle.yaml', {'kind':'production_runtime','measured_at':runtime['measured_at'], 'versions':runtime.get('moodle_versions'), 'errors':runtime['errors']})
    write_json(context/'runtime/server.yaml', {'kind':'production_runtime','measured_at':runtime['measured_at'], **runtime['server'], 'contextd_units':runtime.get('contextd_units'), 'contextd_pids':runtime.get('contextd_pids')})
    comparison = None
    if runtime.get('moodle_versions', {}).get('host_root'):
        comparison = audit(repo, Path(runtime['moodle_versions']['host_root']), a.commit, out)
    # Build code map from the exact reference, not the mounted production tree.
    sha = git(repo,'rev-parse','--verify',a.commit+'^{commit}').decode().strip()
    names = git(repo,'ls-tree','-r','--name-only',sha,'--','moodle/local/ustar','moodle/theme/ustar').decode().splitlines()
    write_json(context/'code_map/source_files.json', {'kind':'git_source','commit':sha,'files':names})
    report = {'measured_at':runtime['measured_at'], 'kind':'audit_snapshot', 'baseline_commit':sha,
              'production_comparison_available': comparison is not None,
              'complete':not runtime['errors'] and comparison is not None and comparison['complete'],
              'source_files_copied':False,'daemon_started':False,'context_promoted':False,
              'existing_context_diff_preserved':True}
    write_json(context/'runtime/refresh_report.json',report)
    subprocess.run(['python3',str(repo/'scripts/build_context_index.py'),'--root',str(out)],check=True)
    lines=[]
    for path in sorted(out.rglob('*')):
        if path.is_file():
            lines.append(hashlib.sha256(path.read_bytes()).hexdigest()+'  '+path.relative_to(out).as_posix())
    (out/'SHA256SUMS').write_text('\n'.join(lines)+'\n')
    print('AUDIT_SAVED='+str(out))
    print('COMPLETE='+str(report['complete']))
    return 0 if report['complete'] else 2


if __name__ == '__main__':
    raise SystemExit(main())
