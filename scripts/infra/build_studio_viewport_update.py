#!/usr/bin/env python3
"""Build a separate, fixed2800→2801 production handoff from exact Git objects."""
import ast
import base64
import gzip
import io
import json
from pathlib import Path
import re
import subprocess
import tarfile
import xml.etree.ElementTree as ET
import connect_con02_contract as c
import studio_viewport_update as m
from build_studio_colors_update import reference_from_git as colors_reference

ROOT = Path(__file__).resolve().parents[2]


def reference_from_git(repo, tool_commit):
    repo = Path(repo)
    def git(*args): return subprocess.check_output(['git', '-C', str(repo), *args], stderr=subprocess.DEVNULL, timeout=30)
    c.require(re.fullmatch('[a-f0-9]{40}', tool_commit), 'exact_tool_commit_required')
    c.require(git('rev-parse', tool_commit + '^{commit}').decode().strip() == tool_commit, 'exact_tool_commit_required')
    previous = colors_reference(repo, m.PREDECESSOR_TOOL)
    payload = {}
    for row in git('ls-tree', '-r', '-z', m.APP_COMMIT, '--', 'moodle/local/ustar', 'moodle/theme/ustar').split(b'\0'):
        if not row: continue
        meta, path = row.split(b'\t', 1); mode, kind, oid = meta.decode().split()
        c.require(kind == 'blob' and mode in ('100644', '100755'), 'regular_git_source_required')
        payload[path.decode().removeprefix('moodle/')] = git('cat-file', 'blob', oid)
    fields, indexes = {}, {}
    for table in ET.fromstring(payload['local/ustar/db/install.xml']).findall('.//TABLE'):
        name = table.attrib['NAME']
        fields[name] = {f.attrib['NAME']: {'type': f.attrib['TYPE'], 'length': f.attrib.get('LENGTH'),
            'notnull': f.attrib.get('NOTNULL') == 'true', 'default': f.attrib.get('DEFAULT'),
            'sequence': f.attrib.get('SEQUENCE') == 'true'} for f in table.findall('./FIELDS/FIELD')}
        indexes[name] = [{'fields': [f.strip() for f in k.attrib['FIELDS'].split(',')],
            'unique': k.attrib.get('TYPE') in ('primary', 'unique', 'foreign-unique') or k.attrib.get('UNIQUE') == 'true'}
            for k in [*table.findall('./KEYS/KEY'), *table.findall('./INDEXES/INDEX')]]
    versions = {key: int(re.search(rb'\$plugin->version\s*=\s*(\d+)', payload[scope + '/version.php'])[1])
        for key, scope in [('local_ustar', 'local/ustar'), ('theme_ustar', 'theme/ustar')]}
    target = {'files': {k: c.sha(v) for k, v in sorted(payload.items())}, 'tables': sorted(fields),
        'versions': versions, 'schema_fields': fields, 'schema_indexes': indexes}
    ref = {'schema': 1, 'tool_commit': tool_commit, 'app_commit': m.APP_COMMIT, 'baseline_commit': m.BASELINE_COMMIT,
        'worker_commit': m.WORKER_COMMIT, 'legacy_commit': c.LEGACY_COMMIT,
        'baseline': previous.reference['candidate'], 'candidate': target}
    m.validate_reference(ref)
    return m.Bundle(ref, {}, payload, previous.worker_archive, previous.legacy, previous)


