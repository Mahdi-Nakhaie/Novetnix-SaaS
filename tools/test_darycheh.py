import base64
import tempfile
import concurrent.futures
import json
import os
import pathlib
import re
import sqlite3
import subprocess
import unittest
import urllib.parse
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
        admin_migration = (ROOT / 'database/migrations/003_darycheh_admin.sqlite.sql').read_text()
        db.executescript(admin_migration)
        db.executescript(admin_migration)
        db.execute("INSERT INTO darycheh_drafts(definition_json,updated_by) VALUES ('{}',1)")
        for statement in ("UPDATE darycheh_drafts SET revision=0", "UPDATE darycheh_drafts SET updated_by=999", "UPDATE darycheh_drafts SET definition_json='invalid'"):
            with self.assertRaises(sqlite3.IntegrityError):
                db.execute(statement)
        product_migration = (ROOT / 'database/migrations/004_darycheh_products.sqlite.sql').read_text()
        db.executescript(product_migration)
        db.executescript(product_migration)
        db.execute('INSERT INTO projects(id,user_id,catalog_index) VALUES (1,1,0)')
        db.execute("INSERT INTO darycheh_product_requests(user_id,request_key,request_fingerprint,attempt_id,project_id) VALUES (1,'build-key','hash',1,1)")
        for statement in ("INSERT INTO darycheh_product_requests(user_id,request_key,request_fingerprint,attempt_id,project_id) VALUES (1,'build-key','hash',1,1)", 'UPDATE darycheh_product_requests SET attempt_id=999', 'UPDATE darycheh_product_requests SET project_id=999'):
            with self.assertRaises(sqlite3.IntegrityError):
                db.execute(statement)
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
 function(&$d){$d['steps']['report']['next']='intro';},
 function(&$d){$d['steps']['problem']['fields']['person']['kind']='multi';$d['steps']['problem']['fields']['person']['min']=1;$d['steps']['problem']['fields']['person']['max']=1;},
 function(&$d){$d['steps']['retry']['fields']['route']['options']['unsupported']='نامعتبر';},
 function(&$d){$d['steps']['evidence']['fields']['selected']['min']=0;},
 function(&$d){$d['steps']['solution']['fields']['order']['max']=4;},
 function(&$d){$d['skill_assessment']['problem_definition']['criteria'][0]['dimension']=[];},
 function(&$d){$d['operations']['duplicate']=[];},
 function(&$d){$d['hints']=['a'=>'one','b'=>'two','c'=>'three'];},
 function(&$d){$d['title']='';},
];
$out=[]; foreach($mutations as $mutation){$copy=$d;$mutation($copy);try{dr_validate_definition($copy);$out[]=false;}catch(DarychehError $e){$out[]=$e->status===409;}}
echo dr_json($out);
''')
        self.assertEqual(result, [True] * 16)

    def test_skill_score_missing_evidence_and_snapshot_compatibility(self):
        result = self.php('''
