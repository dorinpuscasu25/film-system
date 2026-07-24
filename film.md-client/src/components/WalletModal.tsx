import { useEffect, useMemo, useState } from 'react';
import { useNavigate } from 'react-router-dom';
import { motion, AnimatePresence } from 'framer-motion';
import { CreditCardIcon, Edit3Icon, HistoryIcon, Loader2Icon, MapPinIcon, XIcon } from 'lucide-react';
import { getCountries, getCountryCallingCode, parsePhoneNumberFromString, type CountryCode } from 'libphonenumber-js';
import { useWallet } from '../contexts/WalletContext';
import { useLanguage } from '../contexts/LanguageContext';
import { useAuth } from '../contexts/AuthContext';
import { fetchPublicPlatformSettings } from '../lib/session';
import type { StorefrontBillingAddressPayload } from '../lib/session';

const PENDING_TOP_UP_STORAGE_KEY = 'film_pending_topup_id';
const TOP_UP_RETURN_CONTEXT_STORAGE_KEY = 'film_topup_return_context';
const DEFAULT_COUNTRY: CountryCode = 'MD';
const REQUIRED_BILLING_FIELDS: Array<keyof StorefrontBillingAddressPayload> = [
  'full_name',
  'country_code',
  'city',
  'postal_code',
  'address_line1',
];

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
  const { balance, currency, addFunds, paymentPhone, billingAddress } = useWallet();
  const { t, currentLanguage } = useLanguage();
  const { user } = useAuth();
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

  const quickAmounts = [200, 300, 400, 500];
  const amountValue = Number(amount);
  const isBillingAddressValid = hasCompleteBillingAddress(billingDraft);
  const canSubmit = !isSubmitting && amountValue >= 20 && amountValue <= 20000 && acceptedTerms && isBillingAddressValid;
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

  useEffect(() => {
    if (!isOpen) {
      return;
    }

    setAcceptedTerms(false);
    setAmount('');
    const savedPhone = paymentPhoneDraft(paymentPhone);
    setPhone(savedPhone.nationalNumber);
    setPhoneTouched(false);
    setSelectedCountry(savedPhone.country);
    setBillingDraft(billingAddress ?? emptyBillingAddress(user?.name));
    setIsEditingBillingAddress(!billingAddress);
    setBillingTouched(false);
    setErrorMessage(null);

    fetchPublicPlatformSettings(currentLanguage.code)
      .then((settings) => {
        setTermsUrl(settings.terms_page_url || settings.terms_page?.url || '/page/termeni-si-conditii');
      })
      .catch(() => {
        setTermsUrl('/page/termeni-si-conditii');
      });
  }, [billingAddress, currentLanguage.code, isOpen, paymentPhone, user?.name]);

  const handleSubmit = async () => {
    if (!acceptedTerms) {
      setErrorMessage(t('wallet.terms_required'));
      return;
    }

    setPhoneTouched(true);

    if (!normalizedPhone) {
      setErrorMessage(t('wallet.phone_invalid'));
      return;
    }

    if (!isBillingAddressValid) {
      setBillingTouched(true);
      setIsEditingBillingAddress(true);
      setErrorMessage(t('wallet.billing_address_required'));
      return;
    }

    setIsSubmitting(true);
    setErrorMessage(null);

    try {
      const topUp = await addFunds(amountValue, {
        phone: normalizedPhone,
        billingAddress: normalizeBillingAddress(billingDraft),
      });

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
      setIsSubmitting(false);
    }
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
          className="glass-panel relative z-10 max-h-[92vh] w-full max-w-lg overflow-y-auto rounded-2xl p-6 shadow-2xl">
          
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
                  placeholder={t('wallet.amount_placeholder')}
                  className="w-full rounded-lg border border-white/10 bg-surface px-4 py-3 text-white outline-none transition placeholder:text-gray-500 focus:border-accent"
                />
              </label>

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

              <div className="mb-5 rounded-xl border border-white/10 bg-black/20 p-4">
                <div className="mb-4 flex items-start justify-between gap-4">
                  <div className="flex items-center gap-3">
                    <div className="rounded-full bg-white/10 p-2">
                      <MapPinIcon className="h-4 w-4 text-white" />
                    </div>
                    <div>
                      <h4 className="font-semibold text-white">{t('wallet.billing_address')}</h4>
                      <p className="text-xs text-gray-400">{t('wallet.billing_address_hint')}</p>
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
                  <div className="space-y-3">
                    <label className="block">
                      <span className="mb-1.5 block text-xs font-medium text-gray-400">{t('wallet.billing_full_name')}</span>
                      <input
                        type="text"
                        value={billingDraft.full_name}
                        onChange={(event) => updateBillingAddress('full_name', event.target.value)}
                        className={`w-full rounded-lg border bg-surface px-3 py-2.5 text-sm text-white outline-none transition placeholder:text-gray-500 focus:border-accent ${
                          isBillingFieldInvalid(billingDraft, 'full_name', billingTouched) ? 'border-red-500/50' : 'border-white/10'
                        }`}
                      />
                    </label>

                    <label className="block">
                      <span className="mb-1.5 block text-xs font-medium text-gray-400">{t('wallet.billing_country')}</span>
                      <select
                        value={billingDraft.country_code}
                        onChange={(event) => updateBillingAddress('country_code', event.target.value)}
                        className={`w-full rounded-lg border bg-surface px-3 py-2.5 text-sm text-white outline-none transition focus:border-accent ${
                          isBillingFieldInvalid(billingDraft, 'country_code', billingTouched) ? 'border-red-500/50' : 'border-white/10'
                        }`}
                      >
                        {countryOptions.map(({ country, name }) => (
                          <option key={country} value={country} className="bg-surface text-white">
                            {name}
                          </option>
                        ))}
                      </select>
                    </label>

                    <label className="block">
                      <span className="mb-1.5 block text-xs font-medium text-gray-400">{t('wallet.billing_address_line1')}</span>
                      <input
                        type="text"
                        value={billingDraft.address_line1}
                        onChange={(event) => updateBillingAddress('address_line1', event.target.value)}
                        placeholder={t('wallet.billing_address_line1_placeholder')}
                        className={`w-full rounded-lg border bg-surface px-3 py-2.5 text-sm text-white outline-none transition placeholder:text-gray-500 focus:border-accent ${
                          isBillingFieldInvalid(billingDraft, 'address_line1', billingTouched) ? 'border-red-500/50' : 'border-white/10'
                        }`}
                      />
                    </label>

                    <label className="block">
                      <span className="mb-1.5 block text-xs font-medium text-gray-400">{t('wallet.billing_address_line2')}</span>
                      <input
                        type="text"
                        value={billingDraft.address_line2 ?? ''}
                        onChange={(event) => updateBillingAddress('address_line2', event.target.value)}
                        placeholder={t('wallet.billing_address_line2_placeholder')}
                        className="w-full rounded-lg border border-white/10 bg-surface px-3 py-2.5 text-sm text-white outline-none transition placeholder:text-gray-500 focus:border-accent"
                      />
                    </label>

                    <div className="grid grid-cols-1 gap-3 sm:grid-cols-2">
                      <label className="block">
                        <span className="mb-1.5 block text-xs font-medium text-gray-400">{t('wallet.billing_city')}</span>
                        <input
                          type="text"
                          value={billingDraft.city}
                          onChange={(event) => updateBillingAddress('city', event.target.value)}
                          className={`w-full rounded-lg border bg-surface px-3 py-2.5 text-sm text-white outline-none transition placeholder:text-gray-500 focus:border-accent ${
                            isBillingFieldInvalid(billingDraft, 'city', billingTouched) ? 'border-red-500/50' : 'border-white/10'
                          }`}
                        />
                      </label>
                      <label className="block">
                        <span className="mb-1.5 block text-xs font-medium text-gray-400">{t('wallet.billing_postal_code')}</span>
                        <input
                          type="text"
                          value={billingDraft.postal_code}
                          onChange={(event) => updateBillingAddress('postal_code', event.target.value)}
                          className={`w-full rounded-lg border bg-surface px-3 py-2.5 text-sm text-white outline-none transition placeholder:text-gray-500 focus:border-accent ${
                            isBillingFieldInvalid(billingDraft, 'postal_code', billingTouched) ? 'border-red-500/50' : 'border-white/10'
                          }`}
                        />
                      </label>
                    </div>

                    <label className="block">
                      <span className="mb-1.5 block text-xs font-medium text-gray-400">{t('wallet.billing_region')}</span>
                      <input
                        type="text"
                        value={billingDraft.administrative_area ?? ''}
                        onChange={(event) => updateBillingAddress('administrative_area', event.target.value)}
                        placeholder={t('wallet.billing_region_placeholder')}
                        className="w-full rounded-lg border border-white/10 bg-surface px-3 py-2.5 text-sm text-white outline-none transition placeholder:text-gray-500 focus:border-accent"
                      />
                    </label>
                  </div>
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

function emptyBillingAddress(fullName = ''): StorefrontBillingAddressPayload {
  return {
    full_name: fullName,
    country_code: DEFAULT_COUNTRY,
    administrative_area: '',
    city: '',
    postal_code: '',
    address_line1: '',
    address_line2: '',
  };
}

function hasCompleteBillingAddress(address: StorefrontBillingAddressPayload): boolean {
  return REQUIRED_BILLING_FIELDS.every((field) => String(address[field] ?? '').trim().length >= 2)
    && String(address.address_line1 ?? '').trim().length >= 3
    && /^[A-Za-z]{2}$/.test(String(address.country_code ?? ''));
}

function isBillingFieldInvalid(
  address: StorefrontBillingAddressPayload,
  field: keyof StorefrontBillingAddressPayload,
  touched: boolean,
): boolean {
  if (!touched || !REQUIRED_BILLING_FIELDS.includes(field)) {
    return false;
  }

  const value = String(address[field] ?? '').trim();

  return field === 'address_line1' ? value.length < 3 : value.length < 2;
}

function normalizeBillingAddress(address: StorefrontBillingAddressPayload): StorefrontBillingAddressPayload {
  return {
    id: address.id,
    full_name: String(address.full_name ?? '').trim().replace(/\s+/g, ' '),
    country_code: String(address.country_code ?? '').trim().toUpperCase(),
    administrative_area: optionalBillingValue(address.administrative_area),
    city: String(address.city ?? '').trim().replace(/\s+/g, ' '),
    postal_code: String(address.postal_code ?? '').trim().toUpperCase(),
    address_line1: String(address.address_line1 ?? '').trim().replace(/\s+/g, ' '),
    address_line2: optionalBillingValue(address.address_line2),
  };
}

function optionalBillingValue(value: unknown): string | null {
  const normalized = String(value ?? '').trim().replace(/\s+/g, ' ');

  return normalized.length > 0 ? normalized : null;
}

function formatBillingAddress(address: StorefrontBillingAddressPayload): string {
  return [
    address.address_line1,
    address.address_line2,
    [address.city, address.administrative_area, address.postal_code].filter(Boolean).join(', '),
    address.country_code,
  ]
    .filter(Boolean)
    .join(' · ');
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
