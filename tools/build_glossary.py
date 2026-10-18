#!/usr/bin/env python3
"""Build curriculum/glossary.json from curriculum/glossary_source.py.

The source file is the single origin of every entry. This script validates it,
adds derived fields (sort key, search terms, letter, counts) and writes a
deterministic JSON file that the PHP app reads at runtime.
"""
import json
import pathlib
import re
import sys

ROOT = pathlib.Path(__file__).resolve().parents[1] / 'curriculum'
sys.path.insert(0, str(ROOT))
import glossary_source  # noqa: E402

ARABIC = {'\u064a': '\u06cc', '\u0643': '\u06a9', '\u0649': '\u06cc'}
TASHKEEL = re.compile('[\u064b-\u065f\u0670\u0640]')
DIACRITIC_STRIP = TASHKEEL
CONTROL = re.compile(r'[\u0000-\u001f\u007f\u0980-\u09ff\u200e\u200f\u202a-\u202e]')
KEY = re.compile(r'^[a-z0-9]+(-[a-z0-9]+)*$')


def clean(value):
    for source, target in ARABIC.items():
        value = value.replace(source, target)
    value = DIACRITIC_STRIP.sub('', value)
    return re.sub(r'[ \t]+', ' ', value).strip()


def search_text(value):
    value = clean(value).lower()
    value = re.sub(r'[\u200c\u200d]', ' ', value)
    value = re.sub(r'[()\[\]«»:"\'.,،؛;!?؟/\\_+*]', ' ', value)
    return re.sub(r'\s+', ' ', value).strip()


def build():
    errors = []
    seen_terms, seen_titles, seen_keys = {}, {}, {}
    entries = []
    categories = {key: clean(label) for key, label in glossary_source.CATEGORIES.items()}
    if len(set(categories.values())) != len(categories):
        errors.append('duplicate category labels')
    for raw in glossary_source.TERMS:
        key, term, title, category, related, definition = raw
        term, title, definition = clean(term), clean(title), clean(definition)
        if not KEY.match(key):
            errors.append(f'{key}: invalid key')
        if key in seen_keys:
            errors.append(f'{key}: duplicate key')
        if term.lower() in seen_terms:
            errors.append(f'{key}: duplicate term {term}')
        if title in seen_titles:
            errors.append(f'{key}: duplicate Persian title {title} ({seen_titles[title]})')
        if category not in categories:
            errors.append(f'{key}: unknown category {category}')
        head = re.sub(r'\s*\([^()]*\)$', '', title)
        if not definition.startswith(head + '؛') and definition != title:
            errors.append(f'{key}: definition must start with «{title}؛»')
        if len(definition) < 40:
            errors.append(f'{key}: definition too short')
        if CONTROL.search(term + title + definition):
            errors.append(f'{key}: control or foreign-script characters')
        if '  ' in term + title + definition or '\n' in definition:
            errors.append(f'{key}: repeated whitespace')
        seen_keys[key] = term
        seen_terms[term.lower()] = key
        seen_titles[title] = key
        entries.append(dict(key=key, term=term, title=title, category=category, related=list(related), definition=definition))
    for entry in entries:
        for link in entry['related']:
            if link not in seen_keys:
                errors.append(f"{entry['key']}: related term {link} not found")
            elif link == entry['key']:
                errors.append(f"{entry['key']}: links to itself")
    if errors:
        raise SystemExit('glossary data invalid:\n  ' + '\n  '.join(errors))

    entries.sort(key=lambda item: (re.sub(r'[^a-z0-9 ]', ' ', item['term'].lower()).strip(), item['term']))
    for entry in entries:
        entry['search'] = search_text(entry['term'] + ' ' + entry['title'] + ' ' + entry['definition'])
        entry['letter'] = entry['term'][0].upper() if entry['term'][:1].isascii() and entry['term'][:1].isalpha() else '#'
        entry['related'].sort()
    counts = {key: 0 for key in categories}
    for entry in entries:
        counts[entry['category']] += 1
    payload = {
        'version': 1,
        'categories': [dict(key=key, label=label, count=counts[key]) for key, label in categories.items()],
        'entries': entries,
    }
    return payload


def main():
    payload = build()
    output = ROOT / 'glossary.json'
    output.write_text(json.dumps(payload, ensure_ascii=False, indent=1, sort_keys=True) + '\n', encoding='utf-8')
    print(f'wrote {output.relative_to(ROOT.parent)}: {len(payload["entries"])} terms, {len(payload["categories"])} categories')


if __name__ == '__main__':
    main()
