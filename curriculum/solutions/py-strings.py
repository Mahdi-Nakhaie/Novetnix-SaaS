def solve(data):
    words = data['text'].casefold().split()
    counts = {}
    for word in words:
        counts[word] = counts.get(word, 0) + 1
    return {'total': len(words), 'counts': counts}

if __name__ == '__main__':
    import json
    from pathlib import Path
    data = json.loads(Path('request.json').read_text(encoding='utf-8'))
    result = solve(data)
    Path('result.json').write_text(json.dumps(result, ensure_ascii=False, allow_nan=False), encoding='utf-8')
    print(result)
