import { useEffect, useMemo, useRef, useState } from 'react';
import { useNavigate } from 'react-router-dom';
import { motion, AnimatePresence } from 'framer-motion';
import { CreditCardIcon, Edit3Icon, HistoryIcon, Loader2Icon, MapPinIcon, QrCodeIcon, XIcon } from 'lucide-react';
import { getCountries, getCountryCallingCode, parsePhoneNumberFromString, type CountryCode } from 'libphonenumber-js';
import { useWallet } from '../contexts/WalletContext';
import { useLanguage } from '../contexts/LanguageContext';
import { useAuth } from '../contexts/AuthContext';
import { cancelStorefrontWalletTopUp, fetchPublicPlatformSettings, fetchStorefrontTopUpMethods } from '../lib/session';
import type {
  StorefrontAccessLocation,
  StorefrontBillingAddressPayload,
  StorefrontTopUpMethod,
  StorefrontTopUpPayload,
} from '../lib/session';
import { AccessLocationStep } from './AccessLocationStep';
import { CardCheckout } from './CardCheckout';
import { MiaQrPayment } from './MiaQrPayment';
import {
  BillingAddressFields,
  emptyBillingAddress,
  formatBillingAddress,
  hasCompleteBillingAddress,
  normalizeBillingAddress,
} from './BillingAddressForm';

const PENDING_TOP_UP_STORAGE_KEY = 'film_pending_topup_id';
const TOP_UP_RETURN_CONTEXT_STORAGE_KEY = 'film_topup_return_context';
const DEFAULT_COUNTRY: CountryCode = 'MD';

function countryFlag(country: CountryCode): string {
  return String.fromCodePoint(
    ...country.split('').map((character) => 127397 + character.charCodeAt(0)),
  );
}

interface WalletModalProps {
  isOpen: boolean;
  onClose: () => void;
  returnContext?: {
    movieId: string;
    movieTitle: string;
  };
}

