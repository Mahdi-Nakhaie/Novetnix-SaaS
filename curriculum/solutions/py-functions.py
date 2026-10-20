def subtotal(items):
    return sum(item['price'] * item['quantity'] for item in items)

def solve(data):
    discount = data['discount']
    if not 0 <= discount <= 100:
        return {'error': 'invalid_discount'}
    amount = subtotal(data['items'])
    return {'subtotal': round(amount, 2), 'total': round(amount * (1 - discount / 100), 2)}

if __name__ == '__main__':
    import json
    from pathlib import Path
    data = json.loads(Path('request.json').read_text(encoding='utf-8'))
    result = solve(data)
    Path('result.json').write_text(json.dumps(result, ensure_ascii=False, allow_nan=False), encoding='utf-8')
    print(result)
