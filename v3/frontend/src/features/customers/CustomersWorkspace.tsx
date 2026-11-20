import { useMemo, useState } from "react";
import { ArrowDownLeft, ArrowUpLeft, CirclePlus, Clock3, Search, ShoppingBag, UserRound, X } from "lucide-react";
import { Button, Notice, StatusBadge, Surface, TextField } from "../../shared/ui";
import "./customers.css";

type Customer = {
  id: number;
  name: string;
  phone: string;
  orders: number;
  spent: number;
  points: number;
  lastVisit: string;
  segment: "regular" | "new" | "inactive";
  note: string;
  history: { code: string; date: string; total: number; status: "completed" | "cancelled" }[];
};

const seedCustomers: Customer[] = [
  { id: 1, name: "سارا محمدی", phone: "0912 345 6789", orders: 18, spent: 8640000, points: 420, lastVisit: "۱۴۰۵/۰۸/۲۸", segment: "regular", note: "ترجیح: سفارش بیرون‌بر", history: [
    { code: "ORD-1048", date: "۱۴۰۵/۰۸/۲۸", total: 485000, status: "completed" },
    { code: "ORD-1012", date: "۱۴۰۵/۰۸/۲۱", total: 620000, status: "completed" },
    { code: "ORD-0984", date: "۱۴۰۵/۰۸/۱۵", total: 310000, status: "cancelled" },
  ] },
  { id: 2, name: "علی رضایی", phone: "0935 222 1040", orders: 7, spent: 2940000, points: 135, lastVisit: "۱۴۰۵/۰۸/۲۶", segment: "regular", note: "بدون یادداشت", history: [
    { code: "ORD-1039", date: "۱۴۰۵/۰۸/۲۶", total: 395000, status: "completed" },
    { code: "ORD-0990", date: "۱۴۰۵/۰۸/۱۷", total: 275000, status: "completed" },
  ] },
  { id: 3, name: "نگار احمدی", phone: "0910 888 3210", orders: 1, spent: 185000, points: 10, lastVisit: "۱۴۰۵/۰۸/۲۷", segment: "new", note: "مشتری تازه", history: [
    { code: "ORD-1044", date: "۱۴۰۵/۰۸/۲۷", total: 185000, status: "completed" },
  ] },
  { id: 4, name: "مهدی کریمی", phone: "0991 456 7832", orders: 3, spent: 970000, points: 25, lastVisit: "۱۴۰۵/۰۶/۱۰", segment: "inactive", note: "بدون یادداشت", history: [
    { code: "ORD-0712", date: "۱۴۰۵/۰۶/۱۰", total: 420000, status: "completed" },
  ] },
];
const money = (value: number) => new Intl.NumberFormat("fa-IR").format(value);
const segmentLabel = (segment: Customer["segment"]) => segment === "regular" ? "فعال" : segment === "new" ? "تازه‌وارد" : "بدون مراجعه اخیر";

