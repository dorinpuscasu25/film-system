/**
 * Finance report for people who are not accountants: management, rights
 * holders, partners. Same approach as the ad report — every amount has a
 * plain-language label, a short summary states the conclusions, and the money
 * is followed from what viewers paid to who it ends up with.
 *
 * Figures come from the auditable reporting ledger (frozen calculations), so
 * the document matches what holders are paid. Buyer identity is never included;
 * the accounting Excel remains the place for that.
 */
import type { FinanceDimensionRow, FinanceReportResponse } from "./api";
import { countryName, triggerDownload } from "./adReport";

type Report = FinanceReportResponse;

const BRAND = "B91C1C";
const INK = "1F2937";
const MUTED = "6B7280";
const ZEBRA = "F9FAFB";
const LINE = "E5E7EB";
const GREEN = "047857";
const AMBER = "B45309";

/** What each amount means, in words a non-accountant would use. */
const GLOSSARY: Array<[string, string]> = [
  ["Vânzări (încasări)", "Totalul plătit de spectatori pentru filme în perioada aleasă, cu TVA inclus."],
  ["Cumpărări", "De câte ori a fost cumpărat sau închiriat un film. Un spectator poate face mai multe cumpărări."],
  ["Spectatori plătitori", "Câte persoane diferite au cumpărat cel puțin un film."],
  ["TVA", "Partea din preț care se datorează statului ca taxă pe valoarea adăugată. Se aplică vânzărilor din Moldova; vânzările în străinătate nu au TVA."],
  ["Venit fără TVA", "Vânzările minus TVA — banii care se împart între titulari și platformă."],
  ["Partea titularilor", "Suma cuvenită deținătorilor drepturilor de autor, după procentul din contract, înainte de impozitul reținut."],
  ["Impozit reținut", "Impozitul pe care platforma îl reține din partea titularilor persoane fizice și îl achită la stat în numele lor."],
  ["De plătit titularilor", "Suma care trebuie virată efectiv titularilor: partea lor minus impozitul reținut."],
  ["Partea platformei", "Ce rămâne la Filmoteca.md din venitul fără TVA după partea titularilor."],
  ["Rambursări", "Bani returnați spectatorilor pentru cumpărări anulate."],
  ["Bani depuși în conturi", "Alimentările de cont făcute cu cardul. Banii devin vânzări abia când spectatorul cumpără un film, de aceea suma poate fi diferită de vânzări."],
  ["Costuri tehnice", "Ce plătim furnizorului video: stocarea filmelor, traficul (livrarea către spectatori) și licențele de protecție DRM. Sunt în dolari (USD)."],
  ["Vizionări", "De câte ori a fost pornit un film, raportat de furnizorul video."],
];

const PAYMENT_METHODS: Record<string, string> = {
  wallet: "Din soldul contului Filmoteca",
  card: "Card bancar",
  apple_iap: "App Store (Apple)",
  "N/A": "Nespecificat",
};

const numberFormat = new Intl.NumberFormat("ro-RO");
const moneyFormat = new Intl.NumberFormat("ro-RO", { minimumFractionDigits: 2, maximumFractionDigits: 2 });
const percentFormat = new Intl.NumberFormat("ro-RO", { maximumFractionDigits: 1, minimumFractionDigits: 1 });
const dateFormat = new Intl.DateTimeFormat("ro-RO", { day: "2-digit", month: "long", year: "numeric" });
const shortDateFormat = new Intl.DateTimeFormat("ro-RO", { day: "2-digit", month: "2-digit", year: "numeric" });
const dateTimeFormat = new Intl.DateTimeFormat("ro-RO", { day: "2-digit", month: "2-digit", year: "numeric", hour: "2-digit", minute: "2-digit" });
const weekdayFormat = new Intl.DateTimeFormat("ro-RO", { weekday: "long" });
const monthFormat = new Intl.DateTimeFormat("ro-RO", { month: "long", year: "numeric" });

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

function marketLabel(value: string | null): string {
  if (value === "domestic") return "Moldova";
  if (value === "export") return "Străinătate (export)";
  return "Nespecificat";
}

function durationLabel(value: string): string {
  if (value === "permanent") return "Cumpărare permanentă";
  const days = Number(value);
  return Number.isFinite(days) ? `Închiriere ${days} ${days === 1 ? "zi" : "zile"}` : value;
}

function personLabel(type: string | null, vat: boolean): string {
  const person = type === "PJ" ? "Companie (PJ)" : type === "PF" ? "Persoană fizică (PF)" : "Nespecificat";
  return vat ? `${person}, plătitor TVA` : person;
}

function monthLabel(value: string): string {
  return monthFormat.format(day(`${value}-01`));
}

function statusLabel(value: string): string {
  return value === "calculated" ? "Calculat" : value === "missing_contract" ? "Fără contract" : value === "missing_fiscal_profile" ? "Fără profil fiscal" : value;
}

function fileBase(report: Report): string {
  return `raport-financiar-${report.period.from}-${report.period.to}`;
}

/** The money waterfall, from what viewers paid to where it ends up. */
function moneyFlow(report: Report): Array<[string, number, string]> {
  const s = report.summary;
  const rows: Array<[string, number, string]> = [
    ["Plătit de spectatori", s.gross_amount, "totalul vânzărilor, cu TVA"],
    ["TVA datorat statului", s.vat_amount, "din vânzările în Moldova"],
    ["Impozit reținut pentru titulari", s.withholding_amount, "achitat la stat în numele lor"],
    ["De plătit titularilor", s.holder_net_amount, "virat către deținătorii drepturilor"],
  ];
  if (!report.scope.is_holder) {
    rows.push(["Rămâne platformei", s.platform_share_amount, "venitul Filmoteca.md"]);
  }
  return rows;
}

