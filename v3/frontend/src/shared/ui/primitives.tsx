import type { ButtonHTMLAttributes, HTMLAttributes, ReactNode } from "react";
import type { LucideIcon } from "lucide-react";

type ButtonVariant = "primary" | "secondary" | "ghost" | "danger";
type ButtonSize = "sm" | "md" | "icon";

export type ButtonProps = ButtonHTMLAttributes<HTMLButtonElement> & {
  variant?: ButtonVariant;
  size?: ButtonSize;
};

export function Button({
  variant = "secondary",
  size = "md",
  className = "",
  type = "button",
  ...props
}: ButtonProps) {
  return (
    <button
      {...props}
      type={type}
      className={`ui-button ui-button--${variant} ui-button--${size} ${className}`.trim()}
    />
  );
}

export type SurfaceProps = HTMLAttributes<HTMLElement> & {
  padding?: "none" | "sm" | "md" | "lg";
};

export function Surface({
  padding = "md",
  className = "",
  ...props
}: SurfaceProps) {
  return (
    <section
      {...props}
      className={`ui-surface ui-surface--${padding} ${className}`.trim()}
    />
  );
}

export function PageHeader({
  eyebrow,
  title,
  description,
  action,
}: {
  eyebrow?: string;
  title: string;
  description?: string;
  action?: ReactNode;
}) {
  return (
    <div className="ui-page-header">
      <div className="ui-page-header__copy">
        {eyebrow ? <span className="ui-eyebrow">{eyebrow}</span> : null}
        <h1>{title}</h1>
        {description ? <p>{description}</p> : null}
      </div>
      {action ? <div className="ui-page-header__action">{action}</div> : null}
    </div>
  );
}

export type StatusVariant = "neutral" | "success" | "warning" | "danger" | "info";

export function StatusBadge({
  children,
  variant = "neutral",
  className = "",
}: {
  children: ReactNode;
  variant?: StatusVariant;
  className?: string;
}) {
  return (
    <span className={`ui-status ui-status--${variant} ${className}`.trim()}>
      <span className="ui-status__dot" aria-hidden="true" />
      {children}
    </span>
  );
}

export function Notice({
  children,
  variant = "info",
}: {
  children: ReactNode;
  variant?: "info" | "warning";
}) {
  return (
    <div className={`ui-notice ui-notice--${variant}`} role="note">
      <span className="ui-notice__dot" aria-hidden="true" />
      <span>{children}</span>
    </div>
  );
}

export function EmptyState({
  icon: Icon,
  eyebrow = "وضعیت ماژول",
  title,
  description,
  notice,
}: {
  icon: LucideIcon;
  eyebrow?: string;
  title: string;
  description: string;
  notice?: ReactNode;
}) {
  return (
    <section className="ui-empty-state" aria-live="polite">
      <div className="ui-empty-state__icon"><Icon size={22} aria-hidden="true" /></div>
      <span className="ui-eyebrow">{eyebrow}</span>
      <h2>{title}</h2>
      <p>{description}</p>
      {notice ? <Notice variant="warning">{notice}</Notice> : null}
    </section>
  );
}
