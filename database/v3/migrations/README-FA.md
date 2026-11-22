# Migrationهای افزایشی V3

این پوشه فقط برای تغییرات پس از baseline منتشرشده V3 است.

## قرارداد نام‌گذاری
- نام فایل: NNN_description-with-hyphens.sql؛ شماره حداقل سه رقم و یکتا.
- فایل migration پس از انتشار/اعمال روی هر محیط immutable است.
- اصلاح migration اعمال‌شده ممنوع است؛ اصلاح بعدی باید migration جدید باشد.
- checksum SHA-256 هر فایل هنگام اجرا در app_schema_migrations ثبت می‌شود.
- تغییر checksum نسخه‌ای که قبلاً اعمال شده باشد باعث توقف runner می‌شود.
- اجرای هم‌زمان با قفل advisory دیتابیس serialize می‌شود.

## اجرا
متغیرهای محیطی POS_V3_DB_HOST، POS_V3_DB_PORT، POS_V3_DB_NAME، POS_V3_DB_USER و POS_V3_DB_PASSWORD را تنظیم کنید و از ریشه مخزن اجرا کنید:

    php database/v3/bin/migrate.php

Baseline با php database/v3/bin/install.php فقط روی دیتابیس خالی نصب می‌شود. فایل baseline بعد از نصب نباید تغییر کند؛ تمام تغییرات بعدی migration افزایشی هستند.

## محدودیت تراکنش
MySQL/MariaDB برای بسیاری از دستورات DDL implicit commit انجام می‌دهند. قفل runner از اجرای هم‌زمان جلوگیری می‌کند، اما شکست وسط یک migration لزوماً rollback کامل ندارد. هر migration باید کوچک، قابل بازبینی و دارای روش recovery مستند باشد؛ پس از شکست جزئی، runner را کورکورانه تکرار نکنید.


## Migration 001 — نشست‌های کاربری
- فایل `001_create-user-sessions.sql` جدول افزایشی `app_user_sessions` را ایجاد می‌کند.
- فقط hash شناسه نشست و hash توکن CSRF ذخیره می‌شوند؛ مقدار خام token در دیتابیس قرار نمی‌گیرد.
- نشست به کاربر FK دارد، session_version را snapshot می‌کند، expiry و revoke دارد و برای جست‌وجوی نشست فعال/پاکسازی انقضا index دارد.
- این migration فقط در repository ثبت شده و روی هیچ دیتابیسی اجرا نشده است.
- پس از انتشار، فایل immutable است؛ اصلاحات بعدی باید با migration جدید انجام شوند.


## Migration 002 — محدودسازی تلاش ورود
- فایل `002_create-auth-login-attempts.sql` جدول `app_auth_login_attempts` را برای شمارش تلاش‌های ناموفق و قفل موقت ایجاد می‌کند.
- دو bucket مستقل با HMAC از نام کاربری و IP نگهداری می‌شوند؛ کلید HMAC از متغیر محرمانه `POS_V3_APP_KEY` می‌آید و شناسه خام در جدول ذخیره نمی‌شود.
- سیاست فعلی: پنج تلاش ناموفق برای هر حساب یا ۳۰ تلاش از هر IP در پنجره ۱۵ دقیقه‌ای، سپس محدودسازی همان bucket برای ۱۵ دقیقه.
- این migration نیز فقط ثبت شده و هنوز اجرا نشده است.


## Migration 004 — idempotency
- فایل `004_create-idempotency-keys.sql` جدول کلیدهای idempotency را با scope actor/business/branch/endpoint ایجاد می‌کند.
- کلید خام ذخیره نمی‌شود؛ hash کلید و fingerprint درخواست نگهداری می‌شوند. پاسخ موفق با AES-256-GCM رمز می‌شود و به `POS_V3_APP_KEY` وابسته است.
- تمام writeهای دامنه و ثبت پاسخ باید در transaction واحد انجام شوند. Migration فقط ثبت شده و اجرا نشده است.

