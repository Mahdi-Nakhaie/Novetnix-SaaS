import numpy as np

def solve(data):
    values = np.asarray(data['values'], dtype=float)
    if values.size == 0:
        return {'error': 'empty_data'}
    average = float(values.mean())
    return {'mean': average, 'max': float(values.max()), 'centered': (values - average).tolist()}

if __name__ == '__main__':
    import json
    from pathlib import Path
    data = json.loads(Path('request.json').read_text(encoding='utf-8'))
    result = solve(data)
    Path('result.json').write_text(json.dumps(result, ensure_ascii=False, allow_nan=False), encoding='utf-8')
    print(result)
