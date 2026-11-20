# طراحی UX تنظیمات، کاربران، نقش‌ها و دسترسی قابلیت‌ها — PersiaCoder POS V3

- شناسه: DESIGN-ACCESS-CONTROL-2026-001
- نسخه: 0.1
- تاریخ: 2026-11-20
- بسته: D-12
- وضعیت: **آماده نمونه‌سازی مرحله‌ای؛ نیازمند تطبیق entitlement با Central**
- مرجع تصمیمات: `FINAL_PRODUCT_DECISIONS_FA.md`، به‌ویژه `LIC-MODULE-CONTROL-2026-001`
- مراجع فنی: `PermissionService`، `RoleManagementService`، `UserManagementService`، `BranchManagementService`، `BusinessScopeResolver`، repositoryهای Permission/Role/User/Business و routeهای access در `v3/backend/public/index.php`.
- مرجع UI: `BusinessSettingsWorkspace.tsx` و `settings.css`.

> این سند نقش‌ها و دسترسی‌های جاری V3 را از entitlement ماژول‌ها و کنترل لایسنس Central جدا می‌کند. RBAC و user/branch management در Backend route دارند؛ صفحه Business Settings فعلی صرفاً preview محلی است. سیستم entitlement و کنترل مجوز قابلیت‌ها از Central هنوز در route/runtime بررسی‌شده اثبات نشده است. ترکیب تجاری پلن‌ها همچنان تصمیم باز است.

## 1. هدف و اصول

تنظیمات باید برای مدیر قابل فهم باشد و بدون آشکارکردن پیچیدگی RBAC، دسترسی کاربران را امن و قابل ممیزی مدیریت کند.

اصول:
- احراز هویت سمت سرور و username/password برای همه کاربران.
- permission در Backend و با business/branch scope enforce شود؛ مخفی‌کردن UI کافی نیست.
- کاربر فقط permissionهایی را به دیگران اعطا کند که خودش در همان scope دارد؛ جلوگیری از privilege escalation.
- نقش سیستمی owner و نقش‌های immutable از UI قابل ویرایش/واگذاری نباشند.
- business role و branch role دو scope مجزا هستند.
- تغییر دسترسی، ایجاد کاربر، تغییر نقش و ایجاد شعبه باید idempotent و audit شوند.
- entitlement قابلیت محصول از RBAC کاربر جداست: اولی می‌گوید نصب مشتری چه ماژولی را مجاز دارد؛ دومی می‌گوید کدام کاربر می‌تواند از قابلیت مجاز استفاده کند.

## 2. معماری اطلاعات پیشنهادی

ناوبری تنظیمات:
1. مشخصات کسب‌وکار
2. شعبه‌ها
3. کاربران
4. نقش‌ها و مجوزها
5. ساعات کاری و کانال‌های سفارش
6. تنظیمات عمومی و منطقه‌ای
7. ماژول‌ها و قابلیت‌های مجاز (نمایش entitlement فقط‌خواندنی از Central در نسخه آینده)
8. امنیت و رویدادهای ممیزی (فقط برای نقش مجاز)

صفحه تنظیمات نباید به یک فرم بلند و شلوغ تبدیل شود؛ هر بخش هدف مستقل و خلاصه وضعیت داشته باشد.

## 3. کسب‌وکار و شعبه‌ها

### کسب‌وکار
- نام، پروفایل صنفی، اطلاعات تماس، نشانی، timezone، currency و تنظیمات پایه.
- پروفایل صنفی باید از لیست مجاز و سیاست محصول خوانده شود؛ UI فعلی مقادیر ثابت محلی دارد و ذخیره سروری ندارد.
- واحد پول نمایشی باید با قرارداد پول عملیاتی هماهنگ شود؛ در UI فعلی انتخاب تومان/ریال local preview است و نباید تغییر آن را به‌عنوان تبدیل امن داده پول تلقی کرد.
- تغییر timezone باید هشدار اثر بر فیلتر تاریخ/گزارش و ساعات کاری بدهد؛ زمان‌های ذخیره‌شده UTC باید دست‌نخورده بمانند.

