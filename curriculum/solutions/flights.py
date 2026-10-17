import json
from pathlib import Path
import pandas as pd
from sklearn.linear_model import LinearRegression
from sklearn.model_selection import train_test_split
from sklearn.metrics import mean_absolute_error


def solve(data):
    train, test = pd.DataFrame(data['train']), pd.DataFrame(data['test'])
    features, target = data['features'], data['target']
    x_train, x_valid, y_train, y_valid = train_test_split(train[features], train[target], test_size=0.2, random_state=42)
    model = LinearRegression()
    model.fit(x_train, y_train)
    print('Local validation MAE:', mean_absolute_error(y_valid, model.predict(x_valid)))
    model.fit(train[features], train[target])
    return {'predictions': model.predict(test[features]).tolist()}


if __name__ == '__main__':
    result = solve(json.loads(Path('request.json').read_text()))
    Path('result.json').write_text(json.dumps(result, allow_nan=False))
    print(result)
