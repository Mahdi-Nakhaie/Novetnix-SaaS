# پروژه‌های عملی یادگیری (۱۲ پروژه، اجرا و ارزیابی واقعی)

## وضعیت این تحویل

کد و آزمون‌های محلی کامل است؛ **اجرای واقعی sandbox (Docker + gVisor) در این محیط انجام نشده** چون سرور ثبت‌شده‌ای در دسترس نیست. ارزیابی، ثبت XP و ارائه نتیجه به همان سرویس اجرای ایزوله موجود (`services/python-runner`) وابسته است؛ تا استقرار و تأیید سلامت آن، دکمه‌های اجرا و ارزیابی صریحاً غیرفعال می‌مانند و هیچ خروجی ساختگی نشان داده نمی‌شود.

## معماری

تعریف پروژه‌ها داده است، نه کد UI یا منطق امتیازدهی:

- `curriculum/catalog.json` — ۱۲ تعریف با کلید پایدار، عنوان، موضوع، سطح، مدت، XP، دیتاست، ورودی/خروجی، گام‌ها، مثال، معیارهای موفقیت، کتابخانه‌ها و `notes`.
- `curriculum/data/` — فایل‌های ثابت دیتاست به‌همراه `manifest.json` (منبع، مجوز، attribution، تعداد ردیف، sha256، تاریخ دریافت).
- `curriculum/solutions/` — راه‌حل مرجع هر پروژه؛ فقط برای آزمون و «نمایش راه‌حل» استفاده می‌شود و هرگز در snapshot دانشجو قرار نمی‌گیرد.
- `curriculum/grading.py` — تنها محل تعریف آزمون‌های پنهان، تولید deterministic ورودی‌ها، آستانه‌های ML و اعتبارسنجی artifact.
- `src/learning_projects.php` — دسترسی به کاتالوگ، شروع پروژه (فایل اولیه + دیتاست)، خواندن XP و ثبت اتمی تکمیل.
- `src/learning_project_views.php` — صفحه راهنما و محیط ساده Python (متن، انتخاب فایل، ذخیره، اجرا، ارزیابی، مشاهده artifact).
- `public/assets/learning-projects.{js,css}` — لایه تعامل؛ بدون فریم‌ورک و بدون اجرای کد در مرورگر.

هیچ سیستم موازی ساخته نشده: پروژه‌ها از `projects`، `project_workspaces` و `workspace_runs` موجود استفاده می‌کنند و فقط دو تغییر افزایشی اضافه شده است:

1. ستون‌های `assessment_key` و `assessment_version` در `workspace_runs`.
2. جدول `project_completions(user_id, learning_key, project_id, run_id UNIQUE, revision, snapshot_hash, assessment_version, xp, completed_at)` با PRIMARY KEY ترکیبی `(user_id, learning_key)`.

مهاجرت‌ها افزایشی‌اند؛ ستون یا داده‌ای حذف نمی‌شود. شاخص کاتالوگ پروژه‌های جدید از پایه ۱۰۰ است تا هویت پروژه‌های قدیمی (`LEGACY_PROJECTS`) حفظ شود.

## فهرست ۱۲ پروژه

| # | پروژه | XP | کتابخانه | دیتاست |
|---|---|---|---|---|
| ۱ | ماشین‌حساب ساده | 60 | — | — |
| ۲ | تحلیل نمرات دانشجویان | 80 | — | — |
| ۳ | تحلیل داده‌های فروش | 100 | — | sales.csv (fixture) |
| ۴ | پاک‌سازی داده‌های ناقص | 130 | pandas | missing.csv (fixture) |
| ۵ | تحلیل پیشرفته فروش + نمودار | 160 | pandas, matplotlib | sales.csv (fixture) |
| ۶ | تحلیل داده‌های آب‌وهوا + نمودار | 170 | pandas, matplotlib | weather.csv (UCI Bike Sharing) |
| ۷ | پیش‌بینی قیمت خانه | 210 | pandas, scikit-learn, numpy | housing.csv (UCI Estate Valuation) |
| ۸ | دسته‌بندی Iris | 220 | pandas, scikit-learn, numpy | iris.csv (UCI Iris) |
| ۹ | تشخیص دیابت (آموزشی) | 240 | pandas, scikit-learn, numpy | diabetes.csv (UCI Early Stage Diabetes) |
| ۱۰ | تحلیل و پیش‌بینی تأخیر پرواز | 270 | pandas, scikit-learn, numpy | flights.csv (nycflights13) |
| ۱۱ | تحلیل احساسات متن | 280 | pandas, scikit-learn, numpy | sentiment.csv (UCI Sentiment Labelled Sentences) |
| ۱۲ | خط لوله یادگیری ماشین | 350 | pandas, scikit-learn, numpy | housing.csv (UCI Estate Valuation) |

