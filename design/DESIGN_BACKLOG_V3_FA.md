# بک‌لاگ طراحی — PersiaCoder POS V3

- شناسه: DESIGN-BACKLOG-V3-001
- تاریخ: 2026-11-20
- وضعیت: فعال
- مرجع وضعیت: [DESIGN_INTEGRATION_REVIEW_AND_HANDOFF_V3_FA.md](DESIGN_INTEGRATION_REVIEW_AND_HANDOFF_V3_FA.md)
- مرجع تصمیمات محصول: [FINAL_PRODUCT_DECISIONS_FA.md](../FINAL_PRODUCT_DECISIONS_FA.md)

| شناسه | بسته طراحی | وضعیت | سند canonical / معیار خروجی |
|---|---|---|---|
| D-01 | سیستم طراحی و زبان بصری | در حال طراحی | DESIGN_SYSTEM_V3_FA.md؛ توکن‌ها و اجزای پایه با نمونه و قواعد RTL |
| D-02 | پوسته برنامه و ناوبری نقش‌محور | آماده نمونه‌سازی | UI_SHELL_DASHBOARD_V3_FA.md؛ shell و ناوبری نقش‌محور |
| D-03 | داشبورد مدیر و صفحه آغازین نقش‌ها | آماده نمونه‌سازی | UI_SHELL_DASHBOARD_V3_FA.md؛ داشبورد و حالت‌های نقش‌ها |
| D-04 | صندوق POS دسکتاپ | آماده نمونه‌سازی | POS_SALES_WORKSPACE_V3_FA.md؛ کاتالوگ، سبد، مشتری، نوع سفارش و جمع |
| D-05 | POS تبلت/نمایشگر کوچک | آماده نمونه‌سازی | POS_SALES_WORKSPACE_V3_FA.md؛ پنل سبد، چیدمان و تعامل لمسی |
| D-06 | پرداخت و رسید | آماده نمونه‌سازی | PAYMENT_AND_RECEIPT_UX_V3_FA.md؛ نقدی/کارتخوان، unknown، idempotency، رسید و refund |
| D-07 | مرکز سفارش‌ها و جزئیات | آماده نمونه‌سازی | ORDER_CENTER_AND_DETAILS_UX_V3_FA.md؛ چندکاناله، فیلتر، جزئیات، timeline وضعیت و transition مجاز |
| D-08 | کاتالوگ، تنوع، افزودنی و رسپی | آماده نمونه‌سازی مشروط | CATALOG_OPTIONS_AND_RECIPES_UX_V3_FA.md؛ طراحی آماده است؛ UI کامل تنوع/افزودنی/رسپی و API client فرانت‌اند باقی است |
| D-09 | انبار، موجودی و اسناد گردش کالا | آماده بازبینی و نمونه‌سازی | INVENTORY_AND_STOCK_MOVEMENTS_UX_V3_FA.md؛ اصلاح شاخص‌های dimension-aware، pagination و اتصال سفارش/رسپی باقی است |
| D-10 | مشتری، وفاداری و پیامک | آماده بازبینی مفهومی | CUSTOMERS_LOYALTY_AND_SMS_UX_V3_FA.md؛ Customer API/POS picker/net spend و SMS adapters/outbox/worker موجود؛ loyalty ledger، consent evidence، event templates و UI مدیریتی باقی است |
| D-11 | مالی، صندوق و تطبیق | آماده بازبینی | FINANCE_CASH_AND_RECONCILIATION_UX_V3_FA.md؛ cash session پایه موجود؛ payment linking، expense، refund، settlement و گزارش کامل باقی است |
| D-12 | تنظیمات، کاربران، نقش‌ها و entitlement | آماده نمونه‌سازی مرحله‌ای | ACCESS_CONTROL_SETTINGS_AND_ENTITLEMENTS_UX_V3_FA.md؛ RBAC Backend وجود دارد؛ UI مدیریت و Central entitlement enforcement باقی است |
| D-13 | فروشگاه آنلاین و checkout عمومی | آماده بازبینی مفهومی | STOREFRONT_AND_PUBLIC_CHECKOUT_UX_V3_FA.md؛ قالب عمومی/UX تعریف شده؛ public API، cart/quote/checkout و payment باقی است |
| D-14 | آشپزخانه، KDS و تحویل | آماده بازبینی مفهومی | KITCHEN_KDS_AND_FULFILLMENT_UX_V3_FA.md؛ kitchen transition پایه و KitchenBoard موجود؛ station routing/KOT/fulfillment پیشرفته باقی است |
| D-15 | یکپارچگی‌ها و سخت‌افزار | آماده بازبینی معماری | INTEGRATIONS_AND_HARDWARE_UX_V3_FA.md + ضمیمه پیامک INTEGRATIONS_SMS_TERMINALS_AND_PRINTING_UX_V3_FA.md؛ adapter/runtime و protocol واقعی باقی است |
| D-16 | بازبینی یکپارچگی و تحویل به توسعه | بازبینی اولیه انجام شد | FINAL_DESIGN_REVIEW_AND_DEVELOPMENT_HANDOFF_V3_FA.md؛ traceability و gapها ثبت شده؛ تبدیل به task، نمونه‌سازی و تأیید مالک باقی است |

## قواعد

- «آماده نمونه‌سازی» یا «آماده بازبینی» به معنی تأیید مالک، پیاده‌سازی یا PASS تست نیست.
- فقط اسناد canonical در README مرجع توسعه‌اند؛ اسناد موازی قدیمی باید stub منسوخ با لینک به سند مرجع باشند.
- وضعیت طراحی، پیاده‌سازی، تست و پذیرش جداگانه گزارش شوند.
- تصمیمات محصول و موضوعات باز باید با FINAL_PRODUCT_DECISIONS_FA.md منطبق بمانند.
- اولویت موج‌های توسعه طبق D-16 و اولویت عرضه مالک: POS/فروش، انبار، مشتری/وفاداری و پیامک؛ Storefront و سایر ماژول‌ها مرحله‌ای.
