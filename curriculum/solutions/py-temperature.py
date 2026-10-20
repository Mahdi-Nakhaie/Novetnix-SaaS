def solve(data):
    value, unit = data['value'], data['unit']
    if unit == 'C':
        return {'value': round(value * 9 / 5 + 32, 2), 'unit': 'F'}
    if unit == 'F':
        return {'value': round((value - 32) * 5 / 9, 2), 'unit': 'C'}
    return {'error': 'invalid_unit'}

if __name__ == '__main__':
    import json
    from pathlib import Path
    data = json.loads(Path('request.json').read_text(encoding='utf-8'))
    result = solve(data)
    Path('result.json').write_text(json.dumps(result, ensure_ascii=False, allow_nan=False), encoding='utf-8')
    print(result)
