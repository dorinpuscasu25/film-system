import React, { useRef, useState } from "react";
import { HelpCircleIcon, ImagePlusIcon, Link2Icon, Loader2Icon, Trash2Icon } from "lucide-react";
import { cn } from "../../lib/utils";
import { adminApi } from "../../lib/api";
import { Button } from "../ui/button";
import { Input } from "../ui/input";
import { Label } from "../ui/label";

const MAX_IMAGE_UPLOAD_BYTES = 256 * 1024 * 1024;
const MAX_IMAGE_UPLOAD_MB = MAX_IMAGE_UPLOAD_BYTES / 1024 / 1024;

interface ImageRecommendation {
  resolution: string;
  formats: string;
  note?: string;
}

interface ImageUploadFieldProps {
  label: string;
  value: string;
  onChange: (value: string) => void;
  error?: string;
  helperText?: string;
  recommendation?: ImageRecommendation;
  previewLabel?: string;
  aspectClassName?: string;
  /** R2 subdirectory for this upload (e.g. "content/posters"). Defaults to "uploads". */
  uploadDirectory?: string;
}

function getDefaultRecommendation(label: string): ImageRecommendation {
  const normalized = label.toLowerCase();

  if (normalized.includes("poster")) {
    return {
      resolution: "1200 x 1800 px, raport 2:3",
      formats: "WebP, JPG/JPEG sau PNG",
      note: "Imagine verticală, fără text foarte mic pe margini.",
    };
  }

  if (normalized.includes("mobile")) {
    return {
      resolution: "1080 x 1440 px, raport 3:4",
      formats: "WebP, JPG/JPEG sau PNG",
      note: "Centrată pe subiect, ca să arate bine pe telefon.",
    };
  }

  if (normalized.includes("backdrop") || normalized.includes("desktop") || normalized.includes("preview")) {
    return {
      resolution: "1920 x 1080 px, raport 16:9",
      formats: "WebP, JPG/JPEG sau PNG",
      note: "Potrivită pentru zone late și galerie.",
    };
  }

  return {
    resolution: "Minim 1440 px pe latura mare",
    formats: "WebP, JPG/JPEG sau PNG",
  };
}

export function ImageUploadField({
  label,
  value,
  onChange,
  error,
  helperText,
  recommendation,
  previewLabel = "Previzualizare",
  aspectClassName = "aspect-video",
  uploadDirectory = "uploads",
}: ImageUploadFieldProps) {
  const inputId = label.toLowerCase().replace(/\s+/g, "-");
  const fileInputRef = useRef<HTMLInputElement | null>(null);
  const [uploading, setUploading] = useState(false);
  const [uploadError, setUploadError] = useState<string | null>(null);
  const imageRecommendation = recommendation ?? getDefaultRecommendation(label);

  async function handleFileChange(event: React.ChangeEvent<HTMLInputElement>) {
    const file = event.target.files?.[0];
    if (!file) {
      return;
    }

    if (file.size > MAX_IMAGE_UPLOAD_BYTES) {
      setUploadError(`Imaginea este prea mare. Limita curentă este ${MAX_IMAGE_UPLOAD_MB} MB.`);
      event.target.value = "";
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
      <div className="flex items-center gap-2">
        <Label htmlFor={inputId}>{label}</Label>
        <div className="group relative inline-flex">
          <button
            type="button"
            className="inline-flex h-5 w-5 items-center justify-center rounded-full text-muted-foreground transition hover:bg-accent hover:text-foreground focus:outline-none focus:ring-2 focus:ring-ring focus:ring-offset-2"
            aria-label={`Recomandări pentru ${label}`}
          >
            <HelpCircleIcon className="h-4 w-4" />
          </button>
          <div
            role="tooltip"
            className="pointer-events-none absolute left-1/2 top-full z-30 mt-2 w-72 -translate-x-1/2 rounded-md border bg-popover p-3 text-sm text-popover-foreground opacity-0 shadow-lg transition group-hover:opacity-100 group-focus-within:opacity-100"
          >
            <div className="font-medium">Recomandări imagine</div>
            <div className="mt-2 space-y-1 text-muted-foreground">
              <p>
                <span className="text-foreground">Rezoluție:</span> {imageRecommendation.resolution}
              </p>
              <p>
                <span className="text-foreground">Formate:</span> {imageRecommendation.formats}
              </p>
              <p>
                <span className="text-foreground">Dimensiune:</span> maxim {MAX_IMAGE_UPLOAD_MB} MB
              </p>
              {imageRecommendation.note ? <p>{imageRecommendation.note}</p> : null}
            </div>
          </div>
        </div>
      </div>

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
