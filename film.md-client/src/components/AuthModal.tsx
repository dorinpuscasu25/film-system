import React, { useMemo, useState } from "react";
import { AnimatePresence, motion } from "framer-motion";
import { ArrowLeftIcon, EyeIcon, EyeOffIcon, MailIcon, RefreshCcwIcon, XIcon } from "lucide-react";
import { useAuth } from "../contexts/AuthContext";
import { useLanguage } from "../contexts/LanguageContext";
import { correctPopularEmailAddress } from "../lib/emailAddress";

export function AuthModal() {
  const {
    isAuthModalOpen,
    pendingRegistration,
    closeAuthModal,
    login,
    register,
    verifyRegistration,
    resendRegistration,
    startVerification,
    clearPendingRegistration,
  } = useAuth();
  const { t, currentLanguage } = useLanguage();
  const [mode, setMode] = useState<"login" | "register">("login");
  const [name, setName] = useState("");
  const [email, setEmail] = useState("");
  const [password, setPassword] = useState("");
  const [showPassword, setShowPassword] = useState(false);
  const [code, setCode] = useState("");
  const [isLoading, setIsLoading] = useState(false);
  const [errorMessage, setErrorMessage] = useState<string | null>(null);
  const [infoMessage, setInfoMessage] = useState<string | null>(null);
  const [correctedEmail, setCorrectedEmail] = useState<string | null>(null);

  const isVerificationStep = pendingRegistration !== null;
  const modalTitle = useMemo(() => {
    if (isVerificationStep) {
      return t("auth.confirm_email");
    }

    return mode === "login" ? t("auth.welcome_back") : t("auth.create_account");
  }, [isVerificationStep, mode, t]);

  if (!isAuthModalOpen) {
    return null;
  }

  const switchMode = (nextMode: "login" | "register") => {
    setMode(nextMode);
    setErrorMessage(null);
    setInfoMessage(null);
    setCorrectedEmail(null);
    setCode("");
    setShowPassword(false);

    if (pendingRegistration) {
      clearPendingRegistration();
    }
  };

  const handleSubmit = async (event: React.FormEvent) => {
    event.preventDefault();
    setErrorMessage(null);
    setInfoMessage(null);
    setIsLoading(true);
    const correction = isVerificationStep ? null : correctPopularEmailAddress(email);
    const submittedEmail = correction?.email ?? email;

    if (correction) {
      setEmail(submittedEmail);
      setCorrectedEmail(correction.changed ? submittedEmail : null);
    }

    try {
      if (isVerificationStep) {
        await verifyRegistration(code);
        return;
      }

      if (mode === "login") {
        await login(submittedEmail, password);
      } else {
        await register(name, submittedEmail, password, currentLanguage.code);
        setInfoMessage(t("auth.code_sent"));
      }
    } catch (error) {
      const message = error instanceof Error ? error.message : t("auth.failed");

      if (mode === "login" && message.includes("Confirm your email")) {
        startVerification(submittedEmail);
        setInfoMessage(t("auth.enter_code"));
      } else {
        setErrorMessage(message);
      }
    } finally {
      setIsLoading(false);
    }
  };

  const handleResend = async () => {
    setErrorMessage(null);
    setInfoMessage(null);
    setIsLoading(true);

    try {
      await resendRegistration();
      setInfoMessage(t("auth.code_resent"));
    } catch (error) {
      setErrorMessage(error instanceof Error ? error.message : t("auth.resend_failed"));
    } finally {
      setIsLoading(false);
    }
  };

  return (
    <div className="fixed inset-0 z-50 flex items-center justify-center p-3 sm:p-4">
      <motion.div
        initial={{ opacity: 0 }}
        animate={{ opacity: 1 }}
        exit={{ opacity: 0 }}
        className="absolute inset-0 bg-black/80 backdrop-blur-sm"
      />

      <motion.div
        initial={{ scale: 0.95, opacity: 0, y: 20 }}
        animate={{ scale: 1, opacity: 1, y: 0 }}
        exit={{ scale: 0.95, opacity: 0, y: 20 }}
        className="glass-panel relative z-10 max-h-[calc(100dvh-1.5rem)] w-full max-w-md overflow-y-auto rounded-2xl border border-white/10 p-5 shadow-2xl sm:max-h-[calc(100dvh-2rem)] sm:p-8"
      >
        <button
          type="button"
          onClick={closeAuthModal}
          aria-label={t("common.close")}
          className="absolute right-4 top-4 text-gray-400 transition-colors hover:text-white"
        >
          <XIcon className="h-6 w-6" />
        </button>

        <div className="mb-8 text-center">
          <h2 className="mb-2 text-3xl font-bold tracking-tighter text-white">
            filmoteca<span className="text-accent">.</span>md
          </h2>
          <p className="text-sm text-gray-400">{modalTitle}</p>
        </div>

        {!isVerificationStep && (
          <div className="mb-8 flex space-x-6 border-b border-white/10">
            <button
              onClick={() => switchMode("login")}
              className={`relative pb-4 text-lg font-medium ${mode === "login" ? "text-white" : "text-gray-400"}`}
            >
              {t("auth.login")}
              {mode === "login" && <motion.div layoutId="authTab" className="absolute bottom-0 left-0 right-0 h-0.5 bg-accent" />}
            </button>
            <button
              onClick={() => switchMode("register")}
              className={`relative pb-4 text-lg font-medium ${mode === "register" ? "text-white" : "text-gray-400"}`}
            >
              {t("auth.register")}
              {mode === "register" && (
                <motion.div layoutId="authTab" className="absolute bottom-0 left-0 right-0 h-0.5 bg-accent" />
              )}
            </button>
          </div>
        )}

        {isVerificationStep && (
          <div className="mb-6 rounded-xl border border-white/10 bg-surfaceHover/60 p-4">
            <div className="mb-3 flex items-center gap-3">
              <div className="rounded-full border border-white/10 bg-white/5 p-2">
                <MailIcon className="h-5 w-5 text-white" />
              </div>
              <div>
                <p className="text-sm font-medium text-white">{pendingRegistration.email}</p>
                <p className="text-xs text-gray-400">
                  {t("auth.enter_code")}
                </p>
              </div>
            </div>
            <button
              type="button"
              onClick={() => {
                clearPendingRegistration();
                setCode("");
                setErrorMessage(null);
                setInfoMessage(null);
                setCorrectedEmail(null);
              }}
              className="inline-flex items-center gap-2 text-sm text-gray-300 transition-colors hover:text-white"
            >
              <ArrowLeftIcon className="h-4 w-4" />
              {t("auth.change_email")}
            </button>
          </div>
        )}

        <form onSubmit={handleSubmit} className="space-y-5">
          <AnimatePresence mode="wait">
            {!isVerificationStep && mode === "register" && (
              <motion.div
                initial={{ opacity: 0, height: 0 }}
                animate={{ opacity: 1, height: "auto" }}
                exit={{ opacity: 0, height: 0 }}
              >
                <input
                  type="text"
                  value={name}
                  onChange={(event) => setName(event.target.value)}
                  placeholder={t("auth.full_name")}
                  className="w-full rounded-lg border border-white/10 bg-surfaceHover px-4 py-3 text-white placeholder-gray-500 transition-colors focus:border-accent focus:outline-none"
                  required={mode === "register"}
                />
              </motion.div>
            )}
          </AnimatePresence>

          {!isVerificationStep && (
            <>
              <div>
                <input
                  type="email"
                  value={email}
                  onChange={(event) => {
                    setEmail(event.target.value);
                    setCorrectedEmail(null);
                  }}
                  placeholder={t("auth.email")}
                  autoCapitalize="none"
                  autoCorrect="off"
                  spellCheck={false}
                  className="w-full rounded-lg border border-white/10 bg-surfaceHover px-4 py-3 text-white placeholder-gray-500 transition-colors focus:border-accent focus:outline-none"
                  required
                />
                {correctedEmail ? (
                  <p className="mt-2 break-words text-xs leading-5 text-amber-200" aria-live="polite">
                    {t("auth.email_corrected", { email: correctedEmail })}
                  </p>
                ) : null}
              </div>

              <div className="relative">
                <input
                  type={showPassword ? "text" : "password"}
                  value={password}
                  onChange={(event) => setPassword(event.target.value)}
                  placeholder={t("auth.password")}
                  className="w-full rounded-lg border border-white/10 bg-surfaceHover px-4 py-3 pr-12 text-white placeholder-gray-500 transition-colors focus:border-accent focus:outline-none"
                  required
                />
                <button
                  type="button"
                  onClick={() => setShowPassword((current) => !current)}
                  aria-label={showPassword ? t("auth.hide_password") : t("auth.show_password")}
                  title={showPassword ? t("auth.hide_password") : t("auth.show_password")}
                  className="absolute inset-y-0 right-0 flex w-12 items-center justify-center text-gray-400 transition hover:text-white"
                >
                  {showPassword ? <EyeOffIcon className="h-5 w-5" /> : <EyeIcon className="h-5 w-5" />}
                </button>
              </div>
            </>
          )}

          {isVerificationStep && (
            <input
              type="text"
              value={code}
              onChange={(event) => setCode(event.target.value.replace(/\D/g, "").slice(0, 6))}
              placeholder="123456"
              inputMode="numeric"
              autoComplete="one-time-code"
              className="w-full whitespace-nowrap rounded-lg border border-white/10 bg-surfaceHover px-3 py-3 text-center text-2xl tabular-nums tracking-[0.2em] text-white placeholder-gray-500 transition-colors focus:border-accent focus:outline-none sm:px-4 sm:tracking-[0.35em]"
              required
            />
          )}

          {infoMessage && (
            <div className="rounded-lg border border-emerald-500/30 bg-emerald-500/10 px-4 py-3 text-sm text-emerald-200">
              {infoMessage}
            </div>
          )}

          {errorMessage && (
            <div className="rounded-lg border border-red-500/30 bg-red-500/10 px-4 py-3 text-sm text-red-200">
              {errorMessage}
            </div>
          )}

          <button
            type="submit"
            disabled={isLoading}
            className="flex w-full items-center justify-center rounded-lg bg-accent py-3 font-bold text-white transition-colors hover:bg-red-700"
          >
            {isLoading ? (
              <div className="h-6 w-6 animate-spin rounded-full border-2 border-white border-t-transparent" />
            ) : isVerificationStep ? (
              t("auth.confirm_code")
            ) : mode === "login" ? (
              t("auth.login")
            ) : (
              t("auth.register")
            )}
          </button>
        </form>

        {isVerificationStep && (
          <div className="mt-6 flex items-center justify-between gap-3">
            <button
              type="button"
              onClick={() => void handleResend()}
              className="inline-flex items-center gap-2 text-sm text-gray-300 transition-colors hover:text-white"
            >
              <RefreshCcwIcon className="h-4 w-4" />
              {t("auth.resend_code")}
            </button>
            {pendingRegistration.expiresAt && (
              <span className="text-xs text-gray-500">
                {t("auth.expires_at", {
                  time: new Date(pendingRegistration.expiresAt).toLocaleTimeString(currentLanguage.code, {
                    hour: "2-digit",
                    minute: "2-digit",
                  }),
                })}
              </span>
            )}
          </div>
        )}

        {!isVerificationStep && mode === "login" && (
          <div className="mt-6 text-center">
            <button type="button" className="text-sm text-gray-400 transition-colors hover:text-white">
              {t("auth.forgot_password")}
            </button>
          </div>
        )}
      </motion.div>
    </div>
  );
}
