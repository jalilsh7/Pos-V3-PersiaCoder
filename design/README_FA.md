# مرکز مستندات طراحی — PersiaCoder POS V3

- شناسه: DESIGN-HUB-V3-001
- تاریخ: 2026-11-20
- وضعیت: فعال؛ نمایه canonical اسناد UX/UI
- مخزن: `jalilsh7/Pos-V3-PersiaCoder`
- مسیر: `design/`

## مرجعیت و قواعد

- تصمیمات محصول و محدوده قطعی فقط در [FINAL_PRODUCT_DECISIONS_FA.md](../FINAL_PRODUCT_DECISIONS_FA.md) نگهداری می‌شوند.
- این پوشه مرجع مشخصات UX/UI است؛ وضعیت واقعی پیاده‌سازی/تست فقط از کد، workflow و شواهد قابل بازتولید تعیین می‌شود.
- برای هر بسته فقط سند canonical جدول زیر مرجع جاری است. اسناد آرشیوی با سربرگ «منسوخ» صرفاً برای تاریخچه نگهداری می‌شوند.
- طراحی، نمونه‌سازی، پیاده‌سازی، تست و پذیرش مالک پنج وضعیت مستقل‌اند؛ آماده نمونه‌سازی به معنی تکمیل UI یا API نیست.
- در تعارض، سند تصمیمات محصول اولویت دارد؛ تعارض با کد باید ثبت و در roadmap حل شود.

## اسناد canonical

| بسته | سند | کاربرد | وضعیت |
|---|---|---|---|
| D-01 | [DESIGN_SYSTEM_V3_FA.md](DESIGN_SYSTEM_V3_FA.md) | زبان بصری، توکن‌ها، رنگ، تایپوگرافی، فاصله و اجزای پایه | پایه اولیه؛ نیازمند نمونه‌سازی و اعتبارسنجی |
| D-02/D-03 | [UI_SHELL_DASHBOARD_V3_FA.md](UI_SHELL_DASHBOARD_V3_FA.md) | پوسته برنامه، ناوبری نقش‌محور و داشبورد | آماده نمونه‌سازی |
| D-04/D-05 | [POS_SALES_WORKSPACE_V3_FA.md](POS_SALES_WORKSPACE_V3_FA.md) | فروش POS دسکتاپ/تبلت/نمایشگر کوچک | آماده نمونه‌سازی |
| D-06 | [PAYMENT_AND_RECEIPT_UX_V3_FA.md](PAYMENT_AND_RECEIPT_UX_V3_FA.md) | پرداخت POS، وضعیت unknown، رسید، چاپ مجدد و refund | آماده نمونه‌سازی |
| D-07 | [ORDER_CENTER_AND_DETAILS_UX_V3_FA.md](ORDER_CENTER_AND_DETAILS_UX_V3_FA.md) | مرکز سفارش چندکاناله، فیلتر، جزئیات و transitionهای مجاز | آماده نمونه‌سازی؛ بازبینی مالک باقی است |
| D-08 | [CATALOG_OPTIONS_AND_RECIPES_UX_V3_FA.md](CATALOG_OPTIONS_AND_RECIPES_UX_V3_FA.md) | دسته/محصول، تنوع، افزودنی و رسپی نسخه‌دار | آماده نمونه‌سازی مشروط؛ UI/API client و اتصال end-to-end باقی است |
| D-09 | [INVENTORY_AND_STOCK_MOVEMENTS_UX_V3_FA.md](INVENTORY_AND_STOCK_MOVEMENTS_UX_V3_FA.md) | مواد، واحدها، مکان، مانده، ledger، اسناد و posting | آماده بازبینی و نمونه‌سازی |
| D-10 | [CUSTOMERS_LOYALTY_AND_SMS_UX_V3_FA.md](CUSTOMERS_LOYALTY_AND_SMS_UX_V3_FA.md) | پرونده مشتری، سفارش، وفاداری، consent/opt-out و پیامک | آماده بازبینی مفهومی؛ Backend مشتری/پیامک باقی است |
| D-11 | [FINANCE_CASH_AND_RECONCILIATION_UX_V3_FA.md](FINANCE_CASH_AND_RECONCILIATION_UX_V3_FA.md) | صندوق/شیفت، دفتر پرداخت، refund، هزینه، settlement و مغایرت | آماده بازبینی؛ Backend مالی تکمیلی باقی است |
| D-12 | [ACCESS_CONTROL_SETTINGS_AND_ENTITLEMENTS_UX_V3_FA.md](ACCESS_CONTROL_SETTINGS_AND_ENTITLEMENTS_UX_V3_FA.md) | تنظیمات، کاربران، نقش‌ها، RBAC و entitlement Central | آماده نمونه‌سازی مرحله‌ای؛ entitlement runtime باقی است |
| D-13 | [STOREFRONT_AND_PUBLIC_CHECKOUT_UX_V3_FA.md](STOREFRONT_AND_PUBLIC_CHECKOUT_UX_V3_FA.md) | قالب عمومی صنفی، منو، سبد، checkout و سفارش آنلاین | آماده بازبینی مفهومی؛ public API/checkout باقی است |
| D-14 | [KITCHEN_KDS_AND_FULFILLMENT_UX_V3_FA.md](KITCHEN_KDS_AND_FULFILLMENT_UX_V3_FA.md) | KDS، صف آشپزخانه، آماده‌سازی، سالن/میز و تحویل | آماده بازبینی مفهومی؛ KDS/تحویل پیشرفته باقی است |
| D-15 | [INTEGRATIONS_AND_HARDWARE_UX_V3_FA.md](INTEGRATIONS_AND_HARDWARE_UX_V3_FA.md) | کارتخوان، agent، چاپگر و adapter سخت‌افزار | آماده بازبینی معماری؛ protocol واقعی دستگاه لازم است |
| D-15-SMS | [INTEGRATIONS_SMS_TERMINALS_AND_PRINTING_UX_V3_FA.md](INTEGRATIONS_SMS_TERMINALS_AND_PRINTING_UX_V3_FA.md) | ضمیمه فنی SmartSMS/SharedNumber، صف، delivery، consent و الزامات چاپ/KOT | ضمیمه مرجع؛ provider runtime باقی است |
| D-16 | [DESIGN_INTEGRATION_REVIEW_AND_HANDOFF_V3_FA.md](DESIGN_INTEGRATION_REVIEW_AND_HANDOFF_V3_FA.md) | بازبینی یکپارچگی، canonical map و موج‌های تحویل به توسعه | بازبینی اولیه انجام شد؛ تبدیل به task و نمونه‌سازی باقی است |
| — | [UX_BLUEPRINT_V3_FA.md](UX_BLUEPRINT_V3_FA.md) | نقشه کلان تجربه و معماری صفحات | در حال طراحی |
| — | [DESIGN_BACKLOG_V3_FA.md](DESIGN_BACKLOG_V3_FA.md) | وضعیت بسته‌ها و معیار پذیرش | فعال |

