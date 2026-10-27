#!/usr/bin/env python3
"""Prepare the exact backup producer/scheduler pair for canonical HTTPS.

Only readiness changes. No unit/calendar, container, archive or key operation.
Caller owns existing HTTPS and backup locks; before-images remain root-private.
"""
import json
import os
from pathlib import Path
import re

import cron_release_update as files
from production_patch_readiness import read_file

BEFORE={'producer':'75ca974f1d763f6fc84281501a99936894f1b487c1cc4d0c1d71697e67c79d75',
        'scheduler':'54533f6a629699e031a877b4a57f68401477a61cf20cab39512c79a2e0477b7d'}
AFTER={'producer':'a604fbcd6a0733d29142bb3130dc4bea2e7da1797c6995699d9d5516f21b05c3',
       'scheduler':'a97039a8111dfa4d68fa0a851e17dec9a82d8f1096b7b61b8a71609859134e8c'}
PATHS={'producer':'usr/local/sbin/USTAR_HDD_BACKUP_20261006.py',
       'scheduler':'usr/local/sbin/USTAR_HDD_SCHEDULE_20261006.py'}


class Manager:
    def __init__(self,sources,root=Path('/'),uid=0):
        self.sources,self.root=sources,Path(root)
        self.p=files.Paths(self.root)
        self.p.base=self.root/'var/lib/ustar-backup/https-compat-20261021'
        self.p.record=self.p.base/'transaction.json'
        self.writer=files.Manager(self.p,uid=uid)
        self.paths={k:self.root/v for k,v in PATHS.items()}
        files.require(set(sources)==set(AFTER),'backup_source_inventory')
        for key,raw in sources.items():
            files.require(files.sha(raw)==AFTER[key],'backup_source_digest')
            compile(raw,'reviewed_'+key,'exec')

    def read(self,path,private=False):
        self.writer.secure(path,private=private)
        return read_file(path,2*1024**2)

    def snapshot(self):return {k:files.sha(self.read(p)) for k,p in self.paths.items()}

    def record(self):return self.writer.json(self.p.record)

    def check(self):
        current=self.snapshot()
        if os.path.lexists(self.p.record):
            r=self.record()
            files.require(r.get('kind')=='backup_https_pair' and r.get('schema')==1,'backup_transaction_identity')
            files.require(r.get('phase')!='prepared','backup_pair_recovery_required')
        if current==AFTER:
            files.require(os.path.lexists(self.p.record) and self.record().get('phase')=='active',
                          'backup_pair_receipt_required')
            return {'phase':'already_applied','backup_or_outage_started':False}
        files.require(current==BEFORE,'backup_helper_drift')
        return {'phase':'ready_for_backup_HTTPS_compatibility','backup_or_outage_started':False}

    def restore(self,r):
        files.require(r.get('kind')=='backup_https_pair' and r.get('schema')==1
                      and r.get('phase') in ('prepared','active','rolled_back'),'backup_transaction_identity')
        for key,path in self.paths.items():
            files.require(files.sha(self.read(path)) in (BEFORE[key],AFTER[key]),'backup_helper_drift')
            files.require(self.writer.metadata(path)==r[key]['meta'],'backup_helper_metadata_drift')
            files.require(files.sha(self.read(self.p.base/(key+'.before'),True))==BEFORE[key],
                          'backup_before_image_drift')
        for key,path in self.paths.items():
            self.writer.replace(path,self.read(self.p.base/(key+'.before'),True),r[key]['meta'])
        files.require(self.snapshot()==BEFORE,'backup_pair_restore_failed')
        r['phase']='rolled_back';self.writer.save(r)
        return {'phase':'rolled_back','backup_or_outage_started':False}

    def apply(self):
        if os.path.lexists(self.p.record):
            r=self.record()
            if r.get('phase')=='prepared':self.restore(r)
        result=self.check()
        if result['phase']=='already_applied':return result
        if not os.path.lexists(self.p.base):
            self.p.base.mkdir(mode=0o700);self.writer.sync(self.p.base.parent)
        self.writer.secure(self.p.base,directory=True,private=True)
        r={'kind':'backup_https_pair','schema':1,'phase':'prepared'}
        for key,path in self.paths.items():
            r[key]={'before':BEFORE[key],'after':AFTER[key],'meta':self.writer.metadata(path)}
            self.writer.private_write(self.p.base/(key+'.before'),self.read(path))
        self.writer.save(r)
        try:
            for key,path in self.paths.items():
                files.require(files.sha(self.read(path))==BEFORE[key],'backup_helper_drift')
                self.writer.replace(path,self.sources[key],r[key]['meta'])
            files.require(self.snapshot()==AFTER,'backup_pair_readback')
            r['phase']='active';self.writer.save(r)
        except BaseException:
            self.restore(r);raise
        return {'phase':'backup_HTTPS_compatibility_prepared','producer_sha256':AFTER['producer'],
                'scheduler_sha256':AFTER['scheduler'],'backup_or_outage_started':False,
                'units_or_schedule_changed':False}
