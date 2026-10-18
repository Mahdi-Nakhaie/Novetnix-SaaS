import json
import re
from collections import Counter
from pathlib import Path


def tokens(text): return re.findall(r'[a-z]{2,}',str(text).lower())


def solve(data):
    train,test,features,target=data['train'],data['test'],data['features'],data['target']; labels=sorted({row[target] for row in train}); totals=Counter(); words={label:Counter() for label in labels}; vocabulary=set()
    for row in train:
        terms=tokens(row[features[0]]); label=row[target]; totals[label]+=1; words[label].update(terms); vocabulary.update(terms)
    predictions=[]
    for row in test:
        terms=tokens(row[features[0]]); scores={}
        for label in labels:
            score=(totals[label]+1)/(len(train)+len(labels)); denominator=sum(words[label].values())+len(vocabulary)
            for term in terms: score*=(words[label][term]+1)/denominator
            scores[label]=score
        predictions.append(max(scores,key=scores.get))
    return {'predictions':predictions}


if __name__ == '__main__':
    result=solve(json.loads(Path('request.json').read_text())); Path('result.json').write_text(json.dumps(result,allow_nan=False)); print(result)
