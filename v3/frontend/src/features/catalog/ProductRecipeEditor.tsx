import { useEffect, useMemo, useState } from "react";
import {
  activateRecipeVersion,
  createRecipeDraftVersion,
  getInventoryStockItems,
  getInventoryUnits,
  getProductRecipe,
  updateRecipeDraftVersion,
  type InventoryStockItem,
  type InventoryUnit,
  type ProductRecipe,
} from "../../shared/api/client";
import { Button, SelectField, StatusBadge, TextField } from "../../shared/ui";

type RecipeLineDraft = { stock_item_id: string; quantity: string; unit_code: string; waste_percent: string };
const normalizeDecimalInput = (value: string) => {
  const normalized = value
    .replace(/[۰-۹]/g, (digit) => String("۰۱۲۳۴۵۶۷۸۹".indexOf(digit)))
    .replace(/[٠-٩]/g, (digit) => String("٠١٢٣٤٥٦٧٨٩".indexOf(digit)))
    .replace(/٫/g, ".")
    .replace(/[٬،,\s]/g, "")
    .replace(/[^0-9.]/g, "");
  const [whole, ...fraction] = normalized.split(".");
  return fraction.length ? `${whole}.${fraction.join("")}` : whole;
};

const statusLabel: Record<string, string> = { draft: "پیش‌نویس", active: "فعال", retired: "بازنشسته" };

