import json
import re
from pathlib import Path
from collections import Counter,defaultdict
import numpy as np


def numeric(rows, features):
    out=[]
    for row in rows:
        values=[]
        for name in features:
            try: values.append(float(row.get(name,0) or 0))
            except (TypeError,ValueError): values.append(1.0 if str(row.get(name)).lower() in ('yes','positive','true') else 0.0)
        out.append(values)
    return np.asarray(out,dtype=float)


def solve(data):
    train,test,features,target=data['train'],data['test'],data['features'],data['target']
    x=numeric(train,features); xt=numeric(test,features); y=[row[target] for row in train]
    center=x.mean(0); scale=x.std(0); scale[scale==0]=1
    predictions=[]
    for row in ((xt[:,None,:]-x[None,:,:])/scale):
        distance=(row*row).sum(1); nearest=np.argsort(distance)[:9]
        votes=Counter(y[i] for i in nearest); predictions.append(votes.most_common(1)[0][0])
    return {'predictions':predictions}


if __name__ == '__main__':
    result=solve(json.loads(Path('request.json').read_text()))
    Path('result.json').write_text(json.dumps(result,allow_nan=False))
    print(result)
