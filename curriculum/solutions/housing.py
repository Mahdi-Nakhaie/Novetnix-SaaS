import json
from pathlib import Path
import numpy as np


def solve(data):
    features,target=data['features'],data['target']; train,test=data['train'],data['test']
    x=np.asarray([[float(row.get(name,0) or 0) for name in features] for row in train]); xt=np.asarray([[float(row.get(name,0) or 0) for name in features] for row in test]); y=np.asarray([float(row[target]) for row in train])
    center=x.mean(0); scale=x.std(0); scale[scale==0]=1; design=np.c_[np.ones(len(x)),(x-center)/scale]; coef=np.linalg.solve(design.T@design+.1*np.eye(design.shape[1]),design.T@y)
    return {'predictions':(np.c_[np.ones(len(xt)),(xt-center)/scale]@coef).tolist()}


if __name__ == '__main__':
    result=solve(json.loads(Path('request.json').read_text())); Path('result.json').write_text(json.dumps(result,allow_nan=False)); print(result)
