import { useEffect, useState, type ElementType } from "react";
import { useTranslation } from "react-i18next";
import { EyeIcon, Globe2Icon, MapPinnedIcon, UsersIcon } from "lucide-react";
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from "../components/ui/card";
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from "../components/ui/table";
import { adminApi } from "../lib/api";

export function GeoStats() {
  const { t } = useTranslation();
  const [days, setDays] = useState(30);
  const [data, setData] = useState<Awaited<ReturnType<typeof adminApi.getGeoStats>> | null>(null);
  const [loading, setLoading] = useState(true);

  async function load() {
    setLoading(true);
    try {
      const res = await adminApi.getGeoStats(days);
      setData(res);
    } finally {
      setLoading(false);
    }
  }

  useEffect(() => {
    void load();
  }, [days]);

  return (
    <div className="space-y-6">
      <div className="flex flex-col gap-4 lg:flex-row lg:items-end lg:justify-between">
        <div className="page-header">
          <h1 className="page-title">{t("geo.title")}</h1>
          <p className="page-description">
            Vizualizări, sesiuni și utilizatori grupați pe țări pentru perioada selectată.
          </p>
        </div>

        <label className="flex flex-col gap-1 text-sm font-medium text-muted-foreground sm:w-44">
          Perioadă
          <select
            value={days}
            onChange={(event) => setDays(Number(event.target.value))}
            className="h-10 rounded-md border border-input bg-background px-3 text-sm text-foreground shadow-sm focus:outline-none focus:ring-2 focus:ring-ring"
          >
            <option value={7}>7 zile</option>
            <option value={30}>30 zile</option>
            <option value={90}>90 zile</option>
            <option value={365}>1 an</option>
          </select>
        </label>
      </div>

      {loading || !data ? (
        <Card>
          <CardContent className="p-10 text-center text-sm text-muted-foreground">{t("common.loading")}…</CardContent>
        </Card>
      ) : (
        <>
          <div className="grid gap-4 md:grid-cols-2 xl:grid-cols-3">
            <MetricCard
              title={t("geo.total_views")}
              value={data.totals.total_views.toLocaleString()}
              icon={EyeIcon}
              description={`Ultimele ${days} zile`}
            />
            <MetricCard
              title={t("geo.unique_countries")}
              value={data.totals.unique_countries.toLocaleString()}
              icon={Globe2Icon}
              description="Țări cu trafic înregistrat"
            />
            <MetricCard
              title="Țara principală"
              value={data.countries[0]?.country ?? "—"}
              icon={MapPinnedIcon}
              description={data.countries[0] ? `${data.countries[0].percent.toFixed(2)}% din vizualizări` : "Nu există date"}
            />
          </div>

          <Card>
            <CardHeader className="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
              <div>
                <CardTitle className="text-xl">Distribuție pe țări</CardTitle>
                <CardDescription>
                  Ponderea este calculată din totalul de vizualizări pentru perioada curentă.
                </CardDescription>
              </div>
              <div className="inline-flex items-center gap-2 rounded-md border bg-muted px-3 py-2 text-sm text-muted-foreground">
                <UsersIcon className="h-4 w-4" />
                {data.countries.length} țări
              </div>
            </CardHeader>
            <CardContent className="p-0">
              <Table>
                <TableHeader>
                  <TableRow>
                    <TableHead>{t("geo.country")}</TableHead>
                    <TableHead className="text-right">{t("geo.views")}</TableHead>
                    <TableHead className="text-right">{t("geo.sessions")}</TableHead>
                    <TableHead className="text-right">{t("geo.users")}</TableHead>
                    <TableHead className="text-right">{t("geo.percent")}</TableHead>
                    <TableHead className="w-[28%]">Distribuție</TableHead>
                  </TableRow>
                </TableHeader>
                <TableBody>
                  {data.countries.length === 0 ? (
                    <TableRow>
                      <TableCell colSpan={6} className="h-24 text-center text-muted-foreground">
                        Nu există date pentru perioada selectată.
                      </TableCell>
                    </TableRow>
                  ) : (
                    data.countries.map((country) => (
                      <TableRow key={country.country}>
                        <TableCell>
                          <div className="font-medium">{country.country}</div>
                        </TableCell>
                        <TableCell className="text-right">{country.views.toLocaleString()}</TableCell>
                        <TableCell className="text-right">{country.sessions.toLocaleString()}</TableCell>
                        <TableCell className="text-right">{country.users.toLocaleString()}</TableCell>
                        <TableCell className="text-right">{country.percent.toFixed(2)}%</TableCell>
                        <TableCell>
                          <div className="h-2 rounded-full bg-muted">
                            <div
                              className="h-2 rounded-full bg-primary"
                              style={{ width: `${Math.min(100, country.percent)}%` }}
                            />
                          </div>
                        </TableCell>
                      </TableRow>
                    ))
                  )}
                </TableBody>
              </Table>
            </CardContent>
          </Card>
        </>
      )}
    </div>
  );
}

function MetricCard({
  title,
  value,
  icon: Icon,
  description,
}: {
  title: string;
  value: string | number;
  icon: ElementType;
  description: string;
}) {
  return (
    <Card>
      <CardHeader className="flex flex-row items-start justify-between space-y-0 pb-3">
        <div className="space-y-1">
          <CardDescription>{title}</CardDescription>
          <CardTitle>{value}</CardTitle>
        </div>
        <div className="rounded-md border bg-muted p-2">
          <Icon className="h-4 w-4" />
        </div>
      </CardHeader>
      <CardContent className="pt-0">
        <p className="text-sm text-muted-foreground">{description}</p>
      </CardContent>
    </Card>
  );
}
