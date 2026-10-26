"""Fixed local queries. Neither PromQL nor upstream URLs come from the browser."""
import json
import math
import time
from urllib.parse import urlencode
import urllib.request

from .collector import NoRedirect

QUERIES = {
    'cpu': '100 * (1 - avg(rate(node_cpu_seconds_total{job="ustar-node",mode="idle"}[5m])))',
    'ram': '100 * (1 - node_memory_MemAvailable_bytes{job="ustar-node"} / node_memory_MemTotal_bytes{job="ustar-node"})',
    'http': 'probe_duration_seconds{job="ustar-http-external"} * 1000',
}
RANGES = {'1h':3600, '24h':86400, '7d':604800, '15d':1296000}


def history(settings, metric, period):
    if metric not in QUERIES or period not in RANGES:
        raise ValueError('Unsupported fixed query')
    empty = dict(metric=metric, period=period, status='no_data', points=[], reason_code='not_configured')
    if settings.prometheus is None:
        return empty
    end = int(time.time())
    step = math.ceil(RANGES[period]/720)
    params = urlencode(dict(query=QUERIES[metric],start=end-RANGES[period],end=end,step=step))
    opener = urllib.request.build_opener(urllib.request.ProxyHandler({}), NoRedirect())
    try:
        with opener.open(settings.prometheus+'/api/v1/query_range?'+params,timeout=2) as response:
            raw = response.read(262145)
        if len(raw) > 262144:
            raise ValueError('Response limit')
        value = json.loads(raw)
        if value.get('status') != 'success' or value['data']['resultType'] != 'matrix':
            raise ValueError('Invalid Prometheus result')
        series = value['data']['result']
        if len(series) != 1:
            return {**empty,'reason_code':'series_missing_or_ambiguous'}
        points = series[0]['values']
        if len(points) > 721:
            raise ValueError('Point limit')
        clean = []
        for timestamp, number in points:
            timestamp, number = float(timestamp), float(number)
            if not math.isfinite(timestamp) or not end-RANGES[period]-step <= timestamp <= end+5:
                raise ValueError('Timestamp outside requested range')
            clean.append([timestamp, number if math.isfinite(number) else None])
        return dict(metric=metric,period=period,status='ok' if clean else 'no_data',points=clean,
                    step_seconds=step,reason_code='measured' if clean else 'no_samples')
    except (OSError, ValueError, KeyError, TypeError):
        return {**empty,'reason_code':'source_unavailable'}
