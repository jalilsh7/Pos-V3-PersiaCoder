# طراحی فیزیکی دیتابیس تجاری — PersiaCoder POS V3

- تاریخ: 2026-11-19
- وضعیت: **طراحی فیزیکی نسخه اول؛ آماده بازبینی فنی پیش از تولید DDL**
- دامنه: رستوران، کافه، فست‌فود، آبمیوه و بستنی؛ نصب تک‌شعبه‌ای
- شاخه: feature/3food-pos-next-release-v2
- اسناد مبنا: COMMERCIAL_CORE_ERD_V3.md، ORDER_DATA_MODEL_V3.md، PAYMENT_LEDGER_V3.md، FINANCE_INVENTORY_DATA_MODEL_V3.md و FEATURE_ACTIVATION_LIMITS_V3.md

> این سند قرارداد فیزیکی جداول، کلیدها، نوع داده، قیود و ایندکس‌ها را تثبیت می‌کند. هنوز SQL اجرایی، migration، API یا تغییر دیتابیس ساخته/اجرا نشده است. schema فعلی php-backend/schema.sql متعلق به runtime PHP/MySQL موجود است؛ آن را بدون طراحی و مجوز جداگانه به schema تجاری جدید تبدیل یا تخریب نمی‌کنیم. V3 تجاری باید از دیتابیس خالی و baseline مستقل نصب شود و هیچ داده V2 را backfill نکند.

## 1. تصمیم‌های پایه فیزیکی

### 1.1 موتور و نسخه
- موتور همه جدول‌های عملیاتی: InnoDB.
- هدف پشتیبانی: **MySQL 8.0.16+ یا MariaDB 10.6+**؛ MySQL 5.7 در هدف تجاری جدید نیست.
- دلیل حداقل MySQL: CHECK constraintهای enforced از 8.0.16. CI فعلی MySQL 8.4 است؛ سازگاری MariaDB باید در job مستقل آزموده شود.
- PHP هدف: 8.2+ با PDO و pdo_mysql؛ prepared statements و exception mode.
- charset/collation: utf8mb4 / utf8mb4_unicode_ci.
- timestampها در DB به UTC و DATETIME(3) ذخیره شوند؛ timezone کسب‌وکار در تنظیمات نصب Asia/Tehran باشد. تبدیل به تاریخ شمسی در لایه نمایش/گزارش انجام شود.

### 1.2 قرارداد شناسه
- کلید داخلی تمام جداول رابطه‌ای: BIGINT UNSIGNED AUTO_INCREMENT.
- برای resourceهایی که از API، لینک عمومی، webhook یا دستگاه بیرونی شناسه پایدار لازم دارند: public_id از نوع CHAR(36) با charset ascii و collation ascii_bin، UUID v4 تولیدشده در Backend و UNIQUE. این شناسه جایگزین FK داخلی نمی‌شود.
- FKها دقیقاً هم‌نوع و هم‌علامت با PK مرجع باشند.
- شناسه سفارش نمایشی order_number جدا از PK و در محدوده کسب‌وکار unique باشد؛ شمارنده با ردیف قفل‌شونده و transaction افزایش یابد.
- کلیدهای idempotency: CHAR(36) ascii_bin یا برای provider با طول متغیر VARCHAR(128) ascii_bin؛ دامنه یکتایی در هر جدول صریح و unique باشد.
- شناسه provider/trace به‌صورت رشته نگهداری شود تا صفرهای ابتدایی حذف نشوند.

### 1.3 پول، مقدار و واحد
- مبالغ عملیاتی نسخه اول **عدد صحیح تومان** هستند؛ float/double ممنوع.
- برای حفظ قرارداد فعلی مدل، currency_code='IRT' به‌عنوان کد داخلی محصول برای مبلغ تومانی نگهداری می‌شود؛ این قرارداد به‌معنی تطابق واحد تومانی با ISO Rial نیست. هر adapter بانکی باید واحد provider را صریح و تست‌شده تبدیل کند. این تفاوت در خروجی‌های استاندارد/حسابداری نباید مبهم بماند.
- مبلغ غیرمنفی: BIGINT UNSIGNED؛ delta مالی signed: BIGINT.
- مقدار مواد/موجودی: DECIMAL(18,6)؛ نسبت تبدیل واحد: DECIMAL(18,8)؛ قیمت تمام‌شده واحد در صورت نیاز به دقت کسری تومان: DECIMAL(24,8).
- محاسبات Backend با integer/decimal-safe انجام شوند؛ تبدیل به float ممنوع.
- سند مالی/انباری currency و unit snapshot یا قرارداد روشن زمان ثبت دارد؛ تغییر تنظیمات بعدی سند قبلی را بازنویسی نمی‌کند.

