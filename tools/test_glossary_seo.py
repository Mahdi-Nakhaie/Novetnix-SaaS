import pathlib
import re
import unittest

ROOT = pathlib.Path(__file__).resolve().parent.parent
SEO = (ROOT / "src/seo.php").read_text(encoding="utf-8")
VIEWS = (ROOT / "src/views.php").read_text(encoding="utf-8")
ROUTER = (ROOT / "src/router.php").read_text(encoding="utf-8")
GEN = (ROOT / "tools/gen.py").read_text(encoding="utf-8")
SITE = ROOT / "site"


class GlossarySeoTest(unittest.TestCase):
    def test_dynamic_metadata_receives_real_indexability(self):
        self.assertIn("seo_head($title,$description ?:", VIEWS)
        self.assertIn("$panel,$indexable", VIEWS)
        self.assertEqual(VIEWS.count('<meta name="robots" content='), 0)
        self.assertIn("function seo_head(string $title,string $description,bool $panel,bool $indexable): void", SEO)
        self.assertIn("publication_status='published'", SEO)
        self.assertIn("og:image", SEO)

    def test_glossary_filters_are_not_indexable(self):
        self.assertIn("$query==='' && $category==='' && $letter==='' && (int)$page===1 && user()===null", ROUTER)
        self.assertIn("head_page('لغت‌نامهٔ هوش مصنوعی',fa((int)$result['total'])", ROUTER)

    def test_static_glossary_pages_have_real_seo(self):
        page = (SITE / "glossary" / "transformer" / "index.html").read_text(encoding="utf-8")
        self.assertIn('<html lang="fa" dir="rtl">', page)
        self.assertIn('<meta name="robots" content="index,follow">', page)
        self.assertIn('<link rel="canonical" href="/Novetnix-SaaS/site/glossary/transformer">', page)
        self.assertIn('"@type": "DefinedTerm"', page)
        self.assertIn('"name": "Transformer"', page)
        self.assertIn('aria-label="مسیر صفحه"', page)
        self.assertEqual(page.count('<meta name="robots"'), 1)

    def test_static_sitemap_and_robots_cover_only_public_glossary(self):
        sitemap = (SITE / "sitemap.xml").read_text(encoding="utf-8")
        robots = (SITE / "robots.txt").read_text(encoding="utf-8")
        self.assertIn("/glossary/transformer</loc>", sitemap)
        self.assertNotIn("/panel/", sitemap)
        self.assertIn("Disallow: /panel", robots)
        self.assertIn("Sitemap: /Novetnix-SaaS/site/sitemap.xml", robots)

    def test_static_related_links_resolve_to_generated_terms(self):
        for page in (SITE / "glossary").glob("*/index.html"):
            html = page.read_text(encoding="utf-8")
            for slug in re.findall(r'/Novetnix-SaaS/site/glossary/([a-z0-9-]+)', html):
                self.assertTrue((SITE / "glossary" / slug / "index.html").is_file(), slug)


if __name__ == "__main__":
    unittest.main()
