def solve(data):
    stock = {}
    for item in data['items']:
        name = item['name']
        stock[name] = stock.get(name, 0) + item['quantity']
    return {'stock': stock, 'total': sum(stock.values())}

if __name__ == '__main__':
    import json
    from pathlib import Path
    data = json.loads(Path('request.json').read_text(encoding='utf-8'))
    result = solve(data)
    Path('result.json').write_text(json.dumps(result, ensure_ascii=False, allow_nan=False), encoding='utf-8')
    print(result)
