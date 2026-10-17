import base64
import json
import os
from pathlib import Path
import shutil
import subprocess
import sys
import tempfile
import unittest

ROOT = Path(__file__).resolve().parents[1]
sys.path.insert(0, str(ROOT / 'curriculum'))
import grading
PHP = os.environ.get('PHP_BIN') or shutil.which('php')


def reference_executor(job, image, cancelled):
    with tempfile.TemporaryDirectory(dir=ROOT / 'storage/tooling/tmp') as directory:
        folder = Path(directory)
        for name, item in job['files'].items():
            if item['kind'] == 'file':
                path = folder / name
                path.parent.mkdir(parents=True, exist_ok=True)
                path.write_bytes(base64.b64decode(item['data']))
        result = subprocess.run([sys.executable, 'main.py'], cwd=folder, capture_output=True, text=True, timeout=25)
        return {'state': 'succeeded' if result.returncode == 0 else 'program_error', 'stdout': result.stdout, 'stderr': result.stderr,
                'files': {p.name: grading.pack(p.read_bytes()) for p in folder.iterdir() if p.is_file() and p.suffix in ('.json', '.csv', '.png')}}


class ReferenceTests(unittest.TestCase):
    def test_all_references(self):
        for definition in grading.definitions():
            with self.subTest(project=definition['key']):
                source = (ROOT / 'curriculum/solutions' / (definition['key']+'.py')).read_bytes()
                result = grading.grade({'id': definition['key'], 'files': {'main.py': grading.pack(source)}, 'assessment': {'key': definition['key'], 'version': grading.version()}}, reference_executor, None, lambda: False)
                self.assertTrue(result.get('assessment', {}).get('passed'), result)

    def test_wrong_output_fails(self):
        def wrong(job, image, cancelled):
            return {'state': 'succeeded', 'stdout': 'PASS', 'stderr': '', 'files': {'result.json': grading.pack('{}')}, 'assessment': {'passed': True}}
        for definition in grading.definitions():
            result = grading.grade({'id': definition['key'], 'files': {}, 'assessment': {'key': definition['key'], 'version': grading.version()}}, wrong, None, lambda: False)
            self.assertFalse(result['assessment']['passed'])


@unittest.skipUnless(PHP, 'PHP unavailable')
class CompletionTests(unittest.TestCase):
    def run_case(self, modification=''):
        with tempfile.TemporaryDirectory(dir=ROOT / 'storage/tooling/tmp') as directory:
            script = r'''
require '/project/src/app.php';
q("INSERT INTO users(phone,name,phone_verified_at) VALUES ('09990000111','Student',1),('09990000112','Other',1)");
$d=PROJECTS[0]; $id=ws_create(1,0,$d['title'],100,lp_starter($d));
$w=ws_owned(1,$id);
$result=['state'=>'succeeded','assessment'=>['key'=>$d['key'],'version'=>lp_version(),'passed'=>true,'checks'=>[['name'=>'case','passed'=>true,'execution_state'=>'succeeded']]]];
q("INSERT INTO workspace_runs(project_id,request_key,revision,entrypoint,snapshot_json,remote_id,state,result_json,assessment_key,assessment_version) VALUES (?, 'fixture', 1, 'main.py', ?, 'trusted-fixture', 'succeeded', ?, ?, ?)",[$id,$w['files_json'],json_encode($result,JSON_THROW_ON_ERROR),$d['key'],lp_version()]);
$run=ws_run_owned(1,1);
'''
            action = "echo json_encode([lp_grant($run,$result,1),lp_grant($run,$result,1),lp_xp(1),lp_xp(2),(int)q('SELECT COUNT(*) FROM activities')->fetchColumn()]);"
            proc = subprocess.run([PHP, '-r', script+modification+action], env=dict(os.environ, DB_DSN='sqlite:'+str(Path(directory)/'test.sqlite')), capture_output=True, text=True, timeout=20)
            self.assertEqual(proc.returncode, 0, proc.stderr)
            return json.loads(proc.stdout)

    def test_atomic_completion_and_repeat(self):
        self.assertEqual(self.run_case(), [True, False, 60, 0, 1])

    def test_stale_revision_fails(self):
        self.assertEqual(self.run_case("q('UPDATE project_workspaces SET revision=2');"), [False, False, 0, 0, 0])

    def test_wrong_assessment_version_fails(self):
        self.assertEqual(self.run_case("$result['assessment']['version']=str_repeat('0',64);"), [False, False, 0, 0, 0])

    def test_client_forged_result_not_persisted_fails(self):
        self.assertEqual(self.run_case("q(\"UPDATE workspace_runs SET result_json='{}'\");"), [False, False, 0, 0, 0])

    def test_wrong_project_result_fails(self):
        self.assertEqual(self.run_case("$result['assessment']['key']='other';"), [False, False, 0, 0, 0])


if __name__ == '__main__':
    unittest.main()
