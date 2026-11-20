/**
 * Same-origin client for the PHP/MySQL backend.
 *
 * Session authentication is cookie-based (HttpOnly); CSRF is held in memory
 * only and refreshed from login / auth-me responses. Never persist passwords,
 * session identifiers, CSRF tokens, or API secrets in localStorage.
 *
 * The POS UI uses this client for PHP session auth, MySQL bootstrap, records,
 * orders, SMS, and backup operations. CSRF remains in memory only.
 */

export type PosRole = "manager" | "cashier" | "kitchen";

export type ApiUser = {
  id: number;
  username: string;
  display_name: string;
  role: PosRole;
  must_change_password?: boolean;
};

export type AppearanceSettings={primary:string;secondary:string;mode:"light"|"dark";fontFamily:"byekan";fontSize:number;cardRadius:number;buttonRadius:number;logoUrl:string|null};

export type TerminalAgent = {id:string;name:string;enabled:boolean|number;agent_version?:string|null;last_seen_at?:string|null;created_at?:string};
export type PaymentTerminal = {id:string;agent_id?:string;agentId?:string;local_terminal_key?:string;name:string;provider_code?:string;providerCode?:string;model_name?:string|null;modelName?:string|null;driver_code?:string;driverCode?:string;connection_type?:"lan"|"wifi";connectionType?:"lan"|"wifi";host?:string;port?:number;enabled:boolean;is_default?:boolean;isDefault?:boolean;last_seen_at?:string|null;agent_name?:string;agent_enabled?:boolean};
export type TerminalPaymentStatus = "queued"|"processing"|"approved"|"declined"|"cancelled"|"unknown"|"expired";
export type TerminalPayment = {id:string;terminal_id?:string;terminal_name?:string;order_id?:number|null;amount:number;currency:string;status:TerminalPaymentStatus;external_transaction_id?:string|null;trace_number?:string|null;retrieval_reference_number?:string|null;response_code?:string|null;response_message?:string|null;requested_at?:string;completed_at?:string|null};

export type ManagedUser = ApiUser & {
  is_active: number | boolean;
  last_login_at?: string | null;
  created_at?: string;
};

export type ApiEnvelope<T> = {
  ok: true;
} & T;

export class PhpApiError extends Error {
  readonly status: number;
  readonly code: string;

  constructor(status: number, code: string, message: string) {
    super(message);
    this.name = "PhpApiError";
    this.status = status;
    this.code = code;
  }
}

const API_ROOT = "/api/v1";
let csrfToken: string | null = null;

type RequestOptions = {
  method?: "GET" | "POST" | "PATCH" | "DELETE";
  body?: unknown;
  csrf?: boolean;
  setupToken?: string;
};

async function request<T>(path: string, options: RequestOptions = {}): Promise<T> {
  const headers = new Headers({ Accept: "application/json" });
  if (options.body !== undefined) headers.set("Content-Type", "application/json");
  if (options.csrf) {
    if (!csrfToken) throw new PhpApiError(419, "csrf_missing", "نشست امنیتی آماده نیست؛ دوباره وارد شوید.");
    headers.set("X-CSRF-Token", csrfToken);
  }
  if (options.setupToken) headers.set("X-Setup-Token", options.setupToken);

  let response: Response;
  try {
    response = await fetch(`${API_ROOT}${path}`, {
      method: options.method ?? "GET",
      credentials: "same-origin",
      cache: "no-store",
      headers,
      body: options.body === undefined ? undefined : JSON.stringify(options.body),
    });
  } catch {
    throw new PhpApiError(0, "network_error", "ارتباط با سرور برقرار نشد؛ اتصال اینترنت را بررسی کنید.");
  }

  let payload: Record<string, unknown>;
  try {
    payload = (await response.json()) as Record<string, unknown>;
  } catch {
    throw new PhpApiError(response.status, "invalid_response", "پاسخ سرور قابل خواندن نیست.");
  }

  if (!response.ok || payload.ok !== true) {
    const code = typeof payload.error === "string" ? payload.error : "request_failed";
    // A bad current password is a field-level 401, not an expired session.
    // Keep CSRF state unless the server explicitly says the session is unauthorized.
    if (response.status === 401 && code === "unauthorized") csrfToken = null;
    const message = typeof payload.message === "string" ? payload.message : "درخواست انجام نشد.";
    throw new PhpApiError(response.status, code, message);
  }

  return payload as T;
}

