/**
 * Ad campaign reports for people who do not read dashboards: advertisers,
 * sales, management. Every number comes with a plain-language label, the
 * rates are spelled out ("out of 100 views…"), and a short summary states the
 * conclusions so nobody has to derive them from raw VAST event names.
 *
 * Both generators are loaded on demand — exceljs and pdfmake are large and only
 * needed when someone actually exports.
 */
import type { AdCampaignStatsResponse, AdReportTotals } from "./api";

type Report = AdCampaignStatsResponse;

const BRAND = "B91C1C";
const INK = "1F2937";
const MUTED = "6B7280";
const ZEBRA = "F9FAFB";
const LINE = "E5E7EB";

const PLATFORM_LABELS: Record<string, string> = {
  web: "Site web (browser)",
  ios: "Aplicația iPhone / iPad",
  tvos: "Apple TV",
  android: "Android",
  unknown: "Nespecificat",
};

const STATUS_LABELS: Record<string, string> = {
  active: "Activă",
  paused: "Pe pauză",
  draft: "Ciornă",
  completed: "Încheiată",
  archived: "Arhivată",
};

/** What each metric means, in words an advertiser would use. */
const GLOSSARY: Array<[string, string]> = [
  ["Afișări", "De câte ori reclama a apărut pe ecran înaintea sau în timpul unui film."],
  ["Vizionări complete", "De câte ori reclama a fost urmărită până la ultima secundă."],
  ["Rata de vizionare completă", "Din toate afișările, ce parte a fost urmărită până la capăt. Cu cât e mai mare, cu atât mesajul a ajuns mai bine."],
  ["Clicuri", "De câte ori spectatorii au apăsat pe reclamă sau pe butonul „Află mai mult”."],
  ["Rata de click (CTR)", "Din toate afișările, ce parte s-a încheiat cu un click. Pentru reclame video, 0,5% – 2% este un rezultat obișnuit."],
  ["Reclame sărite", "De câte ori spectatorul a apăsat „Sari peste” după secundele obligatorii."],
];

const numberFormat = new Intl.NumberFormat("ro-RO");
const percentFormat = new Intl.NumberFormat("ro-RO", { maximumFractionDigits: 1, minimumFractionDigits: 1 });
const dateFormat = new Intl.DateTimeFormat("ro-RO", { day: "2-digit", month: "long", year: "numeric" });
const shortDateFormat = new Intl.DateTimeFormat("ro-RO", { day: "2-digit", month: "2-digit", year: "numeric" });
const weekdayFormat = new Intl.DateTimeFormat("ro-RO", { weekday: "long" });

let regionNames: Intl.DisplayNames | null = null;
function countryName(code: string): string {
  if (!code || code === "ZZ") return "Necunoscută";
  try {
    regionNames ??= new Intl.DisplayNames(["ro"], { type: "region" });
    return regionNames.of(code.toUpperCase()) ?? code;
  } catch {
    return code;
  }
}

/** Parses a `YYYY-MM-DD` as a local date so it never shifts by a day. */
function day(value: string): Date {
  const [y, m, d] = value.slice(0, 10).split("-").map(Number);
  return new Date(y, (m ?? 1) - 1, d ?? 1);
}

const n = (value: number) => numberFormat.format(Math.round(value));
const pct = (value: number) => `${percentFormat.format(value)}%`;
const share = (part: number, total: number) => (total > 0 ? (part / total) * 100 : 0);

function periodLabel(report: Report): string {
  return `${dateFormat.format(day(report.period.from))} – ${dateFormat.format(day(report.period.to))}`;
}

function contentTitle(row: Report["content_chart"][number]): string {
  if (row.title) return row.title;
  return row.content_id === null ? "Fără film asociat" : `Film #${row.content_id}`;
}

function fileBase(report: Report): string {
  const slug = report.campaign.name
    .normalize("NFD")
    .replace(/[̀-ͯ]/g, "")
    .replace(/[^a-zA-Z0-9]+/g, "-")
    .replace(/^-|-$/g, "")
    .toLowerCase();
  return `raport-reclama-${slug || report.campaign.id}-${report.period.from}-${report.period.to}`;
}

