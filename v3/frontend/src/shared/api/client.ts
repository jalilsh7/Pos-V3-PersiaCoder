export type BusinessSummary = { id: string; code: string; name: string; timezone: string; currency: string };
export type BranchSummary = { id: string; code: string; name: string; timezone: string | null; is_active: boolean };
export type SavedBusinessContext = { businessId: string; branchId: string };

export type AuthenticatedUser = {
  id: string;
  username: string;
  display_name: string;
  must_change_password: boolean;
};

export class ApiError extends Error {
  constructor(message: string, readonly status: number, readonly code: string) {
    super(message);
    this.name = "ApiError";
  }
}

const apiBase = (import.meta.env.VITE_API_BASE_URL || "/api/v1").replace(/\/$/, "");
let csrfToken: string | null = null;
let businessContext: { businessId: string; branchId: string } | null = null;
const contextStorageKey = "pos_v3_business_context";
const pendingMutationIdempotencyKeys = new Map<string, string>();

export function getSavedBusinessContext(): SavedBusinessContext | null {
  try {
    const raw = window.localStorage.getItem(contextStorageKey);
    if (!raw) return null;
    const value = JSON.parse(raw) as Partial<SavedBusinessContext>;
    if (typeof value.businessId !== "string" || typeof value.branchId !== "string"
      || !/^[0-9a-f-]{36}$/i.test(value.businessId) || !/^[0-9a-f-]{36}$/i.test(value.branchId)) return null;
    return { businessId: value.businessId, branchId: value.branchId };
  } catch {
    return null;
  }
}

export function setBusinessContext(business: BusinessSummary, branch: BranchSummary): void {
  if (businessContext && (businessContext.businessId !== business.id || businessContext.branchId !== branch.id)) {
    pendingMutationIdempotencyKeys.clear();
  }
  businessContext = { businessId: business.id, branchId: branch.id };
  try {
    window.localStorage.setItem(contextStorageKey, JSON.stringify(businessContext));
  } catch {
    // Public context identifiers are a convenience only; the active in-memory context remains usable.
  }
}

export function clearBusinessContext(): void {
  businessContext = null;
  pendingMutationIdempotencyKeys.clear();
  try {
    window.localStorage.removeItem(contextStorageKey);
  } catch {
    // Storage may be unavailable; the in-memory context is still cleared.
  }
}

function setCsrfToken(token: unknown): void {
  csrfToken = typeof token === "string" && /^[A-Za-z0-9_-]{43}$/.test(token) ? token : null;
}

async function request<T>(path: string, options: RequestInit = {}, context: { businessId: string; branchId: string | null } | null | undefined = undefined): Promise<T> {
  const method = (options.method || "GET").toUpperCase();
  const headers = new Headers(options.headers);
  const scope = context === undefined ? businessContext : context;
  if (scope?.businessId && !headers.has("X-Business-ID")) headers.set("X-Business-ID", scope.businessId);
  if (scope?.branchId && !headers.has("X-Branch-ID")) headers.set("X-Branch-ID", scope.branchId);
  if (options.body && !headers.has("Content-Type")) headers.set("Content-Type", "application/json");
  let pendingIdempotencySignature: string | null = null;
  if ((path.startsWith("/inventory/") || path.startsWith("/catalog/") || path.startsWith("/orders/") || path.startsWith("/cash/")) && !["GET", "HEAD", "OPTIONS"].includes(method)
    && !headers.has("Idempotency-Key")) {
    pendingIdempotencySignature = JSON.stringify([method, path, typeof options.body === "string" ? options.body : ""]);
    let key = pendingMutationIdempotencyKeys.get(pendingIdempotencySignature);
    if (!key) {
      key = globalThis.crypto.randomUUID();
      pendingMutationIdempotencyKeys.set(pendingIdempotencySignature, key);
    }
    headers.set("Idempotency-Key", key);
  }
  if (!["GET", "HEAD", "OPTIONS"].includes(method) && csrfToken) {
    headers.set("X-CSRF-Token", csrfToken);
  }

  let response: Response;
  try {
    response = await fetch(`${apiBase}${path}`, {
      ...options,
      method,
      headers,
      credentials: "same-origin",
      cache: "no-store",
    });
  } catch {
    throw new ApiError("ارتباط با سرور برقرار نشد. اتصال شبکه و نشانی API را بررسی کنید.", 0, "network_error");
  }

  const payload = await response.json().catch(() => null) as
    | { data?: T; error?: { message?: string; code?: string } }
    | null;

  if (!response.ok || !payload || !("data" in payload)) {
    throw new ApiError(
      payload?.error?.message || "درخواست انجام نشد. دوباره تلاش کنید.",
      response.status,
      payload?.error?.code || "request_failed",
    );
  }
  if (pendingIdempotencySignature !== null) pendingMutationIdempotencyKeys.delete(pendingIdempotencySignature);
  return payload.data as T;
}

