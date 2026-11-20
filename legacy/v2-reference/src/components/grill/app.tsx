import { useCallback, useEffect, useMemo, useRef, useState, type FormEvent, type ReactNode } from "react";
import {
  ChefHat, ClipboardList, LayoutDashboard, MessageSquare, Percent,
  Settings as SettingsIcon, ShoppingBag, Users, UtensilsCrossed, Warehouse, Truck, LogOut,
} from "lucide-react";
import { Btn, Card, Empty, Field, PageHeading, inputCls } from "./bits";
import { ReceiptView } from "./receipt";
import {
  customerCodeUses, fillTemplate, money, quoteOrder, TIER_LABEL, tierOf, uid,
  type CartLine, type DiscountCode, type Order, type Product, type Role, type SmsEvent, type PosState, type Settings, type SmsLog,
} from "@/lib/pos/model";
import { lowStock, usePos } from "@/lib/pos/store";
import { phpApi, type ApiUser, type ManagedUser, type AppearanceSettings, type PaymentTerminal, type TerminalAgent } from "@/lib/api/php-client";
import { APPEARANCE_PRESETS, useAppearance } from "@/lib/appearance";


async function saveCentralRecord<T extends { id: string | number }>(resource: string, item: T, commit: () => void) {
  try {
    await phpApi.saveRecord(resource, item);
    commit();
    return true;
  } catch (error) {
    window.alert(error instanceof Error ? error.message : "ذخیره در سرور انجام نشد");
    return false;
  }
}
async function adjustCentralStock<TItem extends { id: string }>(id: string, delta: number, commit: (item: TItem) => void) {
  try {
    const result = await phpApi.adjustIngredientStock<TItem>(id, delta);
    commit(result.item);
    return true;
  } catch (error) {
    window.alert(error instanceof Error ? error.message : "تغییر موجودی در سرور انجام نشد");
    return false;
  }
}
async function deleteCentralRecord(resource: string, id: string, commit: () => void) {
  try {
    await phpApi.deleteRecord(resource, id);
    commit();
    return true;
  } catch (error) {
    window.alert(error instanceof Error ? error.message : "حذف از سرور انجام نشد");
    return false;
  }
}

const INITIAL_RENDER_TIME = Date.now();

type View = "pos" | "orders" | "menu" | "stock" | "customers" | "codes" | "reports" | "expenses" | "suppliers" | "sms" | "users" | "settings";

const NAV: { id: View; label: string; icon: typeof ShoppingBag; roles: Role[] }[] = [
  { id: "pos", label: "فروش", icon: ShoppingBag, roles: ["manager", "cashier"] },
  { id: "orders", label: "سفارش‌ها", icon: ClipboardList, roles: ["manager", "cashier", "kitchen"] },
  { id: "menu", label: "منو", icon: UtensilsCrossed, roles: ["manager"] },
  { id: "stock", label: "انبار", icon: Warehouse, roles: ["manager"] },
  { id: "customers", label: "باشگاه", icon: Users, roles: ["manager", "cashier"] },
  { id: "codes", label: "کد تخفیف", icon: Percent, roles: ["manager"] },
  { id: "reports", label: "گزارش", icon: LayoutDashboard, roles: ["manager"] },
  { id: "expenses", label: "هزینه‌ها", icon: ClipboardList, roles: ["manager"] },
  { id: "suppliers", label: "تأمین‌کنندگان", icon: Truck, roles: ["manager"] },
  { id: "sms", label: "پیامک", icon: MessageSquare, roles: ["manager"] },
  { id: "users", label: "کاربران", icon: Users, roles: ["manager"] },
  { id: "settings", label: "تنظیمات", icon: SettingsIcon, roles: ["manager"] },
];

const ROLE_LABEL: Record<Role, string> = { manager: "مدیر", cashier: "صندوقدار", kitchen: "آشپز" };
const EVENT_LABEL: Record<SmsEvent, string> = {
  order_created: "ثبت سفارش", order_ready: "آماده شدن", order_delivered: "تحویل",
  points_earned: "کسب امتیاز", discount_used: "استفاده از کد",
};

export function GrillApp() {
  const shop = usePos((s) => s.settings.shopName);
  const { settings: appearance } = useAppearance();
  const [user, setUser] = useState<ApiUser | null>(null);
  const [loading, setLoading] = useState(true);
  const [setupRequired, setSetupRequired] = useState(false);
  const [sessionError, setSessionError] = useState("");

  useEffect(() => {
    let active = true;
    void phpApi.me()
      .then(async (result) => {
        if (!active) return;
        if (result.authenticated && result.user) {
          setUser(result.user);
          return;
        }
        const setup = await phpApi.setupStatus();
        if (active) setSetupRequired(setup.setup_required);
      })
      .catch((error: unknown) => {
        if (active) setSessionError(error instanceof Error ? error.message : "ارتباط با سرور برقرار نشد");
      })
      .finally(() => {
        if (active) setLoading(false);
      });
    return () => { active = false; };
  }, []);

  useEffect(() => {
    if (!user || user.must_change_password) return;
    let active = true;
    let inFlight = false;
    const refresh = async (showError = false) => {
      if (!active || inFlight || document.visibilityState === "hidden") return;
      inFlight = true;
      try {
        const result = await phpApi.bootstrap<ServerBootstrap>();
        if (active) hydratePosFromServer(result.data, result.next_order, user.role);
      } catch (error) {
        // A temporary network failure must not log the user out or replace
        // the last known good state. The next poll/focus event retries.
        if (active && showError) {
          setSessionError(error instanceof Error ? error.message : "دریافت داده‌های MySQL انجام نشد");
        }
      } finally {
        inFlight = false;
      }
    };
    void refresh(true);
    const timer = window.setInterval(() => { void refresh(); }, 15000);
    const onVisible = () => { if (document.visibilityState === "visible") void refresh(); };
    window.addEventListener("focus", onVisible);
    document.addEventListener("visibilitychange", onVisible);
    return () => {
      active = false;
      window.clearInterval(timer);
      window.removeEventListener("focus", onVisible);
      document.removeEventListener("visibilitychange", onVisible);
    };
  }, [user]);

  async function logout() {
    try {
      await phpApi.logout();
      setUser(null);
      setSessionError("");
    } catch (error) {
      setSessionError(error instanceof Error ? error.message : "خروج انجام نشد");
    }
  }

  if (loading) return <AuthFrame><p className="text-sm text-muted">در حال بررسی نشست امن...</p></AuthFrame>;
  if (sessionError && !user) return <AuthFrame><p className="text-sm text-warn">{sessionError}</p><button className="rounded-xl bg-ink px-4 py-2 text-bg" onClick={() => window.location.reload()}>تلاش دوباره</button></AuthFrame>;
  if (!user) return <PhpLoginScreen setupRequired={setupRequired} onAuthenticated={setUser} />;
  if (user.must_change_password) return <ChangePasswordScreen user={user} onChanged={() => setUser({ ...user, must_change_password: false })} />;
  const allowed = NAV.filter((n) => n.roles.includes(user.role));
  const [viewFallback] = allowed;
  return (
    <div className="pos-app min-h-screen bg-bg text-ink">
      <header className="pos-topbar sticky top-0 z-30 flex min-w-0 items-center justify-between gap-3 px-4 py-3 sm:px-6">
        <div className="flex min-w-0 items-center gap-3">
          {appearance.logoUrl ? (
            <img src={appearance.logoUrl} alt="لوگوی فروشگاه" className="h-11 w-11 rounded-2xl border border-line bg-surface object-contain p-1" />
          ) : (
            <div className="grid h-11 w-11 shrink-0 place-items-center rounded-2xl bg-chili text-chili-ink shadow-sm"><UtensilsCrossed size={21} /></div>
          )}
          <div className="min-w-0">
            <p className="truncate text-base font-extrabold tracking-tight sm:text-lg">{shop}</p>
            <p className="text-[11px] font-medium text-muted sm:text-xs">سامانه مدیریت و فروش 3Food</p>
          </div>
        </div>
        <div className="flex shrink-0 items-center gap-2 sm:gap-3">
          <div className="hidden text-left sm:block">
            <p className="text-sm font-bold">{user.display_name}</p>
            <p className="text-[11px] text-muted">{ROLE_LABEL[user.role]}</p>
          </div>
          <span className="grid h-9 w-9 place-items-center rounded-full bg-leaf/10 text-sm font-bold text-leaf">{user.display_name.trim().slice(0, 1) || "ک"}</span>
          <button type="button" onClick={() => void logout()} className="pos-logout inline-flex items-center gap-2 rounded-xl border border-line bg-surface px-3 py-2 text-xs font-semibold sm:text-sm">
            <LogOut size={16} /><span className="hidden sm:inline">خروج امن</span>
          </button>
        </div>
      </header>
      <PosWorkspace allowed={allowed} initialView={viewFallback?.id ?? "orders"} role={user.role} />
    </div>
  );
}

function PosWorkspace({ allowed, initialView, role }: { allowed: typeof NAV; initialView: View; role: Role }) {
  const [view, setView] = useState<View>(initialView);
  const current = allowed.some((n) => n.id === view) ? view : allowed[0]?.id ?? "orders";
  return (
    <div className="pos-layout mx-auto grid w-full max-w-[1600px] min-w-0 gap-4 px-3 py-4 sm:px-5 lg:grid-cols-[248px_minmax(0,1fr)] lg:gap-6 lg:px-7 lg:py-7">
      <nav className="pos-nav min-w-0 lg:sticky lg:top-[92px] lg:flex lg:h-[calc(100vh-116px)] lg:flex-col">
        <div className="hidden px-3 pb-4 pt-2 lg:block">
          <p className="text-[10px] font-bold tracking-[0.18em] text-muted">WORKSPACE</p>
          <p className="mt-1 text-sm font-extrabold">دسترسی سریع</p>
        </div>
        <div className="pos-nav-list flex min-w-0 gap-2 overflow-x-auto pb-2 lg:flex-col lg:overflow-visible lg:pb-0">
          {allowed.map((n) => {
            const Icon = n.icon;
            const on = current === n.id;
            return (
              <button key={n.id} type="button" aria-current={on ? "page" : undefined} onClick={() => setView(n.id)} className={`pos-nav-item inline-flex shrink-0 items-center gap-3 rounded-2xl px-4 py-3 text-sm font-semibold ${on ? "is-active" : ""}`}>
                <span className="grid h-8 w-8 place-items-center rounded-xl"><Icon size={18} strokeWidth={2} /></span>
                <span>{n.label}</span>
              </button>
            );
          })}
        </div>
        <div className="mt-auto hidden rounded-2xl border border-line bg-surface/80 p-4 lg:block">
          <p className="text-xs font-bold text-ink">3Food POS</p>
          <p className="mt-1 text-[11px] leading-6 text-muted">مدیریت یکپارچه فروش، سفارش و موجودی</p>
          <span className="mt-2 inline-flex items-center gap-2 text-[10px] font-semibold text-leaf"><span className="h-1.5 w-1.5 rounded-full bg-leaf" /> سامانه فعال</span>
        </div>
      </nav>
      <main className="pos-main min-w-0">
        {current === "pos" ? <PosScreen /> : null}
        {current === "orders" ? <OrdersScreen role={role} /> : null}
        {current === "menu" ? <MenuScreen /> : null}
        {current === "stock" ? <StockScreen /> : null}
        {current === "customers" ? <CustomersScreen role={role} /> : null}
        {current === "codes" ? <CodesScreen /> : null}
        {current === "reports" ? <ReportsScreen /> : null}
        {current === "expenses" ? <ExpensesScreen /> : null}
        {current === "suppliers" ? <SuppliersScreen /> : null}
        {current === "sms" ? <SmsScreen /> : null}
        {current === "users" ? <UsersScreen /> : null}
        {current === "settings" ? <SettingsScreen /> : null}
      </main>
    </div>
  );
}

function UsersScreen() {
  const [users, setUsers] = useState<ManagedUser[]>([]);
  const [loading, setLoading] = useState(true);
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState("");
  const [draft, setDraft] = useState({ username: "", display_name: "", password: "", role: "cashier" as Role });

  async function refresh() {
    setLoading(true);
    try {
      const result = await phpApi.listUsers();
      setUsers(result.users);
      setError("");
    } catch (err) {
      setError(err instanceof Error ? err.message : "دریافت کاربران انجام نشد");
    } finally {
      setLoading(false);
    }
  }

  useEffect(() => { queueMicrotask(() => { void refresh(); }); }, []);

  async function create(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    setBusy(true);
    setError("");
    try {
      await phpApi.createUser(draft);
      setDraft({ username: "", display_name: "", password: "", role: "cashier" });
      await refresh();
    } catch (err) {
      setError(err instanceof Error ? err.message : "ساخت حساب انجام نشد");
    } finally {
      setBusy(false);
    }
  }

  async function changeUser(user: ManagedUser, patch: Partial<Pick<ManagedUser, "role" | "is_active">>) {
    setBusy(true);
    setError("");
    try {
      await phpApi.updateUser(user.id, patch);
      await refresh();
    } catch (err) {
      setError(err instanceof Error ? err.message : "تغییر حساب انجام نشد");
    } finally {
      setBusy(false);
    }
  }

  return (
    <div className="space-y-4">
      <PageHeading title="مدیریت کاربران" description="حساب‌ها، نقش‌ها و سطح دسترسی اعضای تیم را مدیریت کنید." />
      <Card>
        <h3 className="mb-3 font-semibold">ساخت حساب جدید</h3>
        <form className="grid gap-2 sm:grid-cols-2" onSubmit={(event) => void create(event)}>
          <Field label="نام کاربری"><input className={inputCls} minLength={4} maxLength={64} pattern="[A-Za-z0-9._-]+" value={draft.username} onChange={(e) => setDraft({ ...draft, username: e.target.value })} required /></Field>
          <Field label="نام نمایشی"><input className={inputCls} maxLength={120} value={draft.display_name} onChange={(e) => setDraft({ ...draft, display_name: e.target.value })} required /></Field>
          <Field label="رمز اولیه (حداقل ۱۲ نویسه)"><input className={inputCls} type="password" minLength={12} maxLength={200} autoComplete="new-password" value={draft.password} onChange={(e) => setDraft({ ...draft, password: e.target.value })} required /></Field>
          <Field label="نقش"><select className={inputCls} value={draft.role} onChange={(e) => setDraft({ ...draft, role: e.target.value as Role })}><option value="cashier">صندوقدار</option><option value="kitchen">آشپز</option><option value="manager">مدیر</option></select></Field>
          <div className="sm:col-span-2"><Btn type="submit" disabled={busy}>{busy ? "در حال ذخیره..." : "ساخت حساب"}</Btn></div>
        </form>
      </Card>
      {error ? <p className="text-sm text-warn">{error}</p> : null}
      {loading ? <p className="text-sm text-muted">در حال دریافت کاربران...</p> : users.map((user) => (
        <Card key={user.id}>
          <div className="flex flex-wrap items-center justify-between gap-3">
            <div>
              <p className="font-semibold">{user.display_name} — {user.username}</p>
              <p className="text-xs text-muted">{ROLE_LABEL[user.role]} · {user.is_active ? "فعال" : "غیرفعال"}{user.must_change_password ? " · نیازمند تغییر رمز" : ""}</p>
            </div>
            <div className="flex flex-wrap items-center gap-2">
              <select className={inputCls} aria-label="نقش کاربر" value={user.role} disabled={busy} onChange={(e) => void changeUser(user, { role: e.target.value as Role })}>
                <option value="cashier">صندوقدار</option><option value="kitchen">آشپز</option><option value="manager">مدیر</option>
              </select>
              <Btn tone="ghost" disabled={busy} onClick={() => void changeUser(user, { is_active: !user.is_active })}>{user.is_active ? "غیرفعال‌کردن" : "فعال‌کردن"}</Btn>
            </div>
          </div>
        </Card>
      ))}
    </div>
  );
}

function AuthFrame({ children }: { children: ReactNode }) {
 const {settings:appearance}=useAppearance();
 return <div className="pos-auth-shell grid min-h-screen place-items-center bg-bg p-4"><Card className="pos-auth-card w-full max-w-md">{appearance.logoUrl?<img src={appearance.logoUrl} alt="لوگوی فروشگاه" className="mx-auto mb-3 h-16 max-w-40 object-contain"/>:null}{children}</Card></div>;
}

function PhpLoginScreen({ setupRequired, onAuthenticated }: { setupRequired: boolean; onAuthenticated: (user: ApiUser) => void }) {
  const [username, setUsername] = useState("");
  const [password, setPassword] = useState("");
  const [displayName, setDisplayName] = useState("مدیر");
  const [setupToken, setSetupToken] = useState("");
  const [error, setError] = useState("");
  const [busy, setBusy] = useState(false);

  async function submit(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    setBusy(true);
    setError("");
    try {
      const result = setupRequired
        ? await phpApi.setupManager({ username, password, display_name: displayName, setupToken })
        : await phpApi.login(username, password);
      onAuthenticated(result.user);
    } catch (err) {
      setError(err instanceof Error ? err.message : "ورود انجام نشد");
    } finally {
      setBusy(false);
    }
  }

  return (
    <AuthFrame>
      <form onSubmit={(event) => void submit(event)} className="w-full min-w-[min(84vw,360px)] space-y-3">
        <h1 className="text-xl font-bold">{setupRequired ? "راه‌اندازی مدیر اصلی" : "ورود به صندوق"}</h1>
        {setupRequired ? <>
          <Field label="نام نمایشی"><input className={inputCls} value={displayName} onChange={(e) => setDisplayName(e.target.value)} required /></Field>
          <Field label="توکن یک‌بارمصرف راه‌اندازی"><input className={inputCls} type="password" value={setupToken} onChange={(e) => setSetupToken(e.target.value)} required autoComplete="off" /></Field>
        </> : null}
        <Field label="نام کاربری"><input className={inputCls} value={username} onChange={(e) => setUsername(e.target.value)} autoComplete="username" required /></Field>
        <Field label="رمز عبور"><input className={inputCls} type="password" value={password} onChange={(e) => setPassword(e.target.value)} autoComplete={setupRequired ? "new-password" : "current-password"} minLength={setupRequired ? 12 : undefined} required /></Field>
        {error ? <p className="text-sm text-warn">{error}</p> : null}
        <button disabled={busy} className="w-full rounded-xl bg-ink px-4 py-3 font-semibold text-bg disabled:opacity-50">{busy ? "در حال انجام..." : setupRequired ? "ساخت مدیر" : "ورود امن"}</button>
      </form>
    </AuthFrame>
  );
}

function ChangePasswordScreen({ user, onChanged }: { user: ApiUser; onChanged: () => void }) {
  const [current, setCurrent] = useState("");
  const [next, setNext] = useState("");
  const [error, setError] = useState("");
  const [busy, setBusy] = useState(false);
  async function submit(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    setBusy(true);
    setError("");
    try {
      await phpApi.changePassword(current, next);
      onChanged();
    } catch (err) {
      setError(err instanceof Error ? err.message : "تغییر رمز انجام نشد");
    } finally {
      setBusy(false);
    }
  }
  return <AuthFrame><form onSubmit={(event) => void submit(event)} className="w-full min-w-[min(84vw,360px)] space-y-3">
    <h1 className="text-xl font-bold">تغییر رمز عبور</h1>
    <p className="text-sm text-muted">{user.display_name}، پیش از ادامه باید رمز خود را تغییر دهید.</p>
    <Field label="رمز فعلی"><input className={inputCls} type="password" value={current} onChange={(e) => setCurrent(e.target.value)} required /></Field>
    <Field label="رمز جدید (حداقل ۱۲ نویسه)"><input className={inputCls} type="password" value={next} onChange={(e) => setNext(e.target.value)} minLength={12} required /></Field>
    {error ? <p className="text-sm text-warn">{error}</p> : null}
    <button disabled={busy} className="w-full rounded-xl bg-ink px-4 py-3 font-semibold text-bg disabled:opacity-50">{busy ? "در حال ذخیره..." : "ذخیره رمز جدید"}</button>
  </form></AuthFrame>;
}

function normalizeIranPhone(value:string){
  let digits=value.replace(/[۰-۹]/g,d=>String(d.charCodeAt(0)-1776)).replace(/[٠-٩]/g,d=>String(d.charCodeAt(0)-1632)).replace(/[^0-9+]/g,"");
  if(digits.startsWith("+98"))digits="0"+digits.slice(3);
  else if(digits.startsWith("0098"))digits="0"+digits.slice(4);
  else if(digits.startsWith("98")&&digits.length===12)digits="0"+digits.slice(2);
  return digits.replace(/\D/g,"");
}
function orderVars(order: Order, balance = "") {
  return { name: order.customerName || "مشتری", order: String(order.number), total: money(order.total), points: String(order.pointsEarned), code: order.discountCode, balance, shop: usePos.getState().settings.shopName };
}

async function pushSms(event: SmsEvent, phone: string, vars: Record<string, string>) {
  const s = usePos.getState();
  const tpl = s.templates.find((t) => t.event === event);
  if (!tpl?.enabled || !phone) return;
  const text = fillTemplate(tpl.body, { ...vars, shop: s.settings.shopName });
  await postSms(phone, text, event, tpl.bodyId);
}

async function sendRaw(phone: string, text: string) {
  const normalized=normalizeIranPhone(phone);
  if (!/^09[0-9]{9}$/.test(normalized) || !text) return false;
  return await postSms(normalized, text, "manual", "");
}

