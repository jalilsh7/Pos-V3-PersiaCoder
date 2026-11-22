import { useCallback, useEffect, useState } from "react";
import { AlertCircle, CheckCircle2, Plus, RefreshCw, ShieldCheck, Users } from "lucide-react";
import {
  ApiError, createSmsMarketingCampaign, getSmsMarketingCampaign, getSmsMarketingCampaigns,
  prepareSmsMarketingAudience, type SmsMarketingAudiencePreview, type SmsMarketingCampaignDetail,
  type SmsMarketingCampaignSummary,
} from "../../shared/api/client";
import { Button, Notice, PageHeader, StatusBadge, Surface } from "../../shared/ui";
import "./communications.css";

const variableOptions = [
  { key: "customer_name", label: "نام مشتری" },
  { key: "order_number", label: "شماره سفارش" },
  { key: "total_amount", label: "مبلغ سفارش" },
  { key: "created_at", label: "زمان ثبت" },
  { key: "order_status", label: "وضعیت سفارش" },
];
const statusLabels: Record<string, string> = { draft: "پیش‌نویس", prepared: "مخاطبان آماده", paused: "متوقف", cancelled: "لغوشده", completed: "تکمیل‌شده" };
const exclusionLabels: Array<[keyof SmsMarketingAudiencePreview, string]> = [
  ["inactive", "مشتری غیرفعال"], ["no_phone", "شماره معتبر ندارد"], ["no_consent", "رضایت ثبت نشده"],
  ["withdrawn", "رضایت پس گرفته شده"], ["invalidated", "رضایت نامعتبر شده"], ["stale_phone", "شماره پس از رضایت تغییر کرده"],
];

