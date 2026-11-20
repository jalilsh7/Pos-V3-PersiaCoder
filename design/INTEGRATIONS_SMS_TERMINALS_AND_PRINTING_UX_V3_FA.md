# طراحی UX و معماری یکپارچگی‌ها، پیامک و سخت‌افزار — PersiaCoder POS V3

- شناسه: DESIGN-INTEGRATIONS-HARDWARE-2026-001
- نسخه: 0.1
- تاریخ: 2026-11-20
- بسته: D-15
- وضعیت: **آماده بازبینی معماری؛ پروتکل‌های سخت‌افزاری نیازمند مستندات مدل**
- مرجع تصمیمات محصول: `FINAL_PRODUCT_DECISIONS_FA.md`
- مراجع پروژه: `docs/integrations/NATIONAL-PAYAMAK-SMS-FA.md`، `v3/backend/src/Communications/Sms/LegacySmsParser.php`، `v3/backend/src/Communications/Sms/README.md` و مستندات آپلودشده مالک.
- منابع آپلودشده بررسی‌شده: `webservice-Smart.pdf`، `webservice-SharedNumber.pdf` و `Rest-Sample.zip`. محتوای فایل‌های RAR فهرست شد اما به علت نبود ابزار استخراج، سورس PHP داخل آن خوانده نشد.

> هیچ پروتکل کارتخوان/چاپگر در منابع آپلودشده این بسته وجود ندارد. اتصال LAN/Wi-Fi کارتخوان در سطح نیازمندی محصول ثبت است، اما مدل، شرکت پرداخت و protocol باید با مستندات همان دستگاه تعیین شود. از حدس‌زدن پورت، فرمان یا قالب پیام خودداری شود.

## 1. اصول یکپارچگی

- هر provider یا سخت‌افزار پشت adapter مستقل و قرارداد داخلی پایدار قرار گیرد.
- منطق سفارش/پرداخت/پیامک به SDK یا پروتکل یک برند قفل نشود.
- secretها فقط سمت سرور/عامل محلی امن ذخیره شوند؛ مرورگر هرگز ApiKey یا رمز provider نداشته باشد.
- عملیات مالی/ارسال/چاپ دارای idempotency یا job identity باشند.
- timeout به معنی شکست قطعی نیست؛ قبل از retry باید وضعیت عملیات استعلام شود.
- رویدادهای provider و سخت‌افزار normalize شوند، اما raw status/reference برای audit و عیب‌یابی حفظ شود.
- خطای provider جانبی نباید سفارش یا پرداخت موفق را خراب کند.
- adapter باید قابلیت health/status، timeout، retry policy، redaction log و versioned configuration داشته باشد.

## 2. پیامک National Payamak — منابع و قراردادهای واقعی

### 2.1 منابع بررسی‌شده
- `webservice-Smart.pdf`: SmartSMS و REST ارسال/گزارش تحویل.
- `webservice-SharedNumber.pdf`: خط خدماتی اشتراکی و ارسال الگوی تأییدشده.
- `Rest-Sample.zip`: نمونه REST برای SendSMS، GetMessages، GetDeliveries و BaseServiceNumber.
- `SamplePHP.rar`: آرشیو نمونه PHP؛ فقط فهرست فایل‌ها قابل خواندن بود، استخراج محتوا در محیط فعلی ممکن نشد. سورس داخل آن به V3 منتقل نشده است.

### 2.2 SmartSMS REST
مسیرهای مستند:
- `POST https://rest.payamak-panel.com/api/SmartSMS/Send`
- `POST https://rest.payamak-panel.com/api/SmartSMS/SendMultiple`
- `POST https://rest.payamak-panel.com/api/SmartSMS/GetDeliveries2`
- `POST https://rest.payamak-panel.com/api/SmartSMS/GetDeliveries`

قواعد استخراج‌شده از مستند:
- پارامترهای پایه شامل username، password که مستند آن را ApiKey معرفی می‌کند، شماره گیرنده، متن و شماره فرستنده است؛ شماره‌های پشتیبان فرستنده در برخی روش‌ها اختیاری‌اند.
- SendMultiple حداکثر 100 گیرنده در هر درخواست دارد؛ آرایه‌های to و text باید هم‌اندازه باشند.
- پاسخ موفق شامل ReqStatus، Message و Result است؛ برای هر گیرنده Mobile و ID بازمی‌گردد.
- ID هر گیرنده باید ذخیره شود تا گزارش تحویل بعداً با GetDeliveries یا GetDeliveries2 خوانده شود.
- مقدار موفقیت صف شدن درخواست، تحویل به گوشی نیست؛ وضعیت صف و delivery report دو مفهوم جدا هستند.
- وضعیت‌های provider شامل ارسال‌شده، رسیده/نرسیده، خطای مخابراتی، blacklist، filtered و unknown است. raw code نگهداری شود.
- نسخه SOAP نیز در مستند وجود دارد؛ برای V3 در شروع REST انتخاب شود تا یک transport عملیاتی واحد داشته باشیم.

