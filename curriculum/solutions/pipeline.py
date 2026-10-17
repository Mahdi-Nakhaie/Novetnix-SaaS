import json
from pathlib import Path
import pandas as pd
from sklearn.impute import SimpleImputer
from sklearn.preprocessing import StandardScaler
from sklearn.linear_model import Ridge
from sklearn.pipeline import make_pipeline
from sklearn.model_selection import train_test_split
from sklearn.metrics import mean_absolute_error


def solve(data):
    train, test = pd.DataFrame(data['train']), pd.DataFrame(data['test'])
    features, target = data['features'], data['target']
    x_train, x_valid, y_train, y_valid = train_test_split(train[features], train[target], test_size=0.2, random_state=42)
    model = make_pipeline(SimpleImputer(strategy='median'), StandardScaler(), Ridge(alpha=1))
    model.fit(x_train, y_train)
    print('Local validation MAE:', mean_absolute_error(y_valid, model.predict(x_valid)))
    model.fit(train[features], train[target])
    imputer, scaler, regressor = model.steps[0][1], model.steps[1][1], model.steps[2][1]
    artifact = {'median': imputer.statistics_.tolist(), 'mean': scaler.mean_.tolist(), 'scale': scaler.scale_.tolist(), 'coef': regressor.coef_.tolist(), 'intercept': float(regressor.intercept_)}
    Path('model.json').write_text(json.dumps(artifact, allow_nan=False))
    return {'predictions': model.predict(test[features]).tolist()}


if __name__ == '__main__':
    result = solve(json.loads(Path('request.json').read_text()))
    Path('result.json').write_text(json.dumps(result, allow_nan=False))
    print(result)
