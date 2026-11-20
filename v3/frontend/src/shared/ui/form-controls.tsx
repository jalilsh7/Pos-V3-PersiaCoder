import { useId } from "react";
import type {
  HTMLAttributes,
  InputHTMLAttributes,
  ReactNode,
  SelectHTMLAttributes,
} from "react";

type FieldFeedback = {
  label: string;
  hint?: string;
  error?: string;
  id?: string;
};

function FieldMessages({
  hint,
  error,
  hintId,
  errorId,
}: {
  hint?: string;
  error?: string;
  hintId: string;
  errorId: string;
}) {
  return (
    <>
      {hint ? <p className="ui-field__hint" id={hintId}>{hint}</p> : null}
      {error ? <p className="ui-field__error" id={errorId} role="alert">{error}</p> : null}
    </>
  );
}

export type TextFieldProps = Omit<InputHTMLAttributes<HTMLInputElement>, "id"> & FieldFeedback;

export function TextField({
  label,
  hint,
  error,
  id,
  className = "",
  required,
  ...inputProps
}: TextFieldProps) {
  const generatedId = useId();
  const fieldId = id ?? generatedId;
  const hintId = `${fieldId}-hint`;
  const errorId = `${fieldId}-error`;
  const describedBy = [hint ? hintId : null, error ? errorId : null].filter(Boolean).join(" ") || undefined;

  return (
    <div className="ui-field">
      <label className="ui-field__label" htmlFor={fieldId}>
        {label}{required ? <span className="ui-field__required" aria-hidden="true"> *</span> : null}
      </label>
      <input
        {...inputProps}
        id={fieldId}
        required={required}
        className={`ui-input ${error ? "has-error" : ""} ${className}`.trim()}
        aria-invalid={Boolean(error)}
        aria-describedby={describedBy}
      />
      <FieldMessages hint={hint} error={error} hintId={hintId} errorId={errorId} />
    </div>
  );
}

export type SelectOption = {
  value: string;
  label: string;
  disabled?: boolean;
};

export type SelectFieldProps = Omit<SelectHTMLAttributes<HTMLSelectElement>, "id"> &
  FieldFeedback & {
    options: SelectOption[];
    placeholder?: string;
  };

export function SelectField({
  label,
  hint,
  error,
  id,
  options,
  placeholder = "انتخاب کنید",
  className = "",
  required,
  ...selectProps
}: SelectFieldProps) {
  const generatedId = useId();
  const fieldId = id ?? generatedId;
  const hintId = `${fieldId}-hint`;
  const errorId = `${fieldId}-error`;
  const describedBy = [hint ? hintId : null, error ? errorId : null].filter(Boolean).join(" ") || undefined;

  return (
    <div className="ui-field">
      <label className="ui-field__label" htmlFor={fieldId}>
        {label}{required ? <span className="ui-field__required" aria-hidden="true"> *</span> : null}
      </label>
      <select
        {...selectProps}
        id={fieldId}
        required={required}
        className={`ui-select ${error ? "has-error" : ""} ${className}`.trim()}
        aria-invalid={Boolean(error)}
        aria-describedby={describedBy}
      >
        <option value="" disabled={required}>{placeholder}</option>
        {options.map((option) => (
          <option key={option.value} value={option.value} disabled={option.disabled}>
            {option.label}
          </option>
        ))}
      </select>
      <FieldMessages hint={hint} error={error} hintId={hintId} errorId={errorId} />
    </div>
  );
}

export type CheckboxFieldProps = Omit<InputHTMLAttributes<HTMLInputElement>, "id" | "type"> &
  FieldFeedback & {
    description?: string;
  };

export function CheckboxField({
  label,
  hint,
  error,
  description,
  id,
  className = "",
  ...inputProps
}: CheckboxFieldProps) {
  const generatedId = useId();
  const fieldId = id ?? generatedId;
  const hintText = hint ?? description;
  const hintId = `${fieldId}-hint`;
  const errorId = `${fieldId}-error`;
  const describedBy = [hintText ? hintId : null, error ? errorId : null].filter(Boolean).join(" ") || undefined;

  return (
    <div className="ui-checkbox-field">
      <label className="ui-checkbox-field__label" htmlFor={fieldId}>
        <input
          {...inputProps}
          id={fieldId}
          type="checkbox"
          className={`ui-checkbox ${className}`.trim()}
          aria-invalid={Boolean(error)}
          aria-describedby={describedBy}
        />
        <span>{label}</span>
      </label>
      <FieldMessages hint={hintText} error={error} hintId={hintId} errorId={errorId} />
    </div>
  );
}

export function FilterBar({
  children,
  className = "",
  ...props
}: HTMLAttributes<HTMLDivElement> & { children: ReactNode }) {
  return (
    <div {...props} className={`ui-filter-bar ${className}`.trim()}>
      {children}
    </div>
  );
}
