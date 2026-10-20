def solve(data):
    values = data['values']
    if not values:
        return {'count': 0, 'min': None, 'max': None, 'mean': None}
    return {'count': len(values), 'min': min(values), 'max': max(values), 'mean': sum(values) / len(values)}

if __name__ == '__main__':
    import json
    from pathlib import Path
    data = json.loads(Path('request.json').read_text(encoding='utf-8'))
    result = solve(data)
    Path('result.json').write_text(json.dumps(result, ensure_ascii=False, allow_nan=False), encoding='utf-8')
    print(result)
