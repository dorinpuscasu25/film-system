# Audit plăți iOS — In-App Purchase

**Data:** 11 august 2026
**Status:** analiză finalizată, implementare **pusă pe pauză**
**Context:** aplicația iOS (`film.md-ios`, bundle `md.filmoteca.ios`, team `4TR9CSYCJW`) vinde acces la filme prin portofel în MDL. Trebuie stabilit cum se conformează regulilor Apple privind comisionul de 15–30%.

---

## 1. Problema

Platforma vinde filme. Utilizatorul își alimentează portofelul (sold în MDL) și plătește cu el accesul la titluri. Pe web, alimentarea trece prin maib → `pay.filmoteca.md`.

Întrebarea inițială: cum aplicăm markup peste prețul primit din admin ca să acoperim comisionul Apple, și cum comunicăm în app că pe site e mai ieftin, fără ca Apple să respingă aplicația.

Concluzia auditului: **a doua parte nu e posibilă** pe storefront-ul Moldova, iar problema reală e alta și mai gravă decât procentele.

---

## 2. Starea actuală a codului

### Backend (`film.md-admin-api`, Laravel)

- `Wallet` — sold unic în MDL (`Wallet::DEFAULT_CURRENCY = 'MDL'`).
- `WalletService` — separă deja fondurile pe surse în `wallet.meta`:
  `platform_credit_balance` (bonus acordat de platformă) vs `own_credit_balance` (bani reali ai userului),
  cu alocare la debit în `allocateDebitFunding()`. **Fundație bună pentru a adăuga o a treia sursă.**
- `WalletTransaction` — tipuri: `welcome_bonus`, `purchase`, `refund`, `adjustment`, `top_up`.
  Fiecare tranzacție are deja `funding_source` în `meta` → raportarea pe sursă e ușor de extins.
- `Offer` — `price_amount`, `currency`, `offer_type` (`free` / `rental` / `lifetime`), `quality`.
  **Un singur preț, global, fără variație pe platformă.**
- `StorefrontPurchaseService::purchase()` — debitează exact `price_amount` și creează `ContentEntitlement`.
- `ContentEntitlement` are `access_location` (`moldova` / `outside_moldova`) — precedent existent pentru
  diferențiere pe context.
- Top-up web: `PaymentTopUp` + `PayFilmotecaPaymentService` + `StorefrontWalletTopUpController`.

### iOS (`film.md-ios`)

**Problema critică — respingere garantată la prima submisie:**

- `Filmoteca/Views/AccountView.swift:577` — `WalletTopUpSheet` colectează suma și adresa de facturare,
  apelează `POST storefront/wallet/top-ups` și deschide URL-ul PayFilmoteca **într-un `InAppBrowser`**.
  Adică vinde credit digital în aplicație cu procesator de plăți propriu → **încălcare directă a 3.1.1**.
- `Filmoteca/Core/Configuration/AppConfiguration.swift:12` — `walletTopUpURL` către `dashboard?tab=wallet`.
- `Filmoteca/Theme/FilmotecaTheme.swift:12` — `topUpURL` hardcodat către `filmoteca.md/dashboard?tab=wallet`.
  Ambele = CTA extern către altă metodă de plată → respingere pe orice storefront în afara SUA.
- `Filmoteca/Views/ContentDetailView.swift` — la sold insuficient deschide `WalletTopUpSheet`.

**Observație:** `README.md` din `film.md-ios` afirmă „Alimentarea soldului este redirecționată către site;
nu există procesare de plăți în aplicație" — **codul contrazice documentația**.

