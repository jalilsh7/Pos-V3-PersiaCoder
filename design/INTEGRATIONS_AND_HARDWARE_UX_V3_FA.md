# طراحی یکپارچگی‌ها و سخت‌افزار — PersiaCoder POS V3

- شناسه: DESIGN-INTEGRATIONS-HARDWARE-2026-001
- نسخه: 0.1
- تاریخ: 2026-11-20
- بسته: D-15
- وضعیت: **آماده بازبینی معماری؛ مدل داده پایه برای کارتخوان/Agent و ایستگاه آماده‌سازی وجود دارد، adapter و runtime سخت‌افزار اثبات نشده**
- مرجع محصول: `FINAL_PRODUCT_DECISIONS_FA.md`
- مراجع بررسی‌شده: `database/v3/schema.sql`، `v3/backend/src/Orders/OrderService.php`، `v3/backend/src/Finance/CashRegisterService.php`، `v3/backend/src/Media/MediaService.php`، `MediaController.php` و routeهای `v3/backend/public/index.php`

> تصمیم محصول: کارتخوان معمولی باید از طریق LAN/Wi-Fi قابل اتصال باشد؛ مبلغ از POS ارسال و نتیجه واقعی تراکنش دریافت شود. پروتکل هر مدل فقط پس از شناسایی مدل/شرکت و مطالعه مستندات همان دستگاه قابل پیاده‌سازی است. در HEAD بررسی‌شده schema برای terminal agent، terminal، terminal transaction و preparation station وجود دارد؛ اما سرویس/route/agent runtime برای کارتخوان یا چاپگر در مسیرهای بررسی‌شده پیدا نشد.

## 1. هدف و اصول

- سخت‌افزار از طریق adapterهای مستقل و قابل توسعه به هسته متصل شود.
- منطق سفارش/پرداخت/انبار نباید به مدل خاص دستگاه وابسته شود.
- همه ارتباطات حساس از Backend/agent امن انجام شوند؛ Frontend مستقیم به دستگاه/secret provider متصل نشود.
- وضعیت اتصال و تراکنش برای اپراتور قابل فهم باشد.
- timeout یا قطع شبکه به معنی رد یا موفقیت تراکنش نیست؛ وضعیت unknown باید پشتیبانی شود.
- خرابی سخت‌افزار نباید order/payment ledger را خراب یا دوباره ثبت کند.
- مدل/پروتکل دستگاه‌ها بر اساس مستندات واقعی ثبت شود؛ حدس پروتکل ممنوع.

## 2. دسته‌های یکپارچگی

1. کارتخوان بانکی LAN/Wi-Fi/USB/Serial از طریق Terminal Agent.
2. چاپگر رسید مشتری، آشپزخانه و لیبل.
3. نمایشگر مشتری و نمایشگر آشپزخانه.
4. اسکنر بارکد و تجهیزات ورودی.
5. پیامک/providerهای ارتباطی.
6. رسانه و فایل‌های تصویری.
7. سرویس‌های بیرونی و APIهای تجاری آینده.

این بسته روی سخت‌افزار POS و الگوی adapter تمرکز دارد؛ integrationهای پیامک در D-10 و رسانه در D-08/D-13 تفصیل شده‌اند.

## 3. وضعیت داده کارتخوان در schema

### Terminal Agent
`app_terminal_agents`:
- public_id
- name
- token_hash
- enabled
- agent_version
- last_seen_at

### Payment Terminal
`pos_payment_terminals`:
- agent_id
- local_key
- name
- provider_code
- model_code
- driver_code
- connection_type: lan/wifi/usb/serial/agent
- host/port
- enabled/is_default

### Terminal Transaction
`pos_terminal_transactions`:
- terminal_id و order_id اختیاری
- idempotency_key
- amount و currency
- status: created/sent/approved/declined/cancelled/unknown/reversed
- provider_reference و trace_number
- safe_response
- created_by/requested_at/completed_at

