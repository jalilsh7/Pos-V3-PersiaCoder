import { useMemo, useState } from "react";
import { Minus, Plus, Search, ShoppingBag, Store, X } from "lucide-react";
import { Button, Notice, StatusBadge } from "../../shared/ui";
import "./storefront.css";

type MenuItem = { id: number; name: string; category: string; description: string; price: number; available: boolean };
const menuItems: MenuItem[] = [
  { id: 1, name: "برگر کلاسیک", category: "غذای اصلی", description: "گوشت گریل، کاهو، گوجه و سس مخصوص", price: 185000, available: true },
  { id: 2, name: "پیتزا مخصوص", category: "غذای اصلی", description: "پنیر، قارچ، فلفل دلمه و ترکیب ویژه", price: 265000, available: true },
  { id: 3, name: "ساندویچ مرغ", category: "غذای اصلی", description: "مرغ مزه‌دار، سبزیجات تازه و سس", price: 158000, available: true },
  { id: 4, name: "اسپرسو", category: "نوشیدنی", description: "قهوه تازه آسیاب‌شده", price: 72000, available: true },
  { id: 5, name: "لاته", category: "نوشیدنی", description: "اسپرسو با شیر بخارداده‌شده", price: 118000, available: true },
  { id: 6, name: "چیزکیک", category: "دسر", description: "برش چیزکیک روز", price: 112000, available: false },
];
const money = (value: number) => new Intl.NumberFormat("fa-IR").format(value);

export function StorefrontWorkspace() {
  const [category, setCategory] = useState("همه");
  const [query, setQuery] = useState("");
  const [cart, setCart] = useState<Record<number, number>>({});
  const [fulfillment, setFulfillment] = useState<"pickup" | "delivery">("pickup");
  const [showCart, setShowCart] = useState(false);
  const categories = ["همه", ...Array.from(new Set(menuItems.map((item) => item.category)))];
  const visible = useMemo(() => menuItems.filter((item) =>
    (category === "همه" || item.category === category) &&
    `${item.name} ${item.description}`.toLocaleLowerCase("fa-IR").includes(query.trim().toLocaleLowerCase("fa-IR"))
  ), [category, query]);
  const cartItems = menuItems.filter((item) => (cart[item.id] ?? 0) > 0);
  const count = cartItems.reduce((sum, item) => sum + cart[item.id], 0);
  const total = cartItems.reduce((sum, item) => sum + item.price * cart[item.id], 0);

  function changeQuantity(id: number, delta: number) {
    setCart((current) => {
      const next = Math.max(0, (current[id] ?? 0) + delta);
      return { ...current, [id]: next };
    });
  }

  return (
    <div className="page-stack storefront-workspace">
      <div className="storefront-heading">
        <div><span className="ui-eyebrow">ONLINE MENU PREVIEW</span><h1>منوی آنلاین و سفارش‌گیری</h1><p>پیش‌نمایش تجربه سفارش مشتری در وب‌سایت کسب‌وکار</p></div>
        <StatusBadge variant="warning">پیش‌نمایش مستقل</StatusBadge>
      </div>
      <Notice variant="warning">این منو فقط نمونه تعاملی است. سبد خرید محلی است و ثبت سفارش، محاسبه هزینه ارسال، پرداخت، موجودی و ساعات کاری به Backend متصل نیست.</Notice>
      <div className="storefront-preview">
        <header className="storefront-brand">
          <div className="storefront-logo"><Store size={19} /></div>
          <div><strong>رستوران نمونه</strong><small>طعم خوب، تجربه بهتر</small></div>
          <StatusBadge variant="success">باز · نمایشی</StatusBadge>
        </header>
        <div className="storefront-cover">
          <span>منوی روز</span><h2>یک انتخاب خوشمزه برای امروز</h2><p>غذاهای تازه با مواد اولیه باکیفیت</p>
        </div>
        <div className="storefront-order-mode">
          <span>روش دریافت</span>
          <div><button type="button" className={fulfillment === "pickup" ? "is-active" : ""} onClick={() => setFulfillment("pickup")}>دریافت حضوری</button><button type="button" className={fulfillment === "delivery" ? "is-active" : ""} onClick={() => setFulfillment("delivery")}>ارسال با پیک</button></div>
        </div>
        <div className="storefront-menu-heading"><div><h2>منوی ما</h2><p>محصول موردعلاقه‌ات را انتخاب کن</p></div><span>{money(menuItems.filter((item) => item.available).length)} محصول فعال نمونه</span></div>
        <label className="storefront-search"><Search size={15} /><input value={query} onChange={(event) => setQuery(event.target.value)} placeholder="جست‌وجو در منو" /><button type="button" onClick={() => setQuery("")} aria-label="پاک کردن جست‌وجو" disabled={!query}><X size={13} /></button></label>
        <div className="storefront-categories">{categories.map((item) => <button type="button" key={item} className={category === item ? "is-active" : ""} onClick={() => setCategory(item)}>{item}</button>)}</div>
        <div className="storefront-products">
          {visible.map((item) => <article className="storefront-product" key={item.id}>
            <div className="storefront-product-art"><Store size={23} /></div>
            <div className="storefront-product-copy"><strong>{item.name}</strong><p>{item.description}</p><b>{money(item.price)} <small>تومان</small></b></div>
            <div className="storefront-product-action">{item.available ? (cart[item.id] ? <div className="storefront-quantity"><button type="button" onClick={() => changeQuantity(item.id, -1)} aria-label="کاهش تعداد"><Minus size={13} /></button><strong>{money(cart[item.id])}</strong><button type="button" onClick={() => changeQuantity(item.id, 1)} aria-label="افزایش تعداد"><Plus size={13} /></button></div> : <Button variant="secondary" size="sm" onClick={() => changeQuantity(item.id, 1)}><Plus size={13} /> افزودن</Button>) : <StatusBadge variant="neutral">ناموجود</StatusBadge>}</div>
          </article>)}
          {visible.length === 0 ? <div className="storefront-empty">محصولی مطابق جست‌وجو پیدا نشد.</div> : null}
        </div>
        <button type="button" className="storefront-cart-bar" onClick={() => setShowCart((value) => !value)}>
          <span><ShoppingBag size={17} /> سبد خرید · {money(count)} قلم</span><strong>{money(total)} تومان</strong>
        </button>
        {showCart ? <div className="storefront-cart-panel">
          <div className="storefront-cart-title"><strong>خلاصه سبد نمونه</strong><button type="button" onClick={() => setShowCart(false)} aria-label="بستن"><X size={15} /></button></div>
          {cartItems.map((item) => <div className="storefront-cart-row" key={item.id}><span>{item.name} × {money(cart[item.id])}</span><strong>{money(item.price * cart[item.id])}</strong></div>)}
          {cartItems.length === 0 ? <p className="storefront-empty">سبد خرید خالی است.</p> : null}
          <div className="storefront-cart-total"><span>جمع نمونه</span><strong>{money(total)} تومان</strong></div>
          <Button variant="primary" className="storefront-continue" disabled={count === 0}>ادامه سفارش (غیرفعال در پیش‌نمایش)</Button>
        </div> : null}
      </div>
    </div>
  );
}
