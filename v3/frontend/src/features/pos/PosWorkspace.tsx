import { useEffect, useMemo, useRef, useState } from "react";
import {
  Coffee,
  Minus,
  Plus,
  Search,
  ShoppingBag,
  Trash2,
  X,
} from "lucide-react";
import {
  ApiError,
  captureCashPayment,
  createPosOrder,
  getCashRegisters,
  getPosCatalogProductDetails,
  getPosCatalogProducts,
  type CashRegisterSummary,
  type PosCatalogProduct,
  type PosCatalogProductDetail,
} from "../../shared/api/client";
import {
  Button,
  Notice,
  SelectField,
  StatusBadge,
  Surface,
  TextField,
} from "../../shared/ui";

type ProductOption = { id: string; name: string; price: number };
type OptionGroup = {
  id: string;
  title: string;
  required: boolean;
  multiple: boolean;
  minSelect: number;
  maxSelect: number;
  options: ProductOption[];
};
type Product = PosCatalogProduct & {
  description: string;
  symbol: string;
  optionGroups: OptionGroup[];
  variants: { id: string; name: string; price: number }[];
};
type CartLine = {
  product: Product;
  quantity: number;
  selectedOptions: ProductOption[];
  variantId: string | null;
  variantName: string | null;
  variantPrice: number;
  configurationKey: string;
};

const normalizeDigits = (value: string) => value
  .replace(/[۰-۹]/g, (digit) => String("۰۱۲۳۴۵۶۷۸۹".indexOf(digit)))
  .replace(/[٠-٩]/g, (digit) => String("٠١٢٣٤٥٦٧٨٩".indexOf(digit)));

const money = (value: number) => new Intl.NumberFormat("fa-IR").format(value);

const orderTypes = [
  { value: "dine-in", label: "سرو داخل" },
  { value: "takeaway", label: "بیرون‌بر" },
  { value: "delivery", label: "ارسال" },
];

function productSymbol(categoryName: string | null): string {
  const category = (categoryName || "").toLocaleLowerCase("fa-IR");
  if (category.includes("قهوه") || category.includes("نوشیدنی")) return "☕";
  if (category.includes("دسر") || category.includes("بستنی")) return "🍰";
  if (category.includes("پیتزا")) return "🍕";
  if (category.includes("برگر")) return "🍔";
  return "🍽️";
}

function mapProductSummary(product: PosCatalogProduct): Product {
  return {
    ...product,
    description: product.sku ? `کد کالا: ${product.sku}` : product.category?.name || "محصول منو",
    symbol: productSymbol(product.category?.name ?? null),
    optionGroups: [],
    variants: [],
  };
}

function mapProductDetail(detail: PosCatalogProductDetail, category: PosCatalogProduct["category"]): Product {
  return {
    ...detail,
    category,
    description: detail.sku ? `کد کالا: ${detail.sku}` : category?.name || "محصول منو",
    symbol: productSymbol(category?.name ?? null),
    optionGroups: detail.option_groups.map((group) => ({
      id: group.id,
      title: group.name,
      required: group.is_required || group.is_required_default,
      multiple: group.selection_type === "multiple",
      minSelect: group.is_required || group.is_required_default ? Math.max(1, group.min_select) : group.min_select,
      maxSelect: group.selection_type === "single" ? Math.min(1, group.max_select) : group.max_select,
      options: group.options.filter((option) => option.is_active).map((option) => ({
        id: option.id,
        name: option.name,
        price: option.price_delta_amount,
      })),
    })),
    variants: detail.variants.filter((variant) => variant.is_active).map((variant) => ({
      id: variant.id,
      name: variant.name,
      price: variant.price_delta_amount,
    })),
  };
}