export async function getCurrentSession(): Promise<AuthenticatedUser> {
  const data = await request<{ user: AuthenticatedUser; csrf_token: string }>("/auth/me", {}, null);
  setCsrfToken(data.csrf_token);
  return data.user;
}

export async function signIn(username: string, password: string): Promise<AuthenticatedUser> {
  const data = await request<{ user: AuthenticatedUser; csrf_token: string }>("/auth/login", {
    method: "POST",
    body: JSON.stringify({ username, password }),
  }, null);
  setCsrfToken(data.csrf_token);
  return data.user;
}

export async function signOut(): Promise<void> {
  await request<{ logged_out: boolean }>("/auth/logout", { method: "POST", body: "{}" }, null);
  setCsrfToken(null);
  pendingMutationIdempotencyKeys.clear();
}

export async function changePassword(currentPassword: string, newPassword: string): Promise<void> {
  await request<{ password_changed: boolean }>("/auth/change-password", {
    method: "POST",
    body: JSON.stringify({ current_password: currentPassword, new_password: newPassword }),
  }, null);
  setCsrfToken(null);
}

 
export async function getAccessibleBusinesses(): Promise<{ businesses: BusinessSummary[]; can_initialize: boolean }> {
  return request<{ businesses: BusinessSummary[]; can_initialize: boolean }>("/access/businesses", {}, null);
}

export async function getBranches(businessId: string): Promise<BranchSummary[]> {
  const data = await request<{ branches: BranchSummary[] }>("/access/branches", {}, { businessId, branchId: null });
  return data.branches.filter((branch) => branch.is_active);
}

export async function createInitialBusiness(payload: {
  name: string;
  code: string;
  timezone: string;
  currency: "IRT";
  branch_name: string;
  branch_code: string;
}): Promise<{ business: BusinessSummary; branch: BranchSummary; setup_completed: boolean }> {
  return request<{ business: BusinessSummary; branch: BranchSummary; setup_completed: boolean }>(
    "/setup/business",
    { method: "POST", body: JSON.stringify(payload) },
    null,
  );
}


export type InventoryLocation = {
  id: string;
  code: string;
  name: string;
  location_type: string;
  is_active: boolean;
};

export type InventorySupplier = {
  id: string;
  name: string;
  phone: string | null;
  notes: string | null;
  is_active: boolean;
};

export async function getInventorySuppliers(includeInactive = false): Promise<InventorySupplier[]> {
  const query = new URLSearchParams({ include_inactive: includeInactive ? "1" : "0" });
  const data = await request<{ suppliers: InventorySupplier[] }>(`/inventory/suppliers?${query.toString()}`);
  return data.suppliers;
}

export async function createInventorySupplier(payload: {
  name: string; phone?: string; notes?: string;
}): Promise<void> {
  await request<{ supplier: { id: string } }>("/inventory/suppliers", {
    method: "POST", body: JSON.stringify(payload),
  });
}

export async function updateInventorySupplier(supplierId: string, payload: {
  name: string; phone?: string | null; notes?: string | null; is_active: boolean;
}): Promise<void> {
  await request<{ supplier: { id: string } }>(`/inventory/suppliers/${encodeURIComponent(supplierId)}`, {
    method: "PATCH", body: JSON.stringify(payload),
  });
}

export type InventoryBalance = {
  stock_item_id: string;
  stock_item_code: string;
  stock_item_name: string;
  base_unit_code: string;
  location_id: string;
  location_code: string;
  location_name: string;
  quantity_on_hand: string;
  quantity_reserved: string;
  quantity_available: string;
  updated_at: string;
};

export type InventoryLedgerEntry = {
  movement_type: string;
  quantity_delta: string;
  base_unit_code: string;
  stock_item_id: string;
  stock_item_code: string;
  stock_item_name: string;
  location_id: string;
  location_code: string;
  location_name: string;
  document_id: string | null;
  document_no: string | null;
  document_type: string | null;
  note: string | null;
  created_at: string;
};

export async function getInventoryLocations(includeInactive = false): Promise<InventoryLocation[]> {
  const data = await request<{ locations: InventoryLocation[] }>("/inventory/locations");
  return includeInactive ? data.locations : data.locations.filter((location) => location.is_active);
}

