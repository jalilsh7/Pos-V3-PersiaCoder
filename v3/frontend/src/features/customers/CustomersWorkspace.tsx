import { useEffect, useMemo, useRef, useState } from "react";
import { CirclePlus, Clock3, Search, ShoppingBag, UserRound, X } from "lucide-react";
import {
  ApiError,
  createCustomer,
  getCustomerDetails,
  getCustomers,
  recordCustomerMarketingSmsConsent,
  updateCustomer,
  type CustomerDetail,
  type CustomerSummary,
} from "../../shared/api/client";
import { Button, Notice, StatusBadge, Surface, TextField } from "../../shared/ui";
import "./customers.css";

const money = (value: number) => new Intl.NumberFormat("fa-IR").format(value);
const dateTime = (value: string | null) => {
  if (!value) return "بدون سفارش";
  const date = new Date(value.includes("Z") || /[+-]\\d\\d:\\d\\d$/.test(value) ? value : `${value.replace(" ", "T")}Z`);
  if (Number.isNaN(date.getTime())) return value;
  return new Intl.DateTimeFormat("fa-IR", { dateStyle: "short", timeStyle: "short" }).format(date);
};
const orderStatusLabel: Record<string, string> = {
  draft: "پیش‌نویس", awaiting_payment: "در انتظار پرداخت", accepted: "پذیرفته‌شده",
  preparing: "در حال آماده‌سازی", ready: "آماده تحویل", completed: "تکمیل‌شده",
  cancelled: "لغوشده", rejected: "ردشده",
};
const paymentStatusLabel: Record<string, string> = {
  unpaid: "پرداخت‌نشده", partially_paid: "پرداخت جزئی", paid: "تسویه‌شده",
  partially_refunded: "بازپرداخت جزئی", refunded: "مستردشده", failed: "ناموفق",
  pending: "در انتظار تأیید",
};

