# معماری فنی مادر — PersiaCoder POS V3
- شناسه: ARCH-MASTER-2026-001
- تاریخ: 2026-11-20
- وضعیت: **در حال انجام — نسخه پایه طراحی**
- فاز رسمی: 1؛ بسته‌های وابسته در فازهای 2 تا 12
- مبنای شروع: `4f3d79f3ac7331d0258020300615eb520a2bb508`

> این سند چارچوب معماری را تعریف می‌کند؛ جایگزین تطبیق با کد، ERD نهایی، DDL، OpenAPI یا تست پذیرش نیست. هر مورد نامشخص در بخش پرسش‌های باز باقی می‌ماند.

## 1. هدف و اصول
PersiaCoder POS V3 یک محصول تجاری یکپارچه برای کسب‌وکارهای غذا و نوشیدنی است. چهار ستون آن POS و عملیات، مالی عملیاتی، فروشگاه آنلاین با قالب عمومی منودار، و مشتری/ارتباطات پیامکی است. همه کانال‌ها باید از هسته مشترک سفارش، کاتالوگ، قیمت، موجودی، مشتری و پرداخت استفاده کنند.
- یک هسته دامنه مشترک و ماژول‌های مستقل با مرز روشن.
- دیتابیس عملیاتی مستقل برای هر مشتری؛ Central فاقد داده عملیاتی مشتری.
- Backend منبع حقیقت قیمت، مالیات، مجوز، موجودی و پرداخت.
- عملیات مالی/انبار/سفارش تراکنشی، قابل ممیزی و idempotent.
- جداسازی UI از منطق تجاری و providerهای خارجی.
- فارسی/RTL، واکنش‌گرا، ساده و سریع؛ برند ثابت PersiaCoder.ir.
- runtime نسخه V3 مستقل از V2؛ legacy فقط مرجع اقتباس است.

## 2. سبک معماری
**Modular Monolith** سبک پایه پیشنهادی برای نسخه تجاری اولیه است: یک Backend قابل استقرار با ماژول‌های دامنه‌ای مجزا، قراردادهای داخلی روشن و مالکیت داده مشخص. این سبک پیچیدگی عملیاتی microservices را در شروع تحمیل نمی‌کند و با حفظ مرزها امکان استخراج سرویس در آینده را باز می‌گذارد. دسترسی مستقیم و بی‌قاعده ماژول‌ها به جداول یکدیگر مجاز نیست؛ ارتباط از application service/contract و event انجام می‌شود.

## 3. لایه‌ها
1. Presentation: React + TypeScript؛ POS، مدیریت، Storefront و KDS روی API مشترک.
2. API/Transport: REST نسخه‌دار، auth، CSRF، validation، serialization و error mapping.
3. Application: use-case، orchestration، authorization policy، transaction boundary و idempotency.
4. Domain: قواعد سفارش، پرداخت، صندوق، کاتالوگ، موجودی، رسپی، مشتری، وفاداری و پیامک.
5. Infrastructure: PDO/repository، MySQL/MariaDB، queue، فایل، logging و provider adapters.
6. Operations: installer، migration، backup/restore، observability، licensing client و update integrity.

Backend موجود PHP 8.2+ و PDO/MySQL و Frontend React/TypeScript/Vite است؛ تغییر فناوری پایه نیازمند دلیل و ثبت تصمیم است.

