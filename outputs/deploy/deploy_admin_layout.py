import hashlib
import http.client
import json
import os
from pathlib import Path
import shutil
import subprocess
import sys

ROOT = Path('/var/www/noventix')
STAGE = Path(sys.argv[1]).resolve()
assert STAGE.parent == Path('/var/backups/noventix')
manifest = json.loads((STAGE / 'manifest.json').read_text())
backup = STAGE / 'previous'
assert not backup.exists()


def digest(path):
    return hashlib.sha256(path.read_bytes()).hexdigest()


def run(args, **kwargs):
    subprocess.run(args, check=True, timeout=90, **kwargs)


def install(source, target):
    temporary = target.with_name(target.name + '.admin-release')
    assert not temporary.exists()
    shutil.copy2(source, temporary)
    temporary.chmod(0o644)
    os.replace(temporary, target)


def probe(path, status):
    connection = http.client.HTTPSConnection('noventix.ir', timeout=30)
    try:
        connection.request('GET', path, headers={'Cache-Control': 'no-cache'})
        response = connection.getresponse()
        body = response.read()
        assert response.status == status, (path, response.status)
        assert b'Fatal error' not in body and b'PDOException' not in body
        if status == 303:
            assert response.getheader('Location', '').startswith('/login?next=')
        if path.startswith('/assets/'):
            assert hashlib.sha256(body).hexdigest() == digest(ROOT / ('public' + path.split('?')[0]))
        elif status == 200:
            assert b'/assets/admin.css' not in body, 'Admin styles leaked to public page'
            assert b'/assets/app.js?v=3' in body, 'Stale shell'
        print('HTTP', status, path)
    finally:
        connection.close()


for name, info in manifest.items():
    assert not Path(name).is_absolute() and '..' not in Path(name).parts
    target = ROOT / name
    assert target.parent.is_dir() and not target.is_symlink()
    assert digest(STAGE / name) == info['sha256']
    assert (not target.exists()) if info['before'] is None else digest(target) == info['before'], name
    if name.endswith('.php'):
        run(['php', '-l', str(STAGE / name)])
run(['python3', str(STAGE / 'tools/test_admin_layout.py')], env={**os.environ, 'NOVENTIX_TEST_ROOT': str(STAGE)})
backup.mkdir()
for name, info in manifest.items():
    if info['before'] is not None:
        saved = backup / name
        saved.parent.mkdir(parents=True, exist_ok=True)
        shutil.copy2(ROOT / name, saved)
installed = []
try:
    for name in sorted(manifest, key=lambda p: p.startswith('src/')):
        install(STAGE / name, ROOT / name)
        installed.append(name)
    run(['systemctl', 'reload', 'php8.2-fpm.service'])
    run(['systemctl', 'is-active', 'php8.2-fpm.service'])
    run(['python3', str(STAGE / 'tools/test_admin_layout.py')], env={**os.environ, 'NOVENTIX_TEST_ROOT': str(ROOT)})
    for path, status in [('/', 200), ('/login', 200), ('/pricing', 200), ('/dashboard', 303), ('/dashboard/users', 303), ('/dashboard/messages', 303), ('/assets/admin.css?v=1', 200), ('/assets/app.js?v=3', 200)]:
        probe(path, status)
    for name, info in manifest.items():
        assert digest(ROOT / name) == info['sha256']
except Exception:
    for name in reversed(installed):
        if manifest[name]['before'] is None:
            (ROOT / name).unlink()
        else:
            install(backup / name, ROOT / name)
    run(['systemctl', 'reload', 'php8.2-fpm.service'])
    print('Previous release restored.')
    raise
print('Admin layout deployed and verified.')
