import json
from pathlib import Path
from sklearn.feature_extraction.text import TfidfVectorizer
from sklearn.linear_model import LogisticRegression
from sklearn.pipeline import make_pipeline
from sklearn.model_selection import train_test_split
from sklearn.metrics import f1_score


def solve(data):
    texts = [row['text'] for row in data['train']]
    labels = [row[data['target']] for row in data['train']]
    x_train, x_valid, y_train, y_valid = train_test_split(texts, labels, test_size=0.2, stratify=labels, random_state=42)
    model = make_pipeline(TfidfVectorizer(ngram_range=(1, 2), sublinear_tf=True), LogisticRegression(C=4, random_state=42, max_iter=500))
    model.fit(x_train, y_train)
    print('Local validation macro-F1:', f1_score(y_valid, model.predict(x_valid), average='macro'))
    model.fit(texts, labels)
    return {'predictions': model.predict([row['text'] for row in data['test']]).tolist()}


if __name__ == '__main__':
    result = solve(json.loads(Path('request.json').read_text()))
    Path('result.json').write_text(json.dumps(result, allow_nan=False))
    print(result)
