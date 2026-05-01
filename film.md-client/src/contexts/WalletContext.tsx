import React, { ReactNode, createContext, useContext, useEffect, useMemo, useState } from "react";
import { Purchase, WalletTransaction } from "../types";
import { useAuth } from "./AuthContext";
import { useLanguage } from "./LanguageContext";
import {
  createStorefrontWalletTopUp,
  favoriteStorefrontContent,
  fetchStorefrontAccount,
  purchaseStorefrontOffer,
  unfavoriteStorefrontContent,
} from "../lib/session";
import type { StorefrontTopUpPayload } from "../lib/session";

interface WalletContextType {
  balance: number;
  currency: string;
  transactions: WalletTransaction[];
  purchases: Purchase[];
  favorites: string[];
  isLoading: boolean;
  addFunds: (amount: number, options?: { phone?: string }) => Promise<StorefrontTopUpPayload>;
  purchaseAccess: (offerId: string) => Promise<void>;
  hasAccess: (movieId: string) => boolean;
  getTimeRemaining: (movieId: string) => string | null;
  toggleFavorite: (movieId: string) => Promise<void>;
  isFavorite: (movieId: string) => boolean;
  refreshWallet: () => Promise<void>;
}

const WalletContext = createContext<WalletContextType | undefined>(undefined);

function mapTransaction(transaction: Awaited<ReturnType<typeof fetchStorefrontAccount>>["transactions"][number]): WalletTransaction {
  const contentSlug = typeof transaction.meta?.content_slug === "string" ? transaction.meta.content_slug : undefined;

  return {
    id: String(transaction.id),
    type: transaction.type as WalletTransaction["type"],
    amount: Number(transaction.amount ?? 0),
    balanceAfter: Number(transaction.balance_after ?? 0),
    currency: transaction.currency,
    description: transaction.description ?? undefined,
    status: transaction.status ?? undefined,
    date: transaction.processed_at ?? transaction.created_at ?? new Date().toISOString(),
    movieTitle: contentSlug,
  };
}

function mapPurchase(item: Awaited<ReturnType<typeof fetchStorefrontAccount>>["library"][number]): Purchase {
  return {
    id: String(item.id),
    movieId: item.content_slug,
    movieTitle: item.content_title,
    purchaseDate: item.granted_at ?? new Date().toISOString(),
    expiresAt: item.expires_at ?? null,
    price: Number(item.price_amount ?? 0),
    currency: item.currency,
    quality: item.quality ?? undefined,
    accessType: item.access_type,
    isActive: Boolean(item.is_active),
    posterUrl: item.poster_url ?? undefined,
    backdropUrl: item.backdrop_url ?? undefined,
    contentType: item.content_type,
  };
}

export function WalletProvider({ children }: { children: ReactNode }) {
  const { isAuthenticated, user, activeProfile, isLoading: isAuthLoading } = useAuth();
  const { currentLanguage } = useLanguage();
  const [balance, setBalance] = useState<number>(0);
  const [currency, setCurrency] = useState<string>("MDL");
  const [transactions, setTransactions] = useState<WalletTransaction[]>([]);
  const [purchases, setPurchases] = useState<Purchase[]>([]);
  const [favoritesByProfile, setFavoritesByProfile] = useState<Record<string, string[]>>({});
  const [isLoading, setIsLoading] = useState(false);

  const favorites = useMemo(() => {
    if (!activeProfile) {
      return [];
    }

    return favoritesByProfile[activeProfile.id] ?? [];
  }, [activeProfile, favoritesByProfile]);

  const loadAccount = async () => {
    if (!user) {
      setBalance(0);
      setCurrency("MDL");
      setTransactions([]);
      setPurchases([]);
      setFavoritesByProfile({});
      return;
    }

    setIsLoading(true);

    try {
      const response = await fetchStorefrontAccount(currentLanguage.code);
      setBalance(Number(response.wallet.balance_amount ?? 0));
      setCurrency(response.wallet.currency ?? "MDL");
      setTransactions((response.transactions ?? []).map(mapTransaction));
      setPurchases((response.library ?? []).map(mapPurchase));
      setFavoritesByProfile(response.favorites_by_profile ?? {});
    } catch {
      setBalance(0);
      setCurrency("MDL");
      setTransactions([]);
      setPurchases([]);
      setFavoritesByProfile({});
    } finally {
      setIsLoading(false);
    }
  };

  useEffect(() => {
    if (isAuthLoading) {
      return;
    }

    if (!isAuthenticated || !user) {
      setBalance(0);
      setCurrency("MDL");
      setTransactions([]);
      setPurchases([]);
      setFavoritesByProfile({});
      return;
    }

    void loadAccount();
  }, [currentLanguage.code, isAuthenticated, isAuthLoading, user?.id]);

  const addFunds = async (amount: number, options?: { phone?: string }) => {
    const response = await createStorefrontWalletTopUp({
      amount,
      currency,
      phone: options?.phone,
      locale: currentLanguage.code,
    });

    return response.top_up;
  };

  const purchaseAccess = async (offerId: string) => {
    await purchaseStorefrontOffer(offerId, currentLanguage.code);
    await loadAccount();
  };

  const hasAccess = (movieId: string): boolean => {
    const purchase = purchases.find((item) => item.movieId === movieId);
    if (!purchase) {
      return false;
    }

    if (!purchase.expiresAt) {
      return true;
    }

    return new Date() < new Date(purchase.expiresAt);
  };

  const getTimeRemaining = (movieId: string): string | null => {
    const purchase = purchases.find((item) => item.movieId === movieId);
    if (!purchase) {
      return null;
    }

    if (!purchase.expiresAt) {
      return "Lifetime";
    }

    const now = new Date();
    const expiresAt = new Date(purchase.expiresAt);
    if (now >= expiresAt) {
      return null;
    }

    const diffMs = expiresAt.getTime() - now.getTime();
    const diffDays = Math.floor(diffMs / (1000 * 60 * 60 * 24));
    const diffHrs = Math.floor((diffMs % (1000 * 60 * 60 * 24)) / (1000 * 60 * 60));
    const diffMins = Math.floor((diffMs % (1000 * 60 * 60)) / (1000 * 60));

    if (diffDays > 0) {
      return `${diffDays}d ${diffHrs}h`;
    }

    if (diffHrs > 0) {
      return `${diffHrs}h ${diffMins}m`;
    }

    return `${diffMins}m`;
  };

  const toggleFavorite = async (movieId: string) => {
    if (!activeProfile) {
      return;
    }

    const response = favorites.includes(movieId)
      ? await unfavoriteStorefrontContent(activeProfile.id, movieId)
      : await favoriteStorefrontContent(activeProfile.id, movieId);

    setFavoritesByProfile(response.favorites_by_profile ?? {});
  };

  const isFavorite = (movieId: string) => favorites.includes(movieId);

  return (
    <WalletContext.Provider
      value={{
        balance,
        currency,
        transactions,
        purchases,
        favorites,
        isLoading,
        addFunds,
        purchaseAccess,
        hasAccess,
        getTimeRemaining,
        toggleFavorite,
        isFavorite,
        refreshWallet: loadAccount,
      }}
    >
      {children}
    </WalletContext.Provider>
  );
}

export function useWallet() {
  const context = useContext(WalletContext);
  if (context === undefined) {
    throw new Error("useWallet must be used within a WalletProvider");
  }
  return context;
}
