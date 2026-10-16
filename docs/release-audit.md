# Noventix — ممیزی محصول و انتشار

تاریخ اجرا: ۲۰۲۶-۱۰-۱۶. **وضعیت انتشار: BLOCKED؛ تکمیل همهٔ فازها یا آمادگی Production اعلام نمی‌شود.**

این گزارش وضعیت کد و محیط تست همین اجرا را توصیف می‌کند، نه دیتابیس یا سرویس Production. گزارش‌های قدیمی `database-audit.md` و `php-structure-audit.md` تاریخی‌اند؛ برخی یافته‌های آن‌ها اکنون رفع شده‌اند.

## شواهد تحویل

- ۱۹۷ تست Python واقعاً اجرا شد: **۱۹۰ PASS، ۷ skipped**. پنج skip مربوط به اجرای واقعی sandbox و دو skip مربوط به Hub زنده است.
- `tools/test_release.py`: پنج تست واقعی HTTP/PHP/SQLite، شامل مسیر اکبر، سئو، انقضای دسترسی، خرابی DB و نبود دامنه. این ابزار مرورگر رندرکننده نیست.
- `tools/test_payments.py`: دوازده تست پرداخت با transport مصنوعی و تراکنش SQLite واقعی؛ **پرداخت بانکی زنده NOT TESTED**.
- تعامل‌های JavaScript و worker با `node tools/test_demo.js` PASS شدند.
- بررسی ساختار، لینک و asset برای ۷۳ فایل HTML PASS شد. فهرست سئوی فایل‌ها در `seo-audit.json` و کنترل‌ها در `release-inventory.json` است.
- کاتالوگ PHP و Python برابرند. بررسی syntax فایل‌های PHP برنامه و `git diff --check` اجرا شد.
- هیچ مرورگر، تست screenshot، سرور دائمی، پرداخت واقعی، پیامک واقعی یا استقرار اجرا نشد. سرورهای HTTP تست موقت متوقف و reap می‌شوند.

## ۱. معماری و Inventory

A = پیاده‌سازی و شواهد اجرایی موجود؛ B = بخشی واقعی و بخشی ناقص؛ C = نمایش یا نمونه بدون عملکرد وعده‌داده‌شده؛ D = موجود نیست؛ E = وابسته به زیرساخت/سرویس در دسترس‌نبوده. A در این جدول به معنی PASS روی Production نیست.

