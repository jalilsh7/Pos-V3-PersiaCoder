# طراحی UX مالی، صندوق‌ها و چرخه پرداخت — PersiaCoder POS V3

- شناسه: DESIGN-FINANCE-PAYMENTS-2026-001
- نسخه: 0.1
- تاریخ: 2026-11-20
- بسته: D-11
- وضعیت: **آماده بازبینی مفهومی؛ بخش صندوق جاری به API متصل است، پرداخت سفارش/هزینه/refund/settlement نیازمند تکمیل و تطبیق Backend**
- مرجع محصول: `FINAL_PRODUCT_DECISIONS_FA.md`
- کد بررسی‌شده: `v3/backend/src/Finance/CashRegisterService.php`، `CashRegisterController.php`، `CashRepository.php`، `v3/frontend/src/features/finance/FinanceWorkspace.tsx` و `database/v3/schema.sql`

> وجود جداول پرداخت، refund، expense و settlement در schema به‌تنهایی به معنی وجود سرویس عملیاتی یا اتصال آن‌ها به فروش POS نیست. در مسیرهای بررسی‌شده، Cash Register API برای صندوق/شیفت و گردش دستی واقعی وجود دارد. Payment/Refund/Settlement service و UI متناظر در این بررسی اثبات نشده‌اند. این سند این دو سطح را از هم جدا می‌کند.

## 1. اهداف و مرزبندی

مرکز مالی باید به کاربر کمک کند:
- صندوق و شیفت هر شعبه را مدیریت کند.
- وجه نقد مورد انتظار را با شمارش واقعی تطبیق دهد.
- گردش نقدی دستی را با دلیل ثبت کند.
- پرداخت‌های سفارش را با مرجع تراکنش واقعی دنبال کند.
- refund و تسویه provider را مستقل و قابل ممیزی بررسی کند.
- هزینه‌ها را در چارچوب صندوق و گزارش مالی ثبت کند.

مرزبندی مفهومی:
1. **Order status**: وضعیت انجام سفارش.
2. **Payment status**: وضعیت پرداخت سفارش و تراکنش‌ها.
3. **Cash session**: شیفت فیزیکی صندوق و وجه نقد مورد انتظار.
4. **Cash movement**: اثر نقدی ثبت‌شده در شیفت.
5. **Refund**: بازپرداخت مستقل مرتبط با payment.
6. **Settlement**: تطبیق پرداخت داخلی با گزارش provider.
7. **Expense**: هزینه ثبت‌شده و در صورت نقدی بودن، اثر آن بر صندوق.

هیچ‌کدام جایگزین دیگری نیستند. «سفارش تکمیل‌شده» الزاماً به معنی پرداخت موفق نیست و «پرداخت موفق» به معنی بسته‌شدن شیفت صندوق نیست.

## 2. وضعیت پیاده‌سازی قابل اثبات

### صندوق
- فهرست صندوق‌های شعبه و شیفت فعال/نیازمند بررسی از API خوانده می‌شود.
- ایجاد صندوق.
- بازکردن شیفت با مبلغ افتتاحیه.
- جلوگیری از بیش از یک شیفت فعال یا نیازمند بررسی برای هر صندوق.
- ثبت گردش نقدی دستی ورود/خروج با مبلغ مثبت و دلیل اجباری.
- بستن شیفت با مبلغ شمارش‌شده.
- محاسبه اختلاف شمارش با مبلغ مورد انتظار.
- اختلاف صفر → بسته‌شدن؛ اختلاف غیرصفر → `requires_review`.
- بررسی مدیریتی با یادداشت و بستن شیفت.
- عملیات نوشتن دارای idempotency و audit در service است.

### Frontend صندوق
`FinanceWorkspace.tsx` به API صندوق متصل است و ایجاد صندوق، بازکردن شیفت، گردش نقدی دستی، بستن و بررسی اختلاف را ارائه می‌کند. خود UI صریحاً می‌گوید ثبت دریافت/پرداخت دستی، هزینه‌ها و گزارش مالی در بسته‌های بعدی تکمیل می‌شوند.

