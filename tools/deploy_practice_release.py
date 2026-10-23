import hashlib
import http.client
import json
import os
from pathlib import Path
import shutil
import sqlite3
import subprocess
import sys
import tarfile
import time
import xml.etree.ElementTree as ET
from html.parser import HTMLParser

RELEASE = 'practice-expansion-20261023'
SERVER = 'root@62.60.191.247'
LIVE = Path('/var/www/noventix')
DATABASE = Path('/var/lib/noventix/noventix.sqlite')


def digest(path):
    return hashlib.sha256(path.read_bytes()).hexdigest()


def remote_python(code):
    result = subprocess.run(['ssh', '-p', '22', SERVER, 'python3 -'], input=code, text=True, capture_output=True, check=True, timeout=60)
    return json.loads(result.stdout)


class Document(HTMLParser):
    def __init__(self, text):
        super().__init__()
        self.h1 = 0
        self.ids = []
        self.meta = {}
        self.canonical = None
        self.json_ld = []
        self.in_schema = False
        self.feed(text)

    def handle_starttag(self, tag, attrs):
        attrs = dict(attrs)
        if tag == 'h1':
            self.h1 += 1
        if 'id' in attrs:
            self.ids.append(attrs['id'])
        if tag == 'meta':
            self.meta[attrs.get('name', attrs.get('property'))] = attrs.get('content')
        if tag == 'link' and attrs.get('rel') == 'canonical':
            self.canonical = attrs.get('href')
        if tag == 'script' and attrs.get('type') == 'application/ld+json':
            self.in_schema = True

    def handle_endtag(self, tag):
        if tag == 'script':
            self.in_schema = False

    def handle_data(self, text):
        if self.in_schema:
            self.json_ld.append(json.loads(text))


def request(path, expected=200):
    connection = http.client.HTTPSConnection('noventix.ir', timeout=30)
    try:
        connection.request('GET', path, headers={'Cache-Control': 'no-cache'})
        response = connection.getresponse()
        text = response.read().decode('utf-8')
        assert response.status == expected, (path, response.status)
        assert 'Fatal error' not in text and 'PDOException' not in text, path
        if expected == 303:
            assert response.getheader('Location', '').startswith('/login'), path
        return text
    finally:
        connection.close()


def local_release():
    root = Path(__file__).resolve().parents[1]
    projects = json.loads((root / 'curriculum/catalog.json').read_text())
    articles = json.loads((root / 'content/articles/catalog.json').read_text())
    fixed = ['curriculum/catalog.json', 'curriculum/beginner_cases.json', 'curriculum/project-guidance.json',
             'content/articles/catalog.json', 'database/connection.php', 'curriculum/glossary-practice-expansion.json']
    files = fixed + ['curriculum/solutions/' + p['key'] + '.py' for p in projects[36:]]
    files += ['content/articles/' + a['slug'] + '.txt' for a in articles[-12:]]
    files += ['tools/test_practice_expansion.py']
    baseline = remote_python('''import json,hashlib,pathlib,sqlite3
r=pathlib.Path('/var/www/noventix')
names=%s
hashes={n:hashlib.sha256((r/n).read_bytes()).hexdigest() if (r/n).is_file() else None for n in names}
data={n:json.loads((r/n).read_text()) for n in %s}
d=sqlite3.connect('file:/var/lib/noventix/noventix.sqlite?mode=ro',uri=True)
print(json.dumps({'hashes':hashes,'data':data,'terms':d.execute('SELECT slug,term_en,canonical_key FROM glossary_terms').fetchall(),'counts':dict(d.execute('SELECT publication_status,COUNT(*) FROM glossary_terms GROUP BY publication_status').fetchall()),'active':d.execute("SELECT COUNT(*) FROM workspace_runs WHERE state IN ('queued','running','cancelling')").fetchone()[0]}))
''' % (repr(files), repr(fixed[:4])))
    assert baseline['active'] == 0, 'Active assessments; defer release to avoid stale versions.'
    for name in fixed[:4]:
        current = json.loads((root / name).read_text())
        previous = baseline['data'][name]
        if isinstance(previous, list):
            assert current[:len(previous)] == previous, ('Existing content changed', name)
            assert len(current) - len(previous) == 12, name
        else:
            assert all(current[key] == value for key, value in previous.items()), ('Existing guidance/cases changed', name)
            assert len(current) - len(previous) == 12, name
    glossary = json.loads((root / 'curriculum/glossary-practice-expansion.json').read_text())
    old_slugs = {row[0] for row in baseline['terms']}
    old_terms = {row[1].casefold() for row in baseline['terms']}
    assert all(e['key'] not in old_slugs and e['term'].casefold() not in old_terms for e in glossary['entries']), 'Glossary collision'
    manifest = {name: {'before': baseline['hashes'][name], 'sha256': digest(root / name)} for name in files}
    output = root / 'outputs/deploy'
    assert output.is_dir()
    archive = output / (RELEASE + '.tar')
    import io
    with tarfile.open(archive, 'w') as tar:
        for name in files:
            tar.add(root / name, arcname=name)
        tar.add(Path(__file__), arcname='deploy.py')
        raw = json.dumps({'files': manifest, 'counts': baseline['counts']}).encode()
        info = tarfile.TarInfo('manifest.json')
        info.size = len(raw)
        tar.addfile(info, io.BytesIO(raw))
    stage = '/var/backups/noventix/' + RELEASE + '-' + str(int(time.time()))
    subprocess.run(['ssh', '-p', '22', SERVER, 'test -d /var/backups/noventix && mkdir ' + stage], check=True, timeout=30)
    subprocess.run(['scp', '-P', '22', str(archive), SERVER + ':' + stage + '/release.tar'], check=True, timeout=60)
    command = 'tar -xf ' + stage + '/release.tar -C ' + stage + ' && python3 ' + stage + '/deploy.py --remote ' + stage
    subprocess.run(['ssh', '-p', '22', SERVER, command], check=True, timeout=240)