## 4. مرز ماژول‌ها و مالکیت
| ماژول | مسئولیت/منبع حقیقت |
|---|---|
| Identity & Access | کاربران، نشست، نقش، مجوز و scope |
| Business Configuration | کسب‌وکار، شعبه، timezone، تنظیمات و ساعات کاری |
| Catalog | محصول، دسته، تنوع، افزودنی و قیمت |
| Orders / POS | سفارش، ردیف، کانال، وضعیت و fulfillment |
| Inventory & Recipes | مواد، واحد، مکان، رسپی نسخه‌دار، رزرو و ledger |
| Payments | تراکنش، provider reference، callback، refund و reconciliation |
| Cash & Finance | صندوق، شیفت، گردش نقدی و مالی عملیاتی |
| Customers & Loyalty | پروفایل، سابقه، رضایت و دفتر وفاداری |
| Communications | قالب، رویداد پیامک، صف، retry، delivery و opt-out |
| Storefront | لایه نمایش عمومی؛ بدون منطق سفارش/دیتابیس مستقل |
| Kitchen & Fulfillment | ایستگاه، آماده‌سازی، pickup و تحویل |
| Reporting | read model؛ بدون تغییر منبع داده مرجع |
| Integrations | adapter پیامک، درگاه، کارتخوان، چاپگر |
| Central Control Plane | نصب، لایسنس، پلن، entitlement، نسخه و update |

این مرزبندی هدف طراحی است؛ وجود جداول یا UI فعلی به معنی تکمیل ماژول نیست.

## 5. استقرار و Central
- هر مشتری دیتابیس عملیاتی مستقل دارد؛ جزئیات business/branch و tenancy باید با schema جاری تطبیق داده شود.
- استقرار روی سرور مشتری یا زیرساخت مدیریت‌شده.
- Central فقط control plane شامل شناسه نصب، لایسنس، پلن، entitlement، نسخه و metadata حداقلی است؛ سفارش، فروش، مشتری، پرداخت عملیاتی و موجودی به Central ارسال نمی‌شود.
- ارتباط نصب و Central احراز هویت‌شده، امضاشده، محدود و auditپذیر باشد.
- کلید خصوصی صدور لایسنس فقط در Central؛ نصب مشتری فقط کلید/داده عمومی اعتبارسنجی.
- تعداد نصب، انتقال، grace period، تعلیق و مدت اعتبار آفلاین هنوز نیازمند مشخصات دقیق است.

## 6. جریان‌های اصلی
### POS حضوری
POS → اعتبارسنجی سروری کاتالوگ/قیمت/مجوز → ایجاد سفارش idempotent → snapshot اقلام و رسپی → رزرو/مصرف موجودی طبق سیاست → ثبت پرداخت موفق → اثر نقدی فقط برای وجه نقد → وضعیت و fulfillment → رویداد مشتری/پیامک → گزارش.

### Storefront
قالب عمومی → API منو و availability → سبد غیرقابل اعتماد سمت کاربر → محاسبه مجدد checkout در سرور → رزرو موقت و سفارش awaiting_payment → provider → callback معتبر/استعلام سرور و reconciliation → تأیید یا تعیین تکلیف نتیجه مبهم → مرکز سفارش POS و آشپزخانه/تحویل. قالب فقط presentation/config است؛ سفارش و داده مستقل ندارد.

### SMS
رویداد دامنه → template/version → بررسی purpose، consent و opt-out → outbox/queue با dedupe → provider adapter → ذخیره شناسه پیام → delivery tracking → گزارش. خطای پیامک نباید سفارش یا پرداخت را rollback کند؛ پیام تبلیغاتی و تراکنشی policy جدا دارند.

## 7. رویداد و صف
رویداد نسخه‌دار دارای event_id، occurred_at، business/branch scope، aggregate_id و correlation_id باشد. نمونه: OrderPlaced، PaymentSucceeded، PaymentRefunded، StockReserved، StockConsumed، CustomerConsentChanged، SmsDeliveryUpdated.
- transactional outbox برای انتشار قابل اعتماد در نظر گرفته شود.
- workerها at-least-once و consumerها idempotent باشند.
- retry با backoff، سقف تلاش، failed/dead-letter و replay کنترل‌شده لازم است.
- timeout provider نتیجه نامعلوم است؛ نه موفقیت و نه شکست قطعی.
- عملیات خارجی داخل تراکنش DB اجرا نشود.

