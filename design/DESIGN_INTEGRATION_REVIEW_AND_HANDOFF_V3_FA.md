# بازبینی یکپارچگی طراحی و تحویل به توسعه — PersiaCoder POS V3

- شناسه: DESIGN-INTEGRATION-REVIEW-2026-001
- نسخه: 0.1
- تاریخ: 2026-11-20
- بسته: D-16
- وضعیت: **بازبینی اولیه انجام شد؛ یکپارچه‌سازی اسناد و نمونه‌سازی/توسعه مرحله‌ای باقی است**
- مرجع الزام‌آور محصول: `FINAL_PRODUCT_DECISIONS_FA.md`
- مراجع وضعیت اجرا: HEAD شاخه `main`، کد Frontend/Backend، schema و migrationهای V3، اسناد UX D-01 تا D-15 و `AI_HANDOFF_FA.md`.

> این سند گزارش بازبینی طراحی است، نه اعلام آمادگی release. تأیید UX، تکمیل کد، اجرای تست و پذیرش staging چهار وضعیت جدا هستند.

## 1. نتیجه بازبینی

تا این بسته، مشخصات UX برای ستون‌های اصلی محصول تدوین شده‌اند؛ اما چند سند موازی برای بعضی بسته‌ها در پوشه طراحی وجود داشت و README/backlog نیز بعضی ردیف‌های تکراری یا ارجاع قدیمی داشتند. برای جلوگیری از دوباره‌کاری، از این پس برای هر بسته فقط یک سند canonical معرفی می‌شود و اسناد موازی به‌عنوان archive/superseded به آن ارجاع می‌دهند.

### اسناد مرجع canonical

| بسته | سند مرجع | وضعیت طراحی |
|---|---|---|
| D-01 | `DESIGN_SYSTEM_V3_FA.md` | پایه اولیه؛ نیازمند نمونه‌سازی و اعتبارسنجی |
| D-02/D-03 | `UI_SHELL_DASHBOARD_V3_FA.md` | آماده نمونه‌سازی |
| D-04/D-05 | `POS_SALES_WORKSPACE_V3_FA.md` | آماده نمونه‌سازی |
| D-06 | `PAYMENT_AND_RECEIPT_UX_V3_FA.md` | آماده نمونه‌سازی |
| D-07 | `ORDER_CENTER_AND_DETAILS_UX_V3_FA.md` | آماده نمونه‌سازی؛ بازبینی مالک باقی است |
| D-08 | `CATALOG_OPTIONS_AND_RECIPES_UX_V3_FA.md` | آماده نمونه‌سازی مشروط |
| D-09 | `INVENTORY_AND_STOCK_MOVEMENTS_UX_V3_FA.md` | آماده بازبینی و نمونه‌سازی |
| D-10 | `CUSTOMERS_LOYALTY_AND_SMS_UX_V3_FA.md` | آماده بازبینی مفهومی |
| D-11 | `FINANCE_CASH_AND_RECONCILIATION_UX_V3_FA.md` | آماده بازبینی؛ Backend مالی تکمیلی باز |
| D-12 | `ACCESS_CONTROL_SETTINGS_AND_ENTITLEMENTS_UX_V3_FA.md` | آماده نمونه‌سازی مرحله‌ای |
| D-13 | `STOREFRONT_AND_PUBLIC_CHECKOUT_UX_V3_FA.md` | آماده بازبینی مفهومی |
| D-14 | `KITCHEN_KDS_AND_FULFILLMENT_UX_V3_FA.md` | آماده بازبینی مفهومی |
| D-15 | `INTEGRATIONS_AND_HARDWARE_UX_V3_FA.md` به‌همراه `INTEGRATIONS_SMS_TERMINALS_AND_PRINTING_UX_V3_FA.md` به‌عنوان ضمیمه مرجع پیامک | آماده بازبینی معماری |

## 2. ممیزی یکپارچگی بین ماژول‌ها

### 2.1 POS → Orders → Kitchen → Fulfillment
- POS UI و Order API واقعی وجود دارند؛ KitchenBoard به Orders API وصل است.
- Kitchen فقط accepted/preparing/ready را مصرف می‌کند و transitionهای preparing/ready را مجاز دارد.
- تکمیل نهایی در مرکز سفارش است؛ fulfillment delivery state machine و courier workflow هنوز کامل نیست.
- Storefront باید همان Order engine را صدا بزند؛ public checkout فعلاً route عملیاتی ندارد.

