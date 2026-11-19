# SDK Victoriabank CyberSource · Victoriabank CyberSource SDK · SDK Victoriabank CyberSource

[Română](#română) · [English](#english) · [Русский](#русский)

| | Node.js (`sdk/node`) | PHP (`sdk/php`) |
|---|---|---|
| Versiune / Version / Версия | 1.0.0 | 1.0.0 |
| Platformă / Platform / Платформа | Node.js 22+ | PHP 8.2+ (curl, json, openssl, mbstring; intl recomandat / recommended / рекомендуется) |
| Reguli / Rules / Правила | `rules/vb-cybersource-rules.json` 1.2.1 (engine 2) | același fișier / the same file / тот же файл |
| Transport oficial / Official transport / Официальный транспорт | `OfficialSdkTransport` → `cybersource-rest-client` (0.0.84) | `OfficialSdkTransport` → `cybersource/rest-client-php` (0.0.77, ext-gmp) |
| Teste / Tests / Тесты | `node --test` (48) | `php tests/run.php` (35) · `php -d extension=gmp tests/official-models.php` (41 + 20) |

---

## Română

### Ce este

SDK-ul Victoriabank este un **strat de reguli Victoriabank peste CyberSource REST**. Pentru fiecare operațiune:

1. construiește cererea canonică (aceeași ca în `exemple/payloads`, P01–P40);
2. o verifică față de regulile R1–R13 **înainte de trimitere** — o cerere care încalcă o regulă nu pleacă (`VbRuleError` / `VbRuleException`);
3. o trimite printr-un *transport*;
4. întoarce răspunsul original CyberSource (`data`) împreună cu interpretarea lui: pasul următor (`outcome.next`), dovada 3-D Secure (`evidence`), decizia (`paid`, `mustReverse`, `mustRelease`), tokenurile (`tokens`), ID-ul de tranzacție al comerciantului (`transactionId`).

SDK-ul **nu înlocuiește** SDK-ul oficial CyberSource: pentru certificarea în CyberSource TEST și în producție îl folosește ca transport, astfel încât semnătura JWT v2 și criptarea MLE sunt cele oficiale.

| Transport | Când | Ce face |
|---|---|---|
| Încorporat (`CybsClient` / `Client`) | simulatorul VB, depanare | JWT v2 (HS256), fără MLE; refuză `production` |
| `OfficialSdkTransport` | **CyberSource TEST (certificare) și producție** | SDK-ul oficial CyberSource: JWT v2 + MLE, conform configurării contului; refuză producția fără MLE |

MLE (provizoriu, de confirmat de bancă): obligatoriu azi acolo unde CyberSource îl cere (webhook-uri pentru evenimente de plată / Unified Checkout, credențiale network token); pentru celelalte API-uri obligatoriu de la termenul CyberSource din martie 2027. Banca **recomandă insistent** certificarea în CyberSource TEST cu `OfficialSdkTransport` + MLE — aceeași configurație ca în producție.

### Instalare

**Node.js** — în proiectul **dvs.** (nu în `sdk/node` al kitului):

```bash
npm install ./vb-kit/sdk/node                  # SDK-ul Victoriabank (0 dependențe)
npm install cybersource-rest-client@0.0.84     # transportul oficial (TEST cu MLE, producție)
```

`examples/quickstart.js` rulat din kit folosește transportul încorporat; cu `CYBS_TRANSPORT=official` (și `CYBS_RUN_ENVIRONMENT`, implicit `apitest.cybersource.com`) folosește SDK-ul oficial, încărcat din `CYBS_SDK_PATH` — calea către `node_modules/cybersource-rest-client` al proiectului dvs. (fără ea: `require('cybersource-rest-client')`). `npm audit` raportează 2 vulnerabilități „high” tranzitive în pachetul oficial (`node-forge`, GHSA-86w9-cpqp-85rv); SDK-ul Victoriabank nu are dependențe. Nu rulați `npm audit fix --force` (ar coborî `cybersource-rest-client` la 0.0.26); urmăriți versiunile noi ale pachetului oficial.

**PHP** — pachetul nu este pe Packagist; adăugați-l ca depozit de tip `path` în `composer.json`:

```json
{
  "repositories": [{ "type": "path", "url": "./vb-kit/sdk/php" }],
  "require": {
    "victoriabank/cybersource-sdk": "*",
    "cybersource/rest-client-php": "~0.0.77"
  }
}
```

```bash
composer update victoriabank/cybersource-sdk cybersource/rest-client-php
```

SDK-ul oficial PHP cere `ext-gmp`: activați `extension=gmp` în `php.ini` (XAMPP îl livrează dezactivat) sau rulați cu `php -d extension=gmp …`. Tot el aduce phpseclib 3, necesar pentru `decryptWebhook`.

Fără Composer (OpenCart, magazine proprii): `require 'vb-kit/sdk/php/autoload.php';` — suficient pentru transportul încorporat. Transportul oficial are nevoie de SDK-ul oficial instalat cu Composer (`vendor/autoload.php`).

**Simulatorul VB cu `--tls`** (certificat propriu, `sim-cert.pem`): Node.js — `NODE_EXTRA_CA_CERTS=sim-cert.pem`; PHP — `Jwt::$caBundle = '/cale/sim-cert.pem';` sau variabila de mediu `VB_CA_BUNDLE`. SDK-ul PHP descarcă cheia publică a capture context-ului prin propriul cURL, deci opțiunea CAINFO a SDK-ului oficial nu este suficientă. SDK-ul Victoriabank nu dezactivează niciodată verificarea TLS. Excepție de test: `tests/simulator.php` cu `VB_TRANSPORT=official` pornește clientul SDK-ului oficial cu `setSSLVerification(false)` pentru certificatul autosemnat al simulatorului — nu copiați această linie în cod real.

### Exemplu (Node.js)

```js
const { VbCyberSource, OfficialSdkTransport, references } = require('vb-cybersource-sdk');

// simulator / depanare: transport încorporat
const vb = new VbCyberSource({ merchantId, keyId, sharedSecret, baseUrl: 'http://127.0.0.1:8770' });
// CyberSource TEST (certificare) și producție: transportul oficial (JWT v2 + MLE)
// const vb = new VbCyberSource({ transport: new OfficialSdkTransport(require('cybersource-rest-client'), merchantConfig) });

const ref = references('ORD-1001');
const av = await vb.verifyCard({ reference: `AV-${ref.order}`, transientToken, billTo });           // 0,00 + TOKEN_CREATE
const { customerId, paymentInstrumentId, cardType } = av.tokens;
const setup = await vb.setupPayerAuth({ reference: ref.order, customerId });
const auth = await vb.authorize({ reference: ref.attempt(1), paymentInstrumentId, amount: '1250.00', billTo, device,
  referenceId: setup.setup.referenceId, returnUrl: 'https://shop.example.md/payments/3ds/return' });
// salvați auth.transactionId cu încercarea (reversare la timeout)
if (auth.outcome.next === 'CHALLENGE') { /* afișați challenge-ul în iframe, apoi vb.validateChallenge(...) */ }
if (auth.mustReverse) await vb.reverse(auth.data.id, { reference: ref.reversal(1), amount: '1250.00' });    // R4
if (auth.paid) { /* livrați / capturați */ }
```

### Exemplu (PHP)

```php
use Victoriabank\CyberSource\{VbCyberSource, OfficialSdkTransport, References};

$vb  = VbCyberSource::create($merchantId, $keyId, $sharedSecret, baseUrl: 'http://127.0.0.1:8770');   // simulator / depanare
// CyberSource TEST (certificare) și producție: \CyberSource\ApiClient configurat cu MerchantConfiguration (JWT + MLE):
// $vb = new VbCyberSource(new OfficialSdkTransport($apiClient));
$ref = new References('ORD-1001');
$av  = $vb->verifyCard("AV-{$ref->order}", $transientToken, $billTo);
['customerId' => $customerId, 'paymentInstrumentId' => $pi] = $av['tokens'];
$setup = $vb->setupPayerAuth($ref->order, $customerId);
$auth  = $vb->authorize($ref->attempt(1), null, $pi, '1250.00', $billTo, $device, $setup['setup']['referenceId'],
    'https://shop.example.md/payments/3ds/return');
if ($auth['mustReverse']) { $vb->reverse($auth['data']['id'], $ref->reversal(1), '1250.00'); }
if ($auth['paid']) { /* livrați / capturați */ }
```

Exemple complete: `sdk/node/examples/quickstart.js`, `sdk/php/tests/simulator.php`, CLI PHP `exemple/php/examples.php` (`session`, `av`, `kinds`, `mit <kind>`, `search`, `delete`); integrarea de referință `exemple/node` folosește SDK-ul Node.js.

Rulare împotriva simulatorului (bash / PowerShell):

```bash
CYBS_BASE_URL=http://127.0.0.1:8770 CYBS_MERCHANT_ID=vb_sim_merchant CYBS_KEY_ID=<key id> CYBS_SHARED_SECRET=<secret> php tests/simulator.php
# prin SDK-ul oficial PHP (simulator pornit cu --tls):
VB_TRANSPORT=official VB_OFFICIAL_SDK_AUTOLOAD=/cale/vendor/autoload.php VB_CA_BUNDLE=/cale/sim-cert.pem CYBS_BASE_URL=https://127.0.0.1:8770 \
  CYBS_MERCHANT_ID=vb_sim_merchant CYBS_KEY_ID=<key id> CYBS_SHARED_SECRET=<secret> php -d extension=gmp tests/simulator.php
```

```powershell
$env:CYBS_BASE_URL='http://127.0.0.1:8770'; $env:CYBS_MERCHANT_ID='vb_sim_merchant'; $env:CYBS_KEY_ID='<key id>'; $env:CYBS_SHARED_SECRET='<secret>'
php tests/simulator.php
$env:VB_TRANSPORT='official'; $env:VB_OFFICIAL_SDK_AUTOLOAD='C:\cale\vendor\autoload.php'; $env:VB_CA_BUNDLE='C:\cale\sim-cert.pem'; $env:CYBS_BASE_URL='https://127.0.0.1:8770'
php -d extension=gmp tests/simulator.php
```

### Metode

Aceleași metode și aceleași rezultate în ambele implementări. Node.js primește un obiect de opțiuni; PHP primește argumente poziționale în ordinea din coloana PHP (argumentele numite PHP 8 funcționează). Fiecare metodă de rețea întoarce `{ httpStatus, data, … }` / `['httpStatus' => …, 'data' => …]`.

| Node.js | PHP | Operațiune CyberSource | Regula |
|---|---|---|---|
| `createMicroformSession({targetOrigin, allowedCardNetworks})` | `createMicroformSession($targetOrigin, $allowedCardNetworks)` | `POST /microform/v2/sessions` + verificare RS256 | R1, R13 |
| `createUcAddCardSession({targetOrigin, reference, billTo, existingCustomerId, locale})` | `createUcAddCardSession($targetOrigin, $reference, $billTo, $existingCustomerId, $locale)` | `POST /uc/v1/sessions` (sesiunea 1: 0,00 + token) | R2, R6, R12 |
| `createUcPaySession({targetOrigin, reference, customerId, amount, billTo, locale, agreement, cardType, brand})` | `createUcPaySession($targetOrigin, $reference, $customerId, $amount, $billTo, $locale, $agreement, $cardType, $brand)` | `POST /uc/v1/sessions` (sesiunea 2: plată cu tokenul, 3DS) | R3, R4, R12 |
| `createUcDirectSession({targetOrigin, reference, amount, billTo, locale, allowedPaymentTypes, capture})` | `createUcDirectSession($targetOrigin, $reference, $amount, $billTo, $locale, $allowedPaymentTypes, $capture)` | `POST /uc/v1/sessions` (plată directă, scenariul A: sumă > 0, PANENTRY / APPLEPAY / GOOGLEPAY, 3DS, nimic salvat — `P40`; `capture` = vânzare, anulată cu `voidPayment`) | R2, R4, R12 |
| `verifyTransientToken(token, {jwk})` | `verifyTransientToken($token, $jwk)` | verificare RS256 a transient token-ului | R9 |
| `verifyCard({reference, transientToken, billTo, existingCustomerId, transactionId})` | `verifyCard($reference, $transientToken, $billTo, $existingCustomerId, $transactionId)` | Account Verification 0,00 + `TOKEN_CREATE` | R2, R6 |
| `setupPayerAuth({reference, customerId})` | `setupPayerAuth($reference, $customerId)` | `POST /risk/v1/authentication-setups` | R4 |
| `authorize({reference, customerId, paymentInstrumentId, amount, billTo, device, referenceId, returnUrl, transactionId, agreement, cardType, brand})` | `authorize($reference, $customerId, $pi, $amount, $billTo, $device, $referenceId, $returnUrl, $transactionId, $agreement, $cardType, $brand)` | CIT cu tokenul + 3-D Secure combinat | R3, R4 |
| `validateChallenge({reference, customerId, paymentInstrumentId, amount, authenticationTransactionId, transactionId, agreement, cardType, brand})` | `validateChallenge($reference, $customerId, $pi, $amount, $authenticationTransactionId, $transactionId, $agreement, $cardType, $brand)` | validare 3DS + autorizare | R3, R4 |
| `chargeStoredCard({reference, customerId, paymentInstrumentId, amount, cardType, brand, kind, previousTransactionId, transactionId, capture})` | `chargeStoredCard($reference, $customerId, $pi, $amount, $cardType, $kind, $previousTransactionId, $brand, $transactionId, $capture)` | MIT după tabelul CIT/MIT (mai jos) | R5 |
| `incrementalAuthorization(paymentId, {reference, additionalAmount, currency, transactionId})` | `incrementalAuthorization($paymentId, $reference, $additionalAmount, $currency, $transactionId)` | `PATCH /pts/v2/payments/{id}` (provizoriu, C9) | R5 |
| `capture(paymentId, {reference, amount})`, `reverse(paymentId, {reference, amount, reason})`, `refund(captureOrSaleId, {reference, amount})` | `capture($paymentId, $reference, $amount)`, `reverse($paymentId, $reference, $amount, $reason)`, `refund($captureOrSaleId, $reference, $amount)` | operațiuni ulterioare | §11 |
| `voidPayment(paymentId, {reference})` / `voidCapture(captureId, {reference})` / `voidRefund(refundId, {reference})` | `voidPayment($paymentId, $reference)` / `voidCapture($captureId, $reference)` / `voidRefund($refundId, $reference)` | void al unei vânzări / capturi / rambursări | §11 |
| `reverseByTransactionId(transactionId, {reference, amount, currency, reason})` | `reverseByTransactionId($transactionId, $reference, $amount, $currency, $reason)` | `POST /pts/v2/reversals` — reversare la timeout (disponibilitate pe VPC: de confirmat, B4) | R10 |
| `searchByReference(reference, {from, to, limit})` | `searchByReference($reference, ['from' => …, 'to' => …, 'limit' => …])` | `POST /tss/v2/searches` (implicit: ieri–mâine, 10) | R10 |
| `getTransaction(id)` | `getTransaction($id)` | `GET /tss/v2/transactions/{id}` | R9, R10 |
| `updatePaymentInstrument(customerId, piId, {expirationMonth, expirationYear, billTo, isDefault})` | `updatePaymentInstrument($customerId, $piId, $month, $year, $billTo, $isDefault)` | `PATCH` instrument de plată: expirare nouă, adresă, card implicit | R1, R7 |
| `getInstrumentIdentifier(id)` / `deleteInstrumentIdentifier(id)` | `getInstrumentIdentifier($id)` / `deleteInstrumentIdentifier($id)` | `/tms/v1/instrumentidentifiers/{id}` (rezultatul are `networkTransactionId` — NTID-ul TMS al cardului; ștergerea îl scoate pentru **toți** clienții, preferați `deletePaymentInstrument`) | R5, R7 |
| `deleteCustomer(customerId)`, `deletePaymentInstrument(customerId, paymentInstrumentId)` | `deleteCustomer($customerId)`, `deletePaymentInstrument($customerId, $paymentInstrumentId)` | ștergerea clientului / a unui card | R7 |
| `confirmUcResult(jwt, {expectedReference, expectedAmount, expectedCurrency})` | `confirmUcResult($jwt, $expectedReference, $expectedAmount, $expectedCurrency)` | rezultat UC: semnătură + `GET /tss/v2/transactions/{id}` + legare de referință, sumă, monedă | R9 |
| `verifyWebhook(header, rawBody, {key, keyId})` | `verifyWebhook($header, $rawBody, $keyB64, $keyId)` | semnătura `v-c-signature` pe corpul brut | S7 |
| `decryptWebhook(rawBody, privateKey, {passphrase, requireEncrypted})` | `decryptWebhook($rawBody, $privateKeyPem, $passphrase, $requireEncrypted, &$encrypted)` | notificare MLE (JWE RSA-OAEP-256 + A256GCM); PHP: phpseclib 3 (vine cu SDK-ul oficial) | A1 |

Toate ID-urile din căi sunt codificate URL. `baseUrl` acceptă doar `https` (simulatorul pe `http://localhost` / `127.0.0.1`).

### Tabelul CIT/MIT

Valorile vin din `policy.storedCredentials` al fișierului de reguli (același pentru SDK, `check-request.js`, simulator). `php examples.php kinds` le afișează; în Node.js: `MIT_KINDS`, `mitCell(kind, brand)`.

| `kind` (`chargeStoredCard`) | Visa: commerceIndicator / reason | Mastercard: commerceIndicator / reason |
|---|---|---|
| `recurring` (sumă fixă) | `recurring` / — | `recurring` / 7 |
| `recurring_variable` | `recurring` / — | `recurring` / 8 |
| `installment` | `install` / — | `install` / 9 |
| `unscheduled` (UCOF) | `internet` / — | `internet` / 10 |
| `topup` | `internet` / — | `internet` / 10 |
| `resubmission` (doar după DECLINED) | `internet` / 1 | `internet` / 1 |
| `delayed` | `internet` / 2 | `internet` / 2 |
| `reauthorization` | `internet` / 3 | nesuportat |
| `noshow` | `internet` / 4 | `internet` / 4 |
| `incremental` | folosiți `incrementalAuthorization` | folosiți `incrementalAuthorization` |
| `partial_shipment` | nesuportat | nesuportat (reason 6, C18) |

Rețeaua este obligatorie (`cardType` `"001"`/`"002"` din `tokens.cardType`, sau `brand`). `previousTransactionId` = NTID-ul de 15 cifre al tranzacției care a **stabilit acordul**, salvat per acord. O celulă nesuportată, o rețea lipsă sau un NTID greșit dau `VbRuleError` / `VbRuleException` — nimic nu se trimite. Rezultatul are `kind`, `scenario`, `paid`, `mustRelease` și, unde este cazul, `pendingConfirmation` (referințe încă de confirmat de bancă).

**Acord (opțiunea `agreement`)** pe `authorize`, `validateChallenge`, `createUcPaySession`: `{ type: 'recurring' | 'installment' | 'unscheduled', variableAmount?: true }` + `cardType`/`brand`. CIT-ul plătit **stabilește** acordul: `credentialStoredOnFile: true` fără `storedCredentialUsed` (C4), `firstRecurringPayment` pentru recurent, Mastercard `reason` 7/8/9; `commerceIndicator` este lăsat autentificării (C12). Salvați `result.agreement.networkTransactionId` (prezent doar când `paid`) — este `previousTransactionId` al fiecărui MIT din acord. Valorile sunt provizorii, de confirmat de bancă.

### Rezultate: `paid`, `state`, status necunoscut

- `paid` este **singura** decizie de livrare: `outcome.next === 'APPROVED'` cu 3-D Secure complet (și, pentru UC, referință + sumă + monedă potrivite). `mustReverse` = aprobat sau în revizuire fără 3DS complet (R4); `mustRelease` = `mustReverse`, autorizare parțială sau `AUTHORIZED_RISK_DECLINED`.
- `confirmUcResult().state` spune doar dacă tranzacția aparține comenzii: `MATCHED`, `MISMATCH` (nu este plata acestei comenzi) sau `PENDING` (încă negăsită — reîncercați). O plată `MATCHED` poate fi neplătită (refuzată, `mustReverse`): livrați **numai** când `paid === true`, niciodată pe baza lui `state`. Pentru Account Verification 0,00 folosiți `verified`.
- **Status necunoscut** = orice HTTP 5xx, timeout sau eroare de transport, ori un `reason` din `UNKNOWN_REASONS` (`DUPLICATE_REQUEST` — și pe HTTP 400 —, `SYSTEM_ERROR`, `SERVER_TIMEOUT`, `SERVICE_TIMEOUT`, `PROCESSOR_TIMEOUT`). SDK-ul aruncă o eroare cu `unknownStatus: true` / `UnknownStatusException`, cu `reference` și, pentru o autorizare, `transactionId`. Un status pe care SDK-ul nu îl cunoaște dă `outcome.next === 'UNKNOWN'`. În ambele cazuri: nu retrimiteți; `searchByReference` (R10); dacă autorizarea nu apare după 60 s, `reverseByTransactionId`.

### Reguli 1.2.1 și politica 3-D Secure

- Fișierul `rules/vb-cybersource-rules.json` 1.2.1 (engine 2) este același în SDK-ul Node.js, SDK-ul PHP, kitul AI și simulator. Versiunea și amprenta: Node.js `RULES_VERSION` / `RULES_HASH`, PHP `Rules::version()` / `Rules::hash()`; ambele apar în fiecare `VbRuleError` / `VbRuleException`.
- `policy.threeDs.acceptAttempted` = `true` (provizoriu, de confirmat de bancă): o autentificare *attempted* (Visa ECI 06, Mastercard ECI 01) **cu criptogramă** contează ca 3-D Secure complet (R4), deci poate da `paid`; cu `false`, aceeași autorizare dă `mustReverse`. Fără criptogramă sau cu Mastercard retrogradat (`ucafCollectionIndicator` 0) nu este niciodată 3DS complet.
- Opțiunea `acceptAttempted` (Node.js `new VbCyberSource({ acceptAttempted })`, PHP argumentul `$acceptAttempted` al `create()` / constructorului) suprascrie valoarea din fișier pentru validarea regulilor **și** pentru `evidence` / `paid`. Schimbați-o doar la decizia scrisă a băncii.
- `strictRules` (implicit `true`): cu `false`, o cerere care încalcă o regulă pleacă totuși, fără avertisment — doar pentru depanare, niciodată în producție.
- Alte opțiuni: `currency` (implicit `MDL`); transportul încorporat are timeout 65 s (Node.js `timeoutMs`) și refuză `environment: 'production'`.

### Erori

- `VbRuleError` / `VbRuleException`: cererea încalcă o regulă; **nu a fost trimisă**. `violations` conține ID-urile regulilor, `scenario` scenariul (mai multe unite cu `+`), `rulesVersion` + `rulesHash` versiunea regulilor.
- Eroare cu `unknownStatus: true` / `UnknownStatusException`: statusul este **necunoscut** (definiția de mai sus).
- `Error` / `InvalidArgumentException`: date invalide (sumă, `transactionId`, referință, `baseUrl`, cheie webhook care nu este Base64) — nimic trimis.
- `Error` / `RuntimeException`: `confirmUcResult` cu un rezultat fără ID de tranzacție; `decryptWebhook` în PHP fără phpseclib 3.

### Domeniul PHP

SDK-ul PHP acoperă toate metodele de mai sus, cu aceleași reguli și aceleași rezultate ca Node.js, plus exemplele CLI (`exemple/php/examples.php`). Mașina de stări de referință a kitului (pagini, challenge 3DS, Unified Checkout, captură, registrul MIT, reconciliere) este implementată **doar în Node.js** (`exemple/node`). Un magazin PHP ajunge la READY cu propriul flux construit pe aceste metode; simulatorul verifică fluxul, nu limbajul.

### Ce s-a verificat

- Constructorii reproduc octet cu octet cererile canonice P01, P03, P05, P06, P08, P12, P13, P16–P24, P27–P40 în ambele implementări (Node.js și PHP); P10/P11 sunt verificate prin reguli; numele claim-urilor JWT = P26; P02, P04, P07, P09, P15 sunt răspunsuri / exemple; P14 și P25 nu au constructor. JWT-ul v2 este identic octet cu octet între Node.js și PHP.
- Aceiași vectori de paritate (`parity-vectors.json`: sume, ECI Visa/Mastercard, interpretare, decizii, căi, reguli) și aceeași matrice CIT/MIT în ambele suite de teste; toate cazurile `payloads/negative` sunt respinse.
- Toate cererile VB trec neschimbate prin modelele SDK-ului oficial PHP (0.0.77); toate rutele `OfficialSdkTransport` PHP corespund metodelor oficiale (verificare prin reflecție).
- Fluxul complet (sesiune, Account Verification, 3-D Secure, captură, rambursare, card implicit, acord + MIT + void, reversare la timeout, ștergere, căutare) trece prin simulatorul VB cu transportul încorporat **și** cu SDK-urile oficiale CyberSource (Node.js 0.0.84, PHP 0.0.77).

### Teste

| Comandă (din `sdk/node` / `sdk/php`) | Ce verifică | Rezultat actual |
|---|---|---|
| `node --test` | constructori P01–P40, reguli, vectori de paritate, matricea CIT/MIT, fațada, transportul oficial (rute fixate) | 48/48 |
| `php tests/run.php` | aceleași vectori de paritate și aceeași matrice în PHP; cu `VB_OFFICIAL_SDK_AUTOLOAD` și decriptarea JWE a webhook-urilor | 35 passed |
| `VB_OFFICIAL_SDK_AUTOLOAD=/cale/vendor/autoload.php php -d extension=gmp tests/official-models.php` | cererile VB prin modelele SDK-ului oficial PHP; rutele `OfficialSdkTransport` (reflecție) | 41 cereri, 20 rute |
| `php tests/simulator.php` (variabilele de mai sus, simulator pornit) | fluxul complet împotriva simulatorului VB | — |

```powershell
cd sdk\node; node --test
cd ..\php; php tests/run.php
$env:VB_OFFICIAL_SDK_AUTOLOAD='C:\cale\vendor\autoload.php'; php -d extension=gmp tests/official-models.php
```

### Limite

MLE se aplică doar prin transportul oficial, iar simulatorul nu îl poate verifica; MLE și comportamentul real al emitenților se verifică în CyberSource TEST. Valorile marcate „provizoriu” (ECI attempted, tabelul CIT/MIT, MLE pentru webhook-uri, `/pts/v2/reversals`) sunt de confirmat de bancă. Cheile și parolele se citesc din variabile de mediu sau dintr-un seif de secrete, niciodată din cod.

---

## English

### What it is

The Victoriabank SDK is a **layer of Victoriabank rules over CyberSource REST**. For every operation it:

1. builds the canonical request (the same as `exemple/payloads`, P01–P40);
2. checks it against rules R1–R13 **before it is sent** — a request that breaks a rule never leaves (`VbRuleError` / `VbRuleException`);
3. sends it through a *transport*;
4. returns the original CyberSource response (`data`) with its interpretation: the next step (`outcome.next`), the 3-D Secure evidence (`evidence`), the decision (`paid`, `mustReverse`, `mustRelease`), the tokens (`tokens`), the merchant transaction ID (`transactionId`).

The SDK **does not replace** the official CyberSource SDK: for certification in CyberSource TEST and in production it uses it as the transport, so the JWT v2 signature and the MLE encryption are the official ones.

| Transport | When | What it does |
|---|---|---|
| Built-in (`CybsClient` / `Client`) | the VB simulator, debugging | JWT v2 (HS256), no MLE; refuses `production` |
| `OfficialSdkTransport` | **CyberSource TEST (certification) and production** | the official CyberSource SDK: JWT v2 + MLE, as configured for the account; refuses production without MLE |

MLE (provisional, to be confirmed by the bank): mandatory today where CyberSource requires it (webhooks for payment / Unified Checkout events, network-token credentials); for all other APIs mandatory from the CyberSource deadline of March 2027. The bank **strongly recommends** certifying in CyberSource TEST with `OfficialSdkTransport` + MLE — the same configuration as production.

### Installation

**Node.js** — in **your** project (not inside the kit's `sdk/node`):

```bash
npm install ./vb-kit/sdk/node                  # the Victoriabank SDK (0 dependencies)
npm install cybersource-rest-client@0.0.84     # the official transport (TEST with MLE, production)
```

`examples/quickstart.js` run from the kit uses the built-in transport; with `CYBS_TRANSPORT=official` (and `CYBS_RUN_ENVIRONMENT`, default `apitest.cybersource.com`) it uses the official SDK, loaded from `CYBS_SDK_PATH` — the path to your project's `node_modules/cybersource-rest-client` (without it: `require('cybersource-rest-client')`). `npm audit` reports 2 transitive "high" vulnerabilities in the official package (`node-forge`, GHSA-86w9-cpqp-85rv); the Victoriabank SDK has no dependencies. Do not run `npm audit fix --force` (it would downgrade `cybersource-rest-client` to 0.0.26); follow new releases of the official package.

**PHP** — the package is not on Packagist; add it as a `path` repository in `composer.json`:

```json
{
  "repositories": [{ "type": "path", "url": "./vb-kit/sdk/php" }],
  "require": {
    "victoriabank/cybersource-sdk": "*",
    "cybersource/rest-client-php": "~0.0.77"
  }
}
```

```bash
composer update victoriabank/cybersource-sdk cybersource/rest-client-php
```

The official PHP SDK needs `ext-gmp`: enable `extension=gmp` in `php.ini` (XAMPP ships it disabled) or run with `php -d extension=gmp …`. It also brings phpseclib 3, which `decryptWebhook` needs.

Without Composer (OpenCart, custom shops): `require 'vb-kit/sdk/php/autoload.php';` — enough for the built-in transport. The official transport needs the official SDK installed with Composer (`vendor/autoload.php`).

**VB simulator with `--tls`** (its own certificate, `sim-cert.pem`): Node.js — `NODE_EXTRA_CA_CERTS=sim-cert.pem`; PHP — `Jwt::$caBundle = '/path/sim-cert.pem';` or the environment variable `VB_CA_BUNDLE`. The PHP SDK downloads the capture-context public key through its own cURL handle, so the CAINFO option of the official SDK is not enough. The Victoriabank SDK never switches TLS verification off. Test-only exception: `tests/simulator.php` with `VB_TRANSPORT=official` starts the official SDK client with `setSSLVerification(false)` for the simulator's self-signed certificate — do not copy that line into real code.

### Example (Node.js)

```js
const { VbCyberSource, OfficialSdkTransport, references } = require('vb-cybersource-sdk');

// simulator / debugging: built-in transport
const vb = new VbCyberSource({ merchantId, keyId, sharedSecret, baseUrl: 'http://127.0.0.1:8770' });
// CyberSource TEST (certification) and production: the official transport (JWT v2 + MLE)
// const vb = new VbCyberSource({ transport: new OfficialSdkTransport(require('cybersource-rest-client'), merchantConfig) });

const ref = references('ORD-1001');
const av = await vb.verifyCard({ reference: `AV-${ref.order}`, transientToken, billTo });           // 0.00 + TOKEN_CREATE
const { customerId, paymentInstrumentId, cardType } = av.tokens;
const setup = await vb.setupPayerAuth({ reference: ref.order, customerId });
const auth = await vb.authorize({ reference: ref.attempt(1), paymentInstrumentId, amount: '1250.00', billTo, device,
  referenceId: setup.setup.referenceId, returnUrl: 'https://shop.example.md/payments/3ds/return' });
// store auth.transactionId with the attempt (timeout reversal)
if (auth.outcome.next === 'CHALLENGE') { /* show the challenge in an iframe, then vb.validateChallenge(...) */ }
if (auth.mustReverse) await vb.reverse(auth.data.id, { reference: ref.reversal(1), amount: '1250.00' });    // R4
if (auth.paid) { /* deliver / capture */ }
```

### Example (PHP)

```php
use Victoriabank\CyberSource\{VbCyberSource, OfficialSdkTransport, References};

$vb  = VbCyberSource::create($merchantId, $keyId, $sharedSecret, baseUrl: 'http://127.0.0.1:8770');   // simulator / debugging
// CyberSource TEST (certification) and production: \CyberSource\ApiClient configured with MerchantConfiguration (JWT + MLE):
// $vb = new VbCyberSource(new OfficialSdkTransport($apiClient));
$ref = new References('ORD-1001');
$av  = $vb->verifyCard("AV-{$ref->order}", $transientToken, $billTo);
['customerId' => $customerId, 'paymentInstrumentId' => $pi] = $av['tokens'];
$setup = $vb->setupPayerAuth($ref->order, $customerId);
$auth  = $vb->authorize($ref->attempt(1), null, $pi, '1250.00', $billTo, $device, $setup['setup']['referenceId'],
    'https://shop.example.md/payments/3ds/return');
if ($auth['mustReverse']) { $vb->reverse($auth['data']['id'], $ref->reversal(1), '1250.00'); }
if ($auth['paid']) { /* deliver / capture */ }
```

Complete examples: `sdk/node/examples/quickstart.js`, `sdk/php/tests/simulator.php`, the PHP CLI `exemple/php/examples.php` (`session`, `av`, `kinds`, `mit <kind>`, `search`, `delete`); the reference integration `exemple/node` uses the Node.js SDK.

Running against the simulator (bash / PowerShell):

```bash
CYBS_BASE_URL=http://127.0.0.1:8770 CYBS_MERCHANT_ID=vb_sim_merchant CYBS_KEY_ID=<key id> CYBS_SHARED_SECRET=<secret> php tests/simulator.php
# through the official PHP SDK (simulator started with --tls):
VB_TRANSPORT=official VB_OFFICIAL_SDK_AUTOLOAD=/path/vendor/autoload.php VB_CA_BUNDLE=/path/sim-cert.pem CYBS_BASE_URL=https://127.0.0.1:8770 \
  CYBS_MERCHANT_ID=vb_sim_merchant CYBS_KEY_ID=<key id> CYBS_SHARED_SECRET=<secret> php -d extension=gmp tests/simulator.php
```

```powershell
$env:CYBS_BASE_URL='http://127.0.0.1:8770'; $env:CYBS_MERCHANT_ID='vb_sim_merchant'; $env:CYBS_KEY_ID='<key id>'; $env:CYBS_SHARED_SECRET='<secret>'
php tests/simulator.php
$env:VB_TRANSPORT='official'; $env:VB_OFFICIAL_SDK_AUTOLOAD='C:\path\vendor\autoload.php'; $env:VB_CA_BUNDLE='C:\path\sim-cert.pem'; $env:CYBS_BASE_URL='https://127.0.0.1:8770'
php -d extension=gmp tests/simulator.php
```

### Methods

The same methods and the same results in both implementations. Node.js takes an options object; PHP takes positional arguments in the order of the PHP column (PHP 8 named arguments work). Every network method returns `{ httpStatus, data, … }` / `['httpStatus' => …, 'data' => …]`.

| Node.js | PHP | CyberSource operation | Rule |
|---|---|---|---|
| `createMicroformSession({targetOrigin, allowedCardNetworks})` | `createMicroformSession($targetOrigin, $allowedCardNetworks)` | `POST /microform/v2/sessions` + RS256 verification | R1, R13 |
| `createUcAddCardSession({targetOrigin, reference, billTo, existingCustomerId, locale})` | `createUcAddCardSession($targetOrigin, $reference, $billTo, $existingCustomerId, $locale)` | `POST /uc/v1/sessions` (session 1: 0.00 + token) | R2, R6, R12 |
| `createUcPaySession({targetOrigin, reference, customerId, amount, billTo, locale, agreement, cardType, brand})` | `createUcPaySession($targetOrigin, $reference, $customerId, $amount, $billTo, $locale, $agreement, $cardType, $brand)` | `POST /uc/v1/sessions` (session 2: payment with the token, 3DS) | R3, R4, R12 |
| `createUcDirectSession({targetOrigin, reference, amount, billTo, locale, allowedPaymentTypes, capture})` | `createUcDirectSession($targetOrigin, $reference, $amount, $billTo, $locale, $allowedPaymentTypes, $capture)` | `POST /uc/v1/sessions` (direct payment, scenario A: amount > 0, PANENTRY / APPLEPAY / GOOGLEPAY, 3DS, nothing stored — `P40`; `capture` = sale, released with `voidPayment`) | R2, R4, R12 |
| `verifyTransientToken(token, {jwk})` | `verifyTransientToken($token, $jwk)` | RS256 verification of the transient token | R9 |
| `verifyCard({reference, transientToken, billTo, existingCustomerId, transactionId})` | `verifyCard($reference, $transientToken, $billTo, $existingCustomerId, $transactionId)` | 0.00 Account Verification + `TOKEN_CREATE` | R2, R6 |
| `setupPayerAuth({reference, customerId})` | `setupPayerAuth($reference, $customerId)` | `POST /risk/v1/authentication-setups` | R4 |
| `authorize({reference, customerId, paymentInstrumentId, amount, billTo, device, referenceId, returnUrl, transactionId, agreement, cardType, brand})` | `authorize($reference, $customerId, $pi, $amount, $billTo, $device, $referenceId, $returnUrl, $transactionId, $agreement, $cardType, $brand)` | CIT with the token + combined 3-D Secure | R3, R4 |
| `validateChallenge({reference, customerId, paymentInstrumentId, amount, authenticationTransactionId, transactionId, agreement, cardType, brand})` | `validateChallenge($reference, $customerId, $pi, $amount, $authenticationTransactionId, $transactionId, $agreement, $cardType, $brand)` | 3DS validation + authorization | R3, R4 |
| `chargeStoredCard({reference, customerId, paymentInstrumentId, amount, cardType, brand, kind, previousTransactionId, transactionId, capture})` | `chargeStoredCard($reference, $customerId, $pi, $amount, $cardType, $kind, $previousTransactionId, $brand, $transactionId, $capture)` | MIT from the CIT/MIT table (below) | R5 |
| `incrementalAuthorization(paymentId, {reference, additionalAmount, currency, transactionId})` | `incrementalAuthorization($paymentId, $reference, $additionalAmount, $currency, $transactionId)` | `PATCH /pts/v2/payments/{id}` (provisional, C9) | R5 |
| `capture(paymentId, {reference, amount})`, `reverse(paymentId, {reference, amount, reason})`, `refund(captureOrSaleId, {reference, amount})` | `capture($paymentId, $reference, $amount)`, `reverse($paymentId, $reference, $amount, $reason)`, `refund($captureOrSaleId, $reference, $amount)` | follow-on operations | §11 |
| `voidPayment(paymentId, {reference})` / `voidCapture(captureId, {reference})` / `voidRefund(refundId, {reference})` | `voidPayment($paymentId, $reference)` / `voidCapture($captureId, $reference)` / `voidRefund($refundId, $reference)` | void of a sale / capture / refund | §11 |
| `reverseByTransactionId(transactionId, {reference, amount, currency, reason})` | `reverseByTransactionId($transactionId, $reference, $amount, $currency, $reason)` | `POST /pts/v2/reversals` — timeout reversal (availability on VPC: to be confirmed, B4) | R10 |
| `searchByReference(reference, {from, to, limit})` | `searchByReference($reference, ['from' => …, 'to' => …, 'limit' => …])` | `POST /tss/v2/searches` (default: yesterday to tomorrow, 10) | R10 |
| `getTransaction(id)` | `getTransaction($id)` | `GET /tss/v2/transactions/{id}` | R9, R10 |
| `updatePaymentInstrument(customerId, piId, {expirationMonth, expirationYear, billTo, isDefault})` | `updatePaymentInstrument($customerId, $piId, $month, $year, $billTo, $isDefault)` | payment instrument `PATCH`: new expiry date, address, default card | R1, R7 |
| `getInstrumentIdentifier(id)` / `deleteInstrumentIdentifier(id)` | `getInstrumentIdentifier($id)` / `deleteInstrumentIdentifier($id)` | `/tms/v1/instrumentidentifiers/{id}` (the result has `networkTransactionId` — the card's TMS NTID; deletion removes the card for **every** customer, prefer `deletePaymentInstrument`) | R5, R7 |
| `deleteCustomer(customerId)`, `deletePaymentInstrument(customerId, paymentInstrumentId)` | `deleteCustomer($customerId)`, `deletePaymentInstrument($customerId, $paymentInstrumentId)` | deletion of the customer / of one card | R7 |
| `confirmUcResult(jwt, {expectedReference, expectedAmount, expectedCurrency})` | `confirmUcResult($jwt, $expectedReference, $expectedAmount, $expectedCurrency)` | UC result: signature + `GET /tss/v2/transactions/{id}` + binding to reference, amount, currency | R9 |
| `verifyWebhook(header, rawBody, {key, keyId})` | `verifyWebhook($header, $rawBody, $keyB64, $keyId)` | the `v-c-signature` signature on the raw body | S7 |
| `decryptWebhook(rawBody, privateKey, {passphrase, requireEncrypted})` | `decryptWebhook($rawBody, $privateKeyPem, $passphrase, $requireEncrypted, &$encrypted)` | MLE notification (JWE RSA-OAEP-256 + A256GCM); PHP: phpseclib 3 (comes with the official SDK) | A1 |

Every id in a path is URL-encoded. `baseUrl` accepts `https` only (the simulator on `http://localhost` / `127.0.0.1`).

### The CIT/MIT table

The values come from `policy.storedCredentials` of the rule file (the same for the SDK, `check-request.js` and the simulator). `php examples.php kinds` prints them; in Node.js: `MIT_KINDS`, `mitCell(kind, brand)`.

| `kind` (`chargeStoredCard`) | Visa: commerceIndicator / reason | Mastercard: commerceIndicator / reason |
|---|---|---|
| `recurring` (fixed amount) | `recurring` / — | `recurring` / 7 |
| `recurring_variable` | `recurring` / — | `recurring` / 8 |
| `installment` | `install` / — | `install` / 9 |
| `unscheduled` (UCOF) | `internet` / — | `internet` / 10 |
| `topup` | `internet` / — | `internet` / 10 |
| `resubmission` (only after DECLINED) | `internet` / 1 | `internet` / 1 |
| `delayed` | `internet` / 2 | `internet` / 2 |
| `reauthorization` | `internet` / 3 | not supported |
| `noshow` | `internet` / 4 | `internet` / 4 |
| `incremental` | use `incrementalAuthorization` | use `incrementalAuthorization` |
| `partial_shipment` | not supported | not supported (reason 6, C18) |

The network is required (`cardType` `"001"`/`"002"` from `tokens.cardType`, or `brand`). `previousTransactionId` = the 15-digit NTID of the transaction that **established the agreement**, stored per agreement. An unsupported cell, a missing network or a wrong NTID give `VbRuleError` / `VbRuleException` — nothing is sent. The result has `kind`, `scenario`, `paid`, `mustRelease` and, where relevant, `pendingConfirmation` (references still to be confirmed by the bank).

**Agreement (option `agreement`)** on `authorize`, `validateChallenge`, `createUcPaySession`: `{ type: 'recurring' | 'installment' | 'unscheduled', variableAmount?: true }` + `cardType`/`brand`. The paid CIT **establishes** the agreement: `credentialStoredOnFile: true` without `storedCredentialUsed` (C4), `firstRecurringPayment` for recurring, Mastercard `reason` 7/8/9; `commerceIndicator` is left to payer authentication (C12). Store `result.agreement.networkTransactionId` (present only when `paid`) — it is the `previousTransactionId` of every MIT of the agreement. The values are provisional, to be confirmed by the bank.

### Results: `paid`, `state`, unknown status

- `paid` is the **only** delivery decision: `outcome.next === 'APPROVED'` with full 3-D Secure (and, for UC, matching reference + amount + currency). `mustReverse` = approved or in review without full 3DS (R4); `mustRelease` = `mustReverse`, a partial authorization or `AUTHORIZED_RISK_DECLINED`.
- `confirmUcResult().state` only says whether the transaction belongs to the order: `MATCHED`, `MISMATCH` (not this order's payment) or `PENDING` (not found yet — retry). A `MATCHED` payment can be unpaid (declined, `mustReverse`): fulfil **only** when `paid === true`, never on `state`. For a 0.00 Account Verification use `verified`.
- **Unknown status** = every HTTP 5xx, a timeout or transport error, or a `reason` in `UNKNOWN_REASONS` (`DUPLICATE_REQUEST` — also on HTTP 400 —, `SYSTEM_ERROR`, `SERVER_TIMEOUT`, `SERVICE_TIMEOUT`, `PROCESSOR_TIMEOUT`). The SDK throws an error with `unknownStatus: true` / `UnknownStatusException`, carrying `reference` and, for an authorization, `transactionId`. A status the SDK does not know gives `outcome.next === 'UNKNOWN'`. In both cases: do not resend; `searchByReference` (R10); if the authorization does not show up after 60 s, `reverseByTransactionId`.

### Rules 1.2.1 and the 3-D Secure policy

- The file `rules/vb-cybersource-rules.json` 1.2.1 (engine 2) is the same in the Node.js SDK, the PHP SDK, the AI kit and the simulator. Version and fingerprint: Node.js `RULES_VERSION` / `RULES_HASH`, PHP `Rules::version()` / `Rules::hash()`; both appear in every `VbRuleError` / `VbRuleException`.
- `policy.threeDs.acceptAttempted` = `true` (provisional, to be confirmed by the bank): an *attempted* authentication (Visa ECI 06, Mastercard ECI 01) **with a cryptogram** counts as full 3-D Secure (R4), so it can give `paid`; with `false`, the same authorization gives `mustReverse`. Without a cryptogram, or with a downgraded Mastercard (`ucafCollectionIndicator` 0), it is never full 3DS.
- The `acceptAttempted` option (Node.js `new VbCyberSource({ acceptAttempted })`, PHP the `$acceptAttempted` argument of `create()` / the constructor) overrides the file value for rule validation **and** for `evidence` / `paid`. Change it only on the bank's written decision.
- `strictRules` (default `true`): with `false`, a request that breaks a rule is sent anyway, without a warning — debugging only, never in production.
- Other options: `currency` (default `MDL`); the built-in transport has a 65 s timeout (Node.js `timeoutMs`) and refuses `environment: 'production'`.

### Errors

- `VbRuleError` / `VbRuleException`: the request breaks a rule; **it was not sent**. `violations` lists the rule IDs, `scenario` the scenario (several joined with `+`), `rulesVersion` + `rulesHash` the rule-set version.
- An error with `unknownStatus: true` / `UnknownStatusException`: the status is **unknown** (definition above).
- `Error` / `InvalidArgumentException`: invalid input (amount, `transactionId`, reference, `baseUrl`, a webhook key that is not Base64) — nothing sent.
- `Error` / `RuntimeException`: `confirmUcResult` with a result that has no transaction id; PHP `decryptWebhook` without phpseclib 3.

### PHP scope

The PHP SDK covers every method above, with the same rules and the same results as Node.js, plus the CLI examples (`exemple/php/examples.php`). The kit's reference state machine (pages, 3DS challenge, Unified Checkout, capture, MIT ledger, reconciliation) is implemented **in Node.js only** (`exemple/node`). A PHP shop reaches READY with its own flow built on these methods; the simulator checks the flow, not the language.

### What was verified

- The builders reproduce byte for byte the canonical requests P01, P03, P05, P06, P08, P12, P13, P16–P24, P27–P40 in both programming languages (Node.js and PHP); P10/P11 are checked by the rules; JWT claim names = P26; P02, P04, P07, P09, P15 are responses / examples; P14 and P25 have no builder. The JWT v2 is identical byte for byte between Node.js and PHP.
- The same parity vectors (`parity-vectors.json`: amounts, Visa/Mastercard ECI, interpretation, decisions, paths, rules) and the same CIT/MIT matrix in both test suites; every `payloads/negative` case is rejected.
- Every VB request passes unchanged through the models of the official PHP SDK (0.0.77); every route of the PHP `OfficialSdkTransport` matches an official method (reflection check).
- The complete flow (session, Account Verification, 3-D Secure, capture, refund, default card, agreement + MIT + void, timeout reversal, deletion, search) passes through the VB simulator with the built-in transport **and** with the official CyberSource SDKs (Node.js 0.0.84, PHP 0.0.77).

### Tests

| Command (from `sdk/node` / `sdk/php`) | What it checks | Current result |
|---|---|---|
| `node --test` | builders P01–P40, rules, parity vectors, the CIT/MIT matrix, the facade, the official transport (pinned routes) | 48/48 |
| `php tests/run.php` | the same parity vectors and the same matrix in PHP; with `VB_OFFICIAL_SDK_AUTOLOAD` also the webhook JWE decryption | 35 passed |
| `VB_OFFICIAL_SDK_AUTOLOAD=/path/vendor/autoload.php php -d extension=gmp tests/official-models.php` | the VB requests through the official PHP SDK models; the `OfficialSdkTransport` routes (reflection) | 41 requests, 20 routes |
| `php tests/simulator.php` (variables above, simulator running) | the complete flow against the VB simulator | — |

```powershell
cd sdk\node; node --test
cd ..\php; php tests/run.php
$env:VB_OFFICIAL_SDK_AUTOLOAD='C:\path\vendor\autoload.php'; php -d extension=gmp tests/official-models.php
```

### Limits

MLE is applied only through the official transport, and the simulator cannot verify it; MLE and real issuer behavior are verified in CyberSource TEST. Values marked "provisional" (attempted ECI, the CIT/MIT table, webhook MLE, `/pts/v2/reversals`) are to be confirmed by the bank. Keys and passwords are read from environment variables or a secret vault, never from code.

---

## Русский

### Что это

SDK Victoriabank — это **слой правил Victoriabank поверх CyberSource REST**. Для каждой операции он:

1. формирует канонический запрос (такой же, как в `exemple/payloads`, P01–P40);
2. проверяет его по правилам R1–R13 **до отправки** — запрос, нарушающий правило, не отправляется (`VbRuleError` / `VbRuleException`);
3. отправляет его через *транспорт*;
4. возвращает исходный ответ CyberSource (`data`) вместе с интерпретацией: следующий шаг (`outcome.next`), подтверждение 3-D Secure (`evidence`), решение (`paid`, `mustReverse`, `mustRelease`), токены (`tokens`), идентификатор транзакции мерчанта (`transactionId`).

SDK **не заменяет** официальный SDK CyberSource: для сертификации в CyberSource TEST и в продуктивной среде он использует его как транспорт, поэтому подпись JWT v2 и шифрование MLE — официальные.

| Транспорт | Когда | Что делает |
|---|---|---|
| Встроенный (`CybsClient` / `Client`) | симулятор VB, отладка | JWT v2 (HS256), без MLE; отказывает для `production` |
| `OfficialSdkTransport` | **CyberSource TEST (сертификация) и продуктивная среда** | официальный SDK CyberSource: JWT v2 + MLE согласно настройкам учётной записи; отказывает для продуктивной среды без MLE |

MLE (предварительно, подлежит подтверждению банком): обязательно уже сейчас там, где этого требует CyberSource (веб-хуки событий платежей / Unified Checkout, учётные данные network token); для остальных API — обязательно с крайнего срока CyberSource в марте 2027 года. Банк **настоятельно рекомендует** проходить сертификацию в CyberSource TEST с `OfficialSdkTransport` + MLE — с той же конфигурацией, что и в продуктивной среде.

### Установка

**Node.js** — в **вашем** проекте (не внутри `sdk/node` комплекта):

```bash
npm install ./vb-kit/sdk/node                  # SDK Victoriabank (0 зависимостей)
npm install cybersource-rest-client@0.0.84     # официальный транспорт (TEST с MLE, продуктивная среда)
```

`examples/quickstart.js`, запущенный из комплекта, использует встроенный транспорт; с `CYBS_TRANSPORT=official` (и `CYBS_RUN_ENVIRONMENT`, по умолчанию `apitest.cybersource.com`) — официальный SDK, загружаемый из `CYBS_SDK_PATH`, пути к `node_modules/cybersource-rest-client` вашего проекта (без него: `require('cybersource-rest-client')`). `npm audit` сообщает о 2 транзитивных уязвимостях уровня «high» в официальном пакете (`node-forge`, GHSA-86w9-cpqp-85rv); у SDK Victoriabank нет зависимостей. Не запускайте `npm audit fix --force` (он понизит `cybersource-rest-client` до 0.0.26); следите за новыми версиями официального пакета.

**PHP** — пакета нет на Packagist; добавьте его как репозиторий типа `path` в `composer.json`:

```json
{
  "repositories": [{ "type": "path", "url": "./vb-kit/sdk/php" }],
  "require": {
    "victoriabank/cybersource-sdk": "*",
    "cybersource/rest-client-php": "~0.0.77"
  }
}
```

```bash
composer update victoriabank/cybersource-sdk cybersource/rest-client-php
```

Официальному SDK для PHP нужно `ext-gmp`: включите `extension=gmp` в `php.ini` (в XAMPP оно отключено) или запускайте с `php -d extension=gmp …`. Он же приносит phpseclib 3, необходимый для `decryptWebhook`.

Без Composer (OpenCart, собственные магазины): `require 'vb-kit/sdk/php/autoload.php';` — достаточно для встроенного транспорта. Официальному транспорту нужен официальный SDK, установленный через Composer (`vendor/autoload.php`).

**Симулятор VB с `--tls`** (собственный сертификат, `sim-cert.pem`): Node.js — `NODE_EXTRA_CA_CERTS=sim-cert.pem`; PHP — `Jwt::$caBundle = '/путь/sim-cert.pem';` или переменная окружения `VB_CA_BUNDLE`. SDK для PHP загружает открытый ключ capture context через собственный cURL, поэтому параметра CAINFO официального SDK недостаточно. SDK Victoriabank никогда не отключает проверку TLS. Исключение только для теста: `tests/simulator.php` с `VB_TRANSPORT=official` запускает клиент официального SDK с `setSSLVerification(false)` для самоподписанного сертификата симулятора — не переносите эту строку в рабочий код.

### Пример (Node.js)

```js
const { VbCyberSource, OfficialSdkTransport, references } = require('vb-cybersource-sdk');

// симулятор / отладка: встроенный транспорт
const vb = new VbCyberSource({ merchantId, keyId, sharedSecret, baseUrl: 'http://127.0.0.1:8770' });
// CyberSource TEST (сертификация) и продуктивная среда: официальный транспорт (JWT v2 + MLE)
// const vb = new VbCyberSource({ transport: new OfficialSdkTransport(require('cybersource-rest-client'), merchantConfig) });

const ref = references('ORD-1001');
const av = await vb.verifyCard({ reference: `AV-${ref.order}`, transientToken, billTo });           // 0,00 + TOKEN_CREATE
const { customerId, paymentInstrumentId, cardType } = av.tokens;
const setup = await vb.setupPayerAuth({ reference: ref.order, customerId });
const auth = await vb.authorize({ reference: ref.attempt(1), paymentInstrumentId, amount: '1250.00', billTo, device,
  referenceId: setup.setup.referenceId, returnUrl: 'https://shop.example.md/payments/3ds/return' });
// сохраните auth.transactionId вместе с попыткой (отмена при тайм-ауте)
if (auth.outcome.next === 'CHALLENGE') { /* покажите challenge в iframe, затем vb.validateChallenge(...) */ }
if (auth.mustReverse) await vb.reverse(auth.data.id, { reference: ref.reversal(1), amount: '1250.00' });    // R4
if (auth.paid) { /* доставка / списание */ }
```

### Пример (PHP)

```php
use Victoriabank\CyberSource\{VbCyberSource, OfficialSdkTransport, References};

$vb  = VbCyberSource::create($merchantId, $keyId, $sharedSecret, baseUrl: 'http://127.0.0.1:8770');   // симулятор / отладка
// CyberSource TEST (сертификация) и продуктивная среда: \CyberSource\ApiClient с MerchantConfiguration (JWT + MLE):
// $vb = new VbCyberSource(new OfficialSdkTransport($apiClient));
$ref = new References('ORD-1001');
$av  = $vb->verifyCard("AV-{$ref->order}", $transientToken, $billTo);
['customerId' => $customerId, 'paymentInstrumentId' => $pi] = $av['tokens'];
$setup = $vb->setupPayerAuth($ref->order, $customerId);
$auth  = $vb->authorize($ref->attempt(1), null, $pi, '1250.00', $billTo, $device, $setup['setup']['referenceId'],
    'https://shop.example.md/payments/3ds/return');
if ($auth['mustReverse']) { $vb->reverse($auth['data']['id'], $ref->reversal(1), '1250.00'); }
if ($auth['paid']) { /* доставка / списание */ }
```

Полные примеры: `sdk/node/examples/quickstart.js`, `sdk/php/tests/simulator.php`, CLI для PHP `exemple/php/examples.php` (`session`, `av`, `kinds`, `mit <kind>`, `search`, `delete`); эталонная интеграция `exemple/node` использует SDK для Node.js.

Запуск с симулятором (bash / PowerShell):

```bash
CYBS_BASE_URL=http://127.0.0.1:8770 CYBS_MERCHANT_ID=vb_sim_merchant CYBS_KEY_ID=<key id> CYBS_SHARED_SECRET=<secret> php tests/simulator.php
# через официальный SDK для PHP (симулятор запущен с --tls):
VB_TRANSPORT=official VB_OFFICIAL_SDK_AUTOLOAD=/путь/vendor/autoload.php VB_CA_BUNDLE=/путь/sim-cert.pem CYBS_BASE_URL=https://127.0.0.1:8770 \
  CYBS_MERCHANT_ID=vb_sim_merchant CYBS_KEY_ID=<key id> CYBS_SHARED_SECRET=<secret> php -d extension=gmp tests/simulator.php
```

```powershell
$env:CYBS_BASE_URL='http://127.0.0.1:8770'; $env:CYBS_MERCHANT_ID='vb_sim_merchant'; $env:CYBS_KEY_ID='<key id>'; $env:CYBS_SHARED_SECRET='<secret>'
php tests/simulator.php
$env:VB_TRANSPORT='official'; $env:VB_OFFICIAL_SDK_AUTOLOAD='C:\путь\vendor\autoload.php'; $env:VB_CA_BUNDLE='C:\путь\sim-cert.pem'; $env:CYBS_BASE_URL='https://127.0.0.1:8770'
php -d extension=gmp tests/simulator.php
```

### Методы

Одни и те же методы и одни и те же результаты в обеих реализациях. Node.js принимает объект параметров; PHP — позиционные аргументы в порядке колонки PHP (именованные аргументы PHP 8 работают). Каждый сетевой метод возвращает `{ httpStatus, data, … }` / `['httpStatus' => …, 'data' => …]`.

| Node.js | PHP | Операция CyberSource | Правило |
|---|---|---|---|
| `createMicroformSession({targetOrigin, allowedCardNetworks})` | `createMicroformSession($targetOrigin, $allowedCardNetworks)` | `POST /microform/v2/sessions` + проверка RS256 | R1, R13 |
| `createUcAddCardSession({targetOrigin, reference, billTo, existingCustomerId, locale})` | `createUcAddCardSession($targetOrigin, $reference, $billTo, $existingCustomerId, $locale)` | `POST /uc/v1/sessions` (сессия 1: 0,00 + токен) | R2, R6, R12 |
| `createUcPaySession({targetOrigin, reference, customerId, amount, billTo, locale, agreement, cardType, brand})` | `createUcPaySession($targetOrigin, $reference, $customerId, $amount, $billTo, $locale, $agreement, $cardType, $brand)` | `POST /uc/v1/sessions` (сессия 2: оплата токеном, 3DS) | R3, R4, R12 |
| `createUcDirectSession({targetOrigin, reference, amount, billTo, locale, allowedPaymentTypes, capture})` | `createUcDirectSession($targetOrigin, $reference, $amount, $billTo, $locale, $allowedPaymentTypes, $capture)` | `POST /uc/v1/sessions` (прямая оплата, сценарий A: сумма > 0, PANENTRY / APPLEPAY / GOOGLEPAY, 3DS, ничего не сохраняется — `P40`; `capture` = продажа, отменяется через `voidPayment`) | R2, R4, R12 |
| `verifyTransientToken(token, {jwk})` | `verifyTransientToken($token, $jwk)` | проверка RS256 transient token | R9 |
| `verifyCard({reference, transientToken, billTo, existingCustomerId, transactionId})` | `verifyCard($reference, $transientToken, $billTo, $existingCustomerId, $transactionId)` | Account Verification 0,00 + `TOKEN_CREATE` | R2, R6 |
| `setupPayerAuth({reference, customerId})` | `setupPayerAuth($reference, $customerId)` | `POST /risk/v1/authentication-setups` | R4 |
| `authorize({reference, customerId, paymentInstrumentId, amount, billTo, device, referenceId, returnUrl, transactionId, agreement, cardType, brand})` | `authorize($reference, $customerId, $pi, $amount, $billTo, $device, $referenceId, $returnUrl, $transactionId, $agreement, $cardType, $brand)` | CIT с токеном + совмещённый 3-D Secure | R3, R4 |
| `validateChallenge({reference, customerId, paymentInstrumentId, amount, authenticationTransactionId, transactionId, agreement, cardType, brand})` | `validateChallenge($reference, $customerId, $pi, $amount, $authenticationTransactionId, $transactionId, $agreement, $cardType, $brand)` | проверка 3DS + авторизация | R3, R4 |
| `chargeStoredCard({reference, customerId, paymentInstrumentId, amount, cardType, brand, kind, previousTransactionId, transactionId, capture})` | `chargeStoredCard($reference, $customerId, $pi, $amount, $cardType, $kind, $previousTransactionId, $brand, $transactionId, $capture)` | MIT по таблице CIT/MIT (ниже) | R5 |
| `incrementalAuthorization(paymentId, {reference, additionalAmount, currency, transactionId})` | `incrementalAuthorization($paymentId, $reference, $additionalAmount, $currency, $transactionId)` | `PATCH /pts/v2/payments/{id}` (предварительно, C9) | R5 |
| `capture(paymentId, {reference, amount})`, `reverse(paymentId, {reference, amount, reason})`, `refund(captureOrSaleId, {reference, amount})` | `capture($paymentId, $reference, $amount)`, `reverse($paymentId, $reference, $amount, $reason)`, `refund($captureOrSaleId, $reference, $amount)` | последующие операции | §11 |
| `voidPayment(paymentId, {reference})` / `voidCapture(captureId, {reference})` / `voidRefund(refundId, {reference})` | `voidPayment($paymentId, $reference)` / `voidCapture($captureId, $reference)` / `voidRefund($refundId, $reference)` | void продажи / списания / возврата | §11 |
| `reverseByTransactionId(transactionId, {reference, amount, currency, reason})` | `reverseByTransactionId($transactionId, $reference, $amount, $currency, $reason)` | `POST /pts/v2/reversals` — отмена при тайм-ауте (доступность на VPC: подлежит подтверждению, B4) | R10 |
| `searchByReference(reference, {from, to, limit})` | `searchByReference($reference, ['from' => …, 'to' => …, 'limit' => …])` | `POST /tss/v2/searches` (по умолчанию: со вчера по завтра, 10) | R10 |
| `getTransaction(id)` | `getTransaction($id)` | `GET /tss/v2/transactions/{id}` | R9, R10 |
| `updatePaymentInstrument(customerId, piId, {expirationMonth, expirationYear, billTo, isDefault})` | `updatePaymentInstrument($customerId, $piId, $month, $year, $billTo, $isDefault)` | `PATCH` платёжного инструмента: новый срок действия, адрес, карта по умолчанию | R1, R7 |
| `getInstrumentIdentifier(id)` / `deleteInstrumentIdentifier(id)` | `getInstrumentIdentifier($id)` / `deleteInstrumentIdentifier($id)` | `/tms/v1/instrumentidentifiers/{id}` (результат содержит `networkTransactionId` — NTID карты в TMS; удаление убирает карту у **всех** клиентов, предпочтительнее `deletePaymentInstrument`) | R5, R7 |
| `deleteCustomer(customerId)`, `deletePaymentInstrument(customerId, paymentInstrumentId)` | `deleteCustomer($customerId)`, `deletePaymentInstrument($customerId, $paymentInstrumentId)` | удаление клиента / одной карты | R7 |
| `confirmUcResult(jwt, {expectedReference, expectedAmount, expectedCurrency})` | `confirmUcResult($jwt, $expectedReference, $expectedAmount, $expectedCurrency)` | результат UC: подпись + `GET /tss/v2/transactions/{id}` + привязка к референсу, сумме, валюте | R9 |
| `verifyWebhook(header, rawBody, {key, keyId})` | `verifyWebhook($header, $rawBody, $keyB64, $keyId)` | подпись `v-c-signature` по исходному телу | S7 |
| `decryptWebhook(rawBody, privateKey, {passphrase, requireEncrypted})` | `decryptWebhook($rawBody, $privateKeyPem, $passphrase, $requireEncrypted, &$encrypted)` | уведомление MLE (JWE RSA-OAEP-256 + A256GCM); PHP: phpseclib 3 (поставляется с официальным SDK) | A1 |

Все идентификаторы в путях URL-кодируются. `baseUrl` принимает только `https` (симулятор — на `http://localhost` / `127.0.0.1`).

### Таблица CIT/MIT

Значения берутся из `policy.storedCredentials` файла правил (общего для SDK, `check-request.js` и симулятора). `php examples.php kinds` выводит их; в Node.js: `MIT_KINDS`, `mitCell(kind, brand)`.

| `kind` (`chargeStoredCard`) | Visa: commerceIndicator / reason | Mastercard: commerceIndicator / reason |
|---|---|---|
| `recurring` (фиксированная сумма) | `recurring` / — | `recurring` / 7 |
| `recurring_variable` | `recurring` / — | `recurring` / 8 |
| `installment` | `install` / — | `install` / 9 |
| `unscheduled` (UCOF) | `internet` / — | `internet` / 10 |
| `topup` | `internet` / — | `internet` / 10 |
| `resubmission` (только после DECLINED) | `internet` / 1 | `internet` / 1 |
| `delayed` | `internet` / 2 | `internet` / 2 |
| `reauthorization` | `internet` / 3 | не поддерживается |
| `noshow` | `internet` / 4 | `internet` / 4 |
| `incremental` | используйте `incrementalAuthorization` | используйте `incrementalAuthorization` |
| `partial_shipment` | не поддерживается | не поддерживается (reason 6, C18) |

Сеть обязательна (`cardType` `"001"`/`"002"` из `tokens.cardType` или `brand`). `previousTransactionId` — 15-значный NTID транзакции, которая **установила соглашение**, хранится для каждого соглашения. Неподдерживаемая ячейка, отсутствующая сеть или неверный NTID дают `VbRuleError` / `VbRuleException` — ничего не отправляется. Результат содержит `kind`, `scenario`, `paid`, `mustRelease` и, где применимо, `pendingConfirmation` (ссылки, ещё подлежащие подтверждению банком).

**Соглашение (параметр `agreement`)** в `authorize`, `validateChallenge`, `createUcPaySession`: `{ type: 'recurring' | 'installment' | 'unscheduled', variableAmount?: true }` + `cardType`/`brand`. Оплаченный CIT **устанавливает** соглашение: `credentialStoredOnFile: true` без `storedCredentialUsed` (C4), `firstRecurringPayment` для рекуррентного, Mastercard `reason` 7/8/9; `commerceIndicator` определяется аутентификацией плательщика (C12). Сохраните `result.agreement.networkTransactionId` (присутствует только при `paid`) — это `previousTransactionId` каждого MIT соглашения. Значения предварительные, подлежат подтверждению банком.

### Результаты: `paid`, `state`, неизвестный статус

- `paid` — **единственное** решение о доставке: `outcome.next === 'APPROVED'` с полным 3-D Secure (а для UC — совпадение референса, суммы и валюты). `mustReverse` = одобрено или на проверке без полного 3DS (R4); `mustRelease` = `mustReverse`, частичная авторизация или `AUTHORIZED_RISK_DECLINED`.
- `confirmUcResult().state` говорит лишь о том, относится ли транзакция к заказу: `MATCHED`, `MISMATCH` (это не платёж данного заказа) или `PENDING` (ещё не найдена — повторите). Платёж `MATCHED` может быть не оплачен (отклонён, `mustReverse`): выполняйте заказ **только** при `paid === true`, никогда по `state`. Для Account Verification 0,00 используйте `verified`.
- **Неизвестный статус** = любой HTTP 5xx, тайм-аут или ошибка транспорта либо `reason` из `UNKNOWN_REASONS` (`DUPLICATE_REQUEST` — в том числе при HTTP 400 —, `SYSTEM_ERROR`, `SERVER_TIMEOUT`, `SERVICE_TIMEOUT`, `PROCESSOR_TIMEOUT`). SDK выбрасывает ошибку с `unknownStatus: true` / `UnknownStatusException` с `reference` и, для авторизации, `transactionId`. Статус, неизвестный SDK, даёт `outcome.next === 'UNKNOWN'`. В обоих случаях: не отправляйте повторно; `searchByReference` (R10); если авторизация не появилась через 60 с — `reverseByTransactionId`.

### Правила 1.2.1 и политика 3-D Secure

- Файл `rules/vb-cybersource-rules.json` 1.2.1 (engine 2) один и тот же в SDK для Node.js, SDK для PHP, AI-комплекте и симуляторе. Версия и отпечаток: Node.js `RULES_VERSION` / `RULES_HASH`, PHP `Rules::version()` / `Rules::hash()`; оба указываются в каждой `VbRuleError` / `VbRuleException`.
- `policy.threeDs.acceptAttempted` = `true` (предварительно, подлежит подтверждению банком): аутентификация *attempted* (Visa ECI 06, Mastercard ECI 01) **с криптограммой** считается полным 3-D Secure (R4) и может дать `paid`; при `false` та же авторизация даёт `mustReverse`. Без криптограммы или при понижении Mastercard (`ucafCollectionIndicator` 0) полного 3DS нет никогда.
- Параметр `acceptAttempted` (Node.js `new VbCyberSource({ acceptAttempted })`, PHP аргумент `$acceptAttempted` в `create()` / конструкторе) переопределяет значение из файла для проверки правил **и** для `evidence` / `paid`. Меняйте его только по письменному решению банка.
- `strictRules` (по умолчанию `true`): при `false` запрос, нарушающий правило, всё равно отправляется, без предупреждения — только для отладки, никогда в продуктивной среде.
- Прочие параметры: `currency` (по умолчанию `MDL`); у встроенного транспорта тайм-аут 65 с (Node.js `timeoutMs`), и он отказывает для `environment: 'production'`.

### Ошибки

- `VbRuleError` / `VbRuleException`: запрос нарушает правило; **он не был отправлен**. `violations` содержит ID правил, `scenario` — сценарий (несколько — через `+`), `rulesVersion` + `rulesHash` — версию набора правил.
- Ошибка с `unknownStatus: true` / `UnknownStatusException`: статус **неизвестен** (определение выше).
- `Error` / `InvalidArgumentException`: неверные данные (сумма, `transactionId`, референс, `baseUrl`, ключ веб-хука не в Base64) — ничего не отправлено.
- `Error` / `RuntimeException`: `confirmUcResult` с результатом без ID транзакции; `decryptWebhook` в PHP без phpseclib 3.

### Область PHP

SDK для PHP охватывает все методы выше, с теми же правилами и теми же результатами, что и Node.js, плюс CLI-примеры (`exemple/php/examples.php`). Эталонный конечный автомат комплекта (страницы, challenge 3DS, Unified Checkout, списание, журнал MIT, сверка) реализован **только на Node.js** (`exemple/node`). Магазин на PHP достигает READY собственным сценарием, построенным на этих методах; симулятор проверяет сценарий, а не язык.

### Что проверено

- Построители побайтно воспроизводят канонические запросы P01, P03, P05, P06, P08, P12, P13, P16–P24, P27–P40 в обеих реализациях (Node.js и PHP); P10/P11 проверяются правилами; имена claims JWT = P26; P02, P04, P07, P09, P15 — ответы / примеры; у P14 и P25 нет построителя. JWT v2 побайтно совпадает в Node.js и PHP.
- Одни и те же векторы паритета (`parity-vectors.json`: суммы, ECI Visa/Mastercard, интерпретация, решения, пути, правила) и одна и та же матрица CIT/MIT в обоих наборах тестов; все случаи `payloads/negative` отклоняются.
- Все запросы VB без изменений проходят через модели официального SDK для PHP (0.0.77); все маршруты `OfficialSdkTransport` для PHP соответствуют официальным методам (проверка через рефлексию).
- Полный сценарий (сессия, Account Verification, 3-D Secure, списание, возврат, карта по умолчанию, соглашение + MIT + void, отмена при тайм-ауте, удаление, поиск) проходит через симулятор VB со встроенным транспортом **и** с официальными SDK CyberSource (Node.js 0.0.84, PHP 0.0.77).

### Тесты

| Команда (из `sdk/node` / `sdk/php`) | Что проверяет | Текущий результат |
|---|---|---|
| `node --test` | построители P01–P40, правила, векторы паритета, матрица CIT/MIT, фасад, официальный транспорт (зафиксированные маршруты) | 48/48 |
| `php tests/run.php` | те же векторы паритета и та же матрица на PHP; с `VB_OFFICIAL_SDK_AUTOLOAD` также расшифровка JWE веб-хуков | 35 passed |
| `VB_OFFICIAL_SDK_AUTOLOAD=/путь/vendor/autoload.php php -d extension=gmp tests/official-models.php` | запросы VB через модели официального SDK для PHP; маршруты `OfficialSdkTransport` (рефлексия) | 41 запрос, 20 маршрутов |
| `php tests/simulator.php` (переменные выше, симулятор запущен) | полный сценарий с симулятором VB | — |

```powershell
cd sdk\node; node --test
cd ..\php; php tests/run.php
$env:VB_OFFICIAL_SDK_AUTOLOAD='C:\путь\vendor\autoload.php'; php -d extension=gmp tests/official-models.php
```

### Ограничения

MLE применяется только через официальный транспорт, и симулятор не может его проверить; MLE и реальное поведение эмитентов проверяются в CyberSource TEST. Значения с пометкой «предварительно» (ECI attempted, таблица CIT/MIT, MLE веб-хуков, `/pts/v2/reversals`) подлежат подтверждению банком. Ключи и пароли берутся из переменных окружения или хранилища секретов, никогда из кода.