### شعبه‌ها
- فهرست شعبه‌های مجاز و وضعیت فعال.
- ایجاد شعبه با کد، نام و timezone.
- کد در محدوده business یکتا؛ timezone باید معتبر باشد.
- business context و branch context از resolver سمت سرور بیاید؛ UI اجازه انتخاب شعبه خارج از دسترسی را ندهد.
- تغییر/غیرفعال‌سازی شعبه فقط در صورت وجود API و policy مشخص؛ از نمایش اقدام فرضی پرهیز شود.

## 4. کاربران

### فهرست
- نام نمایشی، username، نقش‌های مؤثر، scope (business/branch) و وضعیت حساب در صورت بازگشت API.
- فیلتر شعبه/نقش و جست‌وجو فقط در حد قابلیت واقعی API.
- داده‌های ورود و password hash هرگز در UI یا API فهرست بازگردانده نشوند.

### ایجاد کاربر
- username بین 3 تا 64 کاراکتر، lowercase با حروف لاتین، عدد، نقطه، underscore یا hyphen.
- display name اجباری و حداکثر 120 نویسه.
- انتخاب نقش مجاز و scope کسب‌وکار یا شعبه.
- Backend یک temporary password تصادفی ایجاد می‌کند، فقط یک‌بار در پاسخ نشان می‌دهد و کاربر باید در اولین ورود رمز را تغییر دهد.
- رابط باید نمایش یک‌باره credential را با هشدار امنیتی، دکمه کپی، زمان محدود نمایش و تأیید تحویل امن همراه کند؛ هرگز آن را در localStorage، log یا audit ذخیره نکند.
- از آنجا که temporary password در پاسخ فقط یک‌بار برمی‌گردد، در صورت بسته‌شدن modal بدون ذخیره امن، نباید امکان بازیابی همان رمز وعده داده شود؛ مسیر reset credential باید API مستقل داشته باشد.

## 5. نقش‌ها و permissionها

### فهرست نقش
- نقش‌های فعال و نام نمایشی.
- نقش‌های سیستمی از custom role متمایز باشند.
- owner و نقش‌های سیستمی immutable هستند؛ UI آن‌ها را فقط‌خواندنی نشان دهد.
- custom role شامل code، name و مجموعه permissionها است.

### ساخت/ویرایش نقش سفارشی
- انتخاب مجوزها به‌صورت دسته‌بندی‌شده بر اساس ماژول.
- توضیح فارسی هر permission، در صورت وجود catalog متادیتا.
- permissionهای حساس با هشدار واضح: مدیریت کاربران/نقش‌ها، مالی، refund، تنظیمات، شعبه و ممیزی.
- Backend فقط اجازه اعطای permissionهایی را می‌دهد که actor خودش در scope دارد؛ اگر نقش انتخابی از دسترسی actor فراتر رود، UI خطای role_escalation_denied را توضیح دهد.
- کد role یکتا، lowercase و immutable در مسیر update جاری؛ تغییر نام و permission مجاز.
- هنگام تغییر نقش، اثر بر کاربران تخصیص‌یافته و نشست‌های جاری باید روشن شود؛ اگر API اثر را برنمی‌گرداند، ادعای اثر فوری یا قطع نشست نکن.

### تخصیص نقش
- تخصیص business role و branch role جداگانه.
- نقش owner یا نقش دارای wildcard `*` از مسیر مدیریت scope‌شده قابل واگذاری نیست.
- حذف role assignment باید با تأیید و نمایش scope دقیق انجام شود.
- حذف تخصیص، حذف کاربر یا پاک‌کردن داده‌های تاریخی نیست.

## 6. ماتریس دسترسی و تجربه کاربر

