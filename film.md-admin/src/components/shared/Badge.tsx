import React from "react";
import { Badge as UiBadge } from "../ui/badge";
import { cn } from "../../lib/utils";

type BadgeVariant =
  | "draft"
  | "ready"
  | "published"
  | "archived"
  | "featured"
  | "free"
  | "paid"
  | "active"
  | "inactive"
  | "scheduled"
  | "suspended"
  | "completed"
  | "refunded"
  | "expired";

interface BadgeProps {
  variant: BadgeVariant;
  children: React.ReactNode;
  className?: string;
}

const variantClasses: Record<BadgeVariant, string> = {
  draft: "border-slate-200 bg-slate-50 text-slate-700",
  ready: "border-amber-200 bg-amber-50 text-amber-700",
  published: "border-emerald-200 bg-emerald-50 text-emerald-700",
  archived: "border-rose-200 bg-rose-50 text-rose-700",
  featured: "border-sky-200 bg-sky-50 text-sky-700",
  free: "border-blue-200 bg-blue-50 text-blue-700",
  paid: "border-orange-200 bg-orange-50 text-orange-700",
  active: "border-emerald-200 bg-emerald-50 text-emerald-700",
  inactive: "border-slate-200 bg-slate-50 text-slate-700",
  scheduled: "border-violet-200 bg-violet-50 text-violet-700",
  suspended: "border-rose-200 bg-rose-50 text-rose-700",
  completed: "border-emerald-200 bg-emerald-50 text-emerald-700",
  refunded: "border-slate-200 bg-slate-50 text-slate-700",
  expired: "border-slate-200 bg-slate-50 text-slate-700",
};

export function Badge({ variant, children, className }: BadgeProps) {
  return (
    <UiBadge variant="outline" className={cn("font-medium", variantClasses[variant], className)}>
      {children}
    </UiBadge>
  );
}