export function CustomersWorkspace() {
  const [customers, setCustomers] = useState(seedCustomers);
  const [query, setQuery] = useState("");
  const [segment, setSegment] = useState("all");
  const [selectedId, setSelectedId] = useState<number | null>(1);
  const [showCreate, setShowCreate] = useState(false);
  const [name, setName] = useState("");
  const [phone, setPhone] = useState("");
  const selected = customers.find((customer) => customer.id === selectedId) ?? null;
  const visible = useMemo(() => customers.filter((customer) =>
    (segment === "all" || customer.segment === segment) &&
    `${customer.name} ${customer.phone}`.toLocaleLowerCase("fa-IR").includes(query.trim().toLocaleLowerCase("fa-IR"))
  ), [customers, query, segment]);

  function addCustomer() {
    const cleanName = name.trim();
    const cleanPhone = phone.trim();
    if (!cleanName || !cleanPhone) return;
    const id = Math.max(0, ...customers.map((customer) => customer.id)) + 1;
    const customer: Customer = { id, name: cleanName, phone: cleanPhone, orders: 0, spent: 0, points: 0, lastVisit: "—", segment: "new", note: "مشتری نمونه جدید", history: [] };
    setCustomers((current) => [customer, ...current]);
    setSelectedId(id);
    setName(""); setPhone(""); setShowCreate(false);
  }

  return (
    <div className="page-stack customers-workspace">
      <div className="customers-heading">
        <div><span className="ui-eyebrow">CUSTOMER RELATIONSHIP</span><h1>مشتریان</h1><p>پروفایل، سوابق سفارش و نمای کلی ارتباط با مشتری</p></div>
        <Button variant="primary" onClick={() => setShowCreate((value) => !value)}><CirclePlus size={15} /> مشتری جدید</Button>
      </div>
      <Notice variant="warning">اطلاعات این صفحه کاملاً نمونه است. افزودن مشتری و تغییرات فقط در حافظه مرورگر انجام می‌شود؛ هنوز به API، دیتابیس، سفارش‌ها یا باشگاه مشتریان متصل نیست.</Notice>
      <div className="customers-metrics">
        <Surface padding="sm"><span>مشتریان نمونه</span><strong>{money(customers.length)}</strong></Surface>
        <Surface padding="sm"><span>مشتریان فعال</span><strong>{money(customers.filter((item) => item.segment === "regular").length)}</strong></Surface>
        <Surface padding="sm"><span>امتیازهای نمایشی</span><strong>{money(customers.reduce((sum, item) => sum + item.points, 0))}</strong></Surface>
      </div>
      {showCreate ? <Surface padding="md" className="customers-create">
        <div className="customers-create-title"><strong>افزودن مشتری نمونه</strong><button type="button" onClick={() => setShowCreate(false)} aria-label="بستن"><X size={16} /></button></div>
        <div className="customers-create-fields">
          <TextField label="نام مشتری" value={name} onChange={(event) => setName(event.target.value)} placeholder="نام و نام خانوادگی" />
          <TextField label="شماره همراه" value={phone} onChange={(event) => setPhone(event.target.value)} placeholder="09xxxxxxxxx" />
        </div>
        <div className="customers-create-footer"><span>ثبت واقعی و بررسی تکراری‌بودن شماره در Backend انجام خواهد شد.</span><Button variant="primary" onClick={addCustomer} disabled={!name.trim() || !phone.trim()}>افزودن نمونه</Button></div>
      </Surface> : null}
      <div className="customers-layout">
        <Surface padding="md" className="customers-list">
          <div className="customers-list-title"><div><h2><UserRound size={17} /> فهرست مشتریان</h2><p>{money(visible.length)} مورد</p></div></div>
          <label className="customers-search"><Search size={16} /><input value={query} onChange={(event) => setQuery(event.target.value)} placeholder="نام یا شماره همراه" /><button type="button" onClick={() => setQuery("")} aria-label="پاک کردن جست‌وجو" disabled={!query}><X size={14} /></button></label>
          <div className="customers-filters">
            {[["all", "همه"], ["regular", "فعال"], ["new", "تازه‌وارد"], ["inactive", "بدون مراجعه اخیر"]].map(([key, label]) => <button key={key} type="button" className={segment === key ? "is-active" : ""} onClick={() => setSegment(key)}>{label}</button>)}
          </div>
          <div className="customers-items">
            {visible.map((customer) => <button type="button" key={customer.id} className={`customer-row ${selectedId === customer.id ? "is-selected" : ""}`} onClick={() => setSelectedId(customer.id)}>
              <span className="customer-avatar">{customer.name.slice(0, 1)}</span>
              <span className="customer-row-copy"><strong>{customer.name}</strong><small>{customer.phone}</small></span>
              <span className="customer-row-meta"><strong>{money(customer.orders)}</strong><small>سفارش</small></span>
            </button>)}
            {visible.length === 0 ? <div className="customers-empty">مشتری‌ای با این مشخصات پیدا نشد.</div> : null}
          </div>
        </Surface>
        <Surface padding="md" className="customers-detail">
          {selected ? <>
            <div className="customers-profile-head"><span className="customer-avatar large">{selected.name.slice(0, 1)}</span><div><h2>{selected.name}</h2><p>{selected.phone}</p><StatusBadge variant={selected.segment === "inactive" ? "warning" : "success"}>{segmentLabel(selected.segment)}</StatusBadge></div></div>
            <div className="customers-profile-stats">
              <div><span>تعداد سفارش</span><strong>{money(selected.orders)}</strong></div>
              <div><span>مجموع خرید</span><strong>{money(selected.spent)} <small>تومان</small></strong></div>
              <div><span>امتیاز نمونه</span><strong>{money(selected.points)}</strong></div>
            </div>
            <div className="customers-last-visit"><Clock3 size={15} /><span>آخرین مراجعه</span><strong>{selected.lastVisit}</strong></div>
            <div className="customers-note"><strong>یادداشت مشتری</strong><p>{selected.note}</p></div>
            <div className="customers-history-title"><h3><ShoppingBag size={16} /> سوابق سفارش</h3><span>{money(selected.history.length)} سفارش اخیر نمونه</span></div>
            <div className="customers-history">
              {selected.history.map((order) => <div className="customers-order" key={order.code}>
                <div><strong>{order.code}</strong><small>{order.date}</small></div>
                <div className="customers-order-total"><strong>{money(order.total)} <small>تومان</small></strong><StatusBadge variant={order.status === "completed" ? "success" : "danger"}>{order.status === "completed" ? "تکمیل‌شده" : "لغوشده"}</StatusBadge></div>
              </div>)}
              {selected.history.length === 0 ? <div className="customers-empty">برای این مشتری هنوز سفارش نمونه‌ای وجود ندارد.</div> : null}
            </div>
            <div className="customers-detail-foot"><span><ArrowDownLeft size={14} /> سوابق صرفاً نمایشی</span><span><ArrowUpLeft size={14} /> اطلاعات مالی از سرور دریافت نشده</span></div>
          </> : <div className="customers-empty">برای مشاهده پروفایل، یک مشتری را انتخاب کنید.</div>}
        </Surface>
      </div>
    </div>
  );
}
