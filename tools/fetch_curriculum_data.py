import csv
import hashlib
import io
import json
from pathlib import Path
import urllib.request
import zipfile

ROOT = Path(__file__).resolve().parents[1] / 'curriculum' / 'data'


def archive(identifier, name):
    url = f'https://archive.ics.uci.edu/static/public/{identifier}/{name}.zip'
    with urllib.request.urlopen(url, timeout=60) as response:
        raw = response.read(12_000_001)
    if len(raw) > 12_000_000:
        raise ValueError('Dataset archive exceeds download limit')
    return zipfile.ZipFile(io.BytesIO(raw)), url


def save(name, rows, source, license, attribution):
    rows = list(rows)
    output = io.StringIO(newline='')
    writer = csv.DictWriter(output, fieldnames=list(rows[0]))
    writer.writeheader()
    writer.writerows(rows)
    raw = output.getvalue().encode()
    (ROOT / name).write_bytes(raw)
    return {'file': name, 'source': source, 'license': license, 'attribution': attribution,
            'rows': len(rows), 'sha256': hashlib.sha256(raw).hexdigest(), 'status': 'bundled',
            'retrieved': '2026-10-16'}


def main():
    import openpyxl
    metadata = []
    bundle, url = archive(477, 'real+estate+valuation+data+set')
    sheet = openpyxl.load_workbook(io.BytesIO(bundle.read(next(n for n in bundle.namelist() if n.endswith('.xlsx')))), read_only=True).active
    values = list(sheet.values)
    metadata.append(save('housing.csv', (dict(zip(['date','age','distance','stores','latitude','longitude','price'], row[1:])) for row in values[1:]), url, 'CC BY 4.0', 'Yeh, I. (2018), Real Estate Valuation, UCI, doi:10.24432/C5J30W. Header names normalized; price is per unit area.'))
    bundle, url = archive(529, 'early+stage+diabetes+risk+prediction+dataset')
    rows = list(csv.DictReader(io.StringIO(bundle.read('diabetes_data_upload.csv').decode('utf-8-sig'))))
    normalized = []
    for row in rows:
        normalized.append({key.lower().replace(' ', '_'): (int(value) if key == 'Age' else int(value in ('Yes','Male','Positive'))) for key, value in row.items()})
    metadata.append(save('diabetes.csv', normalized, url, 'CC BY 4.0', 'Islam et al. (2020), Early Stage Diabetes Risk Prediction, UCI, doi:10.24432/C5VG8H. Categories encoded 0/1; educational only.'))
    bundle, url = archive(275, 'bike+sharing+dataset')
    rows = list(csv.DictReader(io.StringIO(bundle.read('day.csv').decode())))
    metadata.append(save('weather.csv', ({'date': r['dteday'], 'temperature': round(float(r['temp'])*47-8, 4), 'condition': r['weathersit']} for r in rows), url, 'CC BY 4.0', 'Fanaee-T (2013), Bike Sharing, UCI, doi:10.24432/C5W894. Daily weather columns only; normalized temperature converted using dataset scale.'))
    bundle, url = archive(331, 'sentiment+labelled+sentences')
    rows = []
    for name in sorted(bundle.namelist()):
        if name.endswith('_labelled.txt') and not name.startswith('__MACOSX'):
            pending = ''
            for line in bundle.read(name).decode().splitlines():
                pending += line
                if '\t' not in pending:
                    pending += ' '
                    continue
                text, label = pending.rsplit('\t', 1)
                rows.append({'text': text, 'label': int(label)})
                pending = ''
    metadata.append(save('sentiment.csv', rows, url, 'CC BY 4.0', 'Kotzias (2015), Sentiment Labelled Sentences, UCI, doi:10.24432/C57604. English Amazon/IMDb/Yelp text, binary labels.'))
    bundle, url = archive(53, 'iris')
    rows = []
    for line in bundle.read('iris.data').decode().splitlines():
        if line.strip():
            fields = line.split(',')
            rows.append(dict(zip(['sepal_length','sepal_width','petal_length','petal_width','species'], fields)))
    metadata.append(save('iris.csv', rows, url, 'CC BY 4.0', 'Fisher (1936), Iris, UCI, doi:10.24432/C56C76.'))
    url = 'https://vincentarelbundock.github.io/Rdatasets/csv/nycflights13/flights.csv'
    rows = []
    with urllib.request.urlopen(url, timeout=60) as response:
        reader = csv.DictReader(io.TextIOWrapper(response, encoding='utf-8'))
        for row in reader:
            keys = ['month','day','hour','distance','air_time','dep_delay','arr_delay']
            if all(row[k] not in ('NA', '') for k in keys):
                rows.append({k: float(row[k]) for k in keys})
            if len(rows) == 2000:
                break
    metadata.append(save('flights.csv', rows, url, 'CC0 1.0', 'nycflights13 (Hadley Wickham), 2013 NYC departures, US Bureau of Transportation Statistics; first 2000 complete rows via Rdatasets. https://cran.r-project.org/web/packages/nycflights13/index.html'))
    metadata.append(save('sales.csv', [{'product':'book','quantity':2,'price':10,'month':'2026-01'}, {'product':'pen','quantity':5,'price':2,'month':'2026-01'}, {'product':'book','quantity':1,'price':10,'month':'2026-02'}, {'product':'pen','quantity':3,'price':2,'month':'2026-02'}], 'Noventix reproducible educational fixture', 'CC0 1.0', 'Synthetic educational sales; not real transactions.'))
    metadata.append(save('missing.csv', [{'name':'A','age':20,'score':80}, {'name':'B','age':'','score':60}, {'name':'C','age':40,'score':''}, {'name':'D','age':30,'score':100}], 'Noventix reproducible educational fixture', 'CC0 1.0', 'Synthetic missing values fixture.'))
    (ROOT / 'manifest.json').write_text(json.dumps(metadata, ensure_ascii=False, indent=2)+'\n')


if __name__ == '__main__':
    main()
