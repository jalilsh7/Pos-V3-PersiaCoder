# مرجع اتصال پیامک — مستندات ارائه‌شده پروژه

## منابع بررسی‌شده
- `webservice-Smart.pdf`: راهنمای SmartSMS و REST مربوط به پنل پیامک.
- `webservice-SharedNumber.pdf`: راهنمای خط خدماتی اشتراکی و ارسال الگو (BaseServiceNumber).
- `Rest-Sample.zip`: نمونه‌های REST برای SendSMS، GetMessages، GetDeliveries و BaseServiceNumber؛ نمونه PHP نیز در آرشیو وجود دارد.
- `legacy/v2-reference/php-backend/private/birthday-sms.php` و `birthday-sms-inbox.php`: منطق parser موجود در V2 که به V3 منتقل و namespaceدار شده است.

فایل `SamplePHP.rar` در محیط فعلی قابل استخراج نبود؛ بنابراین محتوای آن بررسی یا وارد V3 نشده است. برای انتقال دقیق آن باید نسخه ZIP یا فایل‌های استخراج‌شده ارائه شود.

## SmartSMS — REST
طبق فایل webservice-Smart.pdf:
- ارسال ساده: `POST https://rest.payamak-panel.com/api/SmartSMS/Send`
- ارسال متناظر چندگیرنده: `POST https://rest.payamak-panel.com/api/SmartSMS/SendMultiple`
- گزارش تحویل گروهی: `POST https://rest.payamak-panel.com/api/SmartSMS/GetDeliveries2`
- گزارش تحویل شناسه‌ای: `POST https://rest.payamak-panel.com/api/SmartSMS/GetDeliveries`
- پارامترهای پایه ارسال شامل username، password (در مستند به‌عنوان ApiKey معرفی شده)، to، text و from است؛ fromSupportOne و fromSupportTwo اختیاری‌اند.
- در SendMultiple حداکثر 100 گیرنده در هر فراخوانی مستند شده و آرایه‌های to و text باید هم‌اندازه باشند.
- پاسخ موفق REST برای SendMultiple شامل ReqStatus، Message و Result با Mobile و ID است. شناسه‌های بازگشتی باید برای پیگیری تحویل ذخیره شوند.
- کدهای وضعیت تحویل در مستند شامل وضعیت‌های ارسال‌شده، رسیده، نرسیده، خطای مخابراتی، لیست سیاه و فیلترشده است؛ پیاده‌سازی باید کدهای خام provider را حفظ کند، نه اینکه همه را به «تحویل‌شده» تبدیل کند.
- مستند SOAP نیز SmartSMS، SendSmartSMS و متد گزارش تحویل را شرح می‌دهد؛ V3 باید در شروع یک مسیر REST را انتخاب کند تا پیچیدگی و نقاط شکست کمتر باشد.

## خط خدماتی اشتراکی — REST
طبق فایل webservice-SharedNumber.pdf:
- ارسال الگوی خدماتی: `POST https://rest.payamak-panel.com/api/SendSMS/BaseServiceNumber`
- ارسال زمان‌بندی‌شده الگوی خدماتی: `POST https://rest.payamak-panel.com/api/SendSMS/BaseServiceNumberSchedule`
- ورودی‌های مستندشده شامل username، password/ApiKey، text، to و bodyId هستند؛ در حالت زمان‌بندی scheduleDate نیز لازم است.
- قبل از استفاده، متن الگو باید در پنل ارائه‌دهنده ثبت و توسط آن سامانه تأیید شود.
- نمونه زمان‌بندی‌شده در مستند بازه مجاز 07:00 تا 22:00 را ذکر می‌کند.
- خط خدماتی اشتراکی و الگوی تأییدشده برای پیام‌های مجاز به گیرندگانی که امکان دریافت از خطوط عمومی را ندارند مطرح شده است؛ این موضوع جایگزین رضایت قانونی برای پیام تبلیغاتی نیست.

## نمونه‌های REST قدیمی
در `Rest-Sample.zip` نمونه‌هایی از SendSMS، GetMessages و GetDeliveries وجود دارد. نمونه PHP از کتابخانه قدیمی PECL HttpRequest و endpointهای HTTP استفاده می‌کند. این نمونه‌ها صرفاً مرجع شکل پارامترها هستند و نباید عیناً وارد V3 شوند:
- از HTTPS استفاده شود.
- کتابخانه قدیمی PECL HttpRequest به‌عنوان وابستگی V3 اضافه نشود.
- اعتبارنامه‌ها فقط از تنظیمات امن سرور خوانده شوند.
- پاسخ provider با schema سخت‌گیرانه parse شود.
- شناسه پیام provider برای گزارش تحویل ذخیره شود.

