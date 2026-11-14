import { useEffect, useMemo, useState, type FormEvent } from 'react';
import { Edit3Icon, Loader2Icon, MapPinIcon } from 'lucide-react';
import { getCountries } from 'libphonenumber-js';
import { useLanguage } from '../contexts/LanguageContext';
import { useWallet } from '../contexts/WalletContext';
import { useAuth } from '../contexts/AuthContext';
import type { StorefrontBillingAddressPayload } from '../lib/session';

const DEFAULT_COUNTRY = 'MD';
const REQUIRED_BILLING_FIELDS: Array<keyof StorefrontBillingAddressPayload> = [
  'full_name',
  'country_code',
  'city',
  'postal_code',
  'address_line1',
];

export function emptyBillingAddress(fullName = ''): StorefrontBillingAddressPayload {
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

export function hasCompleteBillingAddress(address: StorefrontBillingAddressPayload): boolean {
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

export function normalizeBillingAddress(address: StorefrontBillingAddressPayload): StorefrontBillingAddressPayload {
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

export function formatBillingAddress(address: StorefrontBillingAddressPayload): string {
  return [
    address.address_line1,
    address.address_line2,
    [address.city, address.administrative_area, address.postal_code].filter(Boolean).join(', '),
    address.country_code,
  ]
    .filter(Boolean)
    .join(' · ');
}

interface BillingAddressFieldsProps {
  value: StorefrontBillingAddressPayload;
  onChange: (field: keyof StorefrontBillingAddressPayload, value: string) => void;
  touched: boolean;
}

/** The address inputs, shared by the top-up form and the profile. */
export function BillingAddressFields({ value, onChange, touched }: BillingAddressFieldsProps) {
  const { t, currentLanguage } = useLanguage();

  const countryOptions = useMemo(() => {
    const displayNames =
      typeof Intl !== 'undefined' && 'DisplayNames' in Intl
        ? new Intl.DisplayNames([currentLanguage.code], { type: 'region' })
        : null;

    return getCountries()
      .map((country) => ({ country, name: displayNames?.of(country) ?? country }))
      .sort((a, b) => a.name.localeCompare(b.name, currentLanguage.code));
  }, [currentLanguage.code]);

  const inputClass = (field: keyof StorefrontBillingAddressPayload) =>
    `w-full rounded-lg border bg-surface px-3 py-2.5 text-sm text-white outline-none transition placeholder:text-gray-500 focus:border-accent ${
      isBillingFieldInvalid(value, field, touched) ? 'border-red-500/50' : 'border-white/10'
    }`;

  return (
    <div className="space-y-3">
      <label className="block">
        <span className="mb-1.5 block text-xs font-medium text-gray-400">{t('wallet.billing_full_name')}</span>
        <input
          type="text"
          autoComplete="name"
          value={value.full_name}
          onChange={(event) => onChange('full_name', event.target.value)}
          className={inputClass('full_name')}
        />
      </label>

      <label className="block">
        <span className="mb-1.5 block text-xs font-medium text-gray-400">{t('wallet.billing_country')}</span>
        <select
          value={value.country_code}
          onChange={(event) => onChange('country_code', event.target.value)}
          className={inputClass('country_code')}
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
          autoComplete="address-line1"
          value={value.address_line1}
          onChange={(event) => onChange('address_line1', event.target.value)}
          placeholder={t('wallet.billing_address_line1_placeholder')}
          className={inputClass('address_line1')}
        />
      </label>

      <label className="block">
        <span className="mb-1.5 block text-xs font-medium text-gray-400">{t('wallet.billing_address_line2')}</span>
        <input
          type="text"
          autoComplete="address-line2"
          value={value.address_line2 ?? ''}
          onChange={(event) => onChange('address_line2', event.target.value)}
          placeholder={t('wallet.billing_address_line2_placeholder')}
          className={inputClass('address_line2')}
        />
      </label>

      <div className="grid grid-cols-1 gap-3 sm:grid-cols-2">
        <label className="block">
          <span className="mb-1.5 block text-xs font-medium text-gray-400">{t('wallet.billing_city')}</span>
          <input
            type="text"
            autoComplete="address-level2"
            value={value.city}
            onChange={(event) => onChange('city', event.target.value)}
            className={inputClass('city')}
          />
        </label>
        <label className="block">
          <span className="mb-1.5 block text-xs font-medium text-gray-400">{t('wallet.billing_postal_code')}</span>
          <input
            type="text"
            autoComplete="postal-code"
            value={value.postal_code}
            onChange={(event) => onChange('postal_code', event.target.value)}
            className={inputClass('postal_code')}
          />
        </label>
      </div>

      <label className="block">
        <span className="mb-1.5 block text-xs font-medium text-gray-400">{t('wallet.billing_region')}</span>
        <input
          type="text"
          autoComplete="address-level1"
          value={value.administrative_area ?? ''}
          onChange={(event) => onChange('administrative_area', event.target.value)}
          placeholder={t('wallet.billing_region_placeholder')}
          className={inputClass('administrative_area')}
        />
      </label>
    </div>
  );
}

/** Profile card: shows the saved billing address and lets the account holder change it. */
export function BillingAddressCard() {
  const { t } = useLanguage();
  const { user, activeProfile } = useAuth();
  const { billingAddress, saveBillingAddress } = useWallet();
  const canEdit = !activeProfile?.isKids;
  const [isEditing, setIsEditing] = useState(false);
  const [draft, setDraft] = useState<StorefrontBillingAddressPayload>(() => emptyBillingAddress());
  const [touched, setTouched] = useState(false);
  const [isSaving, setIsSaving] = useState(false);
  const [message, setMessage] = useState<{ type: 'success' | 'error'; text: string } | null>(null);

  useEffect(() => {
    if (!isEditing) {
      setDraft(billingAddress ?? emptyBillingAddress(user?.name));
    }
  }, [billingAddress, isEditing, user?.name]);

  function startEditing() {
    setDraft(billingAddress ?? emptyBillingAddress(user?.name));
    setTouched(false);
    setMessage(null);
    setIsEditing(true);
  }

  function updateField(field: keyof StorefrontBillingAddressPayload, value: string) {
    setDraft((current) => ({ ...current, [field]: field === 'country_code' ? value.toUpperCase() : value }));
    setTouched(true);
    setMessage(null);
  }

  async function handleSave(event: FormEvent) {
    event.preventDefault();
    setTouched(true);

    if (!hasCompleteBillingAddress(draft)) {
      setMessage({ type: 'error', text: t('wallet.billing_address_required') });
      return;
    }

    setIsSaving(true);
    setMessage(null);

    try {
      await saveBillingAddress(normalizeBillingAddress(draft));
      setIsEditing(false);
      setMessage({ type: 'success', text: t('wallet.billing_address_saved') });
    } catch (error) {
      setMessage({
        type: 'error',
        text: error instanceof Error && error.message ? error.message : t('wallet.billing_address_save_failed'),
      });
    } finally {
      setIsSaving(false);
    }
  }

  return (
    <div className="rounded-2xl border border-white/10 bg-white/5 p-6">
      <div className="mb-4 flex items-start justify-between gap-4">
        <div className="flex items-start gap-3">
          <div className="rounded-full bg-white/10 p-2">
            <MapPinIcon className="h-4 w-4 text-white" />
          </div>
          <div>
            <h3 className="font-semibold text-white">{t('wallet.billing_address')}</h3>
            <p className="text-xs text-gray-400">{t('wallet.billing_address_note')}</p>
          </div>
        </div>
        {!isEditing && canEdit ? (
          <button
            type="button"
            onClick={startEditing}
            className="flex shrink-0 items-center gap-1 rounded-lg border border-white/10 px-3 py-2 text-xs font-semibold text-white transition hover:bg-white/10"
          >
            <Edit3Icon className="h-3.5 w-3.5" />
            {billingAddress ? t('wallet.edit_billing_address') : t('wallet.add_billing_address')}
          </button>
        ) : null}
      </div>

      {isEditing ? (
        <form onSubmit={handleSave} className="space-y-4">
          <BillingAddressFields value={draft} onChange={updateField} touched={touched} />
          <div className="flex gap-3">
            <button
              type="submit"
              disabled={isSaving}
              className="flex flex-1 items-center justify-center gap-2 rounded-lg bg-accent px-4 py-2.5 text-sm font-semibold text-white transition hover:bg-red-700 disabled:opacity-60"
            >
              {isSaving ? <Loader2Icon className="h-4 w-4 animate-spin" /> : null}
              {isSaving ? t('dashboard.saving') : t('common.save')}
            </button>
            <button
              type="button"
              disabled={isSaving}
              onClick={() => {
                setIsEditing(false);
                setMessage(null);
              }}
              className="rounded-lg border border-white/10 px-4 py-2.5 text-sm font-semibold text-white transition hover:bg-white/10 disabled:opacity-60"
            >
              {t('common.cancel')}
            </button>
          </div>
        </form>
      ) : billingAddress ? (
        <div className="rounded-lg border border-white/10 bg-black/20 px-4 py-3 text-sm text-gray-300">
          <p className="font-semibold text-white">{billingAddress.full_name}</p>
          <p>{formatBillingAddress(billingAddress)}</p>
        </div>
      ) : (
        <p className="text-sm text-gray-400">{t('wallet.billing_address_empty')}</p>
      )}

      {message ? (
        <p className={`mt-3 text-sm ${message.type === 'success' ? 'text-accentGreen' : 'text-red-200'}`}>{message.text}</p>
      ) : null}
    </div>
  );
}
