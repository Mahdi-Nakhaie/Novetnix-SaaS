import base64
import csv
import hashlib
import io
import json
import math
import random
import struct
import time
import zlib
from pathlib import Path

ROOT = Path(__file__).resolve().parent


def version():
    return hashlib.sha256(b''.join((ROOT / name).read_bytes() for name in ('catalog.json', 'grading.py', 'data/manifest.json'))).hexdigest()


def definitions():
    return json.loads((ROOT / 'catalog.json').read_text())


def pack(raw):
    if isinstance(raw, str):
        raw = raw.encode()
    return {'kind': 'file', 'data': base64.b64encode(raw).decode()}


def unpack(files, name):
    return base64.b64decode(files[name]['data'], validate=True)


def close(actual, expected):
    if isinstance(expected, dict):
        return isinstance(actual, dict) and actual.keys() == expected.keys() and all(close(actual[k], v) for k, v in expected.items())
    if isinstance(expected, list):
        return isinstance(actual, list) and len(actual) == len(expected) and all(close(a, b) for a, b in zip(actual, expected))
    if isinstance(expected, (int, float)):
        return type(actual) in (int, float) and math.isfinite(actual) and math.isclose(actual, expected, rel_tol=1e-5, abs_tol=1e-5)
    return actual == expected


def csv_text(rows):
    stream = io.StringIO(newline='')
    writer = csv.DictWriter(stream, fieldnames=list(rows[0]))
    writer.writeheader()
    writer.writerows(rows)
    return stream.getvalue()


def dataset(name):
    sources = json.loads((ROOT / 'data/manifest.json').read_text())
    source = next(s for s in sources if s['file'] == name)
    raw = (ROOT / 'data' / name).read_bytes()
    if hashlib.sha256(raw).hexdigest() != source['sha256']:
        raise ValueError('Dataset checksum mismatch')
    return list(csv.DictReader(io.StringIO(raw.decode())))


def sales_summary(rows):
    products, months = {}, {}
    for row in rows:
        amount = float(row['quantity']) * float(row['price'])
        products[row['product']] = products.get(row['product'], 0) + amount
        months[row['month']] = months.get(row['month'], 0) + amount
    return sum(products.values()), products, months