| قابلیت | قبل → اکنون | پیاده‌سازی و محل داده | شواهد / محدودیت |
|---|---|---|---|
| PHP routing / 404 | A → A | `public/index.php`، `src/router.php` | HTTP واقعی، 404 و noindex تست شد |
| ثبت‌نام / OTP / ورود | B → A/E | `src/auth.php`؛ users، otp_codes، otp_requests | ثبت‌نام واقعی endpoint با SMS fixture؛ SMS زنده E |
| نشست / خروج | B → A | PHP session؛ strict، HttpOnly، SameSite، Secure روی HTTPS | regeneration، خروج و idle expiry؛ سیاست ۳۰ دقیقه بی‌فعالیتی و ۱۲ ساعت عمر احراز هویت |
| پنل / وضعیت حساب | A → A | users، enrollments، lesson_progress | HTTP اکبر و تست‌های dashboard؛ مرورگر تست نشده |
| Course / Lesson | B → B | courses و lessons؛ فرم‌ها و POST مدیریت اضافه شد | ایجاد و انتشار واقعی دوره و جلسه تست شد؛ مدیریت عمومی ویدئو هنوز موجود نیست |
| آزمون / تکمیل | B → A/B | lesson_quizzes، lesson_progress، activities | مؤلف می‌تواند آزمون واقعی ذخیره کند؛ تکمیل تنها با پاسخ معتبر و نسخهٔ جاری آزمون |
| Video | B → B/E | embed ثابت آپارات برای جلسهٔ اول AI | Play/Pause/Seek و دسترس‌پذیری واقعی NOT TESTED؛ سایر جلسه‌ها ویدئوی عمومی ندارند |
| Progress / Resume | B → B | lesson_progress؛ dashboard_active_lesson | HTTP ذخیره/بازخوانی بعد ورود مجدد PASS؛ embed فعلی telemetry خودکار ندارد |
| یکپارچگی Progress | B → B | upsert با MAX/GREATEST | watched/percentage عقب نمی‌روند؛ last_position آخرین درخواست رسیده به سرور است، نه ترتیب قطعی رخداد دو دستگاه |
| Lead یادگیری | A → A | وضعیت مشتق‌شده از users/enrollments/verified progress | Verified تا High Intent با شواهد آزمون؛ جدول leads به این مسیر وصل نیست |
| Lead بازاریابی | B → B | contact_messages و leads جدا | فرم تماس واقعی است؛ چرخهٔ CRM و همگام‌سازی خودکار جدول leads پیاده نشده |
| مدیر / گزارش کاربران | B → A/B | guard سروری و داده‌های واقعی users/progress/subscriptions | جست‌وجوی نام، تأیید شماره و پلن فعال اضافه شد؛ جدول با نام اکبر بررسی شد |
| پرداخت / اشتراک | A/E → A/E | payment_orders و subscriptions | verify سروری و idempotency تست fixture؛ زرین‌پال زنده E |
| مزایای پلن | C/B → B | PLANS، can_course، payment_plan_courses | ادعاهای فاقد پیاده‌سازی حذف/غیرفعال؛ فقط محتوای پولی منتشرشدهٔ دارای جلسه قابل‌فروش است |
| تیتانیوم | C → غیرفعال برای فروش | تاریخچه حفظ می‌شود؛ شروع خرید مسدود | نسبت به طلایی مزیت فعال ندارد؛ تست عدم ایجاد سفارش PASS |
| میزکار خصوصی | A/E → A/E | projects، project_workspaces، templates، runs | ساخت/ذخیره/مالکیت/نسخه واقعی؛ runner زنده E |
| پروژهٔ کاتالوگ | B → A | همان ws_create، همان project identity | مسیر قدیمی هم به میزکار واقعی می‌رود؛ ایجاد تکراری catalog identity تکرار نمی‌شود |
| Hub datasets | A/E → A/E | metadata DB + فایل خصوصی | تست‌های محلی PASS؛ Hub زنده opt-in اجرا نشده |
| انجمن / ذخیره شخصی | A → A | post_reactions یکتا و owner filter | تکرار save، CSRF و جداسازی مجموعه تست شد |
| Nova | متوقف → متوقف | تاریخچه حذف نشده، endpoint غیرفعال | هیچ سهمیه یا پاسخ فعال فروخته نمی‌شود |
| Gamification / XP / mentoring / certificate | C/D → C/D | بخشی از UI و کاتالوگ نمایشی | مزیت فعال پلن نیست؛ محصول کامل این قابلیت‌ها پیاده نشده |
| محتوای مدیریتی عمومی | B → B | content_items عنوان/نوع/وضعیت | پیام draft اصلاح شد؛ CMS مقالهٔ دارای body/author/date و route عمومی وجود ندارد |
| SEO | B → B | `src/seo.php` و static metadata | تست محلی عمومی PASS؛ دامنه و Crawl/Index زنده NOT TESTED |
| Responsive / performance | B → NOT TESTED | CSS/RTL موجود | بررسی واقعی پنج viewport، console، touch و Web Vitals ممکن نبود |

### مسیرها

عمومی PHP: `/`، `/courses`، `/course/{published-slug}`، `/projects`، `/project/{0..8}`، `/pricing`، `/about`، `/contact`، `/terms`، `/privacy`، `/community`، `/member/{id}`.

ورود و تأیید: `/login`، `/verify`، `/admin-login`؛ فرم‌های `auth/request`، `auth/resend`، `auth/verify`، `auth/login`، `auth/admin` و `logout`.

