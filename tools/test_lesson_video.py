import json
import os
import pathlib
import re
import shutil
import subprocess
import unittest


ROOT = pathlib.Path(__file__).resolve().parent.parent
APP = (ROOT / 'src/app.php').read_text(encoding='utf-8')
VIDEO = (ROOT / 'src/lesson_video.php').read_text(encoding='utf-8')
VIEWS = (ROOT / 'src/views.php').read_text(encoding='utf-8')
ADMIN = (ROOT / 'src/course_admin.php').read_text(encoding='utf-8')
PLAYER_JS = (ROOT / 'public/assets/free-course.js').read_text(encoding='utf-8')

HASHES = ['ereh151', 'jlckro2', 'ooz6d75', 'bakrn6p', 'atrkj08', 'frzh61x']


class LessonVideoTest(unittest.TestCase):
    def test_all_six_sessions_are_seeded_with_their_own_video(self):
        block = APP[APP.index('const AI_FOUNDATIONS_LESSONS'):APP.index('function seed_ai_foundations')]
        hashes = re.findall(r"^\s*\['([a-z0-9]+)'", block, re.M)
        self.assertEqual(hashes, HASHES)

    def test_every_frame_starts_at_zero(self):
        self.assertIn("https://www.aparat.com/video/video/embed/videohash/%s/vt/frame?titleShow=true&startTime=0", VIDEO)
        self.assertIn('&startTime=0', PLAYER_JS)
        self.assertNotIn('startTime=', VIEWS)

    def test_embed_code_is_reduced_to_its_hash(self):
        self.assertIn('videohash', function_body(VIDEO, 'lesson_aparat_hash'))
        self.assertNotRegex(VIEWS, r'aparat\.com/embed/(?!\'\.h\(\$videoHash)')

    def test_php_parses_user_embeds_into_aparat_urls(self):
        php = shutil.which(os.environ.get('PHP_BIN', 'php'))
        if not php:
            self.skipTest('php is not installed')
        script = f'''require "{ROOT}/src/lesson_video.php";
echo json_encode([
 lesson_aparat_hash("https://www.aparat.com/embed/frzh61x?data[rnddiv]=83415919261&data[responsive]=yes&titleShow=true&startTime=0"),
 lesson_aparat_hash("https://www.aparat.com/video/video/embed/videohash/ereh151/vt/frame"),
 lesson_aparat_hash("https://www.aparat.com/v/atrkj08"),
 lesson_aparat_hash("jlckro2"),
 lesson_aparat_hash("https://evil.example/embed/x"),
 lesson_aparat_frame("ooz6d75"),
]);'''
        parsed = json.loads(subprocess.check_output([php, '-r', script], text=True))
        self.assertEqual(parsed[:5], ['frzh61x', 'ereh151', 'atrkj08', 'jlckro2', None])
        self.assertEqual(parsed[5], 'https://www.aparat.com/video/video/embed/videohash/ooz6d75/vt/frame?titleShow=true&startTime=0')

    def test_workspace_renders_the_selected_lesson_video(self):
        body = function_body(VIEWS, 'page_course_workspace')
        self.assertIn("lesson_aparat_hash($current['video_url']", body)
        self.assertIn('lesson_aparat_frame($videoHash)', body)
        self.assertNotIn('qbk51d2', VIEWS)

    def test_admin_can_attach_a_video_and_bad_input_is_rejected(self):
        self.assertIn('name="video_url"', function_body(ADMIN, 'course_admin_forms'))
        save = function_body(ADMIN, 'course_admin_save')
        self.assertIn('lesson_aparat_hash($video)===null', save)
        self.assertIn('lesson_video_save($savedId,$video)', save)

    def test_player_script_only_builds_aparat_urls_from_the_hash(self):
        self.assertIn("const hash = button.dataset.aparatHash", PLAYER_JS)
        self.assertIn("'https://www.aparat.com/embed/' + hash", PLAYER_JS)
        self.assertIn('startTime=0', PLAYER_JS)


def function_body(source, name):
    start = source.index('function ' + name)
    depth = 0
    for index in range(start, len(source)):
        if source[index] == '{':
            depth += 1
        elif source[index] == '}':
            depth -= 1
            if depth == 0:
                return source[start:index + 1]
    raise AssertionError(name)


if __name__ == '__main__':
    unittest.main()
