import { useState, type FormEvent } from "react";
import {
  attachCatalogOptionGroup, createCatalogOptionGroup, createCatalogVariant,
  updateCatalogOption, updateCatalogVariant,
  type PosCatalogProductDetail,
} from "../../shared/api/client";
import { Button, StatusBadge } from "../../shared/ui";

const money = (value: number) => new Intl.NumberFormat("fa-IR").format(value);

export function CatalogOptionsEditor({ productId, detail, onSaved }: {
  productId: string; detail: PosCatalogProductDetail; onSaved: () => Promise<void>;
}) {
  const [variantName, setVariantName] = useState("");
  const [variantSku, setVariantSku] = useState("");
  const [variantDelta, setVariantDelta] = useState("0");
  const [groupName, setGroupName] = useState("");
  const [selectionType, setSelectionType] = useState<"single" | "multiple">("single");
  const [minSelect, setMinSelect] = useState("0");
  const [maxSelect, setMaxSelect] = useState("1");
  const [required, setRequired] = useState(false);
  const [attachRequired, setAttachRequired] = useState(false);
  const [options, setOptions] = useState([{ name: "", price: "0" }, { name: "", price: "0" }]);
  const [busy, setBusy] = useState(false);
  const [message, setMessage] = useState<string | null>(null);

  async function saveVariant(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    const delta = Number(variantDelta);
    if (!variantName.trim() || !Number.isSafeInteger(delta)) { setMessage("نام تنوع و اختلاف قیمت معتبر وارد کنید."); return; }
    setBusy(true); setMessage(null);
    try {
      await createCatalogVariant(productId, { name: variantName.trim(), sku: variantSku.trim() || null, price_delta_amount: delta });
      setVariantName(""); setVariantSku(""); setVariantDelta("0"); await onSaved(); setMessage("تنوع محصول ثبت شد.");
    } catch (error) { setMessage(error instanceof Error ? error.message : "ثبت تنوع ناموفق بود."); }
    finally { setBusy(false); }
  }
  async function saveGroup(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    const clean = options.map((item) => ({ name: item.name.trim(), price_delta_amount: Number(item.price) })).filter((item) => item.name);
    const min = Number(minSelect), max = Number(maxSelect);
    if (!groupName.trim() || clean.length < 1 || clean.some((item) => !Number.isSafeInteger(item.price_delta_amount))
      || !Number.isInteger(min) || !Number.isInteger(max) || min < 0 || max < 1 || max < min
      || (selectionType === "single" && max > 1) || max > clean.length || (required && min < 1)) {
      setMessage("قواعد انتخاب و دست‌کم یک گزینه معتبر وارد کنید."); return;
    }
    setBusy(true); setMessage(null);
    try {
      const group = await createCatalogOptionGroup({ name: groupName.trim(), selection_type: selectionType,
        min_select: min, max_select: max, is_required_default: required, options: clean });
      await attachCatalogOptionGroup(productId, { option_group_id: group.id, sort_order: detail.option_groups.length, is_required: attachRequired });
      setGroupName(""); setOptions([{ name: "", price: "0" }, { name: "", price: "0" }]);
      setMinSelect("0"); setMaxSelect("1"); setRequired(false); setAttachRequired(false);
      await onSaved(); setMessage("گروه گزینه ساخته و به همین محصول متصل شد.");
    } catch (error) { setMessage(error instanceof Error ? error.message : "ساخت گروه گزینه ناموفق بود."); }
    finally { setBusy(false); }
  }
  async function toggle(action: () => Promise<void>) {
    setBusy(true); setMessage(null);
    try { await action(); await onSaved(); }
    catch (error) { setMessage(error instanceof Error ? error.message : "ذخیره تغییر انجام نشد."); }
    finally { setBusy(false); }
  }

  return <section className="catalog-options-editor">
    <div className="catalog-groups-heading"><strong>مدیریت تنوع و افزودنی‌ها</strong><span>ذخیره مستقیم در کاتالوگ</span></div>
    {message ? <p className="catalog-editor-message" role="status">{message}</p> : null}
    <div className="catalog-editor-section">
      <strong>تنوع‌های محصول</strong>
      {detail.variants.map((variant) => <div className="catalog-editor-row" key={variant.id}>
        <span><b>{variant.name}</b><small>{variant.sku || "بدون SKU"} · {variant.price_delta_amount > 0 ? "+" : ""}{money(variant.price_delta_amount)} تومان</small></span>
        <StatusBadge variant={variant.is_active ? "success" : "neutral"}>{variant.is_active ? "فعال" : "غیرفعال"}</StatusBadge>
        <Button size="sm" variant="secondary" disabled={busy} onClick={() => void toggle(() => updateCatalogVariant(variant.id, { is_active: !variant.is_active }))}>{variant.is_active ? "غیرفعال" : "فعال‌سازی"}</Button>
      </div>)}
      {!detail.variants.length ? <small className="catalog-editor-hint">هنوز تنوعی ثبت نشده است.</small> : null}
      <form onSubmit={(event) => void saveVariant(event)} className="catalog-editor-form">
        <input aria-label="نام تنوع" placeholder="نام تنوع، مثلاً بزرگ" value={variantName} onChange={(event) => setVariantName(event.target.value)} maxLength={140} required />
        <input aria-label="SKU تنوع" placeholder="SKU اختیاری" value={variantSku} onChange={(event) => setVariantSku(event.target.value)} maxLength={80} />
        <input aria-label="اختلاف قیمت تنوع" inputMode="numeric" placeholder="اختلاف قیمت" value={variantDelta} onChange={(event) => setVariantDelta(event.target.value)} required />
        <Button size="sm" type="submit" disabled={busy || !variantName.trim()}>افزودن تنوع</Button>
      </form>
    </div>
    <div className="catalog-editor-section">
      <strong>گروه‌های گزینه متصل به محصول</strong>
      {detail.option_groups.map((group) => <div className="catalog-editor-group" key={group.id}>
        <div className="catalog-editor-row"><span><b>{group.name}</b><small>{group.selection_type === "single" ? "تک‌انتخابی" : "چندانتخابی"} · حداقل {group.min_select} / حداکثر {group.max_select} · {group.is_required ? "اجباری برای این محصول" : "اختیاری"}</small></span>
          <StatusBadge variant="success">متصل</StatusBadge>
        </div>
        {group.options.map((option) => <div className="catalog-editor-row catalog-editor-option" key={option.id}>
          <span><b>{option.name}</b><small>{option.price_delta_amount > 0 ? "+" : ""}{money(option.price_delta_amount)} تومان</small></span>
          <StatusBadge variant={option.is_active ? "success" : "neutral"}>{option.is_active ? "فعال" : "غیرفعال"}</StatusBadge>
          <Button size="sm" variant="ghost" disabled={busy} onClick={() => void toggle(() => updateCatalogOption(option.id, { is_active: !option.is_active }))}>{option.is_active ? "غیرفعال" : "فعال‌سازی"}</Button>
        </div>)}
      </div>)}
      {!detail.option_groups.length ? <small className="catalog-editor-hint">گروه گزینه‌ای به این محصول متصل نیست.</small> : null}
      <form onSubmit={(event) => void saveGroup(event)} className="catalog-editor-form catalog-editor-group-form">
        <input aria-label="نام گروه گزینه" placeholder="نام گروه، مثلاً انتخاب سس" value={groupName} onChange={(event) => setGroupName(event.target.value)} maxLength={140} required />
        <select aria-label="نوع انتخاب" value={selectionType} onChange={(event) => { setSelectionType(event.target.value as "single" | "multiple"); if (event.target.value === "single") setMaxSelect("1"); }}>
          <option value="single">تک‌انتخابی</option><option value="multiple">چندانتخابی</option>
        </select>
        <input aria-label="حداقل انتخاب" type="number" min="0" value={minSelect} onChange={(event) => setMinSelect(event.target.value)} />
        <input aria-label="حداکثر انتخاب" type="number" min="1" value={maxSelect} onChange={(event) => setMaxSelect(event.target.value)} />
        <label><input type="checkbox" checked={required} onChange={(event) => { setRequired(event.target.checked); if (event.target.checked && minSelect === "0") setMinSelect("1"); }} /> پیش‌فرض گروه اجباری</label>
        <label><input type="checkbox" checked={attachRequired} onChange={(event) => setAttachRequired(event.target.checked)} /> اجباری برای این محصول</label>
        <div className="catalog-editor-options">
          {options.map((option, index) => <div key={index}>
            <input aria-label={`نام گزینه ${index + 1}`} placeholder={`گزینه ${index + 1}`} value={option.name} onChange={(event) => setOptions((current) => current.map((item, i) => i === index ? { ...item, name: event.target.value } : item))} />
            <input aria-label={`قیمت گزینه ${index + 1}`} inputMode="numeric" placeholder="اختلاف قیمت" value={option.price} onChange={(event) => setOptions((current) => current.map((item, i) => i === index ? { ...item, price: event.target.value } : item))} />
            {options.length > 1 ? <button type="button" onClick={() => setOptions((current) => current.filter((_, i) => i !== index))}>حذف</button> : null}
          </div>)}
          <button type="button" disabled={options.length >= 100} onClick={() => setOptions((current) => [...current, { name: "", price: "0" }])}>افزودن گزینه</button>
        </div>
        <Button size="sm" type="submit" disabled={busy || !groupName.trim()}>ساخت و اتصال گروه گزینه</Button>
      </form>
    </div>
  </section>;
}
