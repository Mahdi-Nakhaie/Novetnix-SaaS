import json
from pathlib import Path
import numpy as np


def solve(data):
    features,target=data['features'],data['target']; train,test=data['train'],data['test']
    def number(row,name):
        value=row.get(name)
        try:return float(value) if value is not None else float('nan')
        except (ValueError,TypeError): return float('nan')
    raw=np.asarray([[number(row,name) for name in features] for row in train]); test_raw=np.asarray([[number(row,name) for name in features] for row in test])
    median=np.nanmedian(raw,axis=0); raw=np.where(np.isnan(raw),median,raw); test_raw=np.where(np.isnan(test_raw),median,test_raw); mean=raw.mean(0); scale=raw.std(0); scale[scale==0]=1
    design=np.c_[np.ones(len(raw)),(raw-mean)/scale]; ridge=.5*np.eye(design.shape[1]); ridge[0,0]=0; coef=np.linalg.solve(design.T@design+ridge,design.T@np.asarray([float(row[target]) for row in train]))
    model={'median':median.tolist(),'mean':mean.tolist(),'scale':scale.tolist(),'coef':coef[1:].tolist(),'intercept':float(coef[0])}
    predictions=[]
    for row in test_raw: predictions.append(float(model['intercept']+sum((row[i]-mean[i])/scale[i]*model['coef'][i] for i in range(len(features)))))
    Path('model.json').write_text(json.dumps(model,allow_nan=False))
    return {'predictions':predictions}


if __name__ == '__main__':
    result=solve(json.loads(Path('request.json').read_text())); Path('result.json').write_text(json.dumps(result,allow_nan=False)); print(result)