### 2.3 خط خدماتی اشتراکی / الگو
مسیرهای REST مستند:
- `POST https://rest.payamak-panel.com/api/SendSMS/BaseServiceNumber`
- `POST https://rest.payamak-panel.com/api/SendSMS/BaseServiceNumberSchedule`

قواعد:
- ورودی‌ها شامل username، password/ApiKey، text، to و bodyId هستند؛ برای زمان‌بندی scheduleDate نیز لازم است.
- متن الگو باید از پنل ارائه‌دهنده ثبت و توسط آن تأیید شود؛ bodyId به همان الگوی تأییدشده مربوط است.
- روش BaseServiceNumber در مستند برای یک شماره گیرنده در هر فراخوانی تعریف شده است.
- دو روش SOAP `SendByBaseNumber` و `SendByBaseNumber2` تفاوت قالب text دارند: آرایه رشته‌ها در برابر رشته جداشده با semicolon؛ این دو قالب نباید بدون adapter یکسان فرض شوند.
- روش زمان‌بندی SOAP `AddScheduleSendByBaseNumber2` نیز مستند است؛ محدودیت زمان ارسال نمونه در مستند ذکر شده و باید در adapter validate شود.
- موفقیت ارسال الگو به معنی رضایت تبلیغاتی نیست؛ خدماتی بودن خط، مبنای consent را تغییر نمی‌دهد.

### 2.4 نمونه‌های REST قدیمی
`Rest-Sample.zip` شامل نمونه‌های SendSMS، GetMessages و GetDeliveries و نمونه‌های PHP/Java/C#/Node است.
- نمونه‌های قدیمی از HTTP و PHP PECL HttpRequest استفاده می‌کنند؛ کد آن‌ها مستقیم وارد V3 نشود.
- endpointهای legacy مانند `/api/SendSMS/SendSMS` و `GetMessages` فقط پس از تأیید HTTPS و قرارداد جاری provider قابل استفاده‌اند؛ نمونه HTTP موجود مجوز ارسال credential روی HTTP نیست.
- در نمونه GetMessages ورودی‌هایی مانند location، from، index و count دیده می‌شود؛ parser V3 فقط envelope/field mapping مستندشده را می‌پذیرد و ساختار ناشناخته را fail-closed می‌کند.

## 3. معماری ماژول پیامک

### Provider Adapter
قرارداد داخلی پیشنهادی:
- `sendSingle`
- `sendBatch`
- `sendTemplate`
- `scheduleTemplate` (در صورت استفاده)
- `getDeliveryStatus`
- `getDeliveryStatuses`
- `fetchInbox` (فقط اگر دریافت پیام ورودی در scope محصول فعال باشد)
- `healthCheck`

هر نتیجه باید شامل provider, provider_message_id, recipient, accepted/queued status, raw status, normalized status, timestamps و error code باشد.

### صف و چرخه پیام
1. رویداد معتبر دامنه ایجاد شود.
2. سیاست پیام و فعال/غیرفعال بودن event بررسی شود.
3. consent/opt-out برای marketing بررسی شود.
4. پیام با template version و recipient snapshot ساخته شود.
5. job با deduplication key وارد صف شود.
6. worker provider را فراخوانی و ID هر گیرنده را ذخیره کند.
7. delivery polling/callback وضعیت را به‌روزرسانی کند.
8. گزارش خطا/تحویل برای مدیر قابل مشاهده شود.