**Aspect pozitiv verificat:** nu există UI de cupon/voucher în iOS. Bine — un câmp „introdu cod promo"
ar fi fost încălcare separată a 3.1.1 („Apps may not use their own mechanisms to unlock content...
such as license keys").

---

## 3. Regulile Apple (verificate în ghidul curent, august 2026)

### 3.1.1 — In-App Purchase

> „If you want to unlock features or functionality within your app (by way of example: subscriptions,
> **in-game currencies**, game levels, access to premium content...), you must use in-app purchase."

Creditele din portofel = *in-game currency*. **IAP obligatoriu.**

> „Any credits or in-game currencies purchased via in-app purchase **may not expire**."

→ soldul cumpărat prin IAP nu are voie să expire niciodată.

### 3.1.3(b) — Multiplatform Services

> „Apps that operate across multiple platforms may allow users to access content, subscriptions, or
> features they have acquired in your app on other platforms or your web site, **provided those items
> are also available as in-app purchases within the app**."

→ Userul cumpără pe filmoteca.md și vizionează în app: **permis**, cu condiția ca aceleași titluri să fie
și cumpărabile în app. Asta legitimează întregul model de business.

### 3.1.3 — Anti-steering

> „In all other storefronts, **except for the United States storefront**, ... apps and their metadata may
> not include buttons, external links, or other calls to action that direct customers to purchasing
> mechanisms other than in-app purchase."

→ **Nu putem scrie în aplicație „cumpără mai ieftin pe site".** Userii sunt pe storefront-ul Moldova
(sau RO/RU/IT pentru diasporă). Moldova nu e SUA și nu e în lista regiunilor cu
*StoreKit External Purchase Link Entitlement* (aceea acoperă UE + câteva piețe).

> „Developers can send communications **outside of the app** to their user base about purchasing methods
> other than in-app purchase."

→ Email, banner pe site, social media: **permis**. În app: nu.

### 3.1.3(e) — Goods and Services Outside of the App

> „If your app enables people to purchase **physical goods or services that will be consumed outside of
> the app**, you must use purchase methods **other than** in-app purchase."

→ Explică de ce Bolt / Yandex Taxi încasează cu card real: pentru ei IAP e **interzis**.
Linia de demarcație e **unde se consumă**, nu ce metodă preferi.

| Ce vinzi | Unde se consumă | Metodă | Apple ia |
|---|---|---|---|
| Cursă Bolt, mâncare Yandex, bilete, haine | lumea reală | card real, obligatoriu | 0% |
| **Film redat în AVPlayer în app** | **în aplicație** | **IAP, obligatoriu** | **15–30%** |

### Paritate de preț

**Nu există** nicio cerință de paritate de preț în ghid. Putem vinde mai scump în app, legal.

---

## 4. Matematica comisionului

Probabil eligibili pentru **Small Business Program** (sub 1M USD/an) → **15%, nu 30%**.
Necesită aplicare manuală, se aprobă pe an calendaristic. **De verificat înainte de a fixa prețurile.**

Formula corectă: `preț_app = preț_web / (1 − comision)` — **nu** `preț_web × (1 + comision)`.

| Comision | Formulă | Film 149 MDL | Încasezi net |
|---|---|---|---|
| 15% (SBP) | ÷ 0,85 | 175 MDL | 149 MDL ✅ |
| 30% | ÷ 0,70 | 213 MDL | 149 MDL ✅ |
| ❌ greșit: × 1,30 | 149 × 1,30 | 194 MDL | 136 MDL ❌ |

**Constrângere:** storefront-ul Moldova nu facturează în MDL (Apple suportă 44 de valute, MDL nu e printre
ele — foarte probabil USD). **De confirmat în App Store Connect.** Prețurile IAP sunt pe price points fixe,
deci preț arbitrar per film e imposibil.

---

## 5. Decizia luată

### Model ales: pachete de credite ca IAP consumabile, cu markup la intrarea banilor

IAP per film e exclus: mii de titluri, fiecare ar necesita produs separat în ASC cu review propriu,
iar prețurile sunt pe tiers fixe.

```
Web:  plătești 200 MDL   →  primești 200 credite
iOS:  plătești $13,99    →  primești 200 credite      (≈235 MDL brut, încasezi ~200 net)

1 credit = 1 MDL. Filmul costă 149 credite. IDENTIC pe toate platformele.
```

### De ce nu umflăm prețul filmului în app

Riscul nu vine de la Apple (nu există regulă de paritate), ci din portofelul mixt:

```
User alimentează 200 MDL pe web (plătește 200 MDL real)
Deschide iOS, vede filmul la 175 în loc de 149
Plătește 175 din bani pe care Apple nu i-a atins
→ l-am taxat cu comision Apple pe o tranzacție fără Apple
```

Evitarea ar necesita preț calculat per-user în funcție de compoziția soldului. Complexitate inutilă.

### Avantajele modelului ales

1. Catalogul rămâne neatins — adminul introduce un singur preț, fără pricing per platformă.
2. Portofelul rămâne unitar, `WalletService` aproape neschimbat (doar al treilea bucket).
3. Nimeni nu vede „același film, alt preț" — doar creditele costă mai mult acolo (model standard din jocuri).
4. Userul deduce singur că e mai avantajos pe web, **fără să scriem nimic în app**.

### Mesajul „mai ieftin pe site"

Gating la runtime pe `Storefront.current.countryCode` (StoreKit 2):

- `US` → CTA permis explicit (fără entitlement, fără comision, post-injonctiune Epic)
- UE → doar cu *StoreKit External Purchase Link Entitlement* (addendum + fee 5–13%; nu merită acum)
- **restul, inclusiv MD/RO/RU → zero mențiuni**

Educarea userilor moldoveni: email de welcome + site. Perfect legal în afara app-ului.

---

## 6. Cum fac alții (validare de piață)

| Serviciu | IAP? | Comision | Strategie |
|---|---|---|---|
| Netflix, Spotify, Kindle | nu | **0%** | „reader app" — zero cumpărare în app, zero conversie iOS |
| **YouTube Premium** | **da** | **30%** | **preț majorat în app** |
| Disney+, HBO Max | da | 15–30% | IAP standard |

**YouTube Premium** e validarea directă a strategiei noastre:

| | Web | iOS |
|---|---|---|
| Premium | $15,99 | **$20,99** |
| Premium Lite | $8,99 | **$11,99** |

Două lucruri confirmate public de Google:
1. **Apple acceptă prețuri diferite** — la vedere, de ani de zile, pentru sute de milioane de useri.
2. **Nici Google nu are voie să spună de ce** — în aplicația YouTube nu există niciun cuvânt despre
   prețul de pe web. Aceeași regulă anti-steering.

**Calibrare utilă:** Google nu recuperează integral comisionul ($20,99 × 0,70 = $14,69 < $15,99).
A preferat un preț rotund în locul recuperării matematic perfecte ($22,84). Facem la fel — prețuri rotunde,
cu o mică pierdere acceptată.

**Poziția noastră e mai bună decât ambele tabere:** vindem în app (nu pierdem userii noi de pe App Store,
cum pierde Netflix), dar plătim comision **doar pe alimentările făcute în app** — nu pe tot business-ul.
Cine alimentează pe filmoteca.md rămâne la 0%.

---

## 7. Fluxul banilor

### Acum (web, maib)

```
User → pay.filmoteca.md → maib → contul tău    (1–3 zile, ~1–2% comision)
```

### Pe iOS

```
User → Apple → [Apple ține banii 30–45 zile] → contul tău
```

**maib și pay.filmoteca.md nu sunt implicate deloc.** Prin serverele noastre **nu trece niciun ban** —
serverul doar verifică o chitanță semnată criptografic și acordă creditul.

```
1. User apasă „200 credite"
2. iOS → Apple: plata se face, Apple încasează
3. Apple → app: chitanță semnată (JWS)
4. app → backend: „uite chitanța"
5. backend → App Store Server API: „e validă?"
6. Apple: „da, tranzacția X, produsul Y"
7. backend: creditează portofelul cu 200
```

### Diferențe operaționale

| | Web (maib) | iOS (Apple) |
|---|---|---|
| Încasare | 1–3 zile | **30–45 zile** |
| Comision | ~1–2% | 15% sau 30% |
| Valuta | MDL | USD/EUR |
| Merchant of record | noi | **Apple Distribution International (Irlanda)** |
| Control refund | noi | **Apple, unilateral** |
| Document | factura noastră | raport Apple |

**⚠️ Impact pe cash flow:** creditele se acordă instant, banii vin peste ~45 de zile. Filmul e vizionat
imediat, licența către deținătorul de drepturi se acumulează imediat. Necesită capital de lucru.

**Refund-urile nu mai sunt la noi:** userul cere direct la Apple, Apple decide singură și scade din plata
următoare. Aflăm prin webhook `REFUND`, după fapt. Singura pârghie: răspunsul la `CONSUMPTION_REQUEST`
(în 12h) — comunicăm că creditele au fost consumate și filmul vizionat. Reduce semnificativ abuzul.

**TVA:** Apple colectează și virează unde e obligată. Comisionul se calculează pe suma fără TVA în
teritoriile unde Apple remite taxa.

**Contabilitate:** două fluxuri complet separate. `WalletService` are deja `funding_source` în meta —
adăugăm `'apple'` ca a treia valoare și raportarea e gata. **De discutat cu contabilul înainte de lansare:**
încasare de la entitate irlandeză, în valută, cu comision reținut la sursă, are tratament diferit de maib.

**Neatins:** PayFilmoteca și maib rămân exact cum sunt pe web, Android și TV. IAP e obligatoriu **doar**
în aplicația iOS. Un user cu iPhone care alimentează din browser trece tot prin maib.

---

## 8. Prerechizite financiare (blocante)

Ordinea contează — fiecare pas îl deblochează pe următorul.

1. **Cont Organization** (nu Individual), necesită D-U-N-S. — *aparent existent, de confirmat*
2. **App Store Connect → Business** (fost *Agreements, Tax, and Banking*):
   - **a) Paid Applications Agreement**, semnat de **Account Holder** (doar el poate, nu Admin).
     ⚠️ **Blocant absolut** — cât timp nu e „Active", secțiunea In-App Purchases e complet blocată.
   - **b) Cont bancar** pe numele entității juridice.
     ⚠️ **DE VERIFICAT PRIMUL:** *Business → Bank Accounts → Add Bank Account* — apare **Moldova**
     în dropdown-ul de țări? Nu a putut fi confirmat că Apple face payout către bănci din Moldova.
     Băncile MD folosesc IBAN (ajută), dar lista de payout e separată de lista de storefront-uri.
     **Dacă Moldova nu e acolo, planul se schimbă** — cont în altă țară, altă planificare fiscală.
   - **c) Formulare fiscale** — pentru entitate non-US: **W-8BEN-E**.
     ⚠️ Fără el, fiscul american reține 30% **peste** comisionul Apple din veniturile din SUA.
