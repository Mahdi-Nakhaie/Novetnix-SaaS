def solve(data):
    table = str.maketrans('۰۱۲۳۴۵۶۷۸۹٠١٢٣٤٥٦٧٨٩', '01234567890123456789')
    value = data['value'].translate(table).replace(' ', '').replace('-', '')
    if len(value) != 6 or any(c not in '0123456789' for c in value):
        return {'error': 'invalid_id'}
    return {'normalized': value}

if __name__ == '__main__':
    import json
    from pathlib import Path
    data = json.loads(Path('request.json').read_text(encoding='utf-8'))
    result = solve(data)
    Path('result.json').write_text(json.dumps(result, ensure_ascii=False, allow_nan=False), encoding='utf-8')
    print(result)