## Migration 005 — business/branch audit scope
- فایل `005_add-audit-business-scope.sql` فیلدهای business_id و branch_id را به audit اضافه می‌کند.
- FK مرکب تضمین می‌کند branch متعلق به business ثبت‌شده باشد. migration فقط ثبت شده و اجرا نشده است.


## Migration 006 — business scope برای کاتالوگ و مشتری
- فایل `006_scope-catalog-and-customers.sql` جدول‌های دسته، محصول، تنوع، گروه/گزینه و مشتری را به کسب‌وکار متصل می‌کند.
- دسته‌ها public UUID می‌گیرند؛ FK مرکب مانع اتصال محصول/تنوع/گزینه به رکورد کسب‌وکار دیگر می‌شود.
- SKU محصول در محدوده business یکتا می‌شود و قید unique سراسری قبلی جایگزین می‌شود.
- این migration برای دیتابیس تازه و پیش از ایجاد داده عملیاتی طراحی شده است؛ فقط ثبت شده و اجرا نشده است.


## Migration 007 — تنوع‌ها و گزینه‌های کاتالوگ
- فایل `007_catalog-variants-and-options.sql` برای تنوع محصول، گروه افزودنی و گزینه‌ها شناسه عمومی UUID و indexهای کسب‌وکار اضافه می‌کند؛ اگر رکورد قبلی وجود داشته باشد، UUID برای آن backfill و سپس ستون اجباری می‌شود.
- `is_required_default` مقدار پیش‌فرض اجباری بودن گروه گزینه است؛ اتصال به محصول همچنان مقدار `is_required` مستقل دارد.
- migration فقط ثبت شده و اجرا نشده است.


## Migration 008 — سفارش‌ها و محدوده شعبه
- فایل 008_scope-orders-and-service-areas.sql جداول order، item، item option، status event، fulfillment، preparation، adjustment، dining area/table و table session را به business و branch متصل می‌کند.
- FKهای مرکب، تعلق سفارش و اقلام آن به همان business/branch را enforce می‌کنند؛ رابطه مشتری/محصول/تنوع/گزینه نیز در محدوده business کنترل می‌شود.
- order number از sequence مستقل برای هر business و branch ساخته می‌شود؛ order number در محدوده شعبه یکتا است.
- برای سالن، میز و ایستگاه آماده‌سازی public UUID افزوده می‌شود.
- این migration برای دیتابیس تازه و قبل از ایجاد داده عملیاتی طراحی شده است؛ فقط ثبت شده و اجرا نشده است.



## Migration 010 — رسانه خصوصی کاتالوگ
- فایل `010_private-media-assets.sql` جدول metadata رسانه را با scope کسب‌وکار، شناسه عمومی، MIME واقعی، اندازه، ابعاد، SHA-256 و uploader ایجاد می‌کند.
- ارتباط یک‌به‌یک فعلی تصویر اصلی محصول و دسته‌بندی در جدول‌های جداگانه نگهداری می‌شود؛ فایل رسانه در جدول metadata می‌ماند و با تعویض تصویر حذف فیزیکی نمی‌شود.
- فایل‌ها در مسیر خصوصی خارج از document root ذخیره می‌شوند؛ هیچ مسیر فایل از سمت client پذیرفته نمی‌شود.
- MIME فقط JPEG/PNG/WebP است؛ SVG و فایل‌های اجرایی پذیرفته نمی‌شوند. سقف پیش‌فرض ۵ MiB و حداکثر قابل تنظیم ۲۰ MiB است.
- تنظیمات: `POS_V3_PRIVATE_STORAGE_PATH` (مسیر مطلق خارج از public root) و `POS_V3_MEDIA_MAX_BYTES` (بازه ۱ KiB تا ۲۰ MiB). برای upload، PHP باید `fileinfo` فعال و `upload_max_filesize` و `post_max_size` متناسب داشته باشد.
- migration فقط در مخزن ثبت شده و اجرا نشده است.


