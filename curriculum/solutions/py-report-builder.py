def solve(data):
    metrics = data['metrics']
    if not metrics:
        return {'error': 'empty_metrics'}
    average = sum(metrics.values()) / len(metrics)
    best = min(metrics, key=lambda name: (-metrics[name], name))
    return {'average': average,
            'status': 'excellent' if average >= 90 else 'good' if average >= 70 else 'needs_review',
            'best_metric': best}

if __name__ == '__main__':
    import json
    from pathlib import Path
    data = json.loads(Path('request.json').read_text(encoding='utf-8'))
    result = solve(data)
    Path('result.json').write_text(json.dumps(result, ensure_ascii=False, allow_nan=False), encoding='utf-8')
    print(result)