### 2.2 Catalog → Recipe → Inventory
- APIهای Catalog و Recipe backend وجود دارند؛ UI کامل تنوع/گروه گزینه/رسپی و API client فرانت‌اند ناقص‌اند.
- schema سفارش `recipe_version_id` دارد؛ snapshot نام/قیمت/گزینه سفارش وجود دارد، اما snapshot خطوط مواد مصرفی برای هر order item در schema بررسی‌شده اثبات نشد.
- Inventory document posting و ledger وجود دارند؛ اتصال خودکار سفارش به رزرو/مصرف انبار end-to-end اثبات نشده است.
- جمع موجودی واحدهای ناهمگون نباید به‌صورت یک total فیزیکی نمایش داده شود؛ شاخص‌ها باید dimension-aware باشند.

### 2.3 Orders → Payments → Cash/Finance
- order status و payment status مستقل‌اند.
- schema payment/refund/settlement دارد، اما POS payment workflow، provider adapter، refund orchestration و reconciliation کامل در UI/Backend بررسی‌شده اثبات نشده‌اند.
- صندوق و شیفت، manual in/out و close/review API و UI دارند؛ cash movement history و اتصال پرداخت سفارش به شیفت هنوز کامل نیست.
- ناسازگاری enum پرداخت `partial` در filter با `partially_paid` در guard لغو باید در فاز Backend/Payment با قرارداد واحد و تست اصلاح شود.

### 2.4 Customers → Loyalty → SMS
- صفحه مشتریان فعلی هنوز API/DB عملیاتی را اثبات نمی‌کند.
- parser ورودی SMS موجود است؛ provider sending, queue, delivery tracking, consent/opt-out runtime باقی است.
- پیامک تبلیغاتی تابع consent معتبر و opt-out است؛ checkbox فعال پیش‌فرض رضایت معتبر ایجاد نمی‌کند.
- خطای SMS نباید سفارش/پرداخت را rollback کند.

### 2.5 RBAC → Entitlement → Central
- Backend RBAC، مدیریت کاربران، نقش‌ها و branch scope دارد.
- صفحه Business Settings فعلی preview محلی است؛ UI مدیریت دسترسی و تنظیمات عملیاتی ناقص است.
- Central entitlement enforcement در runtime بررسی‌شده اثبات نشده؛ باید در Backend نصب enforce شود.
- ترکیب امکانات و پلن‌های فروش همچنان باز است و نباید حدس زده شود.

### 2.6 Storefront → Orders → Payments
- Storefront فعلی seed/cart local preview است؛ public API و checkout عملیاتی موجود نیست.
- قالب عمومی صنفی و موتور سفارش مشترک تصمیم قطعی‌اند.
- public endpoint باید tenant resolution، rate limit، anti-abuse، idempotency، price quote و payment callback امن داشته باشد.

### 2.7 Integrations/Hardware
- SmartSMS و SharedNumber APIها از PDFهای مالک و Rest-Sample استخراج شده‌اند؛ adapter/runtime هنوز وجود ندارد.
- کارتخوان LAN/Wi-Fi و چاپگر نیازمند مدل و protocol واقعی‌اند؛ هیچ port/command نباید حدس زده شود.
- schema terminal/agent وجود دارد اما driver/adapter و تراکنش واقعی سخت‌افزار اثبات نشده‌اند.

## 3. تعارض‌ها و اصلاحات لازم پیش از پیاده‌سازی

1. **اسناد موازی:** فقط canonical map بخش 1 مرجع توسعه باشد؛ نسخه‌های قدیمی به stub ارجاع‌دهنده تبدیل شوند.
2. **D-07 payment status:** `partial` در filter و `partially_paid` در cancellation guard همسان‌سازی شود.
3. **D-08 recipe snapshot:** order item recipe_version_id وجود دارد؛ مقداردهی توسط OrderService و snapshot مواد هنوز باید با کد و integration test اثبات شود.
4. **D-09 dashboard:** جمع موجودی واحدهای ناهمگون اصلاح و dimension-aware شود.
5. **D-10 consent:** رضایت معتبر با checkbox default checked یکی نیست؛ consent event باید evidence/source/time/policy داشته باشد.
6. **D-11 finance:** cash session و manual movements از payment ledger و expense جدا بمانند؛ گزارش فروش/دریافت/سود را یک مفهوم نکن.
7. **D-12 access:** RBAC کاربر با entitlement نصب/ماژول جدا بماند؛ Central مرجع entitlement است.
8. **D-13 channel:** مقدار نهایی source_channel برای سفارش آنلاین باید با OrderService enum هماهنگ شود؛ `storefront` در API فعلی وجود دارد.
9. **D-14 states:** order, fulfillment و item preparation جدا بمانند؛ ready به معنی تحویل قطعی نیست.
10. **D-15 transport:** نمونه‌های HTTP قدیمی مجوز ارسال credential روی HTTP نیستند؛ از HTTPS و secret storage استفاده شود.

