import { useEffect, useMemo, useRef, useState, type RefObject } from "react";
import { CheckIcon, XIcon } from "lucide-react";
import { cn } from "../../lib/utils";
import type { SelectOption } from "../../types";

const ISO_COUNTRY_CODES = `
AD AE AF AG AI AL AM AO AQ AR AS AT AU AW AX AZ BA BB BD BE BF BG BH BI BJ BL BM BN BO BQ BR BS BT BV BW BY BZ
CA CC CD CF CG CH CI CK CL CM CN CO CR CU CV CW CX CY CZ DE DJ DK DM DO DZ EC EE EG EH ER ES ET FI FJ FK FM FO FR
GA GB GD GE GF GG GH GI GL GM GN GP GQ GR GS GT GU GW GY HK HM HN HR HT HU ID IE IL IM IN IO IQ IR IS IT JE JM JO
JP KE KG KH KI KM KN KP KR KW KY KZ LA LB LC LI LK LR LS LT LU LV LY MA MC MD ME MF MG MH MK ML MM MN MO MP MQ MR
MS MT MU MV MW MX MY MZ NA NC NE NF NG NI NL NO NP NR NU NZ OM PA PE PF PG PH PK PL PM PN PR PS PT PW PY QA RE RO
RS RU RW SA SB SC SD SE SG SH SI SJ SK SL SM SN SO SR SS ST SV SX SY SZ TC TD TF TG TH TJ TK TL TM TN TO TR TT TV
TW TZ UA UG UM US UY UZ VA VC VE VG VI VN VU WF WS XK YE YT ZA ZM ZW
`;

function countryLabel(code: string): string {
  try {
    const displayNames = new Intl.DisplayNames(["ro", "en"], { type: "region" });
    return displayNames.of(code) ?? code;
  } catch {
    return code;
  }
}

const ALL_COUNTRY_OPTIONS: SelectOption[] = ISO_COUNTRY_CODES
  .trim()
  .split(/\s+/)
  .map((code) => ({ value: code, label: countryLabel(code) }))
  .sort((left, right) => left.label.localeCompare(right.label, "ro"));

const LOCAL_COUNTRY_LABELS = new Map(ALL_COUNTRY_OPTIONS.map((option) => [String(option.value), option.label]));

interface CountrySelectProps {
  label?: string;
  value: string;
  onChange: (value: string) => void;
  options?: SelectOption[];
  placeholder?: string;
  emptyLabel?: string;
  disabled?: boolean;
  helperText?: string;
  className?: string;
}

export function CountrySelect({
  label,
  value,
  onChange,
  options = ALL_COUNTRY_OPTIONS,
  placeholder = "Caută țara...",
  emptyLabel = "Global",
  disabled = false,
  helperText,
  className,
}: CountrySelectProps) {
  const [query, setQuery] = useState("");
  const [open, setOpen] = useState(false);
  const rootRef = useRef<HTMLDivElement>(null);
  const selected = options.find((option) => String(option.value) === value);
  const visibleOptions = useMemo(() => filterCountries(options, query).slice(0, 80), [options, query]);
  useCloseOnOutside(rootRef, () => setOpen(false));

  return (
    <div ref={rootRef} className={cn("relative space-y-2", className)}>
      {label ? <label className="text-sm font-medium">{label}</label> : null}
      <button
        type="button"
        disabled={disabled}
        onClick={() => setOpen((current) => !current)}
        className="flex h-10 w-full items-center justify-between rounded-md border border-input bg-background px-3 py-2 text-left text-sm"
      >
        <span className={selected ? "" : "text-muted-foreground"}>
          {selected ? `${displayCountryLabel(selected)} (${selected.value})` : emptyLabel}
        </span>
      </button>
      {open ? (
        <div className="absolute left-0 right-0 z-50 mt-1 rounded-md border bg-background p-2 shadow-lg">
          <input
            autoFocus
            value={query}
            disabled={disabled}
            onChange={(event) => setQuery(event.target.value)}
            placeholder={placeholder}
            className="h-9 w-full rounded-md border border-input bg-background px-3 text-sm outline-none focus:ring-2 focus:ring-ring"
          />
          <div className="mt-2 max-h-56 overflow-auto rounded-md border">
            <button
              type="button"
              disabled={disabled}
              onClick={() => {
                onChange("");
                setQuery("");
                setOpen(false);
              }}
              className={cn(
                "flex w-full items-center justify-between px-3 py-2 text-left text-sm hover:bg-accent",
                value === "" ? "bg-accent" : "",
              )}
            >
              <span>{emptyLabel}</span>
              {value === "" ? <CheckIcon className="h-4 w-4" /> : null}
            </button>
            {visibleOptions.map((option) => {
              const optionValue = String(option.value);

              return (
                <button
                  key={optionValue}
                  type="button"
                  disabled={disabled}
                  onClick={() => {
                    onChange(optionValue);
                    setQuery("");
                    setOpen(false);
                  }}
                  className={cn(
                    "flex w-full items-center justify-between px-3 py-2 text-left text-sm hover:bg-accent",
                    optionValue === value ? "bg-accent" : "",
                  )}
                >
                  <span>
                    {displayCountryLabel(option)} <span className="text-muted-foreground">({optionValue})</span>
                  </span>
                  {optionValue === value ? <CheckIcon className="h-4 w-4" /> : null}
                </button>
              );
            })}
          </div>
        </div>
      ) : null}
      {helperText ? <p className="text-sm text-muted-foreground">{helperText}</p> : null}
    </div>
  );
}

