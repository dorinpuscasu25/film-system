import React, { useEffect, useMemo, useState } from 'react';
import { motion, AnimatePresence } from 'framer-motion';
import { PlusIcon, XIcon, WalletIcon } from 'lucide-react';
import { Movie, Offer } from '../types';
import { useWallet } from '../contexts/WalletContext';
import { useLanguage } from '../contexts/LanguageContext';
import { useAuth } from '../contexts/AuthContext';
import { WalletModal } from './WalletModal';
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

function offerDurationLabel(offer: Offer, t: (key: string, options?: Record<string, unknown>) => string) {
  if (offer.accessType === 'free') return t('checkout.free_access');
  return offer.accessType === 'lifetime' ? t('checkout.forever') : t('checkout.for_days', { count: offer.rentalDays || 2 });
}

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
  const [selectedOfferId, setSelectedOfferId] = useState<string>(offers[0]?.id ?? '');
  const [isAccessLocationStepOpen, setIsAccessLocationStepOpen] = useState(false);
  const [accessLocation, setAccessLocation] = useState<AccessLocation | null>(null);

  useEffect(() => {
    if (isOpen) {
      setSelectedOfferId(offers[0]?.id ?? '');
      setErrorMessage(null);
      setIsAccessLocationStepOpen(false);
      setAccessLocation(null);
    }
  }, [isOpen, offers]);

  const selectedOffer = offers.find((offer) => offer.id === selectedOfferId) ?? offers[0];
  const canAfford = selectedOffer ? balance >= selectedOffer.price : false;
  const groupedOffers = useMemo(() => {
    return offers.reduce<Record<string, Offer[]>>((groups, offer) => {
      const label = groupLabel(offer, t);
      groups[label] = groups[label] ?? [];
      groups[label].push(offer);
      return groups;
    }, {});
  }, [offers]);

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
                {Object.entries(groupedOffers).map(([label, group]) =>
                <div key={label} className="space-y-2 sm:space-y-3">
                    {group.map((offer) =>
                  <button
                    key={offer.id}
                    onClick={() => setSelectedOfferId(offer.id)}
                    className={`w-full rounded-xl border-2 p-3 text-left backdrop-blur-md transition-all sm:p-5 ${selectedOffer?.id === offer.id ? 'bg-white/20 border-white shadow-[0_0_30px_rgba(255,255,255,0.2)]' : 'bg-black/40 border-white/10 hover:bg-black/60'}`}>

                        <div className="mb-2 flex items-start justify-between gap-3 sm:mb-4">
                          <div>
                            <span className="inline-flex items-center rounded-md bg-accent/15 px-2 py-1 text-[10px] font-bold uppercase tracking-[0.18em] text-accent sm:text-xs">
                              {offerDurationLabel(offer, t)}
                            </span>
                            <div className="mt-2 text-2xl font-bold leading-tight text-white sm:text-3xl">{offer.quality}</div>
                          </div>
                        </div>
                        <div className="text-xl font-semibold text-white sm:text-2xl">
                          {formatCurrency(offer.price, offer.currency)}
                        </div>
                      </button>
                  )}
                  </div>
                )}
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

          <AnimatePresence>
            {isAccessLocationStepOpen && !isKidsProfile &&
          <div className="absolute inset-0 z-20 flex items-center justify-center p-4">
                <motion.div
              initial={{ opacity: 0 }}
              animate={{ opacity: 1 }}
              exit={{ opacity: 0 }}
              className="absolute inset-0 bg-black/80 backdrop-blur-sm"
              onClick={() => setIsAccessLocationStepOpen(false)} />

                <motion.div
              initial={{ scale: 0.95, opacity: 0 }}
              animate={{ scale: 1, opacity: 1 }}
              exit={{ scale: 0.95, opacity: 0 }}
              className="relative z-10 w-full max-w-md rounded-2xl border border-white/10 bg-surface p-5 shadow-2xl sm:p-6">

                  <h3 className="text-lg font-bold text-white sm:text-xl">
                    {t('checkout.access_location_title')}
                  </h3>
                  <p className="mt-1 text-sm text-gray-400">
                    {t('checkout.access_location_note')}
                  </p>
                  <p className="mb-5 mt-2 text-sm text-gray-400">
                    {t('checkout.access_location_subtitle')}
                  </p>

                  <div className="mb-5 space-y-3">
                    {([
                { value: 'moldova' as const, label: t('checkout.access_from_moldova') },
                { value: 'outside_moldova' as const, label: t('checkout.access_outside_moldova') }]).
                map((option) =>
                <button
                  key={option.value}
                  type="button"
                  role="radio"
                  aria-checked={accessLocation === option.value}
                  onClick={() => setAccessLocation(option.value)}
                  className={`flex w-full items-center gap-3 rounded-xl border p-3 text-left transition-colors sm:p-4 ${accessLocation === option.value ? 'border-white bg-white/10' : 'border-white/10 bg-black/30 hover:bg-white/5'}`}>

                        <span
                    className={`flex h-5 w-5 shrink-0 items-center justify-center rounded-full border-2 transition-colors ${accessLocation === option.value ? 'border-white' : 'border-white/30'}`}>

                          {accessLocation === option.value &&
                    <span className="h-2.5 w-2.5 rounded-full bg-white" />
                    }
                        </span>
                        <span className="text-sm text-gray-100">{option.label}</span>
                      </button>
                )}
                  </div>

                  <div className="flex flex-col-reverse gap-3 sm:flex-row">
                    <button
                  type="button"
                  onClick={() => setIsAccessLocationStepOpen(false)}
                  className="w-full rounded-xl border border-white/10 py-3 text-sm font-semibold text-white transition-colors hover:bg-white/10 sm:w-auto sm:px-5">

                      {t('common.back')}
                    </button>
                    <button
                  type="button"
                  onClick={handlePurchase}
                  disabled={!accessLocation}
                  className={`w-full rounded-xl py-3 text-sm font-bold transition-all ${accessLocation ? 'bg-white text-background hover:bg-gray-200' : 'cursor-not-allowed bg-white/10 text-gray-500'}`}>

                      {selectedOffer ?
                  t('checkout.confirm_purchase', { price: formatCurrency(selectedOffer.price, selectedOffer.currency) }) :
                  t('checkout.no_offer')
                  }
                    </button>
                  </div>
                </motion.div>
              </div>
          }
          </AnimatePresence>

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
