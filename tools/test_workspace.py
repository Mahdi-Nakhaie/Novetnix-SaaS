import base64
import importlib.util
import json
import os
from pathlib import Path
import re
import shutil
import sqlite3
import subprocess
import sys
import tempfile
import unittest
from unittest import mock
import uuid

ROOT = Path(__file__).resolve().parent.parent
RUNNER = ROOT / 'services/python-runner'
sys.path.insert(0, str(RUNNER))
import policy
import server as runner

PHP = (ROOT / 'src/workspace.php').read_text()
SCHEMA = (ROOT / 'database/schema.sqlite.sql').read_text()


def file_data(text):
    return {'kind': 'file', 'data': base64.b64encode(text.encode()).decode()}


def payload(owner=1, code="print('real-python')", request_id=None):
    return {'owner': owner, 'request_id': request_id or 'p1-' + str(uuid.uuid4()),
            'entrypoint': 'main.py', 'files': {'main.py': file_data(code)}}


class PolicyTests(unittest.TestCase):
    def test_paths_and_encoding(self):
        for name in ('../x.py', '/x.py', 'a//x.py', 'a/../x.py', '.env', 'a/.hidden', 'x\\y.py', 'https://example.test/x.py', 'a/ x.py', 'x.py\x00'):
            with self.subTest(name=name):
                self.assertFalse(policy.valid_path(name))
        self.assertTrue(policy.valid_path('data/train-1.csv'))
        self.assertEqual(policy.manifest({'data/train.csv': file_data('x,y\n1,2')}), {'data/train.csv': file_data('x,y\n1,2')})
        with self.assertRaises(ValueError):
            policy.manifest({'bad.py': {'data': 'not-base64'}})

    def test_quota_and_parent_collisions(self):
        for files in ({'a.py': file_data('x' * (policy.FILE_BYTES + 1))},
                      {f'{i}.txt': file_data('') for i in range(101)},
                      {'x.py': file_data(''), 'x.py/y.py': file_data('')},
                      {'x.html': file_data('<script>bad</script>')},
                      {f'{i}.csv': file_data('x' * policy.FILE_BYTES) for i in range(6)}):
            with self.assertRaises(ValueError):
                policy.manifest(files)

    def test_requirements_are_exact_offline_allowlist(self):
        self.assertEqual(policy.requirements({'requirements.txt': file_data('# approved\nnumpy==2.2.3\nscikit-learn==1.6.1')}), {'numpy': '2.2.3', 'scikit-learn': '1.6.1'})
        for text in ('numpy', 'numpy>=2', 'numpy==0.0.0', '-r other.txt', '--index-url https://example.test', 'thing @ https://example.test/x.whl', 'torch==2.0.0', 'numpy==2.2.3\nnumpy==2.2.3'):
            with self.subTest(text=text), self.assertRaises(ValueError):
                policy.requirements({'requirements.txt': file_data(text)})

    def test_startup_failure_cleans_up_and_pauses(self):
        job = {**payload(), 'id': str(uuid.uuid4())}
        image = 'noventix/python@sha256:' + 'a' * 64
        with mock.patch.object(runner.subprocess, 'run', side_effect=subprocess.TimeoutExpired('docker', 10)), mock.patch.object(runner, 'remove_container') as cleanup:
            with self.assertRaises(runner.CleanupFailure):
                runner.execute(job, image, lambda: False)
            cleanup.assert_called_once_with('noventix-' + job['id'])

    def test_empty_file_map_and_local_metadata_remain_authoritative(self):
        self.assertIn("'files'=>(object)hf_materialize(", PHP)
        self.assertIn("return array_replace($result,['local_id'", PHP)
        frontend = (ROOT / 'public/assets/workspace.js').read_text()
        self.assertIn('files = {...state.files}', frontend)
        self.assertEqual(policy.manifest({}), {})

    def test_container_plan_has_mandatory_isolation_and_no_host_mount(self):
        image = 'noventix/python@sha256:' + 'a' * 64
        command = runner.docker_command(image, 'noventix-' + str(uuid.uuid4()))
        for required in ('--runtime=runsc', '--network=none', '--read-only', '--user=65532:65532', '--cap-drop=ALL', '--security-opt=no-new-privileges', '--pids-limit=64', '--memory=512m', '--memory-swap=512m', '--cpus=1', '--pull=never', '--log-driver=none'):
            self.assertIn(required, command)
        for prohibited in ('--privileged', '--volume', '-v', '--mount', '--env-file', '--pid=host', '--network=host'):
            self.assertNotIn(prohibited, command)
        with self.assertRaises(ValueError):
            runner.docker_command('python:latest', 'noventix-' + str(uuid.uuid4()))
        self.assertNotIn('shell=True', (RUNNER / 'server.py').read_text())


