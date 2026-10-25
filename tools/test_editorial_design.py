import pathlib
import re
import unittest


ROOT = pathlib.Path(__file__).resolve().parent.parent
CSS = (ROOT / 'public/assets/editorial.css').read_text(encoding='utf-8')
VIEWS = (ROOT / 'src/views.php').read_text(encoding='utf-8')
BASE = (ROOT / 'public/assets/style.css').read_text(encoding='utf-8')


def contrast(first, second):
    def luminance(color):
        channels = [int(color[i:i + 2], 16) / 255 for i in (1, 3, 5)]
        linear = [c / 12.92 if c <= .04045 else ((c + .055) / 1.055) ** 2.4 for c in channels]
        return sum(c * weight for c, weight in zip(linear, (.2126, .7152, .0722)))
    light, dark = sorted((luminance(first), luminance(second)), reverse=True)
    return (light + .05) / (dark + .05)


class EditorialDesignTest(unittest.TestCase):
    def test_stylesheet_is_limited_to_selected_public_routes(self):
        line = next(line for line in VIEWS.splitlines() if '/assets/editorial.css' in line)
        self.assertIn('if (!$panel &&', line)
        self.assertIn("['','articles','projects','pricing','about','contact','community']", line)
        self.assertIn("$path==='course/'.FREE_COURSE_SLUG", line)
        self.assertIn("str_starts_with($path,'articles/')", line)
        self.assertNotIn('dashboard', line)
        self.assertNotIn('login', line)
        self.assertNotIn('register', line)

    def test_every_selector_excludes_panels(self):
        selectors = re.findall(r'([^{}]+)\{', CSS)
        for selector in selectors:
            if not selector.strip().startswith('@'):
                self.assertTrue(selector.strip().startswith('body:not(.is-panel)'), selector)
        self.assertNotIn('.panel-card', CSS)
        self.assertNotIn('.stat-card', CSS)

    def test_existing_brand_and_font_are_preserved(self):
        self.assertNotRegex(CSS, r'#[0-9a-fA-F]{3,8}\b')
        self.assertNotIn('font-family:', CSS)
        for token in ('--brand', '--gold', '--dark', '--ink-2', '--card'):
            self.assertIn('var(' + token + ')', CSS)
            self.assertIn(token + ':', BASE)

    def test_text_and_focus_token_contrast(self):
        tokens = dict(re.findall(r'(--[\w-]+):\s*(#[0-9A-Fa-f]{6})\b', BASE))
        self.assertGreaterEqual(contrast(tokens['--ink-2'], tokens['--card']), 4.5)
        self.assertGreaterEqual(contrast(tokens['--brand'], tokens['--card']), 4.5)
        self.assertGreaterEqual(contrast(tokens['--card'], tokens['--dark']), 4.5)
        self.assertGreaterEqual(contrast(tokens['--gold'], tokens['--dark']), 3)

    def test_mobile_reading_order_and_code_scroll(self):
        self.assertIn('@media (max-width: 1180px)', CSS)
        self.assertIn('@media (max-width: 560px)', CSS)
        self.assertRegex(CSS, r'\.hero-code-display\s*\{[^}]*order:\s*0;')
        self.assertRegex(CSS, r'\.hero-code-window pre\s*\{[^}]*overflow-x:\s*auto;')
        self.assertIn('grid-template-columns: minmax(0, 1fr);', CSS)
        self.assertIn('flex-direction: column;', CSS)
        self.assertIn('white-space: normal;', CSS)

    def test_reduced_motion_and_visible_focus(self):
        self.assertIn('@media (prefers-reduced-motion: reduce)', CSS)
        self.assertIn('animation: none !important;', CSS)
        self.assertIn('transition: none !important;', CSS)
        self.assertIn(':focus-visible', CSS)
        self.assertIn('outline: 3px solid var(--brand);', CSS)
        self.assertIn('outline-color: var(--card);', CSS)


if __name__ == '__main__':
    unittest.main()
