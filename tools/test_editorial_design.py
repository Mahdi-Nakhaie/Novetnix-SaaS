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
    def test_public_redesign_is_no_longer_loaded(self):
        self.assertNotIn('/assets/editorial.css', VIEWS)

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


class HeaderLayoutTest(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.css = (ROOT / 'public/assets/header.css').read_text(encoding='utf-8')

    def test_shared_header_styles_exclude_private_panels(self):
        self.assertIn("if (!$panel) echo '<link rel=\"stylesheet\" href=\"/assets/header.css?v=6\">';", VIEWS)
        for selector in re.findall(r'([^{}]+)\{', self.css):
            if not selector.strip().startswith('@'):
                self.assertTrue(selector.strip().startswith('body .site-header'), selector)

    def test_centering_uses_equal_side_columns_and_symmetric_padding(self):
        self.assertIn('grid-template-columns: minmax(0, 1fr) auto minmax(0, 1fr);', self.css)
        self.assertIn('grid-template-areas: "brand nav actions";', self.css)
        self.assertNotIn('grid-template-areas: "brand actions" "nav nav";', self.css)
        self.assertIn('padding: var(--space-4) var(--header-gutter);', self.css)
        self.assertIn('max-width: none;', self.css)
        self.assertIn('justify-content: center;', self.css)
        self.assertNotIn('position: absolute', self.css)

    def test_navigation_only_increases_spacing_and_type(self):
        self.assertRegex(self.css, r'\.main-nav a\s*\{[^}]*font-size: clamp\(\.78rem, \.5vw \+ \.42rem, 1\.125rem\);')
        self.assertIn('font-size: clamp(.75rem, .82vw, .95rem);', self.css)
        self.assertIn('gap: clamp(2px, .35vw, 8px);', self.css)
        self.assertIn('gap: clamp(3px, .4vw, 10px);', self.css)
        self.assertIn('flex-wrap: nowrap;', self.css)
        self.assertIn('min-height: 48px;', self.css)
        self.assertIn('@media (min-width: 1181px) and (max-width: 1919px)', self.css)
        self.assertIn('@media (max-width: 1180px)', self.css)
        self.assertIn('@media (max-width: 560px)', self.css)

    def test_navigation_stays_on_one_row_between_brand_and_actions(self):
        self.assertIn('grid-template-areas: "brand nav actions";', self.css)
        self.assertNotIn('"brand actions"', self.css)
        self.assertNotIn('"nav nav"', self.css)
        self.assertRegex(self.css, r'\.main-nav\s*\{[^}]*flex-wrap: nowrap;')
        self.assertRegex(self.css, r'\.main-nav\s*\{[^}]*justify-self: center;')
        self.assertRegex(self.css, r'\.header-actions \.btn\s*\{[^}]*flex: 0 0 auto;')

    def test_mobile_alignment_and_focus_are_preserved(self):
        self.assertIn('grid-template-areas: "brand toggle";', self.css)
        self.assertIn('padding-inline: var(--header-gutter);', self.css)
        self.assertRegex(self.css, r'\.mobile-nav a\s*\{[^}]*justify-content: center;')
        self.assertIn(':focus-visible', self.css)
        self.assertNotIn('outline: none', self.css)
        self.assertNotIn('animation:', self.css)


if __name__ == '__main__':
    unittest.main()
