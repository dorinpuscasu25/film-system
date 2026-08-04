import { useEffect, useState } from "react";
import { Building2Icon, Clock3Icon, ExternalLinkIcon, MailIcon, PhoneIcon, ShieldCheckIcon } from "lucide-react";
import { useLanguage } from "../contexts/LanguageContext";
import { fetchPublicPlatformSettings, type PublicPlatformSettingsPayload } from "../lib/session";

const copy = {
  ro: {
    eyebrow: "Suport și informații",
    title: "Contacte",
    intro: "Ai o întrebare despre cont, accesul la un film sau o plată? Scrie-ne și îți răspundem cât mai curând.",
    company: "Datele operatorului",
    address: "Adresă",
    support: "Suport utilizatori",
    emailHelp: "Pentru conturi, plăți, acces și probleme tehnice.",
    schedule: "Program",
    unavailable: "Datele de contact vor fi publicate în curând.",
    consumerTitle: "Protecția consumatorilor",
    consumerText: "Pentru consiliere privind drepturile consumatorilor poți contacta Inspectoratul de Stat pentru Supravegherea Produselor Nealimentare și Protecția Consumatorilor (ISSPNPC).",
    greenLine: "Linia Verde",
    fixedOnly: "apel gratuit din rețeaua fixă",
    website: "Site oficial consumator.gov.md",
  },
  en: {
    eyebrow: "Support and information",
    title: "Contact",
    intro: "Questions about your account, access to a film, or a payment? Email us and we will reply as soon as possible.",
    company: "Operator details",
    address: "Address",
    support: "User support",
    emailHelp: "For accounts, payments, access, and technical issues.",
    schedule: "Working hours",
    unavailable: "Contact details will be published soon.",
    consumerTitle: "Consumer protection",
    consumerText: "For guidance about consumer rights, contact Moldova's State Inspectorate for Non-Food Product Supervision and Consumer Protection (ISSPNPC).",
    greenLine: "Green Line",
    fixedOnly: "free from landlines",
    website: "Official consumator.gov.md website",
  },
  ru: {
    eyebrow: "Поддержка и информация",
    title: "Контакты",
    intro: "Есть вопрос об аккаунте, доступе к фильму или оплате? Напишите нам, и мы ответим как можно скорее.",
    company: "Данные оператора",
    address: "Адрес",
    support: "Поддержка пользователей",
    emailHelp: "По вопросам аккаунта, оплаты, доступа и технических проблем.",
    schedule: "График работы",
    unavailable: "Контактные данные будут опубликованы в ближайшее время.",
    consumerTitle: "Защита прав потребителей",
    consumerText: "За консультацией о правах потребителей можно обратиться в Государственную инспекцию по надзору за непродовольственными товарами и защите прав потребителей (ISSPNPC).",
    greenLine: "Зелёная линия",
    fixedOnly: "бесплатно со стационарных телефонов",
    website: "Официальный сайт consumator.gov.md",
  },
} as const;

type ContactSettings = NonNullable<PublicPlatformSettingsPayload["contact"]>;

