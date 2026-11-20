# PersiaCoder POS V3 — Design System

وضعیت: زیرساخت اولیه در حال تکمیل؛ هنوز build و پذیرش مرورگری تأیید نشده است.

## اصول

- رابط فارسی و RTL به‌صورت پیش‌فرض.
- هویت ثابت محصول PersiaCoder.ir؛ عبارت Powered by PersiaCoder.ir در پوسته حفظ می‌شود.
- رنگ‌ها و وضعیت‌ها باید معنای ثابت و قابل دسترس داشته باشند؛ رنگ تنها حامل معنا نباشد و متن وضعیت نیز نمایش داده شود.
- کامپوننت‌های مشترک فقط در workspace مستقل `v3/frontend/src/shared/ui/` نگهداری شوند؛ هیچ import از runtime ریشه/V2 مجاز نیست.
- نمایش داده عملیاتی فقط پس از اتصال به API معتبر مجاز است؛ placeholderها باید صریحاً وضعیت اتصال‌نداشتن را اعلام کنند.

## توکن‌ها

فایل مرجع: `src/tokens.css`.

| خانواده | نمونه | کاربرد |
|---|---|---|
| Brand | `--pc-brand`, `--pc-brand-soft` | هویت و اقدام اصلی |
| Text | `--pc-ink`, `--pc-muted`, `--pc-subtle` | متن اصلی و ثانویه |
| Surface | `--pc-surface`, `--pc-canvas`, `--pc-line` | سطوح و مرزبندی |
| Semantic | `--pc-success`, `--pc-warning`, `--pc-danger`, `--pc-info` | پیام و وضعیت با متن همراه |
| Radius | `--pc-radius-sm` تا `--pc-radius-xl` | گوشه‌ها |
| Elevation | `--pc-shadow-sm`, `--pc-shadow-md` | عمق سطوح |
| Focus | `--pc-focus-ring` | حلقه فوکوس صفحه‌کلید |

Aliasهای کوتاه فعلی برای سازگاری CSS پوسته نگه داشته شده‌اند؛ در کامپوننت‌های جدید از نام‌های `--pc-*` استفاده شود.

## کامپوننت‌های پایه

پیاده‌سازی: `src/shared/ui/primitives.tsx`، `form-controls.tsx` و `data-table.tsx`؛ استایل‌ها در `primitives.css` و `forms-table.css`؛ ورودی عمومی: `src/shared/ui/index.ts`.

- `Button`: variantهای `primary`، `secondary`، `ghost` و `danger`؛ اندازه‌های `sm`، `md` و `icon`. نوع پیش‌فرض button است تا submit ناخواسته فرم ایجاد نشود.
- `Surface`: سطح معنایی section با padding قابل انتخاب.
- `PageHeader`: عنوان، توضیح، eyebrow و action اختیاری.
- `StatusBadge`: نشانگر متنی با variantهای neutral/success/warning/danger/info؛ وضعیت هرگز فقط با رنگ نمایش داده نشود.
- `Notice`: پیام توضیحی info یا warning.
- `EmptyState`: حالت خالی/درحال‌ساخت با آیکن، عنوان، توضیح و notice اختیاری.
- `TextField` و `SelectField`: کنترل‌های ورودی دارای label، hint، error، شناسه پایدار و اتصال `aria-describedby`.
- `CheckboxField`: چک‌باکس دارای label و توضیح/خطا.
- `FilterBar`: ظرف واکنش‌گرا برای فیلترها.
- `DataTable<Row>`: جدول typed با render ستون، کلید ردیف پایدار، alignment، عرض و حالت خالی.

نمونه:

```tsx
import { Button, PageHeader, StatusBadge, Surface } from "../shared/ui";

<PageHeader
  title="مرکز سفارش‌ها"
  description="مدیریت سفارش‌های همه کانال‌ها"
  action={<StatusBadge variant="warning">پیش‌نمایش</StatusBadge>}
/>
<Surface padding="md">
  <Button variant="primary">ثبت سفارش</Button>
</Surface>
```

## دسترس‌پذیری و تعامل

- همه دکمه‌ها باید نام قابل فهم داشته باشند؛ کنترل آیکنی نیازمند `aria-label` است.
- فوکوس صفحه‌کلید با `:focus-visible` قابل مشاهده می‌ماند.
- وضعیت انتخاب ناوبری با `aria-current` اعلام می‌شود.
- پیام‌های EmptyState از `aria-live="polite"` استفاده می‌کنند.
- از انیمیشن غیرضروری پرهیز شود و `prefers-reduced-motion` رعایت شود.
- خطاهای فرم باید متنی، نزدیک فیلد و با `aria-invalid` و `aria-describedby` مرتبط باشند؛ رنگ به‌تنهایی کافی نیست.
- جدول‌ها باید عنوان ستون با `scope=col`، متن خالی واضح و wrapper اسکرول افقی داشته باشند.
- ترتیب DOM و جهت متن باید با RTL سازگار باشد؛ برای داده‌های لاتین/شناسه‌ها در صورت نیاز `dir="ltr"` موضعی اعمال شود.

## محدودیت فعلی

این مجموعه فقط پایه UI است. هنوز تست واحد، Storybook، visual regression، typecheck، lint، build و browser acceptance اجرا نشده‌اند. هیچ component به API، احراز هویت یا دیتابیس متصل نیست. نسخه اولیه برای استفاده عملیاتی/production آماده نیست.
