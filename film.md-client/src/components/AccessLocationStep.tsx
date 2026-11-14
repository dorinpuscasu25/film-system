import { motion, AnimatePresence } from 'framer-motion';
import { useLanguage } from '../contexts/LanguageContext';
import type { StorefrontAccessLocation } from '../lib/session';

interface AccessLocationStepProps {
  isOpen: boolean;
  value: StorefrontAccessLocation | null;
  onChange: (value: StorefrontAccessLocation) => void;
  onBack: () => void;
  onConfirm: () => void;
  confirmLabel: string;
  isProcessing?: boolean;
}

/**
 * "Unde beneficiezi de serviciile FILMOTECA.md?" — the place of supply of digital services
 * (art. 111 Cod fiscal), asked before money is added and before a purchase. Rendered over the
 * modal that opens it (place it inside a relatively positioned full-screen container).
 */
export function AccessLocationStep({
  isOpen,
  value,
  onChange,
  onBack,
  onConfirm,
  confirmLabel,
  isProcessing = false,
}: AccessLocationStepProps) {
  const { t } = useLanguage();
  const options: Array<{ value: StorefrontAccessLocation; label: string }> = [
    { value: 'moldova', label: t('access_location.moldova') },
    { value: 'outside_moldova', label: t('access_location.outside') },
  ];

  return (
    <AnimatePresence>
      {isOpen ? (
        <div className="absolute inset-0 z-20 flex items-center justify-center p-4">
          <motion.div
            initial={{ opacity: 0 }}
            animate={{ opacity: 1 }}
            exit={{ opacity: 0 }}
            className="absolute inset-0 bg-black/80 backdrop-blur-sm"
            onClick={isProcessing ? undefined : onBack}
          />

          <motion.div
            role="dialog"
            aria-modal="true"
            aria-labelledby="access-location-title"
            initial={{ scale: 0.95, opacity: 0 }}
            animate={{ scale: 1, opacity: 1 }}
            exit={{ scale: 0.95, opacity: 0 }}
            className="relative z-10 w-full max-w-md rounded-2xl border border-white/10 bg-surface p-5 shadow-2xl sm:p-6"
          >
            <h3 id="access-location-title" className="mb-5 text-xl font-bold leading-snug text-white sm:text-2xl">
              {t('access_location.title')}
            </h3>

            <div role="radiogroup" aria-labelledby="access-location-title" className="mb-4 space-y-3">
              {options.map((option) => {
                const checked = value === option.value;

                return (
                  <button
                    key={option.value}
                    type="button"
                    role="radio"
                    aria-checked={checked}
                    onClick={() => onChange(option.value)}
                    className={`flex w-full items-center gap-3 rounded-xl border p-3 text-left transition-colors sm:p-4 ${
                      checked ? 'border-white bg-white/10' : 'border-white/10 bg-black/30 hover:bg-white/5'
                    }`}
                  >
                    <span
                      className={`flex h-5 w-5 shrink-0 items-center justify-center rounded-full border-2 transition-colors ${
                        checked ? 'border-white' : 'border-white/30'
                      }`}
                    >
                      {checked ? <span className="h-2.5 w-2.5 rounded-full bg-white" /> : null}
                    </span>
                    <span className="text-sm font-medium text-gray-100 sm:text-base">{option.label}</span>
                  </button>
                );
              })}
            </div>

            <p className="mb-5 text-xs leading-relaxed text-gray-400 sm:text-sm">{t('access_location.legal')}</p>

            <div className="flex flex-col-reverse gap-3 sm:flex-row">
              <button
                type="button"
                onClick={onBack}
                disabled={isProcessing}
                className="w-full rounded-xl border border-white/10 py-3 text-sm font-semibold text-white transition-colors hover:bg-white/10 disabled:opacity-60 sm:w-auto sm:px-5"
              >
                {t('common.back')}
              </button>
              <button
                type="button"
                onClick={onConfirm}
                disabled={!value || isProcessing}
                className={`flex w-full items-center justify-center rounded-xl py-3 text-sm font-bold transition-all ${
                  value && !isProcessing ? 'bg-white text-background hover:bg-gray-200' : 'cursor-not-allowed bg-white/10 text-gray-500'
                }`}
              >
                {isProcessing ? (
                  <span className="h-5 w-5 animate-spin rounded-full border-2 border-gray-500 border-t-transparent" />
                ) : (
                  confirmLabel
                )}
              </button>
            </div>
          </motion.div>
        </div>
      ) : null}
    </AnimatePresence>
  );
}
