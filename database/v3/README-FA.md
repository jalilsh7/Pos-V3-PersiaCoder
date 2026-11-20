# دیتابیس تجاری PersiaCoder POS V3

این پوشه متعلق به نسل تجاری V3 است و عمداً از schema و migrationهای runtime فعلی PHP جداست.

## هشدار نصب
- این baseline فقط برای یک دیتابیس تازه و خالی است.
- آن را روی دیتابیس POS فعلی، دیتابیس V2 یا دیتابیس حاوی جدول‌های عملیاتی اجرا نکنید.
- این commit فقط فایل‌ها را در مخزن ثبت می‌کند؛ هیچ دیتابیس واقعی را ایجاد یا تغییر نمی‌دهد.
- پیش از نصب مشتری، نسخه DB، backup قابل بازیابی، دسترسی محدود کاربر DB و محیط staging باید بررسی شود.

## نیازمندی‌ها
- PHP 8.2+ و افزونه PDO MySQL؛ ابزارهای backup/restore به zlib نیز نیاز دارند.
- MySQL 8.0.16+ یا MariaDB 10.6+؛ ابزار اتصال نسخه engine را در زمان اجرا preflight می‌کند.
- InnoDB و utf8mb4؛ timestampها UTC و تبدیل تاریخ شمسی فقط در لایه نمایش.

## فایل‌ها
- schema.sql: baseline ساخت جداول نسخه اول؛ داده آزمایشی ندارد.
- migrations/: برای تغییرات بعد از انتشار baseline؛ در این بسته هنوز migration افزایشی منتشر نشده است.

## روش اجرای کنترل‌شده (فعلاً فقط راهنما)
1. یک دیتابیس کاملاً جدید و اختصاصی برای V3 بسازید.
2. از خالی بودن دیتابیس مقصد اطمینان بگیرید؛ وجود هر جدول عملیاتی باید باعث توقف نصب شود.
3. فایل schema.sql را روی MySQL/MariaDB هدف staging اجرا کنید.
4. خطای هر دستور باید نصب را متوقف کند؛ نصب نیمه‌کاره نباید به‌عنوان نصب موفق علامت‌گذاری شود.
5. پس از اجرای موفق و بررسی همه جداول/FKها، installer باید checksum فایل baseline را در app_schema_migrations ثبت کند.
6. نصب اولیه مدیر و seed نقش‌ها/مجوزهای ثابت باید از installer امن سمت سرور انجام شود؛ رمز یا حساب پیش‌فرض داخل SQL قرار نگیرد.
7. backup بگیرید و بازیابی آن را در دیتابیس خالی دیگری آزمایش کنید.

## قراردادهای مهم
- کلیدهای داخلی BIGINT UNSIGNED هستند؛ public_id برای منابع قابل ارجاع خارجی UUID v4 است.
- مبلغ‌ها integer تومان هستند. کد داخلی IRT در این baseline فقط marker سازگاری مدل فعلی برای تومان است و نباید به‌عنوان مقدار ریال ISO تعبیر شود؛ adapter پرداخت باید واحد PSP را تبدیل و اعتبارسنجی کند.
- مقدارهای انبار DECIMAL هستند و منفی‌شدن موجودی در نسخه اول مجاز نیست.
- event/ledgerها append-only طراحی شده‌اند؛ اصلاح با reversal/adjustment جدید انجام می‌شود.
- این فایل baseline اولیه است؛ migration runner، checksum verification، rollback/retry، CI روی MariaDB، backup/restore و race tests هنوز باید پیاده‌سازی و اجرا شوند.

## محدودیت‌های این بسته
این schema یک قرارداد اولیه اجرایی است اما هنوز به runtime PHP متصل نشده است. API، repository layer، installer، migration runner و UI در بسته‌های بعدی ساخته خواهند شد. اجرای مستقیم روی محیط اصلی ممنوع است.


## ابزار نصب و migration در V3

ابزارهای CLI مستقل از runtime فعلی V2 در مسیر database/v3/bin قرار دارند:

- install.php: فقط روی دیتابیس خالی اجرا می‌شود؛ وجود هر جدول یا view باعث توقف قبل از تغییر می‌شود.
- migrate.php: migrationهای افزایشی را به ترتیب نام فایل اجرا می‌کند؛ checksum هر نسخه در app_schema_migrations ثبت می‌شود.
- bootstrap.php: اتصال PDO، parser فایل SQL و قفل advisory مشترک.

نمونه تنظیم متغیرها (مقادیر واقعی را در shell امن خود وارد کنید و در مخزن commit نکنید):

- POS_V3_DB_HOST، POS_V3_DB_PORT، POS_V3_DB_NAME، POS_V3_DB_USER، POS_V3_DB_PASSWORD
- POS_V3_ADMIN_USERNAME، POS_V3_ADMIN_PASSWORD، POS_V3_ADMIN_NAME
- POS_V3_BACKUP_HMAC_KEY (کلید محرمانه حداقل ۳۲ بایت برای امضای اصالت backup؛ برای restore همان کلید لازم است)

