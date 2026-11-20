import { create } from "zustand";
import {
  customerCodeUses,
  quoteOrder,
  tierOf,
  uid,
  type CartLine,
  type Category,
  type Customer,
  type DiscountCode,
  type Expense,
  type Ingredient,
  type Order,
  type OrderStatus,
  type PosState,
  type Product,
  type Role,
  type Settings,
  type SmsLog,
  type SmsTemplate,
} from "./model";

function seed(): PosState {
  return {
    categories: [],
    products: [],
    ingredients: [],
    customers: [],
    discounts: [],
    orders: [],
    expenses: [],
    templates: [],
    smsLogs: [],
    settings: {
      shopName: "",
      address: "",
      phone: "",
      taxPercent: 0,
      taxEnabled: true,
      stockPolicy: "warn_and_allow",
      pointsPerToman: 100000,
      tomanPerPoint: 1000,
      paperWidth: 80,
      autoPrint: false,
      invoiceSmsEnabledDefault: false,
      loyaltySilverPoints: 500,
      loyaltyGoldPoints: 1500,
      loyaltyPlatinumPoints: 4000,
      loyaltySilverDiscount: 3,
      loyaltyGoldDiscount: 5,
      loyaltyPlatinumDiscount: 8,
      printCustomer: true,
      printKitchen: true,
      headerNote: "",
      footerNote: "",
      smsUser: "",
      smsKey: "",
      smsFrom: "",
      birthdaySmsPrompt: "برای ثبت تاریخ تولد در باشگاه مشتریان، لطفاً پاسخ دهید: تولد ۱۳۷۰/۰۵/۱۲",
      role: "manager",
    },
    nextOrder: 1,
  };
}

type Actions = {
  setRole: (role: Role) => void;
  patchSettings: (p: Partial<Settings>) => void;
  saveCategory: (c: Category) => void;
  removeCategory: (id: string) => void;
  saveProduct: (p: Product) => void;
  removeProduct: (id: string) => void;
  saveIngredient: (i: Ingredient) => void;
  adjustStock: (id: string, delta: number) => void;
  saveCustomer: (c: Customer) => void;
  saveDiscount: (d: DiscountCode) => void;
  removeDiscount: (id: string) => void;
  addExpense: (e: Omit<Expense, "id"> & { id?: string }) => void;
  saveExpense: (e: Expense) => void;
  removeExpense: (id: string) => void;
  saveTemplate: (t: SmsTemplate) => void;
  placeOrder: (input: {
    lines: CartLine[];
    customer: Customer | null;
    code: string;
    pointsToUse: number;
    taxEnabled: boolean;
    payment: Order["payment"];
    guestName: string;
    guestPhone: string;
    serverOrder?: { id: string; number: number; createdAt?: string };
  }) => { ok: true; order: Order } | { ok: false; error: string };
  setStatus: (id: string, status: OrderStatus) => Order | null;
  logSms: (log: SmsLog) => void;
};

