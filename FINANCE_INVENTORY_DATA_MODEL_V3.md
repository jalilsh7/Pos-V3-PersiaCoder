# POS V3 — مدل داده مالی، صندوق و انبار غذا و نوشیدنی

- تاریخ: 2026-11-19
- وضعیت: **طراحی پیشنهادی برای بازبینی مالک؛ نه DDL اجرایی و نه مجوز پیاده‌سازی**
- محدوده: رستوران، کافه، فست‌فود، آبمیوه و بستنی؛ نسخه اول تک‌کسب‌وکاری/تک‌شعبه‌ای.
- اسناد مرتبط: `PAYMENT_LEDGER_V3.md`، `ORDER_DATA_MODEL_V3.md`، `SETTINGS_POLICY_ENGINE_V3.md` و `PRODUCT_SCOPE_V3.md`.
- دیتابیس تجاری از صفر نصب می‌شود؛ انتقال داده‌های V2 در محدوده نیست.

## 1. اصول قطعی طراحی

1. سفارش، پرداخت، صندوق و موجودی چهار دامنه مرتبط اما مستقل‌اند؛ هیچ‌کدام جایگزین منبع حقیقت دیگری نمی‌شود.
2. پرداخت موفق از دفتر پرداخت، وجه نقد فیزیکی از گردش صندوق، موجودی از دفتر گردش انبار و بهای تمام‌شده از لایه ارزش‌گذاری موجودی به دست می‌آید.
3. رویدادهای مالی و گردش موجودی append-only هستند؛ اصلاح با سند معکوس/اصلاحی جدید انجام می‌شود، نه update/delete سابقه.
4. مبلغ‌ها عدد صحیح در کوچک‌ترین واحد مصوب محصول و با ارز `IRT` ذخیره می‌شوند؛ مقدار مواد/واحدها decimal با precision ثابت و بدون float است.
5. هر عملیات اثرگذار بر سفارش، پرداخت، صندوق یا انبار در transaction واحد و با idempotency، قفل هم‌زمانی و audit انجام می‌شود.
6. در نسخه اول چندشعبه‌ای نداریم، اما محل نگهداری/انبار در یک کسب‌وکار قابل تعریف است؛ معماری branch-aware در آینده با migration اضافه می‌شود.
7. تنظیمات مشتری مانند اجازه موجودی منفی، هشدار کمبود و زمان مصرف رسپی در Backend enforce می‌شوند؛ UI منبع اجرای policy نیست.

## 2. دامنه پرداخت و صندوق

### 2.1 مرجعیت داده
- `pos_payments` دریافت/تلاش دریافت وجه را ثبت می‌کند.
- `pos_payment_events` تاریخچه تغییرناپذیر تراکنش پرداخت است.
- `pos_refunds` بازپرداخت مستقل و قابل پیگیری را نگه می‌دارد.
- `pos_cash_sessions` شیفت/نوبت صندوق و مسئول آن را مشخص می‌کند.
- `pos_cash_movements` ورود و خروج واقعی وجه نقد از کشوی صندوق را ثبت می‌کند.
- `pos_settlement_batches` و `pos_settlement_items` تطبیق گروهی تراکنش‌های کارتخوان/درگاه با گزارش تسویه provider را ثبت می‌کنند.

پرداخت نقدی موفق باید به شیفت صندوق باز مربوط به صندوق‌دار/صندوق متصل باشد. پرداخت کارتخوان نیز به تراکنش terminal تأییدشده و هم‌مبلغ متصل می‌شود؛ در صورت نیاز، رکورد cash session به‌عنوان عامل/محل انجام عملیات ثبت می‌شود، اما مبلغ کارت وارد گردش وجه نقد کشو نمی‌شود.

