#!/usr/bin/env python3
"""Export the immutable CON-02 source profile; plain-file packaging is separate."""
import contextlib
import ast
import base64
import io
import json
from pathlib import Path
import re
import subprocess
import tempfile
import xml.etree.ElementTree as ET
from connect_con02_contract import (APP_COMMIT, BASELINE_COMMIT, LEGACY_COMMIT, OVERLAY_SHA, MODULES, Refusal,
                                    EmbeddedBundle, require, sha)

ROOT = Path(__file__).resolve().parents[2]


def reference_from_git(repo, tool_commit):
    repo = Path(repo)
    require(re.fullmatch('[a-f0-9]{40}', tool_commit), 'exact_tool_commit_required')
    def git(*args):
        return subprocess.check_output(['git', '-C', str(repo), *args], stderr=subprocess.DEVNULL, timeout=30)
    require(git('rev-parse', '--verify', tool_commit + '^{commit}').decode().strip() == tool_commit,
            'exact_tool_commit_required')
    def export(commit, roots):
        result = {}
        for entry in git('ls-tree', '-r', '-z', commit, '--', *roots).split(b'\0'):
            if not entry: continue
            meta, path = entry.split(b'\t', 1)
            mode, kind, oid = meta.decode().split()
            require(kind == 'blob' and mode in ('100644', '100755'), 'regular_git_source_required')
            result[path.decode()] = git('cat-file', 'blob', oid)
        return result
    def release(commit):
        raw = {k.removeprefix('moodle/'): v for k, v in export(commit, ['moodle/local/ustar', 'moodle/theme/ustar']).items()}
        fields, indexes = {}, {}
        for table in ET.fromstring(raw['local/ustar/db/install.xml']).findall('.//TABLE'):
            name = table.attrib['NAME']
            fields[name] = {f.attrib['NAME']: {'type': f.attrib['TYPE'], 'length': f.attrib.get('LENGTH'),
                'notnull': f.attrib.get('NOTNULL') == 'true'} for f in table.findall('./FIELDS/FIELD')}
            indexes[name] = [{'fields': [f.strip() for f in k.attrib['FIELDS'].split(',')],
                'unique': k.attrib.get('TYPE') in ('primary', 'unique', 'foreign-unique') or k.attrib.get('UNIQUE') == 'true'}
                for k in [*table.findall('./KEYS/KEY'), *table.findall('./INDEXES/INDEX')]]
        versions = {}
        for key, scope in [('local_ustar', 'local/ustar'), ('theme_ustar', 'theme/ustar')]:
            found = re.findall(rb'(?m)^\s*\$plugin->version\s*=\s*([0-9]{10})\s*;', raw[scope + '/version.php'])
            require(len(found) == 1, 'release_version')
            versions[key] = int(found[0])
        return {'files': {k: sha(v) for k, v in sorted(raw.items())}, 'tables': sorted(fields),
                'versions': versions, 'schema_fields': fields, 'schema_indexes': indexes}, raw
    baseline, _ = release(BASELINE_COMMIT)
    overlay = git('show', LEGACY_COMMIT + ':moodle/local/ustar/classes/connect/notification_delivery.php')
    require(sha(overlay) == OVERLAY_SHA, 'accepted_policy_overlay')
    baseline['files']['local/ustar/classes/connect/notification_delivery.php'] = OVERLAY_SHA
    candidate, payload = release(APP_COMMIT)
    require((len(baseline['files']), len(candidate['files']), len(baseline['tables']), len(candidate['tables']))
            == (552, 557, 99, 100), 'frozen_release_counts')
    ref = {'schema': 1, 'tool_commit': tool_commit, 'app_commit': APP_COMMIT, 'worker_commit': APP_COMMIT,
           'baseline_commit': BASELINE_COMMIT, 'legacy_commit': LEGACY_COMMIT,
           'baseline': baseline, 'candidate': candidate}
    # Only frozen builder source executes, with ROOT explicitly bound to this Git store.
    source = git('show', APP_COMMIT + ':connect-worker/deploy/build_release.py')
    namespace = {'__name__': '_frozen_worker_builder', '__file__': str(repo / 'connect-worker/deploy/build_release.py')}
    exec(compile(source, namespace['__file__'], 'exec'), namespace)
    namespace['ROOT'] = repo
    with tempfile.TemporaryDirectory() as td, contextlib.redirect_stdout(io.StringIO()):
        worker = namespace['build'](APP_COMMIT, td).read_bytes()
    legacy = export(LEGACY_COMMIT, ['scripts/infra/connect_notification_pilot.py',
        'scripts/infra/connect_bridge_activation.py', 'scripts/infra/connect_private_bridge.py',
        'scripts/infra/academy_https.py', 'connect-worker/deploy/install.py'])
    return EmbeddedBundle(ref, {}, payload, worker, legacy)


