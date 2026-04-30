import { useState } from 'react';
import { useTranslation } from 'react-i18next';

interface PreviewResponse {
  coupon: { id: number; code: string; discount_type: string; discount_value: number };
  discount: number;
  final_price: number;
  currency: string;
}

interface Props {
  apiBase: string;
  authToken: string | null;
  offerId: number;
  onApplied: (preview: PreviewResponse) => void;
  onCleared: () => void;
}

export function CouponField({ apiBase, authToken, offerId, onApplied, onCleared }: Props) {
  const { t } = useTranslation();
  const [code, setCode] = useState('');
  const [error, setError] = useState<string | null>(null);
  const [preview, setPreview] = useState<PreviewResponse | null>(null);
  const [submitting, setSubmitting] = useState(false);

  async function apply() {
    setSubmitting(true);
    setError(null);
    try {
      const res = await fetch(`${apiBase}/storefront/coupons/preview`, {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json',
          Accept: 'application/json',
          ...(authToken ? { Authorization: `Bearer ${authToken}` } : {}),
        },
        body: JSON.stringify({ code: code.toUpperCase(), offer_id: offerId }),
      });
      if (!res.ok) {
        const data = (await res.json().catch(() => ({}))) as { message?: string };
        setError(data.message ?? t('checkout.coupon_invalid'));
        setPreview(null);
        onCleared();
        return;
      }
      const data = (await res.json()) as PreviewResponse;
      setPreview(data);
      onApplied(data);
    } catch (e) {
      setError(t('checkout.coupon_invalid'));
      setPreview(null);
      onCleared();
    } finally {
      setSubmitting(false);
    }
  }

  function clear() {
    setCode('');
    setPreview(null);
    setError(null);
    onCleared();
  }

  return (
    <div className="space-y-2">
      <label className="text-xs uppercase tracking-wide text-white/50">
        {t('checkout.coupon_code')}
      </label>
      <div className="flex gap-2">
        <input
          value={code}
          onChange={(e) => setCode(e.target.value.toUpperCase())}
          className="flex-1 bg-white/5 border border-white/15 rounded-lg px-3 py-2 text-white"
          placeholder="WELCOME20"
        />
        <button
          onClick={() => void apply()}
          disabled={submitting || !code}
          className="px-4 py-2 rounded-lg bg-white/10 hover:bg-white/15 text-white disabled:opacity-50"
        >
          {t('checkout.apply')}
        </button>
        {preview && (
          <button
            onClick={clear}
            className="px-3 py-2 text-white/60 hover:text-white"
            aria-label="Clear coupon"
          >
            ✕
          </button>
        )}
      </div>
      {error && <div className="text-sm text-red-400">{error}</div>}
      {preview && (
        <div className="text-sm rounded-lg bg-emerald-500/10 border border-emerald-500/30 p-2 text-emerald-300">
          −{preview.discount.toFixed(2)} {preview.currency} ({preview.coupon.code})
        </div>
      )}
    </div>
  );
}