### 2.2 شیفت صندوق — `pos_cash_sessions`
فیلدها/روابط پیشنهادی:
- شناسه، `register_id`، `opened_by_user_id`، `closed_by_user_id`.
- وضعیت `open | closing | closed | requires_review`.
- `opening_float_amount`، `counted_closing_amount`، `expected_closing_amount_snapshot`، `variance_amount`؛ همگی با واحد پولی روشن.
- زمان بازشدن/بسته‌شدن، توضیح اختلاف، تأییدکننده و timestamps.
- هر session به یک صندوق/ترمینال کاری و یک مسئول اصلی متصل باشد؛ همکاری چند کاربر فقط با ثبت actor هر movement مجاز است.
- هم‌زمان برای هر register حداکثر یک session باز وجود داشته باشد؛ با قفل ردیف register و constraint/کنترل تراکنشی اعمال شود.
- session بسته‌شده دوباره باز نمی‌شود؛ اصلاح اختلاف با movement تعدیلی مستقل و مجوز مدیر انجام می‌شود.

### 2.3 گردش وجه نقد — `pos_cash_movements`
فیلدهای پیشنهادی:
- شناسه، `cash_session_id`، نوع movement، مبلغ مثبت، جهت `in | out`.
- انواع: `opening_float`, `cash_payment`, `cash_refund`, `cash_in`, `cash_out`, `supplier_payout`, `closing_adjustment`.
- مرجع اختیاری یکتا به payment/refund/expense یا سند مربوط؛ actor، علت، توضیح، زمان و idempotency.
- هر پرداخت نقدی موفق یک movement ورودی متناظر دارد؛ هر refund نقدی موفق یک movement خروجی متناظر. عملیات دستی وجه بدون علت و مجوز رد می‌شود.
- کارتخوان و درگاه در cash movement ثبت نمی‌شوند. کارمزد بانک/درگاه و تسویه بانکی نیز movement صندوق نیستند.
- مانده مورد انتظار از مجموع opening float و movementهای معتبر محاسبه می‌شود؛ عدد شمارش‌شده کاربر و اختلاف در زمان بستن session snapshot می‌شوند.
- دوباره‌شماری ممنوع: مبلغ پرداخت نقدی در گزارش دریافت از payment ledger می‌آید؛ cash movement فقط اثبات گردش کشو و reconciliation است، نه دریافت دوم.

### 2.4 صندوق‌ها و هزینه‌های نقدی
- موجودیت `pos_registers` برای تعریف صندوق/کشوی فیزیکی، نام، محل، فعال‌بودن و تنظیمات چاپ/ترمینال پیشنهاد می‌شود.
- هزینه یا برداشت نقدی فقط با سند هزینه/پرداخت مربوط و movement پیونددار ثبت شود؛ ایجاد expense module کامل در این بخش فقط در حد قرارداد اتصال است و دفتر هزینه‌ها در طراحی مالی/گزارش جدا تکمیل می‌شود.
- دریافت/پرداخت غیرنقدی به صندوق فیزیکی نسبت داده نمی‌شود؛ در گزارش تسویه مالی جداگانه دیده می‌شود.

### 2.5 تسویه و تطبیق — `pos_settlement_batches`, `pos_settlement_items`
- batch شامل provider، نوع کانال `terminal | gateway`، بازه زمانی، ارز، مبلغ ناخالص، کارمزد، مبلغ خالص، مرجع فایل/گزارش و وضعیت تطبیق است.
- هر item به payment داخلی و شناسه مرجع provider متصل می‌شود؛ مبلغ/تاریخ/وضعیت provider و نتیجه تطبیق نگهداری می‌شوند.
- وضعیت‌ها: `imported | matched | mismatch | missing_internal | missing_provider | reviewed | closed`.
- import فایل/گزارش تکراری با fingerprint و idempotency جلوگیری می‌شود؛ اختلاف بدون ثبت دلیل و actor بسته نمی‌شود.
- این مدل جایگزین استعلام لحظه‌ای تراکنش کارتخوان نیست؛ settlement گزارش تسویه دوره‌ای provider را با دفتر داخلی تطبیق می‌دهد.
- اطلاعات حساس کارت/رمز/PIN/CVV یا credential provider در این جداول ذخیره نمی‌شود.

## 3. دامنه انبار غذا و نوشیدنی