export const usePos = create<PosState & Actions>()(
    (set, get) => ({
      ...seed(),
      setRole: (role) => set((s) => ({ settings: { ...s.settings, role } })),
      patchSettings: (p) => set((s) => ({ settings: { ...s.settings, ...p, role: s.settings.role } })),
      saveCategory: (c) =>
        set((s) => ({
          categories: s.categories.some((x) => x.id === c.id)
            ? s.categories.map((x) => (x.id === c.id ? c : x))
            : [...s.categories, c],
        })),
      removeCategory: (id) => set((s) => ({ categories: s.categories.filter((c) => c.id !== id) })),
      saveProduct: (p) =>
        set((s) => ({
          products: s.products.some((x) => x.id === p.id)
            ? s.products.map((x) => (x.id === p.id ? p : x))
            : [...s.products, p],
        })),
      removeProduct: (id) => set((s) => ({ products: s.products.filter((p) => p.id !== id) })),
      saveIngredient: (i) =>
        set((s) => ({
          ingredients: s.ingredients.some((x) => x.id === i.id)
            ? s.ingredients.map((x) => (x.id === i.id ? i : x))
            : [...s.ingredients, i],
        })),
      adjustStock: (id, delta) =>
        set((s) => ({
          ingredients: s.ingredients.map((i) =>
            i.id === id ? { ...i, stock: Math.max(0, i.stock + delta) } : i,
          ),
        })),
      saveCustomer: (c) =>
        set((s) => ({
          customers: s.customers.some((x) => x.id === c.id)
            ? s.customers.map((x) => (x.id === c.id ? c : x))
            : [...s.customers, c],
        })),
      saveDiscount: (d) =>
        set((s) => ({
          discounts: s.discounts.some((x) => x.id === d.id)
            ? s.discounts.map((x) => (x.id === d.id ? d : x))
            : [...s.discounts, { ...d, code: d.code.toUpperCase() }],
        })),
      removeDiscount: (id) => set((s) => ({ discounts: s.discounts.filter((d) => d.id !== id) })),
      addExpense: (e) => set((s) => ({ expenses: [{ ...e, id: e.id ?? uid("ex") }, ...s.expenses] })),
      saveExpense: (e) => set((s) => ({ expenses: s.expenses.some((x) => x.id === e.id) ? s.expenses.map((x) => x.id === e.id ? e : x) : [e, ...s.expenses] })),
      removeExpense: (id) => set((s) => ({ expenses: s.expenses.filter((e) => e.id !== id) })),
      saveTemplate: (t) =>
        set((s) => ({ templates: s.templates.map((x) => (x.event === t.event ? t : x)) })),
      placeOrder: (input) => {
        const s = get();
        if (!input.lines.length) return { ok: false, error: "سبد خالی است" };
        const uses =
          input.customer && input.code
            ? customerCodeUses(s.orders, input.customer.id, input.code)
            : 0;
        const q = quoteOrder({
          lines: input.lines,
          customer: input.customer,
          code: input.code,
          discounts: s.discounts,
          pointsToUse: input.pointsToUse,
          taxPercent: s.settings.taxPercent,
          taxEnabled: input.taxEnabled,
          tomanPerPoint: s.settings.tomanPerPoint,
          usesSoFar: uses,
          settings: s.settings,
        });
        if (input.code && q.codeError) return { ok: false, error: q.codeError };
        if (input.pointsToUse > 0 && q.pointsError) return { ok: false, error: q.pointsError };
        const phone = input.customer?.phone || input.guestPhone;
        const name = input.customer?.name || input.guestName || "مشتری";
        let customerId = input.customer?.id ?? null;
        let customers = s.customers;
        if (!customerId && phone) {
          const existing = customers.find((c) => c.phone === phone);
          if (existing) customerId = existing.id;
          else {
            const created: Customer = {
              id: uid("cu"),
              name,
              phone,
              points: 0,
              birthday: "",
              totalSpent: 0,
              visits: 0,
              createdAt: new Date().toISOString(),
            };
            customers = [created, ...customers];
            customerId = created.id;
          }
        }
        const earned = customerId ? Math.floor(q.total / s.settings.pointsPerToman) : 0;
        const spentPts = q.pointsDiscount > 0 ? Math.ceil(q.pointsDiscount / s.settings.tomanPerPoint) : 0;
        const order: Order = {
          id: input.serverOrder?.id ?? uid("or"),
          number: input.serverOrder?.number ?? s.nextOrder,
          createdAt: input.serverOrder?.createdAt ?? new Date().toISOString(),
          status: "new",
          lines: input.lines,
          subtotal: q.subtotal,
          tierDiscount: q.tierDiscount,
          codeDiscount: q.codeDiscount,
          pointsDiscount: q.pointsDiscount,
          tax: q.tax,
          total: q.total,
          payment: input.payment,
          customerId,
          customerName: name,
          customerPhone: phone,
          discountCode: q.discount?.code ?? "",
          pointsUsed: spentPts,
          pointsEarned: earned,
        };
        const ingredients = s.ingredients.map((ing) => {
          let need = 0;
          for (const line of input.lines) {
            const product = s.products.find((p) => p.id === line.productId);
            const rec = product?.recipe.find((r) => r.ingredientId === ing.id);
            if (rec) need += rec.qty * line.qty;
            if (line.optionLabels.some((l) => l.includes("پنیر")) && ing.id === "i3") need += line.qty;
          }
          return { ...ing, stock: +(ing.stock - need).toFixed(2) };
        });
        customers = customers.map((c) =>
          c.id === customerId
            ? {
                ...c,
                name: c.name || name,
                points: Math.max(0, c.points - spentPts + earned),
                totalSpent: c.totalSpent + q.total,
                visits: c.visits + 1,
              }
            : c,
        );
        const discounts = s.discounts.map((d) =>
          q.discount && d.id === q.discount.id ? { ...d, usedCount: d.usedCount + 1 } : d,
        );
        set({
          orders: [order, ...s.orders],
          nextOrder: Math.max(s.nextOrder + 1, order.number + 1),
          ingredients,
          customers,
          discounts,
        });
        return { ok: true, order };
      },
      setStatus: (id, status) => {
        const prev = get().orders.find((o) => o.id === id) ?? null;
        set((s) => ({
          orders: s.orders.map((o) => (o.id === id ? { ...o, status } : o)),
        }));
        return prev ? { ...prev, status } : null;
      },
      logSms: (log) =>
        set((s) => ({
          smsLogs: [log, ...s.smsLogs.filter((entry) => entry.id !== log.id)].slice(0, 500),
        })),
    }),
);

export function lowStock(list: Ingredient[]) {
  return list.filter((i) => i.stock <= i.minStock);
}

export { tierOf };
