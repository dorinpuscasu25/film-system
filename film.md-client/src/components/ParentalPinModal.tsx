import { useState } from 'react';
import { useTranslation } from 'react-i18next';
import { ShieldIcon } from 'lucide-react';

interface Props {
  /** Profile id whose PIN we're verifying */
  profileId: number;
  apiBase: string;
  authToken: string | null;
  onUnlocked: () => void;
  onCancel: () => void;
}

/**
 * Modal asking the user to enter the parental PIN to unlock restricted
 * content. Calls /storefront/profiles/{id}/parental/unlock and on success
 * the cached unlock lasts 30 minutes server-side.
 */
export function ParentalPinModal({ profileId, apiBase, authToken, onUnlocked, onCancel }: Props) {
  const { t } = useTranslation();
  const [pin, setPin] = useState('');
  const [error, setError] = useState<string | null>(null);
  const [submitting, setSubmitting] = useState(false);

  async function submit() {
    if (!/^\d{4,6}$/.test(pin)) {
      setError('PIN must be 4-6 digits');
      return;
    }
    setSubmitting(true);
    setError(null);
    try {
      const res = await fetch(`${apiBase}/storefront/profiles/${profileId}/parental/unlock`, {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json',
          Accept: 'application/json',
          ...(authToken ? { Authorization: `Bearer ${authToken}` } : {}),
        },
        body: JSON.stringify({ pin }),
      });
      if (!res.ok) {
        const data = (await res.json().catch(() => ({}))) as { message?: string };
        setError(data.message ?? 'PIN incorect');
        return;
      }
      onUnlocked();
    } finally {
      setSubmitting(false);
    }
  }

  return (
    <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/80 p-4">
      <div className="w-full max-w-md rounded-2xl border border-white/10 bg-zinc-950 p-6 shadow-2xl">
        <div className="flex items-center gap-3 mb-4">
          <div className="rounded-full bg-amber-500/20 p-3 text-amber-400">
            <ShieldIcon className="w-5 h-5" />
          </div>
          <h2 className="text-lg font-semibold text-white">{t('parental.unlock_title')}</h2>
        </div>
        <p className="text-sm text-white/70 mb-4">{t('parental.unlock_description')}</p>
        <input
          autoFocus
          type="password"
          inputMode="numeric"
          pattern="\d*"
          maxLength={6}
          value={pin}
          onChange={(e) => setPin(e.target.value.replace(/\D/g, ''))}
          onKeyDown={(e) => {
            if (e.key === 'Enter') void submit();
          }}
          className="w-full text-center tracking-[0.5em] text-2xl bg-white/5 border border-white/15 rounded-lg py-3 text-white"
          placeholder="••••"
        />
        {error && <div className="mt-2 text-sm text-red-400">{error}</div>}
        <div className="mt-6 flex justify-end gap-2">
          <button
            onClick={onCancel}
            className="px-4 py-2 rounded-lg text-white/70 hover:bg-white/5"
          >
            {t('common.cancel')}
          </button>
          <button
            onClick={() => void submit()}
            disabled={submitting}
            className="px-4 py-2 rounded-lg bg-amber-500 text-black font-medium hover:bg-amber-400 disabled:opacity-50"
          >
            {t('parental.unlock')}
          </button>
        </div>
      </div>
    </div>
  );
}
