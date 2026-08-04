import { CheckCircle2Icon, CreditCardIcon, HelpCircleIcon } from "lucide-react";
import { Link } from "react-router-dom";
import { useLanguage } from "../contexts/LanguageContext";

const copy = {
  ro: {
    eyebrow: "Transparență",
    title: "Politica de prețuri FILMOTECA.md",
    intro: "FILMOTECA.md funcționează în regim pay-per-content: plătești doar pentru titlul și tipul de acces pe care le alegi, fără un abonament recurent implicit.",
    points: [
      ["Preț afișat clar", "Prețul final este afișat în MDL înainte de confirmarea plății."],
      ["Durata accesului", "Perioada de vizionare sau accesul permanent sunt indicate pentru fiecare ofertă înainte de cumpărare."],
      ["Fără taxe ascunse", "Orice reducere sau cod promoțional este calculat înainte de confirmarea comenzii."],
      ["Conținut gratuit", "Titlurile marcate ca gratuite pot fi vizionate fără debitarea portofelului."],
    ],
    payments: "Plăți și confirmare",
    paymentsText: "Plata este inițiată numai după confirmarea explicită. După procesarea cu succes, accesul apare în cont. Dacă plata nu este finalizată, accesul nu este activat.",
    help: "Ai o întrebare despre un preț sau o plată?",
    contact: "Contactează suportul",
  },
  en: {
    eyebrow: "Transparency",
    title: "FILMOTECA.md pricing policy",
    intro: "FILMOTECA.md uses a pay-per-content model: you pay only for the title and access option you choose, with no implicit recurring subscription.",
    points: [
      ["Clear final price", "The final price is shown in MDL before payment confirmation."],
      ["Access duration", "The viewing period or permanent access is shown for every offer before purchase."],
      ["No hidden fees", "Discounts and promo codes are calculated before you confirm the order."],
      ["Free content", "Titles marked as free can be watched without charging your wallet."],
    ],
    payments: "Payments and confirmation",
    paymentsText: "Payment starts only after explicit confirmation. Once processed successfully, access appears in your account. If payment is not completed, access is not activated.",
    help: "Questions about a price or payment?",
    contact: "Contact support",
  },
  ru: {
    eyebrow: "Прозрачность",
    title: "Ценовая политика FILMOTECA.md",
    intro: "FILMOTECA.md работает по модели pay-per-content: вы платите только за выбранный фильм и вариант доступа, без скрытой регулярной подписки.",
    points: [
      ["Понятная цена", "Итоговая цена в MDL отображается до подтверждения платежа."],
      ["Срок доступа", "Период просмотра или постоянный доступ указываются для каждого предложения до покупки."],
      ["Без скрытых сборов", "Скидки и промокоды рассчитываются до подтверждения заказа."],
      ["Бесплатный контент", "Фильмы с отметкой «Бесплатно» доступны без списания средств."],
    ],
    payments: "Оплата и подтверждение",
    paymentsText: "Платёж начинается только после явного подтверждения. После успешной обработки доступ появляется в аккаунте. Если платёж не завершён, доступ не активируется.",
    help: "Есть вопрос о цене или платеже?",
    contact: "Связаться с поддержкой",
  },
} as const;

export function PricingPolicyPage() {
  const { currentLanguage } = useLanguage();
  const text = copy[currentLanguage.code];

  return (
    <div className="container mx-auto max-w-5xl px-4 pb-20 pt-28 sm:pt-32 md:px-8">
      <header className="max-w-3xl">
        <p className="text-xs font-semibold uppercase tracking-[0.22em] text-accent">{text.eyebrow}</p>
        <h1 className="mt-3 text-3xl font-bold tracking-tight text-white sm:text-5xl">{text.title}</h1>
        <p className="mt-5 text-base leading-7 text-gray-300 sm:text-lg">{text.intro}</p>
      </header>

      <div className="mt-10 grid gap-4 sm:grid-cols-2">
        {text.points.map(([title, description]) => (
          <article key={title} className="rounded-2xl border border-white/10 bg-surface/80 p-5 sm:p-6">
            <CheckCircle2Icon className="h-5 w-5 text-emerald-300" />
            <h2 className="mt-4 text-lg font-semibold text-white">{title}</h2>
            <p className="mt-2 text-sm leading-6 text-gray-400">{description}</p>
          </article>
        ))}
      </div>

      <section className="mt-5 rounded-2xl border border-white/10 bg-surface/80 p-5 sm:p-7">
        <div className="flex items-start gap-3">
          <CreditCardIcon className="mt-0.5 h-6 w-6 shrink-0 text-accent" />
          <div><h2 className="text-xl font-semibold text-white">{text.payments}</h2><p className="mt-3 max-w-3xl text-sm leading-6 text-gray-300">{text.paymentsText}</p></div>
        </div>
      </section>

      <div className="mt-5 flex flex-col items-start gap-4 rounded-2xl border border-white/10 bg-white/[0.03] p-5 sm:flex-row sm:items-center sm:justify-between sm:p-6">
        <p className="flex items-center gap-2 text-sm font-medium text-gray-300"><HelpCircleIcon className="h-5 w-5 text-gray-500" /> {text.help}</p>
        <Link to="/contacte" className="inline-flex min-h-11 w-full items-center justify-center rounded-lg bg-accent px-4 py-2.5 font-semibold text-white hover:bg-red-700 sm:w-auto">{text.contact}</Link>
      </div>
    </div>
  );
}
