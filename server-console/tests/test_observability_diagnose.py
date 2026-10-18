import importlib.util
import json
from pathlib import Path
from types import SimpleNamespace

import pytest


def module():
    path=Path(__file__).parents[1]/'tools/observability_diagnose.py'
    assert path.exists(), 'Safe collector/snapshot comparison tool missing'
    spec=importlib.util.spec_from_file_location('observability_diagnose_fixture',path)
    value=importlib.util.module_from_spec(spec);spec.loader.exec_module(value)
    return value


def test_diagnosis_keeps_empty_requests_and_successful_events_independent(tmp_path,monkeypatch):
    value=module();path=tmp_path/'snapshot.json';log=tmp_path/'requests.log';log.write_text('')
    direct=dict(observed_at='2026-10-18T16:00:00+00:00',status='no_data',reason_code='source_empty',
                coverage_seconds=0,requests=None,rps=None,p95_ms=None,errors_4xx=None,errors_5xx=None,
                events_status='ok',events_reason_code='measured',events=[])
    path.write_text(json.dumps(dict(observed_at=direct['observed_at'],web_observability=direct,
                                    secret='NEVER_EXPORT',host={'private':'NO'})))
    monkeypatch.setattr(value,'ACCESS_LOG',log)
    monkeypatch.setattr(value,'collect',lambda:direct)
    monkeypatch.setattr(value,'safe_json',lambda path,owner:json.loads(path.read_text()))
    result=value.diagnose(SimpleNamespace(snapshot=path),now=1792339205)
    assert result['direct']['requests']['reason_code']=='source_empty'
    assert result['direct']['events']==dict(status='ok',reason_code='measured',categories=0)
    assert result['snapshot']['events']['status']=='ok'
    assert result['numeric_log']['bytes']==0
    assert 'NEVER_EXPORT' not in json.dumps(result) and 'private' not in json.dumps(result)


def test_diagnosis_reports_snapshot_failure_without_hiding_live_events(tmp_path,monkeypatch):
    value=module();path=tmp_path/'missing.json'
    monkeypatch.setattr(value,'ACCESS_LOG',path)
    monkeypatch.setattr(value,'collect',lambda:dict(status='no_data',reason_code='source_empty',events_status='ok',events_reason_code='measured',events=[]))
    def unavailable(*args,**kwargs):raise OSError()
    monkeypatch.setattr(value,'safe_json',unavailable)
    result=value.diagnose(SimpleNamespace(snapshot=path))
    assert result['snapshot']['status']=='unavailable_or_invalid'
    assert result['direct']['events']['status']=='ok'
    assert result['numeric_log']['present'] is False


def test_unknown_nested_values_and_events_never_escape_diagnosis(tmp_path,monkeypatch):
    value=module();path=tmp_path/'snapshot.json'
    unsafe=dict(status='SECRET',reason_code='token=SECRET',events_status='SECRET',events_reason_code='SECRET',events=[dict(MESSAGE='SECRET')],rps=float('nan'))
    monkeypatch.setattr(value,'ACCESS_LOG',path)
    monkeypatch.setattr(value,'collect',lambda:unsafe)
    monkeypatch.setattr(value,'safe_json',lambda *args,**kwargs:dict(observed_at='invalid',web_observability=unsafe))
    result=value.diagnose(SimpleNamespace(snapshot=path))
    assert 'SECRET' not in json.dumps(result,allow_nan=False)
