# PHP Structure Audit

## ساختار پوشه‌ها

- `public/`: ریشهٔ اجرای PHP و دارایی‌های فرانت‌اند (`assets/`).
- `src/`: منطق برنامه، router، دادهٔ ثابت و schemaهای SQLite/MySQL.
- `storage/`: مسیر پیش‌فرض دیتابیس SQLite در زمان اجرا؛ در مخزن موجود نیست و به‌صورت runtime ساخته می‌شود.
- `site/`: خروجی استاتیک GitHub Pages همراه HTML، JavaScript و CSS.
- `docs/`: مستندات پروژه.

## Entry Pointها

- `public/index.php`: تنها front controller قابل مشاهده؛ session، headerهای امنیتی، اتصال به `src/app.php` و تعریف viewها را انجام می‌دهد.
- `src/router.php`: router و handlerهای صفحه‌ها را تعریف می‌کند، اما در `public/index.php` فعلی `require` یا فراخوانی `route()` برای آن دیده نمی‌شود؛ بنابراین اتصال router به entry point نیازمند بررسی است.

## Config

فایل config جداگانه یا `.env` در مخزن وجود ندارد. تنظیمات از متغیرهای محیطی خوانده می‌شوند: `DB_DSN`، `DB_USER`، `DB_PASSWORD`، `ADMIN_PHONE`، `ADMIN_PASSWORD_HASH`، `SMS_API_URL`، `SMS_API_TOKEN`، `AVALAI_API_KEY` و `AVALAI_MODEL`.

## Database Connection

`src/app.php` با PDO اتصال را ایجاد می‌کند، SQLite را به‌صورت پیش‌فرض انتخاب می‌کند، خطاها را به exception تبدیل می‌کند، prepared statement را فعال نگه می‌دارد، برای SQLite کلید خارجی را فعال می‌کند و schema مناسب SQLite/MySQL را اجرا می‌کند. migrationهای محدود OTP و `password_hash` نیز هنگام اتصال انجام می‌شوند.

## Authentication

ثبت‌نام با شماره موبایل و OTP، ورود با password و captcha، session regeneration، CSRF token، محدودیت تلاش OTP و احراز هویت جداگانهٔ مدیر پیاده‌سازی شده است. کد OTP به‌صورت hash نگهداری می‌شود و cookieهای session تنظیمات HttpOnly و SameSite دارند.

## Application Logic

- `src/app.php`: اتصال DB، helperها، احراز هویت، OTP، quota، عملیات فرم و منطق پنل.
- `src/router.php`: routeها و viewهای عمومی/پنل.
- `src/data.php`: کاتالوگ ثابت پلن‌ها، دوره‌ها، پروژه‌ها و داده‌های نمایشی.

## Frontend

فرانت‌اند PHP در `public/index.php` با CSS/JS و فونت‌های `public/assets/` ارائه می‌شود. نسخهٔ استاتیک جداگانه در `site/` شامل صفحه‌های HTML، `site/app.js` و `site/style.css` است و داده‌ها را در مرورگر نگه می‌دارد.

## Dependencies

`composer.json` یا `vendor/` وجود ندارد؛ dependency خارجی PHP مشاهده نشد. قابلیت‌های اجرایی به extensionهای PHP مانند PDO، درایور SQLite/MySQL، session، cURL و JSON متکی هستند.

## جمع‌بندی

ساختار فعلی یک PHP front controller، منطق مشترک در `src/` و دو خروجی PHP/استاتیک دارد. تنظیمات محیطی و اتصال PDO متمرکز هستند؛ مهم‌ترین نکتهٔ ساختاری، نبود اتصال آشکار `public/index.php` به `src/router.php` و فراخوانی `route()` است.