خصوصی: `/dashboard` و زیرمسیرهای profile، learning، projects، community، nova، subscription، support، challenges، gamification، activity، settings؛ `/dashboard/course/{slug}`؛ `/dashboard/workspace` و `/{id}`؛ `/dashboard/payment-history`.

مدیر: users، users-new، courses، catalog، subscriptions، messages، analytics، payments، announcements، content، settings و workspace-templates. گارد GET و POST مستقل است؛ نقش ارسالی Client معتبر نیست.

API و عملیات: `progress/lesson/{id}`، `progress/save`، `workspace/*`، `community/*`، `tickets/*`، contact/send، profile/save، projects/start، courses/enroll، payment/start، payment/retry، payment/zarinpal/callback، admin/course-save، admin/lesson-save، admin/quiz-save و عملیات مدیریتی قدیمی. نام دقیق شاخه‌های literal و فرم‌ها با محل سورس در `release-inventory.json` آمده است؛ مسیرهای محاسبه‌شده باید با این خانواده‌ها خوانده شوند.

نسخهٔ ایستا: ۷۲ route تولیدشده به‌علاوهٔ 404، شامل help/support و panel/student و panel/admin. PHP مسیر عمومی help/support ندارد؛ CTA پشتیبانی PHP به dashboard/support اصلاح شد. نسخهٔ ایستا backend نیست، پرداخت و احراز هویت Production نیست، و تمام صفحاتش عمداً noindex هستند.

## ۲. Requirements Traceability Matrix

| وعده / محل نمایش | قابلیت لازم و مسیر | ذخیره / دسترسی | تست پذیرش و وضعیت نهایی |
|---|---|---|---|
| شروع رایگان؛ Landing | login → request → verify → enroll AI | users، otp_codes، enrollments؛ کاربر تأییدشده | Akbar HTTP PASS؛ SMS زنده NOT TESTED |
| بازگشت به یادگیری؛ Dashboard | login مجدد → progress GET → course | lesson_progress مالک؛ guard publication/enrollment/plan | تست اکبر و dashboard PASS؛ seek واقعی ویدئو NOT TESTED |
| تکمیل درس | quiz POST → پاسخ معتبر → version hash | quizzes/progress/activities؛ مالک | پاسخ غلط/تقلب completed و تکرار completion پوشش دارد؛ PASS |
| تکمیل دوره / Lead | همهٔ درس‌های published با evidence جاری | مشتق‌شده از progress، ثبت یک‌باره activity | اکبر: ۲ درس تکمیل، یک course_completed، High Intent؛ PASS |
| مشاهده مدیر | admin step-up → users?q | users + verified progress + subscription | جست‌وجو و وضعیت اکبر PASS؛ دسترسی غیرمدیر 403 |
| پلن فعال | request/verify معتبر → subscription | payment_orders/subscriptions؛ فقط سرور | fixture transport و SQL transaction PASS؛ بانک زنده NOT TESTED |
| محتوای پولی | enroll و هر course/progress/template request | subscriptions با starts/status/expires؛ مالک | همهٔ پلن‌ها، future/inactive/expired، رابطهٔ template PASS |
| ظرفیت پروژه؛ pricing | ws_create از مسیر workspace و catalog | projects/project_workspaces؛ مالک، user lock | سقف واقعی ۲۰ پروژه و ۲۰ MiB؛ نامحدود حذف شد |
| نگهداری فایل | save revision → reload/restore | DB خصوصی + dataset filesystem | تست workspace/datasets PASS؛ اجرای زیرساخت واقعی NOT TESTED |
| اجرای Python | submit → runner → result | snapshot/run private | صف و سیاست محلی PASS؛ پنج sandbox check skipped |
| ذخیره‌های من | community/react → saved collection | post_reactions با کلید یکتا | تکرار و جداسازی مالک PASS |
| Nova AI | فعلاً هیچ درخواست تولیدی پذیرفته نمی‌شود | تاریخچه محفوظ | endpoint و UI paused PASS |
| منتور/رزومه/گواهی/SLA/شخصی‌سازی/VIP | سرویس واقعی در پروژه وجود ندارد | ندارد | وعدهٔ فعال حذف شد؛ خود قابلیت‌ها D/C |
| تیتانیوم | مزیت اضافی عملی لازم است | سفارش جدید ایجاد نمی‌شود | backend و checkout فروش را مسدود می‌کنند؛ PASS |
| Course authoring | admin → course-save / lesson-save / quiz-save | همان جداول موجود، CSRF و admin | ایجاد محتوا از endpointهای واقعی در سناریوی اکبر PASS |
| محتوا draft | content/create | content_items | پیام «پیش‌نویس»؛ انتشار مقالهٔ عمومی NOT TESTED/موجود نیست |
| SEO عمومی | head_page، robots، sitemap، 404 | PUBLIC_SITE_URL تنظیم‌شده؛ بدون Host fallback | metadata و XML محلی PASS؛ دامنه نهایی NOT TESTED |
| ارتباط / پشتیبانی | contact/send و dashboard/support | contact_messages / tickets؛ دسترسی متناسب | لینک داخلی اصلاح و اسکن شد؛ همهٔ تعاملات فرم‌ها end-to-end تست نشده‌اند |