export function CustomersWorkspace() {
  const [customers, setCustomers] = useState<CustomerSummary[]>([]);
  const [query, setQuery] = useState("");
  const [status, setStatus] = useState<"all" | "active" | "inactive">("all");
  const [selectedId, setSelectedId] = useState<string | null>(null);
  const [detail, setDetail] = useState<CustomerDetail | null>(null);
  const [loading, setLoading] = useState(true);
  const [loadingMore, setLoadingMore] = useState(false);
  const [nextCursor, setNextCursor] = useState<string | null>(null);
  const [detailLoading, setDetailLoading] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [detailError, setDetailError] = useState<string | null>(null);
  const [showCreate, setShowCreate] = useState(false);
  const [name, setName] = useState("");
  const [phone, setPhone] = useState("");
  const [notes, setNotes] = useState("");
  const [saving, setSaving] = useState(false);
  const [actionError, setActionError] = useState<string | null>(null);
  const [editing, setEditing] = useState(false);
  const [editName, setEditName] = useState("");
  const [editPhone, setEditPhone] = useState("");
  const [editNotes, setEditNotes] = useState("");
  const [editActive, setEditActive] = useState(true);
  const [consentNoticeVersion, setConsentNoticeVersion] = useState("marketing-sms-v1");
  const [consentEvidenceRef, setConsentEvidenceRef] = useState("");
  const [consentSaving, setConsentSaving] = useState(false);
  const consentAttempt = useRef<{ signature: string; key: string } | null>(null);
  const createAttempt = useRef<{ signature: string; key: string } | null>(null);
  const updateAttempt = useRef<{ signature: string; key: string } | null>(null);
  const listRequestVersion = useRef(0);

  async function refreshCustomers() {
    const version = ++listRequestVersion.current;
    setLoading(true);
    setLoadingMore(false);
    setNextCursor(null);
    setError(null);
    try {
      const result = await getCustomers({ query, status, limit: 50 });
      if (version !== listRequestVersion.current) return;
      setCustomers(result.customers);
      setNextCursor(result.next_cursor);
      if (selectedId && !result.customers.some((customer) => customer.id === selectedId)) {
        setSelectedId(null);
        setDetail(null);
      }
    } catch (cause) {
      if (version !== listRequestVersion.current) return;
      setError(cause instanceof ApiError ? cause.message : "دریافت فهرست مشتریان انجام نشد.");
      setCustomers([]);
    } finally {
      if (version === listRequestVersion.current) setLoading(false);
    }
  }

  useEffect(() => {
    let active = true;
    const version = ++listRequestVersion.current;
    setLoading(true);
    setLoadingMore(false);
    setNextCursor(null);
    getCustomers({ query, status, limit: 50 })
      .then((result) => {
        if (!active || version !== listRequestVersion.current) return;
        setCustomers(result.customers);
        setNextCursor(result.next_cursor);
        if (selectedId && !result.customers.some((customer) => customer.id === selectedId)) {
          setSelectedId(null);
          setDetail(null);
        }
        setError(null);
      })
      .catch((cause: unknown) => {
        if (!active || version !== listRequestVersion.current) return;
        setError(cause instanceof ApiError ? cause.message : "دریافت فهرست مشتریان انجام نشد.");
        setCustomers([]);
      })
      .finally(() => { if (active && version === listRequestVersion.current) setLoading(false); });
    return () => { active = false; };
  }, [query, status]);

  async function loadMoreCustomers() {
    if (!nextCursor || loadingMore) return;
    const version = listRequestVersion.current;
    setLoadingMore(true);
    try {
      const result = await getCustomers({ query, status, limit: 50, cursor: nextCursor });
      if (version !== listRequestVersion.current) return;
      setCustomers((current) => {
        const existing = new Set(current.map((customer) => customer.id));
        return [...current, ...result.customers.filter((customer) => !existing.has(customer.id))];
      });
      setNextCursor(result.next_cursor);
      setError(null);
    } catch (cause) {
      if (version !== listRequestVersion.current) return;
      setError(cause instanceof ApiError ? cause.message : "بارگذاری ادامه فهرست مشتریان انجام نشد.");
    } finally {
      if (version === listRequestVersion.current) setLoadingMore(false);
    }
  }

  useEffect(() => {
    if (!selectedId) {
      setDetail(null);
      setEditing(false);
      return;
    }
    let active = true;
    setDetailLoading(true);
    setDetailError(null);
    getCustomerDetails(selectedId)
      .then((result) => {
        if (!active) return;
        setDetail(result);
        setEditName(result.customer.name);
        setEditPhone(result.customer.phone);
        setEditNotes(result.customer.notes);
        setEditActive(result.customer.is_active);
      })
      .catch((cause: unknown) => {
        if (!active) return;
        setDetail(null);
        setDetailError(cause instanceof ApiError ? cause.message : "دریافت پروفایل مشتری انجام نشد.");
      })
      .finally(() => { if (active) setDetailLoading(false); });
    return () => { active = false; };
  }, [selectedId]);

  async function submitConsent(decision: "granted" | "withdrawn") {
    if (!selectedId || consentSaving) return;
    const payload = decision === "granted"
      ? { decision, notice_version: consentNoticeVersion.trim(), evidence_ref: consentEvidenceRef.trim() }
      : { decision };
    if (decision === "granted" && (!payload.notice_version || !payload.evidence_ref)) {
      setActionError("برای ثبت رضایت، نسخه متن اطلاع‌رسانی و مرجع/شرح مدرک رضایت الزامی است.");
      return;
    }
    const signature = JSON.stringify([selectedId, payload]);
    if (!consentAttempt.current || consentAttempt.current.signature !== signature) {
      consentAttempt.current = { signature, key: globalThis.crypto.randomUUID() };
    }
    setConsentSaving(true);
    setActionError(null);
    try {
      await recordCustomerMarketingSmsConsent(selectedId, payload, consentAttempt.current.key);
      consentAttempt.current = null;
      setDetail(await getCustomerDetails(selectedId));
      if (decision === "granted") setConsentEvidenceRef("");
    } catch (cause) {
      setActionError(cause instanceof ApiError ? cause.message : cause instanceof Error ? cause.message : "ثبت وضعیت رضایت پیامکی انجام نشد.");
    } finally {
      setConsentSaving(false);
    }
  }

  const selected = detail?.customer ?? null;
  const visible = useMemo(() => customers, [customers]);
  const activeCount = customers.filter((customer) => customer.is_active).length;
  const orderCount = customers.reduce((sum, customer) => sum + customer.order_count, 0);

  async function submitCreate() {
    if (saving || !name.trim()) return;
    const payload = { name: name.trim(), phone: phone.trim(), notes: notes.trim() };
    const signature = JSON.stringify(payload);
    if (!createAttempt.current || createAttempt.current.signature !== signature) {
      createAttempt.current = { signature, key: globalThis.crypto.randomUUID() };
    }
    setSaving(true);
    setActionError(null);
    try {
      const result = await createCustomer(payload, createAttempt.current.key);
      createAttempt.current = null;
      setShowCreate(false);
      setName(""); setPhone(""); setNotes("");
      setSelectedId(result.customer.id);
      setStatus("all");
      setQuery("");
      await refreshCustomers();
    } catch (cause) {
      setActionError(cause instanceof ApiError ? cause.message : cause instanceof Error ? cause.message : "ثبت مشتری انجام نشد.");
    } finally {
      setSaving(false);
    }
  }

  async function submitUpdate() {
    if (!selectedId || saving || !editName.trim()) return;
    const payload = {
      name: editName.trim(),
      phone: editPhone.trim(),
      notes: editNotes.trim(),
      is_active: editActive,
    };
    const signature = JSON.stringify([selectedId, payload]);
    if (!updateAttempt.current || updateAttempt.current.signature !== signature) {
      updateAttempt.current = { signature, key: globalThis.crypto.randomUUID() };
    }
    setSaving(true);
    setActionError(null);
    try {
      const result = await updateCustomer(selectedId, payload, updateAttempt.current.key);
      updateAttempt.current = null;
      setDetail(result);
      setEditing(false);
      await refreshCustomers();
    } catch (cause) {
      setActionError(cause instanceof ApiError ? cause.message : cause instanceof Error ? cause.message : "ذخیره تغییرات مشتری انجام نشد.");
    } finally {
      setSaving(false);
    }
  }

  return (
    <div className="page-stack customers-workspace">
      <div className="customers-heading">
        <div><span className="ui-eyebrow">CUSTOMER RELATIONSHIP</span><h1>مشتریان</h1><p>پروفایل واقعی، سوابق سفارش و ارزش خرید ثبت‌شده</p></div>
        <Button variant="primary" onClick={() => { setShowCreate((value) => !value); setActionError(null); }}>
          <CirclePlus size={15} /> مشتری جدید
        </Button>
      </div>
      {error ? <Notice variant="warning">{error}</Notice> : null}
      {actionError ? <Notice variant="warning">{actionError}</Notice> : null}
      <div className="customers-metrics">
        <Surface padding="sm"><span>مشتریان بارگذاری‌شده</span><strong>{money(customers.length)}</strong></Surface>
        <Surface padding="sm"><span>فعال در فهرست</span><strong>{money(activeCount)}</strong></Surface>
        <Surface padding="sm"><span>سفارش‌های فهرست</span><strong>{money(orderCount)}</strong></Surface>
      </div>
      {showCreate ? (
        <Surface padding="md" className="customers-create">
          <div className="customers-create-title"><strong>ثبت مشتری جدید</strong><button type="button" onClick={() => setShowCreate(false)} aria-label="بستن"><X size={16} /></button></div>
          <div className="customers-create-fields">
            <TextField label="نام مشتری" value={name} onChange={(event) => setName(event.target.value)} placeholder="نام و نام خانوادگی" />
            <TextField label="شماره همراه (اختیاری)" value={phone} onChange={(event) => setPhone(event.target.value)} placeholder="09xxxxxxxxx" />
            <TextField label="یادداشت (اختیاری)" value={notes} onChange={(event) => setNotes(event.target.value)} placeholder="یادداشت داخلی درباره مشتری" />
          </div>
          <div className="customers-create-footer">
            <span>شماره موبایل ایران در سرور نرمال‌سازی و از تکرار آن جلوگیری می‌شود.</span>
            <Button variant="primary" onClick={() => { void submitCreate(); }} disabled={saving || !name.trim()}>
              {saving ? "در حال ثبت..." : "ثبت مشتری"}
            </Button>
          </div>
        </Surface>
      ) : null}
      <div className="customers-layout">
        <Surface padding="md" className="customers-list">
          <div className="customers-list-title"><div><h2><UserRound size={17} /> فهرست مشتریان</h2><p>{money(visible.length)} مورد بارگذاری‌شده</p></div></div>
          <label className="customers-search">
            <Search size={16} />
            <input value={query} onChange={(event) => setQuery(event.target.value)} placeholder="نام یا شماره همراه" />
            <button type="button" onClick={() => setQuery("")} aria-label="پاک کردن جست‌وجو" disabled={!query}><X size={14} /></button>
          </label>
          <div className="customers-filters">
            {([["all", "همه"], ["active", "فعال"], ["inactive", "غیرفعال"]] as const).map(([key, label]) => (
              <button key={key} type="button" className={status === key ? "is-active" : ""} onClick={() => setStatus(key)}>{label}</button>
            ))}
          </div>
          <div className="customers-items">
            {loading ? <div className="customers-empty">در حال دریافت مشتریان...</div> : visible.map((customer) => (
              <button type="button" key={customer.id} className={`customer-row ${selectedId === customer.id ? "is-selected" : ""}`} onClick={() => setSelectedId(customer.id)}>
                <span className="customer-avatar">{customer.name.slice(0, 1)}</span>
                <span className="customer-row-copy"><strong>{customer.name}</strong><small>{customer.phone || "شماره همراه ثبت نشده"}</small></span>
                <span className="customer-row-meta"><strong>{money(customer.order_count)}</strong><small>سفارش</small></span>
              </button>
            ))}
            {!loading && visible.length === 0 ? <div className="customers-empty">مشتری‌ای با این مشخصات پیدا نشد.</div> : null}
          </div>
          {nextCursor ? (
            <div className="customers-load-more">
              <Button variant="secondary" onClick={() => { void loadMoreCustomers(); }} disabled={loadingMore}>
                {loadingMore ? "در حال بارگذاری..." : "بارگذاری مشتریان بیشتر"}
              </Button>
            </div>
          ) : null}
        </Surface>
        <Surface padding="md" className="customers-detail">
          {detailLoading ? <Notice variant="info">در حال دریافت پروفایل مشتری...</Notice> : null}
          {detailError ? <Notice variant="warning">{detailError}</Notice> : null}
          {selected && detail ? (
            <>
              <div className="customers-profile-head">
                <span className="customer-avatar large">{selected.name.slice(0, 1)}</span>
                <div><h2>{selected.name}</h2><p>{selected.phone || "شماره همراه ثبت نشده"}</p>
                  <StatusBadge variant={selected.is_active ? "success" : "warning"}>{selected.is_active ? "فعال" : "غیرفعال"}</StatusBadge>
                </div>
              </div>
              <div className="customers-profile-stats">
                <div><span>تعداد سفارش</span><strong>{money(detail.stats.order_count)}</strong></div>
                <div><span>خرید خالص ثبت‌شده</span><strong>{money(detail.stats.total_spent)} <small>تومان</small></strong></div>
                <div><span>آخرین سفارش</span><strong>{dateTime(detail.stats.last_order_at)}</strong></div>
              </div>
              <section className="customers-note" aria-label="رضایت پیامک تبلیغاتی">
                <strong>رضایت دریافت پیامک تبلیغاتی</strong>
                <p>
                  وضعیت فعلی: {detail.marketing_consent.decision === "granted" ? "رضایت ثبت‌شده" : detail.marketing_consent.decision === "withdrawn" ? "انصراف ثبت‌شده" : detail.marketing_consent.decision === "stale" ? "شماره تغییر کرده؛ رضایت نیازمند ثبت مجدد" : "نامشخص / بدون رضایت معتبر"}
                  {detail.marketing_consent.recorded_at ? ` · ${dateTime(detail.marketing_consent.recorded_at)}` : ""}
                </p>
                <p>ثبت رضایت فقط پس از اعلام صریح مشتری انجام شود؛ وجود شماره همراه به‌تنهایی رضایت محسوب نمی‌شود. با تغییر شماره، رضایت قبلی برای شماره جدید معتبر نیست.</p>
                <div className="customers-create-fields">
                  <TextField label="نسخه متن اطلاع‌رسانی" value={consentNoticeVersion} onChange={(event) => setConsentNoticeVersion(event.target.value)} />
                  <TextField label="مرجع یا شرح مدرک رضایت (الزامی)" value={consentEvidenceRef} onChange={(event) => setConsentEvidenceRef(event.target.value)} placeholder="مثلاً فرم امضاشده / شناسه ثبت رضایت" />
                </div>
                <div className="customers-detail-actions">
                  <Button variant="primary" disabled={consentSaving || !selected.phone || !consentNoticeVersion.trim() || !consentEvidenceRef.trim()} onClick={() => { void submitConsent("granted"); }}>
                    {consentSaving ? "در حال ثبت..." : "ثبت رضایت اعلام‌شده"}
                  </Button>
                  <Button variant="secondary" disabled={consentSaving || detail.marketing_consent.decision !== "granted"} onClick={() => { void submitConsent("withdrawn"); }}>
                    ثبت انصراف
                  </Button>
                </div>
                {detail.marketing_consent.history.length > 0 ? (
                  <div className="customers-history">
                    {detail.marketing_consent.history.map((event, index) => (
                      <div className="customers-order" key={`${event.recorded_at}-${index}`}>
                        <div><strong>{event.decision === "granted" ? "رضایت ثبت شد" : "انصراف ثبت شد"}</strong><small>{dateTime(event.recorded_at)} · {event.source}</small></div>
                        <div className="customers-order-total"><small>{event.notice_version || "بدون نسخه متن"}{event.evidence_ref ? ` · ${event.evidence_ref}` : ""}</small></div>
                      </div>
                    ))}
                  </div>
                ) : null}
              </section>
              <div className="customers-profile-stats customers-loyalty-stats">
                <div><span>امتیاز وفاداری</span><strong>{money(detail.loyalty.points_balance)}</strong></div>
                <div><span>اعتبار مشتری</span><strong>{money(detail.loyalty.credit_balance)} <small>تومان</small></strong></div>
                <div><span>مراجعه ثبت‌شده</span><strong>{money(detail.loyalty.visit_count)}</strong></div>
              </div>
              <div className="customers-history-title"><h3>گردش باشگاه مشتریان</h3><span>{money(detail.loyalty.entries.length)} رویداد اخیر</span></div>
              <div className="customers-history">
                {detail.loyalty.entries.map((entry) => (
                  <div className="customers-order" key={entry.event_key}>
                    <div>
                      <strong>{entry.reason || "رویداد وفاداری"}</strong>
                      <small>{dateTime(entry.created_at)}{entry.order_number ? ` · سفارش #${money(entry.order_number)}` : ""}</small>
                    </div>
                    <div className="customers-order-total">
                      {entry.points_delta !== 0 ? <strong>{entry.points_delta > 0 ? "+" : ""}{money(entry.points_delta)} امتیاز</strong> : null}
                      {entry.credit_delta !== 0 ? <strong>{entry.credit_delta > 0 ? "+" : ""}{money(entry.credit_delta)} تومان</strong> : null}
                      {entry.visit_delta !== 0 ? <strong>{entry.visit_delta > 0 ? "+" : ""}{money(entry.visit_delta)} مراجعه</strong> : null}
                    </div>
                  </div>
                ))}
                {detail.loyalty.entries.length === 0 ? <div className="customers-empty">هنوز رویدادی در دفتر وفاداری این مشتری ثبت نشده است.</div> : null}
              </div>
              {editing ? (
                <div className="customers-create customers-edit">
                  <TextField label="نام مشتری" value={editName} onChange={(event) => setEditName(event.target.value)} />
                  <TextField label="شماره همراه" value={editPhone} onChange={(event) => setEditPhone(event.target.value)} />
                  <TextField label="یادداشت" value={editNotes} onChange={(event) => setEditNotes(event.target.value)} />
                  <label className="customers-active-toggle"><input type="checkbox" checked={editActive} onChange={(event) => setEditActive(event.target.checked)} /> مشتری فعال باشد</label>
                  <div className="customers-detail-actions">
                    <Button variant="secondary" disabled={saving} onClick={() => setEditing(false)}>انصراف</Button>
                    <Button variant="primary" disabled={saving || !editName.trim()} onClick={() => { void submitUpdate(); }}>{saving ? "در حال ذخیره..." : "ذخیره تغییرات"}</Button>
                  </div>
                </div>
              ) : (
                <>
                  <div className="customers-note"><strong>یادداشت مشتری</strong><p>{selected.notes || "یادداشتی ثبت نشده است."}</p></div>
                  <div className="customers-detail-actions"><Button variant="secondary" onClick={() => setEditing(true)}>ویرایش اطلاعات</Button></div>
                </>
              )}
              <div className="customers-history-title"><h3><ShoppingBag size={16} /> سوابق سفارش</h3><span>{money(detail.recent_orders.length)} سفارش اخیر</span></div>
              <div className="customers-history">
                {detail.recent_orders.map((order) => (
                  <div className="customers-order" key={order.id}>
                    <div><strong>#{money(order.order_number)}</strong><small>{dateTime(order.created_at)} · {orderStatusLabel[order.order_status] ?? order.order_status}</small></div>
                    <div className="customers-order-total"><strong>{money(order.total_amount)} <small>تومان</small></strong><StatusBadge variant={order.payment_status === "paid" ? "success" : order.payment_status === "refunded" ? "danger" : "warning"}>{paymentStatusLabel[order.payment_status] ?? order.payment_status}</StatusBadge></div>
                  </div>
                ))}
                {detail.recent_orders.length === 0 ? <div className="customers-empty">برای این مشتری هنوز سفارشی ثبت نشده است.</div> : null}
              </div>
              <div className="customers-detail-foot"><span><Clock3 size={14} /> آمار از سفارش‌ها و دفتر پرداخت سرور محاسبه می‌شود</span></div>
            </>
          ) : !detailLoading ? <div className="customers-empty">برای مشاهده پروفایل، یک مشتری را انتخاب کنید.</div> : null}
        </Surface>
      </div>
    </div>
  );
}
