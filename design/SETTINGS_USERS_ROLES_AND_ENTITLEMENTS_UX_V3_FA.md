# طراحی تنظیمات، کاربران، نقش‌ها و مجوز قابلیت‌ها — PersiaCoder POS V3

- شناسه: DESIGN-ACCESS-SETTINGS-2026-001
- نسخه: 0.1
- تاریخ: 2026-11-20
- بسته: D-12
- وضعیت: **آماده بازبینی مفهومی؛ RBAC و مدیریت کاربر در Backend وجود دارد، UI مدیریت و تنظیمات عملیاتی/entitlement نیازمند تکمیل**
- مرجع تصمیمات: `FINAL_PRODUCT_DECISIONS_FA.md` به‌ویژه `LIC-MODULE-CONTROL-2026-001`
- کدهای بررسی‌شده: `AuthController.php`، `PermissionService.php`، `PermissionRepository.php`، `UserManagementService.php`، `UserManagementController.php`، `RoleManagementService.php`، `RoleManagementController.php`، `BranchManagementService.php`، `v3/backend/public/index.php` و `database/v3/schema.sql`
- UI تنظیمات بررسی‌شده: `v3/frontend/src/features/settings/BusinessSettingsWorkspace.tsx`

> دو مفهوم نباید با هم ادغام شوند: RBAC مشخص می‌کند «کاربر چه عملیاتی مجاز است انجام دهد»؛ entitlement از Central مشخص می‌کند «این نصب/مشتری مجوز استفاده از کدام قابلیت را دارد». هر دو باید سمت Backend enforce شوند. ترکیب امکانات در بسته‌های فروش هنوز تصمیم‌گیری نشده است.

## 1. هدف

یک مرکز تنظیمات ساده و قابل فهم برای مدیر:
- مدیریت کاربران و دسترسی آن‌ها در سطح کسب‌وکار و شعبه.
- ساخت نقش‌های سفارشی با مجموعه مجوزهای محدود به اختیار مدیر.
- مشاهده و مدیریت تنظیمات کسب‌وکار و شعبه.
- نمایش وضعیت ماژول‌ها/قابلیت‌ها و entitlement معتبر.
- حفظ امنیت و جلوگیری از افزایش سطح دسترسی ناخواسته.

## 2. وضعیت فعلی بر اساس کد

### احراز هویت
- ورود با username/password از `AuthController` و `AuthenticationService`.
- نشست سمت سرور و cookie؛ CSRF token و Same-Origin guard برای عملیات تغییردهنده.
- `/auth/me` اطلاعات کاربر و CSRF token را برمی‌گرداند.
- تغییر رمز عبور وجود دارد و پس از آن cookie نشست/CSRF پاک می‌شود.
- وضعیت `must_change_password` در پاسخ کاربر وجود دارد.

### RBAC
- `PermissionService::allows` مجوز را با BusinessScope بررسی می‌کند.
- مجوز از roleهای تخصیص‌یافته در سطح کسب‌وکار یا شعبه می‌آید.
- wildcard `*` در PermissionRepository قابل پذیرش است.
- middleware route-level می‌تواند permission مشخص و scope شعبه را enforce کند.
- ایجاد/ویرایش نقش سفارشی و تخصیص/حذف نقش در سرویس‌های Auth وجود دارد.
- نقش system و owner از ویرایش/تخصیص عادی محافظت می‌شوند.
- هنگام ساخت کاربر، نقش دارای مجوزهایی فراتر از مجوزهای actor رد می‌شود (role escalation guard).

### مدیریت کاربران
- فهرست کاربران کسب‌وکار و شعبه.
- ایجاد کاربر در scope کسب‌وکار یا شعبه.
- username، display_name و role_code.
- رمز موقت تصادفی تولید و فقط یک‌بار در پاسخ برگردانده می‌شود؛ کاربر باید رمز را در اولین ورود تغییر دهد.
- audit و idempotency برای ایجاد کاربر.
- غیرفعال/فعال‌سازی کاربر یا reset password در مسیرهای بررسی‌شده هنوز تأیید نشده‌اند؛ UI آن‌ها را فعال فرض نکند.

### تنظیمات
- schema شامل `pos_business_settings`، `pos_setting_history`، `pos_feature_flags` و `pos_usage_limits` است.
- UI `BusinessSettingsWorkspace` نمونه محلی است؛ نام کسب‌وکار، صنف، کانال‌ها، ساعات و ارز در state مرورگر تغییر می‌کنند و ذخیره در سرور ندارند.
- API مدیریتی جامع تنظیمات و schema validation در این بررسی اثبات نشده است.

