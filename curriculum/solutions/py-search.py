def solve(data):
    query = data['query'].strip().casefold()
    matches = [title for title in data['titles'] if query and query in title.casefold()]
    return {'matches': matches}

if __name__ == '__main__':
    import json
    from pathlib import Path
    data = json.loads(Path('request.json').read_text(encoding='utf-8'))
    result = solve(data)
    Path('result.json').write_text(json.dumps(result, ensure_ascii=False, allow_nan=False), encoding='utf-8')
    print(result)
