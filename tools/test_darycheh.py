import concurrent.futures
import json
import os
import pathlib
import re
import sqlite3
import subprocess
import unittest
import uuid

from tools import test_release

ROOT = pathlib.Path(__file__).resolve().parent.parent
ORDER = ['duplicate', 'validate', 'capacity', 'reserve', 'remember']
EVIDENCE = {'selected': ['same_id', 'same_data', 'double_change', 'server_trace']}
PROBLEM = {'person': 'requester', 'cause': 'new_each_time', 'goal': 'once', 'limits': 'valid_capacity'}
PREDICTIONS = {'normal': 'reserved:2:1', 'repeat': 'replayed:2:1', 'full': 'rejected:1:0', 'invalid': 'rejected:3:0'}


def key():
    return uuid.uuid4().hex


class DarychehSchemaTests(unittest.TestCase):
    def test_migration_is_repeatable_and_constraints_are_real(self):
        db = sqlite3.connect(':memory:')
        db.execute('PRAGMA foreign_keys=ON')
        db.executescript((ROOT / 'database/schema.sqlite.sql').read_text())
        migration = (ROOT / 'database/migrations/002_darycheh.sqlite.sql').read_text()
        db.executescript(migration)
        db.executescript(migration)
        db.execute("INSERT INTO users(id,phone) VALUES (1,'09990000999')")
        db.execute("INSERT INTO darycheh_releases(id,scenario_key,content_version,definition_schema_version,engine_version,assessment_version,definition_hash,definition_json,availability) VALUES (1,'sample','1',1,'1','1','hash','{}','available')")
        sql = "INSERT INTO darycheh_attempts(user_id,release_id,scenario_key,attempt_no,start_request_key,start_fingerprint,status,active_slot,current_step_key,revision,state_json) VALUES (1,1,'sample',?,?,?,'in_progress',?,'intro',1,'{}')"
        db.execute(sql, (1, 'first', 'fingerprint', 1))
        for args in ((2, 'second', 'x', 1), (2, 'third', 'x', None)):
            with self.assertRaises(sqlite3.IntegrityError):
                db.execute(sql, args)
        with self.assertRaises(sqlite3.IntegrityError):
            db.execute("UPDATE darycheh_attempts SET release_id=999")
        self.assertEqual(db.execute('PRAGMA foreign_key_check').fetchall(), [])
        db.close()


class DarychehEngineTests(unittest.TestCase):
    def php(self, code):
        bootstrap = 'require ' + json.dumps(str(ROOT / 'src/darycheh_engine.php')) + ';'
        bootstrap += '$d=require ' + json.dumps(str(ROOT / 'content/darycheh.php')) + ';'
        return json.loads(subprocess.check_output(['php', '-r', bootstrap + code], text=True, timeout=15))

    def test_incomplete_definitions_fail_closed(self):
        result = self.php('''
$mutations=[
 function(&$d){unset($d['steps']['solution']);},
 function(&$d){$d['steps']['intro']['next']='missing';},
 function(&$d){$d['steps']['retry']['routes']='broken';},
 function(&$d){$d['tests'][0]['expected']='wrong';},
 function(&$d){unset($d['steps']['problem']['fields']['cause']);},
 function(&$d){$d['rubric']['evidence']='broken';},
 function(&$d){$d['engine_version']='unsupported';},
];
$out=[]; foreach($mutations as $mutation){$copy=$d;$mutation($copy);try{dr_validate_definition($copy);$out[]=false;}catch(DarychehError $e){$out[]=$e->status===409;}}
echo dr_json($out);
''')
        self.assertEqual(result, [True] * 7)

    def test_simulation_is_deterministic_and_replays_last_seat(self):
        result = self.php('''
dr_validate_definition($d);$order=array_keys($d['operations']);$out=[];
foreach($d['tests'] as $test){$a=dr_simulate($test,$order);$out[]=($a===dr_simulate($test,$order) && $a['passed']);}
$last=['capacity'=>1,'requests'=>[['LAST',1],['LAST',1]],'expected'=>'replayed:0:1'];
$out[]=dr_simulate($last,$order)['passed'];
$invalid=['capacity'=>3,'requests'=>[['ZERO',0],['ZERO',0]],'expected'=>'replayed:3:0'];
$out[]=dr_simulate($invalid,$order)['passed'];
echo dr_json($out);
''')
        self.assertEqual(result, [True] * 6)