export function PosWorkspace() {
  const [products, setProducts] = useState<Product[]>([]);
  const [loadingCatalog, setLoadingCatalog] = useState(true);
  const [catalogError, setCatalogError] = useState<string | null>(null);
  const [category, setCategory] = useState("همه");
  const [query, setQuery] = useState("");
  const [cart, setCart] = useState<CartLine[]>([]);
  const [orderType, setOrderType] = useState("dine-in");
  const [recipientName, setRecipientName] = useState("");
  const [recipientPhone, setRecipientPhone] = useState("");
  const [deliveryAddress, setDeliveryAddress] = useState("");
  const [editingProduct, setEditingProduct] = useState<Product | null>(null);
  const [loadingProductId, setLoadingProductId] = useState<string | null>(null);
  const [selectedOptions, setSelectedOptions] = useState<ProductOption[]>([]);
  const [selectedVariantId, setSelectedVariantId] = useState("");
  const [submitting, setSubmitting] = useState(false);
  const [actionError, setActionError] = useState<string | null>(null);
  const [createdOrder, setCreatedOrder] = useState<{
    number: number; total: number; id: string; paymentStatus: string; capturedAmount: number; dueAmount: number;
  } | null>(null);
  const [cashRegisters, setCashRegisters] = useState<CashRegisterSummary[]>([]);
  const [cashSessionId, setCashSessionId] = useState("");
  const [paymentAmount, setPaymentAmount] = useState("");
  const [paymentSubmitting, setPaymentSubmitting] = useState(false);
  const [paymentError, setPaymentError] = useState<string | null>(null);
  const idempotencyAttempt = useRef<{ signature: string; key: string } | null>(null);
  const cashPaymentAttempt = useRef<{ signature: string; key: string } | null>(null);

  useEffect(() => {
    let active = true;
    setLoadingCatalog(true);
    getPosCatalogProducts()
      .then((items) => {
        if (!active) return;
        setProducts(items.map(mapProductSummary));
        setCatalogError(null);
      })
      .catch((cause: unknown) => {
        if (!active) return;
        setCatalogError(cause instanceof Error ? cause.message : "دریافت منوی محصولات انجام نشد.");
      })
      .finally(() => {
        if (active) setLoadingCatalog(false);
      });
    return () => { active = false; };
  }, []);

  useEffect(() => {
    if (!createdOrder) {
      setCashRegisters([]);
      setCashSessionId("");
      return;
    }
    let active = true;
    getCashRegisters()
      .then((registers) => {
        if (!active) return;
        setCashRegisters(registers);
        const openSessions = registers.filter((register) => register.is_active && register.active_session?.status === "open");
        setCashSessionId((current) => openSessions.some((register) => register.active_session?.id === current)
          ? current
          : openSessions[0]?.active_session?.id ?? "");
        setPaymentError(null);
      })
      .catch((cause: unknown) => {
        if (!active) return;
        setPaymentError(cause instanceof Error ? cause.message : "دریافت شیفت‌های صندوق انجام نشد.");
      });
    return () => { active = false; };
  }, [createdOrder?.id]);

  const categories = useMemo(
    () => ["همه", ...Array.from(new Set(products.map((product) => product.category?.name || "بدون دسته")))],
    [products],
  );
  const visibleProducts = useMemo(() => {
    const normalized = query.trim().toLocaleLowerCase("fa-IR");
    return products.filter((product) => {
      const productCategory = product.category?.name || "بدون دسته";
      const inCategory = category === "همه" || productCategory === category;
      const matchesQuery = !normalized ||
        `${product.name} ${product.description}`.toLocaleLowerCase("fa-IR").includes(normalized);
      return inCategory && matchesQuery;
    });
  }, [category, products, query]);

  const lineUnitPrice = (line: CartLine) =>
    line.product.base_price_amount + line.variantPrice +
    line.selectedOptions.reduce((sum, option) => sum + option.price, 0);
  const subtotal = cart.reduce((sum, line) => sum + lineUnitPrice(line) * line.quantity, 0);
  const itemCount = cart.reduce((sum, line) => sum + line.quantity, 0);

  function addConfiguredProduct(product: Product, options: ProductOption[], variantId: string | null) {
    const variant = product.variants.find((item) => item.id === variantId) ?? null;
    const configurationKey = JSON.stringify([
      variantId,
      options.map((option) => option.id).sort(),
    ]);
    setCart((current) => {
      const existing = current.find((line) => line.product.id === product.id && line.configurationKey === configurationKey);
      if (existing) {
        return current.map((line) => line.product.id === product.id && line.configurationKey === configurationKey
          ? { ...line, quantity: line.quantity + 1 }
          : line);
      }
      return [...current, {
        product,
        quantity: 1,
        selectedOptions: options,
        variantId,
        variantName: variant?.name ?? null,
        variantPrice: variant?.price ?? 0,
        configurationKey,
      }];
    });
    setEditingProduct(null);
    setSelectedOptions([]);
    setSelectedVariantId("");
    setCreatedOrder(null);
    setActionError(null);
  }

  async function openProductEditor(summary: Product) {
    setLoadingProductId(summary.id);
    setActionError(null);
    try {
      const detail = mapProductDetail(await getPosCatalogProductDetails(summary.id), summary.category);
      if (!detail.is_active) return;
      if (!detail.optionGroups.length && !detail.variants.length) {
        addConfiguredProduct(detail, [], null);
      } else {
        setEditingProduct(detail);
        setSelectedOptions([]);
        setSelectedVariantId(detail.variants.length === 1 ? detail.variants[0].id : "");
      }
    } catch (cause) {
      setActionError(cause instanceof Error ? cause.message : "جزئیات محصول دریافت نشد.");
    } finally {
      setLoadingProductId(null);
    }
  }

  function toggleOption(group: OptionGroup, option: ProductOption) {
    setSelectedOptions((current) => {
      const exists = current.some((item) => item.id === option.id);
      if (group.multiple) {
        if (exists) return current.filter((item) => item.id !== option.id);
        const selectedInGroup = current.filter((item) => group.options.some((candidate) => candidate.id === item.id)).length;
        if (selectedInGroup >= group.maxSelect) return current;
        return [...current, option];
      }
      return [...current.filter((item) => !group.options.some((candidate) => candidate.id === item.id)), option];
    });
  }

  function confirmProductOptions() {
    if (!editingProduct) return;
    const invalidGroup = editingProduct.optionGroups.some((group) => {
      const count = selectedOptions.filter((option) => group.options.some((candidate) => candidate.id === option.id)).length;
      return count < group.minSelect || count > group.maxSelect;
    });
    if (invalidGroup || (editingProduct.variants.length > 0 && !selectedVariantId)) return;
    addConfiguredProduct(editingProduct, selectedOptions, selectedVariantId || null);
  }

  function changeQuantity(configurationKey: string, delta: number) {
    setCart((current) => current
      .map((line) => line.configurationKey === configurationKey
        ? { ...line, quantity: line.quantity + delta }
        : line)
      .filter((line) => line.quantity > 0));
    setCreatedOrder(null);
  }

  function clearCart() {
    setCart([]);
    setCreatedOrder(null);
    setActionError(null);
    idempotencyAttempt.current = null;
  }

  async function submitOrder() {
    if (!cart.length || submitting) return;
    if (orderType === "delivery") {
      if (!recipientName.trim() || !/^[0-9+(). -]{7,32}$/.test(normalizeDigits(recipientPhone).trim()) || !deliveryAddress.trim()) {
        setActionError("برای سفارش ارسالی، نام گیرنده، شماره تماس معتبر و نشانی را وارد کنید.");
        return;
      }
    }
    const payload = {
      source_channel: "pos" as const,
      fulfillment_method: orderType === "dine-in" ? "dine_in" as const : orderType as "takeaway" | "delivery",
      ...(orderType === "delivery" ? {
        recipient_name: recipientName.trim(),
        recipient_phone: normalizeDigits(recipientPhone).trim(),
        delivery_address: { street: deliveryAddress.trim() },
      } : {}),
      items: cart.map((line) => ({
        product_id: line.product.id,
        variant_id: line.variantId,
        quantity: line.quantity,
        options: line.selectedOptions.map((option) => option.id),
      })),
    };
    const signature = JSON.stringify(payload);
    if (!idempotencyAttempt.current || idempotencyAttempt.current.signature !== signature) {
      idempotencyAttempt.current = { signature, key: globalThis.crypto.randomUUID() };
    }
    setSubmitting(true);
    setActionError(null);
    try {
      const order = await createPosOrder(payload, idempotencyAttempt.current.key);
      setCreatedOrder({
        number: order.order_number,
        total: order.total_amount,
        id: order.id,
        paymentStatus: order.payment_status,
        capturedAmount: 0,
        dueAmount: order.total_amount,
      });
      setPaymentAmount(String(order.total_amount));
      setPaymentError(null);
      cashPaymentAttempt.current = null;
      setCart([]);
      setEditingProduct(null);
      setRecipientName("");
      setRecipientPhone("");
      setDeliveryAddress("");
      idempotencyAttempt.current = null;
    } catch (cause) {
      const message = cause instanceof ApiError
        ? cause.message
        : cause instanceof Error ? cause.message : "ثبت سفارش انجام نشد.";
      setActionError(message);
    } finally {
      setSubmitting(false);
    }
  }

  async function submitCashPayment() {
    if (!createdOrder || paymentSubmitting) return;
    const normalizedAmount = normalizeDigits(paymentAmount).replace(/[,٬\\s]/g, "");
    const amount = Number(normalizedAmount);
    if (!Number.isSafeInteger(amount) || amount < 1) {
      setPaymentError("مبلغ دریافتی باید عدد صحیح و بیشتر از صفر باشد.");
      return;
    }
    if (!cashSessionId) {
      setPaymentError("برای ثبت دریافت، یک شیفت صندوق باز انتخاب کنید.");
      return;
    }
    const signature = JSON.stringify([createdOrder.id, cashSessionId, amount]);
    if (!cashPaymentAttempt.current || cashPaymentAttempt.current.signature !== signature) {
      cashPaymentAttempt.current = { signature, key: globalThis.crypto.randomUUID() };
    }
    setPaymentSubmitting(true);
    setPaymentError(null);
    try {
      const result = await captureCashPayment(
        createdOrder.id,
        cashSessionId,
        amount,
        cashPaymentAttempt.current.key,
      );
      setCreatedOrder((current) => current ? {
        ...current,
        paymentStatus: result.order.payment_status,
        capturedAmount: result.order.captured_amount,
        dueAmount: result.order.due_amount,
      } : current);
      setPaymentAmount(String(result.order.due_amount));
      cashPaymentAttempt.current = null;
    } catch (cause) {
      setPaymentError(cause instanceof ApiError
        ? cause.message
        : cause instanceof Error ? cause.message : "ثبت دریافت وجه انجام نشد.");
    } finally {
      setPaymentSubmitting(false);
    }
  }

  const openCashSessions = cashRegisters.filter(
    (register) => register.is_active && register.active_session?.status === "open",
  );

  return (
    <div className="page-stack pos-workspace">
      <div className="pos-page-heading">
        <div>
          <span className="ui-eyebrow">POINT OF SALE</span>
          <h1>صندوق فروش</h1>
          <p>ثبت سفارش حضوری بر پایه منوی واقعی کسب‌وکار</p>
        </div>
        <div className="pos-heading-meta">
          <StatusBadge variant="success">متصل به Backend</StatusBadge>
          <span>{money(products.length)} محصول فعال</span>
        </div>
      </div>

      <Notice variant="warning">
        ثبت سفارش و محاسبه قیمت در سرور انجام می‌شود. پرداخت، تخفیف و ثبت مشتری در این مرحله فعال نیستند؛
        سفارش تازه با وضعیت «پیش‌نویس» و پرداخت‌نشده ساخته می‌شود.
      </Notice>
      {catalogError ? <div className="auth-error" role="alert">{catalogError}</div> : null}
      {actionError ? <div className="auth-error" role="alert">{actionError}</div> : null}
      {createdOrder ? (
        <div className="auth-success" role="status" aria-live="polite">
          سفارش شماره {money(createdOrder.number)} ثبت شد.
          مبلغ کل: {money(createdOrder.total)} تومان · دریافت‌شده: {money(createdOrder.capturedAmount)} تومان · مانده: {money(createdOrder.dueAmount)} تومان
          · وضعیت پرداخت: {createdOrder.paymentStatus === "paid" ? "تسویه‌شده" : createdOrder.paymentStatus === "partially_paid" ? "پرداخت جزئی" : "پرداخت‌نشده"}
        </div>
      ) : null}
      {createdOrder && createdOrder.dueAmount > 0 ? (
        <Surface padding="md" className="pos-payment-panel">
          <div className="pos-payment-heading">
            <div><h2>دریافت وجه سفارش</h2><p>ثبت نقدی در دفتر پرداخت و شیفت صندوق فعال</p></div>
            <StatusBadge variant={createdOrder.capturedAmount > 0 ? "warning" : "neutral"}>
              مانده {money(createdOrder.dueAmount)} تومان
            </StatusBadge>
          </div>
          {paymentError ? <div className="auth-error" role="alert">{paymentError}</div> : null}
          {openCashSessions.length ? (
            <>
              <label className="pos-payment-field">
                <span>شیفت صندوق</span>
                <select value={cashSessionId} onChange={(event) => setCashSessionId(event.target.value)}>
                  {openCashSessions.map((register) => (
                    <option key={register.active_session!.id} value={register.active_session!.id}>
                      {register.name} · {register.code}
                    </option>
                  ))}
                </select>
              </label>
              <label className="pos-payment-field">
                <span>مبلغ دریافتی (تومان)</span>
                <input
                  type="number"
                  min="1"
                  max={createdOrder.dueAmount}
                  step="1"
                  inputMode="numeric"
                  value={paymentAmount}
                  onChange={(event) => setPaymentAmount(event.target.value)}
                />
              </label>
              <Button onClick={() => { void submitCashPayment(); }} disabled={paymentSubmitting || !cashSessionId}>
                {paymentSubmitting ? "در حال ثبت دریافت..." : "ثبت دریافت نقدی"}
              </Button>
            </>
          ) : (
            <Notice variant="warning">
              شیفت صندوق باز پیدا نشد. ابتدا از بخش مدیریت صندوق یک صندوق فعال و شیفت باز ایجاد کنید؛ سفارش ثبت‌شده محفوظ است و پرداخت هنوز انجام نشده.
            </Notice>
          )}
        </Surface>
      ) : null}

      <div className="pos-layout">
        <Surface padding="md" className="pos-catalog">
          <div className="pos-catalog-heading">
            <div><h2>انتخاب محصول</h2><p>محصولات فعال کاتالوگ این کسب‌وکار</p></div>
            <span className="pos-count">{money(visibleProducts.length)} محصول</span>
          </div>

          <label className="pos-search">
            <Search size={17} aria-hidden="true" />
            <input value={query} onChange={(event) => setQuery(event.target.value)} placeholder="جست‌وجوی نام محصول..." aria-label="جست‌وجوی محصول" />
            {query ? <button type="button" onClick={() => setQuery("")} aria-label="پاک کردن جست‌وجو"><X size={15} /></button> : null}
          </label>

          <div className="pos-categories" role="group" aria-label="دسته‌بندی محصولات">
            {categories.map((item) => (
              <button type="button" key={item} className={category === item ? "pos-category is-active" : "pos-category"}
                aria-pressed={category === item} onClick={() => setCategory(item)}>{item}</button>
            ))}
          </div>

          {loadingCatalog ? <div className="pos-no-products" role="status">در حال دریافت منوی محصولات...</div> : visibleProducts.length ? (
            <div className="pos-product-grid">
              {visibleProducts.map((product) => (
                <button type="button" className="pos-product-card" key={product.id}
                  onClick={() => { void openProductEditor(product); }}
                  disabled={loadingProductId !== null}
                  aria-label={`افزودن ${product.name} به سبد خرید`}>
                  <span className="pos-product-symbol" aria-hidden="true">{product.symbol}</span>
                  <span className="pos-product-name">{product.name}</span>
                  <span className="pos-product-description">{product.description}</span>
                  <span className="pos-product-footer">
                    <strong>{money(product.base_price_amount)} <small>تومان</small></strong>
                    <span className="pos-add-icon">{loadingProductId === product.id ? "…" : <Plus size={15} />}</span>
                  </span>
                </button>
              ))}
            </div>
          ) : (
            <div className="pos-no-products">
              <Search size={22} /><strong>{products.length ? "محصولی پیدا نشد" : "هنوز محصول فعالی ثبت نشده است"}</strong>
              <span>{products.length ? "عبارت جست‌وجو یا دسته‌بندی را تغییر دهید." : "ابتدا محصولات را در بخش منو و محصولات ثبت کنید."}</span>
            </div>
          )}

          {editingProduct ? (
            <div className="pos-option-editor" role="dialog" aria-label={`شخصی‌سازی ${editingProduct.name}`}>
              <div className="pos-option-editor-heading">
                <div><strong>شخصی‌سازی {editingProduct.name}</strong><span>مدل و افزودنی‌های موردنظر را انتخاب کنید</span></div>
                <button type="button" onClick={() => setEditingProduct(null)} aria-label="بستن"><X size={17} /></button>
              </div>
              {editingProduct.variants.length ? (
                <fieldset className="pos-option-group">
                  <legend>مدل محصول <span>الزامی</span></legend>
                  <div className="pos-option-list">
                    {editingProduct.variants.map((variant) => (
                      <label className={selectedVariantId === variant.id ? "pos-option is-selected" : "pos-option"} key={variant.id}>
                        <input type="radio" name="pos-product-variant" checked={selectedVariantId === variant.id} onChange={() => setSelectedVariantId(variant.id)} />
                        <span>{variant.name}</span><strong>{variant.price ? `${variant.price > 0 ? "+" : ""}${money(variant.price)} تومان` : "بدون تغییر قیمت"}</strong>
                      </label>
                    ))}
                  </div>
                </fieldset>
              ) : null}
              {editingProduct.optionGroups.map((group) => (
                <fieldset className="pos-option-group" key={group.id}>
                  <legend>{group.title} {group.required ? <span>الزامی</span> : <small>اختیاری</small>}</legend>
                  <div className="pos-option-list">
                    {group.options.map((option) => {
                      const checked = selectedOptions.some((item) => item.id === option.id);
                      const selectedCount = selectedOptions.filter((item) => group.options.some((candidate) => candidate.id === item.id)).length;
                      return (
                        <label className={checked ? "pos-option is-selected" : "pos-option"} key={option.id}>
                          <input type={group.multiple ? "checkbox" : "radio"} name={`option-${group.id}`} checked={checked}
                            disabled={!checked && group.multiple && selectedCount >= group.maxSelect}
                            onChange={() => toggleOption(group, option)} />
                          <span>{option.name}</span><strong>{option.price ? `${option.price > 0 ? "+" : ""}${money(option.price)} تومان` : "بدون هزینه"}</strong>
                        </label>
                      );
                    })}
                  </div>
                </fieldset>
              ))}
              <div className="pos-option-editor-footer">
                <span>قیمت پایه + مدل + افزودنی‌ها</span>
                <strong>{money(editingProduct.base_price_amount +
                  (editingProduct.variants.find((item) => item.id === selectedVariantId)?.price ?? 0) +
                  selectedOptions.reduce((sum, option) => sum + option.price, 0))} تومان</strong>
              </div>
              <Button variant="primary" onClick={confirmProductOptions}
                disabled={(editingProduct.variants.length > 0 && !selectedVariantId) || editingProduct.optionGroups.some((group) => {
                  const count = selectedOptions.filter((option) => group.options.some((candidate) => candidate.id === option.id)).length;
                  return count < group.minSelect || count > group.maxSelect;
                })}>افزودن به سبد</Button>
            </div>
          ) : null}
        </Surface>

        <Surface padding="md" className="pos-cart">
          <div className="pos-cart-heading">
            <div><h2><ShoppingBag size={18} /> سبد سفارش</h2><p>{money(itemCount)} قلم</p></div>
            {cart.length ? <Button variant="ghost" size="sm" onClick={clearCart}><Trash2 size={14} /> پاک‌کردن</Button> : null}
          </div>

          {cart.length ? (
            <div className="pos-cart-lines">
              {cart.map((line) => (
                <div className="pos-cart-line" key={line.configurationKey + ":" + line.product.id}>
                  <span className="pos-cart-emoji" aria-hidden="true">{line.product.symbol}</span>
                  <div className="pos-cart-line-copy">
                    <strong>{line.product.name}</strong>
                    {line.variantName ? <small>{line.variantName}</small> : null}
                    <small>{money(lineUnitPrice(line))} تومان</small>
                    {line.selectedOptions.length ? <small>{line.selectedOptions.map((option) => option.name).join("، ")}</small> : null}
                  </div>
                  <div className="pos-quantity">
                    <button type="button" onClick={() => changeQuantity(line.configurationKey, 1)} aria-label={`افزایش ${line.product.name}`}><Plus size={13} /></button>
                    <span>{money(line.quantity)}</span>
                    <button type="button" onClick={() => changeQuantity(line.configurationKey, -1)} aria-label={`کاهش ${line.product.name}`}>
                      {line.quantity === 1 ? <Trash2 size={12} /> : <Minus size={13} />}
                    </button>
                  </div>
                  <strong className="pos-line-total">{money(lineUnitPrice(line) * line.quantity)}</strong>
                </div>
              ))}
            </div>
          ) : (
            <div className="pos-cart-empty"><span><ShoppingBag size={24} /></span><strong>سبد خرید خالی است</strong><p>از منوی واقعی محصولات، یک مورد انتخاب کنید.</p></div>
          )}

          <div className="pos-cart-options">
            <SelectField label="نوع سفارش" value={orderType} onChange={(event) => setOrderType(event.target.value)}
              options={orderTypes} placeholder="نوع سفارش را انتخاب کنید" />
            {orderType === "delivery" ? (
              <>
                <TextField label="نام گیرنده" value={recipientName} onChange={(event) => setRecipientName(event.target.value)} placeholder="نام تحویل‌گیرنده" />
                <TextField label="شماره تماس گیرنده" value={recipientPhone} onChange={(event) => setRecipientPhone(event.target.value)} placeholder="09..." inputMode="tel" />
                <TextField label="نشانی تحویل" value={deliveryAddress} onChange={(event) => setDeliveryAddress(event.target.value)} placeholder="نشانی کامل" />
              </>
            ) : null}
          </div>

          <div className="pos-totals">
            <div><span>جمع تقریبی کالاها (بدون مالیات)</span><strong>{money(subtotal)} تومان</strong></div>
            <div className="pos-grand-total"><span>مبلغ نهایی</span><strong>محاسبه در سرور</strong></div>
          </div>
          <Button variant="primary" disabled={!cart.length || submitting || loadingCatalog} onClick={() => { void submitOrder(); }}>
            {submitting ? "در حال ثبت سفارش..." : "ثبت سفارش در سرور"}
          </Button>
          <p className="pos-cart-disclaimer">
            <Coffee size={13} /> قیمت، مالیات و شناسه نهایی را سرور تعیین می‌کند؛ سفارش با وضعیت پیش‌نویس ثبت می‌شود.
          </p>
        </Surface>
      </div>
    </div>
  );
}
