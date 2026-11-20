# طراحی UX آشپزخانه، آماده‌سازی و تحویل — PersiaCoder POS V3

- شناسه: DESIGN-KITCHEN-HANDOFF-2026-001
- نسخه: 0.1
- تاریخ: 2026-11-20
- بسته: D-14
- وضعیت: **آماده بازبینی و نمونه‌سازی؛ نیازمند تکمیل قابلیت‌های KDS پیشرفته**
- مرجع تصمیمات محصول: `FINAL_PRODUCT_DECISIONS_FA.md`
- وابستگی‌ها: D-07 مرکز سفارش، D-09 انبار، D-13 storefront، `PAYMENT_AND_RECEIPT_UX_V3_FA.md`.
- مراجع فنی: `OrderService`، `DiningService`، `DiningRepository`، `OrderRepository`، `KitchenBoard.tsx`، `OrderCenter.tsx` و routeهای dining/orders.

> در نسخه فعلی، KitchenBoard صف سفارش‌های accepted/preparing/ready را از Orders API می‌خواند و فقط transitionهای مجاز آشپزخانه را اجرا می‌کند. مدل ایستگاه‌های آماده‌سازی در schema وجود دارد، اما route/API و UI مدیریت ایستگاه، ارسال item به station، زمان‌بندی و چاپ KOT در مسیرهای بررسی‌شده اثبات نشده‌اند. سند میان KDS فعلی و KDS چندایستگاهی آینده تمایز می‌گذارد.

## 1. هدف و اصول

- سفارش ثبت‌شده از هر کانال باید به صف واحد آماده‌سازی وارد شود.
- وضعیت سفارش، وضعیت پرداخت و وضعیت fulfillment سه محور مستقل‌اند.
- آشپزخانه فقط transitionهای مجاز خود را انجام دهد؛ تکمیل نهایی سفارش در مرکز سفارش است.
- صف آشپزخانه از داده Backend تغذیه شود؛ optimistic transition بدون پاسخ قطعی ممنوع.
- UI سریع، خوانا، لمسی، کم‌متن و مناسب نمایشگر بزرگ آشپزخانه باشد.
- تأخیر یا قطعی پیامک/چاپگر نباید وضعیت سفارش را به‌صورت جعلی تغییر دهد.

## 2. وضعیت موجود در Backend و UI

### صف آشپزخانه
- UI با فیلتر وضعیت‌های accepted، preparing و ready سفارش‌ها را از API می‌گیرد.
- برای هر سفارش شماره، کانال، روش دریافت، زمان، مشتری، مبلغ و وضعیت پرداخت نمایش داده می‌شود.
- جزئیات اقلام، تنوع و گزینه‌ها از Order Details API خوانده می‌شود.
- اقدام آشپزخانه از accepted به preparing و preparing به ready است.
- route و سرویس Backend برای kitchen transition فقط preparing/ready را می‌پذیرند و permission `kitchen.update` را enforce می‌کنند.
- تکمیل نهایی (`completed`) در مرکز سفارش انجام می‌شود؛ آشپزخانه نباید آن را انجام دهد.
- UI فعلی refresh دستی دارد؛ push/websocket/auto-refresh در مسیر بررسی‌شده اثبات نشده است.

### سالن و میز
- Dining API فهرست area/table و ایجاد area/table را دارد.
- بازکردن session میز فقط وقتی میز فعال و session باز دیگری ندارد.
- بستن session میز فقط وقتی سفارش unfinished به آن session متصل نباشد.
- ظرفیت میز بین 1 تا 50 و code تا 20 کاراکتر است.
- UI مدیریت سالن/نقشه میز در featureهای بررسی‌شده مشاهده نشد؛ این مسیر باید جدا از KDS طراحی شود.

### داده‌های آماده‌سازی
- schema دارای order item preparation و station references است.
- وجود جدول به معنی API عملیاتی station assignment، item-level preparation، آماده‌سازی موازی یا چاپ آشپزخانه نیست.
- فعلاً وضعیت اصلی آشپزخانه در order-level نگهداری می‌شود.

## 3. صف اصلی آشپزخانه (KDS پایه)

### سربرگ و شاخص‌ها
- سفارش‌های فعال (accepted + preparing).
- در حال آماده‌سازی.
- آماده تحویل.
- زمان آخرین refresh و وضعیت اتصال.