3. **Small Business Program** — aplicare separată la
   `developer.apple.com/app-store/small-business-program`. Intră în vigoare de la începutul lunii
   următoare → **aplică devreme**, nu la lansare.
4. **Chei tehnice** (după ce agreement-ul e Active):
   - **In-App Purchase Key** (.p8) → *Users and Access → Integrations → In-App Purchase* (Key ID + Issuer ID)
   - **Server Notifications V2** → două URL-uri separate: production și sandbox
   - **Apple Root CA G3** pentru verificarea semnăturii JWS

**Timp realist:** agreement + banking + tax = câteva zile (aprobare Apple 24–48h). SBP = până la o lună,
dar nu blochează nimic tehnic (afectează doar procentul, care e config în `PlatformSetting`).

---

## 9. Strategia de testare

**Verificat: IAP în TestFlight NU funcționează fără Paid Applications Agreement activ** (care necesită
și bancă, și formulare fiscale). Fără el, `Product.products(for:)` returnează listă goală. Nu se poate ocoli.

| | Fără setup financiar | Necesită Paid Apps activ |
|---|---|---|
| Tot codul (backend + iOS) | ✅ | |
| Flux complet de cumpărare în simulator | ✅ | |
| **Flux complet pe iPhone real, rulat din Xcode** | ✅ | |
| Validare JWS în backend | ✅ | |
| Testare refund, eșec plată, Ask to Buy | ✅ | |
| TestFlight pentru restul aplicației | ✅ | |
| Cumpărare IAP în TestFlight | | ❌ |
| Sandbox pe device | | ❌ |
| Server Notifications reale | | ❌ |

