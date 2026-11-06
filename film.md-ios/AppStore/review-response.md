# Răspuns la respingerea 2.1 (Information Needed)

## Înainte de toate: deploy backend

Raportarea și blocarea recenziilor au nevoie de backend-ul nou. **Fă deploy și rulează migrarea înainte să filmezi și să retrimiți:**

```bash
php artisan migrate --force
```

Apoi trimite un **build nou** din Xcode (Archive → Distribute). Build-ul vechi nu are butoanele de raportare și blocare, iar Apple trebuie să le vadă.

---

## 1. Filmarea (o faci tu, pe un iPhone real)

**Setări:** iPhone cu ultimul iOS → Control Center → Screen Recording. Pornește înregistrarea **înainte** să deschizi aplicația.
Durata țintă: 2–4 minute. Nu e nevoie de sunet.

Scenariu, în ordine:

1. **Pornirea aplicației** de pe ecranul principal (intro → Acasă).
2. **Răsfoire:** scroll prin Acasă (Top 10, genuri), deschizi un gen, revii.
3. **Cont nou:** Cont → Autentificare → **Cont nou** → completezi nume, email, parolă → contul e creat.
   (Folosește un email de test, de ex. `review-demo+1@…`.)
4. **Logare:** Ieși din cont → Autentificare cu contul demo (cel din Notes).
5. **Conținut plătit:**
   - Cont → **Alimentează** → alegi pachetul de 100 de credite → fereastra Apple de plată (sandbox / TestFlight) → soldul crește.
   - Deschizi un film plătit → **Vizionează** → cumperi/închiriezi cu credite → pornește redarea, lași câteva secunde.
6. **Recenzii (conținut generat de utilizatori):**
   - Pe pagina unui film: **Scrie o recenzie** → publici.
   - Pe recenzia **altui** utilizator: **⋯ → Raportează recenzia → Conținut ofensator** → apare confirmarea, iar recenzia dispare.
   - **⋯ → Blochează utilizatorul → Blochează** → recenziile lui dispar.
     (Ca să ai o recenzie de la altcineva, scrie una înainte de filmare din al doilea cont de test.)
7. **Ștergerea contului:** Cont → Setări → **Șterge contul** → confirmi → aplicația revine la ecranul de vizitator.
   (Fă asta cu contul nou creat la pasul 3, nu cu contul demo.)

**Cum trimiți filmarea:** încarcă fișierul la App Review Information → **Attachment**. Dacă fișierul e prea mare,
urcă-l pe Google Drive sau YouTube (Unlisted) și pune linkul în Notes, în locul `<VIDEO LINK>`.

---

## 2. Textul pentru Notes (copiază tot, în engleză)

Înlocuiește `<VIDEO LINK>`, `<demo email>` și `<demo password>`.

```
SCREEN RECORDING
<VIDEO LINK> (also attached). Recorded on iPhone with the latest iOS. It shows: launch, browsing, account registration, login, buying credits with In-App Purchase, watching a paid film, writing a review, reporting a review, blocking a user, and deleting the account.

1. PURPOSE AND AUDIENCE
Filmoteca is a streaming service for Moldovan and Romanian cinema: feature films, series, documentaries and short films, many of which are not available on any other platform. It is aimed at viewers in Moldova, Romania and the Romanian- and Russian-speaking diaspora. It gives local filmmakers a legal, paid distribution channel and gives viewers one place to find and watch local films. The app is available in Romanian, Russian and English.

2. HOW TO USE THE APP
Demo account (has credits in its wallet, so paid titles open without a purchase):
Email: <demo email>
Password: <demo password>
- Home: featured titles, Top 10, genres. Tap a title, then "Vizionează" (Watch).
- Free titles are marked "GRATUIT"; paid titles are rented or bought with wallet credits (1 credit = 1 MDL, credits never expire).
- Buying credits: Cont (Account) tab -> "Alimentează" (Add funds) -> choose a pack. Credits are sold only through In-App Purchase (consumables md.filmoteca.wallet.100 and md.filmoteca.wallet.200), submitted with this version.
- Reviews: on a title page, "Scrie o recenzie" (Write a review). On anyone else's review, the "..." button offers "Raportează recenzia" (Report review, with a reason) and "Blochează utilizatorul" (Block user). A reported review is hidden for the reporter immediately; our moderators review every report within 24 hours, and a review reported by 3 users is hidden for everyone until reviewed. Blocking hides all reviews from that user for the blocker. Users accept the Terms of Use, which prohibit objectionable content, when they create an account.
- Account deletion: Cont -> Setări (Settings) -> "Șterge contul" (Delete account) permanently deletes the account and its data.
- "Conectează televizorul" (Connect TV) optionally pairs the account with our TV app using a code.

3. EXTERNAL SERVICES
- Bunny.net Stream: video hosting, streaming and FairPlay DRM.
- Cloudflare (CDN, R2 storage for images): content delivery.
- Apple In-App Purchase / StoreKit 2 with the App Store Server API: purchase of credits in the app.
- Our own backend (Laravel, PostgreSQL, Redis, Meilisearch search) at filmoteca.md: accounts and email/password sign-in, catalog, wallet, reviews and moderation.
- An SMTP email provider: account verification and password reset emails.
No AI services, no third-party sign-in, no ad networks (the short video ads before some films are sold and served by us directly).

4. REGIONAL DIFFERENCES
The app works the same in every region. The only difference: a few titles can be restricted to certain countries by their licence; in other countries they are not offered for playback. Ads shown before films can be targeted by country. Prices of films are the same everywhere.

5. CONTENT RIGHTS
All titles are distributed under licence agreements between 609 FILM S.R.L. (the operator of Filmoteca) and the rights holders. <A sample agreement is attached. / We can provide the agreements on request.>
```

⚠️ Ultimul paragraf: păstrează doar varianta adevărată. Dacă poți, atașează un contract de licență ca exemplu.
⚠️ La punctul 3, scoate „FairPlay DRM” dacă nu e activ pe producție, iar la email pune numele real al furnizorului, dacă vrei.

---

## 3. Răspunsul în Resolution Center

După ce actualizezi Notes și alegi build-ul nou, răspunde scurt la mesajul de respingere:

```
Hello, thank you for the review. We have added all the requested information to the App Review Information Notes, including a screen recording on a physical device. This build also adds reporting and blocking for user reviews. Thank you!
```

Apoi **Add for Review → Submit for Review**.