export async function createInventoryLocation(payload: {
  code: string; name: string; location_type: "storage" | "kitchen" | "bar" | "freezer" | "other";
}): Promise<void> {
  await request<{ location: { id: string } }>("/inventory/locations", {
    method: "POST", body: JSON.stringify(payload),
  });
}

export async function updateInventoryLocation(locationId: string, payload: {
  code: string; name: string; location_type: "storage" | "kitchen" | "bar" | "freezer" | "other"; is_active: boolean;
}): Promise<void> {
  await request<{ location: { id: string } }>(`/inventory/locations/${encodeURIComponent(locationId)}`, {
    method: "PATCH", body: JSON.stringify(payload),
  });
}

export async function getInventoryBalances(filters: {
  stockItemId?: string;
  locationId?: string;
  limit?: number;
} = {}): Promise<InventoryBalance[]> {
  const query = new URLSearchParams({ limit: String(filters.limit ?? 200) });
  if (filters.stockItemId) query.set("stock_item_id", filters.stockItemId);
  if (filters.locationId) query.set("location_id", filters.locationId);
  const data = await request<{ balances: InventoryBalance[] }>(`/inventory/balances?${query.toString()}`);
  return data.balances;
}

export async function getInventoryLedger(filters: {
  stockItemId?: string;
  locationId?: string;
  limit?: number;
} = {}): Promise<InventoryLedgerEntry[]> {
  const query = new URLSearchParams({ limit: String(filters.limit ?? 50) });
  if (filters.stockItemId) query.set("stock_item_id", filters.stockItemId);
  if (filters.locationId) query.set("location_id", filters.locationId);
  const data = await request<{ entries: InventoryLedgerEntry[] }>(`/inventory/ledger?${query.toString()}`);
  return data.entries;
}


export type InventoryStockItem = {
  id: string;
  code: string;
  name: string;
  base_unit: { code: string; name: string; dimension: string };
  negative_stock_policy: string;
  is_active: boolean;
};

export async function getInventoryStockItems(search = "", includeInactive = false): Promise<InventoryStockItem[]> {
  const query = new URLSearchParams({ limit: "100" });
  if (search.trim()) query.set("search", search.trim());
  const data = await request<{ stock_items: InventoryStockItem[] }>(`/inventory/items?${query.toString()}`);
  return includeInactive ? data.stock_items : data.stock_items.filter((item) => item.is_active);
}

export async function createInventoryStockItem(payload: {
  code: string; name: string; base_unit_code: string; negative_stock_policy?: "block";
}): Promise<void> {
  await request<{ stock_item: { id: string } }>("/inventory/items", {
    method: "POST", body: JSON.stringify(payload),
  });
}

export async function updateInventoryStockItem(itemId: string, payload: {
  code: string; name: string; is_active: boolean;
}): Promise<void> {
  await request<{ stock_item: { id: string } }>(`/inventory/items/${encodeURIComponent(itemId)}`, {
    method: "PATCH", body: JSON.stringify(payload),
  });
}


export type InventoryUnit = { code: string; name: string; dimension: string };
export type InventoryDocumentType =
  | "purchase_receipt" | "consumption" | "transfer" | "adjustment_in" | "adjustment_out" | "waste";
export type InventoryDocumentSummary = {
  id: string;
  document_no: string;
  document_type: string;
  status: "draft" | "posted" | "voided";
  reason: string | null;
  location: { id: string; name: string };
  destination: { id: string; name: string } | null;
  supplier: { id: string; name: string } | null;
  created_at: string;
  posted_at: string | null;
};

export async function getInventoryUnits(): Promise<InventoryUnit[]> {
  const data = await request<{ units: InventoryUnit[] }>("/inventory/units");
  return data.units;
}

export async function getInventoryDocuments(limit = 50): Promise<InventoryDocumentSummary[]> {
  const query = new URLSearchParams({ limit: String(limit) });
  const data = await request<{ documents: InventoryDocumentSummary[] }>(`/inventory/documents?${query.toString()}`);
  return data.documents;
}

export async function createInventoryDocument(payload: {
  document_type: InventoryDocumentType;
  location_id: string;
  destination_location_id?: string;
  reason?: string;
  supplier_id?: string;
}): Promise<{ id: string; document_no: string; document_type: string; status: string }> {
  const data = await request<{ stock_document: { id: string; document_no: string; document_type: string; status: string } }>(
    "/inventory/documents",
    { method: "POST", body: JSON.stringify(payload) },
  );
  return data.stock_document;
}

