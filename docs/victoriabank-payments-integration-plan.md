# Integrare directă Victoriabank — analiză și plan

_Data: 18.11.2026. Surse: „VB-CyberSource-Ghid-Integrare.html” v1.2 și „VictoriaBank-Business-IPS-Integration-API” v2.1.3 (SharePoint Victoriabank)._

## 1. Situația actuală

Plata pe web (suplinirea portofelului) trece azi prin intermediarul `pay.filmoteca.md`:

| Componentă | Fișier | Ce face |
|---|---|---|
| Serviciu plăți | `film.md-admin-api/app/Services/PayFilmotecaPaymentService.php` (1 823 linii) | `POST /payment-request` → primește `payment_url` → redirecționează clientul; polling detalii; refund |
| Callback | `app/Http/Controllers/Api/PayFilmotecaCallbackController.php`, ruta `payments/pay-filmoteca/callback` | primește notificarea de la intermediar |
| Polling | `routes/console.php` → `payments:poll-topups` (în fiecare minut) | finalizează top-up-urile rămase în `processing` |
| API client | `StorefrontWalletTopUpController` (`wallet/top-ups`, `latest`, `{topUp}`) | creare / stare top-up |
| Admin | `Admin/PaymentTopUpController` (`payments/top-ups`, `refunds`) | listă + rambursări |
| Web | `film.md-client/src/components/WalletModal.tsx` (`window.location.href = payment_url`), `pages/PaymentStatusPage.tsx` | redirect + pagina de revenire |
| iOS | Apple IAP (`AppleIapService`) | **nu e afectat** — conținutul digital pe iOS rămâne prin IAP |
| TV Android | `LibraryScreen.kt` — arată QR către pagina web de portofel | indirect afectat (depinde de web) |

Ce se păstrează: tabelul `payment_top_ups`, `payment_refunds`, `WalletService::credit`, ledger-ul (`WalletFundingLedger`, `AccountingLedgerService`), logica idempotentă din `creditWallet()` (lock + `credited_at`). Ce se înlocuiește: tot ce vorbește cu `pay.filmoteca.md` (cererea, redirectul, parsarea răspunsului, polling-ul, refund-ul).

## 2. Ce oferă banca: două canale

### 2.1 CyberSource — carduri Visa/Mastercard (+ Apple Pay / Google Pay)

- Cazul nostru este **Scenariul A — plată directă**: suplinirea portofelului e plată unică, nu salvăm cardul. Asta înseamnă **fără Account Verification 0,00, fără tokenuri TMS, fără MIT** (R2/R3/R5/R6/R7 nu se aplică). Partea grea a ghidului (§4, §10, §13) nu ne privește.
- Integrare recomandată de bancă: **Unified Checkout (UC)** cu `autoProcessing: true` + `completeMandate` (R12). CyberSource face singur 3-D Secure + autorizarea și întoarce un JWT semnat.
- **Formularul trebuie să fie în pagina noastră, fără redirect și fără pop-up** (R13) — UC în mod embedded/sidebar. Deci fluxul „redirect către pagina de plată” dispare.
- Autentificare REST: **JWT v2 + Message-Level Encryption** prin SDK-ul oficial PHP (`cybersource/rest-client-php`, cere `ext-gmp`). HTTP Signature este interzis (R8).
- Banca oferă un **SDK Victoriabank pentru PHP** (în `VB-CyberSource-Comerciant.zip`) care construiește cererile canonice, verifică regulile R1–R13 și are `createUcDirectSession`, `confirmUcResult`, `voidPayment`, `refund`, `searchByReference`, `decryptWebhook`. Fluxul complet de referință există doar în Node (`exemple/node/src/server.js`); în PHP îl construim noi pe SDK.
- Medii: TEST `https://apitest.cybersource.com`, PROD `https://api.cybersource.com`.
- **Certificare obligatorie** înainte de producție (simulator VB + scenarii în CyberSource TEST + pachet de dovezi).

### 2.2 MIA — plăți instant BNM prin cod QR

