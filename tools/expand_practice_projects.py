import json
from pathlib import Path

ROOT = Path(__file__).resolve().parent.parent

PROJECTS = [
    ('py-expenses', 'python-expense-aggregation', 'expenses: فهرست رکوردهای category (رشتهٔ غیرخالی) و amount (عدد صحیح غیرمنفی). دسته‌ها عیناً حفظ می‌شوند؛ فهرست خالی مجاز است.', 'by_category: جمع هر دسته؛ total: مجموع همهٔ هزینه‌ها؛ فهرست خالی نگاشت خالی و مجموع صفر دارد.', '''def solve(data):
    totals = {}
    for item in data['expenses']:
        category = item['category']
        totals[category] = totals.get(category, 0) + item['amount']
    return {'by_category': totals, 'total': sum(totals.values())}
''', [
        ({'expenses': []}, {'by_category': {}, 'total': 0}),
        ({'expenses': [{'category': 'A', 'amount': 0}]}, {'by_category': {'A': 0}, 'total': 0}),
        ({'expenses': [{'category': 'A', 'amount': 8}, {'category': 'B', 'amount': 5}, {'category': 'A', 'amount': 3}]}, {'by_category': {'A': 11, 'B': 5}, 'total': 16}),
    ]),
    ('py-password-audit', 'python-validation-contracts', 'passwords: فهرست رشته‌های کاملاً ساختگی برای تمرین؛ هرگز گذرواژهٔ واقعی وارد نکنید. حروف و رقم‌ها فقط ASCII و نماد فقط مجموعهٔ !@#$%^&* هستند.', 'results: یک رکورد برای هر رشته به همان ترتیب؛ length طول رشته؛ has_letter و has_digit و has_symbol وجود گروه مربوطه؛ strong یعنی طول حداقل ۸ و وجود هر سه گروه. این قواعد تضمین امنیت نیستند.', '''def solve(data):
    results = []
    for value in data['passwords']:
        letter = any('a' <= c.lower() <= 'z' for c in value)
        digit = any('0' <= c <= '9' for c in value)
        symbol = any(c in '!@#$%^&*' for c in value)
        results.append({'length': len(value), 'has_letter': letter,
                        'has_digit': digit, 'has_symbol': symbol,
                        'strong': len(value) >= 8 and letter and digit and symbol})
    return {'results': results}
''', [
        ({'passwords': []}, {'results': []}),
        ({'passwords': ['']}, {'results': [{'length': 0, 'has_letter': False, 'has_digit': False, 'has_symbol': False, 'strong': False}]}),
        ({'passwords': ['A123456!']}, {'results': [{'length': 8, 'has_letter': True, 'has_digit': True, 'has_symbol': True, 'strong': True}]}),
        ({'passwords': ['abcdefgh1 ', '۱۲۳۴۵۶۷۸']}, {'results': [{'length': 10, 'has_letter': True, 'has_digit': True, 'has_symbol': False, 'strong': False}, {'length': 8, 'has_letter': False, 'has_digit': False, 'has_symbol': False, 'strong': False}]}),
    ]),
    ('py-log-parser', 'python-log-levels', 'levels: فهرست رشته‌های سطح رخداد؛ هر رشته با strip و upper نرمال شود. رشتهٔ خالی کنار گذاشته شود؛ سطح ناشناخته هم شمارش می‌شود.', 'counts: نگاشت سطح نرمال‌شده به تعداد؛ فهرست خالی نگاشت خالی برمی‌گرداند.', '''def solve(data):
    counts = {}
    for raw in data['levels']:
        level = raw.strip().upper()
        if level:
            counts[level] = counts.get(level, 0) + 1
    return {'counts': counts}
''', [
        ({'levels': []}, {'counts': {}}),
        ({'levels': [' info ', 'INFO', '', '  ', 'trace']}, {'counts': {'INFO': 2, 'TRACE': 1}}),
        ({'levels': ['error', 'Warning', 'ERROR', 'debug']}, {'counts': {'ERROR': 2, 'WARNING': 1, 'DEBUG': 1}}),
    ]),
    ('py-sales-tax', 'python-tax-rounding', 'items: فهرست price به‌صورت رشتهٔ اعشاری غیرمنفی با حداکثر دو رقم اعشار و quantity صحیح غیرمنفی؛ tax_rate رشتهٔ اعشاری بین صفر و یک. نرخ صرفاً دادهٔ تمرین است، نه نرخ قانونی.', 'subtotal, tax, total همگی رشتهٔ دو رقم اعشاری؛ مالیات فقط یک بار بر جمع سبد محاسبه و با ROUND_HALF_UP به ۰٫۰۱ گرد می‌شود.', '''from decimal import Decimal, ROUND_HALF_UP


def solve(data):
    subtotal = sum((Decimal(item['price']) * item['quantity']
                    for item in data['items']), Decimal('0'))
    tax = (subtotal * Decimal(data['tax_rate'])).quantize(
        Decimal('0.01'), rounding=ROUND_HALF_UP)
    return {'subtotal': format(subtotal, '.2f'), 'tax': format(tax, '.2f'),
            'total': format(subtotal + tax, '.2f')}
''', [
        ({'items': [], 'tax_rate': '0.10'}, {'subtotal': '0.00', 'tax': '0.00', 'total': '0.00'}),
        ({'items': [{'price': '0.05', 'quantity': 1}], 'tax_rate': '0.10'}, {'subtotal': '0.05', 'tax': '0.01', 'total': '0.06'}),
        ({'items': [{'price': '19.99', 'quantity': 2}, {'price': '0.02', 'quantity': 1}], 'tax_rate': '0.05'}, {'subtotal': '40.00', 'tax': '2.00', 'total': '42.00'}),
    ]),
    ('py-text-cleaner', 'python-name-normalization', 'names: فهرست رشته‌ها؛ فاصله‌های سفید پی‌درپی با یک فاصله جایگزین، دو سر حذف، نام خالی حذف و نام‌های برابر پس از همین پاک‌سازی یکتا شوند. شکل حروف تغییر نکند.', 'names: نام‌های یکتا به ترتیب اولین رخداد؛ count: تعداد. این تمرین تطبیق هویت اشخاص نیست.', '''def solve(data):
    names, seen = [], set()
    for raw in data['names']:
        name = ' '.join(raw.split())
        if name and name not in seen:
            seen.add(name)
            names.append(name)
    return {'names': names, 'count': len(names)}
''', [
        ({'names': []}, {'names': [], 'count': 0}),
        ({'names': [' ', '\t', 'A', 'A ']}, {'names': ['A'], 'count': 1}),
        ({'names': ['علی  رضا', 'علی\tرضا', 'سارا', 'علي رضا']}, {'names': ['علی رضا', 'سارا', 'علي رضا'], 'count': 3}),
    ]),
    ('py-date-range', 'python-date-intervals', 'start و end رشتهٔ تاریخ میلادی با قالب دقیق YYYY-MM-DD؛ بدون ساعت و منطقهٔ زمانی. تاریخ نامعتبر باید خطا بدهد.', 'days: اختلاف علامت‌دار end-start بر حسب روز؛ روز آغاز شمرده نمی‌شود. تاریخ نامعتبر: فقط error=invalid_date.', '''from datetime import datetime


def solve(data):
    try:
        dates = []
        for name in ('start', 'end'):
            text = data[name]
            value = datetime.strptime(text, '%Y-%m-%d').date()
            if value.isoformat() != text:
                raise ValueError('Invalid format')
            dates.append(value)
        return {'days': (dates[1] - dates[0]).days}
    except ValueError:
        return {'error': 'invalid_date'}
''', [
        ({'start': '2024-02-28', 'end': '2024-03-01'}, {'days': 2}),
        ({'start': '2026-01-03', 'end': '2026-01-01'}, {'days': -2}),
        ({'start': '2026-01-01', 'end': '2026-01-01'}, {'days': 0}),
        ({'start': '2025-02-29', 'end': '2025-03-01'}, {'error': 'invalid_date'}),
        ({'start': '2026-1-01', 'end': '2026-01-02'}, {'error': 'invalid_date'}),
    ]),
    ('py-scoreboard', 'python-stable-ranking', 'players: فهرست رکوردهای name رشته و score عدد صحیح؛ امتیاز منفی و فهرست خالی مجاز است.', 'ranking: همان رکوردها با امتیاز نزولی و در تساوی، نام صعودی طبق ترتیب Unicode پایتون؛ تساوی هر دو کلید ترتیب اولیه را حفظ می‌کند.', '''def solve(data):
    return {'ranking': sorted(data['players'],
                              key=lambda item: (-item['score'], item['name']))}
''', [
        ({'players': []}, {'ranking': []}),
        ({'players': [{'name': 'B', 'score': 2}, {'name': 'A', 'score': 2}, {'name': 'C', 'score': -1}]}, {'ranking': [{'name': 'A', 'score': 2}, {'name': 'B', 'score': 2}, {'name': 'C', 'score': -1}]}),
        ({'players': [{'name': 'Z', 'score': -2}]}, {'ranking': [{'name': 'Z', 'score': -2}]}),
    ]),
    ('py-budget-check', 'python-budget-invariants', 'budget: عدد صحیح غیرمنفی در یک واحد ثابت؛ spent: فهرست هزینه‌های صحیح غیرمنفی. خالی بودن هزینه‌ها مجاز است.', 'spent: جمع هزینه‌ها؛ remaining: بودجه منهای مصرف و ممکن است منفی شود؛ within_budget: مصرف کمتر یا مساوی بودجه.', '''def solve(data):
    spent = sum(data['spent'])
    return {'spent': spent, 'remaining': data['budget'] - spent,
            'within_budget': spent <= data['budget']}
''', [
        ({'budget': 0, 'spent': []}, {'spent': 0, 'remaining': 0, 'within_budget': True}),
        ({'budget': 10, 'spent': [5, 5]}, {'spent': 10, 'remaining': 0, 'within_budget': True}),
        ({'budget': 10, 'spent': [7, 8]}, {'spent': 15, 'remaining': -5, 'within_budget': False}),
    ]),
    ('py-frequency-table', 'python-frequency-ratios', 'labels: فهرست برچسب‌های رشته‌ای؛ تفاوت بزرگی حروف حفظ شود؛ خالی بودن فهرست مجاز است.', 'counts: تعداد هر برچسب؛ ratios: تعداد تقسیم بر طول کل. برای فهرست خالی هر دو نگاشت خالی‌اند.', '''from collections import Counter


def solve(data):
    counts = dict(Counter(data['labels']))
    total = len(data['labels'])
    return {'counts': counts,
            'ratios': {label: count / total for label, count in counts.items()}}
''', [
        ({'labels': []}, {'counts': {}, 'ratios': {}}),
        ({'labels': ['x', 'x']}, {'counts': {'x': 2}, 'ratios': {'x': 1}}),
        ({'labels': ['a', 'A']}, {'counts': {'a': 1, 'A': 1}, 'ratios': {'a': 0.5, 'A': 0.5}}),
    ]),
    ('py-matrix-summary', 'python-matrix-axes', 'matrix: فهرست سطرهای عددی؛ ماتریس باید مربعی باشد. ماتریس خالی معتبر است؛ سطرها همگی فهرست و مقدارها عدد متناهی‌اند.', 'row_sums و column_sums: جمع هر محور؛ diagonal: مجموع قطر اصلی. شکل غیرمربعی: فقط error=invalid_matrix. خالی: دو فهرست خالی و قطر صفر.', '''def solve(data):
    matrix = data['matrix']
    size = len(matrix)
    if any(len(row) != size for row in matrix):
        return {'error': 'invalid_matrix'}
    return {'row_sums': [sum(row) for row in matrix],
            'column_sums': [sum(row[i] for row in matrix) for i in range(size)],
            'diagonal': sum(matrix[i][i] for i in range(size))}
''', [
        ({'matrix': []}, {'row_sums': [], 'column_sums': [], 'diagonal': 0}),
        ({'matrix': [[-2]]}, {'row_sums': [-2], 'column_sums': [-2], 'diagonal': -2}),
        ({'matrix': [[1, 2, 3], [4, 5, 6]]}, {'error': 'invalid_matrix'}),
        ({'matrix': [[1, 2], [3]]}, {'error': 'invalid_matrix'}),
    ]),
    ('py-api-payload', 'python-payload-contracts', 'payload: دیکشنری با فیلدهای احتمالی name, email, age. نام و ایمیل باید رشته باشند؛ سن فقط int غیرمنفی باشد نه bool. کلیدهای اضافی نادیده گرفته شوند.', 'ورودی معتبر: valid=true و نام و ایمیل strip‌شده و سن؛ نام خالی یا ایمیل فاقد @ یا دو طرف خالی نامعتبر است. نامعتبر: فقط valid=false. این قاعده تأیید مالکیت یا اعتبار کامل ایمیل نیست.', '''def solve(data):
    payload = data['payload']
    if not isinstance(payload.get('name'), str) or not isinstance(payload.get('email'), str):
        return {'valid': False}
    name, email = payload['name'].strip(), payload['email'].strip()
    parts = email.split('@')
    valid = (bool(name) and len(parts) == 2 and all(parts)
             and not any(c.isspace() for c in email)
             and type(payload.get('age')) is int and payload['age'] >= 0)
    if not valid:
        return {'valid': False}
    return {'valid': True, 'name': name, 'email': email, 'age': payload['age']}
''', [
        ({'payload': {}}, {'valid': False}),
        ({'payload': {'name': ' A ', 'email': ' a@b.test ', 'age': 0, 'role': 'extra'}}, {'valid': True, 'name': 'A', 'email': 'a@b.test', 'age': 0}),
        ({'payload': {'name': 'A', 'email': 'a@b', 'age': True}}, {'valid': False}),
        ({'payload': {'name': 'A', 'email': '@b.test', 'age': 2}}, {'valid': False}),
        ({'payload': {'name': ' ', 'email': 'a@b.test', 'age': 2}}, {'valid': False}),
    ]),
    ('py-report-builder', 'python-metric-scorecards', 'metrics: نگاشت نام شاخص به عدد متناهی بین صفر و صد با وزن برابر. فهرست تهی مجاز است.', 'average: میانگین حسابی؛ status: از ۹۰ excellent، از ۷۰ good و کمتر needs_review؛ best_metric: نام بیشترین مقدار، در تساوی کوچک‌ترین نام Unicode. خالی: فقط error=empty_metrics.', '''def solve(data):
    metrics = data['metrics']
    if not metrics:
        return {'error': 'empty_metrics'}
    average = sum(metrics.values()) / len(metrics)
    best = min(metrics, key=lambda name: (-metrics[name], name))
    return {'average': average,
            'status': 'excellent' if average >= 90 else 'good' if average >= 70 else 'needs_review',
            'best_metric': best}
''', [
        ({'metrics': {}}, {'error': 'empty_metrics'}),
        ({'metrics': {'a': 90}}, {'average': 90, 'status': 'excellent', 'best_metric': 'a'}),
        ({'metrics': {'b': 70, 'a': 70}}, {'average': 70, 'status': 'good', 'best_metric': 'a'}),
        ({'metrics': {'x': 0, 'y': 100}}, {'average': 50, 'status': 'needs_review', 'best_metric': 'y'}),
    ]),
]


