import { useEffect, useState } from 'react';
import { useNavigate } from 'react-router-dom';
import { motion, AnimatePresence } from 'framer-motion';
import { CreditCardIcon, HistoryIcon, Loader2Icon, XIcon } from 'lucide-react';
import { useWallet } from '../contexts/WalletContext';
import { useLanguage } from '../contexts/LanguageContext';
import { fetchPublicPlatformSettings } from '../lib/session';

const PENDING_TOP_UP_STORAGE_KEY = 'film_pending_topup_id';

interface WalletModalProps {
  isOpen: boolean;
  onClose: () => void;
}

export function WalletModal({ isOpen, onClose }: WalletModalProps) {
  const { balance, currency, addFunds } = useWallet();
  const { t, currentLanguage } = useLanguage();
  const navigate = useNavigate();
  const [amount, setAmount] = useState('20');
  const [phone, setPhone] = useState('');
  const [acceptedTerms, setAcceptedTerms] = useState(false);
  const [termsUrl, setTermsUrl] = useState('/page/termeni-si-conditii');
  const [isSubmitting, setIsSubmitting] = useState(false);
  const [errorMessage, setErrorMessage] = useState<string | null>(null);

  const quickAmounts = [20, 50, 100, 250];
  const amountValue = Number(amount);
  const canSubmit = !isSubmitting && amountValue >= 20 && amountValue <= 20000 && acceptedTerms;

  function updateAmount(value: string) {
    const normalized = value
      .replace(',', '.')
      .replace(/[^\d.]/g, '')
      .replace(/(\..*)\./g, '$1');

    if (normalized === '') {
      setAmount('');
      return;
    }

    if (normalized.startsWith('0') && !normalized.startsWith('0.') && normalized.length > 1) {
      setAmount(normalized.replace(/^0+(?=\d)/, ''));
      return;
    }

    setAmount(normalized);
  }

  useEffect(() => {
    if (!isOpen) {
      return;
    }

    setAcceptedTerms(false);

    fetchPublicPlatformSettings(currentLanguage.code)
      .then((settings) => {
        setTermsUrl(settings.terms_page_url || settings.terms_page?.url || '/page/termeni-si-conditii');
      })
      .catch(() => {
        setTermsUrl('/page/termeni-si-conditii');
      });
  }, [currentLanguage.code, isOpen]);

  const handleSubmit = async () => {
    if (!acceptedTerms) {
      setErrorMessage(t('wallet.terms_required'));
      return;
    }

    setIsSubmitting(true);
    setErrorMessage(null);

    try {
      const topUp = await addFunds(amountValue, { phone: phone.trim() || undefined });

      if (!topUp.payment_url) {
        throw new Error(t('wallet.provider_missing_url'));
      }

      localStorage.setItem(PENDING_TOP_UP_STORAGE_KEY, topUp.id);
      window.location.href = topUp.payment_url;
    } catch (error) {
      setErrorMessage(error instanceof Error ? error.message : t('wallet.payment_start_failed'));
      setIsSubmitting(false);
    }
  };

  return (
    <AnimatePresence>
      {isOpen &&
      <div className="fixed inset-0 z-50 flex items-center justify-center p-4">
          <motion.div
          initial={{
            opacity: 0
          }}
          animate={{
            opacity: 1
          }}
          exit={{
            opacity: 0
          }}
          className="absolute inset-0 bg-black/80 backdrop-blur-sm"
          onClick={onClose} />
        

          <motion.div
          initial={{
            scale: 0.9,
            opacity: 0,
            y: 20
          }}
          animate={{
            scale: 1,
            opacity: 1,
            y: 0
          }}
          exit={{
            scale: 0.9,
            opacity: 0,
            y: 20
          }}
          className="glass-panel relative z-10 w-full max-w-md rounded-2xl p-6 shadow-2xl">
          
            <button
            onClick={onClose}
            className="absolute top-4 right-4 text-gray-400 hover:text-white transition-colors">
            
              <XIcon className="w-6 h-6" />
            </button>

            <h2 className="text-2xl font-bold text-white mb-6">
              {t('wallet.title')}
            </h2>

            <div className="bg-surface p-4 rounded-xl mb-6 flex items-center justify-between border border-white/5">
              <span className="text-gray-400">{t('wallet.current_balance')}</span>
              <span className="text-2xl font-bold text-accentGreen">
                {currency} {balance.toFixed(2)}
              </span>
            </div>

            <div className="rounded-2xl border border-white/10 bg-surfaceHover/60 p-5 text-left">
              <div className="mb-5 flex items-center space-x-3">
                <div className="rounded-full bg-white/10 p-2">
                  <CreditCardIcon className="w-5 h-5 text-white" />
                </div>
                <div>
                  <h3 className="text-white font-semibold">{t('wallet.top_up')}</h3>
                  <p className="text-sm text-gray-400">{t('wallet.redirect_notice')}</p>
                </div>
              </div>

              <div className="mb-4 grid grid-cols-4 gap-2">
                {quickAmounts.map((value) => (
                  <button
                    key={value}
                    type="button"
                    onClick={() => setAmount(String(value))}
                    className={`rounded-lg border px-3 py-2 text-sm font-semibold transition ${
                      amount === String(value)
                        ? 'border-white bg-white text-background'
                        : 'border-white/10 bg-black/20 text-white hover:bg-white/10'
                    }`}
                  >
                    {value}
                  </button>
                ))}
              </div>

              <label className="mb-4 block">
                <span className="mb-2 block text-sm text-gray-400">{t('wallet.amount', { currency })}</span>
                <input
                  type="text"
                  inputMode="decimal"
                  value={amount}
                  onChange={(event) => updateAmount(event.target.value)}
                  className="w-full rounded-lg border border-white/10 bg-surface px-4 py-3 text-white outline-none transition focus:border-accent"
                />
              </label>

              <label className="mb-5 block">
                <span className="mb-2 block text-sm text-gray-400">{t('wallet.phone')}</span>
                <input
                  type="tel"
                  value={phone}
                  onChange={(event) => setPhone(event.target.value)}
                  placeholder="+373..."
                  className="w-full rounded-lg border border-white/10 bg-surface px-4 py-3 text-white outline-none transition focus:border-accent"
                />
              </label>

              <label className="mb-5 flex items-start gap-3 rounded-lg border border-white/10 bg-black/20 p-3 text-sm text-gray-300">
                <input
                  type="checkbox"
                  checked={acceptedTerms}
                  onChange={(event) => {
                    setAcceptedTerms(event.target.checked);
                    if (event.target.checked) {
                      setErrorMessage(null);
                    }
                  }}
                  className="mt-1 h-4 w-4 rounded border-white/20 bg-surface text-accent focus:ring-accent"
                />
                <span>
                  {t('wallet.accept_terms')}{' '}
                  <a
                    href={termsUrl}
                    target="_blank"
                    rel="noreferrer"
                    className="font-semibold text-white underline decoration-white/40 underline-offset-4 transition hover:text-accent"
                  >
                    {t('wallet.terms_link')}
                  </a>
                </span>
              </label>

              {errorMessage ? (
                <p className="mb-4 rounded-lg border border-red-500/30 bg-red-500/10 px-4 py-3 text-sm text-red-200">
                  {errorMessage}
                </p>
              ) : null}

              <button
                type="button"
                onClick={() => void handleSubmit()}
                disabled={!canSubmit}
                className="flex w-full items-center justify-center rounded-xl bg-accent px-5 py-3 font-bold text-white transition hover:bg-red-700 disabled:cursor-not-allowed disabled:opacity-60"
              >
                {isSubmitting ? <Loader2Icon className="mr-2 h-5 w-5 animate-spin" /> : null}
                {t('wallet.continue_payment')}
              </button>
              <button
                type="button"
                onClick={() => {
                  onClose();
                  navigate('/dashboard?tab=wallet');
                }}
                className="mt-3 flex w-full items-center justify-center gap-2 rounded-xl border border-white/10 bg-white/5 px-5 py-3 font-semibold text-white transition hover:bg-white/10"
              >
                <HistoryIcon className="h-5 w-5" />
                {t('wallet.view_transactions')}
              </button>
            </div>
          </motion.div>
        </div>
      }
    </AnimatePresence>);

}
