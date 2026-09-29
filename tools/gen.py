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
)

ROOT = pathlib.Path(__file__).resolve().parent.parent
OUT = ROOT / "site"
SITE_PATH = "/Novetnix-SaaS/site"  # GitHub Pages currently serves the main branch root

YEAR = "۱۴۰۵"


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
    return f'<div class="panel-heading"><h1>{h(title)}</h1><p>{h(sub)}</p></div>'


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


def demo_notice():
    return ('<div class="container"><div role="status" class="notice success demo-notice">'
            'این نسخه نمایشی روی GitHub Pages اجرا می‌شود؛ داده‌ها فقط در مرورگر خود شما ذخیره می‌شوند '
            'و به سرور ارسال نمی‌شوند.</div></div>')


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
        target = "login" if p["id"] == "free" else "panel/student/subscription"
        label = "شروع رایگان" if p["id"] == "free" else "انتخاب پلن"
        out += (f'</ul><a class="btn {"btn-gold" if featured else "btn-outline"} full" '
                f'href="{h(href(target))}">{label}</a></article>')
    return out + "</div>"


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
            f'<span>{h(level)}</span></div><h3>{h(title)}</h3><p>{h(desc)}</p>'
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
            f'<span>{h(c["level"])} · {fa(c["minutes"])} دقیقه مطالعه</span></div>'
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
        f'<img src="{h(asset("logo.png"))}" alt="Noventix" width="171" height="55"></a>'
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
    out = (f'<main class="article"><div class="container narrow-container">'
           f'<span class="eyebrow">{h(c["topic"])} · {h(c["level"])}</span>'
           f'<h1>{h(c["title"])}</h1><p class="lead">{h(c["intro"])}</p>'
           f'<div class="article-meta"><span>زمان مطالعه: {fa(c["minutes"])} دقیقه</span>'
           f"<span>به‌روزرسانی: {YEAR[:4]}</span></div>")
    for i, (heading, body) in enumerate(c["sections"]):
        out += (f'<section class="article-section"><h2><span>{fa(i + 1)}</span>{h(heading)}</h2>'
                f"<p>{h(body)}</p></section>")
    out += ('<div class="article-footer">'
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
           f'<span class="eyebrow">{h(topic)} · {h(level)}</span><h1>{h(title)}</h1>'
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
    out += ('</tbody></table></div></section><section class="section container"><div class="cta-panel">'
            '<span class="eyebrow">پرداخت</span><h2>فعال‌سازی اشتراک با پشتیبانی</h2>'
            "<p>دروازه پرداخت هنوز متصل نشده است؛ برای فعال‌سازی مجوز خود را برای تیم ما بفرستید "
            "تا پس از تأیید، اشتراک شما فعال شود.</p>"
            + linkto("contact", "تماس با پشتیبانی ←", "btn btn-light") + "</div></section></main>")
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
    return ('<main>' + page_hero("تماس با ما", "با Noventix در ارتباط باشید",
                                 "برای دریافت آموزش‌ها، اخبار و پروژه‌ها همراه ما باشید و پرسش‌های خود را در انجمن مطرح کنید.")
            + '<section class="section container"><div class="contact-layout">'
              '<div class="panel-card"><h2>ارسال پیام</h2>'
              '<p class="muted">پیام شما در سیستم ثبت می‌شود و در اولین فرصت بررسی خواهد شد.</p>'
              '<form class="stack-form" data-form="contact">'
              '<label>نام و نام خانوادگی<input name="name" required minlength="2" maxlength="100"></label>'
              '<label>ایمیل<input type="email" dir="ltr" name="email" required maxlength="255" placeholder="you@example.com"></label>'
              '<label>پیام<textarea name="message" required minlength="10" maxlength="3000" rows="6"></textarea></label>'
              '<button class="btn btn-primary">ارسال پیام</button></form>'
              '<p class="form-note" hidden></p></div><div class="social-column">'
            + "".join(
                f'<a href="{u}" target="_blank" rel="noopener noreferrer"><strong>{n}</strong><span>{s}</span></a>'
                for u, n, s in SOCIALS)
            + "</div></div></section></main>")


def view_login():
    return ('<main class="auth-page"><div class="auth-card">'
            f'<img src="{h(asset("nova.png"))}" alt="مسکات Nova" width="70" height="70">'
            "<h1>ورود یا ثبت‌نام</h1>"
            "<p class=\"muted\">شماره موبایل خود را وارد کنید تا کد تأیید برایتان ارسال شود.</p>"
            '<form class="stack-form" data-form="login">'
            '<label>شماره موبایل<input type="tel" dir="ltr" name="phone" required pattern="0?9[0-9]{9}" '
            'placeholder="09123456789" autocomplete="tel"></label>'
            '<button class="btn btn-primary">ارسال کد تأیید</button></form>'
            '<p class="form-note" hidden></p>'
            "<p class=\"fine-print\">در این نسخه نمایشی هیچ پیامکی ارسال نمی‌شود؛ کد تأیید همان‌جا "
            "نمایش داده می‌شود تا بتوانید پنل را ببینید.</p></div></main>")


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
              '<div class="community-list" data-community-list></div></section></main>')


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
                f'<button type="button" class="btn btn-primary full" data-challenge="{i}">شروع چالش</button></article>')
    return out + "</div>"


