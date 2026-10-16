import base64
import concurrent.futures
import fcntl
import hashlib
import hmac
import json
import os
import re
import selectors
import sqlite3
import subprocess
import threading
import time
import uuid
from http.server import BaseHTTPRequestHandler, HTTPServer
from pathlib import Path
from urllib.parse import parse_qs, urlsplit

from policy import PACKAGES, manifest, requirements, valid_path

ACTIVE = ('queued', 'running', 'cancelling')
TERMINAL = ('succeeded', 'program_error', 'cancelled', 'timeout', 'output_limit',
            'artifact_limit', 'dependency_error', 'memory_limit', 'cpu_limit', 'infrastructure_error')
MAX_BODY = 7_500_000
DOCKER_ENV = {'PATH': '/usr/local/bin:/usr/bin:/bin', 'HOME': '/nonexistent'}


def docker_command(image, container):
    if not re.fullmatch(r'[A-Za-z0-9./:_-]+@sha256:[a-f0-9]{64}', image):
        raise ValueError('A reviewed image pinned by sha256 digest is required.')
    if not re.fullmatch(r'noventix-[a-f0-9-]{36}', container):
        raise ValueError('Invalid container name.')
    return ['docker', 'create', '--pull=never', '--name', container, '--runtime=runsc', '--network=none',
            '--read-only', '--user=65532:65532', '--cap-drop=ALL',
            '--security-opt=no-new-privileges', '--pids-limit=64',
            '--memory=512m', '--memory-swap=512m', '--cpus=1', '--cgroupns=private',
            '--ulimit=core=0:0', '--ulimit=nofile=64:64', '--ulimit=cpu=10:10',
            '--tmpfs=/workspace:rw,noexec,nosuid,nodev,size=16m,uid=65532,gid=65532,mode=0700',
            '--tmpfs=/tmp:rw,noexec,nosuid,nodev,size=16m,uid=65532,gid=65532,mode=0700',
            '--workdir=/workspace', '--log-driver=none', '-i', image,
            'python', '-I', '-B', '/opt/runner/bootstrap.py']


class CleanupFailure(RuntimeError):
    pass


def remove_container(name):
    existing = subprocess.check_output(['docker', 'ps', '-a', '--filter', 'name=^/' + name + '$', '--format', '{{.Names}}'], env=DOCKER_ENV, timeout=10).decode().strip()
    if not existing:
        return
    if existing != name:
        raise CleanupFailure('Unexpected sandbox identity.')
    completed = subprocess.run(['docker', 'rm', '-f', name], env=DOCKER_ENV,
                               stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL, timeout=15)
    if completed.returncode:
        raise CleanupFailure('Sandbox cleanup failed; execution must stop.')


def execute(job, image, cancelled):
    files = manifest(job['files'])
    try:
        requirements(files)
    except ValueError as error:
        return {'state': 'dependency_error', 'stdout': '', 'stderr': str(error), 'files': {}}
    name = 'noventix-' + job['id']
    command = docker_command(image, name)
    output = bytearray()
    state = None
    started = time.monotonic()
    try:
        subprocess.run(command, stdin=subprocess.DEVNULL, stdout=subprocess.DEVNULL,
                       stderr=subprocess.DEVNULL, env=DOCKER_ENV, timeout=10, check=True)
        process = subprocess.Popen(['docker', 'start', '-a', '-i', name], stdin=subprocess.PIPE, stdout=subprocess.PIPE,
                                   stderr=subprocess.STDOUT, env=DOCKER_ENV)
    except Exception as error:
        try:
            remove_container(name)
        except Exception as cleanup_error:
            raise CleanupFailure('Sandbox startup cleanup could not be confirmed.') from cleanup_error
        raise CleanupFailure('Sandbox startup failed; runner paused for inspection.') from error
    # A separate writer avoids blocking timeout/cancellation while Docker consumes stdin.
    def feed():
        try:
            process.stdin.write(json.dumps({'files': files, 'entrypoint': job['entrypoint']}).encode())
            process.stdin.close()
        except (BrokenPipeError, OSError):
            pass
    writer = threading.Thread(target=feed, daemon=True)
    writer.start()
    try:
        with selectors.DefaultSelector() as selector:
            selector.register(process.stdout, selectors.EVENT_READ)
            while True:
                if cancelled():
                    state = 'cancelled'; break
                if time.monotonic() - started > 30:
                    state = 'timeout'; break
                ready = selector.select(0.1)
                if ready:
                    chunk = os.read(process.stdout.fileno(), 65536)
                    if not chunk:
                        break
                    output.extend(chunk)
                    if len(output) > 8_388_608:
                        state = 'output_limit'; break
                elif process.poll() is not None:
                    continue
        if not state:
            try:
                oom = subprocess.check_output(['docker', 'inspect', '--format', '{{.State.OOMKilled}}', name], env=DOCKER_ENV, timeout=5).strip() == b'true'
                if oom:
                    return {'state': 'memory_limit', 'stdout': '', 'stderr': 'Sandbox memory limit exceeded.', 'files': {}}
                result = json.loads(output)
                if process.wait(timeout=3) != 0 or result.get('state') not in TERMINAL:
                    raise ValueError('Invalid sandbox result.')
                result['files'] = manifest(result.get('files', {}))
                for field in ('stdout', 'stderr'):
                    if not isinstance(result.get(field), str) or len(result[field].encode()) > 196608:
                        raise ValueError('Invalid sandbox output.')
                return result
            except (ValueError, TypeError, subprocess.TimeoutExpired):
                state = 'infrastructure_error'
        return {'state': state, 'stdout': '', 'stderr': 'Sandbox stopped: ' + state, 'files': {}}
    finally:
        try:
            remove_container(name)
        except Exception as error:
            raise CleanupFailure('Sandbox cleanup could not be confirmed.') from error
        finally:
            if process.poll() is None:
                process.kill()
            process.wait(timeout=5)
            writer.join(timeout=1)
            process.stdout.close()


