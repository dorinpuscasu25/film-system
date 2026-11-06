# Filmoteca — trimiterea la App Store (iOS 1.0)

Tot ce trebuie completat în App Store Connect, în ordine. Textele sunt gata de copiat.
Pachetele de credite (In-App Purchases) sunt descrise separat, în [in-app-purchases/README.md](in-app-purchases/README.md).

---

## 1. iOS App Version 1.0 → Product Page Information

Selectorul de limbă e în dreapta sus („Romanian ⌄”). Completezi **Romanian**, apoi alegi **Russian** și
**English (U.S.)** și completezi din nou. Screenshot-urile încărcate la română se folosesc automat și la
celelalte limbi, dacă nu pui altele.

### App Previews and Screenshots

| Dispozitiv în ASC | Fișiere | Rezoluție |
|---|---|---|
| **iPhone with Dynamic Island (medium display)** — 6.1"/6.3" | `screenshots/iphone-6.3/01…05` (în ordine) | 1206 × 2622 |

| **iPad 13" Display** | `screenshots/ipad-13/ipad-01-acasa-2064x2752.png` | 2064 × 2752 |

Fiecare set merge în tab-ul lui: setul de iPhone în tab-ul iPhone, cel de iPad în tab-ul **iPad 13" display**.
Celelalte mărimi le generează Apple automat. iPhone Duo, Apple Watch și Header Asset sunt opționale.

### Promotional Text (max 170, se poate schimba oricând fără review)

**RO**
```
Cele mai noi filme și seriale din Moldova și România, într-un singur loc. Vizionează pe telefon, tabletă, web sau televizor.
```
**RU**
```
Новые фильмы и сериалы из Молдовы и Румынии в одном месте. Смотрите на телефоне, планшете, в браузере или на телевизоре.
```
**EN**
```
The newest films and series from Moldova and Romania in one place. Watch on your phone, tablet, the web or your TV.
```

### Description (max 4000)

**RO**
```
FILMOTECA.md este casa filmului moldovenesc și românesc. Lungmetraje, seriale, documentare și scurtmetraje — premiate la festivaluri sau abia lansate — toate într-o singură aplicație.

CE GĂSEȘTI
• Filme și seriale locale, multe dintre ele disponibile doar aici
• Titluri gratuite și titluri pe care le închiriezi sau le cumperi
• Top 10 al săptămânii, colecții tematice și căutare după gen, an sau țară
• Trailer, distribuție, echipă și recenzii pentru fiecare film

VIZIONARE FĂRĂ GRIJI
• Continui de unde ai rămas, pe orice dispozitiv
• Conectezi televizorul și vezi filmul pe ecranul mare
• Profiluri separate pentru fiecare membru al familiei, inclusiv profil pentru copii
• Aplicația e disponibilă în română, rusă și engleză

PORTOFELUL FILMOTECA
Plătești filmele din portofelul tău Filmoteca. 1 credit = 1 MDL, iar creditele nu expiră niciodată. În aplicație, creditele se cumpără în siguranță prin App Store.

Susții cinematografia locală cu fiecare film pe care îl vezi.
```

**RU**
```
FILMOTECA.md — дом молдавского и румынского кино. Полнометражные фильмы, сериалы, документальные и короткометражные картины — отмеченные на фестивалях и совсем новые — в одном приложении.

ЧТО ВНУТРИ
• Местные фильмы и сериалы, многие из которых доступны только здесь
• Бесплатные фильмы, а также фильмы для аренды и покупки
• Топ-10 недели, тематические подборки и поиск по жанру, году или стране
• Трейлер, актёры, съёмочная группа и отзывы для каждого фильма

СМОТРИТЕ БЕЗ ЗАБОТ
• Продолжайте просмотр с того места, где остановились, на любом устройстве
• Подключите телевизор и смотрите на большом экране
• Отдельные профили для каждого члена семьи, включая детский профиль
• Приложение доступно на румынском, русском и английском

КОШЕЛЁК FILMOTECA
Фильмы оплачиваются из вашего кошелька Filmoteca. 1 кредит = 1 MDL, кредиты никогда не сгорают. В приложении кредиты безопасно покупаются через App Store.

Каждый просмотр — это поддержка местного кино.
```

**EN**
```
FILMOTECA.md is the home of Moldovan and Romanian cinema. Feature films, series, documentaries and shorts — festival winners and brand-new releases — all in one app.

WHAT'S INSIDE
• Local films and series, many of them available only here
• Free titles, plus titles you can rent or buy
• This week's Top 10, curated collections and search by genre, year or country
• Trailer, cast, crew and reviews for every film

WATCH WITHOUT WORRY
• Pick up where you left off, on any device
• Connect your TV and watch on the big screen
• Separate profiles for everyone in the family, including a kids profile
• Available in Romanian, Russian and English

THE FILMOTECA WALLET
Films are paid for from your Filmoteca wallet. 1 credit = 1 MDL, and credits never expire. In the app, credits are bought securely through the App Store.

Every film you watch supports local cinema.
```