- API REST simplu: token OAuth (password grant, ~1 h), `POST /api/v1/qr` (QR dinamic `DYNM`, sumă `Fixed`, `pmtContext: "e"`, TTL), webhook `POST /api/signals` (JWT RS256 semnat cu `VBCA.crt`), status `GET /api/v1/qr-extensions/{uuid}/status`, refund total/parțial, reconciliere.
- Clientul scanează QR-ul din aplicația oricărei bănci (desktop) sau deschide deeplink-ul `qrAsText` (mobil). Banii intră direct pe IBAN-ul nostru.
- Mediu test: `https://test-ipspj.victoriabank.md` + simulator de plată (`test-ipspj-demopay…`) — se poate testa tot fluxul fără aplicație bancară.
- Fără certificare grea ca la CyberSource; nu există 3DS.
- **Ideal pentru TV**: televizorul poate afișa direct QR-ul MIA de plată, nu doar un link către web.

## 3. Ce trebuie de la bancă / decizii

| # | Ce | De la cine |
|---|---|---|
| 1 | Contract (MSign) → Organization ID, MID `4980000498xxxxx`, link activare Business Center TEST (**expiră în 24 h**) | Victoriabank |
| 2 | Chei REST (shared secret) + cheie MLE (.p12 + parolă + Key ID) generate în Business Center TEST | noi, în Business Center |
| 3 | Confirmare `completeMandate.type`: **CAPTURE** (vânzare, creditare imediată a portofelului — propunerea noastră) vs AUTH + captură | Victoriabank |
| 4 | Apple Pay / Google Pay activate pe MID? (Apple Pay cere certificat pe domeniul exact) | Victoriabank |
| 5 | Politica ECI „încercat” (06 Visa / 01 MC) — acceptăm sau anulăm? (provizoriu în §9.3) | Victoriabank |
| 6 | MIA: username/parolă API, IBAN de încasare, URL producție, înregistrarea URL-ului de callback (calea `/api/signals` e fixă?) | Victoriabank |
| 7 | Webhook CyberSource `uc.orders.transactionresults` creat în Business Center | noi |
| 8 | `targetOrigins` = domeniile exacte care încarcă formularul (ex. `https://filmoteca.md`, `https://www.filmoteca.md`) | noi |

## 4. Arhitectura propusă (backend Laravel)

### 4.1 Refactor comun (înainte de ambii provideri)

1. Migrare `payment_top_ups`: coloane `provider` (`pay_filmoteca` | `cybersource` | `mia`), `payment_method` (`card` | `mia`), `provider_reference` (unic — ex. `TOPUP-000123`, apoi `-A2`, `-RF1`, `-VD1`, conform R10), `provider_transaction_id`, `qr_header_uuid`, `qr_extension_uuid`, `qr_expires_at`, `last_checked_at`.
2. Interfață `TopUpPaymentProvider`: `initiate()`, `confirm()/refresh()`, `refund()`, `reconcilePending()`.
3. Extragere `creditWallet()` + `refundedAmount/refundableAmount/ownCreditBalance` din `PayFilmotecaPaymentService` într-un `TopUpSettlementService` independent de provider (metadata `provider` din coloană, nu text fix).
4. `StorefrontWalletTopUpController::store` primește `method: card|mia` și alege providerul. Răspunsul devine diferit pe metodă (sesiune UC vs. QR), nu mai e `payment_url`.
5. `payments:poll-topups` → apelează `reconcilePending()` pentru fiecare provider activ.
6. `PayFilmotecaPaymentService` rămâne doar pentru top-up-urile istorice (refund/afișare), apoi se șterge.

### 4.2 CyberSource (card)

