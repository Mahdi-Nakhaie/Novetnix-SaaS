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

وابستگی خارجی PHP یا Composer در پروژه وجود ندارد. برای افزودن Authentication و قابلیت‌های بعدی، handlerها و منطق در `src/` و لایهٔ داده در `database/` باقی می‌مانند و entry point عمومی فقط bootstrap و dispatch را نگه می‌دارد.