async function postSms(phone: string, text: string, event: string, bodyId: string) {
  let log: SmsLog = {
    id: uid("sm"),
    at: new Date().toISOString(),
    to: phone,
    event,
    text,
    ok: false,
    detail: "ارسال انجام نشد",
  };
  try {
    const data = await phpApi.sendSms({ to: phone, text, bodyId });
    log = { ...log, ok: data.sent, detail: data.detail };
  } catch (err) {
    log = { ...log, detail: err instanceof Error ? err.message : "خطا در ارسال پیامک" };
  }
  try {
    await phpApi.saveRecord("sms_logs", log);
  } catch (err) {
    log = { ...log, detail: `${log.detail} — ذخیره گزارش در سرور ناموفق بود: ${err instanceof Error ? err.message : "خطای نامشخص"}` };
  }
  usePos.getState().logSms(log);
  return log.ok;
}

function PosScreen() {
  const products = usePos((s) => s.products);
  const categories = usePos((s) => s.categories);
  const ingredients = usePos((s) => s.ingredients);
  const customers = usePos((s) => s.customers);
  const discounts = usePos((s) => s.discounts);
  const orders = usePos((s) => s.orders);
  const settings = usePos((s) => s.settings);
  const placeOrder = usePos((s) => s.placeOrder);
  const [cat, setCat] = useState("");
  const [productQuery, setProductQuery] = useState("");
  const [cart, setCart] = useState<CartLine[]>([]);
  const [phone, setPhone] = useState("");
  const [guestName, setGuestName] = useState("مشتری عزیز");
  const [code, setCode] = useState("");
  const [points, setPoints] = useState(0);
  const [pay, setPay] = useState<Order["payment"]>("cash");
  const [terminalOptions,setTerminalOptions]=useState<PaymentTerminal[]>([]);
  const [selectedTerminalId,setSelectedTerminalId]=useState("");
  const pendingTerminalPayment=useRef<{id:string;amount:number;terminalId:string}|null>(null);
  const [sendInvoiceSms, setSendInvoiceSms] = useState<boolean | null>(null);
  const shouldSendInvoiceSms = sendInvoiceSms ?? settings.invoiceSmsEnabledDefault ?? false;
  const [msg, setMsg] = useState("");
  const [submitting, setSubmitting] = useState(false);
  const pendingIdempotencyKey = useRef<string | null>(null);
  const [printed, setPrinted] = useState<Order | null>(null);
  const [optFor, setOptFor] = useState<Product | null>(null);
  const [picked, setPicked] = useState<string[]>([]);
  const [note, setNote] = useState("");
  const [stockWarningMutedUntil, setStockWarningMutedUntil] = useState(0);
  const [stockClock, setStockClock] = useState(INITIAL_RENDER_TIME);
  useEffect(()=>{let active=true;void phpApi.listAvailableTerminals().then(result=>{if(!active)return;setTerminalOptions(result.items);const preferred=result.items.find(t=>t.is_default||t.isDefault)??result.items[0];if(preferred)setSelectedTerminalId(preferred.id);}).catch(()=>{if(active)setTerminalOptions([]);});return()=>{active=false;};},[]);
  useEffect(() => {
    queueMicrotask(() => {
      try {
        setStockWarningMutedUntil(Number(window.localStorage.getItem("3food-pos-stock-warning-muted-until") || 0));
      } catch {
        setStockWarningMutedUntil(0);
      }
      setStockClock(Date.now());
    });
    const timer = window.setInterval(() => setStockClock(Date.now()), 30000);
    return () => window.clearInterval(timer);
  }, []);
  const requiredIngredients = new Map<string, number>();
  for (const line of cart) {
    const product = products.find((item) => item.id === line.productId);
    for (const recipeLine of product?.recipe ?? []) {
      requiredIngredients.set(recipeLine.ingredientId, (requiredIngredients.get(recipeLine.ingredientId) ?? 0) + recipeLine.qty * line.qty);
    }
  }
  const stockShortages = [...requiredIngredients.entries()].flatMap(([ingredientId, required]) => {
    const ingredient = ingredients.find((item) => item.id === ingredientId);
    return ingredient && ingredient.stock < required ? [{ name: ingredient.name, stock: ingredient.stock, required, unit: ingredient.unit }] : [];
  });
  const stockPolicy = settings.stockPolicy ?? "warn_and_allow";
  const showStockWarning = stockShortages.length > 0 && stockPolicy !== "allow_negative" &&
    (stockPolicy === "block" || stockClock >= stockWarningMutedUntil);
  function muteStockWarningForHour() {
    const until = Date.now() + 60 * 60 * 1000;
    setStockWarningMutedUntil(until);
    setStockClock(Date.now());
    try {
      window.localStorage.setItem("3food-pos-stock-warning-muted-until", String(until));
    } catch {
      // The alert remains dismissible for this session if storage is unavailable.
    }
  }
  const customer = customers.find((c) => normalizeIranPhone(c.phone) === normalizeIranPhone(phone)) ?? null;
  function updateCustomerPhone(value: string) {
    setPhone(value);
    const matched = customers.find((item) => normalizeIranPhone(item.phone) === normalizeIranPhone(value));
    setGuestName(matched?.name || "مشتری عزیز");
  }
  const uses = customer && code ? customerCodeUses(orders, customer.id, code) : 0;
  const q = quoteOrder({ lines: cart, customer, code, discounts, pointsToUse: points, taxPercent: settings.taxPercent, taxEnabled: settings.taxEnabled, tomanPerPoint: settings.tomanPerPoint, usesSoFar: uses, settings });

  function addProduct(p: Product, optionIds: string[], noteText: string) {
    const opts = p.options.filter((o) => optionIds.includes(o.id));
    const unit = p.price + opts.reduce((s, o) => s + o.price, 0);
    const key = `${p.id}:${[...optionIds].sort().join(",")}:${noteText}`;
    setCart((prev) => {
      const hit = prev.find((l) => l.key === key);
      if (hit) return prev.map((l) => (l.key === key ? { ...l, qty: l.qty + 1 } : l));
      return [...prev, { key, productId: p.id, name: p.name, qty: 1, unitPrice: unit, optionIds, optionLabels: opts.map((o) => o.name), note: noteText }];
    });
  }


  async function waitForTerminalApproval(amount:number):Promise<string> {
    if(pendingTerminalPayment.current&&pendingTerminalPayment.current.amount!==amount)
      throw new Error("یک پرداخت کارتخوان تأییدشده برای مبلغ قبلی در انتظار ثبت سفارش است؛ مبلغ سبد را تغییر ندهید و ابتدا سفارش را تعیین‌تکلیف کنید.");
    let pending=pendingTerminalPayment.current;
    if(!pending){
      const unresolved=(await phpApi.listPendingTerminalPayments()).items;
      if(unresolved.length>1)throw new Error("چند تراکنش تعیین‌تکلیف‌نشده وجود دارد؛ مدیر باید ابتدا وضعیت آن‌ها را بررسی کند.");
      if(unresolved.length===1){
        const previous=unresolved[0];
        if(previous.amount!==amount)throw new Error("یک تراکنش قبلی با مبلغ متفاوت در انتظار تعیین‌تکلیف است؛ پرداخت جدید مجاز نیست.");
        if(previous.status==="unknown")throw new Error("نتیجه تراکنش قبلی نامشخص است؛ قبل از پرداخت مجدد، از بانک استعلام کنید.");
        pending={id:previous.id,amount,terminalId:previous.terminal_id??""};
        pendingTerminalPayment.current=pending;
      }else{
        const available=terminalOptions.length?terminalOptions:(await phpApi.listAvailableTerminals()).items;
        setTerminalOptions(available);
        const terminal=available.find(t=>t.id===selectedTerminalId)??available.find(t=>t.is_default||t.isDefault)??available[0];
        if(!terminal)throw new Error("کارتخوان آنلاین و دارای درایور تأییدشده پیدا نشد؛ تنظیمات کارتخوان را بررسی کنید.");
        const created=await phpApi.createTerminalPayment({terminalId:terminal.id,amount,idempotencyKey:crypto.randomUUID()});
        pending={id:created.payment.id,amount,terminalId:terminal.id};pendingTerminalPayment.current=pending;
      }
    }
    for(let attempt=0;attempt<60;attempt++){
      const result=await phpApi.getTerminalPayment(pending.id);
      const status=result.payment.status;
      if(status==="approved")return pending.id;
      if(status==="declined")throw new Error("بانک تراکنش را رد کرد. مبلغی به‌عنوان پرداخت موفق ثبت نشد.");
      if(status==="cancelled")throw new Error("تراکنش کارتخوان لغو شد.");
      if(status==="unknown"||status==="expired")throw new Error("نتیجه تراکنش قطعی نیست. قبل از تلاش مجدد، وضعیت را از کارتخوان یا بانک استعلام کنید.");
      await new Promise<void>(resolve=>window.setTimeout(resolve,1500));
    }
    throw new Error("پاسخ کارتخوان در زمان مقرر نرسید. وضعیت تراکنش را بررسی کنید و پرداخت جدیدی ایجاد نکنید.");
  }

  async function checkout() {
    if (submitting || !cart.length) return;
    if (code.trim() && q.codeError) { setMsg(q.codeError); return; }
    if (q.pointsError) { setMsg(q.pointsError); return; }
    setSubmitting(true);
    setMsg("");
    try {
      if(pendingTerminalPayment.current&&pay!=="card")throw new Error("یک پرداخت کارتخوان تعیین‌تکلیف‌نشده وجود دارد؛ ابتدا همان سفارش را تکمیل کنید.");
      // Every identified buyer joins the shared customer club before the order is created.
      // The phone is the stable lookup key; the server then updates points and purchase totals.
      let checkoutCustomer = customer;
      if (checkoutCustomer && guestName.trim() && guestName.trim() !== checkoutCustomer.name) {
        const renamedCustomer = { ...checkoutCustomer, name: guestName.trim() };
        const savedCustomer = await phpApi.saveRecord("customers", renamedCustomer);
        checkoutCustomer = savedCustomer.item ?? renamedCustomer;
        usePos.getState().saveCustomer(checkoutCustomer);
      }
      if (!checkoutCustomer) {
        const normalizedPhone = normalizeIranPhone(phone);
        if (!/^09[0-9]{9}$/.test(normalizedPhone)) throw new Error("برای ثبت سفارش، شماره موبایل معتبر ایران را وارد کنید.");
        const existing = usePos.getState().customers.find((item) => normalizeIranPhone(item.phone) === normalizedPhone);
        if (existing) {
          checkoutCustomer = existing;
        } else {
          const createdCustomer = {
            id: uid("cu"),
            name: guestName.trim() || "مشتری عزیز",
            phone: normalizedPhone,
            points: 0,
            birthday: "",
            totalSpent: 0,
            visits: 0,
            createdAt: new Date().toISOString(),
          };
          const savedCustomer = await phpApi.saveRecord("customers", createdCustomer);
          usePos.getState().saveCustomer(savedCustomer.item ?? createdCustomer);
          checkoutCustomer = createdCustomer;
        }
      }
      const terminalPaymentId = pay==="card" ? await waitForTerminalApproval(q.total) : null;
      const idempotencyKey = pendingIdempotencyKey.current ?? crypto.randomUUID();
      pendingIdempotencyKey.current = idempotencyKey;
      const server = await phpApi.createOrder<{ id: string; orderNumber: number; createdAt: string }>({
        idempotencyKey,
        items: cart.map((line) => ({
          key: line.key,
          name: line.name,
          productId: line.productId,
          qty: line.qty,
          unitPrice: line.unitPrice,
          optionIds: line.optionIds,
          optionLabels: line.optionLabels,
          note: line.note,
        })),
        totals: {
          subtotal: q.subtotal,
          tierDiscount: q.tierDiscount,
          codeDiscount: q.codeDiscount,
          pointsDiscount: q.pointsDiscount,
          tax: q.tax,
          total: q.total,
        },
        customer: checkoutCustomer ? { id: checkoutCustomer.id } : undefined,
        discountCode: q.discount?.code ?? '',
        note: cart.map((line) => line.note).filter(Boolean).join(" | ").slice(0, 2000),
        guestName,
        guestPhone: normalizeIranPhone(phone),
        payment: pay,
        ...(terminalPaymentId ? { terminalPaymentId } : {}),
      });
      const res = placeOrder({
        lines: cart, customer: checkoutCustomer, code, pointsToUse: points, taxEnabled: settings.taxEnabled !== false, payment: pay,
        guestName, guestPhone: phone.trim(),
        serverOrder: { id: server.order.id, number: server.order.orderNumber, createdAt: server.order.createdAt },
      });
      if (!res.ok) {
        setMsg(`سفارش در سرور ثبت شد اما همگام‌سازی محلی انجام نشد: ${res.error}. صفحه را تازه‌سازی کنید.`);
        return;
      }
      pendingIdempotencyKey.current = null;
      pendingTerminalPayment.current = null;
      setMsg(`سفارش ${res.order.number.toLocaleString("fa-IR")} ثبت شد`);
      setCart([]); setPhone(""); setGuestName("مشتری عزیز"); setCode(""); setPoints(0); setSendInvoiceSms(null); setPrinted(res.order);
      const fresh = usePos.getState().customers.find((c) => c.id === res.order.customerId);
      const vars = orderVars(res.order, String(fresh?.points ?? 0));
      void pushSms("order_created", res.order.customerPhone, vars);
      if (shouldSendInvoiceSms && res.order.customerPhone) {
        const invoice = [`${settings.shopName} - فاکتور سفارش ${res.order.number}`, ...res.order.lines.map(line => `${line.name} × ${line.qty}: ${money(line.unitPrice * line.qty)}`), `جمع: ${money(res.order.subtotal)}`, `تخفیف: ${money(res.order.tierDiscount + res.order.codeDiscount + res.order.pointsDiscount)}`, `مالیات: ${money(res.order.tax)}`, `مبلغ نهایی: ${money(res.order.total)}`, `تاریخ: ${new Date(res.order.createdAt).toLocaleString("fa-IR",{timeZone:"Asia/Tehran"})}`].join("\n").slice(0, 950);
        void postSms(res.order.customerPhone, invoice, "order_invoice", "");
      }
      if (res.order.pointsEarned) void pushSms("points_earned", res.order.customerPhone, vars);
      if (res.order.discountCode) void pushSms("discount_used", res.order.customerPhone, vars);
      if (settings.autoPrint) setTimeout(() => window.print(), 300);
    } catch (error) {
      setMsg(error instanceof Error ? error.message : "ثبت سفارش در سرور انجام نشد");
    } finally {
      setSubmitting(false);
    }
  }

  const shown = products.filter((p) =>
    p.active &&
    (!cat || p.categoryId === cat) &&
    (!productQuery.trim() || p.name.toLocaleLowerCase("fa").includes(productQuery.trim().toLocaleLowerCase("fa")))
  );
  return (
    <div className="grid gap-4 lg:grid-cols-[1fr_320px]">
      <div className="lg:col-span-2"><PageHeading title="صندوق فروش" description="محصول را انتخاب کنید، سفارش را بسازید و پرداخت را ثبت کنید." /></div>
      <div>
        <div className="mb-3 space-y-2">
          <input className={inputCls} value={productQuery} onChange={(e) => setProductQuery(e.target.value)} placeholder="جستجوی نام محصول..." />
          <div className="flex min-w-0 flex-wrap gap-2">
            <button onClick={() => setCat("")} className={`rounded-full px-4 py-2 text-sm ${cat === "" ? "bg-ink text-bg" : "bg-surface border border-line"}`}>همه دسته‌ها</button>
            {categories.map((c) => (
              <button key={c.id} onClick={() => setCat(c.id)} className={`rounded-full px-4 py-2 text-sm ${cat === c.id ? "bg-ink text-bg" : "bg-surface border border-line"}`}>{c.name}</button>
            ))}
          </div>
        </div>
        <div className="grid grid-cols-2 gap-3 sm:grid-cols-3">
          {shown.map((p) => (
            <button key={p.id} onClick={() => { if (p.options.length) { setOptFor(p); setPicked([]); setNote(""); } else addProduct(p, [], ""); }} className="rounded-2xl border border-line bg-surface p-3 text-right shadow-sm">
              <p className="font-semibold">{p.name}</p>
              <p className="mt-2 text-sm text-chili">{money(p.price)}</p>
            </button>
          ))}
        </div>
      </div>
      <Card>
        <h2 className="mb-3 font-bold">سفارش جاری</h2>
        {showStockWarning ? (
          <div role="status" className="mb-3 rounded-xl border border-warn bg-warn-bg p-3 text-sm text-warn">
            <p className="font-bold">هشدار کمبود موجودی</p>
            <p className="mt-1">{stockShortages.map((item) => `${item.name}: موجودی ${item.stock.toLocaleString("fa-IR")}، نیاز سفارش ${item.required.toLocaleString("fa-IR")} ${item.unit}`).join(" · ")}</p>
            <p className="mt-1">{stockPolicy === "block" ? "ثبت سفارش تا تأمین موجودی متوقف می‌شود." : "ثبت سفارش ادامه پیدا می‌کند و کسری موجودی در انبار ثبت خواهد شد."}</p>
            {stockPolicy === "warn_and_allow" ? <Btn tone="ghost" onClick={muteStockWarningForHour}>خاموش‌کردن هشدار برای یک ساعت</Btn> : null}
          </div>
        ) : null}
        {cart.length === 0 ? <Empty text="آیتمی انتخاب نشده" /> : null}
        <ul className="space-y-2">
          {cart.map((l) => (
            <li key={l.key} className="flex items-center justify-between gap-2 text-sm">
              <div>
                <p className="font-semibold">{l.name}</p>
                <p className="text-xs text-muted">{l.optionLabels.join("، ")}</p>
              </div>
              <div className="flex items-center gap-1">
                <button className="rounded-lg border border-line px-3 py-2" onClick={() => setCart((c) => c.flatMap((x) => (x.key === l.key ? (x.qty > 1 ? [{ ...x, qty: x.qty - 1 }] : []) : [x])))}>−</button>
                <span>{l.qty.toLocaleString("fa-IR")}</span>
                <button className="rounded-lg border border-line px-3 py-2" onClick={() => setCart((c) => c.map((x) => (x.key === l.key ? { ...x, qty: x.qty + 1 } : x)))}>+</button>
              </div>
            </li>
          ))}
        </ul>
        <div className="mt-3 space-y-2">
          <Field label="نام مشتری"><input className={inputCls} value={guestName} onChange={(e) => setGuestName(e.target.value)} placeholder="مشتری عزیز" /></Field>
          <Field label="موبایل مشتری"><input className={inputCls} value={phone} onChange={(e) => updateCustomerPhone(e.target.value)} placeholder="0912..." /></Field>
          {customer ? (
            <p className="rounded-xl bg-leaf-ink px-3 py-2 text-sm text-leaf">{TIER_LABEL[tierOf(customer.points,settings)]} ({customer.points.toLocaleString("fa-IR")} امتیاز، تخفیف {(tierOf(customer.points,settings)==="silver"?settings.loyaltySilverDiscount??3:tierOf(customer.points,settings)==="gold"?settings.loyaltyGoldDiscount??5:tierOf(customer.points,settings)==="platinum"?settings.loyaltyPlatinumDiscount??8:0).toLocaleString("fa-IR")}٪)</p>
          ) : null}
          <Field label="کد تخفیف"><input className={inputCls} value={code} onChange={(e) => setCode(e.target.value)} placeholder="WELCOME" /></Field>
          {q.codeError ? <p className="text-sm text-warn">{q.codeError}</p> : null}
          {customer ? <Field label="استفاده از امتیاز"><input className={inputCls} type="number" min={0} value={points} onChange={(e) => setPoints(Number(e.target.value))} /></Field> : null}
          {q.pointsError ? <p className="text-sm text-warn">{q.pointsError}</p> : null}
          <div className="flex gap-2">
            {(["cash", "card"] as const).map((p) => (
              <button key={p} onClick={() => setPay(p)} className={`flex-1 rounded-xl py-3 text-sm ${pay === p ? "bg-ink text-bg" : "border border-line"}`}>{p === "cash" ? "نقد" : "کارتخوان"}</button>
            ))}
            <button type="button" disabled title="پس از اتصال درگاه بانکی فعال می‌شود" className="flex-1 cursor-not-allowed rounded-xl border border-line py-3 text-sm opacity-50">آنلاین (به‌زودی)</button>
            {pay==="card"?<div className="mt-2 w-full"><Field label="کارتخوان"><select className={inputCls} value={selectedTerminalId} onChange={e=>setSelectedTerminalId(e.target.value)}><option value="">انتخاب کارتخوان آنلاین</option>{terminalOptions.map(t=><option key={t.id} value={t.id}>{t.name} · {t.provider_code??"شرکت نامشخص"}</option>)}</select></Field><p className="mt-1 text-xs text-muted">مبلغ نهایی سفارش ({money(q.total)}) پس از تأیید به کارتخوان ارسال می‌شود.</p></div>:null}
          </div>
          <label className="flex items-center gap-2 text-sm"><input type="checkbox" checked={shouldSendInvoiceSms} onChange={e=>setSendInvoiceSms(e.target.checked)}/>ارسال پیامک ریز فاکتور برای مشتری</label>
          <p className="flex justify-between text-sm"><span>جمع</span><span>{money(q.subtotal)}</span></p>
          {q.tierDiscount ? <p className="flex justify-between text-sm"><span>سطح عضویت</span><span>{money(q.tierDiscount)}</span></p> : null}
          {q.codeDiscount ? <p className="flex justify-between text-sm"><span>کد</span><span>{money(q.codeDiscount)}</span></p> : null}
          <p className="flex justify-between text-base font-bold"><span>قابل پرداخت</span><span>{money(q.total)}</span></p>
          {msg ? <p className="text-sm text-leaf">{msg}</p> : null}
          <Btn onClick={checkout} disabled={!cart.length || submitting}>{submitting ? "در حال ثبت در سرور..." : "ثبت سفارش"}</Btn>
        </div>
      </Card>
      {optFor ? (
        <div className="fixed inset-0 z-30 flex items-end justify-center bg-ink/40 p-4 sm:items-center">
          <Card className="w-full max-w-md">
            <h3 className="mb-2 font-bold">{optFor.name}</h3>
            {optFor.options.map((o) => (
              <label key={o.id} className="mt-2 flex items-center justify-between text-sm">
                <span><input type="checkbox" className="ms-2" checked={picked.includes(o.id)} onChange={() => setPicked((ids) => ids.includes(o.id) ? ids.filter((x) => x !== o.id) : [...ids, o.id])} />{o.name}</span>
                <span>{o.price ? money(o.price) : "رایگان"}</span>
              </label>
            ))}
            <Field label="یادداشت آشپزخانه"><input className={inputCls} value={note} onChange={(e) => setNote(e.target.value)} /></Field>
            <div className="mt-3 flex gap-2">
              <Btn onClick={() => { addProduct(optFor, picked, note); setOptFor(null); }}>افزودن</Btn>
              <Btn tone="ghost" onClick={() => setOptFor(null)}>انصراف</Btn>
            </div>
          </Card>
        </div>
      ) : null}
      {printed ? (
        <>
          <div id="print-root" className="hidden print:block">
            {settings.printCustomer ? <ReceiptView order={printed} settings={settings} kind="customer" /> : null}
            {settings.printKitchen ? <ReceiptView order={printed} settings={settings} kind="kitchen" /> : null}
          </div>
          <Card className="lg:col-span-2">
            <div className="mb-2 flex items-center justify-between"><h3 className="font-bold">پیش‌نمایش فیش</h3><Btn tone="ghost" onClick={() => setPrinted(null)}>بستن</Btn></div>
            <div className="grid gap-4 sm:grid-cols-2">
              <ReceiptView order={printed} settings={settings} kind="customer" />
              <ReceiptView order={printed} settings={settings} kind="kitchen" />
            </div>
            <div className="mt-2"><Btn onClick={() => window.print()}>چاپ فیش</Btn></div>
          </Card>
        </>
      ) : null}
    </div>
  );
}

