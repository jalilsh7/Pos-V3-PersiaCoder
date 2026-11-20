# مرکز مستندات طراحی — PersiaCoder POS V3

- شناسه: DESIGN-HUB-V3-001
- تاریخ: 2026-11-20
- وضعیت: فعال؛ مرجع واحد فهرست اسناد طراحی
- مخزن: `jalilsh7/Pos-V3-PersiaCoder`
- مسیر: `design/`

## هدف و مرجعیت

این پوشه مرجع طراحی UX/UI، زبان بصری، صفحات، جریان‌ها و معیارهای پذیرش است. مرجع تصمیمات محصول همچنان `FINAL_PRODUCT_DECISIONS_FA.md` است و در تعارض، تصمیمات محصول اولویت دارند.

وضعیت طراحی از وضعیت کد، تست و آمادگی انتشار مستقل است. «آماده نمونه‌سازی» به معنی پیاده‌سازی یا PASS شدن تست نیست.

## اسناد اصلی

| شناسه | فایل | کاربرد | وضعیت |
|---|---|---|---|
| D-01 | [DESIGN_SYSTEM_V3_FA.md](DESIGN_SYSTEM_V3_FA.md) | سیستم طراحی و اصول بصری | پایه اولیه؛ نیازمند نمونه‌سازی و اعتبارسنجی |
| D-02/D-03 | [UI_SHELL_DASHBOARD_V3_FA.md](UI_SHELL_DASHBOARD_V3_FA.md) | پوسته، ناوبری نقش‌محور و داشبورد | آماده نمونه‌سازی |
| D-04/D-05 | [POS_SALES_WORKSPACE_V3_FA.md](POS_SALES_WORKSPACE_V3_FA.md) | UX صندوق و فروش در دسکتاپ/تبلت/نمایشگر کوچک | آماده نمونه‌سازی |
| D-06 | [PAYMENT_AND_RECEIPT_UX_V3_FA.md](PAYMENT_AND_RECEIPT_UX_V3_FA.md) | پرداخت POS، رسید، unknown و refund | آماده نمونه‌سازی؛ پیاده‌سازی پرداخت باید جداگانه verify شود |
| D-07 | [ORDER_CENTER_AND_DETAILS_UX_V3_FA.md](ORDER_CENTER_AND_DETAILS_UX_V3_FA.md) | مرکز سفارش، فیلترها، جزئیات، status timeline و اقدامات | آماده نمونه‌سازی |
| D-08 | [CATALOG_ADDONS_AND_RECIPES_UX_V3_FA.md](CATALOG_ADDONS_AND_RECIPES_UX_V3_FA.md) | دسته/محصول/تنوع/افزودنی و نسخه‌بندی رسپی | کاتالوگ و API پایه رسپی آماده نمونه‌سازی؛ UI رسپی و اتصال سفارش/انبار باقی |
| D-09 | [INVENTORY_AND_STOCK_MOVEMENTS_UX_V3_FA.md](INVENTORY_AND_STOCK_MOVEMENTS_UX_V3_FA.md) | مواد اولیه، مکان، تأمین‌کننده، اسناد، posting، valuation و ledger | آماده بازبینی و نمونه‌سازی |
| D-10 | [CUSTOMERS_LOYALTY_AND_SMS_UX_V3_FA.md](CUSTOMERS_LOYALTY_AND_SMS_UX_V3_FA.md) | مشتری، وفاداری، consent/opt-out و پیامک | آماده بازبینی مفهومی؛ Backend لازم است |
| D-11 | [FINANCE_CASH_AND_PAYMENTS_UX_V3_FA.md](FINANCE_CASH_AND_PAYMENTS_UX_V3_FA.md) | صندوق/شیفت، پرداخت، refund، هزینه و settlement | آماده بازبینی مفهومی؛ تکمیل Backend مالی لازم است |
| D-12 | [SETTINGS_USERS_ROLES_AND_ENTITLEMENTS_UX_V3_FA.md](SETTINGS_USERS_ROLES_AND_ENTITLEMENTS_UX_V3_FA.md) | تنظیمات، کاربران، RBAC و Central entitlement | آماده بازبینی مفهومی؛ UI و entitlement لازم است |
| D-13 | [STOREFRONT_AND_CUSTOMER_CHECKOUT_UX_V3_FA.md](STOREFRONT_AND_CUSTOMER_CHECKOUT_UX_V3_FA.md) | قالب عمومی منودار، cart، checkout، fulfillment و پرداخت | آماده نمونه‌سازی مفهومی؛ public checkout لازم است |
| D-14 | [KITCHEN_KDS_AND_FULFILLMENT_UX_V3_FA.md](KITCHEN_KDS_AND_FULFILLMENT_UX_V3_FA.md) | KDS، آماده‌سازی، سالن/میز و تحویل | آماده بازبینی مفهومی؛ KDS و تحویل لازم است |
| D-15 | [INTEGRATIONS_AND_HARDWARE_UX_V3_FA.md](INTEGRATIONS_AND_HARDWARE_UX_V3_FA.md) | Agent، کارتخوان، driver، چاپگر، ایستگاه و رسانه | آماده بازبینی معماری؛ runtime سخت‌افزار لازم است |
| D-16 | [FINAL_DESIGN_REVIEW_AND_DEVELOPMENT_HANDOFF_V3_FA.md](FINAL_DESIGN_REVIEW_AND_DEVELOPMENT_HANDOFF_V3_FA.md) | traceability، quality gates و تحویل طراحی به توسعه | گزارش اولیه؛ تست و تأیید مالک باقی است |

## اسناد راهبری

- [UX Blueprint](UX_BLUEPRINT_V3_FA.md)
- [Design Backlog](DESIGN_BACKLOG_V3_FA.md)
- [سند یکتای تصمیمات محصول](../FINAL_PRODUCT_DECISIONS_FA.md)
- [معماری کلان](../ARCHITECTURE_MASTER_V3_FA.md)
- [نقشه راه](../ROADMAP_V3.md)
- [دفتر تحویل و نقطه ادامه](../AI_HANDOFF_FA.md)

## قواعد نگهداری

1. هر بسته طراحی باید هدف، نقش‌ها، ساختار، داده، اقدامات، خطاها، empty/loading، دسترس‌پذیری، RTL و responsive را پوشش دهد.
2. تصمیمات بصری مشترک در Design System نگهداری شوند.
3. وضعیت هر سند: پیشنویس، در حال طراحی، آماده بازبینی، آماده نمونه‌سازی، تأییدشده یا منسوخ.
4. تأیید طراحی، پیاده‌سازی یا تست را تأیید نمی‌کند.
5. تغییر تصمیم محصول ابتدا در سند یکتای تصمیمات ثبت شود.
6. لینک فقط به سند canonical داده شود؛ فایل‌های تکراری/قدیمی در فهرست اصلی نیایند.
7. هر تغییر مهم دارای شناسه، تاریخ و خلاصه اثر باشد.

## ترتیب کلی

1. تثبیت Design System و پوسته.
2. POS، پرداخت و مرکز سفارش.
3. کاتالوگ، رسپی و انبار.
4. مشتری، وفاداری و پیامک.
5. مالی و RBAC/تنظیمات.
6. Storefront، KDS و سخت‌افزار.
7. traceability، نمونه‌سازی، تست و تحویل.
