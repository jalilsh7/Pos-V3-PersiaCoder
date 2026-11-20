# طراحی UX مالی، صندوق‌ها و تطبیق پرداخت — PersiaCoder POS V3

- شناسه: DESIGN-FINANCE-CASH-2026-001
- نسخه: 0.1
- تاریخ: 2026-11-20
- بسته: D-11
- وضعیت: **آماده بازبینی؛ پرداخت/بازپرداخت و گزارش مالی نیازمند تکمیل Backend**
- مرجع محصول: `FINAL_PRODUCT_DECISIONS_FA.md`
- مراجع فنی:
  - `PAYMENT_LEDGER_V3.md`
  - `database/v3/schema.sql`
  - `v3/backend/src/Finance/CashRegisterService.php`
  - `v3/backend/src/Finance/CashRegisterController.php`
  - `v3/backend/src/Infrastructure/Database/CashRepository.php`
  - `v3/frontend/src/features/finance/FinanceWorkspace.tsx`
  - `v3/frontend/src/shared/api/client.ts`
  - `v3/backend/src/Orders/OrderService.php`
  - `v3/backend/public/index.php`

> این سند تجربه مالی را به دو حوزه تفکیک می‌کند: صندوق و شیفت نقدی که در Backend/UI فعلی تا حدی عملیاتی است؛ و دفتر پرداخت، refund، هزینه و reconciliation که schema/سند فنی برای آن وجود دارد اما service/API/UI انتهابه‌انتها در HEAD بررسی‌شده اثبات نشده است. وجود جدول مالی معادل عملیات مالی تکمیل‌شده نیست.

## 1. هدف و مرزبندی

کاربر باید بتواند:
- صندوق شعبه را انتخاب و شیفت را باز/بسته کند.
- وجه اول شیفت، دریافت/پرداخت دستی، مبلغ مورد انتظار و اختلاف شمارش را بفهمد.
- پرداخت‌های واقعی هر سفارش و مانده آن را مستقل از وضعیت عملیاتی سفارش مشاهده کند.
- refund را از لغو سفارش جدا انجام دهد.
- تراکنش‌های نامشخص و مغایرت را برای تطبیق پیگیری کند.

مرزبندی:
- **Cash Register/Session:** مدیریت وجه نقد فیزیکی و شمارش شیفت.
- **Payment Ledger:** دریافت وجه سفارش با روش cash/card/online/other، تراکنش و event.
- **Refund:** برگشت وجه مستقل از لغو سفارش.
- **Expense:** هزینه عملیاتی و ارتباط آن با صندوق/شیفت.
- **Settlement/Reconciliation:** تطبیق پرداخت provider/کارتخوان با مرجع بانکی.
- **Accounting:** حسابداری رسمی و دفتر کل کامل در محدوده این UX فرض نشده است.

## 2. وضعیت واقعی فعلی

### 2.1 صندوق و شیفت
Backend و UI فعلی:
- فهرست صندوق‌های شعبه.
- ایجاد صندوق با کد و نام.
- بازکردن شیفت با مبلغ اولیه.
- ثبت گردش نقدی دستی ورودی/خروجی با مبلغ مثبت و علت اجباری.
- بستن شیفت با مبلغ شمارش‌شده.
- اگر اختلاف صفر باشد status برابر `closed`؛ اگر اختلاف وجود داشته باشد `requires_review`.
- ثبت یادداشت بررسی و بستن شیفت نیازمند بررسی.
- عملیات create/open/close/manual movement/review در CashRegisterService با idempotency و audit پیاده شده‌اند.
- قفل ردیف و branch/business scope در مسیرهای سرویس/repository استفاده می‌شوند.

### 2.2 محدودیت FinanceWorkspace
- UI به API واقعی صندوق متصل است.
- صندوق‌ها، شیفت‌ها، دریافت/پرداخت دستی، بستن و بررسی اختلاف را نمایش می‌دهد.
- API فعلی در Workspace، فهرست گردش کامل، جزئیات تراکنش، هزینه‌ها، refund، پرداخت سفارش یا settlement را مصرف نمی‌کند.
- پیام UI نیز اعلام می‌کند هزینه‌ها و گزارش مالی در بسته‌های بعدی تکمیل می‌شوند.