### entitlement و Central
- تصمیم قطعی LIC-MODULE-CONTROL-2026-001: Central مرجع entitlement قابلیت‌ها؛ Backend نصب مشتری باید مجوز معتبر را enforce کند.
- `pos_feature_flags` محلی به‌تنهایی entitlement Central نیست و نباید جایگزین آن شود.
- در schema و routes بررسی‌شده، جریان کامل دریافت/اعتبارسنجی entitlement از Central یا backend enforcement آن اثبات نشده است.
- بسته‌ها و ترکیب تجاری امکانات همچنان نامشخص و خارج از اختیار حدس توسعه‌دهنده‌اند.

## 3. ناوبری مرکز تنظیمات

گروه‌ها:
1. اطلاعات کسب‌وکار
2. شعبه‌ها و تنظیمات شعبه
3. کاربران
4. نقش‌ها و مجوزها
5. قابلیت‌ها و وضعیت لایسنس
6. ساعات کاری و کانال‌های سفارش
7. تنظیمات عمومی و تاریخچه تغییرات

منو باید بر اساس permission و entitlement هر قابلیت ساخته شود. در صورت عدم دسترسی، کاربر نباید داده محرمانه را با بازکردن مستقیم URL یا API دریافت کند.

## 4. کاربران

### فهرست
- نام نمایشی، username، وضعیت حساب، scope تخصیص (کسب‌وکار/شعبه) و نقش‌های تخصیص‌یافته.
- جست‌وجو و فیلتر فقط پس از API واقعی.
- تفکیک کاربر سطح کسب‌وکار از کاربر محدود به شعبه.
- نمایش کاربر فعال/غیرفعال فقط در صورت وجود فیلد و endpoint واقعی.

### ایجاد کاربر
فیلدها:
- username با الگوی 3 تا 64 حرف کوچک لاتین، عدد، نقطه، underscore یا hyphen.
- نام نمایشی تا 120 کاراکتر.
- انتخاب نقش فعال و غیرسیستمی؛ نقش owner یا wildcard ممنوع.
- انتخاب scope: کسب‌وکار یا شعبه فعال فعلی.
- برای ایجاد در سطح شعبه، context شعبه لازم است.

### رمز موقت
- Backend رمز تصادفی تولید می‌کند و فقط یک بار در پاسخ برمی‌گرداند.
- UI باید نمایش یک‌باره امن با امکان copy، هشدار عدم ثبت در log و دستور تحویل امن داشته باشد.
- بعد از بسته‌شدن پنجره، رمز دوباره قابل مشاهده نیست.
- ارسال رمز از طریق پیامک/ایمیل تا زمانی که مسیر امن و رضایت/سیاست مشخص نشده، پیشنهاد نشود.
- اولین ورود باید اجبار تغییر رمز را نشان دهد.

### چرخه دسترسی
- مشاهده نقش‌های کاربر.
- تخصیص/حذف نقش در سطح کسب‌وکار یا شعبه.
- تغییرات با تأیید صریح و audit.
- حذف آخرین مدیر مجاز یا کاهش دسترسی خود مدیر به شکلی که سیستم را بدون مدیر کند باید با guard سمت سرور ممنوع یا به مسیر مالک نصب ارجاع شود.
- reset password، deactivate user و revoke sessions تا زمان endpoint تأییدشده فقط به‌عنوان backlog نمایش داده شوند.

## 5. نقش‌ها و مجوزها

### فهرست نقش‌ها
- نام و کد.
- system/custom.
- تعداد مجوزها.
- وضعیت فعال.
- نقش‌های system غیرقابل ویرایش‌اند.
- نقش owner و wildcard نباید به کاربران عادی تخصیص داده شوند.

### نقش سفارشی
- کد یکتا با الگوی lowercase و محدودیت طول.
- نام حداکثر 100 کاراکتر.
- انتخاب مجوزها از فهرست معتبر Backend.
- مدیر فقط می‌تواند مجوزهایی را به نقش بدهد که خودش در همان scope داراست؛ از privilege escalation جلوگیری شود.
- ساخت و ویرایش با idempotency و audit.
- مجوزها به گروه‌های UX تقسیم شوند: فروش/سفارش، کاتالوگ، انبار، صندوق/مالی، مشتری/پیامک، تنظیمات، گزارش و audit.
- گروه‌بندی صرفاً نمایش UX است؛ کد permission اصلی و دقیقاً از Backend خوانده شود.

### تفکیک سطح کسب‌وکار و شعبه
- مجوز کسب‌وکار در scope آن کسب‌وکار اعمال می‌شود.
- مجوز شعبه فقط در همان شعبه معتبر است.
- UI scope را کنار نقش نمایش دهد تا مدیر متوجه تفاوت شود.
- انتخاب شعبه خارج از scope مجاز باید توسط Backend رد شود.

