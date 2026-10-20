from pathlib import PurePosixPath

def solve(data):
    counts = {}
    for name in data['names']:
        suffix = PurePosixPath(name).suffix.lower() or '[none]'
        counts[suffix] = counts.get(suffix, 0) + 1
    return {'extensions': counts}

if __name__ == '__main__':
    import json
    from pathlib import Path
    data = json.loads(Path('request.json').read_text(encoding='utf-8'))
    result = solve(data)
    Path('result.json').write_text(json.dumps(result, ensure_ascii=False, allow_nan=False), encoding='utf-8')
    print(result)