/** Plain sentences a reader can repeat in a meeting without interpreting tables. */
export function reportHighlights(report: Report): string[] {
  const totals = report.period_totals;
  const lines: string[] = [];

  if (totals.impressions === 0) {
    return [`În perioada ${periodLabel(report)} reclama nu a fost afișată niciodată.`];
  }

  lines.push(`În perioada ${periodLabel(report)} reclama a fost afișată de ${n(totals.impressions)} ori.`);

  const completedOutOf100 = Math.round(share(totals.completes, totals.impressions));
  lines.push(
    `Din fiecare 100 de afișări, aproximativ ${completedOutOf100} au fost urmărite până la capăt (${n(totals.completes)} vizionări complete).`,
  );

  if (totals.clicks > 0) {
    const perClick = Math.max(1, Math.round(totals.impressions / totals.clicks));
    lines.push(`Spectatorii au apăsat pe reclamă de ${n(totals.clicks)} ori — în medie un click la fiecare ${n(perClick)} afișări (CTR ${pct(totals.ctr)}).`);
  } else {
    lines.push("Nimeni nu a apăsat pe reclamă în această perioadă.");
  }

  if (totals.skips > 0) {
    lines.push(`Reclama a fost sărită de ${n(totals.skips)} ori (${pct(share(totals.skips, totals.impressions))} din afișări).`);
  }

  const bestDay = [...report.daily_chart].sort((a, b) => b.impressions - a.impressions)[0];
  if (bestDay && bestDay.impressions > 0) {
    lines.push(`Cea mai bună zi a fost ${weekdayFormat.format(day(bestDay.date))}, ${dateFormat.format(day(bestDay.date))}, cu ${n(bestDay.impressions)} afișări.`);
  }

  const topFilm = report.content_chart[0];
  if (topFilm && topFilm.impressions > 0) {
    lines.push(`Cele mai multe afișări au venit din filmul „${contentTitle(topFilm)}” (${pct(share(topFilm.impressions, totals.impressions))} din total).`);
  }

  const topCountry = report.country_chart[0];
  if (topCountry && topCountry.count > 0) {
    lines.push(`Majoritatea spectatorilor au fost din ${countryName(topCountry.country)} (${pct(topCountry.percent)}).`);
  }

  const topPlatform = report.platform_chart[0];
  if (topPlatform && topPlatform.impressions > 0) {
    lines.push(`Platforma principală: ${PLATFORM_LABELS[topPlatform.platform] ?? topPlatform.platform} (${pct(share(topPlatform.impressions, totals.impressions))} din afișări).`);
  }

  return lines;
}

/** How far into the ad viewers got, as a share of all impressions. */
function funnel(totals: AdReportTotals): Array<[string, number]> {
  return [
    ["Reclama a apărut pe ecran", totals.impressions],
    ["Au văzut cel puțin un sfert", totals.first_quartile],
    ["Au văzut cel puțin jumătate", totals.midpoint],
    ["Au văzut trei sferturi", totals.third_quartile],
    ["Au văzut-o până la capăt", totals.completes],
  ];
}

function triggerDownload(blob: Blob, filename: string) {
  const url = URL.createObjectURL(blob);
  const link = document.createElement("a");
  link.href = url;
  link.download = filename;
  document.body.appendChild(link);
  link.click();
  link.remove();
  window.setTimeout(() => URL.revokeObjectURL(url), 1000);
}

// ---------------------------------------------------------------------------
// Excel
// ---------------------------------------------------------------------------

