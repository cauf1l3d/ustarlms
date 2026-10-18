import json
import os
from pathlib import Path
import subprocess
import time

import pytest
from ustar_console import collector, data, metrics
from ustar_console.config import Settings


def put(path,value):
    path.parent.mkdir(parents=True,exist_ok=True)
    path.write_text(json.dumps(value))


@pytest.mark.parametrize('attack',['symlink','parent_symlink','hardlink','large','writable','nan'])
def test_json_input_guards(tmp_path,attack):
    path=tmp_path/'source.json'
    put(path,{'ok':True})
    if attack=='symlink':
        target=tmp_path/'target.json';path.rename(target);path.symlink_to(target)
    if attack=='parent_symlink':
        (tmp_path/'real').mkdir();path.rename(tmp_path/'real/source.json');(tmp_path/'link').symlink_to(tmp_path/'real');path=tmp_path/'link/source.json'
    if attack=='hardlink': os.link(path,tmp_path/'other')
    if attack=='large': path.write_bytes(b'x'*262145)
    if attack=='writable': path.chmod(0o666)
    if attack=='nan': path.write_text('{"value":NaN}')
    with pytest.raises((OSError,ValueError)):
        data.safe_json(path)


def test_safe_json_owner(tmp_path):
    path=tmp_path/'value';put(path,{'ok':True})
    with pytest.raises(ValueError): data.safe_json(path,owner=os.getuid()+1)


def test_missing_snapshot_is_not_healthy(tmp_path):
    value=data.snapshot(tmp_path/'missing')
    assert value['stale'] and value['host']=={}
    assert all(s=='no_data' for s in value['domains'].values())


@pytest.mark.parametrize('age',[0,46,-60])
def test_freshness_and_clock(tmp_path,monkeypatch,age):
    path=tmp_path/'snapshot';now=time.time()
    original=data.safe_json
    monkeypatch.setattr(data,'safe_json',lambda p,owner:original(p))
    put(path,dict(version=1,observed_at=data.stamp(now-age),checks=[data.check('host','host','server','ok')],host={},containers=[],backup={},lab={}))
    value=data.snapshot(path,now)
    assert (value['checks'][0]['status']=='ok') == (age==0)
    assert value['domains']['server']=='no_data'  # Missing sustained-resource rules.


def test_memavailable_not_memfree():
    result=collector.memory('MemTotal: 100 kB\nMemAvailable: 40 kB\nMemFree: 2 kB\nCached: 30 kB\nSwapTotal: 5 kB\nSwapFree: 3 kB\n')
    assert result['available_bytes']==40960 and result['available_percent']==40


def test_probe_does_not_expose_env(monkeypatch):
    calls=[]
    def fake(argv,**kwargs):
        calls.append(argv)
        return '"running"|true|false|0|"unless-stopped"|"2026-10-16T00:00:00Z"\n'
    monkeypatch.setattr(collector,'command',fake)
    rows=collector.containers()
    assert len(rows)==2 and rows[0]['running'] is True
    assert all('Env' not in ' '.join(argv) for argv in calls)
    assert [argv[-1] for argv in calls]==list(collector.CONTAINERS)


def test_docker_failure_no_fake_stopped(monkeypatch):
    def failed(*args): raise TimeoutError()
    monkeypatch.setattr(collector,'command',failed)
    assert all(row['state']=='no_data' and 'running' not in row for row in collector.containers())


@pytest.mark.parametrize('probe',['timeout','too_large'])
def test_command_resource_bounds(probe):
    if probe=='timeout':
        with pytest.raises(TimeoutError): collector.command(['/bin/sleep','2'],timeout=.05)
    else:
        with pytest.raises(ValueError): collector.command(['/usr/bin/yes'],limit=1000)


def test_no_probe_on_browser_read(logged,monkeypatch):
    def forbidden(*args): raise AssertionError('Browser invoked root probe')
    monkeypatch.setattr(collector,'collect',forbidden)
    assert logged.get('/api/overview').status_code==200


def test_unknown_history_query(logged):
    assert logged.get('/api/history?metric=arbitrary_query').status_code==422
    assert logged.get('/api/history?metric=cpu&period=99y').status_code==422


