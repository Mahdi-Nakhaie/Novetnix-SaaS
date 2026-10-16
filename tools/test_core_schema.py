import json
import pathlib
import re
import sqlite3
import unittest


ROOT = pathlib.Path(__file__).resolve().parent.parent
SQLITE_SCHEMA = (ROOT / "database/schema.sqlite.sql").read_text(encoding="utf-8")
MYSQL_SCHEMA = (ROOT / "database/schema.mysql.sql").read_text(encoding="utf-8")


class CoreSchemaTest(unittest.TestCase):
    def setUp(self):
        self.db = sqlite3.connect(":memory:")
        self.db.execute("PRAGMA foreign_keys=ON")
        self.db.executescript(SQLITE_SCHEMA)

    def tearDown(self):
        self.db.close()

    def test_tables_match_both_engines(self):
        tables = lambda schema: set(re.findall(r"CREATE TABLE IF NOT EXISTS (\w+)", schema))
        self.assertEqual(tables(SQLITE_SCHEMA), tables(MYSQL_SCHEMA))
        self.assertTrue({"users", "courses", "lessons", "lesson_quizzes"} <= tables(SQLITE_SCHEMA))
        self.assertTrue({'workspace_templates', 'project_workspaces', 'workspace_runs', 'workspace_dataset_imports', 'workspace_hub_rates'} <= tables(SQLITE_SCHEMA))
        self.assertEqual(len(tables(SQLITE_SCHEMA)), 31)

    def test_quiz_schema_links_each_lesson_to_one_valid_threshold(self):
        self.db.execute("INSERT INTO courses(slug,title,description) VALUES ('main','Main','')")
        self.db.executemany("INSERT INTO lessons(course_id,title,content,position,status) VALUES (1,?,'',?,'published')", [("One", 1), ("Two", 2), ("Three", 3), ("Four", 4), ("Five", 5)])
        fixture='[{"prompt":"Sample?","options":["Yes","No"],"correct":0}]'
        for lesson in (1, 3, 5):
            self.db.execute("INSERT INTO lesson_quizzes(lesson_id,pass_percent,questions_json) VALUES (?,?,?)", (lesson, 75, fixture))
        self.assertEqual([row[0] for row in self.db.execute("SELECT lesson_id FROM lesson_quizzes ORDER BY lesson_id")], [1, 3, 5])
        with self.assertRaises(sqlite3.IntegrityError):
            self.db.execute("INSERT INTO lesson_quizzes(lesson_id,pass_percent,questions_json) VALUES (1,75,?)", (fixture,))
        with self.assertRaises(sqlite3.IntegrityError):
            self.db.execute("INSERT INTO lesson_quizzes(lesson_id,pass_percent,questions_json) VALUES (99,75,?)", (fixture,))
        with self.assertRaises(sqlite3.IntegrityError):
            self.db.execute("INSERT INTO lesson_quizzes(lesson_id,pass_percent,questions_json) VALUES (2,0,?)", (fixture,))
        self.db.execute("UPDATE lessons SET position=6 WHERE id=3")
        self.assertEqual(self.db.execute("SELECT pass_percent FROM lesson_quizzes WHERE lesson_id=3").fetchone(), (75,))
        self.db.execute("DELETE FROM lesson_quizzes WHERE lesson_id=5")
        self.assertIsNone(self.db.execute("SELECT lesson_id FROM lesson_quizzes WHERE lesson_id=5").fetchone())

    def test_multiple_users_courses_and_lessons(self):
        self.db.executemany("INSERT INTO users(phone) VALUES (?)", [("09123456789",), ("09987654321",)])
        self.db.executemany(
            "INSERT INTO courses(slug,title,description) VALUES (?,?,?)",
            [("python-foundations", "Python", "Foundations"), ("data-analysis", "Data", "Analysis")],
        )
        self.db.executemany(
            "INSERT INTO lessons(course_id,title,content,position) VALUES (?,?,?,?)",
            [(1, "Intro", "Text", 1), (1, "Functions", "Text", 2), (2, "Data", "Text", 1)],
        )
        self.assertEqual(self.db.execute("SELECT COUNT(*) FROM users").fetchone()[0], 2)
        self.assertEqual(self.db.execute("SELECT COUNT(*) FROM courses").fetchone()[0], 2)
        self.assertEqual(self.db.execute("SELECT course_id,position FROM lessons ORDER BY course_id,position").fetchall(), [(1, 1), (1, 2), (2, 1)])

    def test_uniqueness_and_required_fields(self):
        self.db.execute("INSERT INTO users(phone) VALUES ('09123456789')")
        with self.assertRaises(sqlite3.IntegrityError):
            self.db.execute("INSERT INTO users(phone) VALUES ('09123456789')")
        self.db.execute("INSERT INTO courses(slug,title,description) VALUES ('python','Python','Intro')")
        with self.assertRaises(sqlite3.IntegrityError):
            self.db.execute("INSERT INTO courses(slug,title,description) VALUES ('python','Again','Intro')")
        self.db.execute("INSERT INTO lessons(course_id,title,content,position) VALUES (1,'First','Text',1)")
        with self.assertRaises(sqlite3.IntegrityError):
            self.db.execute("INSERT INTO lessons(course_id,title,content,position) VALUES (1,'Second','Text',1)")
        with self.assertRaises(sqlite3.IntegrityError):
            self.db.execute("INSERT INTO lessons(course_id,title,content,position) VALUES (NULL,'Orphan','Text',2)")
        with self.assertRaises(sqlite3.IntegrityError):
            self.db.execute("INSERT INTO lessons(course_id,title,content,position) VALUES (1,'Invalid','Text',0)")
        with self.assertRaises(sqlite3.IntegrityError):
            self.db.execute("INSERT INTO lessons(course_id,title,content,position) VALUES (1,NULL,'Text',2)")
        with self.assertRaises(sqlite3.IntegrityError):
            self.db.execute("INSERT INTO lessons(course_id,title,content,position) VALUES (1,'Invalid',NULL,2)")
        with self.assertRaises(sqlite3.IntegrityError):
            self.db.execute("INSERT INTO courses(slug,title,description,status) VALUES ('invalid','Invalid','Text','unknown')")

    def test_courses_support_independent_crud_status_timestamps_and_slug_uniqueness(self):
        self.db.execute("INSERT INTO courses(slug,title,description,status,created_at,updated_at) VALUES (?,?,?,?,?,?)", ("draft-course", "Draft", "First", "draft", "2026-10-07 10:00:00", "2026-10-07 10:00:00"))
        self.db.execute("INSERT INTO courses(slug,title,description,status,created_at,updated_at) VALUES (?,?,?,?,?,?)", ("published-course", "Published", "Second", "published", "2026-10-07 11:00:00", "2026-10-07 11:00:00"))
        self.assertEqual(self.db.execute("SELECT slug,status FROM courses ORDER BY id").fetchall(), [("draft-course", "draft"), ("published-course", "published")])
        self.db.execute("UPDATE courses SET title=?,description=?,updated_at=? WHERE slug=?", ("Updated", "Changed", "2026-10-08 10:00:00", "draft-course"))
        self.db.execute("UPDATE courses SET status=?,updated_at=? WHERE slug=?", ("published", "2026-10-08 11:00:00", "draft-course"))
        self.assertEqual(self.db.execute("SELECT title,description,status,created_at,updated_at FROM courses WHERE slug='draft-course'").fetchone(), ("Updated", "Changed", "published", "2026-10-07 10:00:00", "2026-10-08 11:00:00"))
        self.assertEqual(self.db.execute("SELECT created_at,updated_at FROM courses WHERE slug='published-course'").fetchone(), ("2026-10-07 11:00:00", "2026-10-07 11:00:00"))
        with self.assertRaises(sqlite3.IntegrityError):
            self.db.execute("INSERT INTO courses(slug,title,description) VALUES ('draft-course','Duplicate','Duplicate')")
        with self.assertRaises(sqlite3.IntegrityError):
            self.db.execute("UPDATE courses SET status='archived' WHERE slug='published-course'")

    def test_lessons_have_independent_status_and_ordering_per_course(self):
        self.db.executemany("INSERT INTO courses(slug,title,description) VALUES (?,?,?)", [("course-a", "A", "A"), ("course-b", "B", "B")])
        self.db.executemany(
            "INSERT INTO lessons(course_id,title,content,position,status) VALUES (?,?,?,?,?)",
            [(1, "A1", "Text", 1, "draft"), (1, "A2", "Text", 2, "published"), (2, "B1", "Text", 1, "published")],
        )
        self.assertEqual(self.db.execute("SELECT title,status FROM lessons WHERE course_id=1 ORDER BY position ASC").fetchall(), [("A1", "draft"), ("A2", "published")])
        self.assertEqual(self.db.execute("SELECT position FROM lessons WHERE course_id=2").fetchall(), [(1,)])
        self.db.execute("UPDATE lessons SET status='published' WHERE course_id=1 AND position=1")
        self.assertEqual(self.db.execute("SELECT status FROM lessons WHERE course_id=1 ORDER BY position").fetchall(), [("published",), ("published",)])
        with self.assertRaises(sqlite3.IntegrityError):
            self.db.execute("INSERT INTO lessons(course_id,title,content,position,status) VALUES (1,'Duplicate','Text',1,'draft')")
        with self.assertRaises(sqlite3.IntegrityError):
            self.db.execute("INSERT INTO lessons(course_id,title,content,position,status) VALUES (1,'Invalid','Text',3,'archived')")

    def test_foreign_key_prevents_orphans(self):
        self.db.execute("INSERT INTO courses(slug,title,description) VALUES ('python','Python','Intro')")
        with self.assertRaises(sqlite3.IntegrityError):
            self.db.execute("INSERT INTO lessons(course_id,title,content,position) VALUES (999,'Orphan','Text',1)")
        self.db.execute("INSERT INTO lessons(course_id,title,content,position) VALUES (1,'Intro','Text',1)")
        with self.assertRaises(sqlite3.IntegrityError):
            self.db.execute("DELETE FROM courses WHERE id=1")
        self.assertEqual(self.db.execute("PRAGMA foreign_key_check").fetchall(), [])

    def test_lesson_progress_is_unique_per_user_and_lesson(self):
        self.db.executemany("INSERT INTO users(phone) VALUES (?)", [("09123456789",), ("09987654321",)])
        self.db.execute("INSERT INTO courses(slug,title,description) VALUES ('python','Python','Intro')")
        self.db.executemany(
            "INSERT INTO lessons(course_id,title,content,position) VALUES (1,?,?,?)",
            [("Intro", "Text", 1), ("Functions", "Text", 2)],
        )
        self.db.executemany(
            "INSERT INTO lesson_progress(user_id,lesson_id) VALUES (?,?)",
            [(1, 1), (1, 2), (2, 1)],
        )
        self.assertEqual(self.db.execute("SELECT COUNT(*) FROM lesson_progress").fetchone()[0], 3)
        with self.assertRaises(sqlite3.IntegrityError):
            self.db.execute("INSERT INTO lesson_progress(user_id,lesson_id) VALUES (1,1)")
        self.assertEqual(
            self.db.execute("SELECT c.slug FROM lesson_progress p JOIN lessons l ON l.id=p.lesson_id JOIN courses c ON c.id=l.course_id WHERE p.user_id=1 AND p.lesson_id=1").fetchone()[0],
            "python",
        )
        self.assertNotIn("course_id", [row[1] for row in self.db.execute("PRAGMA table_info(lesson_progress)")])
        indexes = self.db.execute("PRAGMA index_list(lesson_progress)").fetchall()
        self.assertTrue(any(index[1] == "idx_lesson_progress_lesson" for index in indexes))
        self.assertTrue(any(index[2] for index in indexes))

    def test_progress_requires_valid_parents_and_consistent_completion(self):
        self.db.execute("INSERT INTO users(phone) VALUES ('09123456789')")
        self.db.execute("INSERT INTO courses(slug,title,description) VALUES ('python','Python','Intro')")
        self.db.execute("INSERT INTO lessons(course_id,title,content,position) VALUES (1,'Intro','Text',1)")
        for sql in (
            "INSERT INTO lesson_progress(user_id,lesson_id) VALUES (999,1)",
            "INSERT INTO lesson_progress(user_id,lesson_id) VALUES (1,999)",
            "INSERT INTO lesson_progress(user_id,lesson_id) VALUES (NULL,1)",
            "INSERT INTO lesson_progress(user_id,lesson_id) VALUES (1,NULL)",
            "INSERT INTO lesson_progress(user_id,lesson_id,status) VALUES (1,1,'unknown')",
            "INSERT INTO lesson_progress(user_id,lesson_id,status) VALUES (1,1,'completed')",
            "INSERT INTO lesson_progress(user_id,lesson_id,completed_at) VALUES (1,1,'2026-10-03 12:00:00')",
        ):
            with self.subTest(sql=sql), self.assertRaises(sqlite3.IntegrityError):
                self.db.execute(sql)
        self.db.execute("INSERT INTO lesson_progress(user_id,lesson_id,status,completed_at) VALUES (1,1,'completed','2026-10-03 12:00:00')")
        with self.assertRaises(sqlite3.IntegrityError):
            self.db.execute("DELETE FROM lessons WHERE id=1")
        with self.assertRaises(sqlite3.IntegrityError):
            self.db.execute("DELETE FROM users WHERE id=1")
        self.assertEqual(self.db.execute("PRAGMA foreign_key_check").fetchall(), [])

    def test_activities_require_user_and_support_history_lookup(self):
        self.db.execute("INSERT INTO users(phone) VALUES ('09123456789')")
        self.db.executemany("INSERT INTO activities(user_id,event_name,metadata) VALUES (1,?,?)", [('lesson_completed', None), ('code_run', json.dumps({'source': 'editor', 'attempt': 2}))])
        with self.assertRaises(sqlite3.IntegrityError):
            self.db.execute("INSERT INTO activities(user_id,event_name) VALUES (999,'code_run')")
        for event_name in (None, '', '  '):
            with self.assertRaises(sqlite3.IntegrityError):
                self.db.execute('INSERT INTO activities(user_id,event_name) VALUES (1,?)', (event_name,))
        with self.assertRaises(sqlite3.IntegrityError):
            self.db.execute("INSERT INTO activities(user_id,event_name,metadata) VALUES (1,'code_run','{invalid')")
        with self.assertRaises(sqlite3.IntegrityError):
            self.db.execute('DELETE FROM users WHERE id=1')
        rows = self.db.execute('SELECT id,event_name,created_at,metadata FROM activities WHERE user_id=1 ORDER BY created_at,id').fetchall()
        self.assertEqual([row[1] for row in rows], ['lesson_completed', 'code_run'])
        self.assertEqual([row[0] for row in rows], [1, 2])
        self.assertTrue(all(row[2] for row in rows))
        self.assertIsNone(rows[0][3])
        self.assertEqual(json.loads(rows[1][3]), {'source': 'editor', 'attempt': 2})
        self.assertEqual(self.db.execute("SELECT event_name FROM activities WHERE event_name='code_run' AND created_at>=?", ('2000-01-01',)).fetchone(), ('code_run',))
        self.assertTrue(any(row[1] == 'idx_activities_user_created' for row in self.db.execute('PRAGMA index_list(activities)')))
        self.assertTrue(any(row[1] == 'idx_activities_created' for row in self.db.execute('PRAGMA index_list(activities)')))

    def test_activities_migration_preserves_legacy_events_and_is_repeatable(self):
        connection = (ROOT / 'database/connection.php').read_text(encoding='utf-8')
        rename = re.search(r"\$db->exec\('(ALTER TABLE activities RENAME COLUMN kind TO event_name)'\)", connection).group(1)
        add_metadata = re.search(r"\$driver==='mysql' \? 'JSON NULL' : '(TEXT)'", connection).group(1)
        create_index = re.search(r"\$db->exec\('(CREATE INDEX IF NOT EXISTS idx_activities_event_created ON activities\(event_name,created_at\))'\)", connection).group(1)
        legacy = sqlite3.connect(':memory:')
        try:
            legacy.execute('PRAGMA foreign_keys=ON')
            legacy.execute('CREATE TABLE users(id INTEGER PRIMARY KEY)')
            legacy.execute('INSERT INTO users(id) VALUES (1)')
            legacy.execute('CREATE TABLE activities(id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INTEGER NOT NULL, kind TEXT NOT NULL, created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP, FOREIGN KEY(user_id) REFERENCES users(id))')
            legacy.execute('CREATE INDEX idx_activities_user_created ON activities(user_id,created_at)')
            legacy.execute("INSERT INTO activities(user_id,kind,created_at) VALUES (1,'old_event','2026-01-01 12:00:00')")
            legacy.executescript(SQLITE_SCHEMA)
            for _ in range(2):
                columns = {row[1] for row in legacy.execute('PRAGMA table_info(activities)')}
                if 'kind' in columns:
                    legacy.execute(rename)
                if 'metadata' not in columns:
                    legacy.execute('ALTER TABLE activities ADD COLUMN metadata ' + add_metadata)
                legacy.execute(create_index)
            self.assertEqual(legacy.execute('SELECT id,user_id,event_name,created_at,metadata FROM activities').fetchall(), [(1, 1, 'old_event', '2026-01-01 12:00:00', None)])
            legacy.execute('INSERT INTO activities(user_id,event_name,metadata) VALUES (1,?,?)', ('new_event', '{"x":1}'))
            self.assertEqual(legacy.execute('SELECT event_name FROM activities WHERE event_name=? AND created_at>=?', ('new_event', '2000-01-01')).fetchone(), ('new_event',))
            self.assertTrue(any(row[1] == 'idx_activities_event_created' for row in legacy.execute('PRAGMA index_list(activities)')))
            self.assertEqual(legacy.execute('PRAGMA foreign_key_check').fetchall(), [])
        finally:
            legacy.close()

    def test_activity_recorder_and_mysql_contract(self):
        connection = (ROOT / 'database/connection.php').read_text(encoding='utf-8')
        self.assertIn('function record_activity_event(int $userId, string $eventName, ?array $metadata=null): int', connection)
        self.assertIn('json_encode($metadata,JSON_THROW_ON_ERROR)', connection)
        self.assertIn("q('INSERT INTO activities(user_id,event_name,metadata) VALUES (?,?,?)'", connection)
        self.assertIn('return (int)db()->lastInsertId()', connection)
        self.assertIn('metadata JSON NULL', MYSQL_SCHEMA)
        self.assertIn('INDEX idx_activities_event_created (event_name,created_at)', MYSQL_SCHEMA)
        self.assertIn('INDEX idx_activities_user_created (user_id,created_at)', MYSQL_SCHEMA)
        self.assertIn('INDEX idx_activities_created (created_at)', MYSQL_SCHEMA)
        self.assertIn("SHOW INDEX FROM activities WHERE Key_name='idx_activities_event_created'", connection)

    def test_leads_require_contact_and_track_status(self):
        self.db.execute("INSERT INTO leads(name,email,source) VALUES ('A','a@example.com','contact')")
        self.db.execute("INSERT INTO leads(name,phone,source,status) VALUES ('B','09123456789','signup','contacted')")
        for sql in (
            "INSERT INTO leads(name,source) VALUES ('C','contact')",
            "INSERT INTO leads(name,email,source,status) VALUES ('D','d@example.com','contact','invalid')",
            "INSERT INTO leads(email,source) VALUES ('e@example.com','contact')",
            "INSERT INTO leads(name,email,source) VALUES ('E','   ','contact')",
            "INSERT INTO leads(name,phone,source) VALUES ('F','   ','contact')",
            "INSERT INTO leads(name,email,source) VALUES (' ','f@example.com','contact')",
            "INSERT INTO leads(name,email,source) VALUES ('G','g@example.com',' ')",
        ):
            with self.subTest(sql=sql), self.assertRaises(sqlite3.IntegrityError):
                self.db.execute(sql)
        self.assertEqual(self.db.execute('SELECT status,COUNT(*) FROM leads GROUP BY status ORDER BY status').fetchall(), [('contacted', 1), ('new', 1)])
        self.assertTrue(any(row[1] == 'idx_leads_status_created' for row in self.db.execute('PRAGMA index_list(leads)')))

    def test_otp_stores_only_hash_and_prevents_reuse(self):
        columns = {row[1] for row in self.db.execute('PRAGMA table_info(otp_codes)')}
        self.assertTrue({'phone', 'otp_hash', 'expires_at', 'used_at', 'attempts', 'created_at'} <= columns)
        self.assertNotIn('code', columns)
        self.assertNotIn('code_hash', columns)
        self.db.execute("INSERT INTO otp_codes(phone,otp_hash,expires_at,created_at) VALUES ('09123456789','hashed-value',200,100)")
        with self.assertRaises(sqlite3.IntegrityError):
            self.db.execute("INSERT INTO otp_codes(phone,otp_hash,expires_at,created_at) VALUES ('09123456789','other-hash',200,100)")
        with self.assertRaises(sqlite3.IntegrityError):
            self.db.execute("UPDATE otp_codes SET attempts=-1 WHERE phone='09123456789'")
        with self.assertRaises(sqlite3.IntegrityError):
            self.db.execute("UPDATE otp_codes SET attempts=6 WHERE phone='09123456789'")
        with self.assertRaises(sqlite3.IntegrityError):
            self.db.execute("UPDATE otp_codes SET expires_at=100 WHERE phone='09123456789'")
        with self.assertRaises(sqlite3.IntegrityError):
            self.db.execute("UPDATE otp_codes SET used_at=201 WHERE phone='09123456789'")
        self.assertEqual(self.db.execute("UPDATE otp_codes SET used_at=150 WHERE phone='09123456789' AND used_at IS NULL AND expires_at>=150").rowcount, 1)
        self.assertEqual(self.db.execute("UPDATE otp_codes SET used_at=151 WHERE phone='09123456789' AND used_at IS NULL AND expires_at>=151").rowcount, 0)
        self.assertEqual(self.db.execute("SELECT otp_hash,used_at,attempts FROM otp_codes").fetchone(), ('hashed-value', 150, 0))

    def test_legacy_otp_columns_preserve_hash_and_timestamps(self):
        db = sqlite3.connect(':memory:')
        try:
            db.execute('CREATE TABLE otp_codes (phone TEXT PRIMARY KEY, code_hash TEXT NOT NULL, expires_at INTEGER NOT NULL, attempts INTEGER NOT NULL DEFAULT 0, sent_at INTEGER NOT NULL)')
            db.execute("INSERT INTO otp_codes VALUES ('09123456789','existing-hash',200,2,100)")
            db.execute('ALTER TABLE otp_codes RENAME COLUMN code_hash TO otp_hash')
            db.execute('ALTER TABLE otp_codes RENAME COLUMN sent_at TO created_at')
            db.execute('ALTER TABLE otp_codes ADD COLUMN used_at INTEGER')
            self.assertEqual(db.execute('SELECT otp_hash,created_at,attempts,used_at FROM otp_codes').fetchone(), ('existing-hash', 100, 2, None))
        finally:
            db.close()

    def test_existing_database_keeps_users_and_adds_core_tables(self):
        self.db.close()
        self.db = sqlite3.connect(":memory:")
        self.db.execute("PRAGMA foreign_keys=ON")
        self.db.execute("CREATE TABLE users (id INTEGER PRIMARY KEY AUTOINCREMENT, phone TEXT NOT NULL UNIQUE, name TEXT NOT NULL DEFAULT '', password_hash TEXT NOT NULL DEFAULT '', role TEXT NOT NULL DEFAULT 'student', created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP)")
        self.db.execute("INSERT INTO users(phone) VALUES ('09123456789')")
        self.db.executescript(SQLITE_SCHEMA)
        self.db.execute("INSERT INTO courses(slug,title,description) VALUES ('python','Python','Intro')")
        self.db.executescript(SQLITE_SCHEMA)
        self.assertEqual(self.db.execute("SELECT phone FROM users").fetchall(), [("09123456789",)])
        self.assertEqual(self.db.execute("SELECT slug FROM courses").fetchall(), [("python",)])
        self.db.execute("INSERT INTO lessons(course_id,title,content,position) VALUES (1,'Intro','Text',1)")
        self.db.execute("INSERT INTO lesson_progress(user_id,lesson_id) VALUES (1,1)")
        self.assertEqual(self.db.execute("SELECT status FROM lesson_progress").fetchone()[0], "started")


if __name__ == "__main__":
    unittest.main()
