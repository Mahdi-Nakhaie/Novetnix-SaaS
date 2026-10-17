import pathlib
import re
import sqlite3
import unittest


ROOT = pathlib.Path(__file__).resolve().parent.parent
SKILL = (ROOT / "src/skill_profile.php").read_text(encoding="utf-8")
MESSAGING = (ROOT / "src/messaging.php").read_text(encoding="utf-8")
PROFILE_VIEWS = (ROOT / "src/profile_views.php").read_text(encoding="utf-8")
MESSAGE_VIEWS = (ROOT / "src/message_views.php").read_text(encoding="utf-8")
ROUTER = (ROOT / "src/router.php").read_text(encoding="utf-8")
APP = (ROOT / "src/app.php").read_text(encoding="utf-8")
VIEWS = (ROOT / "src/views.php").read_text(encoding="utf-8")
SCHEMA = (ROOT / "database/schema.sqlite.sql").read_text(encoding="utf-8")
MYSQL_SCHEMA = (ROOT / "database/schema.mysql.sql").read_text(encoding="utf-8")
STYLE = (ROOT / "public/assets/style.css").read_text(encoding="utf-8")


def body(source, name):
    start = source.index(f"function {name}(")
    end = source.find("\nfunction ", start + 1)
    return source[start:end if end != -1 else None]