پروژه دیابت در متن پروژه، ورودی و `notes` صریحاً آموزشی اعلام شده و هیچ ادعای تشخیص پزشکی ندارد.

## دیتاست‌ها: منبع، مجوز و بازتولید

| فایل | منبع | مجوز | ردیف |
|---|---|---|---|
| housing.csv | UCI Real Estate Valuation (477)، doi:10.24432/C5J30W | CC BY 4.0 | 414 |
| diabetes.csv | UCI Early Stage Diabetes Risk Prediction (529)، doi:10.24432/C5VG8H | CC BY 4.0 | 520 |
| weather.csv | UCI Bike Sharing (275)، doi:10.24432/C5W894 | CC BY 4.0 | 731 |
| sentiment.csv | UCI Sentiment Labelled Sentences (331)، doi:10.24432/C57604 | CC BY 4.0 | 3000 |
| iris.csv | UCI Iris (53)، doi:10.24432/C56C76 | CC BY 4.0 | 150 |
| flights.csv | nycflights13 (Wickham) از مسیر Rdatasets، منبع اصلی BTS | CC0 1.0 | 2000 |
| sales.csv / missing.csv | fixture آموزشی بازتولیدپذیر | CC0 1.0 | 4 |

`sales.csv` و `missing.csv` داده واقعی نیستند و در manifest و UI به‌صراحت «fixture آموزشی» معرفی شده‌اند. فایل‌های واقعی با `tools/fetch_curriculum_data.py` از همان URLهای UCI بازسازی می‌شوند و sha256 آن‌ها با manifest تطبیق داده می‌شود؛ هیچ دانلود حین اجرا لازم نیست و شبکه sandbox بسته می‌ماند. هنگام اجرا، sha256 هر دیتاست در سمت runner دوباره بررسی می‌شود.

## اجرا

شروع پروژه یک workspace خصوصی با `main.py` و فایل(های) دیتاست می‌سازد. دکمه «اجرای واقعی» snapshot همان revision را با کلید درخواست یکتا به runner می‌فرستد؛ PHP کد دانشجو را اجرا نمی‌کند. مرزهای sandbox همان `docs/python-workspace.md` است: gVisor اجباری، بدون شبکه، فایل‌سیستم فقط‌خواندنی، کاربر 65532، 512 MiB، یک CPU، مهلت ۱۵ ثانیه، خروجی محدود. خروجی نمایشی از `stdout`/`stderr` واقعی برنامه می‌آید و در UI برچسب «اجرای واقعی» و وضعیت صف نمایش داده می‌شود.

## ارزیابی

اجرای موفق با تکمیل پروژه یکی نیست. «ارزیابی و دریافت XP» همان snapshot را با `assessment: {key, version}` می‌فرستد و runner برای هر معیار یک اجرای تازه با ورودی تازه در sandbox جدا انجام می‌دهد:

- ورودی‌های آزمون در `curriculum/grading.py` از seed مشتق از job id تولید می‌شوند و در فایل دانشجو یا snapshot وجود ندارند.
- دانشجو فقط `main.py` و `requirements.txt` را در sandbox می‌بیند؛ برچسب‌ها، انتظارات و مقادیر مرجع در فرایند runner می‌مانند.
- شرط هر معیار: return code صفر، وجود `result.json` معتبر و تطبیق مقدارهای مشخص با انتظار.
- پروژه‌های ML با آستانه‌های deterministic سنجیده می‌شوند (مثلاً MAE، دقت/F1 و تطبیق پیش‌بینی روی ردیف‌های تازه). خط لوله علاوه بر پیش‌بینی، معقول‌بودن `model.json` (median/mean/scale/coef/intercept) را بررسی می‌کند؛ برچسب‌ها هرگز به sandbox نمی‌رسند.
- نمودار با اعتبارسنجی واقعی PNG سنجیده می‌شود: ابعاد و عمق رنگ منطقی، CRC هر chunk، decompress بدون خطای zlib، طول داده متناسب با عرض/ارتفاع و تنوع پیکسل. `chart.png` باید محصول اجرای همان کد باشد.
- `lp_version()` = sha256 از `catalog.json` + `grading.py` + `manifest.json`؛ PHP و runner هر دو همین نسخه را بررسی می‌کنند. تعریف یا آزمون تغییرکرده، ارزیابی قدیمی را بی‌اعتبار می‌کند.

