import { useEffect, useMemo, useState } from "react";
import { Clock3, LockKeyhole, Plus, RefreshCw, Wallet } from "lucide-react";
import {
  ApiError,
  closeCashSession,
  createCashRegister,
  getCashRegisters,
  openCashSession,
  reviewCashSession,
  type CashRegisterSummary,
} from "../../shared/api/client";
import { Button, Notice, SelectField, StatusBadge, Surface, TextField } from "../../shared/ui";
import "./finance.css";

const money = (value: number) => new Intl.NumberFormat("fa-IR").format(value);
const parseAmount = (value: string) => {
  const normalized = value.replace(/[۰-۹]/g, (digit) => String("۰۱۲۳۴۵۶۷۸۹".indexOf(digit)))
    .replace(/[٠-٩]/g, (digit) => String("٠١٢٣٤٥٦٧٨٩".indexOf(digit)))
    .replace(/[،,٬\s]/g, "").replace(/[^0-9]/g, "");
  return normalized ? Number(normalized) : 0;
};

export function FinanceWorkspace() {
  const [registers, setRegisters] = useState<CashRegisterSummary[]>([]);
  const [selectedId, setSelectedId] = useState("");
  const [loading, setLoading] = useState(true);
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [notice, setNotice] = useState<string | null>(null);
  const [showCreate, setShowCreate] = useState(false);
  const [registerName, setRegisterName] = useState("صندوق اصلی");
  const [registerCode, setRegisterCode] = useState("MAIN");
  const [openingAmount, setOpeningAmount] = useState("0");
  const [countedAmount, setCountedAmount] = useState("");
  const [reviewNote, setReviewNote] = useState("");

  async function refresh() {
    setLoading(true);
    setError(null);
    try {
      const result = await getCashRegisters();
      setRegisters(result);
      setSelectedId((current) => result.some((item) => item.id === current) ? current : result[0]?.id ?? "");
    } catch (cause) {
      setError(cause instanceof ApiError ? cause.message : "دریافت اطلاعات صندوق‌ها انجام نشد.");
    } finally {
      setLoading(false);
    }
  }

  useEffect(() => {
    void refresh();
  }, []);

  const selected = registers.find((item) => item.id === selectedId) ?? null;
  const activeSession = selected?.active_session ?? null;
  const totalRegisters = registers.length;
  const openRegisters = useMemo(() => registers.filter((item) => item.active_session?.status === "open").length, [registers]);
  const reviewRegisters = useMemo(() => registers.filter((item) => item.active_session?.status === "requires_review").length, [registers]);

  async function addRegister() {
    if (!registerName.trim() || !/^[A-Z0-9][A-Z0-9_-]{1,39}$/.test(registerCode.trim().toUpperCase())) return;
    setBusy(true); setError(null); setNotice(null);
    try {
      const created = await createCashRegister({ code: registerCode, name: registerName });
      await refresh();
      setSelectedId(created.id);
      setShowCreate(false);
      setNotice("صندوق با موفقیت در سرور ایجاد شد.");
    } catch (cause) {
      setError(cause instanceof ApiError ? cause.message : "ایجاد صندوق انجام نشد.");
    } finally { setBusy(false); }
  }

  async function startShift() {
    if (!selected) return;
    const amount = parseAmount(openingAmount);
    if (!Number.isSafeInteger(amount) || amount < 0) return;
    setBusy(true); setError(null); setNotice(null);
    try {
      await openCashSession(selected.id, amount);
      await refresh();
      setNotice("شیفت صندوق در سرور باز شد.");
    } catch (cause) {
      setError(cause instanceof ApiError ? cause.message : "باز کردن شیفت انجام نشد.");
    } finally { setBusy(false); }
  }

  async function finishShift() {
    if (!activeSession) return;
    const amount = parseAmount(countedAmount);
    if (!Number.isSafeInteger(amount) || amount < 0) return;
    setBusy(true); setError(null); setNotice(null);
    try {
      const result = await closeCashSession(activeSession.id, amount);
      await refresh();
      setNotice(`شیفت بسته شد. اختلاف شمارش با مبلغ مورد انتظار: ${money(result.difference_amount)} تومان.`);
      setCountedAmount("");
    } catch (cause) {
      setError(cause instanceof ApiError ? cause.message : "بستن شیفت انجام نشد.");
    } finally { setBusy(false); }
  }

  async function resolveReview() {
    if (!activeSession || !reviewNote.trim()) return;
    setBusy(true); setError(null); setNotice(null);
    try {
      await reviewCashSession(activeSession.id, reviewNote);
      await refresh();
      setReviewNote("");
      setNotice("بررسی شیفت در سرور ثبت شد.");
    } catch (cause) {
      setError(cause instanceof ApiError ? cause.message : "ثبت بررسی شیفت انجام نشد.");
    } finally { setBusy(false); }
  }

  return (
    <div className="page-stack finance-workspace">
      <div className="finance-heading">
        <div><span className="ui-eyebrow">CASH & SHIFT CONTROL</span><h1>مالی و صندوق‌ها</h1><p>مدیریت صندوق‌ها و شیفت‌های واقعی ثبت‌شده در سرور</p></div>
        <Button variant="secondary" onClick={() => void refresh()} disabled={loading || busy}><RefreshCw size={14} /> به‌روزرسانی</Button>
      </div>
      <Notice variant="info">این بخش اکنون به API صندوق V3 متصل است. ثبت دریافت/پرداخت دستی، هزینه‌ها و گزارش مالی در بسته‌های بعدی همین ماژول تکمیل می‌شوند.</Notice>
      {error ? <Notice variant="warning">{error}</Notice> : null}
      {notice ? <Notice variant="info">{notice}</Notice> : null}
      <div className="finance-metrics">
        <Surface padding="sm"><span>تعداد صندوق‌ها</span><strong>{loading ? "…" : money(totalRegisters)}</strong></Surface>
        <Surface padding="sm"><span>شیفت‌های باز</span><strong>{loading ? "…" : money(openRegisters)}</strong></Surface>
        <Surface padding="sm"><span>نیازمند بررسی</span><strong>{loading ? "…" : money(reviewRegisters)}</strong></Surface>
      </div>
      <Surface padding="md" className="finance-shift-card">
        <div className="finance-shift-top">
          <div className="finance-shift-icon"><Wallet size={20} /></div>
          <div className="finance-shift-copy"><strong>صندوق و شیفت فعال</strong><span><Clock3 size={13} /> وضعیت از دیتابیس دریافت می‌شود</span></div>
          {activeSession ? <StatusBadge variant={activeSession.status === "open" ? "success" : "warning"}>{activeSession.status === "open" ? "شیفت باز" : "نیازمند بررسی"}</StatusBadge> : <StatusBadge variant="neutral">بدون شیفت فعال</StatusBadge>}
        </div>
        <div className="finance-form-fields">
          <SelectField label="انتخاب صندوق" value={selectedId} onChange={(event) => setSelectedId(event.target.value)} disabled={loading || busy} placeholder="انتخاب صندوق" options={registers.map((item) => ({ value: item.id, label: `${item.name} · ${item.code}` }))} />
          {selected && activeSession ? (
            <div className="finance-close-values">
              <span>موجودی ابتدای شیفت<strong>{money(activeSession.opening_amount)} تومان</strong></span>
              <span>موجودی مورد انتظار<strong>{money(activeSession.expected_cash_amount)} تومان</strong></span>
              <span>شروع شیفت<strong>{new Date(activeSession.opened_at).toLocaleString("fa-IR")}</strong></span>
            </div>
          ) : selected ? (
            <TextField label="موجودی اولیه صندوق (تومان)" value={openingAmount} onChange={(event) => setOpeningAmount(event.target.value)} placeholder="۰" />
          ) : null}
        </div>
        <div className="finance-panel-actions">
          {selected && !activeSession ? <Button variant="primary" onClick={() => void startShift()} disabled={busy || !Number.isSafeInteger(parseAmount(openingAmount))}><Clock3 size={14} /> بازکردن شیفت</Button> : null}
          {!selected ? <Button variant="primary" onClick={() => setShowCreate((value) => !value)} disabled={busy}><Plus size={14} /> ایجاد اولین صندوق</Button> : null}
        </div>
        {selected && activeSession?.status === "open" ? (
          <div className="finance-movement-form">
            <div className="finance-panel-heading"><strong>بستن شیفت</strong><LockKeyhole size={16} /></div>
            <TextField label="مبلغ شمارش‌شده واقعی (تومان)" value={countedAmount} onChange={(event) => setCountedAmount(event.target.value)} placeholder="مبلغ وجه نقد شمارش‌شده" />
            <div className="finance-panel-actions"><Button variant="primary" onClick={() => void finishShift()} disabled={busy || countedAmount.trim() === "" || !Number.isSafeInteger(parseAmount(countedAmount))}>ثبت شمارش و بستن شیفت</Button></div>
          </div>
        ) : null}
        {selected && activeSession?.status === "requires_review" ? (
          <div className="finance-movement-form">
            <Notice variant="warning">این شیفت به دلیل اختلاف شمارش نیازمند بررسی مدیر است.</Notice>
            <TextField label="یادداشت بررسی" value={reviewNote} onChange={(event) => setReviewNote(event.target.value)} placeholder="دلیل و نتیجه بررسی" />
            <div className="finance-panel-actions"><Button variant="primary" onClick={() => void resolveReview()} disabled={busy || !reviewNote.trim()}>تأیید بررسی و بستن</Button></div>
          </div>
        ) : null}
      </Surface>
      {showCreate ? <Surface padding="md" className="finance-movement-form">
        <div className="finance-panel-heading"><strong>ایجاد صندوق جدید</strong></div>
        <div className="finance-form-fields">
          <TextField label="نام صندوق" value={registerName} onChange={(event) => setRegisterName(event.target.value)} />
          <TextField label="کد صندوق (انگلیسی)" value={registerCode} onChange={(event) => setRegisterCode(event.target.value.toUpperCase())} />
        </div>
        <div className="finance-panel-actions"><Button variant="secondary" onClick={() => setShowCreate(false)}>انصراف</Button><Button variant="primary" onClick={() => void addRegister()} disabled={busy || !registerName.trim() || !/^[A-Z0-9][A-Z0-9_-]{1,39}$/.test(registerCode.trim().toUpperCase())}>ایجاد صندوق</Button></div>
      </Surface> : null}
      <Surface padding="md" className="finance-ledger">
        <div className="finance-ledger-heading"><div><h2>صندوق‌های این شعبه</h2><p>فهرست صندوق‌ها و وضعیت شیفت جاری</p></div><Button variant="secondary" onClick={() => setShowCreate((value) => !value)}><Plus size={14} /> صندوق جدید</Button></div>
        {loading ? <div className="finance-empty">در حال دریافت اطلاعات...</div> : registers.length ? (
          <div className="finance-movement-list">{registers.map((item) => <button type="button" className="finance-movement finance-register-row" key={item.id} onClick={() => setSelectedId(item.id)}>
            <span className="finance-movement-icon in"><Wallet size={16} /></span>
            <span className="finance-movement-copy"><strong>{item.name}</strong><small>{item.code} · {item.active_session ? (item.active_session.status === "open" ? "شیفت باز" : "نیازمند بررسی") : "بدون شیفت فعال"}</small></span>
            <span className="finance-movement-amount"><strong>{item.active_session ? money(item.active_session.expected_cash_amount) : "—"}</strong><small>تومان</small></span>
          </button>)}</div>
        ) : <div className="finance-empty">هنوز صندوقی برای این شعبه تعریف نشده است.</div>}
      </Surface>
    </div>
  );
}