## 4. ترتیب نمونه‌سازی UI و توسعه بر اساس اولویت عرضه مالک

اولویت تجاری مالک: POS کامل + انبار + باشگاه مشتریان + پیامک. فروشگاه آنلاین و سایر ماژول‌ها حذف نشده‌اند، اما باید مرحله‌ای اجرا شوند.

### موج 1 — تثبیت تجربه فروش و ثبت سفارش
1. POS shell و POS Sales Workspace (D-02 تا D-05).
2. پرداخت/رسید (D-06) با وضعیت unknown و جلوگیری از retry کور.
3. مرکز سفارش و جزئیات (D-07).
4. اتصال UI کاتالوگ/تنوع/افزودنی و رسپی (D-08).
5. KDS پایه موجود را با order center و مجوزهای واقعی polish کن (بخش پایه D-14).

### موج 2 — انبار و مشتری
1. اصلاح dimension-aware dashboard و pagination انبار (D-09).
2. مدیریت کامل تنوع/option/recipe UI و API client (D-08).
3. Customer API/DB و اتصال customer به order (D-10).
4. ledger وفاداری و consent evidence با policy مصوب (D-10).
5. اتصال recipe/order/reservation/consumption با policy مالک و integration test (D-08/D-09).

### موج 3 — پیامک عملیاتی
1. Provider adapter National Payamak REST/SmartSMS.
2. queue, retry, dedupe, delivery report و secret settings.
3. event-based transactional SMS.
4. marketing SMS فقط با consent معتبر و opt-out.
5. تست provider با sandbox/credential آزمایشی؛ هیچ secret در Git ثبت نشود.

### موج 4 — مالی و Central
1. تکمیل پرداخت سفارش، cash movement linking و reconciliation (D-11).
2. هزینه‌ها و گزارش‌های مالی واقعی.
3. UI کاربران/نقش‌ها و تنظیمات عملیاتی (D-12).
4. Central entitlement client/enforcement و policyهای offline/downgrade پس از تصمیم‌های باز؛ ترکیب پلن فروش حدس زده نشود.

### موج 5 — Storefront و سخت‌افزار پیشرفته
1. public storefront API، cart/quote/checkout، ساعات کاری و fulfillment (D-13).
2. اتصال سفارش online به POS/KDS.
3. کارتخوان LAN/Wi-Fi فقط با مدل و protocol مستند و تست واقعی (D-15).
4. چاپگر رسید/KOT با print job و agent.
5. KDS چندایستگاهی و تحویل پیشرفته (D-14).

## 5. قرارداد تحویل هر بسته به توسعه

هر سند UX باید به issue/taskهای قابل اجرا تبدیل شود و برای هر task این موارد مشخص باشد:
- فایل/endpoint/schema مورد تغییر.
- منبع داده و مالکیت آن.
- permission و entitlement مورد نیاز.
- state machine و transitionهای مجاز.
- idempotency/audit/transaction boundary.
- حالت loading/empty/error/conflict/unknown.
- معیار پذیرش UI و API.
- تست‌های مرتبط و شواهد PASS.
- وضعیت: طراحی، پیاده‌سازی، تست، پذیرش؛ هر کدام مستقل.

## 6. Gate پایان طراحی

- [ ] هر بسته D-01 تا D-15 یک سند canonical و وضعیت واحد دارد.
- [ ] README و backlog هیچ ردیف یا لینک تکراری ندارند.
- [ ] تصمیمات محصول و بازبودن policyها با سند یکتا منطبق‌اند.
- [ ] قراردادهای موجود/ناقص در هر سند از هم تفکیک شده‌اند.
- [ ] شکاف‌های حیاتی فروش، پرداخت، انبار، مشتری و SMS به taskهای فنی تبدیل شده‌اند.
- [ ] نمونه‌سازی بصری و تست usability برای موج 1 برنامه‌ریزی شده است.
- [ ] هیچ سند UX به‌عنوان تست یا پیاده‌سازی کامل معرفی نشده است.

## 7. ثبت تغییرات

- 2026-11-20 — ممیزی اولیه D-16 و تعریف canonical map و موج‌های تحویل.
- وضعیت: **بازبینی اولیه انجام شد؛ پاک‌سازی ارجاع‌های تکراری و تبدیل طراحی به taskهای توسعه باقی است**.
