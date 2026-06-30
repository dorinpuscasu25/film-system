import React, { useEffect, useMemo, useState } from 'react';
import { motion, AnimatePresence } from 'framer-motion';
import { PlusIcon, XIcon, WalletIcon } from 'lucide-react';
import { Movie, Offer } from '../types';
import { useWallet } from '../contexts/WalletContext';
import { useLanguage } from '../contexts/LanguageContext';
import { WalletModal } from './WalletModal';
import { resizedImageUrl } from '../lib/images';

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
  return offer.accessType === 'lifetime' ? t('checkout.forever') : t('checkout.for_days', { days: offer.rentalDays || 2 });
}

function offerDurationLabel(offer: Offer, t: (key: string, options?: Record<string, unknown>) => string) {
  if (offer.accessType === 'free') return t('checkout.free_access');
  return offer.accessType === 'lifetime' ? t('checkout.forever') : t('checkout.for_days', { days: offer.rentalDays || 2 });
}

export function PurchaseModal({
  isOpen,
  onClose,
  movie,
  onSuccess
}: PurchaseModalProps) {
  const { balance, currency, purchaseAccess } = useWallet();
  const { t } = useLanguage();
  const [isProcessing, setIsProcessing] = useState(false);
  const [errorMessage, setErrorMessage] = useState<string | null>(null);
  const [isWalletModalOpen, setIsWalletModalOpen] = useState(false);
  const offers = useMemo(() => movie.offers && movie.offers.length > 0 ? movie.offers : buildFallbackOffers(movie), [movie]);
  const [selectedOfferId, setSelectedOfferId] = useState<string>(offers[0]?.id ?? '');

  useEffect(() => {
    if (isOpen) {
      setSelectedOfferId(offers[0]?.id ?? '');
      setErrorMessage(null);
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

  const handlePurchase = async () => {
    if (!selectedOffer || !canAfford) {
      return;
    }

    setIsProcessing(true);
    setErrorMessage(null);

    try {
      await purchaseAccess(selectedOffer.id);
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
              <p className="mb-5 max-w-lg text-center text-sm text-gray-300 drop-shadow sm:mb-10 sm:text-base">
                {t('checkout.choose_option')}
              </p>

              <div className="mb-6 grid w-full max-w-3xl grid-cols-1 gap-4 sm:mb-10 md:grid-cols-2 md:gap-5">
                {Object.entries(groupedOffers).map(([label, group]) =>
                <div key={label} className="space-y-2 sm:space-y-3">
                    <h3 className="mb-2 text-center text-xs font-bold uppercase tracking-[0.22em] text-white sm:mb-4 sm:text-sm">
                      {label}
                    </h3>

                    {group.map((offer) =>
                  <button
                    key={offer.id}
                    onClick={() => setSelectedOfferId(offer.id)}
                    className={`w-full rounded-xl border-2 p-3 text-left backdrop-blur-md transition-all sm:p-5 ${selectedOffer?.id === offer.id ? 'bg-white/20 border-white shadow-[0_0_30px_rgba(255,255,255,0.2)]' : 'bg-black/40 border-white/10 hover:bg-black/60'}`}>

                        <div className="mb-2 flex items-start justify-between gap-3 sm:mb-4">
                          <div>
                            <div className="text-[10px] font-bold uppercase tracking-[0.18em] text-gray-300 sm:text-xs">
                              {offerDurationLabel(offer, t)}
                            </div>
                            <div className="mt-0.5 text-2xl font-bold leading-tight text-white sm:mt-1 sm:text-3xl">{offer.quality}</div>
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
                onClick={handlePurchase}
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
            </div>
          </motion.div>
          <WalletModal
            isOpen={isWalletModalOpen}
            onClose={() => setIsWalletModalOpen(false)}
          />
        </div>
      }
    </AnimatePresence>);

}
