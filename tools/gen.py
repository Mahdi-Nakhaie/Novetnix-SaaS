#!/usr/bin/env python3
"""Noventix static site generator.

Renders this project into plain HTML/CSS/JS that GitHub Pages can serve.
The dynamic PHP application under public/ and src/ is left untouched.

Usage:  python3 tools/gen.py
Output: site/index.html, site/<page>/index.html, site/404.html, site/assets/
"""
import html
import json
import pathlib
import shutil
import sys

sys.path.insert(0, str(pathlib.Path(__file__).resolve().parent))
from catalog import (  # noqa: E402
    PLANS, COMPARISON, COURSES, PROJECTS, CHALLENGES, BADGES,
    TRACKS, ANNOUNCEMENTS, CONTENT_ITEMS, SETTINGS_GROUPS, TICKET_PRIORITIES,
    ACTIVITY_WEEKS, ACTIVITY_LABELS, ACTIVITY_KINDS,
)

ROOT = pathlib.Path(__file__).resolve().parent.parent
OUT = ROOT / "site"
SITE_PATH = "/Novetnix-SaaS/site"  # GitHub Pages currently serves the main branch root

YEAR = "۱۴۰۵"
PROJECT_PLANS = ("free", "free", "bronze", "silver", "bronze", "gold", "silver", "gold", "titanium")
PAID_COURSES = ("ml-basics", "nlp-practice", "api-deployment")
ARTICLE_DETAILS = {
    "python-foundations": [("مثال قابل اجرا", "فهرست فروش‌های روزانه را دریافت کن، ورودی خالی را جداگانه بررسی کن و مجموع را بر تعداد تقسیم کن. سپس با داده‌های [۱۰۰، ۲۰۰، ۳۰۰] انتظار خروجی ۲۰۰ را داشته باش. این مثال نشان می‌دهد چرا قرارداد تابع باید قبل از پیاده‌سازی روشن باشد."), ("خطاهای رایج", "تقسیم بر صفر، تغییر ناخواسته فهرست ورودی و آمیختن دریافت داده با منطق محاسبه از خطاهای متداول‌اند. تابع را مستقل از ورودی کاربر بنویس تا بتوانی آن را با چند مجموعه داده آزمون کنی."), ("تمرین و معیار پذیرش", "تابعی بنویس که برای فهرست خالی نتیجه مشخصی برگرداند و برای اعداد اعشاری هم درست کار کند. دست‌کم سه آزمون برای حالت عادی، ورودی خالی و داده منفی بنویس.")],
    "data-analysis": [("نمونه واقعی", "در یک جدول سفارش، شناسه مشتری، زمان ثبت و مبلغ را بررسی کن. نرخ بازگشت را به‌صورت تعداد مشتریانی که در ماه بعد خرید داشته‌اند تقسیم بر تعداد مشتریان ماه اول تعریف کن؛ تعریف جمعیت پایه را در گزارش بیاور."), ("دام‌های تحلیل", "اگر تاریخ‌ها timezone یکسان نداشته باشند، سفارش‌های مرزی ممکن است به ماه اشتباه بروند. همچنین حذف مشتریان بدون خرید دوم، نرخ بازگشت را غیرواقعی بالا نشان می‌دهد."), ("تمرین عملی", "یک فایل نمونه کوچک بساز، مقادیر گمشده و سفارش‌های تکراری را بشمار و قبل از رسم نمودار تصمیم پاک‌سازی را ثبت کن. خروجی باید شامل تعداد ردیف‌های اولیه و نهایی باشد.")],
    "ml-basics": [("خط پایه و داده آزمون", "برای پیش‌بینی ریزش، داده را بر اساس زمان جدا کن تا رفتار آینده وارد آموزش نشود. یک پیش‌بین ساده که همه را در کلاس غالب می‌گذارد بساز و سپس precision و recall مدل را با آن مقایسه کن."), ("تفسیر خطا", "ماتریس درهم‌ریختگی را بخوان: مشتریان در معرض ریزش که از دست رفته‌اند کدام‌اند؟ هزینه تماس اضافه را کنار هزینه از دست دادن مشتری قرار بده تا آستانه تصمیم را آگاهانه انتخاب کنی."), ("تمرین عملی", "یک pipeline پیش‌پردازش و مدل بساز. داده آزمون را تا پایان تنظیم پارامترها کنار بگذار و در گزارش معیار خط پایه، معیار مدل و محدودیت داده را بنویس.")],
    "deep-learning": [("معماری اولیه", "برای دسته‌بندی تصویر، ابتدا اندازه ورودی و تعداد کلاس‌ها را تعیین کن. یک شبکه کوچک آموزش بده؛ تعداد پارامترها را با حجم داده مقایسه کن و از افزایش بی‌دلیل لایه‌ها خودداری کن."), ("پایش آموزش", "نمودار loss آموزش و اعتبارسنجی را در هر epoch نگه دار. اگر خطای آموزش پایین می‌رود اما اعتبارسنجی بدتر می‌شود، به بیش‌برازش فکر کن و early stopping یا داده بیشتر را بررسی کن."), ("تمرین عملی", "ده تصویرِ طبقه‌بندی‌شدهٔ اشتباه را مرور کن و برای هر خطا علت احتمالی ثبت کن. پیش از تغییر مدل بررسی کن آیا برچسب‌ها و کیفیت تصویر قابل اعتمادند.")],
    "nlp-practice": [("نرمال‌سازی قابل تکرار", "حروف ي و ك عربی را به شکل فارسی تبدیل کن و نیم‌فاصله را با یک قاعده ثابت مدیریت کن. نسخه تابع نرمال‌سازی را همراه مدل نگه دار تا پردازش ورودی جدید با آموزش یکسان بماند."), ("ارزیابی در سطح کاربر", "وقتی چند متن از یک نویسنده داری، تمام متن‌های او را در یک بخش داده قرار بده. در غیر این صورت مدل ممکن است سبک نویسنده را به‌جای احساس متن یاد بگیرد و نتیجه آزمون گمراه‌کننده شود."), ("تمرین عملی", "یک دسته‌بند ساده متن بساز؛ خطاهای مثبت کاذب و منفی کاذب را جدا فهرست کن. اثر حذف نیم‌فاصله و تغییر حروف را با دو نمونه واقعی مقایسه کن.")],
    "api-deployment": [("قرارداد درخواست", "برای endpoint پیش‌بینی یک JSON نمونه با نوع هر فیلد و محدوده معتبر آن مشخص کن. پاسخ موفق باید علاوه بر نتیجه، شناسه نسخه مدل داشته باشد تا پیگیری خطا ممکن شود."), ("یکسانی آموزش و اجرا", "مدل و تمام تبدیل‌های داده را در یک pipeline نگه دار. اگر در سرویس‌دهی ترتیب ستون‌ها یا تبدیل مقادیر گمشده متفاوت باشد، حتی مدل سالم هم پیش‌بینی نامعتبر می‌دهد."), ("تمرین عملی", "برای ورودی معتبر، مقدار گمشده و نوع داده نادرست سه آزمون API بنویس. زمان پاسخ و نرخ خطا را بسنج و داده شخصی را در لاگ ثبت نکن.")],
}


# --------------------------------------------------------------- utilities

def h(value):
    return html.escape(str(value), quote=True)


def fa(value):
    return str(value).translate(str.maketrans("0123456789", "۰۱۲۳۴۵۶۷۸۹"))


def money(value):
    return f"{fa(f'{value:,}')} تومان" if value else "رایگان"


def href(path):
    return SITE_PATH + "/" + str(path).lstrip("/")


def asset(name):
    return SITE_PATH + "/assets/" + name.lstrip("/")


def linkto(path, label, css=""):
    return f'<a class="{h(css)}" href="{h(href(path))}">{h(label)}</a>'


def section_header(eyebrow, title, body=""):
    out = f'<div class="section-header"><span class="eyebrow">{h(eyebrow)}</span><h2>{h(title)}</h2>'
    if body:
        out += f"<p>{h(body)}</p>"
    return out + "</div>"


def panel_head(title, sub):
    return ('<div class="dashboard-code-floats" aria-hidden="true"><span class="dashboard-code-a">import numpy as np</span>'
            '<span class="dashboard-code-b">model.fit(X_train, y_train)</span><span class="dashboard-code-c">print(result.score())</span></div>'
            f'<div class="panel-heading"><h1>{h(title)}</h1><p>{h(sub)}</p></div>')


def progress_bar(percent):
    return f'<div class="progress"><span style="width:{percent}%"></span></div>'


def stat_grid(stats):
    out = '<div class="stat-grid">'
    for label, value, key in stats:
        attr = f' data-stat="{h(key)}"' if key else ""
        out += f'<div class="stat-card"><span>{h(label)}</span><strong{attr}>{h(value)}</strong></div>'
    return out + "</div>"


def bars(values, gold=False):
    top = max([1] + list(values))
    out = f'<div class="chart-bars{" is-gold" if gold else ""}">'
    for v in values:
        out += f'<span style="height:{round(v / top * 100)}%" title="{h(fa(v))}"></span>'
    return out + "</div>"


# ------------------------------------------------------------ components

def plan_cards(short=False):
    out = '<div class="pricing-grid">'
    for p in PLANS:
        featured = p["id"] == "gold"
        limit = 3 if short else 5
        out += (
            f'<article class="price-card{" featured" if featured else ""}">'
            f'<div class="card-top"><span class="pill">{h(p["tag"])}</span>'
            + ('<span class="popular">پیشنهاد ما</span>' if featured else "")
            + f'</div><h3>{h(p["name"])}</h3><p class="muted">{h(p["summary"])}</p>'
            f'<div class="price">{money(p["price"]) if p["price"] else "رایگان"}'
            f'<small>{"/ ماه" if p["price"] else "برای همیشه"}</small></div>'
            f'<div class="card-divider"></div><ul class="check-list">'
        )
        for feature in p["features"][:limit]:
            out += f"<li>{h(feature)}</li>"
        out += "</ul>"
        if not short and p.get("limits"):
            out += '<div class="plan-limits"><span class="eyebrow">سقف دقیق این پلن</span><table><tbody>'
            for label, value in p["limits"]:
                out += f'<tr><th scope="row">{h(label)}</th><td>{h(value)}</td></tr>'
            out += "</tbody></table></div>"
        target = "login" if p["id"] == "free" else "panel/student/subscription"
        label = "شروع رایگان" if p["id"] == "free" else "انتخاب پلن"
        out += (f'<a class="btn {"btn-gold" if featured else "btn-outline"} full" '
                f'href="{h(href(target))}">{label}</a></article>')
    return out + "</div>"


def plan_name(pid):
    return next(p["name"] for p in PLANS if p["id"] == pid)


def project_cards(limit=None):
    rows = PROJECTS if limit is None else PROJECTS[:limit]
    glyphs = ["{ }", "◈", "◇", "⌘"]
    out = '<div class="project-grid">'
    for i, (title, topic, level, desc, skills) in enumerate(rows):
        number = fa(f"{i + 1:02d}")
        out += (
            f'<article class="project-card"><div class="project-visual visual-{i % 4}">'
            f'<span class="visual-number">{number}</span>'
            f'<span class="visual-glyph">{glyphs[i % 4]}</span>'
            f'<span class="visual-label">{h(topic.upper())}</span></div>'
            f'<div class="project-info"><div class="meta-row"><span class="pill">{h(topic)}</span>'
            f'<span>{h(level)}</span><span class="pill">پلن {h(plan_name(PROJECT_PLANS[i]))}</span></div><h3>{h(title)}</h3><p>{h(desc)}</p>'
            f'<div class="project-bottom"><span dir="ltr">{h(skills)}</span>'
            f'<a href="{h(href(f"project/{i}"))}" aria-label="مشاهده پروژه {h(title)}">مشاهده پروژه ←</a>'
            "</div></div></article>"
        )
    return out + "</div>"


def course_cards():
    out = '<div class="course-grid">'
    for i, (slug, c) in enumerate(COURSES.items()):
        out += (
            f'<article class="course-card"><div class="course-icon icon-{i % 4}">{h(c["topic"][0])}</div>'
            f'<div class="meta-row"><span class="pill">{h(c["topic"])}</span>'
            f'<span>{h(c["level"])} · {fa(c["minutes"])} دقیقه مطالعه</span>'
            f'<span class="pill">{("پلن برنزی" if slug in PAID_COURSES else "رایگان")}</span></div>'
            f'<h3>{h(c["title"])}</h3><p>{h(c["intro"])}</p>'
            f'<a class="inline-link" href="{h(href(f"course/{slug}"))}">مطالعه مقاله ←</a></article>'
        )
    return out + "</div>"


NAV_ITEMS = [("", "خانه"), ("courses", "مسیر یادگیری"), ("projects", "پروژه‌ها"),
             ("community", "انجمن"), ("pricing", "قیمت‌گذاری"), ("about", "درباره ما"),
             ("contact", "تماس با ما")]


def header_html(active):
    out = (
        f'<header class="site-header"><div class="container header-inner">'
        f'<a href="{h(href(""))}" class="brand" aria-label="Noventix، صفحه اصلی">'
        f'<img src="{h(asset("favicon.png"))}" alt="" width="48" height="48"><span class="brand-name">Noventix</span></a>'
        '<nav class="main-nav" aria-label="ناوبری اصلی">'
    )
    for path, label in NAV_ITEMS:
        out += linkto(path, label, "active" if active == path else "")
    out += ('</nav><div class="header-actions">'
            + linkto("panel/student", "پنل من", "btn btn-small btn-outline")
            + linkto("panel/student", "شروع رایگان", "btn btn-small btn-primary")
            + '</div><button class="menu-toggle" type="button" aria-label="باز کردن فهرست" '
              'aria-expanded="false" aria-controls="mobile-nav">☰</button></div>'
              '<nav id="mobile-nav" class="mobile-nav" aria-label="ناوبری موبایل">')
    for path, label in NAV_ITEMS + [("panel/student", "پنل من")]:
        out += linkto(path, label)
    return out + "</nav></header>"


SOCIALS = [
    ("https://instagram.com/noventix.ir", "Instagram", "noventix.ir"),
    ("https://t.me/noventix_ir", "Telegram", "noventix_ir@"),
    ("https://rubika.ir/noventix_ir", "Rubika", "noventix_ir@"),
    ("https://ble.ir/noventix_ir", "Bale", "noventix_ir@"),
    ("https://www.aparat.com/noventix.ir", "Aparat", "noventix.ir"),
]