### 2.3 پرداخت سفارش
- OrderService فعلی سفارش را با payment_status اولیه `unpaid` می‌سازد.
- در مسیر بررسی‌شده، ثبت پرداخت سفارش، payment event، cash movement متصل به payment و refund service دیده نشد.
- روش انتخابی سفارش یا payment_status به‌تنهایی اثبات دریافت وجه نیست.
- جدول‌های `pos_payments`, `pos_payment_events`, `pos_refunds`, `pos_settlement_batches` و settlement items در schema وجود دارند؛ اما API/service مربوط به آن‌ها در مسیرهای فعلی ثبت نشده است.
- جدول pos_cash_movements امکان اتصال به payment/refund/expense را دارد، ولی این FKها به معنی اتصال عملیاتی فعلی نیستند.

## 3. داشبورد مالی

صفحه اصلی «مالی و صندوق‌ها» با تب‌های:
1. صندوق‌ها و شیفت‌ها
2. گردش نقدی
3. پرداخت‌های سفارش
4. بازپرداخت‌ها
5. هزینه‌ها
6. تطبیق و مغایرت
7. گزارش‌ها

تب‌هایی که API واقعی ندارند باید با برچسب «در حال تکمیل» و بدون دکمه عملیاتی نمایش داده شوند؛ نمونه داده یا عدد فرضی مجاز نیست.

شاخص‌های آینده فقط با داده واقعی:
- صندوق‌های فعال و شیفت باز.
- وجه نقد مورد انتظار در شیفت‌های باز.
- تعداد شیفت نیازمند بررسی.
- دریافت‌های موفق به تفکیک روش.
- refundهای در انتظار/ناموفق.
- تراکنش‌های unknown/reconciliation_required.
- هزینه‌های ثبت‌شده و posted.

فروش، دریافت، refund، هزینه، مانده سفارش و اختلاف صندوق شاخص‌های متفاوت‌اند و نباید در یک عدد مبهم «درآمد» ادغام شوند.

## 4. مدیریت صندوق و شیفت

### فهرست صندوق‌ها
- نام و کد.
- وضعیت فعال.
- وضعیت شیفت: بدون شیفت، باز، نیازمند بررسی.
- مبلغ افتتاحیه و وجه مورد انتظار در شیفت فعال.
- فقط صندوق‌های scope شعبه جاری.

### ایجاد صندوق
- کد 2 تا 40 نویسه، حروف لاتین بزرگ/عدد/خط تیره/underscore.
- نام حداکثر 120 کاراکتر.
- کد در شعبه یکتا.
- ایجاد نیازمند مجوز `finance.manage`.

### بازکردن شیفت
- صندوق فعال و بدون شیفت فعال قبلی.
- مبلغ اولیه صحیح و نامنفی.
- ثبت opening float در cash movement در صورت مبلغ غیرصفر.
- هر صندوق حداکثر یک شیفت با وضعیت open یا requires_review دارد.
- UI پس از موفقیت، مبلغ افتتاحیه و expected cash را از پاسخ سرور refresh کند.

### گردش دستی نقدی
- جهت: دریافت یا پرداخت.
- مبلغ صحیح مثبت.
- علت اجباری، حداکثر 500 نویسه.
- فقط روی شیفت باز.
- پس از موفقیت، expected_cash از سرور refresh شود.
- این گردش دستی، پرداخت سفارش یا هزینه رسمی را جایگزین نمی‌کند؛ نوع و علت آن باید شفاف باشد.

### بستن شیفت
- صندوق‌دار مبلغ نقدی شمارش‌شده واقعی را وارد می‌کند.
- Backend اختلاف را به‌صورت counted - expected محاسبه می‌کند.
- اختلاف صفر: closed.
- اختلاف غیرصفر: requires_review و شیفت همچنان فعال/قابل بررسی باقی می‌ماند.
- UI مقدار expected، counted و difference را جدا نمایش دهد؛ علامت مثبت/منفی اختلاف حفظ شود.