### 3.1 موجودیت‌های پایه
- `pos_stock_items`: ماده اولیه، کالای بسته‌بندی‌شده قابل فروش، اقلام مصرفی و ملزومات؛ شامل کد، نام، نوع، واحد پایه، فعال/آرشیو و سیاست ردیابی.
- `pos_units`: واحدهای استاندارد با کد و نوع کمیت مانند جرم، حجم، تعداد؛ نمونه g/kg، ml/l، each.
- `pos_stock_locations`: انبار/محل نگهداری؛ نسخه اول می‌تواند چند محل در یک کسب‌وکار داشته باشد.
- `pos_stock_documents` و `pos_stock_document_lines`: سربرگ و ردیف اسناد رسید، حواله، انتقال، شمارش، اصلاح، ضایعات و برگشت.
- `pos_stock_ledger`: دفتر تغییرناپذیر گردش؛ هر ردیف مقدار signed در واحد پایه، محل، قلم، علت، سند مرجع، actor، زمان و idempotency دارد.
- `pos_stock_reservations` و `pos_stock_reservation_lines`: رزرو موقت/قطعی موجودی برای سفارش یا فرایند داخلی.
- `pos_stock_balances`: در صورت نیاز projection/cache برای query سریع؛ منبع حقیقت همچنان ledger به‌علاوه رزروهای فعال است و projection باید قابل بازسازی باشد.

### 3.2 واحد و تبدیل
- هر stock item دقیقاً یک واحد پایه دارد؛ مقدار ledger همیشه به همان واحد پایه تبدیل می‌شود.
- `pos_unit_conversions` تبدیل‌های مجاز درون یک نوع کمیت را نگه می‌دارد؛ تبدیل جرم به حجم بدون چگالی/قاعده اختصاصی ممنوع است.
- نسبت تبدیل decimal با precision کنترل‌شده و زمان/actor تغییر ثبت می‌شود؛ تبدیل مورد استفاده در سند snapshot می‌شود تا تغییر بعدی محاسبه سند قدیمی را عوض نکند.
- مقدارها با DECIMAL (پیشنهاد اولیه DECIMAL(18,6)) ذخیره می‌شوند؛ استفاده از float/double برای مقدار موجودی ممنوع است.
- بسته‌بندی خرید (مثلاً کارتن) می‌تواند واحد خرید جدا داشته باشد اما پس از تبدیل به واحد پایه وارد ledger می‌شود.

### 3.3 دفتر موجودی و اسناد
- هر stock movement فقط با سند معتبر و reason code ثبت می‌شود؛ موجودی مستقیماً با ویرایش یک balance تغییر نمی‌کند.
- انواع سند پایه: `opening_balance`, `purchase_receipt`, `sale_consumption`, `transfer`, `count_adjustment`, `waste`, `return_in`, `return_out`, `manual_adjustment`.
- انتقال بین دو محل دو اثر متوازن در یک transaction دارد؛ مجموع مقدار ورودی و خروجی در کل کسب‌وکار صفر است.
- شمارش انبار ابتدا count sheet می‌سازد؛ تأیید مغایرت توسط نقش مجاز سند adjustment جدید ایجاد می‌کند.
- هر سند قطعی شماره، وضعیت، زمان، actor و خطوط snapshot دارد؛ سند posted و ledger آن update/delete نمی‌شوند؛ ابطال با سند reversal مرتبط انجام می‌شود.
- خرید/تأمین‌کننده می‌تواند در مرحله اول با رسید مستقیم ثبت شود؛ گردش کامل سفارش خرید، فاکتور تأمین‌کننده و حساب پرداختنی به‌عنوان زیرماژول مالی/تأمین در بخش جدا تصمیم‌گیری می‌شود.