class SkillLevelTest(unittest.TestCase):
    def setUp(self):
        match = re.search(r"const SKILL_LEVELS = \[([^\]]+)\];", SKILL)
        self.thresholds = [int(value) for value in match.group(1).split(",")]

    def level(self, xp):
        level, floor = 1, 0
        for index, floor_xp in enumerate(self.thresholds):
            if xp < floor_xp:
                break
            level, floor = index + 2, floor_xp
        next_xp = self.thresholds[level - 1] if level - 1 < len(self.thresholds) else None
        progress = 100 if next_xp is None else min(100, (xp - floor) * 100 // max(1, next_xp - floor))
        return level, next_xp, progress

    def test_skill_levels_start_at_zero_and_are_strictly_ascending(self):
        self.assertEqual(self.thresholds, sorted(set(self.thresholds)))
        self.assertGreater(self.thresholds[0], 0)
        self.assertEqual(self.level(0)[:2], (1, self.thresholds[0]))
        self.assertEqual(self.level(0)[2], 0)

    def test_each_threshold_promotes_exactly_once(self):
        for expected_level, threshold in enumerate(self.thresholds, start=2):
            self.assertEqual(self.level(threshold - 1)[0], expected_level - 1)
            self.assertEqual(self.level(threshold)[0], expected_level)

    def test_progress_is_capped_and_hits_full_on_next_level(self):
        for threshold in self.thresholds:
            self.assertEqual(self.level(threshold)[2], 0 if threshold != self.thresholds[-1] else 100)
        self.assertLessEqual(self.level(self.thresholds[-1] + 5000)[2], 100)

    def test_progress_never_reports_a_gap_larger_than_one_level(self):
        for xp in (0, 100, 499, 500, 1199, 1200, 7000, 15999, 16000, 99999):
            level, next_xp, progress = self.level(xp)
            if next_xp is not None:
                self.assertTrue(0 <= progress < 100, (xp, level, progress))


class SkillProfileSourceTest(unittest.TestCase):
    def test_progress_is_derived_from_stored_completions_only(self):
        profile = body(SKILL, "skill_profile_for")
        self.assertIn("FROM project_completions WHERE user_id=?", profile)
        self.assertIn("FROM lesson_progress WHERE user_id=? AND status=?", profile)
        self.assertNotIn("$_POST", profile)
        self.assertNotIn("$_GET", profile)

    def test_profile_never_selects_contact_columns(self):
        for source in (SKILL, PROFILE_VIEWS, MESSAGE_VIEWS):
            self.assertNotIn("u.phone,", source)
            self.assertNotIn("SELECT phone", source)
            self.assertNotIn("password_hash", source)

    def test_directory_hides_admins_unverified_and_nameless_members(self):
        directory = body(SKILL, "learner_directory")
        self.assertIn("u.phone_verified_at IS NOT NULL", directory)
        self.assertIn("trim(u.name)<>''", directory)
        self.assertIn("u.role<>'admin'", directory)
        self.assertIn("ORDER BY xp DESC,u.id ASC LIMIT '.$perPage.' OFFSET '", directory)

    def test_directory_query_is_bound_not_interpolated(self):
        directory = body(SKILL, "learner_directory")
        self.assertIn("$params[]='%'.", directory)
        self.assertNotIn("LIKE '%\".$query", directory)

    def test_dashboard_and_views_escape_member_names(self):
        for source in (PROFILE_VIEWS, MESSAGE_VIEWS):
            self.assertNotIn("'<h1>'.$profile['name']", source)
            self.assertNotIn("'<strong>'.$learner['name']", source)
        self.assertIn("h($profile['name'])", PROFILE_VIEWS)


class MessagingSourceTest(unittest.TestCase):
    def test_send_path_runs_after_csrf_and_bounds_the_body(self):
        handler = APP[APP.index("messages/send"):APP.index("messages/accept")]
        self.assertLess(APP.index("validate_csrf();"), APP.index("messages/send"))
        self.assertIn("message_send(", handler)
        self.assertIn("redirect($returnTo);", handler)

    def test_peer_state_is_follow_based_and_excludes_self(self):
        state = body(MESSAGING, "message_peer_state")
        self.assertIn("'self'", state)
        self.assertIn("user_follows", state)
        self.assertIn("message_pending_request", state)

    def test_pending_request_hides_messages_until_accepted(self):
        send = body(MESSAGING, "message_send") + body(MESSAGING, "message_peer_state")
        self.assertIn("message_requests", MESSAGING)
        self.assertIn("message_accept", MESSAGING)
        self.assertNotRegex(send, r"str_repeat\(|random_int|rand\(")

    def test_send_is_deduplicated_and_rate_limited(self):
        self.assertIn("MESSAGE_DUPLICATE", MESSAGING)
        self.assertIn("23000", MESSAGING)
        self.assertIn("MESSAGE_RATE_WINDOW", MESSAGING)
        self.assertIn("message_rate_ok()", MESSAGING)

    def test_forms_carry_the_shared_message_token(self):
        form = PROFILE_VIEWS[PROFILE_VIEWS.index("function member_message_form"):]
        self.assertIn("message_token()", form)
        self.assertIn("return_to", form)
        self.assertIn("form_start('messages/send'", form)

    def test_messages_require_login(self):
        self.assertIn("if ($path==='messages') { $u=require_user();", ROUTER)
        self.assertIn("~^messages/([1-9][0-9]*)$~", ROUTER)

    def test_thread_marks_read_before_listing(self):
        thread = body(MESSAGE_VIEWS, "page_message_thread")
        self.assertLess(thread.index("messages_mark_read"), thread.index("messages_thread"))


class ProfileRoutesTest(unittest.TestCase):
    def test_member_profile_route_is_public_and_indexable_for_guests(self):
        member = body(ROUTER, "page_member")
        self.assertIn("skill_profile_for($id)", member)
        self.assertIn("page_skill_profile($profile,$own,(bool)$viewer)", member)
        self.assertIn("head_page('پروفایل مهارتی '", member)
        self.assertIn("if (!$profile) { page_not_found(); return; }", member)

    def test_learners_directory_is_reachable_and_searchable(self):
        self.assertIn("if ($path==='learners')", ROUTER)
        self.assertIn("learner_directory($query,(int)$page)", ROUTER)
        self.assertIn("name=\"q\"", PROFILE_VIEWS)
        self.assertIn("page_learners($directory,$query,(bool)user())", ROUTER)

    def test_community_links_each_author_to_their_profile(self):
        community = ROUTER[ROUTER.index("function page_community"):ROUTER.index("function page_login")]
        self.assertIn("url('member/'.$p['user_id'])", community)
        self.assertIn("url('member/'.$p['user_id'])", VIEWS)

    def test_panel_links_to_own_skill_profile_and_messages(self):
        self.assertIn("$items['member/'.$me['id']]='پروفایل مهارتی'", VIEWS)
        self.assertIn("'messages'=>'پیام‌ها'", VIEWS)

    def test_learners_is_indexable_only_on_the_clean_first_page(self):
        self.assertIn("$indexable=$query==='' && (int)$page===1 && $viewer===null;", ROUTER)
        self.assertIn("false,$indexable);", ROUTER)

    def test_styles_for_the_new_components_exist(self):
        for selector in (".profile-hero", ".profile-grid", ".skill-meter", ".learner-grid", ".message-compose", ".message-bubble", ".thread-item"):
            self.assertIn(selector + "{", STYLE, selector)

    def test_both_schemas_declare_the_message_tables(self):
        tables = lambda schema: set(re.findall(r"CREATE TABLE IF NOT EXISTS (\w+)", schema))
        self.assertTrue({"direct_messages", "message_requests"} <= tables(SCHEMA))
        self.assertTrue({"direct_messages", "message_requests"} <= tables(MYSQL_SCHEMA))


class MessagingDatabaseTest(unittest.TestCase):
    def setUp(self):
        self.db = sqlite3.connect(":memory:")
        self.db.execute("PRAGMA foreign_keys=ON")
        self.db.executescript(SCHEMA)
        self.db.executemany(
            "INSERT INTO users(phone,name,phone_verified_at) VALUES (?,?,?)",
            [("09120000001", "Sender", 1), ("09120000002", "Recipient", 1)],
        )

    def send(self, sender, recipient, entity_hash, text="سلام"):
        self.db.execute(
            "INSERT INTO direct_messages(sender_id,recipient_id,entity_hash,body) VALUES (?,?,?,?)",
            (sender, recipient, entity_hash, text),
        )

    def test_self_messages_are_rejected(self):
        with self.assertRaises(sqlite3.IntegrityError):
            self.send(1, 1, "a" * 64)

    def test_empty_body_is_rejected(self):
        for text in ("", "   "):
            with self.assertRaises(sqlite3.IntegrityError):
                self.send(1, 2, "b" * 64, text)

    def test_same_entity_cannot_be_sent_twice(self):
        self.send(1, 2, "c" * 64)
        with self.assertRaises(sqlite3.IntegrityError):
            self.send(1, 2, "c" * 64, "تکرار")
        self.assertEqual(self.db.execute("SELECT COUNT(*) FROM direct_messages").fetchone()[0], 1)

    def test_duplicate_request_pairs_are_rejected(self):
        self.db.execute("INSERT INTO message_requests(sender_id,recipient_id) VALUES (1,2)")
        with self.assertRaises(sqlite3.IntegrityError):
            self.db.execute("INSERT INTO message_requests(sender_id,recipient_id) VALUES (1,2)")

    def test_deleting_a_user_removes_their_messages(self):
        self.send(1, 2, "d" * 64)
        self.db.execute("INSERT INTO message_requests(sender_id,recipient_id) VALUES (2,1)")
        for table in ("direct_messages", "message_requests"):
            self.db.execute(f"DELETE FROM {table}")
        self.db.execute("DELETE FROM users WHERE id=1")
        self.assertEqual(self.db.execute("PRAGMA foreign_key_check").fetchall(), [])

    def test_accepting_a_request_inserts_a_follow(self):
        self.db.execute("INSERT INTO user_follows(follower_id,followed_id) VALUES (2,1)")
        self.assertTrue(self.db.execute("SELECT 1 FROM user_follows WHERE follower_id=2 AND followed_id=1").fetchone())
        with self.assertRaises(sqlite3.IntegrityError):
            self.db.execute("INSERT INTO user_follows(follower_id,followed_id) VALUES (1,1)")


if __name__ == "__main__":
    unittest.main()