### بررسی اختلاف
- فقط شیفت requires_review.
- یادداشت بررسی اجباری.
- ثبت actor مدیر/نقش مجاز و audit.
- پس از بررسی موفق، status به closed تغییر می‌کند.
- بررسی اختلاف نباید مقدار واقعی شمارش یا سابقه گردش را پاک کند.

## 5. گردش نقدی و دفتر صندوق

در طراحی آینده هر ردیف:
- زمان.
- نوع گردش: opening_float، sale_in، refund_out، expense_out، manual_in، manual_out، adjustment.
- مبلغ.
- شیفت و صندوق.
- عامل.
- علت/توضیح.
- مرجع payment/refund/expense در صورت وجود.

قواعد:
- منبع حقیقت گردش، cash movementهای ثبت‌شده در Backend است.
- ویرایش/حذف گردش posted ارائه نشود؛ اصلاح با رویداد/گردش جدید و reason انجام شود.
- گردش دستی نباید به‌عنوان فروش یا refund طبقه‌بندی شود.
- دریافت کارتخوان/آنلاین در وجه نقد مورد انتظار صندوق وارد نشود، مگر ثبت cash movement مربوطه طبق قرارداد واقعی انجام شود.
- API فهرست و صفحه‌بندی گردش در UI فعلی وجود ندارد؛ طراحی عملیاتی پس از ایجاد read API انجام شود.

## 6. دفتر پرداخت سفارش

### خلاصه سفارش
در جزئیات سفارش:
- مبلغ کل.
- مبلغ پرداخت‌شده موفق.
- مانده قابل پرداخت.
- مبلغ refund موفق.
- وضعیت پرداخت مستقل از وضعیت سفارش.
- روش و ردیف‌های هر دریافت.
- provider/reference و زمان دریافت در صورت وجود.

مبالغ باید از payment ledger و محاسبه سرور بیایند؛ از روی payment_method یا UI محاسبه نشوند.

### روش‌ها
- cash.
- bank_terminal.
- online.
- credit.
- other.

هر دریافت یک payment record مستقل است؛ یک سفارش می‌تواند چند پرداخت داشته باشد تا پرداخت ترکیبی پشتیبانی شود.

### وضعیت‌های schema فعلی
Payment row:
- pending
- authorized
- captured
- failed
- cancelled
- unknown
- partially_refunded
- refunded

Order summary payment_status در schema:
- unpaid
- partial
- paid
- refunded
- partially_refunded
- failed
- pending

این مقادیر عیناً از schema فعلی استخراج شده‌اند؛ قرارداد نهایی محاسبه وضعیت سفارش از ledger باید در Backend مشخص و تست شود. به‌خصوص ناسازگاری `partial` در schema با `partially_paid` در برخی typeها/کدهای فرانت یا مستندات باید پیش از اتصال UI یکسان شود.

### جریان ثبت دریافت
- انتخاب روش پرداخت و مبلغ توسط کاربر مجاز.
- Backend مبلغ قابل دریافت و مانده را دوباره محاسبه می‌کند.
- payment record و payment event در transaction واحد ثبت شوند.
- cash payment فقط پس از ثبت موفق payment، به cash movement و شیفت باز مرتبط شود.
- کارتخوان فقط پس از نتیجه واقعی provider/terminal با مرجع تراکنش ثبت captured شود.
- online فقط پس از server-to-server callback/query معتبر؛ redirect مرورگر کافی نیست.
- timeout/unknown نباید success یا failed فرض شود؛ وضعیت unknown/reconciliation_required و استعلام لازم است.
- idempotency در تمام مسیرها؛ retry کور ممنوع.

## 7. بازپرداخت و لغو سفارش

- لغو سفارش و refund دو عملیات مستقل‌اند.
- refund باید به payment موفق اصلی و order متصل باشد.
- مبلغ refund مثبت و مجموع refund موفق نباید از مبلغ captured بیشتر شود.
- refund کامل/جزئی و چندمرحله‌ای از نظر schema قابل مدل‌سازی است؛ عملیات واقعی هنوز API/service لازم دارد.
- statusهای refund در schema: requested، approved، processing، succeeded، failed، cancelled.
- روش بازپرداخت: cash، bank_terminal، online، other.
- دلیل اجباری؛ actor درخواست‌کننده و تأییدکننده ثبت شود.
- refund کارتخوان/درگاه فقط با پاسخ معتبر provider succeeded شود.
- refund نقدی فقط با نقش مجاز، تأیید صریح، علت و cash movement خروجی به شیفت مناسب ثبت شود.
- timeout refund نتیجه unknown تلقی شود؛ دوباره‌پرداخت خودکار ممنوع.
- سفارش پرداخت‌شده فقط پس از refund موفق یا فرایند مغایرت مجاز قابل لغو باشد؛ لغو به‌تنهایی وجه را برنمی‌گرداند.

