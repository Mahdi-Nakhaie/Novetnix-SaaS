import json
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1] / 'curriculum'


def definition(key, title, description, goal, skills, minutes, xp, datasets, inputs, outputs, steps, example, criteria, dependencies, notes=''):
    return dict(key=key, title=title, description=description, goal=goal, skills=skills, minutes=minutes, xp=xp, datasets=datasets, input=inputs, output=outputs, steps=steps, example=example, criteria=criteria, dependencies=dependencies, notes=notes)


def main():
    catalog = json.loads((ROOT / 'catalog.json').read_text())[:1]
    catalog += [
        definition('grades','تحلیل نمرات دانشجویان','آمار نمرات و تعداد قبول و مردود را محاسبه کن.','کار با لیست، توابع آماری و اعتبارسنجی','Python · Lists · Statistics',40,80,[], 'grades: لیست عددی از صفر تا بیست؛ حد قبولی ۱۰ است.', 'result.json: mean, min, max, passed, failed؛ لیست خالی: error=empty_grades؛ نمره خارج بازه: error=invalid_grade', ['لیست grades را از request.json بخوان.','خالی بودن و بازه نمره‌ها را پیش از محاسبه بررسی کن.','میانگین، کمینه و بیشینه را محاسبه کن.','قبولی را برای نمره حداقل ۱۰ بشمار و JSON ذخیره کن.'], {'input':{'grades':[8,12,16]},'output':{'mean':12,'min':8,'max':16,'passed':2,'failed':1}}, ['میانگین و حدود درست','قبولی مرزی نمره ۱۰','مدیریت لیست خالی و نمره نامعتبر'], []),
        definition('sales','تحلیل داده‌های فروش','از CSV فروش، جمع کل و فروش هر محصول را گزارش کن.','خواندن CSV و تجمیع داده','Python · csv · Aggregation',50,100,['sales.csv'], 'csv: متن CSV با ستون‌های product, quantity, price, month؛ تعداد و قیمت غیرمنفی‌اند.', 'result.json: total و by_product (نگاشت نام محصول به مبلغ فروش)؛ CSV خالی error=empty_data؛ قیمت یا تعداد نامعتبر error=invalid_row', ['متن csv در request.json را با csv.DictReader و io.StringIO بخوان.','برای هر سطر quantity × price را حساب کن.','فروش تکراری هر محصول را جمع کن.','جمع کل و نگاشت محصولات را ذخیره کن.'], {'input':{'csv':'product,quantity,price,month\nbook,2,10,2026-01\npen,3,2,2026-01\n'},'output':{'total':26,'by_product':{'book':20,'pen':6}}}, ['فروش کل و تجمیع محصولات تکراری','اعشار و سطرهای صفر','رد داده نامعتبر و خالی'], []),
        definition('cleaning','پاک‌سازی داده‌های ناقص','مقادیر گمشده ستون‌های عددی را شناسایی و با میانگین جایگزین کن.','مدیریت missing و ذخیره خروجی پاک','Pandas · Missing values · CSV',60,130,['missing.csv'], 'csv با name, age, score؛ خانه خالی در age/score گمشده است.', 'result.json: missing_before و means شامل age/score؛ cleaned.csv با همان ترتیب سطر و ستون؛ ستون کاملاً خالی error=all_missing بدون فایل پاک', ['CSV را با pandas.read_csv بخوان.','تعداد خانه‌های خالی عددی را ثبت کن.','میانگین هر ستون عددی را فقط از خانه‌های غیرخالی حساب کن.','اگر ستونی هیچ عددی ندارد خطای all_missing برگردان؛ در غیر این صورت fillna و to_csv را اجرا کن.'], {'input':{'csv':'name,age,score\nA,20,80\nB,,60\nC,40,\n'},'output':{'missing_before':2,'means':{'age':30,'score':70}}}, ['شمارش missing و میانگین بدون نشت از مقادیر جایگزین','خروجی cleaned.csv بدون خانه گمشده و حفظ نام‌ها','مدیریت ستون تماماً خالی'], ['pandas']),
        definition('advanced-sales','تحلیل داده‌های فروش پیشرفته','فروش ماهانه و شاخص‌های کلیدی را همراه نمودار بساز.','گروه‌بندی زمانی و تجسم داده','Pandas · GroupBy · Matplotlib',70,160,['sales.csv'], 'csv با ستون‌های product, quantity, price, month، حداقل یک سطر معتبر.', 'result.json: total, by_month, orders, average_order؛ chart.png نمودار ستونی فروش ماهانه حداقل ۳۲۰×۲۰۰ پیکسل', ['ستون revenue = quantity × price بساز.','با groupby فروش هر ماه را جمع بزن.','تعداد سطرها را orders و total/orders را average_order قرار بده.','نمودار by_month را با matplotlib ذخیره کن؛ plt.close را فراموش نکن.'], {'input':{'csv':'product,quantity,price,month\nbook,2,10,2026-01\npen,3,2,2026-02\n'},'output':{'total':26,'by_month':{'2026-01':20,'2026-02':6},'orders':2,'average_order':13}}, ['مجموع ماهانه و کل','تعداد و میانگین سفارش‌ها','فایل PNG واقعی غیرخالی'], ['pandas','matplotlib']),
        definition('weather','تحلیل داده‌های آب‌وهوا','دماهای روزانه واقعی را خلاصه و تغییراتشان را رسم کن.','تحلیل سری روزانه و اختلاف متوالی','Pandas · Time series · Matplotlib',70,170,['weather.csv'], 'csv با date, temperature, condition؛ حداقل یک ردیف؛ تاریخ‌ها به ترتیب صعودی هستند.', 'result.json: mean, min, max, changes (اختلاف دمای هر روز با روز قبل؛ طول n−1) و chart.png نمودار خطی حداقل ۳۲۰×۲۰۰', ['CSV را بخوان و ستون temperature را عددی در نظر بگیر.','میانگین و کمینه و بیشینه را محاسبه کن.','با diff و حذف مقدار اول، تغییرات متوالی را بساز.','تاریخ و دما را روی نمودار خطی رسم و chart.png ذخیره کن.'], {'input':{'csv':'date,temperature,condition\n2026-01-01,10,1\n2026-01-02,13,2\n'},'output':{'mean':11.5,'min':10,'max':13,'changes':[3]}}, ['آمار دمای واقعی','تغییرات روزانه و حالت تک‌روز','تولید PNG معتبر'], ['pandas','matplotlib']),
    ]
    ml = [
        ('housing','پیش‌بینی قیمت خانه','housing.csv','price',['age','distance','stores','latitude','longitude'],'regression',8.0,210,'آموزش مدل رگرسیون برای قیمت هر واحد مساحت؛ این داده قیمت کل خانه نیست.'),
        ('iris','دسته‌بندی گل‌های Iris','iris.csv','species',['sepal_length','sepal_width','petal_length','petal_width'],'classification',0.85,220,'از اندازه‌های گل، گونه را پیش‌بینی کن.'),
        ('diabetes','تشخیص دیابت با داده‌های آموزشی','diabetes.csv','class',None,'classification',0.80,240,'طبقه‌بندی برچسب آموزشی با داده علائم؛ این تمرین ابزار تشخیص پزشکی نیست.'),
        ('flights','تحلیل و پیش‌بینی تأخیرهای پرواز','flights.csv','arr_delay',['month','day','hour','distance','air_time','dep_delay'],'regression',15.0,270,'تأخیر ورود را پس از مشخص‌شدن تأخیر خروج و زمان پرواز تخمین بزن؛ پیش‌بینی پیش از خروج نیست.'),
        ('sentiment','تحلیل احساسات متن','sentiment.csv','label',['text'],'classification',0.72,280,'متن انگلیسی Amazon/IMDb/Yelp را به مثبت (۱) و منفی (۰) دسته‌بندی کن.'),
        ('pipeline','ساخت یک خط لوله یادگیری ماشین','housing.csv','price',['age','distance','stores','latitude','longitude'],'regression',9.0,350,'پاک‌سازی، مقیاس‌بندی، آموزش، ارزیابی و ذخیره یک مدل رگرسیون را ترکیب کن.'),
    ]
    import csv, random
    for key,title,dataset,target,features,task,threshold,xp,desc in ml:
        rows=list(csv.DictReader((ROOT/'data'/dataset).open()))
        if features is None: features=[k for k in rows[0] if k!=target]
        random.Random(13).shuffle(rows)
        sample=rows[:40] if key!='sentiment' else rows[:60]
        train=[]
        for row in sample:
            train.append({k: row[k] if k=='text' or k==target and task=='classification' else float(row[k]) for k in features+[target]})
        test=[{k: row[k] if k=='text' else float(row[k]) for k in features} for row in rows[60:63]]
        example={'input':{'train':train,'test':test,'features':features,'target':target},'output':{'predictions':['برچسب یا عدد برای هر سطر test']}}
        steps=['request.json شامل train دارای برچسب و test بدون برچسب است؛ فایل CSV محلی برای مطالعه است و در ارزیابی به محیط ارسال نمی‌شود.','train را به ویژگی‌ها و target جدا کن؛ داده ارزیابی بیرونی را هرگز برای fit استفاده نکن.','برای تمرین، با train_test_split و random_state=42 بخشی از train را نگه دار و مدل را با MAE یا accuracy/F1 بسنج.','مدل یا Pipeline را فقط روی داده آموزش fit کن؛ سپس برای تمام سطرهای test پیش‌بینی کن.','predictions را به ترتیب test در result.json ذخیره کن؛ امتیاز نهایی را داور روی برچسب‌های خارج محیط محاسبه می‌کند.']
        criteria=[f'{"MAE حداکثر " if task=="regression" else "accuracy و macro-F1 حداقل "}{threshold}', 'شکل و تعداد و نوع پیش‌بینی‌ها مطابق ورودی','ارزیابی روی دو تقسیم تصادفی مستقل؛ برچسب test به کد ارسال نمی‌شود.']
        dependencies=['pandas','scikit-learn','numpy']
        if key=='sentiment':
            steps.insert(2,'از TfidfVectorizer و LogisticRegression در یک Pipeline استفاده کن؛ واژگان فقط از train استخراج شوند.')
        if key=='pipeline':
            steps.insert(2,'از SimpleImputer(strategy="median")، StandardScaler و Ridge در Pipeline استفاده کن؛ چند خانه عددی train/test در ارزیابی null هستند.')
            steps.append('model.json را شامل median, mean, scale, coef (لیست به ترتیب features) و intercept ذخیره کن؛ فرمول پیش‌بینی ((x−mean)/scale)·coef+intercept است. بردار median برای null به کار می‌رود.')
            criteria.append('بازیابی عددی model.json بدون اجرای pickle؛ پیش‌بینی بازسازی‌شده باید با result.json برابر باشد.')
        outputs='result.json شامل predictions با یک مقدار به‌ازای هر سطر test؛ عدد برای رگرسیون و برچسب موجود در train برای دسته‌بندی.'
        if key=='pipeline': outputs+=' model.json با پارامترهای عددی مدل و پیش‌پردازش.'
        catalog.append(definition(key,title,desc,'جداسازی آموزش و ارزیابی و سنجش تعمیم مدل','Python · Scikit-learn · Evaluation',100 if key!='pipeline' else 150,xp,[dataset],f'train: لیست سطرهای دارای {target}؛ test: لیست ویژگی‌های بدون هدف؛ features: {features}؛ target: {target}',outputs,steps,example,criteria,dependencies,'تقسیم داده را داور هم مستقل انجام می‌دهد؛ موفقیت صرف اجرای برنامه یا metric چاپ‌شده کافی نیست. random_state=42 را برای بازتولیدپذیری تنظیم کن.'))
    for index, item in enumerate(catalog):
        item['topic']=['Python','Data Science','Machine Learning','Applied AI'][index//3]
        item['level']=['مقدماتی','تحلیل داده','یادگیری ماشین','کاربردی'][index//3]
    (ROOT/'catalog.json').write_text(json.dumps(catalog,ensure_ascii=False,indent=2)+'\n')


if __name__=='__main__':
    main()