## قواعد معماری لازم برای V3
1. Provider adapter سمت Backend؛ هیچ credential یا فراخوانی مستقیم provider در مرورگر قرار نگیرد.
2. قرارداد داخلی مستقل از provider برای ارسال، دریافت شناسه، گزارش تحویل و خطا.
3. صف ارسال با retry کنترل‌شده، backoff، deduplication و idempotency؛ خطاهای دائمی نباید بی‌نهایت retry شوند.
4. ثبت نتیجه هر گیرنده و شناسه provider برای پیگیری delivery.
5. جداسازی کامل پیامک تراکنشی از تبلیغاتی.
6. پیام تبلیغاتی فقط با رضایت معتبر ثبت‌شده و مسیر opt-out ارسال شود؛ پاسخ ورودی یا داشتن شماره تلفن به‌تنهایی consent نیست.
7. پیام‌های تراکنشی باید از رویداد معتبر دامنه (مثلاً سفارش ثبت‌شده) تولید شوند و متن/قالب قابل تنظیم باشد.
8. محدودیت batch، قالب ورودی، خطاها، وضعیت تحویل و سقف‌های provider در adapter کنترل شوند.
9. هیچ رمز، ApiKey، شماره خط یا شناسه واقعی حساب در کد یا log ثبت نشود.
10. انتخاب provider و امکانات خط خدماتی/SmartSMS باید در تنظیمات سمت سرور قابل پیکربندی باشد.

## وضعیت پیاده‌سازی فعلی
- منطق parser پیام ورودی Payamak و فرمان تاریخ تولد از V2 در `v3/backend/src/Communications/Sms/LegacySmsParser.php` منتقل شده است.
- تست متمرکز parser در `v3/backend/tests/Communications/Sms/LegacySmsParserTest.php` ایجاد شده است.
- این انتقال به معنی تکمیل اتصال پیامک نیست. ارسال، صف، گزارش تحویل، provider adapter، ذخیره‌سازی، تنظیمات امن و consent/opt-out هنوز باید پیاده‌سازی شوند.
- تا زمانی که تست‌های parser و تست یکپارچه provider اجرا نشوند، این قابلیت PASS یا آماده تولید اعلام نمی‌شود.

## وضعیت پیاده‌سازی Adapter در V3 (2026-11-20)

### SmartSMS REST

بر اساس راهنمای SmartSMS ارسالی:
- Send: POST https://rest.payamak-panel.com/api/SmartSMS/Send، ارسال یک متن به حداکثر 100 گیرنده، فیلدهای username/password/to/text/from و دو شماره پشتیبان اختیاری.
- SendMultiple: POST https://rest.payamak-panel.com/api/SmartSMS/SendMultiple، آرایه گیرنده و متن متناظر با همان ترتیب، حداکثر 100 مورد.
- GetDeliveries: POST https://rest.payamak-panel.com/api/SmartSMS/GetDeliveries با آرایه Ids برای استعلام گروهی وضعیت.
- password در REST به معنی ApiKey دریافتی از پنل است.
- ID هر پیام باید ذخیره شود؛ صرف دریافت ID به معنی تحویل به گوشی نیست.
- کدهای تحویل مستندشده شامل 0 ارسال به مخابرات، 1 تحویل به گوشی، 2 عدم تحویل، 3 خطای مخابراتی، 5 نامشخص، 8 تحویل به مخابرات، 16 عدم تحویل به مخابرات، 35 لیست سیاه، 100 نامشخص، 200 ارسال‌شده، 300 فیلترشده، 400 در صف و 500 عدم پذیرش هستند.

### Adapter فعلی

- NationalPayamakSmartSmsProvider، SmsProvider، SmartSmsConfig، CurlSmsHttpClient و fake-transport tests اضافه شدند.
- ارسال متن یکسان، ارسال متناظر، استعلام گروهی، محدودیت 100 گیرنده، نرمال‌سازی موبایل ایران و نگاشت خطا/وضعیت پوشش داده شده است.
- کلید API فقط از متغیر محیطی سرور خوانده می‌شود و در payload با نام password ارسال می‌شود؛ در config به‌صورت private نگهداری می‌شود.
- HTTPS، TLS verification، عدم دنبال‌کردن redirect و timeout محدود اعمال می‌شود.
- timeout/خطای شبکه/HTTP 5xx می‌تواند outcome نامشخص داشته باشد؛ retry کور ممنوع است. ابتدا باید IDهای provider و delivery report برای reconciliation استفاده شوند.
- تست‌ها فقط با fake HTTP transport هستند؛ ارسال واقعی با اعتبار حساب کاربر انجام نشده است.

### موارد تکمیل‌نشده

- persistence/outbox و queue worker
- ذخیره provider ID برای هر گیرنده و delivery attempts
- polling گزارش تحویل و retry policy کنترل‌شده
- secrets management UI و تست credential واقعی
- consent/opt-out enforcement و تفکیک transactional از promotional
- زمان‌بندی و محدودیت ساعت ارسال
- adapter خط خدماتی اشتراکی/الگو (BaseServiceNumber)؛ این adapter جداگانه است و با SmartSMS یکی نیست.