## 8. هزینه‌ها

Schema فعلی:
- pos_expense_categories.
- pos_expenses با مبلغ، currency_code، payment_method، cash_session_id اختیاری، description، occurred_at، actor و status draft/posted/voided.
- pos_cash_movements می‌تواند به expense متصل شود.

در Backend فعلی، route/service مدیریتی هزینه در مسیرهای بررسی‌شده دیده نشد. بنابراین طراحی آینده:
- دسته هزینه.
- مبلغ و روش پرداخت.
- زمان وقوع.
- شرح و مدرک پیوست (در صورت API امن).
- اتصال به شیفت برای هزینه نقدی.
- پیش‌نویس/ثبت نهایی/ابطال طبق state machine و مجوزهای آینده.
- هزینه ثبت‌شده باید به گردش نقدی متصل شود؛ یک هزینه دوبار در expected_cash محاسبه نشود.

## 9. تطبیق و تسویه

Schema دارای:
- pos_settlement_batches.
- settlement items و match_statusهایی مانند unmatched، matched، amount_mismatch، duplicate، ignored، resolved.
- ارتباط بالقوه با provider/terminal/payment.

UX آینده:
- فهرست batchهای واردشده.
- منبع فایل/API و بازه.
- شمار تراکنش‌های تطبیق‌شده، نامنطبق، تکراری و حل‌شده.
- فهرست موارد اختلاف با مبلغ/زمان/reference.
- پیوند به payment/terminal transaction.
- ثبت نتیجه بررسی با actor، reason و audit.
- هیچ ردیف unmatched خودکار captured یا refund تلقی نشود.
- عملیات settlement فقط پس از API، import validation، provider contract و تست واقعی فعال شود.

## 10. گزارش‌های مالی

گزارش‌های عملیاتی آینده:
- دریافت وجه به تفکیک روش/صندوق/شیفت/کاربر/بازه.
- refund کامل و جزئی.
- مانده سفارش‌ها.
- گردش نقدی دستی.
- هزینه‌ها.
- اختلاف شمارش شیفت.
- تراکنش‌های نامشخص و reconciliation.
- تطبیق provider/terminal.

تعریف‌ها:
- فروش ناخالص، تخفیف، مالیات، فروش خالص، وجه دریافت‌شده، refund و هزینه جدا باشند.
- سفارش draft/unpaid فروش قطعی یا دریافت وجه نیست.
- payment captured لزوماً settlement بانکی نیست.
- اختلاف صندوق با اختلاف settlement بانکی یکی نیست.
- گزارش حسابداری رسمی، سود و زیان یا ترازنامه از این UX نتیجه‌گیری نمی‌شود تا مدل حسابداری و chart of accounts مصوب شود.

## 11. مجوز و امنیت

Routeهای فعلی:
- GET cash/registers: `finance.read`.
- POST cash/registers: `finance.manage`.
- POST open session: `cash.session.open`.
- PATCH close session: `cash.session.close`.
- POST manual movements/review: `finance.manage`.
- همه write routeها session، permission، CSRF و Same-Origin guard دارند.
- Branch scope برای صندوق الزامی است.

برای پرداخت/refund/expense/settlement، مجوزهای دقیق هنوز route ندارد و باید در RBAC مصوب و سمت سرور enforce شود. UI صرفاً کنترل دسترسی را منعکس می‌کند.

امنیت:
- idempotency، audit، قفل ردیف و transaction boundary.
- هیچ داده کارت (PAN/CVV/PIN) ذخیره نشود.
- provider reference و safe payload پالایش شوند.
- refund با maker-checker در صورت policy/نقش موردنیاز.
- secrets سمت سرور.
- business/branch ownership در همه queryها.