class Queue:
    def __init__(self, path):
        self.path = str(path)
        self.inputs = Path(self.path + '.inputs')
        if self.inputs.is_symlink():
            raise ValueError('Input storage must not be a symlink.')
        self.inputs.mkdir(mode=0o700, exist_ok=True)
        with self.connect() as db:
            db.execute('PRAGMA journal_mode=WAL')
            db.execute('CREATE TABLE IF NOT EXISTS jobs (id TEXT PRIMARY KEY, owner INTEGER NOT NULL, request_id TEXT NOT NULL, fingerprint TEXT NOT NULL, state TEXT NOT NULL, payload TEXT NOT NULL, result TEXT, created REAL NOT NULL, UNIQUE(owner,request_id))')
            db.execute("CREATE UNIQUE INDEX IF NOT EXISTS one_active_owner ON jobs(owner) WHERE state IN ('queued','running','cancelling')")

    def connect(self):
        db = sqlite3.connect(self.path, timeout=10)
        db.row_factory = sqlite3.Row
        return db

    def submit(self, payload):
        owner = payload.get('owner')
        key = payload.get('request_id')
        if type(owner) is not int or owner <= 0 or not isinstance(key, str) or not re.fullmatch(r'p[1-9][0-9]*-[a-f0-9-]{36}', key):
            raise ValueError('Invalid owner/request ID.')
        files = manifest(payload.get('files'))
        entry = payload.get('entrypoint')
        if not valid_path(entry) or not entry.endswith('.py') or files.get(entry, {}).get('kind') != 'file':
            raise ValueError('Invalid entrypoint.')
        dataset_paths = payload.get('dataset_paths', [])
        if not isinstance(dataset_paths, list) or len(dataset_paths) > 100 or any(not isinstance(p, str) or not re.fullmatch(r'datasets/[a-f0-9]{32}/.+', p) or p not in files for p in dataset_paths):
            raise ValueError('Invalid dataset references.')
        encoded = json.dumps({'owner': owner, 'request_id': key, 'files': files, 'entrypoint': entry, 'dataset_paths': dataset_paths}, sort_keys=True)
        fingerprint = hashlib.sha256(encoded.encode()).hexdigest()
        with self.connect() as db:
            db.execute('BEGIN IMMEDIATE')
            existing = db.execute('SELECT * FROM jobs WHERE owner=? AND request_id=?', (owner, key)).fetchone()
            if existing:
                if existing['fingerprint'] != fingerprint:
                    raise ValueError('Request ID already used for another snapshot.')
                return {'id': existing['id'], 'state': existing['state']}
            now = time.time()
            db.execute("DELETE FROM jobs WHERE created<? AND state NOT IN ('queued','running','cancelling')", (now - 86400,))
            if db.execute('SELECT COUNT(*) FROM jobs').fetchone()[0] >= 500:
                raise OverflowError('History capacity reached.')
            if db.execute("SELECT COUNT(*) FROM jobs WHERE state IN ('queued','running','cancelling')").fetchone()[0] >= 32:
                raise OverflowError('Queue is full.')
            if db.execute('SELECT COUNT(*) FROM jobs WHERE owner=? AND created>?', (owner, now - 60)).fetchone()[0] >= 10:
                raise OverflowError('Run rate limit exceeded.')
            if db.execute("SELECT id FROM jobs WHERE owner=? AND state IN ('queued','running','cancelling')", (owner,)).fetchone():
                raise OverflowError('An execution is already active for this owner.')
            identifier = str(uuid.uuid4())
            input_path = self.inputs / (identifier + '.json')
            with input_path.open('xb') as stream:
                os.chmod(input_path, 0o600)
                stream.write(encoded.encode())
            reference = json.dumps({'spooled': True, 'sha256': fingerprint})
            try:
                db.execute('INSERT INTO jobs VALUES (?,?,?,?,?,?,?,?)',
                           (identifier, owner, key, fingerprint, 'queued', reference, None, now))
            except Exception:
                input_path.unlink(missing_ok=True)
                raise
        return {'id': identifier, 'state': 'queued'}

    def state(self, identifier, owner):
        with self.connect() as db:
            row = db.execute('SELECT * FROM jobs WHERE id=? AND owner=?', (identifier, owner)).fetchone()
        if not row:
            raise LookupError('Run not found.')
        result = json.loads(row['result']) if row['result'] else {}
        return {**result, 'id': row['id'], 'state': row['state']}

    def cancel(self, identifier, owner):
        with self.connect() as db:
            db.execute("UPDATE jobs SET state=CASE WHEN state='queued' THEN 'cancelled' ELSE 'cancelling' END WHERE id=? AND owner=? AND state IN ('queued','running')", (identifier, owner))
        state = self.state(identifier, owner)
        if state['state'] == 'cancelled':
            self.remove_input(identifier)
            with self.connect() as db:
                db.execute("UPDATE jobs SET payload='{}' WHERE id=? AND owner=?", (identifier, owner))
        return state

    def remove_input(self, identifier):
        if not re.fullmatch(r'[a-f0-9-]{36}', identifier):
            raise ValueError('Invalid input identity.')
        (self.inputs / (identifier + '.json')).unlink(missing_ok=True)

    def claim(self):
        with self.connect() as db:
            db.execute('BEGIN IMMEDIATE')
            row = db.execute("SELECT * FROM jobs WHERE state='queued' ORDER BY created LIMIT 1").fetchone()
            if not row:
                return None
            db.execute("UPDATE jobs SET state='running' WHERE id=?", (row['id'],))
        try:
            payload = json.loads(row['payload'])
            if payload.get('spooled'):
                input_path = self.inputs / (row['id'] + '.json')
                if input_path.is_symlink() or input_path.stat().st_size > MAX_BODY:
                    raise ValueError('Invalid input storage.')
                raw = input_path.read_bytes()
                if not hmac.compare_digest(hashlib.sha256(raw).hexdigest(), payload['sha256']):
                    raise ValueError('Input checksum mismatch.')
                payload = json.loads(raw)
            return {**payload, 'id': row['id']}
        except (OSError, ValueError, KeyError):
            with self.connect() as db:
                db.execute("UPDATE jobs SET state='infrastructure_error',payload='{}' WHERE id=?", (row['id'],))
            self.remove_input(row['id'])
            return None

    def finish(self, job, result):
        if job.get('dataset_paths') and isinstance(result.get('files'), dict):
            result = {**result, 'files': {p: f for p, f in result['files'].items() if not p.startswith('datasets/')}}
        with self.connect() as db:
            row = db.execute('SELECT state FROM jobs WHERE id=?', (job['id'],)).fetchone()
            if row and row['state'] == 'cancelling':
                result = {'state': 'cancelled', 'stdout': '', 'stderr': '', 'files': {}}
            db.execute('UPDATE jobs SET state=?,result=?,payload=? WHERE id=?',
                       (result['state'], json.dumps(result), '{}', job['id']))
        self.remove_input(job['id'])