```
Web  ── POST /storefront/wallet/top-ups {method:card, amount} ──► API
API  ── POST /uc/v1/sessions (P40: PANENTRY[,APPLEPAY,GOOGLEPAY], CAPTURE, 3DS, ref TOPUP-123) ──► CyberSource
API  ◄── capture context JWT; validăm semnătura; extragem clientLibrary + integrity
Web  ◄── { top_up uuid, session_jwt, client_library, client_library_integrity }
Web  ── VAS.UnifiedCheckout(jwt) → createCheckout({autoProcessing:true}) → mount(inline) → resultJwt
Web  ── POST /storefront/wallet/top-ups/{uuid}/card-result {result_jwt} ──► API
API  ── verificare RS256 (kid → /flex/v2/public-keys/{kid}), apoi GET /tss/v2/transactions/{id}
        potrivire ref + sumă + MDL + dovadă 3DS (ECI 05/02 + CAVV/AAV)
        OK → creditWallet (o singură dată per transaction id)
        altfel → void/refund + top-up failed
CyberSource ── webhook uc.orders.transactionresults (MLE) ──► POST /api/payments/cybersource/webhook
        verificare semnătură → decriptare → confirmare prin API → același confirm()
Cron  ── top-up-uri „necunoscute” > 60 s → searchByReference (P24) → finalizare; alertă > 10 min
Admin ── refund: POST /pts/v2/payments/{id}/refunds; void înainte de decontare: /voids
```

Reguli de respectat în cod: browserul nu decide niciodată starea (R9); PAN/CVV nu ating serverul (R1 — UC le ține în iframe); timeout HTTP ≥ 60–65 s; la timeout/5xx/`DUPLICATE_REQUEST` **nu** retrimitem, reconciliem după referință (R10); constrângere unică pe `provider_reference` + tip operațiune; nicio cheie în cod sau în loguri (R11); sesiune nouă de plată interzisă pentru un top-up deja plătit.

Config nou (`config/services.php` → `cybersource`): `run_environment`, `merchant_id`, `key_id`, `shared_secret`, `mle_key_path`, `mle_key_password`, `mle_key_id`, `target_origins[]`, `webhook_secret`.

Infrastructură: `ext-gmp` pe server, CSP cu `apitest/api.cybersource.com`, `flex.cybersource.com`, `centinelapi(stag).cardinalcommerce.com`; pentru dezvoltare locală un tunel HTTPS pus în `targetOrigins`.

### 4.3 MIA (QR)

```
Web/TV ── POST /storefront/wallet/top-ups {method:mia, amount} ──► API
API    ── token (cache ~55 min, refresh_token) → POST /api/v1/qr
          {header:{qrType:DYNM, amountType:Fixed, pmtContext:e},
           extension:{iban, amount:{sum:"100.00",currency:MDL}, dba:"Filmoteca", remittanceInfo4Payer:"Suplinire cont", creditorRef:"TOPUP000123", ttl:{length:15,units:mm}}}
API    ◄── qrHeaderUUID, qrExtensionUUID, qrAsImage, qrAsText → salvăm UUID-urile
Web/TV ◄── QR (desktop/TV) sau deeplink qrAsText (mobil); client face polling pe GET top-ups/{uuid}
Bancă  ── POST /api/signals  (body = JWT RS256) ──► API
          verificare cu VBCA.crt (2 certificate configurate pentru rotație; actualul expiră 03.07.2027)
          potrivire după qrExtensionUUID + sumă → salvăm payment.reference → creditWallet → 204
Cron   ── fără semnal: GET /api/v1/qr-extensions/{uuid}/status până la Paid/Expired/Cancelled/Inactive (obligatoriu — banca nu retrimite semnale)
Admin  ── refund total: DELETE /api/v1/transaction/{segment[3]}; parțial: …/partial-refund {amount}
          confirmare prin reconciliere (rând QRREFUND)
```

Atenție la refund MIA: **o singură rambursare per tranzacție**. După o rambursare parțială restul nu mai poate fi returnat prin API (404). Admin-ul trebuie să afișeze clar acest lucru înainte de confirmare. Pentru refund se folosește segmentul 4 din `payment.reference`, **nu** `miaId`.

Config nou (`services.mia`): `base_url`, `username`, `password`, `iban`, `dba`, `qr_ttl_minutes`, `signal_certificates[]`.

## 5. Frontend

- **Web (`film.md-client`)**: `WalletModal` primește alegerea metodei (Card / MIA QR). Card → încarcă scriptul `clientLibrary` cu `integrity` și montează UC inline în modal (fără redirect); trimite `resultJwt` la API; afișează rezultatul din răspunsul serverului. MIA → afișează QR + buton „Deschide aplicația băncii” pe mobil + polling stare + countdown TTL. `PaymentStatusPage` rămâne doar pentru reveniri (ex. 3DS în aplicația bancară).
- **TV (`film.md-tv`, `film.md-tv-web`)**: opțional, suplinire directă prin QR MIA pe ecran.
- **Admin (`film.md-admin`)**: coloana provider/metodă în lista de top-up-uri; refund cu reguli diferite pe provider (MIA: o singură dată).
- **iOS**: fără schimbări (Apple IAP).