## 12. خطاها و حالت‌ها

- شیفت فعال قبلی: conflict و refresh.
- صندوق غیرفعال/خارج شعبه: not found امن.
- شیفت بسته: گردش جدید مجاز نیست.
- اختلاف شمارش: requires_review.
- مبلغ نامعتبر/منفی/بیش از محدوده: validation.
- payment timeout: unknown و استعلام.
- duplicate idempotency: پاسخ replay بدون ایجاد دوباره اثر.
- refund بیشتر از مانده قابل بازپرداخت: conflict.
- provider unavailable: پرداخت در وضعیت مبهم/ناموفق طبق پاسخ واقعی، نه حدس UI.
- permission denied/feature disabled.
- دسکتاپ: داشبورد و جدول؛ تبلت/موبایل: کارت‌های شیفت/پرداخت و فرم‌های تک‌ستونه.
- RTL و تفکیک مبلغ/شناسه فنی LTR.

## 13. موارد باز و مغایرت‌ها

1. اختلاف وضعیت payment summary: schema مقدار `partial` دارد، اما برخی typeهای فرانت/اسناد از `partially_paid` استفاده می‌کنند؛ قرارداد واحد لازم است.
2. schema payment/refund/settlement کامل‌تر از runtime فعلی است؛ هیچ endpoint عملیاتی payment/refund/settlement در routes بررسی‌شده مشاهده نشد.
3. CashRegisterService به‌تنهایی دریافت وجه سفارش را ثبت نمی‌کند؛ manual movement نباید جای payment ledger استفاده شود.
4. FinanceWorkspace فعلی expenses، cash movement history و payment ledger را نمایش نمی‌دهد.
5. schema expenses وجود دارد اما expense service/controller route در این بررسی پیدا نشد.
6. پرداخت آنلاین و کارتخوان تا اتصال واقعی provider و reconciliation نباید success فرض شوند.
7. سیاست اضافه‌پرداخت، split، credit/نسیه، چندارزی و rounding هنوز باز است.
8. دامنه حسابداری رسمی و chart of accounts هنوز تصمیم نشده است.
9. وضعیت session requires_review در listRegisters به‌عنوان active session برمی‌گردد؛ UI باید اجازه بازکردن شیفت جدید ندهد تا بررسی/بستن انجام شود.
10. وضعیت cash movementهای متصل به payment/refund/expense باید با ledger و session در transaction واحد هماهنگ شود.

## 14. معیار پذیرش

- [ ] صندوق‌ها و شیفت‌ها فقط داده واقعی شعبه را نشان می‌دهند.
- [ ] بازکردن/بستن شیفت، اختلاف و review با state واقعی Backend منطبق است.
- [ ] گردش دستی با فروش/پرداخت/هزینه اشتباه نمی‌شود.
- [ ] پرداخت سفارش از payment ledger و eventها خوانده می‌شود.
- [ ] status سفارش و status پرداخت مستقل‌اند.
- [ ] پرداخت نقدی با شیفت باز و cash movement یکتا هماهنگ می‌شود.
- [ ] پرداخت کارت/آنلاین فقط با نتیجه معتبر provider موفق می‌شود.
- [ ] timeout به وضعیت نامشخص و استعلام منجر می‌شود.
- [ ] refund مستقل از لغو و محدود به مبلغ captured است.
- [ ] هزینه‌ها به cash movement درست متصل می‌شوند.
- [ ] settlement mismatch به‌صورت جداگانه قابل بررسی است.
- [ ] هیچ گزارش مالی از seed/local state یا payment_method به‌تنهایی ساخته نمی‌شود.
- [ ] permission، branch scope، audit، idempotency و RTL رعایت شده‌اند.

## 15. ثبت تغییرات

- 2026-11-20 — نسخه 0.1 بر اساس CashRegisterService، CashRepository، FinanceWorkspace، schema و PAYMENT_LEDGER_V3.md تدوین شد.
- وضعیت: **آماده بازبینی؛ پرداخت/بازپرداخت و گزارش مالی نیازمند تکمیل Backend**.
- این سند به معنی فعال‌شدن payment ledger، refund، expense یا reconciliation نیست.