### 1.4 ستون‌های مشترک و حذف
- جدول‌های master قابل ویرایش: created_at DATETIME(3)، updated_at DATETIME(3)، و در صورت نیاز created_by/updated_by.
- داده‌های دارای سابقه عملیاتی به‌جای حذف فیزیکی is_active یا archived_at می‌گیرند.
- اسناد، eventها، ledger، پرداخت، refund و گردش موجودی با ON DELETE RESTRICT محافظت شوند؛ update/delete عمومی ممنوع. اصلاح با reversal/adjustment جدید.
- حساب کاربر archive/disable می‌شود؛ حذف فیزیکی کاربر دارای سابقه ممنوع.
- از FK polymorphic (یک ستون type/id بدون FK واقعی) در روابط مالی و موجودی استفاده نشود. برای چند نوع مرجع، FKهای nullable مجزا با constraint «حداکثر یک مرجع» یا جدول subtype تعریف شود.

## 2. فهرست جداول نسخه اول

### 2.1 نصب، کاربران، مجوز و تنظیمات
| جدول | کلیدها و ستون‌های مهم | قیود/کاربرد |
|---|---|---|
| app_install_state | singleton id=TINYINT، setup_completed، installed_at | فقط یک ردیف؛ نصب اولیه امن |
| app_schema_migrations | version VARCHAR(64)، checksum CHAR(64)، applied_at، execution_ms | PK version؛ ledger مهاجرت |
| app_users | BIGINT PK، public_id، username، password_hash، display_name، is_active، session_version، last_login_at | username unique؛ disable به‌جای delete |
| app_roles | BIGINT PK، code، name، is_system، is_active | code unique |
| app_permissions | BIGINT PK، code، description | code unique و تحت کنترل کد |
| app_role_permissions | role_id، permission_id | PK مرکب؛ FK هر دو |
| app_user_roles | user_id، role_id | PK مرکب؛ چند نقش |
| app_audit_logs | actor nullable، action، entity_type/id، before_json، after_json، reason، request_id، ip، created_at | append-only؛ index actor/time و entity/time |
| pos_business_settings | setting_key، value_json، schema_version، updated_by، updated_at | PK setting_key؛ allowlist و validation سمت سرور |
| pos_feature_flags | feature_code، is_enabled، updated_by، updated_at | PK feature_code؛ کاتالوگ ثابت Backend |
| pos_usage_limits | resource_code، max_active، updated_by، updated_at | PK resource_code؛ سقف شمارشی |
| pos_setting_history | id، setting_key، before_json، after_json، actor، reason، created_at | append-only؛ index key/time |

تنظیمات ساده و قابل فهم‌اند؛ سیستم پلن/اشتراک چندسطحی در نسخه اول ساخته نمی‌شود. feature_code و resource_code کدهای ثابت Backend هستند، نه مقادیر دلخواه مشتری. سقف تعداد با transaction و قفل ردیف policy enforce می‌شود.

### 2.2 کاتالوگ و مشتری
| جدول | کلیدها و ستون‌های مهم | قیود/کاربرد |
|---|---|---|
| pos_categories | id، parent_id nullable، name، sort_order، is_active | index parent/active |
| pos_products | id، public_id، category_id، name، sku nullable، product_type، base_price_amount، tax_profile، is_active، archived_at | SKU unique در صورت مقدار؛ archive |
| pos_product_variants | id، product_id، name، sku، price_delta_amount، is_active | unique product/sku |
| pos_option_groups | id، name، selection_type، min_select، max_select، is_active | گروه افزودنی |
| pos_options | id، group_id، name، price_delta_amount، is_active | FK group |
| pos_product_option_groups | product_id، option_group_id، sort_order، is_required | PK مرکب |
| pos_customers | id، public_id، name، phone_normalized، phone_display، notes محدود، loyalty projection | index phone؛ دسترسی PII محدود |

Snapshot نام/قیمت/مالیات/گزینه‌های محصول در ردیف سفارش ذخیره می‌شود؛ archive محصول نباید فاکتور گذشته را تغییر دهد. مقادیر وفاداری از ledger مشتق می‌شوند و از payload صندوق‌دار قابل دستکاری نیستند.

