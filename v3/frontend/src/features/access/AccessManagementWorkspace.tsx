import { useEffect, useMemo, useRef, useState, type FormEvent } from "react";
import { Check, Copy, Plus, RefreshCw, ShieldCheck, UserPlus, Users } from "lucide-react";
import { ApiError, assignAccessRole, createAccessRole, createAccessUser, getAccessPermissions, getAccessRoles, getAccessUsers, updateAccessRole, type AccessPermissionSummary, type AccessRoleSummary, type AccessUserSummary, type CreatedAccessUser } from "../../shared/api/client";
import { Button, Notice, PageHeader, StatusBadge, Surface } from "../../shared/ui/primitives";
import { CheckboxField, SelectField, TextField } from "../../shared/ui/form-controls";
import "./access-management.css";

type Scope = "business" | "branch";
type Tab = "users" | "roles";
const newKey = () => globalThis.crypto.randomUUID();
const messageOf = (error: unknown, fallback: string) => error instanceof ApiError ? error.message : fallback;

export function AccessManagementWorkspace() {
  const [scope, setScope] = useState<Scope>("business");
  const [tab, setTab] = useState<Tab>("users");
  const [users, setUsers] = useState<AccessUserSummary[]>([]);
  const [roles, setRoles] = useState<AccessRoleSummary[]>([]);
  const [permissions, setPermissions] = useState<AccessPermissionSummary[]>([]);
  const [usersLoading, setUsersLoading] = useState(true);
  const [rolesLoading, setRolesLoading] = useState(true);
  const [usersError, setUsersError] = useState<string | null>(null);
  const [rolesError, setRolesError] = useState<string | null>(null);
  const [actionError, setActionError] = useState<string | null>(null);

  const [username, setUsername] = useState("");
  const [displayName, setDisplayName] = useState("");
  const [newUserRole, setNewUserRole] = useState("");
  const [creatingUser, setCreatingUser] = useState(false);
  const [credentials, setCredentials] = useState<CreatedAccessUser | null>(null);
  const [passwordCopied, setPasswordCopied] = useState(false);
  const userAttempt = useRef<{ signature: string; key: string } | null>(null);

  const [roleCode, setRoleCode] = useState("");
  const [roleName, setRoleName] = useState("");
  const [selectedPermissions, setSelectedPermissions] = useState<string[]>([]);
  const [selectedRoleCode, setSelectedRoleCode] = useState("");
  const [savingRole, setSavingRole] = useState(false);
  const roleAttempt = useRef<{ signature: string; key: string } | null>(null);
  const [roleChoices, setRoleChoices] = useState<Record<string, string>>({});
  const [assigningUserId, setAssigningUserId] = useState<string | null>(null);

  const selectedRole = roles.find((role) => role.code === selectedRoleCode) ?? null;
  const assignableRoles = useMemo(() => roles.filter((role) => role.code !== "owner").map((role) => ({ value: role.code, label: role.name })), [roles]);

  async function refreshUsers() {
    setUsersLoading(true);
    setUsersError(null);
    try {
      const rows = await getAccessUsers(scope);
      setUsers(rows);
      setRoleChoices((current) => {
        const next = { ...current };
        for (const user of rows) if (!next[user.id] && assignableRoles[0]) next[user.id] = assignableRoles[0].value;
        return next;
      });
    } catch (error) {
      setUsers([]);
      setUsersError(messageOf(error, "دریافت فهرست کاربران انجام نشد."));
    } finally {
      setUsersLoading(false);
    }
  }

  async function refreshRoles() {
    setRolesLoading(true);
    setRolesError(null);
    try {
      const [roleRows, permissionRows] = await Promise.all([getAccessRoles(), getAccessPermissions()]);
      setRoles(roleRows);
      setPermissions(permissionRows);
    } catch (error) {
      setRolesError(messageOf(error, "دریافت نقش‌ها و مجوزها انجام نشد."));
    } finally {
      setRolesLoading(false);
    }
  }

  useEffect(() => { void refreshUsers(); }, [scope]);
  useEffect(() => { void refreshRoles(); }, []);

  useEffect(() => {
    if (assignableRoles.length === 0) return;
    setRoleChoices((current) => {
      const next = { ...current };
      for (const user of users) if (!next[user.id]) next[user.id] = assignableRoles[0].value;
      return next;
    });
  }, [users, assignableRoles]);

  useEffect(() => {
    if (!selectedRole) return;
    setRoleCode(selectedRole.is_system ? "" : selectedRole.code);
    setRoleName(selectedRole.name);
    setSelectedPermissions(selectedRole.permissions);
  }, [selectedRoleCode, roles]);

  async function submitUser(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    setActionError(null);
    setCredentials(null);
    setPasswordCopied(false);
    const normalizedUsername = username.trim().toLowerCase();
    const signature = JSON.stringify([scope, normalizedUsername, displayName.trim(), newUserRole]);
    if (!userAttempt.current || userAttempt.current.signature !== signature) userAttempt.current = { signature, key: newKey() };
    setCreatingUser(true);
    try {
      const result = await createAccessUser(scope, {
        username: normalizedUsername,
        display_name: displayName.trim(),
        role_code: newUserRole,
      }, userAttempt.current.key);
      setCredentials(result);
      setUsername("");
      setDisplayName("");
      setNewUserRole("");
      userAttempt.current = null;
      await refreshUsers();
    } catch (error) {
      setActionError(messageOf(error, "ساخت کاربر انجام نشد. اگر پاسخ شبکه نامشخص بود، همان فرم را دوباره ارسال کنید."));
    } finally {
      setCreatingUser(false);
    }
  }

  async function copyPassword() {
    if (!credentials) return;
    try {
      await navigator.clipboard.writeText(credentials.temporary_password);
      setPasswordCopied(true);
    } catch {
      setActionError("کپی خودکار در دسترس نیست؛ رمز موقت را از متن نمایش‌داده‌شده کپی کنید.");
    }
  }

  async function assignRole(user: AccessUserSummary) {
    const code = roleChoices[user.id];
    if (!code) return;
    setAssigningUserId(user.id);
    setActionError(null);
    try {
      await assignAccessRole(user.id, code, scope, newKey());
      await refreshUsers();
    } catch (error) {
      setActionError(messageOf(error, "افزودن نقش انجام نشد."));
    } finally {
      setAssigningUserId(null);
    }
  }

  function startNewRole() {
    setSelectedRoleCode("");
    setRoleCode("");
    setRoleName("");
    setSelectedPermissions([]);
    roleAttempt.current = null;
    setActionError(null);
  }

  async function submitRole(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    setActionError(null);
    const normalizedCode = roleCode.trim().toLowerCase();
    const signature = JSON.stringify([selectedRoleCode, normalizedCode, roleName.trim(), [...selectedPermissions].sort()]);
    if (!roleAttempt.current || roleAttempt.current.signature !== signature) roleAttempt.current = { signature, key: newKey() };
    setSavingRole(true);
    try {
      if (selectedRoleCode) {
        await updateAccessRole(selectedRoleCode, { name: roleName.trim(), permissions: selectedPermissions }, roleAttempt.current.key);
      } else {
        await createAccessRole({ code: normalizedCode, name: roleName.trim(), permissions: selectedPermissions }, roleAttempt.current.key);
      }
      roleAttempt.current = null;
      await refreshRoles();
      if (!selectedRoleCode) setSelectedRoleCode(normalizedCode);
    } catch (error) {
      setActionError(messageOf(error, "ذخیره نقش انجام نشد."));
    } finally {
      setSavingRole(false);
    }
  }

  function togglePermission(code: string, checked: boolean) {
    setSelectedPermissions((current) => checked ? [...current, code] : current.filter((item) => item !== code));
  }

  return (
    <div className="page-stack access-management">
      <PageHeader
        eyebrow="SECURITY & ACCESS"
        title="کاربران و دسترسی‌ها"
        description="مدیریت کاربران، نقش‌ها و مجوزها در محدوده کسب‌وکار یا شعبه"
        action={<div className="access-scope-switch">
          <Button size="sm" variant={scope === "business" ? "primary" : "secondary"} onClick={() => setScope("business")}>کل کسب‌وکار</Button>
          <Button size="sm" variant={scope === "branch" ? "primary" : "secondary"} onClick={() => setScope("branch")}>شعبه جاری</Button>
        </div>}
      />

      <div className="access-tabs" role="tablist">
        <button type="button" role="tab" aria-selected={tab === "users"} className={tab === "users" ? "is-active" : ""} onClick={() => setTab("users")}><Users size={16} /> کاربران</button>
        <button type="button" role="tab" aria-selected={tab === "roles"} className={tab === "roles" ? "is-active" : ""} onClick={() => setTab("roles")}><ShieldCheck size={16} /> نقش‌ها و مجوزها</button>
      </div>
      {actionError ? <Notice variant="warning">{actionError}</Notice> : null}

      {tab === "users" ? (
        <div className="access-users-layout">
          <Surface className="access-user-create">
            <div className="access-section-heading"><div><h2><UserPlus size={17} /> ساخت کاربر جدید</h2><p>رمز موقت فقط یک بار نمایش داده می‌شود؛ آن را امن تحویل دهید.</p></div></div>
            <form className="access-form-grid" onSubmit={(event) => { void submitUser(event); }}>
              <TextField label="نام کاربری" value={username} onChange={(event) => setUsername(event.target.value)} required minLength={3} maxLength={64} autoComplete="off" />
              <TextField label="نام نمایشی" value={displayName} onChange={(event) => setDisplayName(event.target.value)} required maxLength={120} />
              <SelectField label="نقش اولیه" value={newUserRole} onChange={(event) => setNewUserRole(event.target.value)} options={assignableRoles} required placeholder="انتخاب نقش" />
              <div className="access-form-actions"><Button type="submit" disabled={creatingUser || !newUserRole}>{creatingUser ? "در حال ساخت..." : "ساخت کاربر"}</Button></div>
            </form>
            {credentials ? <div className="access-credential-card">
              <div><StatusBadge variant="success">کاربر ساخته شد</StatusBadge><strong>{credentials.user.username}</strong><p>{credentials.credential_notice}</p></div>
              <div className="access-password-row"><code dir="ltr">{credentials.temporary_password}</code><Button size="sm" variant="secondary" onClick={() => { void copyPassword(); }}>{passwordCopied ? <Check size={15} /> : <Copy size={15} />}{passwordCopied ? "کپی شد" : "کپی رمز"}</Button></div>
            </div> : null}
          </Surface>

          <Surface className="access-user-list">
            <div className="access-section-heading"><div><h2>کاربران {scope === "business" ? "کسب‌وکار" : "شعبه جاری"}</h2><p>{users.length} کاربر</p></div><Button size="sm" variant="ghost" onClick={() => { void refreshUsers(); }} disabled={usersLoading}><RefreshCw size={15} /> بروزرسانی</Button></div>
            {usersError ? <Notice variant="warning">{usersError}</Notice> : null}
            {usersLoading ? <div className="access-empty">در حال دریافت کاربران...</div> : users.length === 0 ? <div className="access-empty">کاربری در این محدوده ثبت نشده است.</div> : (
              <div className="access-user-rows">
                {users.map((user) => {
                  const activeRoles = scope === "business" ? user.business_roles : user.branch_roles;
                  const inheritedRoles = scope === "business" ? user.branch_roles : user.business_roles;
                  return <article className="access-user-row" key={user.id}>
                    <div className="access-user-main">
                      <div><strong>{user.display_name}</strong><span dir="ltr">@{user.username}</span></div>
                      <div className="access-user-badges">
                        <StatusBadge variant={user.is_active ? "success" : "danger"}>{user.is_active ? "فعال" : "غیرفعال"}</StatusBadge>
                        {user.must_change_password ? <StatusBadge variant="warning">تغییر رمز الزامی</StatusBadge> : null}
                        <span className="access-last-login">{user.last_login_at ? `آخرین ورود: ${new Date(user.last_login_at).toLocaleString("fa-IR")}` : "هنوز وارد نشده"}</span>
                      </div>
                    </div>
                    <div className="access-role-chips">
                      {activeRoles.map((code) => <span className="access-role-chip" key={code}>{roles.find((role) => role.code === code)?.name ?? code}</span>)}
                      {inheritedRoles.map((code) => <span className="access-role-chip access-role-chip--muted" key={code}>{roles.find((role) => role.code === code)?.name ?? code} · {scope === "business" ? "شعبه" : "کسب‌وکار"}</span>)}
                    </div>
                    <div className="access-assign-row">
                      <SelectField label="افزودن نقش" value={roleChoices[user.id] ?? ""} onChange={(event) => setRoleChoices((current) => ({ ...current, [user.id]: event.target.value }))} options={assignableRoles} placeholder="انتخاب نقش" />
                      <Button size="sm" variant="secondary" disabled={!roleChoices[user.id] || assigningUserId === user.id} onClick={() => { void assignRole(user); }}>{assigningUserId === user.id ? "در حال ثبت..." : "افزودن نقش"}</Button>
                    </div>
                  </article>;
                })}
              </div>
            )}
          </Surface>
        </div>
      ) : (
        <div className="access-roles-layout">
          <Surface className="access-role-list">
            <div className="access-section-heading"><div><h2>نقش‌های موجود</h2><p>{roles.length} نقش فعال</p></div><Button size="sm" variant="ghost" onClick={() => { void refreshRoles(); }} disabled={rolesLoading}><RefreshCw size={15} /> بروزرسانی</Button></div>
            {rolesError ? <Notice variant="warning">{rolesError}</Notice> : null}
            {roles.map((role) => <button type="button" key={role.code} className={`access-role-option ${selectedRoleCode === role.code ? "is-selected" : ""}`} onClick={() => setSelectedRoleCode(role.code)}>
              <span><strong>{role.name}</strong><small dir="ltr">{role.code}</small></span>
              <span><StatusBadge variant={role.is_system ? "info" : "neutral"}>{role.is_system ? "سیستمی" : "سفارشی"}</StatusBadge><small>{role.permissions.length} مجوز</small></span>
            </button>)}
            <Button variant="secondary" className="access-new-role" onClick={startNewRole}><Plus size={15} /> نقش سفارشی جدید</Button>
          </Surface>
          <Surface className="access-role-editor">
            <div className="access-section-heading"><div><h2>{selectedRoleCode ? "ویرایش نقش سفارشی" : "ساخت نقش سفارشی"}</h2><p>فقط مجوزهایی قابل واگذاری هستند که خودتان در محدوده جاری دارید.</p></div></div>
            <form onSubmit={(event) => { void submitRole(event); }}>
              {!selectedRoleCode ? <TextField label="کد نقش" value={roleCode} onChange={(event) => setRoleCode(event.target.value)} required maxLength={64} placeholder="مثلاً branch_cashier" /> : <div className="access-selected-code"><span>کد نقش</span><strong dir="ltr">{selectedRoleCode}</strong></div>}
              <TextField label="نام نقش" value={roleName} onChange={(event) => setRoleName(event.target.value)} required maxLength={100} disabled={Boolean(selectedRole?.is_system)} />
              {selectedRole?.is_system ? <Notice variant="warning">نقش‌های سیستمی قابل ویرایش نیستند.</Notice> : null}
              <div className="access-permission-heading"><strong>مجوزها</strong><span>{selectedPermissions.length} انتخاب‌شده</span></div>
              {rolesError ? null : permissions.length === 0 ? <div className="access-empty">فهرست مجوزها در دسترس نیست.</div> : <div className="access-permission-list">
                {permissions.map((permission) => <CheckboxField key={permission.code} label={permission.code} hint={permission.description ?? undefined} checked={selectedPermissions.includes(permission.code)} disabled={Boolean(selectedRole?.is_system)} onChange={(event) => togglePermission(permission.code, event.target.checked)} />)}
              </div>}
              <div className="access-form-actions"><Button type="submit" disabled={savingRole || Boolean(selectedRole?.is_system) || selectedPermissions.length === 0 || !roleName.trim() || (!selectedRoleCode && !roleCode.trim())}>{savingRole ? "در حال ذخیره..." : selectedRoleCode ? "ذخیره تغییرات نقش" : "ساخت نقش"}</Button></div>
            </form>
          </Surface>
        </div>
      )}
    </div>
  );
}
