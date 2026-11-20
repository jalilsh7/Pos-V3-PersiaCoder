# PersiaCoder POS V3

مخزن مستقل محصول تجاری **PersiaCoder POS V3** و مرجع اصلی توسعه نسخه سوم.

## مرزبندی مخزن

- `v3/backend/` — هسته PHP و API نسخه سوم
- `v3/frontend/` — رابط مستقل React/TypeScript/Vite نسخه سوم
- `database/v3/` — schema، migration و ابزارهای نصب، backup و restore
- `scripts/` — تست‌ها و اسکریپت‌های اعتبارسنجی V3
- `.github/workflows/v3-*.yml` — CI اختصاصی V3
- فایل‌های معماری و محصول `*_V3.md` — قراردادهای اصلی محصول
- `legacy/v2-reference/` — آرشیو snapshot نسخه قبلی برای بازیابی قابلیت‌ها؛ خارج از runtime و CI فعال

## انتقال به مخزن مستقل

- انتقال اولیه شامل 170 فایل منتخب V3 بود؛ README مقصد نیز در همان مرحله اضافه شد.
- در مرحله تکمیل مخزن، 89 فایل متنی/سورس دیگر از snapshot منبع با حفظ مسیر نسبی در `legacy/v2-reference/` آرشیو شدند.
- snapshot منبع: `jalilsh7/3Food-pos`، شاخه `feature/3food-pos-next-release-v2`، commit `4e82753cfe95f2824d75fbbcda738d74e3bf9f64`.
- یک ZIP باینری 716,768 بایتی به دلیل محدودیت انتقال متنی کپی نشده است؛ جزئیات در `legacy/v2-reference/ARCHIVE-EXCLUSIONS-FA.md` ثبت شده است.
- تاریخچه commitهای مخزن مبدأ منتقل نشده؛ محتوای فایل‌ها به‌صورت commitهای جدید ثبت شده‌اند.
- انتقال و آرشیو فایل‌ها به معنی موفقیت build، تست، پذیرش staging یا استقرار production نیست.

## اصول توسعه

- کد V2 را مستقیم وارد runtime V3 نکنید؛ از `legacy/v2-reference/` فقط به‌عنوان مرجع استفاده کنید.
- V3 باید مستقل، چندکسب‌وکاری/چندشعبه‌ای، امن و مبتنی بر دیتابیس مستقل هر مشتری باقی بماند.
- اطلاعات عملیاتی فروش مشتری نباید در دیتابیس Central ذخیره شود.
- برند محصول ثابت است: **PersiaCoder.ir** و در انتهای صفحات «Powered by PersiaCoder.ir»؛ قابلیت White-Label یا حذف برند اضافه نشود.
- تست‌ها و محدودیت‌های تأییدنشده را صریح گزارش کنید.

## وضعیت و شروع کار

- نقشه راه: [ROADMAP_V3.md](ROADMAP_V3.md)
- دستورالعمل مخزن: [AGENTS.md](AGENTS.md)
- دفتر تحویل: [AI_HANDOFF_FA.md](AI_HANDOFF_FA.md)
- گزارش تکمیل ساختار مخزن: [REPOSITORY_COMPLETION_AUDIT_FA.md](REPOSITORY_COMPLETION_AUDIT_FA.md)
- راهنمای آرشیو: [legacy/v2-reference/README.md](legacy/v2-reference/README.md)

برند: **PersiaCoder.ir** — Powered by PersiaCoder.ir
