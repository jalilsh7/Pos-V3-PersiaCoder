import { useEffect, useState } from "react";
import {
  Activity,
  Boxes,
  ChefHat,
  ChevronLeft,
  ClipboardList,
  LayoutDashboard,
  MessageSquare,
  Globe,
  LogOut,
  Search,
  Settings,
  ShieldCheck,
  ShoppingCart,
  Store,
  Users,
  Utensils,
  Wallet,
} from "lucide-react";
import type { LucideIcon } from "lucide-react";
import { Button, EmptyState, PageHeader, StatusBadge, Surface } from "../shared/ui/primitives";
import { ApiError, clearBusinessContext, getCurrentSession, signOut, type AuthenticatedUser, type BranchSummary, type BusinessSummary } from "../shared/api/client";
import { AuthScreen } from "../features/auth/AuthScreen";
import { BusinessContextScreen } from "../features/auth/BusinessContextScreen";
import { PosWorkspace } from "../features/pos/PosWorkspace";
import { OrderCenter } from "../features/orders/OrderCenter";
import { KitchenBoard } from "../features/kitchen/KitchenBoard";
import { CatalogWorkspace } from "../features/catalog/CatalogWorkspace";
import { InventoryWorkspace } from "../features/inventory/InventoryWorkspace";
import { RecipeWorkspace } from "../features/recipes/RecipeWorkspace";
import { CustomersWorkspace } from "../features/customers/CustomersWorkspace";
import { FinanceWorkspace } from "../features/finance/FinanceWorkspace";
import { BusinessSettingsWorkspace } from "../features/settings/BusinessSettingsWorkspace";
import { AccessManagementWorkspace } from "../features/access/AccessManagementWorkspace";
import { StorefrontWorkspace } from "../features/storefront/StorefrontWorkspace";
import { SmsOutboxWorkspace } from "../features/communications/SmsOutboxWorkspace";
import { SmsServiceTemplatesWorkspace } from "../features/communications/SmsServiceTemplatesWorkspace";

type ViewId = "overview" | "pos" | "orders" | "kitchen" | "catalog" | "inventory" | "recipes" | "customers" | "finance" | "settings" | "storefront" | "access" | "communications" | "smsTemplates";

type NavigationItem = {
  id: ViewId;
  label: string;
  icon: LucideIcon;
  description: string;
};

type NavigationGroup = {
  title: string;
  items: NavigationItem[];
};

const navigation: NavigationGroup[] = [
  {
    title: "عملیات روزانه",
    items: [
      { id: "overview", label: "نمای کلی", icon: LayoutDashboard, description: "نمای کلی کسب‌وکار" },
      { id: "pos", label: "صندوق فروش", icon: ShoppingCart, description: "ثبت فروش حضوری" },
      { id: "orders", label: "مرکز سفارش‌ها", icon: ClipboardList, description: "مدیریت همه کانال‌های سفارش" },
      { id: "storefront", label: "منوی آنلاین", icon: Globe, description: "پیش‌نمایش منو و سفارش اینترنتی" },
      { id: "kitchen", label: "آشپزخانه", icon: ChefHat, description: "صف آماده‌سازی و ایستگاه‌ها" },
    ],
  },
  {
    title: "کاتالوگ و منابع",
    items: [
      { id: "catalog", label: "منو و محصولات", icon: Utensils, description: "محصولات، دسته‌ها و افزودنی‌ها" },
      { id: "inventory", label: "مواد اولیه و انبار", icon: Boxes, description: "موجودی، اسناد و گردش انبار" },
      { id: "recipes", label: "رسپی و ترکیبات", icon: ChefHat, description: "نسخه‌بندی مواد مصرفی محصولات" },
      { id: "customers", label: "مشتریان", icon: Users, description: "پروفایل مشتری و وفاداری" },
      { id: "communications", label: "گزارش پیامک", icon: MessageSquare, description: "صف و گزارش تحویل پیامک" },
      { id: "smsTemplates", label: "قالب خدماتی پیامک", icon: MessageSquare, description: "کد قالب و راهنمای متغیرهای ملی‌پیامک" },
    ],
  },
  {
    title: "مدیریت",
    items: [
      { id: "finance", label: "مالی و صندوق‌ها", icon: Wallet, description: "پرداخت، شیفت و تسویه" },
      { id: "settings", label: "تنظیمات", icon: Settings, description: "تنظیمات کسب‌وکار و قابلیت‌ها" },
      { id: "access", label: "کاربران و دسترسی‌ها", icon: ShieldCheck, description: "کاربران، نقش‌ها و مجوزها" },
    ],
  },
];

const allItems = navigation.flatMap((group) => group.items);