def footer_html():
    return (
        '<footer class="footer"><div class="container"><div class="footer-grid">'
        f'<div><a href="{h(href(""))}" class="footer-brand">Noventix <span>N</span></a>'
        '<p>از یاد گرفتن تا توانایی ساختن؛ یک قدم واقعی در هر پروژه.</p></div>'
        "<div><h3>یادگیری</h3>"
        + linkto("courses", "مقاله‌ها") + linkto("projects", "پروژه‌ها") + linkto("community", "انجمن")
        + "</div><div><h3>Noventix</h3>"
        + linkto("pricing", "پلن‌ها") + linkto("about", "درباره ما") + linkto("contact", "تماس با ما")
        + linkto("help", "مرکز راهنما") + linkto("support", "پشتیبانی") + linkto("terms", "قوانین و مقررات")
        + linkto("privacy", "حریم خصوصی")
        + "</div><div><h3>همراه ما باشید</h3>"
        + "".join(f'<a href="{u}" rel="noopener noreferrer" target="_blank">{n} ↗</a>' for u, n, _ in SOCIALS)
        + f'</div></div><div class="footer-end"><span>© {YEAR} Noventix. همه حقوق محفوظ است.</span>'
          "<span>یادگیری عمیق، ساختن واقعی.</span></div></div></footer>"
    )


# --------------------------------------------------------------- views

def view_home():
    out = "<main>" + (
        '<section class="hero container"><div class="hero-copy">'
        '<span class="eyebrow"><span class="dot"></span> مسیر تازه یادگیری هوش مصنوعی</span>'
        "<h1>فقط یاد نگیر؛<br><em>واقعاً بساز.</em></h1>"
        "<p>پایتون، علم داده و هوش مصنوعی را با مقاله‌های کاربردی یاد بگیر؛ بعد با پروژه‌های واقعی، آموخته‌هایت را به مهارت تبدیل کن.</p>"
        '<div class="hero-buttons">' + linkto("courses", "شروع مسیر یادگیری ←", "btn btn-primary")
        + linkto("projects", "دیدن پروژه‌ها", "btn btn-outline") + "</div>"
        '<div class="hero-proof"><span>✦ مسیر پروژه‌محور</span><span>✦ یادگیری به زبان فارسی</span><span>✦ شروع رایگان</span></div></div>'
        '<div class="hero-code-floats" aria-hidden="true"><span class="code-float code-float-a"><code>import numpy as np</code></span>'
        '<span class="code-float code-float-b"><code>model.fit(X, y)</code></span></div>'
        '<div class="hero-display"><div class="hero-window"><div class="window-top">'
        '<span class="window-dots">● ● ●</span><span dir="ltr">noventix / your-next-project</span>'
        '<span class="code-label">PYTHON</span></div>'
        f'<div class="nova-bubble"><img src="{h(asset("nova.png"))}" alt="مسکات Nova" width="70" height="70">'
        "<span><strong>سلام، من Nova هستم!</strong><small>بیا مسئله را قدم‌به‌قدم حل کنیم.</small></span></div>"
        '<pre dir="ltr"><span class="code-blue">from</span> sklearn.model_selection <span class="code-blue">import</span> train_test_split\n\n'
        '<span class="code-purple">def</span> <span class="code-yellow">build_your_future</span>(data):\n'
        '    insights = <span class="code-yellow">learn</span>(data)\n'
        '    <span class="code-purple">return</span> <span class="code-green">"something remarkable"</span></pre>'
        '<div class="window-footer"><span><span class="status-dot"></span> اولین پروژه‌ات از همین‌جا شروع می‌شود</span>'
        f'<a href="{h(href("projects"))}">شروع ساخت ←</a></div></div>'
        '<div class="floating-note"><span>↗</span><strong>از ایده تا اجرا</strong><small>یک پروژه در هر قدم</small></div></div></section>'
    )
    out += ('<section class="metrics"><div class="container metrics-inner">'
            "<div><strong>۰۱</strong><span>مقاله‌های روشن و کاربردی</span></div>"
            "<div><strong>۰۲</strong><span>تمرین روی پروژه واقعی</span></div>"
            "<div><strong>۰۳</strong><span>بازخورد و رشد مستمر</span></div>"
            "<div><strong>∞</strong><span>مسیرهای تازه برای ساختن</span></div></div></section>")

    out += ('<section class="section container">'
            + section_header("روش یادگیری ما", "فاصله دانستن تا توانستن را کم کن",
                             "به جای جمع‌کردن آموزش‌های ناتمام، با یک مسیر روشن مهارتت را در عمل بساز.")
            + '<div class="features-grid">'
              '<article class="feature"><div class="feature-icon">◇</div><span class="feature-n">01 / DISCOVER</span>'
              "<h3>بخوان و بفهم</h3><p>مفهوم‌های پایتون، ماشین لرنینگ و علم داده را در مقاله‌های کوتاه و هدفمند یاد بگیر.</p></article>"
              '<article class="feature"><div class="feature-icon">⌘</div><span class="feature-n">02 / BUILD</span>'
              "<h3>یادگیری با ساختن</h3><p>از تحلیل داده تا مدل‌های یادگیری عمیق، مهارت‌هایت را روی مسئله‌های واقعی آزمایش کن.</p></article>"
              '<article class="feature"><div class="feature-icon">✳</div><span class="feature-n">03 / GROW</span>'
              "<h3>پیشرفت قابل مشاهده</h3><p>پروژه‌هایت را در میزکار دنبال کن و با گفت‌وگو در انجمن، راه‌حل‌های بهتری پیدا کن.</p></article>"
              "</div></section>")

    out += ('<section class="section alt-section"><div class="container"><div class="section-row">'
            + section_header("مسیرهای یادگیری", "از کجا شروع می‌کنی؟", "مقاله‌های کاربردی برای هر مرحله از سفر یادگیری.")
            + linkto("courses", "همه مقاله‌ها ←", "inline-link") + "</div>" + course_cards() + "</div></section>")

    out += ('<section class="section container"><div class="section-row">'
            + section_header("یادگیری در عمل", "پروژه‌هایی برای دنیای واقعی",
                             "از مسئله‌های کوچک شروع کن و به ساختن راه‌حل‌های قابل ارائه برس.")
            + linkto("projects", "همه پروژه‌ها ←", "inline-link") + "</div>" + project_cards(3) + "</section>")

    out += ('<section class="section dark-band"><div class="container band-inner"><div>'
            '<span class="eyebrow">متد یادگیری CRAFT</span>'
            "<h2>هر قدم یادگیری،<br>یک قدم نزدیک‌تر به ساختن.</h2>"
            "<p>از درک مسئله و تمرین تا کاربرد آموخته‌ها در موقعیت‌های تازه؛ مسیری که با عمل معنا پیدا می‌کند.</p>"
            + linkto("about", "درباره رویکرد ما ←", "btn btn-light") + "</div>"
            '<div class="band-steps">'
            "<div><span>01</span><strong>درک مفهوم</strong><small>با سؤال درست شروع کن</small></div>"
            "<div><span>02</span><strong>ساخت پروژه</strong><small>راه‌حل را امتحان کن</small></div>"
            "<div><span>03</span><strong>انتقال مهارت</strong><small>در مسئله تازه مستقل باش</small></div>"
            "</div></div></section>")

    out += ('<section class="section container">'
            + section_header("پلن‌های یادگیری", "برای هر مرحله، یک انتخاب",
                             "از رایگان شروع کن و هر وقت آماده بودی، امکانات بیشتر را فعال کن.")
            + plan_cards(True)
            + '<div class="center-link">' + linkto("pricing", "مقایسه کامل امکانات پلن‌ها ←", "inline-link") + "</div></section>")

    out += ('<section class="section container"><div class="cta-panel">'
            '<span class="eyebrow">آینده از همین قدم شروع می‌شود</span>'
            "<h2>پروژه بعدی‌ات منتظر توست.</h2>"
            "<p>مسیرت را انتخاب کن، یاد بگیر و چیزی بساز که بتوانی به آن افتخار کنی.</p>"
            + linkto("login", "رایگان شروع کن ←", "btn btn-light") + "</div></section></main>")
    return out


def page_hero(eyebrow, title, body):
    return (f'<section class="page-hero container"><span class="eyebrow">{h(eyebrow)}</span>'
            f"<h1>{h(title)}</h1><p>{h(body)}</p></section>")


def view_courses():
    return ('<main>' + page_hero("مسیر یادگیری", "قدم‌به‌قدم تا توانایی ساختن",
                                 "هر مقاله یک مفهوم کاربردی را با تمرین واقعی ترکیب می‌کند؛ از پایتون تا یادگیری عمیق.")
            + '<section class="section container">' + course_cards() + "</section></main>")


def view_course(slug):
    c = COURSES[slug]
    sections = list(c["sections"]) + ARTICLE_DETAILS.get(slug, [])
    paid = slug in PAID_COURSES
    out = ('<div class="reading-progress" aria-hidden="true"><span data-reading-progress></span></div>'
           f'<main class="article"><div class="container narrow-container">'
           f'<span class="eyebrow">{h(c["topic"])} · {h(c["level"])} · '
           f'{"پلن برنزی" if paid else "رایگان"}</span>'
           f'<h1>{h(c["title"])}</h1><p class="lead">{h(c["intro"])}</p>'
           f'<div class="article-meta"><span>زمان مطالعه: {fa(c["minutes"])} دقیقه</span>'
           f'<span>{fa(len(sections))} بخش</span><span>به‌روزرسانی: {YEAR[:4]}</span></div>'
           '<nav class="article-toc" aria-label="فهرست مطالب"><strong>در این مقاله می‌خوانید</strong><ol>')
    for heading, _ in sections:
        out += f"<li>{h(heading)}</li>"
    out += "</ol></nav>"
    for i, (heading, body) in enumerate(sections):
        out += f'<section class="article-section" id="section-{i + 1}"><h2><span>{fa(i + 1)}</span>{h(heading)}</h2>'
        for para in body.split("\n"):
            out += f"<p>{h(para)}</p>"
        out += "</section>"
    out += ('<section class="article-section takeaways"><h2><span>✓</span>جمع‌بندی و گام بعدی</h2><ul class="check-list">'
            f'<li>مفهوم اصلی «{h(c["title"])}» را با یک مثال کوچک روی داده خودت تمرین کن.</li>'
            "<li>قبل از افزودن پیچیدگی، نتیجه را با یک خط پایه ساده مقایسه کن.</li>"
            "<li>پس از مطالعه، همین الگو را در یک پروژه واقعی به کار بگیر.</li></ul></section>"
            '<div class="article-footer">'
            f'<button type="button" class="btn btn-primary" data-enroll="{h(slug)}">افزودن به مسیر یادگیری</button>'
            + linkto("projects", "پروژه مرتبط را ببین ←", "btn btn-outline") + "</div></div></main>")
    return out


def view_projects():
    return ('<main>' + page_hero("پروژه‌ها", "یادگیری با ساختن، نه فقط خواندن",
                                 "هر پروژه یک مسئله واقعی با ورودی، خروجی و معیار موفقیت روشن است.")
            + '<section class="section container">' + project_cards() + "</section></main>")


def view_project(index):
    title, topic, level, desc, skills = PROJECTS[index]
    steps = [
        ("صورت مسئله", "مسئله را با داده و محدودیت‌های واقعی تعریف کن؛ خروجی مورد انتظار و معیار موفقیت را از ابتدا روشن کن."),
        ("مسیر پیشنهادی", "با یک راه‌حل ساده شروع کن، نتیجه را بسنج و سپس گام‌به‌گام پیچیدگی را فقط در جایی که لازم است اضافه کن."),
        ("ارزیابی و انتقال", "کیفیت راه‌حل را با معیار روشن بسنج و همان الگو را روی یک مسئله تازه امتحان کن تا مهارت واقعی شکل بگیرد."),
    ]
    out = (f'<main class="article"><div class="container narrow-container">'
           f'<span class="eyebrow">{h(topic)} · {h(level)} · پلن {h(plan_name(PROJECT_PLANS[index]))}</span><h1>{h(title)}</h1>'
           f'<p class="lead">{h(desc)}</p><div class="article-meta"><span dir="ltr">{h(skills)}</span></div>')
    for i, (heading, body) in enumerate(steps):
        out += f'<section class="article-section"><h2><span>{fa(i + 1)}</span>{h(heading)}</h2><p>{h(body)}</p></section>'
    out += ('<div class="article-footer">'
            f'<button type="button" class="btn btn-primary" data-project="{index}">افزودن به پروژه‌های من</button>'
            + linkto("community", "پرسش در انجمن ←", "btn btn-outline") + "</div></div></main>")
    return out


def view_pricing():
    out = ('<main>' + page_hero("قیمت‌گذاری", "پلنی که با مسیرت هماهنگ است",
                                "از رایگان شروع کن و هر زمان که خواستی، امکانات بیشتر را فعال کن.")
           + '<section class="section container">' + plan_cards() + "</section>")
    out += ('<section class="section container"><div class="section-header">'
            '<span class="eyebrow">مقایسه کامل</span><h2>دقیقاً چه چیزی به دست می‌آوری؟</h2>'
            "<p>همه قیمت‌ها ماهانه و به تومان است.</p></div>"
            '<div class="table-wrap compare-wrap"><table class="compare-table">'
            '<caption class="sr-only">مقایسه امکانات پلن‌های Noventix</caption>'
            '<thead><tr><th scope="col">ویژگی</th>')
    for p in PLANS:
        out += f'<th scope="col">{h(p["name"])}</th>'
    out += "</tr></thead><tbody>"
    for feature, values in COMPARISON:
        out += f'<tr><th scope="row">{h(feature)}</th>'
        for v in values:
            out += f"<td>{h(v)}</td>"
        out += "</tr>"
    out += ('</tbody></table></div>'
            '<div class="table-wrap compare-wrap"><table class="compare-table">'
            '<caption class="sr-only">سقف دقیق امکانات هر پلن</caption>'
            '<thead><tr><th scope="col">سقف دقیق</th>')
    for p in PLANS:
        out += f'<th scope="col">{h(p["name"])}</th>'
    out += "</tr></thead><tbody>"
    for index in range(len(PLANS[0]["limits"])):
        label = PLANS[0]["limits"][index][0]
        out += f'<tr><th scope="row">{h(label)}</th>'
        for p in PLANS:
            out += f'<td>{h(p["limits"][index][1])}</td>'
        out += "</tr>"
    out += ('</tbody></table></div>'
            '<p class="fine-print">سقف‌ها ماهانه شمرده می‌شوند و در ابتدای هر دوره بازنشانی می‌شوند. '
            'اگر به سقف برسی، دسترسی‌ات قطع نمی‌شود؛ فقط تا دورهٔ بعد امکان استفادهٔ بیشتر از آن مورد را نداری.</p>'
            '</section><section class="section container"><div class="cta-panel">'
            '<span class="eyebrow">مسیر یادگیری</span><h2>پلن‌ها و امکانات</h2>'
            '<p>برای فعال‌سازی پلن و دریافت راهنمای پرداخت با پشتیبانی تماس بگیرید.</p>'
            + linkto("panel/student/subscription", "مشاهده پلن‌ها ←", "btn btn-light") + "</div></section></main>")
    return out


