import React from "react";
import { cn } from "../../lib/utils";
import { Input } from "../ui/input";
import { Label } from "../ui/label";
import { Switch } from "../ui/switch";
import { Textarea } from "../ui/textarea";

interface FormFieldProps
  extends React.InputHTMLAttributes<HTMLInputElement | HTMLTextAreaElement | HTMLSelectElement> {
  label: string;
  helperText?: string;
  error?: string;
  type?:
    | "text"
    | "email"
    | "password"
    | "number"
    | "date"
    | "file"
    | "textarea"
    | "select"
    | "toggle";
  options?: {
    label: string;
    value: string | number;
  }[];
  rows?: number;
}

export function FormField({
  label,
  helperText,
  error,
  type = "text",
  options = [],
  className = "",
  id,
  ...props
}: FormFieldProps) {
  const fieldId = id || label.toLowerCase().replace(/\s+/g, "-");

  return (
    <div className={cn("space-y-2", className)}>
      {type !== "toggle" ? <Label htmlFor={fieldId}>{label}</Label> : null}

      {type === "textarea" ? (
        <Textarea
          id={fieldId}
          rows={props.rows || 4}
          className={cn(error ? "border-destructive focus-visible:ring-destructive/30" : "")}
          {...(props as React.TextareaHTMLAttributes<HTMLTextAreaElement>)}
        />
      ) : type === "select" ? (
        <select
          id={fieldId}
          className={cn(
            "flex h-10 w-full rounded-md border border-input bg-background px-3 py-2 text-sm ring-offset-background focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2",
            error ? "border-destructive focus-visible:ring-destructive/30" : "",
          )}
          {...(props as React.SelectHTMLAttributes<HTMLSelectElement>)}
        >
          {options.map((opt) => (
            <option key={opt.value} value={opt.value}>
              {opt.label}
            </option>
          ))}
        </select>
      ) : type === "toggle" ? (
        <div className="flex items-center justify-between rounded-lg border p-4">
          <div className="space-y-1">
            <p className="text-sm font-medium">{label}</p>
            {helperText ? <p className="text-sm text-muted-foreground">{helperText}</p> : null}
          </div>
          <Switch
            checked={Boolean(props.checked)}
            onCheckedChange={(checked) => {
              if (props.onChange) {
                const event = {
                  target: {
                    checked,
                    name: props.name,
                  },
                } as React.ChangeEvent<HTMLInputElement>;
                props.onChange(event);
              }
            }}
          />
        </div>
      ) : (
        <Input
          id={fieldId}
          type={type}
          className={cn(error ? "border-destructive focus-visible:ring-destructive/30" : "")}
          {...(props as React.InputHTMLAttributes<HTMLInputElement>)}
        />
      )}

      {error ? <p className="text-sm text-destructive">{error}</p> : null}
      {helperText && !error ? <p className="text-sm text-muted-foreground">{helperText}</p> : null}
    </div>
  );
}