def community_tools():
    return ('<div class="panel-card narrow-left"><h2>Create Post</h2>'
            '<form class="stack-form" data-form="post">'
            '<label>عنوان<input name="title" required minlength="5" maxlength="180"></label>'
            '<label>متن<textarea name="body" required minlength="10" maxlength="5000" rows="4"></textarea></label>'
            '<button class="btn btn-primary">Create Post</button></form>'
            '<p class="form-note" hidden></p></div><div class="panel-list" data-panel-posts></div>')


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


def profile_view():
    out = panel_head("پروفایل من", "اطلاعات نمایش داده‌شده در حساب کاربری.")
    out += ('<div class="profile-head"><div>'
            f'<img src="{h(asset("nova.png"))}" alt="" width="56" height="56" aria-hidden="true">'
            "<div><h2 data-profile-name>کاربر Noventix</h2>"
            '<p class="muted" data-profile-phone>—</p>'
            "<p class=\"muted\">سازنده‌ای در مسیر یادگیری هوش مصنوعی</p></div></div>"
            + linkto("panel/student/settings", "ویرایش پروفایل", "btn btn-outline") + "</div>")
    out += ('<div class="panel-card narrow"><form class="stack-form" data-form="profile">'
            '<label>نام نمایشی<input name="name" maxlength="100" minlength="2" required placeholder="نام شما"></label>'
            '<label>ایمیل (اختیاری)<input type="email" dir="ltr" name="email" maxlength="255" placeholder="you@example.com"></label>'
            '<label>شماره موبایل<input dir="ltr" disabled data-profile-phone-input value="—"></label>'
            '<button class="btn btn-primary">ذخیره تغییرات</button></form>'
            '<p class="form-note" hidden></p>'
            "<p class=\"fine-print\">شماره موبایل شناسه ورود شماست و از این صفحه قابل تغییر نیست.</p></div>")
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
    plan = PLANS[0]
    out = panel_head("اشتراک من", "وضعیت پلن و ظرفیت امکانات شما.")
    out += ('<div class="sub-layout"><section class="panel-card"><h2>Usage</h2>'
            f'<div class="usage-row"><span>AI Credits</span><strong>۰ از {fa(plan["nova"])}</strong></div>'
            + progress_bar(2)
            + '<div class="usage-row"><span>Projects</span><strong>'
            + ("نامحدود" if plan["projects"] == -1 else fa(plan["projects"])) + "</strong></div></section>"
            '<section class="plan-tile"><span class="eyebrow">Current Plan</span>'
            f'<h2>{h(plan["name"])}</h2><p>اشتراک فعالی ثبت نشده است.</p>'
            + linkto("pricing", "ارتقای پلن", "btn btn-gold") + "</section></div>")
    out += '<div class="panel-card"><h2>امکانات فعلی</h2><ul class="check-list">'
    for feature in plan["features"]:
        out += f"<li>{h(feature)}</li>"
    out += ('</ul><p class="muted">در نسخه نمایشی، فعال‌سازی پلن به‌صورت شبیه‌سازی‌شده '
            "در همین صفحه انجام می‌شود.</p></div>")
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
    if sub == "community":
        return panel_head("Community", "از سؤال کوچک تا نمایش بزرگ‌ترین پروژه‌ها.") + community_tools()
    if sub == "subscription":
        return student_subscription()
    if sub == "support":
        return panel_head("پشتیبانی", "تیکت‌های باز و اولویت‌بندی آن‌ها.") + ticket_tools()
    if sub == "gamification":
        return gamification_view()
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
                + f'<div class="panel-card"><p class="muted">{fa(len(COURSES))} مقاله فعال در کاتالوگ.</p>'
                + linkto("courses", "مشاهده کاتالوگ ←", "btn btn-outline") + "</div>" + course_cards())
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
        "projects": [{"title": t, "topic": topic, "description": desc}
                     for t, topic, _lvl, desc, _sk in PROJECTS],
        "plans": [{"id": p["id"], "name": p["name"], "price": p["price"]} for p in PLANS],
    }
    return ("<script>window.NOVENTIX_BASE=" + json.dumps(SITE_PATH, ensure_ascii=False)
            + ";window.NOVENTIX_SEED=" + json.dumps(payload, ensure_ascii=False) + ";</script>")


