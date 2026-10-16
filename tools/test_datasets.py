import json
import os
from pathlib import Path
import shutil
import subprocess
import unittest

ROOT = Path(__file__).resolve().parent.parent
PHP = shutil.which(os.environ.get('PHP_BIN', 'php'))


@unittest.skipUnless(PHP, 'PHP CLI is required')
class DatasetPolicyTests(unittest.TestCase):
    def php(self, code):
        result = subprocess.run([PHP, '-r', 'require ' + json.dumps(str(ROOT / 'src/workspace.php')) + ';' + code], capture_output=True, text=True, timeout=35)
        self.assertEqual(result.returncode, 0, result.stderr)
        return result.stdout.strip()

    def test_ids_destinations_and_public_access(self):
        output = self.php('''
        $bad=['../iris','https://example.test/a','a/b/c','a/..','a%2fb','a//b','a\\\\b'];
        foreach ($bad as $id) { try { hf_id($id); throw new Exception('accepted invalid ID'); } catch (InvalidArgumentException $e) {} }
        foreach (['http://huggingface.co/api/datasets','https://127.0.0.1/api/x','https://huggingface.co.evil.test/api/x','https://user@huggingface.co/api/x','https://huggingface.co:443/api/x','https://huggingface.co/api/x#fragment','https://huggingface.co//evil.test/x','https://cdn-lfs.huggingface.co/api/x'] as $url) { try { hf_url($url); throw new Exception('accepted invalid destination'); } catch (InvalidArgumentException $e) {} }
        foreach ([['private'=>true,'gated'=>false],['private'=>false,'gated'=>'auto'],['private'=>false,'gated'=>'manual'],['private'=>false],['private'=>false,'gated'=>false,'disabled'=>true]] as $info) if (hf_public($info)) throw new Exception('non-public accepted');
        if (!hf_public(['private'=>false,'gated'=>false])) throw new Exception('public rejected');
        echo hf_id('scikit-learn/iris');
        ''')
        self.assertEqual(output, 'scikit-learn/iris')

    def test_checksum_size_invalid_encoding_and_json(self):
        self.assertEqual(self.php('''
        $raw="x,y\\n1,2\\n";
        $file=['path'=>'small.csv','size'=>strlen($raw),'hash'=>sha1('blob '.strlen($raw)."\\0".$raw),'algorithm'=>'git-sha1'];
        hf_validate_bytes($raw,$file);
        $sha=$file; $sha['algorithm']='sha256'; $sha['hash']=hash('sha256',$raw); hf_validate_bytes($raw,$sha);
        foreach ([$raw.'x',str_repeat('x',1048577),"\\0bad","\\xff"] as $bad) { try { hf_validate_bytes($bad,$file); throw new Exception('invalid file accepted'); } catch (InvalidArgumentException $e) {} }
        $file['hash']=str_repeat('0',40);
        try { hf_validate_bytes($raw,$file); throw new Exception('checksum mismatch accepted'); } catch (InvalidArgumentException $e) {}
        $raw='{bad json}'; $file=['path'=>'x.json','size'=>strlen($raw),'hash'=>hash('sha256',$raw),'algorithm'=>'sha256'];
        try { hf_validate_bytes($raw,$file); throw new Exception('invalid JSON accepted'); } catch (JsonException $e) {}
        echo 'PASS';
        '''), 'PASS')

    def test_unknown_license_is_not_invented(self):
        data = json.loads(self.php("echo json_encode(hf_summary(['id'=>'test/data','private'=>false,'gated'=>false]));"))
        self.assertIsNone(data['license'])
        self.assertIsNone(data['approximate_bytes'])

    def test_count_and_input_validation_before_network(self):
        self.assertEqual(self.php('''
        foreach ([[],array_fill(0,11,'x.csv'),['x.csv','x.csv'],[42]] as $paths) {
            try { hf_begin(1,1,'test/data',str_repeat('a',40),$paths); throw new Exception('invalid selection accepted'); } catch (InvalidArgumentException $e) {}
        }
        echo 'PASS';
        '''), 'PASS')

    @unittest.skipUnless(os.environ.get('NOVENTIX_HF_LIVE_TESTS') == '1', 'Live Hub tests are opt-in')
    def test_real_response_size_and_http_error(self):
        self.assertEqual(self.php('''
        try { hf_http('https://huggingface.co/api/datasets/scikit-learn/iris',1,10); throw new Exception('size limit not enforced'); } catch (RuntimeException|InvalidArgumentException $e) {}
        try { hf_api('/NoventixMissingPublicFixture000000000?blobs=true'); throw new Exception('HTTP error not handled'); } catch (RuntimeException $e) {}
        echo 'PASS';
        '''), 'PASS')