رمز اولیه مدیر باید حداقل ۱۴ کاراکتر باشد؛ رمز به صورت hash ذخیره می‌شود و تغییر رمز در اولین ورود اجباری است. اجرای installer از HTTP مسدود است و فعلاً فقط CLI دارد.

دستورات از ریشه مخزن:
    php database/v3/bin/install.php
    php database/v3/bin/migrate.php

Baseline پس از نصب immutable است. تغییرات بعدی فقط با migration جدید انجام می‌شوند. قفل GET_LOCK اجرای هم‌زمان installer/migration را serialize می‌کند. از آنجا که DDL در MySQL/MariaDB اغلب implicit commit دارد، شکست وسط اجرای baseline یا migration می‌تواند دیتابیس نیمه‌ساخته باقی بگذارد؛ recovery باید روی دیتابیس disposable با ساخت مجدد دیتابیس انجام شود و هرگز نباید اسکریپت برای پاک‌سازی خودکار دیتابیس مشتری طراحی شود.

## وضعیت اعتبارسنجی

workflow جداگانه v3-database-validation.yml برای MySQL 8.4 و MariaDB 10.6 ایجاد شده است. این workflow نصب baseline، کنترل metadata و CHECK/FK/unique، نصب مدیر اولیه و اجرای دوباره migration runner را در دیتابیس‌های disposable آزمایش می‌کند.

در زمان ثبت این یادداشت، GitHub Actions jobها قبل از اجرای stepها fail شده‌اند و runner_id صفر بوده است؛ بنابراین نتیجه واقعی engine هنوز تأیید نشده است. تست محلی MySQL/MariaDB نیز در محیط توسعه در دسترس نبود.

## پشتیبان‌گیری و بازیابی CLI

- ابتدا یک کلید مستقل و تصادفی بسازید: `openssl rand -hex 32`؛ آن را در secret manager یا environment امن نگهداری کنید و هرگز در Git، log یا فایل backup قرار ندهید.
- پیش از هر دو دستور backup و restore مقدار `POS_V3_BACKUP_HMAC_KEY` را با همان کلید امن تنظیم کنید؛ حداقل ۳۲ بایت لازم است.
- تهیه نسخه پشتیبان: `php database/v3/bin/backup.php /secure/path/pos-v3-backup.jsonl.gz`
- بازیابی فقط روی دیتابیس کاملاً خالی: `php database/v3/bin/restore.php /secure/path/pos-v3-backup.jsonl.gz`
- فرمت نسخه پشتیبان JSON Lines فشرده با gzip است؛ ساختار جدول‌ها و داده‌ها را ثبت می‌کند، ستون‌های generated را دوباره محاسبه می‌کند و هنگام بازیابی تعداد ردیف‌ها و روابط FK را بررسی می‌کند.
- فایل خروجی با مجوز 0600 ساخته می‌شود و مسیر داخل php-backend/public رد می‌شود. مسیر پشتیبان باید خارج از web root و ترجیحاً روی فضای جداگانه باشد.
- همه رکوردهای آرشیو با HMAC-SHA256 احراز اصالت می‌شوند؛ restore قبل از اجرای هر DDL یا تغییر مقصد، امضا را کامل بررسی می‌کند و آرشیو ناقص، دست‌کاری‌شده یا دارای کلید متفاوت را رد می‌کند. کلید HMAC باید جدا از فایل backup نگهداری و در restore نیز در دسترس باشد.
- بازیابی فقط بین دو سرور از یک خانواده engine (MySQL به MySQL یا MariaDB به MariaDB) مجاز است. مقصد باید خالی باشد. شکست در میانه restore دیتابیس نیمه‌ساخته باقی می‌گذارد؛ مقصد disposable را حذف و از نو ایجاد کنید.
- archive به‌صورت پیش‌فرض رمزنگاری نمی‌شود و ممکن است داده مشتری/فروش داشته باشد؛ آن را خارج از سرور و بدون رمزنگاری ذخیره نکنید. رمزنگاری و انتقال امن باید در لایه storage/ops انجام شود.

## موارد باقی‌مانده فاز ۲

- اجرای موفق CI روی هر دو engine و رفع هر خطای واقعی schema/parser/installer/backup/restore.
- preflight سازگاری نسخه PHP/MySQL و مجوزهای دیتابیس.
- installer گرافیکی امن و اتصال آن به runtime V3؛ ابزار فعلی CLI است و هنوز به API/محصول متصل نشده است.
- پذیرش نهایی نصب تازه، نصب مجدد، شکست نیمه‌کاره و recovery.


## پیش‌بررسی نصب (Preflight)

پیش از اجرای installer، دستور زیر را با همان environment اتصال دیتابیس اجرا کنید:

    php database/v3/bin/preflight.php