### 2.3 میز، سالن و نشست
| جدول | کلیدها و ستون‌های مهم | قیود/کاربرد |
|---|---|---|
| pos_dining_areas | id، name، sort_order، is_active | سالن |
| pos_dining_tables | id، area_id، table_code، capacity، is_active | unique area/table_code |
| pos_table_sessions | id، public_id، table_id، opened_by، status، opened_at، closed_at، active_table_id generated | unique active_table_id؛ حداکثر یک نشست فعال برای هر میز |

active_table_id ستون generated nullable است: در وضعیت باز مقدار table_id و در وضعیت بسته NULL؛ unique index از دو نشست فعال جلوگیری می‌کند. باز/بستن نشست با lock روی ردیف میز و transaction انجام می‌شود.

### 2.4 سفارش و آماده‌سازی
| جدول | کلیدها و ستون‌های مهم | قیود/کاربرد |
|---|---|---|
| pos_orders | id، public_id، order_number، source_channel، order_status، payment_status projection، customer_id nullable، table_session_id nullable، totals، currency_code، accepted_at، created_by | order_number unique؛ index status/created و channel/created |
| pos_order_items | id، order_id، product_id nullable، product/variant/SKU/name snapshot، qty، unit_price، discount/tax/line_total، recipe_version_id nullable | FK order؛ snapshot مستقل |
| pos_order_item_options | id، order_item_id، group/option ids nullable، name/price snapshot، qty | FK item |
| pos_order_status_events | id، order_id، from_status، to_status، reason، actor، source_channel، idempotency_key، metadata | append-only؛ unique event idempotency |
| pos_order_fulfillments | id، order_id unique، method، status، recipient/address snapshot، delivery_fee، scheduled_for | آدرس فقط برای نقش‌های لازم |
| pos_preparation_stations | id، name، station_type، is_active، sort_order | ایستگاه آشپزخانه/بار |
| pos_order_item_preparations | id، order_item_id، station_id، status، qty، started_at، ready_at، actor IDs | index station/status/time |
| pos_order_adjustments | id، order_id، order_item_id nullable، adjustment_type، amount/qty، reason، actor، idempotency_key | append-only؛ لغو جزئی/اصلاح |

statusها در Domain Service ثابت و versioned هستند؛ جدول transition قابل ویرایش توسط مشتری نداریم. payment_status projection است و فقط از ledger بازسازی می‌شود. هر سفارش یک fulfillment header دارد؛ چند preparation task برای یک order item مجاز است.

### 2.5 پرداخت، کارتخوان و تسویه
| جدول | کلیدها و ستون‌های مهم | قیود/کاربرد |
|---|---|---|
| pos_payments | id، public_id، order_id، method، provider_code، source_type، amount، currency_code، status، idempotency_key، terminal_transaction_id nullable، provider_reference، captured_at، created_by | unique idempotency؛ unique terminal_transaction_id؛ index order/status/time |
| pos_payment_events | id، payment_id، event_type، from_status، to_status، amount، actor، provider_reference، idempotency_key، safe_payload، created_at | append-only؛ unique event idempotency |
| pos_refunds | id، public_id، payment_id، order_id، amount، method، status، reason، idempotency_key، provider_reference، requested_by، approved_by، completed_at | unique idempotency؛ index payment/status |
| app_terminal_agents | id، public_id، name، token_hash، enabled، version، last_seen_at | token خام فقط یک‌بار نمایش؛ hash در DB |
| pos_payment_terminals | id، public_id، agent_id، local_key، name، provider/model/driver، connection_type، host، port، enabled، is_default | unique agent/local_key؛ index enabled |
| pos_terminal_transactions | id، public_id، idempotency_key، terminal_id، order_id nullable، amount، currency، status، provider refs، safe_response، created_by، requested_at، completed_at | unique idempotency؛ index status/time و terminal/time |
| pos_settlement_batches | id، public_id، provider_code، terminal_id nullable، business_date، file_fingerprint، status، imported_by، imported_at | unique provider/fingerprint |
| pos_settlement_items | id، batch_id، payment_id nullable، provider_reference، provider_amount، internal_amount، match_status، difference_amount، resolution_actor/reason | unique batch/payment؛ index status/reference |