تمام امتیازها و امکانات موجود در نسخهٔ ایستا صرفاً نشان‌دهندهٔ رفتار همان demo هستند؛ گزارش آن‌ها معادل entitlement یا دادهٔ سرور نیست.

## ۳. CRAFT و Landing

در اسناد موجود و محتوای بررسی‌شده، تعریف معتبر مرحله‌به‌مرحله CRAFT یافت نشد. نوشته‌های «تا C و R»، «تا F» و سه قدم عمومی، تعریف رسمی پنج مرحله نیستند. بنابراین **نگاشت رسمی C/R/A/F/T: NOT TESTED / BLOCKED by missing approved definition**؛ هیچ expansion اختراع نشد و ادعاهای پلنی و برچسب متد رسمی حذف شد.

نگاشت واقعی فعلی، بدون نام‌گذاری به‌عنوان CRAFT:

| نقش محتوایی | بخش واقعی | اقدام و شاهد |
|---|---|---|
| شناخت مسئله و مخاطب | Landing «اگر می‌دانی اما هنوز نمی‌سازی» | توضیح مخاطب مبتدی و پرهیز از تضمین شغلی |
| مسیر پیشنهادی | بخش سه قدم و Course curriculum | ثبت‌نام/تأیید → دورهٔ رایگان → تمرین |
| شواهد قابل بررسی | محتوای منتشرشده، آزمون نسخه‌دار و داشبورد | مشاهده جلسه، پاسخ آزمون و وضعیت واقعی |
| اقدام مناسب | CTA دورهٔ رایگان، فرم ثبت‌نام و checkout | مقصد واقعی؛ خرید بدون محتوای قابل‌فروش مسدود |
| ادامه یادگیری | Dashboard و میزکار | ذخیرهٔ خصوصی، بازخوانی و تکمیل قابل‌سنجش |

Nova در Landing و قوانین دیگر دستیار فعال معرفی نمی‌شود. آمار کاربران، نظر کاربر، شمارش معکوس و تضمین درآمد اضافه نشده‌اند.

## ۴. SEO Audit تمام خانواده‌های عمومی PHP

PASS زیر به معنی اجرا در fixture HTTP است؛ به معنی اتصال دامنه، مشاهده توسط Google یا تست Production نیست. `PUBLIC_SITE_URL` باید origin نهایی HTTPS بدون مسیر، query یا credential باشد. تا پیش از تنظیم، canonical/og:url ساخته نمی‌شود و sitemap پاسخ 503 صریح می‌دهد، نه URL فرضی. origin تست فقط مصنوعی است.

