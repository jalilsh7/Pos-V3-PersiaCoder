# POS V3 — Test Account Handoff

وضعیت: **آماده‌سازی مستند؛ انتقال repository انجام نشده است.**

## هدف

پس از آماده‌شدن نسخه قابل تست، کد V3 در یک repository جدا و خصوصی تحت حساب GitHub دوم قرار گیرد تا تست‌ها بدون دست‌زدن به شاخه اصلی/محیط production مخزن فعلی انجام شوند.

## مبدأ فعلی

- Repository: `jalilsh7/3Food-pos`
- Development branch: `feature/3food-pos-next-release-v2`
- V3 frontend root: `v3/frontend/`
- این شاخه محیط توسعه است؛ تا زمان تأیید صریح، `main` و production تغییر نمی‌کنند.

## روش پیشنهادی

برای تست مستقل، یک repository **Private** در حساب مقصد ایجاد شود. سپس یکی از دو روش زیر با توجه به دسترسی انتخاب شود:

1. **Private fork**: مناسب وقتی GitHub اجازه fork خصوصی از مبدأ را می‌دهد و می‌خواهیم رابطه upstream حفظ شود.
2. **Repository transfer یا import/mirror**: transfer مالکیت فقط وقتی مناسب است که مالکیت repository واقعاً باید عوض شود؛ برای تست موقت معمولاً private fork/import امن‌تر است. mirror/import می‌تواند تاریخچه commit را حفظ کند، اما تنظیمات repository، secrets، environmentها و دسترسی‌ها باید جداگانه بازبینی شوند.

هیچ روش نباید repository مبدأ را حذف، public یا به حساب دیگری منتقل کند مگر اینکه مالک صریحاً transfer مالکیت را درخواست کرده باشد.

## پیش‌نیازهای قبل از handoff

- [ ] تثبیت نسخه تست و انتخاب commit دقیق از شاخه توسعه.
- [ ] تولید و commit کردن `package-lock.json` معتبر و هماهنگ با Node 22.
- [ ] اجرای موفق `npm ci`, `npm run typecheck` و `npm run build` در CI دارای runner.
- [ ] تست مرورگر روی دسکتاپ، تبلت و موبایل؛ بررسی RTL و دسترس‌پذیری.
- [ ] مشخص‌کردن وضعیت backend/API: mock، staging یا متصل به سرویس آزمایشی؛ هیچ داده واقعی مشتری وارد نشود.
- [ ] آماده‌سازی دیتابیس کاملاً disposable و روش reset/backup/restore برای تست backend.
- [ ] مستند نصب و اجرای staging و فهرست environment variableها بدون مقدار secret.
- [ ] بازبینی history برای اطمینان از نبود credentials، token، کلید HMAC، dump دیتابیس یا اطلاعات شخصی.
- [ ] بررسی repository settings، Actions permissions، secrets، environments، branch rules و مجوزهای مقصد.

## Secrets و داده‌ها

- هیچ secret یا مقدار واقعی متغیر محیطی در Git ثبت نشود.
- `POS_V3_BACKUP_HMAC_KEY` فقط در GitHub Actions Secrets یا secret manager محیط تست تنظیم شود؛ حداقل ۳۲ بایت و جدا از repository.
- از اطلاعات مشتری/پرداخت واقعی استفاده نشود؛ داده‌های تست باید ساختگی و غیرقابل انتساب باشند.
- دسترسی repository مقصد تا حد امکان Private و محدود به اعضای تست باشد.

## مسئولیت‌ها و محدودیت انتقال

- انتقال repository به حساب دوم بدون مشخص‌شدن نام حساب مقصد و مجوز لازم انجام نمی‌شود.
- اتصال GitHub فعلی ممکن است به repository دوم دسترسی نداشته باشد؛ در آن صورت مالک باید repository مقصد را به اتصال GitHub مجاز کند یا دسترسی مناسب بدهد.
- هیچ GitHub token یا password از کاربر در گفتگو درخواست نمی‌شود.
- پس از آماده‌شدن مقصد، شاخه تست از commit تأییدشده ساخته می‌شود و صحت SHA و فایل‌ها در repository مقصد دوباره بررسی خواهد شد.