### کارت سفارش
- شماره سفارش بزرگ و قابل مشاهده از فاصله.
- زمان ثبت و elapsed time محاسبه‌شده از زمان سرور.
- کانال سفارش: POS، تلفنی، ثبت دستی، میز، بیرون‌بر، storefront.
- روش دریافت: داخل سالن، بیرون‌بر، ارسال.
- نام مشتری در صورت وجود؛ اطلاعات تماس فقط برای نقش مجاز و نیاز عملیاتی.
- اقلام با نام snapshot، تنوع، تعداد و گزینه‌ها.
- یادداشت سفارش فقط اگر API آن را برگرداند.
- وضعیت پرداخت برای آگاهی، اما اقدام آشپزخانه بر اساس order status و permission؛ وضعیت پرداخت با وضعیت آماده‌سازی مخلوط نشود.

### ستون‌ها / گروه‌ها
- «در انتظار شروع» (accepted).
- «در حال آماده‌سازی» (preparing).
- «آماده تحویل» (ready).
- کارت پس از transition موفق به ستون بعدی منتقل شود؛ در صورت خطا جایگاه قبلی حفظ و داده refresh شود.

### اقدامات
- accepted: «شروع آماده‌سازی».
- preparing: «علامت‌گذاری آماده».
- ready: فقط نمایش «آماده تحویل؛ تکمیل نهایی در مرکز سفارش‌ها».
- لغو/رد و completed از KDS انجام نشود.
- درخواست transition دارای idempotency؛ timeout با استعلام وضعیت پیش از retry.
- خطای 403/409 باعث refresh و نمایش علت می‌شود.

## 4. زمان و اولویت

- زمان انتظار از created_at سرور محاسبه شود.
- آستانه‌های رنگی SLA فقط پس از تصمیم محصول/تنظیمات شعبه تعریف شوند؛ فعلاً عدد یا رنگ هشدار ثابت hard-code نشود.
- مرتب‌سازی پیش‌فرض قدیمی‌ترین سفارش فعال در اولویت آماده‌سازی می‌تواند پیشنهاد UX باشد، اما رفتار موجود UI فعلی جدیدترین را نشان می‌دهد؛ تغییر اولویت باید به‌عنوان تصمیم UX قابل بازبینی باشد.
- زمان پذیرش/شروع/آماده‌شدن از status_history فقط اگر event timestamp معتبر وجود داشته باشد نمایش داده شود.
- pause، bump، recall و priority override در API جاری بررسی‌شده وجود ندارند؛ دکمه نمایشی ساخته نشود.

## 5. جزئیات اقلام و آماده‌سازی

- هر item نام محصول snapshot، تنوع، quantity، line total و options را نشان دهد.
- گزینه‌ها به ترتیب snapshot و با نام گروه/گزینه نمایش داده شوند.
- itemهای تاریخی از تغییر کاتالوگ اثر نگیرند.
- grouping بر اساس محصول یا station تا زمانی که station assignment API وجود ندارد، فقط دسته‌بندی نمایشی مجاز نیست؛ داده تخصیص واقعی لازم است.
- اصلاح اقلام پس از پذیرش باید از Order API و policy مجاز عبور کند؛ آشپزخانه نباید سبد سفارش را محلی ویرایش کند.

## 6. تحویل به مشتری و وضعیت fulfillment

- order status و fulfillment status مستقل‌اند.
- ready به معنی completed یا تحویل قطعی نیست.
- fulfillment methods فعلی: dine_in، takeaway، delivery.
- snapshot گیرنده، تلفن، نشانی و delivery fee در سفارش/fulfillment می‌تواند نمایش داده شود، مشروط به مجوز.
- برای ارسال، مراحل dispatch/delivered نیازمند API/state machine مستقل و اثبات‌شده‌اند؛ UI فعلی KitchenBoard آن‌ها را مدیریت نمی‌کند.
- برای takeaway/dine_in، تأیید تحویل باید از مرکز سفارش یا ماژول تحویل انجام شود؛ ready به‌تنهایی پایان سفارش نیست.
- تکمیل سفارش فقط با transition مجاز در Order Center.

## 7. سالن و میز

### نمای میزها
- سالن/area و میز با code، ظرفیت و وضعیت.
- میز available یا دارای session باز.
- شروع session با مجوز orders.create و جلوگیری از session تکراری.
- بستن session با مجوز orders.update و فقط در صورت نبود سفارش فعال.
- سفارش‌های میز با table_session_id به همان نشست وصل شوند.
- انتقال میز، ادغام/تقسیم صورت‌حساب و جابه‌جایی سفارش فقط پس از API مستقل طراحی شوند.

### شکاف UI
- feature مدیریت area/table/session در frontend بررسی‌شده دیده نشد.
- تا ایجاد UI و API کامل، صفحه میزها را قابلیت عملیاتی معرفی نکن.

## 8. چاپ KOT و تجهیزات

