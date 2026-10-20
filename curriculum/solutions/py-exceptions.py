def solve(data):
    numbers, invalid = [], []
    for index, text in enumerate(data['values']):
        try:
            numbers.append(int(text))
        except ValueError:
            invalid.append(index)
    return {'numbers': numbers, 'invalid_indices': invalid}

if __name__ == '__main__':
    import json
    from pathlib import Path
    data = json.loads(Path('request.json').read_text(encoding='utf-8'))
    result = solve(data)
    Path('result.json').write_text(json.dumps(result, ensure_ascii=False, allow_nan=False), encoding='utf-8')
    print(result)
