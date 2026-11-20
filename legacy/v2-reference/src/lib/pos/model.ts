export type Role = "manager" | "cashier" | "kitchen";

export type Category = { id: string; name: string };

export type Option = { id: string; name: string; price: number };

export type RecipeLine = { ingredientId: string; qty: number };

export type Product = {
  id: string;
  name: string;
  categoryId: string;
  price: number;
  cost: number;
  options: Option[];
  recipe: RecipeLine[];
  active: boolean;
};

export type Ingredient = {
  id: string;
  name: string;
  unit: string;
  stock: number;
  minStock: number;
  costPerUnit: number;
};

export type Tier = "bronze" | "silver" | "gold" | "platinum";

export type Customer = {
  id: string;
  name: string;
  phone: string;
  points: number;
  birthday: string;
  smsConsent?: boolean;
  totalSpent: number;
  visits: number;
  createdAt: string;
};

export type DiscountAudience = "all" | "club" | Tier;

export type DiscountCode = {
  id: string;
  code: string;
  type: "percent" | "fixed";
  value: number;
  maxDiscount: number;
  minOrder: number;
  maxUses: number;
  usedCount: number;
  perCustomer: number;
  startsAt: string;
  endsAt: string;
  audience: DiscountAudience;
};

export type CartLine = {
  key: string;
  productId: string;
  name: string;
  qty: number;
  unitPrice: number;
  optionIds: string[];
  optionLabels: string[];
  note: string;
};

export type OrderStatus = "new" | "preparing" | "ready" | "delivered" | "cancelled";

export type Order = {
  id: string;
  number: number;
  createdAt: string;
  status: OrderStatus;
  lines: CartLine[];
  subtotal: number;
  tierDiscount: number;
  codeDiscount: number;
  pointsDiscount: number;
  tax: number;
  total: number;
  payment: "cash" | "card" | "online";
  customerId: string | null;
  customerName: string;
  customerPhone: string;
  discountCode: string;
  pointsUsed: number;
  pointsEarned: number;
};

export type Expense = {
  id: string;
  title: string;
  amount: number;
  category: string;
  date: string;
  note?: string;
  createdAt?: string;
};

export type SmsEvent =
  | "order_created"
  | "order_ready"
  | "order_delivered"
  | "points_earned"
  | "discount_used";

export type SmsTemplate = {
  event: SmsEvent;
  enabled: boolean;
  body: string;
  bodyId: string;
};

export type SmsLog = {
  id: string;
  at: string;
  to: string;
  event: string;
  text: string;
  ok: boolean;
  detail: string;
};

export type Settings = {
  shopName: string;
  address: string;
  phone: string;
  taxPercent: number;
  taxEnabled: boolean;
  stockPolicy: "block" | "warn_and_allow" | "allow_negative";
  pointsPerToman: number;
  tomanPerPoint: number;
  paperWidth: 58 | 80;
  autoPrint: boolean;
  invoiceSmsEnabledDefault?: boolean;
  birthdaySmsPrompt?: string;
  loyaltySilverPoints?: number;
  loyaltyGoldPoints?: number;
  loyaltyPlatinumPoints?: number;
  loyaltySilverDiscount?: number;
  loyaltyGoldDiscount?: number;
  loyaltyPlatinumDiscount?: number;
  printCustomer: boolean;
  printKitchen: boolean;
  headerNote: string;
  footerNote: string;
  smsUser: string;
  smsKey: string;
  smsFrom: string;
  role: Role;
};

export type PosState = {
  categories: Category[];
  products: Product[];
  ingredients: Ingredient[];
  customers: Customer[];
  discounts: DiscountCode[];
  orders: Order[];
  expenses: Expense[];
  templates: SmsTemplate[];
  smsLogs: SmsLog[];
  settings: Settings;
  nextOrder: number;
};

export const TIER_LABEL: Record<Tier, string> = {
  bronze: "برنز",
  silver: "نقره",
  gold: "طلا",
  platinum: "پلاتین",
};

export const TIER_OFF: Record<Tier, number> = {
  bronze: 0,
  silver: 3,
  gold: 5,
  platinum: 8,
};

export function tierOf(points: number, settings?: Partial<Settings>): Tier {
  const silver = Math.max(1, settings?.loyaltySilverPoints ?? 500);
  const gold = Math.max(silver + 1, settings?.loyaltyGoldPoints ?? 1500);
  const platinum = Math.max(gold + 1, settings?.loyaltyPlatinumPoints ?? 4000);
  if (points >= platinum) return "platinum";
  if (points >= gold) return "gold";
  if (points >= silver) return "silver";
  return "bronze";
}