export async function addInventoryDocumentLine(documentId: string, payload: {
  stock_item_id: string;
  quantity: string;
  unit_code: string;
  total_cost_amount?: string;
  note?: string;
}): Promise<void> {
  await request<{ stock_document_line: { id: string } }>(
    `/inventory/documents/${encodeURIComponent(documentId)}/lines`,
    { method: "POST", body: JSON.stringify(payload) },
  );
}

export async function postInventoryDocument(documentId: string): Promise<void> {
  await request<{ stock_document: { id: string; status: string } }>(
    `/inventory/documents/${encodeURIComponent(documentId)}/post`,
    { method: "POST", body: "{}" },
  );
}


export type InventoryDocumentLine = {
  id: string;
  stock_item_id: string;
  stock_item_name: string;
  quantity: string;
  unit_code: string;
  unit_name: string;
  conversion_factor_snapshot: string;
  base_quantity: string;
  note: string | null;
};
export type InventoryDocumentDetail = InventoryDocumentSummary & {
  lines: InventoryDocumentLine[];
};

export async function getInventoryDocument(documentId: string): Promise<InventoryDocumentDetail> {
  const data = await request<{ stock_document: InventoryDocumentDetail }>(
    `/inventory/documents/${encodeURIComponent(documentId)}`,
  );
  return data.stock_document;
}

export async function updateInventoryDocumentLine(documentId: string, lineId: string, payload: {
  stock_item_id: string;
  quantity: string;
  unit_code: string;
  total_cost_amount?: string;
  note?: string;
}): Promise<void> {
  await request<{ stock_document_line: { id: string } }>(
    `/inventory/documents/${encodeURIComponent(documentId)}/lines/${encodeURIComponent(lineId)}`,
    { method: "PATCH", body: JSON.stringify(payload) },
  );
}

export async function deleteInventoryDocumentLine(documentId: string, lineId: string): Promise<void> {
  await request<{ deleted: boolean }>(
    `/inventory/documents/${encodeURIComponent(documentId)}/lines/${encodeURIComponent(lineId)}`,
    { method: "DELETE", body: "{}" },
  );
}


export type PosCatalogProduct = {
  id: string;
  name: string;
  sku: string | null;
  product_type: string;
  base_price_amount: number;
  tax_rate_bps: number;
  is_active: boolean;
  category: { id: string; name: string } | null;
  image: { id: string; url: string } | null;
};

export type PosCatalogProductDetail = Omit<PosCatalogProduct, "category"> & {
  variants: { id: string; name: string; sku: string | null; price_delta_amount: number; is_active: boolean }[];
  option_groups: {
    id: string;
    name: string;
    selection_type: "single" | "multiple";
    min_select: number;
    max_select: number;
    is_required_default: boolean;
    is_required: boolean;
    sort_order: number;
    options: { id: string; name: string; price_delta_amount: number; is_active: boolean }[];
  }[];
};

export async function getPosCatalogProducts(search = ""): Promise<PosCatalogProduct[]> {
  const query = new URLSearchParams({ limit: "100" });
  if (search.trim()) query.set("search", search.trim());
  const data = await request<{ products: PosCatalogProduct[] }>(`/catalog/products?${query.toString()}`);
  return data.products.filter((product) => product.is_active);
}

export async function getPosCatalogProductDetails(productId: string): Promise<PosCatalogProductDetail> {
  const data = await request<{ product: PosCatalogProductDetail }>(`/catalog/products/${encodeURIComponent(productId)}`);
  return data.product;
}

export type CatalogCategory = {
  id: string;
  name: string;
  parent_id: string | null;
  sort_order: number;
  is_active: boolean;
};

export type CreatedCatalogProduct = {
  id: string;
  name: string;
  sku: string | null;
  product_type: string;
  base_price_amount: number;
  tax_rate_bps: number;
  category_id: string | null;
  is_active: boolean;
};

export type CatalogProductInput = {
  name: string;
  base_price_amount: number;
  category_id?: string | null;
  sku?: string | null;
  product_type?: "prepared" | "packaged" | "ingredient" | "service";
  tax_rate_bps?: number;
};

export async function getCatalogCategories(): Promise<CatalogCategory[]> {
  const data = await request<{ categories: CatalogCategory[] }>("/catalog/categories");
  return data.categories.filter((category) => category.is_active);
}

export async function getCatalogProducts(): Promise<PosCatalogProduct[]> {
  const data = await request<{ products: PosCatalogProduct[] }>("/catalog/products?limit=100");
  return data.products;
}