function statusFa(s: Order["status"]) {
  return { new: "جدید", preparing: "در حال آماده‌سازی", ready: "آماده", delivered: "تحویل شد", cancelled: "لغو" }[s];
}

function tehranDate(value:Date|string|number = new Date()){
  return new Intl.DateTimeFormat("en-CA",{timeZone:"Asia/Tehran",year:"numeric",month:"2-digit",day:"2-digit"}).format(new Date(value));
}
function formatElapsed(ms:number){
  const total=Math.max(0,Math.floor(ms/60000));const days=Math.floor(total/1440);const hours=Math.floor((total%1440)/60);const minutes=total%60;
  return days>0?`${days.toLocaleString("fa-IR")} روز و ${hours.toLocaleString("fa-IR")} ساعت`:hours>0?`${hours.toLocaleString("fa-IR")} ساعت و ${minutes.toLocaleString("fa-IR")} دقیقه`:`${minutes.toLocaleString("fa-IR")} دقیقه`;
}

function OrdersScreen({ role }: { role: Role }) {
  const orders = usePos((s) => s.orders);
  const settings = usePos((s) => s.settings);
  const products = usePos((s) => s.products);
  const customers = usePos((s) => s.customers);
  const discounts = usePos((s) => s.discounts);
  const setStatus = usePos((s) => s.setStatus);
  const [focus, setFocus] = useState<Order | null>(null);
  const [clockNow, setClockNow] = useState(INITIAL_RENDER_TIME);
  useEffect(() => { queueMicrotask(() => setClockNow(Date.now())); const timer=window.setInterval(()=>setClockNow(Date.now()),30000); return ()=>window.clearInterval(timer); }, []);
  const [editing, setEditing] = useState<Order | null>(null);
  const [editLines, setEditLines] = useState<CartLine[]>([]);
  const [editPhone, setEditPhone] = useState("");
  const [editGuestName, setEditGuestName] = useState("");
  const [editCode, setEditCode] = useState("");
  const [editPoints, setEditPoints] = useState(0);
  const [editPayment, setEditPayment] = useState<Order["payment"]>("cash");
  const [addProductId, setAddProductId] = useState("");
  const [editBusy, setEditBusy] = useState(false);
  const steps: Order["status"][] = role === "kitchen"
    ? ["preparing", "ready"]
    : role === "manager" ? ["preparing", "ready", "delivered", "cancelled"] : [];
  const editCustomer = customers.find((c) => c.phone === editPhone.trim()) ?? null;
  const editCodeUses = editCustomer && editCode
    ? Math.max(0, customerCodeUses(orders, editCustomer.id, editCode) - (editing?.customerId === editCustomer.id && editing.discountCode.toUpperCase() === editCode.trim().toUpperCase() ? 1 : 0))
    : 0;
  const editQuoteCustomer = editCustomer && editing?.customerId === editCustomer.id
    ? { ...editCustomer, points: Math.max(0, editCustomer.points + editing.pointsUsed - editing.pointsEarned) }
    : editCustomer;
  const editDiscounts = discounts.map((discount) =>
    editing && discount.code.toUpperCase() === editing.discountCode.toUpperCase()
      ? { ...discount, usedCount: Math.max(0, discount.usedCount - 1) }
      : discount,
  );
  const editQuote = quoteOrder({
    lines: editLines,
    customer: editQuoteCustomer,
    code: editCode,
    discounts: editDiscounts,
    pointsToUse: editPoints,
    taxPercent: settings.taxPercent,
    taxEnabled: settings.taxEnabled !== false,
    tomanPerPoint: settings.tomanPerPoint,
    usesSoFar: editCodeUses,
    settings,
  });
  function beginEdit(order: Order) {
    setEditing(order);
    setEditLines(order.lines.map((line) => {
      const product = products.find((item) => item.id === line.productId);
      const options = product?.options.filter((option) => line.optionIds.includes(option.id)) ?? [];
      return {
        ...line,
        name: product?.name ?? line.name,
        unitPrice: product ? product.price + options.reduce((sum, option) => sum + option.price, 0) : line.unitPrice,
        optionIds: [...line.optionIds],
        optionLabels: options.length ? options.map((option) => option.name) : [...line.optionLabels],
      };
    }));
    setEditPhone(order.customerPhone);
    setEditGuestName(order.customerName);
    setEditCode(order.discountCode);
    setEditPoints(order.pointsUsed);
    setEditPayment(order.payment);
    setAddProductId("");
  }
  function canMove(from: Order["status"], to: Order["status"]) {
    const allowed: Record<Order["status"], Order["status"][]> = {
      new: role === "manager" ? ["preparing", "cancelled"] : ["preparing"],
      preparing: role === "manager" ? ["ready", "cancelled"] : ["ready"],
      ready: role === "manager" ? ["delivered", "cancelled"] : [],
      delivered: [],
      cancelled: [],
    };
    return allowed[from].includes(to);
  }
  async function jump(order: Order, status: Order["status"]) {
    try {
      if (/^\d+$/.test(order.id)) await phpApi.setOrderStatus(order.id, status);
      setStatus(order.id, status);
      try {
        const snapshot = await phpApi.bootstrap<ServerBootstrap>();
        hydratePosFromServer(snapshot.data, snapshot.next_order, role);
      } catch {
        // The status transition is committed; the next poll refreshes the snapshot.
      }
      const next = { ...order, status };
      if (status === "ready") void pushSms("order_ready", order.customerPhone, orderVars(next));
      if (status === "delivered") void pushSms("order_delivered", order.customerPhone, orderVars(next));
    } catch (error) {
      window.alert(error instanceof Error ? error.message : "تغییر وضعیت در سرور انجام نشد");
    }
  }
  function addEditProduct() {
    const product = products.find((p) => p.id === addProductId && p.active);
    if (!product) return;
    if (product.options.length) {
      window.alert("برای افزودن این محصول ابتدا گزینه‌های آن باید در منو انتخاب شوند؛ می‌توانید اقلام فعلی را ویرایش کنید.");
      return;
    }
    const key = `edit:${product.id}`;
    setEditLines((current) => {
      const hit = current.find((line) => line.key === key);
      return hit
        ? current.map((line) => line.key === key ? { ...line, qty: line.qty + 1 } : line)
        : [...current, { key, productId: product.id, name: product.name, qty: 1, unitPrice: product.price, optionIds: [], optionLabels: [], note: "" }];
    });
    setAddProductId("");
  }
  async function saveEdit() {
    if (!editing || editBusy || !editLines.length) return;
    if (editing.payment !== "cash") {
      window.alert("ویرایش سفارش دارای پرداخت کارتخوان یا پرداخت آنلاین قدیمی تا تعیین‌تکلیف مالی و بازپرداخت امکان‌پذیر نیست.");
      return;
    }
    if (editCode.trim() && editQuote.codeError) { window.alert(editQuote.codeError); return; }
    if (editQuote.pointsError) { window.alert(editQuote.pointsError); return; }
    setEditBusy(true);
    try {
      await phpApi.updateOrder(editing.id, {
        items: editLines.map((line) => ({
          key: line.key,
          name: line.name,
          productId: line.productId,
          qty: line.qty,
          unitPrice: line.unitPrice,
          optionIds: line.optionIds,
          optionLabels: line.optionLabels,
          note: line.note,
        })),
        totals: {
          subtotal: editQuote.subtotal,
          tierDiscount: editQuote.tierDiscount,
          codeDiscount: editQuote.codeDiscount,
          pointsDiscount: editQuote.pointsDiscount,
          tax: editQuote.tax,
          total: editQuote.total,
        },
        customer: editCustomer ? { id: editCustomer.id } : undefined,
        discountCode: editQuote.discount?.code ?? "",
        note: editLines.map((line) => line.note).filter(Boolean).join(" | ").slice(0, 2000),
        guestName: editGuestName.trim() || "مشتری",
        guestPhone: editPhone.trim(),
        payment: editPayment,
      });
      try {
        const snapshot = await phpApi.bootstrap<ServerBootstrap>();
        hydratePosFromServer(snapshot.data, snapshot.next_order, role);
      } catch {
        // Save succeeded; periodic bootstrap will refresh the local order list.
      }
      setEditing(null);
      window.alert("ویرایش سفارش ذخیره شد.");
    } catch (error) {
      window.alert(error instanceof Error ? error.message : "ویرایش سفارش در سرور انجام نشد");
    } finally {
      setEditBusy(false);
    }
  }
  return (
    <div className="space-y-3">
      <PageHeading title="مدیریت سفارش‌ها" description="وضعیت سفارش‌ها، زمان آماده‌سازی و فیش هر سفارش را پیگیری کنید." icon={<ChefHat size={20} />} />
      {orders.length === 0 ? <Empty text="هنوز سفارشی ثبت نشده" /> : null}
      {orders.map((o) => (
        <Card key={o.id}>
          <div className="flex flex-wrap items-center justify-between gap-2">
            <div>
              <p className="font-bold">#{o.number.toLocaleString("fa-IR")} — {o.customerName}</p>
              <p className="text-sm text-muted">{o.lines.map((l) => `${l.qty} ${l.name}`).join(" · ")} — {money(o.total)}</p>
              <p className="text-xs text-muted">ثبت: {new Date(o.createdAt).toLocaleString("fa-IR",{timeZone:"Asia/Tehran"})} · زمان سپری‌شده: {formatElapsed(clockNow-new Date(o.createdAt).getTime())}</p>
            </div>
            <span className={`order-status order-status-${o.status}`}>{statusFa(o.status)}</span>
          </div>
          <div className="mt-3 flex flex-wrap gap-2">
            {steps.filter((st) => canMove(o.status, st)).map((st) => <Btn key={st} tone="ghost" onClick={() => jump(o, st)}>{statusFa(st)}</Btn>)}
            {role === "manager" && o.status === "new" && o.payment === "cash" && /^\d+$/.test(o.id) ? <Btn tone="ghost" onClick={() => beginEdit(o)}>ویرایش سفارش</Btn> : null}
            <Btn tone="ghost" onClick={() => setFocus(o)}>فیش</Btn>
          </div>
        </Card>
      ))}
      {editing ? (
        <div className="fixed inset-0 z-40 flex items-end justify-center overflow-y-auto bg-ink/50 p-3 sm:items-center">
          <Card className="my-auto w-full max-w-2xl space-y-3">
            <div className="flex items-center justify-between gap-2">
              <h3 className="font-bold">ویرایش سفارش #{editing.number.toLocaleString("fa-IR")}</h3>
              <Btn tone="ghost" onClick={() => setEditing(null)}>بستن</Btn>
            </div>
            <p className="text-xs text-muted">فقط سفارش جدید قابل ویرایش است؛ قیمت و موجودی نهایی در سرور دوباره بررسی می‌شود.</p>
            <div className="space-y-2">
              {editLines.map((line) => (
                <div key={line.key} className="flex flex-wrap items-center justify-between gap-2 rounded-xl border border-line p-2">
                  <div className="min-w-0 flex-1">
                    <p className="font-semibold">{line.name}</p>
                    <p className="text-xs text-muted">{line.optionLabels.join("، ")}</p>
                    <input className={inputCls + " mt-1"} aria-label={"یادداشت " + line.name} value={line.note} onChange={(e) => setEditLines((all) => all.map((x) => x.key === line.key ? { ...x, note: e.target.value } : x))} placeholder="یادداشت" />
                  </div>
                  <div className="flex items-center gap-1">
                    <Btn tone="ghost" onClick={() => setEditLines((all) => all.flatMap((x) => x.key === line.key ? (x.qty > 1 ? [{ ...x, qty: x.qty - 1 }] : []) : [x]))}>−</Btn>
                    <span className="min-w-7 text-center">{line.qty.toLocaleString("fa-IR")}</span>
                    <Btn tone="ghost" onClick={() => setEditLines((all) => all.map((x) => x.key === line.key ? { ...x, qty: x.qty + 1 } : x))}>+</Btn>
                    <Btn tone="ghost" onClick={() => setEditLines((all) => all.filter((x) => x.key !== line.key))}>حذف</Btn>
                  </div>
                </div>
              ))}
            </div>
            <div className="flex gap-2">
              <select className={inputCls + " min-w-0 flex-1"} value={addProductId} onChange={(e) => setAddProductId(e.target.value)}>
                <option value="">افزودن محصول ساده…</option>
                {products.filter((p) => p.active && p.options.length === 0).map((p) => <option key={p.id} value={p.id}>{p.name} — {money(p.price)}</option>)}
              </select>
              <Btn tone="ghost" onClick={addEditProduct} disabled={!addProductId}>افزودن</Btn>
            </div>
            <div className="grid gap-2 sm:grid-cols-2">
              <Field label="موبایل مشتری"><input className={inputCls} value={editPhone} onChange={(e) => setEditPhone(e.target.value)} /></Field>
              {editCustomer ? <p className="self-end text-sm text-leaf">{editCustomer.name} — {editCustomer.points.toLocaleString("fa-IR")} امتیاز</p> : <Field label="نام مشتری مهمان"><input className={inputCls} value={editGuestName} onChange={(e) => setEditGuestName(e.target.value)} /></Field>}
              <Field label="کد تخفیف"><input className={inputCls} value={editCode} onChange={(e) => setEditCode(e.target.value)} /></Field>
              {editCustomer ? <Field label="امتیاز مصرفی"><input className={inputCls} type="number" min={0} value={editPoints} onChange={(e) => setEditPoints(Math.max(0, Number(e.target.value)))} /></Field> : null}
            </div>
            <p className="rounded-lg bg-surface px-3 py-2 text-sm text-muted">روش پرداخت ثبت‌شده: {editing.payment === "cash" ? "نقد" : editing.payment === "card" ? "کارتخوان" : "آنلاین (قدیمی)"} — روش پرداخت پس از ثبت سفارش قابل تغییر نیست.</p>
            {editQuote.codeError ? <p className="text-sm text-warn">{editQuote.codeError}</p> : null}
            {editQuote.pointsError ? <p className="text-sm text-warn">{editQuote.pointsError}</p> : null}
            <div className="space-y-1 rounded-xl bg-surface p-3 text-sm">
              <p className="flex justify-between"><span>جمع اقلام</span><span>{money(editQuote.subtotal)}</span></p>
              <p className="flex justify-between"><span>تخفیف‌ها</span><span>{money(editQuote.tierDiscount + editQuote.codeDiscount + editQuote.pointsDiscount)}</span></p>
              <p className="flex justify-between font-bold"><span>قابل پرداخت</span><span>{money(editQuote.total)}</span></p>
            </div>
            <div className="flex gap-2">
              <Btn onClick={saveEdit} disabled={editBusy || !editLines.length || !!editQuote.pointsError || !!editQuote.codeError}>{editBusy ? "در حال ذخیره…" : "ذخیره ویرایش"}</Btn>
              <Btn tone="ghost" onClick={() => setEditing(null)} disabled={editBusy}>انصراف</Btn>
            </div>
          </Card>
        </div>
      ) : null}
      {focus ? (
        <Card>
          <div id="print-root" className="hidden print:block">
            <ReceiptView order={focus} settings={settings} kind="customer" />
            <ReceiptView order={focus} settings={settings} kind="kitchen" />
          </div>
          <ReceiptView order={focus} settings={settings} kind="customer" />
          <div className="mt-2 flex gap-2"><Btn onClick={() => window.print()}>چاپ</Btn><Btn tone="ghost" onClick={() => setFocus(null)}>بستن</Btn></div>
        </Card>
      ) : null}
    </div>
  );
}

function MenuScreen() {
  const categories = usePos((s) => s.categories);
  const products = usePos((s) => s.products);
  const ingredients = usePos((s) => s.ingredients);
  const saveCategory = usePos((s) => s.saveCategory);
  const saveProduct = usePos((s) => s.saveProduct);
  const removeProduct = usePos((s) => s.removeProduct);
  const [name, setName] = useState("");
  const [draft, setDraft] = useState<Product | null>(null);
  const [productCategory, setProductCategory] = useState("all");
  const visibleProducts = products.filter((product) => productCategory === "all" || product.categoryId === productCategory);
  return (
    <div className="space-y-4">
      <PageHeading title="مدیریت منو و محصولات" description="دسته‌ها، قیمت‌ها و ترکیب مواد اولیه محصولات را یکپارچه مدیریت کنید." />
      <Card>
        <h3 className="mb-2 font-bold">دسته‌بندی</h3>
        <form className="flex gap-2" onSubmit={(e) => { e.preventDefault(); if (!name.trim()) return; const item = { id: uid("c"), name: name.trim() }; void saveCentralRecord("categories", item, () => saveCategory(item)).then((ok) => { if (ok) setName(""); }); }}>
          <input className={inputCls} value={name} onChange={(e) => setName(e.target.value)} placeholder="نام دسته" />
          <Btn type="submit">افزودن</Btn>
        </form>
        <p className="mt-2 text-sm text-muted">{categories.map((c) => c.name).join(" · ")}</p>
      </Card>
      <div className="flex justify-between"><h2 className="font-bold">محصولات</h2>
        <Btn onClick={() => setDraft({ id: uid("p"), name: "", categoryId: categories[0]?.id ?? "", price: 0, cost: 0, options: [], recipe: [], active: true })}>محصول جدید</Btn>
      </div>
      <Field label="فیلتر دسته‌بندی محصولات">
        <select className={inputCls} value={productCategory} onChange={(e) => setProductCategory(e.target.value)}>
          <option value="all">همه دسته‌ها</option>
          {categories.map((category) => <option key={category.id} value={category.id}>{category.name}</option>)}
        </select>
      </Field>
      {visibleProducts.map((p) => (
        <Card key={p.id} className="flex items-center justify-between gap-2">
          <div><p className="font-semibold">{p.name}</p><p className="text-sm text-muted">{money(p.price)} — تمام‌شده {money(p.cost)}</p></div>
          <div className="flex gap-2"><Btn tone="ghost" onClick={() => setDraft(p)}>ویرایش</Btn><Btn tone="ghost" onClick={() => void deleteCentralRecord("products", p.id, () => removeProduct(p.id))}>حذف</Btn></div>
        </Card>
      ))}
      {draft ? (
        <Card>
          <div className="grid gap-2 sm:grid-cols-2">
            <Field label="نام"><input className={inputCls} value={draft.name} onChange={(e) => setDraft({ ...draft, name: e.target.value })} /></Field>
            <Field label="دسته"><select className={inputCls} value={draft.categoryId} onChange={(e) => setDraft({ ...draft, categoryId: e.target.value })}>{categories.map((c) => <option key={c.id} value={c.id}>{c.name}</option>)}</select></Field>
            <Field label="قیمت فروش"><input className={inputCls} type="number" value={draft.price} onChange={(e) => setDraft({ ...draft, price: Number(e.target.value) })} /></Field>
            <Field label="قیمت تمام‌شده"><input className={inputCls} type="number" value={draft.cost} onChange={(e) => setDraft({ ...draft, cost: Number(e.target.value) })} /></Field>
          </div>
          <p className="mt-3 text-sm font-semibold">مواد اولیه هر پرس</p>
          {ingredients.map((ing) => {
            const line = draft.recipe.find((r) => r.ingredientId === ing.id);
            return (
              <label key={ing.id} className="mt-1 flex items-center justify-between gap-2 text-sm">
                <span>{ing.name}</span>
                <input className={`${inputCls} max-w-28`} type="number" value={line?.qty ?? 0} onChange={(e) => {
                  const qty = Number(e.target.value);
                  const recipe = draft.recipe.filter((r) => r.ingredientId !== ing.id);
                  setDraft({ ...draft, recipe: qty > 0 ? [...recipe, { ingredientId: ing.id, qty }] : recipe });
                }} />
              </label>
            );
          })}
          <div className="mt-3 flex gap-2"><Btn onClick={() => { if (draft.name) void saveCentralRecord("products", draft, () => saveProduct(draft)).then((ok) => { if (ok) setDraft(products.some((item) => item.id === draft.id) ? null : { id: uid("p"), name: "", categoryId: draft.categoryId || categories[0]?.id || "", price: 0, cost: 0, options: [], recipe: [], active: true }); }); }}>ذخیره</Btn><Btn tone="ghost" onClick={() => setDraft(null)}>بستن</Btn></div>
        </Card>
      ) : null}
    </div>
  );
}

