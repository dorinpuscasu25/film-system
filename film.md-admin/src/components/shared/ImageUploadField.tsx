import React, { useRef, useState } from "react";
import { ImagePlusIcon, Link2Icon, Loader2Icon, Trash2Icon } from "lucide-react";
import { cn } from "../../lib/utils";
import { adminApi } from "../../lib/api";
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
  /** R2 subdirectory for this upload (e.g. "content/posters"). Defaults to "uploads". */
  uploadDirectory?: string;
}

export function ImageUploadField({
  label,
  value,
  onChange,
  error,
  helperText,
  previewLabel = "Previzualizare",
  aspectClassName = "aspect-video",
  uploadDirectory = "uploads",
}: ImageUploadFieldProps) {
  const inputId = label.toLowerCase().replace(/\s+/g, "-");
  const fileInputRef = useRef<HTMLInputElement | null>(null);
  const [uploading, setUploading] = useState(false);
  const [uploadError, setUploadError] = useState<string | null>(null);

  async function handleFileChange(event: React.ChangeEvent<HTMLInputElement>) {
    const file = event.target.files?.[0];
    if (!file) {
      return;
    }

    setUploading(true);
    setUploadError(null);

    try {
      const response = await adminApi.uploadFile(file, uploadDirectory);
      onChange(response.url);
    } catch (err: unknown) {
      const message =
        err instanceof Error ? err.message : "Eroare la încărcarea imaginii.";
      setUploadError(message);
    } finally {
      setUploading(false);
      event.target.value = "";
    }
  }

  const displayError = error ?? uploadError;

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
              onChange={(event) => {
                setUploadError(null);
                onChange(event.target.value);
              }}
              className={cn("pl-9", displayError ? "border-destructive focus-visible:ring-destructive/30" : "")}
              placeholder="https://... sau încarcă imagine"
              disabled={uploading}
            />
          </div>

          <div className="flex flex-wrap items-center gap-2">
            <Button
              type="button"
              variant="outline"
              onClick={() => fileInputRef.current?.click()}
              disabled={uploading}
            >
              {uploading ? (
                <Loader2Icon className="h-4 w-4 animate-spin" />
              ) : (
                <ImagePlusIcon className="h-4 w-4" />
              )}
              {uploading ? "Se încarcă..." : "Încarcă imagine"}
            </Button>
            <Button
              type="button"
              variant="ghost"
              onClick={() => {
                setUploadError(null);
                onChange("");
              }}
              disabled={!value || uploading}
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

          {displayError ? <p className="text-sm text-destructive">{displayError}</p> : null}
          {helperText && !displayError ? <p className="text-sm text-muted-foreground">{helperText}</p> : null}
        </div>

        <div className="space-y-2">
          <div className="text-xs font-medium uppercase tracking-wide text-muted-foreground">{previewLabel}</div>
          <div className={cn("overflow-hidden rounded-xl border bg-muted", aspectClassName)}>
            {uploading ? (
              <div className="flex h-full w-full items-center justify-center">
                <Loader2Icon className="h-8 w-8 animate-spin text-muted-foreground" />
              </div>
            ) : value ? (
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