class QueueTests(unittest.TestCase):
    def setUp(self):
        self.tmp = tempfile.TemporaryDirectory(dir=ROOT / 'tools')
        self.path = Path(self.tmp.name) / 'queue.sqlite'
        self.queue = runner.Queue(self.path)

    def tearDown(self):
        self.tmp.cleanup()

    def test_dataset_input_spooled_and_results_exclude_original_content(self):
        path = 'datasets/' + 'a' * 32 + '/small.csv'
        request = payload()
        request['files'][path] = file_data('x,y\n1,2\n')
        request['dataset_paths'] = [path]
        first = self.queue.submit(request)
        with self.queue.connect() as db:
            stored = db.execute('SELECT payload FROM jobs WHERE id=?', (first['id'],)).fetchone()[0]
            self.assertNotIn(request['files'][path]['data'], stored)
            self.assertTrue(json.loads(stored)['spooled'])
        snapshot = self.queue.claim()
        self.assertEqual(snapshot['files'][path], request['files'][path])
        self.queue.finish(snapshot, {'state': 'succeeded', 'files': snapshot['files'], 'stdout': '', 'stderr': ''})
        self.assertNotIn(path, self.queue.state(first['id'], 1)['files'])
        self.assertFalse((self.queue.inputs / (first['id'] + '.json')).exists())

    def test_corrupt_spooled_input_fails_closed(self):
        first = self.queue.submit(payload())
        (self.queue.inputs / (first['id'] + '.json')).write_text('{}')
        self.assertIsNone(self.queue.claim())
        self.assertEqual(self.queue.state(first['id'], 1)['state'], 'infrastructure_error')
        self.assertFalse((self.queue.inputs / (first['id'] + '.json')).exists())

    def test_idempotent_snapshot_and_durable_queue(self):
        request = payload()
        first = self.queue.submit(request)
        self.assertEqual(first, self.queue.submit(request))
        self.assertEqual(first, runner.Queue(self.path).submit(request))
        changed = {**request, 'files': {'main.py': file_data('print(2)')}}
        with self.assertRaises(ValueError):
            self.queue.submit(changed)
        with self.queue.connect() as db:
            self.assertEqual(db.execute('SELECT COUNT(*) FROM jobs').fetchone()[0], 1)

    def test_per_owner_concurrency_and_ownership(self):
        first = self.queue.submit(payload())
        with self.assertRaises(OverflowError):
            self.queue.submit(payload())
        other = self.queue.submit(payload(2))
        with self.assertRaises(LookupError):
            self.queue.state(first['id'], 2)
        with self.assertRaises(LookupError):
            self.queue.cancel(first['id'], 2)
        self.assertEqual(self.queue.state(other['id'], 2)['state'], 'queued')

    def test_queued_cancel_does_not_execute(self):
        first = self.queue.submit(payload())
        self.assertEqual(self.queue.cancel(first['id'], 1)['state'], 'cancelled')
        self.assertIsNone(self.queue.claim())
        self.assertEqual(self.queue.submit(payload())['state'], 'queued')

    def test_running_cancel_wins_finish_race_and_stops_other_claims(self):
        first = self.queue.submit(payload())
        job = self.queue.claim()
        self.assertEqual(job['id'], first['id'])
        self.assertIsNone(self.queue.claim())
        self.assertEqual(self.queue.cancel(job['id'], 1)['state'], 'cancelling')
        self.queue.finish(job, {'state': 'succeeded', 'stdout': 'not persisted', 'files': {}})
        state = self.queue.state(job['id'], 1)
        self.assertEqual(state['state'], 'cancelled')
        self.assertEqual(state['stdout'], '')

    def test_rate_limit_persists_after_cancellation(self):
        for _ in range(10):
            job = self.queue.submit(payload())
            self.queue.cancel(job['id'], 1)
        with self.assertRaises(OverflowError):
            self.queue.submit(payload())

    def test_global_queue_capacity(self):
        for owner in range(1, 33):
            self.queue.submit(payload(owner))
        with self.assertRaises(OverflowError):
            self.queue.submit(payload(33))