این مدل داده پایه برای ثبت دستگاه و تراکنش را نشان می‌دهد؛ اما وجود schema، endpoint یا ارتباط واقعی با کارتخوان نیست.

## 4. UX مدیریت دستگاه‌ها

### فهرست Agentها
- نام Agent.
- نسخه نصب‌شده.
- وضعیت فعال/غیرفعال.
- آخرین heartbeat/last_seen_at.
- وضعیت احراز/اتصال.
- تعداد دستگاه‌های ثبت‌شده.

### ثبت Agent
- ایجاد identity و credential امن از طریق flow provisioning.
- secret فقط یک‌بار نمایش/تحویل امن؛ در دیتابیس hash نگهداری شود.
- امکان revoke و rotate token.
- محدودکردن Agent به نصب/شعبه/دستگاه‌های مجاز.
- مشاهده نسخه، آخرین heartbeat و health status.
- Agent غیرفعال یا credential revoke شده نباید command دریافت کند.

### فهرست کارتخوان
- نام نمایشی.
- provider_code/model_code/driver_code.
- connection_type.
- وضعیت فعال/پیش‌فرض.
- Agent مرتبط.
- host/port فقط برای نقش مجاز؛ اطلاعات شبکه در UI عمومی نمایش داده نشود.

### ثبت کارتخوان
- انتخاب Agent.
- انتخاب provider/model از registry واقعی driverهای نصب‌شده.
- connection type و پارامترهای لازم طبق پروتکل مستند.
- local_key یکتا در Agent.
- تست اتصال read-only و بدون تراکنش مالی.
- انتخاب default فقط در scope مجاز و با کنترل یکتایی سمت سرور.

تا زمان ایجاد API، UI ثبت دستگاه فقط نمونه مفهومی است؛ هیچ تنظیم host/port نباید در مرورگر به‌عنوان اتصال واقعی تلقی شود.

## 5. جریان پرداخت کارتخوان

### مسیر استاندارد
1. اپراتور سفارش و مبلغ نهایی معتبر Backend را انتخاب می‌کند.
2. POS کارتخوان فعال/پیش‌فرض مجاز را انتخاب می‌کند.
3. Backend یک terminal transaction با idempotency key می‌سازد.
4. Agent احراز‌شده command را دریافت می‌کند.
5. Agent از طریق driver مدل واقعی مبلغ را به کارتخوان می‌فرستد.
6. Agent پاسخ/نتیجه را با correlation ID به Backend برمی‌گرداند.
7. Backend نتیجه را validate و persist می‌کند.
8. Payment ledger و order payment_status فقط طبق نتیجه معتبر به‌روزرسانی می‌شوند.
9. UI نتیجه و trace/reference را به اپراتور نشان می‌دهد.

### وضعیت‌ها
- created: درخواست در Backend ساخته شده.
- sent: Agent ارسال را تأیید کرده؛ این وضعیت به معنی پرداخت موفق نیست.
- approved: پاسخ تأیید معتبر از کارتخوان.
- declined: پاسخ رد معتبر.
- cancelled: لغو قطعی طبق پروتکل.
- unknown: timeout/قطع ارتباط/پاسخ مبهم.
- reversed: reversal معتبر طبق پروتکل.

### وضعیت unknown
- پیام واضح «نتیجه تراکنش هنوز مشخص نیست».
- دکمه retry پرداخت تا زمان استعلام/تعیین تکلیف غیرفعال.
- استعلام وضعیت با reference/trace و همان transaction.
- تراکنش جدید برای همان سفارش تا policy روشن و نتیجه تراکنش قبلی، ریسک double charge دارد.
- پرداخت موفق تنها پس از verify پاسخ معتبر ثبت شود.

### محدودیت مهم
پروتکل LAN/Wi-Fi هر کارتخوان متفاوت است. هیچ endpoint، port، packet، command، checksum یا ترتیب پیام مشترکی بدون مستندات مدل فرض نشود.

## 6. Driver / Adapter Architecture