export function money(n: number) {
  return `${Math.round(n).toLocaleString("fa-IR")} تومان`;
}

export function uid(prefix = "id") {
  return `${prefix}_${Math.random().toString(36).slice(2, 9)}`;
}

export function fillTemplate(body: string, vars: Record<string, string>) {
  return body.replace(/\{(\w+)\}/g, (_, k) => vars[k] ?? "");
}

export type Quote = {
  subtotal: number;
  tierDiscount: number;
  codeDiscount: number;
  pointsDiscount: number;
  tax: number;
  total: number;
  codeError: string;
  pointsError: string;
  discount: DiscountCode | null;
};

export function quoteOrder(input: {
  lines: CartLine[];
  customer: Customer | null;
  code: string;
  discounts: DiscountCode[];
  pointsToUse: number;
  taxPercent: number;
  taxEnabled?: boolean;
  tomanPerPoint: number;
  usesSoFar?: number;
  now?: Date;
  settings?: Partial<Settings>;
}): Quote {
  const subtotal = input.lines.reduce((s, l) => s + l.unitPrice * l.qty, 0);
  const tier = input.customer ? tierOf(input.customer.points, input.settings) : null;
  const tierRate = tier === "silver" ? input.settings?.loyaltySilverDiscount ?? TIER_OFF.silver : tier === "gold" ? input.settings?.loyaltyGoldDiscount ?? TIER_OFF.gold : tier === "platinum" ? input.settings?.loyaltyPlatinumDiscount ?? TIER_OFF.platinum : 0;
  const tierDiscount = Math.round((subtotal * tierRate) / 100);
  const afterTier = subtotal - tierDiscount;
  let codeDiscount = 0;
  let codeError = "";
  let discount: DiscountCode | null = null;
  const raw = input.code.trim();
  if (raw) {
    const found = input.discounts.find((d) => d.code.toLowerCase() === raw.toLowerCase());
    const now = input.now ?? new Date();
    if (!found) codeError = "کد پیدا نشد";
    else if (found.startsAt && now < new Date(found.startsAt)) codeError = "هنوز فعال نشده";
    else if (found.endsAt && now > new Date(found.endsAt + "T23:59:59")) codeError = "منقضی شده";
    else if (found.maxUses > 0 && found.usedCount >= found.maxUses) codeError = "سقف استفاده تمام شده";
    else if (afterTier < found.minOrder) codeError = `حداقل سفارش ${money(found.minOrder)}`;
    else if (found.audience === "club" && !input.customer) codeError = "فقط اعضای باشگاه";
    else if (
      found.audience !== "all" &&
      found.audience !== "club" &&
      (!input.customer || tierOf(input.customer.points) !== found.audience)
    )
      codeError = "برای سطح عضویت شما نیست";
    else if (found.perCustomer > 0 && (input.usesSoFar ?? 0) >= found.perCustomer)
      codeError = "سقف استفاده این مشتری پر شده";
    else {
      discount = found;
      codeDiscount =
        found.type === "percent"
          ? Math.min(found.maxDiscount || Infinity, Math.round((afterTier * found.value) / 100))
          : Math.min(afterTier, found.value);
    }
  }
  const afterCode = afterTier - codeDiscount;
  let pointsDiscount = 0;
  let pointsError = "";
  const want = Math.max(0, Math.floor(input.pointsToUse));
  if (want > 0) {
    if (!input.customer) pointsError = "اول مشتری را انتخاب کنید";
    else if (want > input.customer.points) pointsError = "امتیاز کافی نیست";
    else {
      pointsDiscount = Math.min(afterCode, want * input.tomanPerPoint);
    }
  }
  const taxable = Math.max(0, afterCode - pointsDiscount);
  const tax = input.taxEnabled === false ? 0 : Math.round((taxable * input.taxPercent) / 100);
  const total = taxable + tax;
  return {
    subtotal,
    tierDiscount,
    codeDiscount,
    pointsDiscount,
    tax,
    total,
    codeError,
    pointsError,
    discount,
  };
}

export function customerCodeUses(orders: Order[], customerId: string, code: string) {
  return orders.filter(
    (o) =>
      o.customerId === customerId &&
      o.status !== "cancelled" &&
      o.discountCode.toLowerCase() === code.toLowerCase(),
  ).length;
}