def view_about():
    blocks = [
        ("مسئله‌ای که دیدیم", "فاصله میان دانستن و ساختن، بزرگ‌ترین مشکل یادگیری برنامه‌نویسی است. بسیاری پایتون را می‌دانند، اما در برابر یک مسئله تازه نمی‌دانند از کجا شروع کنند."),
        ("رویکرد ما", "یادگیری باید با عمل گره بخورد. به همین دلیل هر مفهوم با یک پروژه واقعی همراه است و ارزیابی بر پایه انتقال مهارت انجام می‌شود."),
        ("تعهد ما", "شفافیت در آنچه ارائه می‌دهیم، پرهیز از وعده‌های بزرگ و احترام به زمان یادگیرنده؛ سه اصل ساده‌ای که به آن پایبندیم."),
    ]
    out = ('<main>' + page_hero("درباره ما", "ما Noventix را برای ساختن ساختیم",
                                "اینجا فقط دوره نمی‌بینی؛ با یک مسیر روشن، مهارتت را در پروژه‌های واقعی می‌سازی.")
           + '<section class="section container"><div class="split">')
    for i, (heading, body) in enumerate(blocks):
        out += f'<div class="article-section"><h2><span>{fa(i + 1)}</span>{h(heading)}</h2><p>{h(body)}</p></div>'
    out += ('</div></section><section class="section container"><div class="section-header">'
            '<span class="eyebrow">تجربه یادگیری</span><h2>سه اصل راهنمای ما</h2></div>'
            '<div class="features-grid">'
            '<article class="feature"><div class="feature-icon">◇</div><h3>شفافیت</h3>'
            "<p>هر مقاله هدف، پیش‌نیاز و نتیجه‌اش را از ابتدا روشن می‌کند.</p></article>"
            '<article class="feature"><div class="feature-icon">⌘</div><h3>کاربردپذیری</h3>'
            "<p>محتوایی می‌نویسیم که در پروژه واقعی به کار بیاید، نه فقط در آزمون.</p></article>"
            '<article class="feature"><div class="feature-icon">✳</div><h3>احترام به یادگیرنده</h3>'
            "<p>بدون وعده‌های غیرواقعی؛ مسیر و انتظارات روشن است.</p></article>"
            "</div></section></main>")
    return out


def view_contact():
    return ('<main><section class="contact-hero"><div class="container"><span class="eyebrow">راه‌های ارتباطی Noventix</span>'
            '<h1>از اینجا با ما در ارتباط باشید</h1><p>برای پیگیری سؤال‌ها و پیشنهادها، راه ارتباطی مناسب خود را انتخاب کنید.</p>'
            '<div class="contact-quick-links">'
            + linkto("support", "مرکز پشتیبانی ←", "btn btn-primary")
            + linkto("community", "پرسش در انجمن ←", "btn btn-outline")
            + '</div></div></section><section class="section container"><div class="contact-layout contact-page-layout">'
              '<div class="panel-card contact-form-card"><span class="eyebrow">پیام مستقیم</span><h2>برای ما پیام بفرستید</h2>'
              '<p class="muted">درخواست خود را با جزئیات بنویسید. در نسخه نمایشی، پیام به‌صورت محلی ثبت می‌شود.</p>'
              '<form class="stack-form" data-form="contact">'
              '<label>نام و نام خانوادگی<input name="name" autocomplete="name" required minlength="2" maxlength="100"></label>'
              '<label>ایمیل برای پاسخ<input type="email" dir="ltr" name="email" autocomplete="email" required maxlength="255" placeholder="you@example.com"></label>'
              '<label>متن پیام<textarea name="message" required minlength="10" maxlength="3000" rows="6" placeholder="موضوع درخواست و جزئیات آن را بنویسید"></textarea></label>'
              '<button class="btn btn-primary">ثبت پیام</button></form>'
              '<p class="form-note" hidden></p><p class="contact-footnote">در نسخه نمایشی، پیام فقط در همین مرورگر ذخیره می‌شود و به تیم پشتیبانی ارسال نمی‌شود.</p></div>'
              '<div class="contact-side"><div class="contact-info-card"><span class="eyebrow">راهنمای ارتباط</span>'
              '<h2>کدام مسیر مناسب شماست؟</h2><p>برای مشکلات حساب یا خدمات، از مرکز پشتیبانی استفاده کنید؛ برای گفت‌وگوی آموزشی و تجربه‌ها به انجمن سر بزنید.</p>'
              + linkto("support", "رفتن به پشتیبانی ←", "inline-link")
              + '</div><div class="contact-social"><h2>ما را دنبال کنید</h2><p>آموزش‌ها و تازه‌های Noventix را در شبکه‌های اجتماعی دنبال کنید.</p><div class="social-column">'
              + "".join(f'<a href="{h(u)}" target="_blank" rel="noopener noreferrer" aria-label="{h(n)}، {h(s)}"><strong>{h(n)}</strong><span dir="ltr">{h(s)} ↗</span></a>' for u, n, s in SOCIALS)
              + '</div></div></div></div></section></main>')


def view_help():
    return ('<main>' + page_hero("مرکز راهنما", "پاسخ‌های روشن برای شروع سریع", "راهنمای استفاده از مسیر یادگیری، میزکار کد و حساب کاربری.")
            + '<section class="section container"><div class="help-grid">'
            + '<article class="panel-card"><h2>شروع کار</h2><p>برای ورود به پنل، نام، نام خانوادگی و شماره موبایل خود را وارد کنید و کد تأیید را ثبت کنید.</p></article>'
            + '<article class="panel-card"><h2>میزکار کد</h2><p>کد Python را در میزکار اجرا کنید. خروجی متنی، ماتریس و نمودار در همان صفحه نمایش داده می‌شود.</p></article>'
            + '<article class="panel-card"><h2>نیاز به کمک دارید؟</h2><p>موضوع را از مسیر پشتیبانی ثبت کنید یا از صفحه تماس با ما پیام بفرستید.</p>'
            + linkto("support", "رفتن به پشتیبانی ←", "btn btn-outline") + '</article></div></section></main>')


def view_support():
    return ('<main>' + page_hero("پشتیبانی", "کنارتان هستیم", "برای مشکل حساب، مسیر یادگیری یا پرداخت، درخواست خود را ثبت کنید.")
            + '<section class="section container"><div class="panel-card narrow"><h2>ارسال درخواست</h2>'
            + '<form class="stack-form" data-form="contact"><label>نام<input name="name" required minlength="2" maxlength="100"></label>'
            + '<label>ایمیل<input type="email" dir="ltr" name="email" required maxlength="255"></label>'
            + '<label>شرح درخواست<textarea name="message" required minlength="10" maxlength="3000" rows="6"></textarea></label>'
            + '<button class="btn btn-primary">ارسال درخواست</button></form><p class="form-note" hidden></p></div></section></main>')


def view_legal(title, intro, sections):
    out = '<main>' + page_hero(title, intro, '') + '<section class="section container"><div class="article-section">'
    for heading, body in sections:
        out += f'<h2>{h(heading)}</h2><p>{h(body)}</p>'
    return out + '</div></section></main>'