function StockScreen() {
  const ingredients = usePos((s) => s.ingredients);
  const saveIngredient = usePos((s) => s.saveIngredient);
  const lows = lowStock(ingredients);
  const [name, setName] = useState("");
  const [unit, setUnit] = useState("عدد");
  return (
    <div className="space-y-3">
      <PageHeading title="انبار مواد اولیه" description="موجودی، حد هشدار و تغییرات روزانه مواد اولیه را کنترل کنید." />
      {lows.length ? <Card className="bg-warn-bg text-warn">کمبود: {lows.map((i) => i.name).join("، ")}</Card> : <p className="text-sm text-leaf">موجودی بالای حد هشدار است.</p>}
      <form className="grid gap-2 sm:grid-cols-3" onSubmit={(e) => { e.preventDefault(); if (!name.trim()) return; const item = { id: uid("i"), name: name.trim(), unit, stock: 0, minStock: 5, costPerUnit: 0 }; void saveCentralRecord("ingredients", item, () => saveIngredient(item)).then((ok) => { if (ok) setName(""); }); }}>
        <input className={inputCls} placeholder="ماده جدید" value={name} onChange={(e) => setName(e.target.value)} />
        <input className={inputCls} placeholder="واحد" value={unit} onChange={(e) => setUnit(e.target.value)} />
        <Btn type="submit">افزودن</Btn>
      </form>
      {ingredients.map((i) => (
        <Card key={i.id}>
          <div className="flex flex-wrap items-center justify-between gap-2">
            <div>
              <p className="font-semibold">{i.name}</p>
              <p className={`text-sm ${i.stock <= i.minStock ? "text-warn" : "text-muted"}`}>موجودی {i.stock.toLocaleString("fa-IR")} {i.unit}</p>
            </div>
            <div className="flex gap-2">
              <Btn tone="ghost" onClick={() => { void adjustCentralStock<typeof i>(i.id, -1, (item) => saveIngredient(item)); }}>−۱</Btn>
              <Btn tone="ghost" onClick={() => { void adjustCentralStock<typeof i>(i.id, 1, (item) => saveIngredient(item)); }}>+۱</Btn>
              <Btn tone="leaf" onClick={() => { void adjustCentralStock<typeof i>(i.id, 10, (item) => saveIngredient(item)); }}>+۱۰</Btn>
            </div>
          </div>
        </Card>
      ))}
    </div>
  );
}

function CustomersScreen({ role }: { role: Role }) {
  const customers = usePos((s) => s.customers);
  const settings = usePos((s) => s.settings);
  const patchSettings = usePos((s) => s.patchSettings);
  const orders = usePos((s) => s.orders);
  const saveCustomer = usePos((s) => s.saveCustomer);
  const [q, setQ] = useState("");
  const [selectedCustomerId, setSelectedCustomerId] = useState<string | null>(null);
  const [form, setForm] = useState({ name: "", phone: "", birthday: "", smsConsent: false });
  const normalizedQuery=normalizeIranPhone(q);
  const list = customers.filter((c) => c.name.includes(q) || (normalizedQuery.length>0 && normalizeIranPhone(c.phone).includes(normalizedQuery)));
  const selectedCustomer = customers.find((customer) => customer.id === selectedCustomerId) ?? null;
  const selectedOrders = selectedCustomer
    ? orders.filter((order) => order.customerId === selectedCustomer.id || normalizeIranPhone(order.customerPhone) === normalizeIranPhone(selectedCustomer.phone))
      .sort((left, right) => right.createdAt.localeCompare(left.createdAt))
    : [];
  return (
    <div className="space-y-3">
      <PageHeading title="باشگاه مشتریان" description="اعضا، سوابق خرید و امتیازهای وفاداری را مدیریت کنید." />
      <p className="text-sm text-muted">سطوح عضویت: نقره از {Number(settings.loyaltySilverPoints??500).toLocaleString("fa-IR")} امتیاز ({settings.loyaltySilverDiscount??3}٪)، طلا از {Number(settings.loyaltyGoldPoints??1500).toLocaleString("fa-IR")} ({settings.loyaltyGoldDiscount??5}٪)، پلاتین از {Number(settings.loyaltyPlatinumPoints??4000).toLocaleString("fa-IR")} ({settings.loyaltyPlatinumDiscount??8}٪).</p>
      {role === "manager" ? (
        <Card>
          <h3 className="mb-2 font-semibold">تنظیمات امتیازدهی باشگاه</h3>
          <div className="grid gap-2 sm:grid-cols-2">
            <Field label="به‌ازای هر چند تومان خرید، یک امتیاز؟">
              <input className={inputCls} type="number" min="1" value={settings.pointsPerToman} onChange={(e) => patchSettings({ pointsPerToman: Math.max(1, Number(e.target.value)) })} onBlur={() => {
                const current = usePos.getState().settings;
                void saveCentralRecord("settings", { id: "main", ...current, smsUser: "", smsKey: "", smsFrom: "" }, () => {});
              }} />
            </Field>
            <Field label="ارزش هر امتیاز به تومان">
              <input className={inputCls} type="number" min="1" value={settings.tomanPerPoint} onChange={(e) => patchSettings({ tomanPerPoint: Math.max(1, Number(e.target.value)) })} onBlur={() => {
                const current = usePos.getState().settings;
                void saveCentralRecord("settings", { id: "main", ...current, smsUser: "", smsKey: "", smsFrom: "" }, () => {});
              }} />
            </Field>
          </div>
          <p className="mt-2 text-xs text-muted">این نرخ‌ها برای تمام صندوق‌ها و کاربران یکسان اعمال می‌شود.</p>
        </Card>
      ) : null}
      <Card>
        <form className="grid gap-2 sm:grid-cols-4" onSubmit={(e) => {
          e.preventDefault();
          if (!form.phone) return;
          const item = { id: uid("cu"), name: form.name || "بدون نام", phone: form.phone, points: 0, birthday: form.birthday, smsConsent: form.smsConsent, totalSpent: 0, visits: 0, createdAt: new Date().toISOString() };
          void saveCentralRecord("customers", item, () => saveCustomer(item)).then((ok) => { if (ok) setForm({ name: "", phone: "", birthday: "", smsConsent: false }); });
        }}>
          <input className={inputCls} placeholder="نام" value={form.name} onChange={(e) => setForm({ ...form, name: e.target.value })} />
          <input className={inputCls} placeholder="موبایل" value={form.phone} onChange={(e) => setForm({ ...form, phone: e.target.value })} />
          <input className={inputCls} type="date" value={form.birthday} onChange={(e) => setForm({ ...form, birthday: e.target.value })} />
          <label className="flex items-center gap-2 text-xs"><input type="checkbox" checked={form.smsConsent} onChange={e=>setForm({...form,smsConsent:e.target.checked})}/>رضایت دریافت پیامک تبلیغاتی</label>
          <Btn type="submit">عضویت</Btn>
        </form>
      </Card>
      <input className={inputCls} placeholder="جستجو با شماره موبایل یا نام خانوادگی" value={q} onChange={(e) => setQ(e.target.value)} />
      {list.map((c) => (
        <Card key={c.id}>
          <div className="flex flex-wrap justify-between gap-2">
            <div><p className="font-bold">{c.name}</p><p className="text-sm text-muted">{c.phone}</p></div>
            <p className="text-sm">{TIER_LABEL[tierOf(c.points,settings)]} — {c.points.toLocaleString("fa-IR")} امتیاز — {money(c.totalSpent)}</p>
          </div>
          <div className="mt-2 flex flex-wrap items-center justify-between gap-2">
            <label className="flex items-center gap-2 text-xs"><input type="checkbox" checked={c.smsConsent === true} onChange={e=>{const updated={...c,smsConsent:e.target.checked};void saveCentralRecord("customers",updated,()=>saveCustomer(updated));}}/>رضایت پیامک تبلیغاتی</label>
            <p className="text-xs text-muted">{orders.filter((o) => o.customerId === c.id || normalizeIranPhone(o.customerPhone) === normalizeIranPhone(c.phone)).length.toLocaleString("fa-IR")} سفارش ثبت‌شده</p>
            <Btn tone="ghost" onClick={() => setSelectedCustomerId(selectedCustomerId === c.id ? null : c.id)}>{selectedCustomerId === c.id ? "بستن سوابق" : "گزارش خریدها"}</Btn>
          </div>
        </Card>
      ))}
      {selectedCustomer ? (
        <Card>
          <h3 className="font-bold">سوابق خرید {selectedCustomer.name}</h3>
          <p className="mt-1 text-sm text-muted">موبایل: {selectedCustomer.phone} — مجموع خرید: {money(selectedCustomer.totalSpent)} — {selectedOrders.length.toLocaleString("fa-IR")} سفارش</p>
          {selectedOrders.length ? (
            <div className="mt-3 space-y-2">
              {selectedOrders.map((order) => (
                <div key={order.id} className="flex flex-wrap justify-between gap-2 border-t border-line pt-2 text-sm">
                  <span>سفارش {order.number.toLocaleString("fa-IR")} — {new Date(order.createdAt).toLocaleString("fa-IR",{timeZone:"Asia/Tehran"})}</span>
                  <span className="font-semibold">{money(order.total)}{order.status === "cancelled" ? " — لغو شده" : ""}</span>
                </div>
              ))}
            </div>
          ) : <Empty text="سفارشی برای این مشتری ثبت نشده است." />}
        </Card>
      ) : null}
    </div>
  );
}

function emptyCode(): DiscountCode {
  return { id: uid("d"), code: "", type: "percent", value: 10, maxDiscount: 50000, minOrder: 0, maxUses: 0, usedCount: 0, perCustomer: 1, startsAt: new Date().toISOString().slice(0, 10), endsAt: "2026-12-31", audience: "all" };
}

function CodesScreen() {
  const discounts = usePos((s) => s.discounts);
  const saveDiscount = usePos((s) => s.saveDiscount);
  const removeDiscount = usePos((s) => s.removeDiscount);
  const [draft, setDraft] = useState<DiscountCode | null>(null);
  return (
    <div className="space-y-3">
      <div className="flex justify-between"><PageHeading title="کدهای تخفیف" description="کمپین‌های تخفیف را با محدودیت و شرایط استفاده تعریف کنید." /><Btn onClick={() => setDraft(emptyCode())}>کد جدید</Btn></div>
      {discounts.map((d) => (
        <Card key={d.id} className="flex flex-wrap items-center justify-between gap-2">
          <div>
            <p className="font-bold">{d.code}</p>
            <p className="text-sm text-muted">{d.type === "percent" ? `${d.value}٪` : money(d.value)} — استفاده {d.usedCount.toLocaleString("fa-IR")}{d.maxUses ? ` از ${d.maxUses.toLocaleString("fa-IR")}` : " (نامحدود)"}</p>
          </div>
          <div className="flex gap-2"><Btn tone="ghost" onClick={() => setDraft(d)}>ویرایش</Btn><Btn tone="ghost" onClick={() => void deleteCentralRecord("discounts", d.id, () => removeDiscount(d.id))}>حذف</Btn></div>
        </Card>
      ))}
      {draft ? (
        <Card>
          <div className="grid gap-2 sm:grid-cols-2">
            <Field label="کد"><input className={inputCls} value={draft.code} onChange={(e) => setDraft({ ...draft, code: e.target.value.toUpperCase() })} /></Field>
            <Field label="نوع"><select className={inputCls} value={draft.type} onChange={(e) => setDraft({ ...draft, type: e.target.value as DiscountCode["type"] })}><option value="percent">درصدی</option><option value="fixed">مبلغ ثابت</option></select></Field>
            <Field label="مقدار"><input className={inputCls} type="number" value={draft.value} onChange={(e) => setDraft({ ...draft, value: Number(e.target.value) })} /></Field>
            <Field label="سقف تخفیف"><input className={inputCls} type="number" value={draft.maxDiscount} onChange={(e) => setDraft({ ...draft, maxDiscount: Number(e.target.value) })} /></Field>
            <Field label="حداقل سفارش"><input className={inputCls} type="number" value={draft.minOrder} onChange={(e) => setDraft({ ...draft, minOrder: Number(e.target.value) })} /></Field>
            <Field label="سقف کل (۰ نامحدود)"><input className={inputCls} type="number" value={draft.maxUses} onChange={(e) => setDraft({ ...draft, maxUses: Number(e.target.value) })} /></Field>
            <Field label="سقف هر مشتری"><input className={inputCls} type="number" value={draft.perCustomer} onChange={(e) => setDraft({ ...draft, perCustomer: Number(e.target.value) })} /></Field>
            <Field label="مخاطب">
              <select className={inputCls} value={draft.audience} onChange={(e) => setDraft({ ...draft, audience: e.target.value as DiscountCode["audience"] })}>
                <option value="all">همه</option><option value="club">فقط باشگاه</option>
                <option value="bronze">برنز</option><option value="silver">نقره</option><option value="gold">طلا</option><option value="platinum">پلاتین</option>
              </select>
            </Field>
            <Field label="از"><input className={inputCls} type="date" value={draft.startsAt} onChange={(e) => setDraft({ ...draft, startsAt: e.target.value })} /></Field>
            <Field label="تا"><input className={inputCls} type="date" value={draft.endsAt} onChange={(e) => setDraft({ ...draft, endsAt: e.target.value })} /></Field>
          </div>
          <div className="mt-3 flex gap-2"><Btn onClick={() => { if (draft.code) void saveCentralRecord("discounts", draft, () => saveDiscount(draft)).then((ok) => { if (ok) setDraft(null); }); }}>ذخیره</Btn><Btn tone="ghost" onClick={() => setDraft(null)}>بستن</Btn></div>
        </Card>
      ) : null}
    </div>
  );
}

function asciiDigits(value:string){
  return value.replace(/[۰-۹]/g,d=>String(d.charCodeAt(0)-1776)).replace(/[٠-٩]/g,d=>String(d.charCodeAt(0)-1632));
}
function jalaliToIso(value:string):string|null{
  const match=asciiDigits(value).trim().match(/^(\d{4})[/-](\d{1,2})[-/](\d{1,2})$/);
  if(!match)return null;
  let jy=Number(match[1]); const jm=Number(match[2]),jd=Number(match[3]);
  if(jm<1||jm>12||jd<1||jd>31)return null;
  jy+=1595;
  let days=-355668+365*jy+Math.floor(jy/33)*8+Math.floor(((jy%33)+3)/4)+jd+(jm<7?(jm-1)*31:(jm-7)*30+186);
  let gy=400*Math.floor(days/146097);days%=146097;
  if(days>36524){gy+=100*Math.floor(--days/36524);days%=36524;if(days>=365)days++;}
  gy+=4*Math.floor(days/1461);days%=1461;
  if(days>365){gy+=Math.floor((days-1)/365);days=(days-1)%365;}
  let gd=days+1;const leap=gy%4===0&&(gy%100!==0||gy%400===0);
  const monthDays=[31,leap?29:28,31,30,31,30,31,31,30,31,30,31];let gm=0;
  while(gm<12&&gd>monthDays[gm]){gd-=monthDays[gm];gm++;}
  if(gm>=12)return null;
  const iso=`${gy}-${String(gm+1).padStart(2,"0")}-${String(gd).padStart(2,"0")}`;
  const expected=`${jy-1595}/${String(jm).padStart(2,"0")}/${String(jd).padStart(2,"0")}`;
  if(asciiDigits(isoToJalali(iso)).replace(/\u200e/g,"")!==expected)return null;
  return iso;
}
function isoToJalali(value:string){
  const parts=new Intl.DateTimeFormat("fa-IR-u-ca-persian",{timeZone:"Asia/Tehran",year:"numeric",month:"2-digit",day:"2-digit"}).formatToParts(new Date(value+"T12:00:00Z"));
  const part=(type:string)=>parts.find(item=>item.type===type)?.value??"";
  return `${part("year")}/${part("month")}/${part("day")}`;
}

function createXlsxBlob(rows: unknown[][]): Blob {
  const encoder = new TextEncoder();
  const escapeXml = (value: string) => {
    let clean = "";
    for (const character of value) {
      const code = character.charCodeAt(0);
      if (code === 9 || code === 10 || code === 13 || code >= 32) clean += character;
    }
    return clean.replaceAll("&", "&amp;").replaceAll("<", "&lt;").replaceAll(">", "&gt;").replaceAll('"', "&quot;").replaceAll("'", "&apos;");
  };
  const columnName = (index: number) => {
    let number = index + 1;
    let name = "";
    while (number > 0) {
      const remainder = (number - 1) % 26;
      name = String.fromCharCode(65 + remainder) + name;
      number = Math.floor((number - 1) / 26);
    }
    return name;
  };
  const sheetRows = rows.map((row, rowIndex) => {
    const cells = row.map((value, columnIndex) => {
      const reference = columnName(columnIndex) + (rowIndex + 1);
      const raw = String(value ?? "");
      if (/^-?\d+(?:\.\d+)?$/.test(raw)) return '<c r="' + reference + '"><v>' + raw + '</v></c>';
      return '<c r="' + reference + '" t="inlineStr"><is><t xml:space="preserve">' + escapeXml(raw) + '</t></is></c>';
    }).join("");
    return '<row r="' + (rowIndex + 1) + '">' + cells + '</row>';
  }).join("");
  const files: [string, string][] = [
    ["[Content_Types].xml", '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/><Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/><Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/></Types>'],
    ["_rels/.rels", '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/></Relationships>'],
    ["xl/workbook.xml", '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets><sheet name="گزارش فروش" sheetId="1" r:id="rId1"/></sheets></workbook>'],
    ["xl/_rels/workbook.xml.rels", '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/></Relationships>'],
    ["xl/worksheets/sheet1.xml", '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetData>' + sheetRows + '</sheetData></worksheet>'],
  ];
  const crc32 = (bytes: Uint8Array) => {
    let crc = 0xffffffff;
    for (const byte of bytes) {
      crc ^= byte;
      for (let bit = 0; bit < 8; bit++) crc = (crc >>> 1) ^ (crc & 1 ? 0xedb88320 : 0);
    }
    return (crc ^ 0xffffffff) >>> 0;
  };
  const localParts: Uint8Array[] = [];
  const centralParts: Uint8Array[] = [];
  let offset = 0;
  for (const [path, content] of files) {
    const name = encoder.encode(path);
    const data = encoder.encode(content);
    const checksum = crc32(data);
    const local = new Uint8Array(30 + name.length);
    const localView = new DataView(local.buffer);
    localView.setUint32(0, 0x04034b50, true);
    localView.setUint16(4, 20, true);
    localView.setUint16(6, 0, true);
    localView.setUint16(8, 0, true);
    localView.setUint16(10, 0, true);
    localView.setUint16(12, 0, true);
    localView.setUint32(14, checksum, true);
    localView.setUint32(18, data.length, true);
    localView.setUint32(22, data.length, true);
    localView.setUint16(26, name.length, true);
    localView.setUint16(28, 0, true);
    local.set(name, 30);
    localParts.push(local, data);

    const central = new Uint8Array(46 + name.length);
    const centralView = new DataView(central.buffer);
    centralView.setUint32(0, 0x02014b50, true);
    centralView.setUint16(4, 20, true);
    centralView.setUint16(6, 20, true);
    centralView.setUint16(8, 0, true);
    centralView.setUint16(10, 0, true);
    centralView.setUint16(12, 0, true);
    centralView.setUint16(14, 0, true);
    centralView.setUint32(16, checksum, true);
    centralView.setUint32(20, data.length, true);
    centralView.setUint32(24, data.length, true);
    centralView.setUint16(28, name.length, true);
    centralView.setUint16(30, 0, true);
    centralView.setUint16(32, 0, true);
    centralView.setUint16(34, 0, true);
    centralView.setUint16(36, 0, true);
    centralView.setUint32(38, 0, true);
    centralView.setUint32(42, offset, true);
    central.set(name, 46);
    centralParts.push(central);
    offset += local.length + data.length;
  }
  const centralSize = centralParts.reduce((sum, part) => sum + part.length, 0);
  const end = new Uint8Array(22);
  const endView = new DataView(end.buffer);
  endView.setUint32(0, 0x06054b50, true);
  endView.setUint16(4, 0, true);
  endView.setUint16(6, 0, true);
  endView.setUint16(8, files.length, true);
  endView.setUint16(10, files.length, true);
  endView.setUint32(12, centralSize, true);
  endView.setUint32(16, offset, true);
  endView.setUint16(20, 0, true);
  const blobParts: BlobPart[] = [];
  for (const part of [...localParts, ...centralParts, end]) {
    const copy = new Uint8Array(part.byteLength);
    copy.set(part);
    blobParts.push(copy.buffer);
  }
  return new Blob(blobParts, { type: "application/vnd.openxmlformats-officedocument.spreadsheetml.sheet" });
}

