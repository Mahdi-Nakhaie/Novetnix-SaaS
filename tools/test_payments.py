import concurrent.futures
import json
import os
from pathlib import Path
import shutil
import sqlite3
import subprocess
import tempfile
import unittest

ROOT = Path(__file__).resolve().parent.parent
PHP = os.environ.get('PHP_BIN') or shutil.which('php')
AUTHORITY = 'A' + '0' * 35


@unittest.skipUnless(PHP, 'PHP runtime unavailable')
class PaymentTests(unittest.TestCase):
    def setUp(self):
        self.tmp = tempfile.TemporaryDirectory(dir=ROOT / 'tools')
        self.path = Path(self.tmp.name) / 'payments.sqlite'
        with sqlite3.connect(self.path) as db:
            db.executescript((ROOT / 'database/schema.sqlite.sql').read_text())
            db.execute("INSERT INTO users(phone,name,phone_verified_at) VALUES ('09120000901','Buyer',1)")
            db.execute("INSERT INTO users(phone,name,phone_verified_at) VALUES ('09120000902','Other',1)")

    def tearDown(self):
        self.tmp.cleanup()

    def run_php(self, action, response=None, post=None, query=None):
        fixture = {'path': str(self.path), 'root': str(ROOT), 'response': response or {},
                   'post': post or {}, 'query': query or {}}
        script = r'''
namespace PaymentFixture;
use \PDO;
use \RuntimeException;
use \Throwable;
$f=json_decode(stream_get_contents(STDIN),true);
require $f['root'].'/src/data.php';
$db=new PDO('sqlite:'.$f['path']);
$db->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);
$db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE,PDO::FETCH_ASSOC);
$db->exec('PRAGMA foreign_keys=ON');
$db->exec('PRAGMA busy_timeout=10000');
$out=[];
function db() { return $GLOBALS['db']; }
function q($sql,$params=[]) { $s=db()->prepare($sql); $s->execute($params); return $s; }
function config_value($key,$default=null) { return ['PAYMENT_BASE_URL'=>'https://shop.example.test','ZARINPAL_MERCHANT_ID'=>'synthetic-test-merchant'][$key] ?? $default; }
function curl_init($url) { $GLOBALS['out']['url']=$url; return 1; }
function curl_setopt_array($handle,$options) { $GLOBALS['options']=$options; $GLOBALS['out']['payload']=json_decode($options[CURLOPT_POSTFIELDS],true); }
function curl_exec($handle) { if (!empty($GLOBALS['f']['response']['transport_failure'])) return false; $body=json_encode($GLOBALS['f']['response']); return ($GLOBALS['options'][CURLOPT_WRITEFUNCTION])(1,$body)===strlen($body); }
function curl_getinfo($handle,$key) { return 200; }
function curl_close($handle) {}
function header($header) { $GLOBALS['out']['header']=$header; }
function flash($message,$kind='success') { $GLOBALS['out']['message']=$message; }
function redirect($path) { $GLOBALS['out']['redirect']=$path; exit; }
register_shutdown_function(function() { echo json_encode($GLOBALS['out']); });
$source=file_get_contents($f['root'].'/src/payments.php');
$source=preg_replace('/^<\?php\s*declare\(strict_types=1\);/','',$source);
eval('namespace PaymentFixture; use \\PDO; use \\Throwable; use \\RuntimeException; '.$source);
$_POST=$f['post']; $_GET=$f['query'];
'''
        proc = subprocess.run([PHP, '-r', script + '\n' + action], input=json.dumps(fixture),
                              text=True, capture_output=True, timeout=30)
        self.assertEqual(proc.returncode, 0, proc.stderr)
        return json.loads(proc.stdout)

    def order(self, key='a' * 32, authority=AUTHORITY):
        with sqlite3.connect(self.path) as db:
            cursor = db.execute("INSERT INTO payment_orders(user_id,plan,amount_rial,request_key,authority,status) VALUES (1,'bronze',1900000,?,?,'pending')", (key, authority))
            return cursor.lastrowid

    def scalar(self, sql):
        with sqlite3.connect(self.path) as db:
            return db.execute(sql).fetchone()[0]

    def test_request_uses_server_price_production_endpoint_and_persists_before_redirect(self):
        out = self.run_php("payment_start(['id'=>1,'phone'=>'09120000901']);",
                           {'data': {'code': 100, 'authority': AUTHORITY}},
                           {'plan': 'bronze', 'amount': '1', 'callback_url': 'https://invalid.test', 'request_key': 'a' * 32})
        self.assertEqual(out['url'], 'https://payment.zarinpal.com/pg/v4/payment/request.json')
        self.assertEqual(out['payload']['amount'], 1900000)
        self.assertEqual(out['payload']['currency'], 'IRR')
        self.assertEqual(out['payload']['callback_url'], 'https://shop.example.test/payment/zarinpal/callback')
        self.assertEqual(out['header'], 'Location: https://payment.zarinpal.com/pg/StartPay/' + AUTHORITY)
        self.assertEqual(self.scalar('SELECT authority FROM payment_orders'), AUTHORITY)
        self.assertEqual(self.scalar('SELECT COUNT(*) FROM subscriptions'), 0)
        repeat = self.run_php("payment_start(['id'=>1,'phone'=>'09120000901']);", post={'plan': 'gold', 'request_key': 'a' * 32})
        self.assertNotIn('payload', repeat)
        self.assertEqual(self.scalar('SELECT COUNT(*) FROM payment_orders'), 1)

    def test_request_transport_failure_does_not_redirect_to_gateway_or_grant(self):
        out = self.run_php("payment_start(['id'=>1,'phone'=>'09120000901']);",
                           {'transport_failure': True},
                           {'plan': 'bronze', 'request_key': 'a' * 32})
        self.assertNotIn('header', out)
        self.assertEqual(out['redirect'], 'dashboard/payment-history')
        self.assertEqual(self.scalar('SELECT status FROM payment_orders'), 'request_failed')
        self.assertEqual(self.scalar('SELECT COUNT(*) FROM subscriptions'), 0)

    def test_verification_transport_failure_keeps_order_recoverable(self):
        self.order()
        self.run_php('payment_callback();', {'transport_failure': True}, query={'Authority': AUTHORITY})
        self.assertEqual(self.scalar('SELECT status FROM payment_orders'), 'pending')
        self.assertEqual(self.scalar('SELECT COUNT(*) FROM subscriptions'), 0)
        self.run_php('payment_callback();', {'data': {'code': 101, 'ref_id': 123456}}, query={'Authority': AUTHORITY})
        self.assertEqual(self.scalar('SELECT status FROM payment_orders'), 'paid')

    def test_request_rate_limit_prevents_gateway_call(self):
        for i in range(5):
            self.order(str(i) * 32, 'A' + str(i) * 35)
        out = self.run_php("payment_start(['id'=>1,'phone'=>'09120000901']);",
                           post={'plan': 'bronze', 'request_key': 'f' * 32})
        self.assertNotIn('payload', out)
        self.assertEqual(self.scalar('SELECT COUNT(*) FROM payment_orders'), 5)

    def test_callback_status_alone_never_grants_and_uses_stored_amount(self):
        self.order()
        out = self.run_php('payment_callback();', {'data': {'code': -21}}, query={'Authority': AUTHORITY, 'Status': 'OK', 'amount': '1', 'user_id': '2'})
        self.assertEqual(out['payload']['amount'], 1900000)
        self.assertEqual(out['url'], 'https://payment.zarinpal.com/pg/v4/payment/verify.json')
        self.assertEqual(self.scalar('SELECT COUNT(*) FROM subscriptions'), 0)

    def test_verified_callback_is_idempotent_and_owned_by_order_not_query(self):
        self.order()
        out = self.run_php('payment_callback();', {'data': {'code': 100, 'ref_id': 123456}}, query={'Authority': AUTHORITY, 'Status': 'NOK', 'user_id': '2'})
        self.assertIn('فعال', out['message'])
        repeat = self.run_php('payment_callback();', query={'Authority': AUTHORITY, 'Status': 'OK'})
        self.assertNotIn('payload', repeat)
        self.assertEqual(self.scalar('SELECT COUNT(*) FROM subscriptions'), 1)
        self.assertEqual(self.scalar('SELECT user_id FROM subscriptions'), 1)
        self.assertEqual(self.scalar('SELECT status FROM payment_orders'), 'paid')

    def test_code_101_recovers_interrupted_local_activation(self):
        self.order()
        self.run_php('payment_callback();', {'data': {'code': 101, 'ref_id': 123456}}, query={'Authority': AUTHORITY})
        self.assertEqual(self.scalar('SELECT COUNT(*) FROM subscriptions'), 1)

    def test_missing_reference_and_malformed_authority_fail_closed(self):
        self.order()
        self.run_php('payment_callback();', {'data': {'code': 100}}, query={'Authority': AUTHORITY})
        self.assertEqual(self.scalar('SELECT COUNT(*) FROM subscriptions'), 0)
        out = self.run_php("$out['valid']=payment_authority('https://invalid.test');")
        self.assertFalse(out['valid'])

    def test_parallel_fulfillment_grants_once(self):
        order = self.order()
        action = f"$out['paid']=payment_grant({order},['code'=>100,'ref_id'=>123456]);"
        with concurrent.futures.ThreadPoolExecutor(max_workers=4) as pool:
            results = list(pool.map(lambda _: self.run_php(action), range(4)))
        self.assertTrue(all(r['paid'] for r in results))
        self.assertEqual(self.scalar('SELECT COUNT(*) FROM subscriptions'), 1)

    def test_same_plan_renewal_keeps_remaining_days(self):
        self.order()
        with sqlite3.connect(self.path) as db:
            db.execute("INSERT INTO subscriptions(user_id,plan,status,starts_at,expires_at) VALUES (1,'bronze','active',datetime('now'),datetime('now','+10 days'))")
        self.run_php("$out['paid']=payment_grant(1,['code'=>100,'ref_id'=>123456]);")
        self.assertGreater(self.scalar("SELECT julianday(MAX(expires_at))-julianday('now') FROM subscriptions"), 39.9)
        self.assertEqual(self.scalar("SELECT COUNT(*) FROM subscriptions WHERE status='active'"), 1)

    def test_reference_collision_rolls_back_subscription_changes(self):
        first = self.order()
        self.run_php(f"payment_grant({first},['code'=>100,'ref_id'=>123456]);")
        second = self.order('b' * 32, 'A' + '1' * 35)
        out = self.run_php(f"try {{ payment_grant({second},['code'=>100,'ref_id'=>123456]); }} catch (Throwable $e) {{ $out['rolled_back']=!db()->inTransaction(); }}")
        self.assertTrue(out['rolled_back'])
        self.assertEqual(self.scalar('SELECT COUNT(*) FROM subscriptions'), 1)
        self.assertEqual(self.scalar("SELECT COUNT(*) FROM subscriptions WHERE status='active'"), 1)


if __name__ == '__main__':
    unittest.main()