export async function createCatalogCategory(name: string): Promise<CatalogCategory> {
  const data = await request<{ category: CatalogCategory }>("/catalog/categories", {
    method: "POST",
    body: JSON.stringify({ name: name.trim(), sort_order: 0 }),
  });
  return data.category;
}

export async function createCatalogProduct(payload: CatalogProductInput): Promise<CreatedCatalogProduct> {
  const data = await request<{ product: CreatedCatalogProduct }>("/catalog/products", {
    method: "POST",
    body: JSON.stringify({
      name: payload.name.trim(),
      base_price_amount: payload.base_price_amount,
      category_id: payload.category_id ?? null,
      sku: payload.sku ?? null,
      product_type: payload.product_type ?? "prepared",
      tax_rate_bps: payload.tax_rate_bps ?? 0,
    }),
  });
  return data.product;
}

export async function updateCatalogProduct(
  productId: string,
  payload: Partial<CatalogProductInput> & { is_active?: boolean },
): Promise<void> {
  await request<{ product: { id: string } }>(`/catalog/products/${encodeURIComponent(productId)}`, {
    method: "PATCH",
    body: JSON.stringify(payload),
  });
}

export async function archiveCatalogProduct(productId: string): Promise<void> {
  await request<{ product: { id: string; archived: boolean } }>(
    `/catalog/products/${encodeURIComponent(productId)}`,
    { method: "DELETE", body: "{}" },
  );
}

export type CreatePosOrderPayload = {
  source_channel: "pos";
  fulfillment_method: "dine_in" | "takeaway" | "delivery";
  recipient_name?: string;
  recipient_phone?: string;
  delivery_address?: { street: string };
  customer_id?: string | null;
  items: {
    product_id: string;
    variant_id: string | null;
    quantity: number;
    options: string[];
  }[];
};

export type CreatedPosOrder = {
  id: string;
  order_number: number;
  source_channel: string;
  order_status: string;
  payment_status: string;
  subtotal_amount: number;
  tax_amount: number;
  total_amount: number;
  currency_code: string;
  fulfillment_method: string | null;
  item_count: number;
};

export async function createPosOrder(payload: CreatePosOrderPayload, idempotencyKey: string): Promise<CreatedPosOrder> {
  const data = await request<{ order: CreatedPosOrder }>("/orders", {
    method: "POST",
    headers: { "Idempotency-Key": idempotencyKey },
    body: JSON.stringify(payload),
  });
  return data.order;
}

export type PosCustomerOption = { id: string; name: string; phone: string };

export async function searchPosCustomers(query: string): Promise<PosCustomerOption[]> {
  const suffix = query.trim() ? `?q=${encodeURIComponent(query.trim())}` : "";
  const data = await request<{ customers: PosCustomerOption[] }>(`/pos/customers${suffix}`);
  return data.customers;
}

export type CustomerSummary = {
  id: string;
  name: string;
  phone: string;
  notes: string;
  is_active: boolean;
  order_count: number;
  total_spent: number;
  last_order_at: string | null;
  created_at: string;
  updated_at: string;
};

export type CustomerDetail = {
  customer: CustomerSummary;
  stats: { order_count: number; total_spent: number; last_order_at: string | null };
  recent_orders: {
    id: string;
    order_number: number;
    source_channel: string;
    order_status: string;
    payment_status: string;
    total_amount: number;
    created_at: string;
  }[];
};

export async function getCustomers(options: {
  query?: string;
  status?: "all" | "active" | "inactive";
  limit?: number;
  cursor?: string | null;
} = {}): Promise<{ customers: CustomerSummary[]; next_cursor: string | null }> {
  const params = new URLSearchParams();
  if (options.query) params.set("q", options.query);
  if (options.status) params.set("status", options.status);
  if (options.limit) params.set("limit", String(options.limit));
  if (options.cursor) params.set("cursor", options.cursor);
  const suffix = params.toString();
  return request<{ customers: CustomerSummary[]; next_cursor: string | null }>(`/customers${suffix ? `?${suffix}` : ""}`);
}

export async function getCustomerDetails(customerId: string): Promise<CustomerDetail> {
  return request<CustomerDetail>(`/customers/${encodeURIComponent(customerId)}`);
}

export async function createCustomer(
  payload: { name: string; phone?: string; notes?: string },
  idempotencyKey: string,
): Promise<{ customer: CustomerSummary }> {
  return request<{ customer: CustomerSummary }>("/customers", {
    method: "POST",
    headers: { "Idempotency-Key": idempotencyKey },
    body: JSON.stringify(payload),
  });
}