def build(tool_commit, output, *, repo=ROOT):
    require(re.fullmatch('[a-f0-9]{40}', tool_commit), 'exact_tool_commit_required')
    repo = Path(repo)
    def git(*args): return subprocess.check_output(['git', '-C', str(repo), *args], stderr=subprocess.DEVNULL, timeout=30)
    # Refuse an executing builder/contract that differs from the requested tool.
    for name in ('build_connect_con02_update', 'connect_con02_contract'):
        path = 'scripts/infra/'+name+'.py'
        try: frozen = git('show', tool_commit+':'+path)
        except subprocess.CalledProcessError: raise Refusal('dirty_builder_dependency') from None
        require((repo/path).read_bytes() == frozen, 'dirty_builder_dependency')
    b = reference_from_git(repo, tool_commit)
    sources = {n+'.py': git('show', tool_commit+':'+('scripts/' if n=='production_manifest' else 'scripts/infra/')+n+'.py').decode('utf-8') for n in MODULES}
    raw_assets = {'reference.json': (json.dumps(b.reference, sort_keys=True)+'\n').encode(),
                  'worker.tar.gz': b.worker_archive,
                  **{'payload/'+n: v for n, v in b.payload.items()}, **{'legacy/'+n: v for n, v in b.legacy.items()}}
    assets = {n: base64.b64encode(v).decode() for n, v in sorted(raw_assets.items())}
    manifest = {'schema': 1, 'tool_commit': tool_commit, 'app_commit': APP_COMMIT, 'worker_commit': APP_COMMIT,
        'sources': {n: {'sha256': sha(v.encode()), 'bytes': len(v.encode())} for n, v in sorted(sources.items())},
        'assets': {n: {'sha256': sha(v), 'bytes': len(v)} for n, v in sorted(raw_assets.items())}}
    contract = sources['connect_con02_contract.py']
    decoder = next(ast.get_source_segment(contract, n) for n in ast.parse(contract).body if isinstance(n, ast.FunctionDef) and n.name=='decode_envelope')
    constants = ''.join(n+' = '+repr(json.dumps(v, sort_keys=True, ensure_ascii=True))+'\n' for n, v in
                        [('MANIFEST_JSON', manifest), ('SOURCES_JSON', sources), ('ASSETS_JSON', assets)])
    bootstrap = '''#!/usr/bin/env python3
"""Exact-Git CON-02 operator controller. Default: offline PLAN_ONLY."""
import json,sys
import importlib.abc,importlib.util
from pathlib import Path
class Refusal(ValueError): pass
def require(value,reason):
    if not value: raise Refusal(reason)
def unique(pairs):
    result={}
    for key,value in pairs:
        require(key not in result,'duplicate_embedded_json_key');result[key]=value
    return result
'''+decoder+'\n'+constants+'''
try:
    manifest=json.loads(MANIFEST_JSON,object_pairs_hook=unique)
    sources=json.loads(SOURCES_JSON,object_pairs_hook=unique)
    assets=json.loads(ASSETS_JSON,object_pairs_hook=unique)
    decoded,_=decode_envelope(manifest,sources,assets)
    require(set(decoded)==EXPECTED_MODULES,'embedded_module_inventory')
    parent=Path(__file__).absolute().parent
    sys.path[:]=[p for p in sys.path if p and Path(p).resolve() not in (parent,Path.cwd())]
    class Embedded(importlib.abc.MetaPathFinder,importlib.abc.Loader):
        def find_spec(self,name,path=None,target=None):
            return importlib.util.spec_from_loader(name,self) if name+'.py' in decoded else None
        def create_module(self,spec):return None
        def exec_module(self,module):
            module.__file__='<ustar-con02/'+module.__name__+'.py>'
            exec(compile(decoded[module.__name__+'.py'],module.__file__,'exec'),module.__dict__)
    require(not any(n[:-3] in sys.modules for n in decoded),'embedded_module_shadowed')
    sys.meta_path.insert(0,Embedded())
    from connect_con02_update import main,load_embedded
    bundle=load_embedded(manifest,sources,assets)
except BaseException:
    print('{"phase":"refused","reason":"embedded_bundle_invalid"}');sys.exit(2)
sys.exit(main(bundle=bundle))
'''
    bootstrap = bootstrap.replace('set(decoded)==EXPECTED_MODULES', 'set(decoded)==set('+repr(tuple(sorted(sources)))+')')
    raw = bootstrap.encode()
    require(len(raw) <= 128*1024**2, 'plain_artifact_size')
    output = Path(output); output.mkdir(parents=True, exist_ok=True)
    target = output / ('ustar-con02-update-'+tool_commit[:8]+'.py')
    target.write_bytes(raw); target.chmod(0o644)
    digest = sha(raw)
    target.with_suffix('.py.sha256').write_text(digest+'  '+target.name+'\n')
    target.with_suffix('.py.metadata.json').write_text(json.dumps({'schema': 1, 'tool_commit': tool_commit,
        'app_commit': APP_COMMIT, 'worker_commit': APP_COMMIT, 'sha256': digest, 'bytes': len(raw),
        'payload_files': len(b.payload), 'default_host_access': False}, sort_keys=True, indent=2)+'\n')
    print(json.dumps({'artifact': str(target), 'sha256': digest, 'tool_commit': tool_commit}, sort_keys=True))
    return target


if __name__ == '__main__':
    import argparse
    p=argparse.ArgumentParser(description=__doc__);p.add_argument('--commit',required=True);p.add_argument('--output-dir',required=True)
    args=p.parse_args();build(args.commit,Path(args.output_dir))
