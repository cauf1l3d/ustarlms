#!/usr/bin/env python3
"""Check the new updater's version/task/schema guards against actual stage SQL."""
import json
from pathlib import Path
import sys
sys.path[:0]=[str(Path(__file__).resolve().parents[2]/'scripts/infra'),
              str(Path(__file__).resolve().parents[2]/'scripts')]
import connect_chat_link_update as update

root=Path(__file__).resolve().parents[2]
before,candidate,repeat,restored=[json.loads((root/'artifacts'/('chat-link-'+phase+'.json')).read_text())
    for phase in ('baseline','candidate','repeat','restored')]
assert {k:before['addons'][k] for k in update.CURRENT}=={k:str(v) for k,v in update.CURRENT.items()}
assert len(before['tables'])==99 and before['tasks'][update.engine.NEW_TASK]==[1,1]
update.verify_versions(None,candidate,before,True)
update.verify_versions(None,repeat,before,True)
update.verify_versions(None,restored,before,False)
print('CHAT_LINK_NATIVE_SQL_CURRENT1905_TARGET2000_CUSTOM_TASK_SCHEMA_PAIRED_RESTORE=PASS')