def main():
    catalog_path = ROOT / 'curriculum/catalog.json'
    cases_path = ROOT / 'curriculum/beginner_cases.json'
    catalog = json.loads(catalog_path.read_text())
    cases = json.loads(cases_path.read_text())
    for key, article, inputs, outputs, source, edge_cases in PROJECTS:
        project = next(item for item in catalog if item['key'] == key)
        project['input'], project['output'] = inputs, outputs
        project['prerequisite_articles'] = [article]
        if key == 'py-sales-tax':
            project['example'] = {'input': {'items': [{'price': '100.00', 'quantity': 2}, {'price': '50.00', 'quantity': 1}], 'tax_rate': '0.10'}, 'output': {'subtotal': '250.00', 'tax': '25.00', 'total': '275.00'}}
        if key == 'py-scoreboard':
            project['example']['output']['ranking'] = sorted(project['example']['input']['players'], key=lambda p: (-p['score'], p['name']))
        cases[key] = [[project['example']['input'], project['example']['output']], *edge_cases]
        code = source + "\nif __name__ == '__main__':\n    import json\n    from pathlib import Path\n    data = json.loads(Path('request.json').read_text(encoding='utf-8'))\n    result = solve(data)\n    Path('result.json').write_text(json.dumps(result, ensure_ascii=False, allow_nan=False), encoding='utf-8')\n    print(result)\n"
        (ROOT / 'curriculum/solutions' / (key + '.py')).write_text(code)
    guidance_path = ROOT / 'curriculum/project-guidance.json'
    guidance = json.loads(guidance_path.read_text())
    transfers = [
        'گزارش را به تفکیک ماه توسعه دهید؛ کلید ترکیبی ماه و دسته چه تغییری در ساختار داده می‌دهد؟',
        'یک گزارش آماری از عبور و عدم عبور رشته‌های کاملاً ساختگی بسازید، بدون نگه‌داشتن خود رشته‌ها.',
        'گزارش روزانه و شمارش سطح را ترکیب کنید؛ تفاوت رخداد تکراری و سطح تکراری را توضیح دهید.',
        'اثر گردکردن مالیات هر ردیف در برابر گردکردن کل سبد را با یک مثال متفاوت نشان دهید.',
        'برای نمایش نام، متن اصلی را نگه دارید اما کلید مقایسهٔ نرمال‌شدهٔ جداگانه بسازید.',
        'بازهٔ نیمه‌باز را به یک شمارندهٔ روزهای کاری توسعه دهید؛ تعطیلات باید ورودی صریح باشند.',
        'رتبهٔ رقابتی ۱، ۲، ۲، ۴ را به خروجی اضافه کنید؛ این رفتار در ارزیابی فعلی الزامی نیست.',
        'آستانهٔ هشدار هشتاد درصد را بدون تغییر معنای مصرف و باقیمانده به گزارش اضافه کنید.',
        'دو جدول فراوانی را ادغام کنید و نسبت‌ها را از تعدادهای ادغام‌شده دوباره محاسبه کنید.',
        'ماتریس مستطیلی را نیز پشتیبانی کنید و مشخص کنید قطر اصلی برای آن چه معنایی دارد.',
        'خطای هر فیلد را در گزارش جداگانه بازگردانید؛ اطلاعات اضافی payload را در خروجی منتشر نکنید.',
        'نسخهٔ وزن‌دار گزارش را بسازید؛ مجموع وزن صفر باید قرارداد روشن و آزمون مستقل داشته باشد.',
    ]
    for (key, _, inputs, outputs, _, _), transfer in zip(PROJECTS, transfers):
        guidance[key] = [transfer, inputs + '\n' + outputs]
    guidance_path.write_text(json.dumps(guidance, ensure_ascii=False, indent=2) + '\n')
    catalog_path.write_text(json.dumps(catalog, ensure_ascii=False, indent=2) + '\n')
    cases_path.write_text(json.dumps(cases, ensure_ascii=False, indent=2) + '\n')


if __name__ == '__main__':
    main()
