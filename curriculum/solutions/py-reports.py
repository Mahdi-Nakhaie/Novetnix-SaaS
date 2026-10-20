def passed_count(grades):
    return sum(grade >= 10 for grade in grades)

def solve(data):
    grades = data['grades']
    if not grades:
        return {'error': 'empty_grades'}
    if any(not 0 <= grade <= 20 for grade in grades):
        return {'error': 'invalid_grade'}
    passed = passed_count(grades)
    return {'mean': sum(grades) / len(grades), 'passed': passed, 'failed': len(grades) - passed}

if __name__ == '__main__':
    import json
    from pathlib import Path
    data = json.loads(Path('request.json').read_text(encoding='utf-8'))
    result = solve(data)
    Path('result.json').write_text(json.dumps(result, ensure_ascii=False, allow_nan=False), encoding='utf-8')
    print(result)