def test_unconfigured_history_has_no_zeros(settings):
    result=metrics.history(settings,'cpu','1h')
    assert result['points']==[] and result['reason_code']=='not_configured'


def test_history_nonfinite_and_fixed_bounds(settings,monkeypatch):
    cfg=Settings(settings.origin,settings.database,settings.snapshot,'http://127.0.0.1:9090')
    urls=[]
    class Reply:
        def __enter__(self): return self
        def __exit__(self,*args): pass
        def read(self,limit):
            return json.dumps(dict(status='success',data=dict(resultType='matrix',result=[{'values':[[time.time(),'NaN']]}]))).encode()
    class Opener:
        def open(self,url,timeout): urls.append((url,timeout));return Reply()
    monkeypatch.setattr(metrics.urllib.request,'build_opener',lambda *args:Opener())
    result=metrics.history(cfg,'cpu','15d')
    assert result['points'][0][1] is None
    assert result['step_seconds']==1800 and urls[0][0].startswith('http://127.0.0.1:9090/api/v1/query_range?')


def test_config_disallows_insecure_or_remote_sources(tmp_path):
    with pytest.raises(ValueError): Settings('http://console.test')
    with pytest.raises(ValueError): Settings('https://console.test/path')
    with pytest.raises(ValueError): Settings('https://console.test',prometheus='http://evil.test')


def test_missing_lab_is_not_stopped(monkeypatch,tmp_path):
    monkeypatch.setattr(collector,'LAB',tmp_path/'missing')
    original=collector.safe_json
    monkeypatch.setattr(collector,'safe_json',lambda p,owner:original(p))
    result=collector.lab()
    assert result['phase']=='no_data'


def test_lab_receipt_has_no_secret_material(monkeypatch,tmp_path):
    path=tmp_path/'state.json'
    put(path,dict(phase='stopped',ready=False,unlimited=False,expires_at=None,token='SECRET',identity='/private/key',config={'dbpass':'SECRET'}))
    monkeypatch.setattr(collector,'LAB',path)
    original=collector.safe_json
    monkeypatch.setattr(collector,'safe_json',lambda p,owner:original(p))
    result=collector.lab()
    assert result['phase']=='stopped'
    assert 'SECRET' not in json.dumps(result) and 'identity' not in result


@pytest.mark.parametrize('age,status',[(3600,'ok'),(100000,'warning'),(200000,'critical')])
def test_backup_receipt_age_and_redaction(tmp_path,monkeypatch,age,status):
    root=tmp_path/'hdd';state=tmp_path/'state'
    monkeypatch.setattr(collector,'HDD',root);monkeypatch.setattr(collector,'BACKUP',state)
    filename='ustar-recovery-20261016T000000Z-1234abcd.tar.gz.age'
    archive=root/'backups/managed'/filename;archive.parent.mkdir(parents=True);archive.write_bytes(b'age')
    now=time.time();os.utime(archive,(now-age,now-age))
    manifest=dict(file=filename,bytes=3,sha256='a'*64,hdd_uuid=collector.HDD_UUID,readback_verified=True,private_key='SECRET')
    put(state/'last-hdd-success.json',dict(success=True,phase='complete',finished_utc=data.stamp(now-age),manifest=manifest))
    original=collector.safe_json
    monkeypatch.setattr(collector,'safe_json',lambda p,owner:original(p))
    original_open=collector.open_regular
    monkeypatch.setattr(collector,'open_regular',lambda p,owner:original_open(p))
    # CI runs unprivileged; the archive itself must be root-owned in service mode.
    original_stat=os.fstat
    def fstat(fd):
        info=original_stat(fd)
        return os.stat_result((info.st_mode,info.st_ino,info.st_dev,info.st_nlink,0,info.st_gid,info.st_size,info.st_atime,info.st_mtime,info.st_ctime))
    monkeypatch.setattr(collector.os,'fstat',fstat)
    result=collector.backup(now)
    assert result['status']==status and result['verification']=='creation_receipt_only'
    assert 'SECRET' not in json.dumps(result)
    os.utime(archive,(now+60,now+60))
    with pytest.raises(ValueError): collector.backup(now)