class DarychehHttpTests(unittest.TestCase):
    counter = 300

    @classmethod
    def setUpClass(cls):
        test_release.ReleaseHttpTest.setUpClass()
        cls.base = test_release.ReleaseHttpTest.base
        cls.database = test_release.ReleaseHttpTest.database

    @classmethod
    def tearDownClass(cls):
        test_release.ReleaseHttpTest.tearDownClass()

    def rows(self, sql, params=()):
        with sqlite3.connect(self.database) as db:
            return db.execute(sql, params).fetchall()

    def change(self, sql, params=()):
        with sqlite3.connect(self.database) as db:
            db.execute(sql, params)

    def member(self):
        type(self).counter += 1
        phone = f'09990000{self.counter:03}'
        client = test_release.Client(self.base)
        test_release.ReleaseHttpTest.register(self, client, phone)
        client.request('/dashboard/darycheh')
        return client, phone

    def start(self, client):
        status, _, body = client.post('/darycheh/start', {'request_key': key()})
        self.assertEqual(status, 200, body)
        return json.loads(body)

    def view(self, client, attempt):
        status, _, body = client.request('/darycheh/state?id=' + str(attempt['attempt_id']))
        self.assertEqual(status, 200, body)
        return json.loads(body)

    def act(self, client, attempt, payload=None, action='submit', request_key=None, status=200):
        response = client.post('/darycheh/act', {'id': attempt['attempt_id'], 'revision': attempt['revision'], 'request_key': request_key or key(), 'action': action, 'payload': json.dumps(payload or {})})
        self.assertEqual(response[0], status, response[2])
        return json.loads(response[2])

    def main_path(self, client, order=None, help=False, evidence=None, problem=None):
        attempt = self.start(client)
        attempt = self.act(client, attempt)
        attempt = self.act(client, attempt, evidence or EVIDENCE)
        attempt = self.act(client, attempt, problem or PROBLEM)
        if help:
            attempt = self.act(client, attempt, action='hint')
        attempt = self.act(client, attempt, {'order': order or ORDER})
        attempt = self.act(client, attempt, PREDICTIONS)
        return attempt

    def enter_transfer(self, client, attempt):
        attempt = self.act(client, attempt)
        return self.act(client, attempt, {'route': 'continue'})

    def transfer(self, client, attempt, correct=True):
        bundle = self.view(client, attempt)['bundle']['key']
        answer = {'cause': 'duplicate', 'order': 'before', 'remaining': '1' if bundle == 'tools' else '3', 'test': 'invalid'}
        if not correct:
            answer = {'cause': 'color', 'order': 'after', 'remaining': '0', 'test': 'font'}
        return self.act(client, attempt, answer)

    def finish(self, client, attempt):
        attempt = self.transfer(client, attempt)
        return self.act(client, attempt, {'route': 'finish'})

    def test_valid_complete_independent_and_terminal_guard(self):
        client, _ = self.member()
        attempt = self.main_path(client)
        state = self.view(client, attempt)
        self.assertEqual(state['step'], 'feedback')
        self.assertTrue(state['main']['passed'])
        self.assertEqual(state['main']['score'], 16)
        before_final = self.transfer(client, self.enter_transfer(client, attempt))
        final_key = key()
        attempt = self.act(client, before_final, {'route': 'finish'}, request_key=final_key)
        self.assertEqual(attempt, self.act(client, before_final, {'route': 'finish'}, request_key=final_key))
        state = self.view(client, attempt)
        self.assertEqual(state['status'], 'completed')
        self.assertEqual(state['summary']['ending'], 'independent')
        self.assertEqual(state['summary']['score'], 20)
        self.act(client, attempt, status=409)
        self.assertEqual(len(self.rows("SELECT id FROM activities WHERE event_name='darycheh_completed' AND user_id=(SELECT user_id FROM darycheh_attempts WHERE id=?)", (attempt['attempt_id'],))), 1)
        page = client.request('/dashboard/darycheh/' + str(attempt['attempt_id']))
        self.assertEqual(page[0], 200)
        self.assertIn('شاهد عملکرد مستقل', page[2])
        self.assertEqual(json.loads(client.request('/darycheh/progress')[2])['groups'].__len__(), 3)

    def test_wrong_branch_retry_and_supported_ending(self):
        client, _ = self.member()
        attempt = self.main_path(client, ['validate', 'capacity', 'reserve', 'remember'])
        self.assertFalse(self.view(client, attempt)['main']['passed'])
        attempt = self.act(client, attempt)
        self.assertNotIn('continue', self.view(client, attempt)['stage']['fields']['route']['options'])
        self.act(client, attempt, {'route': 'continue'}, status=409)
        attempt = self.act(client, attempt, {'route': 'revise'})
        attempt = self.act(client, attempt, action='hint')
        attempt = self.act(client, attempt, {'order': ORDER})
        attempt = self.act(client, attempt, PREDICTIONS)
        attempt = self.finish(client, self.enter_transfer(client, attempt))
        self.assertEqual(self.view(client, attempt)['summary']['ending'], 'supported')
        evaluations = self.rows("SELECT result_json FROM darycheh_evaluations WHERE attempt_id=? AND scope='main' ORDER BY id", (attempt['attempt_id'],))
        self.assertEqual(len(evaluations), 2)
        self.assertFalse(json.loads(evaluations[0][0])['passed'])
        self.assertTrue(json.loads(evaluations[1][0])['passed'])

    def test_low_dimension_never_passes_on_total_only(self):
        client, _ = self.member()
        problem = dict(PROBLEM, person='designer', cause='careless')
        attempt = self.main_path(client, problem=problem)
        attempt = self.finish(client, self.enter_transfer(client, attempt))
        summary = self.view(client, attempt)['summary']
        self.assertEqual(summary['score'], 18)
        self.assertEqual(summary['ending'], 'needs_practice')
        self.assertFalse(summary['passed'])

    def test_invalid_choice_missing_data_and_arbitrary_payload(self):
        client, _ = self.member()
        attempt = self.act(client, self.start(client))
        count = self.rows('SELECT COUNT(*) FROM darycheh_actions WHERE attempt_id=?', (attempt['attempt_id'],))[0][0]
        for payload in ({}, {'selected': ['bad'] * 4}, {'selected': EVIDENCE['selected'], 'sql': 'DELETE FROM users'}, {'selected': {'x': 'same_id'}}):
            self.act(client, attempt, payload, status=422)
        self.assertEqual(self.rows('SELECT COUNT(*) FROM darycheh_actions WHERE attempt_id=?', (attempt['attempt_id'],))[0][0], count)
        for raw in ('{broken', 'x' * 8193):
            response = client.post('/darycheh/act', {'id': attempt['attempt_id'], 'revision': attempt['revision'], 'request_key': key(), 'payload': raw})
            self.assertEqual(response[0], 422)
        attempt = self.act(client, attempt, EVIDENCE)
        attempt = self.act(client, attempt, PROBLEM)
        self.act(client, attempt, {'order': ['reserve', 'reserve']}, status=422)
        self.act(client, attempt, {'order': ['eval']}, status=422)
        self.assertEqual(self.view(client, attempt)['step'], 'solution')

    def test_duplicate_stale_and_completed_request_replay(self):
        client, _ = self.member()
        request = key()
        start_data = {'request_key': request}
        first = json.loads(client.post('/darycheh/start', start_data)[2])
        second = json.loads(client.post('/darycheh/start', start_data)[2])
        self.assertEqual(first, second)
        request = key()
        advanced = self.act(client, first, request_key=request)
        self.assertEqual(advanced, self.act(client, first, request_key=request))
        self.act(client, first, request_key=request, action='hint', status=409)
        self.act(client, first, status=409)
        self.assertEqual(self.rows('SELECT COUNT(*) FROM darycheh_actions WHERE attempt_id=?', (first['attempt_id'],))[0][0], 2)
        self.assertEqual(json.loads(client.post('/darycheh/start', start_data)[2]), first)

    def test_logout_login_resume_and_safe_return_destination(self):
        client, phone = self.member()
        attempt = self.main_path(client)
        before = self.view(client, attempt)
        self.assertEqual(client.post('/logout', {})[0], 303)
        self.assertEqual(client.request('/darycheh/state?id=' + str(attempt['attempt_id']))[0], 401)
        client.request('/login?next=dashboard/darycheh/' + str(attempt['attempt_id']))
        login = test_release.ReleaseHttpTest.login(self, client, phone)
        self.assertEqual(login[0], 303)
        self.assertEqual(login[1].get('Location'), '/dashboard/darycheh/' + str(attempt['attempt_id']))
        self.assertEqual(before, self.view(client, attempt))

    def test_ownership_and_csrf(self):
        client, _ = self.member()
        other, _ = self.member()
        attempt = self.start(client)
        for path in ('/darycheh/state?id=', '/dashboard/darycheh/'):
            self.assertEqual(other.request(path + str(attempt['attempt_id']))[0], 404)
        self.act(other, attempt, status=404)
        response = other.post('/darycheh/restart', {'id': attempt['attempt_id'], 'revision': attempt['revision'], 'request_key': key()})
        self.assertEqual(response[0], 404)
        self.assertEqual(client.request('/darycheh/act', {'id': attempt['attempt_id'], 'revision': 1, 'request_key': key(), 'csrf': 'invalid'})[0], 419)
        anonymous = test_release.Client(self.base)
        anonymous.request('/login')
        self.assertEqual(anonymous.post('/darycheh/start', {'request_key': key()})[0], 401)

    def test_restart_is_idempotent_and_preserves_history(self):
        client, _ = self.member()
        attempt = self.act(client, self.start(client))
        data = {'id': attempt['attempt_id'], 'revision': attempt['revision'], 'request_key': key()}
        first = json.loads(client.post('/darycheh/restart', data)[2])
        second = json.loads(client.post('/darycheh/restart', data)[2])
        self.assertEqual(first, second)
        self.assertNotEqual(first['attempt_id'], attempt['attempt_id'])
        self.assertEqual(self.view(client, attempt)['status'], 'abandoned')
        self.assertEqual(self.view(client, first)['attempt_no'], 2)
        self.assertEqual(self.rows("SELECT COUNT(*) FROM darycheh_attempts WHERE user_id=(SELECT user_id FROM darycheh_attempts WHERE id=?) AND active_slot=1", (first['attempt_id'],))[0][0], 1)

    def test_exposed_transfer_cannot_be_reset_into_independence(self):
        client, _ = self.member()
        attempt = self.enter_transfer(client, self.main_path(client))
        self.assertEqual(self.view(client, attempt)['bundle']['key'], 'tools')
        attempt = self.transfer(client, attempt, correct=False)
        attempt = self.act(client, attempt, {'route': 'retry'})
        self.assertEqual(self.view(client, attempt)['bundle']['key'], 'books')
        attempt = self.transfer(client, attempt, correct=False)
        attempt = self.act(client, attempt, {'route': 'retry'})
        self.assertTrue(self.view(client, attempt)['bundle']['previously_exposed'])
        attempt = self.finish(client, attempt)
        self.assertEqual(self.view(client, attempt)['summary']['ending'], 'needs_fresh_evidence')
        response = client.post('/darycheh/restart', {'id': attempt['attempt_id'], 'revision': attempt['revision'], 'request_key': key()})
        self.assertEqual(response[0], 200)
        fresh = self.enter_transfer(client, self.main_path(client))
        self.assertTrue(self.view(client, fresh)['bundle']['previously_exposed'])

    def test_missing_scenario_snapshot_and_corrupt_checkpoint(self):
        client, _ = self.member()
        attempt = self.start(client)
        self.assertEqual(client.post('/darycheh/start', {'scenario_key': 'deleted', 'request_key': key()})[0], 404)
        original = self.rows('SELECT current_step_key,state_json FROM darycheh_attempts WHERE id=?', (attempt['attempt_id'],))[0]
        for step, state in (('missing', original[1]), ('intro', '{}')):
            self.change('UPDATE darycheh_attempts SET current_step_key=?,state_json=? WHERE id=?', (step, state, attempt['attempt_id']))
            self.assertEqual(client.request('/darycheh/state?id=' + str(attempt['attempt_id']))[0], 409)
        self.change('UPDATE darycheh_attempts SET current_step_key=?,state_json=? WHERE id=?', (*original, attempt['attempt_id']))
        release = self.rows('SELECT release_id FROM darycheh_attempts WHERE id=?', (attempt['attempt_id'],))[0][0]
        content = self.rows('SELECT definition_json,definition_hash FROM darycheh_releases WHERE id=?', (release,))[0]
        try:
            self.change('UPDATE darycheh_releases SET definition_json=? WHERE id=?', ('{}', release))
            self.assertEqual(client.request('/darycheh/state?id=' + str(attempt['attempt_id']))[0], 409)
        finally:
            self.change('UPDATE darycheh_releases SET definition_json=?,definition_hash=? WHERE id=?', (*content, release))
        self.assertEqual(self.view(client, attempt)['step'], 'intro')

    def test_retired_release_can_resume_but_not_start(self):
        client, _ = self.member()
        attempt = self.start(client)
        release = self.rows('SELECT release_id FROM darycheh_attempts WHERE id=?', (attempt['attempt_id'],))[0][0]
        other, _ = self.member()
        try:
            self.change("UPDATE darycheh_releases SET availability='retired' WHERE id=?", (release,))
            self.assertEqual(self.act(client, attempt)['step'], 'evidence')
            self.assertEqual(self.start(client)['step'], 'evidence')
            self.assertEqual(other.post('/darycheh/start', {'request_key': key()})[0], 409)
            self.assertIn('هنوز سناریویی برای شروع در دسترس نیست', other.request('/dashboard/darycheh')[2])
            self.assertIn('ادامهٔ تجربهٔ ذخیره‌شده', client.request('/dashboard/darycheh')[2])
        finally:
            self.change("UPDATE darycheh_releases SET availability='available' WHERE id=?", (release,))

    def test_deleted_source_does_not_destroy_existing_snapshot(self):
        client, _ = self.member()
        attempt = self.start(client)
        content = ROOT / 'content/darycheh.php'
        moved = content.with_suffix('.fixture-backup')
        content.rename(moved)
        try:
            self.assertEqual(self.act(client, attempt)['step'], 'evidence')
            self.assertEqual(self.start(client)['step'], 'evidence')
            other, _ = self.member()
            self.assertEqual(other.post('/darycheh/start', {'request_key': key()})[0], 404)
        finally:
            moved.rename(content)

    def test_resume_request_remains_idempotent_after_completion(self):
        client, _ = self.member()
        self.start(client)
        resume_data = {'request_key': key()}
        resumed = json.loads(client.post('/darycheh/start', resume_data)[2])
        attempt = self.main_path(client)
        attempt = self.finish(client, self.enter_transfer(client, attempt))
        replay = json.loads(client.post('/darycheh/start', resume_data)[2])
        self.assertEqual(replay, resumed)
        self.assertEqual(self.rows('SELECT COUNT(*) FROM darycheh_attempts WHERE user_id=(SELECT user_id FROM darycheh_attempts WHERE id=?)', (attempt['attempt_id'],))[0][0], 1)

    def test_server_rendered_forms_complete_without_javascript(self):
        client, _ = self.member()
        page = client.request('/dashboard/darycheh')[2]
        token = re.search(r'name="request_key" value="([^"]+)"', page)[1]
        response = client.post('/darycheh/start', {'request_key': token, '_html': '1'})
        self.assertEqual(response[0], 303)
        location = response[1].get('Location')
        attempt_id = int(location.rsplit('/', 1)[1])
        payloads = [{}, EVIDENCE, PROBLEM, {'order': ORDER}, PREDICTIONS, {}, {'route': 'continue'}, {'cause': 'duplicate', 'order': 'before', 'remaining': '1', 'test': 'invalid'}, {'route': 'finish'}]
        for payload in payloads:
            status, _, page = client.request(location)
            self.assertEqual(status, 200)
            self.assertNotIn('PDOException', page)
            ids = re.findall(r'\bid="([^"]+)"', page)
            self.assertEqual(len(ids), len(set(ids)), 'IDs must be unique at every stage')
            for targets in re.findall(r'aria-(?:describedby|labelledby)="([^"]+)"', page):
                for target in targets.split():
                    if target.startswith('dr-'):
                        self.assertIn(target, ids)
            state = self.view(client, {'attempt_id': attempt_id})
            token = re.search(r'name="request_key" value="([^"]+)"', page)[1]
            data = {'id': attempt_id, 'revision': state['revision'], 'request_key': token, '_html': '1'}
            for field, value in payload.items():
                data['payload[' + field + ']' + ('[]' if isinstance(value, list) else '')] = value
            response = client.post('/darycheh/act', data)
            self.assertEqual(response[0], 303, response[2])
            self.assertEqual(response[1].get('Location'), location)
        self.assertIn('شاهد عملکرد مستقل', client.request(location)[2])

    def test_interface_assets_journey_resume_and_feedback(self):
        client, _ = self.member()
        home = client.request('/dashboard/darycheh')[2]
        self.assertIn('دریچه؛ فراتر از یادگیری، به‌سوی توانایی ساختن.', home)
        self.assertIn('اولین مسئله واقعی تو', home)
        self.assertIn('کسب‌وکار کوچک', home)
        self.assertNotIn('چرا یک کلیک، دو رزرو ساخته است؟', home)
        self.assertIn('class="dr-portal" aria-hidden="true"', home)
        self.assertIn('/assets/darycheh.css?v=1', home)
        self.assertIn('/assets/darycheh.js?v=1', home)
        self.assertIn('هنوز قدم اول را ثبت نکرده‌ای.', home)
        self.assertNotIn('/assets/darycheh.', client.request('/dashboard')[2])
        attempt = self.start(client)
        location = '/dashboard/darycheh/' + str(attempt['attempt_id'])
        home = client.request('/dashboard/darycheh')[2]
        self.assertIn('href="' + location + '"', home)
        self.assertIn('ادامهٔ تجربهٔ ذخیره‌شده', home)
        page = client.request(location)[2]
        self.assertEqual(page.count('aria-current="step"'), 1)
        steps = re.search(r'<ol class="dr-steps">(.*?)</ol>', page)[1]
        self.assertEqual(steps.count('<li'), 8)
        self.assertIn('id="dr-save-status"', page)
        self.assertIn('id="dr-form-error"', page)
        self.assertIn('id="dr-content" tabindex="-1"', page)
        attempt = self.main_path(client)
        page = client.request(location)[2]
        self.assertEqual(page.count('class="dr-trace"'), 4)
        self.assertEqual(page.count('پیش‌بینی تو:'), 4)
        self.assertIn('بررسی سابقه', page)
        self.assertIn('ظرفیت نهایی', page)
        attempt = self.finish(client, self.enter_transfer(client, attempt))
        page = client.request(location)[2]
        self.assertEqual(page.count('<meter '), 5)
        self.assertEqual(page.count('max="4" value="4"'), 5)
        self.assertNotIn('aria-current="step"', page)
        self.assertIn('تجربه پایان یافته', page)
        self.assertNotIn('id="dr-edit-status"', page)

    def test_interface_error_and_owned_invalid_html_return(self):
        client, _ = self.member()
        missing = client.request('/dashboard/darycheh/999999')
        self.assertEqual(missing[0], 404)
        self.assertIn('تجربه پیدا نشد', missing[2])
        self.assertIn('role="alert"', missing[2])
        attempt = self.act(client, self.start(client))
        location = '/dashboard/darycheh/' + str(attempt['attempt_id'])
        page = client.request(location)[2]
        self.assertEqual(page.count('type="checkbox"'), 5)
        response = client.post('/darycheh/act', {'id': attempt['attempt_id'], 'revision': attempt['revision'], 'request_key': key(), '_html': '1', 'payload[selected][]': ['same_id']})
        self.assertEqual(response[0], 303)
        self.assertEqual(response[1].get('Location'), location)
        self.assertEqual(self.view(client, attempt)['step'], 'evidence')
        other, _ = self.member()
        self.assertEqual(other.request(location)[0], 404)
        self.change("UPDATE darycheh_attempts SET state_json='{}' WHERE id=?", (attempt['attempt_id'],))
        corrupt = client.request(location)
        self.assertEqual(corrupt[0], 409)
        self.assertIn('بارگذاری دوباره', corrupt[2])
        self.assertNotIn('PDOException', corrupt[2])

    def test_interface_unavailable_and_malformed_source(self):
        client, _ = self.member()
        content = ROOT / 'content/darycheh.php'
        original = content.read_bytes()
        moved = content.with_suffix('.ui-fixture-backup')
        content.rename(moved)
        try:
            empty = client.request('/dashboard/darycheh')
            self.assertEqual(empty[0], 200)
            self.assertIn('هنوز سناریویی برای شروع در دسترس نیست', empty[2])
            self.assertNotIn('ورود به اولین تجربه', empty[2])
            content.write_text('<?php return [];')
            malformed = client.request('/dashboard/darycheh')
            self.assertIn('این سناریو فعلاً قابل بارگذاری نیست', malformed[2])
            self.assertIn('role="alert"', malformed[2])
        finally:
            content.write_bytes(original)
            moved.unlink()

    def test_database_failure_rolls_back_action_state_and_evaluation(self):
        client, _ = self.member()
        attempt = self.start(client)
        for payload in ({}, EVIDENCE, PROBLEM, {'order': ORDER}):
            attempt = self.act(client, attempt, payload)
        before = self.view(client, attempt)
        self.change("CREATE TRIGGER fixture_fail_evaluation BEFORE INSERT ON darycheh_evaluations BEGIN SELECT RAISE(ABORT,'fixture failure'); END")
        try:
            response = client.post('/darycheh/act', {'id': attempt['attempt_id'], 'revision': attempt['revision'], 'request_key': key(), 'payload': json.dumps(PREDICTIONS)})
            self.assertEqual(response[0], 503)
            self.assertEqual(self.view(client, attempt), before)
            self.assertEqual(self.rows('SELECT COUNT(*) FROM darycheh_evaluations WHERE attempt_id=?', (attempt['attempt_id'],))[0][0], 0)
        finally:
            self.change('DROP TRIGGER fixture_fail_evaluation')

    def test_concurrent_duplicate_is_applied_once(self):
        client, _ = self.member()
        attempt = self.start(client)
        uid = self.rows('SELECT user_id FROM darycheh_attempts WHERE id=?', (attempt['attempt_id'],))[0][0]
        request = key()
        code = 'require ' + json.dumps(str(ROOT / 'src/app.php')) + ';'
        code += f'echo dr_json(dr_act({uid},{attempt["attempt_id"]},{attempt["revision"]},"{request}","submit",[]));'
        env = dict(os.environ, DB_DSN='sqlite:' + str(self.database))
        def run(_):
            return json.loads(subprocess.check_output(['php', '-r', code], env=env, text=True, timeout=20))
        with concurrent.futures.ThreadPoolExecutor(max_workers=4) as pool:
            results = list(pool.map(run, range(4)))
        self.assertTrue(all(result == results[0] for result in results))
        self.assertEqual(self.rows('SELECT COUNT(*) FROM darycheh_actions WHERE attempt_id=?', (attempt['attempt_id'],))[0][0], 2)
        self.assertEqual(self.view(client, attempt)['revision'], 2)


if __name__ == '__main__':
    unittest.main()
