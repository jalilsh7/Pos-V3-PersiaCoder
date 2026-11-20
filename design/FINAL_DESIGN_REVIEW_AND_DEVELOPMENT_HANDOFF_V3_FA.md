# بازبینی یکپارچگی طراحی و تحویل به توسعه — PersiaCoder POS V3

- شناسه: DESIGN-RELEASE-TRACEABILITY-2026-001
- نسخه: 0.1
- تاریخ: 2026-11-20
- بسته: D-16
- وضعیت: **گزارش بازبینی اولیه؛ نیازمند اجرای نمونه‌سازی، تست‌های فنی و تأیید مالک**
- مرجع تصمیمات: `FINAL_PRODUCT_DECISIONS_FA.md`
- مرجع اجرا: آخرین HEAD شاخه `main` و اسناد D-01 تا D-15

> D-16 نقطه تحویل طراحی به توسعه است، نه گواهی آمادگی تجاری. هیچ صفحه‌ای صرفاً با داشتن سند UX یا schema آماده انتشار محسوب نمی‌شود. وضعیت طراحی، وضعیت Backend، وضعیت Frontend و وضعیت تست جداگانه‌اند.

## 1. هدف D-16

- ردیابی هر تصمیم محصول تا طراحی، API، DB، مجوز، entitlement و تست.
- شناسایی اسناد تکراری، لینک‌های شکسته و ادعاهای منسوخ.
- تثبیت ترتیب نمونه‌سازی و توسعه بر اساس اولویت عرضه اولیه مالک.
- تعریف gateهای کیفیت، امنیت، تجربه و انتشار.
- تحویل بسته‌های قابل اجرا به تیم توسعه بدون ابهام در وضعیت واقعی قابلیت.

## 2. اصول غیرقابل مذاکره

1. فقط POS V3 توسعه فعال است؛ V2 مرجع تاریخی انتخابی است.
2. PersiaCoder.ir برند ثابت محصول است؛ Powered by PersiaCoder.ir حذف نمی‌شود و White-Label نداریم.
3. یک موتور سفارش مشترک برای POS، Storefront، تلفن و کانال‌های آینده.
4. دیتابیس عملیاتی مستقل برای هر مشتری؛ Central داده عملیاتی مشتری را نگهداری نمی‌کند.
5. username/password و احراز هویت واقعی سمت سرور؛ RBAC در Backend enforce می‌شود.
6. Central entitlement مرجع قابلیت تجاری است؛ RBAC و entitlement دو لایه جدا هستند.
7. بسته‌های فروش/ترکیب امکانات هنوز تعیین نشده‌اند؛ حدس یا hard-code ممنوع.
8. پیامک تبلیغاتی فقط با consent معتبر و opt-out؛ شماره موبایل یا checkbox پیش‌فرض رضایت نیست.
9. تغییرات مالی، موجودی، پرداخت و state transition باید idempotent و audit شوند.
10. طراحی باید فارسی، RTL، مینیمال، شیک، responsive و مناسب touch باشد.
11. هیچ پروتکل سخت‌افزاری بدون مدل/نسخه و مستندات واقعی قطعی نمی‌شود.
12. تست‌های حیاتی امنیت، مالی، موجودی، سفارش و داده حذف نمی‌شوند.

## 3. Traceability Matrix

