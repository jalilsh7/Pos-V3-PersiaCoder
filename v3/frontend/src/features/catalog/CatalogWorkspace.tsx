import { useEffect, useMemo, useState } from "react";
import { Archive, Check, ChevronDown, CirclePlus, Search, SlidersHorizontal, Utensils, X } from "lucide-react";
import {
  ApiError,
  archiveCatalogProduct,
  createCatalogCategory,
  createCatalogProduct,
  getCatalogCategories,
  getCatalogProducts,
  getPosCatalogProductDetails,
  updateCatalogProduct,
  type CatalogCategory,
  type PosCatalogProduct,
  type PosCatalogProductDetail,
} from "../../shared/api/client";
import { Button, SelectField, StatusBadge, Surface, TextField } from "../../shared/ui";
import "./catalog.css";

const money = (value: number) => new Intl.NumberFormat("fa-IR").format(value);
const normalizeDigits = (value: string) => value
  .replace(/[۰-۹]/g, (digit) => String("۰۱۲۳۴۵۶۷۸۹".indexOf(digit)))
  .replace(/[٠-٩]/g, (digit) => String("٠١٢٣٤٥٦٧٨٩".indexOf(digit)))
  .replace(/[،,٬\\s]/g, "");
const parsePrice = (value: string) => {
  const normalized = normalizeDigits(value).replace(/[^0-9]/g, "");
  return normalized ? Number(normalized) : 0;
};
const parseTaxRate = (value: string) => {
  const normalized = normalizeDigits(value).replace(",", ".");
  const percent = normalized.trim() ? Number(normalized) : 0;
  return Number.isFinite(percent) && percent >= 0 && percent <= 100 ? Math.round(percent * 100) : null;
};
const productTypeLabels: Record<string, string> = {
  prepared: "آماده‌سازی‌شونده",
  packaged: "بسته‌بندی‌شده",
  ingredient: "ماده اولیه",
  service: "خدمت",
};

async function fetchCatalogSnapshot() {
  const [categories, products] = await Promise.all([getCatalogCategories(), getCatalogProducts()]);
  return { categories, products };
}

