import pandas as pd

def solve(data):
    if not data['rows']:
        return {'total': 0, 'by_category': {}}
    frame = pd.DataFrame(data['rows'], columns=['category', 'amount'])
    grouped = frame.groupby('category')['amount'].sum()
    return {'total': float(frame['amount'].sum()), 'by_category': {str(k): float(v) for k, v in grouped.items()}}

if __name__ == '__main__':
    import json
    from pathlib import Path
    data = json.loads(Path('request.json').read_text(encoding='utf-8'))
    result = solve(data)
    Path('result.json').write_text(json.dumps(result, ensure_ascii=False, allow_nan=False), encoding='utf-8')
    print(result)
