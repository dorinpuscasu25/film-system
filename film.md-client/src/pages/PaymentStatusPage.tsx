import { useEffect, useState } from 'react';
import { Link, useSearchParams } from 'react-router-dom';
import { CheckCircle2Icon, ClapperboardIcon, Loader2Icon, RotateCcwIcon, XCircleIcon } from 'lucide-react';
import {
  fetchLatestStorefrontWalletTopUp,
  fetchStorefrontWalletTopUp,
} from '../lib/session';
import type { StorefrontTopUpPayload } from '../lib/session';
import { useWallet } from '../contexts/WalletContext';
import { useLanguage } from '../contexts/LanguageContext';

const PENDING_TOP_UP_STORAGE_KEY = 'film_pending_topup_id';

interface PaymentStatusPageProps {
  fallbackStatus: 'success' | 'failed';
}

export function PaymentStatusPage({ fallbackStatus }: PaymentStatusPageProps) {
  const [searchParams] = useSearchParams();
  const searchParamKey = searchParams.toString();
  const { refreshWallet } = useWallet();
  const { t } = useLanguage();
  const [topUp, setTopUp] = useState<StorefrontTopUpPayload | null>(null);
  const [isLoading, setIsLoading] = useState(true);
  const [errorMessage, setErrorMessage] = useState<string | null>(null);

  useEffect(() => {
    if (fallbackStatus === 'failed') {
      localStorage.removeItem(PENDING_TOP_UP_STORAGE_KEY);
      setTopUp(null);
      setErrorMessage(null);
      setIsLoading(false);
      return;
    }

    let active = true;
    let attempts = 0;
    const params = new URLSearchParams(searchParamKey);
    const queryTopUpId = params.get('topup_id') ?? params.get('top_up_id') ?? params.get('uuid');
    const providerOrderId = params.get('order_id') ?? params.get('orderId') ?? params.get('OrderID');
    const storedTopUpId = localStorage.getItem(PENDING_TOP_UP_STORAGE_KEY);
    const topUpId = queryTopUpId ?? storedTopUpId;

    async function load() {
      attempts += 1;
      setIsLoading(true);
      setErrorMessage(null);

      try {
        const response = topUpId
          ? await fetchStorefrontWalletTopUp(topUpId, { orderId: providerOrderId })
          : await fetchLatestStorefrontWalletTopUp({ orderId: providerOrderId });

        if (!active) {
          return;
        }

        setTopUp(response.top_up);

        if (response.top_up.status === 'paid') {
          localStorage.removeItem(PENDING_TOP_UP_STORAGE_KEY);
          await refreshWallet();
        }

        if (['pending', 'redirect_created', 'processing'].includes(response.top_up.status) && attempts < 6) {
          window.setTimeout(load, 2500);
          return;
        }
      } catch (error) {
        if (active) {
          setErrorMessage(error instanceof Error ? error.message : t('payment.verify_failed'));
        }
      } finally {
        if (active) {
          setIsLoading(false);
        }
      }
    }

    void load();

    return () => {
      active = false;
    };
  }, [fallbackStatus, refreshWallet, searchParamKey, t]);

  const isPaid = fallbackStatus === 'success';
  const isFailed = fallbackStatus === 'failed' || topUp?.status === 'failed' || topUp?.status === 'canceled' || topUp?.status === 'refunded';
  const isPending = topUp && ['pending', 'redirect_created', 'processing'].includes(topUp.status);
  const isChecking = fallbackStatus !== 'success' && Boolean(isLoading || isPending);
  const isSuccessView = !isChecking && isPaid && !isFailed;
  const Icon = isChecking ? Loader2Icon : isSuccessView ? CheckCircle2Icon : XCircleIcon;

  return (
    <div className="min-h-[70vh] bg-background px-4 pb-20 pt-32">
      <div
        className={`mx-auto max-w-xl overflow-hidden rounded-3xl border p-8 text-center shadow-2xl ${
          isChecking
            ? 'border-white/10 bg-surface'
            : isSuccessView
              ? 'border-emerald-400/25 bg-[linear-gradient(180deg,rgba(16,185,129,0.14),rgba(24,24,36,0.92)_42%)]'
              : 'border-red-400/25 bg-[linear-gradient(180deg,rgba(239,68,68,0.16),rgba(24,24,36,0.92)_42%)]'
        }`}
      >
        <div className="mb-6 flex justify-center">
          <div
            className={`rounded-full p-5 ${
              isChecking ? 'bg-white/10' : isSuccessView ? 'bg-emerald-400/15' : 'bg-red-400/15'
            }`}
          >
            <Icon
              className={`h-12 w-12 ${
                isChecking ? 'animate-spin text-white' : isSuccessView ? 'text-emerald-300' : 'text-red-300'
              }`}
            />
          </div>
        </div>

        <p
          className={`mb-3 text-xs font-bold uppercase tracking-[0.28em] ${
            isChecking ? 'text-white/50' : isSuccessView ? 'text-emerald-200/80' : 'text-red-200/80'
          }`}
        >
          {isChecking ? t('payment.checking_badge') : isSuccessView ? t('payment.success_badge') : t('payment.failed_badge')}
        </p>

        <h1 className="mx-auto mb-4 max-w-md text-3xl font-bold leading-tight text-white md:text-4xl">
          {isChecking ? t('payment.checking_title') : isSuccessView ? t('payment.paid_title') : t('payment.failed_title')}
        </h1>
        <p className="mx-auto mb-8 max-w-md text-base leading-7 text-gray-300">
          {isChecking
            ? t('payment.checking_message')
            : isSuccessView
              ? topUp
                ? t('payment.paid_message', { currency: topUp.currency, amount: topUp.amount.toFixed(2) })
                : t('payment.paid_message_generic')
              : t('payment.failed_message')}
        </p>

        {errorMessage ? (
          <p className="mb-6 rounded-lg border border-red-500/30 bg-red-500/10 px-4 py-3 text-sm text-red-200">
            {errorMessage}
          </p>
        ) : null}

        <div className="flex flex-col gap-3 sm:flex-row sm:justify-center">
          <Link to="/search" className="inline-flex items-center justify-center gap-2 rounded-xl bg-white px-5 py-3 font-bold text-background transition hover:bg-gray-200">
            <ClapperboardIcon className="h-5 w-5" />
            {isSuccessView ? t('payment.start_watching') : t('payment.go_to_films')}
          </Link>
          <Link to="/dashboard?tab=wallet" className="inline-flex items-center justify-center gap-2 rounded-xl border border-white/10 px-5 py-3 font-bold text-white transition hover:bg-white/10">
            <RotateCcwIcon className="h-5 w-5" />
            {t('payment.view_wallet')}
          </Link>
        </div>
      </div>
    </div>
  );
}
