#!/usr/bin/env python3
"""Deterministic ordinary Python operator; no host access at build time."""
import argparse
import hashlib
from pathlib import Path

HERE=Path(__file__).resolve().parent

def build():
    sources={name:(HERE/(name+'.py')).read_text() for name in ('docker_lifecycle','docker_lifecycle_host')}
    digests={name:hashlib.sha256(raw.encode()).hexdigest() for name,raw in sources.items()}
    bootstrap='''#!/usr/bin/env python3
"""USTAR runtime successor. Default offline PLAN_ONLY; fixed server1 profile."""
import hashlib,json,sys
from pathlib import Path
from types import ModuleType
'''
    bootstrap+='SOURCES = '+repr(sources)+'\nDIGESTS = '+repr(digests)+'\n'
    bootstrap+='''
def bootstrap():
    expected={'docker_lifecycle','docker_lifecycle_host'}
    if set(SOURCES)!=expected or set(DIGESTS)!=expected:
        raise ValueError('inventory')
    for name,raw in SOURCES.items():
        if not isinstance(raw,str) or hashlib.sha256(raw.encode()).hexdigest()!=DIGESTS[name]:
            raise ValueError('digest')
    for name in ('docker_lifecycle','docker_lifecycle_host'):
        module=ModuleType(name);module.__file__='<embedded/'+name+'.py>'
        sys.modules[name]=module
        exec(compile(SOURCES[name],module.__file__,'exec'),module.__dict__)
    return sys.modules['docker_lifecycle']
if __name__=='__main__':
    try:operator=bootstrap()
    except BaseException:
        print(json.dumps({'phase':'refused','reason':'embedded_source_integrity',
            'business_rows_restored':False,'provider_called':False}));sys.exit(2)
    sys.exit(operator.main(operator_path=Path(__file__).absolute()))
'''
    return bootstrap.encode()

if __name__=='__main__':
    parser=argparse.ArgumentParser(description=__doc__);parser.add_argument('output',type=Path)
    args=parser.parse_args();raw=build();args.output.write_bytes(raw)
    print(hashlib.sha256(raw).hexdigest())
