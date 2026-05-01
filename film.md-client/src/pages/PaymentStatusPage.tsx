import { useEffect, useState } from 'react';
import { Link, useSearchParams } from 'react-router-dom';
import { CheckCircle2Icon, Loader2Icon, XCircleIcon } from 'lucide-react';
import {
  fetchLatestStorefrontWalletTopUp,
  fetchStorefrontWalletTopUp,
} from '../lib/session';
import type { StorefrontTopUpPayload } from '../lib/session';
import { useWallet } from '../contexts/WalletContext';

const PENDING_TOP_UP_STORAGE_KEY = 'film_pending_topup_id';

interface PaymentStatusPageProps {
  fallbackStatus: 'success' | 'failed';
}

export function PaymentStatusPage({ fallbackStatus }: PaymentStatusPageProps) {
  const [searchParams] = useSearchParams();
  const searchParamKey = searchParams.toString();
  const { refreshWallet } = useWallet();
  const [topUp, setTopUp] = useState<StorefrontTopUpPayload | null>(null);
  const [isLoading, setIsLoading] = useState(true);
  const [errorMessage, setErrorMessage] = useState<string | null>(null);

  useEffect(() => {
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
          setErrorMessage(error instanceof Error ? error.message : 'Nu am putut verifica plata.');
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
  }, [searchParamKey]);

  const isPaid = topUp?.status === 'paid';
  const isFailed = topUp?.status === 'failed' || topUp?.status === 'canceled' || topUp?.status === 'refunded' || (!topUp && fallbackStatus === 'failed');
  const isPending = topUp && ['pending', 'redirect_created', 'processing'].includes(topUp.status);

  return (
    <div className="min-h-[70vh] bg-background px-4 pt-32 pb-20">
      <div className="mx-auto max-w-xl rounded-2xl border border-white/10 bg-surface p-8 text-center shadow-2xl">
        <div className="mb-6 flex justify-center">
          {isLoading || isPending ? (
            <div className="rounded-full bg-white/10 p-4">
              <Loader2Icon className="h-10 w-10 animate-spin text-white" />
            </div>
          ) : isPaid ? (
            <div className="rounded-full bg-emerald-500/10 p-4">
              <CheckCircle2Icon className="h-10 w-10 text-emerald-400" />
            </div>
          ) : (
            <div className="rounded-full bg-red-500/10 p-4">
              <XCircleIcon className="h-10 w-10 text-red-400" />
            </div>
          )}
        </div>

        <h1 className="mb-3 text-3xl font-bold text-white">
          {isPaid ? 'Plata a fost confirmată' : isFailed ? 'Plata nu a fost finalizată' : 'Verificăm plata'}
        </h1>
        <p className="mb-6 text-gray-400">
          {isPaid
            ? `Contul tău a fost suplinit cu ${topUp?.currency} ${topUp?.amount.toFixed(2)}.`
            : isFailed
              ? 'Poți încerca din nou sau verifica tranzacția mai târziu.'
              : 'Așteptăm confirmarea providerului de plată. De obicei durează câteva secunde.'}
        </p>

        {errorMessage ? (
          <p className="mb-6 rounded-lg border border-red-500/30 bg-red-500/10 px-4 py-3 text-sm text-red-200">
            {errorMessage}
          </p>
        ) : null}

        {topUp ? (
          <div className="mb-6 rounded-xl border border-white/10 bg-black/20 p-4 text-left text-sm text-gray-300">
            <div className="flex justify-between gap-4">
              <span>Status</span>
              <span className="font-semibold text-white">{topUp.status}</span>
            </div>
            <div className="mt-2 flex justify-between gap-4">
              <span>Order</span>
              <span className="font-mono text-xs text-white">{topUp.provider_order_id ?? topUp.id}</span>
            </div>
          </div>
        ) : null}

        <div className="flex flex-col gap-3 sm:flex-row sm:justify-center">
          <Link to="/dashboard" className="rounded-lg bg-white px-5 py-3 font-bold text-background transition hover:bg-gray-200">
            Dashboard
          </Link>
          <Link to="/" className="rounded-lg border border-white/10 px-5 py-3 font-bold text-white transition hover:bg-white/10">
            Acasă
          </Link>
        </div>
      </div>
    </div>
  );
}
