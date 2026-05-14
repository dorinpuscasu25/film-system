import React, { useEffect, useMemo, useState } from "react";
import { motion } from "framer-motion";
import { FilmIcon, HeartIcon, HistoryIcon, PlayIcon, WalletIcon } from "lucide-react";
import { useLocation, useNavigate } from "react-router-dom";
import { useAuth } from "../contexts/AuthContext";
import { useWallet } from "../contexts/WalletContext";
import { useLanguage } from "../contexts/LanguageContext";
import { Tabs } from "../components/Tabs";
import { WalletModal } from "../components/WalletModal";
import { getFullCatalog } from "../lib/storefront";
import { fetchContinueWatching } from "../lib/session";
import { Movie } from "../types";

const DASHBOARD_TABS = ["myfilms", "favorites", "wallet", "settings"];

export function UserDashboardPage() {
  const {
    user,
    activeProfile,
    updateAccount,
    changePassword,
  } = useAuth();
  const { balance, currency, transactions, purchases, favorites, refreshWallet } = useWallet();
  const { currentLanguage, setLanguage, t } = useLanguage();
  const [activeTab, setActiveTab] = useState("myfilms");
  const [isWalletModalOpen, setIsWalletModalOpen] = useState(false);
  const [catalog, setCatalog] = useState<Movie[]>([]);
  const [continueWatching, setContinueWatching] = useState<Array<{
    contentSlug: string;
    title: string;
    posterUrl: string;
    progressPercent: number;
  }>>([]);
  const [accountName, setAccountName] = useState("");
  const [accountLocale, setAccountLocale] = useState<"en" | "ro" | "ru">("en");
  const [currentPassword, setCurrentPassword] = useState("");
  const [newPassword, setNewPassword] = useState("");
  const [confirmPassword, setConfirmPassword] = useState("");
  const [accountMessage, setAccountMessage] = useState<string | null>(null);
  const [passwordMessage, setPasswordMessage] = useState<string | null>(null);
  const [accountError, setAccountError] = useState<string | null>(null);
  const [passwordError, setPasswordError] = useState<string | null>(null);
  const [isSavingAccount, setIsSavingAccount] = useState(false);
  const [isSavingPassword, setIsSavingPassword] = useState(false);
  const navigate = useNavigate();
  const location = useLocation();

  useEffect(() => {
    let active = true;

    async function loadCatalog() {
      try {
        const items = await getFullCatalog(currentLanguage.code);
        if (active) {
          setCatalog(items);
        }
      } catch {
        if (active) {
          setCatalog([]);
        }
      }
    }

    void loadCatalog();

    return () => {
      active = false;
    };
  }, [currentLanguage.code]);

  useEffect(() => {
    if (!user) {
      setContinueWatching([]);
      return;
    }

    let active = true;

    async function loadContinueWatching() {
      try {
        const response = await fetchContinueWatching(currentLanguage.code);
        if (!active) {
          return;
        }

        setContinueWatching(
          (response.items ?? []).map((item) => ({
            contentSlug: item.content_slug,
            title: item.title ?? item.content_slug,
            posterUrl: item.poster_url ?? "",
            progressPercent: Number(item.progress_percent ?? 0),
          })),
        );
      } catch {
        if (active) {
          setContinueWatching([]);
        }
      }
    }

    void loadContinueWatching();

    return () => {
      active = false;
    };
  }, [currentLanguage.code, user]);

  useEffect(() => {
    const tab = new URLSearchParams(location.search).get("tab");

    if (tab && DASHBOARD_TABS.includes(tab)) {
      setActiveTab(tab);
    }
  }, [location.search]);

  useEffect(() => {
    if (!user) {
      return;
    }

    setAccountName(user.name);
    setAccountLocale((user.preferredLocale as "en" | "ro" | "ru" | undefined) ?? currentLanguage.code);
  }, [currentLanguage.code, user]);

  const hasPendingWalletTopUp = useMemo(
    () => transactions.some((transaction) => ["pending", "redirect_created", "processing"].includes(transaction.status ?? "")),
    [transactions],
  );

  useEffect(() => {
    if (activeTab !== "wallet" || !hasPendingWalletTopUp) {
      return;
    }

    const interval = window.setInterval(() => {
      void refreshWallet();
    }, 5000);

    return () => window.clearInterval(interval);
  }, [activeTab, hasPendingWalletTopUp, refreshWallet]);

  const tabs = [
    { id: "myfilms", label: t("dashboard.my_films") },
    { id: "favorites", label: t("dashboard.favorites") },
    { id: "wallet", label: t("dashboard.wallet_billing") },
    { id: "settings", label: t("dashboard.settings") },
  ];

  const purchasedMovies = useMemo(
    () => purchases
      .map((purchase) => ({
        purchase,
        movie: catalog.find((item) => item.id === purchase.movieId),
      }))
      .filter((item): item is { purchase: typeof purchases[number]; movie: Movie } => item.movie !== undefined),
    [catalog, purchases],
  );

  const favoriteMovies = useMemo(
    () => favorites
      .map((identifier) => catalog.find((item) => item.id === identifier))
      .filter((item): item is Movie => item !== undefined),
    [catalog, favorites],
  );

  const handleAccountSave = async (event: React.FormEvent) => {
    event.preventDefault();
    if (!user) {
      return;
    }

    setAccountError(null);
    setAccountMessage(null);
    setIsSavingAccount(true);

    try {
      await updateAccount({
        name: accountName.trim(),
        email: user.email,
        preferredLocale: accountLocale,
      });
      setLanguage(accountLocale);
      setAccountMessage(t("dashboard.account_updated"));
    } catch (error) {
      setAccountError(error instanceof Error ? error.message : t("dashboard.account_update_failed"));
    } finally {
      setIsSavingAccount(false);
    }
  };

  const handlePasswordSave = async (event: React.FormEvent) => {
    event.preventDefault();
    setPasswordError(null);
    setPasswordMessage(null);

    if (newPassword !== confirmPassword) {
      setPasswordError(t("dashboard.password_mismatch"));
      return;
    }

    setIsSavingPassword(true);

    try {
      await changePassword(currentPassword, newPassword);
      setCurrentPassword("");
      setNewPassword("");
      setConfirmPassword("");
      setPasswordMessage(t("dashboard.password_updated"));
    } catch (error) {
      setPasswordError(error instanceof Error ? error.message : t("dashboard.password_update_failed"));
    } finally {
      setIsSavingPassword(false);
    }
  };

  const transactionStatusLabel = (status?: string) => {
    switch (status) {
      case "pending":
      case "redirect_created":
      case "processing":
        return t("dashboard.processing");
      case "failed":
        return t("dashboard.failed");
      case "canceled":
        return t("dashboard.canceled");
      case "refunded":
        return t("dashboard.refunded");
      default:
        return null;
    }
  };

  const transactionStatusClass = (status?: string) => {
    switch (status) {
      case "pending":
      case "redirect_created":
      case "processing":
        return "border-amber-400/30 bg-amber-400/10 text-amber-200";
      case "failed":
      case "canceled":
        return "border-red-400/30 bg-red-400/10 text-red-200";
      case "refunded":
        return "border-sky-400/30 bg-sky-400/10 text-sky-200";
      default:
        return "border-emerald-400/30 bg-emerald-400/10 text-emerald-200";
    }
  };

  if (!user || !activeProfile) {
    return null;
  }

  return (
    <div className="min-h-screen bg-background pb-20 pt-24">
      <div className="container mx-auto max-w-6xl px-4 md:px-8">
        <div className="mb-12 flex items-center space-x-6 rounded-2xl border-l-4 border-l-accent bg-white/5 p-6">
          <div className={`flex h-20 w-20 items-center justify-center rounded-full bg-gradient-to-br ${activeProfile.color} text-3xl font-bold text-white`}>
            {activeProfile.avatarUrl}
          </div>
          <div>
            <h1 className="mb-1 text-3xl font-bold text-white">{activeProfile.name}</h1>
            <p className="text-gray-400">{user.email}</p>
            <div className="mt-2 flex flex-wrap items-center gap-2">
              {activeProfile.isKids && (
                <span className="rounded-full border border-emerald-500/30 bg-emerald-500/15 px-3 py-1 text-xs font-semibold uppercase tracking-[0.2em] text-emerald-200">
                  {t("dashboard.kids_profile")}
                </span>
              )}
              <span className="rounded-full border border-white/10 bg-white/5 px-3 py-1 text-xs text-gray-300">
                {t(user.profiles.length === 1 ? "dashboard.profiles_count_one" : "dashboard.profiles_count", { count: user.profiles.length })}
              </span>
            </div>
          </div>
        </div>

        <Tabs
          tabs={tabs}
          activeTab={activeTab}
          onTabChange={(tab) => {
            setActiveTab(tab);
            navigate(`/dashboard?tab=${tab}`, { replace: true });
          }}
        />

        <div className="mt-8">
          {activeTab === "myfilms" && (
            <motion.div initial={{ opacity: 0 }} animate={{ opacity: 1 }}>
              {continueWatching.length > 0 ? (
                <div className="mb-8 rounded-2xl border border-white/10 bg-white/5 p-5">
                  <div className="mb-4 flex items-center gap-3">
                    <HistoryIcon className="h-5 w-5 text-accent" />
                    <div>
                      <h2 className="text-xl font-bold text-white">{t("movie.continue_watching")}</h2>
                      <p className="text-sm text-gray-400">{t("dashboard.saved_progress")}</p>
                    </div>
                  </div>
                  <div className="grid gap-4 md:grid-cols-2 xl:grid-cols-3">
                    {continueWatching.map((item) => (
                      <button
                        key={item.contentSlug}
                        onClick={() => navigate(`/watch/${item.contentSlug}`)}
                        className="flex items-center gap-4 rounded-xl border border-white/10 bg-background/50 p-4 text-left transition hover:border-white/20 hover:bg-background/70"
                      >
                        <div className="h-20 w-14 shrink-0 overflow-hidden rounded-lg bg-surface">
                          {item.posterUrl ? (
                            <img src={item.posterUrl} alt={item.title} className="h-full w-full object-cover" />
                          ) : null}
                        </div>
                        <div className="min-w-0 flex-1">
                          <p className="line-clamp-2 font-semibold text-white">{item.title}</p>
                          <div className="mt-3 h-1.5 overflow-hidden rounded-full bg-white/10">
                            <div
                              className="h-full rounded-full bg-accent"
                              style={{ width: `${Math.min(100, Math.max(0, item.progressPercent))}%` }}
                            />
                          </div>
                        </div>
                      </button>
                    ))}
                  </div>
                </div>
              ) : null}

              {purchasedMovies.length > 0 ? (
                <div className="grid grid-cols-2 gap-6 md:grid-cols-3 lg:grid-cols-4">
                  {purchasedMovies.map(({ purchase, movie }) => {
                    const isActive = !purchase.expiresAt || new Date() < new Date(purchase.expiresAt);

                    return (
                      <div key={purchase.id} className="group relative overflow-hidden rounded-xl bg-surface">
                        <div className="relative aspect-[2/3]">
                          <img src={movie.posterUrl} alt={movie.title} className="h-full w-full object-cover" />
                          <div className="absolute inset-0 bg-gradient-to-t from-black via-black/40 to-transparent opacity-80" />
                          <div className="absolute right-2 top-2">
                            <span className={`rounded px-2 py-1 text-xs font-bold ${isActive ? "bg-accentGreen text-background" : "border border-white/10 bg-surfaceHover text-gray-400"}`}>
                              {isActive ? t("dashboard.active") : t("dashboard.expired")}
                            </span>
                          </div>
                          <div className="absolute inset-0 flex flex-col justify-end p-4">
                            <h3 className="mb-1 font-bold text-white">{movie.title}</h3>
                            <p className="mb-4 text-xs text-gray-300">
                              {purchase.expiresAt
                                ? t("dashboard.expires", { date: new Date(purchase.expiresAt).toLocaleDateString() })
                                : t("dashboard.lifetime_access")}
                            </p>
                            <button
                              onClick={() => navigate(isActive ? `/watch/${movie.id}` : `/movie/${movie.id}`)}
                              className={`flex w-full items-center justify-center space-x-2 rounded py-2 text-sm font-bold transition-colors ${
                                isActive ? "bg-accent text-white hover:bg-red-700" : "bg-white/20 text-white backdrop-blur-sm hover:bg-white/30"
                              }`}
                            >
                              {isActive && <PlayIcon className="h-4 w-4 fill-current" />}
                              <span>{isActive ? t("common.watch_now") : t("dashboard.buy_again")}</span>
                            </button>
                          </div>
                        </div>
                      </div>
                    );
                  })}
                </div>
              ) : (
                <div className="rounded-2xl border border-white/10 bg-white/5 py-20 text-center">
                  <FilmIcon className="mx-auto mb-4 h-16 w-16 text-gray-600" />
                  <h2 className="mb-2 text-2xl font-bold text-white">{t("dashboard.no_films")}</h2>
                  <p className="mb-6 text-gray-400">{t("dashboard.no_films_hint")}</p>
                  <button
                    onClick={() => navigate("/search")}
                    className="rounded-lg bg-accent px-6 py-2 font-medium text-white transition-colors hover:bg-red-700"
                  >
                    {t("dashboard.browse_catalog")}
                  </button>
                </div>
              )}
            </motion.div>
          )}

          {activeTab === "favorites" && (
            <motion.div initial={{ opacity: 0 }} animate={{ opacity: 1 }}>
              {favoriteMovies.length > 0 ? (
                <div className="grid grid-cols-2 gap-6 md:grid-cols-3 lg:grid-cols-4">
                  {favoriteMovies.map((movie) => (
                    <div
                      key={movie.id}
                      className="group relative cursor-pointer overflow-hidden rounded-xl bg-surface"
                      onClick={() => navigate(`/movie/${movie.id}`)}
                    >
                      <div className="relative aspect-[2/3]">
                        <img src={movie.posterUrl} alt={movie.title} className="h-full w-full object-cover" />
                        <div className="absolute inset-0 bg-gradient-to-t from-black via-black/40 to-transparent opacity-80" />
                        <div className="absolute inset-0 flex flex-col justify-end p-4">
                          <h3 className="mb-1 font-bold text-white">{movie.title}</h3>
                          <div className="flex items-center space-x-2 text-sm text-gray-300">
                            <span className="text-accentGold">★ {movie.rating}</span>
                            <span>{movie.year}</span>
                          </div>
                        </div>
                      </div>
                    </div>
                  ))}
                </div>
              ) : (
                <div className="rounded-2xl border border-white/10 bg-white/5 py-20 text-center">
                  <HeartIcon className="mx-auto mb-4 h-16 w-16 text-gray-600" />
                  <h2 className="mb-2 text-2xl font-bold text-white">{t("dashboard.no_favorites")}</h2>
                  <p className="mb-6 text-gray-400">{t("dashboard.no_favorites_hint")}</p>
                  <button
                    onClick={() => navigate("/search")}
                    className="rounded-lg bg-accent px-6 py-2 font-medium text-white transition-colors hover:bg-red-700"
                  >
                    {t("dashboard.browse_catalog")}
                  </button>
                </div>
              )}
            </motion.div>
          )}

          {activeTab === "wallet" && (
            <motion.div initial={{ opacity: 0 }} animate={{ opacity: 1 }} className="grid gap-8 md:grid-cols-3">
              <div className="md:col-span-1">
                <div className="rounded-2xl border border-white/10 bg-white/5 p-6 text-center">
                  <WalletIcon className="mx-auto mb-4 h-12 w-12 text-accentGreen" />
                  <p className="mb-2 text-gray-400">{t("wallet.current_balance")}</p>
                  <h2 className="mb-6 text-4xl font-bold text-white">
                    {currency} {balance.toFixed(2)}
                  </h2>
                  <button
                    onClick={() => setIsWalletModalOpen(true)}
                    className="w-full rounded-xl bg-accentGreen py-3 font-bold text-background transition-colors hover:bg-green-600"
                  >
                    {t("dashboard.wallet_details")}
                  </button>
                </div>
              </div>

              <div className="md:col-span-2">
                <h3 className="mb-4 flex items-center gap-3 text-xl font-bold text-white">
                  <HistoryIcon className="h-5 w-5" />
                  {t("dashboard.transaction_history")}
                </h3>
                <div className="overflow-hidden rounded-2xl border border-white/10 bg-white/5">
                  {transactions.length > 0 ? (
                    <div className="divide-y divide-white/10">
                      {transactions.map((transaction) => (
                        <div key={transaction.id} className="flex items-center justify-between p-4 transition-colors hover:bg-white/5">
                          <div>
                            <div className="flex flex-wrap items-center gap-2">
                              <p className="font-medium text-white">
                                {transaction.description || (
                                  transaction.type === "welcome_bonus"
                                    ? t("dashboard.welcome_credit")
                                    : transaction.type === "topup" || transaction.type === "top_up"
                                      ? t("dashboard.wallet_topup")
                                      : t("dashboard.purchase")
                                )}
                              </p>
                              {transactionStatusLabel(transaction.status) ? (
                                <span className={`rounded-full border px-2 py-0.5 text-xs font-semibold ${transactionStatusClass(transaction.status)}`}>
                                  {transactionStatusLabel(transaction.status)}
                                </span>
                              ) : null}
                            </div>
                            <p className="text-sm text-gray-400">{new Date(transaction.date).toLocaleString()}</p>
                          </div>
                          <span className={`font-bold ${
                            ["pending", "redirect_created", "processing"].includes(transaction.status ?? "")
                              ? "text-amber-200"
                              : ["failed", "canceled", "refunded"].includes(transaction.status ?? "")
                                ? "text-gray-500"
                                : transaction.amount > 0
                                  ? "text-accentGreen"
                                  : "text-white"
                          }`}>
                            {transaction.amount > 0 && !transaction.status ? "+" : ""}
                            {currency} {transaction.amount.toFixed(2)}
                          </span>
                        </div>
                      ))}
                    </div>
                  ) : (
                    <div className="p-8 text-center text-gray-400">{t("dashboard.no_transactions")}</div>
                  )}
                </div>
              </div>
            </motion.div>
          )}

          {activeTab === "settings" && (
            <motion.div initial={{ opacity: 0 }} animate={{ opacity: 1 }} className="space-y-8">
              <form onSubmit={handleAccountSave} className="space-y-6 rounded-2xl border border-white/10 bg-white/5 p-8">
                <h3 className="border-b border-white/10 pb-4 text-xl font-bold text-white">{t("dashboard.account_details")}</h3>

                <div className="grid gap-6 md:grid-cols-2">
                  <div>
                    <label className="mb-2 block text-sm font-medium text-gray-400">{t("dashboard.account_name")}</label>
                    <input
                      type="text"
                      value={accountName}
                      onChange={(event) => setAccountName(event.target.value)}
                      className="w-full rounded-lg border border-white/10 bg-surface px-4 py-3 text-white outline-none focus:border-accent"
                      required
                    />
                  </div>
                  <div>
                    <label className="mb-2 block text-sm font-medium text-gray-400">{t("dashboard.account_email")}</label>
                    <input
                      type="email"
                      value={user.email}
                      disabled
                      className="w-full cursor-not-allowed rounded-lg border border-white/5 bg-surface/50 px-4 py-3 text-gray-500"
                    />
                  </div>
                </div>

                <div className="max-w-xs">
                  <label className="mb-2 block text-sm font-medium text-gray-400">{t("dashboard.preferred_language")}</label>
                  <select
                    value={accountLocale}
                    onChange={(event) => setAccountLocale(event.target.value as "en" | "ro" | "ru")}
                    className="w-full rounded-lg border border-white/10 bg-surface px-4 py-3 text-white outline-none focus:border-accent"
                  >
                    <option value="en">English</option>
                    <option value="ro">Română</option>
                    <option value="ru">Русский</option>
                  </select>
                </div>

                {accountMessage && (
                  <div className="rounded-lg border border-emerald-500/30 bg-emerald-500/10 px-4 py-3 text-sm text-emerald-200">
                    {accountMessage}
                  </div>
                )}
                {accountError && (
                  <div className="rounded-lg border border-red-500/30 bg-red-500/10 px-4 py-3 text-sm text-red-200">
                    {accountError}
                  </div>
                )}

                <button
                  type="submit"
                  disabled={isSavingAccount}
                  className="rounded-lg bg-white px-6 py-2 font-bold text-background transition-colors hover:bg-gray-200"
                >
                  {isSavingAccount ? t("dashboard.saving") : t("dashboard.save_account")}
                </button>
              </form>

              <form onSubmit={handlePasswordSave} className="space-y-6 rounded-2xl border border-white/10 bg-white/5 p-8">
                <h3 className="border-b border-white/10 pb-4 text-xl font-bold text-white">{t("dashboard.change_password")}</h3>

                <div className="grid max-w-xl gap-4">
                  <div>
                    <label className="mb-2 block text-sm font-medium text-gray-400">{t("dashboard.current_password")}</label>
                    <input
                      type="password"
                      value={currentPassword}
                      onChange={(event) => setCurrentPassword(event.target.value)}
                      className="w-full rounded-lg border border-white/10 bg-surface px-4 py-3 text-white outline-none focus:border-accent"
                      required
                    />
                  </div>
                  <div>
                    <label className="mb-2 block text-sm font-medium text-gray-400">{t("dashboard.new_password")}</label>
                    <input
                      type="password"
                      value={newPassword}
                      onChange={(event) => setNewPassword(event.target.value)}
                      className="w-full rounded-lg border border-white/10 bg-surface px-4 py-3 text-white outline-none focus:border-accent"
                      required
                    />
                  </div>
                  <div>
                    <label className="mb-2 block text-sm font-medium text-gray-400">{t("dashboard.confirm_new_password")}</label>
                    <input
                      type="password"
                      value={confirmPassword}
                      onChange={(event) => setConfirmPassword(event.target.value)}
                      className="w-full rounded-lg border border-white/10 bg-surface px-4 py-3 text-white outline-none focus:border-accent"
                      required
                    />
                  </div>
                </div>

                {passwordMessage && (
                  <div className="rounded-lg border border-emerald-500/30 bg-emerald-500/10 px-4 py-3 text-sm text-emerald-200">
                    {passwordMessage}
                  </div>
                )}
                {passwordError && (
                  <div className="rounded-lg border border-red-500/30 bg-red-500/10 px-4 py-3 text-sm text-red-200">
                    {passwordError}
                  </div>
                )}

                <button
                  type="submit"
                  disabled={isSavingPassword}
                  className="rounded-lg border border-white/20 bg-surfaceHover px-6 py-2 font-bold text-white transition-colors hover:bg-white/10"
                >
                  {isSavingPassword ? t("dashboard.updating") : t("dashboard.update_password")}
                </button>
              </form>

              <div className="grid gap-8 md:grid-cols-2">
                <div className="space-y-4 rounded-2xl border border-white/10 bg-white/5 p-8">
                  <h3 className="border-b border-white/10 pb-4 text-xl font-bold text-white">{t("dashboard.profiles")}</h3>
                  <p className="text-sm text-gray-400">
                    {t(user.profiles.length === 1 ? "dashboard.profiles_hint_one" : "dashboard.profiles_hint", { count: user.profiles.length })}
                  </p>
                  <div className="space-y-3">
                    {user.profiles.map((profile) => (
                      <div key={profile.id} className="flex items-center justify-between rounded-xl border border-white/10 bg-black/20 px-4 py-3">
                        <div className="flex items-center gap-3">
                          <div className={`flex h-10 w-10 items-center justify-center rounded-full bg-gradient-to-br ${profile.color} text-sm font-bold text-white`}>
                            {profile.avatarUrl}
                          </div>
                          <div>
                            <p className="font-medium text-white">{profile.name}</p>
                            <p className="text-xs text-gray-400">
                              {profile.isKids ? t("profiles.kids") : t("dashboard.standard")} {profile.isDefault ? `• ${t("dashboard.default")}` : ""}
                            </p>
                          </div>
                        </div>
                      </div>
                    ))}
                  </div>
                  <button
                    onClick={() => navigate("/profiles")}
                    className="rounded-lg bg-accent px-6 py-2 font-medium text-white transition-colors hover:bg-red-700"
                  >
                    {t("profiles.manage")}
                  </button>
                </div>

                <div className="space-y-4 rounded-2xl border border-white/10 bg-white/5 p-8">
                  <h3 className="border-b border-white/10 pb-4 text-xl font-bold text-white">{t("dashboard.billing_library")}</h3>
                  <div className="rounded-xl border border-white/10 bg-black/20 p-4">
                    <p className="text-sm text-gray-400">{t("wallet.balance")}</p>
                    <p className="mt-1 text-2xl font-bold text-white">
                      {currency} {balance.toFixed(2)}
                    </p>
                  </div>
                  <div className="rounded-xl border border-white/10 bg-black/20 p-4">
                    <p className="text-sm text-gray-400">{t("dashboard.owned_titles")}</p>
                    <p className="mt-1 text-2xl font-bold text-white">{purchases.length}</p>
                  </div>
                  <div className="rounded-xl border border-white/10 bg-black/20 p-4">
                    <p className="text-sm text-gray-400">{t("dashboard.favorites_active")}</p>
                    <p className="mt-1 text-2xl font-bold text-white">{favorites.length}</p>
                  </div>
                </div>
              </div>
            </motion.div>
          )}
        </div>
      </div>

      <WalletModal isOpen={isWalletModalOpen} onClose={() => setIsWalletModalOpen(false)} />
    </div>
  );
}
