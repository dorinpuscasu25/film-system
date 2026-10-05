import { useMemo, useState } from "react";
import { CheckIcon, SearchIcon, XIcon } from "lucide-react";
import type { AdCampaignOptionContent } from "../../types";

interface ContentMultiSelectProps {
  contents: AdCampaignOptionContent[];
  value: number[];
  onChange: (ids: number[]) => void;
  emptyLabel: string;
}

/**
 * Searchable multi-select over the film catalogue.
 *
 * The catalogue is large enough that a plain <select multiple> is unusable, and
 * an operator picking films for a campaign needs to find them by title.
 */
export function ContentMultiSelect({ contents, value, onChange, emptyLabel }: ContentMultiSelectProps) {
  const [query, setQuery] = useState("");

  const selected = useMemo(
    () => contents.filter((content) => value.includes(content.id)),
    [contents, value],
  );

  const matches = useMemo(() => {
    const term = query.trim().toLowerCase();
    const pool = contents.filter((content) => !value.includes(content.id));
    if (!term) return pool.slice(0, 50);
    return pool.filter((content) => content.title.toLowerCase().includes(term)).slice(0, 50);
  }, [contents, query, value]);

  return (
    <div className="space-y-3">
      {selected.length > 0 ? (
        <div className="flex flex-wrap gap-2">
          {selected.map((content) => (
            <span
              key={content.id}
              className="inline-flex items-center gap-1.5 rounded-full border border-border bg-muted/50 py-1 pl-3 pr-1.5 text-xs"
            >
              {content.title}
              <button
                type="button"
                aria-label={`Elimină ${content.title}`}
                onClick={() => onChange(value.filter((id) => id !== content.id))}
                className="rounded-full p-0.5 transition-colors hover:bg-destructive/20 hover:text-destructive"
              >
                <XIcon className="h-3 w-3" />
              </button>
            </span>
          ))}
        </div>
      ) : (
        <p className="text-xs text-muted-foreground">{emptyLabel}</p>
      )}

      <div className="relative">
        <SearchIcon className="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-muted-foreground" />
        <input
          type="search"
          value={query}
          onChange={(event) => setQuery(event.target.value)}
          placeholder="Caută un film după titlu…"
          className="w-full rounded-lg border border-border bg-background py-2 pl-9 pr-3 text-sm outline-none focus:border-primary"
        />
      </div>

      {query.trim() !== "" && (
        <div className="max-h-56 overflow-y-auto rounded-lg border border-border">
          {matches.length === 0 ? (
            <p className="p-3 text-xs text-muted-foreground">Niciun film găsit.</p>
          ) : (
            matches.map((content) => (
              <button
                key={content.id}
                type="button"
                onClick={() => {
                  onChange([...value, content.id]);
                  setQuery("");
                }}
                className="flex w-full items-center justify-between gap-3 border-b border-border px-3 py-2 text-left text-sm transition-colors last:border-0 hover:bg-muted/50"
              >
                <span className="truncate">{content.title}</span>
                <CheckIcon className="h-4 w-4 shrink-0 opacity-0" />
              </button>
            ))
          )}
        </div>
      )}
    </div>
  );
}