| صفحه | HTTP/عنوان اختصاصی/Description/H1/OG/Twitter/RTL | Canonical و JSON-LD در fixture | سیاست Index | سایر موارد |
|---|---|---|---|---|
| `/` | PASS | PASS؛ WebPage | index | performance/Google NOT TESTED |
| `/courses` | PASS | PASS؛ WebPage | index | همین محدودیت |
| `/projects` | PASS | PASS؛ WebPage | index | همین محدودیت |
| `/pricing` | PASS | PASS؛ WebPage؛ نه Product با offer ساختگی | index | بانک زنده NOT TESTED |
| `/about` | PASS | PASS؛ WebPage | index | همین محدودیت |
| `/contact` | PASS | PASS؛ WebPage | index | همین محدودیت |
| `/terms` | PASS | PASS؛ WebPage | index | بازبینی حقوقی تخصصی NOT TESTED |
| `/privacy` | PASS | PASS؛ WebPage | index | بازبینی حقوقی تخصصی NOT TESTED |
| `/project/0` | PASS | PASS؛ WebPage | index | محتوای راهنمای پروژه، نه محصول فروشی مستقل |
| `/project/1` | PASS | PASS؛ WebPage | index | همین محدودیت |
| `/project/2` | PASS | PASS؛ WebPage | index | همین محدودیت |
| `/project/3` | PASS | PASS؛ WebPage | index | همین محدودیت |
| `/project/4` | PASS | PASS؛ WebPage | index | همین محدودیت |
| `/project/5` | PASS | PASS؛ WebPage | index | همین محدودیت |
| `/project/6` | PASS | PASS؛ WebPage | index | همین محدودیت |
| `/project/7` | PASS | PASS؛ WebPage | index | همین محدودیت |
| `/project/8` | PASS | PASS؛ WebPage | index | همین محدودیت |
| `/course/ai-foundations` با محتوای fixture منتشرشده | PASS | PASS؛ Course فقط با جلسهٔ منتشرشده | index | محتوای Production NOT TESTED |
| سایر `/course/{slug}` | NOT TESTED برای محتوای مستقر | همان renderer؛ URL پایدار | فقط published | عنوان/شرح واقعی هر رکورد در Production باید ممیزی شود |
| `/community` | HTTP و noindex PASS | canonical عمومی ندارد | noindex؛ محتوای کاربر بازبینی نشده | تعامل کامل تمام پست‌ها NOT TESTED |
| `/member/{id}` | NOT TESTED به‌صورت صفحه‌به‌صفحه | سیاست عمومی noindex در head | noindex | دادهٔ واقعی اعضا عمداً خوانده نشد |
| login / verify / admin-login | ورود و redirect در سناریو PASS؛ login noindex PASS | بدون canonical عمومی | noindex | صفحهٔ حساب برای Index نیست |
| مسیر ناموجود | HTTP 404 و noindex PASS | بدون canonical | noindex | نه soft-404 |
| robots.txt | PASS | فقط در صورت origin معتبر sitemap درج می‌شود | private route families محدود | سیاست robots جای authorization نیست |
| sitemap.xml | XML/حذف private/عدم origin PASS | همهٔ published course و پروژه‌های catalog | فقط عمومی | دریافت Search Console NOT TESTED |

جزئیات ۷۳ فایل ایستا، headingها، alt attribute و کنترل‌های metadata در `seo-audit.json` جداگانه است. noindex برای demo تصمیم آگاهانهٔ جلوگیری از Duplicate Content است، نه راه‌اندازی SEO برای یک محصول Production روی Pages. robots.txt نسخهٔ ایستا نباید به‌عنوان robots ریشهٔ دامنهٔ PHP کپی شود.

