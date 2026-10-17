import json
from pathlib import Path


def solve(data):
    grades = data['grades']
    if not grades:
        return {'error': 'empty_grades'}
    if any(not isinstance(g, (int, float)) or isinstance(g, bool) or not 0 <= g <= 20 for g in grades):
        return {'error': 'invalid_grade'}
    passed = sum(g >= 10 for g in grades)
    return {'mean': sum(grades)/len(grades), 'min': min(grades), 'max': max(grades), 'passed': passed, 'failed': len(grades)-passed}


if __name__ == '__main__':
    result = solve(json.loads(Path('request.json').read_text()))
    Path('result.json').write_text(json.dumps(result, allow_nan=False))
    print(result)
