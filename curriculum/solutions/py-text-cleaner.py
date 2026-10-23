def solve(data):
    names, seen = [], set()
    for raw in data['names']:
        name = ' '.join(raw.split())
        if name and name not in seen:
            seen.add(name)
            names.append(name)
    return {'names': names, 'count': len(names)}

if __name__ == '__main__':
    import json
    from pathlib import Path
    data = json.loads(Path('request.json').read_text(encoding='utf-8'))
    result = solve(data)
    Path('result.json').write_text(json.dumps(result, ensure_ascii=False, allow_nan=False), encoding='utf-8')
    print(result)
