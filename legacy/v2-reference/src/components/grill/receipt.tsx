import { money, type Order, type Settings } from "@/lib/pos/model";
import { useAppearance } from "@/lib/appearance";
import { ReceiptQr } from "@/components/grill/receipt-qr";

const pay: Record<Order["payment"], string> = {
  cash: "نقد",
  card: "کارت",
  online: "آنلاین",
};

export function ReceiptView({
  order,
  settings,
  kind,
}: {
  order: Order;
  settings: Settings;
  kind: "customer" | "kitchen";
}) {
  const width = settings.paperWidth === 58 ? "max-w-56" : "max-w-80";
  const { settings: appearance } = useAppearance();
  const qrPayload = [
    `${settings.shopName} | فاکتور ${order.number}`,
    `تاریخ: ${new Date(order.createdAt).toLocaleString("fa-IR", { timeZone: "Asia/Tehran" })}`,
    ...order.lines.map(line => `${line.name} × ${line.qty} — ${money(line.unitPrice * line.qty)}`),
    `جمع: ${money(order.subtotal)}`,
    `تخفیف: ${money(order.tierDiscount + order.codeDiscount + order.pointsDiscount)}`,
    `مالیات: ${money(order.tax)}`,
    `قابل پرداخت: ${money(order.total)}`,
    `پرداخت: ${pay[order.payment]}`,
  ].join("\n");
  return (
    <article className={`mx-auto bg-surface p-3 text-ink ${width}`} dir="rtl">
      <header className="border-b border-dashed border-line pb-2 text-center">
        {appearance.logoUrl ? <img src={appearance.logoUrl} alt="لوگوی فروشگاه" className="mx-auto mb-2 max-h-12 max-w-32 object-contain" /> : null}
        <p className="text-lg font-bold">{settings.shopName}</p>
        {kind === "customer" ? (
          <>
            <p className="text-xs text-muted">{settings.headerNote}</p>
            <p className="text-xs">{settings.address}</p>
            <p className="text-xs">{settings.phone}</p>
          </>
        ) : (
          <p className="text-sm font-semibold">فیش آشپزخانه</p>
        )}
      </header>
      <p className="mt-2 flex justify-between text-sm">
        <span>سفارش {order.number.toLocaleString("fa-IR")}</span>
        <span>{new Date(order.createdAt).toLocaleString("fa-IR", { timeZone: "Asia/Tehran" })}</span>
      </p>
      <p className="text-sm">
        {order.customerName}
        {order.customerPhone ? ` — ${order.customerPhone}` : ""}
      </p>
      <ul className="my-2 space-y-1 border-y border-dashed border-line py-2 text-sm">
        {order.lines.map((l) => (
          <li key={l.key}>
            <div className="flex justify-between gap-2">
              <span>
                {l.qty.toLocaleString("fa-IR")} × {l.name}
              </span>
              {kind === "customer" ? <span>{money(l.unitPrice * l.qty)}</span> : null}
            </div>
            {l.optionLabels.length ? (
              <p className="text-xs text-muted">{l.optionLabels.join("، ")}</p>
            ) : null}
            {l.note ? <p className="text-xs">یادداشت: {l.note}</p> : null}
          </li>
        ))}
      </ul>
      {kind === "customer" ? (
        <dl className="space-y-1 text-sm">
          <Row k="جمع" v={money(order.subtotal)} />
          {order.tierDiscount ? <Row k="تخفیف سطح عضویت" v={money(order.tierDiscount)} /> : null}
          {order.codeDiscount ? (
            <Row k={`کد ${order.discountCode}`} v={money(order.codeDiscount)} />
          ) : null}
          {order.pointsDiscount ? <Row k="امتیاز" v={money(order.pointsDiscount)} /> : null}
          {order.tax ? <Row k="مالیات" v={money(order.tax)} /> : null}
          <div className="flex justify-between pt-1 text-base font-bold">
            <span>قابل پرداخت</span>
            <span>{money(order.total)}</span>
          </div>
          <Row k="پرداخت" v={pay[order.payment]} />
          {order.pointsEarned ? (
            <Row k="امتیاز این خرید" v={order.pointsEarned.toLocaleString("fa-IR")} />
          ) : null}
        </dl>
      ) : null}
      {kind === "customer" ? (
        <>
          <p className="mt-3 text-center text-xs">{settings.footerNote}</p>
          <div className="mt-3 flex flex-col items-center gap-1">
            <ReceiptQr value={qrPayload} size={settings.paperWidth === 58 ? 88 : 112} />
            <p className="text-center text-[10px] text-muted">برای مشاهده ریز فاکتور، کد را اسکن کنید</p>
          </div>
        </>
      ) : null}
    </article>
  );
}

function Row({ k, v }: { k: string; v: string }) {
  return (
    <div className="flex justify-between gap-3">
      <dt className="text-muted">{k}</dt>
      <dd>{v}</dd>
    </div>
  );
}
