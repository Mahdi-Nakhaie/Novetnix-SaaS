from datetime import datetime


def solve(data):
    try:
        dates = []
        for name in ('start', 'end'):
            text = data[name]
            value = datetime.strptime(text, '%Y-%m-%d').date()
            if value.isoformat() != text:
                raise ValueError('Invalid format')
            dates.append(value)
        return {'days': (dates[1] - dates[0]).days}
    except ValueError:
        return {'error': 'invalid_date'}

if __name__ == '__main__':
    import json
    from pathlib import Path
    data = json.loads(Path('request.json').read_text(encoding='utf-8'))
    result = solve(data)
    Path('result.json').write_text(json.dumps(result, ensure_ascii=False, allow_nan=False), encoding='utf-8')
    print(result)
