import json
from pathlib import Path
import numpy as np


def encode(rows, features):
    maps={}
    columns=[]
    for name in features:
        raw=[row.get(name) for row in rows]
        try: columns.append(np.asarray([float(v) for v in raw],dtype=float))
        except (TypeError,ValueError):
            labels={v:i for i,v in enumerate(sorted(set(raw)))}
            maps[name]=labels; columns.append(np.asarray([labels[v] for v in raw],dtype=float))
    return np.column_stack(columns), maps


def solve(data):
    train, test, features, target = data['train'], data['test'], data['features'], data['target']
    x, maps = encode(train, features); y=[row[target] for row in train]
    xt=[]
    for row in test:
        xt.append([float(row[name]) if name not in maps else maps[name].get(row[name],0) for name in features])
    xt=np.asarray(xt,dtype=float); center=x.mean(0); scale=x.std(0); scale[scale==0]=1
    distances=((xt[:,None,:]-x[None,:,:])/scale)**2
    predictions=[]
    for distance in distances.sum(2):
        nearest=np.argsort(distance)[:7]
        votes={label:sum(1/(1+distance[i]) for i in nearest if y[i]==label) for label in set(y)}
        predictions.append(max(votes,key=votes.get))
    return {'predictions': predictions}


if __name__ == '__main__':
    result=solve(json.loads(Path('request.json').read_text()))
    Path('result.json').write_text(json.dumps(result,allow_nan=False))
    print(result)
