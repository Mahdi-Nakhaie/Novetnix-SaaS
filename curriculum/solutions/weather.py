import json
from pathlib import Path
import numpy as np


def features(rows):
    out=[]
    for row in rows:
        day=float(row['day_index']); condition=float(row.get('condition',0) or 0)
        out.append([1.0,np.sin(2*np.pi*day/365.0),np.cos(2*np.pi*day/365.0),condition,day/365.0])
    return np.asarray(out)


def solve(data):
    train,test=data['train'],data['test']; x=features(train); xt=features(test); y=np.asarray([float(row[data['target']]) for row in train]); ridge=.5*np.eye(x.shape[1]); ridge[0,0]=0; coef=np.linalg.solve(x.T@x+ridge,x.T@y)
    return {'predictions':(xt@coef).tolist()}


if __name__ == '__main__':
    result=solve(json.loads(Path('request.json').read_text())); Path('result.json').write_text(json.dumps(result,allow_nan=False)); print(result)