قواعد:
- مجموع captureهای موفق و refundهای موفق در transaction با قفل سفارش/پرداخت کنترل می‌شود؛ refund موفق از capture همان payment بیشتر نمی‌شود.
- provider reference در محدوده provider unique می‌شود، نه global اگر providerها namespace جدا دارند.
- تراکنش terminal با status مبهم به payment موفق تبدیل نمی‌شود؛ فقط تأیید معتبر provider/agent و اتصال idempotent مجاز است.
- settlement صرفاً تطبیق است و payment/cash movement جدید نمی‌سازد.

### 2.6 صندوق و هزینه
| جدول | کلیدها و ستون‌های مهم | قیود/کاربرد |
|---|---|---|
| pos_registers | id، public_id، code، name، is_active | code unique |
| pos_cash_sessions | id، public_id، register_id، opened_by، opening_amount، expected_cash projection، counted_cash، difference، status، opened_at، closed_at، reviewed_by، active_register_id generated | unique active_register_id |
| pos_cash_movements | id، public_id، session_id، movement_type، amount، source_type، payment_id nullable unique، refund_id nullable unique، expense_id nullable unique، reason، actor، idempotency_key | هر مرجع فقط یک حرکت؛ index session/time |
| pos_expense_categories | id، name، is_active | دسته هزینه |
| pos_expenses | id، public_id، category_id، amount، currency، payment_method، cash_session_id nullable، description، occurred_at، actor، status | هزینه نقدی posted به cash movement وصل می‌شود |

برای cash movement، سه FK nullable مجزا (payment/refund/expense) با constraint «حداکثر یک مرجع» استفاده می‌شود؛ manual deposit/withdrawal هر سه را NULL دارد و reason اجباری است. این روش از polymorphic FK بدون مرجع واقعی جلوگیری می‌کند.
active_register_id ستون generated nullable است: برای وضعیت open و requires_review مقدار register_id و برای وضعیت نهایی NULL؛ unique index مانع session دوم می‌شود. session در requires_review فروش نمی‌پذیرد و تأیید مدیر مغایرت را نهایی می‌کند.

### 2.7 انبار، خرید مستقیم و رسپی
| جدول | کلیدها و ستون‌های مهم | قیود/کاربرد |
|---|---|---|
| pos_units | id، code، name، dimension، precision | code unique |
| pos_stock_items | id، public_id، code، name، base_unit_id، is_active، negative_stock_policy | code unique؛ نسخه اول پیش‌فرض جلوگیری از منفی‌شدن |
| pos_stock_locations | id، public_id، code، name، location_type، is_active | code unique |
| pos_unit_conversions | id، from_unit_id، to_unit_id، factor، effective_from، created_by | versioned؛ factor مثبت |
| pos_stock_documents | id، public_id، document_no، document_type، location_id، destination_location_id، supplier_id nullable، status، source_order_id nullable، reason، actor، posted_at | document_no unique؛ posted immutable |
| pos_stock_document_lines | id، document_id، stock_item_id، qty، unit_id، conversion_snapshot، base_qty، unit_cost_snapshot، total_cost_amount | index document/item |
| pos_stock_ledger | id، stock_item_id، location_id، document_line_id، movement_type، quantity_delta، base_unit_id، unit_cost_snapshot، total_cost_amount، source_event_key، created_at | append-only؛ unique source_event_key؛ index item/location/time |
| pos_stock_balances | stock_item_id، location_id، quantity_on_hand، quantity_reserved، average_unit_cost، updated_at | PK مرکب؛ projection قابل rebuild |
| pos_stock_reservations | id، public_id، order_id، status، expires_at، source_type، idempotency_key، created_at | unique idempotency؛ index status/expiry |
| pos_stock_reservation_lines | id، reservation_id، stock_item_id، location_id، base_qty، consumed_qty، released_qty | unique reservation/item/location |
| pos_recipes | id، product_id/variant scope، name، is_active | recipe header |
| pos_recipe_versions | id، recipe_id، version_no، status، effective_from، created_by | unique recipe/version |
| pos_recipe_lines | id، recipe_version_id، stock_item_id، qty، unit_id، conversion snapshot، waste_percent | unique version/item/unit |
| pos_order_item_recipe_lines | id، order_item_id، recipe_version_id، stock_item_id، location_id، required_base_qty، consumed_base_qty، unit_cost_snapshot | immutable recipe snapshot |
| pos_suppliers | id، public_id، name، phone، notes، is_active | مرجع اختیاری رسید خرید |

