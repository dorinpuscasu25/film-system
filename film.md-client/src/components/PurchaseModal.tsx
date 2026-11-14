import React, { useEffect, useMemo, useState } from 'react';
import { motion, AnimatePresence } from 'framer-motion';
import { CalendarDaysIcon, InfinityIcon, PlusIcon, XIcon, WalletIcon } from 'lucide-react';
import { Movie, Offer } from '../types';
import { useWallet } from '../contexts/WalletContext';
import { useLanguage } from '../contexts/LanguageContext';
import { useAuth } from '../contexts/AuthContext';
import { WalletModal } from './WalletModal';
import { AccessLocationStep } from './AccessLocationStep';
import { resizedImageUrl } from '../lib/images';
import type { StorefrontAccessLocation } from '../lib/session';

type AccessLocation = StorefrontAccessLocation;

interface PurchaseModalProps {
  isOpen: boolean;
  onClose: () => void;
  movie: Movie;
  onSuccess?: () => void;
}

function formatCurrency(amount: number, currency: string) {
  return new Intl.NumberFormat('en-US', {
    style: 'currency',
    currency: currency || 'MDL'
  }).format(amount);
}

function buildFallbackOffers(movie: Movie): Offer[] {
  return [
  {
    id: `${movie.id}-life-hd`,
    name: 'Forever HD',
    accessType: 'lifetime',
    quality: 'HD',
    price: movie.price * 2,
    currency: 'MDL'
  },
  {
    id: `${movie.id}-life-sd`,
    name: 'Forever SD',
    accessType: 'lifetime',
    quality: 'SD',
    price: movie.price * 1.5,
    currency: 'MDL'
  },
  {
    id: `${movie.id}-2d-hd`,
    name: '2 days HD',
    accessType: 'rental',
    quality: 'HD',
    price: movie.price,
    currency: 'MDL',
    rentalDays: 2
  },
  {
    id: `${movie.id}-2d-sd`,
    name: '2 days SD',
    accessType: 'rental',
    quality: 'SD',
    price: movie.price * 0.7,
    currency: 'MDL',
    rentalDays: 2
  }];
}

function groupLabel(offer: Offer, t: (key: string, options?: Record<string, unknown>) => string) {
  if (offer.accessType === 'free') return t('checkout.free_access');
  return offer.accessType === 'lifetime' ? t('checkout.forever') : t('checkout.for_days', { count: offer.rentalDays || 2 });
}

/** Longer access ranks higher when two groups cost the same. */
function durationRank(offer: Offer) {
  return offer.accessType === 'lifetime' ? Number.MAX_SAFE_INTEGER : offer.rentalDays ?? 0;
}

/**
 * One colour per duration block, in display order: gold for the top (most expensive),
 * brand red next, then green.
 */
const GROUP_TINTS = [
  { badge: 'bg-accentGold text-black', edge: 'border-l-accentGold', selected: 'border-accentGold shadow-[0_0_30px_rgba(255,184,0,0.25)]' },
  { badge: 'bg-accent text-white', edge: 'border-l-accent', selected: 'border-accent shadow-[0_0_30px_rgba(229,9,20,0.3)]' },
  { badge: 'bg-accentGreen text-black', edge: 'border-l-accentGreen', selected: 'border-accentGreen shadow-[0_0_30px_rgba(0,200,83,0.25)]' },
];
const FALLBACK_TINT = { badge: 'bg-white/85 text-black', edge: 'border-l-white/85', selected: 'border-white shadow-[0_0_30px_rgba(255,255,255,0.2)]' };

