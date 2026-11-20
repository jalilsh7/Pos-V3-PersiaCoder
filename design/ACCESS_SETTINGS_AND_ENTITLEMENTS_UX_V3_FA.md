# طراحی UX تنظیمات، کاربران، نقش‌ها و مجوز قابلیت‌ها — PersiaCoder POS V3

- شناسه: DESIGN-ACCESS-SETTINGS-2026-001
- نسخه: 0.1
- تاریخ: 2026-11-20
- بسته: D-12
- وضعیت: **آماده بازبینی معماری؛ بخش‌های فاقد API نیازمند تکمیل Backend**
- مرجع تصمیم محصول: `FINAL_PRODUCT_DECISIONS_FA.md`
- تصمیم entitlement: `LIC-MODULE-CONTROL-2026-001`
- مراجع فنی بررسی‌شده:
  - `v3/backend/src/Auth/UserManagementService.php`
  - `v3/backend/src/Auth/UserManagementController.php`
  - `v3/backend/src/Auth/RoleManagementService.php`
  - `v3/backend/src/Auth/RoleManagementController.php`
  - `v3/backend/src/Auth/PermissionService.php`
  - `v3/backend/src/Auth/BranchManagementService.php`
  - `v3/backend/src/Auth/BusinessSetupService.php`
  - `v3/backend/src/Infrastructure/Database/RoleRepository.php`
  - `v3/backend/src/Infrastructure/Database/PermissionRepository.php`
  - `v3/backend/public/index.php`
  - `database/v3/schema.sql`

> این سند UX و تفکیک مسئولیت است. احراز هویت و RBAC در Backend وجود دارد؛ برخی endpointهای مدیریت کاربر/نقش/شعبه نیز وجود دارند. تنظیمات عمومی کسب‌وکار و entitlementهای Central در runtime جاری به‌صورت کامل اثبات نشده‌اند. این سند هیچ پلن یا ترکیب تجاری ماژول را تعریف نمی‌کند.

## 1. اصول محصول و امنیت

- همه کاربران با username/password وارد می‌شوند؛ نقش یا مدیر بودن جایگزین احراز هویت نیست.
- مجوز هر درخواست در Backend کنترل می‌شود؛ مخفی‌کردن دکمه در UI امنیت محسوب نمی‌شود.
- Role، Permission، BusinessScope و BranchScope مفاهیم مجزا هستند.
- مجوزهای کاربر از نقش‌های تخصیص‌یافته در سطح کسب‌وکار و/یا شعبه استخراج می‌شوند.
- نقش owner نصب، نقش محافظت‌شده است و از مدیریت عادی قابل تخصیص یا ویرایش نیست.
- ایجاد کاربر با نقشی که مجوزهای آن از مجوزهای actor بیشتر باشد ممنوع است.
- ماژول/feature entitlement از RBAC داخلی جداست؛ کاربر دارای permission فقط در صورت entitlement معتبر می‌تواند قابلیت را استفاده کند.
- خاموش‌شدن entitlement نباید داده تاریخی را حذف کند.
- بسته‌های تجاری، قیمت و ترکیب امکانات هنوز تصمیم‌گیری نشده‌اند؛ UI/Backend نباید آن‌ها را hard-code کند.

## 2. وضعیت فعلی و APIهای اثبات‌شده

### 2.1 کاربران
APIهای موجود:
- فهرست کاربران کسب‌وکار: `GET /api/v1/access/users` با `users.read`.
- فهرست کاربران شعبه: `GET /api/v1/access/branches/users` با `users.read` و branch scope.
- ایجاد کاربر کسب‌وکار: `POST /api/v1/access/users` با `users.manage`.
- ایجاد کاربر شعبه: `POST /api/v1/access/branches/users` با `users.manage`.

قرارداد ایجاد:
- username: lowercase، 3 تا 64 نویسه؛ حروف/عدد/نقطه/underscore/hyphen.
- display_name: الزامی، حداکثر 120 نویسه.
- role_code: نقش فعال و موجود.
- نقش owner یا نقش دارای wildcard ممنوع است.
- سیستم رمز موقت تصادفی تولید و hash می‌کند.
- رمز موقت فقط یک بار در پاسخ ایجاد نمایش داده می‌شود؛ کاربر باید در اولین ورود آن را تغییر دهد.
- عملیات idempotent و audit می‌شود.

مواردی که در routeهای بررسی‌شده پیدا نشد:
- ویرایش نام/username کاربر.
- غیرفعال‌سازی/فعال‌سازی کاربر.
- reset password مدیریتی.
- لغو همه نشست‌ها یا مدیریت نشست‌های کاربر.
- حذف کاربر.

