import json
import os
from pathlib import Path
import socket
import sqlite3
import struct
import subprocess
import tempfile
import time
import urllib.error
import urllib.request

DATABASE = Path('/var/lib/noventix/noventix.sqlite')
SOURCE = Path('/var/backups/noventix/00-noventix-admin-phone.conf')
TARGET = Path('/etc/php/8.2/fpm/pool.d/00-noventix-admin-phone.conf')
LEGACY = TARGET.with_name('zzzz-noventix-admin-phone.conf')
EXPECTED = '[noventix]\nenv[ADMIN_PHONE] = 09371939827\n'


def run(command):
    result = subprocess.run(command, capture_output=True, timeout=30)
    if result.returncode:
        raise RuntimeError('Management command failed: ' + command[0])


def verify_runtime_admin_phone():
    descriptor, filename = tempfile.mkstemp(prefix='admin-config-check-', suffix='.php', dir='/var/www/noventix/storage')
    try:
        with os.fdopen(descriptor, 'w') as output:
            output.write('<?php echo getenv("ADMIN_PHONE") === "09371939827" ? "MATCH" : "MISMATCH";')
        os.chmod(filename, 0o644)

        def record(kind, data=b''):
            return struct.pack('!BBHHBB', 1, kind, 1, len(data), 0, 0) + data

        def parameter(key, value):
            key, value = key.encode(), value.encode()
            def length(size):
                return bytes([size]) if size < 128 else struct.pack('!I', size | 0x80000000)
            return length(len(key)) + length(len(value)) + key + value

        parameters = b''.join(parameter(key, value) for key, value in {
            'SCRIPT_FILENAME': filename, 'SCRIPT_NAME': '/admin-config-check.php',
            'REQUEST_METHOD': 'GET', 'SERVER_PROTOCOL': 'HTTP/1.1',
        }.items())
        with socket.socket(socket.AF_UNIX, socket.SOCK_STREAM) as client:
            client.settimeout(10)
            client.connect('/run/php/php8.2-fpm-noventix.sock')
            client.sendall(record(1, struct.pack('!HB5x', 1, 0)) + record(4, parameters) + record(4) + record(5))
            def read(size):
                data = b''
                while len(data) < size:
                    chunk = client.recv(size - len(data))
                    if not chunk:
                        raise RuntimeError('Runtime configuration probe ended early')
                    data += chunk
                return data
            response = b''
            while True:
                _, kind, _, size, padding, _ = struct.unpack('!BBHHBB', read(8))
                data = read(size) if size else b''
                if padding:
                    read(padding)
                if kind == 6:
                    response += data
                if kind == 3:
                    break
        assert response.split(b'\r\n\r\n', 1)[-1] == b'MATCH', 'Effective administrator phone does not match'
    finally:
        os.unlink(filename)


class NoRedirect(urllib.request.HTTPRedirectHandler):
    def redirect_request(self, request, response, code, message, headers, new_url):
        return None


assert DATABASE.is_file() and not DATABASE.is_symlink()
assert SOURCE.read_text() == EXPECTED
assert not TARGET.exists()
# PHP-FPM prepends environment entries, so the earliest assignment takes precedence at runtime.
assert all(path.name > TARGET.name for path in TARGET.parent.glob('*.conf'))
legacy_exists = LEGACY.exists()
assert not legacy_exists or (not LEGACY.is_symlink() and LEGACY.read_text() == EXPECTED)
connection = sqlite3.connect(DATABASE.as_uri() + '?mode=rw', uri=True, timeout=10)
installed = False
legacy_removed = False
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
    if legacy_exists:
        LEGACY.unlink()
        legacy_removed = True
    run(['php-fpm8.2', '-t'])
    connection.commit()
    committed = True
    run(['systemctl', 'reload', 'php8.2-fpm.service'])
    run(['systemctl', 'is-active', 'php8.2-fpm.service'])
    deadline = time.monotonic() + 10
    while True:
        try:
            verify_runtime_admin_phone()
            break
        except (OSError, AssertionError, RuntimeError):
            if time.monotonic() >= deadline:
                raise
            time.sleep(0.1)
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
        if legacy_removed:
            with LEGACY.open('x') as output:
                output.write(EXPECTED)
            LEGACY.chmod(0o644)
        run(['php-fpm8.2', '-t'])
        run(['systemctl', 'reload', 'php8.2-fpm.service'])
    raise
finally:
    connection.close()