### schema مالی
جداولی برای `pos_payments`، `pos_payment_events`، `pos_refunds`، `pos_settlement_batches`، `pos_settlement_items`، `pos_expenses` و `pos_cash_movements` وجود دارند. همچنین ارتباط cash movement به payment/refund/expense و یکتایی ارجاع‌ها در schema تعریف شده است.

### موارد اثبات‌نشده
- پرداخت سفارش POS از UI تا payment capture و تغییر payment_status.
- split payment و چند روش برای یک سفارش.
- اتصال موفقیت پرداخت نقدی/کارتخوان به cash movement.
- refund واقعی و ارتباط آن با cash movement/provider.
- ثبت و posting هزینه‌ها.
- import و reconciliation settlement.
- گزارش مالی قابل تطبیق با payment/cash ledger.

## 3. ناوبری پیشنهادی

- نمای کلی مالی
- صندوق‌ها و شیفت‌ها
- پرداخت‌ها
- بازپرداخت‌ها
- هزینه‌ها
- تسویه و مغایرت provider
- گزارش گردش مالی

در نسخه فعلی فقط بخش «صندوق‌ها و شیفت‌ها» با API قابل استفاده است. سایر گزینه‌ها تا زمان تکمیل endpoint و permission، باید در وضعیت «در حال تکمیل» بمانند و عملیات ساختگی نداشته باشند.

## 4. نمای کلی مالی

شاخص‌های آینده:
- دریافتی واقعی به تفکیک روش.
- مبلغ پرداخت‌نشده/در انتظار.
- refund موفق و در انتظار.
- وجه نقد مورد انتظار در شیفت‌های باز.
- اختلاف‌های نیازمند بررسی.
- هزینه‌های ثبت نهایی‌شده.
- settlementهای تطبیق‌نیافته.

قواعد:
- اعداد از ledger و payment records مرجع بیایند.
- سفارش draft، failed یا unpaid به‌عنوان فروش وصول‌شده محاسبه نشود.
- refund در gross receipts پنهان نشود؛ گزارش باید gross، refund و net را جدا کند.
- جمع وجوه نقد با مجموع پرداخت آنلاین/کارتخوان مخلوط نشود.
- اگر API aggregate وجود ندارد، شاخص نمایش داده نشود؛ از جمع local صفحه‌بندی‌شده عدد کل نساز.

## 5. صندوق‌ها و شیفت‌ها

### فهرست صندوق
- نام و کد.
- فعال/غیرفعال.
- وضعیت شیفت جاری: باز، نیازمند بررسی یا بدون شیفت فعال.
- مبلغ افتتاحیه و وجه نقد مورد انتظار برای شیفت جاری.
- زمان شروع.

### ایجاد صندوق
- کد 2 تا 40 حرف لاتین بزرگ/عدد/underscore/hyphen.
- نام الزامی تا 120 کاراکتر.
- کد در شعبه یکتا.
- ایجاد با idempotency و audit.

### بازکردن شیفت
- انتخاب صندوق فعال.
- مبلغ افتتاحیه غیرمنفی، عدد صحیح تومان.
- جلوگیری از بازکردن شیفت دوم برای صندوق دارای open یا requires_review.
- ثبت opening float در cash movement، اگر مبلغ افتتاحیه بیشتر از صفر باشد.
- پس از موفقیت، سرور مبلغ افتتاحیه و expected cash را برمی‌گرداند.

### گردش نقدی دستی
- دو حالت «دریافت نقدی» و «پرداخت نقدی».
- مبلغ مثبت و دلیل اجباری تا 500 کاراکتر.
- فقط برای شیفت باز.
- پرداخت نقدی بیشتر از وجه مورد انتظار رد می‌شود.
- ثبت گردش باید expected_cash_amount را در همان عملیات به‌روزرسانی کند.
- این عملیات دریافت/پرداخت دستی صندوق است؛ نباید به‌عنوان پرداخت یک سفارش یا هزینه رسمی تلقی شود.

