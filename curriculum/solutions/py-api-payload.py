def solve(data):
    payload = data['payload']
    if not isinstance(payload.get('name'), str) or not isinstance(payload.get('email'), str):
        return {'valid': False}
    name, email = payload['name'].strip(), payload['email'].strip()
    parts = email.split('@')
    valid = (bool(name) and len(parts) == 2 and all(parts)
             and not any(c.isspace() for c in email)
             and type(payload.get('age')) is int and payload['age'] >= 0)
    if not valid:
        return {'valid': False}
    return {'valid': True, 'name': name, 'email': email, 'age': payload['age']}

if __name__ == '__main__':
    import json
    from pathlib import Path
    data = json.loads(Path('request.json').read_text(encoding='utf-8'))
    result = solve(data)
    Path('result.json').write_text(json.dumps(result, ensure_ascii=False, allow_nan=False), encoding='utf-8')
    print(result)
