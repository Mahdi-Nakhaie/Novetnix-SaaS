import contextlib
import importlib.util
import io
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
        for hook in ('data-ws-terminal-form', 'data-ws-command', 'data-ws-terminal-log', 'data-ws-runtime', 'workspace.css?v=3'):
            self.assertIn(hook, source)
        self.assertNotIn('python-runner.js', source)
        self.assertNotIn('pyodide', source)
        import json
        state = json.loads(client.request('/workspace/data?id=' + str(project))[2])
        self.assertFalse(state['runner_ready'])
        self.assertEqual(client.post('/workspace/run', {'id': project, 'revision': state['revision'], 'request_key': 'a' * 36, 'terminal_command': 'python main.py'})[0], 503)


if __name__ == '__main__':
    unittest.main()