### بستن شیفت
- مبلغ واقعی شمارش‌شده.
- محاسبه اختلاف = counted_cash_amount - expected_cash_amount.
- اختلاف صفر: بسته‌شده.
- اختلاف غیرصفر: `requires_review` و نیازمند بررسی.
- نمایش مبلغ مورد انتظار، مبلغ شمارش‌شده و اختلاف با علامت مثبت/منفی و توضیح.
- در وضعیت requires_review، شیفت جدید برای همان صندوق باز نشود.

### بررسی اختلاف
- فقط شیفت `requires_review`.
- یادداشت بررسی اجباری تا 500 کاراکتر.
- ثبت reviewer و تبدیل وضعیت به closed.
- بررسی، اختلاف تاریخی را حذف یا صفر نمی‌کند؛ صرفاً نتیجه بررسی و مسئول آن ثبت می‌شود.
- UI نباید با دکمه «تأیید» مقدار اختلاف را بی‌صدا تغییر دهد.

## 6. گردش‌های صندوق و اتصال به فروش

schema برای movement typeهای `sale_in`، `refund_out`، `expense_out`، `manual_in`، `manual_out`، `opening_float` و `adjustment` پیش‌بینی دارد.

هدف UX:
- گردش‌های مرتبط با پرداخت/بازپرداخت/هزینه از مرجع transaction/expense خودشان نمایش داده شوند.
- گردش دستی با دلیل و actor مستقل بماند.
- یک payment/refund/expense نباید بیش از یک cash movement متناظر بسازد؛ قید یکتا در schema برای referenceها وجود دارد.
- اتصال به شیفت درست بر اساس شعبه/صندوق و زمان معتبر باشد.
- پرداخت غیرنقدی نباید وجه نقد مورد انتظار صندوق را افزایش دهد.
- تا زمانی که API history گردش‌های صندوق ارائه نشده، UI فقط expected cash summary فعلی را نشان دهد؛ تاریخچه ساختگی نسازد.

## 7. پرداخت سفارش

### روش‌ها در schema
- cash
- bank_terminal
- online
- credit
- other

و source:
- pos
- storefront
- manual
- refund_reversal

وضعیت‌های payment:
- pending
- authorized
- captured
- failed
- cancelled
- unknown
- partially_refunded
- refunded

### UX هدف
- روش پرداخت در جریان تسویه سفارش انتخاب شود.
- مبلغ، مانده و روش‌ها از server response نمایش داده شوند.
- پرداخت نقدی تنها پس از ثبت موفق payment و cash movement موفق تلقی شود.
- کارتخوان: وضعیت‌های created/sent/approved/declined/cancelled/unknown/reversed از terminal transaction؛ نتیجه unknown با استعلام وضعیت حل شود و retry کور ممنوع باشد.
- پرداخت آنلاین storefront از پرداخت حضوری POS جدا بماند.
- پرداخت credit فقط در صورت policy نسیه مصوب و endpoint معتبر فعال شود.
- وضعیت سفارش و وضعیت پرداخت مستقل نمایش داده شوند.

### پرداخت ترکیبی
Schema امکان چند ردیف payment برای order را از نظر ساختار پشتیبانی می‌کند، اما split payment و قوانین مانده/اضافه‌پرداخت هنوز تصمیم باز هستند. تا تعیین policy و وجود service، UI پرداخت ترکیبی را عملیاتی معرفی نکند.

## 8. بازپرداخت (Refund)

### ساختار schema
`pos_refunds` به payment و order وصل است؛ amount، method، status، reason، idempotency key، provider reference، requester، approver و completed_at دارد.
وضعیت‌ها: requested، approved، processing، succeeded، failed، cancelled.
روش‌ها: cash، bank_terminal، online، other.