| دامنه/تصمیم | سند UX | شواهد Backend/DB فعلی | وضعیت Frontend | شکاف اصلی | Gate لازم |
|---|---|---|---|---|---|
| برند، پوسته، RTL | D-01/D-02 | پوسته و سیستم UI در مخزن | نمونه‌های UI موجود | تثبیت Design Tokens و accessibility | Visual + a11y review |
| POS و موتور سفارش مشترک | D-04/D-05 | OrderService create و Catalog API | POS به catalog/order API وصل است | پرداخت، مشتری، تخفیف، موجودی و close loop | POS E2E |
| پرداخت و رسید | D-06/D-11 | schema payment/refund/cash؛ CashRegisterService | صندوق به API وصل؛ payment UI اثبات نشده | payment lifecycle و refund واقعی | Payment safety tests |
| مرکز سفارش | D-07 | list/detail/status history/transitions | مرکز کامل در مسیرهای بررسی‌شده اثبات نشده | UI، search، payment timeline | Order workflow tests |
| کاتالوگ/افزودنی | D-08 | CatalogService و routes دسته/محصول/variant/options | POS catalog مصرف می‌کند | UI مدیریت کامل/ detach/ترتیب | Catalog API/UI tests |
| رسپی | D-08 | RecipeService: GET recipe، create draft version، activate version | UI رسپی در مسیر بررسی‌شده اثبات نشده | UI، draft edit/list، order-reservation-consumption | Recipe integration tests |
| انبار و گردش | D-09 | Inventory/Document/Posting/Supplier services و ledger | InventoryWorkspace به API وصل؛ جمع موجودی فیزیکی/قابل استفاده اکنون بر اساس واحد پایه جدا شده | return/void، order integration؛ build/typecheck اصلاح UI هنوز لازم است | Inventory concurrency/valuation + UI build |
| مشتری و وفاداری | D-10 | pos_customers و customer_ledger schema | CustomersWorkspace seed/local | Customer API، ledger service و consent | CRM/ledger tests |
| پیامک | D-10 | LegacySmsParser فقط inbound parsing | UI اجرایی اثبات نشده | provider adapter، queue، delivery، consent/opt-out | Provider + consent tests |
| کاربران/RBAC | D-12 | Auth, Permission, User/Role services و middleware | UI کامل مدیریت اثبات نشده | user/role UI و تکمیل lifecycle | Security/RBAC tests |
| تنظیمات | D-12 | settings/history/flags schema | BusinessSettingsWorkspace preview/local | Settings API و policy | Config validation |
| Central entitlement | D-12 | schema/route اتصال Central اثبات نشده | UI وجود ندارد | signed entitlement, sync, backend gate | Entitlement tamper/offline tests |
| Storefront | D-13 | Catalog/Order داخلی؛ public checkout route اثبات نشده | StorefrontWorkspace seed/cart local | public tenant resolution, checkout/payment | Public checkout E2E |
| KDS/تحویل | D-14 | kitchen-status transition و DiningService؛ station/fulfillment schema پایه | KDS UI اثبات نشده | queue API, station flow, delivery | Kitchen concurrency tests |
| سخت‌افزار | D-15 | terminal agent/device/transaction و station schema | UI/agent runtime اثبات نشده | driver, agent protocol, printer queue | Hardware-in-loop |
| نصب/انتشار | D-16 | migrations/bootstrap/CI باید جدا ممیزی شوند | build/deploy pipeline باید verify شود | installer, upgrade, rollback, docs | Release gate |

## 4. وضعیت اسناد طراحی D-01 تا D-15

