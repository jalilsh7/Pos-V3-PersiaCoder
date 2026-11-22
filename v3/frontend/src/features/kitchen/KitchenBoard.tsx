import { useEffect, useMemo, useRef, useState } from "react";
import { ChefHat, Clock3, Eye, RefreshCw } from "lucide-react";
import {
  ApiError,
  getOrderDetails,
  getOrders,
  transitionKitchenOrder,
  type OrderDetail,
  type OrderSummary,
} from "../../shared/api/client";
import { Button, Notice, StatusBadge, Surface } from "../../shared/ui";
import "./kitchen.css";

const statusLabels: Record<"accepted" | "preparing" | "ready", string> = {
  accepted: "در انتظار شروع",
  preparing: "در حال آماده‌سازی",
  ready: "آماده تحویل",
};
const channelLabels: Record<string, string> = {
  pos: "صندوق", phone: "تلفنی", manual: "ثبت دستی", table: "میز",
  takeaway: "بیرون‌بر", storefront: "فروشگاه آنلاین",
};
const fulfillmentLabels: Record<string, string> = {
  dine_in: "سرو داخل", takeaway: "بیرون‌بر", delivery: "ارسال",
};
const money = (value: number) => new Intl.NumberFormat("fa-IR").format(value);
const dateTime = (value: string, timeZone: string) => {
  const date = new Date(value.includes("Z") || /[+-]\\d\\d:\\d\\d$/.test(value) ? value : `${value.replace(" ", "T")}Z`);
  if (Number.isNaN(date.getTime())) return value;
  try {
    return new Intl.DateTimeFormat("fa-IR", { dateStyle: "short", timeStyle: "short", timeZone }).format(date);
  } catch {
    return new Intl.DateTimeFormat("fa-IR", { dateStyle: "short", timeStyle: "short" }).format(date);
  }
};

async function fetchKitchenOrders(): Promise<OrderSummary[]> {
  const statuses = ["accepted", "preparing", "ready"] as const;
  const pages = await Promise.all(statuses.map(async (status) => {
    const collected: OrderSummary[] = [];
    let before: string | undefined;
    let hasMore = true;
    while (hasMore) {
      const page = await getOrders({ status, limit: 100, before });
      collected.push(...page.orders);
      hasMore = page.has_more;
      before = page.next_cursor ?? undefined;
      if (hasMore && !before) {
        throw new Error("ادامه صفحه‌بندی صف آشپزخانه نامعتبر است.");
      }
    }
    return collected;
  }));
  const unique = new Map<string, OrderSummary>();
  for (const page of pages) {
    for (const order of page) unique.set(order.id, order);
  }
  return [...unique.values()].sort((left, right) =>
    left.created_at.localeCompare(right.created_at) || left.order_number - right.order_number
  );
}