لایه‌های پیشنهادی:
- Terminal Registry: مشخصات provider/model/driver و capability.
- Terminal Agent Protocol: احراز Agent، heartbeat، command polling/push و result callback.
- Driver Adapter: پیاده‌سازی پروتکل واقعی مدل.
- Transaction Orchestrator: state machine، timeout، retry/status query، idempotency.
- Payment Integration: ثبت payment و eventهای مالی.
- Audit/Observability: request ID، correlation ID، trace/reference و safe response.

هر driver باید capabilityهای واقعی را اعلام کند:
- purchase/sale
- status inquiry
- cancel
- reversal
- receipt reference
- supported connection type
- firmware/version constraints

فقط capability مستند/تست‌شده در UI فعال شود.

## 7. چاپگرها

### انواع چاپ
- رسید مشتری.
- ticket آشپزخانه/ایستگاه.
- رسید صندوق/گزارش شیفت.
- لیبل محصول/سفارش در صورت نیاز.

### UX
- فهرست چاپگرها و اتصال/Agent.
- نوع چاپگر و paper width.
- اتصال LAN/USB/Serial/Bluetooth فقط بر اساس adapter موجود.
- انتخاب printer پیش‌فرض به تفکیک شعبه/ایستگاه.
- test print.
- صف چاپ، وضعیت queued/sent/failed و زمان آخرین تلاش.
- retry/reprint با برچسب «چاپ مجدد» و ثبت audit.
- چاپ مجدد نباید سفارش یا پرداخت جدید بسازد.

### وضعیت فعلی
در مسیرهای بررسی‌شده printer service/agent/queue/route/schema اختصاصی پیدا نشد. این بخش نیازمند طراحی و پیاده‌سازی مستقل است؛ وجود terminal agent به‌تنهایی پشتیبانی چاپگر را اثبات نمی‌کند.

## 8. ایستگاه‌های آماده‌سازی و نمایشگر

schema شامل:
- `pos_preparation_stations`: name، station_type، is_active، sort_order.
- `pos_order_item_preparations`: order_item_id، station_id، status queued/started/ready/cancelled، quantity، started_at/ready_at و actorها.

این ساختار نشان‌دهنده مدل پایه آماده‌سازی در سطح ردیف سفارش است؛ در این بررسی service/controller/routes و KDS UI مربوط به این جدول‌ها اثبات نشده‌اند.

UX هدف:
- ایستگاه‌ها و ترتیب.
- نگاشت محصول/دسته به ایستگاه (تا زمان schema/API واقعی، فرض نشود).
- صف item-level.
- زمان شروع و آماده‌شدن.
- وضعیت ایستگاه و مسئول.
- نمایشگر آشپزخانه با فیلتر ایستگاه.
- order-level status همچنان مرجع اصلی سفارش بماند.

## 9. سایر تجهیزات

- بارکدخوان: ورودی keyboard wedge یا adapter مشخص؛ mapping SKU و جست‌وجوی امن.
- نمایشگر مشتری: نمایش خلاصه سبد و نتیجه پرداخت بدون داده حساس.
- نمایشگر وضعیت: queue/ready token بدون نمایش نام/تلفن کامل.
- ترازوی دیجیتال: پروتکل مدل و precision باید مستند شود.
- کشوی پول: فرمان بازشدن فقط پس از رویداد مجاز نقدی و سخت‌افزار معتبر؛ بازکردن خودکار در هر سفارش ممنوع.
هر کدام نیازمند driver/capability و تست دستگاه واقعی‌اند.

## 10. Media Integration

MediaService فعلی:
- upload multipart با بررسی واقعی MIME و image dimensions.
- فرمت‌های JPEG/PNG/WebP.
- حداکثر اندازه configurable بین 1KB تا 20MB.
- ابعاد حداکثر 6000×6000 و 20MP.
- SHA-256، storage خصوصی، metadata و audit.
- attach/detach تصویر محصول/دسته.
- routeهای upload و attach با session و catalog.manage.

این مسیر برای تصویر کاتالوگ موجود است؛ public delivery برای Storefront نیازمند route عمومی امن با tenant/media policy و cache درست است. endpoint فعلی private و scoped است.

