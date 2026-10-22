import json
from pathlib import Path
import sqlite3
import time

runtime = {}
source = Path('/var/backups/noventix/assign-noventix-admin.py').read_text()
exec(compile(source.split('\nassert DATABASE.is_file()', 1)[0], 'runtime-verification', 'exec'), runtime)
runtime['verify_runtime_admin_phone']()

connection = sqlite3.connect('file:/var/lib/noventix/noventix.sqlite?mode=rw', uri=True, timeout=10)
try:
    connection.execute('BEGIN IMMEDIATE')
    account = connection.execute('SELECT name,role FROM users WHERE id=? AND phone=?', (2, '09371939827')).fetchone()
    assert account == ('مهدی نخعی', 'admin')
    assert connection.execute('SELECT user_id FROM admin_credentials WHERE user_id=?', (2,)).fetchone() is None
    expires = int(time.time()) + 86400
    connection.execute('INSERT INTO admin_credentials(user_id,reset_authorized_until) VALUES(?,?)', (2, expires))
    connection.commit()
    row = connection.execute('SELECT reset_authorized_until,reset_attempts,password_version FROM admin_credentials WHERE user_id=?', (2,)).fetchone()
    assert row == (expires, 0, 0)
    print(json.dumps({'account_id': 2, 'single_use_setup_authorized': True, 'valid_for_hours': 24, 'password_changed': False}))
except Exception:
    connection.rollback()
    raise
finally:
    connection.close()
