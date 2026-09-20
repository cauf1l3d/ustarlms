#!/usr/bin/env python3
"""Check the current application, never a historical release copy."""
import argparse
from pathlib import Path
import re
import subprocess
import xml.etree.ElementTree as ET

ROOT = Path(__file__).resolve().parents[2]


def check(skip_php=False):
    files = [ROOT / p for p in subprocess.check_output(
        ['git', 'ls-files', '-z', '--', 'moodle'], cwd=ROOT).decode().split('\0') if p]
    php = [p for p in files if p.suffix == '.php']
    js = [p for p in files if p.suffix == '.js' and 'vendor' not in p.parts]
    if not skip_php:
        for path in php:
            subprocess.run(['php', '-l', str(path)], check=True, stdout=subprocess.DEVNULL)
    for path in js:
        subprocess.run(['node', '--check', str(path)], check=True, stdout=subprocess.DEVNULL)
    schema = ET.parse(ROOT / 'moodle/local/ustar/db/install.xml').getroot()
    tables = schema.find('TABLES')
    names = [table.attrib['NAME'] for table in tables]
    assert len(names) == len(set(names)), 'Duplicate XMLDB table'
    for table in tables:
        fields = {f.attrib['NAME'] for f in table.find('FIELDS')}
        for group in ('KEYS', 'INDEXES'):
            records = table.find(group)
            if records is None:
                continue
            seen = set()
            for item in records:
                assert item.attrib['NAME'] not in seen, (table.attrib['NAME'], group, 'duplicate name')
                seen.add(item.attrib['NAME'])
                assert {f.strip() for f in item.attrib['FIELDS'].split(',')} <= fields, (table.attrib['NAME'], item.attrib)
    plugin = (ROOT / 'moodle/local/ustar/version.php').read_text()
    version = int(re.search(r'\$plugin->version\s*=\s*(\d+)', plugin)[1])
    assert int(schema.attrib['VERSION']) <= version, 'Schema version exceeds plugin version'
    saves = re.findall(r'upgrade_plugin_savepoint\(true,\s*(\d+)', (ROOT / 'moodle/local/ustar/db/upgrade.php').read_text())
    assert all(int(v) <= version for v in saves), 'Upgrade savepoint exceeds plugin version'
    templates = [p for p in files if p.suffix == '.mustache']
    for path in templates:
        stack = []
        for match in re.finditer(r'{{([#^/])\s*([\w.]+)\s*}}', path.read_text()):
            kind, name = match.groups()
            if kind in '#^':
                stack.append(name)
            else:
                assert stack and stack.pop() == name, f'{path}: mismatched {name}'
        assert not stack, f'{path}: unclosed section'
    print(f'CURRENT_SOURCE_OK php={"not_run" if skip_php else len(php)} js={len(js)} tables={len(names)} templates={len(templates)}')


if __name__ == '__main__':
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--skip-php', action='store_true', help='Local structural checks only; forbidden as a CI substitute')
    check(parser.parse_args().skip_php)
