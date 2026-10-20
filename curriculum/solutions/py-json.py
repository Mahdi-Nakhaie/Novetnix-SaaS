import json

def solve(data):
    try:
        settings = json.loads(data['settings'])
    except json.JSONDecodeError:
        return {'error': 'invalid_json'}
    if not isinstance(settings, dict):
        return {'error': 'invalid_settings'}
    result = {'theme': 'light', 'page_size': 10}
    result.update({k: v for k, v in settings.items() if k in result})
    return result

if __name__ == '__main__':
    import json
    from pathlib import Path
    data = json.loads(Path('request.json').read_text(encoding='utf-8'))
    result = solve(data)
    Path('result.json').write_text(json.dumps(result, ensure_ascii=False, allow_nan=False), encoding='utf-8')
    print(result)
