import contextlib
import importlib.util
import io
import json
import shutil
import subprocess
import sys
import pathlib
import tempfile
import unittest
from unittest import mock

from tools import test_release

ROOT = pathlib.Path(__file__).resolve().parent.parent
spec = importlib.util.spec_from_file_location('studio_terminal', ROOT / 'services/python-runner/terminal.py')
terminal = importlib.util.module_from_spec(spec)
spec.loader.exec_module(terminal)


class TerminalTests(unittest.TestCase):
    def run_command(self, command):
        output = io.StringIO()
        with contextlib.redirect_stdout(output):
            terminal.terminal(command)
        return output.getvalue()

    def test_python_and_version(self):
        self.assertEqual(self.run_command('python -c "print(6 * 7)"'), '42\n')
        self.assertRegex(self.run_command('python --version'), r'^3\.')

    def test_project_files_and_arguments(self):
        with tempfile.TemporaryDirectory(dir=ROOT / 'tools') as directory:
            root = pathlib.Path(directory)
            (root / 'main.py').write_text('import sys; print(sys.argv[1])')
            (root / 'data.csv').write_text('x,y\n1,2')
            with mock.patch.object(terminal.pathlib.Path, 'cwd', return_value=root):
                self.assertEqual(self.run_command('python main.py "hello world"'), 'hello world\n')
                self.assertIn('data.csv', self.run_command('dir'))
                self.assertIn('1,2', self.run_command('cat data.csv'))
                with self.assertRaises(ValueError):
                    self.run_command('cat ../outside.txt')

    def test_no_shell_or_online_install(self):
        for command in ('curl example.invalid', 'pip install numpy', 'sh -c anything', 'python', 'cat'):
            with self.subTest(command=command), self.assertRaises(ValueError):
                self.run_command(command)
        self.assertEqual(self.run_command('pwd'), '/workspace\n')
        self.assertIn('==', self.run_command('pip list'))

    def test_library_profile_is_pinned(self):
        spec = importlib.util.spec_from_file_location('studio_policy', ROOT / 'services/python-runner/policy.py')
        policy = importlib.util.module_from_spec(spec)
        spec.loader.exec_module(policy)
        requirements = (ROOT / 'services/python-runner/requirements-science.txt').read_text().splitlines()
        self.assertEqual(set(requirements), {f'{k}=={v}' for k, v in policy.PACKAGES.items()})
        for name in ('seaborn', 'sympy', 'networkx', 'openpyxl', 'requests', 'scikit-learn'):
            self.assertIn(name, policy.PACKAGES)


class AutomaticPlotTests(unittest.TestCase):
    def setUp(self):
        spec = importlib.util.spec_from_file_location('studio_plotting', ROOT / 'services/python-runner/plotting.py')
        self.plotting = importlib.util.module_from_spec(spec)
        spec.loader.exec_module(self.plotting)

    def test_no_scientific_import_for_plain_python(self):
        with mock.patch.dict(sys.modules):
            sys.modules.pop('matplotlib.pyplot', None)
            with mock.patch.object(self.plotting.Path, 'cwd') as cwd:
                self.plotting.save_figures()
                cwd.assert_not_called()

    def test_figures_are_saved_without_overwriting_user_files(self):
        with tempfile.TemporaryDirectory(dir=ROOT / 'tools') as directory:
            root = pathlib.Path(directory)
            (root / 'figure-1.png').write_bytes(b'original')
            first, empty, second = mock.Mock(axes=[1]), mock.Mock(axes=[]), mock.Mock(axes=[1])
            pyplot = mock.Mock()
            pyplot.get_fignums.return_value = [1, 2, 3]
            pyplot.figure.side_effect = [first, empty, second]
            with mock.patch.dict(sys.modules, {'matplotlib.pyplot': pyplot}), mock.patch.object(self.plotting.Path, 'cwd', return_value=root):
                self.plotting.save_figures()
            first.savefig.assert_called_once_with(root / 'figure-2.png', format='png', dpi=100)
            empty.savefig.assert_not_called()
            second.savefig.assert_called_once_with(root / 'figure-3.png', format='png', dpi=100)
            self.assertEqual((root / 'figure-1.png').read_bytes(), b'original')

    def test_figures_collected_even_when_user_code_fails(self):
        for failure in (ZeroDivisionError('test'), SystemExit(0)):
            with self.subTest(failure=type(failure).__name__), mock.patch.object(sys, 'argv', ['launcher', 'main.py']), mock.patch.object(self.plotting.runpy, 'run_path', side_effect=failure), mock.patch.object(self.plotting, 'save_figures') as capture:
                with self.assertRaises(type(failure)):
                    self.plotting.run('main.py')
                capture.assert_called_once()


