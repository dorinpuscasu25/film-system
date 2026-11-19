import { useEffect, useId, useRef, useState } from 'react';
import { CheckCircle2Icon, Loader2Icon, RefreshCwIcon } from 'lucide-react';
import { useLanguage } from '../contexts/LanguageContext';
import {
  fetchStorefrontWalletTopUp,
  submitStorefrontCardResult,
  type StorefrontTopUpPayload,
} from '../lib/session';

/** Minimal surface of the CyberSource Unified Checkout v1 library (window.VAS). */
interface UnifiedCheckoutClient {
  on(event: 'error', handler: (error: { source?: string; code?: string; message?: string }) => void): void;
  createCheckout(options: { autoProcessing: boolean }): Promise<UnifiedCheckoutCheckout>;
  destroy(): void;
}

interface UnifiedCheckoutCheckout {
  mount(target: string | { paymentSelection: string; paymentScreen: string }): Promise<string>;
  destroy(): void;
}

declare global {
  interface Window {
    VAS?: { UnifiedCheckout(sessionJwt: string): Promise<UnifiedCheckoutClient> };
  }
}

const POLL_INTERVAL_MS = 3000;
const scriptLoads = new Map<string, Promise<void>>();

/** Loads the library from the URL and SRI hash the server read from the session (never hard-coded). */
function loadLibrary(src: string, integrity: string | null): Promise<void> {
  const cached = scriptLoads.get(src);
  if (cached) {
    return cached;
  }

  const promise = new Promise<void>((resolve, reject) => {
    const script = document.createElement('script');
    script.src = src;
    if (integrity) {
      script.integrity = integrity;
    }
    script.crossOrigin = 'anonymous';
    script.async = true;
    script.onload = () => resolve();
    script.onerror = () => {
      scriptLoads.delete(src);
      script.remove();
      reject(new Error('library'));
    };
    document.head.appendChild(script);
  });

  scriptLoads.set(src, promise);

  return promise;
}

interface CardCheckoutProps {
  topUp: StorefrontTopUpPayload;
  onPaid: (topUp: StorefrontTopUpPayload) => void;
  onRetry: () => void;
}

type Phase = 'loading' | 'form' | 'confirming' | 'paid' | 'failed';

/**
 * Victoriabank card form (CyberSource Unified Checkout) embedded in our page — no redirect,
 * 3-D Secure challenge in an iframe (bank rule R13). The browser only forwards the signed
 * result; the server decides whether the payment counts (R9).
 */
