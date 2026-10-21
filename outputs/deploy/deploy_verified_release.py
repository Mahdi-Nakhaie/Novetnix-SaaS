import hashlib
import http.client
import json
import os
from pathlib import Path
import shutil
import subprocess
import sys

ROOT = Path('/var/www/noventix')
STAGE = Path(sys.argv[1]).resolve()
assert STAGE.parent == Path('/var/backups/noventix')
manifest = json.loads((STAGE / 'release-manifest.json').read_text())
files = manifest['files']
backup = STAGE / 'previous'
assert not backup.exists()


def digest(path):
    return hashlib.sha256(path.read_bytes()).hexdigest()


def run(command):
    result = subprocess.run(command, capture_output=True, timeout=60)
    if result.returncode:
        raise RuntimeError('Command failed: ' + command[0])


for name, entry in files.items():
    assert not Path(name).is_absolute() and '..' not in Path(name).parts
    source, target = STAGE / name, ROOT / name
    assert source.is_file() and not source.is_symlink() and digest(source) == entry['sha256'], name
    assert target.parent.is_dir() and not target.is_symlink(), name
    if entry['before'] is None:
        assert not target.exists(), 'Unexpected production file: ' + name
    else:
        assert target.is_file() and digest(target) == entry['before'], 'Production drift: ' + name
    if name.endswith('.php'):
        run(['php', '-l', str(source)])

runner_changed = 'curriculum/grading.py' in files
if runner_changed:
    code = "require '/var/www/noventix/src/app.php'; if ((int)q(\"SELECT COUNT(*) FROM workspace_runs WHERE state IN ('queued','running','cancelling')\")->fetchColumn()>0) exit(2);"
    run(['php', '-r', code])

backup.mkdir()
for name, entry in files.items():
    if entry['before'] is not None:
        saved = backup / name
        saved.parent.mkdir(parents=True, exist_ok=True)
        shutil.copy2(ROOT / name, saved)


def install(source, target):
    temporary = target.with_name(target.name + '.release-new')
    assert not temporary.exists()
    shutil.copy2(source, temporary)
    os.chmod(temporary, 0o644)
    os.replace(temporary, target)


def probe(path, expected):
    connection = http.client.HTTPSConnection('noventix.ir', timeout=30)
    try:
        connection.request('GET', path, headers={'User-Agent': 'Noventix-Release-Check', 'Cache-Control': 'no-cache'})
        response = connection.getresponse()
        body = response.read()
        assert response.status == expected, (path, response.status)
        assert b'Fatal error' not in body and b'PDOException' not in body, path
        if expected == 303:
            assert response.getheader('Location', '').startswith('/login?next='), path
        if path in ('/login', '/course/ai-foundations'):
            assert b'/assets/style.css?v=15' in body, 'Stale HTML: ' + path
        if 'content/articles/catalog.json' in files and path in ('/', '/articles'):
            assert b'<meta name="google-site-verification" content="ac80Ad6zRSLvvfcYFAMfV2JVVj8rUKlz49RpTpzwv70">' in body.split(b'</head>', 1)[0], 'Missing Google verification'
        if path == '/articles' and 'content/articles/catalog.json' in files:
            catalog = json.loads((STAGE / 'content/articles/catalog.json').read_text())
            assert all(('/articles/' + a['slug']).encode() in body for a in catalog), 'Missing article cards'
        if path == '/projects' and 'curriculum/catalog.json' in files:
            catalog = json.loads((STAGE / 'curriculum/catalog.json').read_text())
            assert all(('href="/project/' + str(i) + '"').encode() in body for i in range(len(catalog))), 'Missing project cards'
        if path == '/glossary' and 'src/glossary.php' in files:
            assert b'glossary-motion' not in body, 'Unexpected motion control'
            assert b'glossary-all-topics' in body, 'Missing all-topics link'
            categories = body.split(b'<nav class="glossary-categories"', 1)[1].split(b'</nav>', 1)[0]
            assert categories.count(b'<a ') == 25, 'Incorrect category grid'
        if path.startswith('/assets/'):
            asset = ROOT / ('public' + path.split('?', 1)[0])
            assert hashlib.sha256(body).hexdigest() == digest(asset), 'Stale asset: ' + path
        print('HTTP', response.status, path, flush=True)
    finally:
        connection.close()


installed = []
try:
    ordered = sorted(files, key=lambda name: (2 if name.startswith('src/') else 1 if name == 'database/connection.php' else 0, name == 'src/app.php', name))
    for name in ordered:
        install(STAGE / name, ROOT / name)
        installed.append(name)
    if runner_changed:
        run(['systemctl', 'restart', 'noventix-runner.service'])
        run(['systemctl', 'is-active', 'noventix-runner.service'])
    run(['systemctl', 'reload', 'php8.2-fpm.service'])
    run(['systemctl', 'is-active', 'php8.2-fpm.service'])
    code = '''require '/var/www/noventix/src/app.php';
    $database=db();
    $lesson=q("SELECT l.id FROM lessons l JOIN courses c ON c.id=l.course_id WHERE c.slug=? AND c.status='published' AND l.position=1 AND l.status='published'",['ai-foundations'])->fetchColumn();
    if (!$lesson || lesson_quiz_for_lesson((int)$lesson)===null) exit(2);
    q('SELECT user_id,request_key FROM darycheh_product_requests LIMIT 0');
    q('SELECT revision FROM darycheh_drafts LIMIT 0');'''
    if 'curriculum/glossary-category-expansion.json' in files:
        code += '''
        foreach (glossary_categories() as $category) if ((int)$category['count']<5) exit(3);
        '''
    run(['php', '-r', code])
    print('Database migrations and published lesson quiz verified.', flush=True)
    if 'src/glossary.php' in files:
        probe('/glossary', 200)
        probe('/glossary?category=python&letter=P', 200)
        probe('/glossary/python', 200)
    for path, expected in [('/', 200), ('/login', 200), ('/course/ai-foundations', 200), ('/articles', 200), ('/dashboard/course/ai-foundations', 303), ('/dashboard/darycheh', 303), ('/dashboard/darycheh-manage', 303), ('/darycheh/progress', 401), ('/assets/style.css?v=15', 200), ('/assets/darycheh.js?v=1', 200), ('/assets/darycheh.css?v=1', 200)]:
        probe(path, expected)
    if 'content/articles/catalog.json' in files:
        probe('/projects', 200)
        catalog = json.loads((STAGE / 'content/articles/catalog.json').read_text())
        for article in catalog:
            if article.get('series') == 'python-basics':
                probe('/articles/' + article['slug'], 200)
                probe('/project/' + str(article['project_index']), 200)
        probe('/sitemap.xml', 200)
    if runner_changed:
        run(['php', '-r', "require '/var/www/noventix/src/app.php'; $health=ws_runner('GET','/health'); if (($health['isolation'] ?? '')!=='gvisor') exit(2);"])
        print('Isolated Python runner health verified through the application helper.', flush=True)
    for name, entry in files.items():
        assert digest(ROOT / name) == entry['sha256'], name
except Exception:
    for name in reversed(installed):
        if files[name]['before'] is None:
            (ROOT / name).unlink()
        else:
            install(backup / name, ROOT / name)
    if runner_changed:
        run(['systemctl', 'restart', 'noventix-runner.service'])
    run(['systemctl', 'reload', 'php8.2-fpm.service'])
    print('Source rollback completed; additive database changes retained.', flush=True)
    raise
print('Release ' + manifest['revision'] + ' deployed; source backup: ' + str(backup), flush=True)