export function ContactPage() {
  const { currentLanguage } = useLanguage();
  const text = copy[currentLanguage.code];
  const [contact, setContact] = useState<ContactSettings | null>(null);
  const [isLoading, setIsLoading] = useState(true);

  useEffect(() => {
    let active = true;
    setIsLoading(true);

    void fetchPublicPlatformSettings(currentLanguage.code)
      .then((settings) => {
        if (active) setContact(settings.contact ?? null);
      })
      .catch(() => {
        if (active) setContact(null);
      })
      .finally(() => {
        if (active) setIsLoading(false);
      });

    return () => {
      active = false;
    };
  }, [currentLanguage.code]);

  const hasOperator = Boolean(contact?.operator_name || contact?.address);
  const hasSupport = Boolean(contact?.email || contact?.phone || contact?.working_hours);

  return (
    <div className="container mx-auto max-w-5xl px-4 pb-20 pt-28 sm:pt-32 md:px-8">
      <header className="max-w-3xl">
        <p className="text-xs font-semibold uppercase tracking-[0.22em] text-accent">{text.eyebrow}</p>
        <h1 className="mt-3 text-4xl font-bold tracking-tight text-white sm:text-5xl">{text.title}</h1>
        <p className="mt-5 whitespace-pre-line text-base leading-7 text-gray-300 sm:text-lg">
          {contact?.description || text.intro}
        </p>
      </header>

      {isLoading ? (
        <div className="mt-10 grid gap-5 lg:grid-cols-2" aria-label="Loading contact details">
          <div className="h-52 animate-pulse rounded-2xl border border-white/10 bg-surface/80" />
          <div className="h-52 animate-pulse rounded-2xl border border-white/10 bg-surface/80" />
        </div>
      ) : hasOperator || hasSupport ? (
        <div className="mt-10 grid gap-5 lg:grid-cols-2">
          {hasOperator ? (
            <section className="rounded-2xl border border-white/10 bg-surface/80 p-5 sm:p-7">
              <div className="flex items-center gap-3">
                <span className="rounded-xl bg-white/5 p-2.5 text-accent"><Building2Icon className="h-5 w-5" /></span>
                <h2 className="text-xl font-semibold text-white">{text.company}</h2>
              </div>
              <dl className="mt-6 space-y-4 text-sm">
                {contact?.operator_name ? (
                  <div><dt className="text-gray-500">Operator</dt><dd className="mt-1 break-words font-medium text-white">{contact.operator_name}</dd></div>
                ) : null}
                {contact?.address ? (
                  <div><dt className="text-gray-500">{text.address}</dt><dd className="mt-1 whitespace-pre-line break-words text-white">{contact.address}</dd></div>
                ) : null}
              </dl>
            </section>
          ) : null}

          {hasSupport ? (
            <section className="rounded-2xl border border-white/10 bg-surface/80 p-5 sm:p-7">
              <div className="flex items-center gap-3">
                <span className="rounded-xl bg-white/5 p-2.5 text-accent"><MailIcon className="h-5 w-5" /></span>
                <h2 className="text-xl font-semibold text-white">{text.support}</h2>
              </div>
              <p className="mt-5 text-sm leading-6 text-gray-400">{text.emailHelp}</p>
              {contact?.email ? (
                <a href={`mailto:${contact.email}`} className="mt-4 inline-flex min-h-11 max-w-full items-center rounded-lg bg-accent px-4 py-2.5 font-semibold text-white transition hover:bg-red-700">
                  <span className="break-all">{contact.email}</span>
                </a>
              ) : null}
              {contact?.phone ? (
                <a href={`tel:${contact.phone.replace(/\s/g, "")}`} className="mt-3 flex min-h-11 items-center gap-2 break-words text-sm text-gray-300 hover:text-white">
                  <PhoneIcon className="h-4 w-4 shrink-0" /> {contact.phone}
                </a>
              ) : null}
              {contact?.working_hours ? (
                <p className="mt-3 flex items-start gap-2 whitespace-pre-line text-sm leading-6 text-gray-300">
                  <Clock3Icon className="mt-1 h-4 w-4 shrink-0" /> <span><span className="font-medium text-white">{text.schedule}:</span> {contact.working_hours}</span>
                </p>
              ) : null}
            </section>
          ) : null}
        </div>
      ) : (
        <div className="mt-10 rounded-2xl border border-white/10 bg-surface/80 p-6 text-sm text-gray-300 sm:p-7">
          {text.unavailable}
        </div>
      )}

      <section className="mt-5 rounded-2xl border border-emerald-400/20 bg-emerald-400/[0.06] p-5 sm:p-7">
        <div className="flex items-start gap-3">
          <ShieldCheckIcon className="mt-0.5 h-6 w-6 shrink-0 text-emerald-300" />
          <div className="min-w-0">
            <h2 className="text-xl font-semibold text-white">{text.consumerTitle}</h2>
            <p className="mt-3 max-w-3xl text-sm leading-6 text-gray-300">{text.consumerText}</p>
            <div className="mt-5 flex flex-col gap-3 sm:flex-row sm:flex-wrap sm:items-center sm:gap-5">
              <a href="tel:080028028" className="inline-flex min-h-11 items-center gap-2 font-semibold text-white hover:text-emerald-200">
                <PhoneIcon className="h-4 w-4" /> {text.greenLine}: 0800 28028
              </a>
              <span className="text-xs text-gray-500">({text.fixedOnly})</span>
              <a href="https://consumator.gov.md" target="_blank" rel="noreferrer" className="inline-flex min-h-11 items-center gap-2 text-sm font-semibold text-emerald-200 hover:text-white">
                {text.website} <ExternalLinkIcon className="h-4 w-4" />
              </a>
            </div>
          </div>
        </div>
      </section>
    </div>
  );
}