def view_terms():
    sections = [
        ("scope", "این قوانین چه چیزی را پوشش می‌دهد؟",
         "این صفحه شرایط استفاده از سایت، پنل کاربری، میزکار کد و انجمن Noventix را توضیح می‌دهد. با ساختن حساب یا استفاده از امکانات سایت، این شرایط را می‌پذیرید. اگر با بخشی از آن موافق نیستید، می‌توانید از امکاناتی که نیاز به حساب ندارند استفاده کنید یا پیش از ادامه با پشتیبانی گفت‌وگو کنید.",
         ["سایت، پنل، میزکار و انجمن را دربر می‌گیرد.",
          "ساخت حساب یعنی پذیرش این شرایط.",
          "بخش‌های عمومی سایت بدون حساب هم قابل مطالعه‌اند."]),
        ("account", "حساب کاربری و امنیت",
         "برای استفاده از بخش‌های کاربری، اطلاعات صحیح و متعلق به خودتان را وارد کنید. شماره موبایل شناسه ورود شماست و رمز عبور را باید محرمانه نگه دارید. اگر نشانه‌ای از دسترسی مشکوک دیدید، از طریق پشتیبانی اطلاع دهید تا دسترسی‌ها بررسی شود. تا زمانی که موضوع بررسی نشده، فعالیت‌های انجام‌شده با حساب شما به همان حساب نسبت داده می‌شود.",
         ["اطلاعات باید واقعی و متعلق به خودتان باشد.",
          "رمز عبور را در اختیار دیگران قرار ندهید.",
          "دسترسی مشکوک را سریع به پشتیبانی گزارش دهید.",
          "یک حساب برای یک نفر؛ ساخت حساب جعلی مجاز نیست."]),
        ("verification", "ورود با کد پیامکی و تأیید هویت",
         "ورود به حساب با شماره موبایل و کد یک‌بارمصرف انجام می‌شود. کد تأیید فقط برای شماست و نباید آن را برای کسی بفرستید؛ حتی اگر فرستنده خود را از تیم Noventix معرفی کند. تعداد درخواست‌های کد در هر نشست محدود است تا از سوءاستفاده جلوگیری شود. اگر کد را چند بار اشتباه وارد کنید، برای مدتی باید صبر کنید.",
         ["کد تأیید را با هیچ‌کس به اشتراک نگذارید.",
          "درخواست کد محدود است تا از سوءاستفاده جلوگیری شود.",
          "تلاش‌های ناموفق موقتاً محدود می‌شوند."]),
        ("learning", "محتوای آموزشی و حقوق نشر",
         "دوره‌ها، تمرین‌ها و مطالب سایت برای یادگیری شخصی ارائه می‌شوند. بازنشر، فروش یا ارائه آن‌ها به نام خود بدون اجازه صاحب اثر مجاز نیست. استفاده از منابع بیرونی هم تابع شرایط و مجوز همان منابع است. اگر برای تدریس یا کار تیمی به استفاده گسترده‌تر نیاز دارید، پیش از انتشار با ما هماهنگ کنید.",
         ["مطالب برای یادگیری شخصی شماست.",
          "بازنشر یا فروش بدون اجازه مجاز نیست.",
          "به شرایط استفاده منابع بیرونی هم پایبند باشید."]),
        ("workspace", "میزکار کد و فایل‌ها",
         "کد شما با مفسر واقعی Python در مرورگر خودتان اجرا می‌شود و فایل‌های پروژه تا زمانی که مرورگر داده‌ها را نگه دارد در همان دستگاه می‌مانند. پیش از بارگذاری فایل یا اجرای کد، اطلاعات محرمانه مانند رمز، کلید دسترسی و داده شخصی دیگران را حذف کنید. میزکار جایگزین فضای نگهداری دائمی نیست؛ از کارهای مهم خود نسخه پشتیبان بگیرید. اجرای کد می‌تواند به اتصال اینترنت و بسته‌های سازگار نیاز داشته باشد و برای جلوگیری از حلقه‌های بی‌پایان، زمان اجرا محدود است.",
         ["کد در مرورگر خودتان اجرا می‌شود، نه روی سرور ما.",
          "فایل‌های محرمانه را در میزکار بارگذاری نکنید.",
          "میزکار فضای نگهداری دائمی نیست؛ پشتیبان بگیرید.",
          "زمان اجرا محدود است تا حلقه‌های بی‌پایان متوقف شوند."]),
        ("ai", "استفاده از Nova و ابزارهای کمکی",
         "Nova برای کمک به یادگیری طراحی شده است؛ پاسخ‌های آن می‌تواند ناقص یا نادرست باشد و جای بررسی خودتان را نمی‌گیرد. خروجی Nova را پیش از استفاده در پروژه واقعی، آزمون یا کار درسی خودتان بسنجید. تعداد درخواست‌های هر پلن مشخص است و از سهمیه ماهانه شما کم می‌شود. اطلاعات محرمانه یا داده شخصی دیگران را در پرسش‌ها وارد نکنید.",
         ["پاسخ Nova ممکن است نادرست باشد؛ خودتان بررسی کنید.",
          "سهمیه هر پلن مشخص و محدود است.",
          "داده محرمانه در پرسش‌ها وارد نکنید."]),
        ("community", "انجمن و تعامل با دیگران",
         "در پست‌ها، نظرات و گفت‌وگوها محترمانه و مرتبط با موضوع مشارکت کنید. انتشار محتوای آزاردهنده، فریبنده، ناقض حقوق دیگران یا اطلاعات خصوصی افراد بدون رضایتشان مجاز نیست. پست‌ها و نظرات شما برای دیگر اعضای انجمن قابل مشاهده است، پس پیش از انتشار مطمئن شوید که انتشارشان را تأیید می‌کنید. در صورت مشاهده تخلف می‌توانید موضوع را به پشتیبانی گزارش دهید.",
         ["محتوا برای دیگر اعضا قابل مشاهده است.",
          "توهین، فریب و نقض حقوق دیگران مجاز نیست.",
          "اطلاعات خصوصی دیگران را بدون اجازه منتشر نکنید.",
          "تخلف‌ها را به پشتیبانی گزارش دهید."]),
        ("content", "محتوای کاربران و مسئولیت آن",
         "مسئولیت آنچه منتشر می‌کنید با خودتان است. با انتشار محتوا در انجمن، به Noventix اجازه می‌دهید آن را در همان فضا نمایش دهد و برای خوانایی یا دسته‌بندی، ویرایش‌های جزئی روی آن انجام دهد. مالکیت اثر همچنان از شماست. اگر محتوایی منتشر کردید که باید حذف شود، از پشتیبانی درخواست کنید؛ در موارد نقض قوانین ممکن است محتوا بدون اطلاع قبلی حذف شود.",
         ["مسئولیت محتوای منتشرشده با شماست.",
          "مالکیت اثر شما حفظ می‌شود.",
          "Noventix می‌تواند محتوای ناقض قوانین را حذف کند."]),
        ("plans", "پلن‌ها، پرداخت و لغو",
         "امکانات هر پلن در صفحه قیمت‌گذاری توضیح داده شده‌اند. فعال‌سازی اشتراک پس از تأیید پرداخت انجام می‌شود و تا پایان دوره اعتبار دارد. ثبت درخواست یا نمایش فرم اشتراک به‌معنای پرداخت موفق نیست. اگر دوره‌ای تمدید نشود، دسترسی به امکانات همان پلن در پایان دوره متوقف می‌شود و حساب شما حذف نمی‌شود. برای لغو یا تغییر پلن از پشتیبانی کمک بگیرید.",
         ["دسترسی پولی پس از تأیید پرداخت فعال می‌شود.",
          "پایان دوره یعنی پایان دسترسی، نه حذف حساب.",
          "تغییر یا لغو پلن از مسیر پشتیبانی انجام می‌شود."]),
        ("fair-use", "استفاده منصفانه و محدودیت‌ها",
         "امکانات سایت برای یادگیری طراحی شده‌اند، نه برای بهره‌برداری خودکار یا انبوه. تلاش برای دورزدن محدودیت‌ها، استخراج انبوه محتوا، بارگذاری مخرب روی سرویس یا دسترسی برنامه‌نویسی‌شده بدون هماهنگی مجاز نیست. در صورت مشاهده چنین الگوهایی، دسترسی محدود یا موقتاً متوقف می‌شود تا موضوع بررسی شود.",
         ["استفاده خودکار یا انبوه مجاز نیست.",
          "دورزدن محدودیت‌ها و بارگذاری مخرب پیامد دارد.",
          "در موارد مشکوک، دسترسی موقتاً محدود می‌شود."]),
        ("availability", "دسترس‌پذیری خدمات",
         "ممکن است برای نگهداری، به‌روزرسانی یا رفع خطا، بخشی از خدمات موقتاً در دسترس نباشد. عملکرد بعضی قابلیت‌ها مانند اجرای کد به اتصال اینترنت، مرورگر و بسته‌های سازگار نیز وابسته است. تلاش می‌کنیم تغییرات را از قبل اطلاع دهیم، اما در همه موارد ممکن نیست. در صورت بروز مشکل، جزئیات خطا و زمان آن را از طریق پشتیبانی ارسال کنید.",
         ["نگهداری و رفع خطا می‌تواند باعث قطع موقت شود.",
          "بعضی قابلیت‌ها به اینترنت و مرورگر وابسته‌اند.",
          "گزارش دقیق خطا به رفع سریع‌تر کمک می‌کند."]),
        ("ip", "مالکیت معنوی Noventix",
         "نام، نشان و طراحی Noventix و ساختار محتوایی سایت متعلق به این مجموعه است. استفاده از آن‌ها در پروژه شخصی شما برای تمرین آزاد است، اما استفاده تجاری، ساخت سرویس مشابه با همان هویت یا معرفی محصول خود به نام Noventix مجاز نیست. برای همکاری یا استفاده خاص، پیش از هر اقدام با ما هماهنگ کنید.",
         ["هویت و طراحی سایت متعلق به Noventix است.",
          "استفاده تجاری یا معرفی محصول دیگر مجاز نیست."]),
        ("liability", "محدوده مسئولیت",
         "محتوای آموزشی با دقت تهیه می‌شود، اما تضمینی برای درست‌بودن همه‌جانبه یا مناسب‌بودن آن برای هدف خاص شما داده نمی‌شود. Noventix مسئول نتایج تصمیم‌هایی که بر پایه محتوا یا خروجی ابزارهای سایت می‌گیرید، نیست. استفاده از آموخته‌ها در پروژه واقعی، با ارزیابی و مسئولیت خودتان انجام می‌شود.",
         ["محتوا با دقت تهیه می‌شود، اما تضمین مطلق نیست.",
          "مسئولیت تصمیم‌های شما با خودتان است."]),
        ("privacy", "حریم خصوصی و نگهداری اطلاعات",
         "برای ارائه خدمات، اطلاعاتی مانند نام، شماره موبایل و پیشرفت یادگیری شما نگهداری می‌شود. این اطلاعات برای نمایش داشبورد، مدیریت دسترسی و پشتیبانی استفاده می‌شود و بدون دلیل روشن در اختیار دیگران قرار نمی‌گیرد. جزئیات بیشتر در صفحه حریم خصوصی آمده است. اگر می‌خواهید درباره اطلاعات حساب شما توضیح بیشتری بگیرید، از پشتیبانی بپرسید.",
         ["اطلاعات حساب برای ارائه خدمات نگهداری می‌شود.",
          "جزئیات بیشتر در صفحه حریم خصوصی است."]),
        ("termination", "تعلیق و پایان دسترسی",
         "اگر این شرایط نقض شود، ممکن است دسترسی شما محدود یا متوقف شود. در موارد کم‌اهمیت، ابتدا تذکر داده می‌شود؛ اما در مواردی مانند تهدید امنیت دیگران یا سوءاستفاده آشکار، ممکن است دسترسی بدون اطلاع قبلی محدود شود. اگر با این تصمیم موافق نیستید، می‌توانید از پشتیبانی درخواست بررسی مجدد کنید.",
         ["نقض شرایط می‌تواند به محدودشدن دسترسی منجر شود.",
          "امکان درخواست بررسی مجدد وجود دارد."]),
        ("updates", "تغییر این شرایط و ارتباط با ما",
         "ممکن است برای روشن‌ترشدن شیوه استفاده از خدمات، متن این صفحه تغییر کند. نسخه منتشرشده در همین صفحه مبنای اطلاع‌رسانی تغییرات است و در تغییرات مهم، از طریق اعلان‌های سایت اطلاع می‌دهیم. اگر درباره این شرایط یا نحوه استفاده از امکانات پرسشی دارید، پیش از ادامه با پشتیبانی تماس بگیرید.",
         ["نسخه منتشرشده در همین صفحه معتبر است.",
          "تغییرات مهم از طریق اعلان‌ها اطلاع داده می‌شود."]),
    ]
    out = ('<main><header class="terms-hero"><div class="container terms-hero-inner">'
           '<span class="eyebrow">راهنمای استفاده از خدمات</span><h1>قوانین و مقررات</h1>'
           '<p>پیش از استفاده از امکانات Noventix، با حقوق و مسئولیت‌های خود در این فضا آشنا شوید. این راهنما به زبان ساده نوشته شده تا بتوانید بخش موردنیازتان را سریع پیدا کنید.</p>'
           f'<div class="terms-hero-facts"><span>{fa(len(sections))} بخش</span><span>زبان ساده</span><span>آخرین بازنگری: مهر ۱۴۰۵</span></div>'
           '</div></header><div class="container terms-layout"><nav class="terms-nav" aria-label="فهرست بخش‌های قوانین">'
           '<strong>در این صفحه</strong><ol>')
    for anchor, title, _body, _points in sections:
        out += f'<li><a href="#{h(anchor)}">{h(title)}</a></li>'
    out += ('</ol></nav><div class="terms-content">'
            '<div class="terms-intro"><strong>خلاصه‌ای برای شروع</strong>'
            '<p>از حساب و فایل‌های خود محافظت کنید، به حقوق دیگران احترام بگذارید و برای پیگیری هر مشکل از مسیر پشتیبانی استفاده کنید.</p>'
            '<ul class="terms-points"><li>اطلاعات محرمانه را در میزکار و پرسش‌های Nova وارد نکنید.</li>'
            '<li>محتوا و کد شما در میزکار روی همان دستگاه می‌ماند؛ پشتیبان بگیرید.</li>'
            '<li>پاسخ‌های Nova و خروجی کد را پیش از استفاده واقعی خودتان بسنجید.</li></ul></div>')
    for number, (anchor, title, body, points) in enumerate(sections, 1):
        out += (f'<section id="{h(anchor)}" class="terms-section" aria-labelledby="heading-{h(anchor)}">'
                f'<div class="terms-section-heading"><span aria-hidden="true">{fa(f"{number:02}")}</span>'
                f'<h2 id="heading-{h(anchor)}">{h(title)}</h2></div><p>{h(body)}</p>')
        if points:
            out += '<ul class="terms-points">' + "".join(f"<li>{h(point)}</li>" for point in points) + "</ul>"
        out += "</section>"
    return out + ('<div class="terms-contact"><div><h2>سؤالی درباره قوانین دارید؟</h2>'
                  '<p>برای روشن‌شدن شرایط استفاده یا گزارش مشکل، از پشتیبانی کمک بگیرید.</p></div>'
                  + linkto("support", "تماس با پشتیبانی ←", "btn btn-primary") + '</div></div></div></main>')


def view_privacy():
    return view_legal("حریم خصوصی", "نحوه نگهداری و استفاده از اطلاعات شما", [("اطلاعات حساب", "شماره موبایل و نام برای ایجاد حساب و ارائه خدمات استفاده می‌شود."), ("داده‌های آموزشی", "پیشرفت، ثبت‌نام‌ها و فعالیت‌های آموزشی برای نمایش داشبورد شما نگهداری می‌شوند."), ("امنیت", "اطلاعات حساس نباید در پیام‌ها یا کدهای آموزشی وارد شوند.")])


def view_login():
    return ('<main class="auth-page"><div class="auth-card">'
            f'<img src="{h(asset("nova.png"))}" alt="مسکات Nova" width="70" height="70">'
            "<h1>ورود یا ثبت‌نام</h1>"
            '<p class="muted">برای ثبت‌نام رمز بسازید؛ ورودهای بعدی با شماره، رمز و کپچا انجام می‌شود.</p>'
            '<form class="stack-form" data-form="login" data-register-form>'
            '<h2>ثبت‌نام</h2><label>نام<input name="first_name" required minlength="2" maxlength="50" autocomplete="given-name" placeholder="نام"></label>'
            '<label>نام خانوادگی<input name="last_name" required minlength="2" maxlength="50" autocomplete="family-name" placeholder="نام خانوادگی"></label>'
            '<label>شماره موبایل<input type="tel" dir="ltr" name="phone" required pattern="0?9[0-9]{9}" placeholder="09123456789" autocomplete="tel"></label>'
            '<label>رمز عبور<input type="password" name="password" required minlength="8" maxlength="72" autocomplete="new-password"></label>'
            '<p class="fine-print">رمز باید حداقل ۸ نویسه و شامل حرف انگلیسی و عدد باشد.</p>'
            '<button class="btn btn-primary">ارسال کد تأیید</button></form><p class="form-note" hidden></p><hr>'
            '<form class="stack-form" data-form="signin" data-signin-form><h2>ورود به پنل</h2>'
            '<label>شماره موبایل<input type="tel" dir="ltr" name="phone" required pattern="0?9[0-9]{9}" autocomplete="username"></label>'
            '<label>رمز عبور<input type="password" name="password" required autocomplete="current-password"></label>'
            '<div class="captcha-box"><strong data-login-captcha dir="ltr"></strong><span>کد امنیتی</span></div>'
            '<label>کپچا<input name="captcha" required maxlength="5" pattern="[A-Za-z0-9]{5}" dir="ltr" autocomplete="off"></label>'
            '<button class="btn btn-outline">ورود</button></form><p class="form-note" hidden></p>'
            '</div></main>')


def view_verify():
    return ('<main class="auth-page"><div class="auth-card"><h1>کد تأیید را وارد کنید</h1>'
            '<p class="muted" data-demo-phone></p>'
            '<form class="stack-form" data-form="verify">'
            '<label>کد ۶ رقمی<input inputmode="numeric" dir="ltr" name="code" required '
            'pattern="[0-9۰-۹]{6}" autocomplete="one-time-code"></label>'
            '<button class="btn btn-primary">تأیید و ورود</button></form>'
            '<p class="form-note" hidden></p>'
            + linkto("login", "ویرایش شماره ←", "inline-link") + "</div></main>")


def view_community():
    return ('<main>' + page_hero("انجمن", "کنار هم یاد می‌گیریم",
                                 "سؤال‌هایت را بپرس، تجربه‌ات را به اشتراک بگذار و مسیر یادگیری دیگران را بهتر کن.")
            + '<section class="section container"><div class="panel-card narrow-left">'
              '<form class="stack-form" data-form="post">'
              '<label>عنوان<input name="title" required minlength="5" maxlength="180"></label>'
              '<label>متن<textarea name="body" required minlength="10" maxlength="5000" rows="5"></textarea></label>'
              '<button class="btn btn-primary">ثبت در انجمن</button></form>'
              '<p class="form-note" hidden></p></div>'
              '<div class="community-list" data-community-list></div></section>'
            + '<section class="section container">' + live_chat() + "</section></main>")


def live_chat():
    return ('<div class="panel-card chat-card"><div class="code-bar"><h2>گفت‌وگوی زنده</h2>'
            '</div>'
            '<p class="muted">پیام‌ها در همین دستگاه نمایش داده می‌شوند؛ گفت‌وگو بین کاربران همگام‌سازی نمی‌شود.</p>'
            '<div class="chat-log" data-chat-log role="log" aria-live="polite">'
            '<div class="chat-empty" data-chat-empty>هنوز پیامی در گفت‌وگوی زنده نیست. اولین نفر باشید.</div></div>'
            '<form class="chat-form" data-form="chat">'
            '<label class="sr-only" for="chat-input">پیام</label>'
            '<input id="chat-input" name="message" autocomplete="off" maxlength="500" '
            'placeholder="پیام خود را بنویسید…" required minlength="1">'
            '<button class="btn btn-primary" type="submit">ارسال</button></form>'
            '<p class="form-note" hidden></p></div>')