### UX هدف
- بازپرداخت از لغو سفارش مستقل است.
- انتخاب payment مرجع و نمایش مبلغ قابل بازپرداخت از server.
- مبلغ و دلیل اجباری.
- وضعیت درخواست، تأیید، پردازش، موفق/ناموفق/لغوشده.
- provider reference و زمان تکمیل.
- پس از refund موفق، وضعیت payment/order و cash movement مرتبط از Backend به‌روزرسانی شود.
- refund ناموفق یا pending به‌عنوان وجه برگشتی قطعی گزارش نشود.
- refund جزئی و چندمرحله‌ای فقط در صورت قرارداد و محدودیت مجموع مبلغ قابل بازپرداخت فعال شود.
- مجوز درخواست/تأیید refund باید جداگانه و سمت سرور enforce شود.

## 9. هزینه‌ها

### schema موجود
`pos_expenses` شامل دسته، مبلغ، currency_code، payment_method، cash_session_id، description، occurred_at، actor و status draft/posted/voided است.

### UX هدف
- دسته هزینه، مبلغ تومان، روش پرداخت، صندوق/شیفت مرتبط در صورت cash، تاریخ وقوع و شرح.
- draft برای ثبت اولیه؛ posted پس از تأیید و اثر مالی/نقدی واقعی.
- void فقط با مجوز، دلیل و ثبت audit؛ اگر endpoint موجود نیست، کنترل نمایش داده نشود.
- هزینه نقدی باید cash movement متناظر ایجاد کند؛ هزینه غیرنقدی وجه نقد شیفت را تغییر ندهد.
- هزینه posted با ویرایش مستقیم اصلاح نشود؛ سند اصلاحی/ابطال قابل ممیزی لازم است.
- در HEAD بررسی‌شده ExpenseService/Controller عملیاتی پیدا نشد؛ این فرم فعلاً طراحی هدف است.

## 10. تسویه و reconciliation

### schema موجود
- batch provider با business_date و file_fingerprint یکتا.
- settlement item با provider_reference، provider_amount، internal_amount، match_status و difference_amount.
- وضعیت batch: imported، matching، matched، review_required، closed.
- وضعیت item: unmatched، matched، amount_mismatch، duplicate، ignored، resolved.

### UX هدف
1. import فایل/داده provider و نمایش fingerprint.
2. بررسی preview و تعداد ردیف‌ها قبل از ثبت.
3. تطبیق بر اساس provider reference و مبلغ.
4. نمایش موارد unmatched، duplicate، mismatch و resolved.
5. ثبت دلیل و actor برای حل مغایرت.
6. بستن batch فقط پس از policy مشخص و عدم وجود موارد unresolved غیرمجاز.
7. داده provider و مقدار داخلی هر دو حفظ شوند؛ اصلاح تطبیق نباید raw record را بازنویسی کند.

در این بررسی Settlement service/UI و فرمت import provider اثبات نشده‌اند؛ هیچ دکمه import واقعی نمایش داده نشود.

## 11. گزارش‌ها

گزارش‌های آینده:
- خلاصه شیفت و گردش نقدی.
- پرداخت‌ها به تفکیک روش/provider/status.
- refund به تفکیک وضعیت/روش.
- هزینه‌ها به تفکیک دسته/روش.
- مغایرت شمارش صندوق.
- مغایرت settlement.
- جریان نقدی و net receipts با تعریف دقیق.

قواعد:
- زمان بر اساس timezone شعبه برای فیلتر روز کاری.
- همه مبالغ تومان صحیح؛ schema از `IRT` به‌عنوان نشانگر داخلی تومان استفاده می‌کند، نه semantics ریال ISO.
- مبلغ و تعداد تراکنش با هم نمایش داده شوند.
- گزارش از ledger/read model معتبر تغذیه شود؛ تغییر report نباید منبع حقیقت را عوض کند.