const REPORT_INITIAL_TO = tehranDate();
const REPORT_INITIAL_FROM = tehranDate(Date.now() - 6 * 86400000);

function ReportsScreen() {
  const orders = usePos((s) => s.orders);
  const expenses = usePos((s) => s.expenses);
  const products = usePos((s) => s.products);
  const addExpense = usePos((s) => s.addExpense);
  const saveExpense = usePos((s) => s.saveExpense);
  const removeExpense = usePos((s) => s.removeExpense);
  const [categories, setCategories] = useState<{id:string;name:string;active?:boolean}[]>([]);
  const [title, setTitle] = useState("");
  const [amount, setAmount] = useState(0);
  const [cat, setCat] = useState("");
  const [note, setNote] = useState("");
  const [expenseDate,setExpenseDate]=useState(tehranDate());
  const [expenseDateText,setExpenseDateText]=useState(()=>isoToJalali(tehranDate()));
  const initialFrom=REPORT_INITIAL_FROM;
  const initialTo=REPORT_INITIAL_TO;
  const [from, setFrom] = useState(initialFrom);
  const [to, setTo] = useState(initialTo);
  const [fromText,setFromText]=useState(()=>isoToJalali(initialFrom));
  const [toText,setToText]=useState(()=>isoToJalali(initialTo));
  const [newCategory, setNewCategory] = useState("");
  const [expenseFilterCategory, setExpenseFilterCategory] = useState("all");
  const [categoryBusy, setCategoryBusy] = useState(false);
  const [serverSummary, setServerSummary] = useState<Record<string, unknown> | null>(null);
  const [reportError, setReportError] = useState("");
  useEffect(() => {
    let active = true;
    void phpApi.listRecords<{id:string;name:string;active?:boolean}>("expense_categories")
      .then(r => { if(active){setCategories(r.items);setCat(r.items.find(x=>x.active!==false)?.name??"");} })
      .catch(() => { if(active) setCategories([]); });
    return () => { active=false; };
  }, []);
  const done = useMemo(() => orders.filter((o) => o.status !== "cancelled" && tehranDate(o.createdAt) >= from && tehranDate(o.createdAt) <= to), [orders,from,to]);
  useEffect(() => { let active=true; void phpApi.getReportSummary(from,to).then(r=>{if(active){setServerSummary(r);setReportError("");}}).catch(e=>{if(active){setServerSummary(null);setReportError(e instanceof Error?e.message:"گزارش سرور دریافت نشد");}}); return ()=>{active=false;}; },[from,to]);
  const visibleExpenses = expenses.filter(e => e.date >= from && e.date <= to && (expenseFilterCategory === "all" || e.category === expenseFilterCategory));
  const sales = done.reduce((sum, o) => sum + o.total, 0);
  const costGuess = done.reduce((sum, o) => sum + o.lines.reduce((lineSum, line) => lineSum + (products.find((x) => x.id === line.productId)?.cost ?? 0) * line.qty, 0), 0);
  const spend = visibleExpenses.reduce((sum, e) => sum + e.amount, 0);
  const reportSales = typeof serverSummary?.sales === "number" ? serverSummary.sales : sales;
  const reportCost = typeof serverSummary?.cost_of_goods === "number" ? serverSummary.cost_of_goods : costGuess;
  const reportExpenses = typeof serverSummary?.expenses === "number" ? serverSummary.expenses : spend;
  const reportNet = typeof serverSummary?.net_profit === "number" ? serverSummary.net_profit : sales-costGuess-spend;
  const reportTax = typeof serverSummary?.tax_collected === "number" ? serverSummary.tax_collected : 0;
  const reportDiscounts = typeof serverSummary?.discounts === "number" ? serverSummary.discounts : 0;
  const reportOrders = typeof serverSummary?.orders === "number" ? serverSummary.orders : done.length;
  const reportCancelled = typeof serverSummary?.cancelled === "number" ? serverSummary.cancelled : 0;
  const inventoryPurchases = typeof serverSummary?.inventory_purchases === "number" ? serverSummary.inventory_purchases : 0;
  const daily = new Map<string,number>();
  for(const o of done) daily.set(tehranDate(o.createdAt),(daily.get(tehranDate(o.createdAt))??0)+o.total);
  const dailyRows: [string,number][] = Array.isArray(serverSummary?.daily) ? (serverSummary.daily as {date:string;sales:number}[]).map(row=>[row.date,row.sales] as [string,number]) : [...daily.entries()].sort((a,b)=>a[0].localeCompare(b[0]));
  const productRows=new Map<string,{qty:number;revenue:number}>();
  for(const o of done) for(const l of o.lines){const p=productRows.get(l.name)??{qty:0,revenue:0};p.qty+=l.qty;p.revenue+=l.unitPrice*l.qty;productRows.set(l.name,p);}
  const top=[...productRows.entries()].sort((a,b)=>b[1].qty-a[1].qty).slice(0,10);
  const payments=["cash","card","online"].map(method=>({method,label:method==="cash"?"نقدی":method==="card"?"کارت‌خوان":"آنلاین",amount:done.filter(o=>o.payment===method).reduce((sum,o)=>sum+o.total,0)}));
  const expenseByCategory=[...visibleExpenses.reduce((map,e)=>map.set(e.category,(map.get(e.category)??0)+e.amount),new Map<string,number>()).entries()];
  const monthlyFallback = [...done.reduce((map,order)=>{const month=tehranDate(order.createdAt).slice(0,7);map.set(month,(map.get(month)??0)+order.total);return map;},new Map<string,number>()).entries()].sort((a,b)=>a[0].localeCompare(b[0])).map(([month,sales])=>({month,sales}));
  const monthlyRows = Array.isArray(serverSummary?.monthly) ? serverSummary.monthly as {month:string;sales:number}[] : monthlyFallback;
  const productCategoryRows = Array.isArray(serverSummary?.product_categories) ? serverSummary.product_categories as {name:string;quantity:number;revenue:number}[] : [];
  const lowStockRows = Array.isArray(serverSummary?.low_stock) ? serverSummary.low_stock as {name:string;stock:number;minStock:number;unit:string}[] : [];
  const topCustomerRows = Array.isArray(serverSummary?.top_customers) ? serverSummary.top_customers as {name:string;phone:string;totalSpent:number;visits:number;points:number}[] : [];
  const allProductRows = Array.isArray(serverSummary?.all_products) ? serverSummary.all_products as {name:string;quantity:number;revenue:number}[] : top.map(([name,value])=>({name,quantity:value.qty,revenue:value.revenue}));
  const inventoryRows = Array.isArray(serverSummary?.inventory_items) ? serverSummary.inventory_items as {name:string;stock:number;minStock:number;unit:string;costPerUnit:number;value:number}[] : lowStockRows.map(row=>({...row,costPerUnit:0,value:0}));
  const allCustomerRows = Array.isArray(serverSummary?.all_customers) ? serverSummary.all_customers as {name:string;phone:string;totalSpent:number;visits:number;points:number}[] : topCustomerRows;
  const expenseItemRows = Array.isArray(serverSummary?.expense_items) ? serverSummary.expense_items as {title:string;category:string;date:string;amount:number}[] : [];
  const visibleExpenseItemRows = expenseItemRows.filter(row => expenseFilterCategory === "all" || row.category === expenseFilterCategory);
  const grossProfit = typeof serverSummary?.gross_profit === "number" ? serverSummary.gross_profit : reportSales - reportCost;
  function downloadExcel(){
    const rows=[["نوع","عنوان","تعداد / جزئیات","مبلغ"],["فروش","جمع فروش خالص","",String(reportSales)],["سود و زیان","بهای تمام‌شده","",String(reportCost)],["سود و زیان","سود ناخالص","",String(grossProfit)],["سود و زیان","هزینه‌های عملیاتی","",String(reportExpenses)],["سود و زیان","سود خالص","",String(reportNet)],["مالی","تخفیف‌ها","",String(reportDiscounts)],["مالی","مالیات وصول‌شده","",String(reportTax)],["انبار","خرید مواد اولیه","",String(inventoryPurchases)],...dailyRows.map(([day,value])=>["روزانه",day,"",String(value)]),...monthlyRows.map(row=>["ماهانه",row.month,"",String(row.sales)]),...allProductRows.map(row=>["محصول",row.name,String(row.quantity),String(row.revenue)]),...productCategoryRows.map(row=>["دسته محصول",row.name,String(row.quantity),String(row.revenue)]),...payments.map(p=>["پرداخت",p.label,"",String(p.amount)]),...allCustomerRows.map(row=>["مشتری",row.name,row.phone,String(row.totalSpent)]),...expenseByCategory.map(([name,value])=>["هزینه بر اساس دسته",name,"",String(value)]),...visibleExpenseItemRows.map(row=>["ریز هزینه",row.title,`${row.category} · ${row.date}`,String(row.amount)]),...inventoryRows.map(row=>["انبار",row.name,`${row.stock} / حداقل ${row.minStock} ${row.unit}`,String(row.value)])];
    const url=URL.createObjectURL(createXlsxBlob(rows));
    const a=document.createElement("a");a.href=url;a.download="3food-pos-report.xlsx";a.click();URL.revokeObjectURL(url);
  }
  async function createCategory(event:FormEvent<HTMLFormElement>){
    event.preventDefault();const name=newCategory.trim();if(!name||categoryBusy)return;setCategoryBusy(true);
    const item={id:uid("expense_cat"),name,active:true};
    const ok=await saveCentralRecord("expense_categories",item,()=>setCategories(old=>[...old,item]));
    if(ok){setNewCategory("");setCat(name);}setCategoryBusy(false);
  }
  async function editCategory(category:{id:string;name:string;active?:boolean}){
    const name=window.prompt("ویرایش نام دسته هزینه",category.name);if(name===null||!name.trim())return;
    const updated={...category,name:name.trim()};
    await saveCentralRecord("expense_categories",updated,()=>setCategories(old=>old.map(c=>c.id===category.id?updated:c)));
  }
  async function deleteCategory(category:{id:string;name:string;active?:boolean}){
    if(expenses.some(e=>e.category===category.name)){window.alert("این دسته در سوابق هزینه استفاده شده است؛ ابتدا هزینه‌های مرتبط را به دسته دیگری منتقل کنید.");return;}
    if(!window.confirm("دسته «"+category.name+"» حذف شود؟"))return;
    await deleteCentralRecord("expense_categories",category.id,()=>setCategories(old=>old.filter(c=>c.id!==category.id)));
  }
  async function editExpense(expense:typeof expenses[number]){
    const title=window.prompt("شرح هزینه",expense.title);if(title===null||!title.trim())return;
    const raw=window.prompt("مبلغ هزینه (تومان)",String(expense.amount));if(raw===null)return;
    const amount=Number(raw);if(!Number.isFinite(amount)||amount<=0){window.alert("مبلغ معتبر نیست.");return;}
    const updated={...expense,title:title.trim(),amount};
    await saveCentralRecord("expenses",updated,()=>saveExpense(updated));
  }
  async function deleteExpense(expense:typeof expenses[number]){
    if(!window.confirm("هزینه «"+expense.title+"» حذف شود؟"))return;
    await deleteCentralRecord("expenses",expense.id,()=>removeExpense(expense.id));
  }
  async function addCost(event:FormEvent<HTMLFormElement>){
    event.preventDefault();if(!title.trim()||amount<=0||!cat)return;
    const item={id:uid("ex"),title:title.trim(),amount,category:cat,date:expenseDate,note:note.trim(),createdAt:new Date().toISOString()};
    const ok=await saveCentralRecord("expenses",item,()=>addExpense(item));
    if(ok){setTitle("");setAmount(0);setNote("");setExpenseDate(tehranDate());setExpenseDateText(isoToJalali(tehranDate()));}
  }
  return (
    <div id="print-root" className="space-y-4">
      <div className="flex flex-wrap items-end justify-between gap-3 print:hidden"><PageHeading title="مرکز گزارش‌گیری و هزینه‌ها" description="عملکرد فروش، سود و زیان، موجودی و هزینه‌های کسب‌وکار را تحلیل کنید." /><div className="flex gap-2"><Btn tone="ghost" onClick={downloadExcel}>خروجی Excel</Btn><Btn onClick={()=>window.print()}>چاپ / ذخیره PDF</Btn></div></div>
      <Card className="grid gap-2 sm:grid-cols-2 print:hidden"><Field label="از تاریخ شمسی"><input className={inputCls} inputMode="numeric" placeholder="۱۴۰۵/۰۱/۰۱" value={fromText} onChange={e=>{setFromText(e.target.value);const parsed=jalaliToIso(e.target.value);if(parsed)setFrom(parsed);}} onBlur={()=>{if(!jalaliToIso(fromText))setFromText(isoToJalali(from));}}/></Field><Field label="تا تاریخ شمسی"><input className={inputCls} inputMode="numeric" placeholder="۱۴۰۵/۰۱/۰۷" value={toText} onChange={e=>{setToText(e.target.value);const parsed=jalaliToIso(e.target.value);if(parsed)setTo(parsed);}} onBlur={()=>{if(!jalaliToIso(toText))setToText(isoToJalali(to));}}/></Field></Card>
      {reportError?<p className="text-xs text-warn">گزارش زنده سرور در دسترس نیست: {reportError}</p>:null}
      <div className="grid gap-3 sm:grid-cols-4">
        <Card><p className="text-xs text-muted">فروش خالص سفارش‌ها</p><p className="text-lg font-bold">{money(reportSales)}</p></Card>
        <Card><p className="text-xs text-muted">بهای تمام‌شده</p><p className="text-lg font-bold">{money(reportCost)}</p></Card>
        <Card><p className="text-xs text-muted">هزینه‌های ثبت‌شده</p><p className="text-lg font-bold">{money(reportExpenses)}</p></Card>
        <Card><p className="text-xs text-muted">سود پس از هزینه‌ها</p><p className="text-lg font-bold">{money(reportNet)}</p></Card>
      </div>
      <div className="grid gap-3 sm:grid-cols-4">
        <Card><p className="text-xs text-muted">تعداد سفارش‌های معتبر</p><p className="text-lg font-bold">{reportOrders.toLocaleString("fa-IR")}</p></Card>
        <Card><p className="text-xs text-muted">سفارش‌های لغوشده</p><p className="text-lg font-bold">{reportCancelled.toLocaleString("fa-IR")}</p></Card>
        <Card><p className="text-xs text-muted">تخفیف‌ها</p><p className="text-lg font-bold">{money(reportDiscounts)}</p></Card>
        <Card><p className="text-xs text-muted">مالیات وصول‌شده</p><p className="text-lg font-bold">{money(reportTax)}</p></Card>
      </div>
      <Card><h3 className="mb-3 font-semibold">نمودار فروش روزانه</h3>{dailyRows.length?<div className="space-y-3">{dailyRows.map(([day,total])=><div key={day} className="grid grid-cols-[84px_minmax(0,1fr)_auto] items-center gap-2 text-xs"><span>{new Date(day+"T12:00:00").toLocaleDateString("fa-IR",{month:"short",day:"numeric"})}</span><div className="h-3 overflow-hidden rounded-full bg-soft"><div className="h-full rounded-full bg-leaf" style={{width:`${Math.max(1,total/Math.max(1,...dailyRows.map(row=>row[1]))*100)}%`}}/></div><b>{money(total)}</b></div>)}</div>:<Empty text="در این بازه سفارشی وجود ندارد"/>}</Card>
      <Card><h3 className="mb-3 font-semibold">نمودار فروش ماهانه</h3>{monthlyRows.length?<div className="space-y-3">{monthlyRows.map(row=><div key={row.month} className="grid grid-cols-[84px_minmax(0,1fr)_auto] items-center gap-2 text-xs"><span>{row.month}</span><div className="h-3 overflow-hidden rounded-full bg-soft"><div className="h-full rounded-full bg-leaf" style={{width:`${Math.max(1,row.sales/Math.max(1,...monthlyRows.map(item=>item.sales))*100)}%`}}/></div><b>{money(row.sales)}</b></div>)}</div>:<Empty text="داده ماهانه‌ای در این بازه وجود ندارد"/>}</Card>
      <Card><h3 className="mb-2 font-semibold">وضعیت انبار</h3><p className="text-sm">ارزش تقریبی موجودی: {money(Number((serverSummary?.inventory as {value?:number}|undefined)?.value??0))}</p><p className="text-sm">مواد اولیه زیر حد هشدار: {lowStockRows.length.toLocaleString("fa-IR")}</p>{lowStockRows.map(row=><p key={row.name} className="flex justify-between text-sm"><span>{row.name}</span><span>{row.stock} / حداقل {row.minStock} {row.unit}</span></p>)}</Card>
      <Card><h3 className="mb-2 font-semibold">مشتریان برتر بر اساس مجموع خرید</h3>{topCustomerRows.map(row=><p key={row.phone} className="flex flex-wrap justify-between gap-2 border-b border-line py-1 text-sm"><span>{row.name} · {row.phone}</span><b>{money(row.totalSpent)}</b></p>)}</Card>
      <Card><h3 className="mb-2 font-semibold">فروش بر اساس دسته‌بندی محصول</h3>{productCategoryRows.length?productCategoryRows.map(row=><p key={row.name} className="flex justify-between border-b border-line py-1 text-sm"><span>{row.name} · {row.quantity.toLocaleString("fa-IR")} عدد</span><b>{money(row.revenue)}</b></p>):<Empty text="فروشی در دسته‌ها ثبت نشده است"/>}</Card>
      <Card><h3 className="mb-3 font-semibold">نمودار محصولات پرفروش</h3>{top.length?<div className="space-y-3">{top.map(([name,value])=><div key={name} className="grid grid-cols-[minmax(90px,1fr)_minmax(0,2fr)_auto] items-center gap-2 text-xs"><span className="truncate">{name}</span><div className="h-3 overflow-hidden rounded-full bg-soft"><div className="h-full rounded-full bg-leaf" style={{width:`${Math.max(1,value.qty/Math.max(1,...top.map(item=>item[1].qty))*100)}%`}}/></div><b>{value.qty.toLocaleString("fa-IR")} عدد</b></div>)}</div>:<Empty text="فروشی ثبت نشده است"/>}</Card>
      <Card><h3 className="mb-3 font-semibold">نمودار روش‌های پرداخت</h3><div className="space-y-3">{payments.map(p=><div key={p.method} className="grid grid-cols-[70px_minmax(0,1fr)_auto] items-center gap-2 text-xs"><span>{p.label}</span><div className="h-3 overflow-hidden rounded-full bg-soft"><div className="h-full rounded-full bg-leaf" style={{width:`${Math.max(0,p.amount/Math.max(1,...payments.map(item=>item.amount))*100)}%`}}/></div><b>{money(p.amount)}</b></div>)}</div></Card>
      <Card><h3 className="mb-2 font-semibold">هزینه‌ها بر اساس دسته</h3>{expenseByCategory.map(([name,value])=><p key={name} className="flex justify-between py-1 text-sm"><span>{name}</span><b>{money(value)}</b></p>)}<p className="mt-2 border-t border-line pt-2 text-xs text-muted">خرید مواد اولیه (سرمایه‌گذاری موجودی، خارج از هزینه عملیاتی سود و زیان): {money(inventoryPurchases)}</p></Card>
      <Card className="print:hidden"><h3 className="mb-3 font-semibold">ثبت هزینه جدید</h3><p className="mb-2 text-xs text-muted">برای خرید مواد اولیه که باید موجودی انبار را افزایش دهد، از بخش تأمین‌کنندگان استفاده کن؛ ثبت هزینه به‌تنهایی موجودی را تغییر نمی‌دهد.</p><form className="grid gap-2 sm:grid-cols-2" onSubmit={e=>void addCost(e)}>
        <Field label="شرح هزینه"><input className={inputCls} value={title} onChange={e=>setTitle(e.target.value)} required/></Field>
        <Field label="مبلغ (تومان)"><input className={inputCls} type="number" min="1" value={amount||""} onChange={e=>setAmount(Number(e.target.value))} required/></Field>
        <Field label="تاریخ هزینه شمسی"><input className={inputCls} inputMode="numeric" placeholder="۱۴۰۵/۰۱/۰۱" value={expenseDateText} onChange={e=>{setExpenseDateText(e.target.value);const parsed=jalaliToIso(e.target.value);if(parsed)setExpenseDate(parsed);}} onBlur={()=>{if(!jalaliToIso(expenseDateText))setExpenseDateText(isoToJalali(expenseDate));}} required/></Field>
        <Field label="دسته‌بندی"><select className={inputCls} value={cat} onChange={e=>setCat(e.target.value)} required>{categories.filter(c=>c.active!==false).map(c=><option key={c.id} value={c.name}>{c.name}</option>)}</select></Field>
        <Field label="توضیحات"><input className={inputCls} value={note} onChange={e=>setNote(e.target.value)}/></Field>
        <div><Btn type="submit">ثبت هزینه</Btn></div>
      </form></Card>
      <Card className="print:hidden"><h3 className="mb-2 font-semibold">افزودن دسته هزینه</h3><form className="flex gap-2" onSubmit={e=>void createCategory(e)}><input className={inputCls} value={newCategory} onChange={e=>setNewCategory(e.target.value)} placeholder="نام دسته جدید" required/><Btn type="submit" disabled={categoryBusy}>افزودن</Btn></form><div className="mt-3 space-y-2">{categories.map(c=><div key={c.id} className="flex items-center justify-between gap-2 rounded-lg border border-line px-3 py-2 text-sm"><span>{c.name}</span><span className="flex gap-1"><Btn tone="ghost" onClick={()=>void editCategory(c)}>ویرایش</Btn><Btn tone="ghost" onClick={()=>void deleteCategory(c)}>حذف</Btn></span></div>)}</div></Card>
      <Card><div className="mb-2 flex flex-wrap items-center justify-between gap-2"><h3 className="font-semibold">سوابق هزینه‌ها</h3><select className={`${inputCls} max-w-64 print:hidden`} value={expenseFilterCategory} onChange={e=>setExpenseFilterCategory(e.target.value)}><option value="all">همه دسته‌ها</option>{categories.map(category=><option key={category.id} value={category.name}>{category.name}</option>)}</select></div>{visibleExpenses.map(e=><div key={e.id} className="flex flex-wrap justify-between gap-2 border-b border-line py-2 text-sm"><span>{e.title} · {e.category} · {new Date(e.date+"T12:00:00").toLocaleDateString("fa-IR")}</span><span className="flex items-center gap-2"><b>{money(e.amount)}</b><Btn tone="ghost" onClick={()=>void editExpense(e)}>ویرایش</Btn><Btn tone="ghost" onClick={()=>void deleteExpense(e)}>حذف</Btn></span></div>)}</Card>
    </div>
  );
}

