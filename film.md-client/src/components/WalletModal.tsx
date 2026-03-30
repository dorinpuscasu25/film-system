import React from 'react';
import { motion, AnimatePresence } from 'framer-motion';
import { XIcon, Clock3Icon } from 'lucide-react';
import { useWallet } from '../contexts/WalletContext';
interface WalletModalProps {
  isOpen: boolean;
  onClose: () => void;
}
export function WalletModal({ isOpen, onClose }: WalletModalProps) {
  const { balance, currency } = useWallet();
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
              Wallet
            </h2>

            <div className="bg-surface p-4 rounded-xl mb-6 flex items-center justify-between border border-white/5">
              <span className="text-gray-400">Current Balance</span>
              <span className="text-2xl font-bold text-accentGreen">
                {currency} {balance.toFixed(2)}
              </span>
            </div>

            <div className="rounded-2xl border border-white/10 bg-surfaceHover/60 p-5 text-left">
              <div className="mb-4 flex items-center space-x-3">
                <div className="rounded-full bg-white/10 p-2">
                  <Clock3Icon className="w-5 h-5 text-white" />
                </div>
                <div>
                  <h3 className="text-white font-semibold">Top-ups are coming next</h3>
                  <p className="text-sm text-gray-400">We will connect wallet funding after the payment provider is selected.</p>
                </div>
              </div>

              <p className="text-sm leading-6 text-gray-300">
                For now every new account receives an automatic starting balance, and purchases already work with real wallet transactions.
              </p>
            </div>
          </motion.div>
        </div>
      }
    </AnimatePresence>);

}
