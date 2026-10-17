import json
from pathlib import Path


def solve(data):
    a, b, op = data['a'], data['b'], data['operator']
    if op not in ('+', '-', '*', '/'):
        return {'error': 'invalid_operator'}
    if op == '/' and b == 0:
        return {'error': 'division_by_zero'}
    return {'value': {'+': lambda: a+b, '-': lambda: a-b, '*': lambda: a*b, '/': lambda: a/b}[op]()}


if __name__ == '__main__':
    result = solve(json.loads(Path('request.json').read_text()))
    Path('result.json').write_text(json.dumps(result, allow_nan=False))
    print(result)
