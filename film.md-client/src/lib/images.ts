const API_URL = import.meta.env.VITE_API_URL ?? "https://filmmd-api.veezify.com/api/v1";
const IMAGE_CDN_HOSTS = String(import.meta.env.VITE_IMAGE_CDN_HOSTS ?? ".r2.dev")
  .split(",")
  .map((host) => host.trim().toLowerCase())
  .filter(Boolean);

interface ImageResizeOptions {
  width: number;
  height?: number;
  fit?: "cover" | "contain";
}

export function resizedImageUrl(url: string | undefined | null, options: ImageResizeOptions): string {
  const source = (url ?? "").trim();
  if (!source || source.startsWith("data:") || source.startsWith("blob:")) {
    return source;
  }

  if (!isResizableImageHost(source)) {
    return source;
  }

  const params = new URLSearchParams({
    url: source,
    w: String(options.width),
    fit: options.fit ?? "cover",
  });

  if (options.height) {
    params.set("h", String(options.height));
  }

  return `${API_URL}/public/images/resize?${params.toString()}`;
}

function isResizableImageHost(url: string): boolean {
  try {
    const hostname = new URL(url).hostname.toLowerCase();

    return IMAGE_CDN_HOSTS.some((host) => (
      host.startsWith(".") ? hostname.endsWith(host) : hostname === host
    ));
  } catch {
    return false;
  }
}

export function imageSrcSet(
  url: string | undefined | null,
  variants: Array<ImageResizeOptions & { descriptor: string }>,
): string {
  return variants
    .map((variant) => `${resizedImageUrl(url, variant)} ${variant.descriptor}`)
    .join(", ");
}