## 8. قرارداد API
- مسیر پایه `/api/v1` و JSON با خطاهای قابل پیش‌بینی.
- هر endpoint: authentication، مجوز سمت سرور، business/branch scope، validation و audit متناسب.
- عملیات تغییر سفارش/پرداخت/انبار دارای idempotency key و پاسخ قابل بازیابی.
- قیمت/جمع کل از کلاینت پذیرفته نمی‌شود؛ سرور محاسبه و snapshot می‌کند.
- pagination و timezone قراردادهای مشترک و مستند داشته باشند.
- OpenAPI endpoint به endpoint هنوز باید تکمیل شود؛ این سند جایگزین آن نیست.

## 9. امنیت و حریم داده
username/password برای همه کاربران؛ نشست امن سمت سرور؛ cookie امن، CSRF/same-origin، rate limit؛ RBAC granular با scope؛ prepared statements و validation؛ audit بدون secrets؛ secrets خارج repository/frontend؛ callback پرداخت با امضا/استعلام provider؛ ledgerهای مالی و انبار append-only و اصلاح با reversal؛ backup رمزنگاری‌شده و integrity check. threat model و تست امنیتی در gate فازهای 3 و 13 تکمیل می‌شود.

## 10. آفلاین
Specification نهایی آفلاین باز است: عملیات مجاز، مدت اعتبار قابل تنظیم، داده محلی حداقلی و رمزگذاری‌شده، install/device identity، صف عملیات، idempotency، ترتیب و حل تعارض باید تعریف شوند. تعارض مالی با last-write-wins حل نمی‌شود؛ عملیات بدون مرجع معتبر ممکن است pending یا محدود بماند. مدت ثابت آفلاین مصوب نیست.

## 11. عملیات و فناوری
PHP 8.2+ / PDO، MySQL یا MariaDB، React/TypeScript/Vite، REST/JSON و adapter برای providerها. حداقل نسخه DB باید با هاست‌های هدف تثبیت شود. installer باید preflight، migration نسخه‌دار، config امن، health check، backup/restore و rollback داشته باشد. update با artifact امضاشده، manifest، hash و سازگاری migration منتشر شود.

## 12. بسته‌های تکمیل طراحی
| بسته | خروجی | وضعیت |
|---|---|---|
| ARCH-01 | مرز ماژول، سبک معماری، استقرار و جریان‌ها | نسخه پایه ثبت شد؛ بازبینی ادامه دارد |
| ARCH-02 | تطبیق مدل دامنه و ERD با schema/migrations و کد | در انتظار |
| ARCH-03 | API contracts و OpenAPI | در انتظار |
| ARCH-04 | event catalog، outbox، queue و idempotency | در انتظار |
| ARCH-05 | tenancy، Central، license و offline sync | در انتظار |
| ARCH-06 | threat model، secrets، update integrity و backup | در انتظار |

## 13. پرسش‌های باز
1. حداقل نسخه عملیاتی MySQL/MariaDB و محدودیت هاست.
2. مدل شعبه و چندکسب‌وکاری در یک نصب.
3. سیاست موجودی منفی، رزرو POS و تبدیل رزرو به مصرف.
4. سفارش در ساعات بسته، پیش‌سفارش و زمان‌بندی دریافت.
5. مدل مالیات/ارز و دامنه حسابداری رسمی نسخه اول.
6. split payment، اضافه‌پرداخت، timeout و terminal reconciliation.
7. تعداد نصب مجاز، شناسه نصب/دستگاه و سیاست انتقال/تمدید/تعلیق.
8. عملیات آفلاین و حل تعارض.
9. retention، حذف/ناشناس‌سازی داده و backup.
10. providerها و سخت‌افزارهای هدف نسخه اول.

## 14. معیار پذیرش معماری
مرز و مالکیت هر ماژول روشن؛ کانال‌ها بدون دیتابیس سفارش جدا؛ سفارش/پرداخت/انبار/پیامک idempotent و auditپذیر؛ Central بدون داده عملیاتی؛ پوشش دو مدل استقرار؛ تعریف نیازهای آفلاین، امنیت، backup و update؛ تطبیق ERD/API/event با کد و migration واقعی و ثبت تعارض‌ها.

