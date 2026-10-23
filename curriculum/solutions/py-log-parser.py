def solve(data):
    counts = {}
    for raw in data['levels']:
        level = raw.strip().upper()
        if level:
            counts[level] = counts.get(level, 0) + 1
    return {'counts': counts}

if __name__ == '__main__':
    import json
    from pathlib import Path
    data = json.loads(Path('request.json').read_text(encoding='utf-8'))
    result = solve(data)
    Path('result.json').write_text(json.dumps(result, ensure_ascii=False, allow_nan=False), encoding='utf-8')
    print(result)
