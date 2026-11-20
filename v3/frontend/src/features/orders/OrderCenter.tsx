import { useEffect, useMemo, useRef, useState } from "react";
import { Eye, FilterX, PackageCheck, RefreshCw } from "lucide-react";
import {
  ApiError,
  getOrderDetails,
  getOpenCashRefundSessions,
  getOrderPayments,
  getOrders,
  refundCashPayment,
  transitionOrder,
  type OpenCashPaymentSession,
  type OrderPaymentSummary,
  type OrderDetail,
  type OrderSummary,
  type OrderStatus,
} from "../../shared/api/client";
import {
  Button,
  DataTable,
  Notice,
  SelectField,
  StatusBadge,
  Surface,
  TextField,
} from "../../shared/ui";

const money = (value: number) => new Intl.NumberFormat("fa-IR").format(value);
const dateTime = (value: string, timeZone: string) => {
  const date = new Date(value.includes("Z") || /[+-]\d\d:\d\d$/.test(value) ? value : `${value.replace(" ", "T")}Z`);
  if (Number.isNaN(date.getTime())) return value;
  try {
    return new Intl.DateTimeFormat("fa-IR", { dateStyle: "short", timeStyle: "short", timeZone }).format(date);
  } catch {
    return new Intl.DateTimeFormat("fa-IR", { dateStyle: "short", timeStyle: "short" }).format(date);
  }
};
const statusLabels: Record<OrderStatus, { label: string; variant: "info" | "warning" | "success" | "danger" | "neutral" }> = {
  draft: { label: "پیش‌نویس", variant: "neutral" },
  awaiting_payment: { label: "در انتظار پرداخت", variant: "warning" },
  accepted: { label: "پذیرفته‌شده", variant: "info" },
  preparing: { label: "در حال آماده‌سازی", variant: "warning" },
  ready: { label: "آماده تحویل", variant: "success" },
  completed: { label: "تکمیل‌شده", variant: "neutral" },
  cancelled: { label: "لغوشده", variant: "danger" },
  rejected: { label: "ردشده", variant: "danger" },
};
const channelLabels: Record<string, string> = {
  pos: "صندوق", phone: "تلفنی", manual: "ثبت دستی", table: "میز",
  takeaway: "بیرون‌بر", storefront: "فروشگاه آنلاین",
};
const fulfillmentLabels: Record<string, string> = {
  dine_in: "سرو داخل", takeaway: "بیرون‌بر", delivery: "ارسال",
};
const paymentLabels: Record<string, { label: string; variant: "success" | "warning" | "danger" | "neutral" }> = {
  unpaid: { label: "پرداخت‌نشده", variant: "warning" },
  partially_paid: { label: "پرداخت بخشی", variant: "warning" },
  pending: { label: "در انتظار تأیید", variant: "warning" },
  failed: { label: "ناموفق", variant: "danger" },
  partially_refunded: { label: "استرداد بخشی", variant: "warning" },
  paid: { label: "پرداخت‌شده", variant: "success" },
  refunded: { label: "مستردشده", variant: "danger" },
};

function paymentBadge(status: string) {
  const value = paymentLabels[status] ?? { label: status || "نامشخص", variant: "neutral" as const };
  return <StatusBadge variant={value.variant}>{value.label}</StatusBadge>;
}