export async function downloadAdReportExcel(report: Report): Promise<void> {
  const ExcelJS = (await import("exceljs")).default;
  const workbook = new ExcelJS.Workbook();
  workbook.creator = "Filmoteca.md";
  workbook.created = new Date();

  type Sheet = ReturnType<typeof workbook.addWorksheet>;
  type Row = ReturnType<Sheet["addRow"]>;

  const thin = { style: "thin" as const, color: { argb: `FF${LINE}` } };
  const border = { top: thin, left: thin, bottom: thin, right: thin };

  const styleHeader = (row: Row) => {
    row.height = 30;
    row.eachCell((cell) => {
      cell.font = { bold: true, color: { argb: "FFFFFFFF" } };
      cell.fill = { type: "pattern", pattern: "solid", fgColor: { argb: `FF${INK}` } };
      cell.alignment = { vertical: "middle", horizontal: "center", wrapText: true };
      cell.border = border;
    });
  };
  const styleBody = (row: Row, index: number) => {
    row.eachCell({ includeEmpty: true }, (cell) => {
      cell.border = border;
      cell.alignment = { vertical: "middle", ...(cell.alignment ?? {}) };
      if (index % 2 === 1) cell.fill = { type: "pattern", pattern: "solid", fgColor: { argb: `FF${ZEBRA}` } };
    });
  };
  const styleTotal = (row: Row) => {
    row.eachCell({ includeEmpty: true }, (cell) => {
      cell.font = { bold: true };
      cell.border = { ...border, top: { style: "medium", color: { argb: `FF${INK}` } } };
      cell.fill = { type: "pattern", pattern: "solid", fgColor: { argb: "FFF3F4F6" } };
    });
  };
  const sheetTitle = (sheet: Sheet, title: string, subtitle: string, width: number) => {
    sheet.mergeCells(1, 1, 1, width);
    sheet.mergeCells(2, 1, 2, width);
    const t = sheet.getCell(1, 1);
    t.value = title;
    t.font = { bold: true, size: 16, color: { argb: `FF${BRAND}` } };
    const s = sheet.getCell(2, 1);
    s.value = subtitle;
    s.font = { italic: true, color: { argb: `FF${MUTED}` } };
    sheet.getRow(1).height = 26;
    sheet.addRow([]);
  };
  const pageSetup = { orientation: "landscape" as const, fitToPage: true, fitToWidth: 1, fitToHeight: 0, paperSize: 9 };

  const totals = report.period_totals;
  const campaign = report.campaign;

  // --- Summary --------------------------------------------------------------
  const summary = workbook.addWorksheet("Rezumat", { pageSetup, views: [{ showGridLines: false }] });
  summary.columns = [{ width: 34 }, { width: 20 }, { width: 80 }];
  sheetTitle(summary, "Raport campanie publicitară", `Generat la ${dateFormat.format(new Date())} · Filmoteca.md`, 3);

  const info: Array<[string, string]> = [
    ["Campanie", campaign.name],
    ["Advertiser", campaign.company_name ?? "—"],
    ["Perioada raportului", periodLabel(report)],
    ["Starea campaniei", STATUS_LABELS[campaign.status] ?? campaign.status],
    [
      "Campania rulează",
      campaign.starts_at || campaign.ends_at
        ? `${campaign.starts_at ? shortDateFormat.format(new Date(campaign.starts_at)) : "—"} → ${campaign.ends_at ? shortDateFormat.format(new Date(campaign.ends_at)) : "fără dată de final"}`
        : "Fără limită de timp",
    ],
  ];
  for (const [label, value] of info) {
    const row = summary.addRow([label, value]);
    summary.mergeCells(row.number, 2, row.number, 3);
    row.getCell(1).font = { bold: true, color: { argb: `FF${MUTED}` } };
    row.getCell(2).font = { bold: true, color: { argb: `FF${INK}` } };
  }

  summary.addRow([]);
  styleHeader(summary.addRow(["Indicator", "Rezultat", "Ce înseamnă"]));
  const kpis: Array<[string, number, string, boolean]> = [
    ["Afișări", totals.impressions, GLOSSARY[0][1], false],
    ["Vizionări complete", totals.completes, GLOSSARY[1][1], false],
    ["Rata de vizionare completă", totals.completion_rate / 100, GLOSSARY[2][1], true],
    ["Clicuri", totals.clicks, GLOSSARY[3][1], false],
    ["Rata de click (CTR)", totals.ctr / 100, GLOSSARY[4][1], true],
    ["Reclame sărite", totals.skips, GLOSSARY[5][1], false],
  ];
  kpis.forEach(([label, value, meaning, isPercent], index) => {
    const row = summary.addRow([label, value, meaning]);
    row.getCell(1).font = { bold: true };
    row.getCell(2).numFmt = isPercent ? "0.0%" : "#,##0";
    row.getCell(2).font = { bold: true, size: 13, color: { argb: `FF${BRAND}` } };
    row.getCell(2).alignment = { horizontal: "center" };
    row.getCell(3).alignment = { wrapText: true };
    row.height = 34;
    styleBody(row, index);
  });

  summary.addRow([]);
  styleHeader(summary.addRow(["Cât din reclamă au văzut spectatorii", "Număr", "Din totalul afișărilor"]));
  funnel(totals).forEach(([label, value], index) => {
    const row = summary.addRow([label, value, totals.impressions > 0 ? value / totals.impressions : 0]);
    row.getCell(2).numFmt = "#,##0";
    row.getCell(3).numFmt = "0.0%";
    row.getCell(2).alignment = { horizontal: "center" };
    styleBody(row, index);
  });
  const funnelEnd = summary.lastRow!.number;
  summary.addConditionalFormatting({
    ref: `C${funnelEnd - 4}:C${funnelEnd}`,
    rules: [{ type: "dataBar", priority: 1, cfvo: [{ type: "num", value: 0 }, { type: "num", value: 1 }], color: { argb: `FF${BRAND}` } } as never],
  });

  summary.addRow([]);
  const conclusionsTitle = summary.addRow(["Pe scurt"]);
  conclusionsTitle.getCell(1).font = { bold: true, size: 13, color: { argb: `FF${BRAND}` } };
  for (const line of reportHighlights(report)) {
    const row = summary.addRow([`•  ${line}`]);
    summary.mergeCells(row.number, 1, row.number, 3);
    row.getCell(1).alignment = { wrapText: true, vertical: "top" };
    row.height = 30;
  }

  // --- Per day --------------------------------------------------------------
  const daily = workbook.addWorksheet("Pe zile", { pageSetup });
  daily.columns = [
    { width: 14 }, { width: 14 }, { width: 12 }, { width: 16 }, { width: 18 }, { width: 10 }, { width: 14 }, { width: 12 },
  ];
  sheetTitle(daily, "Rezultate pe zile", `${campaign.name} · ${periodLabel(report)}`, 8);
  const dailyHeader = daily.addRow(["Data", "Ziua", "Afișări", "Vizionări complete", "Rata de vizionare completă", "Clicuri", "Rata de click", "Sărite"]);
  styleHeader(dailyHeader);
  daily.views = [{ state: "frozen", ySplit: dailyHeader.number, showGridLines: false }];
  const dailyFirst = dailyHeader.number + 1;
  report.daily_chart.forEach((item, index) => {
    const date = day(item.date);
    // exceljs stores dates as UTC; a local midnight would land on the previous
    // day east of Greenwich.
    const row = daily.addRow([
      new Date(Date.UTC(date.getFullYear(), date.getMonth(), date.getDate())),
      weekdayFormat.format(date),
      item.impressions,
      item.completes,
      item.completion_rate / 100,
      item.clicks,
      item.ctr / 100,
      item.skips,
    ]);
    row.getCell(1).numFmt = "dd.mm.yyyy";
    [3, 4, 6, 8].forEach((c) => (row.getCell(c).numFmt = "#,##0"));
    [5, 7].forEach((c) => (row.getCell(c).numFmt = "0.0%"));
    styleBody(row, index);
  });
  const dailyLast = daily.lastRow!.number;
  const dailyTotal = daily.addRow([
    "TOTAL", "", totals.impressions, totals.completes, totals.completion_rate / 100, totals.clicks, totals.ctr / 100, totals.skips,
  ]);
  [3, 4, 6, 8].forEach((c) => (dailyTotal.getCell(c).numFmt = "#,##0"));
  [5, 7].forEach((c) => (dailyTotal.getCell(c).numFmt = "0.0%"));
  styleTotal(dailyTotal);
  if (dailyLast >= dailyFirst) {
    daily.addConditionalFormatting({
      ref: `C${dailyFirst}:C${dailyLast}`,
      rules: [{ type: "dataBar", priority: 1, cfvo: [{ type: "min" }, { type: "max" }], color: { argb: `FF${BRAND}` } } as never],
    });
  }

  // --- Breakdown sheets -----------------------------------------------------
  const breakdown = (
    name: string,
    title: string,
    firstColumn: string,
    rows: Array<{ label: string } & AdReportTotals>,
  ) => {
    const sheet = workbook.addWorksheet(name, { pageSetup });
    sheet.columns = [{ width: 42 }, { width: 12 }, { width: 16 }, { width: 16 }, { width: 18 }, { width: 10 }, { width: 14 }];
    sheetTitle(sheet, title, `${campaign.name} · ${periodLabel(report)}`, 7);
    const header = sheet.addRow([firstColumn, "Afișări", "Din total afișări", "Vizionări complete", "Rata de vizionare completă", "Clicuri", "Rata de click"]);
    styleHeader(header);
    sheet.views = [{ state: "frozen", ySplit: header.number, showGridLines: false }];
    rows.forEach((item, index) => {
      const row = sheet.addRow([
        item.label,
        item.impressions,
        share(item.impressions, totals.impressions) / 100,
        item.completes,
        item.completion_rate / 100,
        item.clicks,
        item.ctr / 100,
      ]);
      [2, 4, 6].forEach((c) => (row.getCell(c).numFmt = "#,##0"));
      [3, 5, 7].forEach((c) => (row.getCell(c).numFmt = "0.0%"));
      styleBody(row, index);
    });
    if (rows.length === 0) {
      sheet.addRow(["Nu există date pentru această perioadă."]).getCell(1).font = { italic: true, color: { argb: `FF${MUTED}` } };
    }
  };

  breakdown(
    "Pe filme",
    "Pe ce filme a rulat reclama",
    "Film",
    report.content_chart.map((item) => ({ ...item, label: contentTitle(item) })),
  );
  breakdown(
    "Pe platforme",
    "Pe ce dispozitive a fost văzută reclama",
    "Platformă",
    report.platform_chart.map((item) => ({ ...item, label: PLATFORM_LABELS[item.platform] ?? item.platform })),
  );

  const countries = workbook.addWorksheet("Pe țări", { pageSetup });
  countries.columns = [{ width: 34 }, { width: 14 }, { width: 18 }];
  sheetTitle(countries, "Din ce țări au fost spectatorii", `${campaign.name} · ${periodLabel(report)}`, 3);
  styleHeader(countries.addRow(["Țara", "Afișări", "Din total"]));
  report.country_chart.forEach((item, index) => {
    const row = countries.addRow([countryName(item.country), item.count, item.percent / 100]);
    row.getCell(2).numFmt = "#,##0";
    row.getCell(3).numFmt = "0.0%";
    styleBody(row, index);
  });

  // --- Glossary -------------------------------------------------------------
  const glossary = workbook.addWorksheet("Explicații", { pageSetup, views: [{ showGridLines: false }] });
  glossary.columns = [{ width: 30 }, { width: 100 }];
  sheetTitle(glossary, "Cum se citește raportul", "Termenii folosiți în acest document", 2);
  styleHeader(glossary.addRow(["Termen", "Explicație"]));
  GLOSSARY.forEach(([term, meaning], index) => {
    const row = glossary.addRow([term, meaning]);
    row.getCell(1).font = { bold: true };
    row.getCell(2).alignment = { wrapText: true };
    row.height = 32;
    styleBody(row, index);
  });

  const buffer = await workbook.xlsx.writeBuffer();
  triggerDownload(
    new Blob([buffer], { type: "application/vnd.openxmlformats-officedocument.spreadsheetml.sheet" }),
    `${fileBase(report)}.xlsx`,
  );
}

