/**
 * Bootstraps Google Analytics 4 if a measurement ID is configured in the
 * platform_settings (caiet de sarcini §8). Loaded lazily after first paint.
 */
const API_BASE = (import.meta.env.VITE_API_URL ?? 'http://localhost:8000/api/v1') as string;

declare global {
  interface Window {
    gtag?: (...args: unknown[]) => void;
    dataLayer?: unknown[];
  }
}

let initialized = false;

export async function initGA4(): Promise<void> {
  if (initialized) return;
  try {
    const res = await fetch(`${API_BASE}/public/settings`);
    if (!res.ok) return;
    const data = (await res.json()) as { ga4_measurement_id?: string | null };
    const id = data.ga4_measurement_id;
    if (!id) return;
    initialized = true;
    const script = document.createElement('script');
    script.async = true;
    script.src = `https://www.googletagmanager.com/gtag/js?id=${encodeURIComponent(id)}`;
    document.head.appendChild(script);
    window.dataLayer = window.dataLayer ?? [];
    window.gtag = function gtag(...args: unknown[]) {
      window.dataLayer?.push(args);
    };
    window.gtag('js', new Date());
    window.gtag('config', id);
  } catch {
    /* swallow — analytics is best-effort */
  }
}

export function trackEvent(name: string, params: Record<string, unknown> = {}): void {
  if (typeof window !== 'undefined' && typeof window.gtag === 'function') {
    window.gtag('event', name, params);
  }
}