نسخه اول موجودی منفی مواد اولیه را مسدود می‌کند؛ این انتخاب از ابهام WMA در حالت کسری جلوگیری می‌کند. موجودی هر محل مستقل است؛ چند محل نگهداری در نصب تک‌شعبه‌ای مجاز است. تولید batch/pre-preparation در MVP نیست.

### 2.8 دفتر وفاداری و تخفیف
- pos_customer_ledger: append-only برای امتیاز/اعتبار/تعداد مراجعه؛ هر اثر به order/event یکتا متصل شود. مانده مشتری projection قابل بازسازی است.
- pos_discount_redemptions: ثبت مصرف کد تخفیف با unique order_id و reversal؛ محدودیت سهمیه در transaction.

## 3. ایندکس‌های حیاتی
- سفارش‌ها: (order_status, created_at, id)، (source_channel, created_at, id)، unique order_number و public_id.
- ردیف سفارش: (order_id, id)؛ رویدادها: (order_id, created_at, id).
- پرداخت‌ها: unique idempotency_key، (order_id, status, created_at)، provider reference scoped provider؛ refund: (payment_id, status).
- صف آماده‌سازی: (station_id, status, created_at, id).
- صندوق: unique generated active_register_id؛ movements: (session_id, created_at, id).
- موجودی: unique (stock_item_id, location_id)؛ ledger: (stock_item_id, location_id, created_at, id)؛ reservations: (status, expires_at).
- تسویه: unique file_fingerprint، (match_status, batch_id).
- audit: (actor_user_id, created_at)، (entity_type, entity_id, created_at).

از indexگذاری همه ستون‌ها خودداری شود؛ queryهای واقعی و EXPLAIN در مرحله پیاده‌سازی مبنای indexهای نهایی‌اند.

## 4. تراکنش‌ها و invariantهای اجباری
1. ایجاد سفارش: کنترل شماره ترتیبی، validate قیمت/تخفیف/موجودی، insert order/items/status event، snapshot recipe و رزرو مواد، idempotency، در transaction واحد.
2. ثبت capture: lock order و payment، validate remaining due، insert payment/event، update payment_status projection و cash movement در صورت نقدی، atomic.
3. اتصال terminal: lock terminal transaction و order؛ status باید approved و مبلغ/currency دقیقاً برابر باشد؛ فقط یک payment به آن متصل شود.
4. refund: lock payment و order؛ مجموع refund موفق + مبلغ درحال‌پردازش از capture بیشتر نشود؛ event و cash out فقط در موفقیت refund نقدی.
5. بستن صندوق: lock session؛ محاسبه expected از movements؛ اختلاف غیرصفر به requires_review و عدم پذیرش movement فروش جدید؛ مدیر نهایی کند.
6. stock posting: lock balance rows با ترتیب ثابت stock_item_id/location_id؛ جلوگیری از منفی‌شدن؛ درج document/ledger و update projection در transaction واحد.
7. رزرو: lock balance rows؛ on_hand - reserved را کنترل؛ TTL و idempotency؛ مصرف رزرو در شروع preparation با درج ledger و کاهش reserved در یک transaction.
8. انتقال: دو ledger row برابر و مخالف برای مبدا/مقصد، یک transaction و یک سند انتقال.
9. رسید خرید: سند ورودی posted و WMA جدید را در transaction واحد می‌سازد؛ هزینه تاریخی خروج را تغییر نمی‌دهد.
10. limit enforcement: قفل ردیف pos_usage_limits یا resource guard transaction-safe پیش از create/activate؛ شمارش active و درج/تغییر resource در همان transaction.

همه عملیات چندقلمی با ترتیب صعودی PK قفل می‌گیرند تا deadlock کاهش یابد؛ deadlock همچنان ممکن است و retry محدود فقط برای خطاهای قابل retry با idempotency انجام می‌شود.