export function CardCheckout({ topUp: initialTopUp, onPaid, onRetry }: CardCheckoutProps) {
  const { t } = useLanguage();
  const baseId = useId().replace(/[^a-zA-Z0-9_-]/g, '');
  const selectionId = `uc-buttons-${baseId}`;
  const screenId = `uc-screen-${baseId}`;
  const [phase, setPhase] = useState<Phase>('loading');
  const [topUp, setTopUp] = useState(initialTopUp);
  const [message, setMessage] = useState<string | null>(null);
  const paidReported = useRef(false);
  const tRef = useRef(t);
  tRef.current = t;

  useEffect(() => {
    const t = tRef.current;
    const session = initialTopUp.card_session;
    let cancelled = false;
    let client: UnifiedCheckoutClient | null = null;
    let checkout: UnifiedCheckoutCheckout | null = null;

    if (!session?.session_jwt || !session.client_library) {
      setPhase('failed');
      setMessage(t('wallet.card_session_missing'));
      return undefined;
    }

    (async () => {
      try {
        await loadLibrary(session.client_library as string, session.client_library_integrity);
        if (cancelled || !window.VAS) {
          return;
        }

        client = await window.VAS.UnifiedCheckout(session.session_jwt);
        client.on('error', (error) => console.warn('Unified Checkout', error.source, error.code));
        checkout = await client.createCheckout({ autoProcessing: true });
        if (cancelled) {
          return;
        }

        setPhase('form');
        const resultJwt = await checkout.mount({ paymentSelection: `#${selectionId}`, paymentScreen: `#${screenId}` });
        if (cancelled) {
          return;
        }

        setPhase('confirming');
        const response = await submitStorefrontCardResult(initialTopUp.id, resultJwt);
        if (!cancelled) {
          setTopUp(response.top_up);
        }
      } catch (error) {
        if (cancelled) {
          return;
        }
        const reason = (error as { reason?: string })?.reason;
        // A declined / failed 3-D Secure card can be retried with a new session.
        setPhase('failed');
        setMessage(reason === 'COMPLETE_AUTHENTICATION_FAILED' ? t('wallet.card_3ds_failed') : t('wallet.card_failed'));
      }
    })();

    return () => {
      cancelled = true;
      checkout?.destroy();
      client?.destroy();
    };
  }, [initialTopUp, screenId, selectionId]);

  // The server may still be confirming (gateway not searchable yet): poll until it decides.
  useEffect(() => {
    if (topUp.status === 'paid') {
      setPhase('paid');
      if (!paidReported.current) {
        paidReported.current = true;
        onPaid(topUp);
      }
      return undefined;
    }

    if (topUp.status === 'failed' || topUp.status === 'canceled' || topUp.status === 'refunded') {
      setPhase('failed');
      setMessage(t('wallet.card_failed'));
      return undefined;
    }

    if (phase !== 'confirming') {
      return undefined;
    }

    const timer = window.setInterval(() => {
      fetchStorefrontWalletTopUp(topUp.id)
        .then((response) => setTopUp(response.top_up))
        .catch(() => undefined);
    }, POLL_INTERVAL_MS);

    return () => window.clearInterval(timer);
  }, [onPaid, phase, t, topUp]);

  if (phase === 'paid') {
    return (
      <div className="rounded-2xl border border-accentGreen/30 bg-accentGreen/10 p-6 text-center">
        <CheckCircle2Icon className="mx-auto mb-3 h-12 w-12 text-accentGreen" />
        <p className="text-lg font-semibold text-white">{t('wallet.card_paid')}</p>
        <p className="mt-1 text-sm text-gray-300">
          {topUp.currency} {Number(topUp.amount).toFixed(2)}
        </p>
      </div>
    );
  }

  if (phase === 'failed') {
    return (
      <div className="rounded-2xl border border-white/10 bg-black/20 p-6 text-center">
        <p className="font-semibold text-white">{message ?? t('wallet.card_failed')}</p>
        <button
          type="button"
          onClick={onRetry}
          className="mt-4 inline-flex items-center gap-2 rounded-xl bg-white px-5 py-3 font-bold text-background transition hover:bg-gray-200"
        >
          <RefreshCwIcon className="h-4 w-4" />
          {t('wallet.card_try_again')}
        </button>
      </div>
    );
  }

  return (
    <div className="rounded-2xl border border-white/10 bg-surfaceHover/60 p-5">
      <p className="mb-4 text-center text-2xl font-bold text-white">
        {topUp.currency} {Number(topUp.amount).toFixed(2)}
      </p>

      {phase !== 'form' ? (
        <div className="flex items-center justify-center gap-2 py-6 text-sm text-gray-300">
          <Loader2Icon className="h-4 w-4 animate-spin" />
          <span>{phase === 'confirming' ? t('wallet.card_confirming') : t('wallet.card_loading')}</span>
        </div>
      ) : null}

      {/* Unified Checkout renders its iframes here (embedded mode, no redirect). */}
      <div id={selectionId} className={phase === 'confirming' ? 'hidden' : ''} />
      <div id={screenId} className={`mt-3 rounded-xl bg-white ${phase === 'confirming' ? 'hidden' : ''}`} />

      <p className="mt-4 text-center text-xs text-gray-400">{t('wallet.card_secure_notice')}</p>
    </div>
  );
}