## 6. Testare și certificare CyberSource (scenariul A)

Scenarii aplicabile din §16.4: **T-UC-03** plată card, **T-UC-04** plată + void, **T-14/T-15** Apple Pay / Google Pay (dacă sunt activate), **T-04** 3DS eșuat, **T-05** 3DS ocolit → anulare, **T-09** void, **T-10** refund total/parțial, **T-16** timeout → reconciliere fără dublă debitare, **T-17** webhook deduplicat + confirmat prin API, **T-18/T-19** formular și challenge 3DS în pagina noastră.

Pachetul de dovezi: fișa simulatorului VB cu verdict **PREGĂTIT** (Node 22), Request ID-uri, `clientReferenceInformation.code`, request/response JSON mascate, starea finală din sistemul nostru. Testele se fac cu SDK-ul oficial + MLE (aceeași configurație ca producția).

După certificare: chei noi de producție, webhook nou, `targetOrigins` de producție, primele tranzacții mici cu carduri proprii verificate împreună cu banca. Pe site trebuie publicate termenii comerciali (date firmă, prețuri, politica de rambursare, confidențialitate).

## 7. Etape și estimare

| Etapă | Conținut | Estimare (dev) |
|---|---|---|
| 0 | Refactor provider + migrare + `TopUpSettlementService` + teste | 1–2 zile |
| 1 | **MIA** backend (token, QR, semnale, polling, refund, reconciliere) + UI web QR + teste cu simulatorul bancii | 3–4 zile |
| 2 | **CyberSource** backend (SDK oficial + SDK VB, sesiune UC, confirmare, webhook MLE, reconciliere, void/refund) | 4–6 zile |
| 3 | UC în `WalletModal` + CSP + tunel HTTPS pentru dev | 2 zile |
| 4 | Simulator VB → scenarii în CyberSource TEST → pachet de dovezi → certificare | depinde de bancă |
| 5 | Producție: chei, webhook-uri, tranzacții mici, monitorizare (alertă top-up „necunoscut” > 10 min, rată refuz, 401/5xx) | 1 zi + bancă |

Recomandare: începem cu etapa 0, apoi **MIA și CyberSource în paralel** — MIA poate ajunge în producție mai repede (fără certificare grea), așa încât utilizatorii web să poată suplini contul cât timp CyberSource trece prin certificare.

## 8. Stare implementare (18.11.2026)

**Etapa 0 — gata.** Migrare `2026_11_18_100000_add_payment_provider_fields_to_payment_top_ups` (`provider`, `payment_method`, `provider_reference`, câmpuri QR; `payment_refunds.provider`). `App\Services\Payments\TopUpSettlementService` (creditare/debitare portofel, comună), `TopUpPaymentProvider` (interfață), `TopUpPaymentGateway` (alege providerul după `payment_top_ups.provider`). `PayFilmotecaPaymentService` folosește serviciul comun; comportamentul lui nu s-a schimbat. `PAYMENTS_CARD_ENABLED=false` oprește plata cu cardul cât timp pay.filmoteca.md nu merge.

**Etapa 1 (MIA) — gata în cod, netestată cu banca.**
- `App\Services\Payments\Mia\MiaClient` (token cache + refresh, retry la 401), `MiaSignalVerifier` (RS256 cu `resources/victoriabank/VBCA.crt`, amprentă SHA-256 verificată cu documentația; suportă mai multe certificate pentru rotație), `MiaPaymentService` (QR `DYNM`/`Fixed`, semnal, polling, anulare, refund total/parțial cu regula „o singură rambursare”, reconciliere pentru refund cu rezultat necunoscut).
- Rute: `POST /api/signals` (+ alias `POST /api/v1/payments/mia/signals`), `GET /api/v1/storefront/wallet/top-up-methods`, `POST /api/v1/storefront/wallet/top-ups` cu `method: "mia"` (fără telefon), `POST /api/v1/storefront/wallet/top-ups/{uuid}/cancel`. `payments:poll-topups` verifică acum ambii provideri.
- Web: `WalletModal` alege Card / MIA, `MiaQrPayment` afișează QR-ul + deeplink + countdown și face polling. Admin (`Billing.tsx`): tip plată, referința MIA, avertisment pentru rambursare unică.
- Teste: `tests/Feature/Api/MiaTopUpTest.php` (14 teste, inclusiv semnătură falsă, sumă greșită, semnal dublu, polling, refund parțial/total/necunoscut).