export async function updateCustomer(
  customerId: string,
  payload: { name?: string; phone?: string; notes?: string; is_active?: boolean },
  idempotencyKey: string,
): Promise<CustomerDetail> {
  return request<CustomerDetail>(`/customers/${encodeURIComponent(customerId)}`, {
    method: "PATCH",
    headers: { "Idempotency-Key": idempotencyKey },
    body: JSON.stringify(payload),
  });
}

export type OpenCashPaymentSession = {
  id: string;
  register_name: string;
  register_code: string;
};

export async function getOpenCashPaymentSessions(): Promise<OpenCashPaymentSession[]> {
  const data = await request<{ sessions: OpenCashPaymentSession[] }>("/cash/payment-sessions");
  return data.sessions;
}

export async function getOpenCashRefundSessions(): Promise<OpenCashPaymentSession[]> {
  const data = await request<{ sessions: OpenCashPaymentSession[] }>("/cash/refund-sessions");
  return data.sessions;
}

export type OrderPaymentSummary = {
  order_id: string;
  order_number: number;
  payment_status: string;
  total_amount: number;
  captured_amount: number;
  refunded_amount: number;
  due_amount: number;
  payments: {
    id: string;
    method: string;
    amount: number;
    currency_code: string;
    status: string;
    provider_reference: string | null;
    captured_at: string | null;
    created_at: string;
  }[];
  refunds: {
    id: string;
    payment_id: string;
    amount: number;
    method: string;
    status: string;
    reason: string;
    provider_reference: string | null;
    created_at: string;
    completed_at: string | null;
  }[];
};

export async function getOrderPayments(orderId: string): Promise<OrderPaymentSummary> {
  return request<OrderPaymentSummary>(`/orders/${encodeURIComponent(orderId)}/payments`);
}

export async function captureCashPayment(
  orderId: string,
  cashSessionId: string,
  amount: number,
  idempotencyKey: string,
): Promise<{
  payment: { id: string; method: "cash"; amount: number; status: "captured"; captured_at: string };
  order: { id: string; order_number: number; payment_status: string; captured_amount: number; due_amount: number };
}> {
  return request<{
    payment: { id: string; method: "cash"; amount: number; status: "captured"; captured_at: string };
    order: { id: string; order_number: number; payment_status: string; captured_amount: number; due_amount: number };
  }>(`/orders/${encodeURIComponent(orderId)}/payments/cash`, {
    method: "POST",
    headers: { "Idempotency-Key": idempotencyKey },
    body: JSON.stringify({ cash_session_id: cashSessionId, amount }),
  });
}

export async function refundCashPayment(
  orderId: string,
  paymentId: string,
  cashSessionId: string,
  amount: number,
  reason: string,
  idempotencyKey: string,
): Promise<{
  refund: { id: string; payment_id: string; amount: number; method: "cash"; status: "succeeded"; reason: string };
  order: { id: string; order_number: number; payment_status: string; captured_amount: number; refunded_amount: number; due_amount: number };
}> {
  return request<{
    refund: { id: string; payment_id: string; amount: number; method: "cash"; status: "succeeded"; reason: string };
    order: { id: string; order_number: number; payment_status: string; captured_amount: number; refunded_amount: number; due_amount: number };
  }>(`/orders/${encodeURIComponent(orderId)}/payments/${encodeURIComponent(paymentId)}/refunds/cash`, {
    method: "POST",
    headers: { "Idempotency-Key": idempotencyKey },
    body: JSON.stringify({ cash_session_id: cashSessionId, amount, reason: reason.trim(), confirm: true }),
  });
}

export type OrderStatus =
  | "draft" | "awaiting_payment" | "accepted" | "preparing"
  | "ready" | "completed" | "cancelled" | "rejected";