### جداسازی تراکنشی و تبلیغاتی
- پیام تراکنشی از رویداد سفارش/پرداخت/آماده‌سازی و بدون نیاز به consent تبلیغاتی، مطابق قوانین ارتباطی و policy محصول ارسال شود.
- پیام تبلیغاتی فقط با رضایت معتبر ثبت‌شده و opt-out فعال مجاز است.
- فعال بودن checkbox در UI به‌تنهایی consent نیست؛ consent باید از اقدام روشن مشتری، timestamp، source و policy version ثبت شود.
- لغو رضایت باید سریع در تمام صف‌های آتی اعمال شود؛ job queued تبلیغاتی پیش از ارسال دوباره consent را بررسی کند.
- خرابی SMS نباید تراکنش فروش را rollback کند.
- نرخ ارسال، batch limit و retry/backoff باید بر اساس خطاهای موقت/دائمی provider تنظیم شود؛ خطاهای auth، فیلتر یا شماره نامعتبر بی‌نهایت retry نشوند.

### تنظیمات امن
- ApiKey و username در secret storage سمت سرور؛ mask در UI.
- تغییر credential، تست اتصال و health status با permission مدیریت.
- عدم نمایش secret کامل پس از ذخیره.
- عدم ثبت body کامل حاوی PII یا credential در log.
- انتخاب provider/خط خدماتی/SmartSMS بر اساس قابلیت entitlement و تنظیمات نصب؛ هیچ رمز واقعی در سورس یا فایل نمونه commit نشود.

## 4. کارتخوان بانکی LAN/Wi-Fi

### نیازمندی محصول ثبت‌شده
- تعریف و پیکربندی کارتخوان در POS.
- اتصال شبکه LAN یا Wi-Fi.
- ارسال مبلغ از POS به کارتخوان.
- دریافت نتیجه واقعی تراکنش.
- قابلیت افزودن مدل‌ها و شرکت‌های پرداخت جدید.
- نتیجه unknown و جلوگیری از retry کور.
- تراکنش پرداخت با order/payment ledger تطبیق یابد.

### معماری پیشنهادی
- `TerminalProviderAdapter` برای هر خانواده پروتکل/شرکت پرداخت.
- Registry دستگاه شامل branch, terminal public ID, model, provider, connection type, endpoint/config reference, enabled state و last health check.
- secret/terminal key فقط در storage امن و خارج از frontend.
- فرمان پرداخت با مبلغ integer و currency مشخص، order/payment reference و idempotency.
- response شامل approved/declined/timeout/unknown، provider reference، terminal transaction ID و raw code.
- query/reversal/void فقط اگر پروتکل مدل واقعی پشتیبانی کند؛ هیچ فرمان عمومی فرض نشود.
- پس از timeout، قبل از ارسال دوباره مبلغ، status inquiry با trace/reference همان تراکنش انجام شود.
- اتصال مستقیم مرورگر به IP کارتخوان ممنوع؛ Backend یا local bridge امن با pairing و allowlist شبکه واسط شود.

### موارد نیازمند مدرک
- مدل و firmware دستگاه.
- نام شرکت پرداخت/PSP و قرارداد پذیرندگی.
- protocol رسمی، transport، port، message framing، encoding و timeout.
- روش authentication/pairing و کلیدهای ارتباطی.
- روش status inquiry، reversal و دریافت رسید/شماره مرجع.
- تست با دستگاه واقعی و sandbox/تراکنش آزمایشی.
تا این مدارک فراهم نشوند، هیچ endpoint، پورت یا فرمانی در V3 hard-code نشود.

## 5. چاپگر و تجهیزات جانبی

- چاپگر رسید مشتری و چاپگر آشپزخانه/KOT دو مقصد و template مستقل‌اند.
- اتصال احتمالی USB/LAN/Wi-Fi/Bluetooth باید با driver/bridge واقعی مدل انتخابی اثبات شود.
- چاپ job دارای شناسه یکتا، نوع سند، مقصد، تعداد نسخه و وضعیت queued/printed/failed/unknown باشد.
- retry چاپ فقط همان print job را تکرار کند؛ سفارش یا پرداخت جدید نسازد.
- رسید پرداخت فقط پس از وضعیت مالی قطعی متن متناسب نشان دهد؛ وضعیت unknown نباید «پرداخت موفق» چاپ شود.
- KOT snapshot اقلام/گزینه‌ها را نشان می‌دهد و نباید اطلاعات مالی غیرضروری مشتری را چاپ کند.
- چاپ ناموفق نباید وضعیت order را تغییر دهد؛ KDS دیجیتال مسیر جایگزین مشاهده سفارش است.
- encoding فارسی، فونت، عرض کاغذ، cutter، drawer kick و QR/barcode باید با مدل دستگاه تست شود.