interface CountryMultiSelectProps {
  label?: string;
  value: string[];
  onChange: (value: string[]) => void;
  options?: SelectOption[];
  placeholder?: string;
  disabled?: boolean;
  helperText?: string;
  className?: string;
}

export function CountryMultiSelect({
  label,
  value,
  onChange,
  options = ALL_COUNTRY_OPTIONS,
  placeholder = "Caută țări...",
  disabled = false,
  helperText,
  className,
}: CountryMultiSelectProps) {
  const [query, setQuery] = useState("");
  const [open, setOpen] = useState(false);
  const rootRef = useRef<HTMLDivElement>(null);
  const selectedValues = new Set(value);
  const selectedOptions = options.filter((option) => selectedValues.has(String(option.value)));
  const visibleOptions = useMemo(() => filterCountries(options, query).slice(0, 100), [options, query]);
  useCloseOnOutside(rootRef, () => setOpen(false));

  function toggleCountry(code: string) {
    if (selectedValues.has(code)) {
      onChange(value.filter((item) => item !== code));
      return;
    }

    onChange([...value, code]);
  }

  return (
    <div ref={rootRef} className={cn("relative space-y-2", className)}>
      {label ? <label className="text-sm font-medium">{label}</label> : null}
      <div className="rounded-md border bg-background p-2">
        <div
          className="flex min-h-9 flex-wrap gap-2 rounded-md border border-input bg-background px-2 py-1"
          onClick={() => setOpen(true)}
        >
          {selectedOptions.length === 0 ? (
            <span className="py-1.5 text-sm text-muted-foreground">Global / toate țările</span>
          ) : (
            selectedOptions.map((option) => (
              <button
                key={option.value}
                type="button"
                disabled={disabled}
                onClick={() => toggleCountry(String(option.value))}
                className="inline-flex items-center gap-1 rounded-md bg-accent px-2 py-1 text-xs"
              >
                {displayCountryLabel(option)} ({option.value})
                <XIcon className="h-3 w-3" />
              </button>
            ))
          )}
          <input
            value={query}
            disabled={disabled}
            onFocus={() => setOpen(true)}
            onChange={(event) => {
              setQuery(event.target.value);
              setOpen(true);
            }}
            placeholder={selectedOptions.length === 0 ? placeholder : "Caută încă o țară..."}
            className="min-w-40 flex-1 bg-transparent py-1.5 text-sm outline-none"
          />
        </div>
        {open ? (
          <div className="absolute left-0 right-0 z-50 mt-2 max-h-56 overflow-auto rounded-md border bg-background shadow-lg">
            <button
              type="button"
              disabled={disabled}
              onClick={() => {
                onChange([]);
                setQuery("");
              }}
              className={cn(
                "flex w-full items-center justify-between px-3 py-2 text-left text-sm hover:bg-accent",
                value.length === 0 ? "bg-accent" : "",
              )}
            >
              <span>Global / toate țările</span>
              {value.length === 0 ? <CheckIcon className="h-4 w-4" /> : null}
            </button>
            {visibleOptions.map((option) => {
              const optionValue = String(option.value);
              const isSelected = selectedValues.has(optionValue);

              return (
                <button
                  key={optionValue}
                  type="button"
                  disabled={disabled}
                  onClick={() => toggleCountry(optionValue)}
                  className={cn(
                    "flex w-full items-center justify-between px-3 py-2 text-left text-sm hover:bg-accent",
                    isSelected ? "bg-accent" : "",
                  )}
                >
                  <span>
                    {displayCountryLabel(option)} <span className="text-muted-foreground">({optionValue})</span>
                  </span>
                  {isSelected ? <CheckIcon className="h-4 w-4" /> : null}
                </button>
              );
            })}
          </div>
        ) : null}
      </div>
      {helperText ? <p className="text-sm text-muted-foreground">{helperText}</p> : null}
    </div>
  );
}

function filterCountries(options: SelectOption[], query: string): SelectOption[] {
  const normalizedQuery = query.trim().toLowerCase();

  if (normalizedQuery === "") {
    return options;
  }

  return options.filter((option) => {
    return `${displayCountryLabel(option)} ${option.label} ${option.value}`.toLowerCase().includes(normalizedQuery);
  });
}

function displayCountryLabel(option: SelectOption): string {
  return LOCAL_COUNTRY_LABELS.get(String(option.value)) ?? option.label;
}

function useCloseOnOutside(ref: RefObject<HTMLElement>, onClose: () => void) {
  useEffect(() => {
    function handlePointerDown(event: MouseEvent) {
      if (!ref.current?.contains(event.target as Node)) {
        onClose();
      }
    }

    document.addEventListener("mousedown", handlePointerDown);

    return () => document.removeEventListener("mousedown", handlePointerDown);
  }, [onClose, ref]);
}
