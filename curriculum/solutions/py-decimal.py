from decimal import Decimal, ROUND_HALF_UP

def solve(data):
    total = sum((Decimal(item['price']) * item['quantity'] for item in data['items']), Decimal('0'))
    return {'total': str(total.quantize(Decimal('0.01'), rounding=ROUND_HALF_UP))}

if __name__ == '__main__':
    import json
    from pathlib import Path
    data = json.loads(Path('request.json').read_text(encoding='utf-8'))
    result = solve(data)
    Path('result.json').write_text(json.dumps(result, ensure_ascii=False, allow_nan=False), encoding='utf-8')
    print(result)
