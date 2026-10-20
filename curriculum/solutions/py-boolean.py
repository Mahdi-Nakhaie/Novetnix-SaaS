def solve(data):
    text = data['text']
    checks = {'length': len(text) >= 8, 'upper': any(c.isupper() for c in text), 'lower': any(c.islower() for c in text), 'digit': any(c.isdigit() for c in text)}
    return {'checks': checks, 'valid': all(checks.values())}

if __name__ == '__main__':
    import json
    from pathlib import Path
    data = json.loads(Path('request.json').read_text(encoding='utf-8'))
    result = solve(data)
    Path('result.json').write_text(json.dumps(result, ensure_ascii=False, allow_nan=False), encoding='utf-8')
    print(result)