| بسته | دامنه | وضعیت طراحی | وضعیت پیاده‌سازی/تست |
|---|---|---|---|
| D-01 | Design System | پایه اولیه | نیازمند نمونه‌سازی و اعتبارسنجی |
| D-02 | پوسته و ناوبری | آماده نمونه‌سازی | UI و تست مستقل باید verify شود |
| D-03 | داشبورد و نقش‌ها | آماده نمونه‌سازی | UI و تست مستقل باید verify شود |
| D-04 | POS دسکتاپ | آماده نمونه‌سازی | POS API-connected؛ جریان end-to-end کامل نیست |
| D-05 | POS تبلت/کوچک | آماده نمونه‌سازی | responsive واقعی نیازمند تست |
| D-06 | پرداخت/رسید | آماده نمونه‌سازی | پرداخت سفارش/رسید نهایی اثبات نشده |
| D-07 | مرکز سفارش | آماده نمونه‌سازی | UI کامل و payment timeline باقی |
| D-08 | کاتالوگ/رسپی | آماده نمونه‌سازی مشروط | Catalog API و Recipe API پایه وجود دارند؛ UI رسپی و order-stock integration باقی |
| D-09 | انبار | آماده بازبینی/نمونه‌سازی | Backend و UI پایه وجود دارند؛ dimension totals و order integration باقی |
| D-10 | مشتری/وفاداری/SMS | آماده بازبینی مفهومی | Customer UI seed؛ SMS parser-only؛ backend CRM/consent/SMS باقی |
| D-11 | مالی/صندوق | آماده بازبینی مفهومی | Cash session API وجود دارد؛ payment/refund/expense/settlement کامل نیست |
| D-12 | تنظیمات/RBAC/entitlement | آماده بازبینی مفهومی | Auth/RBAC API وجود دارد؛ settings UI و Central entitlement باقی |
| D-13 | Storefront/checkout | آماده نمونه‌سازی مفهومی | preview local؛ public checkout/payment باقی |
| D-14 | KDS/تحویل | آماده بازبینی مفهومی | kitchen status و dining پایه؛ KDS/station/delivery UI/API باقی |
| D-15 | Hardware/integrations | آماده بازبینی معماری | schema پایه؛ driver/agent/printer runtime و device tests باقی |

## 5. ترتیب توسعه پیشنهادی بر اساس اولویت عرضه

اولویت تجاری مالک: **POS/فروش + انبار + مشتری/وفاداری + پیامک**. این ترتیب اجرایی با وابستگی فنی اصلاح می‌شود، نه اینکه اولویت محصول حذف شود.

### موج A — تثبیت فروش حضوری
1. تکمیل POS end-to-end: مشتری، fulfillment، تخفیف/قیمت، پرداخت و رسید.
2. اتصال پرداخت به cash session/movements و ثبت reconciliation.
3. مرکز سفارش و جزئیات با فیلترهای موجود.
4. اصلاح status mismatch و تست state machine.
5. تست E2E ثبت سفارش تا payment/cash/receipt.

### موج B — انبار و رسپی
1. اصلاح شاخص‌های dimension-aware در InventoryWorkspace.
2. تکمیل UI مدیریت رسپی روی API موجود.
3. تصمیم و پیاده‌سازی اتصال recipe snapshot به order item.
4. تعیین نقطه reserve/consume/release با تصمیم مالک/سیاست محصول.
5. تست posting، valuation، concurrency، transfer و rollback.

### موج C — مشتری و ارتباطات
1. Customer API و اتصال CustomersWorkspace.
2. Customer order history و ledger read/write.
3. تعریف policy وفاداری (نرخ‌ها/انقضا/مصرف) با تصمیم مالک.
4. consent/opt-out persistence و enforcement.
5. SMS provider adapter، queue، delivery tracking و reports.
6. تست duplicate send، provider unknown و consent race.

### موج D — آماده‌سازی عرضه مرحله‌ای
1. RBAC UI و settings API.
2. Central entitlement contract و backend enforcement.
3. Storefront public checkout پس از تثبیت order/payment.
4. KDS و fulfillment delivery.
5. hardware drivers پس از تعیین مدل‌های هدف و دریافت پروتکل.

## 6. Design-to-Development Handoff

هر بسته توسعه باید این ورودی‌ها را داشته باشد:
- تصمیم محصول و شناسه آن.
- سند UX و نسخه.
- نقش‌ها و مجوزهای لازم.
- entitlement/feature dependency.
- API contract و payload/error cases.
- DB entities/migrations و data ownership.
- state machine و transition matrix.
- idempotency/audit/security.
- empty/loading/error/permission/disabled/offline states.
- responsive/RTL/accessibility.
- تست‌های unit/integration/E2E و معیار pass.

