# طراحی آشپزخانه، KDS، آماده‌سازی و تحویل — PersiaCoder POS V3

- شناسه: DESIGN-KITCHEN-FULFILLMENT-2026-001
- نسخه: 0.1
- تاریخ: 2026-11-20
- بسته: D-14
- وضعیت: **آماده بازبینی مفهومی؛ انتقال وضعیت آشپزخانه و مدیریت میز Backend پایه دارند، KDS/ایستگاه/تحویل عملیاتی اثبات نشده**
- مرجع محصول: `FINAL_PRODUCT_DECISIONS_FA.md`
- کدهای بررسی‌شده: `OrderService.php`، `OrderController.php`، `DiningService.php`، `DiningController.php`، `DiningRepository.php`، `v3/backend/public/index.php` و `database/v3/schema.sql`

> سفارش POS و Storefront باید یک شناسه و state machine مشترک داشته باشند. KDS یک نمای عملیاتی/صف از سفارش‌هاست، نه منبع حقیقت سفارش. در HEAD بررسی‌شده، مسیر kitchen-status برای انتقال به preparing/ready و API پایه سالن/میز/نشست وجود دارد؛ اما KDS frontend، routing ایستگاه، چاپ آشپزخانه، زمان آماده‌سازی و تحویل/پیک به‌صورت end-to-end اثبات نشده‌اند.

## 1. هدف

- نمایش سریع سفارش‌های آماده‌سازی به آشپزخانه.
- تفکیک سفارش‌ها بر اساس وضعیت واقعی، کانال، روش دریافت و در آینده ایستگاه.
- امکان مشاهده جزئیات اقلام، تنوع‌ها، افزودنی‌ها و یادداشت‌های سفارش.
- ثبت انتقال‌های مجاز آماده‌سازی در Backend.
- کمک به تحویل درست سفارش به مشتری/پیک.
- مدیریت میز و نشست سالن بدون قطع ارتباط با سفارش و پرداخت.

## 2. وضعیت واقعی موجود

### Order state machine
وضعیت‌های موجود:
- draft
- awaiting_payment
- accepted
- preparing
- ready
- completed
- cancelled
- rejected

انتقال‌های مجاز در کد:
- draft → awaiting_payment / accepted / cancelled / rejected
- awaiting_payment → accepted / cancelled / rejected
- accepted → preparing / ready / completed / cancelled
- preparing → ready / cancelled
- ready → completed / cancelled
- completed/cancelled/rejected → بدون انتقال بعدی

### Kitchen route
- `PATCH /api/v1/orders/{orderPublicId}/kitchen-status`
- session middleware و permission `kitchen.update`.
- مسیر kitchen در سرویس فقط انتقال به `preparing` یا `ready` را می‌پذیرد.
- سایر transitionها از order status route و مجوز `orders.update` انجام می‌شوند.
- هیچ transition جدیدی مانند «در صف»، «در حال بسته‌بندی»، «تحویل پیک» به state machine اضافه نشود مگر تصمیم/پیاده‌سازی مجزا.

### Dining / میز
- فهرست area و table با status available/occupied.
- ایجاد dining area با نام و sort_order.
- ایجاد table با code و capacity (1 تا 50).
- بازکردن table session؛ جلوگیری از نشست باز دوم.
- بستن نشست فقط وقتی سفارش‌های فعال باقی نمانده و سفارش completed پرداخت‌نشده وجود نداشته باشد.
- همه عملیات دارای scope شعبه، idempotency و audit.

### شکاف‌های موجود
- KDS frontend و queue view در مسیرهای بررسی‌شده پیدا نشد.
- station routing، prep timers، priority/SLA، course firing، bump/reopen و چاپگر آشپزخانه اثبات نشده‌اند.
- تحویل پیک، assign courier، proof of delivery و status مستقل تحویل در APIهای بررسی‌شده وجود ندارد.

## 3. نقش‌ها و نماها

### آشپزخانه
- صف سفارش‌های پذیرفته‌شده و آماده‌سازی.
- مشاهده اقلام، تعداد، تنوع و افزودنی.
- اقدام‌های محدود به preparing/ready طبق permission و state.
- بدون دسترسی به اطلاعات غیرضروری مشتری/پرداخت.

### صندوق‌دار
- مشاهده وضعیت آماده‌سازی برای پاسخ به مشتری.
- ثبت سفارش و تغییرات فقط از جریان سفارش و مجوز مربوط.

### مسئول تحویل / expeditor
- مشاهده سفارش‌های ready.
- تأیید تحویل فقط پس از تعریف وضعیت/endpoint واقعی.
- تفکیک pickup و delivery.

### مدیر شعبه
- مشاهده تمام صف‌ها و وضعیت‌ها.
- دسترسی به میزها/نشست‌ها و گزارش تأخیر در صورت وجود timestamp معتبر.

## 4. KDS — صفحه صف آشپزخانه