### ماتریس دسترسی
- جدول نقش‌ها × مجوزها برای مرور سریع.
- در موبایل، مجوزها به گروه‌های بازشونده تبدیل شوند.
- مجوزهای حساس مانند مالی، مدیریت کاربران، refund، تنظیمات provider و audit با توضیح کوتاه و هشدار مشخص شوند.
- عدم دسترسی یک کاربر را با entitlement ماژول اشتباه نگیریم.

## 6. تنظیمات کسب‌وکار و شعبه

### اطلاعات پایه
- نام، کد، پروفایل صنفی، timezone، ارز داخلی و اطلاعات تماس.
- اطلاعات کسب‌وکار و شعبه در صفحه‌های جدا و با context مشخص.
- timezone شعبه می‌تواند override داشته باشد؛ زمان‌بندی/گزارش باید timezone مؤثر را استفاده کند.
- schema و business setup برخی داده‌ها را دارد؛ فرم جامع و API update باید تکمیل/تطبیق شوند.

### کانال‌های سفارش
- حضوری/میز، بیرون‌بر، ارسال و آنلاین.
- فعال‌بودن کانال باید هم در UI و هم در Backend سفارش/Storefront enforce شود.
- فعال‌کردن storefront نباید بدون entitlement و آماده‌بودن endpoint فروش آنلاین امکان‌پذیر باشد.

### ساعات کاری
- برنامه هفتگی، ساعت شروع/پایان و timezone.
- استثنای روز خاص، تعطیلی موقت و ساعت جداگانه شعبه در صورت قرارداد.
- زمان‌های شبانه‌روزی و بازه عبوری از نیمه‌شب باید در مدل داده صریح باشند؛ صرف دو time input کافی نیست.
- تا وجود API تنظیمات، UI فعلی صرفاً preview است و نباید «ذخیره شد» عملیاتی نمایش دهد.

### تاریخچه تنظیمات
- schema شامل `pos_setting_history` با before/after JSON، actor، reason و timestamp است.
- هر تغییر مهم باید history و audit داشته باشد.
- secretها نباید در history خام ذخیره شوند؛ فقط metadata/نسخه/ماسک.

## 7. ماژول‌ها و entitlement قابلیت‌ها

### تصمیم قطعی
- Central مرجع مجوز قابلیت‌هاست.
- نصب مشتری entitlement معتبر را دریافت/اعتبارسنجی می‌کند.
- Backend هر endpoint/use case مربوط را قبل از اجرا enforce می‌کند.
- UI فقط تجربه را مطابق entitlement نشان می‌دهد؛ مخفی‌کردن منو امنیت محسوب نمی‌شود.
- خاموش‌شدن قابلیت نباید داده تاریخی را حذف کند یا سفارش/مالی/انبار را خراب کند.

### UX مدیر نصب/مشتری
- فهرست capability code و نام قابل فهم.
- وضعیت entitlement: فعال، غیرفعال، در انتظار همگام‌سازی، منقضی/نامعتبر فقط اگر قرارداد واقعی Central این وضعیت‌ها را ارائه کند.
- منبع وضعیت و آخرین زمان اعتبارسنجی.
- توضیح اینکه غیرفعال‌شدن قابلیت چه اثری بر عملیات جدید و داده تاریخی دارد.
- دسترسی read-only/export در خاموشی فقط طبق policy مصوب همان capability.

### مرز Central
- Central control plane است و داده عملیاتی مشتری را نگهداری نمی‌کند.
- مدیریت لایسنس، پلن، entitlement، نصب، نسخه و update در Central انجام می‌شود.
- UI نصب مشتری فقط وضعیت و قابلیت‌های مجاز را نمایش می‌دهد؛ تغییر entitlement در Central و با مجوز اپراتور مجاز انجام می‌شود.
- بسته‌های فروش، قیمت، ترکیب ماژول‌ها، quota و tierها هنوز تعیین نشده‌اند؛ UI نباید نام/قیمت/ترکیب فرضی نمایش دهد.

### مدل فنی پیشنهادی برای UX
- Capability registry با code پایدار، dependency و scope.
- Entitlement snapshot امضاشده و دارای version/issued_at/expiry/installation binding.
- Backend policy gate با fail-closed برای قابلیت‌های حساس و رفتار آفلاین مطابق policy معتبر.
- cache/status قابل مشاهده اما بدون اعتماد به state کلاینت.
این موارد مشخصات پیشنهادی معماری UX هستند و نیازمند تطبیق با طراحی فنی Central؛ schema فعلی آن‌ها را اثبات نمی‌کند.

## 8. تنظیمات عمومی و feature flags

