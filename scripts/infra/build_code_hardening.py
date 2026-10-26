#!/usr/bin/env python3
"""Build a deterministic standalone operator from the reviewed stdlib modules."""
import argparse
import hashlib
from pathlib import Path

HERE=Path(__file__).resolve().parent
DB_SHA='976afa0d2dffd58bf260dde4cb90cb5297f73d3f78fb9df10c2f9dc4b60ecf5e'

def build():
    db=(HERE/'postgres_app_handoff.py').read_bytes()
    if hashlib.sha256(db).hexdigest()!=DB_SHA:raise ValueError('DB coordinator pin changed')
    core=(HERE/'code_hardening.py').read_text()
    return ('#!/usr/bin/env python3\n# USTAR code hardening; default offline PLAN_ONLY.\n'
            'import sys\nfrom types import ModuleType\n'
            'def embedded(name, source):\n'
            '    module=ModuleType(name);module.__file__="<embedded/"+name+">"\n'
            '    sys.modules[name]=module\n'
            '    exec(compile(source,module.__file__,"exec"),module.__dict__)\n'
            '    return module\n'
            'embedded("postgres_app_handoff",'+repr(db.decode())+')\n'
            'operator=embedded("ustar_code_hardening",'+repr(core)+')\n'
            'if __name__=="__main__":sys.exit(operator.main())\n').encode()

if __name__=='__main__':
    parser=argparse.ArgumentParser();parser.add_argument('output',type=Path);args=parser.parse_args()
    raw=build();args.output.write_bytes(raw);print(hashlib.sha256(raw).hexdigest())
