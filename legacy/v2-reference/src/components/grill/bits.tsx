import type { ReactNode } from "react";

export function Field({
  label,
  children,
}: {
  label: string;
  children: ReactNode;
}) {
  return (
    <label className="block space-y-1 text-sm">
      <span className="text-muted">{label}</span>
      {children}
    </label>
  );
}

export const inputCls =
  "w-full rounded-xl border border-line bg-surface px-3 py-3 text-ink outline-none transition-all duration-200 placeholder:text-muted/70 hover:border-leaf/40 focus:border-chili focus:ring-4 focus:ring-chili/10";

export function Btn({
  children,
  onClick,
  tone = "chili",
  type = "button",
  disabled,
  className = "",
}: {
  children: ReactNode;
  onClick?: () => void;
  tone?: "chili" | "leaf" | "ghost" | "ink";
  type?: "button" | "submit";
  disabled?: boolean;
  className?: string;
}) {
  const map = {
    chili: "bg-chili text-chili-ink",
    leaf: "bg-leaf text-leaf-ink",
    ghost: "bg-surface text-ink border border-line",
    ink: "bg-chili text-chili-ink",
  } as const;
  return (
    <button
      type={type}
      disabled={disabled}
      onClick={onClick}
      className={`pos-btn inline-flex min-h-11 items-center justify-center gap-2 rounded-xl px-4 py-2.5 text-sm font-bold shadow-sm transition-all duration-200 hover:-translate-y-0.5 hover:shadow-md focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-chili/30 disabled:translate-y-0 disabled:opacity-50 ${map[tone]} ${className}`}
    >
      {children}
    </button>
  );
}

export function Card({ children, className = "" }: { children: ReactNode; className?: string }) {
  return (
    <section className={`pos-card min-w-0 rounded-[var(--radius-card)] border border-line bg-surface p-5 shadow-[var(--shadow-card)] transition-shadow duration-200 ${className}`}>
      {children}
    </section>
  );
}

export function PageHeading({ title, description, icon }: { title: string; description?: string; icon?: ReactNode }) {
  return (
    <header className="pos-page-heading min-w-0">
      <div className="pos-page-heading-mark" aria-hidden="true">{icon ?? <span />}</div>
      <div className="min-w-0">
        <h2>{title}</h2>
        {description ? <p>{description}</p> : null}
      </div>
    </header>
  );
}

export function Empty({ text }: { text: string }) {
  return <p className="py-8 text-center text-muted">{text}</p>;
}