def worker(queue, image, stop):
    while not stop.is_set():
        job = queue.claim()
        if not job:
            stop.wait(0.2)
            continue
        try:
            result = execute(job, image, lambda: stop.is_set() or queue.state(job['id'], job['owner'])['state'] == 'cancelling')
        except CleanupFailure:
            stop.set()
            result = {'state': 'infrastructure_error', 'stdout': '', 'stderr': 'Sandbox cleanup failed; runner paused.', 'files': {}}
        except Exception:
            result = {'state': 'infrastructure_error', 'stdout': '', 'stderr': 'Runner infrastructure failed.', 'files': {}}
        queue.finish(job, result)


class Handler(BaseHTTPRequestHandler):
    def log_message(self, *args):
        pass

    def handle_request(self):
        self.connection.settimeout(10)
        if not hmac.compare_digest(self.headers.get('Authorization', ''), 'Bearer ' + self.server.token):
            self.reply(401, {'error': 'Unauthorized'}); return
        if self.server.stop.is_set():
            self.reply(503, {'error': 'Runner paused after an infrastructure failure'}); return
        try:
            path = urlsplit(self.path)
            if self.command == 'GET' and path.path == '/health':
                self.reply(200, {**self.server.runtime, 'isolation': 'gvisor', 'gpu': False}); return
            if self.command == 'POST' and path.path == '/jobs':
                length = int(self.headers.get('Content-Length', '0'))
                if length < 1 or length > MAX_BODY:
                    raise ValueError('Invalid request size.')
                raw = self.rfile.read(length)
                if len(raw) != length:
                    raise ValueError('Incomplete request.')
                payload = json.loads(raw)
                if not isinstance(payload, dict):
                    raise ValueError('Invalid request.')
                self.reply(202, self.server.queue.submit(payload)); return
            match = re.fullmatch(r'/jobs/([a-f0-9-]{36})', path.path)
            if match and self.command in ('GET', 'DELETE'):
                owner = int(parse_qs(path.query).get('owner', ['0'])[0])
                method = self.server.queue.cancel if self.command == 'DELETE' else self.server.queue.state
                self.reply(200, method(match[1], owner)); return
            self.reply(404, {'error': 'Not found'})
        except OverflowError as error:
            self.reply(429, {'error': str(error)})
        except LookupError:
            self.reply(404, {'error': 'Not found'})
        except (ValueError, KeyError, TypeError):
            self.reply(422, {'error': 'Invalid request'})
        except Exception:
            self.reply(503, {'error': 'Runner unavailable'})

    do_GET = handle_request
    do_POST = handle_request
    do_DELETE = handle_request

    def reply(self, status, payload):
        data = json.dumps(payload).encode()
        self.send_response(status)
        self.send_header('Content-Type', 'application/json')
        self.send_header('Content-Length', str(len(data)))
        self.send_header('Cache-Control', 'no-store')
        self.end_headers()
        self.wfile.write(data)