### Keywords (max 100, separate prin virgulă, fără spații)

| | |
|---|---|
| RO | `filme,seriale,cinema,moldovenesc,romanesc,documentare,scurtmetraje,streaming,online,film,Moldova` |
| RU | `фильмы,сериалы,кино,Молдова,молдавское,румынское,документальное,онлайн,стриминг,короткометражки` |
| EN | `movies,series,Moldova,Moldovan,Romanian,cinema,films,documentary,short films,streaming,watch` |

Nu repeta numele aplicației („Filmoteca”) — Apple îl indexează deja.

### URL-uri și restul

| Câmp | Valoare |
|---|---|
| Support URL | `https://filmoteca.md/contacte` |
| Marketing URL | `https://filmoteca.md` |
| Version | `1.0` |
| Copyright | `2026 609 FILM S.R.L.` |
| Routing App Coverage File | gol (doar pentru aplicații de navigație) |

### Build

„Add Build” → alegi build-ul încărcat din Xcode (Product → Archive → Distribute App → App Store Connect).
Build-ul apare după ~15–30 de minute de procesare. Dacă cere „Export Compliance”: aplicația folosește doar
criptare standard (HTTPS, FairPlay) → **None of the algorithms mentioned above** / exempt.

### In-App Purchases and Subscriptions

Apare după ce alegi build-ul. Adaugi **Wallet 100 MDL** și **Wallet 200 MDL** — la prima versiune se trimit
obligatoriu împreună.

### Game Center

Debifat.

---

## 2. App Review Information

**Sign-in required:** bifat, cu un **cont de test separat** (nu contul tău), creat pe filmoteca.md.
Pune-i câteva credite în portofel, ca reviewer-ul să poată deschide un film plătit fără să cumpere.

**Contact Information:** numele, telefonul și emailul cuiva care răspunde rapid (Apple sună rar, dar scrie).

**Notes:**
```
Filmoteca is a streaming service for Moldovan and Romanian films and series, operated by 609 FILM S.R.L.

DEMO ACCOUNT
The demo account below already has credits in its wallet, so paid titles can be opened without a purchase.

HOW TO TEST
1. Sign in with the demo account (Cont tab → Autentificare).
2. Home tab: open any title and tap "Vizionează" (Watch). Free titles are marked "GRATUIT".
3. Paid titles are paid from the in-app wallet: 1 credit = 1 MDL. Credits never expire.
4. To buy credits: Cont (Account) tab → "Alimentează" (Add funds) → choose a pack. Credits are sold only through In-App Purchase (consumable products md.filmoteca.wallet.100 and md.filmoteca.wallet.200).
5. "Conectează televizorul" (Connect TV) pairs the account with our Apple TV / smart TV app using a code; it is optional.

CONTENT RIGHTS
All titles are distributed under licence agreements held by 609 FILM S.R.L. We can provide the agreements on request.

ACCOUNT DELETION
Cont → Setări → "Șterge contul" deletes the account and its data from within the app.
```

⚠️ Paragraful **CONTENT RIGHTS** — lasă-l doar dacă e adevărat. Apple verifică drepturile pentru aplicațiile
de streaming (ghid 5.2.3); dacă ai contractele, e bine să atașezi unul la „Attachment”.

---

## 3. App Store Version Release

„Automatically release this version” e ok. Dacă vrei să alegi tu ziua lansării, pune „Manually release”.

---

## 4. În afara paginii versiunii (meniul din stânga)

| Pagină | Ce completezi |
|---|---|
| **App Information** | Name: `Filmoteca` · Subtitle (max 30): `Filme și seriale din Moldova` · Category: **Entertainment** · Content Rights: „contains third-party content” → **Yes, I have the rights** |
| **App Information → Age Rating** | Răspunzi la chestionar onest (filmele au N-15 / 18+ → probabil **16+** sau **18+**) |
| **App Privacy** | Privacy Policy URL: `https://filmoteca.md/page/politica-de-confidentialitate-filmoteca-md` · Data collected: **Email, Name** (Account), **Purchase history**, **Product interaction** (istoric vizionare), legate de identitate, fără tracking |
| **Pricing and Availability** | Price: **Free** · Availability: țările dorite |

Subtitle în rusă / engleză: `Фильмы и сериалы из Молдовы` / `Films & series from Moldova`.

---

## 5. Ordinea de trimitere

1. App Information, App Privacy, Pricing — completate.
2. Pachetele IAP — preț, Availability, localizări, screenshot de review.
3. Versiunea 1.0 — screenshot-uri, texte, build, IAP adăugate, App Review Information.
4. **Add for Review** (sus dreapta) → **Submit for Review**.

Platformele **macOS App** și **tvOS App** din stânga pot rămâne în „Prepare for Submission”; trimiți doar iOS.

---

Screenshot-urile se regenerează cu `screenshots/compose.swift`: capturi din Simulator + titlu deasupra.
