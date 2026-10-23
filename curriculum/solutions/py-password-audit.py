def solve(data):
    results = []
    for value in data['passwords']:
        letter = any('a' <= c.lower() <= 'z' for c in value)
        digit = any('0' <= c <= '9' for c in value)
        symbol = any(c in '!@#$%^&*' for c in value)
        results.append({'length': len(value), 'has_letter': letter,
                        'has_digit': digit, 'has_symbol': symbol,
                        'strong': len(value) >= 8 and letter and digit and symbol})
    return {'results': results}

if __name__ == '__main__':
    import json
    from pathlib import Path
    data = json.loads(Path('request.json').read_text(encoding='utf-8'))
    result = solve(data)
    Path('result.json').write_text(json.dumps(result, ensure_ascii=False, allow_nan=False), encoding='utf-8')
    print(result)