export function PurchaseModal({
  isOpen,
  onClose,
  movie,
  onSuccess
}: PurchaseModalProps) {
  const { balance, currency, purchaseAccess } = useWallet();
  const { t } = useLanguage();
  const { activeProfile } = useAuth();
  const isKidsProfile = Boolean(activeProfile?.isKids);
  const [isProcessing, setIsProcessing] = useState(false);
  const [errorMessage, setErrorMessage] = useState<string | null>(null);
  const [isWalletModalOpen, setIsWalletModalOpen] = useState(false);
  const offers = useMemo(() => movie.offers && movie.offers.length > 0 ? movie.offers : buildFallbackOffers(movie), [movie]);
  // Most expensive first (7 days before 2 days, FullHD before SD): the bigger price anchors
  // the choice, so viewers weigh the options instead of grabbing the first cheap line.
  const offerGroups = useMemo(() => {
    const groups = new Map<string, Offer[]>();
    for (const offer of offers) {
      const label = groupLabel(offer, t);
      groups.set(label, [...(groups.get(label) ?? []), offer]);
    }

    return [...groups.entries()]
      .map(([label, group]) => ({
        label,
        isLifetime: group[0].accessType === 'lifetime',
        offers: [...group].sort((left, right) => right.price - left.price || String(left.quality).localeCompare(String(right.quality))),
      }))
      .sort((left, right) => right.offers[0].price - left.offers[0].price || durationRank(right.offers[0]) - durationRank(left.offers[0]));
  }, [offers, t]);
  const defaultOfferId = offerGroups[0]?.offers[0]?.id ?? '';
  const [selectedOfferId, setSelectedOfferId] = useState<string>(defaultOfferId);
  const [isAccessLocationStepOpen, setIsAccessLocationStepOpen] = useState(false);
  const [accessLocation, setAccessLocation] = useState<AccessLocation | null>(null);

  useEffect(() => {
    if (isOpen) {
      setSelectedOfferId(defaultOfferId);
      setErrorMessage(null);
      setIsAccessLocationStepOpen(false);
      setAccessLocation(null);
    }
  }, [isOpen, defaultOfferId]);

  const selectedOffer = offers.find((offer) => offer.id === selectedOfferId) ?? offers[0];
  const canAfford = selectedOffer ? balance >= selectedOffer.price : false;

  const handleConfirmClick = () => {
    if (!selectedOffer || !canAfford) {
      return;
    }

    setErrorMessage(null);
    setAccessLocation(null);
    setIsAccessLocationStepOpen(true);
  };

  const handlePurchase = async () => {
    if (!selectedOffer || !canAfford || !accessLocation) {
      return;
    }

    setIsAccessLocationStepOpen(false);
    setIsProcessing(true);
    setErrorMessage(null);

    try {
      await purchaseAccess(selectedOffer.id, { accessLocation });
      setIsProcessing(false);
      onSuccess?.();
      onClose();
    } catch (error) {
      setErrorMessage(error instanceof Error ? error.message : t('checkout.purchase_failed'));
      setIsProcessing(false);
    }
  };

  return (
    <AnimatePresence>
      {isOpen &&
      <div className="fixed inset-0 z-50 flex items-stretch justify-center p-0 sm:items-center sm:p-4">
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
          className="absolute inset-0 bg-black/90 backdrop-blur-sm"
          onClick={onClose} />

          <motion.div
          initial={{
            scale: 0.95,
            opacity: 0
          }}
          animate={{
            scale: 1,
            opacity: 1
          }}
          exit={{
            scale: 0.95,
            opacity: 0
          }}
          className="relative z-10 flex h-[100dvh] w-full flex-col overflow-hidden rounded-none shadow-2xl sm:h-auto sm:max-h-[calc(100dvh-2rem)] sm:max-w-4xl sm:rounded-2xl md:min-h-[500px]">

            <div className="absolute inset-0 z-0">
              <img
              src={resizedImageUrl(movie.backdropUrl, { width: 960, height: 540 })}
              alt={movie.title}
              className="w-full h-full object-cover" />

              <div className="absolute inset-0 bg-gradient-to-t from-background via-background/80 to-background/40" />
            </div>

            <button
            onClick={onClose}
            className="absolute right-3 top-3 z-20 rounded-full bg-black/25 p-2 text-white/70 backdrop-blur-md transition-colors hover:text-white sm:right-4 sm:top-4">

              <XIcon className="h-5 w-5 sm:h-6 sm:w-6" />
            </button>

            <div className="relative z-10 flex flex-1 flex-col items-center overflow-y-auto px-4 pb-5 pt-14 sm:p-8">
              <h2 className="mb-1 max-w-[85%] text-center text-xl font-bold leading-tight text-white drop-shadow-lg sm:mb-2 sm:max-w-none sm:text-3xl">
                {movie.title}
              </h2>
              {isKidsProfile ? (
                <div className="mt-8 w-full max-w-lg rounded-2xl border border-amber-400/30 bg-black/55 p-6 text-center backdrop-blur-md">
                  <WalletIcon className="mx-auto mb-4 h-10 w-10 text-amber-300" />
                  <p className="text-base font-semibold text-white">{t('checkout.kids_restricted')}</p>
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
              <p className="mb-5 max-w-lg text-center text-sm text-gray-300 drop-shadow sm:mb-10 sm:text-base">
                {t('checkout.choose_option')}
              </p>

              <div className="mb-6 grid w-full max-w-3xl grid-cols-1 gap-4 sm:mb-10 md:grid-cols-2 md:gap-5">
                {offerGroups.map((group, index) => {
                  const tint = GROUP_TINTS[index] ?? FALLBACK_TINT;
                  return (
                <div key={group.label} className="space-y-2 sm:space-y-3">
                    <div className={`inline-flex items-center gap-2 rounded-full px-3 py-1.5 text-xs font-black uppercase tracking-[0.16em] shadow-lg sm:px-4 sm:py-2 sm:text-sm ${tint.badge}`}>
                      {group.isLifetime ? <InfinityIcon className="h-4 w-4" /> : <CalendarDaysIcon className="h-4 w-4" />}
                      {group.label}
                    </div>
                    {group.offers.map((offer) =>
                  <button
                    key={offer.id}
                    onClick={() => setSelectedOfferId(offer.id)}
                    className={`w-full rounded-xl border-2 border-l-[6px] p-3 text-left backdrop-blur-md transition-all sm:p-5 ${tint.edge} ${selectedOffer?.id === offer.id ? `bg-white/20 ${tint.selected}` : 'bg-black/40 border-white/10 hover:bg-black/60'}`}>

                        <div className="text-2xl font-bold leading-tight text-white sm:text-3xl">{offer.quality}</div>
                        <div className="mt-1 text-xl font-semibold text-white/90 sm:mt-2 sm:text-2xl">
                          {formatCurrency(offer.price, offer.currency)}
                        </div>
                      </button>
                  )}
                  </div>
                  );
                })}
              </div>

              <div className="flex w-full max-w-md flex-col items-center">
                <div className="mb-3 flex w-full items-center justify-between px-1 sm:mb-4 sm:px-4">
                  <span className="flex items-center text-sm text-gray-300">
                    <WalletIcon className="w-4 h-4 mr-2" />
                    {t('checkout.wallet_balance')}
                  </span>
                  <span
                  className={`font-bold ${canAfford ? 'text-accentGreen' : 'text-accent'}`}>

                    {currency} {balance.toFixed(2)}
                  </span>
                </div>

                {!canAfford &&
                  <div className="mb-3 w-full rounded-xl border border-accent/30 bg-accent/10 p-3 text-center">
                    <p className="mb-3 text-sm text-accent">
                      {t('checkout.insufficient_funds')}
                    </p>
                    <button
                      type="button"
                      onClick={() => setIsWalletModalOpen(true)}
                      className="inline-flex items-center justify-center gap-2 rounded-lg bg-accent px-4 py-2 text-sm font-bold text-white transition hover:bg-red-700"
                    >
                      <PlusIcon className="h-4 w-4" />
                      {t('checkout.top_up_wallet')}
                    </button>
                  </div>
              }

                {errorMessage &&
                <p className="mb-3 rounded-lg border border-red-500/30 bg-red-500/10 px-4 py-3 text-sm text-red-200">
                    {errorMessage}
                  </p>
                }

                <button
                onClick={handleConfirmClick}
                disabled={!selectedOffer || !canAfford || isProcessing}
                className={`flex w-full items-center justify-center rounded-xl py-3 text-sm font-bold transition-all sm:py-4 sm:text-lg ${canAfford ? 'bg-white text-background hover:bg-gray-200' : 'bg-white/10 text-gray-500 cursor-not-allowed backdrop-blur-md'}`}>

                  {isProcessing ?
                <div className="w-6 h-6 border-2 border-background border-t-transparent rounded-full animate-spin" /> :
                selectedOffer ?
                t('checkout.confirm_purchase', { price: formatCurrency(selectedOffer.price, selectedOffer.currency) }) :
                t('checkout.no_offer')
                }
                </button>
              </div>
                </>
              )}
            </div>
          </motion.div>

          <AccessLocationStep
            isOpen={isAccessLocationStepOpen && !isKidsProfile}
            value={accessLocation}
            onChange={setAccessLocation}
            onBack={() => setIsAccessLocationStepOpen(false)}
            onConfirm={() => void handlePurchase()}
            isProcessing={isProcessing}
            confirmLabel={selectedOffer ?
            t('checkout.confirm_purchase', { price: formatCurrency(selectedOffer.price, selectedOffer.currency) }) :
            t('checkout.no_offer')}
          />

          <WalletModal
            isOpen={isWalletModalOpen && !isKidsProfile}
            onClose={() => setIsWalletModalOpen(false)}
            returnContext={{
              movieId: movie.id,
              movieTitle: movie.title,
            }}
          />
        </div>
      }
    </AnimatePresence>);

}
