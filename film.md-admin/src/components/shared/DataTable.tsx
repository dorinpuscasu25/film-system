import React, { useState } from "react";
import {
  ArrowUpDownIcon,
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
}

interface DataTableProps<T> {
  data: T[];
  columns: Column<T>[];
  searchPlaceholder?: string;
  onRowClick?: (item: T) => void;
  actions?: React.ReactNode;
  keyExtractor: (item: T) => string;
}

export function DataTable<T>({
  data,
  columns,
  searchPlaceholder = "Caută...",
  onRowClick,
  actions,
  keyExtractor,
}: DataTableProps<T>) {
  const [searchTerm, setSearchTerm] = useState("");
  const [currentPage, setCurrentPage] = useState(1);
  const [selectedRows, setSelectedRows] = useState<Set<string>>(new Set());
  const itemsPerPage = 10;

  const filteredData = data.filter((item: any) => {
    if (!searchTerm) {
      return true;
    }

    return Object.values(item).some((val) =>
      String(val).toLowerCase().includes(searchTerm.toLowerCase()),
    );
  });

  const totalPages = Math.ceil(filteredData.length / itemsPerPage);
  const paginatedData = filteredData.slice(
    (currentPage - 1) * itemsPerPage,
    currentPage * itemsPerPage,
  );

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
            value={searchTerm}
            onChange={(event) => {
              setSearchTerm(event.target.value);
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
                  <div className="flex items-center gap-2">
                    {col.header}
                    {col.sortable ? <ArrowUpDownIcon className="h-3 w-3" /> : null}
                  </div>
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