قالب عمومی lang=fa و dir=rtl دارد؛ public assets محلی‌اند. JSON-LD nonce متناسب با CSP دارد و از دادهٔ واقعی title/description/course ساخته می‌شود؛ review/rating/author/date/Offer ساختگی اضافه نشده است. Article/BlogPosting ساخته نشد، چون CMS عمومی مقالهٔ دارای بدنه/نویسنده/تاریخ در این معماری وجود ندارد. WebPage/Course بودن تضمین rich result نیست.

Alt معنایی تمام تصاویر و پیوستگی H2/H3 در همهٔ محتوای Production، حجم واقعی منابع روی شبکهٔ ایران، Crawl واقعی، سرعت، Search Console و Index: **NOT TESTED**. وجود metadata تضمین Index نیست.

## ۵. سناریوی اکبر و ایمنی Fixture

تست با DB موقت خالی شروع می‌شود. مدیر نیز از endpoint ثبت‌نام و OTP fixture وارد می‌شود، step-up واقعی را می‌گذراند و دوره، دو جلسه و آزمون‌ها را با POSTهای admin می‌سازد. اکبر سپس از Landing به ثبت‌نام، ارسال/دریافت کد fixture، تأیید، خروج/ورود، Dashboard، enroll، جلسه، ذخیره و بازیابی progress، ورود دوباره، پاسخ آزمون و تکمیل می‌رود. مدیر نام اکبر را جست‌وجو و Completion و High Intent را مشاهده می‌کند. تکمیل تکراری activity مضاعف نمی‌سازد.

هیچ INSERT/UPDATE دستی دیتابیس در مسیر اکبر انجام نمی‌شود؛ SQL سمت تست فقط شواهد را می‌خواند. تست جداگانهٔ انقضای اشتراک fixture مستقل می‌سازد و تغییر می‌دهد؛ این بخشی از سناریوی اکبر یا دادهٔ Production نیست.

`tools/release_test_router.php` فقط با PHP cli-server، فلگ تست و جایگزینی صریح cURL فعال است. ارسال SMS به transport مصنوعی محدود به شماره‌های fixture است؛ درخواست به host دیگری از این transport رد می‌شود. مسیر fixture فقط اطلاعات نشست مصنوعی را برمی‌گرداند، در `public/` نیست و **هرگز نباید deploy شود**. هیچ flag یا مسیر افشای OTP در کد Production اضافه نشده است.

مرحله‌های ۱۲ و Play/Pause/Seek واقعی، رویداد refresh/exit و موقعیت player آپارات: **NOT TESTED**. نوشتن telemetry توسط HTTP اثبات تماشای ویدئو نیست. Completion آزمون دانش را نشان می‌دهد، نه مدت تماشا. بنابراین شرط Done کامل ۲۷ مرحلهٔ دارای پخش واقعی ویدئو هنوز برآورده نشده است.

## ۶. امنیت و Failure Handling

| بررسی | نتیجه و شاهد |
|---|---|
| Password hashing / OTP hashing | PASS در ثبت‌نام و تست‌های auth؛ رمز واقعی خوانده نشد |
| wrong password / unknown / unverified | PASS؛ تست‌های OTP HTTP و ورود |
| expired/invalid/reused OTP و سقف تلاش | PASS؛ تست‌های OTP HTTP و تراکنش مصرف یک‌باره |
| duplicate registration و cooldown | PASS در مجموعهٔ OTP؛ SMS زنده NOT TESTED |
| session fixation / logout / regeneration | PASS در HTTP؛ idle expiry جدید در اکبر PASS |
| Session Cookie روی HTTPS مستقر | NOT TESTED؛ flags در سورس موجودند، وضعیت reverse proxy مشخص نیست |
| CSRF / دسترسی مدیر / مالکیت | PASS برای endpointهای تست‌شده؛ لاگین نقش client را قبول نمی‌کند |
| IDOR progress و ذخیرهٔ شخصی / workspace | PASS روی fixtureهای مستقل کاربران |
| SQL injection و escaping | prepared statements و h() در مسیرهای بررسی‌شده؛ آزمون همهٔ ورودی‌ها/دادهٔ واقعی NOT TESTED |
| XSS عملی مرورگری | NOT TESTED؛ escaping و CSP بررسی شده‌اند |
| Invalid ID/relationship/ranges/empty/draft | پوشش آزمون unit/HTTP؛ مدیریت همهٔ فرم‌ها به‌صورت تعاملی NOT TESTED |
| DB unavailable | PASS؛ HTTP 503 عمومی بدون DSN/stack trace؛ خطای کلاس در server log |
| Gateway request/verify failure | PASS با transport fixture؛ سرویس زنده NOT TESTED |
| malformed origin / missing environment | PASS؛ sitemap origin جعلی نمی‌سازد، پرداخت فاقد تنظیم غیرفعال است |
| repeated/concurrent payment / progress / OTP | PASS در تست‌های transaction/uniqueness؛ لاگ‌های واقعی Production بررسی نشد |
| موقتاً قطع‌شدن شبکهٔ player و retry browser | NOT TESTED؛ player فعلی API پیشرفت خودکار ندارد |

