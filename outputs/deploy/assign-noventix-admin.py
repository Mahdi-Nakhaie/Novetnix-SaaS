import json
from pathlib import Path
import sqlite3
import subprocess
import urllib.error
import urllib.request

DATABASE = Path('/var/lib/noventix/noventix.sqlite')
SOURCE = Path('/var/backups/noventix/zzzz-noventix-admin-phone.conf')
TARGET = Path('/etc/php/8.2/fpm/pool.d/zzzz-noventix-admin-phone.conf')
EXPECTED = '[noventix]\nenv[ADMIN_PHONE] = 09371939827\n'


def run(command):
    result = subprocess.run(command, capture_output=True, timeout=30)
    if result.returncode:
        raise RuntimeError('Management command failed: ' + command[0])


class NoRedirect(urllib.request.HTTPRedirectHandler):
    def redirect_request(self, request, response, code, message, headers, new_url):
        return None


assert DATABASE.is_file() and not DATABASE.is_symlink()
assert SOURCE.read_text() == EXPECTED
assert not TARGET.exists()
assert all(path.name < TARGET.name for path in TARGET.parent.glob('*.conf'))
connection = sqlite3.connect(DATABASE.as_uri() + '?mode=rw', uri=True, timeout=10)
installed = False
committed = False
try:
    connection.execute('BEGIN IMMEDIATE')
    account = connection.execute('SELECT name,role FROM users WHERE id=? AND phone=?', (2, '09371939827')).fetchone()
    assert account and account[0] == 'مهدی نخعی' and account[1] in ('student', 'admin')
    original_role = account[1]
    cursor = connection.execute("UPDATE users SET role='admin' WHERE id=? AND phone=? AND name=? AND role=?", (2, '09371939827', 'مهدی نخعی', original_role))
    assert cursor.rowcount == 1
    with TARGET.open('x') as output:
        output.write(EXPECTED)
    installed = True
    TARGET.chmod(0o644)
    run(['php-fpm8.2', '-t'])
    connection.commit()
    committed = True
    run(['systemctl', 'reload', 'php8.2-fpm.service'])
    run(['systemctl', 'is-active', 'php8.2-fpm.service'])
    assert connection.execute('SELECT role FROM users WHERE id=? AND phone=?', (2, '09371939827')).fetchone() == ('admin',)
    with urllib.request.urlopen('https://noventix.ir/login', timeout=30) as response:
        assert response.status == 200
    opener = urllib.request.build_opener(NoRedirect)
    try:
        opener.open('https://noventix.ir/admin-login', timeout=30)
        raise AssertionError('Admin login must require account authentication')
    except urllib.error.HTTPError as response:
        assert response.code == 303
        assert response.headers.get('Location', '').startswith('/login')
    print(json.dumps({'account_id': 2, 'role': 'admin', 'admin_phone_configured': True, 'password_changed': False, 'login_protection_preserved': True}))
except Exception:
    if connection.in_transaction:
        connection.rollback()
    if committed:
        connection.execute('UPDATE users SET role=? WHERE id=? AND phone=?', (original_role, 2, '09371939827'))
        connection.commit()
    if installed:
        TARGET.unlink()
        run(['php-fpm8.2', '-t'])
        run(['systemctl', 'reload', 'php8.2-fpm.service'])
    raise
finally:
    connection.close()