def view_notfound():
    return ('<main class="container section"><span class="eyebrow">404</span>'
            "<h1>این صفحه پیدا نشد.</h1><p>ممکن است نشانی تغییر کرده باشد.</p>"
            + linkto("", "بازگشت به خانه ←", "btn btn-primary") + "</main>")


# --------------------------------------------------------------- panels

def challenge_grid():
    out = '<div class="challenge-grid">'
    for i, (kind, title, xp, topic) in enumerate(CHALLENGES):
        out += (f'<article class="challenge-card"><div class="meta-row"><span class="pill">{h(kind)}</span>'
                f'<span class="xp">XP +{fa(xp)}</span></div><h3>{h(title)}</h3><span class="muted">{h(topic)}</span>'
                f'<a class="btn btn-primary full" href="{h(href(f"panel/student/challenge/{i}"))}">شروع چالش</a></article>')
    return out + "</div>"


CHALLENGE_SOLUTIONS = [
    ("def discount(price, percent):\n    if price < 0:\n        raise ValueError(\"price must be positive\")\n    return price * (1 - percent / 100)\n\nprint(discount(200, 15))",
     "تابع باید ورودی نامعتبر را واضح رد کند و برای تخفیف صفر هم درست کار کند.",
     ["تخفیف صفر", "قیمت منفی", "درصد بیش از ۱۰۰"]),
    ("import numpy as np\n\ndef moving_average(values, window):\n    if window < 1 or window > len(values):\n        raise ValueError(\"window must fit inside the series\")\n    series = np.asarray(values, dtype=float)\n    return np.convolve(series, np.ones(window) / window, mode=\"valid\")\n\nprint(moving_average([1, 2, 3, 4, 5], 3))",
     "پنجره نامعتبر را رد کن و برای سری کوتاه‌تر از پنجره هم رفتار روشن داشته باش.",
     ["سری کوتاه", "پنجره بزرگ‌تر از طول", "مقادیر اعشاری"]),
    ("import torch\n\nX = torch.randn(200, 8)\ny = (X.sum(dim=1) > 0).float().unsqueeze(1)\n\nmodel = torch.nn.Sequential(\n    torch.nn.Linear(8, 16),\n    torch.nn.ReLU(),\n    torch.nn.Linear(16, 1),\n)\nloss_fn = torch.nn.BCEWithLogitsLoss()\noptimizer = torch.optim.Adam(model.parameters(), lr=0.01)\n\nfor step in range(200):\n    optimizer.zero_grad()\n    loss = loss_fn(model(X), y)\n    loss.backward()\n    optimizer.step()\n\nprint(round(float(loss), 4))",
     "معماری را با اندازه داده هماهنگ کن و زیان را در چند گام ثبت کن، نه فقط در پایان.",
     ["آموزش چند گام", "زیان کاهشی", "داده نامتوازن"]),
    ("import pandas as pd\n\ndf = pd.read_csv(\"sales.csv\")\ndf = df.dropna(subset=[\"amount\"])\ndf[\"amount\"] = pd.to_numeric(df[\"amount\"], errors=\"coerce\")\ndf = df.drop_duplicates()\nprint(df[\"amount\"].sum())",
     "پیش از هر محاسبه نوع ستون‌ها را مشخص کن و اثر حذف ردیف‌ها را گزارش بده.",
     ["مقدار گمشده", "مقدار متنی در ستون عددی", "ردیف تکراری"]),
    ("from sklearn.model_selection import train_test_split\nfrom sklearn.linear_model import LogisticRegression\nfrom sklearn.metrics import recall_score\n\nX_train, X_test, y_train, y_test = train_test_split(X, y, test_size=0.2, stratify=y, random_state=42)\nmodel = LogisticRegression(max_iter=1000, class_weight=\"balanced\")\nmodel.fit(X_train, y_train)\nprint(recall_score(y_test, model.predict(X_test)))",
     "داده نامتوازن را با stratify جدا کن و معیار را متناسب با هزینه خطا انتخاب کن.",
     ["کلاس نامتوازن", "داده آزمون دست‌نخورده", "معیار recall"]),
]


def code_workspace(starter="", challenge=None):
    form_open = (f'<form class="stack-form" data-form="solution"><input type="hidden" name="challenge" value="{challenge}">' if challenge is not None else '')
    form_close = ('<button class="btn btn-primary" type="submit">ذخیره پاسخ من</button></form><p class="form-note" hidden></p>' if challenge is not None else '')
    return (f'<div class="code-layout"><section class="panel-card code-col">{form_open}'
            '<div class="code-bar"><span class="muted">میزکار چندفایلی Python · مفسر مشترک با ترمینال · سقف اجرا ۱۵ ثانیه</span></div>'
            '<div class="workspace-files"><div class="workspace-files-head"><strong>فایل‌های پروژه</strong><label class="btn btn-small btn-outline file-upload-label">بارگذاری فایل<input type="file" data-file-upload multiple hidden></label></div><div class="file-tree" data-file-tree role="listbox" aria-label="فایل‌های میزکار"></div>'
            '<div class="file-actions"><input class="file-name-input" data-file-name-input dir="ltr" placeholder="src/analysis.py" aria-label="نام فایل جدید">'
            '<button type="button" class="btn btn-small btn-outline" data-file-create>فایل جدید</button></div></div>'
            '<div class="editor-tabs"><span class="editor-tab is-active" data-file-name>main.py</span></div>'
            '<label class="sr-only" for="code-input">کد فایل</label>'
            '<textarea id="code-input" name="code" class="code-input" dir="ltr" spellcheck="false" rows="14" '
            f'data-code-input placeholder="print(2 + 3)">{h(starter)}</textarea>'
            '<div class="code-actions"><button type="button" class="btn btn-primary" data-code-run>اجرای فایل</button>'
            '<button type="button" class="btn btn-outline" data-code-stop disabled>توقف اجرا</button>'
            '<button type="button" class="btn btn-outline" data-code-reset>پاک‌کردن فایل</button>'
            '<span class="muted" data-code-status role="status">آماده اجرا</span></div>'
            f'{form_close}</section>'
            '<section class="panel-card code-col"><h2>خروجی</h2>'
            '<pre class="code-output" dir="ltr" data-code-output aria-live="polite">هنوز کدی اجرا نشده است.</pre>'
            '<div class="code-plots" data-code-plots aria-label="نمودارهای خروجی"></div>'
            '<p class="muted">کد شما با مفسر واقعی Python (Pyodide) در مرورگر اجرا می‌شود و فایل‌های پروژه بین اجراها و بین ادیتور و ترمینال مشترک‌اند. بسته‌های سازگار با Pyodide هنگام اجرا بارگیری می‌شوند؛ دسترسی به فایل واقعی دستگاه، شبکه و پوسته سیستم‌عامل وجود ندارد.</p>'
            '<h2>راهنمای تمرین</h2><ul class="check-list"><li>فایل‌های داده را با نام نسبی مثل <code>data.csv</code> بخوان.</li>'
            '<li>برای بسته‌ها از <code>pip install numpy</code> در ترمینال استفاده کن.</li>'
            '<li>حالت‌های مرزی و خطاها را در فایل جداگانه آزمون کن.</li></ul></section></div>')


def challenge_detail(index):
    kind, title, xp, topic = CHALLENGES[index]
    starter, hint, tests = CHALLENGE_SOLUTIONS[index % len(CHALLENGE_SOLUTIONS)]
    out = panel_head(title, f"{kind} · {topic}")
    out += (f'<div class="panel-card"><div class="meta-row"><span class="pill">{h(kind)}</span>'
           f'<span class="xp">XP +{fa(xp)}</span><span class="muted">{h(topic)}</span></div>'
           f"<h2>صورت مسئله</h2><p class=\"muted\">{h(hint)}</p>"
           f'<button type="button" class="btn btn-primary" data-challenge="{index}">افزودن به چالش‌های من</button>'
           f'<p class="form-note" data-challenge-note="{index}" hidden></p></div>')
    out += ('<div class="panel-card narrow-left"><h2>آزمون‌های پذیرش</h2><ul class="check-list">')
    for case in tests:
        out += f"<li>{h(case)}</li>"
    out += '</ul><p class="muted">این‌ها معیارهای پیشنهادی ارزیابی‌اند؛ ذخیره پاسخ به معنای قبولی در آزمون خودکار نیست.</p></div>'
    out += '<div class="panel-card"><h2>نمونه راه‌حل</h2>' + code_block(starter) + "</div>"
    out += code_workspace(starter, index)
    out += ('<div class="article-footer">' + linkto("panel/student/challenges", "بازگشت به چالش‌ها ←", "btn btn-outline")
            + linkto("panel/student/workspace", "تمرین آزاد در میزکار ←", "btn btn-outline") + "</div>")
    return out


def code_block(source):
    return f'<pre class="code-block" dir="ltr"><code>{h(source)}</code></pre>'


def workspace_view():
    out = panel_head("میزکار کد", "محیط تمرین آزاد برای نوشتن و آزمودن ایده‌ها.")
    out += stat_grid([("اجراهای من", "۰", "runs"), ("چالش حل‌شده", "۰", "solved"),
                      ("قطعه کد ذخیره‌شده", "۰", "snippets"), ("زبان پیشنهادی", "Python", None)])
    out += code_workspace()
    out += terminal_view()
    out += nova_review_card()
    out += ('<div class="panel-columns"><section class="panel-card"><h2>قطعه‌های ذخیره‌شده</h2>'
            '<div class="panel-list" data-snippets>'
            '<div class="empty-state">هنوز قطعه‌کدی ذخیره نکرده‌ای.</div></div></section>'
            '<section class="panel-card"><h2>تمرین‌های پیشنهادی</h2><ul class="check-list">'
            '<li>یک تابع بنویس که فهرست خالی را مدیریت کند.</li>'
            '<li>همان راه‌حل را با نوع داده متفاوت آزمون کن.</li>'
            '<li>خطا را عمداً ایجاد کن و پیام آن را مستند کن.</li></ul>'
            + linkto("panel/student/challenges", "شروع چالش هفتگی ←", "btn btn-outline") + "</section></div>")
    return out


def terminal_view():
    return ('<section class="panel-card code-col"><div class="code-bar"><span class="pill">Terminal</span>'
            '<span class="muted">همان مفسر Python میزکار · ls · cat · pip · python</span></div>'
            '<div class="terminal" data-terminal-log dir="ltr" role="log" aria-live="polite">'
            '<span class="term-line term-hint">$ help</span></div>'
            '<form class="terminal-form" data-form="terminal">'
            '<label class="sr-only" for="term-input">فرمان ترمینال</label>'
            '<span class="term-prompt" aria-hidden="true">$</span>'
            '<input id="term-input" class="term-input" dir="ltr" name="command" autocomplete="off" '
            'spellcheck="false" placeholder="python main.py یا pip install numpy">'
            '<button class="btn btn-small btn-primary" type="button" data-terminal-run>اجرا</button></form>'
            '<div class="code-actions"><button type="button" class="btn btn-small btn-outline" '
            'data-terminal-stop disabled>توقف فرمان</button>'
            '<span class="muted" data-terminal-status role="status">آماده</span></div>'
            '<div class="package-status" data-package-status>بسته‌های پایه: numpy، pandas، matplotlib، scipy</div>'
            '<p class="muted">فرمان‌ها روی همان مفسر Python میزکار اجرا می‌شوند؛ فایل‌ها و بسته‌ها در حافظهٔ مرورگر '
            'شما می‌مانند و این ترمینال به سیستم‌عامل، شبکه یا فایل‌های واقعی دستگاه دسترسی ندارد.</p></section>')


def nova_review_card():
    return ('<section class="panel-card"><div class="code-bar"><h2>بررسی کد با Nova</h2>'
            '<span class="pill">Nova Review</span></div>'
            '<p class="muted">کد خود را برای بازبینی بفرستید؛ Nova نکته‌های ساختاری، خوانایی و آزمون‌های '
            'پیشنهادی را فهرست می‌کند.</p>'
            '<form class="stack-form" data-form="review">'
            '<label>کد برای بررسی<textarea class="code-input" dir="ltr" name="code" rows="10" '
            'spellcheck="false" required minlength="20" placeholder="def solve(data):&#10;    return data"></textarea></label>'
            '<button class="btn btn-primary">ارسال برای بررسی</button></form>'
            '<p class="form-note" hidden></p><div class="review-output" data-review-output hidden></div></section>')



def community_tools():
    return ('<div class="panel-card narrow-left"><h2>Create Post</h2>'
            '<form class="stack-form" data-form="post">'
            '<label>عنوان<input name="title" required minlength="5" maxlength="180"></label>'
            '<label>متن<textarea name="body" required minlength="10" maxlength="5000" rows="4"></textarea></label>'
            '<button class="btn btn-primary">Create Post</button></form>'
            '<p class="form-note" hidden></p></div>'
            '<div class="panel-columns"><div class="panel-list" data-panel-posts></div>'
            + live_chat() + "</div>")


def ticket_tools():
    out = ('<div class="panel-card narrow-left"><span class="pill">Support Queue</span>'
           '<h2><span data-open-tickets>۰</span> تیکت باز</h2>'
           '<p class="muted">تیکت‌های اولویت‌دار را بررسی یا تیکت جدیدی ایجاد کن.</p>'
           '<button type="button" class="btn btn-primary" data-toggle="#ticket-form">Create Ticket</button></div>'
           '<div id="ticket-form" class="panel-card narrow" hidden><h2>تیکت جدید</h2>'
           '<form class="stack-form" data-form="ticket">'
           '<label>موضوع<input name="subject" required minlength="5" maxlength="180"></label>'
           '<label>اولویت<select name="priority">')
    for pr in TICKET_PRIORITIES:
        out += f'<option value="{h(pr)}">{h(pr)}</option>'
    return (out + '</select></label><label>شرح مشکل<textarea name="body" required minlength="10" '
                 'maxlength="5000" rows="5"></textarea></label>'
                 '<button class="btn btn-primary">ثبت تیکت</button></form>'
                 '<p class="form-note" hidden></p></div><div class="panel-list" data-tickets></div>')


