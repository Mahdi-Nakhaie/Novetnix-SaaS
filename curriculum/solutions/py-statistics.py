from statistics import mean, median

def solve(data):
    values = data['minutes']
    if not values:
        return {'error': 'empty_data'}
    return {'mean': mean(values), 'median': median(values), 'spread': max(values) - min(values)}

if __name__ == '__main__':
    import json
    from pathlib import Path
    data = json.loads(Path('request.json').read_text(encoding='utf-8'))
    result = solve(data)
    Path('result.json').write_text(json.dumps(result, ensure_ascii=False, allow_nan=False), encoding='utf-8')
    print(result)
