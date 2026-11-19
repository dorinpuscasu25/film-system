import { useEffect, useRef, useState } from 'react';
import { CheckCircle2Icon, ExternalLinkIcon, Loader2Icon, QrCodeIcon, RefreshCwIcon } from 'lucide-react';
import { useLanguage } from '../contexts/LanguageContext';
import { fetchStorefrontWalletTopUp, type StorefrontTopUpPayload } from '../lib/session';

const POLL_INTERVAL_MS = 3000;

interface MiaQrPaymentProps {
  topUp: StorefrontTopUpPayload;
  onPaid: (topUp: StorefrontTopUpPayload) => void;
  onRetry: () => void;
}

/**
 * Shows a Victoriabank MIA QR and waits for the bank to confirm it. The server is the only
 * source of truth: the status comes from polling the top-up, never from the page itself.
 */
export function MiaQrPayment({ topUp: initialTopUp, onPaid, onRetry }: MiaQrPaymentProps) {
  const { t } = useLanguage();
  const [topUp, setTopUp] = useState(initialTopUp);
  const [secondsLeft, setSecondsLeft] = useState(() => secondsUntil(initialTopUp.qr?.expires_at));
  const paidReported = useRef(false);
  const isOpen = topUp.status === 'processing' || topUp.status === 'pending';

  useEffect(() => {
    setTopUp(initialTopUp);
    paidReported.current = false;
  }, [initialTopUp]);

  useEffect(() => {
    if (!isOpen) {
      return undefined;
    }

    let cancelled = false;
    const poll = window.setInterval(() => {
      fetchStorefrontWalletTopUp(topUp.id)
        .then((response) => {
          if (!cancelled) {
            setTopUp((current) => ({ ...response.top_up, qr: response.top_up.qr ?? (response.top_up.status === 'processing' ? current.qr : null) }));
          }
        })
        .catch(() => undefined);
    }, POLL_INTERVAL_MS);

    return () => {
      cancelled = true;
      window.clearInterval(poll);
    };
  }, [isOpen, topUp.id]);

  useEffect(() => {
    if (!isOpen) {
      return undefined;
    }

    const timer = window.setInterval(() => setSecondsLeft(secondsUntil(topUp.qr?.expires_at)), 1000);

    return () => window.clearInterval(timer);
  }, [isOpen, topUp.qr?.expires_at]);

  useEffect(() => {
    if (topUp.status === 'paid' && !paidReported.current) {
      paidReported.current = true;
      onPaid(topUp);
    }
  }, [onPaid, topUp]);

  if (topUp.status === 'paid') {
    return (
      <div className="rounded-2xl border border-accentGreen/30 bg-accentGreen/10 p-6 text-center">
        <CheckCircle2Icon className="mx-auto mb-3 h-12 w-12 text-accentGreen" />
        <p className="text-lg font-semibold text-white">{t('wallet.mia_paid')}</p>
        <p className="mt-1 text-sm text-gray-300">
          {topUp.currency} {Number(topUp.amount).toFixed(2)}
        </p>
      </div>
    );
  }

  if (!isOpen) {
    return (
      <div className="rounded-2xl border border-white/10 bg-black/20 p-6 text-center">
        <p className="font-semibold text-white">{t('wallet.mia_expired')}</p>
        <button
          type="button"
          onClick={onRetry}
          className="mt-4 inline-flex items-center gap-2 rounded-xl bg-white px-5 py-3 font-bold text-background transition hover:bg-gray-200"
        >
          <RefreshCwIcon className="h-4 w-4" />
          {t('wallet.mia_new_code')}
        </button>
      </div>
    );
  }

  return (
    <div className="rounded-2xl border border-white/10 bg-surfaceHover/60 p-5 text-center">
      <div className="mb-4 flex items-center justify-center gap-2 text-white">
        <QrCodeIcon className="h-5 w-5" />
        <h3 className="font-semibold">{t('wallet.mia_scan_title')}</h3>
      </div>

      <p className="mb-4 text-2xl font-bold text-white">
        {topUp.currency} {Number(topUp.amount).toFixed(2)}
      </p>

      {topUp.qr?.image ? (
        <img
          src={topUp.qr.image}
          alt={t('wallet.mia_qr_alt')}
          className="mx-auto mb-4 h-60 w-60 rounded-xl bg-white p-3"
        />
      ) : null}

      <p className="mb-4 text-sm text-gray-300">{t('wallet.mia_scan_hint')}</p>

      {topUp.qr?.text ? (
        <a
          href={topUp.qr.text}
          className="mb-4 inline-flex w-full items-center justify-center gap-2 rounded-xl border border-white/10 bg-white/5 px-5 py-3 font-semibold text-white transition hover:bg-white/10"
        >
          <ExternalLinkIcon className="h-4 w-4" />
          {t('wallet.mia_open_bank_app')}
        </a>
      ) : null}

      <div className="flex items-center justify-center gap-2 text-sm text-gray-400">
        <Loader2Icon className="h-4 w-4 animate-spin" />
        <span>
          {t('wallet.mia_waiting')}
          {secondsLeft > 0 ? ` · ${formatCountdown(secondsLeft)}` : ''}
        </span>
      </div>
    </div>
  );
}

function secondsUntil(iso: string | null | undefined): number {
  if (!iso) {
    return 0;
  }

  return Math.max(0, Math.round((new Date(iso).getTime() - Date.now()) / 1000));
}

function formatCountdown(seconds: number): string {
  const minutes = Math.floor(seconds / 60);
  const rest = seconds % 60;

  return `${minutes}:${String(rest).padStart(2, '0')}`;
}