export function CatalogWorkspace() {
  const [products, setProducts] = useState<PosCatalogProduct[]>([]);
  const [categories, setCategories] = useState<CatalogCategory[]>([]);
  const [query, setQuery] = useState("");
  const [category, setCategory] = useState("همه");
  const [selectedId, setSelectedId] = useState<string | null>(null);
  const [selectedDetail, setSelectedDetail] = useState<PosCatalogProductDetail | null>(null);
  const [loadingCatalog, setLoadingCatalog] = useState(true);
  const [loadingDetail, setLoadingDetail] = useState(false);
  const [saving, setSaving] = useState(false);
  const [showNew, setShowNew] = useState(false);
  const [editingProductId, setEditingProductId] = useState<string | null>(null);
  const [showNewCategory, setShowNewCategory] = useState(false);
  const [newName, setNewName] = useState("");
  const [newPrice, setNewPrice] = useState("");
  const [newSku, setNewSku] = useState("");
  const [newTaxRate, setNewTaxRate] = useState("0");
  const [newCategoryId, setNewCategoryId] = useState("none");
  const [newProductType, setNewProductType] = useState("prepared");
  const [newCategoryName, setNewCategoryName] = useState("");
  const [actionError, setActionError] = useState<string | null>(null);
  const [successMessage, setSuccessMessage] = useState<string | null>(null);

  useEffect(() => {
    let active = true;
    fetchCatalogSnapshot()
      .then(async (snapshot) => {
        if (!active) return;
        setCategories(snapshot.categories);
        setProducts(snapshot.products);
        const first = snapshot.products[0] ?? null;
        setSelectedId(first?.id ?? null);
        if (first) {
          try {
            const detail = await getPosCatalogProductDetails(first.id);
            if (active) setSelectedDetail(detail);
          } catch (cause) {
            if (active) setActionError(cause instanceof Error ? cause.message : "جزئیات محصول دریافت نشد.");
          }
        }
      })
      .catch((cause: unknown) => {
        if (active) setActionError(cause instanceof Error ? cause.message : "دریافت کاتالوگ انجام نشد.");
      })
      .finally(() => {
        if (active) setLoadingCatalog(false);
      });
    return () => { active = false; };
  }, []);

  const categoriesForFilter = useMemo(
    () => ["همه", ...categories.map((item) => item.id), "بدون دسته"],
    [categories],
  );
  const visible = useMemo(() => products.filter((product) => {
    const productCategory = product.category?.id ?? "بدون دسته";
    const matchesCategory = category === "همه" || category === productCategory;
    const searchText = `${product.name} ${product.sku ?? ""}`.toLocaleLowerCase("fa-IR");
    return matchesCategory && searchText.includes(query.trim().toLocaleLowerCase("fa-IR"));
  }), [products, category, query]);
  const selected = products.find((product) => product.id === selectedId) ?? null;
  const activeCount = products.filter((product) => product.is_active).length;
  const optionGroupCount = selectedDetail?.option_groups.length ?? 0;

  async function refreshCatalog(preferredId?: string) {
    setLoadingCatalog(true);
    try {
      const snapshot = await fetchCatalogSnapshot();
      setCategories(snapshot.categories);
      setProducts(snapshot.products);
      const nextSelected = snapshot.products.find((item) => item.id === preferredId)
        ?? snapshot.products.find((item) => item.id === selectedId)
        ?? snapshot.products[0]
        ?? null;
      setSelectedId(nextSelected?.id ?? null);
      if (nextSelected) {
        setLoadingDetail(true);
        try {
          setSelectedDetail(await getPosCatalogProductDetails(nextSelected.id));
        } catch (cause) {
          setSelectedDetail(null);
          setActionError(cause instanceof Error ? cause.message : "جزئیات محصول دریافت نشد.");
        } finally {
          setLoadingDetail(false);
        }
      } else {
        setSelectedDetail(null);
      }
    } catch (cause) {
      setActionError(cause instanceof Error ? cause.message : "به‌روزرسانی کاتالوگ انجام نشد.");
    } finally {
      setLoadingCatalog(false);
    }
  }

  async function selectProduct(productId: string) {
    setSelectedId(productId);
    setSelectedDetail(null);
    setLoadingDetail(true);
    setActionError(null);
    try {
      setSelectedDetail(await getPosCatalogProductDetails(productId));
    } catch (cause) {
      setActionError(cause instanceof Error ? cause.message : "جزئیات محصول دریافت نشد.");
    } finally {
      setLoadingDetail(false);
    }
  }

  function startProductEdit(product: PosCatalogProduct) {
    setEditingProductId(product.id);
    setNewName(product.name);
    setNewPrice(String(product.base_price_amount));
    setNewSku(product.sku ?? "");
    setNewTaxRate(String(product.tax_rate_bps / 100));
    setNewCategoryId(product.category?.id ?? "none");
    setNewProductType(product.product_type);
    setActionError(null);
    setSuccessMessage(null);
    setShowNew(true);
  }

  async function addProduct() {
    const name = newName.trim();
    const price = parsePrice(newPrice);
    const taxRate = parseTaxRate(newTaxRate);
    const sku = newSku.trim() || null;
    if (!name || !Number.isSafeInteger(price) || price < 0 || taxRate === null) {
      setActionError("نام، قیمت معتبر و نرخ مالیات بین صفر تا صد را وارد کنید.");
      return;
    }
    setSaving(true);
    setActionError(null);
    setSuccessMessage(null);
    try {
      const payload = {
        name,
        base_price_amount: price,
        category_id: newCategoryId === "none" ? null : newCategoryId,
        sku,
        product_type: newProductType as "prepared" | "packaged" | "ingredient" | "service",
        tax_rate_bps: taxRate,
      };
      const savedId = editingProductId;
      if (savedId) {
        await updateCatalogProduct(savedId, payload);
      } else {
        const created = await createCatalogProduct(payload);
        await refreshCatalog(created.id);
      }
      if (savedId) await refreshCatalog(savedId);
      setNewName("");
      setNewPrice("");
      setNewSku("");
      setNewTaxRate("0");
      setNewCategoryId("none");
      setNewProductType("prepared");
      setEditingProductId(null);
      setShowNew(false);
      setSuccessMessage(savedId ? "اطلاعات محصول در سرور به‌روزرسانی شد." : "محصول در کاتالوگ سرور ثبت شد و در صندوق قابل انتخاب است.");
    } catch (cause) {
      setActionError(cause instanceof ApiError ? cause.message : cause instanceof Error ? cause.message : "ثبت محصول انجام نشد.");
    } finally {
      setSaving(false);
    }
  }

  async function addCategory() {
    const name = newCategoryName.trim();
    if (!name) return;
    setSaving(true);
    setActionError(null);
    try {
      const created = await createCatalogCategory(name);
      await refreshCatalog(selectedId ?? undefined);
      setNewCategoryId(created.id);
      setCategory(created.id);
      setNewCategoryName("");
      setShowNewCategory(false);
      setSuccessMessage("دسته‌بندی در سرور ساخته شد.");
    } catch (cause) {
      setActionError(cause instanceof ApiError ? cause.message : cause instanceof Error ? cause.message : "ساخت دسته‌بندی انجام نشد.");
    } finally {
      setSaving(false);
    }
  }

  async function toggleAvailability(product: PosCatalogProduct) {
    setSaving(true);
    setActionError(null);
    setSuccessMessage(null);
    try {
      await updateCatalogProduct(product.id, { is_active: !product.is_active });
      await refreshCatalog(product.id);
      setSuccessMessage(product.is_active ? "محصول غیرفعال شد و از صندوق حذف می‌شود." : "محصول دوباره برای فروش فعال شد.");
    } catch (cause) {
      setActionError(cause instanceof ApiError ? cause.message : cause instanceof Error ? cause.message : "تغییر وضعیت محصول انجام نشد.");
    } finally {
      setSaving(false);
    }
  }

  async function archiveProduct(product: PosCatalogProduct) {
    if (!globalThis.confirm(`محصول «${product.name}» بایگانی شود؟ این کار آن را از فهرست فروش خارج می‌کند.`)) return;
    setSaving(true);
    setActionError(null);
    setSuccessMessage(null);
    try {
      await archiveCatalogProduct(product.id);
      await refreshCatalog();
      setSuccessMessage("محصول بایگانی شد.");
    } catch (cause) {
      setActionError(cause instanceof ApiError ? cause.message : cause instanceof Error ? cause.message : "بایگانی محصول انجام نشد.");
    } finally {
      setSaving(false);
    }
  }

  return (
    <div className="page-stack catalog-workspace">
      <div className="catalog-heading">
        <div><span className="ui-eyebrow">PRODUCT CATALOG</span><h1>منو و محصولات</h1><p>مدیریت محصولات واقعی، دسته‌ها، قیمت پایه و گروه‌های گزینه</p></div>
        <div className="catalog-heading-actions">
          <Button variant="secondary" onClick={() => setShowNewCategory((value) => !value)}><CirclePlus size={15} /> دسته جدید</Button>
          <Button variant="primary" onClick={() => setShowNew((value) => !value)}><CirclePlus size={15} /> محصول جدید</Button>
        </div>
      </div>
      {actionError ? <div className="auth-error" role="alert">{actionError}</div> : null}
      {successMessage ? <div className="auth-success" role="status">{successMessage}</div> : null}
      <div className="catalog-metrics">
        <Surface padding="sm"><span>کل محصولات</span><strong>{money(products.length)}</strong></Surface>
        <Surface padding="sm"><span>فعال برای فروش</span><strong>{money(activeCount)}</strong></Surface>
        <Surface padding="sm"><span>دسته‌بندی فعال</span><strong>{money(categories.length)}</strong></Surface>
      </div>

      {showNewCategory ? (
        <Surface padding="md" className="catalog-create">
          <div className="catalog-create-heading"><strong>دسته‌بندی جدید</strong><button type="button" onClick={() => setShowNewCategory(false)} aria-label="بستن"><X size={16} /></button></div>
          <div className="catalog-create-fields"><TextField label="نام دسته‌بندی" value={newCategoryName} onChange={(event) => setNewCategoryName(event.target.value)} placeholder="مثلاً نوشیدنی گرم" /></div>
          <div className="catalog-create-footer"><span>دسته‌بندی به همین کسب‌وکار اضافه می‌شود.</span><Button variant="primary" onClick={() => { void addCategory(); }} disabled={!newCategoryName.trim() || saving}>ثبت دسته‌بندی</Button></div>
        </Surface>
      ) : null}

      {showNew ? (
        <Surface padding="md" className="catalog-create">
          <div className="catalog-create-heading"><strong>{editingProductId ? "ویرایش محصول" : "افزودن محصول به کاتالوگ"}</strong><button type="button" onClick={() => { setShowNew(false); setEditingProductId(null); }} aria-label="بستن"><X size={16} /></button></div>
          <div className="catalog-create-fields">
            <TextField label="نام محصول" value={newName} onChange={(event) => setNewName(event.target.value)} placeholder="مثلاً سالاد سزار" />
            <TextField label="قیمت پایه به تومان" value={newPrice} onChange={(event) => setNewPrice(event.target.value)} placeholder="مثلاً ۱۸۵۰۰۰" />
            <TextField label="کد کالا (اختیاری)" value={newSku} onChange={(event) => setNewSku(event.target.value)} placeholder="مثلاً FD-001" />
            <SelectField label="دسته‌بندی" value={newCategoryId} onChange={(event) => setNewCategoryId(event.target.value)}
              options={[{ value: "none", label: "بدون دسته‌بندی" }, ...categories.map((item) => ({ value: item.id, label: item.name }))]} />
            <SelectField label="نوع محصول" value={newProductType} onChange={(event) => setNewProductType(event.target.value)}
              options={Object.entries(productTypeLabels).map(([value, label]) => ({ value, label }))} />
            <TextField label="نرخ مالیات (درصد)" value={newTaxRate} onChange={(event) => setNewTaxRate(event.target.value)} placeholder="مثلاً ۱۰" />
          </div>
          <div className="catalog-create-footer"><span>قیمت و مالیات در زمان ثبت سفارش توسط سرور محاسبه می‌شوند.</span><Button variant="primary" onClick={() => { void addProduct(); }} disabled={!newName.trim() || !newPrice.trim() || saving}>{saving ? "در حال ذخیره..." : editingProductId ? "ذخیره تغییرات" : "ثبت محصول"}</Button></div>
        </Surface>
      ) : null}

      <div className="catalog-layout">
        <Surface padding="md" className="catalog-list">
          <div className="catalog-list-heading"><div><h2><Utensils size={17} /> فهرست محصولات</h2><p>{money(visible.length)} محصول</p></div><SlidersHorizontal size={17} /></div>
          <label className="catalog-search"><Search size={16} /><input value={query} onChange={(event) => setQuery(event.target.value)} placeholder="نام محصول یا کد کالا" /><button type="button" onClick={() => setQuery("")} aria-label="پاک کردن جست‌وجو" disabled={!query}><X size={14} /></button></label>
          <div className="catalog-categories">
            {categoriesForFilter.map((item) => {
              const label = item === "همه" ? "همه" : item === "بدون دسته" ? "بدون دسته" : categories.find((candidate) => candidate.id === item)?.name ?? "";
              if (!label) return null;
              return <button type="button" key={item} className={category === item ? "is-active" : ""} onClick={() => setCategory(item)}>{label}</button>;
            })}
          </div>
          {loadingCatalog ? <div className="catalog-empty">در حال دریافت کاتالوگ...</div> : (
            <div className="catalog-product-list">
              {visible.map((product) => (
                <button type="button" className={selectedId === product.id ? "catalog-product is-selected" : "catalog-product"} key={product.id}
                  onClick={() => { void selectProduct(product.id); }}>
                  <span className="catalog-product-icon"><Utensils size={16} /></span>
                  <span className="catalog-product-copy"><strong>{product.name}</strong><small>{product.sku || "بدون کد"} · {product.category?.name || "بدون دسته"}</small></span>
                  <span className="catalog-product-price">{money(product.base_price_amount)} <small>تومان</small></span>
                  <StatusBadge variant={product.is_active ? "success" : "neutral"}>{product.is_active ? "فعال" : "غیرفعال"}</StatusBadge>
                </button>
              ))}
              {!visible.length ? <div className="catalog-empty"><Archive size={22} /><strong>{products.length ? "محصولی پیدا نشد" : "هنوز محصولی ثبت نشده است"}</strong><span>{products.length ? "فیلتر یا عبارت جست‌وجو را تغییر دهید." : "برای شروع، یک محصول جدید در همین صفحه بسازید."}</span></div> : null}
            </div>
          )}
        </Surface>

        <Surface padding="md" className="catalog-detail">
          {selected ? <>
            <div className="catalog-detail-heading"><div><span className="ui-eyebrow">PRODUCT DETAILS</span><h2>{selected.name}</h2><p>{selected.sku || "بدون کد"} · {selected.category?.name || "بدون دسته"}</p></div><StatusBadge variant={selected.is_active ? "success" : "neutral"}>{selected.is_active ? "فعال برای فروش" : "غیرفعال"}</StatusBadge></div>
            <div className="catalog-base-price"><span>قیمت پایه</span><strong>{money(selected.base_price_amount)} <small>تومان</small></strong></div>
            <div className="catalog-detail-meta">
              <div><span>نوع محصول</span><strong>{productTypeLabels[selected.product_type] || selected.product_type}</strong></div>
              <div><span>نرخ مالیات</span><strong>{money(selected.tax_rate_bps / 100)}٪</strong></div>
              <div><span>گروه گزینه</span><strong>{loadingDetail ? "..." : money(optionGroupCount)}</strong></div>
            </div>
            <div className="catalog-groups-heading"><strong>گروه‌های گزینه و افزودنی</strong><span>{selectedDetail?.option_groups.length ? "تنظیم‌شده در کاتالوگ" : "بدون گزینه"}</span></div>
            {loadingDetail ? <div className="catalog-no-options">در حال دریافت جزئیات...</div> : selectedDetail?.option_groups.length ? selectedDetail.option_groups.map((group) => (
              <section className="catalog-option-group" key={group.id}>
                <div><strong>{group.name}</strong><StatusBadge variant={group.is_required || group.is_required_default ? "warning" : "neutral"}>{group.is_required || group.is_required_default ? "اجباری" : "اختیاری"}</StatusBadge><small>{group.selection_type === "multiple" ? "چندانتخابی" : "تک‌انتخابی"}</small></div>
                {group.options.filter((option) => option.is_active).map((option) => <div className="catalog-option" key={option.id}><span>{option.name}</span><b>{option.price_delta_amount ? `${option.price_delta_amount > 0 ? "+" : ""}${money(option.price_delta_amount)} تومان` : "بدون هزینه"}</b></div>)}
              </section>
            )) : <div className="catalog-no-options">برای این محصول گروه گزینه‌ای تعریف نشده است.</div>}
            {selectedDetail?.variants.length ? (
              <div className="catalog-option-group"><div><strong>مدل‌ها / تنوع‌ها</strong><small>{money(selectedDetail.variants.filter((item) => item.is_active).length)} مورد فعال</small></div>
                {selectedDetail.variants.filter((item) => item.is_active).map((variant) => <div className="catalog-option" key={variant.id}><span>{variant.name}</span><b>{variant.price_delta_amount ? `${variant.price_delta_amount > 0 ? "+" : ""}${money(variant.price_delta_amount)} تومان` : "بدون تغییر قیمت"}</b></div>)}
              </div>
            ) : null}
            <div className="catalog-detail-actions">
              <Button variant="secondary" disabled={saving} onClick={() => startProductEdit(selected)}>ویرایش محصول</Button>
              <Button variant={selected.is_active ? "secondary" : "primary"} disabled={saving} onClick={() => { void toggleAvailability(selected); }}>
                {selected.is_active ? <Archive size={14} /> : <Check size={14} />}{selected.is_active ? "غیرفعال‌سازی" : "فعال‌سازی"}
              </Button>
              <Button variant="ghost" disabled={saving} onClick={() => { void archiveProduct(selected); }}>بایگانی محصول</Button>
              <span>تغییرات در دیتابیس ذخیره می‌شوند.</span>
            </div>
          </> : <div className="catalog-empty"><ChevronDown size={22} /><strong>{loadingCatalog ? "در حال دریافت اطلاعات..." : "محصولی انتخاب نشده است"}</strong><span>از فهرست یک محصول را انتخاب کنید.</span></div>}
        </Surface>
      </div>
    </div>
  );
}