## وضعیت فعلی پذیرش

- UX-03 و UX-04 هنوز prototype تعاملی با داده ثابت هستند؛ Backend متصل نیست.
- GitHub Actions frontend در run `44609415972` پیش از اجرای هر step شکست خورد؛ بنابراین typecheck و build هنوز انجام نشده‌اند.
- نسخه فعلی برای استفاده عملیاتی یا تست تراکنش واقعی آماده نیست.


## آخرین وضعیت تست فنی — 2026-11-20

کد HEAD تست‌شده: `9e800c96f6c3f1f5aaee6d68ed18edeaf3529c8d`.

### نتایج موفق

- Backend Validation روی PHP 8.2، 8.3 و 8.4: [44698948479](https://github.com/jalilsh7/3Food-pos/actions/runs/44698948479).
- V3 Frontend TypeScript و production build: [44698948599](https://github.com/jalilsh7/3Food-pos/actions/runs/44698948599).
- V3 DB validation روی MySQL 8.4 و MariaDB 10.6: [44698942685](https://github.com/jalilsh7/3Food-pos/actions/runs/44698942685).
- PHP migration workflow شامل API integration، TypeScript، Unit، Lint، cPanel package build، Chromium smoke، performance audit و artifact: [44698948522](https://github.com/jalilsh7/3Food-pos/actions/runs/44698948522).
- دیتابیس disposable: installer، migration 001–013، checksum/idempotency، backup/restore، رد archive دست‌کاری‌شده و بازیابی BINARY nonce/tag.
- Inventory posting integration: خرید و میانگین موزون، تعدیل ورودی/خروجی، مصرف، انتقال با ارزش متوازن، ضایعات، reserved stock، return block، business/branch isolation، idempotency replay، rollback چندردیفی و race دو posting هم‌زمان.

### تست‌های مهم انجام‌شده

- [x] هم‌زمانی دو سند خروجی روی یک balance: دقیقاً یک موفق و دیگری رد.
- [x] جلوگیری از موجودی منفی و مصرف مقدار رزروشده.
- [x] rollback کامل balance، ledger و document state در posting چندردیفی ناموفق.
- [x] replay درخواست posting بدون ledger duplicate.
- [x] جداسازی business و branch در خواندن سند.
- [x] حفظ binary nonce/tag پس از backup/restore.

### موارد باقی‌مانده برای انتهای تست

- [ ] Load/soak تست با چندین branch و حجم بالای posting.
- [ ] Deadlock retry policy و observability در فشار بالا؛ تست فعلی فقط رفتار lock و rollback را ثابت می‌کند.
- [ ] Integration رزرو سفارش با مصرف مواد اولیه و آزادسازی رزرو.
- [ ] برگشت مشتری/تأمین‌کننده با source line و historical cost.
- [ ] تست browser end-to-end برای ورود، مجوزها، گردش سند و مدیریت خطا پس از اتصال UI به API.
- [ ] تست بازیابی بکاپ‌های واقعی نسخه ۱ قدیمی که پیش از binary v2 با داده باینری ساخته شده‌اند؛ در صورت وجود، ممکن است داده خراب‌شده قدیمی قابل بازیابی نباشد.

### آمادگی انتقال به حساب GitHub دوم

Build و CI فنی فعلی موفق‌اند، اما نسخه هنوز برای تست عملیاتی کامل آماده نیست: frontend V3 همچنان prototype است و Login/Me/Logout و ماژول Inventory به API واقعی متصل نشده‌اند. انتقال repository به حساب دوم تا انتخاب حساب مقصد و تکمیل FE-01 و اتصال Inventory UI انجام نمی‌شود.