class WorkspaceDatabaseTests(unittest.TestCase):
    def setUp(self):
        self.tmp = tempfile.TemporaryDirectory(dir=ROOT / 'tools')
        self.path = Path(self.tmp.name) / 'app.sqlite'
        self.db = sqlite3.connect(self.path)
        self.db.execute('PRAGMA foreign_keys=ON')
        self.db.executescript(SCHEMA)
        self.db.executemany("INSERT INTO users(phone,name,phone_verified_at) VALUES (?, ?,1)", [('09120000201', 'A'), ('09120000202', 'B')])
        self.files = json.dumps({'main.py': file_data('print(1)')})
        self.db.execute("INSERT INTO workspace_templates(title,instructions,status,files_json,bytes) VALUES ('Exercise','','published',?,8)", (self.files,))
        self.db.execute('INSERT INTO projects(user_id,catalog_index) VALUES (1,-1)')
        self.db.execute("INSERT INTO project_workspaces(project_id,template_id,title,files_json,bytes) VALUES (1,1,'Mine',?,8)", (self.files,))
        self.db.commit()

    def tearDown(self):
        self.db.close()
        self.tmp.cleanup()

    def test_same_project_identity_private_ownership_and_persistence(self):
        query = re.search(r"q\('(SELECT w\.\*,p\.user_id,p\.catalog_index[^']+)'", PHP).group(1)
        self.assertIsNotNone(self.db.execute(query, (1, 1)).fetchone())
        self.assertIsNone(self.db.execute(query, (1, 2)).fetchone())
        self.db.close(); self.db = sqlite3.connect(self.path)
        self.assertEqual(self.db.execute('SELECT files_json FROM project_workspaces WHERE project_id=1').fetchone()[0], self.files)
        self.assertEqual(self.db.execute('SELECT COUNT(*) FROM projects').fetchone()[0], 1)

    def test_template_updates_cannot_overwrite_user_copy(self):
        self.db.execute("UPDATE workspace_templates SET files_json='{}',revision=revision+1 WHERE id=1")
        self.assertEqual(self.db.execute('SELECT files_json FROM project_workspaces WHERE project_id=1').fetchone()[0], self.files)
        self.db.execute("UPDATE project_workspaces SET files_json='{}' WHERE project_id=1")
        self.assertEqual(self.db.execute('SELECT revision FROM workspace_templates WHERE id=1').fetchone()[0], 2)

    def test_compare_and_swap_rejects_stale_save(self):
        query = re.search(r"q\('(UPDATE project_workspaces SET files_json=[^']+)'", PHP).group(1)
        self.assertEqual(self.db.execute(query, ('{}', 0, 1, 1)).rowcount, 1)
        self.assertEqual(self.db.execute(query, (self.files, 8, 1, 1)).rowcount, 0)
        self.assertEqual(self.db.execute('SELECT revision,files_json FROM project_workspaces').fetchone(), (2, '{}'))

    def test_delete_restore_and_quota_include_deleted_files(self):
        self.db.execute("UPDATE project_workspaces SET deleted_at=CURRENT_TIMESTAMP WHERE project_id=1")
        query = re.search(r"q\('(SELECT COALESCE\(SUM\(w.bytes\),0\)[^']+)'", PHP).group(1)
        self.assertEqual(self.db.execute(query, (1, 0)).fetchone()[0], 8)
        self.db.execute('UPDATE project_workspaces SET deleted_at=NULL WHERE project_id=1')
        self.assertEqual(self.db.execute('SELECT files_json FROM project_workspaces').fetchone()[0], self.files)

    def test_schema_is_additive_and_reapplicable(self):
        self.db.executescript(SCHEMA)
        self.assertEqual(self.db.execute('SELECT COUNT(*) FROM users').fetchone()[0], 2)
        self.assertEqual(self.db.execute('SELECT files_json FROM project_workspaces').fetchone()[0], self.files)
        self.assertNotRegex(SCHEMA, r'\b(DROP|TRUNCATE)\b')

    def test_api_uses_existing_auth_csrf_and_no_host_execution(self):
        app = (ROOT / 'src/app.php').read_text()
        route = (ROOT / 'src/router.php').read_text()
        self.assertLess(app.index('validate_csrf();', app.index('function handle_post')), app.index("ws_post($path)"))
        self.assertIn('$u=require_user()', PHP)
        self.assertIn('require_admin();', PHP[PHP.index('function ws_template_save'):])
        self.assertIn("if ($path==='dashboard/workspace-templates') {\n        require_admin();", route)
        self.assertNotRegex(PHP, r'\b(exec|shell_exec|system|passthru|proc_open)\(')
        self.assertIn('CURLOPT_FOLLOWLOCATION=>false', PHP)
        self.assertIn('CURLOPT_PROTOCOLS=>CURLPROTO_HTTPS', PHP)
        self.assertIn('Content-Disposition: attachment', PHP)
        self.assertNotIn('extractTo', PHP)
        self.assertIn('getExternalAttributesIndex', PHP)
        self.assertIn('WS_PROJECT_BYTES', PHP)
        self.assertIn('revision=revision+1', PHP)
        self.assertIn('ws_lock_user($userId)', PHP)