- feature flag محلی برای rollout داخلی/تست با entitlement تجاری یکی نیست.
- usage limits محلی نیز به‌تنهایی پلن فروش یا entitlement محسوب نمی‌شوند.
- کلیدهای setting دارای schema_version باشند؛ فرم هر بخش schema-aware و versioned باشد.
- تغییر تنظیمات حساس با دلیل، actor، before/after و audit.
- تنظیمات عمومی شامل ظاهر/زبان/تاریخ/چاپگر/ساعات در صورت وجود API؛ گزینه فاقد API غیرفعال/پیش‌نمایش بماند.

## 9. امنیت و خطاها

- ورود username/password و نشست سمت سرور؛ هیچ مجوزی از localStorage یا state UI مرجع نشود.
- permission middleware روی تمام routeهای خصوصی.
- CSRF و Same-Origin برای write endpoints.
- جلوگیری از role escalation در ساخت و تغییر نقش.
- owner/system role immutable و protected.
- branch/business scope در تمام queryها و mutationها.
- idempotency و audit برای تغییرات مدیریتی.
- رمز موقت فقط یک بار؛ هیچ log/analytics یا clipboard ناخواسته.
- entitlement invalid/expired/offline unknown باید در Backend enforce شود.
- UI حالت‌های 401، 403، 404، 409، validation، network error و feature disabled را پوشش دهد.

## 10. حالت‌های صفحه و واکنش‌گرایی

- Loading و skeleton.
- Empty users/roles.
- No permission.
- Role protected/system.
- Role escalation denied.
- Duplicate username/role code.
- Branch context required.
- Temporary password shown once.
- Settings preview/not saved.
- Entitlement unavailable/invalid.
- Desktop: جدول users و ماتریس permission.
- Tablet: جدول فشرده و پنل ویرایش.
- Mobile: کارت کاربر و گروه‌های permission بازشونده.
- RTL کامل و شناسه‌های فنی/username در LTR.
- رنگ وضعیت همراه label متنی.

## 11. کارهای Backend/Integration باقی‌مانده

1. UI واقعی برای مدیریت کاربران/نقش‌ها و اتصال به APIهای موجود.
2. تکمیل deactivate/reactivate، reset password، session revoke در صورت نیاز و policy.
3. بررسی route-level permission برای همه endpointهای user/role/branch و تطبیق scope.
4. API خواندن/نوشتن تنظیمات کسب‌وکار و شعبه با schema validation و history.
5. policy ساعات کاری و channel flags و enforcement در order/storefront.
6. طراحی و پیاده‌سازی Central entitlement contract، signature verification و refresh/revocation.
7. Backend entitlement middleware/use-case gate و تست fail-closed.
8. policy حفظ داده و read-only/export هنگام disable.
9. UI وضعیت لایسنس/entitlement در نصب مشتری و عملیات مدیریتی در Central.
10. تفکیک feature flags داخلی از entitlement تجاری.
11. تست امنیت role escalation، owner protection، scope، CSRF، session revoke، entitlement tampering و offline expiry.

## 12. معیار پذیرش

- [ ] ورود username/password و نشست سمت سرور برقرار است.
- [ ] فهرست و ایجاد کاربران business/branch scope را درست نشان می‌دهد.
- [ ] رمز موقت فقط یک‌بار نمایش داده می‌شود و تغییر رمز اولیه اجباری است.
- [ ] نقش system/owner محافظت می‌شود.
- [ ] مدیر نمی‌تواند مجوزی فراتر از مجوزهای خود به نقش بدهد.
- [ ] permission در Backend و scope در هر درخواست enforce می‌شود.
- [ ] تنظیمات preview به‌عنوان ذخیره واقعی معرفی نمی‌شوند.
- [ ] entitlement Central از RBAC و local feature flag جدا است.
- [ ] Backend بدون entitlement معتبر قابلیت تجاری را اجرا نمی‌کند.
- [ ] خاموش‌کردن ماژول داده تاریخی را حذف نمی‌کند.
- [ ] بسته‌های فروش/قیمت/ترکیب امکانات فرض یا hard-code نشده‌اند.
- [ ] همه تغییرات مدیریتی audit و idempotent هستند.
- [ ] RTL و حالات خطا و permission/entitlement پوشش داده شده‌اند.

## 13. ثبت تغییرات

- 2026-11-20 — نسخه 0.1 بر اساس Auth/RBAC/User/Role services، routes و schema موجود.
- وضعیت: آماده بازبینی مفهومی؛ UI مدیریت و Central entitlement هنوز نیازمند تکمیل‌اند.
- این سند به معنی تکمیل ماژول تنظیمات، مدیریت لایسنس یا پذیرش فاز 9 نیست.
