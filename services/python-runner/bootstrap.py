import base64
import json
import os
import resource
import signal
import stat
import subprocess
import sys
import time
from pathlib import Path

sys.path.insert(0, '/opt/runner')
from policy import FILE_BYTES, FILE_COUNT, PROJECT_BYTES, manifest, valid_path


def child_limits():
    resource.setrlimit(resource.RLIMIT_FSIZE, (FILE_BYTES, FILE_BYTES))
    resource.setrlimit(resource.RLIMIT_CORE, (0, 0))
    resource.setrlimit(resource.RLIMIT_NOFILE, (64, 64))


def main():
    request = json.loads(sys.stdin.buffer.read(7_500_001))
    files = manifest(request['files'])
    root = Path('/workspace')
    for name, item in files.items():
        path = root / name
        path.parent.mkdir(parents=True, exist_ok=True)
        if item['kind'] == 'directory':
            path.mkdir(exist_ok=True)
        else:
            path.write_bytes(base64.b64decode(item['data'], validate=True))
    entry = request['entrypoint']
    if not valid_path(entry) or entry not in files or not entry.endswith('.py'):
        raise ValueError('Invalid entrypoint.')
    launcher = 'import os,runpy,sys;sys.path.insert(0,os.getcwd());runpy.run_path(sys.argv[1],run_name="__main__")'
    started = time.monotonic()
    state = None
    with open('/tmp/stdout', 'w+b') as out, open('/tmp/stderr', 'w+b') as err:
        process = subprocess.Popen([sys.executable, '-I', '-B', '-c', launcher, entry],
                                   cwd=root, stdin=subprocess.DEVNULL, stdout=out, stderr=err,
                                   env={'PATH': '/usr/local/bin:/usr/bin', 'HOME': '/tmp',
                                        'MPLBACKEND': 'Agg', 'MPLCONFIGDIR': '/tmp/matplotlib',
                                        'OPENBLAS_NUM_THREADS': '1', 'OMP_NUM_THREADS': '1'},
                                   start_new_session=True, preexec_fn=child_limits)
        while process.poll() is None:
            if os.fstat(out.fileno()).st_size + os.fstat(err.fileno()).st_size > 131_072:
                state = 'output_limit'
            elif time.monotonic() - started > 15:
                state = 'timeout'
            if state:
                try:
                    os.killpg(process.pid, signal.SIGKILL)
                except ProcessLookupError:
                    pass
                break
            try:
                process.wait(timeout=0.05)
            except subprocess.TimeoutExpired:
                pass
        process.wait()
        out.seek(0); err.seek(0)
        stdout, stderr = out.read(131_072), err.read(131_072)
        if len(stdout) + len(stderr) > 131_072:
            state = 'output_limit'
        result = {'state': state or ('cpu_limit' if process.returncode == -signal.SIGXCPU else 'succeeded' if process.returncode == 0 else 'program_error'),
                  'stdout': stdout[:65536].decode('utf-8', 'replace'),
                  'stderr': stderr[:65536].decode('utf-8', 'replace'),
                  'exit_code': process.returncode, 'files': {}}
    if state:
        return result
    total = 0
    for directory, dirs, names in os.walk(root, followlinks=False):
        dirs[:] = [d for d in dirs if not Path(directory, d).is_symlink() and not d.startswith('.') and d != '__pycache__']
        for name in names:
            path = Path(directory, name)
            relative = path.relative_to(root).as_posix()
            if not valid_path(relative) or not stat.S_ISREG(path.lstat().st_mode):
                continue
            if path.suffix.lower().lstrip('.') not in {'py', 'txt', 'csv', 'json', 'md', 'png', 'joblib', 'pkl', 'npy', 'npz'}:
                continue
            fd = os.open(path, os.O_RDONLY | os.O_NOFOLLOW | os.O_NONBLOCK)
            with os.fdopen(fd, 'rb') as handle:
                if not stat.S_ISREG(os.fstat(handle.fileno()).st_mode):
                    continue
                raw = handle.read(FILE_BYTES + 1)
            total += len(raw)
            if len(raw) > FILE_BYTES or total > PROJECT_BYTES or len(result['files']) >= FILE_COUNT:
                result.update(state='artifact_limit', files={})
                return result
            result['files'][relative] = {'kind': 'file', 'data': base64.b64encode(raw).decode('ascii')}
    for name, item in files.items():
        if item['kind'] == 'directory' and (root / name).is_dir() and not (root / name).is_symlink():
            result['files'][name] = item
    result['files'] = manifest(result['files'])
    return result


if __name__ == '__main__':
    try:
        result = main()
    except Exception:
        result = {'state': 'artifact_limit', 'stdout': '', 'stderr': 'Sandbox result could not be collected.', 'files': {}}
    sys.stdout.write(json.dumps(result))
