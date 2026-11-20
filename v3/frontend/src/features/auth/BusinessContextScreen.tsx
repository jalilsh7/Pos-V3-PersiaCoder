import { useEffect, useState, type FormEvent } from "react";
import { Building2, LogOut, MapPin, Store } from "lucide-react";
import {
  ApiError,
  clearBusinessContext,
  createInitialBusiness,
  getAccessibleBusinesses,
  getBranches,
  getSavedBusinessContext,
  setBusinessContext,
  type BranchSummary,
  type BusinessSummary,
} from "../../shared/api/client";
import { Button, Notice, SelectField, TextField } from "../../shared/ui";
import "./business-context.css";

type Props = {
  onSelected: (business: BusinessSummary, branch: BranchSummary) => void;
  onLogout: () => void;
};

export function BusinessContextScreen({ onSelected, onLogout }: Props) {
  const [businesses, setBusinesses] = useState<BusinessSummary[]>([]);
  const [canInitialize, setCanInitialize] = useState(false);
  const [selectedBusinessId, setSelectedBusinessId] = useState("");
  const [branches, setBranches] = useState<BranchSummary[]>([]);
  const [selectedBranchId, setSelectedBranchId] = useState("");
  const [loading, setLoading] = useState(true);
  const [branchesLoading, setBranchesLoading] = useState(true);
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [attempt, setAttempt] = useState(0);
  const [businessName, setBusinessName] = useState("");
  const [businessCode, setBusinessCode] = useState("my-business");
  const [branchName, setBranchName] = useState("شعبه اصلی");
  const [branchCode, setBranchCode] = useState("main");
  const [timezone, setTimezone] = useState("Asia/Tehran");

  useEffect(() => {
    let active = true;
    getAccessibleBusinesses()
      .then((result) => {
        if (!active) return;
        setBusinesses(result.businesses);
        setCanInitialize(result.can_initialize);
        const saved = getSavedBusinessContext();
        const savedBusiness = result.businesses.find((item) => item.id === saved?.businessId);
        const initial = savedBusiness ?? (result.businesses.length === 1 ? result.businesses[0] : null);
        setSelectedBusinessId(initial?.id ?? "");
        if (!initial) clearBusinessContext();
        setLoading(false);
      })
      .catch((cause: unknown) => {
        if (!active) return;
        setError(cause instanceof ApiError ? cause.message : "فهرست کسب‌وکارها دریافت نشد.");
        setLoading(false);
      });
    return () => { active = false; };
  }, [attempt]);

  useEffect(() => {
    if (!selectedBusinessId) return;
    let active = true;
    getBranches(selectedBusinessId)
      .then((items) => {
        if (!active) return;
        setBranches(items);
        const saved = getSavedBusinessContext();
        const savedBranch = saved?.businessId === selectedBusinessId
          ? items.find((item) => item.id === saved.branchId)
          : undefined;
        setSelectedBranchId(savedBranch?.id ?? (items.length === 1 ? items[0].id : ""));
        setBranchesLoading(false);
      })
      .catch((cause: unknown) => {
        if (!active) return;
        setBranches([]);
        setSelectedBranchId("");
        setError(cause instanceof ApiError ? cause.message : "فهرست شعب دریافت نشد.");
        setBranchesLoading(false);
      });
    return () => { active = false; };
  }, [selectedBusinessId, attempt]);

  async function submitInitialSetup(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    setError(null);
    setBusy(true);
    try {
      const result = await createInitialBusiness({
        name: businessName.trim(),
        code: businessCode.trim().toLowerCase(),
        timezone: timezone.trim(),
        currency: "IRT",
        branch_name: branchName.trim(),
        branch_code: branchCode.trim().toLowerCase(),
      });
      const business: BusinessSummary = {
        id: result.business.id,
        name: result.business.name,
        code: businessCode.trim().toLowerCase(),
        timezone: timezone.trim(),
        currency: "IRT",
      };
      const branch: BranchSummary = {
        id: result.branch.id,
        name: result.branch.name,
        code: branchCode.trim().toLowerCase(),
        timezone: timezone.trim(),
        is_active: true,
      };
      setBusinessContext(business, branch);
      onSelected(business, branch);
    } catch (cause) {
      setError(cause instanceof ApiError ? cause.message : "راه‌اندازی کسب‌وکار انجام نشد.");
    } finally {
      setBusy(false);
    }
  }

  function enterWorkspace() {
    const business = businesses.find((item) => item.id === selectedBusinessId);
    const branch = branches.find((item) => item.id === selectedBranchId);
    if (!business || !branch) return;
    setBusinessContext(business, branch);
    onSelected(business, branch);
  }

  if (loading) {
    return <main className="context-screen" dir="rtl"><section className="context-card"><span className="context-mark"><Store size={22} /></span><strong>در حال دریافت دسترسی‌های شما...</strong></section></main>;
  }

  return (
    <main className="context-screen" dir="rtl">
      <section className="context-card" aria-labelledby="context-title">
        <header className="context-header">
          <span className="context-mark"><Store size={22} /></span>
          <div><strong>PersiaCoder POS</strong><small>Commercial Edition · V3</small></div>
          <Button size="sm" variant="ghost" onClick={onLogout}><LogOut size={14} /> خروج</Button>
        </header>

        {businesses.length === 0 && canInitialize ? (
          <>
            <div className="context-intro">
              <span className="context-intro__icon"><Building2 size={20} /></span>
              <span className="ui-eyebrow">FIRST-TIME SETUP</span>
              <h1 id="context-title">راه‌اندازی کسب‌وکار</h1>
              <p>اطلاعات اولیه کسب‌وکار و شعبه اصلی را وارد کنید. این مرحله فقط یک‌بار انجام می‌شود.</p>
            </div>
            {error ? <div className="context-error" role="alert">{error}</div> : null}
            <form className="context-form" onSubmit={submitInitialSetup}>
              <TextField label="نام کسب‌وکار" value={businessName} onChange={(event) => setBusinessName(event.target.value)} required maxLength={160} placeholder="مثلاً کافه نمونه" />
              <TextField label="کد کسب‌وکار (انگلیسی)" value={businessCode} onChange={(event) => setBusinessCode(event.target.value)} required minLength={2} maxLength={63} pattern="[a-zA-Z0-9][a-zA-Z0-9-]{1,62}" hint="فقط حروف انگلیسی، عدد و خط تیره" />
              <TextField label="نام شعبه اصلی" value={branchName} onChange={(event) => setBranchName(event.target.value)} required maxLength={160} />
              <TextField label="کد شعبه (انگلیسی)" value={branchCode} onChange={(event) => setBranchCode(event.target.value)} required minLength={2} maxLength={63} pattern="[a-zA-Z0-9][a-zA-Z0-9-]{1,62}" />
              <TextField label="منطقه زمانی" value={timezone} onChange={(event) => setTimezone(event.target.value)} required placeholder="Asia/Tehran" hint="مانند Asia/Tehran یا Europe/Berlin" />
              <Button type="submit" variant="primary" disabled={busy || !businessName.trim() || !businessCode.trim() || !branchName.trim() || !branchCode.trim() || !timezone.trim()}>
                <Building2 size={16} /> {busy ? "در حال راه‌اندازی..." : "ایجاد کسب‌وکار و شعبه"}
              </Button>
            </form>
          </>
        ) : businesses.length > 0 ? (
          <>
            <div className="context-intro">
              <span className="context-intro__icon"><MapPin size={20} /></span>
              <span className="ui-eyebrow">WORKSPACE CONTEXT</span>
              <h1 id="context-title">انتخاب کسب‌وکار و شعبه</h1>
              <p>دسترسی‌ها و اطلاعات عملیاتی بر اساس کسب‌وکار و شعبه انتخاب‌شده محدود می‌شوند.</p>
            </div>
            {error ? <div className="context-error" role="alert">{error}</div> : null}
            <div className="context-form">
              <SelectField
                label="کسب‌وکار"
                value={selectedBusinessId}
                onChange={(event) => {
                  setBranches([]);
                  setSelectedBranchId("");
                  setBranchesLoading(Boolean(event.target.value));
                  setError(null);
                  setSelectedBusinessId(event.target.value);
                }}
                options={businesses.map((item) => ({ value: item.id, label: item.name }))}
                placeholder="کسب‌وکار را انتخاب کنید"
                required
              />
              <SelectField
                label="شعبه"
                value={selectedBranchId}
                onChange={(event) => setSelectedBranchId(event.target.value)}
                options={branches.map((item) => ({ value: item.id, label: item.name }))}
                placeholder={branchesLoading ? "در حال دریافت شعب..." : "شعبه را انتخاب کنید"}
                disabled={!selectedBusinessId || branchesLoading || branches.length === 0}
                required
              />
              {selectedBusinessId && !branchesLoading && branches.length === 0 ? <Notice variant="warning">برای این کسب‌وکار شعبه فعالی در دسترس نیست.</Notice> : null}
              <Button variant="primary" onClick={enterWorkspace} disabled={!selectedBusinessId || !selectedBranchId || branchesLoading}>
                ورود به فضای کاری
              </Button>
            </div>
          </>
        ) : (
          <div className="context-intro">
            <span className="context-intro__icon"><Building2 size={20} /></span>
            <span className="ui-eyebrow">ACCESS REQUIRED</span>
            <h1 id="context-title">کسب‌وکاری در دسترس نیست</h1>
            <p>برای حساب شما دسترسی کسب‌وکار ثبت نشده است. از مدیر سیستم بخواهید دسترسی مناسب را اختصاص دهد.</p>
            {error ? <div className="context-error" role="alert">{error}</div> : null}
            <Button variant="secondary" onClick={() => { setLoading(true); setError(null); setBranchesLoading(true); setAttempt((value) => value + 1); }}>تلاش مجدد</Button>
          </div>
        )}
        <footer className="context-footer">Powered by PersiaCoder.ir</footer>
      </section>
    </main>
  );
}