function Overview() {
  return (
    <div className="page-stack">
      <PageHeader
        eyebrow="COMMERCIAL EDITION"
        title="نمای کلی کسب‌وکار"
        description="مرکز کنترل فروش، سفارش و عملیات روزانه"
        action={<StatusBadge variant="warning">پیش‌نمایش رابط</StatusBadge>}
      />
      <div className="metric-grid">
        {[
          { title: "فروش امروز", value: "—", note: "پس از اتصال سرویس مالی" },
          { title: "سفارش‌های باز", value: "—", note: "پس از اتصال مرکز سفارش" },
          { title: "وضعیت صندوق", value: "—", note: "پس از اتصال شیفت صندوق" },
          { title: "هشدار موجودی", value: "—", note: "پس از اتصال انبار" },
        ].map((metric) => (
          <article className="metric-card" key={metric.title}>
            <span>{metric.title}</span>
            <strong>{metric.value}</strong>
            <small>{metric.note}</small>
          </article>
        ))}
      </div>
      <div className="overview-grid">
        <Surface padding="none" className="surface-card">
          <div className="section-heading">
            <div>
              <h2>جریان عملیات</h2>
              <p>مسیر یکپارچه از ثبت سفارش تا تحویل</p>
            </div>
            <Activity size={19} />
          </div>
          <div className="flow-list">
            {["ثبت سفارش", "تأیید و دریافت وجه", "آماده‌سازی", "تحویل یا ارسال"].map((step, index) => (
              <div className="flow-step" key={step}>
                <span>{String(index + 1).padStart(2, "0")}</span>
                <strong>{step}</strong>
                {index < 3 ? <ChevronLeft size={16} /> : null}
              </div>
            ))}
          </div>
        </Surface>
        <Surface padding="none" className="surface-card">
          <div className="section-heading">
            <div>
              <h2>ماژول‌های نسخه اول</h2>
              <p>ویژه کسب‌وکارهای غذا و نوشیدنی</p>
            </div>
            <Store size={19} />
          </div>
          <div className="module-tags">
            {["رستوران", "کافه", "فست‌فود", "آبمیوه‌فروشی", "بستنی‌فروشی"].map((name) => <span key={name}>{name}</span>)}
          </div>
          <p className="muted-note">پروفایل صنفی در راه‌اندازی نصب انتخاب می‌شود و واژگان و قابلیت‌های مرتبط را تنظیم می‌کند.</p>
        </Surface>
      </div>
    </div>
  );
}

function AuthenticatedWorkspace({ user, business, branch, onLogout, actionError }: { user: AuthenticatedUser; business: BusinessSummary; branch: BranchSummary; onLogout: () => void; actionError: string | null }) {
  const [activeView, setActiveView] = useState<ViewId>("overview");
  const [search, setSearch] = useState("");
  const activeItem = allItems.find((item) => item.id === activeView) ?? allItems[0];
  const ActiveIcon = activeItem.icon;
  const normalizedSearch = search.trim().toLocaleLowerCase("fa-IR");
  const filteredNavigation = navigation
    .map((group) => ({
      ...group,
      items: group.items.filter((item) =>
        `${item.label} ${item.description}`.toLocaleLowerCase("fa-IR").includes(normalizedSearch),
      ),
    }))
    .filter((group) => group.items.length > 0);

  return (
    <div className="app-shell">
      <aside className="sidebar">
        <div className="brand-lockup">
          <div className="brand-mark"><Store size={22} /></div>
          <div>
            <strong>PersiaCoder</strong>
            <span>POS V3 · Commercial</span>
          </div>
        </div>
        <div className="workspace-label">فضای کاری</div>
        <nav className="main-navigation" aria-label="ناوبری اصلی">
          {filteredNavigation.map((group) => (
            <section className="nav-group" key={group.title}>
              <h2>{group.title}</h2>
              {group.items.map((item) => {
                const Icon = item.icon;
                const active = item.id === activeView;
                return (
                  <button
                    className={`nav-item ${active ? "active" : ""}`}
                    key={item.id}
                    type="button"
                    aria-current={active ? "page" : undefined}
                    onClick={() => setActiveView(item.id)}
                  >
                    <Icon size={18} strokeWidth={1.9} />
                    <span>{item.label}</span>
                    {active ? <span className="nav-indicator" /> : null}
                  </button>
                );
              })}
            </section>
          ))}
        </nav>
        <div className="sidebar-foot">
          <span className="connection-mark" />
          <div>
            <strong>نشست امن فعال</strong>
            <small>داده عملیاتی ماژول‌ها در حال اتصال است</small>
          </div>
        </div>
      </aside>

      <main className="main-area">
        <header className="topbar">
          <div className="breadcrumb">
            <span>PersiaCoder POS</span>
            <ChevronLeft size={15} />
            <strong>{activeItem.label}</strong>
          </div>
          <div className="topbar-actions">
            <label className="search-box">
              <Search size={17} />
              <input value={search} onChange={(event) => setSearch(event.target.value)} placeholder="جست‌وجوی بخش‌ها" aria-label="جست‌وجوی بخش‌ها" />
              {search ? <Button variant="ghost" size="icon" onClick={() => setSearch("")} aria-label="پاک کردن جست‌وجو">×</Button> : null}
            </label>
            <span className="topbar-divider" />
            <StatusBadge variant="success" className="compact">نشست معتبر</StatusBadge>
            <div className="account-menu"><span className="context-name">{business.name} · {branch.name}</span><span className="account-name">{user.display_name}</span><span className="avatar" aria-label={user.display_name}>{user.display_name.trim().slice(0, 1) || "؟"}</span><Button size="sm" variant="ghost" onClick={onLogout}><LogOut size={14} /> خروج</Button></div>
          </div>
        </header>

        <div className="content-area">
          {actionError ? <div className="auth-error" role="alert">{actionError}</div> : null}
          {activeView === "overview" ? <Overview /> : activeView === "pos" ? <PosWorkspace /> : activeView === "orders" ? <OrderCenter timeZone={branch.timezone ?? business.timezone} /> : activeView === "kitchen" ? <KitchenBoard timeZone={branch.timezone ?? business.timezone} /> : activeView === "catalog" ? <CatalogWorkspace /> : activeView === "inventory" ? <InventoryWorkspace /> : activeView === "recipes" ? <RecipeWorkspace /> : activeView === "customers" ? <CustomersWorkspace /> : activeView === "finance" ? <FinanceWorkspace /> : activeView === "settings" ? <BusinessSettingsWorkspace /> : activeView === "storefront" ? <StorefrontWorkspace /> : activeView === "access" ? <AccessManagementWorkspace /> : activeView === "communications" ? <SmsOutboxWorkspace /> : activeView === "smsTemplates" ? <SmsServiceTemplatesWorkspace /> : (
            <div className="page-stack">
              <PageHeader
                eyebrow={activeItem.description}
                title={activeItem.label}
                description="فضای مستقل نسخه تجاری PersiaCoder POS V3"
                action={<StatusBadge variant="warning">در حال طراحی</StatusBadge>}
              />
              <EmptyState
                icon={ActiveIcon}
                title={activeItem.label}
                description={`ساختار صفحه «${activeItem.label}» در مرحله بعد بر اساس قرارداد دامنه و API مستقل V3 پیاده‌سازی می‌شود.`}
                notice="این بخش هنوز به احراز هویت، API و دیتابیس مستقل V3 متصل نشده است."
              />
            </div>
          )}
          <footer className="app-footer">Powered by PersiaCoder.ir</footer>
        </div>
      </main>
    </div>
  );
}


