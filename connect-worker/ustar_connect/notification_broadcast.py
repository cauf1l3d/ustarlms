"""Validate and stage the explicit announcement payload from the private bridge."""
import re
import tempfile


EVENT = 'feed_broadcast'
MAX_FILE_BYTES = 50 * 1024 * 1024
MAX_TOTAL_BYTES = 100 * 1024 * 1024
INVALID_FILENAME = re.compile(r'[\x00-\x1f\x7f"\\/]')
INVALID_TEXT = re.compile(r'[\x00-\x08\x0b\x0c\x0e-\x1f\x7f]')


def valid_filename(name):
    return (isinstance(name, str) and 0 < len(name.encode()) <= 1020
            and name not in ('.', '..') and not INVALID_FILENAME.search(name))


def valid_text(text, with_files):
    return (isinstance(text, str) and bool(text.strip()) and not INVALID_TEXT.search(text)
            and len(text.encode('utf-16-le')) // 2 <= (1024 if with_files else 4096))


def stage(value, bridge, lease, resources, clock, expires):
    if (not isinstance(value, dict) or set(value) != {'text', 'files', 'stamp', 'subject'}
            or not isinstance(value['stamp'], str) or not re.fullmatch('[a-f0-9]{64}', value['stamp'])
            or not isinstance(value['files'], list) or len(value['files']) > 10
            or not valid_text(value['text'], bool(value['files']))):
        raise ValueError('invalid_broadcast')
    seen = set()
    total = 0
    for file in value['files']:
        if (not isinstance(file, dict) or set(file) != {'id', 'name', 'size', 'hash'}
                or type(file['id']) is not int or not 0 < file['id'] < 2**63 or file['id'] in seen
                or type(file['size']) is not int or not 0 < file['size'] <= MAX_FILE_BYTES
                or not valid_filename(file['name'])
                or not isinstance(file['hash'], str) or not re.fullmatch('[a-f0-9]{40}', file['hash'])):
            raise ValueError('invalid_broadcast_file')
        seen.add(file['id'])
        total += file['size']
    if total > MAX_TOTAL_BYTES:
        raise ValueError('broadcast_files_too_large')
    files = []
    for file in value['files']:
        if int(clock()) >= expires:
            raise ValueError('expired_broadcast_lease')
        target = resources.enter_context(tempfile.TemporaryFile())
        bridge.download_broadcast_file(lease, file, target)
        files.append((file['name'], file['size'], target))
    if int(clock()) >= expires:
        raise ValueError('expired_broadcast_lease')
    return value['stamp'], value['text'], files