/** Plain sentences a reader can repeat in a meeting without interpreting tables. */
export function financeHighlights(report: Report): string[] {
  const s = report.summary;
  const cur = s.currency;
  const lines: string[] = [];

  if (s.purchases === 0) {
    lines.push(`În perioada ${periodLabel(report)} nu a fost vândut niciun film.`);
  } else {
    lines.push(
      `În perioada ${periodLabel(report)} spectatorii au cumpărat filme de ${n(s.purchases)} ori, în total ${moneyFormat.format(s.gross_amount)} ${cur}, ${s.buyers === 1 ? "de la o singură persoană" : `de la ${n(s.buyers)} persoane diferite`}.`,
    );
    lines.push(`În medie, o cumpărare a costat ${moneyFormat.format(s.gross_amount / s.purchases)} ${cur}.`);

    const top = report.by_film[0];
    if (top && top.gross_amount > 0) {
      lines.push(
        `Cel mai vândut film: „${top.title ?? "Fără titlu"}” — ${n(top.purchases)} cumpărări, ${moneyFormat.format(top.gross_amount)} ${cur} (${pct(share(top.gross_amount, s.gross_amount))} din vânzări).`,
      );
    }

    if (s.gross_amount > 0) {
      const per100 = (value: number) => moneyFormat.format(share(value, s.gross_amount));
      lines.push(
        report.scope.is_holder
          ? `Din fiecare 100 ${cur} plătiți de spectatori pentru filmele tale, ${per100(s.holder_net_amount)} ${cur} îți revin după impozit.`
          : `Din fiecare 100 ${cur} plătiți de spectatori: ${per100(s.vat_amount)} ${cur} sunt TVA, ${per100(s.holder_gross_amount)} ${cur} revin titularilor și ${per100(s.platform_share_amount)} ${cur} rămân platformei.`,
      );
    }

    if (s.holder_gross_amount > 0) {
      lines.push(
        `Titularilor li se datorează ${moneyFormat.format(s.holder_net_amount)} ${cur}` +
          (s.withholding_amount > 0 ? `, după ce platforma reține și achită la stat impozit de ${moneyFormat.format(s.withholding_amount)} ${cur}.` : "."),
      );
    }

    if (s.export_amount > 0) {
      lines.push(`${pct(share(s.export_amount, s.gross_amount))} din vânzări au venit din afara Moldovei (${moneyFormat.format(s.export_amount)} ${cur}, fără TVA).`);
    }

    const bestDay = [...report.timeline].sort((a, b) => b.amount - a.amount)[0];
    if (bestDay && bestDay.amount > 0 && bestDay.label !== "N/A") {
      lines.push(`Cea mai bună zi a fost ${weekdayFormat.format(day(bestDay.label))}, ${dateFormat.format(day(bestDay.label))}: ${n(bestDay.purchases)} cumpărări, ${moneyFormat.format(bestDay.amount)} ${cur}.`);
    }
  }

  if (report.top_ups && report.top_ups.count > 0) {
    lines.push(`Spectatorii și-au alimentat contul de ${n(report.top_ups.count)} ori, cu ${moneyFormat.format(report.top_ups.amount)} ${report.top_ups.currency} în total.`);
  }

  if (s.refunds > 0) {
    lines.push(`Au fost rambursate ${n(s.refunds)} cumpărări, în valoare de ${moneyFormat.format(s.refund_amount)} ${cur}.`);
  }

  if (s.without_holder > 0) {
    lines.push(
      `Atenție: ${n(s.without_holder)} ${s.without_holder === 1 ? "vânzare nu are" : "vânzări nu au"} titular cu contract și profil fiscal configurat. Partea titularului nu a putut fi calculată și apare momentan la platformă — completați datele în „Raportare și drepturi”.`,
    );
  }

  return lines;
}

function costTotals(report: Report) {
  const items = report.costs.items;
  const sum = (key: keyof (typeof items)[number]) => items.reduce((total, item) => total + Number(item[key] ?? 0), 0);
  return {
    views: sum("views"),
    watch_hours: sum("watch_hours"),
    storage: sum("storage_cost_usd"),
    delivery: sum("delivery_cost_usd"),
    drm: sum("drm_cost_usd"),
    total: sum("storage_cost_usd") + sum("delivery_cost_usd") + sum("drm_cost_usd"),
  };
}

// ---------------------------------------------------------------------------
// Excel
// ---------------------------------------------------------------------------

