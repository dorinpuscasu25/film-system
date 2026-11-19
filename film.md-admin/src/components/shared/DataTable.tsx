import React, { useEffect, useState } from "react";
import {
  ArrowDownIcon,
  ArrowUpDownIcon,
  ArrowUpIcon,
  ChevronLeftIcon,
  ChevronRightIcon,
  SearchIcon,
} from "lucide-react";
import { Button } from "../ui/button";
import { Card, CardContent } from "../ui/card";
import { Input } from "../ui/input";
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from "../ui/table";

export interface Column<T> {
  key: string;
  header: string;
  render?: (item: T) => React.ReactNode;
  sortable?: boolean;
  /** Makes the header clickable and sorts rows by this value (empty values always last). */
  sortValue?: (item: T) => string | number | null | undefined;
}

type SortState = { key: string; direction: "asc" | "desc" } | null;

const sortCollator = new Intl.Collator(undefined, { numeric: true, sensitivity: "base" });

function isEmptySortValue(value: string | number | null | undefined) {
  return value === null || value === undefined || (typeof value === "string" && value.trim() === "");
}

interface DataTableProps<T> {
  data: T[];
  columns: Column<T>[];
  searchPlaceholder?: string;
  searchTerm?: string;
  onSearchTermChange?: (term: string) => void;
  disableLocalSearch?: boolean;
  onRowClick?: (item: T) => void;
  actions?: React.ReactNode;
  keyExtractor: (item: T) => string;
}

