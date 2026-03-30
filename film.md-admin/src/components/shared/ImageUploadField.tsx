import React, { useRef } from "react";
import { ImagePlusIcon, Link2Icon, Trash2Icon } from "lucide-react";
import { cn } from "../../lib/utils";
import { Button } from "../ui/button";
import { Input } from "../ui/input";
import { Label } from "../ui/label";

interface ImageUploadFieldProps {
  label: string;
  value: string;
  onChange: (value: string) => void;
  error?: string;
  helperText?: string;
  previewLabel?: string;
  aspectClassName?: string;
}

function readFileAsDataUrl(file: File): Promise<string> {
  return new Promise((resolve, reject) => {
    const reader = new FileReader();

    reader.onload = () => resolve(String(reader.result ?? ""));
    reader.onerror = () => reject(new Error("Nu am putut citi imaginea selectată."));
    reader.readAsDataURL(file);
  });
}

export function ImageUploadField({
  label,
  value,
  onChange,
  error,
  helperText,
  previewLabel = "Previzualizare",
  aspectClassName = "aspect-video",
}: ImageUploadFieldProps) {
  const inputId = label.toLowerCase().replace(/\s+/g, "-");
  const fileInputRef = useRef<HTMLInputElement | null>(null);

  async function handleFileChange(event: React.ChangeEvent<HTMLInputElement>) {
    const file = event.target.files?.[0];
    if (!file) {
      return;
    }

    const dataUrl = await readFileAsDataUrl(file);
    onChange(dataUrl);
    event.target.value = "";
  }

  return (
    <div className="space-y-3">
      <Label htmlFor={inputId}>{label}</Label>

      <div className="grid gap-4 xl:grid-cols-[minmax(0,1fr)_220px]">
        <div className="space-y-3">
          <div className="relative">
            <Link2Icon className="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-muted-foreground" />
            <Input
              id={inputId}
              value={value}
              onChange={(event) => onChange(event.target.value)}
              className={cn("pl-9", error ? "border-destructive focus-visible:ring-destructive/30" : "")}
              placeholder="https://... sau încărcare locală"
            />
          </div>

          <div className="flex flex-wrap gap-2">
            <Button type="button" variant="outline" onClick={() => fileInputRef.current?.click()}>
              <ImagePlusIcon className="h-4 w-4" />
              Încarcă imagine
            </Button>
            <Button
              type="button"
              variant="ghost"
              onClick={() => onChange("")}
              disabled={!value}
            >
              <Trash2Icon className="h-4 w-4" />
              Golește
            </Button>
          </div>

          <input
            ref={fileInputRef}
            type="file"
            accept="image/*"
            className="hidden"
            onChange={(event) => {
              void handleFileChange(event);
            }}
          />

          {error ? <p className="text-sm text-destructive">{error}</p> : null}
          {helperText && !error ? <p className="text-sm text-muted-foreground">{helperText}</p> : null}
        </div>

        <div className="space-y-2">
          <div className="text-xs font-medium uppercase tracking-wide text-muted-foreground">{previewLabel}</div>
          <div className={cn("overflow-hidden rounded-xl border bg-muted", aspectClassName)}>
            {value ? (
              <img src={value} alt={`${label} preview`} className="h-full w-full object-cover" />
            ) : (
              <div className="flex h-full w-full items-center justify-center px-4 text-center text-sm text-muted-foreground">
                Nicio imagine selectată
              </div>
            )}
          </div>
        </div>
      </div>
    </div>
  );
}