## 5. نصب تمیز و مهاجرت
- baseline نصب تازه در database/v3/schema.sql ثبت شده است؛ database/v3/migrations/0001_... برای تغییرات پس از انتشار baseline استفاده خواهد شد.
- این مسیر از php-backend/schema.sql و migrationهای runtime فعلی جدا می‌ماند تا schema جدید تصادفاً روی دیتابیس موجود اجرا نشود.
- app_schema_migrations ثبت version، checksum، applied_at و duration می‌کند؛ migration اجراشده با checksum متفاوت fail-fast می‌شود.
- پیش از migration: بررسی اتصال، نسخه DB، charset، engine، فضای کافی، backup/restore و پیش‌نیازها.
- DDL در MySQL/MariaDB ممکن است implicit commit داشته باشد؛ rollback تراکنشی کامل فرض نمی‌شود. migration مرحله‌ای، قابل retry و قابل تشخیص در شکست میانه طراحی می‌شود.
- اجرا فقط از CLI/installer امن با lock نصب؛ endpoint عمومی بدون احراز هویت و lock ممنوع.
- پس از انتشار، migrationها forward-only؛ downgrade مخرب خودکار ممنوع.
- fixture/demo data از schema جدا و فقط برای محیط تست است.
- هیچ داده V2 وارد دیتابیس تجاری V3 نمی‌شود.

## 6. سازگاری MySQL و MariaDB
- JSON فقط برای metadata/snapshot غیرحیاتی و بدون وابستگی به رفتار اختصاصی JSON path/index؛ داده قابل فیلتر/join/unique/report ستون رابطه‌ای typed دارد.
- generated columns و unique index آن‌ها در هر دو DB target باید در CI مستقل آزموده شوند.
- CHECK برای مقدار مثبت، statusهای مجاز و قواعد محلی استفاده می‌شود؛ اعتبارسنجی Backend همچنان اجباری است.
- از ویژگی اختصاصی MySQL 8.4 یا MariaDB-only استفاده نشود؛ ماتریس نسخه مستقل لازم است.
- collation، FK type، prefix index و رفتار JSON در MariaDB واقعی CI بررسی شود.

## 7. تصمیم‌های بسته‌شده و موارد باقیمانده
تصمیم‌های این بسته:
- PK داخلی BIGINT UNSIGNED و UUID عمومی CHAR(36).
- UTC DATETIME(3)، utf8mb4، InnoDB.
- MySQL 8.0.16+/MariaDB 10.6+.
- مبالغ integer toman، مقدارها DECIMAL؛ عدم موجودی منفی در نسخه اول.
- چند نقش، تنظیمات/feature flags/usage limits ساده.
- نشست فعال یکتا با generated nullable unique key.
- روابط نقدی typed FK و عدم استفاده از polymorphic financial references.
- baseline مستقل V3 بدون انتقال V2.

مواردی که در DDL/کدنویسی دقیق می‌شوند:
1. قرارداد payment capture vs authorization و provider-specific references.
2. snapshot دقیق محصول/مالیات/رسپی و ارتباط با preparation task.
3. ساختار نهایی هزینه‌ها و دسته‌بندی گزارش مدیریت (بدون ادعای حسابداری رسمی).
4. valuation projection و الگوریتم WMA با rounding policy و رفتار برگشت خرید/مصرف.
5. schema واقعی terminal adapter پس از انتخاب مدل کارتخوان.
6. index plan با queryهای API واقعی و EXPLAIN.
7. CI ماتریس MySQL 8.4 و MariaDB 10.6/نسخه پایدار پشتیبانی‌شده، نصب تازه، migration retry، backup/restore و race tests.

## 8. معیار پذیرش DB-01
- FKها هم‌نوع و هم‌علامت و همه جداول InnoDB باشند.
- unique/idempotency و generated active-session constraints در هر دو DB target کار کنند.
- پول هیچ‌جا float نباشد و واحد تومان در DB/API/UI صریح و یکسان باشد.
- سفارش، پرداخت، refund، cash movement، settlement و stock ledger منابع حقیقت جدا داشته باشند.
- عملیات مالی/انباری transaction، idempotency و lock order قابل تست داشته باشد.
- نصب تازه فقط schema تجاری V3 را بسازد؛ هیچ داده V2 را backfill نکند.
- migration version/checksum ثبت شود و failure نیمه‌کاره قابل تشخیص باشد.
- backup/restore تمام FKها و ledgerها را بدون فعال‌سازی ناخواسته دستگاه/درگاه بازیابی کند.
- تست MySQL و MariaDB جدا اجرا و خروجی‌ها مستند شوند.

این سند قرارداد طراحی فیزیکی است. baseline اولیه SQL در بسته DB-02 ساخته شده، اما تا زمان اجرای تست روی MySQL/MariaDB disposable و بازبینی نهایی، آماده نصب مشتری یا محیط اصلی محسوب نمی‌شود.
