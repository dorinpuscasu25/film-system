import { useEffect, useState } from "react";
import { adminApi } from "../../lib/api";
import type { ContentFinancialsResponse } from "../../types";
import { Card, CardContent, CardHeader, CardTitle } from "../ui/card";

interface ContentCostsTabProps {
  contentId: number;
}

const formatMdl = (value: number): string => {
  return new Intl.NumberFormat("ro-MD", {
    style: "currency",
    currency: "MDL",
    minimumFractionDigits: 2,
    maximumFractionDigits: 2,
  }).format(value);
};

const formatUsd = (value: number): string => {
  return new Intl.NumberFormat("en-US", {
    style: "currency",
    currency: "USD",
    minimumFractionDigits: 2,
    maximumFractionDigits: 4,
  }).format(value);
};

const monthLabel = (yyyymm: string): string => {
  const [year, month] = yyyymm.split("-");
  if (!year || !month) return yyyymm;
  const date = new Date(Number(year), Number(month) - 1, 1);
  return date.toLocaleDateString("ro-MD", { month: "long", year: "numeric" });
};

export function ContentCostsTab({ contentId }: ContentCostsTabProps) {
  const [data, setData] = useState<ContentFinancialsResponse | null>(null);
  const [isLoading, setIsLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);

  useEffect(() => {
    let active = true;
    setIsLoading(true);
    setError(null);

    adminApi
      .getContentFinancials(contentId, 12)
      .then((response) => {
        if (active) setData(response);
      })
      .catch((err: unknown) => {
        if (active) {
          setError(err instanceof Error ? err.message : "Nu am putut încărca costurile.");
        }
      })
      .finally(() => {
        if (active) setIsLoading(false);
      });

    return () => {
      active = false;
    };
  }, [contentId]);

  if (isLoading) {
    return (
      <Card>
        <CardContent className="py-10 text-center text-sm text-muted-foreground">
          Se încarcă datele financiare...
        </CardContent>
      </Card>
    );
  }

  if (error) {
    return (
      <Card>
        <CardContent className="py-10 text-center text-sm text-destructive">{error}</CardContent>
      </Card>
    );
  }

  if (!data) return null;

  const totalCostUsd =
    data.totals.storage_cost_usd + data.totals.delivery_cost_usd + data.totals.drm_cost_usd;

  return (
    <div className="space-y-4">
      {/* Top widgets */}
      <div className="grid grid-cols-2 gap-4 lg:grid-cols-4">
        <Card>
          <CardHeader className="pb-2">
            <CardTitle className="text-xs font-medium text-muted-foreground">
              Cost storage
            </CardTitle>
          </CardHeader>
          <CardContent>
            <p className="text-2xl font-bold">{formatMdl(data.totals_mdl.storage_cost)}</p>
            <p className="text-xs text-muted-foreground">{formatUsd(data.totals.storage_cost_usd)}</p>
          </CardContent>
        </Card>

        <Card>
          <CardHeader className="pb-2">
            <CardTitle className="text-xs font-medium text-muted-foreground">
              Cost livrare (CDN)
            </CardTitle>
          </CardHeader>
          <CardContent>
            <p className="text-2xl font-bold">{formatMdl(data.totals_mdl.delivery_cost)}</p>
            <p className="text-xs text-muted-foreground">{formatUsd(data.totals.delivery_cost_usd)}</p>
          </CardContent>
        </Card>

        <Card>
          <CardHeader className="pb-2">
            <CardTitle className="text-xs font-medium text-muted-foreground">
              Cost DRM
            </CardTitle>
          </CardHeader>
          <CardContent>
            <p className="text-2xl font-bold">{formatMdl(data.totals_mdl.drm_cost)}</p>
            <p className="text-xs text-muted-foreground">{formatUsd(data.totals.drm_cost_usd)}</p>
          </CardContent>
        </Card>

        <Card className={data.totals_mdl.profit >= 0 ? "border-emerald-500/40" : "border-destructive/40"}>
          <CardHeader className="pb-2">
            <CardTitle className="text-xs font-medium text-muted-foreground">
              Profit total
            </CardTitle>
          </CardHeader>
          <CardContent>
            <p
              className={`text-2xl font-bold ${
                data.totals_mdl.profit >= 0 ? "text-emerald-500" : "text-destructive"
              }`}
            >
              {formatMdl(data.totals_mdl.profit)}
            </p>
            <p className="text-xs text-muted-foreground">
              Venit: {formatMdl(data.totals_mdl.revenue)} · Cost: {formatMdl(data.totals_mdl.total_cost)}
            </p>
          </CardContent>
        </Card>
      </div>

      {/* Sales summary */}
      <Card>
        <CardHeader className="pb-3">
          <CardTitle className="text-base">Sumar vânzări</CardTitle>
        </CardHeader>
        <CardContent className="space-y-2 text-sm">
          <div className="flex justify-between">
            <span className="text-muted-foreground">Total cumpărări active</span>
            <span className="font-mono font-medium">{data.sales_count}</span>
          </div>
          <div className="flex justify-between">
            <span className="text-muted-foreground">Curs USD/MDL aplicat</span>
            <span className="font-mono font-medium">1 USD = {data.usd_to_mdl_rate.toFixed(2)} MDL</span>
          </div>
          {data.offers.length > 0 && (
            <div className="mt-3 border-t pt-3">
              <p className="mb-2 text-xs font-medium uppercase text-muted-foreground">Oferte active</p>
              <ul className="space-y-1">
                {data.offers.map((offer) => (
                  <li key={offer.id} className="flex justify-between">
                    <span>
                      {offer.quality} {offer.is_active ? "" : "(inactiv)"}
                    </span>
                    <span className="font-mono">
                      {offer.price} {offer.currency}
                    </span>
                  </li>
                ))}
              </ul>
            </div>
          )}
        </CardContent>
      </Card>

      {/* Monthly breakdown */}
      <Card>
        <CardHeader className="pb-3">
          <CardTitle className="text-base">
            Defalcare lunară ({data.months.length} {data.months.length === 1 ? "lună" : "luni"})
          </CardTitle>
        </CardHeader>
        <CardContent>
          {data.months.length === 0 ? (
            <p className="py-6 text-center text-sm text-muted-foreground">
              Nu există încă date de cost pentru acest film. Costurile apar după ce Bunny stats puller rulează prima oară.
            </p>
          ) : (
            <div className="space-y-6">
              {data.months.map((month) => (
                <div key={month.month}>
                  <h4 className="mb-2 text-sm font-semibold capitalize">{monthLabel(month.month)}</h4>
                  <div className="overflow-x-auto rounded border">
                    <table className="w-full text-sm">
                      <thead className="bg-muted/50">
                        <tr>
                          <th className="px-3 py-2 text-left font-medium">Format</th>
                          <th className="px-3 py-2 text-right font-medium">Storage</th>
                          <th className="px-3 py-2 text-right font-medium">Livrare</th>
                          <th className="px-3 py-2 text-right font-medium">DRM</th>
                          <th className="px-3 py-2 text-right font-medium">Venit</th>
                          <th className="px-3 py-2 text-right font-medium">Profit</th>
                        </tr>
                      </thead>
                      <tbody>
                        {month.rows.map((row) => (
                          <tr key={`${month.month}-${row.format_id}`} className="border-t">
                            <td className="px-3 py-2">
                              {row.quality ?? "—"}
                              {row.format_type && row.format_type !== "main" && (
                                <span className="ml-1 text-xs text-muted-foreground">({row.format_type})</span>
                              )}
                              {row.is_locked && (
                                <span className="ml-2 rounded bg-muted px-1.5 py-0.5 text-[10px] uppercase">
                                  locked
                                </span>
                              )}
                            </td>
                            <td className="px-3 py-2 text-right font-mono">{formatUsd(row.storage_cost_usd)}</td>
                            <td className="px-3 py-2 text-right font-mono">{formatUsd(row.delivery_cost_usd)}</td>
                            <td className="px-3 py-2 text-right font-mono">{formatUsd(row.drm_cost_usd)}</td>
                            <td className="px-3 py-2 text-right font-mono">{formatUsd(row.revenue_usd)}</td>
                            <td
                              className={`px-3 py-2 text-right font-mono font-medium ${
                                row.profit_usd >= 0 ? "text-emerald-600" : "text-destructive"
                              }`}
                            >
                              {formatUsd(row.profit_usd)}
                            </td>
                          </tr>
                        ))}
                        <tr className="border-t bg-muted/30 font-semibold">
                          <td className="px-3 py-2">Total {monthLabel(month.month)}</td>
                          <td className="px-3 py-2 text-right font-mono">{formatUsd(month.totals.storage_cost_usd)}</td>
                          <td className="px-3 py-2 text-right font-mono">{formatUsd(month.totals.delivery_cost_usd)}</td>
                          <td className="px-3 py-2 text-right font-mono">{formatUsd(month.totals.drm_cost_usd)}</td>
                          <td className="px-3 py-2 text-right font-mono">{formatUsd(month.totals.revenue_usd)}</td>
                          <td
                            className={`px-3 py-2 text-right font-mono ${
                              month.totals.profit_usd >= 0 ? "text-emerald-600" : "text-destructive"
                            }`}
                          >
                            {formatUsd(month.totals.profit_usd)}
                          </td>
                        </tr>
                      </tbody>
                    </table>
                  </div>
                </div>
              ))}

              {/* Grand total */}
              <div className="rounded border-2 border-foreground/10 bg-muted/40 p-3">
                <div className="grid grid-cols-2 gap-3 text-sm md:grid-cols-6">
                  <div>
                    <p className="text-xs uppercase text-muted-foreground">Total general</p>
                  </div>
                  <div className="text-right">
                    <p className="text-xs uppercase text-muted-foreground">Storage</p>
                    <p className="font-mono font-semibold">{formatUsd(data.totals.storage_cost_usd)}</p>
                  </div>
                  <div className="text-right">
                    <p className="text-xs uppercase text-muted-foreground">Livrare</p>
                    <p className="font-mono font-semibold">{formatUsd(data.totals.delivery_cost_usd)}</p>
                  </div>
                  <div className="text-right">
                    <p className="text-xs uppercase text-muted-foreground">DRM</p>
                    <p className="font-mono font-semibold">{formatUsd(data.totals.drm_cost_usd)}</p>
                  </div>
                  <div className="text-right">
                    <p className="text-xs uppercase text-muted-foreground">Venit</p>
                    <p className="font-mono font-semibold">{formatUsd(data.totals.revenue_usd)}</p>
                  </div>
                  <div className="text-right">
                    <p className="text-xs uppercase text-muted-foreground">Profit</p>
                    <p
                      className={`font-mono font-semibold ${
                        data.totals.profit_usd >= 0 ? "text-emerald-600" : "text-destructive"
                      }`}
                    >
                      {formatUsd(data.totals.profit_usd)}
                    </p>
                  </div>
                </div>
                <div className="mt-2 border-t pt-2 text-right text-xs text-muted-foreground">
                  Total cost (USD): {formatUsd(totalCostUsd)} · Total cost (MDL):{" "}
                  <span className="font-semibold">{formatMdl(data.totals_mdl.total_cost)}</span>
                </div>
              </div>
            </div>
          )}
        </CardContent>
      </Card>
    </div>
  );
}
