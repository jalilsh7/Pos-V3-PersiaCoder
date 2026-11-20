import { useState, type FormEvent } from "react";
import { KeyRound, LockKeyhole, LogIn, ShieldCheck, Store } from "lucide-react";
import { ApiError, changePassword, signIn, type AuthenticatedUser } from "../../shared/api/client";
import { Button, Notice, TextField } from "../../shared/ui";
import "./auth.css";

type AuthScreenProps = {
  mustChangePassword?: boolean;
  bootstrapError?: string | null;
  onAuthenticated: (user: AuthenticatedUser) => void;
  onPasswordChanged: () => void;
  onRetry?: () => void;
};

export function AuthScreen({
  mustChangePassword = false,
  bootstrapError = null,
  onAuthenticated,
  onPasswordChanged,
  onRetry,
}: AuthScreenProps) {
  const [username, setUsername] = useState("");
  const [password, setPassword] = useState("");
  const [currentPassword, setCurrentPassword] = useState("");
  const [newPassword, setNewPassword] = useState("");
  const [confirmPassword, setConfirmPassword] = useState("");
  const [error, setError] = useState<string | null>(null);
  const [busy, setBusy] = useState(false);

  async function submitLogin(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    setError(null);
    setBusy(true);
    try {
      const user = await signIn(username.trim(), password);
      onAuthenticated(user);
    } catch (cause) {
      setError(cause instanceof ApiError ? cause.message : "ورود انجام نشد. دوباره تلاش کنید.");
    } finally {
      setBusy(false);
    }
  }

  async function submitPasswordChange(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    setError(null);
    if (newPassword.length < 12 || newPassword.length > 128) {
      setError("رمز عبور جدید باید بین ۱۲ تا ۱۲۸ نویسه باشد.");
      return;
    }
    if (newPassword !== confirmPassword) {
      setError("تکرار رمز عبور با رمز جدید مطابقت ندارد.");
      return;
    }
    setBusy(true);
    try {
      await changePassword(currentPassword, newPassword);
      onPasswordChanged();
    } catch (cause) {
      setError(cause instanceof ApiError ? cause.message : "تغییر رمز عبور انجام نشد.");
    } finally {
      setBusy(false);
    }
  }

  return (
    <main className="auth-screen" dir="rtl">
      <section className="auth-card" aria-labelledby="auth-title">
        <div className="auth-brand">
          <span className="auth-brand__mark"><Store size={23} /></span>
          <span><strong>PersiaCoder POS</strong><small>Commercial Edition · V3</small></span>
        </div>
        <div className="auth-intro">
          <span className="auth-intro__icon">{mustChangePassword ? <KeyRound size={20} /> : <ShieldCheck size={20} />}</span>
          <span className="ui-eyebrow">{mustChangePassword ? "ACCOUNT SECURITY" : "SECURE WORKSPACE"}</span>
          <h1 id="auth-title">{mustChangePassword ? "تغییر رمز عبور اولیه" : "ورود به فضای کاری"}</h1>
          <p>{mustChangePassword ? "برای ادامه کار، رمز اولیه را با یک رمز اختصاصی و امن جایگزین کنید." : "برای ورود، نام کاربری و رمز عبور حساب خود را وارد کنید."}</p>
        </div>

        {bootstrapError ? (
          <Notice variant="warning">
            {bootstrapError}
            {onRetry ? <button className="auth-inline-action" type="button" onClick={onRetry}>تلاش مجدد</button> : null}
          </Notice>
        ) : null}
        {error ? <div className="auth-error" role="alert">{error}</div> : null}

        {mustChangePassword ? (
          <form className="auth-form" onSubmit={submitPasswordChange}>
            <TextField label="رمز عبور فعلی" type="password" autoComplete="current-password" value={currentPassword} onChange={(event) => setCurrentPassword(event.target.value)} required />
            <TextField label="رمز عبور جدید" type="password" autoComplete="new-password" minLength={12} maxLength={128} value={newPassword} onChange={(event) => setNewPassword(event.target.value)} required hint="حداقل ۱۲ و حداکثر ۱۲۸ نویسه" />
            <TextField label="تکرار رمز عبور جدید" type="password" autoComplete="new-password" minLength={12} maxLength={128} value={confirmPassword} onChange={(event) => setConfirmPassword(event.target.value)} required />
            <Button type="submit" variant="primary" disabled={busy || !currentPassword || !newPassword || !confirmPassword}>
              <LockKeyhole size={16} /> {busy ? "در حال تغییر..." : "ثبت رمز جدید"}
            </Button>
          </form>
        ) : (
          <form className="auth-form" onSubmit={submitLogin}>
            <TextField label="نام کاربری" autoComplete="username" autoCapitalize="none" value={username} onChange={(event) => setUsername(event.target.value)} required autoFocus />
            <TextField label="رمز عبور" type="password" autoComplete="current-password" value={password} onChange={(event) => setPassword(event.target.value)} required />
            <Button type="submit" variant="primary" disabled={busy || !username.trim() || !password}>
              <LogIn size={16} /> {busy ? "در حال ورود..." : "ورود امن"}
            </Button>
          </form>
        )}
        <div className="auth-security-note"><LockKeyhole size={14} /><span>نشست امن · محافظت CSRF · دسترسی بر اساس نقش</span></div>
        <footer className="auth-footer">Powered by PersiaCoder.ir</footer>
      </section>
    </main>
  );
}