- صفحه دسترسی باید نقش انتخاب‌شده را در ستون و permissionها را در ردیف نشان دهد یا به دسته‌های قابل بازشدن تقسیم کند.
- وضعیت مجوزها از API permissions و نقش خوانده شود؛ فهرست hard-code فرانت‌اند نباید مرجع نهایی باشد.
- برای هر permission، کد فنی فقط در tooltip/جزئیات مدیر نمایش داده شود؛ عنوان اصلی فارسی باشد.
- permissionهای branch-scoped در کنار scope شعبه نمایش داده شوند.
- اگر permissionها در backend تغییر کنند، صفحه باید refresh شود و وضعیت stale را نشان دهد.
- کاربر فاقد permission نباید بتواند از طریق API یا URL مستقیم عملیات را انجام دهد؛ Backend باید 403 برگرداند.

## 7. entitlement و کنترل ماژول از Central

### تصمیم قطعی
طبق `LIC-MODULE-CONTROL-2026-001`:
- Central مرجع فعال/غیرفعال‌سازی ماژول‌ها و featureها بر اساس entitlement هر مشتری/نصب است.
- Backend نصب مشتری entitlement معتبر را enforce می‌کند؛ مخفی‌کردن UI کافی نیست.
- ساختار باید قابلیت تعریف ماژول، feature جزئی، وابستگی و entitlement را بدون hard-code بسته‌های فروش داشته باشد.
- غیرفعال‌کردن قابلیت نباید داده تاریخی را حذف یا یکپارچگی سفارش/مالی/انبار را بشکند.

### تجربه هدف
- صفحه «ماژول‌ها و قابلیت‌های مجاز» فقط اطلاعات entitlement معتبر و امضاشده را نمایش دهد: شناسه قابلیت، وضعیت مجاز، نسخه/زمان اعتبارسنجی و محدودیت‌های تعریف‌شده.
- مدیر نصب نباید با toggle محلی، entitlement Central را جعل یا فعال کند.
- قابلیت غیرفعال با توضیح «در مجوز این نصب فعال نیست» نمایش داده شود؛ اگر entitlement stale/expired/offline است، وضعیت دقیق policy سرور نمایش داده شود.
- داده تاریخی ماژول غیرفعال همچنان قابل نگهداری/مشاهده طبق مجوز و policy؛ حذف داده خودکار ممنوع.
- dependencyها و feature granularity باید از catalog مرکزی خوانده شوند.

### موضوعات صریحاً باز
- پلن‌ها و ترکیب امکانات/قیمت‌گذاری.
- تعداد/مدل نصب، شعبه و دستگاه.
- رفتار trial، grace، suspension، expiry و offline.
- رفتار read-only/export هنگام غیرفعال‌سازی.
- downgrade و migration داده‌های وابسته.
- هیچ طرح فروش یا قیمت از طرف UI/عامل توسعه حدس زده نشود.

### وضعیت پیاده‌سازی
در کدهای بررسی‌شده، RBAC، نقش‌ها، کاربران و شعبه‌ها وجود دارند؛ اما Central licensing/entitlement client، دریافت/اعتبارسنجی entitlement و enforcement feature-level در runtime این بسته بررسی نشده‌اند. این بخش طراحی معماری/UX آینده است، نه قابلیت جاری.

## 8. تنظیمات ساعات کاری و کانال‌ها

- UI فعلی روزهای هفته، ساعت شروع/پایان، کانال‌های dine-in/takeaway/delivery/online و مشخصات کسب‌وکار را در state محلی نگه می‌دارد.
- صفحه فعلی به API ذخیره‌سازی متصل نیست و خود UI هشدار preview دارد.
- در طراحی عملیاتی آینده، ساعات کاری، استثنای تعطیلی، باز/بسته‌بودن دستی، ساعات هر شعبه و policy پذیرش سفارش هنگام تعطیلی باید از Business Configuration API بیایند.
- timezone شعبه مرجع تفسیر ساعات است.
- کانال فعال، تنظیم نمایش/دریافت است؛ مجوز entitlement و وضعیت عملیاتی فروشگاه باید جداگانه کنترل شوند.