### ساختار
- ستون/تب «پذیرفته‌شده»، «در حال آماده‌سازی»، «آماده».
- این ستون‌ها projection از order_status هستند؛ وضعیت جدید دیتابیسی نیستند.
- فیلتر کانال و روش دریافت.
- مرتب‌سازی پیش‌فرض بر اساس زمان پذیرش/ثبت موجود در Backend.
- کارت سفارش: شماره، زمان، کانال، روش دریافت، اقلام و گزینه‌ها، یادداشت عملیاتی اگر API ارائه کند.
- زمان آماده‌سازی یا elapsed time فقط از timestamp معتبر؛ در نبود accepted/preparing timestamp نشان داده نشود.

### اقدامات
- «شروع آماده‌سازی» فقط برای سفارش accepted و از kitchen-status endpoint.
- «آماده شد» فقط برای preparing و از kitchen-status endpoint.
- بعد از اقدام، کارت از response تازه Backend به ستون جدید منتقل شود.
- اقدام همزمان چند دستگاه با 409/وضعیت قدیمی refresh شود.
- لغو سفارش در KDS نمایش read-only باشد؛ مجوز لغو و مسیر جداگانه orders.cancel.
- تکمیل سفارش/تحویل از مسیر orders.update فقط در صورت نقش/سیاست؛ آشپزخانه به‌صورت پیش‌فرض مجوز completed نگیرد.

### بروزرسانی زنده
- polling یا push پس از تصمیم فنی؛ polling با cursor/updated_at و backoff.
- زمان آخرین همگام‌سازی نمایش داده شود.
- قطع شبکه، صف آخرین داده cache شده را read-only کند؛ اقدام تغییردهنده در حالت offline ممنوع مگر offline policy رسمی تعریف شود.
- duplicate event و refresh نباید کارت تکراری بسازد.

## 5. کارت سفارش و اطلاعات

- شماره سفارش.
- کانال source_channel.
- روش fulfillment: dine_in/takeaway/delivery.
- زمان ایجاد و accepted_at در صورت موجود.
- اقلام با snapshot نام، تنوع، SKU، quantity.
- گروه/گزینه‌های انتخاب‌شده با نام snapshot.
- یادداشت آشپزخانه فقط در صورت فیلد/API معتبر؛ از notes مشتری یا آدرس برای همه نقش‌ها افشای بی‌مورد نشود.
- میز/نشست فقط برای سفارش table دارای table_session_id.
- نام/تلفن/نشانی مشتری فقط برای نقش و مرحله‌ای که نیاز عملیاتی دارد.

## 6. مسیر آماده‌سازی و تحویل

### مسیر پایه قابل استفاده
accepted → preparing → ready → completed

- سفارش می‌تواند از accepted مستقیماً ready یا completed شود طبق state machine فعلی؛ UI نباید این امکان کد را حذف کند مگر policy مصوب.
- completed پایان وضعیت سفارش فعلی است؛ معنای تحویل فیزیکی دقیق در کد تفکیک نشده است.
- در طراحی تحویل، ready و completed را با تحویل به پیک یا دریافت مشتری یکی فرض نکن.

### طراحی هدف تحویل
پس از تعیین قرارداد fulfillment:
- صف pickup آماده دریافت.
- صف delivery آماده اعزام.
- تخصیص courier و مشخصات تحویل در صورت تصمیم محصول.
- زمان خروج، تحویل و مدرک تحویل.
- تحویل ناموفق/بازگشت/لغو ارسال.
این وضعیت‌ها تا زمانی که model/API مستقل ایجاد نشده، فقط در roadmap باشند و به order_status اضافه نشوند.

## 7. مدیریت سالن و میزها

### نقشه سالن
- Areaها بر اساس sort_order و نام.
- میزها با کد، ظرفیت، فعال/غیرفعال و وضعیت available/occupied.
- نمایش session باز و زمان شروع.
- ایجاد Area و میز با کنترل کد یکتا و ظرفیت.
- انتخاب میز و بازکردن نشست؛ از نشست باز دوم جلوگیری شود.

### نشست میز
- یک نشست open برای هر میز.
- سفارش‌های میز با table_session_id مشترک به نشست مرتبط شوند.
- بستن نشست فقط وقتی هیچ سفارش غیرنهایی و هیچ سفارش completed با payment_status غیر paid باقی نمانده باشد.
- خطای table_session_has_open_orders با توضیح سفارش‌های باقی‌مانده و امکان رفتن به مرکز سفارش مدیریت شود.
- بستن نشست میز با بستن شیفت صندوق یا پرداخت سفارش اشتباه نشود.

### موارد باز
- ادیت/غیرفعال‌سازی Area و میز در API جاری بررسی‌شده مشاهده نشده.
- انتقال سفارش بین میزها/نشست‌ها تعریف نشده.
- ادغام/تفکیک صورتحساب میز و پرداخت گروهی تصمیم/پیاده‌سازی نشده.

## 8. ایستگاه‌ها و routing

طراحی هدف آینده:
- ایستگاه‌های آشپزخانه/بار/دسر.
- نگاشت category/product به station.
- سفارش چندایستگاهی با item-level state و زمان‌بندی.
- نمایش بخش مرتبط هر ایستگاه.
- یک سفارش ممکن است چند کارت ایستگاهی داشته باشد، اما order ID و order status اصلی مشترک بماند.

