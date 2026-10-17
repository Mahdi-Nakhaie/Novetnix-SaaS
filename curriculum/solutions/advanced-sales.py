import io
import json
from pathlib import Path
import pandas as pd
import matplotlib
matplotlib.use('Agg')
import matplotlib.pyplot as plt


def solve(data):
    frame = pd.read_csv(io.StringIO(data['csv']))
    frame['revenue'] = frame['quantity'] * frame['price']
    monthly = frame.groupby('month')['revenue'].sum()
    fig, ax = plt.subplots(figsize=(6, 4))
    monthly.plot.bar(ax=ax)
    ax.set_ylabel('Sales')
    fig.tight_layout()
    fig.savefig('chart.png')
    plt.close(fig)
    total = float(frame['revenue'].sum())
    return {'total': total, 'by_month': monthly.to_dict(), 'orders': len(frame), 'average_order': total/len(frame)}


if __name__ == '__main__':
    result = solve(json.loads(Path('request.json').read_text()))
    Path('result.json').write_text(json.dumps(result, allow_nan=False))
    print(result)
