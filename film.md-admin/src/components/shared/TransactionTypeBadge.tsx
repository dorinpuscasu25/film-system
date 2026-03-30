import React from "react";
import { cn } from "../../lib/utils";

interface TransactionTypeBadgeProps {
  type: "purchase" | "refund" | "top_up" | "welcome_bonus" | "adjustment";
  amount: number;
  label?: string;
}

export function TransactionTypeBadge({ type, amount, label }: TransactionTypeBadgeProps) {
  const resolvedLabel = label ?? (
    type === "purchase"
      ? amount === 0
        ? "Acces gratuit"
        : "Cumpărare"
      : type === "refund"
        ? "Refund"
        : type === "top_up"
          ? "Alimentare"
          : type === "welcome_bonus"
            ? "Credit de bun venit"
            : "Ajustare"
  );

  const className = type === "purchase"
    ? amount === 0
      ? "border-blue-200 bg-blue-50 text-blue-700"
      : "border-emerald-200 bg-emerald-50 text-emerald-700"
    : type === "refund"
      ? "border-rose-200 bg-rose-50 text-rose-700"
      : type === "top_up"
        ? "border-sky-200 bg-sky-50 text-sky-700"
        : "border-slate-200 bg-slate-50 text-slate-700";

  return (
    <span
      className={cn(
        "inline-flex items-center rounded-full border px-2.5 py-1 text-xs font-medium",
        className,
      )}
    >
      {resolvedLabel}
    </span>
  );
}
