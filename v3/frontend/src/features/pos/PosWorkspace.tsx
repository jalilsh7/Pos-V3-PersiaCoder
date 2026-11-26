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
  getOrderDetails,
  getOpenCashPaymentSessions,
  getPosCatalogProductDetails,
  transitionOrder,
  type OrderDetail,
  searchPosCustomers,
  getPosCatalogProducts,
  type OpenCashPaymentSession,
  type PosCustomerOption,
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
  const [customerSearch, setCustomerSearch] = useState("");
  const [customerOptions, setCustomerOptions] = useState<PosCustomerOption[]>([]);
  const [selectedCustomer, setSelectedCustomer] = useState<PosCustomerOption | null>(null);
  const [customerLookupError, setCustomerLookupError] = useState<string | null>(null);
  const [editingProduct, setEditingProduct] = useState<Product | null>(null);
  const [loadingProductId, setLoadingProductId] = useState<string | null>(null);
  const [selectedOptions, setSelectedOptions] = useState<ProductOption[]>([]);
  const [selectedVariantId, setSelectedVariantId] = useState("");
  const [submitting, setSubmitting] = useState(false);
  const [actionError, setActionError] = useState<string | null>(null);
  const [createdOrder, setCreatedOrder] = useState<{
    number: number; total: number; id: string; orderStatus: string; paymentStatus: string; capturedAmount: number; dueAmount: number;
  } | null>(null);
  const [receiptOrder, setReceiptOrder] = useState<OrderDetail | null>(null);
  const [receiptOrderLoading, setReceiptOrderLoading] = useState(false);
  const [receiptOrderError, setReceiptOrderError] = useState<string | null>(null);
  const [acceptingOrder, setAcceptingOrder] = useState(false);
  const [acceptanceError, setAcceptanceError] = useState<string | null>(null);
  const [openCashSessions, setOpenCashSessions] = useState<OpenCashPaymentSession[]>([]);
  const [loadingCashSessions, setLoadingCashSessions] = useState(false);
  const [cashSessionId, setCashSessionId] = useState("");
  const [paymentAmount, setPaymentAmount] = useState("");
  const [paymentSubmitting, setPaymentSubmitting] = useState(false);
  const [paymentError, setPaymentError] = useState<string | null>(null);
  const [lastReceipt, setLastReceipt] = useState<{
    paymentId: string; amount: number; capturedAt: string; orderNumber: number; dueAmount: number;
  } | null>(null);
  const idempotencyAttempt = useRef<{ signature: string; key: string } | null>(null);
  const cashPaymentAttempt = useRef<{ signature: string; key: string } | null>(null);
  const receiptRequestVersion = useRef(0);

  async function loadCatalog() {
    setLoadingCatalog(true);
    setCatalogError(null);
    try {
      const items = await getPosCatalogProducts();
      setProducts(items.map(mapProductSummary));
    } catch (cause) {
      setCatalogError(cause instanceof Error ? cause.message : "دریافت منوی محصولات انجام نشد.");
    } finally {
      setLoadingCatalog(false);
    }
  }

  async function loadCashSessions() {
    setLoadingCashSessions(true);
    setPaymentError(null);
    try {
      const sessions = await getOpenCashPaymentSessions();
      setOpenCashSessions(sessions);
      setCashSessionId((current) => sessions.some((session) => session.id === current)
        ? current
        : sessions[0]?.id ?? "");
    } catch (cause) {
      setPaymentError(cause instanceof Error ? cause.message : "دریافت شیفت‌های صندوق انجام نشد.");
    } finally {
      setLoadingCashSessions(false);
    }
  }

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
    const query = customerSearch.trim();
    if (!query || selectedCustomer?.name === query) {
      setCustomerOptions([]);
      setCustomerLookupError(null);
      return;
    }
    let active = true;
    searchPosCustomers(query)
      .then((customers) => {
        if (!active) return;
        setCustomerOptions(customers);
        setCustomerLookupError(null);
      })
      .catch((cause: unknown) => {
        if (!active) return;
        setCustomerOptions([]);
        setCustomerLookupError(cause instanceof ApiError
          ? cause.message
          : "جست‌وجوی مشتری در دسترس نیست؛ می‌توانید سفارش را بدون اتصال مشتری ثبت کنید.");
      });
    return () => { active = false; };
  }, [customerSearch, selectedCustomer?.name]);

  useEffect(() => {
    if (!createdOrder) {
      setOpenCashSessions([]);
      setCashSessionId("");
      return;
    }
    let active = true;
    getOpenCashPaymentSessions()
      .then((sessions) => {
        if (!active) return;
        setOpenCashSessions(sessions);
        setCashSessionId((current) => sessions.some((session) => session.id === current)
          ? current
          : sessions[0]?.id ?? "");
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
    setReceiptOrder(null);
    setReceiptOrderError(null);
    setAcceptanceError(null);
    receiptRequestVersion.current += 1;
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
    setReceiptOrder(null);
    setReceiptOrderError(null);
    setAcceptanceError(null);
    receiptRequestVersion.current += 1;
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
      customer_id: selectedCustomer?.id ?? null,
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
        orderStatus: order.order_status,
        paymentStatus: order.payment_status,
        capturedAmount: 0,
        dueAmount: order.total_amount,
      });
      setReceiptOrder(null);
      setReceiptOrderError(null);
      setAcceptanceError(null);
      const receiptVersion = ++receiptRequestVersion.current;
      setReceiptOrderLoading(true);
      void getOrderDetails(order.id)
        .then((details) => {
          if (receiptRequestVersion.current === receiptVersion) setReceiptOrder(details);
        })
        .catch((cause: unknown) => {
          if (receiptRequestVersion.current === receiptVersion) {
            setReceiptOrderError(cause instanceof ApiError ? cause.message : "جزئیات رسید دریافت نشد؛ سفارش ثبت‌شده محفوظ است.");
          }
        })
        .finally(() => {
          if (receiptRequestVersion.current === receiptVersion) setReceiptOrderLoading(false);
        });
      setPaymentAmount(String(order.total_amount));
      setPaymentError(null);
      setLastReceipt(null);
      cashPaymentAttempt.current = null;
      setCart([]);
      setEditingProduct(null);
      setRecipientName("");
      setRecipientPhone("");
      setDeliveryAddress("");
      setCustomerSearch("");
      setCustomerOptions([]);
      setSelectedCustomer(null);
      setCustomerLookupError(null);
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

  async function acceptOrderForPreparation() {
    if (!createdOrder || createdOrder.dueAmount !== 0 || createdOrder.orderStatus !== "draft" || acceptingOrder) return;
    setAcceptingOrder(true);
    setAcceptanceError(null);
    try {
      const result = await transitionOrder(createdOrder.id, "accepted");
      setCreatedOrder((current) => current && current.id === result.order_id
        ? { ...current, orderStatus: result.to_status }
        : current);
      try {
        const details = await getOrderDetails(createdOrder.id);
        setReceiptOrder(details);
        setReceiptOrderError(null);
      } catch {
        setReceiptOrderError("سفارش پذیرفته شد؛ بروزرسانی جزئیات رسید انجام نشد.");
      }
    } catch (cause) {
      setAcceptanceError(cause instanceof ApiError
        ? cause.message
        : cause instanceof Error ? cause.message : "پذیرش سفارش انجام نشد.");
    } finally {
      setAcceptingOrder(false);
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
      setLastReceipt({
        paymentId: result.payment.id,
        amount: result.payment.amount,
        capturedAt: result.payment.captured_at,
        orderNumber: result.order.order_number,
        dueAmount: result.order.due_amount,
      });
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

      <Notice variant="info">
        قیمت و ثبت سفارش در سرور اعتبارسنجی می‌شود. پس از تسویه کامل، می‌توانید سفارش را صریحاً بپذیرید تا برای آماده‌سازی ارسال و موجودی مواد اولیه رزرو شود.
      </Notice>
      {catalogError ? <div className="auth-error" role="alert">
        <span>{catalogError}</span>
        <Button size="sm" variant="secondary" disabled={loadingCatalog} onClick={() => { void loadCatalog(); }}>
          {loadingCatalog ? "در حال تلاش..." : "تلاش دوباره برای دریافت منو"}
        </Button>
      </div> : null}
      {actionError ? <div className="auth-error" role="alert">{actionError}</div> : null}
      {createdOrder ? (
        <div className="auth-success" role="status" aria-live="polite">
          سفارش شماره {money(createdOrder.number)} ثبت شد.
          مبلغ کل: {money(createdOrder.total)} تومان · دریافت‌شده: {money(createdOrder.capturedAmount)} تومان · مانده: {money(createdOrder.dueAmount)} تومان
          · وضعیت پرداخت: {createdOrder.paymentStatus === "paid" ? "تسویه‌شده" : createdOrder.paymentStatus === "partially_paid" ? "پرداخت جزئی" : "پرداخت‌نشده"}
        </div>
      ) : null}
      {acceptanceError ? <div className="auth-error" role="alert">{acceptanceError}</div> : null}
      {createdOrder && createdOrder.dueAmount === 0 && createdOrder.orderStatus === "draft" ? (
        <Surface padding="md" className="pos-payment-panel">
          <div className="pos-payment-heading">
            <div><h2>تسویه کامل شد</h2><p>برای ارسال سفارش به صف آماده‌سازی، پذیرش را تأیید کنید. این کار موجودی مواد اولیه رسپی را رزرو می‌کند.</p></div>
            <StatusBadge variant="success">پرداخت کامل</StatusBadge>
          </div>
          <Button onClick={() => { void acceptOrderForPreparation(); }} disabled={acceptingOrder}>
            {acceptingOrder ? "در حال پذیرش..." : "پذیرش سفارش و ارسال به آماده‌سازی"}
          </Button>
        </Surface>
      ) : null}
      {createdOrder && createdOrder.orderStatus === "accepted" ? (
        <Notice variant="success">سفارش پذیرفته شد و برای آماده‌سازی در مرکز سفارش‌ها قرار گرفت.</Notice>
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
                  {openCashSessions.map((session) => (
                    <option key={session.id} value={session.id}>
                      {session.register_name} · {session.register_code}
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
          ) : loadingCashSessions ? (
            <Notice variant="info">در حال دریافت شیفت‌های باز صندوق...</Notice>
          ) : (
            <div className="page-stack">
              <Notice variant="warning">
                شیفت صندوق باز پیدا نشد. ابتدا از بخش مدیریت صندوق یک صندوق فعال و شیفت باز ایجاد کنید؛ سفارش ثبت‌شده محفوظ است و پرداخت هنوز انجام نشده.
              </Notice>
              <Button variant="secondary" size="sm" onClick={() => { void loadCashSessions(); }} disabled={loadingCashSessions}>
                تلاش دوباره برای دریافت شیفت‌های صندوق
              </Button>
            </div>
          )}
        </Surface>
      ) : null}

      {createdOrder ? (
        <section className="pos-receipt" aria-label="رسید سفارش">
          <div className="pos-receipt-brand">PersiaCoder.ir</div>
          <h2>{createdOrder.dueAmount === 0 ? "رسید فروش" : "رسید سفارش"}</h2>
          <div className="pos-receipt-row"><span>شماره سفارش</span><strong>{money(createdOrder.number)}</strong></div>
          <div className="pos-receipt-row"><span>وضعیت سفارش</span><strong>{createdOrder.orderStatus === "accepted" ? "پذیرفته‌شده" : "در انتظار پذیرش"}</strong></div>
          <div className="pos-receipt-row"><span>وضعیت پرداخت</span><strong>{createdOrder.dueAmount === 0 ? "تسویه‌شده" : createdOrder.capturedAmount > 0 ? "پرداخت بخشی" : "پرداخت‌نشده"}</strong></div>
          {receiptOrderLoading ? <div className="pos-receipt-meta">در حال دریافت اقلام سفارش...</div> : null}
          {receiptOrderError ? <div className="pos-receipt-meta">{receiptOrderError}</div> : null}
          {receiptOrder?.order.id === createdOrder.id ? (
            <>
              <div className="pos-receipt-divider" />
              {receiptOrder.items.map((item, index) => (
                <div className="pos-receipt-item" key={`${createdOrder.id}-${index}`}>
                  <div><strong>{item.name}{item.variant_name ? ` · ${item.variant_name}` : ""}</strong><small>{item.quantity} × {money(item.unit_price_amount)} تومان</small></div>
                  <b>{money(item.line_total_amount)} تومان</b>
                  {item.options.length ? <small className="pos-receipt-options">{item.options.map((option) => `${option.group_name}: ${option.name}`).join("، ")}</small> : null}
                </div>
              ))}
              <div className="pos-receipt-divider" />
              <div className="pos-receipt-row"><span>جمع جزء</span><strong>{money(receiptOrder.order.subtotal_amount)} تومان</strong></div>
              {receiptOrder.order.discount_amount > 0 ? <div className="pos-receipt-row"><span>تخفیف</span><strong>−{money(receiptOrder.order.discount_amount)} تومان</strong></div> : null}
              {receiptOrder.order.tax_amount > 0 ? <div className="pos-receipt-row"><span>مالیات</span><strong>{money(receiptOrder.order.tax_amount)} تومان</strong></div> : null}
              <div className="pos-receipt-row"><strong>مبلغ کل</strong><strong>{money(receiptOrder.order.total_amount)} تومان</strong></div>
            </>
          ) : null}
          {lastReceipt && lastReceipt.orderNumber === createdOrder.number ? (
            <>
              <div className="pos-receipt-divider" />
              <h3>آخرین دریافت نقدی</h3>
              <div className="pos-receipt-row"><span>مبلغ این دریافت</span><strong>{money(lastReceipt.amount)} تومان</strong></div>
              <div className="pos-receipt-row"><span>مانده سفارش</span><strong>{money(lastReceipt.dueAmount)} تومان</strong></div>
              <div className="pos-receipt-meta">شناسه پرداخت: {lastReceipt.paymentId}</div>
              <div className="pos-receipt-meta">زمان ثبت: {lastReceipt.capturedAt}</div>
            </>
          ) : null}
          <div className="pos-receipt-footer">Powered by PersiaCoder.ir</div>
          <Button className="pos-receipt-print" onClick={() => window.print()}>چاپ رسید</Button>
        </section>
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
            <div className="pos-customer-picker">
              <label htmlFor="pos-customer-search">مشتری (اختیاری)</label>
              {selectedCustomer ? (
                <div className="pos-selected-customer">
                  <span><strong>{selectedCustomer.name}</strong><small>{selectedCustomer.phone || "بدون شماره همراه"}</small></span>
                  <button type="button" onClick={() => { setSelectedCustomer(null); setCustomerSearch(""); }} aria-label="حذف مشتری انتخاب‌شده"><X size={14} /></button>
                </div>
              ) : (
                <>
                  <input id="pos-customer-search" value={customerSearch} onChange={(event) => setCustomerSearch(event.target.value)} placeholder="جست‌وجوی نام یا موبایل مشتری" />
                  {customerOptions.length ? (
                    <div className="pos-customer-results">
                      {customerOptions.map((customer) => (
                        <button type="button" key={customer.id} onClick={() => {
                          setSelectedCustomer(customer);
                          setCustomerSearch(customer.name);
                          setCustomerOptions([]);
                          setCustomerLookupError(null);
                        }}>
                          <strong>{customer.name}</strong><small>{customer.phone || "بدون شماره همراه"}</small>
                        </button>
                      ))}
                    </div>
                  ) : null}
                  {customerLookupError ? <small className="pos-customer-error">{customerLookupError}</small> : null}
                </>
              )}
            </div>
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
