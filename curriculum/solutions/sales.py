import csv
import io
import json
import math
from pathlib import Path


def solve(data):
    rows = list(csv.DictReader(io.StringIO(data['csv'])))
    if not rows:
        return {'error': 'empty_data'}
    products = {}
    try:
        for row in rows:
            quantity, price = float(row['quantity']), float(row['price'])
            if not math.isfinite(quantity) or not math.isfinite(price) or quantity < 0 or price < 0 or not row['product']:
                return {'error': 'invalid_row'}
            products[row['product']] = products.get(row['product'], 0) + quantity*price
    except (ValueError, KeyError, TypeError):
        return {'error': 'invalid_row'}
    return {'total': sum(products.values()), 'by_product': products}


if __name__ == '__main__':
    result = solve(json.loads(Path('request.json').read_text()))
    Path('result.json').write_text(json.dumps(result, allow_nan=False))
    print(result)