// ---------------------------------------------------------------------------
// PDF
// ---------------------------------------------------------------------------

/* eslint-disable @typescript-eslint/no-explicit-any -- pdfmake's document model is loosely typed. */
export async function downloadAdReportPdf(report: Report): Promise<void> {
  const [{ default: pdfMake }, { default: vfs }] = await Promise.all([
    import("pdfmake/build/pdfmake") as Promise<any>,
    import("pdfmake/build/vfs_fonts") as Promise<any>,
  ]);
  // Roboto from pdfmake's bundle covers ă â î ș ț (and Cyrillic), which the
  // PDF standard fonts do not.
  pdfMake.addVirtualFileSystem(vfs);

  const totals = report.period_totals;
  const campaign = report.campaign;
  const color = (hex: string) => `#${hex}`;

  const kpi = (label: string, value: string, hint: string) => ({
    stack: [
      { text: label.toUpperCase(), fontSize: 7.5, color: color(MUTED), bold: true, characterSpacing: 0.5 },
      { text: value, fontSize: 20, bold: true, color: color(BRAND), margin: [0, 3, 0, 2] },
      { text: hint, fontSize: 7.5, color: color(MUTED) },
    ],
    margin: [8, 8, 8, 8],
  });

  const tableLayout = {
    hLineWidth: (i: number, node: any) => (i === 0 || i === node.table.body.length ? 0 : 0.5),
    vLineWidth: () => 0,
    hLineColor: () => color(LINE),
    fillColor: (rowIndex: number) => (rowIndex === 0 ? color(INK) : rowIndex % 2 === 0 ? color(ZEBRA) : null),
    paddingTop: () => 5,
    paddingBottom: () => 5,
  };
  const headerCell = (text: string, alignment: "left" | "right" = "right") => ({ text, bold: true, color: "#FFFFFF", fontSize: 8.5, alignment });
  const cell = (text: string, alignment: "left" | "right" = "right") => ({ text, fontSize: 8.5, alignment });

  /** Horizontal bar drawn with vectors, so it prints crisply. */
  const bar = (value: number, max: number, width = 150) => ({
    canvas: [
      { type: "rect", x: 0, y: 2, w: width, h: 8, r: 2, color: "#F3F4F6" },
      { type: "rect", x: 0, y: 2, w: max > 0 ? Math.max(1, (value / max) * width) : 0, h: 8, r: 2, color: color(BRAND) },
    ],
  });

  /** Daily impressions as a column chart; empty days stay visible as gaps. */
  const dailyChart = () => {
    const width = 515;
    const height = 110;
    const days = report.daily_chart;
    const max = Math.max(1, ...days.map((d) => d.impressions));
    const slot = width / Math.max(1, days.length);
    const barWidth = Math.max(1, slot * 0.7);
    const shapes: any[] = [{ type: "line", x1: 0, y1: height, x2: width, y2: height, lineWidth: 0.5, lineColor: color(LINE) }];
    days.forEach((d, i) => {
      const h = (d.impressions / max) * (height - 4);
      if (h > 0) shapes.push({ type: "rect", x: i * slot + (slot - barWidth) / 2, y: height - h, w: barWidth, h, color: color(BRAND) });
    });
    return [
      { canvas: shapes },
      {
        columns: [
          { text: shortDateFormat.format(day(report.period.from)), fontSize: 7, color: color(MUTED) },
          { text: `maxim ${n(max)} afișări / zi`, fontSize: 7, color: color(MUTED), alignment: "center" },
          { text: shortDateFormat.format(day(report.period.to)), fontSize: 7, color: color(MUTED), alignment: "right" },
        ],
        margin: [0, 3, 0, 0],
      },
    ];
  };

  const sectionTitle = (text: string) => ({ text, style: "section" });

  const breakdownTable = (firstColumn: string, rows: Array<{ label: string } & AdReportTotals>) => {
    if (rows.length === 0) return { text: "Nu există date pentru această perioadă.", italics: true, color: color(MUTED), fontSize: 9 };
    const max = Math.max(1, ...rows.map((r) => r.impressions));
    return {
      table: {
        headerRows: 1,
        widths: ["*", 50, 90, 62, 40, 45],
        body: [
          [headerCell(firstColumn, "left"), headerCell("Afișări"), headerCell(""), headerCell("Văzute integral"), headerCell("Clicuri"), headerCell("Din total")],
          ...rows.map((r) => [
            cell(r.label, "left"),
            cell(n(r.impressions)),
            { ...bar(r.impressions, max, 85), margin: [4, 0, 0, 0] },
            cell(pct(r.completion_rate)),
            cell(n(r.clicks)),
            cell(pct(share(r.impressions, totals.impressions))),
          ]),
        ],
      },
      layout: tableLayout,
    };
  };

  const info: Array<[string, string]> = [
    ["Advertiser", campaign.company_name ?? "—"],
    ["Perioada", periodLabel(report)],
    ["Starea campaniei", STATUS_LABELS[campaign.status] ?? campaign.status],
  ];

  const docDefinition: any = {
    pageSize: "A4",
    pageMargins: [40, 50, 40, 50],
    info: { title: `Raport – ${campaign.name}`, author: "Filmoteca.md" },
    defaultStyle: { font: "Roboto", fontSize: 10, color: color(INK), lineHeight: 1.2 },
    styles: {
      section: { fontSize: 13, bold: true, color: color(INK), margin: [0, 18, 0, 4] },
      lead: { fontSize: 8.5, color: color(MUTED), margin: [0, 0, 0, 8] },
    },
    footer: (current: number, count: number) => ({
      columns: [
        { text: `Filmoteca.md · Raport generat la ${dateFormat.format(new Date())}`, fontSize: 7.5, color: color(MUTED) },
        { text: `Pagina ${current} din ${count}`, fontSize: 7.5, color: color(MUTED), alignment: "right" },
      ],
      margin: [40, 18, 40, 0],
    }),
    content: [
      { text: "RAPORT CAMPANIE PUBLICITARĂ", fontSize: 8.5, bold: true, color: color(BRAND), characterSpacing: 1 },
      { text: campaign.name, fontSize: 22, bold: true, margin: [0, 2, 0, 8] },
      {
        table: { widths: [110, "*"], body: info.map(([k, v]) => [{ text: k, color: color(MUTED), fontSize: 9 }, { text: v, bold: true, fontSize: 9 }]) },
        layout: "noBorders",
      },
      { canvas: [{ type: "line", x1: 0, y1: 8, x2: 515, y2: 8, lineWidth: 1, lineColor: color(BRAND) }], margin: [0, 0, 0, 10] },

      {
        table: {
          widths: ["*", "*", "*"],
          body: [
            [
              kpi("Afișări", n(totals.impressions), "de câte ori a apărut reclama"),
              kpi("Vizionări complete", n(totals.completes), "urmărite până la capăt"),
              kpi("Rata de vizionare completă", pct(totals.completion_rate), "din afișări, văzute integral"),
            ],
            [
              kpi("Clicuri", n(totals.clicks), "apăsări pe reclamă"),
              kpi("Rata de click (CTR)", pct(totals.ctr), "din afișări, încheiate cu click"),
              kpi("Reclame sărite", n(totals.skips), "după secundele obligatorii"),
            ],
          ],
        },
        layout: {
          hLineWidth: () => 0.5,
          vLineWidth: () => 0.5,
          hLineColor: () => color(LINE),
          vLineColor: () => color(LINE),
          fillColor: () => "#FCFCFD",
        },
      },

      sectionTitle("Pe scurt"),
      { ul: reportHighlights(report), fontSize: 9.5, margin: [0, 2, 0, 0], lineHeight: 1.35 },

      sectionTitle("Cât din reclamă au văzut spectatorii"),
      { text: "Fiecare rând arată câți spectatori au ajuns cel puțin până în acel punct al reclamei.", style: "lead" },
      {
        table: {
          widths: [170, "*", 55, 45],
          body: [
            [headerCell("Etapă", "left"), headerCell(""), headerCell("Număr"), headerCell("%")],
            ...funnel(totals).map(([label, value]) => [
              cell(label, "left"),
              { ...bar(value, totals.impressions, 200), margin: [4, 0, 0, 0] },
              cell(n(value)),
              cell(pct(share(value, totals.impressions))),
            ]),
          ],
        },
        layout: tableLayout,
      },

      // Kept together so the heading never ends a page without its chart.
      { stack: [sectionTitle("Afișări pe zile"), ...dailyChart()], unbreakable: true },

      sectionTitle("Pe ce filme a rulat reclama"),
      breakdownTable("Film", report.content_chart.map((r) => ({ ...r, label: contentTitle(r) }))),

      sectionTitle("Pe ce dispozitive"),
      breakdownTable("Platformă", report.platform_chart.map((r) => ({ ...r, label: PLATFORM_LABELS[r.platform] ?? r.platform }))),

      sectionTitle("Din ce țări"),
      report.country_chart.length === 0
        ? { text: "Nu există date pentru această perioadă.", italics: true, color: color(MUTED), fontSize: 9 }
        : {
            table: {
              headerRows: 1,
              widths: ["*", 200, 55, 45],
              body: [
                [headerCell("Țara", "left"), headerCell(""), headerCell("Afișări"), headerCell("%")],
                ...report.country_chart.map((c) => [
                  cell(countryName(c.country), "left"),
                  { ...bar(c.count, report.country_chart[0]?.count ?? 1, 190), margin: [4, 0, 0, 0] },
                  cell(n(c.count)),
                  cell(pct(c.percent)),
                ]),
              ],
            },
            layout: tableLayout,
          },

      { text: "Rezultate zi cu zi", style: "section", pageBreak: "before" },
      {
        table: {
          headerRows: 1,
          widths: [62, "*", 55, 55, 70, 45, 45],
          body: [
            [headerCell("Data", "left"), headerCell("Ziua", "left"), headerCell("Afișări"), headerCell("Complete"), headerCell("Rată completare"), headerCell("Clicuri"), headerCell("Sărite")],
            ...report.daily_chart.map((d) => [
              cell(shortDateFormat.format(day(d.date)), "left"),
              cell(weekdayFormat.format(day(d.date)), "left"),
              cell(n(d.impressions)),
              cell(n(d.completes)),
              cell(d.impressions > 0 ? pct(d.completion_rate) : "—"),
              cell(n(d.clicks)),
              cell(n(d.skips)),
            ]),
            [
              { text: "TOTAL", bold: true, fontSize: 8.5 },
              "",
              { text: n(totals.impressions), bold: true, fontSize: 8.5, alignment: "right" },
              { text: n(totals.completes), bold: true, fontSize: 8.5, alignment: "right" },
              { text: pct(totals.completion_rate), bold: true, fontSize: 8.5, alignment: "right" },
              { text: n(totals.clicks), bold: true, fontSize: 8.5, alignment: "right" },
              { text: n(totals.skips), bold: true, fontSize: 8.5, alignment: "right" },
            ],
          ],
        },
        layout: tableLayout,
      },

      sectionTitle("Cum se citește raportul"),
      {
        table: {
          widths: [130, "*"],
          body: GLOSSARY.map(([term, meaning]) => [{ text: term, bold: true, fontSize: 8.5 }, { text: meaning, fontSize: 8.5 }]),
        },
        layout: { ...tableLayout, fillColor: (rowIndex: number) => (rowIndex % 2 === 0 ? color(ZEBRA) : null) },
      },
    ],
  };

  await pdfMake.createPdf(docDefinition).download(`${fileBase(report)}.pdf`);
}
/* eslint-enable @typescript-eslint/no-explicit-any */