## 12. امنیت، مجوز و پایداری

- scope کسب‌وکار و شعبه در تمام عملیات enforce شود.
- عملیات ایجاد/بازکردن/بستن/حرکت دستی/بررسی شیفت دارای idempotency و audit باشند.
- مجوزهای پیشنهادی جدا: cash.register.manage، cash.session.open، cash.session.close، cash.movement.create، cash.session.review، payment.capture، refund.request، refund.approve، expense.post، settlement.import، settlement.resolve. این کدها تا ثبت در RBAC، مجوز واقعی محسوب نمی‌شوند.
- برای کارتخوان/درگاه، callback تکراری، timeout و unknown باید state machine و idempotency داشته باشند.
- هیچ PAN، CVV یا داده حساس کارت در UI/log ذخیره نشود.
- نمایش داده مالی به نقش مجاز محدود شود.
- خطای provider نباید سفارش/پرداخت موفق قبلی را پاک کند.
- نتیجه مبهم نیازمند استعلام است؛ retry جدید با idempotency متفاوت ممنوع تا تعیین نتیجه.

## 13. حالات UI

- Loading و refresh.
- صندوق بدون شیفت.
- شیفت باز.
- شیفت نیازمند بررسی.
- اختلاف صفر و غیرصفر.
- درخواست در حال انجام.
- conflict شیفت فعال.
- insufficient cash.
- permission denied.
- provider pending/unknown/failed.
- refund pending/failed/succeeded.
- settlement mismatch/unmatched.
- feature disabled.
- network timeout و نتیجه نامشخص.

## 14. معیار پذیرش

- [ ] صندوق و شیفت از API واقعی شعبه خوانده می‌شوند.
- [ ] فقط یک شیفت open/requires_review برای هر صندوق وجود دارد.
- [ ] opening float، manual movement و close/review دارای دلیل/مقدار معتبر و audit هستند.
- [ ] اختلاف شمارش با علامت و توضیح دقیق نشان داده می‌شود.
- [ ] manual cash movement با payment یا expense اشتباه نمی‌شود.
- [ ] payment status از order status جدا است.
- [ ] پرداخت نقدی/کارتخوان/آنلاین فقط پس از نتیجه معتبر سرور موفق محسوب می‌شود.
- [ ] unknown و timeout retry کور ندارند.
- [ ] refund مستقل، محدود به مبلغ قابل برگشت و متصل به payment/order است.
- [ ] expense و settlement فقط پس از وجود API واقعی فعال می‌شوند.
- [ ] گزارش‌ها از ledger و payment records واقعی استخراج می‌شوند.
- [ ] scope، RBAC، idempotency، audit، RTL و حالت‌های خطا پوشش داده می‌شوند.

## 15. موارد باز و وابستگی

1. split payment، اضافه‌پرداخت و سیاست نسیه.
2. policy تأیید refund، refund جزئی و روش بازگشت وجه.
3. نقطه اتصال پرداخت نقدی به cash movement و اثر بر expected cash.
4. مدیریت تراکنش unknown و reconciliation کارتخوان.
5. سرویس‌های Expense و Settlement و import provider.
6. دسترسی‌ها و ماتریس نقش مالی.
7. گزارش مالی و تعریف دقیق فروش وصول‌شده، net sales، refund و هزینه.
8. تطبیق ناسازگاری payment status `partial` و `partially_paid` ثبت‌شده در D-07.
9. تست‌های حیاتی concurrency شیفت، idempotency، double capture/refund، callback تکراری، cash reconciliation و decimal/rounding.

## 16. ثبت تغییرات

- 2026-11-20 — نسخه 0.1 بر اساس CashRegisterService/UI و schema مالی.
- وضعیت: آماده بازبینی مفهومی؛ این سند به معنی تکمیل payment/refund/expense/settlement یا پذیرش فاز 6 نیست.
- تست runtime جدید در این بسته اجرا نشده است.