### 3.4 ارزش‌گذاری و بهای تمام‌شده
- پیشنهاد نسخه اول: میانگین موزون متحرک (Moving Weighted Average) برای هر stock item در هر محل نگهداری.
- رسید ورودی با مقدار پایه و بهای تمام‌شده کل/واحد ثبت می‌شود؛ هزینه‌های مستقیم قابل تخصیص (مانند حمل خرید) فقط در صورت تصمیم محصول و ثبت سند قابل افزودن‌اند.
- خروج مصرفی با cost snapshot محاسبه‌شده در لحظه posting ثبت می‌شود؛ تغییر قیمت خرید آینده، بهای تمام‌شده خروج گذشته را بازنویسی نمی‌کند.
- تغییر قیمت/ارزش با سند ارزش‌گذاری و event حسابرسی انجام می‌شود؛ موجودی منفی از نظر ارزش‌گذاری باید policy صریح داشته باشد و تا رفع موجودی منفی به‌عنوان هزینه قطعی نهایی تلقی نشود.
- مالیات خرید، حسابداری تعهدی و دفتر کل رسمی در این سند ادعا یا پیاده‌سازی نمی‌شوند؛ خروجی این مدل بهای مصرف عملیاتی و ارزش موجودی قابل ردیابی است، نه جایگزین نرم‌افزار حسابداری رسمی.

## 4. رسپی و مصرف مواد

### 4.1 نسخه‌بندی رسپی
- `pos_recipes` هویت رسپی متصل به محصول/variant را نگه می‌دارد.
- `pos_recipe_versions` نسخه immutable با بازه اعتبار، yield، واحد خروجی و وضعیت draft/active/archived دارد.
- `pos_recipe_lines` مواد اولیه، مقدار لازم در واحد پایه، ضریب پرت/آماده‌سازی، واحد و روش گردکردن را برای هر نسخه ثبت می‌کند.
- هر order item در لحظه ثبت/پذیرش، شناسه و snapshot نسخه رسپی را نگه می‌دارد؛ ویرایش رسپی آینده سفارش‌های گذشته را تغییر نمی‌دهد.
- برای محصول بسته‌بندی‌شده، امکان نگاشت مستقیم به stock item به‌جای رسپی چندماده‌ای وجود دارد.
- انتخاب‌های محصول/add-on می‌توانند مواد اضافی یا جایگزین تعریف کنند؛ اثر آن‌ها باید در snapshot رسپی سفارش resolve شود، نه اینکه در زمان مصرف به کاتالوگ جاری رجوع کند.

### 4.2 زمان رزرو و مصرف
- هنگام پذیرش سفارش، نیاز مواد از snapshot رسپی محاسبه و رزرو می‌شود؛ سفارش پرداخت‌نشده storefront تا زمانی که policy پذیرش آن را مجاز نکرده، رزرو/مصرف قطعی ایجاد نمی‌کند.
- هنگام شروع آماده‌سازی، رزرو به مصرف واقعی ledger تبدیل می‌شود؛ این عملیات idempotent است و برای هر order item/preparation task فقط یک بار مجاز است.
- اگر کسب‌وکار سیاست مصرف در زمان پذیرش یا تکمیل را انتخاب کند، فقط از گزینه‌های تعریف‌شده و تست‌شده policy استفاده می‌شود؛ پیش‌فرض پیشنهادی «رزرو هنگام پذیرش، مصرف هنگام شروع آماده‌سازی» است.
- لغو قبل از مصرف رزرو را آزاد می‌کند؛ لغو پس از مصرف با برگشت واقعی مواد قابل استفاده یا سند waste/return جدا ثبت می‌شود، نه با پاک‌کردن مصرف.
- تغییر quantity، لغو جزئی و add-onها باید رزرو/مصرف را به‌صورت متناسب و تراکنشی تعدیل کنند.

### 4.3 ضایعات و بازده
- ضایعات آماده‌سازی، فساد، انقضا، شکست تولید و کسری انبار reason code جدا دارند.
- پرت رسپی با ضایعات واقعی یکسان نیست: پرت استاندارد در مقدار موردنیاز رسپی محاسبه می‌شود؛ ضایعات واقعی سند جدا با علت و actor دارد.
- تولید batch/pre-preparation در صورت ورود به نسخه اول باید سند تولید ورودی/مصرف مواد متوازن داشته باشد؛ تا زمان طراحی این جریان، موجودی محصول نیمه‌آماده با تغییر مستقیم balance ساخته نمی‌شود.

## 5. سیاست‌های تجاری و تنظیمات