## 6. وضعیت کد V3 در HEAD بررسی‌شده

- SMS: فقط `LegacySmsParser` برای نرمال‌سازی ارقام/موبایل، تاریخ تولد شمسی و parse پاسخ GetMessages وجود دارد؛ ارسال provider، queue، delivery persistence، settings امن و consent/opt-out runtime کامل نیست.
- پرداخت: schema تراکنش‌ها و provider references را دارد، اما در این ممیزی adapter کارتخوان LAN/Wi-Fi و provider terminal runtime پیدا نشد.
- چاپگر: adapter/print queue/runtime در مسیرهای بررسی‌شده پیدا نشد.
- KDS: KitchenBoard واقعی وجود دارد، اما چاپ KOT و چندایستگاهی اثبات نشده است.
- فایل‌های `SamplePHP.rar` فقط فهرست فایل‌های SOAP/cURL را نشان دادند؛ استخراج کد ممکن نشد. هیچ قطعه کد آن آرشیو منتقل نشده است.

## 7. خطاها و امنیت

- timeout، network unreachable، auth failure، invalid recipient, provider throttling, template not approved, batch mismatch و delivery unknown تفکیک شوند.
- secretها و شماره‌های حساس در log redacted شوند.
- retry با exponential backoff + jitter و سقف تلاش؛ برای عملیات ارسال، deduplication قبل از provider call.
- provider message ID برای هر گیرنده ذخیره شود؛ batch ID به‌تنهایی کافی نیست.
- callback/polling معتبرسازی شود؛ status regression و event duplicate مدیریت شوند.
- برای terminal: timeout unknown، status inquiry و reconciliation پیش از retry.
- برای printer: job queue و retry همان job.
- تست‌های امنیتی شامل credential leakage، SSRF/device endpoint allowlist، replay/idempotency، consent bypass و signature validation.

## 8. موارد باز

1. انتخاب provider و قرارداد رسمی/نسخه API پیامک نهایی.
2. تأیید current API behavior و HTTPS برای endpointهای legacy SendSMS/GetMessages.
3. تصمیم درباره inbox دوطرفه و کاربرد آن در محصول.
4. نگاشت کامل کدهای delivery به وضعیت داخلی بدون از دست دادن raw code.
5. مدل کارتخوان/PSP و مستندات protocol واقعی.
6. local bridge یا ارتباط server-to-terminal و مدل pairing.
7. مدل چاپگر و معماری print agent.
8. سیاست مدیریت credential و rotation.
9. SLA/retention داده پیامک، delivery و print jobs.
10. policy پیامک تراکنشی/تبلیغاتی، consent evidence و opt-out در همه کانال‌ها.

## 9. معیار پذیرش طراحی

- [ ] providerها از طریق adapter قابل تعویض باشند.
- [ ] SmartSMS SendMultiple سقف 100 و هم‌اندازه بودن آرایه‌ها را رعایت کند.
- [ ] شناسه ID هر گیرنده ذخیره و برای delivery polling استفاده شود.
- [ ] queued بودن با delivered بودن یکی نباشد.
- [ ] BaseServiceNumber فقط با bodyId/الگوی تأییدشده و محدودیت یک گیرنده استفاده شود.
- [ ] consent معتبر و opt-out قبل از ارسال تبلیغاتی enforce شود.
- [ ] خطای SMS سفارش را متوقف/rollback نکند.
- [ ] ApiKey در frontend یا log افشا نشود.
- [ ] کارتخوان با adapter مدل‌محور و status inquiry قبل از retry unknown طراحی شود.
- [ ] هیچ پروتکل دستگاه بدون سند رسمی و تست واقعی پیاده‌سازی نشود.
- [ ] print job مستقل از order/payment و قابل retry امن باشد.
- [ ] رسید مشتری و KOT از هم جدا باشند.
- [ ] raw provider/terminal status برای audit حفظ شود.

## 10. ثبت تغییرات و منبع

- 2026-11-20 — نسخه 0.1 بر اساس PDFهای Smart/SharedNumber، Rest-Sample.zip، فهرست SamplePHP.rar و کد V3.
- وضعیت: **آماده بازبینی معماری؛ پیاده‌سازی provider و سخت‌افزار باقی است**.
- SamplePHP.rar استخراج نشد و محتوای آن مبنای طراحی/کدنویسی قرار نگرفت.
- این سند اجرای تست provider، کارتخوان یا چاپگر را اثبات نمی‌کند.
