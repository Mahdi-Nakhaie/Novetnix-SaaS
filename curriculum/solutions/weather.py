import io
import json
from pathlib import Path
import pandas as pd
import matplotlib
matplotlib.use('Agg')
import matplotlib.pyplot as plt


def solve(data):
    frame = pd.read_csv(io.StringIO(data['csv']))
    series = frame['temperature']
    fig, ax = plt.subplots(figsize=(6, 4))
    ax.plot(frame['date'], series, marker='o')
    ax.set_ylabel('Temperature (C)')
    fig.autofmt_xdate()
    fig.tight_layout()
    fig.savefig('chart.png')
    plt.close(fig)
    return {'mean': float(series.mean()), 'min': float(series.min()), 'max': float(series.max()), 'changes': series.diff().dropna().tolist()}


if __name__ == '__main__':
    result = solve(json.loads(Path('request.json').read_text()))
    Path('result.json').write_text(json.dumps(result, allow_nan=False))
    print(result)