هیچ بسته‌ای با «ظاهر آماده» تحویل توسعه نشود اگر قرارداد سرور یا رفتار خطا نامعلوم است.

## 7. Quality Gates

### UX / Visual
- RTL کامل و typography فارسی.
- صفحه‌های 360px موبایل، تبلت و دسکتاپ.
- touch target، keyboard focus، contrast و screen reader labels.
- حالت‌های empty/loading/error/permission/feature-disabled.
- عدم نمایش داده seed در production.
- سازگاری رنگ و spacing با Design System.

### Backend / Data
- migration از نسخه قبلی و نصب تازه.
- tenant/business/branch isolation.
- transaction boundaries و rollback.
- idempotency و replay safety.
- audit actor/before/after/reason.
- input validation و output privacy.
- no hard delete برای داده‌های مالی/سفارش/ledger.
- schema constraints با service rules سازگار باشند.

### مالی و موجودی
- مبلغ تومان و rounding.
- split/partial payment فقط طبق policy.
- payment unknown recovery و duplicate callback.
- refund amount limits و reconciliation.
- stock on-hand/reserved/available و جلوگیری از منفی.
- conversion factor snapshot و valuation.
- order cancellation/refund/stock compensation.

### امنیت
- session/CSRF/Origin.
- RBAC server-side و privilege escalation.
- entitlement signature/expiry/revocation.
- secret storage و rotation.
- rate limiting و anti-abuse.
- privacy masking/retention.
- hardware agent authentication و command replay.

### Performance / Reliability
- load tests با حجم هدف پس از تعیین معیار عددی.
- index/query review.
- pagination و عدم N+1.
- graceful failure provider/agent.
- offline policy و sync conflict.
- backup/restore و upgrade/rollback.

## 8. Release Readiness Checklist

- [ ] تصمیمات محصول و open items برای نسخه انتشار مشخص‌اند.
- [ ] Design System حداقل در سطح توکن‌ها و اجزای اصلی اعتبارسنجی شده است.
- [ ] POS فروش، پرداخت و رسید end-to-end تست شده‌اند.
- [ ] inventory ledger و order integration تست شده‌اند.
- [ ] Customer API و loyalty ledger تست شده‌اند.
- [ ] SMS consent/opt-out و provider delivery تست شده‌اند.
- [ ] RBAC و entitlement در Backend enforce شده‌اند.
- [ ] public storefront checkout و payment تست شده‌اند، اگر در scope انتشار هستند.
- [ ] KDS/fulfillment و hardware فقط در صورت تکمیل واقعی فعال‌اند.
- [ ] fresh install، upgrade، rollback و backup/restore تست شده‌اند.
- [ ] CI روی HEAD انتشار PASS است.
- [ ] مستندات نصب/مدیریت/بازیابی و privacy/security تکمیل‌اند.
- [ ] هیچ secret، seed data یا endpoint آزمایشی در build تولیدی باقی نمانده است.
- [ ] برند ثابت PersiaCoder.ir در تمام صفحات رعایت شده است.
- [ ] نسخه، changelog و artifact integrity verify شده‌اند.

## 9. ثبت تغییرات

- 2026-11-20 — ایجاد D-16، نسخه 0.1؛ traceability و quality gates بر اساس تصمیمات محصول و اسناد D-01 تا D-15.
- 2026-11-20 — اصلاح شاخص‌های موجودی ناهمگون در `InventoryWorkspace.tsx`؛ مقادیر اکنون بر اساس واحد پایه جدا نمایش داده می‌شوند. کد در commitهای `c876b1b` و `1dfa0ff` ثبت شد؛ build/typecheck/test هنوز pending است.
- وضعیت: گزارش بازبینی اولیه؛ نیازمند اجرای نمونه‌سازی، تست‌های فنی و تأیید مالک.
- این سند به معنی تکمیل فازهای فنی یا آمادگی تجاری نیست.