export function WalletModal({ isOpen, onClose, returnContext }: WalletModalProps) {
  const { balance, currency, addFunds, paymentPhone, billingAddress, refreshWallet } = useWallet();
  const { t, currentLanguage } = useLanguage();
  const { user, activeProfile } = useAuth();
  const isKidsProfile = Boolean(activeProfile?.isKids);
  const navigate = useNavigate();
  const [amount, setAmount] = useState('');
  const [selectedCountry, setSelectedCountry] = useState<CountryCode>(DEFAULT_COUNTRY);
  const [phone, setPhone] = useState('');
  const [phoneTouched, setPhoneTouched] = useState(false);
  const [billingDraft, setBillingDraft] = useState<StorefrontBillingAddressPayload>(() => emptyBillingAddress());
  const [isEditingBillingAddress, setIsEditingBillingAddress] = useState(true);
  const [billingTouched, setBillingTouched] = useState(false);
  const [acceptedTerms, setAcceptedTerms] = useState(false);
  const [termsUrl, setTermsUrl] = useState('/page/termeni-si-conditii');
  const [isSubmitting, setIsSubmitting] = useState(false);
  const [errorMessage, setErrorMessage] = useState<string | null>(null);
  const [isAccessLocationStepOpen, setIsAccessLocationStepOpen] = useState(false);
  const [accessLocation, setAccessLocation] = useState<StorefrontAccessLocation | null>(null);
  const [availableMethods, setAvailableMethods] = useState<Record<StorefrontTopUpMethod, boolean>>({ card: true, mia: false });
  const [method, setMethod] = useState<StorefrontTopUpMethod>('card');
  const [miaTopUp, setMiaTopUp] = useState<StorefrontTopUpPayload | null>(null);
  const [cardTopUp, setCardTopUp] = useState<StorefrontTopUpPayload | null>(null);
  // Only the legacy pay.filmoteca.md card checkout asks for a phone number.
  const [cardRequiresPhone, setCardRequiresPhone] = useState(true);
  const isCard = method === 'card';
  const needsPhone = isCard && cardRequiresPhone;

  const quickAmounts = [200, 300, 400, 500];
  const amountValue = Number(amount);
  const isBillingAddressValid = hasCompleteBillingAddress(billingDraft);
  const canSubmit = !isSubmitting && availableMethods[method] && amountValue >= 20 && amountValue <= 20000 && acceptedTerms && isBillingAddressValid;
  const normalizedPhone = normalizePhoneNumber(phone, selectedCountry);
  const phoneError = phoneTouched && !normalizedPhone ? t('wallet.phone_invalid') : null;
  const countryOptions = useMemo(() => {
    const displayNames =
      typeof Intl !== 'undefined' && 'DisplayNames' in Intl
        ? new Intl.DisplayNames([currentLanguage.code], { type: 'region' })
        : null;

    return getCountries()
      .map((country) => ({
        country,
        callingCode: getCountryCallingCode(country),
        name: displayNames?.of(country) ?? country,
      }))
      .sort((a, b) => a.name.localeCompare(b.name, currentLanguage.code));
  }, [currentLanguage.code]);

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

  // Reset the form only when the modal OPENS. addFunds() saves the billing address (and
  // refreshWallet() reloads it) while a card form / MIA QR is on screen; re-running the full
  // reset on those changes would wipe the payment in progress.
  const wasOpenRef = useRef(false);
  const paymentInProgress = cardTopUp !== null || miaTopUp !== null;

  useEffect(() => {
    if (!isOpen) {
      wasOpenRef.current = false;
      return;
    }

    if (wasOpenRef.current) {
      return;
    }
    wasOpenRef.current = true;

    setAcceptedTerms(false);
    setAmount('');
    setPhoneTouched(false);
    setBillingTouched(false);
    setErrorMessage(null);
    setIsAccessLocationStepOpen(false);
    setAccessLocation(null);
    setMiaTopUp(null);
    setCardTopUp(null);

    fetchStorefrontTopUpMethods()
      .then((response) => {
        const enabled = { card: false, mia: false } as Record<StorefrontTopUpMethod, boolean>;
        response.methods.forEach((item) => {
          enabled[item.id] = item.enabled;
          if (item.id === 'card') {
            setCardRequiresPhone(item.requires_phone !== false);
          }
        });
        setAvailableMethods(enabled);
        setMethod(response.default ?? 'card');
      })
      .catch(() => {
        setAvailableMethods({ card: true, mia: false });
        setMethod('card');
      });

    fetchPublicPlatformSettings(currentLanguage.code)
      .then((settings) => {
        setTermsUrl(settings.terms_page_url || settings.terms_page?.url || '/page/termeni-si-conditii');
      })
      .catch(() => {
        setTermsUrl('/page/termeni-si-conditii');
      });
  }, [currentLanguage.code, isOpen]);

  // Saved phone / billing address can arrive after the modal opened: copy them into the form
  // while the customer is still filling it in, never once a payment has started.
  useEffect(() => {
    if (!isOpen || paymentInProgress || phoneTouched) {
      return;
    }
    const savedPhone = paymentPhoneDraft(paymentPhone);
    setPhone(savedPhone.nationalNumber);
    setSelectedCountry(savedPhone.country);
  }, [isOpen, paymentPhone, paymentInProgress, phoneTouched]);

  useEffect(() => {
    if (!isOpen || paymentInProgress || billingTouched) {
      return;
    }
    setBillingDraft(billingAddress ?? emptyBillingAddress(user?.name));
    setIsEditingBillingAddress(!billingAddress);
  }, [isOpen, billingAddress, user?.name, paymentInProgress, billingTouched]);

  const handleSubmit = async () => {
    if (!acceptedTerms) {
      setErrorMessage(t('wallet.terms_required'));
      return;
    }

    if (needsPhone) {
      setPhoneTouched(true);

      if (!normalizedPhone) {
        setErrorMessage(t('wallet.phone_invalid'));
        return;
      }
    }

    if (!isBillingAddressValid) {
      setBillingTouched(true);
      setIsEditingBillingAddress(true);
      setErrorMessage(t('wallet.billing_address_required'));
      return;
    }

    // Last step before the payment page: where the services are used (art. 111 Cod fiscal).
    setErrorMessage(null);
    setIsAccessLocationStepOpen(true);
  };

  const startPayment = async () => {
    if (!accessLocation || (needsPhone && !normalizedPhone)) {
      return;
    }

    setIsSubmitting(true);
    setErrorMessage(null);

    try {
      const topUp = await addFunds(amountValue, {
        method,
        phone: needsPhone ? normalizedPhone ?? undefined : undefined,
        billingAddress: normalizeBillingAddress(billingDraft),
        accessLocation,
      });

      if (method === 'mia') {
        // The QR is paid from the bank app; this modal waits for the bank's confirmation.
        setMiaTopUp(topUp);
        setIsAccessLocationStepOpen(false);
        setIsSubmitting(false);
        return;
      }

      if (topUp.card_session) {
        // Victoriabank card form, embedded in this modal (no redirect).
        setCardTopUp(topUp);
        setIsAccessLocationStepOpen(false);
        setIsSubmitting(false);
        return;
      }

      if (!topUp.payment_url) {
        throw new Error(t('wallet.provider_missing_url'));
      }

      localStorage.setItem(PENDING_TOP_UP_STORAGE_KEY, topUp.id);
      if (returnContext) {
        localStorage.setItem(TOP_UP_RETURN_CONTEXT_STORAGE_KEY, JSON.stringify(returnContext));
      } else {
        localStorage.removeItem(TOP_UP_RETURN_CONTEXT_STORAGE_KEY);
      }
      window.location.href = topUp.payment_url;
    } catch (error) {
      setErrorMessage(error instanceof Error ? error.message : t('wallet.payment_start_failed'));
      setIsAccessLocationStepOpen(false);
      setIsSubmitting(false);
    }
  };

  const handleClose = () => {
    // A QR left open could still be paid later; cancel it at the bank when the customer leaves.
    if (miaTopUp && (miaTopUp.status === 'processing' || miaTopUp.status === 'pending')) {
      void cancelStorefrontWalletTopUp(miaTopUp.id).catch(() => undefined);
    }
    onClose();
  };

  function updatePhone(value: string) {
    const withoutInvalidCharacters = value.replace(/[^\d+]/g, '');
    const normalized =
      withoutInvalidCharacters.includes('+')
        ? `+${withoutInvalidCharacters.replace(/\+/g, '')}`
        : withoutInvalidCharacters;

    setPhone(normalized);
    setPhoneTouched(true);
    setErrorMessage(null);
  }

  function updateBillingAddress(field: keyof StorefrontBillingAddressPayload, value: string) {
    setBillingDraft((current) => ({
      ...current,
      [field]: field === 'country_code' ? value.toUpperCase() : value,
    }));
    setBillingTouched(true);
    setErrorMessage(null);
  }

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
          onClick={handleClose} />
        

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
          className="glass-panel relative z-10 max-h-[92vh] w-full max-w-lg overflow-y-auto rounded-2xl p-6 shadow-2xl">
          
            <button
            onClick={handleClose}
            className="absolute top-4 right-4 text-gray-400 hover:text-white transition-colors">
            
              <XIcon className="w-6 h-6" />
            </button>

            <h2 className="text-2xl font-bold text-white mb-6">
              {t('wallet.title')}
            </h2>

            {isKidsProfile ? (
              <div className="rounded-2xl border border-amber-400/30 bg-amber-400/10 p-6 text-center">
                <CreditCardIcon className="mx-auto mb-4 h-10 w-10 text-amber-300" />
                <p className="font-semibold text-white">{t('wallet.kids_restricted')}</p>
                <button
                  type="button"
                  onClick={onClose}
                  className="mt-6 w-full rounded-xl bg-white px-5 py-3 font-bold text-background transition hover:bg-gray-200"
                >
                  {t('common.close')}
                </button>
              </div>
            ) : (
              <>
            <div className="bg-surface p-4 rounded-xl mb-6 flex items-center justify-between border border-white/5">
              <span className="text-gray-400">{t('wallet.current_balance')}</span>
              <span className="text-2xl font-bold text-accentGreen">
                {currency} {balance.toFixed(2)}
              </span>
            </div>

            {cardTopUp ? (
              <CardCheckout
                topUp={cardTopUp}
                onPaid={() => {
                  void refreshWallet();
                }}
                onRetry={() => setCardTopUp(null)}
              />
            ) : miaTopUp ? (
              <MiaQrPayment
                topUp={miaTopUp}
                onPaid={() => {
                  void refreshWallet();
                }}
                onRetry={() => setMiaTopUp(null)}
              />
            ) : (
            <div className="rounded-2xl border border-white/10 bg-surfaceHover/60 p-5 text-left">
              <div className="mb-5 flex items-center space-x-3">
                <div className="rounded-full bg-white/10 p-2">
                  {isCard ? <CreditCardIcon className="w-5 h-5 text-white" /> : <QrCodeIcon className="w-5 h-5 text-white" />}
                </div>
                <div>
                  <h3 className="text-white font-semibold">{t('wallet.top_up')}</h3>
                  <p className="text-sm text-gray-400">
                    {isCard ? (cardRequiresPhone ? t('wallet.redirect_notice') : t('wallet.card_notice')) : t('wallet.mia_notice')}
                  </p>
                </div>
              </div>

              {availableMethods.card && availableMethods.mia ? (
                <div className="mb-4 grid grid-cols-2 gap-2" role="radiogroup" aria-label={t('wallet.method')}>
                  {(['card', 'mia'] as StorefrontTopUpMethod[]).map((option) => (
                    <button
                      key={option}
                      type="button"
                      role="radio"
                      aria-checked={method === option}
                      onClick={() => {
                        setMethod(option);
                        setErrorMessage(null);
                      }}
                      className={`flex items-center justify-center gap-2 rounded-lg border px-3 py-2 text-sm font-semibold transition ${
                        method === option
                          ? 'border-white bg-white text-background'
                          : 'border-white/10 bg-black/20 text-white hover:bg-white/10'
                      }`}
                    >
                      {option === 'card' ? <CreditCardIcon className="h-4 w-4" /> : <QrCodeIcon className="h-4 w-4" />}
                      {option === 'card' ? t('wallet.method_card') : t('wallet.method_mia')}
                    </button>
                  ))}
                </div>
              ) : null}

              {!availableMethods.card && !availableMethods.mia ? (
                <p className="mb-4 rounded-lg border border-amber-400/30 bg-amber-400/10 px-4 py-3 text-sm text-amber-100">
                  {t('wallet.methods_unavailable')}
                </p>
              ) : null}

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
                  placeholder={t('wallet.amount_placeholder')}
                  className="w-full rounded-lg border border-white/10 bg-surface px-4 py-3 text-white outline-none transition placeholder:text-gray-500 focus:border-accent"
                />
              </label>

              {needsPhone ? (
              <label className="mb-5 block">
                <span className="mb-2 block text-sm text-gray-400">{t('wallet.phone')}</span>
                <div className={`flex rounded-lg border bg-surface transition focus-within:border-accent ${
                  phoneError ? 'border-red-500/50' : 'border-white/10'
                }`}>
                  <select
                    value={selectedCountry}
                    onChange={(event) => {
                      setSelectedCountry(event.target.value as CountryCode);
                      setPhoneTouched(true);
                      setErrorMessage(null);
                    }}
                    aria-label={t('wallet.country_code')}
                    className="w-[112px] shrink-0 rounded-l-lg border-r border-white/10 bg-surface px-3 py-3 text-sm text-white outline-none"
                  >
                    {countryOptions.map(({ country, callingCode }) => (
                      <option key={country} value={country} className="bg-surface text-white">
                        {countryFlag(country)} +{callingCode}
                      </option>
                    ))}
                  </select>
                  <input
                    type="tel"
                    inputMode="tel"
                    value={phone}
                    onChange={(event) => updatePhone(event.target.value)}
                    onBlur={() => setPhoneTouched(true)}
                    placeholder={t('wallet.phone_placeholder')}
                    className="min-w-0 flex-1 rounded-r-lg bg-transparent px-4 py-3 text-white outline-none placeholder:text-gray-500"
                  />
                </div>
                {phoneError ? (
                  <span className="mt-2 block text-sm text-red-200">{phoneError}</span>
                ) : null}
              </label>
              ) : null}

              <div className="mb-5 rounded-xl border border-white/10 bg-black/20 p-4">
                <div className="mb-4 flex items-start justify-between gap-4">
                  <div className="flex items-center gap-3">
                    <div className="rounded-full bg-white/10 p-2">
                      <MapPinIcon className="h-4 w-4 text-white" />
                    </div>
                    <div>
                      <h4 className="font-semibold text-white">{t('wallet.billing_address')}</h4>
                      <p className="text-xs text-gray-400">{t('wallet.billing_address_note')}</p>
                      <p className="mt-1 text-xs text-gray-400">{t('wallet.billing_address_hint')}</p>
                    </div>
                  </div>
                  {!isEditingBillingAddress && billingAddress ? (
                    <button
                      type="button"
                      onClick={() => {
                        setIsEditingBillingAddress(true);
                        setBillingTouched(false);
                        setErrorMessage(null);
                      }}
                      className="flex shrink-0 items-center gap-1 rounded-lg border border-white/10 px-3 py-2 text-xs font-semibold text-white transition hover:bg-white/10"
                    >
                      <Edit3Icon className="h-3.5 w-3.5" />
                      {t('wallet.edit_billing_address')}
                    </button>
                  ) : null}
                </div>

                {!isEditingBillingAddress && billingAddress ? (
                  <div className="rounded-lg border border-white/10 bg-surface px-4 py-3 text-sm text-gray-300">
                    <p className="font-semibold text-white">{billingAddress.full_name}</p>
                    <p>{formatBillingAddress(billingAddress)}</p>
                  </div>
                ) : (
                  <BillingAddressFields value={billingDraft} onChange={updateBillingAddress} touched={billingTouched} />
                )}
              </div>

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
                {isCard ? t('wallet.continue_payment') : t('wallet.mia_generate_code')}
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
            )}
              </>
            )}
          </motion.div>

          <AccessLocationStep
            isOpen={isAccessLocationStepOpen && !isKidsProfile}
            value={accessLocation}
            onChange={setAccessLocation}
            onBack={() => setIsAccessLocationStepOpen(false)}
            onConfirm={() => void startPayment()}
            isProcessing={isSubmitting}
            confirmLabel={t('wallet.continue_payment_amount', { amount: `${currency} ${amountValue.toFixed(2)}` })}
          />
        </div>
      }
    </AnimatePresence>);

}

function normalizePhoneNumber(value: string, country: CountryCode): string | null {
  const trimmed = value.trim();

  if (trimmed === '') {
    return null;
  }

  const parsed = trimmed.startsWith('+')
    ? parsePhoneNumberFromString(trimmed)
    : parsePhoneNumberFromString(trimmed, country);

  return parsed?.isValid() ? parsed.number : null;
}

function paymentPhoneDraft(value: string | null): {
  country: CountryCode;
  nationalNumber: string;
} {
  const parsed = value ? parsePhoneNumberFromString(value) : undefined;

  if (!parsed?.isValid() || !parsed.country) {
    return {
      country: DEFAULT_COUNTRY,
      nationalNumber: '',
    };
  }

  return {
    country: parsed.country,
    nationalNumber: parsed.nationalNumber,
  };
}