function ExpensesScreen(){
  const expenses=usePos((s)=>s.expenses);
  const addExpense=usePos((s)=>s.addExpense);
  const saveExpense=usePos((s)=>s.saveExpense);
  const removeExpense=usePos((s)=>s.removeExpense);
  const [categories,setCategories]=useState<{id:string;name:string;active?:boolean}[]>([]);
  const [title,setTitle]=useState("");
  const [amount,setAmount]=useState(0);
  const [category,setCategory]=useState("");
  const [date,setDate]=useState(tehranDate());
  const [note,setNote]=useState("");
  const [newCategory,setNewCategory]=useState("");
  const [filter,setFilter]=useState("all");
  useEffect(()=>{let active=true;void phpApi.listRecords<{id:string;name:string;active?:boolean}>("expense_categories").then(result=>{if(active){setCategories(result.items);setCategory(result.items.find(item=>item.active!==false)?.name??"");}}).catch(()=>{if(active)setCategories([]);});return()=>{active=false;};},[]);
  const visible=expenses.filter(item=>(filter==="all"||item.category===filter)).sort((a,b)=>b.date.localeCompare(a.date));
  const total=visible.reduce((sum,item)=>sum+item.amount,0);
  async function add(event:FormEvent<HTMLFormElement>){event.preventDefault();if(!title.trim()||amount<=0||!category)return;const item={id:uid("ex"),title:title.trim(),amount,category,date,note:note.trim(),createdAt:new Date().toISOString()};const ok=await saveCentralRecord("expenses",item,()=>addExpense(item));if(ok){setTitle("");setAmount(0);setNote("");setDate(tehranDate());}}
  async function addCat(event:FormEvent<HTMLFormElement>){event.preventDefault();const name=newCategory.trim();if(!name||categories.some(item=>item.name===name))return;const item={id:uid("expense_cat"),name,active:true};if(await saveCentralRecord("expense_categories",item,()=>setCategories(old=>[...old,item]))){setNewCategory("");setCategory(name);}}
  async function edit(item:typeof expenses[number]){const nextTitle=window.prompt("شرح هزینه",item.title);if(nextTitle===null||!nextTitle.trim())return;const raw=window.prompt("مبلغ (تومان)",String(item.amount));if(raw===null)return;const nextAmount=Number(raw);if(!Number.isFinite(nextAmount)||nextAmount<=0){window.alert("مبلغ معتبر نیست.");return;}const updated={...item,title:nextTitle.trim(),amount:nextAmount};await saveCentralRecord("expenses",updated,()=>saveExpense(updated));}
  async function remove(item:typeof expenses[number]){if(!window.confirm("هزینه «"+item.title+"» حذف شود؟"))return;await deleteCentralRecord("expenses",item.id,()=>removeExpense(item.id));}
  return <div className="space-y-4"><PageHeading title="مدیریت هزینه‌ها" description="ثبت مستقل هزینه‌های جاری، دسته‌بندی و پیگیری مخارج کسب‌وکار."/><div className="grid gap-3 sm:grid-cols-3"><Card><p className="text-xs text-muted">مجموع هزینه‌های نمایش‌داده‌شده</p><p className="text-xl font-bold">{money(total)}</p></Card><Card><p className="text-xs text-muted">تعداد هزینه‌ها</p><p className="text-xl font-bold">{visible.length.toLocaleString("fa-IR")}</p></Card><Card><p className="text-xs text-muted">تعداد دسته‌ها</p><p className="text-xl font-bold">{categories.length.toLocaleString("fa-IR")}</p></Card></div><Card><h3 className="mb-3 font-semibold">ثبت هزینه جدید</h3><form className="grid gap-2 sm:grid-cols-2" onSubmit={e=>void add(e)}><Field label="عنوان هزینه"><input className={inputCls} value={title} onChange={e=>setTitle(e.target.value)} required/></Field><Field label="مبلغ (تومان)"><input className={inputCls} type="number" min="1" value={amount||""} onChange={e=>setAmount(Number(e.target.value))} required/></Field><Field label="تاریخ"><input className={inputCls} type="date" value={date} onChange={e=>setDate(e.target.value)} required/></Field><Field label="دسته‌بندی"><select className={inputCls} value={category} onChange={e=>setCategory(e.target.value)} required>{categories.filter(item=>item.active!==false).map(item=><option key={item.id} value={item.name}>{item.name}</option>)}</select></Field><Field label="توضیحات"><input className={inputCls} value={note} onChange={e=>setNote(e.target.value)}/></Field><div className="flex items-end"><Btn type="submit">ثبت هزینه</Btn></div></form></Card><Card><h3 className="mb-2 font-semibold">مدیریت دسته‌بندی‌ها</h3><form className="flex gap-2" onSubmit={e=>void addCat(e)}><input className={inputCls} value={newCategory} onChange={e=>setNewCategory(e.target.value)} placeholder="نام دسته جدید"/><Btn type="submit">افزودن</Btn></form><div className="mt-3 flex flex-wrap gap-2">{categories.map(item=><span key={item.id} className="rounded-lg border border-line px-3 py-2 text-sm">{item.name}</span>)}</div></Card><Card><div className="mb-3 flex flex-wrap items-center justify-between gap-2"><h3 className="font-semibold">سوابق هزینه‌ها</h3><select className={inputCls+" max-w-64"} value={filter} onChange={e=>setFilter(e.target.value)}><option value="all">همه دسته‌ها</option>{categories.map(item=><option key={item.id} value={item.name}>{item.name}</option>)}</select></div>{visible.length?visible.map(item=><div key={item.id} className="flex flex-wrap items-center justify-between gap-2 border-b border-line py-3 text-sm"><div><b>{item.title}</b><p className="text-xs text-muted">{item.category} · {new Date(item.date+"T12:00:00").toLocaleDateString("fa-IR")}{item.note?" · "+item.note:""}</p></div><div className="flex items-center gap-2"><b>{money(item.amount)}</b><Btn tone="ghost" onClick={()=>void edit(item)}>ویرایش</Btn><Btn tone="ghost" onClick={()=>void remove(item)}>حذف</Btn></div></div>):<Empty text="هنوز هزینه‌ای ثبت نشده است."/>}</Card></div>;
}

function SuppliersScreen(){
  type Supplier={id:string;name:string;phone:string;contact:string;notes:string;balance:number;active:boolean};
  type Purchase={id:string;supplierId:string;title:string;amount:number;date:string;paid:number;note:string;kind?:string;ingredientId?:string;quantity?:number;unitCost?:number};
  const ingredients=usePos((s)=>s.ingredients);
  const [suppliers,setSuppliers]=useState<Supplier[]>([]);
  const [purchases,setPurchases]=useState<Purchase[]>([]);
  const [draft,setDraft]=useState({name:"",phone:"",contact:"",notes:""});
  const [purchase,setPurchase]=useState({supplierId:"",title:"",amount:0,paid:0,note:"",ingredientId:"",quantity:0,unitCost:0});
  const [busy,setBusy]=useState(false);
  const [error,setError]=useState("");
  const refresh = useCallback(async () => {
    setBusy(true);
    setError("");
    try {
      const [supplierResult, purchaseResult] = await Promise.all([
        phpApi.listRecords<Supplier>("suppliers"),
        phpApi.listRecords<Purchase>("supplier_purchases"),
      ]);
      setSuppliers(supplierResult.items);
      setPurchases(purchaseResult.items);
    } catch (e) {
      setError(e instanceof Error ? e.message : "دریافت اطلاعات تأمین‌کنندگان انجام نشد");
    } finally {
      setBusy(false);
    }
  }, []);
  useEffect(() => { queueMicrotask(() => { void refresh(); }); }, [refresh]);
  async function addSupplier(e:FormEvent<HTMLFormElement>){
    e.preventDefault();if(!draft.name.trim())return;
    const item:Supplier={id:uid("sup"),...draft,name:draft.name.trim(),balance:0,active:true};
    if(await saveCentralRecord("suppliers",item,()=>setSuppliers(old=>[item,...old])))setDraft({name:"",phone:"",contact:"",notes:""});
  }
  async function addPurchase(e:FormEvent<HTMLFormElement>){
    e.preventDefault();const total=purchase.ingredientId?Math.round(purchase.quantity*purchase.unitCost):purchase.amount;
    if(!purchase.supplierId||!purchase.title.trim()||total<=0||purchase.paid<0||purchase.paid>total||(purchase.ingredientId&&(purchase.quantity<=0||purchase.unitCost<=0)))return;
    const supplier=suppliers.find(s=>s.id===purchase.supplierId);if(!supplier)return;
    const item:Purchase={id:uid("pur"),...purchase,amount:total,title:purchase.title.trim(),date:tehranDate(),note:purchase.note.trim()};
    setBusy(true);
    try{
      const result=await phpApi.createSupplierTransaction<Purchase & {kind:string},Supplier>(supplier.id,{...item,kind:"purchase"});
      setPurchases(old=>[result.item,...old]);setSuppliers(old=>old.map(s=>s.id===supplier.id?result.supplier:s));const snapshot=await phpApi.bootstrap<ServerBootstrap>();hydratePosFromServer(snapshot.data,snapshot.next_order,"manager");setPurchase({supplierId:"",title:"",amount:0,paid:0,note:"",ingredientId:"",quantity:0,unitCost:0});
    }catch(e){setError(e instanceof Error?e.message:"ثبت خرید انجام نشد");}
    finally{setBusy(false);}
  }
  async function editSupplier(supplier:Supplier){
    const name=window.prompt("نام تأمین‌کننده",supplier.name);if(name===null||!name.trim())return;
    const phone=window.prompt("شماره تماس",supplier.phone);if(phone===null)return;
    const contact=window.prompt("نام رابط",supplier.contact);if(contact===null)return;
    const updated={...supplier,name:name.trim(),phone:phone.trim(),contact:contact.trim()};
    await saveCentralRecord("suppliers",updated,()=>setSuppliers(old=>old.map(s=>s.id===supplier.id?updated:s)));
  }
  async function toggleSupplier(supplier:Supplier){
    const updated={...supplier,active:!supplier.active};
    await saveCentralRecord("suppliers",updated,()=>setSuppliers(old=>old.map(s=>s.id===supplier.id?updated:s)));
  }
  async function settle(supplier:Supplier){
    if(busy)return;
    const raw=window.prompt("مبلغ پرداختی برای تسویه (تومان):");if(raw===null)return;
    const amount=Number(raw);if(!Number.isFinite(amount)||amount<=0||amount>supplier.balance){setError("مبلغ تسویه معتبر نیست.");return;}
    const item={id:uid("pay"),supplierId:supplier.id,title:"پرداخت/تسویه",amount:0,paid:amount,date:tehranDate(),note:"تسویه حساب",kind:"payment"};
    setBusy(true);
    try{const result=await phpApi.createSupplierTransaction<typeof item,Supplier>(supplier.id,item);setSuppliers(old=>old.map(s=>s.id===supplier.id?result.supplier:s));setPurchases(old=>[result.item as Purchase,...old]);}
    catch(e){setError(e instanceof Error?e.message:"ثبت تسویه انجام نشد");}
    finally{setBusy(false);}
  }
  return <div className="space-y-4"><PageHeading title="مدیریت تأمین‌کنندگان" description="تأمین‌کننده‌ها، خریدها و مانده‌حساب‌ها را پیگیری کنید." />{error?<p className="text-sm text-warn">{error}</p>:null}
    <Card><h3 className="mb-2 font-semibold">ثبت تأمین‌کننده</h3><form className="grid gap-2 sm:grid-cols-2" onSubmit={e=>void addSupplier(e)}>
      <Field label="نام تأمین‌کننده"><input className={inputCls} value={draft.name} onChange={e=>setDraft({...draft,name:e.target.value})} required/></Field>
      <Field label="شماره تماس"><input className={inputCls} value={draft.phone} onChange={e=>setDraft({...draft,phone:e.target.value})}/></Field>
      <Field label="نام رابط"><input className={inputCls} value={draft.contact} onChange={e=>setDraft({...draft,contact:e.target.value})}/></Field>
      <Field label="یادداشت"><input className={inputCls} value={draft.notes} onChange={e=>setDraft({...draft,notes:e.target.value})}/></Field><div><Btn type="submit">ثبت تأمین‌کننده</Btn></div>
    </form></Card>
    <Card><h3 className="mb-2 font-semibold">ثبت فاکتور خرید / بدهی</h3><form className="grid gap-2 sm:grid-cols-2" onSubmit={e=>void addPurchase(e)}>
      <Field label="تأمین‌کننده"><select className={inputCls} value={purchase.supplierId} onChange={e=>setPurchase({...purchase,supplierId:e.target.value})} required><option value="">انتخاب کنید</option>{suppliers.filter(s=>s.active).map(s=><option key={s.id} value={s.id}>{s.name}</option>)}</select></Field>
      <Field label="شرح خرید"><input className={inputCls} value={purchase.title} onChange={e=>setPurchase({...purchase,title:e.target.value})} required/></Field>
      <Field label="ماده اولیه (اختیاری؛ برای ورود به انبار)"><select className={inputCls} value={purchase.ingredientId} onChange={e=>{const ingredient=ingredients.find(x=>x.id===e.target.value);setPurchase({...purchase,ingredientId:e.target.value,title:ingredient?purchase.title||ingredient.name:purchase.title,unitCost:ingredient?.costPerUnit??purchase.unitCost});}}><option value="">خرید غیرانبار / سایر</option>{ingredients.map(x=><option key={x.id} value={x.id}>{x.name} — موجودی {x.stock} {x.unit}</option>)}</select></Field>
      {purchase.ingredientId?<><Field label="مقدار ورودی"><input className={inputCls} type="number" min="0.001" step="0.001" value={purchase.quantity||""} onChange={e=>{const quantity=Number(e.target.value);setPurchase({...purchase,quantity,amount:Math.round(quantity*purchase.unitCost)});}} required/></Field><Field label="قیمت خرید هر واحد"><input className={inputCls} type="number" min="1" value={purchase.unitCost||""} onChange={e=>{const unitCost=Number(e.target.value);setPurchase({...purchase,unitCost,amount:Math.round(purchase.quantity*unitCost)});}} required/></Field></>:null}
      <Field label="مبلغ کل (تومان)"><input className={inputCls} type="number" min="1" value={(purchase.ingredientId?Math.round(purchase.quantity*purchase.unitCost):purchase.amount)||""} onChange={e=>setPurchase({...purchase,amount:Number(e.target.value)})} disabled={!!purchase.ingredientId} required/></Field>
      <Field label="پرداخت‌شده"><input className={inputCls} type="number" min="0" value={purchase.paid||""} onChange={e=>setPurchase({...purchase,paid:Number(e.target.value)})}/></Field>
      <Field label="یادداشت"><input className={inputCls} value={purchase.note} onChange={e=>setPurchase({...purchase,note:e.target.value})}/></Field><div><Btn type="submit" disabled={busy}>ثبت خرید</Btn></div>
    </form></Card>
    {suppliers.map(s=><Card key={s.id}><div className="flex flex-wrap items-center justify-between gap-2"><div><b>{s.name}</b><p className="text-xs text-muted">{s.phone} · رابط: {s.contact||"—"}</p></div><div className="text-left"><b>{money(s.balance)} بدهی</b><div className="flex gap-1"><Btn tone="ghost" onClick={()=>void editSupplier(s)}>ویرایش</Btn><Btn tone="ghost" onClick={()=>void toggleSupplier(s)}>{s.active?"غیرفعال‌کردن":"فعال‌کردن"}</Btn><Btn tone="ghost" onClick={()=>void settle(s)} disabled={s.balance<=0}>ثبت تسویه</Btn></div></div></div>
      <div className="mt-2 border-t border-line pt-2 text-sm">{purchases.filter(p=>p.supplierId===s.id).map(p=><p key={p.id} className="flex justify-between"><span>{p.title} · {new Date(p.date+"T12:00:00").toLocaleDateString("fa-IR",{timeZone:"Asia/Tehran"})}{p.quantity&&p.unitCost?` · ${p.quantity} × ${money(p.unitCost)}`:""}</span><span>{money(p.amount)} / پرداخت {money(p.paid)}</span></p>)}</div></Card>)}
    {busy?<p className="text-xs text-muted">در حال بارگذاری...</p>:null}
  </div>;
}