def build(tool_commit, output, *, repo=ROOT):
    repo = Path(repo)
    def git(*args): return subprocess.check_output(['git', '-C', str(repo), *args], stderr=subprocess.DEVNULL, timeout=30)
    c.require(re.fullmatch('[a-f0-9]{40}', tool_commit), 'exact_tool_commit_required')
    for scope, qualified in [('moodle/local/ustar', m.APP_COMMIT), ('moodle/theme/ustar', m.APP_COMMIT), ('connect-worker', m.WORKER_COMMIT)]:
        c.require(git('rev-parse', tool_commit + ':' + scope) == git('rev-parse', qualified + ':' + scope), 'tool_qualified_component_mismatch')
    names = {n: ('scripts/' if n == 'production_manifest' else 'scripts/infra/') + n + '.py' for n in m.MODULES}
    names.update({n: 'scripts/infra/' + n + '.py' for n in ('build_studio_viewport_update', 'build_studio_colors_update', 'build_studio_materials_update', 'build_course_studio_update',
        'build_connect_telegram_update', 'build_connect_con02_update')})
    for name, path in names.items():
        raw = git('show', tool_commit + ':' + path)
        c.require((repo / path).read_bytes() == raw, 'dirty_builder_dependency')
        if name not in ('studio_viewport_update', 'build_studio_viewport_update'):
            c.require(raw == git('show', m.PREDECESSOR_TOOL + ':' + path), 'frozen_predecessor_dependency_changed')
    b = reference_from_git(repo, tool_commit)
    sources = {n + '.py': git('show', tool_commit + ':' + names[n]).decode() for n in m.MODULES}
    values = {'reference.json': (json.dumps(b.reference, sort_keys=True) + '\n').encode(),
        'colors.json': (json.dumps(b.predecessor.reference, sort_keys=True) + '\n').encode(),
        'materials.json': (json.dumps(b.predecessor.predecessor.reference, sort_keys=True) + '\n').encode(),
        'studio.json': (json.dumps(b.predecessor.predecessor.predecessor.reference, sort_keys=True) + '\n').encode(),
        'telegram.json': (json.dumps(b.predecessor.predecessor.predecessor.predecessor.reference, sort_keys=True) + '\n').encode(),
        'con02.json': (json.dumps(b.predecessor.predecessor.predecessor.predecessor.predecessor.reference, sort_keys=True) + '\n').encode(),
        'worker.tar.gz': b.worker_archive,
        'predecessor-worker.tar.gz': b.predecessor.predecessor.predecessor.predecessor.predecessor.worker_archive,
        **{'payload/' + n: raw for n, raw in b.payload.items()},
        **{'baseline/' + n: b.predecessor.payload[n] for n in m.CHANGED},
        **{'legacy/' + n: raw for n, raw in b.legacy.items()}}
    assets = {n: base64.b64encode(raw).decode() for n, raw in sorted(values.items())}
    manifest = {'schema': 1, 'tool_commit': tool_commit, 'app_commit': m.APP_COMMIT, 'worker_commit': m.WORKER_COMMIT,
        'sources': {n: {'sha256': c.sha(raw.encode()), 'bytes': len(raw.encode())} for n, raw in sorted(sources.items())},
        'assets': {n: {'sha256': c.sha(raw), 'bytes': len(raw)} for n, raw in sorted(values.items())}}
    m.load_embedded(manifest, sources, assets)
    contract = sources['connect_con02_contract.py']
    decoder = next(ast.get_source_segment(contract, n) for n in ast.parse(contract).body
        if isinstance(n, ast.FunctionDef) and n.name == 'decode_envelope')
    header = '''#!/usr/bin/env python3
"""Fixed Academy canvas/panels production update. Default: offline PLAN_ONLY."""
import json,sys
import importlib.abc,importlib.util
from pathlib import Path
class Refusal(ValueError):pass
def require(value,reason):
    if not value:raise Refusal(reason)
def unique(pairs):
    result={}
    for key,value in pairs:
        require(key not in result,'duplicate_embedded_json_key');result[key]=value
    return result
'''
    constants = ''.join(n + ' = ' + repr(json.dumps(v, sort_keys=True, ensure_ascii=True)) + '\n'
        for n, v in [('MANIFEST_JSON', manifest), ('SOURCES_JSON', sources), ('ASSETS_JSON', assets)])
    bootstrap = '''
try:
    manifest=json.loads(MANIFEST_JSON,object_pairs_hook=unique)
    sources=json.loads(SOURCES_JSON,object_pairs_hook=unique)
    assets=json.loads(ASSETS_JSON,object_pairs_hook=unique)
    require(manifest.get('worker_commit')==WORKER_IDENTITY,'viewport_worker_identity')
    decoded,_=decode_envelope({**manifest,'worker_commit':APP_IDENTITY},sources,assets,app_commit=APP_IDENTITY)
    require(set(decoded)==MODULE_INVENTORY,'embedded_module_inventory')
    parent=Path(__file__).absolute().parent
    sys.path[:]=[p for p in sys.path if p and Path(p).resolve() not in (parent,Path.cwd())]
    class Embedded(importlib.abc.MetaPathFinder,importlib.abc.Loader):
        def find_spec(self,name,path=None,target=None):
            return importlib.util.spec_from_loader(name,self) if name+'.py' in decoded else None
        def create_module(self,spec):return None
        def exec_module(self,module):
            module.__file__='<ustar-studio-viewport/'+module.__name__+'.py>'
            exec(compile(decoded[module.__name__+'.py'],module.__file__,'exec'),module.__dict__)
    sys.meta_path.insert(0,Embedded())
    for n in MODULE_INVENTORY:sys.modules.pop(n.removesuffix('.py'),None)
    from studio_viewport_update import main,load_embedded
    bundle=load_embedded(manifest,sources,assets)
except BaseException:
    print('{"phase":"refused","reason":"embedded_bundle_invalid"}');sys.exit(2)
sys.exit(main(bundle=bundle))
'''
    raw = (header + decoder + '\n' + constants + 'APP_IDENTITY = ' + repr(m.APP_COMMIT)
        + '\nWORKER_IDENTITY = ' + repr(m.WORKER_COMMIT) + '\nMODULE_INVENTORY = set('
        + repr(tuple(sorted(sources))) + ')\n' + bootstrap).encode()
    c.require(len(raw) <= 128 * 1024**2, 'plain_artifact_size')
    output = Path(output); output.mkdir(parents=True, exist_ok=True)
    target = output / ('ustar-studio-viewport-update-' + tool_commit[:8] + '.py'); target.write_bytes(raw); target.chmod(0o644)
    digest = c.sha(raw)
    metadata = {'schema': 1, 'tool_commit': tool_commit, 'app_commit': m.APP_COMMIT, 'baseline_commit': m.BASELINE_COMMIT,
        'predecessor_tool': m.PREDECESSOR_TOOL, 'worker_commit': m.WORKER_COMMIT, 'worker_rollout': False,
        'payload_files': len(b.payload), 'tables': 100, 'versions': m.VERSIONS, 'journal': str(m.BASE),
        'sha256': digest, 'bytes': len(raw), 'default_host_access': False}
    target.with_suffix('.py.sha256').write_text(digest + '  ' + target.name + '\n')
    target.with_suffix('.py.metadata.json').write_text(json.dumps(metadata, sort_keys=True, indent=2) + '\n')
    readme = ('USTAR Academy materials/Studio:2800 ->2801\nApp: ' + m.APP_COMMIT + '\nOperator: ' + tool_commit +
        '\n\nCopy archive to /home/aduk; verify external SHA256 before extraction.\n'
        'Extract into a new root-owned0700 directory, update.py root-owned0600.\n'
        'sha256sum --check --strict SHA256SUMS\n'
        'python3 -I -B update.py --check (read-only; ready_for_viewport_update/local2800)\n'
        'python3 -I -B -u update.py --upgrade --acknowledge-outage\n'
        'python3 -I -B update.py --status (app_ready/local2801/theme2700)\n'
        '\nRecovery: same file --recover. Before public release it restores the paired2800 snapshot;\n'
        'after public release it finishes2801 and preserves newer writes. No worker activation.\n'
        'Full late restore additionally requires --restore --acknowledge-outage --discard-writes-since-snapshot;\n'
        'this discards ALL business writes since the snapshot. Do not use it for routine recovery.\n'
        'Private journal: ' + str(m.BASE) + '. Do not delete locks or edit state.\n').encode()
    members = {'update.py': raw, 'README.txt': readme, 'INSTALL.json': (json.dumps(metadata, sort_keys=True, indent=2) + '\n').encode()}
    members['SHA256SUMS'] = ''.join(c.sha(v) + '  ' + n + '\n' for n, v in sorted(members.items())).encode()
    archive = output / ('ustar-studio-viewport-install-' + tool_commit[:8] + '.tar.gz')
    with archive.open('wb') as stream, gzip.GzipFile(fileobj=stream, mode='wb', mtime=0, filename='') as gz, tarfile.open(fileobj=gz, mode='w') as tar:
        for name, value in sorted(members.items()):
            entry = tarfile.TarInfo(name); entry.size = len(value); entry.mode = 0o600; entry.mtime = 0; tar.addfile(entry, io.BytesIO(value))
    archive.with_suffix('.gz.sha256').write_text(c.sha(archive.read_bytes()) + '  ' + archive.name + '\n')
    print(json.dumps({'artifact': str(target), 'archive': str(archive), 'sha256': digest, 'tool_commit': tool_commit}, sort_keys=True))
    return target


if __name__ == '__main__':
    import argparse
    p = argparse.ArgumentParser(description=__doc__); p.add_argument('--commit', required=True); p.add_argument('--output-dir', required=True)
    args = p.parse_args(); build(args.commit, args.output_dir)