### 2.2 نقش و مجوز
APIهای موجود:
- `GET /api/v1/access/roles` با `roles.read`.
- `GET /api/v1/access/permissions` با `roles.read`.
- `POST /api/v1/access/roles` با `roles.manage`.
- `PATCH /api/v1/access/roles/{roleCode}` با `roles.manage`.
- تخصیص/برداشتن نقش در سطح business و branch از routeهای مربوط به userPublicId.
- عملیات تغییر نقش idempotent و audit می‌شود.

قواعد:
- نقش سفارشی دارای code، name و فهرست permission است.
- نقش‌های system قابل ویرایش نیستند.
- نقش owner و wildcard قابل تخصیص از مسیر مدیریت عادی نیست.
- actor فقط می‌تواند role/permissionهایی را تخصیص دهد که خودش مجاز به استفاده از آن‌هاست.
- ایجاد کاربر با role دارای permission بیشتر از actor با `role_escalation_denied` رد می‌شود.

### 2.3 کسب‌وکار و شعبه
- BusinessContext می‌تواند کسب‌وکارهای قابل دسترس کاربر را فهرست کند.
- BusinessSetup فقط راه‌اندازی اولیه کسب‌وکار/شعبه را برای installation owner انجام می‌دهد.
- راه‌اندازی اولیه در نسخه فعلی currency را به `IRT` محدود می‌کند و timezone معتبر می‌خواهد.
- BranchManagementService فهرست و ایجاد شعبه را پشتیبانی می‌کند؛ timezone خالی از business به ارث می‌رسد.
- ویرایش/غیرفعال‌سازی شعبه در routeهای بررسی‌شده مشاهده نشد.
- تنظیمات عمومی مانند ساعات کاری، اطلاعات تماس، سیاست‌های فروش و پیکربندی عمومی در یک Settings API جامع در این بررسی اثبات نشده‌اند.

### 2.4 Central و entitlement
- تصمیم محصول `LIC-MODULE-CONTROL-2026-001` می‌گوید Central مرجع entitlement قابلیت/ماژول هر نصب است و Backend مشتری باید آن را enforce کند.
- پیاده‌سازی runtime اتصال Central، entitlement cache/signature، feature dependency و enforcement کامل در HEAD بررسی‌شده اثبات نشده است.
- بسته‌های فروش، قیمت، feature bundle و ترکیب پلن‌ها باز هستند؛ هیچ نمونه فرضی نباید به‌عنوان پلن مصوب نمایش داده شود.

## 3. معماری اطلاعات پیشنهادی

ناوبری تنظیمات:
1. اطلاعات کسب‌وکار
2. شعبه‌ها
3. کاربران
4. نقش‌ها و مجوزها
5. امنیت و نشست‌ها
6. ماژول‌ها و قابلیت‌های مجاز (وضعیت لایسنس/entitlement)
7. تنظیمات عملیاتی هر ماژول

صفحه اصلی تنظیمات ساده و دسته‌بندی‌شده باشد؛ تنظیمات تخصصی POS، انبار، پیامک، پرداخت و storefront در ماژول خودشان باقی بمانند تا تنظیمات تکراری ساخته نشود.

## 4. مدیریت کاربران

### فهرست
- نام نمایشی.
- username.
- نقش‌های کسب‌وکار و شعبه.
- وضعیت فعال، فقط اگر API آن را ارائه کند.
- آخرین ورود فقط در صورت API و فیلد معتبر؛ از حدس زمان فعالیت خودداری شود.

فیلتر:
- scope کسب‌وکار یا شعبه.
- نقش.
- وضعیت، فقط پس از API status.
- جست‌وجوی username/display name پس از API server-side.

### ایجاد کاربر
فرم کوتاه:
- نام نمایشی.
- username.
- scope تخصیص: کسب‌وکار یا شعبه جاری.
- role از فهرست مجاز actor.

پس از پاسخ موفق:
- رمز موقت فقط یک‌بار در modal امن نمایش داده شود.
- دکمه copy و هشدار «پس از بستن دیگر قابل مشاهده نیست» در نمونه‌سازی لحاظ شود.
- رمز در localStorage، query string، log، analytics یا state ماندگار ذخیره نشود.
- کاربر مدیر باید رمز را از مسیر امن به فرد تحویل دهد؛ پیام/ایمیل خودکار بدون integration مصوب فرض نشود.
- ایجاد مجدد به علت timeout با همان idempotency key انجام شود؛ کلید جدید ممکن است کاربر/رمز جدید بسازد.

### اقدامات باز
ویرایش/غیرفعال‌سازی/reset password/session revoke فقط پس از endpoint و permission واقعی فعال شوند. تا آن زمان UI این اقدامات را disabled با توضیح «در حال تکمیل» نشان دهد.

## 5. مدیریت نقش‌ها و مجوزها