## اسناد مرتبط بیرون از پوشه

- [سند یکتای تصمیمات محصول](../FINAL_PRODUCT_DECISIONS_FA.md)
- [معماری کلان](../ARCHITECTURE_MASTER_V3_FA.md)
- [نقشه راه](../ROADMAP_V3.md)
- [دفتر تحویل](../AI_HANDOFF_FA.md)
- [مرجع فنی پیامک](../docs/integrations/NATIONAL-PAYAMAK-SMS-FA.md)

## قواعد نگهداری

1. هر صفحه باید هدف، نقش، ساختار، اقدام‌ها، داده، حالت خالی/loading/error، دسترس‌پذیری، واکنش‌گرایی و معیار پذیرش داشته باشد.
2. Design System مرجع توکن‌ها و اجزای مشترک است؛ اسناد ماژول‌ها نباید مقادیر متناقض تعریف کنند.
3. وضعیت هر سند باید یکی از «پیشنویس»، «در حال طراحی»، «آماده بازبینی»، «تأییدشده» یا «منسوخ» باشد.
4. هر تغییر مهم با شناسه و تاریخ ثبت شود.
5. تصمیم محصول ابتدا در سند یکتا ثبت می‌شود؛ این پوشه جایگزین آن نیست.
6. برای هر بسته فقط یک سند canonical در جدول معرفی می‌شود؛ نسخه‌های موازی باید به archive stub تبدیل شوند.
7. UI باید فارسی، RTL، لمسی، دسکتاپ و نمایشگر کوچک را پوشش دهد؛ برند ثابت PersiaCoder.ir حذف‌پذیر نیست.
