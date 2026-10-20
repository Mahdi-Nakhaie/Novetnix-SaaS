import csv
import io

def solve(data):
    totals = {}
    for row in csv.DictReader(io.StringIO(data['csv'])):
        amount = int(row['quantity']) * float(row['price'])
        totals[row['product']] = totals.get(row['product'], 0) + amount
    return {'total': round(sum(totals.values()), 2), 'by_product': {k: round(v, 2) for k, v in totals.items()}}

if __name__ == '__main__':
    import json
    from pathlib import Path
    data = json.loads(Path('request.json').read_text(encoding='utf-8'))
    result = solve(data)
    Path('result.json').write_text(json.dumps(result, ensure_ascii=False, allow_nan=False), encoding='utf-8')
    print(result)
