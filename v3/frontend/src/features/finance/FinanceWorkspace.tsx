import { useMemo, useState } from "react";
import { ArrowDownToLine, ArrowUpFromLine, Clock3, LockKeyhole, Plus, Wallet, X } from "lucide-react";
import { Button, Notice, StatusBadge, Surface, TextField } from "../../shared/ui";
import "./finance.css";

type Movement = { id: number; kind: "in" | "out"; title: string; amount: number; time: string; note: string };
const seedMovements: Movement[] = [
  { id: 1, kind: "in", title: "دریافت نقدی نمونه", amount: 350000, time: "۱۰:۲۵", note: "ثبت نمایشی" },
  { id: 2, kind: "out", title: "هزینه خرد نمونه", amount: 80000, time: "۱۱:۱۰", note: "ثبت نمایشی" },
  { id: 3, kind: "in", title: "واریز به صندوق نمونه", amount: 200000, time: "۱۲:۴۰", note: "ثبت نمایشی" },
];
const money = (value: number) => new Intl.NumberFormat("fa-IR").format(value);
const parseAmount = (value: string) => {
  const normalized = value.replace(/[۰-۹]/g, (digit) => String("۰۱۲۳۴۵۶۷۸۹".indexOf(digit)))
    .replace(/[٠-٩]/g, (digit) => String("٠١٢٣٤٥٦٧٨٩".indexOf(digit)))
    .replace(/[،,٬\s]/g, "").replace(/[^0-9]/g, "");
  return normalized ? Number(normalized) : 0;
};