class StudioHttpTests(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        test_release.ReleaseHttpTest.setUpClass()
        cls.base = test_release.ReleaseHttpTest.base

    @classmethod
    def tearDownClass(cls):
        test_release.ReleaseHttpTest.tearDownClass()

    def test_member_settings_streak_and_commerce_pause(self):
        client = test_release.Client(self.base)
        client.request('/register')
        response = client.post('/auth/request', {'first_name': 'هنرجو', 'last_name': 'میزکار', 'phone': '09990000871', 'password': 'FixturePass123'})
        self.assertEqual(response[0], 303)
        for path in ('/dashboard/activity', '/dashboard/settings', '/dashboard/gamification'):
            with self.subTest(path=path):
                status, _, source = client.request(path)
                self.assertEqual(status, 200)
                self.assertNotIn('PDOException', source)
        dashboard = client.request('/dashboard')[2]
        self.assertNotIn('تأیید نشده', dashboard)
        self.assertNotIn('تأیید شده', dashboard)
        self.assertNotIn('href="/dashboard/subscription"', dashboard)
        client.request('/dashboard/settings')
        self.assertEqual(client.post('/profile/account', {'name': 'نام تازه'})[0], 303)
        self.assertIn('نام تازه', client.request('/dashboard/settings')[2])
        self.assertEqual(client.request('/dashboard/users')[0], 403)
        for path in ('/pricing', '/dashboard/subscription'):
            status, _, source = client.request(path)
            self.assertEqual(status, 200)
            self.assertIn('موقتاً غیرفعال', source)
            self.assertNotIn('انتخاب پلن', source)

    def test_editor_has_server_terminal_and_no_browser_runtime(self):
        client = test_release.Client(self.base)
        client.request('/register')
        client.post('/auth/request', {'first_name': 'هنرجو', 'last_name': 'پایتون', 'phone': '09990000872', 'password': 'FixturePass123'})
        client.request('/dashboard/workspace')
        project = __import__('json').loads(client.post('/workspace/create', {'title': 'Studio test'})[2])['id']
        status, _, source = client.request('/dashboard/workspace/' + str(project))
        self.assertEqual(status, 200)
        for hook in ('data-ws-terminal-form', 'data-ws-command', 'data-ws-terminal-log', 'data-ws-runtime', 'workspace.css?v=5'):
            self.assertIn(hook, source)
        self.assertNotIn('python-runner.js', source)
        self.assertNotIn('pyodide', source)
        panel_start = source.index('class="panel-card ws-code-panel"')
        run_button = source.index('data-ws-action="run"')
        editor = source.index('class="ws-code-wrap"')
        self.assertLess(panel_start, run_button)
        self.assertLess(run_button, editor)
        self.assertEqual(source.count('data-ws-action="run"'), 1)
        self.assertIn('class="ws-dataset-search"', source)
        self.assertIn('چالش هفتگی', source)
        for hook in ('data-ws-plots', 'data-ws-tab="plots"', 'data-ws-upload-list', 'data-ws-errors', 'data-ws-file-count'):
            self.assertIn(hook, source)
        self.assertNotIn('data-ws-action="assess"', source)
        import json
        state = json.loads(client.request('/workspace/data?id=' + str(project))[2])
        self.assertFalse(state['runner_ready'])
        self.assertEqual(client.post('/workspace/run', {'id': project, 'revision': state['revision'], 'request_key': 'a' * 36, 'terminal_command': 'python main.py'})[0], 503)

    def test_challenges_create_private_persistent_assessable_workspace(self):
        client = test_release.Client(self.base)
        client.request('/register')
        client.post('/auth/request', {'first_name': 'هنرجو', 'last_name': 'چالش', 'phone': '09990000873', 'password': 'FixturePass123'})
        status, _, source = client.request('/dashboard/challenges')
        self.assertEqual(status, 200)
        self.assertIn('id="weekly-challenge"', source)
        self.assertIn('name="challenge_index"', source)
        self.assertNotIn('XP +', source)
        self.assertEqual(client.post('/workspace/create', {'challenge_index': '-1'})[0], 422)
        self.assertEqual(client.post('/workspace/create', {'challenge_index': '9999'})[0], 422)
        self.assertEqual(client.post('/workspace/create', {'challenge_index': 'invalid'})[0], 422)
        status, _, body = client.post('/workspace/create', {'challenge_index': '0'})
        self.assertEqual(status, 201)
        project = json.loads(body)['id']
        self.assertEqual(json.loads(client.post('/workspace/create', {'challenge_index': '0'})[2])['id'], project)
        state = json.loads(client.request('/workspace/data?id=' + str(project))[2])
        self.assertIn('main.py', state['files'])
        self.assertIn('request.json', state['files'])
        self.assertIn('housing.csv', state['files'])
        source = client.request('/dashboard/workspace/' + str(project))[2]
        self.assertIn('data-ws-action="assess"', source)
        self.assertIn('معیارهای موفقیت', source)
        self.assertIn('data-ws-assessment', source)
        self.assertIn('ادامه در میزکار', client.request('/dashboard/challenges')[2])
        other = test_release.Client(self.base)
        other.request('/register')
        other.post('/auth/request', {'first_name': 'هنرجو', 'last_name': 'دیگر', 'phone': '09990000874', 'password': 'FixturePass123'})
        self.assertNotEqual(other.request('/workspace/data?id=' + str(project))[0], 200)
        self.assertNotIn('ادامه در میزکار', other.request('/dashboard/challenges')[2])
        self.assertEqual(client.post('/workspace/delete', {'id': project, 'revision': state['revision']})[0], 200)
        self.assertIn('بازیابی از سطل بازیافت', client.request('/dashboard/challenges')[2])
        self.assertEqual(client.post('/workspace/restore', {'id': project, 'revision': state['revision'] + 1})[0], 200)
        self.assertIn('ادامه در میزکار', client.request('/dashboard/challenges')[2])

    @unittest.skipUnless(shutil.which('php'), 'PHP CLI required')
    def test_weekly_challenge_is_stable_and_rolls_on_monday_utc(self):
        code = 'require ' + json.dumps(str(ROOT / 'src/data.php')) + '; require ' + json.dumps(str(ROOT / 'src/learning_projects.php')) + ';'
        code += "echo json_encode(array_map(fn($date)=>lp_weekly_challenge(strtotime($date)),['2026-10-19 00:00:00 UTC','2026-10-25 23:59:59 UTC','2026-10-26 00:00:00 UTC','2027-01-04 00:00:00 UTC']));"
        first, last, following, next_year = json.loads(subprocess.check_output(['php', '-r', code], text=True))
        self.assertEqual(first, last)
        self.assertEqual(first['start'], '2026-10-19 00:00:00')
        self.assertEqual(first['end'], following['start'])
        self.assertNotEqual(first['index'], following['index'])
        self.assertEqual(next_year['start'], '2027-01-04 00:00:00')
        self.assertGreaterEqual(next_year['index'], 0)


if __name__ == '__main__':
    unittest.main()