## 11. امنیت و پایش

- Agent token فقط hash ذخیره شود؛ rotation/revoke و least privilege.
- TLS برای ارتباط شبکه؛ عدم اعتماد به IP/host به‌تنهایی.
- signed command/result یا mutual authentication طبق threat model.
- commandها دارای idempotency، expiry، nonce و correlation ID.
- Agent فقط commandهای نصب/شعبه/device مجاز را اجرا کند.
- response خام کارتخوان که ممکن است داده حساس داشته باشد، sanitize و فقط safe_response ذخیره شود.
- PAN/CVV/track data هرگز ذخیره یا log نشود.
- heartbeat و version monitoring.
- rate limit، replay protection و clock skew handling.
- printer/terminal failures در audit و diagnostics، بدون افشای secret.
- commandهای مالی از commandهای غیرمالی جدا باشند.

## 12. مدیریت خطا

- Agent offline.
- دستگاه disabled.
- driver/model ناشناخته.
- اتصال refused/timeout.
- command expired.
- پاسخ ناقص/نامعتبر.
- transaction unknown.
- duplicate callback/result.
- provider decline.
- reversal pending/failed.
- printer out of paper/offline.
- print queue failure.
- version incompatibility.
- entitlement یا permission disabled.

UI باید راهکار عملی متناسب نشان دهد؛ هیچ‌گاه در خطای unknown، نتیجه موفق/ناموفق را حدس نزند.

## 13. کارهای Backend/Hardware باقی‌مانده

1. مستندکردن پروتکل Agent و provisioning امن.
2. API register/revoke/rotate Agent.
3. heartbeat/health/agent version API.
4. API مدیریت کارتخوان و driver registry.
5. انتخاب یک مدل کارتخوان هدف و تهیه protocol document رسمی.
6. پیاده‌سازی adapter واقعی و hardware-in-the-loop test.
7. command queue، status inquiry، cancellation/reversal و timeout recovery.
8. اتصال terminal transaction به payment/order و cash ledger.
9. جلوگیری از double charge و callback replay.
10. printer registry/agent/driver/queue/test print/reprint.
11. preparation station service/routes و نگاشت item به station.
12. public media delivery برای storefront.
13. threat model، key rotation، audit و monitoring.
14. تست قطعی: approved/declined/timeout/unknown/reversal/offline/reconnect/concurrent request.

## 14. معیار پذیرش

- [ ] Agent فقط با credential امن و scope محدود ثبت می‌شود.
- [ ] heartbeat و نسخه Agent قابل مشاهده است.
- [ ] کارتخوان با driver واقعی و مدل مشخص ثبت و تست می‌شود.
- [ ] مبلغ از Backend معتبر دریافت می‌شود و کلاینت نمی‌تواند آن را تغییر دهد.
- [ ] نتیجه approved/declined از پاسخ واقعی device است.
- [ ] unknown با status inquiry حل می‌شود و retry کور وجود ندارد.
- [ ] idempotency و correlation از تراکنش تکراری جلوگیری می‌کنند.
- [ ] payment/order/cash ledger با نتیجه واقعی و transaction-safe به‌روزرسانی می‌شوند.
- [ ] داده حساس کارت ذخیره/log نمی‌شود.
- [ ] چاپگر دارای صف، test print و reprint audit است.
- [ ] ایستگاه آماده‌سازی با order item و state مجاز مرتبط است.
- [ ] offline، disconnect، timeout و failure بدون آسیب به سفارش مدیریت می‌شوند.
- [ ] پروتکل هیچ مدل دستگاهی حدس زده نشده است.

## 15. ثبت تغییرات

- 2026-11-20 — نسخه 0.1 بر اساس schema terminal/agent/preparation و MediaService موجود.
- وضعیت: آماده بازبینی معماری؛ terminal/printer runtime و تست سخت‌افزار باقی است.
- این سند به معنی پشتیبانی عملیاتی از هیچ مدل کارتخوان یا چاپگر نیست.
