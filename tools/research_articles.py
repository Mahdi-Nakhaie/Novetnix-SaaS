import concurrent.futures
import datetime
import json
import pathlib
import urllib.parse
import urllib.request
from html.parser import HTMLParser

ROOT = pathlib.Path(__file__).resolve().parent.parent


class Extract(HTMLParser):
    def __init__(self):
        super().__init__()
        self.skip = 0
        self.tag = None
        self.current = []
        self.title = ''
        self.headings = []
        self.paragraphs = []

    def handle_starttag(self, tag, attrs):
        if tag in ('script', 'style', 'noscript'):
            self.skip += 1
        if not self.skip and tag in ('title', 'h1', 'h2', 'h3', 'p'):
            self.tag, self.current = tag, []

    def handle_endtag(self, tag):
        if tag in ('script', 'style', 'noscript'):
            self.skip = max(0, self.skip - 1)
        if tag == self.tag:
            text = ' '.join(''.join(self.current).split())
            if tag == 'title':
                self.title = text
            elif tag.startswith('h') and text:
                self.headings.append(text[:160])
            elif len(text.split()) >= 20:
                self.paragraphs.append(text)
            self.tag = None

    def handle_data(self, text):
        if self.tag and not self.skip:
            self.current.append(text)


def fetch(item):
    topic, position, url = item
    result = {'topic': topic, 'search_selection_order': position, 'url': url}
    try:
        encoded = urllib.parse.quote(url, safe=':/?=&%~@+(),')
        request = urllib.request.Request(encoded, headers={'User-Agent': 'Noventix-Editorial-Research/1.0'})
        with urllib.request.urlopen(request, timeout=22) as response:
            result['status'] = response.status
            content_type = response.headers.get('Content-Type', '')
            if 'html' not in content_type:
                result['review_status'] = 'not_html'
                return result
            parser = Extract()
            parser.feed(response.read(1500000).decode(response.headers.get_content_charset() or 'utf-8', errors='replace'))
        result.update(title=parser.title, headings=parser.headings[:14], extracted_words=sum(len(p.split()) for p in parser.paragraphs), opening_excerpt=' '.join(parser.paragraphs)[:650])
        result['review_status'] = 'extracted' if result['extracted_words'] >= 100 else 'insufficient_content'
    except Exception as exc:
        result['review_status'] = 'unavailable'
        result['error'] = str(exc)[:180]
    return result


def main():
    candidates = json.loads((ROOT / 'outputs/research/candidates.json').read_text())
    items = [(topic, n, url) for topic, urls in candidates.items() for n, url in enumerate(urls, 1)]
    with concurrent.futures.ThreadPoolExecutor(max_workers=6) as pool:
        reviews = list(pool.map(fetch, items))
    data = {'date': datetime.date.today().isoformat(), 'method': 'Selected search results, excluding video and PDF; includes tutorials, references, course and book landing pages. Selection order is not verified Google Iran rank. HTTP failures and insufficient extracts are not completed reviews.', 'reviews': reviews}
    (ROOT / 'outputs/research/reviews.json').write_text(json.dumps(data, ensure_ascii=False, indent=2))
    for topic in candidates:
        rows = [r for r in reviews if r['topic'] == topic]
        print(topic, sum(r['review_status'] == 'extracted' for r in rows), '/', len(rows))


if __name__ == '__main__':
    main()