export function KitchenBoard({ timeZone }: { timeZone: string }) {
  const [orders, setOrders] = useState<OrderSummary[]>([]);
  const [statusFilter, setStatusFilter] = useState<"all" | "accepted" | "preparing" | "ready">("all");
  const [selectedId, setSelectedId] = useState<string | null>(null);
  const [detail, setDetail] = useState<OrderDetail | null>(null);
  const [loading, setLoading] = useState(true);
  const [detailLoading, setDetailLoading] = useState(false);
  const [refreshing, setRefreshing] = useState(false);
  const [transitioningId, setTransitioningId] = useState<string | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [actionError, setActionError] = useState<string | null>(null);
  const refreshInFlight = useRef(false);
  const refreshQueued = useRef(false);

  useEffect(() => {
    let active = true;
    const load = async (showError: boolean) => {
      if (refreshInFlight.current) {
        refreshQueued.current = true;
        return;
      }
      refreshInFlight.current = true;
      try {
        const items = await fetchKitchenOrders();
        if (active) {
          setOrders(items);
          setError(null);
          if (selectedId) {
            const stillActive = items.some((order) => order.id === selectedId);
            if (stillActive) {
              try {
                const latestDetail = await getOrderDetails(selectedId);
                if (active) setDetail(latestDetail);
              } catch {
                // Keep the last successfully loaded detail during a transient refresh failure.
              }
            } else if (active) {
              setSelectedId(null);
              setDetail(null);
            }
          }
        }
      } catch (cause) {
        if (active && showError) {
          setError(cause instanceof ApiError ? cause.message : cause instanceof Error ? cause.message : "دریافت صف آشپزخانه انجام نشد.");
        }
      } finally {
        refreshInFlight.current = false;
        if (active) {
          setLoading(false);
          if (refreshQueued.current) {
            refreshQueued.current = false;
            void load(true);
          }
        }
      }
    };
    void load(true);
    const intervalId = window.setInterval(() => { void load(true); }, 15000);
    return () => {
      active = false;
      window.clearInterval(intervalId);
    };
  }, [selectedId]);

  useEffect(() => {
    if (!selectedId) return;
    let active = true;
    setDetailLoading(true);
    getOrderDetails(selectedId)
      .then((result) => { if (active) setDetail(result); })
      .catch((cause: unknown) => {
        if (active) {
          setDetail(null);
          setActionError(cause instanceof ApiError ? cause.message : "دریافت اقلام سفارش انجام نشد.");
        }
      })
      .finally(() => { if (active) setDetailLoading(false); });
    return () => { active = false; };
  }, [selectedId]);

  const visibleOrders = useMemo(
    () => orders.filter((order) => statusFilter === "all" || order.order_status === statusFilter),
    [orders, statusFilter],
  );
  const activeCount = orders.filter((order) => order.order_status === "accepted" || order.order_status === "preparing").length;
  const preparingCount = orders.filter((order) => order.order_status === "preparing").length;
  const readyCount = orders.filter((order) => order.order_status === "ready").length;

  async function refreshOrders() {
    if (refreshInFlight.current) {
      refreshQueued.current = true;
      return;
    }
    setRefreshing(true);
    setError(null);
    refreshInFlight.current = true;
    try {
      const latest = await fetchKitchenOrders();
      setOrders(latest);
      if (selectedId && latest.some((order) => order.id === selectedId)) {
        try { setDetail(await getOrderDetails(selectedId)); } catch { /* retain last detail */ }
      } else if (selectedId) {
        setSelectedId(null);
        setDetail(null);
      }
    } catch (cause) {
      setError(cause instanceof ApiError ? cause.message : cause instanceof Error ? cause.message : "بروزرسانی صف آشپزخانه انجام نشد.");
    } finally {
      refreshInFlight.current = false;
      setRefreshing(false);
      if (refreshQueued.current) {
        refreshQueued.current = false;
        void refreshOrders();
      }
    }
  }

  async function advanceOrder(order: OrderSummary) {
    const nextStatus = order.order_status === "accepted" ? "preparing" : order.order_status === "preparing" ? "ready" : null;
    if (!nextStatus || transitioningId) return;
    setTransitioningId(order.id);
    setActionError(null);
    try {
      await transitionKitchenOrder(order.id, nextStatus);
      await refreshOrders();
    } catch (cause) {
      setActionError(cause instanceof ApiError ? cause.message : cause instanceof Error ? cause.message : "تغییر وضعیت آماده‌سازی انجام نشد.");
    } finally {
      setTransitioningId(null);
    }
  }

  function toggleDetails(orderId: string) {
    setActionError(null);
    if (selectedId === orderId) {
      setSelectedId(null);
      setDetail(null);
      return;
    }
    setDetail(null);
    setSelectedId(orderId);
  }

  return (
    <div className="page-stack kitchen-workspace">
      <div className="kitchen-heading">
        <div><span className="ui-eyebrow">KITCHEN DISPLAY SYSTEM</span><h1>آشپزخانه و آماده‌سازی</h1><p>صف زنده سفارش‌های پذیرفته‌شده از مرکز سفارش‌ها</p></div>
        <div className="kitchen-heading-actions">
          <StatusBadge variant="success">متصل به Backend</StatusBadge>
          <Button size="sm" variant="secondary" onClick={() => { void refreshOrders(); }} disabled={loading || refreshing}>
            <RefreshCw size={14} /> {refreshing ? "در حال بروزرسانی..." : "بروزرسانی"}
          </Button>
        </div>
      </div>
      <Notice variant="info">تغییر وضعیت در سرور و با کنترل دسترسی انجام می‌شود. آشپزخانه فقط می‌تواند سفارش را به «در حال آماده‌سازی» یا «آماده تحویل» منتقل کند؛ تکمیل نهایی در مرکز سفارش‌ها انجام می‌شود.</Notice>
      {error ? <Notice variant="warning">{error}</Notice> : null}
      {actionError ? <Notice variant="warning">{actionError}</Notice> : null}
      <div className="kitchen-metrics">
        <Surface padding="sm"><span>سفارش‌های فعال</span><strong>{money(activeCount)}</strong></Surface>
        <Surface padding="sm"><span>در حال آماده‌سازی</span><strong>{money(preparingCount)}</strong></Surface>
        <Surface padding="sm"><span>آماده تحویل</span><strong>{money(readyCount)}</strong></Surface>
      </div>
      <div className="kitchen-toolbar">
        <div><h2><ChefHat size={18} /> صف آماده‌سازی</h2><p>سفارش‌های پذیرفته‌شده و در جریان</p></div>
        <div className="kitchen-stations" role="group" aria-label="فیلتر وضعیت سفارش">
          {([
            ["all", "همه"],
            ["accepted", "در انتظار شروع"],
            ["preparing", "در حال آماده‌سازی"],
            ["ready", "آماده تحویل"],
          ] as const).map(([value, label]) => (
            <button type="button" key={value} className={statusFilter === value ? "is-active" : ""}
              aria-pressed={statusFilter === value} onClick={() => setStatusFilter(value)}>{label}</button>
          ))}
        </div>
      </div>
      {loading ? <Surface padding="lg"><div className="kitchen-empty"><ChefHat size={26} /><strong>در حال دریافت سفارش‌ها...</strong></div></Surface> : visibleOrders.length ? (
        <div className="kitchen-ticket-grid">
          {visibleOrders.map((order) => {
            const isSelected = selectedId === order.id;
            const isReady = order.order_status === "ready";
            const nextLabel = order.order_status === "accepted" ? "شروع آماده‌سازی" : order.order_status === "preparing" ? "علامت‌گذاری آماده" : "";
            return (
              <Surface padding="md" className={isReady ? "kitchen-ticket" : "kitchen-ticket is-active"} key={order.id}>
                <div className="kitchen-ticket-head">
                  <div><strong>#{money(order.order_number)}</strong><span>{channelLabels[order.source_channel] ?? order.source_channel} · {fulfillmentLabels[order.fulfillment_method ?? ""] ?? "نوع دریافت نامشخص"}</span></div>
                  <div className="kitchen-ticket-time"><Clock3 size={13} /><span>{dateTime(order.created_at, timeZone)}</span></div>
                </div>
                <div className="kitchen-ticket-flags">
                  <StatusBadge variant={isReady ? "success" : order.order_status === "preparing" ? "warning" : "info"}>
                    {statusLabels[order.order_status as keyof typeof statusLabels] ?? order.order_status}
                  </StatusBadge>
                  <StatusBadge variant="neutral">{order.customer?.name ?? "مشتری مهمان"}</StatusBadge>
                </div>
                <div className="kitchen-ticket-summary">
                  <strong>{money(order.total_amount)} تومان</strong>
                  <span>وضعیت پرداخت: {order.payment_status === "paid" ? "پرداخت‌شده" : ["partially_paid", "partial"].includes(order.payment_status) ? "پرداخت بخشی" : order.payment_status === "refunded" ? "مستردشده" : "پرداخت‌نشده"}</span>
                </div>
                {isSelected ? (
                  <div className="kitchen-ticket-items">
                    {detailLoading ? <div className="kitchen-empty">در حال دریافت اقلام سفارش...</div> : detail ? detail.items.map((item, index) => (
                      <article className="kitchen-item" key={`${order.id}-${index}`}>
                        <div className="kitchen-item-main"><strong>{item.name}{item.variant_name ? ` · ${item.variant_name}` : ""} <b>× {item.quantity}</b></strong><span>{money(item.line_total_amount)} تومان</span></div>
                        {item.options.length ? <small>گزینه‌ها: {item.options.map((option) => `${option.group_name}: ${option.name}`).join("، ")}</small> : null}
                      </article>
                    )) : <div className="kitchen-empty">جزئیات اقلام در دسترس نیست.</div>}
                  </div>
                ) : null}
                <div className="kitchen-ticket-actions">
                  <Button size="sm" variant="ghost" onClick={() => toggleDetails(order.id)}>
                    <Eye size={14} /> {isSelected ? "بستن اقلام" : "نمایش اقلام"}
                  </Button>
                  {nextLabel ? (
                    <Button size="sm" variant="primary" disabled={transitioningId !== null} onClick={() => { void advanceOrder(order); }}>
                      {transitioningId === order.id ? "در حال ثبت..." : nextLabel}
                    </Button>
                  ) : <span className="kitchen-ready-note">آماده تحویل؛ تکمیل نهایی در مرکز سفارش‌ها</span>}
                </div>
              </Surface>
            );
          })}
        </div>
      ) : (
        <Surface padding="lg"><div className="kitchen-empty"><ChefHat size={26} /><strong>{orders.length ? "سفارشی در این وضعیت نیست" : "صف آشپزخانه خالی است"}</strong><span>سفارش‌های پذیرفته‌شده پس از تأیید در این صفحه نمایش داده می‌شوند.</span></div></Surface>
      )}
    </div>
  );
}