## Migration 011 — مالکیت انبار و رسپی
- فایل `011_scope-inventory-and-recipes.sql` اقلام انبار و تامین‌کنندگان را business-scoped و مکان‌ها، اسناد، گردش‌ها، مانده‌ها و رزروها را business/branch-scoped می‌کند.
- FKهای مرکب جلوی اتصال سند/گردش/رزرو به کالا، مکان، سفارش یا شعبه کسب‌وکار دیگر را می‌گیرند؛ واحدها و تبدیل‌های استاندارد واحد به‌عنوان reference data مشترک باقی می‌مانند.
- رسپی و نسخه‌های رسپی شناسه عمومی UUID و business scope می‌گیرند؛ variant اختیاری باید متعلق به همان product باشد. برای هر محصول/تنوع حداکثر یک رکورد recipe تعریف می‌شود و تغییر فرمول با نسخه جدید انجام می‌گیرد.
- واحدهای مرجع mg/g/kg، ml/l و pcs و تبدیل‌های استاندارد پایه seed می‌شوند. ضریب تبدیل در هر recipe line snapshot می‌شود تا تغییرات بعدی تبدیل، نسخه‌های قبلی را تغییر ندهد.
- migration برای نصب تازه و پیش از ورود داده عملیاتی طراحی شده است؛ به علت ستون‌های NOT NULL و تغییر FKها، برای دیتابیس دارای داده نیازمند طرح backfill جداگانه است. فقط ثبت شده و اجرا نشده است.

## Migration 013 — صف رمزگذاری‌شده پیامک

- فایل 013_sms-outbox-and-delivery-attempts.sql جدول outbox پیامک‌های transactional/verification و delivery attempts را business-scoped ایجاد می‌کند.
- متن و شماره مقصد به‌صورت ciphertext با nonce/tag جدا ذخیره می‌شوند؛ recipient_hash و message_hash برای بررسی یکپارچگی و بدون نگهداری مقدار خام هستند.
- شناسه provider، وضعیت ارسال/تحویل، attempt count، زمان retry و delivery check ذخیره می‌شود.
- پیام تبلیغاتی در enum این نسخه وجود ندارد؛ تا زمان پیاده‌سازی consent/opt-out ارسال marketing مجاز نیست.
- این migration فقط در مخزن ثبت شده؛ اجرای واقعی آن باید از DB validation workflow روی MySQL و MariaDB تأیید شود.


## Migration 014 — شاخص صفحه‌بندی مشتریان

- شاخص idx_customers_business_updated_id روی (business_id, updated_at, id) برای keyset pagination فهرست مشتریان اضافه می‌شود.
- ترتیب پایدار فهرست بر اساس updated_at DESC و id DESC است.
- این migration صرفاً بهینه‌سازی خواندن است و داده مشتری را تغییر نمی‌دهد.


## Migration 015 — مجوز مشاهده صف پیامک

- فایل `015_sms-outbox-read-permission.sql` مجوز granular با کد `communications.read` را ایجاد و به نقش سیستمی manager متصل می‌کند.
- این مجوز فقط دسترسی خواندن صف پیامک و تاریخچه تحویل را می‌دهد؛ عملیات ارسال، retry یا مدیریت تنظیمات provider را اعطا نمی‌کند.
- دسترسی installation owner از wildcard موجود RBAC پیروی می‌کند.
- migration فقط در مخزن ثبت شده و هنوز اجرای DB validation برای آن انجام نشده است.

## Migration 019 — مرجع سفارش در دفتر گردش انبار
- فایل `019_stock-ledger-order-reference.sql` ستون nullable `source_order_id` را به `pos_stock_ledger` اضافه می‌کند تا خروج مصرف ناشی از سفارش مستقیماً به سفارش مبدأ متصل باشد.
- FK مرکب روی `business_id, branch_id, source_order_id` مانع اتصال گردش به سفارش شعبه یا کسب‌وکار دیگر می‌شود؛ برای گردش‌های مبتنی بر سند، این ستون NULL باقی می‌ماند.
- migration برای نصب تازه از طریق migration runner اجرا می‌شود؛ اجرای واقعی روی MySQL/MariaDB هنوز باید در workflow دستی تأیید شود.