def gamification_view():
    out = panel_head("Gamification", "سطح، امتیاز و نشان‌های یادگیری.")
    out += stat_grid([("Level", "۱۲", None), ("XP", "۲٬۴۸۰", "xp"),
                      ("Streak", "۷ روز", "streak"), ("Missions", f"{fa(len(CHALLENGES))} فعال", None)])
    out += '<div class="panel-columns"><section class="panel-card"><h2>Skill Tree</h2>'
    for name, percent in TRACKS:
        out += (f'<div class="skill-row"><div class="usage-row"><span>{h(name)}</span>'
                f"<strong>{fa(percent)}٪</strong></div>" + progress_bar(percent) + "</div>")
    out += '</section><section class="panel-card"><h2>Achievements &amp; Badges</h2><div class="badge-grid">'
    for title, icon, need in BADGES:
        out += f'<div class="badge" title="{h(need)}"><span>{h(icon)}</span>{h(title)}</div>'
    return out + "</div></section></div>"


def activity_view():
    out = panel_head("فعالیت و Streak", "هر روزی که کد زدی، در این تقویم ثبت می‌شود.")
    out += stat_grid([("Streak فعلی", "۰ روز", "streak"), ("بلندترین Streak", "۰ روز", "streak_best"),
                      ("روزهای فعال این ماه", "۰", "active_days"), ("XP این هفته", "۰", "week_now")])
    out += ('<div class="panel-card"><h2>فعالیت هفته</h2>'
            '<p class="muted">اندازه‌های هر ستون نشان می‌دهد آن روز چقدر درگیر کد و تمرین بودی.</p>'
            '<div class="activity-strip" data-activity-strip>')
    for label, value in zip(ACTIVITY_LABELS, ACTIVITY_WEEKS[-7:]):
        out += (f'<div class="activity-day"><span class="activity-bar" style="height:{value * 10}%" '
                f'title="{h(label)} · {h(fa(value))} فعالیت"></span><small>{h(label)}</small></div>')
    out += ('</div><p class="muted">ستون‌ها بر اساس تعداد اجرای کد، چالش حل‌شده و بازبینی محاسبه می‌شوند؛ '
            'یک روز فقط وقتی فعال شمرده می‌شود که حداقل یک کار واقعی ثبت شده باشد.</p></div>')
    out += '<div class="panel-columns"><section class="panel-card"><h2>شکست فعالیت</h2><div class="panel-list">'
    for key, title, hint in ACTIVITY_KINDS:
        out += (f'<div class="usage-row"><span>{h(title)}<small class="muted"> · {h(hint)}</small></span>'
                f'<strong data-stat="{h(key)}">۰</strong></div>')
    out += ('</div></section><section class="panel-card"><h2>Streak چطور محاسبه می‌شود؟</h2>'
            '<ul class="check-list">'
            '<li>هر روزی که دست‌کم یک کد اجرا کنی یا یک چالش حل کنی، برای آن روز ثبت می‌شود.</li>'
            '<li>اگر یک روز کامل بی‌فعالیت بماند، شمارنده به صفر برمی‌گردد؛ روزهای فعال قبلی حفظ می‌شوند.</li>'
            '<li>ستون‌های کم‌ارتفاع هم روز فعال حساب می‌شوند؛ فقط حجم کار را نشان می‌دهند.</li>'
            '</ul></section></div>')
    out += ('<div class="panel-card"><h2>هفته‌های اخیر</h2>'
            '<p class="muted">دوازده هفته گذشته، از قدیم به جدید.</p>'
            + bars(ACTIVITY_WEEKS, True) + '</div>')
    return out


def profile_view():
    out = panel_head("پروفایل من", "اطلاعات نمایش داده‌شده در حساب کاربری.")
    out += ('<div class="profile-head"><div>'
            f'<img src="{h(asset("nova.png"))}" alt="" width="56" height="56" aria-hidden="true">'
            "<div><h2 data-profile-name>کاربر Noventix</h2>"
            '<p class="muted" data-profile-phone>—</p>'
            "<p class=\"muted\">سازنده‌ای در مسیر یادگیری هوش مصنوعی</p></div></div>"
            + linkto("panel/student/settings", "ویرایش پروفایل", "btn btn-outline") + "</div>")
    out += ('<div class="panel-card narrow"><form class="stack-form" data-form="profile">'
            '<label>نام<input name="first_name" maxlength="50" minlength="2" required autocomplete="given-name"></label>'
            '<label>نام خانوادگی<input name="last_name" maxlength="50" minlength="2" required autocomplete="family-name"></label>'
            '<label>ایمیل (اختیاری)<input type="email" dir="ltr" name="email" maxlength="255" placeholder="you@example.com"></label>'
            '<label>شماره موبایل<input dir="ltr" disabled data-profile-phone-input value="—"></label>'
            '<button class="btn btn-primary">ذخیره تغییرات</button></form>'
            '<p class="form-note" hidden></p>'
            "<p class=\"fine-print\">شماره موبایل شناسه ورود شماست و از این صفحه قابل تغییر نیست.</p></div>")
    return out


def nova_view():
    out = panel_head("Nova AI", "مربی همراه شما در مسیر یادگیری.")
    out += stat_grid([("گفت‌وگوهای امروز", "۰", "nova_today"), ("اعتبار پلن", "۰", "plan_credits"),
                      ("پرسش بی‌پاسخ", "۰", "nova_open"), ("وضعیت Nova", "نیازمند سرور", None)])
    out += ('<div class="panel-card narrow-left"><h2>Nova AI</h2>'
            '<p class="muted">این نسخهٔ ایستا امکان اتصال امن به سرویس هوش مصنوعی را ندارد. برای دریافت پاسخ واقعی Nova، نسخهٔ سروری Noventix با کلید تنظیم‌شده روی سرور لازم است؛ در این صفحه پاسخی تولید یا ارسال نمی‌شود.</p></div>')
    out += '<div class="panel-list" data-nova-thread></div>'
    return out


def notifications_view():
    out = panel_head("اعلان‌ها", "رخدادهای مسیر یادگیری و پیام‌های سامانه.")
    out += '<div class="panel-card"><div class="panel-list" data-announcements>'
    for title, body, ago in ANNOUNCEMENTS:
        out += (f'<div class="announce-card"><div><h2>{h(title)}</h2><p>{h(body)}</p></div>'
                f"<small>{h(ago)}</small></div>")
    return out + "</div></div>"


def student_payments():
    out = panel_head("پرداخت‌ها", "تاریخچه پرداخت و وضعیت اشتراک شما.")
    out += ('<div class="panel-card narrow-left"><h2>وضعیت پرداخت</h2>'
            '<p class="muted">سوابق فعال‌سازی پلن در این حساب.</p></div>'
            '<div class="panel-card table-wrap"><table><thead><tr><th>پلن</th><th>مبلغ ماهانه</th>'
            "<th>وضعیت</th><th>پایان</th></tr></thead><tbody data-student-payments>"
            '<tr><td colspan="4" class="muted">هنوز پرداختی ثبت نشده است.</td></tr></tbody></table></div>')
    return out


def settings_view():
    rows = [("اعلان‌های ایمیلی", "خبر پروژه‌ها و پاسخ‌های انجمن", "فعال"),
            ("اعلان‌های پیامکی", "یادآور مسیر یادگیری", "غیرفعال"),
            ("نمایش پروفایل در انجمن", "نمایش نام در گفت‌وگوها", "فعال")]
    out = panel_head("تنظیمات", "تنظیمات حساب و اعلان‌های شما.") + '<div class="settings-grid">'
    for title, desc, badge in rows:
        out += (f'<article class="setting-card"><div><h3>{h(title)}</h3>'
                f'<span class="muted">{h(desc)}</span></div><span class="pill">{h(badge)}</span></article>')
    out += ('<article class="setting-card"><div><h3>ویرایش پروفایل</h3>'
            '<span class="muted">نام و ایمیل حساب</span></div>'
            + linkto("panel/student/profile", "ویرایش", "btn btn-small btn-outline") + "</article></div>")
    return out


def student_subscription():
    out = panel_head("اشتراک من", "وضعیت پلن و ظرفیت امکانات شما.")
    out += ('<div class="sub-layout"><section class="panel-card"><h2>مصرف حساب</h2>'
            '<div class="usage-row"><span>اعتبار Nova</span><strong data-plan-credits>۰</strong></div>'
            '<div class="usage-row"><span>ظرفیت پروژه</span><strong data-plan-projects>۰</strong></div></section>'
            '<section class="plan-tile"><span class="eyebrow">پلن فعلی</span>'
            '<h2 data-current-plan>رایگان</h2><p data-plan-expiry>اشتراک پولی فعالی ثبت نشده است.</p></section></div>')
    out += ('<div class="panel-card"><h2>فعال‌سازی آزمایشی اشتراک</h2>'
            '<p class="muted">برای آزمون دسترسی‌ها، یک پلن را بدون پرداخت فعال کنید. این قابلیت فقط برای محیط آزمایشی است.</p>'
            '<form class="subscription-form" data-form="subscription"><label>پلن<select name="plan">'
            + ''.join(f'<option value="{h(p["id"])}">{h(p["name"])} · {money(p["price"])}</option>' for p in PLANS if p["id"] != "free")
            + '</select></label><button class="btn btn-primary">فعال‌سازی پلن</button></form><p class="form-note" hidden></p></div>')
    out += ('<div class="panel-card"><h2>سوابق اشتراک</h2>'
            '<div class="table-wrap"><table><thead><tr><th>پلن</th><th>مبلغ</th><th>وضعیت</th>'
            '<th>تاریخ</th></tr></thead><tbody data-subscription-payments>'
            '<tr><td colspan="4" class="muted">هنوز پرداختی ثبت نشده است.</td></tr></tbody></table></div></div>')
    return out


def student_section(sub):
    if sub == "":
        out = ('<div class="panel-welcome"><div><span class="eyebrow">فضای یادگیری شما</span>'
               "<h1>سلام، خوش آمدی 👋</h1><p>امروز هم یک قدم برای ساختن مهارت تازه بردار.</p></div>"
               '<span class="pill">پلن رایگان</span></div>')
        out += stat_grid([("مقاله‌های من", "۰", "enrollments"), ("پروژه‌های من", "۰", "projects"),
                          ("گفت‌وگوها", "۰", "posts"), ("پلن فعال", PLANS[0]["name"], None)])
        out += ('<div class="panel-columns"><section class="panel-card">'
                '<span class="eyebrow">گام بعدی</span><h2>مسیرت را ادامه بده</h2>'
                "<p>یک مقاله را بخوان و سپس دانسته‌هایت را در یک پروژه تازه به کار بگیر.</p>"
                + linkto("courses", "مشاهده مقاله‌ها ←", "btn btn-primary")
                + '</section><section class="panel-card soft">'
                  '<span class="eyebrow">کنار هم یاد می‌گیریم</span><h2>انجمن Noventix</h2>'
                  "<p>سؤال‌ها، تجربه‌ها و ایده‌ها را با جامعه یادگیری در میان بگذار.</p>"
                + linkto("community", "ورود به انجمن ←", "btn btn-outline") + "</section></div>")
        out += ('<div class="panel-columns"><section class="panel-card"><h2>ادامه مسیر یادگیری</h2>'
                '<ul class="check-list">'
                '<li>دو مقاله رایگان را کامل بخوان و هر کدام را در مسیر یادگیری ذخیره کن.</li>'
                '<li>یک پروژه رایگان را انتخاب کن و نسخه اولیه راه‌حل را بنویس.</li>'
                '<li>سؤال‌های باقی‌مانده را در انجمن مطرح کن.</li></ul></section>'
                '<section class="panel-card"><h2>دسترسی پلن فعلی</h2><ul class="check-list">'
                '<li>اعتبار Nova: <strong data-plan-credits>۰</strong></li>'
                '<li>ظرفیت پروژه: <strong data-plan-projects>۰</strong></li></ul>'
                '<p class="muted">برای دسترسی به پروژه‌ها و مقاله‌های پیشرفته، پلن مناسب را فعال کنید.</p>'
                + linkto("panel/student/subscription", "مدیریت اشتراک ←", "btn btn-outline") + "</section></div>")
        out += ('<div class="panel-columns"><section class="panel-card"><h2>پیشرفت مسیرها</h2>')
        for name, percent in TRACKS:
            out += (f'<div class="skill-row"><div class="usage-row"><span>{h(name)}</span>'
                    f"<strong>{fa(percent)}٪</strong></div>" + progress_bar(percent) + "</div>")
        out += ('</section><section class="panel-card"><h2>فعالیت هفته گذشته</h2>'
                '<p class="muted">تعداد تمرین‌ها در هفت روز گذشته</p>')
        out += '<div class="week-grid">'
        for day, count in (("شنبه", 3), ("یک‌شنبه", 5), ("دوشنبه", 2), ("سه‌شنبه", 6),
                           ("چهارشنبه", 4), ("پنج‌شنبه", 7), ("جمعه", 1)):
            out += (f'<div class="week-cell"><strong>{fa(count)}</strong>'
                    f'<span class="week-bar" style="height:{round(count / 7 * 100)}%"></span>'
                    f"<small>{h(day)}</small></div>")
        out += "</div></section></div>"
        out += ('<div class="panel-columns"><section class="panel-card"><h2>میان‌بُرهای امروز</h2>'
                '<div class="quick-links">'
                + linkto("panel/student/workspace", "میزکار کد", "btn btn-outline")
                + linkto("panel/student/challenges", "چالش روزانه", "btn btn-outline")
                + linkto("panel/student/nova", "پرسش از Nova", "btn btn-outline")
                + linkto("panel/student/notifications", "اعلان‌ها", "btn btn-outline") + "</div></section>"
                '<section class="panel-card soft"><h2>نشان بعدی</h2>'
                '<div class="badge-grid">'
                + "".join(f'<div class="badge" title="{h(need)}"><span>{h(icon)}</span>{h(title)}</div>'
                          for title, icon, need in BADGES[:3])
                + "</div>" + linkto("panel/student/gamification", "همه نشان‌ها ←", "btn btn-outline")
                + "</section></div>")
        out += ('<div class="panel-columns"><section class="panel-card"><h2>سرعت یادگیری</h2>'
                '<p class="muted">مقایسه این هفته با هفته گذشته</p><ul class="check-list">'
                '<li>تمرین‌های این هفته: <strong data-stat="week_now">۰</strong></li>'
                '<li>تمرین‌های هفته گذشته: <strong>۱۸</strong></li>'
                '<li>هدف هفتگی: <strong>۲۵ تمرین</strong></li></ul>'
                + progress_bar(48) + '</section>'
                '<section class="panel-card"><h2>کتابخانه من</h2><ul class="check-list">'
                '<li>مقاله‌های ذخیره‌شده: <strong data-stat="enrollments">۰</strong></li>'
                '<li>پروژه‌های فعال: <strong data-stat="projects">۰</strong></li>'
                '<li>قطعه‌کد ذخیره‌شده: <strong data-stat="snippets">۰</strong></li>'
                '<li>گفت‌وگوهای زنده: <strong data-stat="chat_messages">۰</strong></li></ul>'
                + linkto("panel/student/workspace", "رفتن به میزکار ←", "btn btn-outline") + "</section></div>")
        out += ('<div class="panel-card"><h2>رخدادهای تازه</h2><div class="panel-list" data-announcements>')
        for title, body, ago in ANNOUNCEMENTS:
            out += (f'<div class="announce-card"><div><h2>{h(title)}</h2><p>{h(body)}</p></div>'
                    f"<small>{h(ago)}</small></div>")
        out += ("</div>" + linkto("panel/student/notifications", "همه اعلان‌ها ←", "btn btn-outline") + "</div>")
        return out
    if sub == "learning":
        return (panel_head("مسیر یادگیری من", "مقاله‌های ذخیره‌شده برای ادامه مطالعه.")
                + '<div class="panel-list" data-enrollments>'
                  '<div class="empty-state">هنوز مقاله‌ای اضافه نکردی. '
                  f'<a href="{h(href("courses"))}">مقاله‌ها را ببین ←</a></div></div>')
    if sub == "projects":
        return (panel_head("پروژه‌های من", "مسئله‌هایی که برای ساختن انتخاب کرده‌ای.")
                + '<div class="panel-list" data-my-projects>'
                  '<div class="empty-state">هنوز پروژه‌ای شروع نکردی. '
                  f'<a href="{h(href("projects"))}">پروژه‌ها را ببین ←</a></div></div>')
    if sub == "challenges":
        return panel_head("چالش‌ها", "مسئله‌های کوتاه برای تمرین روزانه و هفتگی.") + challenge_grid()
    if sub == "my-challenges":
        return (panel_head("چالش‌های من", "چالش‌هایی که برای تمرین انتخاب کرده‌ای.")
                + '<div class="panel-list" data-my-challenges>'
                  '<div class="empty-state">هنوز چالشی اضافه نکردی. '
                  f'<a href="{h(href("panel/student/challenges"))}">چالش‌ها را ببین ←</a></div></div>')
    if sub.startswith("challenge/"):
        index = int(sub.split("/")[1])
        return challenge_detail(index)
    if sub == "workspace":
        return workspace_view()
    if sub == "nova":
        return nova_view()
    if sub == "notifications":
        return notifications_view()
    if sub == "community":
        return panel_head("Community", "از سؤال کوچک تا نمایش بزرگ‌ترین پروژه‌ها.") + community_tools()
    if sub == "subscription":
        return student_subscription()
    if sub == "payments":
        return student_payments()
    if sub == "support":
        return panel_head("پشتیبانی", "تیکت‌های باز و اولویت‌بندی آن‌ها.") + ticket_tools()
    if sub == "gamification":
        return gamification_view()
    if sub == "activity":
        return activity_view()
    if sub == "profile":
        return profile_view()
    if sub == "settings":
        return settings_view()
    return '<div class="empty-state">این بخش در دسترس نیست.</div>'


