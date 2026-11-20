import { useEffect, useState } from "react";
import { MessageSquare, RefreshCw } from "lucide-react";
import { ApiError, getSmsOutboxMessages, type SmsOutboxMessage } from "../../shared/api/client";
import { Button, Notice, PageHeader, StatusBadge, Surface } from "../../shared/ui";
import "./communications.css";

const statusOptions = [
  { value: "all", label: "همه وضعیت‌ها" },
  { value: "queued", label: "در صف" },
  { value: "processing", label: "در حال ارسال" },
  { value: "accepted", label: "پذیرفته‌شده توسط سرویس" },
  { value: "sent_to_carrier", label: "ارسال به اپراتور" },
  { value: "delivered", label: "تحویل‌شده" },
  { value: "failed", label: "ناموفق" },
  { value: "rejected", label: "ردشده" },
  { value: "unknown", label: "نامشخص" },
  { value: "cancelled", label: "لغوشده" },
];
const statusMeta: Record<string, { label: string; variant: "neutral" | "success" | "warning" | "danger" | "info" }> = {
  queued: { label: "در صف", variant: "info" },
  processing: { label: "در حال ارسال", variant: "warning" },
  accepted: { label: "پذیرفته‌شده", variant: "info" },
  sent_to_carrier: { label: "ارسال به اپراتور", variant: "info" },
  delivered: { label: "تحویل‌شده", variant: "success" },
  failed: { label: "ناموفق", variant: "danger" },
  rejected: { label: "ردشده", variant: "danger" },
  unknown: { label: "نامشخص", variant: "warning" },
  cancelled: { label: "لغوشده", variant: "neutral" },
};
const dateLabel = (value: string) => {
  const date = new Date(value.includes("Z") ? value : `${value.replace(" ", "T")}Z`);
  return Number.isNaN(date.getTime()) ? value : new Intl.DateTimeFormat("fa-IR", { dateStyle: "short", timeStyle: "short" }).format(date);
};

export function SmsOutboxWorkspace() {
  const [status, setStatus] = useState("all");
  const [messages, setMessages] = useState<SmsOutboxMessage[]>([]);
  const [cursor, setCursor] = useState<string | null>(null);
  const [loading, setLoading] = useState(true);
  const [loadingMore, setLoadingMore] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [refreshKey, setRefreshKey] = useState(0);

  useEffect(() => {
    let active = true;
    setLoading(true);
    setError(null);
    getSmsOutboxMessages({ status, limit: 25 }).then((page) => {
      if (!active) return;
      setMessages(page.messages);
      setCursor(page.next_cursor);
    }).catch((cause: unknown) => {
      if (!active) return;
      const apiError = cause instanceof ApiError ? cause : null;
      setError(apiError?.status === 403
        ? "دسترسی مشاهده گزارش پیامک برای نقش کاربری شما فعال نیست."
        : apiError?.message || "دریافت گزارش پیامک انجام نشد.");
      setMessages([]);
      setCursor(null);
    }).finally(() => { if (active) setLoading(false); });
    return () => { active = false; };
  }, [status, refreshKey]);

  async function loadMore() {
    if (!cursor || loadingMore) return;
    setLoadingMore(true);
    setError(null);
    try {
      const page = await getSmsOutboxMessages({ status, limit: 25, cursor });
      setMessages((current) => [...current, ...page.messages]);
      setCursor(page.next_cursor);
    } catch (cause) {
      setError(cause instanceof ApiError ? cause.message : "دریافت ادامه گزارش انجام نشد.");
    } finally {
      setLoadingMore(false);
    }
  }

  return (
    <div className="page-stack">
      <PageHeader
        eyebrow="COMMUNICATIONS"
        title="صف و گزارش پیامک"
        description="پیگیری وضعیت پیامک‌های تراکنشی و تاریخچه تلاش‌های ارسال"
        action={<Button variant="secondary" onClick={() => setRefreshKey((value) => value + 1)} disabled={loading}><RefreshCw size={16} /> تازه‌سازی</Button>}
      />
      <Notice variant="info">این صفحه فقط گزارش می‌دهد. شماره مقصد و متن پیام برای حفظ حریم خصوصی نمایش داده نمی‌شوند. پیام با وضعیت «نامشخص» به‌صورت خودکار دوباره ارسال نمی‌شود.</Notice>
      <Surface padding="md" className="sms-outbox-panel">
        <div className="sms-outbox-toolbar">
          <label htmlFor="sms-status-filter">وضعیت پیام</label>
          <select id="sms-status-filter" value={status} onChange={(event) => setStatus(event.target.value)}>
            {statusOptions.map((option) => <option key={option.value} value={option.value}>{option.label}</option>)}
          </select>
          <span className="sms-outbox-count">{messages.length.toLocaleString("fa-IR")} پیام در این فهرست</span>
        </div>
        {error ? <div className="sms-outbox-error" role="alert">{error}</div> : null}
        {loading ? <div className="sms-outbox-empty">در حال دریافت گزارش…</div> : messages.length === 0 ? (
          <div className="sms-outbox-empty"><MessageSquare size={24} /><strong>پیامی برای نمایش وجود ندارد</strong><span>با تغییر فیلتر یا پس از ثبت رویدادهای مجاز، گزارش این بخش نمایش داده می‌شود.</span></div>
        ) : (
          <div className="sms-outbox-list">
            {messages.map((message) => {
              const meta = statusMeta[message.status] ?? { label: message.status, variant: "neutral" as const };
              return (
                <article className="sms-outbox-item" key={message.id}>
                  <div className="sms-outbox-item__main">
                    <div className="sms-outbox-item__title">
                      <strong>{message.message_type === "transactional" ? "پیام تراکنشی" : "تأیید هویت"}</strong>
                      <StatusBadge variant={meta.variant}>{meta.label}</StatusBadge>
                    </div>
                    <div className="sms-outbox-item__meta">
                      <span>ارائه‌دهنده: {message.provider}</span>
                      <span>تلاش‌ها: {message.attempt_count.toLocaleString("fa-IR")}</span>
                      <span>ثبت: {dateLabel(message.created_at)}</span>
                      {message.delivered_at ? <span>تحویل: {dateLabel(message.delivered_at)}</span> : null}
                    </div>
                    {message.failure_code ? <small className="sms-outbox-failure">کد خطا: {message.failure_code}</small> : null}
                  </div>
                  <details className="sms-outbox-details">
                    <summary>جزئیات تلاش‌ها ({message.attempts.length.toLocaleString("fa-IR")})</summary>
                    {message.attempts.length === 0 ? <p>هنوز تلاش ثبت‌شده‌ای وجود ندارد.</p> : (
                      <ol>
                        {message.attempts.map((attempt, index) => (
                          <li key={`${message.id}-${attempt.number}-${attempt.type}-${index}`}>
                            <span>#{attempt.number.toLocaleString("fa-IR")} · {attempt.type === "send" ? "ارسال" : "بررسی تحویل"} · {attempt.outcome}</span>
                            <small>{dateLabel(attempt.attempted_at)}{attempt.error_code ? ` · ${attempt.error_code}` : ""}</small>
                          </li>
                        ))}
                      </ol>
                    )}
                  </details>
                </article>
              );
            })}
          </div>
        )}
        {cursor && !loading ? <div className="sms-outbox-more"><Button onClick={loadMore} disabled={loadingMore}>{loadingMore ? "در حال دریافت…" : "نمایش پیام‌های بیشتر"}</Button></div> : null}
      </Surface>
    </div>
  );
}