export type OrderPaymentStatus = "unpaid" | "partially_paid" | "paid" | "refunded" | string;
export type OrderChannel = "pos" | "phone" | "manual" | "table" | "takeaway" | "storefront";
export type OrderSummary = {
  id: string;
  order_number: number;
  source_channel: OrderChannel;
  order_status: OrderStatus;
  payment_status: OrderPaymentStatus;
  subtotal_amount: number;
  tax_amount: number;
  total_amount: number;
  currency_code: string;
  created_at: string;
  fulfillment_method: "dine_in" | "takeaway" | "delivery" | null;
  customer: { id: string; name: string } | null;
};
export type OrderDetail = {
  order: OrderSummary & {
    discount_amount: number;
    delivery_fee_amount: number;
    accepted_at: string | null;
    table_session_id: string | null;
    fulfillment: {
      method: "dine_in" | "takeaway" | "delivery";
      recipient_name: string | null;
      recipient_phone: string | null;
      address: Record<string, string | number> | null;
      delivery_fee_amount: number;
    } | null;
  };
  items: {
    name: string;
    variant_name: string | null;
    sku: string | null;
    quantity: string;
    unit_price_amount: number;
    discount_amount: number;
    tax_rate_bps: number;
    tax_amount: number;
    line_total_amount: number;
    options: { group_name: string; name: string; price_delta_amount: number; quantity: string }[];
  }[];
  status_history: {
    from_status: string | null;
    to_status: string;
    reason_code: string | null;
    reason_text: string | null;
    source_channel: string;
    created_at: string;
    actor: { id: string; name: string | null } | null;
  }[];
};

export type OrderTransitionResult = {
  order_id: string;
  order_number: number;
  from_status: OrderStatus;
  to_status: OrderStatus;
  payment_status: string;
};

export async function transitionOrder(
  orderId: string,
  toStatus: OrderStatus,
  reasonText?: string,
): Promise<OrderTransitionResult> {
  const data = await request<OrderTransitionResult>(`/orders/${encodeURIComponent(orderId)}/status`, {
    method: "PATCH",
    body: JSON.stringify({
      to_status: toStatus,
      ...(reasonText ? { reason_text: reasonText } : {}),
    }),
  });
  return data;
}

export async function transitionKitchenOrder(
  orderId: string,
  toStatus: "preparing" | "ready",
): Promise<OrderTransitionResult> {
  const data = await request<OrderTransitionResult>(`/orders/${encodeURIComponent(orderId)}/kitchen-status`, {
    method: "PATCH",
    body: JSON.stringify({ to_status: toStatus }),
  });
  return data;
}

export type OrderPage = {
  orders: OrderSummary[];
  has_more: boolean;
  next_cursor: string | null;
};

export async function getOrders(filters: {
  status?: string;
  channel?: string;
  payment?: string;
  fulfillment?: string;
  fromDate?: string;
  toDate?: string;
  limit?: number;
  before?: string;
} = {}): Promise<OrderPage> {
  const query = new URLSearchParams({ limit: String(filters.limit ?? 50) });
  if (filters.status) query.set("status", filters.status);
  if (filters.channel) query.set("channel", filters.channel);
  if (filters.payment) query.set("payment", filters.payment);
  if (filters.fulfillment) query.set("fulfillment", filters.fulfillment);
  if (filters.fromDate) query.set("from_date", filters.fromDate);
  if (filters.toDate) query.set("to_date", filters.toDate);
  if (filters.before) query.set("before", filters.before);
  return request<OrderPage>(`/orders?${query.toString()}`);
}

export async function getOrderDetails(orderId: string): Promise<OrderDetail> {
  return request<OrderDetail>(`/orders/${encodeURIComponent(orderId)}`);
}


export type CashSessionSummary = {
  id: string;
  status: "open" | "requires_review";
  opening_amount: number;
  expected_cash_amount: number;
  opened_at: string;
};

export type CashRegisterSummary = {
  id: string;
  code: string;
  name: string;
  is_active: boolean;
  active_session: CashSessionSummary | null;
};

export async function getCashRegisters(): Promise<CashRegisterSummary[]> {
  const data = await request<{ registers: CashRegisterSummary[] }>("/cash/registers");
  return data.registers;
}

export async function createCashRegister(payload: { code: string; name: string }): Promise<CashRegisterSummary> {
  const data = await request<{ register: CashRegisterSummary }>("/cash/registers", {
    method: "POST",
    body: JSON.stringify({ code: payload.code.trim().toUpperCase(), name: payload.name.trim() }),
  });
  return data.register;
}

export async function openCashSession(registerId: string, openingAmount: number): Promise<CashSessionSummary> {
  const data = await request<{ session: CashSessionSummary }>(`/cash/registers/${encodeURIComponent(registerId)}/sessions`, {
    method: "POST",
    body: JSON.stringify({ opening_amount: openingAmount }),
  });
  return data.session;
}

export async function closeCashSession(sessionId: string, countedCashAmount: number): Promise<{
  session_id: string; status: string; expected_cash_amount: number; counted_cash_amount: number; difference_amount: number;
}> {
  return request<{ session_id: string; status: string; expected_cash_amount: number; counted_cash_amount: number; difference_amount: number }>(`/cash/sessions/${encodeURIComponent(sessionId)}/close`, {
    method: "PATCH",
    body: JSON.stringify({ counted_cash_amount: countedCashAmount }),
  });
}