def admin_section(sub):
    if sub == "":
        out = ('<div class="panel-welcome"><div><span class="eyebrow">مدیریت Noventix</span>'
               "<h1>سلام، خوش آمدی 👋</h1>"
               "<p>وضعیت پلتفرم را در یک نگاه ببین و کاربران را مدیریت کن.</p></div>"
               '<span class="pill">پلن رایگان</span></div>')
        out += stat_grid([("کاربران", "۰", "users"), ("اشتراک فعال", "۰", "subs"),
                          ("گفت‌وگوها", "۰", "posts"), ("پیام‌ها", "۰", "messages")])
        out += ('<div class="panel-columns"><section class="panel-card">'
                '<span class="eyebrow">گام بعدی</span><h2>مدیریت اشتراک‌ها</h2>'
                "<p>فعال‌سازی اشتراک پس از تأیید پرداخت خارج از سامانه و بررسی دستی امکان‌پذیر است.</p>"
                + linkto("panel/admin/subscriptions", "مدیریت اشتراک‌ها ←", "btn btn-primary")
                + '</section><section class="panel-card soft"><span class="eyebrow">جامعه</span>'
                  "<h2>انجمن Noventix</h2>"
                  "<p>سؤال‌ها، تجربه‌ها و ایده‌ها را با جامعه یادگیری در میان بگذار.</p>"
                + linkto("community", "ورود به انجمن ←", "btn btn-outline") + "</section></div>")
        out += ('<div class="chart-grid"><section class="panel-card"><h2>کاربران جدید</h2>'
                '<p class="muted">هشت هفته گذشته</p>' + bars([3, 5, 4, 8, 6, 9, 7, 11], True)
                + '</section><section class="panel-card"><h2>درآمد ماهانه</h2>'
                  '<p class="muted">به تفکیک پلن، به تومان</p>'
                + bars([190000, 990000, 1290000, 2900000]) + "</section></div>")
        out += ('<div class="panel-columns"><section class="panel-card"><h2>توزیع پلن‌ها</h2>'
                '<div class="table-wrap"><table><thead><tr><th>پلن</th><th>اشتراک فعال</th></tr></thead><tbody>')
        for p in PLANS:
            out += f'<tr><td>{h(p["name"])}</td><td data-plan-count="{h(p["name"])}">۰</td></tr>'
        out += ('</tbody></table></div></section><section class="panel-card"><h2>کارهای پیشنهادی</h2>'
                '<ul class="check-list">'
                '<li>کاربران جدید را بررسی و در صورت نیاز تأیید کن.</li>'
                '<li>تیکت‌های اولویت‌دار را پاسخ بده.</li>'
                '<li>محتوای پیش‌نویس را برای انتشار بازبینی کن.</li></ul><div class="quick-links">'
                + linkto("panel/admin/users-new", "کاربران جدید", "btn btn-outline")
                + linkto("panel/admin/support", "پشتیبانی", "btn btn-outline")
                + linkto("panel/admin/content", "Content", "btn btn-outline")
                + "</div></section></div>")
        return out
    if sub == "users":
        return (panel_head("کاربران", "فهرست حساب‌های ثبت‌شده.")
                + '<div class="panel-card table-wrap"><table><thead><tr><th>نام</th><th>شماره</th>'
                  "<th>نقش</th><th>تاریخ عضویت</th></tr></thead><tbody data-users-table>"
                  '<tr><td colspan="4" class="muted">هنوز کاربری ثبت نشده است.</td></tr></tbody></table></div>')
    if sub == "users-new":
        return (panel_head("کاربران جدید", "حساب‌هایی که تازه ثبت‌نام کرده‌اند.")
                + '<div class="panel-card table-wrap"><table><thead><tr><th>نام</th><th>شماره</th>'
                  "<th>تاریخ عضویت</th></tr></thead><tbody data-users-new>"
                  '<tr><td colspan="3" class="muted">هنوز کاربری ثبت نشده است.</td></tr></tbody></table></div>')
    if sub == "courses":
        return (panel_head("دوره‌ها", "مدیریت کاتالوگ آموزش.")
                + '<div class="panel-card"><h2>مقاله‌های من</h2><div class="content-list" data-content-list></div></div>'
                + f'<div class="panel-card"><p class="muted">{fa(len(COURSES))} مقاله فعال در کاتالوگ.</p>'
                + linkto("courses", "مشاهده کاتالوگ ←", "btn btn-outline") + "</div>" + course_cards())
    if sub == "course-new":
        return (panel_head("مقاله جدید", "ایجاد مقاله آموزشی در کاتالوگ.")
                + '<div class="panel-card narrow-left"><form class="stack-form" data-form="content">'
                  '<label>عنوان مقاله<input name="title" required minlength="5" maxlength="180"></label>'
                  '<label>موضوع<input name="topic" required minlength="3" maxlength="60" placeholder="Python"></label>'
                  '<label>سطح<select name="kind"><option value="مقدماتی">مقدماتی</option>'
                  '<option value="متوسط">متوسط</option><option value="پیشرفته">پیشرفته</option></select></label>'
                  '<button class="btn btn-primary">ذخیره پیش‌نویس</button></form>'
                  '<p class="form-note" hidden></p></div>'
                + '<div class="panel-card"><p class="muted">مقاله‌های ثبت‌شده:</p>'
                  '<div class="content-list" data-content-list></div></div>')
    if sub == "catalog":
        return (panel_head("پروژه‌ها", "کاتالوگ پروژه‌های فعال پلتفرم.")
                + f'<div class="panel-card"><p class="muted">{fa(len(PROJECTS))} پروژه فعال در کاتالوگ.</p>'
                + linkto("projects", "مشاهده کاتالوگ ←", "btn btn-outline") + "</div>" + project_cards())
    if sub == "challenges":
        return panel_head("چالش‌ها", "مسئله‌های کوتاه برای تمرین روزانه و هفتگی.") + challenge_grid()
    if sub == "subscriptions":
        out = panel_head("مدیریت اشتراک‌ها", "وضعیت پلن‌ها و مصرف ظرفیت.")
        out += ('<div class="sub-layout"><section class="panel-card"><h2>Usage</h2>'
                f'<div class="usage-row"><span>AI Credits</span><strong>۰ از {fa(PLANS[-1]["nova"])}∞</strong></div>'
                + progress_bar(2)
                + '<div class="usage-row"><span>Projects</span><strong>نامحدود</strong></div></section>'
                '<section class="plan-tile"><span class="eyebrow">Current Plan</span>'
                f'<h2>{h(PLANS[0]["name"])}</h2><p>اشتراک فعالی ثبت نشده است.</p>'
                + linkto("pricing", "ارتقای پلن", "btn btn-gold") + "</section></div>")
        out += ('<div class="panel-card narrow"><h2>فعال‌سازی اشتراک ۳۰ روزه</h2>'
                '<form class="stack-form" data-form="activate">'
                '<label>شماره موبایل کاربر<input name="phone" type="tel" dir="ltr" placeholder="09123456789" required></label>'
                "<label>پلن<select name=\"plan\">")
        for p in PLANS:
            if p["id"] != "free":
                out += f'<option value="{h(p["id"])}">{h(p["name"])}</option>'
        out += ('</select></label><button class="btn btn-primary">فعال‌سازی پس از تأیید پرداخت</button>'
                '</form><p class="form-note" hidden></p></div>'
                '<div class="panel-card"><h2>اشتراک‌های اخیر</h2><div class="table-wrap"><table><thead><tr>'
                "<th>شماره</th><th>پلن</th><th>وضعیت</th><th>پایان</th></tr></thead><tbody data-subs-table>"
                '<tr><td colspan="4" class="muted">هنوز اشتراکی ثبت نشده است.</td></tr></tbody></table></div></div>')
        return out
    if sub == "payments":
        return (panel_head("مدیریت پرداخت‌ها", "وضعیت پرداخت‌ها بر اساس اشتراک‌های ثبت‌شده.")
                + '<div class="panel-card"><h2>صورت‌حساب و پرداخت</h2>'
                  "<p class=\"muted\">درگاه پرداخت متصل نیست؛ فعال‌سازی اشتراک فقط پس از تأیید "
                  "پرداخت خارج از سامانه انجام می‌شود.</p>"
                + linkto("panel/admin/subscriptions", "فعال‌سازی دستی اشتراک ←", "btn btn-primary") + "</div>"
                + '<div class="panel-card table-wrap"><table><thead><tr><th>شماره</th><th>پلن</th>'
                  "<th>مبلغ ماهانه</th><th>وضعیت</th><th>پایان</th></tr></thead><tbody data-payments-table>"
                  '<tr><td colspan="5" class="muted">هنوز پرداختی ثبت نشده است.</td></tr></tbody></table></div>')
    if sub == "community":
        return panel_head("Community", "از سؤال کوچک تا نمایش بزرگ‌ترین پروژه‌ها.") + community_tools()
    if sub == "support":
        return panel_head("پشتیبانی", "تیکت‌های باز و اولویت‌بندی آن‌ها.") + ticket_tools()
    if sub == "announcements":
        out = (panel_head("اعلان‌ها", "آخرین رخدادهای مسیر یادگیری.")
               + '<div class="panel-card narrow-left"><h2>ارسال اعلان</h2>'
                 '<form class="stack-form" data-form="announcement">'
                 '<label>عنوان<input name="title" required minlength="3" maxlength="255"></label>'
                 '<label>متن<textarea name="body" required minlength="5" maxlength="2000" rows="4"></textarea></label>'
                 '<button class="btn btn-primary">Send Notification</button></form>'
                 '<p class="form-note" hidden></p></div><div class="panel-list" data-announcements>')
        for title, body, ago in ANNOUNCEMENTS:
            out += (f'<div class="announce-card"><div><h2>{h(title)}</h2><p>{h(body)}</p></div>'
                    f"<small>{h(ago)}</small></div>")
        return out + "</div>"
    if sub == "gamification":
        return gamification_view()
    if sub == "analytics":
        out = panel_head("Analytics", "روند فعالیت و درآمد پلتفرم.")
        out += stat_grid([("کاربران ۳۰ روز گذشته", "۰", "users30"),
                          ("گفت‌وگوهای ۳۰ روز گذشته", "۰", "posts30"),
                          ("پروژه‌های شروع‌شده", "۰", "projects_all"),
                          ("مقاله‌های ذخیره‌شده", "۰", "enroll_all")])
        out += ('<div class="chart-grid"><section class="panel-card"><h2>Active Users</h2>'
                '<p class="muted">کاربران جدید در ۸ هفته گذشته</p>' + bars([3, 5, 4, 8, 6, 9, 7, 11], True)
                + '</section><section class="panel-card"><h2>Revenue</h2>'
                  '<p class="muted">درآمد ماهانه به تفکیک پلن</p>'
                + bars([190000, 990000, 1290000, 2900000]) + "</section></div>")
        out += ('<div class="panel-card"><h2>توزیع پلن‌ها</h2><div class="table-wrap"><table><thead><tr>'
                "<th>پلن</th><th>اشتراک فعال</th></tr></thead><tbody>")
        for p in PLANS:
            out += f'<tr><td>{h(p["name"])}</td><td data-plan-count="{h(p["name"])}">۰</td></tr>'
        return out + "</tbody></table></div></div>"
    if sub == "content":
        out = (panel_head("Content", "مدیریت محتوای آموزشی و صفحات عمومی.")
               + '<div class="panel-card narrow-left">'
                 '<button type="button" class="btn btn-primary" data-toggle="#content-form">ایجاد محتوا</button></div>'
                 '<div id="content-form" class="panel-card narrow" hidden><h2>محتوای جدید</h2>'
                 '<form class="stack-form" data-form="content">'
                 '<label>عنوان<input name="title" required minlength="3" maxlength="180"></label>'
                 '<label>نوع<select name="kind"><option value="مقاله">مقاله</option>'
                 '<option value="صفحه">صفحه</option></select></label>'
                 '<button class="btn btn-primary">ذخیره و انتشار</button></form>'
                 '<p class="form-note" hidden></p></div>'
                 '<div class="panel-card"><h2>آخرین آیتم‌ها</h2><div class="content-list" data-content-list>')
        for title, kind, status in CONTENT_ITEMS:
            out += (f'<div class="content-row"><div><h3>{h(title)}</h3>'
                    f'<span class="muted">{h(kind)} · {h(status)}</span></div>'
                    '<div class="content-actions"><span class="icon-btn" aria-hidden="true">✎</span></div></div>')
        return out + "</div></div>"
    if sub == "profile":
        return profile_view()
    if sub == "settings":
        out = panel_head("System Settings", "مدیریت تنظیمات مرتبط با هر بخش.") + '<div class="settings-grid">'
        for title, hint, key in SETTINGS_GROUPS:
            out += (f'<article class="setting-card"><div><h3>{h(title)}</h3>'
                    f'<span class="muted">{h(hint)}</span></div>'
                    '<form class="setting-form" data-form="setting">'
                    f'<input type="hidden" name="key" value="{h(key)}">'
                    '<input name="value" placeholder="مقدار تنظیم">'
                    '<button class="btn btn-small btn-outline">ذخیره</button></form></article>')
        return out + "</div>"
    return '<div class="empty-state">این بخش در دسترس نیست.</div>'