هر تنظیم زیر باید در کاتالوگ تنظیمات، entitlement/permission، validation، Backend enforcement، audit و تست ثبت شود:
- سیاست کمبود موجودی: `block | warn_and_allow | allow_negative`؛ پیش‌فرض ثبت‌شده فعلی `warn_and_allow` است.
- سطح اعمال policy: در نسخه اول سراسری برای کسب‌وکار؛ override محصول/انبار فقط پس از طراحی اولویت و تست.
- زمان رزرو و مصرف: پیش‌فرض رزرو در پذیرش و مصرف در شروع آماده‌سازی.
- مدت رزرو سفارش آنلاین و رفتار آزادسازی؛ نتیجه مبهم پرداخت موجب آزادسازی فوری نمی‌شود.
- اجازه برداشت/اصلاح دستی و نیاز به تأیید دوم برای مبالغ/مقادیر حساس.
- انبار/محل پیش‌فرض برای فروش و رسپی؛ انتخاب محل باید deterministic باشد.
- آستانه هشدار کمبود و حداقل موجودی، بدون hard-code در UI.
- روش ارزش‌گذاری: نسخه اول Moving Weighted Average؛ تغییر روش در سطح سند/دوره ممنوع و نیازمند مهاجرت/ارزیابی جداست.

## 6. قیود و هم‌زمانی

- همه postingهای مالی/انبار در transaction دیتابیس انجام شوند؛ شکست یکی از اجزای posting باید کل عملیات را rollback کند.
- موجودی قابل‌مصرف = موجودی ledger/projection منهای رزروهای فعال؛ query و تغییر باید با lock یا optimistic version روی قلم/محل انجام شود.
- دو سفارش هم‌زمان نباید بتوانند بیش از مقدار قابل رزرو را رزرو کنند؛ سیاست allow_negative استثنای صریح و auditشده است.
- هر source event شناسه یکتا دارد تا retry سفارش/رسپی/کارتخوان سند دوباره نسازد.
- ledgerها با FKهای محافظت‌شده به سند/سفارش/رسپی متصل‌اند؛ اسناد تاریخی حذف فیزیکی نمی‌شوند.
- موجودی و مبلغ در واحدهای مشخص ذخیره می‌شوند؛ هیچ محاسبه مالی با float انجام نمی‌شود.
- گزارش balance و valuation باید با بازپخش ledger قابل بازسازی و reconcile باشد.

## 7. گزارش‌ها و queryهای لازم

### صندوق و مالی
- دریافت نقدی/کارتخوان/آنلاین به تفکیک روش و صندوق/شیفت.
- مبلغ مورد انتظار و شمارش واقعی هر شیفت، اختلاف و علت آن.
- بازپرداخت‌ها، پرداخت‌های نامشخص و مغایرت provider.
- فروش سفارش، دریافت وجه، refund و تسویه provider به‌عنوان معیارهای جدا.
- گردش هر cash session و audit اصلاحات.

### انبار و غذا
- موجودی قابل‌مصرف و رزروشده به تفکیک قلم و محل.
- گردش یک قلم در بازه زمانی با سند مرجع.
- ارزش موجودی و بهای مصرف با روش ارزش‌گذاری مصوب.
- مصرف استاندارد رسپی در برابر ضایعات واقعی.
- اقلام زیر حد هشدار، مغایرت انبارگردانی و رزروهای منقضی/نامشخص.
- قابلیت drill-down از گزارش به سند و event مرجع.

## 8. ماتریس موجودیت‌ها و روابط پیشنهادی

