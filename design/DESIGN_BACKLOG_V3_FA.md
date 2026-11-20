# بک‌لاگ طراحی — PersiaCoder POS V3

- شناسه: DESIGN-BACKLOG-V3-001
- تاریخ: 2026-11-20
- وضعیت: فعال؛ فهرست canonical بسته‌های D-01 تا D-16

| شناسه | بسته طراحی | وضعیت طراحی | خروجی/معیار پذیرش |
|---|---|---|---|
| D-01 | سیستم طراحی و زبان بصری | در حال طراحی | توکن‌ها، typography، spacing، components و قواعد RTL؛ اعتبارسنجی نمونه لازم است |
| D-02 | پوسته برنامه و ناوبری نقش‌محور | آماده نمونه‌سازی | مشخصات در UI_SHELL_DASHBOARD_V3_FA.md؛ نمونه بصری و تست responsive باقی است |
| D-03 | داشبورد مدیر و صفحه آغازین نقش‌ها | آماده نمونه‌سازی | مشخصات در UI_SHELL_DASHBOARD_V3_FA.md؛ اتصال داده و role-specific views باید verify شود |
| D-04 | صندوق POS دسکتاپ | آماده نمونه‌سازی | کاتالوگ/سبد، انتخاب محصول، مشتری، نوع سفارش و حالت‌های خطا در POS_SALES_WORKSPACE_V3_FA.md |
| D-05 | POS تبلت/نمایشگر کوچک | آماده نمونه‌سازی | پنل سبد، نوار اقدام، چیدمان و تعامل لمسی در POS_SALES_WORKSPACE_V3_FA.md |
| D-06 | پرداخت و رسید | آماده نمونه‌سازی | نقدی/کارتخوان، unknown، idempotency، رسید و refund در PAYMENT_AND_RECEIPT_UX_V3_FA.md |
| D-07 | مرکز سفارش‌ها و جزئیات | آماده نمونه‌سازی | فهرست چندکاناله، فیلتر API، جزئیات، timeline و state machine در ORDER_CENTER_AND_DETAILS_UX_V3_FA.md |
| D-08 | کاتالوگ، افزودنی و رسپی | آماده نمونه‌سازی مشروط | Catalog API و Recipe API پایه وجود دارند؛ UI کامل رسپی و اتصال سفارش به رزرو/مصرف انبار باقی است |
| D-09 | انبار، موجودی و اسناد گردش کالا | آماده بازبینی و نمونه‌سازی | مواد اولیه، مکان، تأمین‌کننده، سند، posting، valuation و ledger؛ dimension-safe totals و order integration باقی است |
| D-10 | مشتری، وفاداری و پیامک | آماده بازبینی مفهومی | Customer API، ledger، consent/opt-out و SMS queue/delivery باید تکمیل شوند |
| D-11 | مالی، صندوق و پرداخت | آماده بازبینی مفهومی | Cash session API وجود دارد؛ payment/refund/expense/settlement عملیاتی و تست‌شده نیستند |
| D-12 | تنظیمات، کاربران، نقش‌ها و entitlement | آماده بازبینی مفهومی | Auth/RBAC پایه وجود دارد؛ UI مدیریت، settings API و Central entitlement enforcement باقی است |
| D-13 | فروشگاه آنلاین و checkout مشتری | آماده نمونه‌سازی مفهومی | Storefront preview فعلی local است؛ public checkout، payment و اتصال end-to-end باقی است |
| D-14 | آشپزخانه، KDS و تحویل | آماده بازبینی مفهومی | kitchen-status و dining پایه وجود دارد؛ KDS، station API و delivery workflow باقی است |
| D-15 | یکپارچگی‌ها و سخت‌افزار | آماده بازبینی معماری | schema پایه terminal/agent/station وجود دارد؛ driver، agent runtime، printer queue و تست دستگاه باقی است |
| D-16 | بازبینی یکپارچگی و تحویل به توسعه | گزارش اولیه | traceability matrix و quality/release gates در FINAL_DESIGN_REVIEW_AND_DEVELOPMENT_HANDOFF_V3_FA.md؛ تست و تأیید مالک باقی است |

## اولویت نمونه‌سازی/توسعه

اولویت تجاری مالک: **POS/فروش + انبار + مشتری/وفاداری + پیامک**. ترتیب اجرا با وابستگی فنی تنظیم می‌شود، اما این اولویت محصول تغییر نمی‌کند.

1. POS end-to-end، پرداخت و مرکز سفارش.
2. کاتالوگ/رسپی و اتصال فروش به انبار.
3. Customer API، وفاداری و consent.
4. SMS provider/queue/delivery.
5. RBAC/settings و Central entitlement.
6. Storefront، KDS و hardware به‌صورت مرحله‌ای.

## قواعد

- «تأییدشده» فقط پس از بازبینی مالک.
- تأیید طراحی معادل تکمیل کد یا تست نیست.
- فایل‌های canonical در README فهرست می‌شوند؛ اسناد قدیمی/تکراری در بک‌لاگ فعال نمی‌مانند.
- تست‌های حیاتی امنیت، مالی، موجودی، سفارش و داده حذف نمی‌شوند.
- هر تغییر با شناسه و تاریخ ثبت شود.