**De făcut pentru MIA în mediul de test:** credențiale + IBAN în `.env` (`MIA_*`), `MIA_ENABLED=true`, banca să înregistreze URL-ul `https://<api>/api/signals`, plată de test cu simulatorul `test-ipspj-demopay.victoriabank.md` (`POST /api/pay/` cu `qrHeaderUUID`), apoi refund și verificare în reconciliere.

**Etapa 2–3 (card prin CyberSource) — gata în cod, testată pe simulatorul băncii.**
- Decizie: integrare **directă** cu CyberSource, nu prin proxy-ul `cybersource.e-programare.md` (ține cheia, folosește HTTP Signature interzisă de R8, nu are Unified Checkout / refund / căutare, se autorizează doar cu Merchant ID). Contul de test `film_test001` există în sandbox; ne trebuie pentru el cheia REST Shared Secret (Key ID + secret) din Business Center.
- SDK-ul PHP al băncii inclus ca pachet Composer local: `film.md-admin-api/packages/victoriabank-cybersource-sdk` (reguli R1–R13 v1.3.0).
- `App\Services\Payments\CyberSource\CyberSourcePaymentService`: sesiune Unified Checkout scenariul A (`createUcDirectSession`), confirmare rezultat (`confirmUcResult`: RS256 + `GET /tss/v2/transactions/{id}` + referință/sumă/monedă + dovadă 3DS), mod **AUTH implicit** (captură `-CP1` înainte de creditare; fără 3DS complet → reversal), mod CAPTURE opțional (void), refund total/parțial (`-RF{n}`), polling după referință (R10), webhook cu `v-c-signature` + confirmare prin API.
- Rute: `POST /api/v1/storefront/wallet/top-ups` (`method: card`, fără telefon când `PAYMENTS_CARD_PROVIDER=cybersource`), `POST /api/v1/storefront/wallet/top-ups/{uuid}/card-result`, `POST /api/v1/webhooks/cybersource`.
- Web: `CardCheckout.tsx` încarcă biblioteca din `clientLibrary` + SRI și montează UC embedded în `WalletModal` (fără redirect, R13).
- Teste: `CyberSourceTopUpTest` (16, transport fals) + `CyberSourceSimulatorTest` (5, opțional, pe simulatorul VB cu JWT v2 real). Pe simulator: 0 încălcări; T-03, T-05, T-06, T-10, T-19 = PASS.

**De făcut pentru card:**
1. Key ID + Shared Secret pentru `film_test001` (REST – Shared Secret, generat pe Transacting MID), Unified Checkout activat cu Payment processing ON, `CYBERSOURCE_TARGET_ORIGINS` = domeniile exacte ale web-ului.
2. Test în browser (web local pe HTTPS prin tunel sau staging) cu cardurile 3DS de test; T-18 (auditul paginii) cere pagina reală.
3. Confirmare de la bancă: certificare doar pe scenariul A (simulatorul v1.2 marchează și T-01/T-13/T-UC-01/02 — scenariul B — ca obligatorii), AUTH vs CAPTURE, politica ECI „attempted”.
4. Înainte de producție: transport oficial `cybersource/rest-client-php` cu MLE (clientul încorporat refuză `production`), webhook MLE (cheie privată), CSP pe web.

## 9. Ce lipsește încă din documentație

Nu au fost deschise încă `VB-CyberSource-Comerciant.zip` (SDK PHP/Node, payload-uri P01–P40, `ai-kit`, `certificare/`) și `VB-CyberSource-Simulator.zip`. Sunt necesare la etapa 2 și 4.