| دامنه | موجودیت‌ها | رابطه/نکته |
|---|---|---|
| پرداخت | `pos_payments`, `pos_payment_events`, `pos_refunds` | چند پرداخت برای هر سفارش؛ refund به پرداخت و سفارش |
| صندوق | `pos_registers`, `pos_cash_sessions`, `pos_cash_movements` | صندوق یک/چند session تاریخی؛ هر session چند movement |
| تسویه | `pos_settlement_batches`, `pos_settlement_items` | هر batch چند ردیف تطبیق؛ هر payment حداکثر یک نتیجه تطبیق نهایی در batchهای معتبر |
| کالا/واحد | `pos_stock_items`, `pos_units`, `pos_unit_conversions` | هر قلم یک واحد پایه؛ تبدیل‌ها درون یک نوع کمیت |
| محل | `pos_stock_locations` | هر محل چند ledger/reservation/document line |
| دفتر موجودی | `pos_stock_documents`, `pos_stock_document_lines`, `pos_stock_ledger` | سند posted چند اثر ledger؛ ledger append-only |
| رزرو | `pos_stock_reservations`, `pos_stock_reservation_lines` | رزرو برای order/source؛ آزادسازی/مصرف با رویداد مستقل |
| رسپی | `pos_recipes`, `pos_recipe_versions`, `pos_recipe_lines` | رسپی چند نسخه immutable؛ نسخه سفارش snapshot می‌شود |

## 9. تصمیم‌های معماری مصوب برای نسخه اول

1. **ارزش‌گذاری:** Moving Weighted Average به تفکیک قلم و محل نگهداری، روش نسخه اول است. FIFO به نسخه بعد موکول می‌شود. تغییر روش ارزش‌گذاری روی اسناد ثبت‌شده اعمال نمی‌شود.
2. **چند صندوق و محل نگهداری:** هر دو در نصب تک‌شعبه‌ای پشتیبانی می‌شوند. تعداد فعال از تنظیمات کسب‌وکار قابل تعیین است و Backend سقف را enforce می‌کند. این تصمیم به معنی چندشعبه‌ای‌شدن نسخه اول نیست.
3. **رزرو و مصرف:** در POS حضوری، رزرو مواد هنگام پذیرش/ثبت قطعی سفارش و تبدیل رزرو به مصرف هنگام شروع آماده‌سازی انجام می‌شود. برای storefront دارای پرداخت آنلاین، رزرو موقت checkout با TTL مستقل اعمال می‌شود؛ نتیجه مبهم پرداخت رزرو را فوراً آزاد نمی‌کند.
4. **خرید:** نسخه اول ثبت رسید خرید/ورودی مستقیم با بهای تمام‌شده و مرجع تأمین‌کننده اختیاری را پشتیبانی می‌کند؛ گردش کامل سفارش خرید، حساب پرداختنی و مدیریت اعتبار تأمین‌کننده به نسخه بعد موکول می‌شود.
5. **تولید نیمه‌آماده:** تولید batch/pre-preparation در دامنه MVP نسخه اول نیست. مدل رسپی و موجودی طوری طراحی می‌شود که افزودن آن در نسخه بعد بدون بازنویسی ledger ممکن باشد.
6. **اختلاف بستن شیفت:** صندوق‌دار می‌تواند شمارش و درخواست بستن را با ثبت علت ارسال کند؛ اگر اختلاف صفر نباشد، شیفت به وضعیت بسته‌شده با بررسی معلق می‌رود و تأیید مدیر برای نهایی‌شدن مغایرت لازم است. شیفت در این وضعیت تراکنش جدید نمی‌پذیرد و بازگشایی عادی مجاز نیست.

## 10. معیار پذیرش پیش از DDL

- ERD پرداخت با سفارش و تراکنش کارتخوان سازگار باشد و جمع پرداخت/refund در race condition از حدود مجاز عبور نکند.
- یک cash payment/refund دقیقاً یک cash movement مرتبط ایجاد کند؛ retry دوباره movement نسازد.
- card/gateway در گردش وجه نقد فیزیکی وارد نشوند.
- بستن شیفت مبلغ مورد انتظار، مبلغ شمارش‌شده و اختلاف را ثبت کند و session بسته دوباره قابل بازشدن نباشد.
- رسید، انتقال، شمارش، ضایعات و مصرف رسپی فقط ledger بسازند؛ تغییر balance مستقیم ممنوع باشد.
- دو رزرو هم‌زمان موجودی را بیش از حد مجاز مصرف نکنند.
- واحدهای ناسازگار (جرم/حجم/تعداد) بدون تبدیل معتبر رد شوند.
- تغییر رسپی/قیمت خرید، اسناد و بهای مصرف گذشته را بازنویسی نکند.
- لغو قبل/بعد از شروع آماده‌سازی، رزرو/مصرف را با سند معکوس درست مدیریت کند.
- backup/restore تمام اسناد، ledgerها، cash sessions، payments، refunds و recipe versions را حفظ کند.
- تست نصب تازه MySQL/MariaDB و تست رقابتی transactionها موفق باشد.

