import json
from pathlib import Path
from collections import Counter
import numpy as np


def solve(data):
    features,target=data['features'],data['target']; train,test=data['train'],data['test']
    def value(row,name):
        try:return float(row.get(name,0) or 0)
        except (ValueError,TypeError): return float({'setosa':0,'versicolor':1,'virginica':2}.get(row.get(name),0))
    x=np.asarray([[value(row,name) for name in features] for row in train]); xt=np.asarray([[value(row,name) for name in features] for row in test]); y=[row[target] for row in train]
    scale=x.std(0); scale[scale==0]=1
    predictions=[]
    for row in ((xt[:,None,:]-x[None,:,:])/scale):
        d=(row*row).sum(1); nearest=np.argsort(d)[:7]; predictions.append(Counter(y[i] for i in nearest).most_common(1)[0][0])
    return {'predictions':predictions}


if __name__ == '__main__':
    result=solve(json.loads(Path('request.json').read_text())); Path('result.json').write_text(json.dumps(result,allow_nan=False)); print(result)