export function SmsMarketingCampaignsWorkspace() {
  const [campaigns, setCampaigns] = useState<SmsMarketingCampaignSummary[]>([]);
  const [selected, setSelected] = useState<SmsMarketingCampaignDetail | null>(null);
  const [preview, setPreview] = useState<SmsMarketingAudiencePreview | null>(null);
  const [title, setTitle] = useState("");
  const [bodyId, setBodyId] = useState("");
  const [keys, setKeys] = useState<string[]>(["customer_name"]);
  const [loading, setLoading] = useState(true);
  const [saving, setSaving] = useState(false);
  const [preparing, setPreparing] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [notice, setNotice] = useState<string | null>(null);

  const refresh = useCallback(async () => {
    setError(null);
    try { setCampaigns(await getSmsMarketingCampaigns()); }
    catch (cause) { setError(cause instanceof ApiError && cause.status === 403 ? "دسترسی مدیریت کمپین پیامکی ندارید." : cause instanceof Error ? cause.message : "دریافت کمپین‌ها انجام نشد."); }
    finally { setLoading(false); }
  }, []);

  useEffect(() => { void refresh(); }, [refresh]);

  async function openCampaign(id: string) {
    setError(null); setNotice(null); setPreview(null);
    try { setSelected(await getSmsMarketingCampaign(id)); }
    catch (cause) { setError(cause instanceof Error ? cause.message : "دریافت جزئیات کمپین انجام نشد."); }
  }

  async function createCampaign() {
    setError(null); setNotice(null);
    const parsed = Number(bodyId);
    if (!title.trim()) { setError("عنوان کمپین را وارد کنید."); return; }
    if (!/^[1-9][0-9]{0,9}$/.test(bodyId) || !Number.isSafeInteger(parsed)) { setError("کد قالب معتبر ملی‌پیامک را وارد کنید."); return; }
    if (keys.length === 0) { setError("حداقل یک متغیر برای قالب انتخاب کنید."); return; }
    setSaving(true);
    try {
      const campaign = await createSmsMarketingCampaign({ title: title.trim(), provider_body_id: parsed, variable_keys: keys });
      setTitle(""); setBodyId(""); setKeys(["customer_name"]); setNotice("پیش‌نویس کمپین ایجاد شد.");
      await refresh(); await openCampaign(campaign.id);
    } catch (cause) { setError(cause instanceof Error ? cause.message : "ایجاد کمپین انجام نشد."); }
    finally { setSaving(false); }
  }

  async function prepareAudience() {
    if (!selected) return;
    setError(null); setNotice(null); setPreparing(true);
    try {
      const result = await prepareSmsMarketingAudience(selected.id);
      setPreview(result.campaign.audience ?? null);
      await openCampaign(selected.id);
      setNotice("فهرست مخاطبان واجد شرایط آماده شد. هیچ پیامکی ارسال نشده است.");
      await refresh();
    } catch (cause) { setError(cause instanceof Error ? cause.message : "آماده‌سازی مخاطبان انجام نشد."); }
    finally { setPreparing(false); }
  }

  return <div className="page-stack">
    <PageHeader eyebrow="COMMUNICATIONS" title="کمپین پیامکی تبلیغاتی" description="ساخت پیش‌نویس و بررسی مخاطبان دارای رضایت معتبر" action={<Button variant="secondary" onClick={() => { setLoading(true); void refresh(); }}><RefreshCw size={15}/>بروزرسانی</Button>}/>
    <Notice variant="warning">این بخش فعلاً فقط پیش‌نویس و فهرست مخاطبان مجاز را آماده می‌کند؛ ارسال پیامک تبلیغاتی غیرفعال است. رضایت و شماره باید پیش از ارسال نیز دوباره بررسی شوند.</Notice>
    <div className="sms-marketing-layout">
      <Surface padding="md" className="sms-outbox-panel">
        <div className="section-heading"><div><h2>کمپین جدید</h2><p>متن پیام در پنل ملی‌پیامک مدیریت می‌شود.</p></div><Plus size={19}/></div>
        <label className="sms-template-field">عنوان کمپین<input value={title} maxLength={160} onChange={e=>setTitle(e.target.value)} placeholder="مثلاً اطلاع‌رسانی جشنواره پاییز"/></label>
        <label className="sms-template-field">کد قالب تأییدشده ملی‌پیامک<input inputMode="numeric" value={bodyId} onChange={e=>setBodyId(e.target.value.replace(/\D/g,""))} placeholder="کد قالب"/></label>
        <div><strong>ترتیب متغیرهای قالب</strong><p className="muted-note">انتخاب و ترتیب باید دقیقاً با متغیرهای قالب تأییدشده در پنل ارائه‌دهنده یکسان باشد.</p>
          <div className="sms-template-variable-list">{variableOptions.map(v=><label className="sms-template-variable" key={v.key}><input type="checkbox" checked={keys.includes(v.key)} onChange={e=>setKeys(old=>e.target.checked?[...old,v.key]:old.filter(k=>k!==v.key))}/><span><strong>{v.label}</strong><small>{v.key}</small></span>{keys.includes(v.key)?<b>ترتیب {keys.indexOf(v.key)+1}</b>:null}</label>)}</div>
        </div>
        <Button onClick={createCampaign} disabled={saving}><Plus size={16}/>{saving?"در حال ایجاد…":"ایجاد پیش‌نویس"}</Button>
      </Surface>
      <Surface padding="md" className="sms-outbox-panel">
        <div className="section-heading"><div><h2>کمپین‌های ثبت‌شده</h2><p>اطلاعات محدود به کسب‌وکار فعال است.</p></div><Users size={19}/></div>
        {loading?<p>در حال دریافت…</p>:campaigns.length===0?<div className="sms-outbox-empty"><strong>هنوز کمپینی ثبت نشده است.</strong><span>از فرم کنار صفحه یک پیش‌نویس بسازید.</span></div>:<div className="sms-outbox-list">{campaigns.map(c=><button type="button" className="sms-marketing-campaign-row" key={c.id} onClick={()=>void openCampaign(c.id)}><span><strong>{c.title}</strong><small>قالب {c.provider_body_id} · {new Date(c.created_at).toLocaleDateString("fa-IR")}</small></span><span><StatusBadge variant={c.status==="prepared"?"success":"warning"}>{statusLabels[c.status]??c.status}</StatusBadge><small>{c.eligible_count} مخاطب آماده</small></span></button>)}</div>}
      </Surface>
    </div>
    {selected?<Surface padding="md" className="sms-outbox-panel">
      <div className="section-heading"><div><h2>{selected.title}</h2><p>کد قالب {selected.provider_body_id} · شناسه {selected.id}</p></div><StatusBadge variant={selected.status==="prepared"?"success":"warning"}>{statusLabels[selected.status]??selected.status}</StatusBadge></div>
      <div className="sms-marketing-metrics"><div><strong>{selected.recipients.eligible}</strong><span>مخاطب واجد شرایط</span></div><div><strong>{selected.recipients.sent}</strong><span>ارسال‌شده</span></div><div><strong>{selected.recipients.failed}</strong><span>ناموفق</span></div><div><strong>{selected.recipients.unknown}</strong><span>وضعیت نامشخص</span></div></div>
      {preview?<div className="sms-marketing-preview"><h3>نتیجه ارزیابی مخاطبان</h3><p>کل مشتریان بررسی‌شده: <strong>{preview.total_customers}</strong> · واجد شرایط: <strong>{preview.eligible}</strong></p>{exclusionLabels.map(([key,label])=><div key={key}><span>{label}</span><strong>{preview[key]}</strong></div>)}</div>:null}
      {selected.status==="draft"?<Button onClick={prepareAudience} disabled={preparing}><ShieldCheck size={16}/>{preparing?"در حال بررسی رضایت‌ها…":"بررسی رضایت و آماده‌سازی مخاطبان"}</Button>:<Notice variant="success"><CheckCircle2 size={16}/>فهرست مخاطبان این کمپین آماده شده است؛ برای جلوگیری از تغییر ناخواسته، snapshot قابل بازسازی نیست.</Notice>}
    </Surface>:null}
    {error?<p className="sms-outbox-error" role="alert"><AlertCircle size={16}/>{error}</p>:null}
    {notice?<Notice variant="success">{notice}</Notice>:null}
  </div>;
}
