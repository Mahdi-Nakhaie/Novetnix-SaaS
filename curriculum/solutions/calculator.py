import json
from pathlib import Path
import numpy as np


def matrix(rows, features):
    values=[]
    for row in rows:
        values.append([float(row.get(name, 0) or 0) for name in features])
    return np.asarray(values, dtype=float)


def solve(data):
    features, target = data['features'], data['target']
    train, test = data['train'], data['test']
    x, y = matrix(train, features), np.asarray([float(row[target]) for row in train])
    center, scale = x.mean(0), x.std(0); scale[scale == 0] = 1
    xz = (x-center)/scale
    design = np.c_[np.ones(len(xz)), xz]
    ridge = 0.1*np.eye(design.shape[1]); ridge[0,0] = 0
    coef = np.linalg.solve(design.T@design+ridge, design.T@y)
    predictions = np.c_[np.ones(len(test)), (matrix(test,features)-center)/scale]@coef
    return {'predictions': predictions.tolist()}


if __name__ == '__main__':
    result = solve(json.loads(Path('request.json').read_text()))
    Path('result.json').write_text(json.dumps(result, allow_nan=False))
    print(result)