@unittest.skipUnless(os.environ.get('NOVENTIX_SANDBOX_TESTS') == '1' and shutil.which('docker'),
                     'NOT TESTED: isolated runner requires a registered staging server with Docker, gVisor and reviewed image')
class SandboxAcceptanceTests(unittest.TestCase):
    def execute(self, code, extra=None):
        job = payload(code=code)
        job['id'] = str(uuid.uuid4())
        job['files'].update(extra or {})
        return runner.execute(job, os.environ['RUNNER_IMAGE'], lambda: False)

    def test_real_python_and_multifile_csv(self):
        result = self.execute("from helper import total\nimport csv\nprint(total([int(r['x']) for r in csv.DictReader(open('data.csv'))]))", {'helper.py': file_data('def total(xs): return sum(xs)\n'), 'data.csv': file_data('x\n2\n3\n')})
        self.assertEqual(result['state'], 'succeeded')
        self.assertEqual(result['stdout'].strip(), '5')

    def test_syntax_and_runtime_tracebacks(self):
        for code, error in [('def invalid(:', 'SyntaxError'), ('print(1/0)', 'ZeroDivisionError')]:
            result = self.execute(code)
            self.assertEqual(result['state'], 'program_error')
            self.assertIn(error, result['stderr'])

    def test_sklearn_training_and_plot_artifact(self):
        code = "import pandas as pd\nfrom sklearn.model_selection import train_test_split\nfrom sklearn.tree import DecisionTreeClassifier\nfrom sklearn.metrics import accuracy_score\nimport matplotlib\nmatplotlib.use('Agg')\nimport matplotlib.pyplot as plt\ndf=pd.read_csv('data.csv')\nx1,x2,y1,y2=train_test_split(df[['x']],df['y'],test_size=.5,random_state=1)\nm=DecisionTreeClassifier(random_state=1).fit(x1,y1)\nprint(accuracy_score(y2,m.predict(x2)))\nplt.plot(df.x,df.y)\nplt.savefig('plot.png')\n"
        result = self.execute(code, {'data.csv': file_data('x,y\n0,0\n1,0\n2,0\n3,1\n4,1\n5,1\n6,1\n7,1\n'), 'requirements.txt': file_data('pandas==2.2.3\nscikit-learn==1.6.1\nmatplotlib==3.10.1\n')})
        self.assertEqual(result['state'], 'succeeded', result.get('stderr'))
        self.assertIn('plot.png', result['files'])
        self.assertGreaterEqual(float(result['stdout'].strip()), 0)

    def test_timeout_and_output_cap(self):
        self.assertIn(self.execute('while True: pass')['state'], ('timeout', 'cpu_limit'))
        self.assertEqual(self.execute("while True: print('x'*4096)")['state'], 'output_limit')

    def test_readonly_root_and_absent_host_files(self):
        code = "from pathlib import Path\nassert not Path('/project').exists()\ntry:\n Path('/opt/runner/write-probe.txt').write_text('test')\nexcept OSError:\n print('root-is-readonly')\nelse:\n raise AssertionError('root writable')\n"
        result = self.execute(code)
        self.assertEqual(result['state'], 'succeeded')
        self.assertIn('root-is-readonly', result['stdout'])


if __name__ == '__main__':
    unittest.main()