export async function downloadFinanceReportExcel(report: Report): Promise<void> {
  const ExcelJS = (await import("exceljs")).default;
  const workbook = new ExcelJS.Workbook();
  workbook.creator = "Filmoteca.md";
  workbook.created = new Date();

  type Sheet = ReturnType<typeof workbook.addWorksheet>;
  type Row = ReturnType<Sheet["addRow"]>;

  const s = report.summary;
  const cur = s.currency;
  const money = `#,##0.00 "${cur}"`;
  const usd = '#,##0.00 "USD"';
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
    const sub = sheet.getCell(2, 1);
    sub.value = subtitle;
    sub.font = { italic: true, color: { argb: `FF${MUTED}` } };
    sheet.getRow(1).height = 26;
    sheet.addRow([]);
  };
  const emptyNote = (sheet: Sheet, text = "Nu există date pentru această perioadă.") => {
    sheet.addRow([text]).getCell(1).font = { italic: true, color: { argb: `FF${MUTED}` } };
  };
  const formats = (row: Row, columns: number[], numFmt: string) => columns.forEach((c) => (row.getCell(c).numFmt = numFmt));
  const dataBar = (sheet: Sheet, column: string, first: number, last: number, color = BRAND) => {
    if (last < first) return;
    sheet.addConditionalFormatting({
      ref: `${column}${first}:${column}${last}`,
      rules: [{ type: "dataBar", priority: 1, cfvo: [{ type: "num", value: 0 }, { type: "max" }], color: { argb: `FF${color}` } } as never],
    });
  };
  const pageSetup = { orientation: "landscape" as const, fitToPage: true, fitToWidth: 1, fitToHeight: 0, paperSize: 9 };
  const subtitle = `Filmoteca.md · ${periodLabel(report)}`;

  // --- Summary --------------------------------------------------------------
  const summary = workbook.addWorksheet("Rezumat", { pageSetup, views: [{ showGridLines: false }] });
  summary.columns = [{ width: 34 }, { width: 22 }, { width: 80 }];
  sheetTitle(summary, report.scope.is_holder ? "Raportul filmelor tale" : "Raport financiar", `Generat la ${dateFormat.format(new Date())} · Filmoteca.md`, 3);

  const info: Array<[string, string]> = [
    ["Perioada raportului", periodLabel(report)],
    ["Monedă", cur === "MDL" ? "Lei moldovenești (MDL)" : cur],
    ["Generat de", report.generated_by ?? "—"],
  ];
  for (const [label, value] of info) {
    const row = summary.addRow([label, value]);
    summary.mergeCells(row.number, 2, row.number, 3);
    row.getCell(1).font = { bold: true, color: { argb: `FF${MUTED}` } };
    row.getCell(2).font = { bold: true, color: { argb: `FF${INK}` } };
  }

  summary.addRow([]);
  styleHeader(summary.addRow(["Indicator", "Rezultat", "Ce înseamnă"]));
  const glossary = Object.fromEntries(GLOSSARY);
  const kpis: Array<[string, number, string, "money" | "count"]> = [
    ["Vânzări (încasări)", s.gross_amount, glossary["Vânzări (încasări)"], "money"],
    ["Cumpărări", s.purchases, glossary["Cumpărări"], "count"],
    ["Spectatori plătitori", s.buyers, glossary["Spectatori plătitori"], "count"],
    ["TVA", s.vat_amount, glossary["TVA"], "money"],
    ["Venit fără TVA", s.net_ex_vat_amount, glossary["Venit fără TVA"], "money"],
    ["Partea titularilor", s.holder_gross_amount, glossary["Partea titularilor"], "money"],
    ["Impozit reținut", s.withholding_amount, glossary["Impozit reținut"], "money"],
    ["De plătit titularilor", s.holder_net_amount, glossary["De plătit titularilor"], "money"],
    ...(report.scope.is_holder ? [] : [["Partea platformei", s.platform_share_amount, glossary["Partea platformei"], "money"] as [string, number, string, "money"]]),
    ["Rambursări", s.refund_amount, glossary["Rambursări"], "money"],
    ...(report.top_ups ? [["Bani depuși în conturi", report.top_ups.amount, glossary["Bani depuși în conturi"], "money"] as [string, number, string, "money"]] : []),
  ];
  kpis.forEach(([label, value, meaning, kind], index) => {
    const row = summary.addRow([label, value, meaning]);
    row.getCell(1).font = { bold: true };
    row.getCell(2).numFmt = kind === "money" ? money : "#,##0";
    row.getCell(2).font = { bold: true, size: 13, color: { argb: `FF${BRAND}` } };
    row.getCell(2).alignment = { horizontal: "right" };
    row.getCell(3).alignment = { wrapText: true };
    row.height = 34;
    styleBody(row, index);
  });

  summary.addRow([]);
  styleHeader(summary.addRow(["Unde merg banii", "Suma", "Din ce au plătit spectatorii"]));
  const flowStart = summary.lastRow!.number + 1;
  moneyFlow(report).forEach(([label, value], index) => {
    const row = summary.addRow([label, value, share(value, s.gross_amount) / 100]);
    row.getCell(2).numFmt = money;
    row.getCell(3).numFmt = "0.0%";
    styleBody(row, index);
  });
  dataBar(summary, "C", flowStart, summary.lastRow!.number);

  summary.addRow([]);
  const conclusionsTitle = summary.addRow(["Pe scurt"]);
  conclusionsTitle.getCell(1).font = { bold: true, size: 13, color: { argb: `FF${BRAND}` } };
  for (const line of financeHighlights(report)) {
    const row = summary.addRow([`•  ${line}`]);
    summary.mergeCells(row.number, 1, row.number, 3);
    row.getCell(1).alignment = { wrapText: true, vertical: "top" };
    row.height = 32;
  }

  // --- Per film -------------------------------------------------------------
  const films = workbook.addWorksheet("Pe filme", { pageSetup });
  films.columns = [{ width: 36 }, { width: 12 }, { width: 12 }, { width: 16 }, { width: 14 }, { width: 14 }, { width: 16 }, { width: 14 }, { width: 16 }, { width: 14 }, { width: 30 }];
  sheetTitle(films, "Vânzări pe filme", subtitle, 11);
  const filmHeader = films.addRow([
    "Film", "Cumpărări", "Spectatori", "Vânzări", "Din total", "TVA", "Partea titularilor", "Impozit reținut", "De plătit titularilor", "Partea platformei", "Titulari",
  ]);
  styleHeader(filmHeader);
  films.views = [{ state: "frozen", ySplit: filmHeader.number, showGridLines: false }];
  report.by_film.forEach((item, index) => {
    const row = films.addRow([
      item.title ?? "Fără titlu", item.purchases, item.buyers, item.gross_amount, share(item.gross_amount, s.gross_amount) / 100,
      item.vat_amount, item.holder_gross_amount, item.withholding_amount, item.net_payable_amount, item.platform_share_amount,
      item.holders.join(", ") || "Fără titular configurat",
    ]);
    formats(row, [2, 3], "#,##0");
    formats(row, [4, 6, 7, 8, 9, 10], money);
    row.getCell(5).numFmt = "0.0%";
    styleBody(row, index);
  });
  if (report.by_film.length === 0) {
    emptyNote(films);
  } else {
    dataBar(films, "D", filmHeader.number + 1, films.lastRow!.number);
    const total = films.addRow([
      "TOTAL", s.purchases, s.buyers, s.gross_amount, 1, s.vat_amount, s.holder_gross_amount, s.withholding_amount, s.holder_net_amount, s.platform_share_amount, "",
    ]);
    formats(total, [2, 3], "#,##0");
    formats(total, [4, 6, 7, 8, 9, 10], money);
    total.getCell(5).numFmt = "0.0%";
    styleTotal(total);
  }

  // --- Per holder -----------------------------------------------------------
  const holders = workbook.addWorksheet("Titulari", { pageSetup });
  holders.columns = [{ width: 30 }, { width: 28 }, { width: 40 }, { width: 12 }, { width: 16 }, { width: 18 }, { width: 16 }, { width: 18 }];
  sheetTitle(holders, "Cât se datorează fiecărui titular", subtitle, 8);
  const holderHeader = holders.addRow(["Titular", "Tip", "Filme", "Cumpărări", "Vânzări din filmele lui", "Partea titularului", "Impozit reținut", "De plătit"]);
  styleHeader(holderHeader);
  holders.views = [{ state: "frozen", ySplit: holderHeader.number, showGridLines: false }];
  report.by_holder.forEach((item, index) => {
    const row = holders.addRow([
      item.name ?? "—", personLabel(item.person_type, item.is_vat_registered), item.films.join(", "), item.purchases,
      item.gross_amount, item.gross_share_amount, item.withholding_amount, item.net_payable_amount,
    ]);
    row.getCell(3).alignment = { wrapText: true };
    row.getCell(4).numFmt = "#,##0";
    formats(row, [5, 6, 7, 8], money);
    row.getCell(8).font = { bold: true, color: { argb: `FF${GREEN}` } };
    styleBody(row, index);
  });
  if (report.by_holder.length === 0) {
    emptyNote(holders, "Nicio vânzare din această perioadă nu are titular configurat.");
  } else {
    const total = holders.addRow(["TOTAL", "", "", "", "", s.holder_gross_amount, s.withholding_amount, s.holder_net_amount]);
    formats(total, [6, 7, 8], money);
    styleTotal(total);
  }

  // --- Per day --------------------------------------------------------------
  const daily = workbook.addWorksheet("Pe zile", { pageSetup });
  daily.columns = [{ width: 14 }, { width: 14 }, { width: 12 }, { width: 18 }];
  sheetTitle(daily, "Vânzări pe zile", subtitle, 4);
  const dailyHeader = daily.addRow(["Data", "Ziua", "Cumpărări", "Vânzări"]);
  styleHeader(dailyHeader);
  daily.views = [{ state: "frozen", ySplit: dailyHeader.number, showGridLines: false }];
  const days = fillDays(report);
  days.forEach((item, index) => {
    const date = day(item.label);
    // exceljs stores dates as UTC; a local midnight would land on the previous
    // day east of Greenwich.
    const row = daily.addRow([new Date(Date.UTC(date.getFullYear(), date.getMonth(), date.getDate())), weekdayFormat.format(date), item.purchases, item.amount]);
    row.getCell(1).numFmt = "dd.mm.yyyy";
    row.getCell(3).numFmt = "#,##0";
    row.getCell(4).numFmt = money;
    styleBody(row, index);
  });
  dataBar(daily, "D", dailyHeader.number + 1, daily.lastRow!.number);
  const dailyTotal = daily.addRow(["TOTAL", "", s.purchases, s.gross_amount]);
  dailyTotal.getCell(3).numFmt = "#,##0";
  dailyTotal.getCell(4).numFmt = money;
  styleTotal(dailyTotal);

  // --- Breakdowns -----------------------------------------------------------
  const details = workbook.addWorksheet("Cum s-a vândut", { pageSetup, views: [{ showGridLines: false }] });
  details.columns = [{ width: 36 }, { width: 12 }, { width: 18 }, { width: 14 }];
  sheetTitle(details, "Cum s-a vândut", subtitle, 4);
  const breakdown = (title: string, rows: FinanceDimensionRow[], label: (value: string) => string) => {
    const heading = details.addRow([title]);
    heading.getCell(1).font = { bold: true, size: 13, color: { argb: `FF${BRAND}` } };
    styleHeader(details.addRow(["", "Cumpărări", "Vânzări", "Din total"]));
    const first = details.lastRow!.number + 1;
    rows.forEach((item, index) => {
      const row = details.addRow([label(item.label), item.purchases, item.amount, share(item.amount, s.gross_amount) / 100]);
      row.getCell(2).numFmt = "#,##0";
      row.getCell(3).numFmt = money;
      row.getCell(4).numFmt = "0.0%";
      styleBody(row, index);
    });
    if (rows.length === 0) emptyNote(details);
    dataBar(details, "D", first, details.lastRow!.number);
    details.addRow([]);
  };
  breakdown("Unde se află spectatorii", report.countries, (code) => (code === "N/A" ? "Necunoscută" : countryName(code)));
  breakdown("Moldova sau străinătate", report.markets, marketLabel);
  breakdown("Închiriere sau cumpărare", report.durations, durationLabel);
  breakdown("Calitatea video aleasă", report.qualities, (value) => (value === "N/A" ? "Nespecificată" : value));
  breakdown("Cum au plătit", report.payment_methods, (value) => PAYMENT_METHODS[value] ?? value);

  // --- Transactions ---------------------------------------------------------
  const sales = workbook.addWorksheet("Lista vânzărilor", { pageSetup });
  sales.columns = [{ width: 18 }, { width: 32 }, { width: 22 }, { width: 10 }, { width: 18 }, { width: 14 }, { width: 12 }, { width: 14 }, { width: 16 }, { width: 28 }, { width: 12 }, { width: 18 }];
  sheetTitle(sales, "Lista vânzărilor", `${subtitle} · fără date personale ale cumpărătorilor`, 12);
  const salesHeader = sales.addRow(["Data", "Film", "Ofertă", "Calitate", "Țara", "Vânzare", "TVA", "Partea platformei", "Partea titularilor", "Titulari", "Rambursat", "Calcul"]);
  styleHeader(salesHeader);
  sales.views = [{ state: "frozen", ySplit: salesHeader.number }];
  sales.autoFilter = { from: { row: salesHeader.number, column: 1 }, to: { row: salesHeader.number, column: 12 } };
  report.transactions.forEach((item, index) => {
    const row = sales.addRow([
      item.purchased_at ? dateTimeFormat.format(new Date(item.purchased_at)) : "—",
      item.film ?? "—",
      item.offer ?? (item.rental_days ? durationLabel(String(item.rental_days)) : "—"),
      item.quality ?? "—",
      item.country_code ? countryName(item.country_code) : "Necunoscută",
      item.gross_amount, item.vat_amount, item.platform_share_amount, item.holder_share_amount,
      item.holders || "—", item.refund_amount, statusLabel(item.calculation_status),
    ]);
    formats(row, [6, 7, 8, 9, 11], money);
    if (item.calculation_status !== "calculated") row.getCell(12).font = { bold: true, color: { argb: `FF${AMBER}` } };
    styleBody(row, index);
  });
  if (report.transactions.length === 0) emptyNote(sales);

  // --- Costs ----------------------------------------------------------------
  const costs = workbook.addWorksheet("Costuri și vizionări", { pageSetup });
  costs.columns = [{ width: 16 }, { width: 32 }, { width: 10 }, { width: 12 }, { width: 14 }, { width: 14 }, { width: 14 }, { width: 14 }, { width: 14 }, { width: 16 }];
  sheetTitle(costs, "Costuri tehnice și vizionări pe filme", `${subtitle} · costurile sunt în dolari (USD), pe luni întregi`, 10);
  const costHeader = costs.addRow(["Luna", "Film", "Calitate", "Vizionări", "Ore vizionate", "Stocare", "Trafic (livrare)", "Licențe DRM", "Cost total", "Venit estimat"]);
  styleHeader(costHeader);
  costs.views = [{ state: "frozen", ySplit: costHeader.number, showGridLines: false }];
  report.costs.items.forEach((item, index) => {
    const row = costs.addRow([
      monthLabel(item.month), item.title, item.quality ?? "—", item.views, item.watch_hours,
      item.storage_cost_usd, item.delivery_cost_usd, item.drm_cost_usd,
      item.storage_cost_usd + item.delivery_cost_usd + item.drm_cost_usd, item.revenue_usd,
    ]);
    row.getCell(4).numFmt = "#,##0";
    row.getCell(5).numFmt = "#,##0.0";
    formats(row, [6, 7, 8, 9, 10], usd);
    styleBody(row, index);
  });
  if (report.costs.items.length === 0) {
    emptyNote(costs, "Costurile pentru lunile din această perioadă nu au fost încă calculate.");
  } else {
    const t = costTotals(report);
    const total = costs.addRow(["TOTAL", "", "", t.views, t.watch_hours, t.storage, t.delivery, t.drm, t.total, ""]);
    total.getCell(4).numFmt = "#,##0";
    total.getCell(5).numFmt = "#,##0.0";
    formats(total, [6, 7, 8, 9], usd);
    styleTotal(total);
  }

  // --- Glossary -------------------------------------------------------------
  const help = workbook.addWorksheet("Explicații", { pageSetup, views: [{ showGridLines: false }] });
  help.columns = [{ width: 30 }, { width: 100 }];
  sheetTitle(help, "Cum se citește raportul", "Termenii folosiți în acest document", 2);
  styleHeader(help.addRow(["Termen", "Explicație"]));
  GLOSSARY.forEach(([term, meaning], index) => {
    const row = help.addRow([term, meaning]);
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

/** Every day of the period, so days without sales show as zero instead of disappearing. */
function fillDays(report: Report): FinanceDimensionRow[] {
  const byDay = new Map(report.timeline.map((row) => [row.label, row]));
  const rows: FinanceDimensionRow[] = [];
  const end = day(report.period.to);
  for (const cursor = day(report.period.from); cursor <= end; cursor.setDate(cursor.getDate() + 1)) {
    const key = `${cursor.getFullYear()}-${String(cursor.getMonth() + 1).padStart(2, "0")}-${String(cursor.getDate()).padStart(2, "0")}`;
    rows.push(byDay.get(key) ?? { label: key, purchases: 0, amount: 0 });
  }
  return rows;
}

// ---------------------------------------------------------------------------
// PDF
// ---------------------------------------------------------------------------

/* eslint-disable @typescript-eslint/no-explicit-any -- pdfmake's document model is loosely typed. */
export async function downloadFinanceReportPdf(report: Report): Promise<void> {
  const [{ default: pdfMake }, { default: vfs }] = await Promise.all([
    import("pdfmake/build/pdfmake") as Promise<any>,
    import("pdfmake/build/vfs_fonts") as Promise<any>,
  ]);
  // Roboto from pdfmake's bundle covers ă â î ș ț, which the PDF standard fonts do not.
  pdfMake.addVirtualFileSystem(vfs);

  const s = report.summary;
  const cur = s.currency;
  const m = (value: number) => `${moneyFormat.format(value)} ${cur}`;
  const usd = (value: number) => `${moneyFormat.format(value)} $`;
  const color = (hex: string) => `#${hex}`;
  const PDF_TRANSACTION_LIMIT = 300;

  const kpi = (label: string, value: string, hint: string, tone = BRAND) => ({
    stack: [
      { text: label.toUpperCase(), fontSize: 7.5, color: color(MUTED), bold: true, characterSpacing: 0.5 },
      { text: value, fontSize: 17, bold: true, color: color(tone), margin: [0, 3, 0, 2] },
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
  const headerCell = (text: string, alignment: "left" | "right" = "right") => ({ text, bold: true, color: "#FFFFFF", fontSize: 8, alignment });
  const cell = (text: string, alignment: "left" | "right" = "right", extra: Record<string, unknown> = {}) => ({ text, fontSize: 8, alignment, ...extra });
  const totalCell = (text: string, alignment: "left" | "right" = "right") => ({ text, fontSize: 8, bold: true, alignment, fillColor: "#F3F4F6" });
  const empty = (text = "Nu există date pentru această perioadă.") => ({ text, italics: true, color: color(MUTED), fontSize: 9 });
  const sectionTitle = (text: string, lead?: string) => [
    { text, style: "section" },
    ...(lead ? [{ text: lead, style: "lead" }] : []),
  ];

  /** Horizontal bar drawn with vectors, so it prints crisply. */
  const bar = (value: number, max: number, width = 150, fill = BRAND) => ({
    canvas: [
      { type: "rect", x: 0, y: 2, w: width, h: 8, r: 2, color: "#F3F4F6" },
      { type: "rect", x: 0, y: 2, w: max > 0 ? Math.max(value > 0 ? 1 : 0, (value / max) * width) : 0, h: 8, r: 2, color: color(fill) },
    ],
  });

  /** Daily sales as a column chart; empty days stay visible as gaps. */
  const dailyChart = () => {
    const width = 515;
    const height = 110;
    const days = fillDays(report);
    const max = Math.max(1, ...days.map((d) => d.amount));
    const slot = width / Math.max(1, days.length);
    const barWidth = Math.max(1, slot * 0.7);
    const shapes: any[] = [{ type: "line", x1: 0, y1: height, x2: width, y2: height, lineWidth: 0.5, lineColor: color(LINE) }];
    days.forEach((d, i) => {
      const h = (d.amount / max) * (height - 4);
      if (h > 0) shapes.push({ type: "rect", x: i * slot + (slot - barWidth) / 2, y: height - h, w: barWidth, h, color: color(BRAND) });
    });
    return [
      { canvas: shapes },
      {
        columns: [
          { text: shortDateFormat.format(day(report.period.from)), fontSize: 7, color: color(MUTED) },
          { text: `maxim ${m(max)} într-o zi`, fontSize: 7, color: color(MUTED), alignment: "center" },
          { text: shortDateFormat.format(day(report.period.to)), fontSize: 7, color: color(MUTED), alignment: "right" },
        ],
        margin: [0, 3, 0, 0],
      },
    ];
  };

  const breakdownTable = (firstColumn: string, rows: FinanceDimensionRow[], label: (value: string) => string) => {
    if (rows.length === 0) return empty();
    const max = Math.max(1, ...rows.map((r) => r.amount));
    return {
      table: {
        headerRows: 1,
        widths: ["*", 150, 50, 75, 45],
        body: [
          [headerCell(firstColumn, "left"), headerCell(""), headerCell("Cumpărări"), headerCell("Vânzări"), headerCell("%")],
          ...rows.map((r) => [
            cell(label(r.label), "left"),
            { ...bar(r.amount, max, 140), margin: [4, 0, 0, 0] },
            cell(n(r.purchases)),
            cell(m(r.amount)),
            cell(pct(share(r.amount, s.gross_amount))),
          ]),
        ],
      },
      layout: tableLayout,
    };
  };

  const flow = moneyFlow(report);
  const filmMax = Math.max(1, ...report.by_film.map((f) => f.gross_amount));
  const costs = costTotals(report);
  const transactions = report.transactions.slice(0, PDF_TRANSACTION_LIMIT);

  const kpiRows = [
    [
      kpi("Vânzări", m(s.gross_amount), "plătit de spectatori, cu TVA"),
      kpi("Cumpărări", n(s.purchases), s.buyers === 1 ? "de la un singur spectator" : `de la ${n(s.buyers)} spectatori`),
      kpi("Filme vândute", n(s.films_sold), "titluri cu cel puțin o cumpărare"),
    ],
    [
      kpi("De plătit titularilor", m(s.holder_net_amount), "după impozitul reținut", GREEN),
      report.scope.is_holder
        ? kpi("Impozit reținut", m(s.withholding_amount), "achitat la stat pentru tine", INK)
        : kpi("Rămâne platformei", m(s.platform_share_amount), "venitul Filmoteca.md", INK),
      report.top_ups
        ? kpi("Bani depuși în conturi", `${moneyFormat.format(report.top_ups.amount)} ${report.top_ups.currency}`, `${n(report.top_ups.count)} alimentări cu cardul`, INK)
        : kpi("TVA", m(s.vat_amount), "din vânzările în Moldova", INK),
    ],
  ];

  const docDefinition: any = {
    pageSize: "A4",
    pageMargins: [40, 50, 40, 50],
    info: { title: `Raport financiar ${periodLabel(report)}`, author: "Filmoteca.md" },
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
      { text: report.scope.is_holder ? "RAPORTUL FILMELOR TALE" : "RAPORT FINANCIAR", fontSize: 8.5, bold: true, color: color(BRAND), characterSpacing: 1 },
      { text: periodLabel(report), fontSize: 22, bold: true, margin: [0, 2, 0, 8] },
      {
        table: {
          widths: [110, "*"],
          body: [
            ["Monedă", cur === "MDL" ? "Lei moldovenești (MDL)" : cur],
            ["Generat de", report.generated_by ?? "—"],
          ].map(([k, v]) => [{ text: k, color: color(MUTED), fontSize: 9 }, { text: v, bold: true, fontSize: 9 }]),
        },
        layout: "noBorders",
      },
      { canvas: [{ type: "line", x1: 0, y1: 8, x2: 515, y2: 8, lineWidth: 1, lineColor: color(BRAND) }], margin: [0, 0, 0, 10] },

      {
        table: { widths: ["*", "*", "*"], body: kpiRows },
        layout: {
          hLineWidth: () => 0.5,
          vLineWidth: () => 0.5,
          hLineColor: () => color(LINE),
          vLineColor: () => color(LINE),
          fillColor: () => "#FCFCFD",
        },
      },

      ...sectionTitle("Pe scurt"),
      { ul: financeHighlights(report), fontSize: 9.5, margin: [0, 2, 0, 0], lineHeight: 1.35 },

      {
        stack: [
          ...sectionTitle("Unde merg banii", "Pornind de la suma plătită de spectatori: cât e taxă, cât revine titularilor și cât rămâne platformei."),
          {
            table: {
              widths: [170, "*", 85, 40],
              body: [
                [headerCell("", "left"), headerCell(""), headerCell("Suma"), headerCell("%")],
                ...flow.map(([label, value, hint], index) => [
                  { stack: [{ text: label, fontSize: 8.5, bold: index === 0 }, { text: hint, fontSize: 7, color: color(MUTED) }] },
                  { ...bar(value, s.gross_amount, 190, index === 0 ? INK : index === 3 ? GREEN : BRAND), margin: [4, 4, 0, 0] },
                  cell(m(value), "right", { margin: [0, 3, 0, 0] }),
                  cell(pct(share(value, s.gross_amount)), "right", { margin: [0, 3, 0, 0] }),
                ]),
              ],
            },
            layout: tableLayout,
          },
        ],
        unbreakable: true,
      },

      // Kept together so the heading never ends a page without its chart.
      { stack: [...sectionTitle("Vânzări pe zile"), ...dailyChart()], unbreakable: true },

      ...sectionTitle("Vânzări pe filme", "Ce a adus fiecare film și cât din asta revine titularilor."),
      report.by_film.length === 0
        ? empty()
        : {
            table: {
              headerRows: 1,
              widths: ["*", 80, 42, 62, 62, 62],
              body: [
                [headerCell("Film", "left"), headerCell(""), headerCell("Cumpărări"), headerCell("Vânzări"), headerCell("Titularilor"), headerCell("De plătit")],
                ...report.by_film.map((f) => [
                  {
                    stack: [
                      { text: f.title ?? "Fără titlu", fontSize: 8.5, bold: true },
                      { text: f.holders.length ? f.holders.join(", ") : "Fără titular configurat", fontSize: 7, color: color(f.holders.length ? MUTED : AMBER) },
                    ],
                  },
                  { ...bar(f.gross_amount, filmMax, 75), margin: [4, 4, 0, 0] },
                  cell(n(f.purchases), "right", { margin: [0, 3, 0, 0] }),
                  cell(m(f.gross_amount), "right", { margin: [0, 3, 0, 0] }),
                  cell(m(f.holder_gross_amount), "right", { margin: [0, 3, 0, 0] }),
                  cell(m(f.net_payable_amount), "right", { margin: [0, 3, 0, 0], bold: true, color: color(GREEN) }),
                ]),
                [totalCell("TOTAL", "left"), totalCell(""), totalCell(n(s.purchases)), totalCell(m(s.gross_amount)), totalCell(m(s.holder_gross_amount)), totalCell(m(s.holder_net_amount))],
              ],
            },
            layout: tableLayout,
          },

      ...sectionTitle("Cât se datorează fiecărui titular", "Suma „De plătit” este cea care se virează titularului; impozitul reținut se achită la stat în numele lui."),
      report.by_holder.length === 0
        ? empty("Nicio vânzare din această perioadă nu are titular configurat.")
        : {
            table: {
              headerRows: 1,
              widths: ["*", 42, 70, 62, 70],
              body: [
                [headerCell("Titular", "left"), headerCell("Cumpărări"), headerCell("Partea lui"), headerCell("Impozit"), headerCell("De plătit")],
                ...report.by_holder.map((h) => [
                  {
                    stack: [
                      { text: h.name ?? "—", fontSize: 8.5, bold: true },
                      { text: `${personLabel(h.person_type, h.is_vat_registered)} · ${h.films.join(", ")}`, fontSize: 7, color: color(MUTED) },
                    ],
                  },
                  cell(n(h.purchases), "right", { margin: [0, 3, 0, 0] }),
                  cell(m(h.gross_share_amount), "right", { margin: [0, 3, 0, 0] }),
                  cell(m(h.withholding_amount), "right", { margin: [0, 3, 0, 0] }),
                  cell(m(h.net_payable_amount), "right", { margin: [0, 3, 0, 0], bold: true, color: color(GREEN) }),
                ]),
                [totalCell("TOTAL", "left"), totalCell(""), totalCell(m(s.holder_gross_amount)), totalCell(m(s.withholding_amount)), totalCell(m(s.holder_net_amount))],
              ],
            },
            layout: tableLayout,
          },

      ...sectionTitle("Unde se află spectatorii"),
      breakdownTable("Țara", report.countries, (code) => (code === "N/A" ? "Necunoscută" : countryName(code))),

      ...sectionTitle("Închiriere sau cumpărare"),
      breakdownTable("Tip", report.durations, durationLabel),

      ...sectionTitle("Calitatea video aleasă"),
      breakdownTable("Calitate", report.qualities, (value) => (value === "N/A" ? "Nespecificată" : value)),

      ...sectionTitle(
        "Costuri tehnice și vizionări",
        `Ce plătim furnizorului video pentru ${report.costs.months.map(monthLabel).join(", ")}. Sumele sunt în dolari (USD), pe luni întregi.`,
      ),
      report.costs.items.length === 0
        ? empty("Costurile pentru lunile din această perioadă nu au fost încă calculate.")
        : {
            table: {
              headerRows: 1,
              widths: ["*", 48, 48, 52, 52, 48, 55],
              body: [
                [headerCell("Film", "left"), headerCell("Vizionări"), headerCell("Ore"), headerCell("Stocare"), headerCell("Trafic"), headerCell("DRM"), headerCell("Total")],
                ...report.costs.items.map((c) => [
                  { stack: [{ text: c.title, fontSize: 8.5 }, { text: `${monthLabel(c.month)}${c.quality ? ` · ${c.quality}` : ""}`, fontSize: 7, color: color(MUTED) }] },
                  cell(n(c.views)),
                  cell(moneyFormat.format(c.watch_hours).replace(/,00$/, "")),
                  cell(usd(c.storage_cost_usd)),
                  cell(usd(c.delivery_cost_usd)),
                  cell(usd(c.drm_cost_usd)),
                  cell(usd(c.storage_cost_usd + c.delivery_cost_usd + c.drm_cost_usd), "right", { bold: true }),
                ]),
                [totalCell("TOTAL", "left"), totalCell(n(costs.views)), totalCell(moneyFormat.format(costs.watch_hours)), totalCell(usd(costs.storage)), totalCell(usd(costs.delivery)), totalCell(usd(costs.drm)), totalCell(usd(costs.total))],
              ],
            },
            layout: tableLayout,
          },

      { text: "Lista vânzărilor", style: "section", pageBreak: "before" },
      {
        text:
          report.transactions.length > PDF_TRANSACTION_LIMIT
            ? `Cele mai recente ${PDF_TRANSACTION_LIMIT} din ${n(report.transactions.length)} vânzări. Lista completă este în raportul Excel. Fără date personale ale cumpărătorilor.`
            : "Toate vânzările din perioadă, de la cea mai recentă. Fără date personale ale cumpărătorilor.",
        style: "lead",
      },
      transactions.length === 0
        ? empty()
        : {
            table: {
              headerRows: 1,
              widths: [62, "*", 70, 55, 55, 60],
              body: [
                [headerCell("Data", "left"), headerCell("Film", "left"), headerCell("Țara", "left"), headerCell("Vânzare"), headerCell("TVA"), headerCell("Titularilor")],
                ...transactions.map((t) => [
                  cell(t.purchased_at ? dateTimeFormat.format(new Date(t.purchased_at)) : "—", "left"),
                  { stack: [{ text: t.film ?? "—", fontSize: 8 }, { text: [t.offer, t.quality].filter(Boolean).join(" · "), fontSize: 6.5, color: color(MUTED) }] },
                  cell(t.country_code ? countryName(t.country_code) : "Necunoscută", "left"),
                  cell(m(t.gross_amount)),
                  cell(m(t.vat_amount)),
                  cell(t.calculation_status === "calculated" ? m(t.holder_share_amount) : statusLabel(t.calculation_status), "right", t.calculation_status === "calculated" ? {} : { color: color(AMBER) }),
                ]),
              ],
            },
            layout: tableLayout,
          },

      ...sectionTitle("Cum se citește raportul"),
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
