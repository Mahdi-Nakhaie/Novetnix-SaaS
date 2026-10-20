from datetime import date

def solve(data):
    try:
        today = date.fromisoformat(data['today'])
        due = date.fromisoformat(data['due'])
    except ValueError:
        return {'error': 'invalid_date'}
    days = (due - today).days
    return {'days': days, 'overdue': days < 0}

if __name__ == '__main__':
    import json
    from pathlib import Path
    data = json.loads(Path('request.json').read_text(encoding='utf-8'))
    result = solve(data)
    Path('result.json').write_text(json.dumps(result, ensure_ascii=False, allow_nan=False), encoding='utf-8')
    print(result)