### Nivelul 1 — StoreKit Configuration File (fără niciun setup)

Fișier `.storekit` în proiect; Xcode simulează complet App Store-ul, **inclusiv pe iPhone fizic** când
rulezi din Xcode. Fluxul e testabil cap-coadă, real. Singura diferență: cine semnează chitanța.
Permite simularea a ceea ce în sandbox se prinde greu: refund, întrerupere de rețea la mijlocul
tranzacției, control parental (Ask to Buy).

Backend-ul validează cu certificatul de test exportat din Xcode (*Editor → Save Public Certificate*),
acceptat **doar** sub flag de development.

### Nivelul 2 — Sandbox

Necesită produsele create în ASC. *Users and Access → Sandbox → Testers*.
Pe device: *Settings → Developer → Sandbox Apple Account*.
Flux 100% real, JWS semnat de Apple, validat pe `api.storekit-sandbox.itunes.apple.com`.

Backend-ul citește câmpul `environment` din tranzacția decodată și alege endpoint-ul automat.
**Nu hardcoda production.**

### Nivelul 3 — TestFlight

Folosește tot Sandbox pentru plăți. QA final. Build-urile TestFlight ignoră `.storekit`.
Fără produse în ASC, ecranul de pachete apare gol → **de tratat elegant în cod**
(mesaj „momentan indisponibil", nu ecran gol sau crash).

### ⚠️ Capcană de securitate

Dacă backend-ul de producție acceptă tranzacții semnate în **sandbox**, oricine poate genera credite
gratuite la nesfârșit (conturile sandbox cumpără infinit, fără bani). Regulă obligatorie în `AppleIapService`:

```
environment == "Production"   → creditează normal
environment == "Sandbox"      → creditează DOAR dacă user.is_test_account
environment == "LocalTesting" → doar dacă APP_ENV != production
```

---

## 10. Plan de implementare

### Backend (`film.md-admin-api`)

1. Migrare `apple_iap_transactions`: `transaction_id` **unic** (idempotență), `original_transaction_id`,
   `product_id`, `user_id`, `wallet_id`, `credits_amount`, `price`, `currency`, `environment`, `status`,
   `raw_payload`, `processed_at`.
2. `PlatformSetting` cheia `iap_credit_packs`: `[{product_id, credits_mdl, sort_order}]`,
   editabil din admin (pattern: `RegistrationCreditService`).
3. `AppleIapService` — validare JWS cu Apple Root CA, App Store Server API, **idempotent pe `transaction_id`**,
   gating pe `environment` (vezi §9).
4. `POST /api/v1/storefront/wallet/apple-iap/redeem` — primește `signedTransaction`, validează,
   creditează cu `funding_source: 'apple'`.
5. `POST /api/v1/webhooks/apple/notifications` — Server Notifications V2:
   `REFUND` → clawback, `CONSUMPTION_REQUEST` → răspuns în 12h, `REVOKE`.
6. `WalletService` — al treilea bucket `apple_credit_balance`; ordinea de consum la debit **de decis**
   (propunere: `platform` → `apple` → `own`).
7. `WalletTransaction` — tip nou `clawback`.
8. `StorefrontWalletTopUpController` — respinge cererile venite de la clientul iOS (defense in depth).

### iOS (`film.md-ios`)

1. Capability In-App Purchase; produse consumabile în ASC.
2. `StoreKitService` (StoreKit 2): `Product.products(for:)`, `purchase()`, listener pe `Transaction.updates`.
   **`finish()` doar după confirmarea backend-ului.**
3. La pornire: procesează `Transaction.unfinished` (user a plătit, app-ul a crăpat).
4. `WalletTopUpSheet` → `CreditPackSheet`.
5. **Șterge codul care pică la review:** `AppConfiguration.walletTopUpURL`, `FilmotecaTheme.topUpURL`,
   formularul PayFilmoteca + `InAppBrowser` din `AccountView.swift`.
6. `ContentDetailView` — „sold insuficient" deschide pachetele IAP.
7. Gating pe `Storefront.current.countryCode` pentru mesajul US.
8. Actualizează `README.md` (contrazice codul actual).

### Admin (`film.md-admin`)

1. Ecran de configurare pachete IAP.
2. Raport venituri `apple` vs `web` (`funding_source` există deja în meta).

### Note pentru App Review

Cont demo + explicație: creditele se vând exclusiv prin IAP; accesul la conținut cumpărat pe web e conform
3.1.3(b), fiindcă toate titlurile sunt cumpărabile și în app.

---

## 11. Riscuri și întrebări deschise

| # | Item | Status |
|---|---|---|
| 1 | **Apple face payout către bănci din Moldova?** | ⚠️ **NEVERIFICAT — potențial blocant** |
| 2 | Storefront-ul Moldova facturează în USD sau altă valută? | ⚠️ de confirmat în ASC |
| 3 | Eligibilitate Small Business Program (15% vs 30%) | ⚠️ de verificat — schimbă toată grila |
| 4 | Ordinea de consum a bucket-urilor la debit | de decis |
| 5 | Grila finală de pachete | de generat din distribuția reală a prețurilor din `offers` |
| 6 | Tratamentul contabil al încasărilor de la Apple Irlanda | de discutat cu contabilul |
| 7 | Sold rezidual — creditele **nu pot expira niciodată** | de dimensionat pachetele aproape de prețurile reale |
| 8 | Refund abuse | mitigat prin `CONSUMPTION_REQUEST` |
| 9 | Cash flow — decalaj de ~45 de zile | necesită capital de lucru |

---

## 12. Următorii pași când reluăm

**Partea utilizatorului (în paralel, nu blochează codul):**
1. Verifică dropdown-ul de țări la bank account — **Moldova apare?**
2. Semnează Paid Applications Agreement (Account Holder)
3. Completează W-8BEN-E
4. Aplică la Small Business Program

**Partea de implementare (poate începe imediat, Nivelul 1):**
Backend + iOS conform §10, testat cu `.storekit` local. Când agreement-ul devine Active, se creează
produsele în ASC cu aceleași ID-uri și se trece pe sandbox **fără schimbări de cod**.

---

## Surse

- [App Review Guidelines — Apple Developer](https://developer.apple.com/app-store/review/guidelines/)
  (3.1.1, 3.1.1(a), 3.1.3, 3.1.3(a), 3.1.3(b), 3.1.3(e))
- [Apple updates App Store Guidelines to allow links to external payments — 9to5Mac](https://9to5mac.com/2025/05/01/apple-app-store-guidelines-external-links/)
- [com.apple.developer.storekit.external-purchase-link — Apple Developer Documentation](https://developer.apple.com/documentation/bundleresources/entitlements/com.apple.developer.storekit.external-purchase-link)
- [Why YouTube Premium Costs More Through Apple's App Store — SlashGear](https://www.slashgear.com/2206600/why-youtube-premium-costs-more-through-apple-app-store-tax/)
- [Are you a YouTube Premium user? — TechRadar](https://www.techradar.com/computing/websites-apps/are-you-a-youtube-premium-user-you-could-be-paying-more-than-you-should-be-if-youve-subscribed-through-the-apple-app-store)
- [Create a Sandbox Apple Account — App Store Connect Help](https://developer.apple.com/help/app-store-connect/test-in-app-purchases/create-a-sandbox-apple-account/)
