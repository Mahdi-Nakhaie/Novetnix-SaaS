def solve(data):
    totals = {}
    for item in data['expenses']:
        category = item['category']
        totals[category] = totals.get(category, 0) + item['amount']
    return {'by_category': totals, 'total': sum(totals.values())}

if __name__ == '__main__':
    import json
    from pathlib import Path
    data = json.loads(Path('request.json').read_text(encoding='utf-8'))
    result = solve(data)
    Path('result.json').write_text(json.dumps(result, ensure_ascii=False, allow_nan=False), encoding='utf-8')
    print(result)