def cases(key, seed):
    rng = random.Random(seed)
    ml_aliases = {
        'calculator': ('housing.csv', 'price', 'regression'),
        'grades': ('iris.csv', 'species', 'classification'),
        'sales': ('diabetes.csv', 'class', 'classification'),
        'cleaning': ('sentiment.csv', 'label', 'classification'),
        'advanced-sales': ('flights.csv', 'arr_delay', 'regression'),
    }
    if key in ml_aliases:
        name, target, role = ml_aliases[key]
        rows = dataset(name)
        features = [k for k in rows[0] if k != target and k != 'date']
        result = []
        for _ in range(2):
            groups = {}
            if role == 'classification':
                for row in rows: groups.setdefault(row[target], []).append(row)
            else: groups['all'] = rows
            train, test = [], []
            for group in groups.values():
                group = group.copy(); rng.shuffle(group); cut = int(len(group)*0.75)
                train.extend(group[:cut]); test.extend(group[cut:])
            rng.shuffle(train); rng.shuffle(test)
            def value(k, v): return v if k in ('text','species') else float(v)
            training = [{k: value(k,r[k]) for k in features+[target]} for r in train]
            testing = [{k: value(k,r[k]) for k in features} for r in test]
            result.append(({'train': training, 'test': testing, 'features': features, 'target': target}, [value(target,r[target]) for r in test]))
        return result
    if key == 'weather':
        rows = dataset('weather.csv')
        result = []
        for start in (0, 120):
            train_rows, test_rows = rows[start:start+500], rows[start+500:start+560]
            training = [{'day_index': float(start+i), 'condition': {'clear':0.0,'cloudy':1.0,'rain':2.0}.get(r['condition'],0.0), 'temperature': float(r['temperature'])} for i,r in enumerate(train_rows)]
            testing = [{'day_index': float(start+500+i), 'condition': {'clear':0.0,'cloudy':1.0,'rain':2.0}.get(r['condition'],0.0)} for i,r in enumerate(test_rows)]
            result.append(({'train':training,'test':testing,'features':['day_index','condition'],'target':'temperature'}, [float(r['temperature']) for r in test_rows]))
        return result
    if key == 'calculator':
        a, b = rng.randint(-100, 100), rng.randint(1, 99)
        return [({'a': a, 'b': b, 'operator': op}, {'value': value}) for op, value in [('+', a+b), ('-', a-b), ('*', a*b), ('/', a/b)]] + [({'a': 7, 'b': 0, 'operator': '/'}, {'error': 'division_by_zero'}), ({'a': 1, 'b': 2, 'operator': '?'}, {'error': 'invalid_operator'})]
    if key == 'grades':
        grades = [rng.randrange(0, 41)/2 for _ in range(12)] + [10]
        passed = sum(g >= 10 for g in grades)
        return [({'grades': grades}, {'mean': sum(grades)/len(grades), 'min': min(grades), 'max': max(grades), 'passed': passed, 'failed': len(grades)-passed}), ({'grades': []}, {'error': 'empty_grades'}), ({'grades': [21]}, {'error': 'invalid_grade'}), ({'grades': [0, 10, 20]}, {'mean': 10, 'min': 0, 'max': 20, 'passed': 2, 'failed': 1})]
    if key in ('sales', 'advanced-sales'):
        result = []
        for count in (1, 14):
            rows = [{'product': rng.choice(['book','pen','bag']), 'quantity': rng.randrange(0, 9), 'price': rng.randrange(1, 200)/2, 'month': rng.choice(['2026-01','2026-02','2026-03'])} for _ in range(count)]
            total, products, months = sales_summary(rows)
            expected = {'total': total, 'by_product': products} if key == 'sales' else {'total': total, 'by_month': months, 'orders': count, 'average_order': total/count}
            result.append(({'csv': csv_text(rows)}, expected))
        if key == 'sales':
            result += [({'csv': 'product,quantity,price,month\n'}, {'error': 'empty_data'}), ({'csv': 'product,quantity,price,month\nbook,-1,5,2026-01\n'}, {'error': 'invalid_row'}), ({'csv': 'product,quantity,price,month\nbook,no,5,2026-01\n'}, {'error': 'invalid_row'})]
        return result
    if key == 'cleaning':
        a, b = rng.randint(10, 40), rng.randint(50, 90)
        rows = [{'name':'A','age':a,'score':b}, {'name':'B','age':'','score':b+10}, {'name':'C','age':a+10,'score':''}]
        return [({'csv': csv_text(rows)}, {'missing_before': 2, 'means': {'age': a+5, 'score': b+5}}), ({'csv': 'name,age,score\nA,,5\nB,,7\n'}, {'error': 'all_missing'}), ({'csv': 'name,age,score\nA,20,10\nB,30,20\n'}, {'missing_before': 0, 'means': {'age':25,'score':15}})]
    if key == 'weather':
        rows = dataset('weather.csv')
        start = rng.randrange(len(rows)-10)
        result = []
        for sample in (rows[start:start+8], rows[start:start+1]):
            values = [float(r['temperature']) for r in sample]
            result.append(({'csv':csv_text(sample)}, {'mean':sum(values)/len(values),'min':min(values),'max':max(values),'changes':[b-a for a,b in zip(values,values[1:])]}))
        return result
    names = {'housing':('housing.csv','price'), 'iris':('iris.csv','species'), 'diabetes':('diabetes.csv','class'), 'flights':('flights.csv','arr_delay'), 'sentiment':('sentiment.csv','label'), 'pipeline':('housing.csv','price')}
    name, target = names[key]
    rows = dataset(name)
    features = [k for k in rows[0] if k != target and k != 'date']
    result = []
    for _ in range(2):
        groups = {}
        if key in ('iris','diabetes','sentiment'):
            for row in rows:
                groups.setdefault(row[target], []).append(row)
        else:
            groups['all'] = rows
        train, test = [], []
        for group in groups.values():
            group = group.copy(); rng.shuffle(group)
            cut = int(len(group)*0.75)
            train.extend(group[:cut]); test.extend(group[cut:])
        rng.shuffle(train); rng.shuffle(test)
        def value(k, v):
            return v if k in ('text','species') else float(v)
        training = [{k: value(k,r[k]) for k in features+[target]} for r in train]
        testing = [{k: value(k,r[k]) for k in features} for r in test]
        if key == 'pipeline':
            for row in training+testing:
                if rng.random() < 0.1:
                    row[rng.choice(features)] = None
        expected = [value(target,r[target]) for r in test]
        result.append(({'train': training, 'test': testing, 'features': features, 'target': target}, expected))
    return result


def png_valid(raw):
    if not raw.startswith(b'\x89PNG\r\n\x1a\n') or len(raw)>1_048_576:
        return False
    offset, compressed, dimensions, ended = 8, bytearray(), None, False
    while offset+12 <= len(raw):
        size = int.from_bytes(raw[offset:offset+4], 'big')
        kind = raw[offset+4:offset+8]
        content = raw[offset+8:offset+8+size]
        if offset+12+size > len(raw) or zlib.crc32(kind+content) & 0xffffffff != int.from_bytes(raw[offset+8+size:offset+12+size], 'big'):
            return False
        if kind == b'IHDR':
            if size != 13: return False
            width, height, depth, color, compression, filtering, interlace = struct.unpack('>IIBBBBB', content)
            if not (320 <= width <= 1600 and 200 <= height <= 1200 and depth == 8 and color in (2,6) and compression == filtering == interlace == 0): return False
            dimensions = width, height, 3 if color == 2 else 4
        elif kind == b'IDAT': compressed.extend(content)
        elif kind == b'IEND': ended = True; break
        offset += size+12
    if not ended or not dimensions: return False
    decoder = zlib.decompressobj()
    decoded = decoder.decompress(bytes(compressed), 8_000_001)
    width, height, channels = dimensions
    return decoder.eof and len(decoded) == height*(width*channels+1) and len(set(decoded))>8