# ------------------------------------------------------------ assembly

def seed_script():
    """Inline catalogue so demo.js can render course/project rows offline."""
    payload = {
        "courses": {slug: {"title": c["title"], "topic": c["topic"], "level": c["level"], "intro": c["intro"]}
                    for slug, c in COURSES.items()},
        "projects": [{"title": t, "topic": topic, "description": desc, "plan": PROJECT_PLANS[i]}
                     for i, (t, topic, _lvl, desc, _sk) in enumerate(PROJECTS)],
        "paid_courses": PAID_COURSES,
        "challenges": [title for _kind, title, _xp, _topic in CHALLENGES],
        "plans": [{"id": p["id"], "name": p["name"], "price": p["price"]} for p in PLANS],
    }
    return ("<script>window.NOVENTIX_BASE=" + json.dumps(SITE_PATH, ensure_ascii=False)
            + ";window.NOVENTIX_SEED=" + json.dumps(payload, ensure_ascii=False) + ";</script>")


def code_glow():
    return ('<div class="code-glow" aria-hidden="true"><div class="glow-grid"></div>'
            '<div class="glow-orbit"><span class="orbit-chip"><code>import pandas as pd</code></span>'
            '<span class="orbit-chip"><code>model.fit(X_train, y_train)</code></span>'
            '<span class="orbit-chip"><code>@app.post("/orders")</code></span>'
            '<span class="orbit-chip"><code>return recall_score(y_test, pred)</code></span></div></div>')


def render_page(route):
    title, desc, view = route["title"], route.get("description", ""), route["view"]
    full = "Noventix | از یادگیری تا ساختن" if title == "خانه" else f"{h(title)} | Noventix"
    body = view_body(route)
    guard = (f' data-protected="{h(route["path"])}"' if view in ("courses", "course", "projects", "project", "community", "panel") else '')
    out = (
        '<!doctype html><html lang="fa" dir="rtl"><head><meta charset="utf-8">'
        '<meta name="viewport" content="width=device-width,initial-scale=1">'
        '<meta name="theme-color" content="#201a35">'
        f"<title>{full}</title>"
        f'<meta name="description" content="{h(desc or "Noventix؛ آموزش پروژه‌محور پایتون، علم داده و هوش مصنوعی از مطالعه تا ساختن.")}">'
        f'<meta property="og:title" content="{h(title)}">'
        f'<meta property="og:description" content="{h(desc)}">'
        '<meta property="og:type" content="website"><meta property="og:locale" content="fa_IR">'
        f'<link rel="icon" type="image/png" href="{h(asset("favicon.png"))}">'
        f'<link rel="stylesheet" href="{h(asset("style.css"))}">'
        + seed_script()
        + f'<script src="{h(asset("app.js"))}" defer></script>'
        + f'<script src="{h(asset("python-runner.js"))}" defer></script>'
        + f'<script src="{h(asset("demo.js"))}" defer></script></head><body{guard}>'
    )
    if view == "panel" and route["role"] == "admin":
        return out + header_html("") + ('<main class="auth-page"><div class="auth-card"><h1>پنل مدیریت</h1>'
                                      '<p class="muted">ورود مدیر فقط از طریق نسخهٔ سروری و پس از تأیید هویت انجام می‌شود.</p>'
                                      + linkto("login", "ورود به حساب ←", "btn btn-primary") + '</div></main>') + footer_html() + "</body></html>"
    if view == "panel":
        return out.replace('<body', '<body class="is-panel"', 1) + body + "</body></html>"
    return out + code_glow() + header_html(route.get("nav", "")) + body + footer_html() + "</body></html>"


def view_body(route):
    view = route["view"]
    if view == "home":
        return view_home()
    if view == "courses":
        return view_courses()
    if view == "course":
        return view_course(route["slug"])
    if view == "projects":
        return view_projects()
    if view == "project":
        return view_project(route["index"])
    if view == "pricing":
        return view_pricing()
    if view == "about":
        return view_about()
    if view == "contact":
        return view_contact()
    if view == "community":
        return view_community()
    if view == "help":
        return view_help()
    if view == "support":
        return view_support()
    if view == "terms":
        return view_terms()
    if view == "privacy":
        return view_privacy()
    if view == "login":
        return view_login()
    if view == "verify":
        return view_verify()
    if view == "notfound":
        return view_notfound()
    if view == "panel":
        return panel_shell(route["role"], route["sub"])
    return '<main class="container section"><h1>صفحه پیدا نشد.</h1></main>'


STUDENT_ITEMS = [("", "نمای کلی"), ("learning", "مسیر یادگیری"), ("projects", "پروژه‌ها"),
                 ("workspace", "میزکار کد"), ("challenges", "چالش‌ها"), ("my-challenges", "چالش‌های من"),
                 ("nova", "Nova AI"),
                 ("community", "Community"), ("notifications", "اعلان‌ها"), ("subscription", "اشتراک"),
                 ("payments", "پرداخت‌ها"), ("support", "پشتیبانی"), ("gamification", "Gamification"),
                 ("activity", "فعالیت و Streak"),
                 ("profile", "پروفایل"), ("settings", "تنظیمات")]

ADMIN_ITEMS = [("", "Overview"), ("users", "کاربران"), ("users-new", "کاربران جدید"),
               ("courses", "دوره‌ها"), ("course-new", "مقاله جدید"), ("catalog", "پروژه‌ها"), ("challenges", "چالش‌ها"),
               ("subscriptions", "اشتراک‌ها"), ("payments", "پرداخت‌ها"), ("community", "Community"),
               ("support", "پشتیبانی"), ("announcements", "اعلان‌ها"), ("gamification", "Gamification"),
               ("analytics", "Analytics"), ("content", "Content"), ("profile", "پروفایل"),
               ("settings", "System Settings")]


def panel_shell(role, sub):
    items = ADMIN_ITEMS if role == "admin" else STUDENT_ITEMS
    base = f"panel/{role}"
    out = ('<aside class="sidebar">'
           f'<a class="panel-brand" href="{h(href(""))}"><img src="{h(asset("favicon.png"))}" alt="" width="42" height="42"><span>Noventix</span></a>'
           f'<div class="sidebar-caption">{"مدیریت پلتفرم" if role == "admin" else "فضای یادگیری"}</div>'
           '<nav aria-label="ناوبری پنل">')
    for key, label in items:
        out += linkto(base + (f"/{key}" if key else ""), label, "selected" if sub == key else "")
    out += ('</nav><div class="sidebar-bottom">'
            f'<a href="{h(href(""))}">بازگشت به سایت ↗</a>'
            '<button type="button" class="text-button" data-logout>خروج از حساب</button></div></aside>'
            '<div class="panel-body"><div class="panel-top">'
            '<button type="button" class="panel-menu" aria-label="فهرست پنل">☰</button>'
            f'<span>Noventix <span class="muted">/ {"مدیریت" if role == "admin" else "دانش‌آموز"}</span></span>'
            '<span class="panel-avatar" data-avatar>ک</span></div>')
    section = admin_section(sub) if role == "admin" else student_section(sub)
    return out + f'<main class="panel-content">{section}</main></div>'


def build_routes():
    routes = [
        {"path": "", "title": "خانه", "view": "home", "nav": "",
         "description": "Noventix؛ آموزش پروژه‌محور پایتون، علم داده، یادگیری ماشین و یادگیری عمیق به زبان فارسی."},
        {"path": "courses", "title": "مسیر یادگیری", "view": "courses", "nav": "courses",
         "description": "مقاله‌های کاربردی پایتون، علم داده، یادگیری ماشین، یادگیری عمیق و ارائه مدل."},
        {"path": "projects", "title": "پروژه‌ها", "view": "projects", "nav": "projects",
         "description": "پروژه‌های واقعی پایتون، علم داده و هوش مصنوعی برای تبدیل دانش به مهارت."},
        {"path": "pricing", "title": "قیمت‌گذاری", "view": "pricing", "nav": "pricing",
         "description": "مقایسه پلن‌های رایگان، برنزی، نقره‌ای، طلایی و تیتانیوم Noventix."},
        {"path": "about", "title": "درباره ما", "view": "about", "nav": "about",
         "description": "داستان، رویکرد آموزشی و اصول تجربه یادگیری در Noventix."},
        {"path": "contact", "title": "تماس با ما", "view": "contact", "nav": "contact",
         "description": "راه‌های ارتباط با تیم Noventix و شبکه‌های اجتماعی."},
        {"path": "community", "title": "انجمن", "view": "community", "nav": "community",
         "description": "پرسش، تجربه و گفت‌وگو میان یادگیرندگان و متخصصان Noventix."},
        {"path": "help", "title": "مرکز راهنما", "view": "help", "nav": "",
         "description": "راهنمای استفاده از Noventix."},
        {"path": "support", "title": "پشتیبانی", "view": "support", "nav": "",
         "description": "پشتیبانی حساب و مسیر یادگیری Noventix."},
        {"path": "terms", "title": "قوانین و مقررات", "view": "terms", "nav": "",
         "description": "قوانین استفاده از Noventix."},
        {"path": "privacy", "title": "حریم خصوصی", "view": "privacy", "nav": "",
         "description": "سیاست حریم خصوصی Noventix."},
        {"path": "login", "title": "ورود", "view": "login", "nav": "",
         "description": "ورود یا ثبت‌نام با شماره موبایل در Noventix."},

        {"path": "verify", "title": "تأیید شماره", "view": "verify", "nav": "",
         "description": "تأیید کد پیامک‌شده."},
    ]
    for slug, c in COURSES.items():
        routes.append({"path": f"course/{slug}", "title": c["title"], "view": "course",
                       "slug": slug, "nav": "courses", "description": c["intro"]})
    for i, (title, _t, _l, desc, _s) in enumerate(PROJECTS):
        routes.append({"path": f"project/{i}", "title": title, "view": "project",
                       "index": i, "nav": "projects", "description": desc})
    for role, items in (("student", STUDENT_ITEMS), ("admin", ADMIN_ITEMS)):
        for key, label in items:
            routes.append({"path": f"panel/{role}" + (f"/{key}" if key else ""),
                           "title": label, "view": "panel", "role": role, "sub": key, "nav": "",
                           "description": f"پنل Noventix — {label}"})
    for i, (kind, title, _xp, _topic) in enumerate(CHALLENGES):
        routes.append({"path": f"panel/student/challenge/{i}", "title": title, "view": "panel",
                       "role": "student", "sub": f"challenge/{i}", "nav": "",
                       "description": f"چالش Noventix — {title}"})
    return routes


def main():
    preserved_fonts = {p.name: p.read_bytes() for p in OUT.glob("Estedad-*.ttf")} if OUT.exists() else {}
    if OUT.exists():
        shutil.rmtree(OUT)
    OUT.mkdir(parents=True)
    for name, data in preserved_fonts.items():
        (OUT / name).write_bytes(data)
    routes = build_routes()
    written = 0
    for route in routes:
        path = route["path"]
        target = OUT / "index.html" if path == "" else OUT / path / "index.html"
        target.parent.mkdir(parents=True, exist_ok=True)
        target.write_text(render_page(route), encoding="utf-8")
        written += 1
    (OUT / "404.html").write_text(render_page(
        {"path": "404", "title": "یافت نشد", "description": "صفحه مورد نظر پیدا نشد.",
         "view": "notfound", "nav": ""}), encoding="utf-8")
    assets_src = ROOT / "public" / "assets"
    assets_dst = OUT / "assets"
    assets_dst.mkdir(parents=True, exist_ok=True)
    for item in assets_src.iterdir():
        if item.is_file():
            shutil.copy2(item, assets_dst / item.name)
    shutil.copy2(ROOT / "tools" / "demo.js", assets_dst / "demo.js")
    (OUT / ".nojekyll").write_text("", encoding="utf-8")
    (ROOT / "index.html").write_text((OUT / "index.html").read_text(encoding="utf-8"), encoding="utf-8")
    (ROOT / ".nojekyll").write_text("", encoding="utf-8")
    print(f"generated {written} pages into {OUT.relative_to(ROOT)}/ and root index.html")


if __name__ == "__main__":
    main()
