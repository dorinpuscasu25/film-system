import React, { useEffect, useMemo, useState } from 'react';
import { motion, AnimatePresence } from 'framer-motion';
import { XIcon, WalletIcon } from 'lucide-react';
import { Movie, Offer } from '../types';
import { useWallet } from '../contexts/WalletContext';

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

function groupLabel(offer: Offer) {
  if (offer.accessType === 'free') return 'Free Access';
  return offer.accessType === 'lifetime' ? 'Forever' : `For ${offer.rentalDays || 2} Days`;
}

export function PurchaseModal({
  isOpen,
  onClose,
  movie,
  onSuccess
}: PurchaseModalProps) {
  const { balance, currency, purchaseAccess } = useWallet();
  const [isProcessing, setIsProcessing] = useState(false);
  const [errorMessage, setErrorMessage] = useState<string | null>(null);
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
      const label = groupLabel(offer);
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
      setErrorMessage(error instanceof Error ? error.message : 'Purchase failed.');
      setIsProcessing(false);
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
          className="relative w-full max-w-4xl rounded-2xl overflow-hidden shadow-2xl z-10 min-h-[500px] flex flex-col">

            <div className="absolute inset-0 z-0">
              <img
              src={movie.backdropUrl}
              alt={movie.title}
              className="w-full h-full object-cover" />

              <div className="absolute inset-0 bg-gradient-to-t from-background via-background/80 to-background/40" />
            </div>

            <button
            onClick={onClose}
            className="absolute top-4 right-4 text-white/70 hover:text-white transition-colors z-20 bg-black/20 rounded-full p-2 backdrop-blur-md">

              <XIcon className="w-6 h-6" />
            </button>

            <div className="relative z-10 flex flex-col items-center justify-center p-8 flex-1">
              <h2 className="text-3xl font-bold text-white mb-2 text-center drop-shadow-lg">
                {movie.title}
              </h2>
              <p className="text-gray-300 mb-10 text-center max-w-lg drop-shadow">
                Choose your viewing option and quality
              </p>

              <div className="grid grid-cols-1 md:grid-cols-2 gap-8 w-full max-w-3xl mb-12">
                {Object.entries(groupedOffers).map(([label, group]) =>
                <div key={label} className="space-y-4">
                    <h3 className="text-center text-white font-bold tracking-widest uppercase text-sm mb-4">
                      {label}
                    </h3>

                    {group.map((offer) =>
                  <button
                    key={offer.id}
                    onClick={() => setSelectedOfferId(offer.id)}
                    className={`w-full p-6 rounded-xl backdrop-blur-md transition-all border-2 ${selectedOffer?.id === offer.id ? 'bg-white/20 border-white shadow-[0_0_30px_rgba(255,255,255,0.2)]' : 'bg-black/40 border-transparent hover:bg-black/60'}`}>

                        <div className="text-3xl font-bold text-white mb-2">{offer.quality}</div>
                        <div className="text-xl text-white font-medium">
                          {formatCurrency(offer.price, offer.currency)}
                        </div>
                      </button>
                  )}
                  </div>
                )}
              </div>

              <div className="w-full max-w-md flex flex-col items-center">
                <div className="flex justify-between items-center w-full mb-4 px-4">
                  <span className="text-gray-300 flex items-center text-sm">
                    <WalletIcon className="w-4 h-4 mr-2" />
                    Wallet Balance
                  </span>
                  <span
                  className={`font-bold ${canAfford ? 'text-accentGreen' : 'text-accent'}`}>

                    {currency} {balance.toFixed(2)}
                  </span>
                </div>

                {!canAfford &&
              <p className="text-accent text-sm mb-3">
                    Insufficient funds. Please add money to your wallet.
                  </p>
              }

                {errorMessage &&
                <p className="mb-3 rounded-lg border border-red-500/30 bg-red-500/10 px-4 py-3 text-sm text-red-200">
                    {errorMessage}
                  </p>
                }

                <button
                onClick={handlePurchase}
                disabled={!selectedOffer || !canAfford || isProcessing}
                className={`w-full py-4 rounded-xl font-bold text-lg transition-all flex items-center justify-center ${canAfford ? 'bg-white text-background hover:bg-gray-200' : 'bg-white/10 text-gray-500 cursor-not-allowed backdrop-blur-md'}`}>

                  {isProcessing ?
                <div className="w-6 h-6 border-2 border-background border-t-transparent rounded-full animate-spin" /> :
                selectedOffer ?
                `Confirm Purchase - ${formatCurrency(selectedOffer.price, selectedOffer.currency)}` :
                'No offer available'
                }
                </button>
              </div>
            </div>
          </motion.div>
        </div>
      }
    </AnimatePresence>);

}