export async function reviewCashSession(sessionId: string, reviewNote: string): Promise<void> {
  await request(`/cash/sessions/${encodeURIComponent(sessionId)}/review`, {
    method: "POST",
    body: JSON.stringify({ review_note: reviewNote.trim() }),
  });
}


export async function createManualCashMovement(sessionId: string, payload: {
  direction: "in" | "out";
  amount: number;
  reason: string;
}): Promise<{ session_id: string; movement_type: "manual_in" | "manual_out"; amount: number; expected_cash_amount: number }> {
  return request<{ session_id: string; movement_type: "manual_in" | "manual_out"; amount: number; expected_cash_amount: number }>(`/cash/sessions/${encodeURIComponent(sessionId)}/movements`, {
    method: "POST",
    body: JSON.stringify(payload),
  });
}


export type AccessUserSummary = {
  id: string;
  username: string;
  display_name: string;
  is_active: boolean;
  must_change_password: boolean;
  last_login_at: string | null;
  business_roles: string[];
  branch_roles: string[];
};

export type AccessRoleSummary = {
  id: number;
  code: string;
  name: string;
  is_system: boolean;
  permissions: string[];
};

export type AccessPermissionSummary = {
  code: string;
  description: string | null;
};

export type CreatedAccessUser = {
  user: {
    id: string;
    username: string;
    display_name: string;
    role_code: string;
    must_change_password: true;
  };
  temporary_password: string;
  credential_notice: string;
};

export async function getAccessUsers(scope: "business" | "branch" = "business"): Promise<AccessUserSummary[]> {
  const path = scope === "branch" ? "/access/branches/users" : "/access/users";
  const data = await request<{ users: AccessUserSummary[] }>(path);
  return data.users;
}

export async function createAccessUser(
  scope: "business" | "branch",
  payload: { username: string; display_name: string; role_code: string },
  idempotencyKey: string,
): Promise<CreatedAccessUser> {
  const path = scope === "branch" ? "/access/branches/users" : "/access/users";
  return request<CreatedAccessUser>(path, {
    method: "POST",
    headers: { "Idempotency-Key": idempotencyKey },
    body: JSON.stringify(payload),
  });
}

export async function getAccessRoles(): Promise<AccessRoleSummary[]> {
  const data = await request<{ roles: AccessRoleSummary[] }>("/access/roles");
  return data.roles;
}

export async function getAccessPermissions(): Promise<AccessPermissionSummary[]> {
  const data = await request<{ permissions: AccessPermissionSummary[] }>("/access/permissions");
  return data.permissions;
}

export async function createAccessRole(
  payload: { code: string; name: string; permissions: string[] },
  idempotencyKey: string,
): Promise<{ created: boolean; code: string }> {
  return request<{ created: boolean; code: string }>("/access/roles", {
    method: "POST",
    headers: { "Idempotency-Key": idempotencyKey },
    body: JSON.stringify(payload),
  });
}

export async function updateAccessRole(
  roleCode: string,
  payload: { name: string; permissions: string[] },
  idempotencyKey: string,
): Promise<{ updated: boolean; code: string }> {
  return request<{ updated: boolean; code: string }>(\`/access/roles/\${encodeURIComponent(roleCode)}\`, {
    method: "PATCH",
    headers: { "Idempotency-Key": idempotencyKey },
    body: JSON.stringify(payload),
  });
}

export async function assignAccessRole(
  userPublicId: string,
  roleCode: string,
  scope: "business" | "branch",
  idempotencyKey: string,
): Promise<{ assigned: boolean }> {
  const suffix = scope === "branch" ? "branch-roles" : "business-roles";
  return request<{ assigned: boolean }>(\`/access/users/\${encodeURIComponent(userPublicId)}/\${suffix}\`, {
    method: "POST",
    headers: { "Idempotency-Key": idempotencyKey },
    body: JSON.stringify({ role_code: roleCode }),
  });
}

export async function removeAccessRole(
  userPublicId: string,
  roleCode: string,
  scope: "business" | "branch",
  idempotencyKey: string,
): Promise<{ removed: boolean }> {
  const suffix = scope === "branch" ? "branch-roles" : "business-roles";
  return request<{ removed: boolean }>(
    \`/access/users/\${encodeURIComponent(userPublicId)}/\${suffix}/\${encodeURIComponent(roleCode)}\`,
    { method: "DELETE", headers: { "Idempotency-Key": idempotencyKey } },
  );
}