این فرمان فقط بررسی می‌کند و هیچ schema یا داده‌ای را تغییر نمی‌دهد:
- PHP 8.2 یا جدیدتر؛
- افزونه‌های PDO MySQL، JSON و zlib؛
- اتصال و نسخه موتور دیتابیس مطابق قرارداد V3؛
- charset پیش‌فرض دیتابیس برابر utf8mb4؛
- خالی بودن کامل دیتابیس مقصد؛
- قابل‌خواندن بودن baseline و محاسبه checksum آن.

اگر هر بررسی شکست بخورد، فرمان با exit code غیرصفر متوقف می‌شود و جزئیات را بدون چاپ رمز عبور نشان می‌دهد. موفقیت preflight به‌تنهایی تضمین‌کننده مجوزهای کامل DDL، تنظیمات وب‌سرور، مسیرهای writable یا آمادگی عملیاتی cPanel نیست؛ این موارد باید در مرحله installer گرافیکی و پذیرش staging بررسی شوند. این فرمان را روی دیتابیس حاوی داده اجرا نکنید و خروجی PASS را معادل نصب موفق تلقی نکنید.


## اجرای تست مستقل روی Windows (بدون Docker و بدون GitHub Actions)

برای بررسی‌های محلی، PowerShell 5.1 یا جدیدتر، PHP CLI نسخه 8.2+ با افزونه‌های PDO، PDO MySQL، JSON و Zlib و Python 3 لازم است. برای تست‌های دیتابیسی، MySQL 8.0.16+ یا MariaDB 10.6+ باید به‌صورت سرویس محلی نصب و اجرا شده باشد؛ Docker لازم نیست.

از ریشه مخزن در PowerShell اجرا کنید:

    Set-ExecutionPolicy -Scope Process Bypass
    .\scripts\run-v3-local-ci.ps1

این حالت PHP lint همه ابزارهای V3 و checkerها، تست SQL parser و compile اسکریپت Python را اجرا می‌کند و هیچ دیتابیسی را تغییر نمی‌دهد.

برای اجرای بررسی دیتابیس، ابتدا دو دیتابیس آزمایشی مستقل و disposable بسازید:
- دیتابیس اول باید baseline کامل ۶۱ جدولی V3 را داشته باشد و فقط برای MYSQL_TEST_* استفاده شود.
- دیتابیس دوم باید کاملاً خالی باشد و فقط برای POS_V3_* و Preflight استفاده شود.
- هرگز نام دیتابیس عملیاتی، V2 یا دیتابیس دارای اطلاعات مشتری را تنظیم نکنید.

سپس متغیرهای MYSQL_TEST_HOST, MYSQL_TEST_PORT, MYSQL_TEST_DATABASE, MYSQL_TEST_USERNAME, MYSQL_TEST_PASSWORD و POS_V3_DB_HOST, POS_V3_DB_PORT, POS_V3_DB_NAME, POS_V3_DB_USER, POS_V3_DB_PASSWORD را در نشست PowerShell تنظیم کنید و اجرا کنید:

    $env:MYSQL_TEST_HOST = '127.0.0.1'
    $env:MYSQL_TEST_PORT = '3306'
    $env:MYSQL_TEST_DATABASE = 'pos_v3_schema_ci'
    $env:MYSQL_TEST_USERNAME = 'pos_v3_test'
    $env:MYSQL_TEST_PASSWORD = '...'
    $env:POS_V3_DB_HOST = '127.0.0.1'
    $env:POS_V3_DB_PORT = '3306'
    $env:POS_V3_DB_NAME = 'pos_v3_empty_ci'
    $env:POS_V3_DB_USER = 'pos_v3_test'
    $env:POS_V3_DB_PASSWORD = '...'
    $env:POS_V3_ADMIN_USERNAME = 'ci-owner'
    $env:POS_V3_ADMIN_PASSWORD = 'یک-رمز-آزمایشی-حداقل-۱۴-کاراکتری'
    $env:POS_V3_ADMIN_NAME = 'Local CI Owner'
    .\scripts\run-v3-local-ci.ps1 -WithDatabase

حالت دیتابیسی فقط Preflight را روی دیتابیس خالی و checker قیود را روی دیتابیس schema از پیش آماده‌شده اجرا می‌کند. برای جلوگیری از حذف یا بازنویسی داده، Installer، migration و restore به‌صورت خودکار اجرا نمی‌شوند و باید در دیتابیس disposable جداگانه و با فرمان‌های CLI مستندشده آزمایش شوند.

این مسیر مستقل از GitHub Actions است؛ خروجی PASS فقط همان بررسی‌هایی را تأیید می‌کند که واقعاً روی سیستم محلی اجرا شده‌اند. نتیجه محلی به‌طور خودکار در GitHub ثبت یا منتشر نمی‌شود.