export function ProductRecipeEditor({ productId, variants }: {
  productId: string;
  variants: { id: string; name: string; is_active: boolean }[];
}) {
  const activeVariants = useMemo(() => variants.filter((variant) => variant.is_active), [variants]);
  const [variantId, setVariantId] = useState("");
  const [recipe, setRecipe] = useState<ProductRecipe | null>(null);
  const [stockItems, setStockItems] = useState<InventoryStockItem[]>([]);
  const [units, setUnits] = useState<InventoryUnit[]>([]);
  const [recipeName, setRecipeName] = useState("");
  const [lines, setLines] = useState<RecipeLineDraft[]>([]);
  const [editingVersionId, setEditingVersionId] = useState<string | null>(null);
  const [revisionSourceVersion, setRevisionSourceVersion] = useState<number | null>(null);
  const [busy, setBusy] = useState(false);
  const [loading, setLoading] = useState(true);
  const [message, setMessage] = useState<string | null>(null);
  const [error, setError] = useState<string | null>(null);

  async function refreshRecipe(scopeId = variantId) {
    setRecipe(await getProductRecipe(productId, scopeId || undefined));
  }

  useEffect(() => {
    let current = true;
    setLoading(true);
    setRecipe(null);
    setError(null);
    Promise.all([
      getProductRecipe(productId, variantId || undefined),
      getInventoryStockItems("", true),
      getInventoryUnits(),
    ]).then(([nextRecipe, nextItems, nextUnits]) => {
      if (!current) return;
      // Draft ingredient rows belong to one product/variant scope only.
      setLines([]);
      setEditingVersionId(null);
      setRevisionSourceVersion(null);
      setMessage(null);
      setError(null);
      setRecipe(nextRecipe);
      setStockItems(nextItems.filter((item) => item.is_active));
      setUnits(nextUnits);
      setRecipeName(nextRecipe?.name ?? "");
    }).catch((cause: unknown) => {
      if (current) setError(cause instanceof Error ? cause.message : "دریافت رسپی و مواد اولیه انجام نشد.");
    }).finally(() => { if (current) setLoading(false); });
    return () => { current = false; };
  }, [productId, variantId]);

  function addLine() {
    const item = stockItems[0];
    if (!item) return;
    setLines((current) => [...current, {
      stock_item_id: item.id,
      quantity: "1",
      unit_code: item.base_unit.code,
      waste_percent: "0",
    }]);
  }

  function updateLine(index: number, patch: Partial<RecipeLineDraft>) {
    setLines((current) => current.map((line, lineIndex) => lineIndex === index ? { ...line, ...patch } : line));
  }

  function editDraft(version: ProductRecipe["versions"][number]) {
    if (version.status !== "draft") return;
    setEditingVersionId(version.id);
    setRecipeName(recipe?.name ?? "");
    setLines(version.lines.map((line) => ({
      stock_item_id: line.stock_item_id, quantity: line.quantity,
      unit_code: line.unit_code, waste_percent: line.waste_percent,
    })));
    setError(null);
    setMessage("پیش‌نویس برای ویرایش بارگذاری شد؛ نسخه فعال یا بازنشسته قابل تغییر نیست.");
  }

  function startActiveRecipeRevision(version: ProductRecipe["versions"][number]) {
    if (version.status !== "active") return;
    setEditingVersionId(null);
    setRevisionSourceVersion(version.version_no);
    setRecipeName(recipe?.name ?? "");
    setLines(version.lines.map((line) => ({
      stock_item_id: line.stock_item_id, quantity: line.quantity,
      unit_code: line.unit_code, waste_percent: line.waste_percent,
    })));
    setError(null);
    setMessage(`اطلاعات نسخه فعال ${version.version_no} کپی شد؛ ذخیره، یک نسخه پیش‌نویس جدید می‌سازد و نسخه فعال فعلی را تغییر نمی‌دهد.`);
  }

  async function saveDraft() {
    if (!recipeName.trim() || !lines.length) {
      setError("نام رسپی و حداقل یک ردیف ماده اولیه لازم است.");
      return;
    }
    setBusy(true); setError(null); setMessage(null);
    try {
      if (editingVersionId) {
        await updateRecipeDraftVersion(editingVersionId, { name: recipeName.trim(), lines });
      } else {
        await createRecipeDraftVersion(productId, {
          name: recipeName.trim(),
          ...(variantId ? { variant_id: variantId } : {}),
          lines,
        });
      }
      await refreshRecipe();
      setLines([]);
      setEditingVersionId(null);
      const revised = revisionSourceVersion !== null;
      setRevisionSourceVersion(null);
      setMessage(editingVersionId ? "تغییرات پیش‌نویس ذخیره شد." : revised ? "نسخه پیش‌نویس جدید از روی رسپی فعال ساخته شد؛ نسخه قبلی همچنان فعال است." : "نسخه پیش‌نویس رسپی ساخته شد. پس از بررسی می‌توانید آن را فعال کنید.");
    } catch (cause) {
      setError(cause instanceof Error ? cause.message : "ساخت نسخه رسپی انجام نشد.");
    } finally { setBusy(false); }
  }

  async function activate(versionId: string) {
    setBusy(true); setError(null); setMessage(null);
    try {
      await activateRecipeVersion(versionId);
      await refreshRecipe();
      setMessage("نسخه رسپی فعال شد؛ نسخه فعال قبلی به‌صورت خودکار بازنشسته می‌شود.");
    } catch (cause) {
      setError(cause instanceof Error ? cause.message : "فعال‌سازی نسخه انجام نشد.");
    } finally { setBusy(false); }
  }

  const versions = recipe?.versions ?? [];
  return <section className="product-recipe-editor">
    <div className="catalog-groups-heading"><strong>رسپی و مواد اولیه</strong><span>نسخه‌بندی‌شده</span></div>
    {error ? <p className="catalog-editor-message" role="alert">{error}</p> : null}
    {message ? <p className="catalog-editor-message" role="status">{message}</p> : null}
    <div className="recipe-scope-row">
      <SelectField label="محدوده رسپی" value={variantId} onChange={(event) => setVariantId(event.target.value)}
        options={[{ value: "", label: "رسپی عمومی محصول" }, ...activeVariants.map((variant) => ({ value: variant.id, label: variant.name }))]} />
    </div>
    {loading ? <p className="catalog-editor-hint">در حال دریافت رسپی و مواد اولیه...</p> : <>
      <div className="recipe-version-list">
        <strong>نسخه‌های ثبت‌شده</strong>
        {versions.length ? versions.map((version) => <article className="recipe-version-card" key={version.id}>
          <div className="recipe-version-heading">
            <b>نسخه {version.version_no}</b>
            <StatusBadge variant={version.status === "active" ? "success" : "neutral"}>{statusLabel[version.status] ?? version.status}</StatusBadge>
            {version.status === "draft" ? <><Button size="sm" variant="secondary" disabled={busy} onClick={() => editDraft(version)}>ویرایش پیش‌نویس</Button><Button size="sm" variant="primary" disabled={busy} onClick={() => void activate(version.id)}>فعال‌سازی نسخه</Button></> : version.status === "active" ? <Button size="sm" variant="secondary" disabled={busy} onClick={() => startActiveRecipeRevision(version)}>ویرایش رسپی</Button> : null}
          </div>
          <div className="recipe-version-lines">
            {version.lines.map((line, index) => <div key={index}><span>{line.stock_item_name}</span><b>{line.quantity} {line.unit_name}</b><small>ضایعات: {line.waste_percent}٪</small></div>)}
          </div>
        </article>) : <p className="catalog-editor-hint">برای این محدوده هنوز رسپی ثبت نشده است.</p>}
      </div>
      <div className="recipe-draft-form">
        <strong>{editingVersionId ? "ویرایش نسخه پیش‌نویس" : revisionSourceVersion !== null ? `ساخت نسخه جدید از نسخه فعال ${revisionSourceVersion}` : "ساخت نسخه جدید"}</strong>
        <p className="catalog-editor-hint">با دکمه ویرایش رسپی، مواد نسخه فعال در پیش‌نویس جدید کپی می‌شوند؛ نسخه فعال تا زمان فعال‌سازی نسخه جدید بدون تغییر می‌ماند. نسخه‌های فعال و بازنشسته مستقیماً ویرایش نمی‌شوند.</p>
        <TextField label="نام رسپی" value={recipeName} onChange={(event) => setRecipeName(event.target.value)} placeholder="مثلاً رسپی استاندارد برگر" />
        {lines.map((line, index) => {
          const selectedItem = stockItems.find((item) => item.id === line.stock_item_id);
          const compatibleUnits = units.filter((unit) => unit.dimension === selectedItem?.base_unit.dimension);
          return <div className="recipe-line-editor" key={index}>
            <SelectField label="ماده اولیه" value={line.stock_item_id} onChange={(event) => {
              const next = stockItems.find((item) => item.id === event.target.value);
              updateLine(index, { stock_item_id: event.target.value, unit_code: next?.base_unit.code ?? "" });
            }} options={stockItems.map((item) => ({ value: item.id, label: item.name }))} />
            <TextField label="مقدار" value={line.quantity} onChange={(event) => updateLine(index, { quantity: event.target.value })} />
            <SelectField label="واحد" value={line.unit_code} onChange={(event) => updateLine(index, { unit_code: event.target.value })}
              options={compatibleUnits.map((unit) => ({ value: unit.code, label: unit.name }))} />
            <TextField label="ضایعات (%)" value={line.waste_percent} onChange={(event) => updateLine(index, { waste_percent: event.target.value })} />
            <Button size="sm" variant="ghost" disabled={busy} onClick={() => setLines((current) => current.filter((_, lineIndex) => lineIndex !== index))}>حذف ردیف</Button>
          </div>;
        })}
        {!stockItems.length ? <p className="catalog-editor-hint">ابتدا در انبار حداقل یک ماده اولیه فعال تعریف کنید.</p> : null}
        <div className="recipe-form-actions">
          <Button size="sm" variant="secondary" disabled={busy || !stockItems.length} onClick={addLine}>افزودن ماده</Button>
          <Button size="sm" variant="primary" disabled={busy || !lines.length || !recipeName.trim()} onClick={() => void saveDraft()}>{busy ? "در حال ذخیره..." : editingVersionId ? "ذخیره تغییرات پیش‌نویس" : "ساخت نسخه پیش‌نویس"}</Button>
          {editingVersionId ? <Button size="sm" variant="ghost" disabled={busy} onClick={() => { setEditingVersionId(null); setLines([]); setRecipeName(recipe?.name ?? ""); }}>انصراف از ویرایش</Button> : null}
        </div>
      </div>
    </>}
    <p className="recipe-policy-note">مصرف یا رزرو مواد به سفارش متصل نشده است؛ این بخش فقط تعریف و نسخه‌بندی رسپی را مدیریت می‌کند.</p>
  </section>;
}