export function FinanceWorkspace() {
  const [shiftOpen, setShiftOpen] = useState(true);
  const [openingCash] = useState(2000000);
  const [movements, setMovements] = useState(seedMovements);
  const [showMovement, setShowMovement] = useState(false);
  const [kind, setKind] = useState<"in" | "out">("in");
  const [title, setTitle] = useState("");
  const [amount, setAmount] = useState("");
  const [note, setNote] = useState("");
  const [showCloseSummary, setShowCloseSummary] = useState(false);
  const cashIn = useMemo(() => movements.filter((item) => item.kind === "in").reduce((sum, item) => sum + item.amount, 0), [movements]);
  const cashOut = useMemo(() => movements.filter((item) => item.kind === "out").reduce((sum, item) => sum + item.amount, 0), [movements]);
  const expectedCash = openingCash + cashIn - cashOut;

  function addMovement() {
    const value = parseAmount(amount);
    if (!title.trim() || !Number.isSafeInteger(value) || value <= 0 || (kind === "out" && value > expectedCash)) return;
    const next: Movement = { id: Math.max(0, ...movements.map((item) => item.id)) + 1, kind, title: title.trim(), amount: value, time: "اکنون", note: note.trim() || "بدون توضیح" };
    setMovements((current) => [next, ...current]);
    setTitle(""); setAmount(""); setNote(""); setShowMovement(false);
  }

  function closeShift() {
    setShiftOpen(false);
    setShowCloseSummary(false);
  }

  return (
    <div className="page-stack finance-workspace">
      <div className="finance-heading">
        <div><span className="ui-eyebrow">CASH & SHIFT CONTROL</span><h1>مالی و صندوق‌ها</h1><p>مدیریت شیفت صندوق، دریافت‌ها و پرداخت‌های نقدی</p></div>
        <StatusBadge variant={shiftOpen ? "success" : "warning"}>{shiftOpen ? "شیفت نمونه باز است" : "شیفت نمونه بسته است"}</StatusBadge>
      </div>
      <Notice variant="warning">این صفحه صرفاً نمونه تعاملی است. مبالغ، شیفت و گردش‌ها ساختگی‌اند و فقط در حافظه مرورگر تغییر می‌کنند؛ هیچ پرداخت، تراکنش بانکی، سند حسابداری یا موجودی واقعی ثبت نمی‌شود.</Notice>
      <div className="finance-metrics">
        <Surface padding="sm"><span>موجودی ابتدای شیفت</span><strong>{money(openingCash)} <small>تومان</small></strong></Surface>
        <Surface padding="sm"><span>دریافت‌های نقدی</span><strong>{money(cashIn)} <small>تومان</small></strong></Surface>
        <Surface padding="sm"><span>پرداخت‌های نقدی</span><strong>{money(cashOut)} <small>تومان</small></strong></Surface>
        <Surface padding="sm"><span>موجودی مورد انتظار</span><strong>{money(expectedCash)} <small>تومان</small></strong></Surface>
      </div>
      <Surface padding="md" className="finance-shift-card">
        <div className="finance-shift-top">
          <div className="finance-shift-icon"><Wallet size={20} /></div>
          <div className="finance-shift-copy"><strong>شیفت صندوق اصلی</strong><span><Clock3 size={13} /> شروع نمونه: امروز، ساعت ۰۸:۰۰</span></div>
          <StatusBadge variant={shiftOpen ? "success" : "warning"}>{shiftOpen ? "باز" : "بسته"}</StatusBadge>
        </div>
        <div className="finance-shift-bottom">
          <div><span>صندوقدار نمونه</span><strong>کاربر نمایشی</strong></div>
          <div><span>تعداد گردش ثبت‌شده</span><strong>{money(movements.length)}</strong></div>
          {shiftOpen ? <Button variant="secondary" onClick={() => setShowCloseSummary(true)}><LockKeyhole size={14} /> بستن شیفت نمونه</Button> : <Button variant="secondary" onClick={() => { setShiftOpen(true); setShowCloseSummary(false); }}>بازکردن شیفت نمونه</Button>}
        </div>
      </Surface>
      {showCloseSummary && shiftOpen ? <Surface padding="md" className="finance-close-panel">
        <div className="finance-panel-heading"><strong>خلاصه بستن شیفت (نمایشی)</strong><button type="button" onClick={() => setShowCloseSummary(false)} aria-label="بستن"><X size={15} /></button></div>
        <div className="finance-close-values"><span>موجودی مورد انتظار<strong>{money(expectedCash)} تومان</strong></span><span>دریافت نقدی<strong>{money(cashIn)} تومان</strong></span><span>پرداخت نقدی<strong>{money(cashOut)} تومان</strong></span></div>
        <Notice variant="info">در نسخه عملیاتی، شمارش واقعی وجه، ثبت کسری/اضافی، کنترل مجوز و تأیید نهایی سرور الزامی است.</Notice>
        <div className="finance-panel-actions"><Button variant="secondary" onClick={() => setShowCloseSummary(false)}>انصراف</Button><Button variant="primary" onClick={closeShift}>بستن محلی شیفت نمونه</Button></div>
      </Surface> : null}
      {showMovement && shiftOpen ? <Surface padding="md" className="finance-movement-form">
        <div className="finance-panel-heading"><strong>ثبت گردش نقدی نمونه</strong><button type="button" onClick={() => setShowMovement(false)} aria-label="بستن"><X size={15} /></button></div>
        <div className="finance-kind-tabs"><button type="button" className={kind === "in" ? "is-active" : ""} onClick={() => setKind("in")}><ArrowDownToLine size={14} /> دریافت نقدی</button><button type="button" className={kind === "out" ? "is-active" : ""} onClick={() => setKind("out")}><ArrowUpFromLine size={14} /> پرداخت نقدی</button></div>
        <div className="finance-form-fields"><TextField label="عنوان گردش" value={title} onChange={(event) => setTitle(event.target.value)} placeholder="مثلاً واریز وجه به صندوق" /><TextField label="مبلغ به تومان" value={amount} onChange={(event) => setAmount(event.target.value)} placeholder="مثلاً ۲۵۰۰۰۰" /><TextField label="یادداشت (اختیاری)" value={note} onChange={(event) => setNote(event.target.value)} placeholder="توضیح کوتاه" /></div>
        {kind === "out" && parseAmount(amount) > expectedCash ? <p className="finance-validation">مبلغ پرداخت از موجودی مورد انتظار بیشتر است.</p> : null}
        <div className="finance-panel-actions"><Button variant="secondary" onClick={() => setShowMovement(false)}>انصراف</Button><Button variant="primary" onClick={addMovement} disabled={!title.trim() || parseAmount(amount) <= 0 || (kind === "out" && parseAmount(amount) > expectedCash)}>ثبت گردش نمونه</Button></div>
      </Surface> : null}
      <Surface padding="md" className="finance-ledger">
        <div className="finance-ledger-heading"><div><h2>گردش‌های نقدی شیفت</h2><p>فهرست نمایشی دریافت‌ها و پرداخت‌ها</p></div>{shiftOpen ? <Button variant="primary" onClick={() => setShowMovement((value) => !value)}><Plus size={14} /> ثبت گردش</Button> : null}</div>
        <div className="finance-movement-list">
          {movements.map((item) => <div className="finance-movement" key={item.id}>
            <span className={`finance-movement-icon ${item.kind}`}>{item.kind === "in" ? <ArrowDownToLine size={16} /> : <ArrowUpFromLine size={16} />}</span>
            <div className="finance-movement-copy"><strong>{item.title}</strong><small>{item.time} · {item.note}</small></div>
            <div className="finance-movement-amount"><strong className={item.kind}>{item.kind === "in" ? "+" : "−"}{money(item.amount)}</strong><small>تومان</small></div>
          </div>)}
          {movements.length === 0 ? <div className="finance-empty">هنوز گردش نمونه‌ای ثبت نشده است.</div> : null}
        </div>
      </Surface>
    </div>
  );
}