def render_page(route):
    title, desc, view = route["title"], route.get("description", ""), route["view"]
    full = "Noventix | از یادگیری تا ساختن" if title == "خانه" else f"{h(title)} | Noventix"
    body = view_body(route)
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
        + f'<script src="{h(asset("demo.js"))}" defer></script></head><body>'
    )
    if view == "panel":
        return out.replace('<body>', '<body class="is-panel">', 1) + body + "</body></html>"
    return out + header_html(route.get("nav", "")) + demo_notice() + body + footer_html() + "</body></html>"


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
                 ("challenges", "چالش‌ها"), ("community", "Community"), ("subscription", "اشتراک"),
                 ("support", "پشتیبانی"), ("gamification", "Gamification"), ("profile", "پروفایل"),
                 ("settings", "تنظیمات")]

ADMIN_ITEMS = [("", "Overview"), ("users", "کاربران"), ("users-new", "کاربران جدید"),
               ("courses", "دوره‌ها"), ("catalog", "پروژه‌ها"), ("challenges", "چالش‌ها"),
               ("subscriptions", "اشتراک‌ها"), ("payments", "پرداخت‌ها"), ("community", "Community"),
               ("support", "پشتیبانی"), ("announcements", "اعلان‌ها"), ("gamification", "Gamification"),
               ("analytics", "Analytics"), ("content", "Content"), ("profile", "پروفایل"),
               ("settings", "System Settings")]


def panel_shell(role, sub):
    items = ADMIN_ITEMS if role == "admin" else STUDENT_ITEMS
    base = f"panel/{role}"
    out = ('<aside class="sidebar">'
           f'<a class="panel-brand" href="{h(href(""))}">Noventix <span>N</span></a>'
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
    switch = ('<div class="demo-switch"><span>پیش‌نمایش پنل‌ها · اطلاعات فقط در همین مرورگر</span>'
              + linkto("panel/student", "دانشجو", "selected" if role == "student" else "")
              + linkto("panel/admin", "مدیر", "selected" if role == "admin" else "") + "</div>")
    return out + f'<main class="panel-content">{switch}{section}</main></div>'


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
    return routes


def main():
    if OUT.exists():
        shutil.rmtree(OUT)
    OUT.mkdir(parents=True)
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
