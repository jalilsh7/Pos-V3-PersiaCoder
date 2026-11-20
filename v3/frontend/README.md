# PersiaCoder POS V3 — Frontend workspace

این پوشه workspace مستقل رابط کاربری نسخه تجاری V3 است.

## مرز استقلال

- این frontend به runtime موجود V2 در ریشه مخزن وابسته نیست.
- هیچ فایل یا ماژولی از root `src/`، store قدیمی، API client قدیمی یا runtime V2 import نمی‌شود.
- backend و قرارداد API نسخه تجاری V3 هنوز به این رابط متصل نشده‌اند.
- رابط فعلی یک اسکلت طراحی/ناوبری است؛ ورود، داده عملیاتی، ثبت سفارش، پرداخت، موجودی و تنظیمات واقعی ندارد.
- تا زمان ایجاد قرارداد امن احراز هویت و API V3، این workspace نباید روی هاست مشتری منتشر شود.

## ساختار UI

- توکن‌های طراحی: `src/tokens.css`
- اجزای مشترک: `src/shared/ui/` (primitiveها، کنترل‌های فرم و جدول typed)
- مستند طراحی و قرارداد componentها: [DESIGN_SYSTEM.md](DESIGN_SYSTEM.md)

## اجرای محلی

نیازمند Node.js سازگار با Vite 8 و npm است.

```powershell
cd v3/frontend
npm install
npm run dev
```

## بررسی و build

```powershell
npm run typecheck
npm run build
```

در حال حاضر lockfile تولید نشده است؛ پس از اجرای `npm install` در محیط توسعه، فایل lock باید commit شود. تست واحد، lint، build و پذیرش مرورگر هنوز اجرا نشده‌اند. هیچ تست یا build موفقی صرفاً با ایجاد این فایل‌ها ادعا نمی‌شود.


## انتقال به حساب تست

برنامه انتقال امن به repository خصوصی حساب GitHub دوم و checklist پذیرش در [راهنمای handoff](../TEST_HANDOFF.md) ثبت شده است. انتقال تا آماده‌شدن نسخه تست و تعیین مقصد انجام نمی‌شود.