## 11. محدوده و محدودیت

این سند مدل منطقی پیشنهادی است؛ DDL، API، UI و گزارش اجرایی هنوز ساخته نشده‌اند. دفتر موجودی و مدل ارزش‌گذاری جایگزین حسابداری رسمی، مالیات قانونی یا ERP نیست. اتصال کارتخوان واقعی، درگاه واقعی، چاپگر و تسویه بانکی فقط پس از تست محیط/دستگاه واقعی قابل تأیید است.


## 12. ثبت تصمیم‌های تکمیلی FIN-01

- وضعیت: **تصمیم‌های معماری نسخه اول اتخاذ شد؛ ERD نهایی و DDL همچنان دروازه پذیرش جداگانه دارند.**
- ارزش‌گذاری نسخه اول Moving Weighted Average است؛ FIFO به نسخه بعد موکول شد.
- چند صندوق و چند محل نگهداری در یک نصب تک‌شعبه‌ای پشتیبانی می‌شوند؛ سقف تعداد از تنظیمات کسب‌وکار قابل مدیریت و در Backend enforce می‌شود.
- در POS، مواد هنگام پذیرش سفارش رزرو و هنگام شروع آماده‌سازی مصرف می‌شوند؛ storefront آنلاین از رزرو موقت checkout و TTL استفاده می‌کند.
- رسید خرید مستقیم در نسخه اول کافی است؛ ماژول کامل سفارش خرید و حساب پرداختنی به نسخه بعد موکول است.
- تولید batch/pre-preparation در MVP نیست؛ مدل باید مسیر توسعه بعدی را باز بگذارد.
- اختلاف غیرصفر صندوق با علت توسط صندوق‌دار ثبت می‌شود؛ نهایی‌شدن مغایرت نیازمند تأیید مدیر است و شیفت در حالت بررسی معلق بسته و غیرقابل استفاده می‌ماند.
- این تصمیم‌ها به معنی پیاده‌سازی یا تست نیستند.


## 10. Posting implementation contract (2026-11-20)
- The selected method is Moving Weighted Average per stock item and per location. It is now used by the first inventory posting slice.
- Purchase receipt and inbound adjustment draft lines accept `total_cost_amount` as a non-negative integer TOMAN amount for the entire line. The service stores `unit_cost_snapshot` per base unit using the captured unit conversion factor; posting updates the line snapshot and balance average in the same transaction.
- Outbound adjustment, consumption and waste use the current location average cost. Transfers use the source location average cost and create equal/opposite valuation ledger entries at source and destination.
- Stock movement ledger entries are append-only through application code. Posting locks the document and affected balance rows, rejects reserved-stock consumption and negative stock, and uses idempotency plus audit.
- Costed document data is exposed only through the `inventory.manage` valuation endpoint; ordinary inventory document detail does not expose costs.
- Return posting is intentionally disabled until a return document can reference the originating order/receipt line and restore its historical cost. A generic return must not be valued at the current average by guesswork.
- Verified in disposable MySQL 8.4 and MariaDB 10.6 integration CI for receipt weighted average, consumption, transfer, idempotency replay and atomic rollback. This does not yet cover concurrent posting races, full reservation consumption, supplier/customer returns or frontend integration.


## 11. Integration verification
- Moving weighted average posting was exercised on disposable MySQL 8.4 and MariaDB 10.6 databases, including concurrent outflow contention, reserved quantity guard, adjustments, consumption, transfer value symmetry, idempotent replay and rollback.
- A transfer produces paired `transfer_out`/`transfer_in` entries with net zero quantity and value across the two locations.
- For competing outgoing posts against one balance, row locking allows one transaction to commit; the other is rejected after reading the committed available balance. No negative stock or partial ledger writes remain.
- Return posting remains disabled until a source document line and historical unit-cost source are part of the return contract.