def install(source, target):
    temp = target.with_name(target.name + '.practice-release')
    assert not temp.exists()
    shutil.copy2(source, temp)
    temp.chmod(0o644)
    os.replace(temp, target)


def remote_release(stage):
    assert stage.parent == Path('/var/backups/noventix')
    manifest = json.loads((stage / 'manifest.json').read_text())
    files = manifest['files']
    for name, info in files.items():
        assert not Path(name).is_absolute() and '..' not in Path(name).parts
        target = LIVE / name
        assert target.parent.is_dir() and not target.is_symlink()
        assert digest(stage / name) == info['sha256']
        assert (not target.exists()) if info['before'] is None else digest(target) == info['before'], name
    for name in ('config',):
        (stage / name).symlink_to(LIVE / name, target_is_directory=True)
    (stage / 'database/migrations').symlink_to(LIVE / 'database/migrations', target_is_directory=True)
    for name in ('glossary.json', 'glossary_initial_drafts.json', 'glossary-category-expansion.json'):
        (stage / 'curriculum' / name).symlink_to(LIVE / 'curriculum' / name)
    subprocess.run(['php', '-l', str(stage / 'database/connection.php')], check=True, timeout=20)
    subprocess.run(['python3', str(stage / 'tools/test_practice_expansion.py')], check=True, timeout=60)
    source = (stage / 'database/connection.php').read_text()
    previous = (LIVE / 'database/connection.php').read_text()
    expected = source.replace("function glossary_expand_categories(PDO $db, string $release='category-expansion-20261021', string $filename='glossary-category-expansion.json'): void {", "function glossary_expand_categories(PDO $db): void {")
    expected = expected.replace("    $check=$db->prepare('SELECT release_key FROM glossary_content_releases WHERE release_key=?');", "    $release='category-expansion-20261021';\n    $check=$db->prepare('SELECT release_key FROM glossary_content_releases WHERE release_key=?');")
    expected = expected.replace("file_get_contents(dirname(__DIR__).'/curriculum/'.$filename)", "file_get_contents(dirname(__DIR__).'/curriculum/glossary-category-expansion.json')")
    expected = expected.replace("        glossary_expand_categories($db,'practice-expansion-20261023','glossary-practice-expansion.json');\n", '')
    assert expected == previous, 'Database source contains unrelated changes compared with production'
    database = sqlite3.connect('file:' + str(DATABASE) + '?mode=ro', uri=True)
    assert database.execute("SELECT COUNT(*) FROM workspace_runs WHERE state IN ('queued','running','cancelling')").fetchone()[0] == 0
    before = database.execute('SELECT * FROM glossary_terms ORDER BY id').fetchall()
    columns = [c[1] for c in database.execute('PRAGMA table_info(glossary_terms)')]
    glossary = json.loads((stage / 'curriculum/glossary-practice-expansion.json').read_text())
    for entry in glossary['entries']:
        assert database.execute('SELECT COUNT(*) FROM glossary_terms WHERE slug=? OR LOWER(term_en)=LOWER(?)', (entry['key'], entry['term'])).fetchone()[0] == 0
    backup = stage / 'previous'
    backup.mkdir()
    for name, info in files.items():
        if info['before'] is not None:
            saved = backup / name
            saved.parent.mkdir(parents=True, exist_ok=True)
            shutil.copy2(LIVE / name, saved)
    installed = []
    try:
        for name in sorted(files, key=lambda n: n == 'database/connection.php'):
            install(stage / name, LIVE / name)
            installed.append(name)
        subprocess.run(['systemctl', 'reload', 'php8.2-fpm.service'], check=True, timeout=30)
        subprocess.run(['systemctl', 'is-active', 'php8.2-fpm.service'], check=True, timeout=20)
        for path in ('/', '/articles', '/projects', '/glossary'):
            request(path)
        request('/dashboard', 303)
        request('/dashboard/users', 303)
        projects = json.loads((LIVE / 'curriculum/catalog.json').read_text())
        articles = json.loads((LIVE / 'content/articles/catalog.json').read_text())
        for index in range(36, 48):
            text = request('/project/' + str(index))
            assert projects[index]['title'] in text
            assert '/articles/' + projects[index]['prerequisite_articles'][0] in text
        for article in articles[-12:]:
            path = '/articles/' + article['slug']
            text = request(path)
            document = Document(text)
            assert document.h1 == 1
            assert len(document.ids) == len(set(document.ids))
            assert document.canonical == 'https://noventix.ir' + path
            assert document.meta['description'] == article['description']
            assert document.meta['robots'] == 'index,follow'
            assert document.meta['og:type'] == 'article'
            assert document.meta['og:locale'] == 'fa_IR'
            graph = document.json_ld[0]['@graph']
            assert any(n['@type'] == 'Article' and n['headline'] == article['title'] and n['wordCount'] >= 600 for n in graph)
            assert any(n['@type'] == 'BreadcrumbList' for n in graph)
        for entry in glossary['entries']:
            text = request('/glossary/' + entry['key'])
            assert entry['title'] in text and entry['example'] in text
        xml = request('/sitemap.xml')
        urls = [n.text for n in ET.fromstring(xml).iter('{http://www.sitemaps.org/schemas/sitemap/0.9}loc')]
        assert len(urls) == len(set(urls))
        for entry in glossary['entries']:
            assert 'https://noventix.ir/glossary/' + entry['key'] in urls
        for article in articles[-12:]:
            assert 'https://noventix.ir/articles/' + article['slug'] in urls
        for index in range(36, 48):
            assert 'https://noventix.ir/project/' + str(index) in urls
        php = "require '/var/www/noventix/src/data.php'; require '/var/www/noventix/src/learning_projects.php'; echo lp_version();"
        php_version = subprocess.check_output(['php', '-r', php], text=True, timeout=20)
        sys.path.insert(0, str(LIVE / 'curriculum'))
        import grading
        assert php_version == grading.version()
        after = database.execute('SELECT * FROM glossary_terms ORDER BY id').fetchall()
        assert after[:len(before)] == before, 'Preexisting glossary content changed'
        assert len(after) == len(before) + 100
        status_index = columns.index('publication_status')
        assert sum(r[status_index] == 'draft' for r in before) == sum(r[status_index] == 'draft' for r in after)
        counts = dict(database.execute('SELECT publication_status,COUNT(*) FROM glossary_terms GROUP BY publication_status'))
        assert counts['published'] == manifest['counts']['published'] + 100
        for name, info in files.items():
            assert digest(LIVE / name) == info['sha256']
        print(json.dumps({'projects': len(projects), 'articles': len(articles), 'glossary': counts, 'http_checked': 131, 'assessment_versions_match': True, 'backup': str(backup)}))
    except Exception:
        for name in reversed(installed):
            if files[name]['before'] is not None:
                install(backup / name, LIVE / name)
            else:
                (LIVE / name).unlink()
        subprocess.run(['systemctl', 'reload', 'php8.2-fpm.service'], check=True, timeout=30)
        print('Source rollback completed; any committed additive glossary entries are preserved.')
        raise
    finally:
        database.close()


if __name__ == '__main__':
    if sys.argv[1:] and sys.argv[1] == '--remote':
        remote_release(Path(sys.argv[2]).resolve())
    else:
        local_release()