export function App() {
  const [user, setUser] = useState<AuthenticatedUser | null>(null);
  const [workspaceContext, setWorkspaceContext] = useState<{ business: BusinessSummary; branch: BranchSummary } | null>(null);
  const [loading, setLoading] = useState(true);
  const [bootstrapError, setBootstrapError] = useState<string | null>(null);
  const [attempt, setAttempt] = useState(0);

  useEffect(() => {
    let active = true;
    getCurrentSession()
      .then((currentUser) => {
        if (!active) return;
        setUser(currentUser);
        setWorkspaceContext(null);
        setLoading(false);
      })
      .catch((cause: unknown) => {
        if (!active) return;
        if (cause instanceof ApiError && cause.status === 401) {
          setUser(null);
          setBootstrapError(null);
        } else {
          setBootstrapError(cause instanceof Error ? cause.message : "اتصال به سرویس احراز هویت برقرار نشد.");
        }
        setLoading(false);
      });
    return () => { active = false; };
  }, [attempt]);

  async function handleLogout() {
    setBootstrapError(null);
    try {
      await signOut();
      clearBusinessContext();
      setWorkspaceContext(null);
      setUser(null);
    } catch (cause) {
      setBootstrapError(cause instanceof Error ? cause.message : "خروج از حساب انجام نشد. دوباره تلاش کنید.");
    }
  }

  if (loading) {
    return <main className="auth-screen" dir="rtl"><section className="auth-card auth-loading" aria-live="polite"><span className="auth-brand__mark"><Store size={23} /></span><strong>در حال بررسی نشست امن...</strong><span>PersiaCoder POS V3</span></section></main>;
  }

  if (!user || user.must_change_password) {
    return (
      <AuthScreen
        mustChangePassword={Boolean(user?.must_change_password)}
        bootstrapError={bootstrapError}
        onAuthenticated={(authenticatedUser) => { setUser(authenticatedUser); setWorkspaceContext(null); setBootstrapError(null); }}
        onPasswordChanged={() => { setUser(null); setWorkspaceContext(null); setBootstrapError("رمز عبور تغییر کرد. برای ادامه با رمز جدید دوباره وارد شوید."); }}
        onRetry={() => { setLoading(true); setBootstrapError(null); setAttempt((value) => value + 1); }}
      />
    );
  }

  if (!workspaceContext) {
    return (
      <BusinessContextScreen
        onSelected={(business, branch) => { setWorkspaceContext({ business, branch }); setBootstrapError(null); }}
        onLogout={() => { void handleLogout(); }}
      />
    );
  }

  return <AuthenticatedWorkspace
    user={user}
    business={workspaceContext.business}
    branch={workspaceContext.branch}
    onLogout={() => { void handleLogout(); }}
    actionError={bootstrapError}
  />;
}