export const phpApi = {

  listTerminalAgents:()=>request<ApiEnvelope<{items:TerminalAgent[]}>>("/terminal-agents"),
  createTerminalAgent:(name:string)=>request<ApiEnvelope<{agent:TerminalAgent;token:string}>>("/terminal-agents",{method:"POST",csrf:true,body:{name}}),
  disableTerminalAgent:(id:string)=>request<ApiEnvelope<Record<string,never>>>(`/terminal-agents/${encodeURIComponent(id)}/disable`,{method:"POST",csrf:true,body:{}}),
  listTerminals:()=>request<ApiEnvelope<{items:PaymentTerminal[]}>>("/terminals"),
  listAvailableTerminals:()=>request<ApiEnvelope<{items:PaymentTerminal[]}>>("/terminals/available"),
  saveTerminal:(terminal:{id:string;agentId:string;localTerminalKey:string;name:string;providerCode:string;modelName?:string;driverCode:string;connectionType:"lan"|"wifi";host:string;port:number;enabled:boolean;isDefault:boolean})=>request<ApiEnvelope<{terminal:PaymentTerminal}>>("/terminals",{method:"POST",csrf:true,body:terminal}),
  disableTerminal:(id:string)=>request<ApiEnvelope<Record<string,never>>>(`/terminals/${encodeURIComponent(id)}/disable`,{method:"POST",csrf:true,body:{}}),
  createTerminalPayment:(input:{terminalId:string;amount:number;idempotencyKey:string})=>request<ApiEnvelope<{payment:{id:string;status:TerminalPaymentStatus;amount:number}}>>("/terminal-payments",{method:"POST",csrf:true,body:input}),
  getTerminalPayment:(id:string)=>request<ApiEnvelope<{payment:TerminalPayment}>>(`/terminal-payments/${encodeURIComponent(id)}`),
  listPendingTerminalPayments:()=>request<ApiEnvelope<{items:TerminalPayment[]}>>("/terminal-payments/pending"),
  cancelTerminalPayment:(id:string)=>request<ApiEnvelope<{status:TerminalPaymentStatus}>>(`/terminal-payments/${encodeURIComponent(id)}/cancel`,{method:"POST",csrf:true,body:{}}),
  attachTerminalPayment:(id:string,orderId:number)=>request<ApiEnvelope<{order_id:number}>>(`/terminal-payments/${encodeURIComponent(id)}/attach-order`,{method:"POST",csrf:true,body:{orderId}}),

  health: () => request<ApiEnvelope<{ service?: string; database?: string }>>("/health"),
  getAppearance:()=>request<ApiEnvelope<{settings:AppearanceSettings}>>("/appearance"),
  saveAppearance:(settings:AppearanceSettings)=>request<ApiEnvelope<{settings:AppearanceSettings}>>("/appearance",{method:"POST",csrf:true,body:{settings}}),
  resetAppearance:()=>request<ApiEnvelope<{settings:AppearanceSettings}>>("/appearance/reset",{method:"POST",csrf:true,body:{}}),
  uploadAppearanceLogo:(image:string)=>request<ApiEnvelope<{logo_url:string}>>("/appearance/logo",{method:"POST",csrf:true,body:{image}}),

  setupStatus: () =>
    request<ApiEnvelope<{ setup_required: boolean }>>("/setup/status"),

  setupManager: (input: {
    username: string;
    password: string;
    display_name: string;
    setupToken: string;
  }) =>
    request<ApiEnvelope<{ user: ApiUser; csrf_token: string }>>("/setup/manager", {
      method: "POST",
      setupToken: input.setupToken,
      body: {
        username: input.username,
        password: input.password,
        display_name: input.display_name,
      },
    }).then((result) => {
      csrfToken = result.csrf_token;
      return result;
    }),

  login: (username: string, password: string) =>
    request<ApiEnvelope<{ user: ApiUser; csrf_token: string }>>("/auth/login", {
      method: "POST",
      body: { username, password },
    }).then((result) => {
      csrfToken = result.csrf_token;
      return result;
    }),

  me: () =>
    request<
      ApiEnvelope<{
        authenticated: boolean;
        user?: ApiUser;
        csrf_token?: string;
      }>
    >("/auth/me").then((result) => {
      csrfToken = result.authenticated ? (result.csrf_token ?? null) : null;
      if (result.authenticated && result.user) {
        const flag = result.user.must_change_password as unknown;
        result.user.must_change_password = flag === true || flag === 1 || flag === "1";
      }
      return result;
    }),

  logout: () =>
    request<ApiEnvelope<Record<string, never>>>("/auth/logout", {
      method: "POST",
      csrf: true,
    }).finally(() => {
      csrfToken = null;
    }),

  changePassword: (current_password: string, new_password: string) =>
    request<ApiEnvelope<{ csrf_token: string }>>("/auth/change-password", {
      method: "POST",
      csrf: true,
      body: { current_password, new_password },
    }).then((result) => {
      csrfToken = result.csrf_token;
      return result;
    }),

  listUsers: () => request<ApiEnvelope<{ users: ManagedUser[] }>>("/users"),

  getReportSummary: (from: string, to: string) => request<ApiEnvelope<Record<string, unknown>>>(`/reports/summary?from=${encodeURIComponent(from)}&to=${encodeURIComponent(to)}`),

  createUser: (input: { username: string; password: string; display_name: string; role: PosRole }) =>
    request<ApiEnvelope<{ user: ManagedUser }>>("/users", { method: "POST", csrf: true, body: input }),

  updateUser: (id: number, input: Partial<Pick<ManagedUser, "display_name" | "role" | "is_active">> & { password?: string }) =>
    request<ApiEnvelope<Record<string, never>>>(`/users/${id}`, { method: "PATCH", csrf: true, body: input }),

  exportBackup: () =>
    request<ApiEnvelope<{ backup: Record<string, unknown> }>>("/backup/export"),
  restoreBackup: (backup: Record<string, unknown>) =>
    request<ApiEnvelope<{ restored: { records: number; orders: number; effects: number; redemptions: number } }>>("/backup/restore", { method: "POST", body: { backup } }),

  importLegacyData: (input: {
    data: Record<string, unknown[]>;
    nextOrder: number;
  }) =>
    request<ApiEnvelope<{ counts: Record<string, number>; orders_imported: number }>>("/migration/import", {
      method: "POST",
      csrf: true,
      body: input,
    }),

  bootstrap: <TData>() =>
    request<
      ApiEnvelope<{
        data: TData;
        next_order: number;
        user: ApiUser;
      }>
    >("/bootstrap"),

  listRecords: <TItem>(resource: string) =>
    request<ApiEnvelope<{ items: TItem[] }>>(`/records/${encodeURIComponent(resource)}`),

  createSupplierTransaction: <TItem, TSupplier>(supplierId: string, item: TItem) => request<ApiEnvelope<{item:TItem;supplier:TSupplier}>>(`/suppliers/${encodeURIComponent(supplierId)}/purchases`, { method: "POST", csrf: true, body: { item } }),

  saveRecord: <TItem extends { id: string | number }>(resource: string, item: TItem) =>
    request<ApiEnvelope<{ item: TItem }>>(`/records/${encodeURIComponent(resource)}`, {
      method: "POST",
      csrf: true,
      body: { item },
    }),

  adjustIngredientStock: <TItem extends { id: string }>(id: string, delta: number) =>
    request<ApiEnvelope<{ item: TItem }>>(
      `/ingredients/${encodeURIComponent(id)}/stock`,
      { method: "POST", csrf: true, body: { delta } },
    ),

  deleteRecord: (resource: string, id: string) =>
    request<ApiEnvelope<Record<string, never>>>(
      `/records/${encodeURIComponent(resource)}?id=${encodeURIComponent(id)}`,
      { method: "DELETE", csrf: true },
    ),

  createOrder: <TOrder>(input: {
    items: unknown[];
    totals: Record<string, number>;
    customer?: unknown;
    discountCode?: string;
    note?: string;
    guestName?: string;
    guestPhone?: string;
    payment?: "cash" | "card" | "online";
    terminalPaymentId?: string;
    idempotencyKey: string;
  }) =>
    request<ApiEnvelope<{ order: TOrder; replayed?: boolean }>>("/orders", {
      method: "POST",
      csrf: true,
      body: input,
    }),

  sendSms: (input: { to: string; text: string; bodyId?: string }) =>
    request<ApiEnvelope<{ sent: boolean; detail: string }>>("/sms/send", {
      method: "POST",
      csrf: true,
      body: input,
    }),

  syncBirthdaySmsInbox: () =>
    request<ApiEnvelope<{ received: number; processed: number; duplicates: number; birthdays_saved: number; unmatched: number; ignored: number }>>("/sms/birthday-sync", {
      method: "POST",
      csrf: true,
      body: {},
    }),

  updateOrder: <TOrder>(id: string, input: {
    items: unknown[];
    totals: Record<string, number>;
    customer?: { id: string };
    discountCode?: string;
    note?: string;
    guestName?: string;
    guestPhone?: string;
    payment?: "cash" | "card" | "online";
  }) =>
    request<ApiEnvelope<{ order: TOrder }>>(`/orders/${encodeURIComponent(id)}`, {
      method: "PATCH",
      csrf: true,
      body: input,
    }),

  setOrderStatus: (id: string, status: string) =>
    request<ApiEnvelope<{ status: string }>>(
      `/orders/${encodeURIComponent(id)}/status`,
      { method: "PATCH", csrf: true, body: { status } },
    ),
};

/** Clear in-memory CSRF state after an unrecoverable session transition. */
export function clearPhpApiSession(): void {
  csrfToken = null;
}
