import pathlib
import sqlite3
import unittest

from tools import test_release

ROOT = pathlib.Path(__file__).resolve().parents[1]


class CommunityAccountsHttpTests(unittest.TestCase):
    rows = test_release.ReleaseHttpTest.rows
    register = test_release.ReleaseHttpTest.register
    login = test_release.ReleaseHttpTest.login
    admin = test_release.ReleaseHttpTest.admin

    @classmethod
    def setUpClass(cls):
        test_release.ReleaseHttpTest.setUpClass.__func__(cls)

    @classmethod
    def tearDownClass(cls):
        test_release.ReleaseHttpTest.tearDownClass.__func__(cls)

    def setUp(self):
        self.author = test_release.Client(self.base)
        self.other = test_release.Client(self.base)
        suffix = str(len(self.rows('SELECT id FROM users')) + 100)
        self.phone = '09991' + suffix.zfill(6)
        self.register(self.author, self.phone, 'نویسنده')
        self.register(self.other, '09992' + suffix.zfill(6), 'پاسخ‌دهنده')
        self.uid = self.rows('SELECT id FROM users WHERE phone=?', (self.phone,))[0][0]
        self.oid = self.rows('SELECT id FROM users ORDER BY id DESC LIMIT 1')[0][0]
        self.post = self.insert('community_posts', user_id=self.uid, title='پرسش نویسنده', body='متن پرسش برای آزمون انجمن')
        self.kept = self.insert('community_posts', user_id=self.oid, title='پست باقی‌مانده', body='این پست باید باقی بماند')
        self.insert('community_replies', post_id=self.post, user_id=self.oid, body='پاسخ نویسنده دوم')
        self.insert('community_replies', post_id=self.kept, user_id=self.uid, body='پاسخ حساب اول')

    def insert(self, table, **values):
        with sqlite3.connect(self.database) as connection:
            connection.execute('PRAGMA foreign_keys=ON')
            return connection.execute('INSERT INTO ' + table + '(' + ','.join(values) + ') VALUES (' + ','.join('?' for _ in values) + ')', tuple(values.values())).lastrowid

    def test_hearts_persist_and_toggle_in_both_feeds(self):
        for path in ('/community', '/dashboard/community'):
            text = self.author.request(path)[2]
            self.assertIn('class="heart-button" aria-pressed="false"', text)
            self.assertIn('href="/member/' + str(self.oid) + '"', text)
            self.assertNotIn('پسندیده‌اید', text)
        data = {'post_id': self.post, 'kind': 'like', 'active': '1', 'return_to': 'community'}
        for _ in range(2):
            status, headers, _ = self.author.post('/community/react', data)
            self.assertEqual((status, headers['Location']), (303, '/community'))
        self.assertEqual(self.rows("SELECT COUNT(*) FROM post_reactions WHERE post_id=? AND kind='like'", (self.post,))[0][0], 1)
        for path in ('/community', '/dashboard/community'):
            self.assertIn('class="heart-button is-liked" aria-pressed="true"', self.author.request(path)[2])
        for _ in range(2):
            self.assertEqual(self.author.post('/community/react', dict(data, active='0'))[0], 303)
        self.assertFalse(self.rows('SELECT * FROM post_reactions WHERE post_id=?', (self.post,)))
        self.assertEqual(self.author.post('/community/react', dict(data, active='bad'))[0], 422)
        self.assertEqual(self.author.post('/community/react', dict(data, return_to='https://invalid.example'))[1]['Location'], '/dashboard/community')
        self.assertIn('برای پسندیدن پست وارد شوید', test_release.Client(self.base).request('/community')[2])

    def test_post_deletion_authorization_confirmation_and_cleanup(self):
        data = {'post_id': self.post, 'confirm': 'delete', 'return_to': 'community'}
        self.assertEqual(self.other.post('/community/delete', data)[0], 404)
        self.assertEqual(self.author.post('/community/delete', {'post_id': self.post})[0], 422)
        self.assertEqual(self.author.request('/community/delete', data)[0], 419)
        self.assertNotEqual(self.author.request('/community/delete?post_id=' + str(self.post))[0], 303)
        self.assertTrue(self.rows('SELECT id FROM community_posts WHERE id=?', (self.post,)))
        self.author.post('/community/react', {'post_id': self.post, 'kind': 'like'})
        self.assertEqual(self.author.post('/community/delete', data)[1]['Location'], '/community')
        for table in ('community_replies', 'post_reactions'):
            self.assertFalse(self.rows('SELECT * FROM ' + table + ' WHERE post_id=?', (self.post,)))
        self.assertFalse(self.rows('SELECT id FROM community_posts WHERE id=?', (self.post,)))
        self.assertTrue(self.rows('SELECT id FROM community_posts WHERE id=?', (self.kept,)))
        admin = self.admin()
        self.assertEqual(admin.post('/community/delete', dict(data, post_id=self.kept))[0], 303)
        self.assertFalse(self.rows('SELECT id FROM community_posts WHERE id=?', (self.kept,)))

    def test_account_requires_password_phrase_csrf_and_disallows_admin(self):
        data = {'password': 'FixturePass123', 'confirmation': 'حذف حساب'}
        self.assertEqual(self.author.request('/account/delete', data)[0], 419)
        self.assertEqual(self.author.post('/account/delete', dict(data, password='wrong'))[0], 303)
        self.assertEqual(self.author.post('/account/delete', dict(data, confirmation='yes'))[0], 303)
        self.assertTrue(self.rows('SELECT id FROM users WHERE id=?', (self.uid,)))
        self.assertIn('name="confirmation"', self.author.request('/dashboard/profile')[2])
        admin = self.admin()
        self.assertEqual(admin.post('/account/delete', data)[0], 403)
        self.assertNotIn('name="confirmation"', admin.request('/dashboard/profile')[2])

    def seed_account_data(self, state='succeeded'):
        project = self.insert('projects', user_id=self.uid, catalog_index=0)
        self.insert('project_workspaces', project_id=project, title='test', files_json='{}', bytes=0)
        run = self.insert('workspace_runs', project_id=project, request_key='delete-test', revision=1, entrypoint='main.py', snapshot_json='{}', state=state)
        self.insert('project_completions', user_id=self.uid, learning_key='delete-test', project_id=project, run_id=run, revision=1, snapshot_hash='test', assessment_version='1', xp=10)
        self.insert('workspace_hub_rates', owner=self.uid, window_start=0, requests=1)
        self.insert('workspace_dataset_imports', id='delete-' + str(self.uid), project_id=project, owner=self.uid, dataset_id='test', revision='1', fingerprint='test', state='succeeded', files_json='[]', metadata_json='{}', bytes=1, created=0)
        sub = self.insert('subscriptions', user_id=self.uid, plan='pro', starts_at='2026-01-01', expires_at='2027-01-01')
        self.insert('payment_orders', user_id=self.uid, plan='pro', amount_rial=100, request_key='test', status='paid', subscription_id=sub)
        ticket = self.insert('tickets', user_id=self.uid, subject='test')
        self.insert('ticket_replies', ticket_id=ticket, user_id=self.oid, body='test')
        self.insert('post_reactions', post_id=self.post, user_id=self.oid, kind='like')
        self.insert('post_reactions', post_id=self.kept, user_id=self.uid, kind='save')
        self.insert('user_follows', follower_id=self.uid, followed_id=self.oid)
        self.insert('user_follows', follower_id=self.oid, followed_id=self.uid)
        self.insert('direct_messages', sender_id=self.oid, recipient_id=self.uid, entity_hash='test', body='test')
        self.insert('message_requests', sender_id=self.uid, recipient_id=self.oid)
        self.insert('enrollments', user_id=self.uid, course_slug='test')
        self.insert('nova_messages', user_id=self.uid, question='test', answer='test')
        self.insert('nova_usage', user_id=self.uid, month='2026-10', used=1)
        self.insert('challenge_progress', user_id=self.uid, challenge_index=0)
        self.insert('leads', name='test', phone=self.phone, source='test')
        self.insert('otp_requests', phone=self.phone, window_start=0, request_count=1, last_requested=0)
        self.insert('otp_codes', phone=self.phone, otp_hash='synthetic', expires_at=2, created_at=1)
        course = self.insert('courses', slug='delete-' + str(self.uid), title='test', description='test')
        lesson = self.insert('lessons', course_id=course, title='test', content='test', position=1)
        self.insert('lesson_progress', user_id=self.uid, lesson_id=lesson)
        release = self.insert('darycheh_releases', scenario_key='delete-' + str(self.uid), content_version='1', definition_schema_version=1, engine_version='1', assessment_version='1', definition_hash='delete-' + str(self.uid), definition_json='{}', availability='retired')
        attempt = self.insert('darycheh_attempts', user_id=self.uid, release_id=release, scenario_key='delete-' + str(self.uid), attempt_no=1, start_request_key='test', start_fingerprint='test', status='in_progress', active_slot=1, current_step_key='test', revision=1, state_json='{}')
        self.insert('darycheh_actions', attempt_id=attempt, seq=1, request_key='test', request_fingerprint='test', action_type='test', step_key='test', base_revision=1, result_revision=2, payload_json='{}', outcome_json='{}', result_state_hash='test')
        self.insert('darycheh_evaluations', attempt_id=attempt, source_action_seq=1, scope='main', round_no=1, assessment_hash='test', input_hash='test', evidence_json='{}', result_json='{}', score=1, max_score=1, passed=1, support_level='none', is_independent=1)
        self.insert('darycheh_product_requests', user_id=self.uid, request_key='test', request_fingerprint='test', attempt_id=attempt, project_id=project)
        return project

    def test_account_cleanup_preserves_other_user_and_invalidates_sessions(self):
        self.seed_account_data()
        second = test_release.Client(self.base)
        self.assertEqual(self.login(second, self.phone)[0], 303)
        status, headers, _ = self.author.post('/account/delete', {'password': 'FixturePass123', 'confirmation': 'حذف حساب', 'user_id': self.oid})
        self.assertEqual((status, headers['Location']), (303, '/login'))
        self.assertFalse(self.rows('SELECT id FROM users WHERE id=?', (self.uid,)))
        self.assertTrue(self.rows('SELECT id FROM users WHERE id=?', (self.oid,)))
        self.assertTrue(self.rows('SELECT id FROM community_posts WHERE id=?', (self.kept,)))
        self.assertFalse(self.rows('SELECT id FROM community_posts WHERE id=?', (self.post,)))
        self.assertFalse(self.rows('SELECT id FROM community_replies WHERE post_id=?', (self.kept,)))
        for table in ('otp_requests', 'otp_codes', 'leads'):
            self.assertFalse(self.rows('SELECT * FROM ' + table + ' WHERE phone=?', (self.phone,)))
        for client in (self.author, second):
            self.assertEqual(client.request('/dashboard')[0], 303)
        self.assertEqual(self.rows('PRAGMA foreign_key_check'), [])
        fresh = test_release.Client(self.base)
        self.register(fresh, self.phone)
        new_id = self.rows('SELECT id FROM users WHERE phone=?', (self.phone,))[0][0]
        self.assertNotEqual(new_id, self.uid)
        self.assertFalse(self.rows('SELECT id FROM projects WHERE user_id=?', (new_id,)))

    def test_guest_and_owner_cannot_delete_accounts(self):
        guest = test_release.Client(self.base)
        guest.request('/login')
        data = {'password': 'FixturePass123', 'confirmation': 'حذف حساب'}
        self.assertEqual(guest.post('/account/delete', data)[0], 303)
        self.assertEqual(guest.post('/community/delete', {'post_id': self.post, 'confirm': 'delete'})[0], 303)
        with sqlite3.connect(self.database) as connection:
            connection.execute("UPDATE users SET role='owner' WHERE id=?", (self.uid,))
        self.assertEqual(self.author.post('/account/delete', data)[0], 403)
        self.assertTrue(self.rows('SELECT id FROM users WHERE id=?', (self.uid,)))

    def test_password_attempts_are_limited(self):
        data = {'password': 'wrong', 'confirmation': 'حذف حساب'}
        for _ in range(5):
            self.assertEqual(self.author.post('/account/delete', data)[0], 303)
        self.assertEqual(self.author.post('/account/delete', dict(data, password='FixturePass123'))[0], 429)
        self.assertTrue(self.rows('SELECT id FROM users WHERE id=?', (self.uid,)))

    def test_failure_rolls_back_all_account_data(self):
        self.seed_account_data()
        with sqlite3.connect(self.database) as connection:
            connection.execute('CREATE TRIGGER block_account_delete BEFORE DELETE ON users WHEN OLD.id=' + str(self.uid) + " BEGIN SELECT RAISE(ABORT,'fixture rollback'); END")
        try:
            self.assertEqual(self.author.post('/account/delete', {'password': 'FixturePass123', 'confirmation': 'حذف حساب'})[0], 503)
            for table in ('users', 'projects', 'community_posts', 'darycheh_attempts'):
                column = 'id' if table == 'users' else 'user_id'
                self.assertTrue(self.rows('SELECT * FROM ' + table + ' WHERE ' + column + '=?', (self.uid,)))
            self.assertTrue(self.rows('SELECT * FROM darycheh_evaluations'))
            self.assertEqual(self.rows('PRAGMA foreign_key_check'), [])
        finally:
            with sqlite3.connect(self.database) as connection:
                connection.execute('DROP TRIGGER block_account_delete')

    def test_legacy_editor_is_not_deleted_with_shared_drafts(self):
        draft = self.insert('darycheh_drafts', definition_json='{}', updated_by=self.uid)
        self.assertEqual(self.author.post('/account/delete', {'password': 'FixturePass123', 'confirmation': 'حذف حساب'})[1]['Location'], '/dashboard/profile')
        self.assertTrue(self.rows('SELECT id FROM users WHERE id=?', (self.uid,)))
        self.assertTrue(self.rows('SELECT id FROM darycheh_drafts WHERE id=?', (draft,)))
        self.assertIn('پیش‌نویس‌های مدیریتی', self.author.request('/dashboard/profile')[2])

    def test_active_jobs_prevent_account_deletion(self):
        self.seed_account_data('running')
        data = {'password': 'FixturePass123', 'confirmation': 'حذف حساب'}
        self.assertEqual(self.author.post('/account/delete', data)[1]['Location'], '/dashboard/profile')
        self.assertTrue(self.rows('SELECT id FROM users WHERE id=?', (self.uid,)))
        with sqlite3.connect(self.database) as connection:
            connection.execute("UPDATE workspace_runs SET state='succeeded' WHERE project_id IN (SELECT id FROM projects WHERE user_id=?)", (self.uid,))
            connection.execute("UPDATE workspace_dataset_imports SET state='downloading' WHERE owner=?", (self.uid,))
        self.assertEqual(self.author.post('/account/delete', data)[1]['Location'], '/dashboard/profile')
        self.assertTrue(self.rows('SELECT id FROM users WHERE id=?', (self.uid,)))
        self.assertTrue(self.rows('SELECT id FROM community_posts WHERE id=?', (self.post,)))


class CommunityControlsSourceTests(unittest.TestCase):
    def test_heart_states_accessibility_and_confirmation(self):
        css = (ROOT / 'public/assets/style.css').read_text()
        views = (ROOT / 'src/views.php').read_text()
        self.assertIn('.heart-button.is-liked{color:#c52242', css)
        self.assertIn('.heart-button.is-liked svg{fill:currentColor}', css)
        self.assertIn('min-height:44px', css)
        self.assertIn('.heart-button:focus-visible', css)
        self.assertIn('aria-pressed=', views)
        self.assertIn('type="checkbox" name="confirm" value="delete" required', views)
        self.assertIn('autocomplete="current-password" required', views)
        self.assertIn("community_post_controls($p,$me,'dashboard/community')", views)
        self.assertIn("community_post_controls($p,$me,'community')", (ROOT / 'src/router.php').read_text())


if __name__ == '__main__':
    unittest.main()
