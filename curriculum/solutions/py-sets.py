def solve(data):
    seen, unique = set(), []
    for name in data['names']:
        if name not in seen:
            seen.add(name)
            unique.append(name)
    return {'unique': unique, 'removed': len(data['names']) - len(unique)}

if __name__ == '__main__':
    import json
    from pathlib import Path
    data = json.loads(Path('request.json').read_text(encoding='utf-8'))
    result = solve(data)
    Path('result.json').write_text(json.dumps(result, ensure_ascii=False, allow_nan=False), encoding='utf-8')
    print(result)