UI برای هر معیار `PASS`/`FAIL`، نام معیار و وضعیت واقعی اجرا را نشان می‌دهد و می‌گوید چه چیزی برای دریافت XP باقی است.

## XP

XP فقط منبع سرور دارد و یک‌بار برای هر کاربر و هر پروژه ثبت می‌شود. `lp_grant` پیش از درج، همه این‌ها را بررسی می‌کند:

1. اجرا متعلق به همان کاربر است و `state` آن `succeeded` و نتیجه ذخیره‌شده همان نتیجه ارزیابی‌شده است.
2. `remote_id` و نتیجه ارزیابی موفق وجود دارد.
3. revision فعلی workspace با revision اجرا برابر است (کد تغییریافته نمی‌تواند تکمیل قبلی را ادعا کند).
4. sha256 snapshot ذخیره‌شده با فایل‌های فعلی workspace برابر است.
5. کلید و نسخه ارزیابی با تعریف فعلی کاتالوگ منطبق است.
6. `run_id` تکراری و `(user_id, learning_key)` تکراری رد می‌شوند.

درج تکمیل و رویداد فعالیت در یک تراکنش انجام می‌شود؛ کلید اصلی ترکیبی درج هم‌زمان و ارسال دوباره را idempotent می‌کند. اجرای عادی، بازکردن پروژه و دیدن راه‌حل هیچ XPی نمی‌دهد. امتیاز قدیمی و ساختگی پنل حذف شده و پنل فقط از `project_completions` می‌خواند.

## آزمون‌ها

```sh
TMPDIR=/project/storage/tooling/tmp PYTHONPATH=/project/storage/tooling/python PHP_BIN=<php> \
python3 -m unittest discover -s tools -p 'test_*.py' -q
node --check public/assets/learning-projects.js
git diff --check
```

`tools/test_learning_projects.py` دو گروه آزمون دارد:

- **ReferenceTests** — هر ۱۲ راه‌حل مرجع با گریدر واقعی (execute شبیه‌سازی‌شده‌ی sandbox محلی) ارزیابی می‌شوند و باید در همه معیارها موفق شوند؛ همچنین جعل `assessment` از داخل کد دانشجو رد می‌شود.
- **CompletionTests** — ثبت XP با PHP واقعی و SQLite واقعی: اتمی‌بودن، مقدار XP، کاربر دیگر، یک رویداد فعالیت، revision قدیمی، نسخه اشتباه، نتیجه دست‌کاری‌شده و کلید اشتباه.

| معیار | وضعیت | شواهد / مانع |
|---|---|---|
| بازشدن هر ۱۲ پروژه | PASS | آزمون HTTP واقعی روی کاتالوگ ۱۲تایی |
| تطبیق راهنما با راه‌حل مرجع | PASS | ReferenceTests هر ۱۲ کلید |
| عبور راه‌حل‌های مرجع از ارزیابی | PASS | ۱۲/۱۲ |
| دسترس‌بودن دیتاست‌ها و sha256 | PASS | manifest + بررسی در گریدر |
| خروجی واقعی اجرا | NOT TESTED | Docker/runsc در این محیط موجود نیست |
| کد نادرست → عدم تکمیل | PASS | CompletionTests و معیارهای گریدر |
| کد درست → تکمیل و XP | PASS | CompletionTests |
| XP تکراری در ارسال دوباره | PASS | کلید یکتا و درج اتمی |
| جدایی کاربران | PASS | CompletionTests با دو کاربر |
| ماندگاری پس از ورود دوباره | PASS | خواندن از DB در آزمون‌های HTTP |
| عدم regression سایت موجود | PASS | مجموعه کامل آزمون‌ها |
| محدودیت منابع و امنیت | NOT TESTED | flags بررسی شده؛ اجرای واقعی container نیست |

## محدودیت‌های شناخته‌شده

- **اجرای واقعی sandbox آزموده نشده**؛ نیازمند سرور مجاز با Docker، runsc و cgroups v2 است.
- `services/python-runner/Dockerfile` ماژول ارزیابی و دیتاست‌ها را کپی می‌کند، اما تصویر ساخته و pin نشده است؛ اپراتور باید آن را روی سرور بسازد، اسکن کند و digest تأییدشده را ثبت کند.
- PyTorch و TensorFlow پشتیبانی نمی‌شوند؛ پروفایل ثابت numpy/pandas/scipy/scikit-learn/matplotlib/joblib است.
- runner فعلی یک worker دارد (یک اجرای فعال در کل صف)؛ برای ترافیک بیشتر باید ظرفیت با سنجش واقعی افزایش یابد.
- MySQL واقعی و بررسی بصری/مرورگر انجام نشده‌اند.
