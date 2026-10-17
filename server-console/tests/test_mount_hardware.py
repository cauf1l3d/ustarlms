import json

import pytest

from ustar_console import collector, hardware
from ustar_console.data import stamp


@pytest.mark.parametrize('fault',[None,'host_ro','host_uuid','host_missing','local_uuid','local_device','local_missing'])
def test_host_mount_and_sandbox_device_must_agree(tmp_path,monkeypatch,fault):
    monkeypatch.setattr(collector,'HDD',tmp_path)
    calls=[]
    def command(argv):
        calls.append(argv)
        host='--task' in argv
        row=dict(target=str(tmp_path),uuid=collector.HDD_UUID,options='rw' if host else 'ro')
        row['maj:min']='8:17'
        if fault=='host_ro' and host: row['options']='ro'
        if fault=='host_uuid' and host or fault=='local_uuid' and not host: row['uuid']='wrong'
        if fault=='local_device' and not host: row['maj:min']='8:1'
        missing=fault=='host_missing' and host or fault=='local_missing' and not host
        return json.dumps(dict(filesystems=[] if missing else [row]))
    monkeypatch.setattr(collector,'command',command)
    if fault:
        with pytest.raises(ValueError): collector.mount()
    else:
        value=collector.mount()
        assert value['uuid']==collector.HDD_UUID and value['total_bytes']>0
        assert any('--task' in argv and argv[argv.index('--task')+1]=='1' for argv in calls)
        assert any('--task' not in argv for argv in calls)


def smart_value(attributes=(5,196,198)):
    return dict(smartctl={'exit_status':0},smart_status={'passed':True},temperature={'current':50},
        ata_smart_attributes={'table':[dict(id=i,raw={'value':0}) for i in attributes]})


def test_partial_smart_keeps_known_values_and_names_unreported_attributes():
    value=hardware.parse_smart(smart_value())
    assert value['status']=='warning' and value['reason_code']=='smart_partial_attributes'
    assert value['health_passed'] is True and value['temperature_c']==50
    assert value['unavailable_attributes']==['197','199']
    assert value['attribute_coverage']=='partial' and '197' not in value['sectors']
    raw=smart_value();raw['ata_smart_attributes']['table'][2]['raw']['value']=1
    assert hardware.parse_smart(raw)['status']=='critical'


@pytest.mark.parametrize('case',['fresh','stale','future','missing','invalid'])
def test_smart_receipt_distinguishes_collection_freshness_and_missing_data(tmp_path,monkeypatch,case):
    path=tmp_path/'smart.json'
    monkeypatch.setattr(hardware,'SMART',path)
    read=hardware.safe_json
    monkeypatch.setattr(hardware,'safe_json',lambda p,**kwargs:read(p))
    when=1000 if case=='stale' else 2200 if case=='future' else 1990
    raw=dict(observed_at=stamp(when),status='ok',disks=[dict(device='/dev/sdb',status='ok')])
    if case!='missing': path.write_text('{bad' if case=='invalid' else json.dumps(raw))
    value=hardware.smart_receipt(now=2000)
    reason={'stale':'smart_stale','future':'smart_clock_skew','missing':'smart_not_collected','invalid':'smart_receipt_invalid'}
    if case=='fresh':
        assert value['status']=='ok' and value['disks'][0]['device']=='/dev/sdb'
    else:
        assert value['status']=='no_data' and value['reason_code']==reason[case]
        assert value['disks']==[]
        if case in ('missing','invalid','future'): assert value['observed_at'] is None


def test_collector_keeps_partial_smart_reason(monkeypatch):
    def unavailable(): raise OSError('fixture source unavailable')
    for name in ('host','containers','mount','backup','collect_cron','lab','units','recovery'):
        monkeypatch.setattr(collector,name,unavailable)
    monkeypatch.setattr(hardware,'clock',unavailable)
    partial=hardware.parse_smart(smart_value())
    value=dict(observed_at=stamp(),status='warning',reason_code='smart_partial_attributes',disks=[partial])
    monkeypatch.setattr(hardware,'smart_receipt',lambda:value)
    row=next(c for c in collector.collect()['checks'] if c['id']=='smart')
    assert row['status']=='warning' and row['reason_code']=='smart_partial_attributes'
    assert row['source_observed_at']==value['observed_at']