---
**PersiaCoder.ir — Powered by PersiaCoder.ir**

## تصمیم استقرار — هاست مشتری بدون Node.js runtime

- مطابق تصمیم `HOSTING-NO-NODE-RUNTIME-2026-001`، Node.js/npm فقط می‌توانند در محیط توسعه یا CI برای build artifact استفاده شوند؛ هاست مشتری نباید Node runtime یا process manager لازم داشته باشد.
- خروجی Frontend در بسته release به‌صورت فایل‌های استاتیک آماده تحویل می‌شود؛ PHP 8.2+ و PDO/MySQL مسئول Backend/API هستند.
- معماری jobهای زمان‌بندی‌شده/پس‌زمینه باید با cron و اجرای کوتاه‌عمر PHP در هاست‌های متداول سازگار طراحی شود؛ daemon دائمی Node الزام محصول نیست.
- installer باید پیش‌نیازهای واقعی PHP، افزونه‌ها، DB، دسترسی فایل و امکانات cron را preflight کند. سازگاری هاست اشتراکی با تست ماتریس اثبات می‌شود، نه با ادعای عمومی.


## الزام معماری — کد هدفمند و حداقل پیچیدگی

مطابق تصمیم `CODE-MINIMALITY-UTILITY-2026-001`، هیچ جزء فنی بدون مسئولیت و مصرف واقعی وارد معماری نشود. پیش از افزودن ماژول، abstraction، interface، service، package، جدول، migration یا component، نیاز واقعی و امکان استفاده از ساختار موجود بررسی شود. کد مرده، مسیرهای موازی تکراری، placeholder، dependency بی‌مصرف و UI بدون رفتار واقعی نباید وارد release شوند. ساده‌سازی نباید مرزهای امنیتی، authorization، validation سمت سرور، تراکنش اتمیک، idempotency، audit، کنترل خطا یا صحت مالی را تضعیف کند. معماری باید به اندازه نیاز محصول باشد، نه پیچیده‌تر.


## الزام معماری — MySQL، تک‌شعبه و فروش آفلاین

مطابق تصمیم `MYSQL-SINGLE-BRANCH-OFFLINE-SALES-2026-001`، دیتابیس عملیاتی باید MySQL/MariaDB باشد و نسخه فعلی فقط یک شعبه را پوشش دهد. برای جلوگیری از توقف صندوق در قطعی اینترنت، معماری وب باید مسیر local-first محدود و قابل اتکا برای سفارش حضوری داشته باشد: assetهای برنامه و داده‌های ضروری از قبل cache شوند؛ سفارش در فضای پایدار محلی مرورگر با شناسه یکتا ذخیره شود؛ چاپ فیش از داده محلی ممکن باشد؛ و صف همگام‌سازی پس از اتصال مجدد با idempotency و گزارش وضعیت اجرا شود. سرور همچنان مرجع نهایی داده‌های همگام‌شده و قواعد تجاری آنلاین است؛ در زمان آفلاین، داده محلی صرفاً ثبت موقت عملیات است و نباید پرداخت آنلاین، موجودی قطعی یا تأیید سرور را جعل کند.

طراحی تفصیلی باید رفتار چند دستگاه هم‌زمان، stale catalog/price، تخفیف، موجودی، پرداخت و تعارض را مشخص کند. اگر چند دستگاه در یک شعبه هم‌زمان آفلاین باشند، بدون کانال محلی مشترک نمی‌توان سازگاری کامل موجودی و شماره‌گذاری سراسری را تضمین کرد. چاپ بی‌صدای حرارتی نیز محدودیت مرورگر دارد و باید روی دستگاه/چاپگر هدف آزموده شود؛ PWA به‌تنهایی تضمین چاپ بی‌صدا نیست.