export function OrderCenter({ timeZone }: { timeZone: string }) {
  const [query, setQuery] = useState("");
  const [fromDate, setFromDate] = useState("");
  const [toDate, setToDate] = useState("");
  const [status, setStatus] = useState("all");
  const [channel, setChannel] = useState("all");
  const [payment, setPayment] = useState("all");
  const [fulfillment, setFulfillment] = useState("all");
  const [orders, setOrders] = useState<OrderSummary[]>([]);
  const [nextCursor, setNextCursor] = useState<string | null>(null);
  const [hasMore, setHasMore] = useState(false);
  const [loadingMore, setLoadingMore] = useState(false);
  const [selectedId, setSelectedId] = useState<string | null>(null);
  const [detail, setDetail] = useState<OrderDetail | null>(null);
  const [loading, setLoading] = useState(true);
  const [detailLoading, setDetailLoading] = useState(false);
  const [refreshing, setRefreshing] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [detailError, setDetailError] = useState<string | null>(null);
  const [transitioning, setTransitioning] = useState(false);
  const [actionError, setActionError] = useState<string | null>(null);
  const [showCancelForm, setShowCancelForm] = useState(false);
  const [cancelReason, setCancelReason] = useState("");
  const [paymentSummary, setPaymentSummary] = useState<OrderPaymentSummary | null>(null);
  const [paymentLoading, setPaymentLoading] = useState(false);
  const [refundSessions, setRefundSessions] = useState<OpenCashPaymentSession[]>([]);
  const [refundPaymentId, setRefundPaymentId] = useState<string | null>(null);
  const [refundAmount, setRefundAmount] = useState("");
  const [refundReason, setRefundReason] = useState("");
  const [refundSessionId, setRefundSessionId] = useState("");
  const [refundConfirmed, setRefundConfirmed] = useState(false);
  const [refundSubmitting, setRefundSubmitting] = useState(false);
  const [refundError, setRefundError] = useState<string | null>(null);
  const [refundSuccess, setRefundSuccess] = useState<string | null>(null);
  const refundAttempt = useRef<{ signature: string; key: string } | null>(null);
  const listRequestVersion = useRef(0);

  async function fetchOrders() {
    const requestVersion = ++listRequestVersion.current;
    setError(null);
    if (fromDate && toDate && fromDate > toDate) {
      setError("تاریخ شروع نمی‌تواند بعد از تاریخ پایان باشد.");
      setOrders([]);
      setNextCursor(null);
      setHasMore(false);
      setLoading(false);
      return;
    }
    try {
      const result = await getOrders({
        status: status === "all" ? undefined : status,
        channel: channel === "all" ? undefined : channel,
        payment: payment === "all" ? undefined : payment,
        fulfillment: fulfillment === "all" ? undefined : fulfillment,
        fromDate: fromDate || undefined,
        toDate: toDate || undefined,
        limit: 50,
      });
      if (requestVersion !== listRequestVersion.current) return;
      setOrders(result.orders);
      setNextCursor(result.next_cursor);
      setHasMore(result.has_more);
      if (selectedId && !result.orders.some((order) => order.id === selectedId)) {
        setSelectedId(null);
        setDetail(null);
        setDetailLoading(false);
      }
    } catch (cause) {
      if (requestVersion !== listRequestVersion.current) return;
      setError(cause instanceof ApiError ? cause.message : "دریافت سفارش‌ها انجام نشد.");
      setOrders([]);
      setNextCursor(null);
      setHasMore(false);
    } finally {
      if (requestVersion === listRequestVersion.current) setLoading(false);
    }
  }

  async function loadMoreOrders() {
    if (!nextCursor || !hasMore || loadingMore) return;
    const requestVersion = listRequestVersion.current;
    setLoadingMore(true);
    try {
      const result = await getOrders({
        status: status === "all" ? undefined : status,
        channel: channel === "all" ? undefined : channel,
        payment: payment === "all" ? undefined : payment,
        fulfillment: fulfillment === "all" ? undefined : fulfillment,
        fromDate: fromDate || undefined,
        toDate: toDate || undefined,
        limit: 50,
        before: nextCursor,
      });
      if (requestVersion !== listRequestVersion.current) return;
      setOrders((current) => {
        const seen = new Set(current.map((order) => order.id));
        return [...current, ...result.orders.filter((order) => !seen.has(order.id))];
      });
      setNextCursor(result.next_cursor);
      setHasMore(result.has_more);
    } catch (cause) {
      if (requestVersion === listRequestVersion.current) {
        setError(cause instanceof ApiError ? cause.message : "دریافت صفحه بعدی سفارش‌ها انجام نشد.");
      }
    } finally {
      if (requestVersion === listRequestVersion.current) setLoadingMore(false);
    }
  }

  async function refreshOrders() {
    setRefreshing(true);
    setError(null);
    try {
      await fetchOrders();
    } finally {
      setRefreshing(false);
    }
  }

  function toggleOrder(orderId: string) {
    if (selectedId === orderId) {
      setSelectedId(null);
      setDetail(null);
      setDetailError(null);
      setDetailLoading(false);
      return;
    }
    setDetail(null);
    setDetailError(null);
    setDetailLoading(true);
    setSelectedId(orderId);
  }

  useEffect(() => {
    // This effect starts an asynchronous API request; state updates happen after the request settles.
    // eslint-disable-next-line react-hooks/set-state-in-effect
    void fetchOrders();
    // The active business/branch context is applied by the shared API client.
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [status, channel, payment, fulfillment, fromDate, toDate]);

  useEffect(() => {
    if (!selectedId) {
      setPaymentLoading(false);
      setPaymentSummary(null);
      setRefundSessions([]);
      setRefundPaymentId(null);
      return;
    }
    let active = true;
    setPaymentLoading(true);
    setPaymentSummary(null);
    setRefundPaymentId(null);
    setRefundError(null);
    setRefundSuccess(null);
    refundAttempt.current = null;
    getOrderDetails(selectedId)
      .then((result) => { if (active) setDetail(result); })
      .catch((cause: unknown) => {
        if (!active) return;
        setDetail(null);
        setDetailError(cause instanceof ApiError ? cause.message : "دریافت جزئیات سفارش انجام نشد.");
      })
      .finally(() => { if (active) setDetailLoading(false); });
    getOrderPayments(selectedId)
      .then((result) => { if (active) setPaymentSummary(result); })
      .catch(() => { if (active) setPaymentSummary(null); })
      .finally(() => { if (active) setPaymentLoading(false); });
    getOpenCashRefundSessions()
      .then((sessions) => {
        if (!active) return;
        setRefundSessions(sessions);
        setRefundSessionId((current) => sessions.some((session) => session.id === current)
          ? current
          : sessions[0]?.id ?? "");
      })
      .catch(() => { if (active) setRefundSessions([]); });
    return () => { active = false; };
  }, [selectedId]);

  const filteredOrders = useMemo(() => {
    const normalized = query.trim().toLocaleLowerCase("fa-IR");
    return orders.filter((order) => {
      const customer = order.customer?.name ?? "مشتری مهمان";
      const matchesQuery = !normalized
        || `${order.order_number} ${customer} ${channelLabels[order.source_channel] ?? order.source_channel}`.toLocaleLowerCase("fa-IR").includes(normalized);
      return matchesQuery;
    });
  }, [orders, query]);

  const selectedOrder = detail?.order ?? null;

  async function changeOrderStatus(toStatus: OrderStatus, reasonText?: string) {
    if (!selectedOrder || transitioning) return;
    setTransitioning(true);
    setActionError(null);
    try {
      await transitionOrder(selectedOrder.id, toStatus, reasonText);
      await fetchOrders();
      const updated = await getOrderDetails(selectedOrder.id);
      setDetail(updated);
      setShowCancelForm(false);
      setCancelReason("");
    } catch (cause) {
      setActionError(cause instanceof ApiError ? cause.message : cause instanceof Error ? cause.message : "تغییر وضعیت سفارش انجام نشد.");
    } finally {
      setTransitioning(false);
    }
  }
  function refundableAmount(paymentId: string, amount: number): number {
    const refunded = paymentSummary?.refunds
      .filter((refund) => refund.payment_id === paymentId && refund.status === "succeeded")
      .reduce((sum, refund) => sum + refund.amount, 0) ?? 0;
    return Math.max(0, amount - refunded);
  }

  function beginCashRefund(paymentId: string, amount: number) {
    setRefundPaymentId(paymentId);
    setRefundAmount(String(amount));
    setRefundReason("");
    setRefundSessionId((current) => refundSessions.some((session) => session.id === current)
      ? current
      : refundSessions[0]?.id ?? "");
    setRefundConfirmed(false);
    setRefundError(null);
    setRefundSuccess(null);
    refundAttempt.current = null;
  }

  async function submitCashRefund() {
    if (!selectedOrder || !refundPaymentId || refundSubmitting) return;
    const amount = Number(refundAmount.replace(/[,٬\\s]/g, ""));
    if (!Number.isSafeInteger(amount) || amount < 1) {
      setRefundError("مبلغ بازپرداخت باید عدد صحیح و بیشتر از صفر باشد.");
      return;
    }
    if (!refundReason.trim()) {
      setRefundError("دلیل بازپرداخت را وارد کنید.");
      return;
    }
    if (!refundSessionId || !refundConfirmed) {
      setRefundError("شیفت صندوق را انتخاب و بازپرداخت را صریحاً تأیید کنید.");
      return;
    }
    const signature = JSON.stringify([selectedOrder.id, refundPaymentId, refundSessionId, amount, refundReason.trim()]);
    if (!refundAttempt.current || refundAttempt.current.signature !== signature) {
      refundAttempt.current = { signature, key: globalThis.crypto.randomUUID() };
    }
    setRefundSubmitting(true);
    setRefundError(null);
    setRefundSuccess(null);
    try {
      const result = await refundCashPayment(
        selectedOrder.id,
        refundPaymentId,
        refundSessionId,
        amount,
        refundReason.trim(),
        refundAttempt.current.key,
      );
      setRefundSuccess(`بازپرداخت ${money(result.refund.amount)} تومان با موفقیت ثبت شد.`);
      setRefundPaymentId(null);
      setRefundConfirmed(false);
      refundAttempt.current = null;
      void getOrderPayments(selectedOrder.id).then(setPaymentSummary).catch(() => undefined);
      void getOrderDetails(selectedOrder.id).then(setDetail).catch(() => undefined);
      void fetchOrders();
    } catch (cause) {
      setRefundError(cause instanceof ApiError
        ? cause.message
        : cause instanceof Error ? cause.message : "ثبت بازپرداخت انجام نشد.");
    } finally {
      setRefundSubmitting(false);
    }
  }

  const columns = [
    {
      key: "number",
      header: "شماره سفارش",
      render: (order: OrderSummary) => <strong className="orders-number">#{order.order_number}</strong>,
    },
    {
      key: "customer",
      header: "مشتری / کانال",
      render: (order: OrderSummary) => (
        <span className="orders-customer">
          <strong>{order.customer?.name ?? "مشتری مهمان"}</strong>
          <small>{channelLabels[order.source_channel] ?? order.source_channel} · {fulfillmentLabels[order.fulfillment_method ?? ""] ?? "نوع دریافت ثبت نشده"}</small>
        </span>
      ),
    },
    {
      key: "time",
      header: "زمان ثبت",
      render: (order: OrderSummary) => dateTime(order.created_at, timeZone),
      align: "center" as const,
    },
    {
      key: "amount",
      header: "مبلغ",
      render: (order: OrderSummary) => <strong>{money(order.total_amount)} تومان</strong>,
      align: "end" as const,
    },
    {
      key: "status",
      header: "وضعیت سفارش",
      render: (order: OrderSummary) => {
        const value = statusLabels[order.order_status];
        return <StatusBadge variant={value?.variant ?? "neutral"}>{value?.label ?? order.order_status}</StatusBadge>;
      },
    },
    {
      key: "payment",
      header: "وضعیت پرداخت",
      render: (order: OrderSummary) => paymentBadge(order.payment_status),
    },
    {
      key: "action",
      header: "جزئیات",
      render: (order: OrderSummary) => (
        <Button size="sm" variant={selectedId === order.id ? "primary" : "secondary"}
          onClick={() => toggleOrder(order.id)}
          aria-label={`نمایش جزئیات سفارش ${order.order_number}`}>
          <Eye size={14} /> مشاهده
        </Button>
      ),
      align: "center" as const,
    },
  ];

  const totalOrders = orders.length;
  const preparingOrders = orders.filter((order) => order.order_status === "preparing").length;
  const readyOrders = orders.filter((order) => order.order_status === "ready").length;
  const awaitingPayment = orders.filter((order) => ["unpaid", "partially_paid", "partially_refunded", "pending"].includes(order.payment_status)).length;

  return (
    <div className="page-stack orders-workspace">
      <div className="orders-page-heading">
        <div>
          <span className="ui-eyebrow">ORDER MANAGEMENT</span>
          <h1>مرکز یکپارچه سفارش‌ها</h1>
          <p>نمایش سفارش‌های صندوق، تلفنی، بیرون‌بر و فروشگاه آنلاین در یک محل</p>
        </div>
        <Button size="sm" variant="secondary" onClick={() => void refreshOrders()} disabled={loading || refreshing}>
          <RefreshCw size={14} /> {refreshing ? "در حال بروزرسانی..." : "بروزرسانی"}
        </Button>
      </div>

      {error ? <Notice variant="warning">{error}</Notice> : null}
      {!error && loading ? <Notice variant="info">در حال دریافت سفارش‌های واقعی شعبه...</Notice> : null}

      <div className="orders-summary-grid">
        <Surface padding="sm"><span>سفارش‌های بارگذاری‌شده</span><strong>{money(totalOrders)}</strong></Surface>
        <Surface padding="sm"><span>در حال آماده‌سازی</span><strong>{money(preparingOrders)}</strong></Surface>
        <Surface padding="sm"><span>آماده تحویل</span><strong>{money(readyOrders)}</strong></Surface>
        <Surface padding="sm"><span>پرداخت‌نشده / بخشی</span><strong>{money(awaitingPayment)}</strong></Surface>
      </div>

      <Surface padding="md" className="orders-table-surface">
        <div className="orders-table-heading">
          <div>
            <h2><PackageCheck size={18} /> فهرست سفارش‌ها</h2>
            <p>{money(filteredOrders.length)} سفارش مطابق فیلتر · نمایش صفحه‌ای سفارش‌ها</p>
          </div>
          <Button size="sm" variant="ghost" onClick={() => {
            setQuery(""); setStatus("all"); setChannel("all"); setPayment("all");
            setFulfillment("all"); setFromDate(""); setToDate(""); setSelectedId(null); setDetail(null); setDetailError(null);
          }}>
            <FilterX size={14} /> پاک‌کردن فیلترها
          </Button>
        </div>

        <div className="orders-filters">
          <TextField label="جست‌وجو" value={query} onChange={(event) => setQuery(event.target.value)}
            placeholder="شماره سفارش، مشتری یا کانال" />
          <SelectField label="وضعیت سفارش" value={status} onChange={(event) => { listRequestVersion.current += 1; setLoadingMore(false); setNextCursor(null); setHasMore(false); setLoading(true); setStatus(event.target.value); }}
            options={[
              { value: "all", label: "همه وضعیت‌ها" },
              { value: "draft", label: "پیش‌نویس" },
              { value: "awaiting_payment", label: "در انتظار پرداخت" },
              { value: "accepted", label: "پذیرفته‌شده" },
              { value: "preparing", label: "در حال آماده‌سازی" },
              { value: "ready", label: "آماده تحویل" },
              { value: "completed", label: "تکمیل‌شده" },
              { value: "cancelled", label: "لغوشده" },
              { value: "rejected", label: "ردشده" },
            ]} />
          <SelectField label="کانال سفارش" value={channel} onChange={(event) => { listRequestVersion.current += 1; setLoadingMore(false); setNextCursor(null); setHasMore(false); setLoading(true); setChannel(event.target.value); }}
            options={[
              { value: "all", label: "همه کانال‌ها" },
              { value: "pos", label: "صندوق" },
              { value: "phone", label: "تلفنی" },
              { value: "manual", label: "ثبت دستی" },
              { value: "table", label: "میز" },
              { value: "takeaway", label: "بیرون‌بر" },
              { value: "storefront", label: "فروشگاه آنلاین" },
            ]} />
          <SelectField label="وضعیت پرداخت" value={payment} onChange={(event) => { listRequestVersion.current += 1; setLoadingMore(false); setNextCursor(null); setHasMore(false); setLoading(true); setPayment(event.target.value); }}
            options={[
              { value: "all", label: "همه وضعیت‌ها" },
              { value: "paid", label: "پرداخت‌شده" },
              { value: "partially_paid", label: "پرداخت بخشی" },
              { value: "pending", label: "در انتظار تأیید" },
              { value: "failed", label: "ناموفق" },
              { value: "partially_refunded", label: "استرداد بخشی" },
              { value: "unpaid", label: "پرداخت‌نشده" },
              { value: "refunded", label: "مستردشده" },
            ]} />
          <SelectField label="نوع دریافت" value={fulfillment} onChange={(event) => { listRequestVersion.current += 1; setLoadingMore(false); setNextCursor(null); setHasMore(false); setLoading(true); setFulfillment(event.target.value); }}
            options={[
              { value: "all", label: "همه انواع" },
              { value: "dine_in", label: "سرو داخل" },
              { value: "takeaway", label: "بیرون‌بر" },
              { value: "delivery", label: "ارسال" },
            ]} />
          <TextField label="از تاریخ" type="date" value={fromDate}
            onChange={(event) => { listRequestVersion.current += 1; setLoadingMore(false); setNextCursor(null); setHasMore(false); setLoading(true); setFromDate(event.target.value); }}
            max={toDate || undefined} hint={`بر اساس ساعت محلی شعبه: ${timeZone}`} />
          <TextField label="تا تاریخ" type="date" value={toDate}
            onChange={(event) => { listRequestVersion.current += 1; setLoadingMore(false); setNextCursor(null); setHasMore(false); setLoading(true); setToDate(event.target.value); }}
            min={fromDate || undefined} hint={`بازه شامل تمام روز انتخاب‌شده است`} />
        </div>

        <DataTable columns={columns} rows={filteredOrders} getRowKey={(order) => order.id}
          emptyMessage={loading ? "در حال دریافت اطلاعات..." : "سفارشی با این فیلترها پیدا نشد."}
          caption="اطلاعات واقعی سفارش‌های کسب‌وکار و شعبه انتخاب‌شده" />
        {hasMore ? (
          <div className="orders-load-more">
            <Button size="sm" variant="secondary" onClick={() => void loadMoreOrders()} disabled={loadingMore}>
              {loadingMore ? "در حال دریافت..." : "نمایش سفارش‌های قدیمی‌تر"}
            </Button>
          </div>
        ) : null}
      </Surface>

      {selectedId ? (
        <Surface padding="md" className="orders-detail">
          <div className="orders-detail-heading">
            <div>
              <span className="ui-eyebrow">ORDER DETAIL</span>
              <h2>{selectedOrder ? `جزئیات سفارش #${selectedOrder.order_number}` : "جزئیات سفارش"}</h2>
            </div>
            <Button size="sm" variant="ghost" onClick={() => setSelectedId(null)}>بستن</Button>
          </div>
          {detailLoading ? <Notice variant="info">در حال دریافت جزئیات سفارش...</Notice> : null}
          {detailError ? <Notice variant="warning">{detailError}</Notice> : null}
          {actionError ? <Notice variant="warning">{actionError}</Notice> : null}
          {selectedOrder && detail ? (
            <>
              <div className="orders-detail-grid">
                <div><span>مشتری</span><strong>{selectedOrder.customer?.name ?? "مشتری مهمان"}</strong></div>
                <div><span>کانال ثبت</span><strong>{channelLabels[selectedOrder.source_channel] ?? selectedOrder.source_channel}</strong></div>
                <div><span>نوع دریافت</span><strong>{fulfillmentLabels[selectedOrder.fulfillment_method ?? ""] ?? "ثبت نشده"}</strong></div>
                <div><span>زمان ثبت</span><strong>{dateTime(selectedOrder.created_at, timeZone)}</strong></div>
                <div><span>وضعیت سفارش</span><StatusBadge variant={statusLabels[selectedOrder.order_status]?.variant ?? "neutral"}>{statusLabels[selectedOrder.order_status]?.label ?? selectedOrder.order_status}</StatusBadge></div>
                <div><span>وضعیت پرداخت</span>{paymentBadge(selectedOrder.payment_status)}</div>
              </div>
              {selectedOrder.fulfillment?.method === "delivery" ? (
                <div className="orders-detail-grid">
                  <div><span>تحویل‌گیرنده</span><strong>{selectedOrder.fulfillment.recipient_name ?? "—"}</strong></div>
                  <div><span>شماره تماس</span><strong>{selectedOrder.fulfillment.recipient_phone ?? "—"}</strong></div>
                  <div><span>نشانی</span><strong>{selectedOrder.fulfillment.address ? Object.values(selectedOrder.fulfillment.address).join("، ") : "—"}</strong></div>
                </div>
              ) : null}
              <div className="orders-detail-items">
                <strong>اقلام سفارش</strong>
                {detail.items.map((item, index) => (
                  <article className="orders-detail-item" key={`${selectedOrder.id}-${index}`}>
                    <div><strong>{item.name}{item.variant_name ? ` · ${item.variant_name}` : ""}</strong>
                      <span>{item.quantity} × {money(item.unit_price_amount)} تومان</span></div>
                    {item.options.length ? <small>انتخاب‌ها: {item.options.map((option) => `${option.group_name}: ${option.name}`).join("، ")}</small> : null}
                    <b>{money(item.line_total_amount)} تومان</b>
                  </article>
                ))}
              </div>
              <section className="orders-payment-history">
                <div className="orders-payment-heading">
                  <strong>گردش پرداخت و بازپرداخت</strong>
                  {paymentSummary ? <span>مانده: {money(paymentSummary.due_amount)} تومان</span> : null}
                </div>
                {paymentLoading ? <Notice variant="info">در حال دریافت گردش پرداخت...</Notice> : null}
                {paymentSummary ? (
                  <>
                    <div className="orders-payment-totals">
                      <span>دریافت‌شده: <b>{money(paymentSummary.captured_amount)} تومان</b></span>
                      <span>بازپرداخت‌شده: <b>{money(paymentSummary.refunded_amount)} تومان</b></span>
                    </div>
                    {paymentSummary.payments.map((payment) => {
                      const refundable = refundableAmount(payment.id, payment.amount);
                      return (
                        <article className="orders-payment-row" key={payment.id}>
                          <div>
                            <strong>{payment.method === "cash" ? "نقدی" : payment.method === "bank_terminal" ? "کارتخوان" : payment.method}</strong>
                            <small>{payment.status} · {dateTime(payment.created_at, timeZone)}</small>
                          </div>
                          <b>{money(payment.amount)} تومان</b>
                          {payment.method === "cash" && refundable > 0 && refundSessions.length ? (
                            <Button size="sm" variant="danger" onClick={() => beginCashRefund(payment.id, refundable)}>
                              بازپرداخت نقدی
                            </Button>
                          ) : null}
                        </article>
                      );
                    })}
                    {paymentSummary.refunds.map((refund) => (
                      <article className="orders-refund-row" key={refund.id}>
                        <div><strong>بازپرداخت نقدی · {refund.status}</strong><small>{refund.reason}</small></div>
                        <b>−{money(refund.amount)} تومان</b>
                      </article>
                    ))}
                    {refundSessions.length === 0 && paymentSummary.payments.some((payment) =>
                      payment.method === "cash" && refundableAmount(payment.id, payment.amount) > 0
                    ) ? <Notice variant="warning">بازپرداخت نقدی به مجوز مدیریت مالی و یک شیفت صندوق باز نیاز دارد؛ دسترسی و وضعیت صندوق را بررسی کنید.</Notice> : null}
                  </>
                ) : null}
                {refundSuccess ? <div className="auth-success" role="status">{refundSuccess}</div> : null}
                {refundError ? <div className="auth-error" role="alert">{refundError}</div> : null}
                {refundPaymentId ? (
                  <div className="orders-refund-form">
                    <strong>ثبت بازپرداخت نقدی</strong>
                    <label>شیفت صندوق
                      <select value={refundSessionId} onChange={(event) => setRefundSessionId(event.target.value)}>
                        {refundSessions.map((session) => <option key={session.id} value={session.id}>{session.register_name} · {session.register_code}</option>)}
                      </select>
                    </label>
                    <label>مبلغ بازپرداخت (تومان)
                      <input type="number" min="1" step="1" value={refundAmount} onChange={(event) => setRefundAmount(event.target.value)} />
                    </label>
                    <label>دلیل بازپرداخت
                      <textarea rows={2} maxLength={500} value={refundReason} onChange={(event) => setRefundReason(event.target.value)} />
                    </label>
                    <label className="orders-refund-confirm">
                      <input type="checkbox" checked={refundConfirmed} onChange={(event) => setRefundConfirmed(event.target.checked)} />
                      <span>مبلغ را به مشتری تحویل می‌دهم و بازپرداخت را تأیید می‌کنم.</span>
                    </label>
                    <div className="orders-detail-actions">
                      <Button variant="secondary" disabled={refundSubmitting} onClick={() => setRefundPaymentId(null)}>انصراف</Button>
                      <Button variant="danger" disabled={refundSubmitting || !refundConfirmed || !refundReason.trim() || !refundSessionId} onClick={() => { void submitCashRefund(); }}>
                        {refundSubmitting ? "در حال ثبت..." : "تأیید و ثبت بازپرداخت"}
                      </Button>
                    </div>
                  </div>
                ) : null}
              </section>
              <div className="orders-timeline">
                <strong>تاریخچه سفارش</strong>
                <ol>{detail.status_history.map((event, index) => (
                  <li className={`is-${index === detail.status_history.length - 1 ? "current" : "done"}`} key={`${event.to_status}-${index}`}>
                    <span className="orders-timeline-marker" />
                    <div><b>{statusLabels[event.to_status as OrderStatus]?.label ?? event.to_status}</b>
                      <small>{event.reason_text || event.actor?.name || channelLabels[event.source_channel] || event.source_channel}</small></div>
                    <time>{dateTime(event.created_at, timeZone)}</time>
                  </li>
                ))}</ol>
              </div>
              <div className="orders-detail-total">
                <span>جمع جزء {money(selectedOrder.subtotal_amount)} · مالیات {money(selectedOrder.tax_amount)}</span>
                <strong>{money(selectedOrder.total_amount)} تومان</strong>
              </div>
              {showCancelForm ? (
                <div className="orders-cancel-form">
                  <label htmlFor="order-cancel-reason">دلیل لغو سفارش (اجباری)</label>
                  <textarea
                    id="order-cancel-reason"
                    value={cancelReason}
                    onChange={(event) => setCancelReason(event.target.value)}
                    maxLength={500}
                    rows={3}
                    placeholder="دلیل لغو را وارد کنید..."
                    disabled={transitioning}
                  />
                  <small>{cancelReason.length}/500</small>
                  <div className="orders-detail-actions">
                    <Button variant="secondary" disabled={transitioning} onClick={() => { setShowCancelForm(false); setCancelReason(""); }}>
                      انصراف
                    </Button>
                    <Button variant="danger" disabled={transitioning || !cancelReason.trim()} onClick={() => { void changeOrderStatus("cancelled", cancelReason.trim()); }}>
                      {transitioning ? "در حال ثبت..." : "تأیید لغو سفارش"}
                    </Button>
                  </div>
                </div>
              ) : null}
              <div className="orders-detail-actions">
                {selectedOrder.order_status === "draft" || selectedOrder.order_status === "awaiting_payment" ? (
                  <Button variant="primary" disabled={transitioning} onClick={() => { void changeOrderStatus("accepted"); }}>
                    {transitioning ? "در حال ثبت..." : "پذیرش سفارش"}
                  </Button>
                ) : null}
                {selectedOrder.order_status === "accepted" ? <span>برای شروع آماده‌سازی، این سفارش در برد آشپزخانه قرار دارد.</span> : null}
                {selectedOrder.order_status === "ready" ? (
                  <Button variant="primary" disabled={transitioning} onClick={() => { void changeOrderStatus("completed"); }}>
                    {transitioning ? "در حال ثبت..." : "تکمیل و تحویل سفارش"}
                  </Button>
                ) : null}
                {!showCancelForm && !["completed", "cancelled", "rejected"].includes(selectedOrder.order_status)
                  && !["paid", "partially_paid", "partially_refunded"].includes(selectedOrder.payment_status) ? (
                  <Button variant="danger" disabled={transitioning} onClick={() => { setCancelReason(""); setShowCancelForm(true); }}>
                    لغو سفارش
                  </Button>
                ) : null}
                {selectedOrder.order_status === "draft" || selectedOrder.order_status === "awaiting_payment" ? (
                  <span>پذیرش سفارش وضعیت پرداخت را تغییر نمی‌دهد.</span>
                ) : null}
              </div>
            </>
          ) : null}
        </Surface>
      ) : null}
    </div>
  );
}