### فهرست نقش
- نام و code.
- system/custom.
- تعداد permission یا خلاصه مجوزها.
- نقش‌های system با نشان قفل و بدون امکان ویرایش.

### ساخت نقش سفارشی
- code یکتا، نام، permissionها.
- permissionها از `GET /access/permissions` بارگذاری شوند.
- گروه‌بندی permissionها در UI می‌تواند بر اساس prefix دامنه باشد، اما کد خام permission باید عین پاسخ سرور بماند.
- wildcard و permissionهایی که actor ندارد قابل تخصیص نیستند.
- خلاصه اثر دسترسی قبل از ذخیره نمایش داده شود.

### ویرایش نقش
- فقط نقش سفارشی و فعال.
- تغییر name و permission set.
- پیش از ذخیره، نمایش کاربران/تخصیص‌های متأثر فقط در صورت API aggregate.
- تغییر نقش باید بلافاصله در درخواست‌های بعدی با PermissionService enforce شود.
- نقش system و owner immutable باقی می‌مانند.

### تخصیص نقش
- یک کاربر می‌تواند نقش business و branch داشته باشد؛ scope در UI باید صریح باشد.
- نقش branch فقط به شعبه مجاز همان business تعلق گیرد.
- حذف assignment با حذف کاربر اشتباه نشود.
- قبل از حذف آخرین نقش مؤثر مدیر، Backend باید از قفل‌شدن کسب‌وکار جلوگیری کند؛ این invariant باید تست شود و UI جایگزین آن نیست.

## 6. سطح دسترسی و نمایش منوها

- منوها بر اساس permissionهای جاری و entitlement فعال ساخته شوند.
- منو پنهان‌شده نباید مانع فراخوانی مستقیم API باشد؛ Backend همچنان مجوز را بررسی کند.
- عدم permission: «دسترسی ندارید».
- entitlement غیرفعال: «این قابلیت در مجوز فعلی نصب فعال نیست» فقط اگر وضعیت معتبر از license service در دسترس باشد.
- اگر license service در دسترس نیست یا entitlement منقضی/نامعتبر است، رفتار بر اساس policy آفلاین/اعتبارسنجی مصوب باشد؛ UI نباید خودش تصمیم fail-open بگیرد.
- لینک مستقیم به صفحه فاقد مجوز باید پاسخ کنترل‌شده بدهد.
- غیرفعال‌سازی feature داده قبلی را پاک نکند؛ read/export و دسترسی تاریخی تابع policy آینده است.

## 7. تفکیک RBAC از Entitlement

| لایه | پرسش | مرجع |
|---|---|---|
| Authentication | این کاربر کیست؟ | Session/Auth |
| Business/Branch scope | در کدام کسب‌وکار/شعبه عمل می‌کند؟ | BusinessScopeResolver |
| RBAC Permission | این کاربر اجازه این عملیات را دارد؟ | PermissionService |
| Entitlement | این نصب مجاز به استفاده از این feature است؟ | Central/license client (نیازمند پیاده‌سازی) |
| Business setting | این کسب‌وکار این رفتار را روشن کرده؟ | Settings domain (نیازمند قرارداد جامع) |

برای عملیات، تمام لایه‌های مرتبط باید سمت Backend کنترل شوند. permission به‌تنهایی entitlement نیست و entitlement نیز جایگزین نقش/مجوز کاربر نیست.

## 8. تنظیمات کسب‌وکار و شعبه

### داده‌های فعلی قابل اتکا
- نام و code کسب‌وکار.
- timezone کسب‌وکار.
- currency فعلی IRT.
- نام، code و timezone شعبه.
- branch timezone در صورت null از business timezone ارث می‌برد.
- BusinessContext فهرست کسب‌وکارهای قابل دسترس را می‌دهد.

### UX
- صفحه «اطلاعات کسب‌وکار» برای داده‌های قابل خواندن از API.
- صفحه شعبه‌ها با فهرست و ایجاد شعبه.
- نمایش timezone مؤثر هر شعبه.
- تغییر timezone باید هشدار اثر بر گزارش‌های تاریخ‌محور، ساعت کاری و تاریخ سفارش داشته باشد؛ تا زمان وجود update API فقط نمایش/راهنما.
- currency در نسخه اولیه IRT است؛ انتخاب ارز دیگر در UI نمایش داده نشود.
- ساعات کاری، اطلاعات تماس، تنظیمات مالیات، سیاست سفارش، locale و دیگر preferenceها تنها پس از تعریف Settings API و مالکیت داده اضافه شوند.

## 9. صفحه entitlement و کنترل ماژول

صفحه هدف برای مدیر نصب/صاحب کسب‌وکار:
- وضعیت نصب و license.
- زمان آخرین sync/اعتبارسنجی.
- فهرست featureهای فعال/غیرفعال و علت.
- dependencyهای feature.
- وضعیت آفلاین و زمان اعتبار باقی‌مانده، فقط از پاسخ امضاشده معتبر Central.
- خطاهای license و زمان آخرین موفقیت sync.

