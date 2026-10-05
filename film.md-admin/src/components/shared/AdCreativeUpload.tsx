import { useRef, useState } from "react";
import { FilmIcon, Trash2Icon, UploadCloudIcon } from "lucide-react";
import { adminApi } from "../../lib/api";

export interface AdCreativeDraft {
  id?: number;
  name: string;
  media_url: string;
  mime_type: string;
  duration_seconds: number;
  width: number | null;
  height: number | null;
  is_active: boolean;
}

interface AdCreativeUploadProps {
  value: AdCreativeDraft | null;
  onChange: (creative: AdCreativeDraft | null) => void;
}

/**
 * Uploads the advertisement video and fills in its technical details itself.
 *
 * Duration and dimensions are read from the file in the browser rather than
 * typed: an operator has no reason to know the pixel size of a creative, and a
 * wrong value would be written straight into the VAST document.
 */
export function AdCreativeUpload({ value, onChange }: AdCreativeUploadProps) {
  const inputRef = useRef<HTMLInputElement>(null);
  const [isUploading, setIsUploading] = useState(false);
  const [error, setError] = useState<string | null>(null);

  /** Reads duration and dimensions from the chosen file before uploading. */
  const readMetadata = (file: File) =>
    new Promise<{ duration: number; width: number | null; height: number | null }>((resolve) => {
      const element = document.createElement("video");
      const objectUrl = URL.createObjectURL(file);

      const done = (result: { duration: number; width: number | null; height: number | null }) => {
        URL.revokeObjectURL(objectUrl);
        resolve(result);
      };

      element.onloadedmetadata = () =>
        done({
          duration: Number.isFinite(element.duration) ? Math.round(element.duration) : 0,
          width: element.videoWidth || null,
          height: element.videoHeight || null,
        });
      // A file the browser cannot decode still uploads; the server only needs the URL.
      element.onerror = () => done({ duration: 0, width: null, height: null });
      element.src = objectUrl;
    });

  const handleFile = async (event: React.ChangeEvent<HTMLInputElement>) => {
    const file = event.target.files?.[0];
    if (!file) return;

    setError(null);
    setIsUploading(true);

    try {
      const metadata = await readMetadata(file);
      const { url } = await adminApi.uploadAdCreative(file);

      onChange({
        name: file.name.replace(/\.[^.]+$/, ""),
        media_url: url,
        mime_type: file.type || "video/mp4",
        duration_seconds: metadata.duration,
        width: metadata.width,
        height: metadata.height,
        is_active: true,
      });
    } catch (uploadError) {
      setError(uploadError instanceof Error ? uploadError.message : "Încărcarea a eșuat.");
    } finally {
      setIsUploading(false);
      if (inputRef.current) inputRef.current.value = "";
    }
  };

  if (value) {
    return (
      <div className="rounded-xl border border-border bg-muted/30 p-4">
        <div className="flex items-start gap-4">
          <video
            src={value.media_url}
            className="h-24 w-40 shrink-0 rounded-lg bg-black object-contain"
            muted
            playsInline
            controls
          />
          <div className="min-w-0 flex-1">
            <p className="truncate text-sm font-medium">{value.name}</p>
            <p className="mt-1 text-xs text-muted-foreground">
              {value.duration_seconds > 0 ? `${value.duration_seconds} secunde` : "durată necunoscută"}
              {value.width && value.height ? ` · ${value.width}×${value.height}` : ""}
            </p>
          </div>
          <button
            type="button"
            onClick={() => onChange(null)}
            className="inline-flex items-center gap-1.5 rounded-lg border border-destructive/40 px-3 py-1.5 text-xs text-destructive transition-colors hover:bg-destructive/10"
          >
            <Trash2Icon className="h-3.5 w-3.5" />
            Șterge
          </button>
        </div>
      </div>
    );
  }

  return (
    <div>
      <button
        type="button"
        onClick={() => inputRef.current?.click()}
        disabled={isUploading}
        className="flex w-full flex-col items-center gap-2 rounded-xl border-2 border-dashed border-border px-6 py-8 text-center transition-colors hover:border-primary/60 disabled:opacity-60"
      >
        {isUploading ? (
          <>
            <UploadCloudIcon className="h-6 w-6 animate-pulse text-primary" />
            <span className="text-sm font-medium">Se încarcă…</span>
          </>
        ) : (
          <>
            <FilmIcon className="h-6 w-6 text-muted-foreground" />
            <span className="text-sm font-medium">Încarcă video-ul reclamei</span>
            <span className="text-xs text-muted-foreground">MP4 sau WebM, maximum 256 MB</span>
          </>
        )}
      </button>

      <input
        ref={inputRef}
        type="file"
        accept="video/mp4,video/webm,video/quicktime"
        className="hidden"
        onChange={handleFile}
      />

      {error && <p className="mt-2 text-xs text-destructive">{error}</p>}
    </div>
  );
}