export function DataTable<T>({
  data,
  columns,
  searchPlaceholder = "Caută...",
  searchTerm,
  onSearchTermChange,
  disableLocalSearch = false,
  onRowClick,
  actions,
  keyExtractor,
}: DataTableProps<T>) {
  const [internalSearchTerm, setInternalSearchTerm] = useState("");
  const [currentPage, setCurrentPage] = useState(1);
  const [selectedRows, setSelectedRows] = useState<Set<string>>(new Set());
  const [sort, setSort] = useState<SortState>(null);
  const itemsPerPage = 10;
  const resolvedSearchTerm = searchTerm ?? internalSearchTerm;

  const filteredData = disableLocalSearch ? data : data.filter((item: any) => {
    if (!resolvedSearchTerm) {
      return true;
    }

    return Object.values(item).some((val) =>
      String(val).toLowerCase().includes(resolvedSearchTerm.toLowerCase()),
    );
  });

  const sortColumn = sort ? columns.find((col) => col.key === sort.key && col.sortValue) : undefined;
  const sortedData = sortColumn?.sortValue && sort
    ? [...filteredData].sort((left, right) => {
        const leftValue = sortColumn.sortValue!(left);
        const rightValue = sortColumn.sortValue!(right);
        const leftEmpty = isEmptySortValue(leftValue);
        const rightEmpty = isEmptySortValue(rightValue);

        if (leftEmpty || rightEmpty) {
          return leftEmpty === rightEmpty ? 0 : leftEmpty ? 1 : -1;
        }

        const result = typeof leftValue === "number" && typeof rightValue === "number"
          ? leftValue - rightValue
          : sortCollator.compare(String(leftValue), String(rightValue));

        return sort.direction === "asc" ? result : -result;
      })
    : filteredData;

  function toggleSort(key: string) {
    setSort((current) => {
      if (!current || current.key !== key) {
        return { key, direction: "asc" };
      }

      return current.direction === "asc" ? { key, direction: "desc" } : null;
    });
    setCurrentPage(1);
  }

  const totalPages = Math.ceil(sortedData.length / itemsPerPage);
  const paginatedData = sortedData.slice(
    (currentPage - 1) * itemsPerPage,
    currentPage * itemsPerPage,
  );

  useEffect(() => {
    setCurrentPage(1);
  }, [data, resolvedSearchTerm]);

  function toggleAll() {
    if (selectedRows.size === paginatedData.length) {
      setSelectedRows(new Set());
      return;
    }

    setSelectedRows(new Set(paginatedData.map(keyExtractor)));
  }

  function toggleRow(id: string) {
    const next = new Set(selectedRows);
    if (next.has(id)) {
      next.delete(id);
    } else {
      next.add(id);
    }
    setSelectedRows(next);
  }

  return (
    <Card>
      <div className="table-toolbar">
        <div className="relative w-full max-w-sm">
          <SearchIcon className="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-muted-foreground" />
          <Input
            className="pl-9"
            placeholder={searchPlaceholder}
            value={resolvedSearchTerm}
            onChange={(event) => {
              const nextSearchTerm = event.target.value;
              if (onSearchTermChange) {
                onSearchTermChange(nextSearchTerm);
              } else {
                setInternalSearchTerm(nextSearchTerm);
              }
              setCurrentPage(1);
            }}
          />
        </div>

        <div className="flex flex-wrap items-center gap-2">
          {selectedRows.size > 0 ? (
            <div className="rounded-md border bg-muted px-3 py-2 text-sm text-muted-foreground">
              {selectedRows.size} selectate
            </div>
          ) : null}
          {actions}
        </div>
      </div>

      <CardContent className="p-0">
        <Table>
          <TableHeader>
            <TableRow>
              <TableHead className="w-10">
                <input
                  type="checkbox"
                  className="h-4 w-4 rounded border-input"
                  checked={paginatedData.length > 0 && selectedRows.size === paginatedData.length}
                  onChange={toggleAll}
                />
              </TableHead>
              {columns.map((col) => (
                <TableHead key={col.key}>
                  {col.sortValue ? (
                    <button
                      type="button"
                      className="flex items-center gap-2 hover:text-foreground"
                      onClick={() => toggleSort(col.key)}
                      title="Sortează"
                    >
                      {col.header}
                      {sort?.key === col.key ? (
                        sort.direction === "asc" ? <ArrowUpIcon className="h-3 w-3" /> : <ArrowDownIcon className="h-3 w-3" />
                      ) : (
                        <ArrowUpDownIcon className="h-3 w-3" />
                      )}
                    </button>
                  ) : (
                    <div className="flex items-center gap-2">
                      {col.header}
                      {col.sortable ? <ArrowUpDownIcon className="h-3 w-3" /> : null}
                    </div>
                  )}
                </TableHead>
              ))}
            </TableRow>
          </TableHeader>
          <TableBody>
            {paginatedData.length === 0 ? (
              <TableRow>
                <TableCell colSpan={columns.length + 1} className="h-24 text-center text-muted-foreground">
                  Nu există date.
                </TableCell>
              </TableRow>
            ) : (
              paginatedData.map((item) => {
                const id = keyExtractor(item);
                const isSelected = selectedRows.has(id);

                return (
                  <TableRow
                    key={id}
                    className={onRowClick ? "cursor-pointer" : ""}
                    data-state={isSelected ? "selected" : undefined}
                    onClick={() => onRowClick && onRowClick(item)}
                  >
                    <TableCell onClick={(event) => event.stopPropagation()}>
                      <input
                        type="checkbox"
                        className="h-4 w-4 rounded border-input"
                        checked={isSelected}
                        onChange={() => toggleRow(id)}
                      />
                    </TableCell>
                    {columns.map((col) => (
                      <TableCell key={col.key}>
                        {col.render ? col.render(item) : String((item as any)[col.key] || "")}
                      </TableCell>
                    ))}
                  </TableRow>
                );
              })
            )}
          </TableBody>
        </Table>

        <div className="flex flex-col gap-4 border-t px-4 py-4 sm:flex-row sm:items-center sm:justify-between">
          <p className="text-sm text-muted-foreground">
            Afișezi{" "}
            <span className="font-medium text-foreground">
              {filteredData.length === 0 ? 0 : (currentPage - 1) * itemsPerPage + 1}
            </span>{" "}
            până la{" "}
            <span className="font-medium text-foreground">
              {Math.min(currentPage * itemsPerPage, filteredData.length)}
            </span>{" "}
            din <span className="font-medium text-foreground">{filteredData.length}</span> rezultate
          </p>

          <div className="flex items-center gap-2">
            <Button
              variant="outline"
              size="icon"
              disabled={currentPage === 1}
              onClick={() => setCurrentPage((page) => Math.max(1, page - 1))}
            >
              <ChevronLeftIcon className="h-4 w-4" />
            </Button>
            <div className="rounded-md border px-3 py-2 text-sm">
              Pagina {currentPage} din {totalPages || 1}
            </div>
            <Button
              variant="outline"
              size="icon"
              disabled={currentPage === totalPages || totalPages === 0}
              onClick={() => setCurrentPage((page) => Math.min(totalPages, page + 1))}
            >
              <ChevronRightIcon className="h-4 w-4" />
            </Button>
          </div>
        </div>
      </CardContent>
    </Card>
  );
}