$empty=dr_skill_evaluation($d,[]); $criteria=[];
foreach($d['skill_assessment'] as $spec) foreach($spec['criteria'] as $ref) $criteria[$ref['dimension'].':'.$ref['key']]=dr_criterion($d,$ref['dimension'],$ref['key'],true);
$full=dr_skill_evaluation($d,array_values($criteria));
foreach($criteria as &$c) $c['score']=0; unset($c);
$zero=dr_skill_evaluation($d,array_values($criteria));
$old=$d; unset($old['skill_assessment']); dr_validate_definition($old);
$bad=$d; $bad['skill_assessment']['problem_definition']['criteria'][0]['key']='unknown'; $rejected=false;
try{dr_validate_definition($bad);}catch(DarychehError $e){$rejected=true;}
echo dr_json(['empty'=>$empty,'full'=>$full,'zero'=>$zero,'bad'=>$rejected]);
''')
        self.assertEqual(len(result['empty']), 9)
        self.assertTrue(all(s['score'] is None and s['status'] == 'not_assessed' for s in result['empty'].values()))
        self.assertTrue(all(s['score'] == 4 and s['level'] == 'strength' for s in result['full'].values()))
        self.assertTrue(all(s['score'] == 0 and s['level'] == 'practice' for s in result['zero'].values()))
        self.assertTrue(result['bad'])

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

    def start(self, client, scenario='first-real-problem'):
        status, _, body = client.post('/darycheh/start', {'request_key': key(), 'scenario_key': scenario})
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

    def main_path(self, client, order=None, help=False, evidence=None, problem=None, scenario='first-real-problem'):
        attempt = self.start(client, scenario)
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

    register = test_release.ReleaseHttpTest.register
    login = test_release.ReleaseHttpTest.login
    admin = test_release.ReleaseHttpTest.admin

    def definition(self):
        data = subprocess.check_output(['php', '-r', 'echo json_encode(require ' + json.dumps(str(ROOT / 'content/darycheh.php')) + ');'], text=True, timeout=15)
        definition = json.loads(data)
        definition['scenario_key'] = 'managed-' + key()
        definition['content_version'] = '1.0'
        definition['title'] = 'سناریوی مدیریت‌شده'
        self.addCleanup(self.change, "UPDATE darycheh_releases SET availability='retired' WHERE scenario_key=?", (definition['scenario_key'],))
        return definition

    def save_draft(self, admin, definition, draft=0, revision=0):
        response = admin.post('/darycheh/admin/save', {'id': draft, 'revision': revision, 'definition': json.dumps(definition, ensure_ascii=False)})
        self.assertEqual(response[0], 303, response[2])
        return int(response[1]['Location'].split('?id=')[1])

    def publish(self, admin, draft, revision=None):
        if revision is None:
            revision = self.rows('SELECT revision FROM darycheh_drafts WHERE id=?', (draft,))[0][0]
        response = admin.post('/darycheh/admin/publish', {'id': draft, 'revision': revision})
        self.assertEqual(response[0], 303, response[2])
        return admin.request(response[1]['Location'])[2]

    def test_admin_permissions_are_enforced_on_server_and_csrf(self):
        guest = test_release.Client(self.base)
        self.assertEqual(guest.request('/dashboard/darycheh-manage')[0], 303)
        member, phone = self.member()
        uid = self.rows('SELECT id FROM users WHERE phone=?', (phone,))[0][0]
        before = self.rows('SELECT COUNT(*) FROM darycheh_drafts')[0][0]
        for role in ('student', 'owner'):
            self.change('UPDATE users SET role=? WHERE id=?', (role, uid))
            self.assertEqual(member.request('/dashboard/darycheh-manage')[0], 403)
            for operation in ('save', 'publish', 'availability'):
                self.assertEqual(member.post('/darycheh/admin/' + operation, {'id': 0, 'definition': '{}'})[0], 403)
        self.assertEqual(self.rows('SELECT COUNT(*) FROM darycheh_drafts')[0][0], before)
        admin = self.admin()
        page = admin.request('/dashboard/darycheh-manage')
        self.assertEqual(page[0], 200)
        self.assertIn('مدیریت سناریوهای دریچه', page[2])
        self.assertIn('تعریف JSON', page[2])
        self.assertEqual(admin.request('/darycheh/admin/save', {'definition': '{}', 'csrf': 'wrong'})[0], 419)
        self.assertEqual(self.rows('SELECT COUNT(*) FROM darycheh_drafts')[0][0], before)

    def test_admin_draft_validation_preview_and_stale_writes(self):
        admin = self.admin()
        before = self.rows('SELECT COUNT(*) FROM darycheh_drafts')[0][0]
        for value in ('{broken', '[]', 'null', 'x' * 131073):
            self.assertEqual(admin.post('/darycheh/admin/save', {'definition': value})[0], 303)
        self.assertEqual(self.rows('SELECT COUNT(*) FROM darycheh_drafts')[0][0], before)
        draft = self.save_draft(admin, {'title': 'ناقص'})
        self.assertIn('انتشار مجاز نیست', self.publish(admin, draft))
        self.assertEqual(self.rows('SELECT revision FROM darycheh_drafts WHERE id=?', (draft,))[0][0], 1)
        definition = self.definition()
        self.save_draft(admin, definition, draft, 1)
        original = self.rows('SELECT definition_json,revision FROM darycheh_drafts WHERE id=?', (draft,))[0]
        self.save_draft(admin, {'title': 'stale'}, draft, 1)
        self.assertEqual(self.rows('SELECT definition_json,revision FROM darycheh_drafts WHERE id=?', (draft,))[0], original)
        self.publish(admin, draft, revision=1)
        self.assertEqual(self.rows('SELECT COUNT(*) FROM darycheh_releases WHERE scenario_key=?', (definition['scenario_key'],))[0][0], 0)
        counts = [self.rows('SELECT COUNT(*) FROM ' + table)[0][0] for table in ('darycheh_attempts', 'darycheh_evaluations')]
        page = admin.request('/dashboard/darycheh-manage?id=' + str(draft))[2]
        self.assertIn('پیش‌نمایش ساختاری معتبر', page)
        self.assertIn('انتشار همین بازبینی بررسی‌شده', page)
        self.assertEqual(counts, [self.rows('SELECT COUNT(*) FROM ' + table)[0][0] for table in ('darycheh_attempts', 'darycheh_evaluations')])
        broken = json.loads(json.dumps(definition))
        broken['steps']['intro']['next'] = 'missing'
        self.save_draft(admin, broken, draft, 2)
        self.assertIn('انتشار مجاز نیست', self.publish(admin, draft))
        self.assertEqual(self.rows('SELECT COUNT(*) FROM darycheh_releases WHERE scenario_key=?', (definition['scenario_key'],))[0][0], 0)

    def test_admin_invalid_definitions_cannot_be_published_or_activated(self):
        admin = self.admin()
        definition = self.definition()
        for field, value in (('steps', []), ('rubric', {'evidence': [], 'problem': []}), ('operations', []), ('objectives', {'extra': []}), ('skill_assessment', [None] * 9), ('title', '')):
            broken = dict(definition, **{field: value})
            draft = self.save_draft(admin, broken)
            page = self.publish(admin, draft)
            self.assertIn('انتشار مجاز نیست', page)
            self.assertNotIn('Fatal error', page)
            self.assertNotIn('Warning:', page)
        self.assertEqual(self.rows('SELECT COUNT(*) FROM darycheh_releases WHERE scenario_key=?', (definition['scenario_key'],))[0][0], 0)
        draft = self.save_draft(admin, definition)
        self.publish(admin, draft)
        release, saved = self.rows('SELECT id,definition_json FROM darycheh_releases WHERE scenario_key=?', (definition['scenario_key'],))[0]
        try:
            self.change("UPDATE darycheh_releases SET availability='retired',definition_json='{}' WHERE id=?", (release,))
            response = admin.post('/darycheh/admin/availability', {'id': release, 'availability': 'available'})
            self.assertEqual(response[0], 303)
            self.assertEqual(self.rows('SELECT availability FROM darycheh_releases WHERE id=?', (release,))[0][0], 'retired')
        finally:
            self.change('UPDATE darycheh_releases SET definition_json=? WHERE id=?', (saved, release))
        self.publish(admin, draft)
        self.assertEqual(self.rows('SELECT COUNT(*) FROM darycheh_releases WHERE scenario_key=?', (definition['scenario_key'],))[0][0], 1)
        self.assertEqual(self.rows('SELECT availability FROM darycheh_releases WHERE id=?', (release,))[0][0], 'retired')

    def test_admin_publish_execute_version_and_availability(self):
        admin = self.admin()
        definition = self.definition()
        definition['steps']['intro']['text'] = '<script>not executable</script>'
        draft = self.save_draft(admin, definition)
        page = self.publish(admin, draft)
        self.assertIn('نسخهٔ مستقل منتشر شد', page)
        self.assertNotIn('<script>not executable</script>', page)
        self.assertIn('&lt;script&gt;not executable&lt;/script&gt;', page)
        release, digest = self.rows('SELECT id,definition_hash FROM darycheh_releases WHERE scenario_key=?', (definition['scenario_key'],))[0]
        member, _ = self.member()
        landing = member.request('/dashboard/darycheh')[2]
        self.assertIn('name="scenario_key" value="' + definition['scenario_key'] + '"', landing)
        attempt = self.start(member, definition['scenario_key'])
        self.assertEqual(self.view(member, attempt)['content_version'], '1.0')
        definition['title'] = 'نسخهٔ اصلاح‌شده'
        revision = self.rows('SELECT revision FROM darycheh_drafts WHERE id=?', (draft,))[0][0]
        self.save_draft(admin, definition, draft, revision)
        self.assertIn('content_version', self.publish(admin, draft))
        self.assertEqual(self.rows('SELECT COUNT(*) FROM darycheh_releases WHERE scenario_key=?', (definition['scenario_key'],))[0][0], 1)
        definition['content_version'] = '2.0'
        revision = self.rows('SELECT revision FROM darycheh_drafts WHERE id=?', (draft,))[0][0]
        self.save_draft(admin, definition, draft, revision)
        self.publish(admin, draft)
        self.assertEqual(self.rows('SELECT definition_hash FROM darycheh_releases WHERE id=?', (release,))[0][0], digest)
        self.assertEqual(self.view(member, attempt)['content_version'], '1.0')
        newer, _ = self.member()
        self.assertEqual(self.view(newer, self.start(newer, definition['scenario_key']))['content_version'], '2.0')
        attempt = self.finish(member, self.enter_transfer(member, self.main_path(member, scenario=definition['scenario_key'])))
        self.assertEqual(attempt['status'], 'completed')
        report = member.request('/dashboard/darycheh/' + str(attempt['attempt_id']))[2]
        self.assertIn('name="scenario_key" value="' + definition['scenario_key'] + '"', report)
        running, _ = self.member()
        active = self.start(running, definition['scenario_key'])
        releases = self.rows('SELECT id FROM darycheh_releases WHERE scenario_key=?', (definition['scenario_key'],))
        for row in releases:
            self.assertEqual(admin.post('/darycheh/admin/availability', {'id': row[0], 'availability': 'retired'})[0], 303)
        fresh, _ = self.member()
        self.assertEqual(fresh.post('/darycheh/start', {'scenario_key': definition['scenario_key'], 'request_key': key()})[0], 409)
        self.assertEqual(self.act(running, active)['step'], 'evidence')
        self.assertEqual(self.start(running, definition['scenario_key'])['step'], 'evidence')
        self.assertEqual(admin.post('/darycheh/admin/availability', {'id': release, 'availability': 'invalid'})[0], 303)
        self.assertEqual(self.rows('SELECT availability FROM darycheh_releases WHERE id=?', (release,))[0][0], 'retired')
        self.assertEqual(admin.post('/darycheh/admin/availability', {'id': release, 'availability': 'available'})[0], 303)
        restarted = fresh.post('/darycheh/start', {'scenario_key': definition['scenario_key'], 'request_key': key()})
        self.assertEqual(restarted[0], 200, restarted[2])
        self.assertEqual(self.view(fresh, json.loads(restarted[2]))['content_version'], '1.0')
        restarted = member.post('/darycheh/restart', {'scenario_key': definition['scenario_key'], 'id': attempt['attempt_id'], 'revision': attempt['revision'], 'request_key': key(), '_html': '1'})
        self.assertEqual(restarted[0], 303)
        new_id = int(restarted[1]['Location'].rsplit('/', 1)[1])
        self.assertNotEqual(new_id, attempt['attempt_id'])
        self.assertEqual(self.rows('SELECT scenario_key FROM darycheh_attempts WHERE id=?', (new_id,))[0][0], definition['scenario_key'])
        self.assertEqual(self.rows('SELECT status FROM darycheh_attempts WHERE id=?', (attempt['attempt_id'],))[0][0], 'completed')

    def test_skill_progress_is_private_persistent_and_not_visit_based(self):
        client, phone = self.member()
        before = json.loads(client.request('/darycheh/progress')[2])
        self.assertTrue(all(h['last']['score'] is None for h in before['skill_groups']['unassessed'].values()))
        for _ in range(2):
            client.request('/dashboard/darycheh')
        self.assertEqual(before, json.loads(client.request('/darycheh/progress')[2]))
        attempt = self.finish(client, self.enter_transfer(client, self.main_path(client)))
        skills = self.view(client, attempt)['summary']['skills']
        self.assertEqual(len(skills), 9)
        self.assertTrue(all(s['score'] == 4 for s in skills.values()))
        progress = json.loads(client.request('/darycheh/progress')[2])
        self.assertEqual(len(progress['skill_groups']), 1)
        self.assertIn('دلیل این بازخورد', client.request('/dashboard/darycheh')[2])
        self.assertEqual(len(self.rows("SELECT id FROM darycheh_evaluations WHERE scope='summary' AND attempt_id=?", (attempt['attempt_id'],))), 1)
        client.post('/logout', {})
        test_release.ReleaseHttpTest.login(self, client, phone)
        self.assertEqual(progress, json.loads(client.request('/darycheh/progress')[2]))
        other, _ = self.member()
        private = json.loads(other.request('/darycheh/progress')[2])
        self.assertEqual(private['groups'], [])
        self.assertIn('unassessed', private['skill_groups'])
        self.assertEqual(other.request('/dashboard/darycheh/' + str(attempt['attempt_id']))[0], 404)

    def test_incomplete_stored_progress_does_not_invent_score(self):
        client, _ = self.member()
        attempt = self.finish(client, self.enter_transfer(client, self.main_path(client)))
        self.change("UPDATE darycheh_evaluations SET result_json='{}' WHERE attempt_id=?", (attempt['attempt_id'],))
        progress = json.loads(client.request('/darycheh/progress')[2])
        self.assertEqual(progress['groups'], [])
        self.assertTrue(all(h['last']['score'] is None for h in progress['skill_groups']['unassessed'].values()))

    def test_product_is_owned_runnable_and_revisable_in_existing_workspace(self):
        client, _ = self.member()
        attempt = self.start(client)
        def build(member, state):
            return member.post('/darycheh/build', {'id': state['attempt_id'], 'revision': state['revision'], 'request_key': key()})
        self.assertEqual(build(client, attempt)[0], 409)
        attempt = self.finish(client, self.enter_transfer(client, self.main_path(client)))
        other, _ = self.member()
        self.assertEqual(build(other, attempt)[0], 404)
        self.assertEqual(build(client, dict(attempt, revision=1))[0], 409)
        response = build(client, attempt)
        self.assertEqual(response[0], 200, response[2])
        project = json.loads(response[2])['project_id']
        files_json, revision = self.rows('SELECT files_json,revision FROM project_workspaces WHERE project_id=?', (project,))[0]
        files = json.loads(files_json)
        spec = json.loads(base64.b64decode(files['specification.json']['data']))
        self.assertEqual(spec['source_attempt'], attempt['attempt_id'])
        self.assertEqual(spec['decisions']['solution']['order'], ORDER)
        self.assertEqual(len(spec['acceptance_tests']), 4)
        self.assertNotIn('product-results.json', files)
        source = base64.b64decode(files['main.py']['data']).decode()
        with tempfile.TemporaryDirectory() as directory:
            script = pathlib.Path(directory) / 'main.py'
            script.write_text(source)
            good = subprocess.run(['python3', str(script)], cwd=directory, capture_output=True, timeout=10)
            self.assertEqual(good.returncode, 0, good.stderr)
            result = json.loads((pathlib.Path(directory) / 'product-results.json').read_text())
            self.assertTrue(result['passed'])
            broken = source.replace('for operation in ORDER:', 'for operation in ["reserve"]:')
            script.write_text(broken)
            bad = subprocess.run(['python3', str(script)], cwd=directory, capture_output=True, timeout=10)
            self.assertEqual(bad.returncode, 1)
            self.assertFalse(json.loads((pathlib.Path(directory) / 'product-results.json').read_text())['passed'])
        files['main.py']['data'] = base64.b64encode(broken.encode()).decode()
        saved = client.post('/workspace/save', {'id': project, 'revision': revision, 'files': json.dumps(files)})
        self.assertEqual(saved[0], 200, saved[2])
        self.assertEqual(client.post('/workspace/save', {'id': project, 'revision': revision, 'files': json.dumps(files)})[0], 409)
        self.assertEqual(other.request('/dashboard/workspace/' + str(project))[0], 404)
        self.assertIn('ساخت پروژهٔ عملی تازه', client.request('/dashboard/darycheh/' + str(attempt['attempt_id']))[2])
        self.assertEqual(self.view(client, attempt)['summary']['score'], 20)

    def test_audit_duplicate_product_request_is_atomic_and_idempotent(self):
        client, _ = self.member()
        attempt = self.finish(client, self.enter_transfer(client, self.main_path(client)))
        data = {'id': attempt['attempt_id'], 'revision': attempt['revision'], 'request_key': key()}
        first = client.post('/darycheh/build', data)
        second = client.post('/darycheh/build', data)
        self.assertEqual(first[0], 200, first[2])
        self.assertEqual(second[0], 200, second[2])
        self.assertEqual(json.loads(first[2]), json.loads(second[2]))
        response = client.post('/darycheh/build', dict(data, revision=1))
        self.assertEqual(response[0], 409)
        project = json.loads(first[2])['project_id']
        self.assertEqual(self.rows('SELECT COUNT(*) FROM project_workspaces w JOIN projects p ON p.id=w.project_id WHERE p.user_id=(SELECT user_id FROM projects WHERE id=?)', (project,))[0][0], 1)
        fresh = client.post('/darycheh/build', dict(data, request_key=key()))
        self.assertEqual(fresh[0], 200, fresh[2])
        self.assertNotEqual(json.loads(fresh[2])['project_id'], project)

    def test_audit_complete_journey_output_evaluation_and_relogin(self):
        client, phone = self.member()
        attempt = self.finish(client, self.enter_transfer(client, self.main_path(client)))
        before = self.view(client, attempt)
        built = client.post('/darycheh/build', {'id': attempt['attempt_id'], 'revision': attempt['revision'], 'request_key': key(), '_html': '1'})
        self.assertEqual(built[0], 303)
        workspace = built[1]['Location']
        self.assertTrue(workspace.startswith('/dashboard/workspace/'))
        self.assertEqual(client.request(workspace)[0], 200)
        project = int(workspace.rsplit('/', 1)[1])
        files = json.loads(self.rows('SELECT files_json FROM project_workspaces WHERE project_id=?', (project,))[0][0])
        with tempfile.TemporaryDirectory() as directory:
            script = pathlib.Path(directory) / 'main.py'
            script.write_bytes(base64.b64decode(files['main.py']['data']))
            result = subprocess.run(['python3', str(script)], cwd=directory, capture_output=True, timeout=10)
            self.assertEqual(result.returncode, 0, result.stderr)
            self.assertTrue(json.loads((pathlib.Path(directory) / 'product-results.json').read_text())['passed'])
        self.assertEqual(client.post('/logout', {})[0], 303)
        self.assertEqual(client.request(workspace)[0], 303)
        self.assertEqual(client.request('/darycheh/state?id=' + str(attempt['attempt_id']))[0], 401)
        self.assertEqual(self.login(client, phone)[0], 303)
        self.assertEqual(self.view(client, attempt), before)
        self.assertEqual(client.request(workspace)[0], 200)
        report = client.request('/dashboard/darycheh/' + str(attempt['attempt_id']))
        self.assertEqual(report[0], 200)
        self.assertIn('شاهد عملکرد مستقل', report[2])
        self.assertIn('no-store', report[1]['Cache-Control'])
        self.assertEqual(self.rows("SELECT COUNT(*) FROM darycheh_evaluations WHERE attempt_id=? AND scope='summary'", (attempt['attempt_id'],))[0][0], 1)

    def test_audit_session_expiry_and_role_revocation_take_effect(self):
        client, phone = self.member()
        attempt = self.act(client, self.start(client))
        self.assertEqual(client.request('/__fixture/expire')[0], 200)
        self.assertEqual(client.request('/darycheh/state?id=' + str(attempt['attempt_id']))[0], 401)
        self.assertEqual(client.post('/darycheh/act', {'id': attempt['attempt_id'], 'revision': attempt['revision'], 'request_key': key(), 'payload': '{}'})[0], 419)
        self.assertEqual(self.login(client, phone)[0], 303)
        self.assertEqual(self.view(client, attempt)['step'], 'evidence')
        admin = self.admin()
        uid = self.rows("SELECT id FROM users WHERE phone='09990000000'")[0][0]
        try:
            self.change("UPDATE users SET role='student' WHERE id=?", (uid,))
            self.assertEqual(admin.request('/dashboard/darycheh-manage')[0], 403)
            self.assertEqual(admin.post('/darycheh/admin/publish', {'id': 0, 'revision': 1})[0], 403)
        finally:
            self.change("UPDATE users SET role='admin' WHERE id=?", (uid,))

    def test_audit_untrusted_text_is_escaped_and_query_values_remain_data(self):
        admin = self.admin()
        definition = self.definition()
        marker = 'DR10 <b data-audit="text">متن</b> \'quoted\' ; --'
        definition['title'] = marker
        definition['steps']['intro']['text'] = marker
        definition['steps']['evidence']['fields']['selected']['options']['same_id'] = marker
        definition['objectives']['evidence'] = marker
        users = self.rows('SELECT COUNT(*) FROM users')[0][0]
        draft = self.save_draft(admin, definition)
        preview = self.publish(admin, draft)
        self.assertNotIn('<b data-audit="text">', preview)
        self.assertIn('&lt;b data-audit=', preview)
        self.assertEqual(self.rows('SELECT COUNT(*) FROM users')[0][0], users)
        self.assertEqual(json.loads(self.rows('SELECT definition_json FROM darycheh_releases WHERE scenario_key=?', (definition['scenario_key'],))[0][0])['title'], marker)
        client, _ = self.member()
        landing = client.request('/dashboard/darycheh')
        self.assertNotIn('<b data-audit="text">', landing[2])
        self.assertIn('&lt;b data-audit=', landing[2])
        self.assertIn("default-src 'self'", landing[1]['Content-Security-Policy'])
        attempt = self.start(client, definition['scenario_key'])
        attempt = self.act(client, attempt)
        rendered = client.request('/dashboard/darycheh/' + str(attempt['attempt_id']))[2]
        self.assertNotIn('<b data-audit="text">', rendered)
        self.assertIn('&lt;b data-audit=', rendered)
        self.act(client, attempt, {'selected': [marker] * 4}, status=422)
        self.assertEqual(client.post('/darycheh/start', {'scenario_key': "DR10' OR '1'='1", 'request_key': key()})[0], 404)

    def test_audit_identifier_types_and_cross_owner_product_replay(self):
        client, _ = self.member()
        other, _ = self.member()
        attempt = self.finish(client, self.enter_transfer(client, self.main_path(client)))
        data = {'id': attempt['attempt_id'], 'revision': attempt['revision'], 'request_key': key()}
        self.assertEqual(client.post('/darycheh/build', data)[0], 200)
        self.assertEqual(other.post('/darycheh/build', data)[0], 404)
        for value in ('0', '-1', '1.5', "1' OR '1'='1", '9' * 100):
            self.assertEqual(client.request('/darycheh/state?id=' + urllib.parse.quote(value))[0], 422)
            self.assertEqual(client.post('/darycheh/build', dict(data, id=value))[0], 422)
        self.assertEqual(client.request('/darycheh/state?id[]=1')[0], 422)
        self.assertEqual(client.post('/darycheh/build', {'id[]': '1', 'revision': 1, 'request_key': key()})[0], 422)
        self.assertEqual(client.post('/darycheh/start', {'scenario_key[]': 'test', 'request_key': key()})[0], 422)

    def test_audit_product_receipt_failure_rolls_back_and_can_retry(self):
        client, _ = self.member()
        attempt = self.finish(client, self.enter_transfer(client, self.main_path(client)))
        data = {'id': attempt['attempt_id'], 'revision': attempt['revision'], 'request_key': key()}
        before = self.rows('SELECT COUNT(*) FROM projects')[0][0]
        self.change("CREATE TRIGGER fixture_fail_product BEFORE INSERT ON darycheh_product_requests BEGIN SELECT RAISE(ABORT,'fixture failure'); END")
        try:
            response = client.post('/darycheh/build', data)
            self.assertEqual(response[0], 503)
            self.assertNotIn('PDOException', response[2])
            self.assertEqual(self.rows('SELECT COUNT(*) FROM projects')[0][0], before)
            self.assertEqual(self.rows('SELECT COUNT(*) FROM darycheh_product_requests WHERE request_key=?', (data['request_key'],))[0][0], 0)
        finally:
            self.change('DROP TRIGGER fixture_fail_product')
        self.assertEqual(client.post('/darycheh/build', data)[0], 200)

    def test_audit_concurrent_product_requests_create_one_workspace(self):
        client, _ = self.member()
        attempt = self.finish(client, self.enter_transfer(client, self.main_path(client)))
        uid = self.rows('SELECT user_id FROM darycheh_attempts WHERE id=?', (attempt['attempt_id'],))[0][0]
        request = key()
        code = 'require ' + json.dumps(str(ROOT / 'src/app.php')) + ';'
        code += f'echo dr_json(dr_build_product({uid},{attempt["attempt_id"]},{attempt["revision"]},"{request}"));'
        env = dict(os.environ, DB_DSN='sqlite:' + str(self.database))
        def run(_):
            return json.loads(subprocess.check_output(['php', '-r', code], env=env, text=True, timeout=20))
        with concurrent.futures.ThreadPoolExecutor(max_workers=2) as pool:
            projects = list(pool.map(run, range(2)))
        self.assertEqual(projects[0], projects[1])
        self.assertEqual(self.rows('SELECT COUNT(*) FROM projects WHERE user_id=?', (uid,))[0][0], 1)
        self.assertEqual(self.rows('SELECT COUNT(*) FROM darycheh_product_requests WHERE user_id=?', (uid,))[0][0], 1)

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
            self.assertEqual(other.post('/darycheh/start', {'request_key': key()})[0], 200)
            self.assertIn('اولین مسئله واقعی تو', other.request('/dashboard/darycheh')[2])
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

    def test_interface_uses_published_snapshot_and_rejects_corruption(self):
        client, _ = self.member()
        attempt = self.start(client)
        fresh, _ = self.member()
        release = self.rows('SELECT release_id FROM darycheh_attempts WHERE id=?', (attempt['attempt_id'],))[0][0]
        saved = self.rows('SELECT definition_json FROM darycheh_releases WHERE id=?', (release,))[0][0]
        content = ROOT / 'content/darycheh.php'
        original = content.read_bytes()
        try:
            content.write_text('<?php return [];')
            page = client.request('/dashboard/darycheh')
            self.assertEqual(page[0], 200)
            self.assertIn('اولین مسئله واقعی تو', page[2])
            self.change("UPDATE darycheh_releases SET definition_json='{}' WHERE id=?", (release,))
            malformed = fresh.request('/dashboard/darycheh')
            self.assertIn('این سناریو فعلاً قابل بارگذاری نیست', malformed[2])
            self.assertIn('role="alert"', malformed[2])
        finally:
            content.write_bytes(original)
            self.change('UPDATE darycheh_releases SET definition_json=? WHERE id=?', (saved, release))

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
