# PHP MVP Structure

```text
config/
  config.php                 محیط و تنظیمات پایه

database/
  connection.php             اتصال PDO و اجرای schema/migrationهای runtime
  schema.sqlite.sql          DDL برای SQLite
  schema.mysql.sql           DDL برای MySQL

src/
  app.php                    منطق برنامه، احراز هویت و handlerهای فرم
  data.php                   کاتالوگ ثابت محتوا و پلن‌ها
  router.php                 routeها و viewهای PHP

public/
  index.php                  front controller و خروجی عمومی
  assets/                    CSS، JavaScript، فونت و تصویر

storage/
  noventix.sqlite            دادهٔ runtime پیش‌فرض؛ خارج از ریشهٔ عمومی وب
```

`public/index.php` به `src/app.php` متصل می‌شود؛ `src/app.php` منطق مشترک را به `database/connection.php` و `src/data.php` واگذار می‌کند. تنظیمات از متغیرهای محیطی در `config/config.php` خوانده می‌شوند و مسیر فایل SQLite در `storage/` قرار دارد.

احراز هویت با `user()` هویت Session را از `$_SESSION['uid']` و رکورد تأییدشدهٔ کاربر تعیین می‌کند. مجوز مدیریتی با `require_admin()` جداگانه بررسی می‌شود و فقط نقش مؤثر `admin` را می‌پذیرد؛ نقش مؤثر برای حساب Admin تنها پس از تطبیق شماره با `ADMIN_PHONE` و تأیید رمز هش‌شدهٔ `ADMIN_PASSWORD_HASH` در مسیر `auth/admin` برقرار می‌شود. مسیرهای حساس `dashboard/*` و عملیات POST مدیریتی این گیت سمت‌سرور را اجرا می‌کنند؛ نقش از ورودی کاربر خوانده نمی‌شود. Sessionهای مدیریتی نیز با `admin_verified_uid` به همان حساب متصل می‌مانند و خروج/ورود عادی آن را پاک می‌کند.

برای تعیین حساب Admin، `ADMIN_PHONE` را به شمارهٔ موردنظر و `ADMIN_PASSWORD_HASH` را به خروجی `password_hash` در تنظیمات محیطی سرور بدهید؛ رمز خام نباید در کد یا Frontend قرار گیرد. اگر این دو مقدار تنظیم نشده باشند، مسیر Admin قابل فعال‌سازی نیست. داشبورد عمومی کاربران و فرآیند ثبت‌نام، OTP و Login از این سازوکار جدا و بدون تغییر باقی می‌مانند.

وابستگی خارجی PHP یا Composer در پروژه وجود ندارد. برای افزودن Authentication و قابلیت‌های بعدی، handlerها و منطق در `src/` و لایهٔ داده در `database/` باقی می‌مانند و entry point عمومی فقط bootstrap و dispatch را نگه می‌دارد.