### Migration 022 — شواهد رضایت مشتری
- جدول `pos_customer_consent_events` رخدادهای append-only رضایت/انصراف بازاریابی را ذخیره می‌کند؛ وضعیت جاری از آخرین رخداد به‌دست می‌آید.
- FK مرکب business/customer مانع اتصال رضایت مشتری به کسب‌وکار دیگر می‌شود؛ `event_key` در محدوده business یکتا است.
- grant نیازمند `notice_version` است و import مجاز به ایجاد grant نیست. permission `customers.consent.manage` برای manager seed می‌شود.
- این migration به‌تنهایی مجوز ارسال تبلیغاتی ایجاد نمی‌کند؛ تا تکمیل API و suppression در مسیر dispatch، ارسال marketing باید غیرفعال بماند.
- اجرای migration روی MySQL/MariaDB هنوز گزارش نشده است.

### وضعیت تکمیلی Migration 022 — ثبت رضایت CRM
- API ثبت grant/withdraw در CRM به جدول رخدادها متصل شده است؛ grant به شماره معتبر، notice_version و evidence_ref نیاز دارد و هر تغییر با actor، idempotency و audit ثبت می‌شود.
- Customer detail آخرین وضعیت و تاریخچه رخدادها را نمایش می‌دهد؛ نبود رخداد برابر `unknown` است.
- این migration هنوز در محیط MySQL/MariaDB اجرا نشده است. وجود API ثبت رضایت، marketing dispatch را فعال نمی‌کند؛ suppression در مسیر ارسال هنوز لازم است.

- هر grant علاوه بر notice_version و evidence_ref، شماره موبایل نرمال‌شده زمان ثبت را در `recipient_phone_normalized` نگهداری می‌کند؛ تغییر شماره مشتری باعث stale شدن رضایت قبلی در read model می‌شود. این migration هنوز اجرا نشده است.

- مقدار `invalidated` برای بی‌اعتبارکردن grant قبلی پس از تغییر شماره پشتیبانی می‌شود و با `withdrawn` (انصراف مشتری) تفاوت دارد. بازگرداندن شماره قدیمی، grant قبلی را احیا نمی‌کند.
- grant فقط برای مشتری فعال دارای شماره معتبر پذیرفته می‌شود؛ مشتری غیرفعال یا بدون موبایل نمی‌تواند رضایت SMS marketing جدید ثبت کند.

### وضعیت اجرایی برگشت سند انبار — INV-RETURN-2026-001 (2026-11-22)
- Migration 021 ستون‌های nullable `source_document_id` و `source_document_line_id` و FK مرکب business/branch را فراهم می‌کند؛ سابقه‌های قدیمی بدون حدس‌زدن NULL می‌مانند.
- Backend از این مراجع برای برگشت جزئی سندهای خروجی posted (adjustment_out، consumption، waste) استفاده می‌کند. بهای برگشت از ledger تاریخی همان source line است و posting با قفل سند مبدأ از over-return جلوگیری می‌کند.
- Migration 021 در این نشست اجرا نشده و DB integration نیز اجرا نشده است. migration جدیدی برای این بسته لازم نشد.


### Migration 023 — زیرساخت کمپین پیامکی بازاریابی

- دو جدول business-scoped برای کمپین و snapshot مخاطبان ایجاد می‌شود؛ FK مرکب از اتصال مشتری/کمپین به کسب‌وکار دیگر جلوگیری می‌کند.
- unique (business_id, campaign_id, customer_id) از تکرار مخاطب در یک کمپین جلوگیری می‌کند.
- consent_event_id فقط به رخداد رضایت مرجع اشاره می‌کند؛ worker آینده باید آخرین رخداد، وضعیت فعال مشتری و تطابق شماره فعلی با شماره زمان grant را درست پیش از ارسال مجدداً بررسی کند.
- شماره خام مشتری در این دو جدول ذخیره نمی‌شود. وضعیت اولیه کمپین draft است و migration به‌تنهایی هیچ ارسال تبلیغاتی را فعال نمی‌کند.
- Static contract guard: scripts/check-v3-sms-marketing-campaign-schema.php.
- migration در این محیط اجرا نشده است؛ سازگاری واقعی MySQL 8.0.16+ و MariaDB 10.6 باید در DB validation تأیید شود.