- KOT باید snapshot اقلام و گزینه‌ها را برای آشپزخانه چاپ کند؛ رسید مشتری از KOT جداست.
- چاپ باید idempotent یا دارای job ID باشد؛ retry چاپ نباید سفارش جدید بسازد.
- وضعیت چاپ: queued/printed/failed/unknown فقط با job/driver response واقعی.
- چاپگر محلی، LAN و print service نیازمند معماری hardware integration و D-15 هستند.
- در نبود چاپگر، KDS همچنان مرجع مشاهده سفارش باشد؛ چاپ ناموفق وضعیت سفارش را تغییر ندهد.

## 9. چند ایستگاه و KDS پیشرفته — هدف آینده

پس از وجود API:
- تعریف stationهای kitchen/bar/dessert/packing.
- تخصیص هر order item به station با priority.
- زمان شروع/آماده‌سازی item-level و تجمیع وضعیت سفارش.
- هماهنگی چند ایستگاه و اعلام آماده‌شدن همه اقلام.
- bump/recall و انتقال بین stationها با audit.
- زمان آماده‌سازی هدف و هشدار SLA.
- نمایشگر لمسی، صدای اعلان قابل تنظیم و auto-refresh/push.
- سیاست اقلام حذف/اضافه‌شده پس از ارسال KOT.
این‌ها در schema یا UX target ممکن‌اند، اما تا route/service/test واقعی به‌عنوان قابلیت موجود عرضه نشوند.

## 10. حالت‌ها و واکنش‌گرایی

- loading صف و جزئیات.
- صف خالی و فیلتر بدون نتیجه.
- قطع ارتباط، stale data و زمان آخرین refresh.
- transition در حال انجام، success، 403، 409، timeout/unknown.
- سفارش cancelled/rejected/completed از صف فعال حذف و در history قابل مشاهده باشد.
- نمایشگر بزرگ: grid کارت‌های پرکنتراست با شماره سفارش بزرگ.
- تبلت: دو/سه ستون با scroll محدود و target لمسی بزرگ.
- موبایل: فهرست تک‌ستونه؛ فیلتر status در بالا.
- RTL و شناسه‌ها/زمان با جهت درست.
- حالت شب/کنتراست بالا برای آشپزخانه به‌عنوان گزینه طراحی، نه الزام پیاده‌شده.

## 11. شکاف‌های فنی و موارد باز

1. API list station، create/update station و item-to-station assignment.
2. API item preparation events و state machine مجزا.
3. auto-refresh/push و قرارداد stale cache.
4. مدیریت area/table/session در UI.
5. fulfillment dispatch/delivered workflow.
6. KOT print job، چاپگرها و retry/unknown.
7. SLA thresholds و اولویت سفارش.
8. هماهنگی چند ایستگاه و partial ready.
9. مجوزهای دقیق KDS و اطلاعات مشتری.
10. اتصال order online به KDS پس از storefront واقعی.
11. سیاست order cancellation پس از شروع آماده‌سازی و اثر انبار/پرداخت.
12. تست race transition و duplicate action، branch scope و order status.

## 12. معیار پذیرش طراحی

- [ ] صف KDS از سفارش‌های واقعی accepted/preparing/ready خوانده شود.
- [ ] اقلام/تنوع/گزینه‌ها از snapshot جزئیات سفارش نمایش داده شوند.
- [ ] فقط transitionهای مجاز آشپزخانه ارائه شوند.
- [ ] completed و لغو از KDS پایه انجام نشود.
- [ ] order/payment/fulfillment state مستقل باشند.
- [ ] زمان انتظار از timestamp سرور محاسبه شود.
- [ ] timeout و retry transition دوباره ایجاد نکند.
- [ ] ready به‌عنوان تحویل قطعی نشان داده نشود.
- [ ] area/table/session با API و permission واقعی مدیریت شوند.
- [ ] station assignment و item prep تا ایجاد API واقعی شبیه‌سازی نشوند.
- [ ] چاپ KOT با receipt مشتری اشتباه نشود و fail آن سفارش را تغییر ندهد.
- [ ] RTL، نمایشگر بزرگ، تبلت و موبایل پوشش داده شوند.

## 13. ثبت تغییرات

- 2026-11-20 — نسخه 0.1 بر اساس KitchenBoard، OrderCenter، DiningService، OrderService و schema فعلی.
- وضعیت: **آماده بازبینی و نمونه‌سازی؛ KDS چندایستگاهی و تحویل پیشرفته باقی است**.
- این سند تکمیل مدیریت سالن، چاپ KOT یا تست E2E را اثبات نمی‌کند.
