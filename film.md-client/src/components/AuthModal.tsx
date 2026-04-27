import React, { useMemo, useState } from "react";
import { AnimatePresence, motion } from "framer-motion";
import { ArrowLeftIcon, MailIcon, RefreshCcwIcon, XIcon } from "lucide-react";
import { useAuth } from "../contexts/AuthContext";
import { useLanguage } from "../contexts/LanguageContext";

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
  const { t } = useLanguage();
  const [mode, setMode] = useState<"login" | "register">("login");
  const [name, setName] = useState("");
  const [email, setEmail] = useState("");
  const [password, setPassword] = useState("");
  const [code, setCode] = useState("");
  const [isLoading, setIsLoading] = useState(false);
  const [errorMessage, setErrorMessage] = useState<string | null>(null);
  const [infoMessage, setInfoMessage] = useState<string | null>(null);

  const isVerificationStep = pendingRegistration !== null;
  const modalTitle = useMemo(() => {
    if (isVerificationStep) {
      return "Confirm your email";
    }

    return mode === "login" ? "Welcome back" : "Create your account";
  }, [isVerificationStep, mode]);

  if (!isAuthModalOpen) {
    return null;
  }

  const switchMode = (nextMode: "login" | "register") => {
    setMode(nextMode);
    setErrorMessage(null);
    setInfoMessage(null);
    setCode("");

    if (pendingRegistration) {
      clearPendingRegistration();
    }
  };

  const handleSubmit = async (event: React.FormEvent) => {
    event.preventDefault();
    setErrorMessage(null);
    setInfoMessage(null);
    setIsLoading(true);

    try {
      if (isVerificationStep) {
        await verifyRegistration(code);
        return;
      }

      if (mode === "login") {
        await login(email, password);
      } else {
        await register(name, email, password);
        setInfoMessage("We sent a 6-digit confirmation code to your email.");
      }
    } catch (error) {
      const message = error instanceof Error ? error.message : "Authentication failed.";

      if (mode === "login" && message.includes("Confirm your email")) {
        startVerification(email);
        setInfoMessage("Enter the code from your email to finish activating the account.");
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
      setInfoMessage("A fresh confirmation code was sent to your inbox.");
    } catch (error) {
      setErrorMessage(error instanceof Error ? error.message : "We could not resend the confirmation code.");
    } finally {
      setIsLoading(false);
    }
  };

  return (
    <div className="fixed inset-0 z-50 flex items-center justify-center p-4">
      <motion.div
        initial={{ opacity: 0 }}
        animate={{ opacity: 1 }}
        exit={{ opacity: 0 }}
        className="absolute inset-0 bg-black/80 backdrop-blur-sm"
        onClick={closeAuthModal}
      />

      <motion.div
        initial={{ scale: 0.95, opacity: 0, y: 20 }}
        animate={{ scale: 1, opacity: 1, y: 0 }}
        exit={{ scale: 0.95, opacity: 0, y: 20 }}
        className="glass-panel relative z-10 w-full max-w-md rounded-2xl border border-white/10 p-8 shadow-2xl"
      >
        <button
          onClick={closeAuthModal}
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
                  Enter the 6-digit code we sent to complete account activation.
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
              }}
              className="inline-flex items-center gap-2 text-sm text-gray-300 transition-colors hover:text-white"
            >
              <ArrowLeftIcon className="h-4 w-4" />
              Change email
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
                  placeholder="Full Name"
                  className="w-full rounded-lg border border-white/10 bg-surfaceHover px-4 py-3 text-white placeholder-gray-500 transition-colors focus:border-accent focus:outline-none"
                  required={mode === "register"}
                />
              </motion.div>
            )}
          </AnimatePresence>

          {!isVerificationStep && (
            <>
              <input
                type="email"
                value={email}
                onChange={(event) => setEmail(event.target.value)}
                placeholder={t("auth.email")}
                className="w-full rounded-lg border border-white/10 bg-surfaceHover px-4 py-3 text-white placeholder-gray-500 transition-colors focus:border-accent focus:outline-none"
                required
              />

              <input
                type="password"
                value={password}
                onChange={(event) => setPassword(event.target.value)}
                placeholder={t("auth.password")}
                className="w-full rounded-lg border border-white/10 bg-surfaceHover px-4 py-3 text-white placeholder-gray-500 transition-colors focus:border-accent focus:outline-none"
                required
              />
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
              className="w-full rounded-lg border border-white/10 bg-surfaceHover px-4 py-3 text-center text-2xl tracking-[0.5em] text-white placeholder-gray-500 transition-colors focus:border-accent focus:outline-none"
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
              "Confirm Code"
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
              Resend code
            </button>
            {pendingRegistration.expiresAt && (
              <span className="text-xs text-gray-500">
                Expires {new Date(pendingRegistration.expiresAt).toLocaleTimeString()}
              </span>
            )}
          </div>
        )}

        {!isVerificationStep && mode === "login" && (
          <div className="mt-6 text-center">
            <a href="#" className="text-sm text-gray-400 transition-colors hover:text-white">
              Forgot your password?
            </a>
          </div>
        )}
      </motion.div>
    </div>
  );
}
