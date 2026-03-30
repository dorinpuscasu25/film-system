import React from "react";
import { TrendingDownIcon, TrendingUpIcon } from "lucide-react";
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from "../ui/card";

interface StatsCardProps {
  title: string;
  value: string | number;
  icon: React.ElementType;
  trend?: number;
  trendLabel?: string;
  colorClass?: string;
}

export function StatsCard({
  title,
  value,
  icon: Icon,
  trend,
  trendLabel,
  colorClass = "bg-muted text-foreground",
}: StatsCardProps) {
  return (
    <Card>
      <CardHeader className="flex flex-row items-start justify-between space-y-0 pb-3">
        <div className="space-y-1">
          <CardDescription>{title}</CardDescription>
          <CardTitle>{value}</CardTitle>
        </div>
        <div className={`rounded-md border p-2 ${colorClass}`}>
          <Icon className="h-4 w-4" />
        </div>
      </CardHeader>
      <CardContent className="pt-0">
        {trend !== undefined ? (
          <div className="flex items-center gap-2 text-sm text-muted-foreground">
            {trend >= 0 ? (
              <TrendingUpIcon className="h-4 w-4 text-emerald-500" />
            ) : (
              <TrendingDownIcon className="h-4 w-4 text-rose-500" />
            )}
            <span className={trend >= 0 ? "font-medium text-emerald-600" : "font-medium text-rose-600"}>
              {trend > 0 ? "+" : ""}
              {trend}%
            </span>
            {trendLabel ? <span>{trendLabel}</span> : null}
          </div>
        ) : (
          <p className="text-sm text-muted-foreground">Instantaneu operațional live</p>
        )}
      </CardContent>
    </Card>
  );
}