خطاهای غیرمنتظره در front controller با خروجی عمومی 503 مدیریت می‌شوند؛ پیام خام exception، DSN و stack trace به کاربر داده نمی‌شود. مسیرهای validation کدهای 419/422 و مسیر مالکیت 404/403 خود را حفظ می‌کنند. تشخیص عملیاتی دقیق‌تر DB در برابر سرویس خارجی در همهٔ endpointها هنوز کامل نیست.

## ۷. Responsive و موانع انتشار

| Viewport | Landing/Auth/Dashboard/Course/Video/Articles/Pricing/Admin |
|---|---|
| 360×800 | NOT TESTED |
| 390×844 | NOT TESTED |
| 768×1024 | NOT TESTED |
| 1366×768 | NOT TESTED |
| 1440×900 | NOT TESTED |

Overflow، touch target، منوی واقعی، فونت رندرشده، modal، console و performance بدون مرورگر اثبات نشدند؛ بررسی HTML/CSS جای این موارد را نمی‌گیرد.

موانع دقیق:
1. سرور متصل ثبت‌شده‌ای برای deploy وجود ندارد؛ production PHP/MySQL، migration روی دادهٔ واقعی و runner ایزوله قابل‌آزمون نبودند.
2. ارسال SMS واقعی، تراکنش بانکی واقعی و recovery واقعی gateway اجرا نشدند.
3. دامنهٔ نهایی برای canonical/sitemap تأیید و تنظیم نشده است؛ از Host یا دامنهٔ حدسی استفاده نمی‌شود.
4. ویدئوی همهٔ جلسه‌ها، player telemetry واقعی و آزمون‌های viewport/console در دسترس نیستند.
5. تعریف رسمی CRAFT موجود نیست. CMS مقالهٔ عمومی، گواهی/منتورینگ و CRM همگام‌شده قابلیت کامل ندارند؛ مزیت فعال فروخته نمی‌شوند.
6. last_position در چند دستگاه سیاست last-server-arrival دارد؛ نسخهٔ optimistic جداگانهٔ رخداد player پیاده نشده است. مقادیر تجمعی حفظ و completion از telemetry جداست.

## تکرار تست‌ها

با PHP دارای PDO SQLite، mbstring، curl و zip:

```sh
PHP_BIN=php python3 -m unittest discover -s tools -p 'test_*.py' -q
node tools/test_demo.js
PHP_BIN=php python3 tools/check_catalog.py
python3 tools/gen.py
python3 tools/verify.py
python3 tools/audit_inventory.py
git diff --check
```

در این اجرا runtime سبک PHP محلی استفاده شد. تست‌ها فقط fixtureهای خصوصی را می‌سازند و سرورهای موقت را قبل از پایان متوقف می‌کنند. سرویس یا تست skipped را با PASS جایگزین نکنید.