در schema و API بررسی‌شده، مدل station و item-level kitchen status اثبات نشده است. این بخش نباید با تقسیم مصنوعی سفارش به چند سفارش مستقل اجرا شود.

## 9. اولویت، زمان و هشدار

- اولویت سفارش بر اساس زمان/قانون مصوب؛ اولویت دستی فقط با permission و audit.
- SLA و prep time از داده‌های واقعی؛ میانگین‌سازی از داده ناقص ممنوع.
- هشدار تأخیر فقط وقتی timestamp و آستانه تعریف شده‌اند.
- زمان انتظار مشتری با زمان آماده‌سازی آشپزخانه متفاوت است.
- رنگ وضعیت همراه متن و نشان؛ از رنگ به‌تنهایی استفاده نشود.

## 10. چاپ و اعلان

- چاپ ticket آشپزخانه/بار از رسید مشتری جداست.
- چاپ مجدد باید واضح و قابل ردیابی باشد.
- printer routing به station و retry print job در Backend/agent نیاز دارد.
- اعلان صوتی/visual در KDS قابل پیکربندی و قابل خاموش‌کردن.
- SMS/notification مشتری از event معتبر و صف پیامک؛ شکست آن نباید state سفارش را rollback کند.
- در حال حاضر print job/agent/station API در مسیر بررسی‌شده اثبات نشده است.

## 11. حالت‌ها و خطاها

- صف خالی.
- سفارش جدید.
- در حال آماده‌سازی.
- آماده.
- لغوشده/ردشده.
- تغییر وضعیت همزمان (409).
- سفارش خارج از scope/یافت‌نشد.
- permission denied.
- feature disabled.
- شبکه قطع/داده stale.
- item details ناقص.
- میز غیرفعال یا session اشغال.
- بستن میز با سفارش فعال یا completed unpaid.
- خطای چاپ یا اعلان در صورت تکمیل ماژول‌های مربوط.

## 12. امنیت و پایداری

- KDS با session و kitchen.update فقط عملیات مجاز را اجرا کند.
- route عمومی یا بدون auth برای kitchen وجود نداشته باشد.
- scope شعبه در فهرست و تغییر وضعیت enforce شود.
- idempotency key برای هر transition؛ stale state و retry کور ممنوع.
- audit actor، from/to status و request ID.
- اطلاعات شخصی مشتری حداقلی و نقش‌محور.
- KDS در حالت offline فقط read-only تا تعیین offline policy.

## 13. کارهای Backend و Frontend باقی‌مانده

1. Kitchen queue API با filters، cursor، branch scope و projection order/items/options.
2. UI KDS و اتصال به routeهای واقعی.
3. timestampهای معتبر accepted_at/preparing_at/ready_at و تاریخچه لازم.
4. انتخاب راهکار real-time/polling و مدیریت stale state.
5. station model، routing و item-level progress در صورت تأیید محصول.
6. قرارداد مستقل fulfillment/delivery/courier و وضعیت تحویل.
7. مدیریت کامل Area/Table و انتقال میز در صورت نیاز.
8. چاپگر/agent و print queue/reprint audit.
9. اعلان آشپزخانه و مشتری بر اساس outbox.
10. تست همزمانی transition، چند KDS، scope شعبه، close table guard و offline/read-only.

## 14. معیار پذیرش

- [ ] KDS فقط سفارش‌های شعبه مجاز را می‌بیند.
- [ ] صف بر اساس order_status واقعی ساخته می‌شود.
- [ ] start/preparing و ready فقط از route مجاز و permission kitchen.update اجرا می‌شوند.
- [ ] transition جدیدی بدون تغییر backend/state machine اضافه نشده است.
- [ ] order status، payment status و fulfillment جدا نمایش داده می‌شوند.
- [ ] snapshot اقلام/گزینه‌ها ثابت می‌ماند.
- [ ] conflict همزمان refresh می‌کند و کارت duplicate نمی‌سازد.
- [ ] زمان‌ها فقط از timestamp معتبر نمایش داده می‌شوند.
- [ ] نشست میز تا پایان سفارش‌های فعال و پرداخت سفارش completed بسته نمی‌شود.
- [ ] تحویل فیزیکی از completed استنتاج نشده است.
- [ ] ایستگاه/چاپ/پیک تا وجود API واقعی به‌عنوان قابلیت عملیاتی معرفی نمی‌شوند.
- [ ] offline حالت read-only است مگر policy رسمی.
- [ ] RTL، touch، accessibility و حالات خطا پوشش داده شده‌اند.

## 15. ثبت تغییرات

- 2026-11-20 — نسخه 0.1 بر اساس OrderService state machine، DiningService و routeهای جاری.
- وضعیت: آماده بازبینی مفهومی؛ KDS UI، station routing و fulfillment delivery باقی است.
- این سند به معنی تکمیل فاز 8 یا تست عملیاتی آشپزخانه نیست.