def validate(key, payload, actual, expected, files):
    regression_keys = {'housing','flights','pipeline','calculator','advanced-sales','weather'}
    classification_keys = {'iris','diabetes','sentiment','grades','sales','cleaning'}
    if key in regression_keys | classification_keys:
        if not isinstance(actual, dict) or set(actual) != {'predictions'}: return False
        predictions = actual['predictions']
        if not isinstance(predictions,list) or len(predictions)!=len(expected): return False
        if key in regression_keys:
            if any(type(v) not in (int,float) or not math.isfinite(v) for v in predictions): return False
            mae = sum(abs(a-b) for a,b in zip(predictions, expected))/len(expected)
            if mae > {'housing':8,'flights':15,'pipeline':9,'calculator':8,'advanced-sales':15,'weather':5}[key]: return False
        else:
            labels = set(expected)
            if any(type(v) not in (int,float,str) or v not in labels for v in predictions): return False
            accuracy = sum(a==b for a,b in zip(predictions,expected))/len(expected)
            scores=[]
            for label in labels:
                tp = sum(a==b==label for a,b in zip(predictions,expected))
                fp = sum(a==label and b!=label for a,b in zip(predictions,expected))
                fn = sum(a!=label and b==label for a,b in zip(predictions,expected))
                scores.append(2*tp/(2*tp+fp+fn) if 2*tp+fp+fn else 0)
            if min(accuracy,sum(scores)/len(scores)) < {'iris':0.85,'diabetes':0.80,'sentiment':0.72,'grades':0.85,'sales':0.80,'cleaning':0.72}[key]: return False
        if key=='pipeline':
            model=json.loads(unpack(files,'model.json'))
            if set(model)!={'median','mean','scale','coef','intercept'}: return False
            count=len(payload['features'])
            for field in ('median','mean','scale','coef'):
                if not isinstance(model[field],list) or len(model[field])!=count or any(type(v) not in (int,float) or not math.isfinite(v) for v in model[field]): return False
            if type(model['intercept']) not in (int,float) or not math.isfinite(model['intercept']) or any(v<=0 for v in model['scale']): return False
            restored=[]
            for row in payload['test']:
                values=[model['median'][i] if row[k] is None else row[k] for i,k in enumerate(payload['features'])]
                restored.append(sum((v-model['mean'][i])/model['scale'][i]*model['coef'][i] for i,v in enumerate(values))+model['intercept'])
            if not close(predictions,restored): return False
        return True
    if not close(actual, expected): return False
    if key in ('weather','advanced-sales'):
        return png_valid(unpack(files,'chart.png'))
    if key == 'cleaning' and 'error' not in expected:
        rows = list(csv.DictReader(io.StringIO(unpack(files,'cleaned.csv').decode())))
        original = list(csv.DictReader(io.StringIO(payload['csv'])))
        if len(rows)!=len(original): return False
        for a,b in zip(rows,original):
            if set(a)!=set(b) or a['name']!=b['name']: return False
            for column in ('age','score'):
                if not close(float(a[column]), float(b[column]) if b[column]!='' else expected['means'][column]): return False
    return True


def grade(job, execute, image, cancelled):
    spec = job['assessment']
    if spec['version'] != version():
        return {'state': 'infrastructure_error', 'stdout': '', 'stderr': 'Curriculum version mismatch.', 'files': {}}
    checks, last = [], None
    seed = int(hashlib.sha256(job['id'].encode()).hexdigest()[:16], 16)
    started = time.monotonic()
    for index, (payload, expected) in enumerate(cases(spec['key'], seed)):
        if cancelled():
            return {'state': 'cancelled', 'stdout': '', 'stderr': '', 'files': {}}
        if time.monotonic()-started > 120:
            return {'state':'timeout','stdout':'','stderr':'Assessment time budget exceeded.','files':{}}
        files = {k: v for k, v in job['files'].items() if k in ('main.py','requirements.txt')}
        files['request.json'] = pack(json.dumps(payload, allow_nan=False))
        # Expectations, holdout labels and verdict logic never enter the sandbox.
        run = execute({**job, 'files': files, 'entrypoint': 'main.py'}, image, lambda: cancelled() or time.monotonic()-started>120)
        last = run
        if run['state'] in ('infrastructure_error', 'cancelled'):
            return run
        passed = False
        if run['state'] == 'succeeded':
            try:
                actual = json.loads(unpack(run['files'],'result.json'))
                passed = validate(spec['key'], payload, actual, expected, run['files'])
            except (ValueError, KeyError, TypeError, OverflowError, ZeroDivisionError, zlib.error, UnicodeError):
                pass
        checks.append({'name': f'معیار {index+1}', 'passed': passed, 'execution_state': run['state']})
    return {'state': 'succeeded', 'stdout': (last or {}).get('stdout', ''), 'stderr': (last or {}).get('stderr', ''),
            'files': {k:v for k,v in (last or {}).get('files', {}).items() if k in ('result.json','chart.png','cleaned.csv','model.json')},
            'assessment': {'key': spec['key'], 'version': spec['version'], 'passed': bool(checks) and all(c['passed'] for c in checks), 'checks': checks}}
