import base64
import binascii
import re

FILE_BYTES = 1_048_576
PROJECT_BYTES = 5_242_880
FILE_COUNT = 100
EXTENSIONS = {'py', 'txt', 'csv', 'json', 'md', 'png', 'joblib', 'pkl', 'npy', 'npz'}
PACKAGES = {'numpy': '2.2.3', 'pandas': '2.2.3', 'scipy': '1.15.2',
            'scikit-learn': '1.6.1', 'matplotlib': '3.10.1', 'joblib': '1.4.2'}


def valid_path(path):
    return (isinstance(path, str) and len(path) <= 160
            and re.fullmatch(r'[A-Za-z0-9_][A-Za-z0-9_. /-]*', path) is not None
            and all(part and not part.startswith('.') and part.strip() == part
                    for part in path.split('/')))


def manifest(files):
    if not isinstance(files, dict) or len(files) > FILE_COUNT:
        raise ValueError('At most 100 files/directories are allowed.')
    clean, total = {}, 0
    for path, item in files.items():
        if not valid_path(path) or not isinstance(item, dict):
            raise ValueError('Invalid project path.')
        directory = item.get('kind') == 'directory'
        if not directory and path.rsplit('.', 1)[-1].lower() not in EXTENSIONS:
            raise ValueError('Unsupported file type.')
        encoded = '' if directory else item.get('data')
        if not isinstance(encoded, str) or len(encoded) > 1_398_104:
            raise ValueError('File size limit exceeded.')
        try:
            raw = base64.b64decode(encoded, validate=True)
        except (ValueError, binascii.Error) as error:
            raise ValueError('Invalid file encoding.') from error
        total += len(raw)
        if len(raw) > FILE_BYTES or total > PROJECT_BYTES:
            raise ValueError('Project storage limit exceeded.')
        clean[path] = {'kind': 'directory' if directory else 'file',
                       'data': base64.b64encode(raw).decode('ascii')}
    for path in clean:
        parts = path.split('/')[:-1]
        for index in range(1, len(parts) + 1):
            parent = '/'.join(parts[:index])
            if parent in clean and clean[parent]['kind'] != 'directory':
                raise ValueError('File cannot be a parent directory.')
    return clean


def requirements(files):
    item = files.get('requirements.txt')
    if not item:
        return {}
    try:
        text = base64.b64decode(item['data'], validate=True).decode('utf-8')
    except (ValueError, UnicodeError) as error:
        raise ValueError('requirements.txt must be UTF-8.') from error
    selected = {}
    for raw in text.splitlines():
        line = raw.strip()
        if not line or line.startswith('#'):
            continue
        match = re.fullmatch(r'([A-Za-z0-9_-]+)==([0-9]+(?:\.[0-9]+){1,3})', line)
        if not match:
            raise ValueError('Only allowlisted package==version requirements are supported.')
        name, version = match.groups()
        name = name.lower().replace('_', '-')
        if PACKAGES.get(name) != version:
            raise ValueError('Unsupported dependency/version: ' + name)
        if name in selected:
            raise ValueError('Duplicate dependency: ' + name)
        selected[name] = version
    return selected
