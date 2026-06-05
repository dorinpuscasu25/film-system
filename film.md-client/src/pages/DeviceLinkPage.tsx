import { useEffect, useMemo, useRef, useState } from "react";
import { useSearchParams } from "react-router-dom";
import { CheckCircle2Icon, TvIcon } from "lucide-react";
import { useAuth } from "../contexts/AuthContext";
import {
  authorizeDevicePairing,
  type RequestErrorWithPayload,
} from "../lib/session";

type Status = "idle" | "submitting" | "success" | "error";

/** Keep only A–Z and 2–9 (the alphabet the TV uses), uppercased. */
function sanitize(raw: string): string {
  return raw
    .toUpperCase()
    .replace(/[^A-Z0-9]/g, "")
    .replace(/[OI01]/g, "")
    .slice(0, 8);
}

/** Render the 8 valid chars as "XXXX-XXXX" for display. */
function formatForDisplay(value: string): string {
  if (value.length <= 4) return value;
  return `${value.slice(0, 4)}-${value.slice(4)}`;
}

export function DeviceLinkPage() {
  const { isAuthenticated, openAuthModal } = useAuth();
  const [searchParams] = useSearchParams();
  const [code, setCode] = useState("");
  const [status, setStatus] = useState<Status>("idle");
  const [message, setMessage] = useState<string | null>(null);
  const inputRef = useRef<HTMLInputElement>(null);

  // Pre-fill from a deep link like /tv?code=KXTP-9F2L (verification_uri_complete).
  useEffect(() => {
    const fromUrl = searchParams.get("code");
    if (fromUrl) {
      setCode(sanitize(fromUrl));
    }
  }, [searchParams]);

  useEffect(() => {
    if (isAuthenticated) {
      inputRef.current?.focus();
    }
  }, [isAuthenticated]);

  const isComplete = code.length === 8;
  const displayValue = useMemo(() => formatForDisplay(code), [code]);

  async function handleSubmit(event: React.FormEvent<HTMLFormElement>) {
    event.preventDefault();

    if (!isAuthenticated) {
      openAuthModal();
      return;
    }

    if (!isComplete) {
      setStatus("error");
      setMessage("Introdu codul complet de 8 caractere afișat pe televizor.");
      return;
    }

    setStatus("submitting");
    setMessage(null);

    try {
      const formatted = `${code.slice(0, 4)}-${code.slice(4)}`;
      const response = await authorizeDevicePairing(formatted);
      setStatus("success");
      setMessage(response.message);
    } catch (error) {
      const err = error as RequestErrorWithPayload;
      setStatus("error");
      setMessage(err.message ?? "Codul nu a putut fi verificat. Încearcă din nou.");
    }
  }

  return (
    <div className="mx-auto flex min-h-[70vh] max-w-xl flex-col items-center justify-center px-5 py-16 text-center">
      <div className="mb-6 flex h-16 w-16 items-center justify-center rounded-2xl bg-surface ring-1 ring-white/10">
        <TvIcon className="h-8 w-8 text-accent" />
      </div>

      <h1 className="text-3xl font-bold text-white md:text-4xl">Conectează televizorul</h1>
      <p className="mt-3 max-w-md text-base leading-7 text-white/65">
        Deschide aplicația FILMOTECA.md pe Android TV și introdu mai jos codul de 8 caractere
        afișat pe ecran.
      </p>

      {status === "success" ? (
        <div className="mt-10 w-full rounded-2xl border border-accentGreen/30 bg-accentGreen/10 p-8">
          <CheckCircle2Icon className="mx-auto mb-4 h-12 w-12 text-accentGreen" />
          <p className="text-lg font-semibold text-white">Televizorul este conectat!</p>
          <p className="mt-2 text-sm leading-6 text-white/70">{message}</p>
        </div>
      ) : (
        <form onSubmit={handleSubmit} className="mt-10 w-full space-y-5">
          <input
            ref={inputRef}
            value={displayValue}
            onChange={(event) => {
              setCode(sanitize(event.target.value));
              if (status === "error") setStatus("idle");
            }}
            inputMode="text"
            autoCapitalize="characters"
            autoComplete="off"
            spellCheck={false}
            placeholder="XXXX-XXXX"
            aria-label="Cod de conectare TV"
            className="h-16 w-full rounded-xl border border-white/15 bg-surface text-center font-mono text-3xl tracking-[0.4em] text-white outline-none transition placeholder:text-white/25 focus:border-accent focus:ring-2 focus:ring-accent/30"
          />

          {message && status === "error" ? (
            <p className="text-sm text-red-300">{message}</p>
          ) : null}

          {!isAuthenticated ? (
            <p className="text-sm text-accentGold">
              Autentifică-te în contul tău pentru a conecta televizorul.
            </p>
          ) : null}

          <button
            type="submit"
            disabled={status === "submitting"}
            className="h-12 w-full rounded-xl bg-accent text-base font-semibold text-white transition hover:bg-accent/90 disabled:cursor-not-allowed disabled:opacity-60"
          >
            {status === "submitting"
              ? "Se conectează…"
              : isAuthenticated
                ? "Conectează televizorul"
                : "Autentifică-te"}
          </button>
        </form>
      )}
    </div>
  );
}
