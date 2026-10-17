import io
import json
from pathlib import Path
import pandas as pd


def solve(data):
    frame = pd.read_csv(io.StringIO(data['csv']))
    numeric = frame[['age', 'score']]
    if numeric.isna().all().any():
        return {'error': 'all_missing'}
    means = numeric.mean()
    frame[['age', 'score']] = numeric.fillna(means)
    frame.to_csv('cleaned.csv', index=False)
    return {'missing_before': int(numeric.isna().sum().sum()), 'means': means.to_dict()}


if __name__ == '__main__':
    result = solve(json.loads(Path('request.json').read_text()))
    Path('result.json').write_text(json.dumps(result, allow_nan=False))
    print(result)