function SmsScreen() {
  const templates = usePos((s) => s.templates);
  const logs = usePos((s) => s.smsLogs);
  const saveTemplate = usePos((s) => s.saveTemplate);
  const customers = usePos((s) => s.customers);
  const orders = usePos((s) => s.orders);
  const settings = usePos((s) => s.settings);
  const patchSettings = usePos((s) => s.patchSettings);
  const [manual, setManual] = useState({ to: "", text: "" });
  const [birthdayPromptBusy, setBirthdayPromptBusy] = useState(false);
  const [birthdayPromptProgress, setBirthdayPromptProgress] = useState("");
  const products = usePos((s) => s.products);
  const [campaignLogic,setCampaignLogic]=useState<"all"|"any">("all");
  const [campaignFilters,setCampaignFilters]=useState({tier:"",birthday:"",inactiveDays:"",minOrders:"",minSpent:"",productId:""});
  const [campaignText,setCampaignText]=useState("");
  const [campaignBusy,setCampaignBusy]=useState(false);
  const [campaignProgress,setCampaignProgress]=useState("");
  const [campaignClock,setCampaignClock]=useState(INITIAL_RENDER_TIME);
  useEffect(() => { const timer=window.setInterval(()=>setCampaignClock(Date.now()),60000); return ()=>window.clearInterval(timer); }, []);
  const activeCampaignFilters = Object.entries(campaignFilters).filter(([,value])=>value!=="");
  const campaignRecipients=customers.filter(customer=>{
    if(customer.smsConsent!==true||!normalizeIranPhone(customer.phone))return false;
    const customerOrders=orders.filter(order=>order.status!=="cancelled"&&(order.customerId===customer.id||normalizeIranPhone(order.customerPhone)===normalizeIranPhone(customer.phone)));
    const latest=customerOrders.reduce((max,order)=>Math.max(max,new Date(order.createdAt).getTime()),0);
    const conditions:boolean[]=[];
    if(campaignFilters.tier)conditions.push(tierOf(customer.points,settings)===campaignFilters.tier);
    if(campaignFilters.birthday==="month")conditions.push(!!customer.birthday&&customer.birthday.slice(5,7)===tehranDate().slice(5,7));
    if(campaignFilters.birthday==="known")conditions.push(!!customer.birthday);
    if(campaignFilters.inactiveDays){const days=Number(campaignFilters.inactiveDays);const baseline=latest||new Date(customer.createdAt||0).getTime();conditions.push(days>0&&(!baseline||campaignClock-baseline>=days*86400000));}
    if(campaignFilters.minOrders)conditions.push(customerOrders.length>=Number(campaignFilters.minOrders));
    if(campaignFilters.minSpent)conditions.push(customer.totalSpent>=Number(campaignFilters.minSpent));
    if(campaignFilters.productId)conditions.push(customerOrders.some(order=>order.lines.some(line=>line.productId===campaignFilters.productId)));
    return conditions.length===0|| (campaignLogic==="all"?conditions.every(Boolean):conditions.some(Boolean));
  });
  async function runCampaign(){
    if(campaignBusy||!campaignText.trim()||!campaignRecipients.length)return;
    if(campaignText.length>950){window.alert("متن پیامک باید حداکثر ۹۵۰ نویسه باشد.");return;}
    if(!window.confirm(`پیامک برای ${campaignRecipients.length.toLocaleString("fa-IR")} مشتری ارسال شود؟`))return;
    setCampaignBusy(true);let sent=0,failed=0;
    for(const customer of campaignRecipients){const ok=await sendRaw(customer.phone,campaignText);if(ok)sent++;else failed++;setCampaignProgress(`${(sent+failed).toLocaleString("fa-IR")} از ${campaignRecipients.length.toLocaleString("fa-IR")} · موفق ${sent.toLocaleString("fa-IR")} · ناموفق ${failed.toLocaleString("fa-IR")}`);}
    setCampaignBusy(false);setCampaignProgress(`پایان کمپین — موفق ${sent.toLocaleString("fa-IR")}، ناموفق ${failed.toLocaleString("fa-IR")}`);
  }
  return (
    <div className="space-y-4">
      <PageHeading title="مرکز پیامک" description="قالب‌های اطلاع‌رسانی، ارسال دستی و کمپین‌های مشتریان را مدیریت کنید." />
      <Card>
        <p className="text-sm">اعتبارنامهٔ ملی‌پیامک فقط در فایل خصوصی سرور (private/config.php) تنظیم می‌شود و در این صفحه یا مرورگر نگهداری نمی‌شود.</p>
        <p className="mt-2 text-xs text-muted">برای فعال‌سازی، مدیر سرور باید provider را روی national_payamak قرار دهد و نام کاربری، رمز/API Key و سرشماره را در تنظیمات خصوصی هاست وارد کند. برای آزمایش، ابتدا ارسال دستی را به شمارهٔ آزمایشی خود انجام دهید.</p>
      </Card>
      {templates.map((t) => (
        <Card key={t.event}>
          <label className="flex items-center gap-2 font-semibold">
            <input type="checkbox" checked={t.enabled} onChange={(e) => { const item = { ...t, enabled: e.target.checked }; void saveCentralRecord("templates", { ...item, id: item.event }, () => saveTemplate(item)); }} />
            {EVENT_LABEL[t.event]}
          </label>
          <textarea className={`${inputCls} mt-2`} rows={2} value={t.body} onChange={(e) => saveTemplate({ ...t, body: e.target.value })} onBlur={() => { const item = usePos.getState().templates.find((x) => x.event === t.event); if (item) void saveCentralRecord("templates", { ...item, id: item.event }, () => saveTemplate(item)); }} />
          <Field label="شناسه پترن (اختیاری)"><input className={inputCls} value={t.bodyId} onChange={(e) => saveTemplate({ ...t, bodyId: e.target.value })} onBlur={() => { const item = usePos.getState().templates.find((x) => x.event === t.event); if (item) void saveCentralRecord("templates", { ...item, id: item.event }, () => saveTemplate(item)); }} /></Field>
        </Card>
      ))}
      <Card>
        <h3 className="mb-2 font-semibold">دریافت تاریخ تولد از پیامک</h3>
        <p className="mb-2 text-xs text-muted">پس از تنظیم صندوق ورودی ملی‌پیامک در private/config.php، پاسخ‌های «تولد ۱۳۷۰/۰۵/۱۲» را دریافت و فقط برای شماره مشتری یکتای منطبق ثبت می‌کند. تاریخ تولد موجود بازنویسی نمی‌شود.</p>
        <Btn onClick={async () => {
          try {
            const result = await phpApi.syncBirthdaySmsInbox();
            window.alert(`بررسی صندوق انجام شد. دریافت: ${result.received}، تاریخ تولد ثبت‌شده: ${result.birthdays_saved}، تکراری: ${result.duplicates}، نامنطبق: ${result.unmatched}، نادیده‌گرفته‌شده: ${result.ignored}`);
          } catch (error) {
            window.alert(error instanceof Error ? error.message : "دریافت پیامک انجام نشد.");
          }
        }}>دریافت و ثبت پاسخ‌های تولد</Btn>
      </Card>
      <Card>
        <h3 className="mb-2 font-semibold">ارسال درخواست تاریخ تولد</h3>
        <p className="mb-2 text-xs text-muted">فقط به مشتریانی پیامک می‌فرستد که رضایت پیامکی دارند و تاریخ تولدشان ثبت نشده است. مشتری باید تاریخ را با قالب نمونه پاسخ دهد.</p>
        <textarea className={inputCls} rows={2} maxLength={950} value={settings.birthdaySmsPrompt ?? "برای ثبت تاریخ تولد در باشگاه مشتریان، لطفاً پاسخ دهید: تولد ۱۳۷۰/۰۵/۱۲"} onChange={e=>patchSettings({birthdaySmsPrompt:e.target.value})} onBlur={()=>{const current=usePos.getState().settings;void saveCentralRecord("settings",{id:"main",...current,smsUser:"",smsKey:"",smsFrom:""},()=>{});}} />
        <div className="mt-2 flex flex-wrap items-center gap-2">
          <Btn disabled={birthdayPromptBusy || !(settings.birthdaySmsPrompt ?? "").trim()} onClick={async () => {
            const targets = customers.filter(customer => customer.smsConsent === true && !customer.birthday && /^09[0-9]{9}$/.test(normalizeIranPhone(customer.phone)));
            if (!targets.length) { window.alert("مشتری واجد شرایطی برای دریافت درخواست وجود ندارد."); return; }
            if (!window.confirm(`درخواست ثبت تاریخ تولد برای ${targets.length.toLocaleString("fa-IR")} مشتری ارسال شود؟`)) return;
            setBirthdayPromptBusy(true);
            let sent = 0, failed = 0;
            for (const customer of targets) {
              if (await sendRaw(customer.phone, settings.birthdaySmsPrompt ?? "")) sent++; else failed++;
              setBirthdayPromptProgress(`موفق ${sent.toLocaleString("fa-IR")} · ناموفق ${failed.toLocaleString("fa-IR")} از ${targets.length.toLocaleString("fa-IR")}`);
            }
            setBirthdayPromptBusy(false);
            setBirthdayPromptProgress(`پایان ارسال — موفق ${sent.toLocaleString("fa-IR")}، ناموفق ${failed.toLocaleString("fa-IR")}`);
          }}>{birthdayPromptBusy ? "در حال ارسال..." : "ارسال درخواست به مشتریان واجد شرایط"}</Btn>
          <span className="text-xs text-muted">{birthdayPromptProgress}</span>
        </div>
      </Card>
      <Card>
        <h3 className="mb-2 font-semibold">ارسال دستی</h3>
        <div className="grid gap-2">
          <input className={inputCls} placeholder="شماره" value={manual.to} onChange={(e) => setManual({ ...manual, to: e.target.value })} />
          <textarea className={inputCls} rows={2} value={manual.text} onChange={(e) => setManual({ ...manual, text: e.target.value })} />
          <div className="flex flex-wrap gap-2">
            <Btn onClick={() => void sendRaw(manual.to, manual.text)}>ارسال به یک شماره</Btn>
            
          </div>
        </div>
      </Card>
      <Card>
        <h3 className="mb-2 font-semibold">کمپین باشگاه مشتریان</h3>
        <p className="mb-2 text-xs text-muted">فقط اعضایی انتخاب می‌شوند که رضایت دریافت پیامک تبلیغاتی را ثبت کرده باشند. می‌توانید چند شرط را ترکیب کنید.</p>
        <Field label="نحوه ترکیب شروط"><select className={inputCls} value={campaignLogic} onChange={e=>setCampaignLogic(e.target.value as "all"|"any")}><option value="all">همه شروط برقرار باشند (AND)</option><option value="any">حداقل یکی از شروط برقرار باشد (OR)</option></select></Field>
        <div className="grid gap-2 sm:grid-cols-2">
          <Field label="سطح عضویت"><select className={inputCls} value={campaignFilters.tier} onChange={e=>setCampaignFilters({...campaignFilters,tier:e.target.value})}><option value="">همه سطوح</option><option value="bronze">برنز</option><option value="silver">نقره</option><option value="gold">طلا</option><option value="platinum">پلاتین</option></select></Field>
          <Field label="تاریخ تولد"><select className={inputCls} value={campaignFilters.birthday} onChange={e=>setCampaignFilters({...campaignFilters,birthday:e.target.value})}><option value="">بدون شرط</option><option value="month">متولدین ماه جاری</option><option value="known">دارای تاریخ تولد ثبت‌شده</option></select></Field>
          <Field label="عدم خرید در چند روز اخیر"><input className={inputCls} type="number" min="1" max="3650" value={campaignFilters.inactiveDays} onChange={e=>setCampaignFilters({...campaignFilters,inactiveDays:e.target.value})} placeholder="مثلاً ۴۵ روز"/></Field>
          <Field label="حداقل تعداد سفارش"><input className={inputCls} type="number" min="1" value={campaignFilters.minOrders} onChange={e=>setCampaignFilters({...campaignFilters,minOrders:e.target.value})} placeholder="مثلاً ۳"/></Field>
          <Field label="حداقل مجموع خرید (تومان)"><input className={inputCls} type="number" min="1" value={campaignFilters.minSpent} onChange={e=>setCampaignFilters({...campaignFilters,minSpent:e.target.value})} placeholder="مثلاً ۱۰۰۰۰۰۰"/></Field>
          <Field label="خرید محصول مشخص"><select className={inputCls} value={campaignFilters.productId} onChange={e=>setCampaignFilters({...campaignFilters,productId:e.target.value})}><option value="">همه محصولات</option>{products.map(product=><option key={product.id} value={product.id}>{product.name}</option>)}</select></Field>
        </div>
        <div className="flex justify-end"><Btn tone="ghost" onClick={()=>setCampaignFilters({tier:"",birthday:"",inactiveDays:"",minOrders:"",minSpent:"",productId:""})}>پاک کردن شروط</Btn></div>
        <p className="my-2 text-sm">تعداد شروط فعال: <b>{activeCampaignFilters.length.toLocaleString("fa-IR")}</b> · تعداد گیرندگان واجد شرایط و دارای رضایت: <b>{campaignRecipients.length.toLocaleString("fa-IR")}</b></p>
        <textarea className={inputCls} rows={3} maxLength={950} value={campaignText} onChange={e=>setCampaignText(e.target.value)} placeholder="متن پیامک کمپین..."/>
        <div className="mt-2 flex flex-wrap items-center gap-2"><Btn onClick={()=>void runCampaign()} disabled={campaignBusy||!campaignText.trim()||campaignRecipients.length===0}>{campaignBusy?"در حال ارسال...":"ارسال کمپین"}</Btn><span className="text-xs text-muted">{campaignProgress}</span></div>
      </Card>
      <Card>
        <h3 className="mb-2 font-semibold">لاگ ارسال</h3>
        {logs.length === 0 ? <Empty text="ارسالی ثبت نشده" /> : logs.slice(0, 20).map((l) => (
          <p key={l.id} className="border-b border-line py-2 text-sm"><span className={l.ok ? "text-leaf" : "text-warn"}>{l.ok ? "موفق" : "ناموفق"}</span> — {l.to} — {l.detail}</p>
        ))}
      </Card>
    </div>
  );
}

type ServerOrder = {
  id: string;
  orderNumber: number;
  status: Order["status"];
  items: CartLine[];
  totals: Partial<Pick<Order, "subtotal" | "tierDiscount" | "codeDiscount" | "pointsDiscount" | "tax" | "total">>;
  created_at?: string;
  createdAt?: string;
  customerId?: string | null;
  customerName?: string | null;
  customerPhone?: string | null;
  payment?: Order["payment"];
  pointsUsed?: number;
  pointsEarned?: number;
  discountCode?: string | null;
};

type ServerBootstrap = Partial<Omit<PosState, "orders" | "settings">> & {
  orders?: ServerOrder[];
  sms_logs?: SmsLog[];
  settings?: Array<Partial<Settings> & { id?: string }>;
};

function readLegacyBrowserPosState(): Partial<PosState> | null {
  try {
    const raw = window.localStorage.getItem("grillcash-v1");
    if (!raw) return null;
    const parsed = JSON.parse(raw) as { state?: Partial<PosState> } & Partial<PosState>;
    const state = parsed.state ?? parsed;
    const hasBusinessData = ["categories", "products", "ingredients", "customers", "discounts", "orders", "expenses", "templates"]
      .some((key) => Array.isArray(state[key as keyof PosState]) && (state[key as keyof PosState] as unknown[]).length > 0);
    if (!hasBusinessData) return null;
    return {
      ...state,
      settings: state.settings ? {
        ...state.settings,
        smsUser: "",
        smsKey: "",
        smsFrom: "",
      } : undefined,
    };
  } catch {
    return null;
  }
}

function hydratePosFromServer(data: ServerBootstrap, nextOrder: number, role: Role) {
  const current = usePos.getState();
  const serverSettings = data.settings?.[0];
  const orders: Order[] = (data.orders ?? []).map((order) => ({
    id: String(order.id),
    number: Number(order.orderNumber),
    createdAt: order.createdAt ?? order.created_at ?? new Date().toISOString(),
    status: order.status,
    lines: Array.isArray(order.items) ? order.items : [],
    subtotal: Number(order.totals?.subtotal ?? 0),
    tierDiscount: Number(order.totals?.tierDiscount ?? 0),
    codeDiscount: Number(order.totals?.codeDiscount ?? 0),
    pointsDiscount: Number(order.totals?.pointsDiscount ?? 0),
    tax: Number(order.totals?.tax ?? 0),
    total: Number(order.totals?.total ?? 0),
    payment: order.payment ?? "cash",
    customerId: order.customerId ?? null,
    customerName: order.customerName ?? "",
    customerPhone: order.customerPhone ?? "",
    discountCode: order.discountCode ?? "",
    pointsUsed: Number(order.pointsUsed ?? 0),
    pointsEarned: Number(order.pointsEarned ?? 0),
  }));

  // Bootstrap is a complete snapshot for the authenticated role. Missing
  // collections are intentionally cleared (e.g. kitchen must not retain
  // manager-only records from a previous in-memory session).
  usePos.setState({
    categories: data.categories ?? [],
    products: data.products ?? [],
    ingredients: data.ingredients ?? [],
    customers: data.customers ?? [],
    discounts: data.discounts ?? [],
    expenses: data.expenses ?? [],
    templates: data.templates ?? [],
    smsLogs: data.sms_logs ?? [],
    orders,
    settings: {
      ...current.settings,
      ...(serverSettings ?? {}),
      smsUser: "",
      smsKey: "",
      smsFrom: "",
      role,
    },
    nextOrder: Math.max(nextOrder, 1),
  });
  return true;
}


function AppearanceSettingsPanel(){
 const {settings,update,save,reset,uploadLogo}=useAppearance();
 const [busy,setBusy]=useState(false),[logoBusy,setLogoBusy]=useState(false),[note,setNote]=useState("");
 async function persist(){setBusy(true);setNote("");try{await save();setNote("ظاهر جدید برای همه کاربران ذخیره شد.");}catch(e){setNote(e instanceof Error?e.message:"ذخیره ظاهر انجام نشد.");}finally{setBusy(false);}}
 async function restore(){if(!window.confirm("همه تنظیمات ظاهری به حالت پیش‌فرض برگردد؟"))return;setBusy(true);setNote("");try{await reset();setNote("ظاهر پیش‌فرض برای همه کاربران فعال شد.");}catch(e){setNote(e instanceof Error?e.message:"بازنشانی انجام نشد.");}finally{setBusy(false);}}
 async function logo(file?:File){if(!file)return;setLogoBusy(true);setNote("");try{await uploadLogo(file);setNote("لوگو بارگذاری شد؛ برای انتشار آن روی همه دستگاه‌ها «ذخیره ظاهر» را بزنید.");}catch(e){setNote(e instanceof Error?e.message:"بارگذاری لوگو انجام نشد.");}finally{setLogoBusy(false);}}
 return <Card className="space-y-4">
 <div><h3 className="font-semibold">طراحی ظاهری سراسری</h3><p className="mt-1 text-xs text-muted">تغییرات زنده پیش‌نمایش می‌شوند؛ پس از ذخیره برای همه کاربران و دستگاه‌ها اعمال خواهند شد.</p></div>
 <div>
  <p className="mb-2 text-sm font-semibold">ترکیب‌رنگ‌های آماده</p>
  <p className="mb-3 text-xs text-muted">پالت‌های کلاسیک و مدرن آماده؛ سه ترکیب مدرن به‌صورت خودکار روی حالت روشن قرار می‌گیرند.</p>
  <div className="grid gap-2 sm:grid-cols-2 xl:grid-cols-3">
   {APPEARANCE_PRESETS.map(preset=>{
    const selected=settings.primary.toLowerCase()===preset.primary.toLowerCase()&&settings.secondary.toLowerCase()===preset.secondary.toLowerCase()&&(!("mode" in preset)||settings.mode===preset.mode);
    return <button key={preset.id} type="button" aria-pressed={selected} onClick={()=>update({primary:preset.primary,secondary:preset.secondary,...("mode" in preset?{mode:preset.mode}:{})})} className={`flex min-w-0 items-center gap-3 rounded-2xl border p-3 text-right transition ${selected?"border-chili bg-chili/5 ring-2 ring-chili/15":"border-line bg-surface hover:border-leaf"}`}>
     <span className="flex shrink-0 gap-1.5"><span className="h-9 w-9 rounded-xl border border-black/10" style={{backgroundColor:preset.primary}}/><span className="mt-1 h-7 w-7 rounded-lg border border-black/10" style={{backgroundColor:preset.secondary}}/></span>
     <span className="min-w-0 flex-1"><span className="block text-sm font-bold">{preset.name}</span><span className="mt-1 block text-[11px] leading-5 text-muted">{preset.description}</span></span>
     {selected?<span className="shrink-0 text-xs font-bold text-leaf">انتخاب‌شده</span>:null}
    </button>;
   })}
  </div>
 </div>
 <div className="grid gap-3 sm:grid-cols-2">
 <Field label="رنگ اصلی"><div className="flex items-center gap-3"><input aria-label="رنگ اصلی" type="color" value={settings.primary} onChange={e=>update({primary:e.target.value})} className="h-11 w-16 cursor-pointer rounded-lg border border-line bg-surface p-1"/><span className="text-xs text-muted">{settings.primary}</span></div></Field>
 <Field label="رنگ مکمل"><div className="flex items-center gap-3"><input aria-label="رنگ مکمل" type="color" value={settings.secondary} onChange={e=>update({secondary:e.target.value})} className="h-11 w-16 cursor-pointer rounded-lg border border-line bg-surface p-1"/><span className="text-xs text-muted">{settings.secondary}</span></div></Field>
 <Field label="حالت نمایش"><select className={inputCls} value={settings.mode} onChange={e=>update({mode:e.target.value as AppearanceSettings["mode"]})}><option value="light">روشن</option><option value="dark">تیره</option></select></Field>
 <Field label="فونت سراسری"><div className={inputCls}>IRANYekanWeb FarsiNum — ایران‌یکان فارسی</div></Field>
 <Field label={`اندازه متن: ${settings.fontSize} پیکسل`}><input className="w-full accent-chili" type="range" min="13" max="20" value={settings.fontSize} onChange={e=>update({fontSize:Number(e.target.value)})}/></Field>
 <Field label={`گردی کارت‌ها: ${settings.cardRadius} پیکسل`}><input className="w-full accent-chili" type="range" min="8" max="28" value={settings.cardRadius} onChange={e=>update({cardRadius:Number(e.target.value)})}/></Field>
 <Field label={`گردی دکمه‌ها: ${settings.buttonRadius} پیکسل`}><input className="w-full accent-chili" type="range" min="6" max="24" value={settings.buttonRadius} onChange={e=>update({buttonRadius:Number(e.target.value)})}/></Field>
 <Field label="لوگو (PNG، JPG یا WebP؛ حداکثر ۵۱۲ کیلوبایت)"><input className={inputCls} type="file" accept="image/png,image/jpeg,image/webp" disabled={logoBusy} onChange={e=>{void logo(e.target.files?.[0]);e.currentTarget.value="";}}/></Field>
 </div>
 <div className="rounded-[var(--radius-card)] border border-line bg-bg p-4"><p className="mb-3 text-xs text-muted">پیش‌نمایش زنده</p><div className="flex flex-wrap items-center gap-3">{settings.logoUrl?<img src={settings.logoUrl} alt="پیش‌نمایش لوگو" className="h-12 w-12 rounded-lg object-contain"/>:null}<div className="min-w-0 flex-1"><p className="font-bold">گریل‌کَش | 3Food POS</p><p className="text-xs text-muted">نمونه ظاهر پنل فروشگاه</p></div><span className="rounded-full bg-leaf px-3 py-1 text-xs text-leaf-ink">فعال</span><button type="button" className="rounded-xl bg-chili px-4 py-2 text-sm font-semibold text-chili-ink">دکمه نمونه</button></div></div>
 <div className="flex flex-wrap items-center gap-2"><Btn onClick={()=>void persist()} disabled={busy||logoBusy}>{busy?"در حال ذخیره...":"ذخیره ظاهر برای همه"}</Btn><Btn tone="ghost" onClick={()=>void restore()} disabled={busy||logoBusy}>بازنشانی پیش‌فرض</Btn>{logoBusy?<span className="text-xs text-muted">در حال بارگذاری لوگو...</span>:null}</div>
 {note?<p className="text-sm text-muted" role="status">{note}</p>:null}</Card>;
}