def main():
    os.umask(0o077)
    lock = open(os.environ['RUNNER_DB'] + '.lock', 'a')
    fcntl.flock(lock, fcntl.LOCK_EX | fcntl.LOCK_NB)
    image = os.environ['RUNNER_IMAGE']
    token = os.environ['RUNNER_TOKEN']
    if len(token) < 32:
        raise ValueError('Runner token must be at least 32 characters.')
    docker_command(image, 'noventix-' + str(uuid.uuid4()))
    info = json.loads(subprocess.check_output(['docker', 'info', '--format', '{{json .}}'], env=DOCKER_ENV, timeout=10))
    if 'runsc' not in info.get('Runtimes', {}) or str(info.get('CgroupVersion')) != '2':
        raise RuntimeError('gVisor and cgroups v2 are mandatory; no fallback is permitted.')
    queue = Queue(os.environ['RUNNER_DB'])
    with queue.connect() as db:
        interrupted = db.execute("SELECT id FROM jobs WHERE state IN ('running','cancelling')").fetchall()
        for row in interrupted:
            remove_container('noventix-' + row['id'])
            queue.remove_input(row['id'])
        db.execute("UPDATE jobs SET state='infrastructure_error',payload='{}' WHERE state IN ('running','cancelling')")
    probe_code = ('import json,platform,importlib.metadata as m;'
                  'print(json.dumps({"python":platform.python_version(),"packages":'
                  '{n:m.version(n) for n in ' + repr(list(PACKAGES)) + '}}))')
    probe = execute({'id': str(uuid.uuid4()), 'entrypoint': 'main.py',
                     'files': {'main.py': {'kind': 'file', 'data': base64.b64encode(probe_code.encode()).decode()}}}, image, lambda: False)
    if probe['state'] != 'succeeded':
        raise RuntimeError('Sandbox startup probe failed.')
    runtime = json.loads(probe['stdout'])
    if not runtime['python'].startswith('3.12.') or runtime['packages'] != PACKAGES:
        raise RuntimeError('Sandbox image does not match the approved runtime profile.')
    stop = threading.Event()
    with concurrent.futures.ThreadPoolExecutor(max_workers=2) as pool:
        for _ in range(2):
            pool.submit(worker, queue, image, stop)
        server = HTTPServer(('127.0.0.1', int(os.environ.get('RUNNER_PORT', '8091'))), Handler)
        server.queue, server.token, server.stop, server.runtime = queue, token, stop, runtime
        try:
            server.serve_forever()
        finally:
            stop.set()
            server.server_close()


if __name__ == '__main__':
    main()