محدودیت‌های فعلی:
- این UI تا زمان اتصال Central صرفاً prototype مستند است.
- هیچ پلن، قیمت یا ترکیب امکانات نمایش داده نشود مگر داده واقعی Central.
- خاموشی feature نباید داده تاریخی را حذف کند.
- مدت اعتبار آفلاین، grace period، expiry و رفتار read-only/export هنوز تصمیم بازند.
- entitlement باید در backend enforce شود و APIهای حساس بدون entitlement معتبر رد شوند؛ پنهان‌کردن منو کافی نیست.

## 10. امنیت و audit

- همه write routeها session، CSRF و Same-Origin guard دارند؛ این الگو برای routeهای مدیریتی جدید حفظ شود.
- create/update/assign/remove در role management با idempotency و audit انجام می‌شوند.
- temporary password فقط یک‌بار بازگردانده می‌شود؛ از log و persistence سمت کلاینت حذف شود.
- role escalation ممنوع.
- owner/system roles محافظت شوند.
- scope در query و mutation لحاظ شود.
- تغییر نقش/permission باید audit قبل/بعد داشته باشد.
- اعمال entitlement و تغییر settings نیز audit و منبع معتبر داشته باشند.
- تغییرات امنیتی مهم با confirmation و نمایش اثر دسترسی انجام شوند.

## 11. حالت‌ها و واکنش‌گرایی

- loading، empty، no result، validation، conflict، permission denied، entitlement unavailable/disabled.
- فرم نقش با permissionهای زیاد، جست‌وجوی permission و گروه‌بندی جمع‌شونده.
- mobile: لیست کارت‌محور و فرم تک‌ستونه؛ desktop: جدول و پنل جزئیات.
- کدهای username/role/permission در قطعه LTR.
- وضعیت system role و owner با متن و نشانه، نه فقط رنگ.
- از نمایش مقادیر موقت رمز بعد از خروج از modal جلوگیری شود.

## 12. موارد باز و نیازمند تکمیل فنی/تصمیم

1. API و UI ویرایش/غیرفعال‌سازی کاربر، reset password و revoke sessions.
2. تعریف invariant آخرین مدیر/owner و تست جلوگیری از lockout.
3. branch update/deactivate و تغییر timezone امن.
4. Settings API با scope و validation و audit.
5. Central client، امضای entitlement، cache، refresh و offline validation.
6. Feature registry و dependency graph.
7. enforcement entitlement در همه service/controllerهای مربوط.
8. سیاست expiry/grace/offline و read-only/export پس از disable.
9. قرارداد role assignment در کسب‌وکارهای چندشعبه و precedence بین business/branch.
10. بازبینی system role/permission seeds و جلوگیری از privilege escalation.
11. تست concurrency برای تغییر نقش هنگام درخواست فعال و cache invalidation.
12. تعریف policy session timeout، session revocation و audit ورودها.

## 13. معیار پذیرش

- [ ] کاربر با username/password واقعی سمت سرور وارد می‌شود.
- [ ] فهرست/ایجاد کاربر بر اساس scope کسب‌وکار یا شعبه کار می‌کند.
- [ ] رمز موقت فقط یک‌بار نمایش داده و تغییر در اولین ورود اجباری است.
- [ ] نقش owner و نقش‌های system از ویرایش/تخصیص ممنوع محافظت می‌شوند.
- [ ] نقش سفارشی فقط permissionهای مجاز actor را می‌گیرد.
- [ ] permission در Backend برای هر عملیات enforce می‌شود.
- [ ] business/branch scope در همه routeهای مدیریتی رعایت می‌شود.
- [ ] entitlement مستقل از RBAC کنترل می‌شود.
- [ ] feature disabled داده تاریخی را حذف نمی‌کند.
- [ ] هیچ بسته تجاری/قیمت/ترکیب امکانات بدون تصمیم مالک ثبت نشده است.
- [ ] تنظیمات فاقد API عملیاتی نمایش داده نمی‌شوند یا صریحاً در حال تکمیل‌اند.
- [ ] audit، idempotency، CSRF و Same-Origin رعایت شده‌اند.
- [ ] حالت‌های خطا و RTL/responsive پوشش داده شده‌اند.

## 14. ثبت تغییرات

- 2026-11-20 — نسخه 0.1 بر اساس Auth/Role/User/Branch services، route middleware و schema تدوین شد.
- وضعیت: **آماده بازبینی معماری؛ بخش‌های فاقد API نیازمند تکمیل Backend**.
- این سند به معنی تکمیل UI تنظیمات یا اتصال Central/entitlement نیست.
