import React from "react";
import { DashboardTimelinePoint } from "../../types";
import { cn } from "../../lib/utils";

interface SalesTimelineProps {
  points: DashboardTimelinePoint[];
  metric: "revenue" | "orders";
  currency?: string;
  className?: string;
}

export function SalesTimeline({ points, metric, currency = "USD", className }: SalesTimelineProps) {
  const maxValue = Math.max(
    ...points.map((point) => (metric === "revenue" ? point.revenue_amount : point.orders_count)),
    1,
  );

  return (
    <div className={cn("space-y-4", className)}>
      <div className="grid h-64 grid-cols-[repeat(auto-fit,minmax(12px,1fr))] items-end gap-2">
        {points.map((point) => {
          const value = metric === "revenue" ? point.revenue_amount : point.orders_count;
          const heightPercent = Math.max((value / maxValue) * 100, value > 0 ? 8 : 2);

          return (
            <div key={point.date} className="group flex h-full flex-col items-center justify-end gap-2">
              <div className="pointer-events-none min-h-[52px] opacity-0 transition-opacity group-hover:opacity-100">
                <div className="rounded-md border bg-background px-2 py-1 text-center text-[11px] shadow-sm">
                  <div className="font-medium text-foreground">
                    {metric === "revenue" ? `${currency} ${point.revenue_amount.toFixed(2)}` : `${point.orders_count} orders`}
                  </div>
                  {point.free_claims_count > 0 ? (
                    <div className="text-muted-foreground">{point.free_claims_count} free</div>
                  ) : null}
                </div>
              </div>
              <div className="flex w-full flex-1 items-end">
                <div
                  className="w-full rounded-t-md border border-b-0 bg-muted/80 transition-colors group-hover:bg-foreground/10"
                  style={{ height: `${heightPercent}%` }}
                />
              </div>
              <span className="text-[10px] text-muted-foreground">{point.label}</span>
            </div>
          );
        })}
      </div>
    </div>
  );
}