## 9. امنیت، audit و حالت‌ها

- هر عملیات نوشتن با permission مناسب، CSRF/origin، idempotency و audit.
- تغییر permission/role باید before/after در audit داشته باشد.
- عدم افشای temporary password، session token، secret یا credential در log.
- جلوگیری از واگذاری نقش owner، wildcard و privilege escalation.
- 403 عدم دسترسی؛ 409 کد/نام تکراری یا conflict؛ 422 داده نامعتبر.
- در خطای شبکه فرم حفظ و نتیجه عملیات idempotent استعلام شود.
- در entitlement unavailable، UI باید وضعیت policy را از Backend بخواند و fail-open محلی انجام ندهد.
- دسکتاپ: جدول و ماتریس دسترسی؛ موبایل: کارت‌های کاربر و permissionهای گروه‌بندی‌شده.
- RTL، کد فنی LTR، کنتراست و target لمسی مناسب.

## 10. موارد باز و شکاف فنی

1. UI مدیریت کاربران، نقش‌ها و شعبه‌ها هنوز در feature settings موجود بررسی‌شده نیست؛ API Backend route دارد.
2. API تغییر وضعیت/غیرفعال‌سازی کاربر، reset password، revoke sessions و بازیابی credential باید بررسی/تکمیل شود.
3. metadata فارسی برای permission catalog و گروه‌بندی آن‌ها.
4. audit log viewer با فیلتر و pagination.
5. ذخیره‌سازی واقعی business settings و hours/channel.
6. Central entitlement API/client، signature verification، cache/offline policy و enforcement در backend.
7. feature dependencies، read-only/export و رفتار downgrade/expiry.
8. تست امنیتی privilege escalation، scope mismatch، role wildcard، owner immutability و bypass route.
9. طراحی انتقال مالکیت و recovery امن در صورت خروج آخرین مدیر.
10. تکمیل route matrix و permission tests برای همه endpointهای ماژول.

## 11. معیار پذیرش طراحی

- [ ] فهرست کاربران، نقش‌ها و شعبه‌ها از API واقعی و scope مجاز خوانده شود.
- [ ] ساخت کاربر business/branch با نقش مجاز انجام شود.
- [ ] temporary password فقط یک‌بار و بدون ذخیره ناامن نشان داده شود.
- [ ] owner/system role قابل ویرایش یا واگذاری نباشد.
- [ ] custom role فقط permissionهای مجاز actor را بگیرد.
- [ ] business role و branch role مستقل و شفاف باشند.
- [ ] UI permissionها را از API بخواند و Backend همه routeها را enforce کند.
- [ ] تنظیمات preview محلی با ذخیره عملیاتی اشتباه نشود.
- [ ] entitlement Central از RBAC کاربر جدا نمایش داده شود.
- [ ] نبود entitlement موجب fail-open یا فعال‌سازی محلی نشود.
- [ ] هیچ پلن/ترکیب فروش/قیمت فرضی در UI یا کد hard-code نشود.
- [ ] غیرفعال‌سازی ماژول داده تاریخی را حذف نکند.
- [ ] RTL و واکنش‌گرایی دسکتاپ/موبایل رعایت شود.

## 12. ثبت تغییرات

- 2026-11-20 — نسخه 0.1 بر اساس RBAC/User/Branch services و routeهای جاری، به‌علاوه تصمیم LIC-MODULE-CONTROL-2026-001.
- وضعیت: **آماده نمونه‌سازی مرحله‌ای؛ نیازمند تطبیق entitlement با Central**.
- این سند تکمیل UI مدیریت دسترسی یا پیاده‌سازی Central entitlement را اثبات نمی‌کند.
