def solve(data):
    matrix = data['matrix']
    size = len(matrix)
    if any(len(row) != size for row in matrix):
        return {'error': 'invalid_matrix'}
    return {'row_sums': [sum(row) for row in matrix],
            'column_sums': [sum(row[i] for row in matrix) for i in range(size)],
            'diagonal': sum(matrix[i][i] for i in range(size))}

if __name__ == '__main__':
    import json
    from pathlib import Path
    data = json.loads(Path('request.json').read_text(encoding='utf-8'))
    result = solve(data)
    Path('result.json').write_text(json.dumps(result, ensure_ascii=False, allow_nan=False), encoding='utf-8')
    print(result)