function TerminalSettingsPanel() {
  const [agents,setAgents]=useState<TerminalAgent[]>([]);
  const [terminals,setTerminals]=useState<PaymentTerminal[]>([]);
  const [agentName,setAgentName]=useState("صندوق اصلی");
  const [newToken,setNewToken]=useState("");
  const [agentId,setAgentId]=useState("");
  const [localKey,setLocalKey]=useState("terminal-1");
  const [terminalName,setTerminalName]=useState("کارتخوان اصلی");
  const [provider,setProvider]=useState("unknown");
  const [model,setModel]=useState("");
  const [driverCode,setDriverCode]=useState("unconfigured");
  const [host,setHost]=useState("192.168.1.100");
  const [port,setPort]=useState("5000");
  const [connection,setConnection]=useState<"lan"|"wifi">("lan");
  const [isDefault,setIsDefault]=useState(false);
  const [busy,setBusy]=useState(false);
  const [note,setNote]=useState("");
  async function refresh(){
    const [a,t]=await Promise.all([phpApi.listTerminalAgents(),phpApi.listTerminals()]);
    setAgents(a.items);setTerminals(t.items);
    if(!agentId&&a.items[0])setAgentId(a.items[0].id);
  }
  useEffect(()=>{let active=true;void Promise.all([phpApi.listTerminalAgents(),phpApi.listTerminals()]).then(([a,t])=>{if(!active)return;setAgents(a.items);setTerminals(t.items);if(a.items[0])setAgentId(a.items[0].id);}).catch(e=>{if(active)setNote(e instanceof Error?e.message:"دریافت تنظیمات کارتخوان انجام نشد");});return()=>{active=false;};},[]);
  async function createAgent(){
    setBusy(true);setNote("");setNewToken("");
    try{const result=await phpApi.createTerminalAgent(agentName.trim());setNewToken(result.token);await refresh();setAgentId(result.agent.id);setNote("عامل محلی ساخته شد. توکن فقط همین یک‌بار نمایش داده می‌شود.");}
    catch(e){setNote(e instanceof Error?e.message:"ساخت عامل محلی انجام نشد");}finally{setBusy(false);}
  }
  async function saveTerminal(){
    setBusy(true);setNote("");
    try{
      if(!agentId)throw new Error("ابتدا یک کامپیوتر صندوق تعریف کنید.");
      await phpApi.saveTerminal({id:uid("pt"),agentId,localTerminalKey:localKey.trim(),name:terminalName.trim(),providerCode:provider,modelName:model.trim(),driverCode:driverCode.trim()||"unconfigured",connectionType:connection,host:host.trim(),port:Number(port),enabled:true,isDefault:isDefault||terminals.length===0});
      await refresh();setNote("مشخصات کارتخوان ذخیره شد. تا نصب درایور تأییدشده، پرداخت واقعی غیرفعال می‌ماند.");
    }catch(e){setNote(e instanceof Error?e.message:"ذخیره کارتخوان انجام نشد");}finally{setBusy(false);}
  }
  async function disableAgent(id:string){if(!window.confirm("عامل محلی غیرفعال شود؟ دستگاه‌های متصل به آن دیگر پرداخت نمی‌پذیرند."))return;setBusy(true);try{await phpApi.disableTerminalAgent(id);await refresh();}catch(e){setNote(e instanceof Error?e.message:"غیرفعال‌سازی انجام نشد");}finally{setBusy(false);}}
  async function disableTerminal(id:string){if(!window.confirm("این کارتخوان غیرفعال شود؟"))return;setBusy(true);try{await phpApi.disableTerminal(id);await refresh();}catch(e){setNote(e instanceof Error?e.message:"غیرفعال‌سازی انجام نشد");}finally{setBusy(false);}}
  return <Card className="space-y-4">
    <div><h3 className="font-semibold">مدیریت کارتخوان‌های بانکی</h3><p className="mt-1 text-xs leading-6 text-muted">اتصال شبکه‌ای چندکارتخوان از طریق عامل محلی صندوق طراحی شده است. ابتدا کامپیوتری که به شبکه کارتخوان دسترسی دارد ثبت می‌شود.</p></div>
    <div className="rounded-xl border border-line bg-bg p-3 space-y-3">
      <div className="flex items-center justify-between gap-2"><p className="text-sm font-semibold">۱. ثبت کامپیوتر صندوق</p><Btn tone="ghost" onClick={()=>void refresh()} disabled={busy}>به‌روزرسانی وضعیت</Btn></div>
      <div className="flex flex-col gap-2 sm:flex-row"><input className={inputCls+" flex-1"} aria-label="نام کامپیوتر صندوق" value={agentName} onChange={e=>setAgentName(e.target.value)} placeholder="نام کامپیوتر صندوق"/><Btn onClick={()=>void createAgent()} disabled={busy||!agentName.trim()}>ساخت عامل محلی</Btn></div>
      {newToken?<div className="space-y-2 rounded-lg border border-warn bg-warn-bg p-3"><p className="text-xs text-warn">توکن اتصال را در محل امن نگه دارید؛ پس از بستن صفحه دیگر نمایش داده نمی‌شود.</p><code className="block break-all text-xs">{newToken}</code><Btn tone="ghost" onClick={()=>void navigator.clipboard.writeText(newToken)}>کپی توکن</Btn></div>:null}
      {agents.length?<div className="space-y-2">{agents.map(a=><div key={a.id} className="flex flex-wrap items-center justify-between gap-2 rounded-lg border border-line bg-surface p-3"><div><p className="text-sm font-medium">{a.name}</p><p className="text-xs text-muted">{a.enabled?"فعال":"غیرفعال"} · آخرین ارتباط: {a.last_seen_at??"هنوز متصل نشده"}</p></div>{a.enabled?<Btn tone="ghost" onClick={()=>void disableAgent(a.id)} disabled={busy}>غیرفعال‌سازی</Btn>:null}</div>)}</div>:<p className="text-xs text-muted">هنوز کامپیوتر صندوقی ثبت نشده است.</p>}
    </div>
    <div className="space-y-3">
      <p className="text-sm font-semibold">۲. افزودن کارتخوان شبکه‌ای</p>
      <div className="grid gap-3 sm:grid-cols-2">
        <Field label="کامپیوتر صندوق"><select className={inputCls} value={agentId} onChange={e=>setAgentId(e.target.value)}><option value="">انتخاب کنید</option>{agents.filter(a=>Boolean(a.enabled)).map(a=><option key={a.id} value={a.id}>{a.name}</option>)}</select></Field>
        <Field label="نام کارتخوان"><input className={inputCls} value={terminalName} onChange={e=>setTerminalName(e.target.value)}/></Field>
        <Field label="شرکت پرداخت"><select className={inputCls} value={provider} onChange={e=>setProvider(e.target.value)}><option value="unknown">نامشخص</option><option value="behpardakht">به‌پرداخت ملت</option><option value="sep">پرداخت الکترونیک سامان‌کیش (سپ)</option><option value="iran_kish">ایران‌کیش</option><option value="asan_pardakht">آسان پرداخت</option><option value="pasargad">پاسارگاد</option><option value="parsian">پارسیان</option><option value="other">سایر</option></select></Field>
        <Field label="مدل دستگاه"><input className={inputCls} value={model} onChange={e=>setModel(e.target.value)} placeholder="مدل کارتخوان"/></Field>
        <Field label="کد درایور"><input className={inputCls} value={driverCode} onChange={e=>setDriverCode(e.target.value)} placeholder="unconfigured"/></Field>
        {provider==="sep"&&<p className="sm:col-span-2 rounded-lg border border-line bg-bg p-3 text-xs leading-6 text-muted">سامان‌کیش (سپ): مسیر اتصال Central PC-POS باید با مشخصات پذیرنده و مستندات رسمی سپ فعال شود. تا نصب درایور تأییدشده، پرداخت واقعی ارسال نمی‌شود. IP یا پورت را حدس نزنید.</p>}
        <Field label="نوع اتصال"><select className={inputCls} value={connection} onChange={e=>setConnection(e.target.value as "lan"|"wifi")}><option value="lan">کابل شبکه (LAN)</option><option value="wifi">Wi-Fi</option></select></Field>
        <Field label="IP کارتخوان"><input className={inputCls} value={host} onChange={e=>setHost(e.target.value)} placeholder="192.168.1.100"/></Field>
        <Field label="پورت"><input className={inputCls} type="number" min="1" max="65535" value={port} onChange={e=>setPort(e.target.value)}/></Field>
        <Field label="کلید محلی دستگاه"><input className={inputCls} value={localKey} onChange={e=>setLocalKey(e.target.value)} placeholder="terminal-1"/></Field>
      </div>
      <label className="flex items-center gap-2 text-sm"><input type="checkbox" checked={isDefault} onChange={e=>setIsDefault(e.target.checked)}/> انتخاب به‌عنوان کارتخوان پیش‌فرض</label>
      <div className="rounded-lg bg-bg p-3 text-xs leading-6 text-muted">کد درایور باید دقیقاً با افزونه معتبر نصب‌شده روی عامل محلی مطابقت داشته باشد. تا زمانی که درایور رسمی و تأییدشده نصب نشده، مقدار را «unconfigured» نگه دارید؛ واردکردن IP یا کد دلخواه به‌تنهایی اتصال بانکی ایجاد نمی‌کند.</div>
      <Btn onClick={()=>void saveTerminal()} disabled={busy||!agentId||!terminalName.trim()||!localKey.trim()||!host.trim()||!Number.isInteger(Number(port))||Number(port)<1||Number(port)>65535}>ذخیره کارتخوان</Btn>
      {terminals.length?<div className="space-y-2">{terminals.map(t=><div key={t.id} className="flex flex-wrap items-center justify-between gap-2 rounded-lg border border-line bg-surface p-3"><div><p className="text-sm font-medium">{t.name}</p><p className="text-xs text-muted" dir="ltr">{t.host}:{t.port} · {t.provider_code??"unknown"} · {t.driver_code??"unconfigured"}</p><p className="text-xs text-muted">{t.enabled?"فعال":"غیرفعال"}{t.is_default?" · پیش‌فرض":""} · {t.agent_name??""}</p></div>{t.enabled?<Btn tone="ghost" onClick={()=>void disableTerminal(t.id)} disabled={busy}>غیرفعال‌سازی</Btn>:null}</div>)}</div>:null}
    </div>
    {note?<p className="text-sm text-muted" role="status">{note}</p>:null}
  </Card>;
}

function SettingsScreen() {
  const settings = usePos((s) => s.settings);
  const patchSettings = usePos((s) => s.patchSettings);
  const [note, setNote] = useState("");
  const [importing, setImporting] = useState(false);
  const [exporting, setExporting] = useState(false);
  const [restoring, setRestoring] = useState(false);
  const restoreInput = useRef<HTMLInputElement>(null);
  function persistSettings() {
    const current = usePos.getState().settings;
    void saveCentralRecord("settings", { id: "main", ...current, smsUser: "", smsKey: "", smsFrom: "" }, () => {});
  }

  async function importLocalDataToMysql() {
    if (!window.confirm("داده‌های فعلی همین دستگاه (به‌جز تاریخچه سفارش‌ها) فقط یک‌بار به دیتابیس خالی منتقل می‌شوند. ادامه می‌دهید؟")) return;
    setImporting(true);
    setNote("");
    try {
      const current = usePos.getState();
      const currentHasData = [current.categories, current.products, current.ingredients, current.customers, current.discounts, current.orders, current.expenses, current.templates]
        .some((items) => items.length > 0);
      const legacy = currentHasData ? null : readLegacyBrowserPosState();
      const state: PosState = { ...current, ...(legacy ?? {}) };
      const hasData = [state.categories, state.products, state.ingredients, state.customers, state.discounts, state.orders, state.expenses, state.templates]
        .some((items) => items.length > 0);
      if (!hasData) throw new Error("داده قدیمی یا پشتیبان قابل انتقالی در این دستگاه پیدا نشد.");
      const result = await phpApi.importLegacyData({
        nextOrder: state.nextOrder,
        data: {
          categories: state.categories,
          products: state.products,
          ingredients: state.ingredients,
          customers: state.customers,
          discounts: state.discounts,
          expenses: state.expenses,
          templates: state.templates,
          settings: [{ id: "main", ...state.settings, smsUser: "", smsKey: "", smsFrom: "" }],
        },
      });
      const total = Object.values(result.counts).reduce((sum, count) => sum + count, 0);
      if (legacy) window.localStorage.removeItem("grillcash-v1");
      setNote(`${total.toLocaleString("fa-IR")} رکورد به MySQL منتقل شد؛ صفحه برای بارگذاری داده مرکزی تازه می‌شود.`);
      window.location.reload();
    } catch (error) {
      setNote(error instanceof Error ? error.message : "انتقال داده انجام نشد");
    } finally {
      setImporting(false);
    }
  }
  async function restoreBackupFile(file: File) {
    if (file.size > 8 * 1024 * 1024) {
      setNote("حجم فایل پشتیبان بیشتر از ۸ مگابایت است.");
      return;
    }
    if (!window.confirm("هشدار: بازیابی فقط روی دیتابیس عملیاتی خالی مجاز است. فایل باید متعلق به 3Food POS و قابل اعتماد باشد. ادامه می‌دهید؟")) return;
    setRestoring(true);
    setNote("");
    try {
      const backup = JSON.parse(await file.text()) as Record<string, unknown>;
      const result = await phpApi.restoreBackup(backup);
      const counts = result.restored;
      setNote(`بازیابی انجام شد: ${counts.records} رکورد، ${counts.orders} سفارش و دفترهای تراکنشی. صفحه تازه‌سازی می‌شود.`);
      window.location.reload();
    } catch (error) {
      setNote(error instanceof Error ? error.message : "بازیابی انجام نشد؛ دیتابیس تغییر نکرد.");
    } finally {
      setRestoring(false);
      if (restoreInput.current) restoreInput.current.value = "";
    }
  }

  async function download() {
    setExporting(true);
    setNote("");
    try {
      const result = await phpApi.exportBackup();
      const blob = new Blob([JSON.stringify(result.backup, null, 2)], { type: "application/json" });
      const url = URL.createObjectURL(blob);
      const a = document.createElement("a");
      a.href = url;
      a.download = `3food-pos-mysql-backup-${new Date().toISOString().slice(0, 10)}.json`;
      a.click();
      URL.revokeObjectURL(url);
      setNote("نسخه پشتیبان کامل از MySQL آماده شد.");
    } catch (error) {
      setNote(error instanceof Error ? error.message : "تهیه نسخه پشتیبان از MySQL انجام نشد");
    } finally {
      setExporting(false);
    }
  }
  return (
    <div className="space-y-4">
      <PageHeading title="تنظیمات فروشگاه" description="ظاهر سامانه، اطلاعات فروشگاه، فیش‌پرینتر و پشتیبان‌گیری را پیکربندی کنید." />
      <AppearanceSettingsPanel />
      <TerminalSettingsPanel />
      <Card className="grid gap-2 sm:grid-cols-2">
        <Field label="نام فروشگاه"><input className={inputCls} value={settings.shopName} onChange={(e) => patchSettings({ shopName: e.target.value })} onBlur={persistSettings} /></Field>
        <Field label="تلفن"><input className={inputCls} value={settings.phone} onChange={(e) => patchSettings({ phone: e.target.value })} onBlur={persistSettings} /></Field>
        <Field label="آدرس"><input className={inputCls} value={settings.address} onChange={(e) => patchSettings({ address: e.target.value })} onBlur={persistSettings} /></Field>
        <Field label="مالیات درصد"><input className={inputCls} type="number" min="0" max="100" step="0.01" value={settings.taxPercent} onChange={(e) => patchSettings({ taxPercent: Number(e.target.value) })} onBlur={persistSettings} /></Field>
        <Field label="سیاست فروش با موجودی ناکافی">
          <select className={inputCls} value={settings.stockPolicy ?? "warn_and_allow"} onChange={(e) => patchSettings({ stockPolicy: e.target.value as Settings["stockPolicy"] })} onBlur={persistSettings}>
            <option value="block">جلوگیری از ثبت سفارش</option>
            <option value="warn_and_allow">نمایش هشدار و اجازه ادامه فروش</option>
            <option value="allow_negative">اجازه موجودی منفی بدون نمایش هشدار</option>
          </select>
        </Field>
        <label className="flex items-center gap-2 text-sm"><input type="checkbox" checked={settings.taxEnabled !== false} onChange={(e) => { const value = e.target.checked; patchSettings({ taxEnabled: value }); const current = usePos.getState().settings; void saveCentralRecord("settings", { id: "main", ...current, taxEnabled: value, smsUser: "", smsKey: "", smsFrom: "" }, () => {}); }} />محاسبه مالیات فعال باشد</label>
        <Field label="هر چند تومان یک امتیاز"><input className={inputCls} type="number" value={settings.pointsPerToman} onChange={(e) => patchSettings({ pointsPerToman: Number(e.target.value) })} onBlur={persistSettings} /></Field>
        <Field label="ارزش هر امتیاز"><input className={inputCls} type="number" value={settings.tomanPerPoint} onChange={(e) => patchSettings({ tomanPerPoint: Number(e.target.value) })} onBlur={persistSettings} /></Field>
        <Field label="آستانه عضویت نقره (امتیاز)"><input className={inputCls} type="number" min="1" value={settings.loyaltySilverPoints??500} onChange={e=>patchSettings({loyaltySilverPoints:Math.max(1,Number(e.target.value))})} onBlur={persistSettings}/></Field>
        <Field label="تخفیف نقره (%)"><input className={inputCls} type="number" min="0" max="100" value={settings.loyaltySilverDiscount??3} onChange={e=>patchSettings({loyaltySilverDiscount:Math.min(100,Math.max(0,Number(e.target.value)))})} onBlur={persistSettings}/></Field>
        <Field label="آستانه عضویت طلا (امتیاز)"><input className={inputCls} type="number" min={(settings.loyaltySilverPoints??500)+1} value={settings.loyaltyGoldPoints??1500} onChange={e=>patchSettings({loyaltyGoldPoints:Math.max((settings.loyaltySilverPoints??500)+1,Number(e.target.value))})} onBlur={persistSettings}/></Field>
        <Field label="تخفیف طلا (%)"><input className={inputCls} type="number" min="0" max="100" value={settings.loyaltyGoldDiscount??5} onChange={e=>patchSettings({loyaltyGoldDiscount:Math.min(100,Math.max(0,Number(e.target.value)))})} onBlur={persistSettings}/></Field>
        <Field label="آستانه عضویت پلاتین (امتیاز)"><input className={inputCls} type="number" min={(settings.loyaltyGoldPoints??1500)+1} value={settings.loyaltyPlatinumPoints??4000} onChange={e=>patchSettings({loyaltyPlatinumPoints:Math.max((settings.loyaltyGoldPoints??1500)+1,Number(e.target.value))})} onBlur={persistSettings}/></Field>
        <Field label="تخفیف پلاتین (%)"><input className={inputCls} type="number" min="0" max="100" value={settings.loyaltyPlatinumDiscount??8} onChange={e=>patchSettings({loyaltyPlatinumDiscount:Math.min(100,Math.max(0,Number(e.target.value)))})} onBlur={persistSettings}/></Field>
        <Field label="عرض کاغذ">
          <select className={inputCls} value={settings.paperWidth} onChange={(e) => patchSettings({ paperWidth: Number(e.target.value) as 58 | 80 })} onBlur={persistSettings}>
            <option value={58}>۵۸ میلی‌متر</option><option value={80}>۸۰ میلی‌متر</option>
          </select>
        </Field>
        <Field label="بالای فیش"><input className={inputCls} value={settings.headerNote} onChange={(e) => patchSettings({ headerNote: e.target.value })} onBlur={persistSettings} /></Field>
        <Field label="پایین فیش"><input className={inputCls} value={settings.footerNote} onChange={(e) => patchSettings({ footerNote: e.target.value })} onBlur={persistSettings} /></Field>
        <label className="flex items-center gap-2 text-sm"><input type="checkbox" checked={settings.autoPrint} onChange={(e) => { const value = e.target.checked; patchSettings({ autoPrint: value }); const current = usePos.getState().settings; void saveCentralRecord("settings", { id: "main", ...current, autoPrint: value, smsUser: "", smsKey: "", smsFrom: "" }, () => {}); }} />چاپ خودکار</label>
        <label className="flex items-center gap-2 text-sm"><input type="checkbox" checked={settings.printCustomer} onChange={(e) => { const value = e.target.checked; patchSettings({ printCustomer: value }); const current = usePos.getState().settings; void saveCentralRecord("settings", { id: "main", ...current, printCustomer: value, smsUser: "", smsKey: "", smsFrom: "" }, () => {}); }} />فیش مشتری</label>
        <label className="flex items-center gap-2 text-sm"><input type="checkbox" checked={settings.printKitchen} onChange={(e) => { const value = e.target.checked; patchSettings({ printKitchen: value }); const current = usePos.getState().settings; void saveCentralRecord("settings", { id: "main", ...current, printKitchen: value, smsUser: "", smsKey: "", smsFrom: "" }, () => {}); }} />فیش آشپزخانه</label>
        <label className="flex items-center gap-2 text-sm"><input type="checkbox" checked={settings.invoiceSmsEnabledDefault ?? false} onChange={(e) => { const value=e.target.checked; patchSettings({invoiceSmsEnabledDefault:value}); const current=usePos.getState().settings; void saveCentralRecord("settings",{id:"main",...current,invoiceSmsEnabledDefault:value,smsUser:"",smsKey:"",smsFrom:""},()=>{}); }}/>ارسال پیامک ریز فاکتور به‌صورت پیش‌فرض فعال باشد</label>
      </Card>
      <Card>
        <p className="mb-2 text-sm text-muted">نسخه پشتیبان از داده‌های مرکزی MySQL ساخته می‌شود و شامل تاریخچه سفارش‌ها و دفترهای تراکنشی است؛ کلید پیامک داخل فایل قرار نمی‌گیرد.</p>
        <div className="flex flex-wrap gap-2">
          <Btn onClick={() => void download()} disabled={exporting}>{exporting ? "در حال تهیه..." : "دانلود پشتیبان MySQL"}</Btn>
          <Btn tone="ghost" onClick={() => void importLocalDataToMysql()} disabled={importing}>{importing ? "در حال انتقال..." : "انتقال داده این دستگاه به MySQL"}</Btn>
          <Btn tone="ghost" onClick={() => restoreInput.current?.click()} disabled={restoring}>{restoring ? "در حال بازیابی..." : "بازیابی پشتیبان MySQL"}</Btn>
          <input ref={restoreInput} type="file" accept="application/json,.json" className="hidden" onChange={(event) => { const file = event.target.files?.[0]; if (file) void restoreBackupFile(file); }} />
          <p className="self-center text-xs text-muted">بازیابی فقط روی دیتابیس POS خالی انجام می‌شود؛ اطلاعات کاربران و ورود حفظ می‌شوند.</p>
        </div>
        {note ? <p className="mt-2 text-sm">{note}</p> : null}
      </Card>
    </div>
  );
}
