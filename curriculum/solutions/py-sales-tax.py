from decimal import Decimal, ROUND_HALF_UP


def solve(data):
    subtotal = sum((Decimal(item['price']) * item['quantity']
                    for item in data['items']), Decimal('0'))
    tax = (subtotal * Decimal(data['tax_rate'])).quantize(
        Decimal('0.01'), rounding=ROUND_HALF_UP)
    return {'subtotal': format(subtotal, '.2f'), 'tax': format(tax, '.2f'),
            'total': format(subtotal + tax, '.2f')}

if __name__ == '__main__':
    import json
    from pathlib import Path
    data = json.loads(Path('request.json').read_text(encoding='utf-8'))
    result = solve(data)
    Path('result.json').write_text(json.dumps(result, ensure_ascii=False, allow_nan=False), encoding='utf-8')
    print(result)
