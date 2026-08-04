import { AlertTriangleIcon, CheckCircle2Icon } from "lucide-react";
import { Link, useSearchParams } from "react-router-dom";
import { useAuth } from "../contexts/AuthContext";
import { useLanguage } from "../contexts/LanguageContext";

const copy = {
  ro: {
    successTitle: "Contul a fost confirmat",
    successText: "Adresa ta de email este confirmată. Acum te poți autentifica în FILMOTECA.md.",
    invalidTitle: "Linkul nu mai este valabil",
    invalidText: "Linkul este expirat, incorect sau a fost deja folosit. Poți folosi codul din email ori poți solicita un cod nou.",
    login: "Autentifică-te",
    home: "Înapoi la pagina principală",
  },
  en: {
    successTitle: "Your account is confirmed",
    successText: "Your email address is confirmed. You can now sign in to FILMOTECA.md.",
    invalidTitle: "This link is no longer valid",
    invalidText: "The link is expired, incorrect, or has already been used. Use the code from your email or request a new one.",
    login: "Sign in",
    home: "Back to the home page",
  },
  ru: {
    successTitle: "Аккаунт подтверждён",
    successText: "Ваш email подтверждён. Теперь вы можете войти в FILMOTECA.md.",
    invalidTitle: "Ссылка больше не действует",
    invalidText: "Ссылка истекла, неверна или уже использована. Введите код из письма или запросите новый.",
    login: "Войти",
    home: "На главную",
  },
} as const;

export function RegistrationConfirmationPage() {
  const [params] = useSearchParams();
  const isSuccess = params.get("status") === "success";
  const { openAuthModal } = useAuth();
  const { currentLanguage } = useLanguage();
  const text = copy[currentLanguage.code];

  return (
    <div className="container mx-auto flex min-h-[70vh] max-w-3xl items-center px-4 pb-20 pt-28 md:px-8">
      <section className="w-full rounded-2xl border border-white/10 bg-surface/90 p-6 text-center shadow-2xl sm:p-10">
        {isSuccess ? <CheckCircle2Icon className="mx-auto h-14 w-14 text-emerald-300" /> : <AlertTriangleIcon className="mx-auto h-14 w-14 text-amber-300" />}
        <h1 className="mt-5 text-3xl font-bold text-white sm:text-4xl">{isSuccess ? text.successTitle : text.invalidTitle}</h1>
        <p className="mx-auto mt-4 max-w-xl text-sm leading-6 text-gray-300 sm:text-base">{isSuccess ? text.successText : text.invalidText}</p>
        <div className="mt-7 flex flex-col justify-center gap-3 sm:flex-row">
          <button type="button" onClick={openAuthModal} className="inline-flex min-h-11 items-center justify-center rounded-lg bg-accent px-5 py-2.5 font-semibold text-white hover:bg-red-700">{text.login}</button>
          <Link to="/" className="inline-flex min-h-11 items-center justify-center rounded-lg border border-white/10 px-5 py-2.5 font-semibold text-gray-200 hover:bg-white/5">{text.home}</Link>
        </div>
      </section>
    </div>
  );
}
