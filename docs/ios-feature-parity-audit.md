# Audit paritate funcțională — Web vs. iOS

**Data:** 11 august 2026
**Scop:** ce există pe web (`film.md-client`) și în API (`film.md-admin-api`) dar lipsește din aplicația iOS (`film.md-ios`), plus oportunități native pentru a face aplicația competitivă.

**Metodă:** comparație între rutele API (`routes/api.php`), paginile și componentele web (`film.md-client/src`), și suprafața iOS (`APIClient.swift` — 30 de metode, `Views/`, `Features/`).

---

## Rezumat

Trei constatări principale:

1. **Două blocante certe de App Store** — ștergerea contului lipsește complet din tot sistemul (API, web, iOS), iar aplicația nu are pagini legale accesibile. Ambele produc respingere, independent de problema IAP documentată separat în [ios-in-app-purchase-audit.md](ios-in-app-purchase-audit.md).
2. **Playerul iOS e mult în urma celui web** — web are subtitrări, selecție calitate și viteză de redare; iOS are doar AirPlay. Pentru o platformă de filme, ăsta e decalajul cel mai vizibil pentru utilizator.
3. **Funcționalități întregi construite în backend nu sunt folosite de niciun client** — control parental, cupoane și sistemul de reclame VAST sunt complete server-side, dar nicio interfață nu le apelează. Efort deja plătit, venit nefructificat.

---

## Categoria 0 — Blocante App Store

Trebuie rezolvate înainte de orice submisie.

### 0.1 Ștergerea contului — **nu există nicăieri**

Verificat: nu există endpoint în API, nu există în web, nu există în iOS.

**Ghidul App Store 5.1.1(v)** cere ca orice aplicație care permite crearea unui cont să permită și **ștergerea contului din interiorul aplicației** — nu un link de contact, nu un email către suport, ci un flux funcțional în app.

Necesită:
- endpoint backend de ștergere (cu perioadă de grație și anonimizare, nu `DELETE` brutal — există entitlements, tranzacții și obligații contabile de păstrat)
- decizie: ce se întâmplă cu soldul rămas și cu filmele cumpărate (recomandare: confirmare explicită că se pierd, plus export prealabil)
- UI în `AccountView` → Setări
- adăugat și pe web, pentru consistență

> Aceasta e cea mai probabilă cauză de respingere după problema IAP.

### 0.2 Pagini legale inaccesibile în aplicație

iOS încarcă meniul de footer (`APIClient.footerMenu`) dar **nu are randare pentru pagini CMS** — nu există apel către `public/pages/{slug}`. Web are `CmsPage.tsx`, `ContactPage.tsx`, `PricingPolicyPage.tsx`.

Apple cere link funcțional către politica de confidențialitate și termeni. În plus, linkurile din meniu duc momentan în gol sau în browser extern.

### 0.3 Formularul de alimentare PayFilmoteca

Documentat separat în [ios-in-app-purchase-audit.md](ios-in-app-purchase-audit.md) §2. Respingere garantată sub 3.1.1.

---

## Categoria 1 — Paritate lipsă (web are, iOS nu)

| # | Funcționalitate | Web | iOS | Impact |
|---|---|---|---|---|
| 1.1 | **Subtitrări în player** | `VideoPlayer.tsx` — `textTracks`, panou dedicat | ❌ absent | 🔴 mare |
| 1.2 | **Selecție calitate** | `VideoPlayer.tsx` — panou `quality` | ❌ absent | 🔴 mare |
| 1.3 | **Viteză de redare** | `VideoPlayer.tsx` — panou `speed` | ❌ absent | 🟠 medie |
| 1.4 | **Recuperare parolă** | `AuthModal.tsx:328` + `auth/forgot-password` | ❌ absent | 🔴 mare |
| 1.5 | **Pagini CMS** | `CmsPage`, `ContactPage`, `PricingPolicyPage` | ❌ absent | 🔴 blocant |
| 1.6 | **Recomandări** | `session.ts:647` → `content/{id}/recommendations` | ❌ absent | 🟠 medie |
| 1.7 | **Heartbeat analytics** | `VideoPlayer.tsx:438` → `tracking/heartbeat` | ❌ absent | 🟠 medie |
| 1.8 | **Watch Party** | `WatchPartyPage.tsx` complet | ❌ absent | 🟡 mică |
| 1.9 | **Premiere countdown** | `PremiereCountdown.tsx` | model există, UI ❌ | 🟡 mică |
| 1.10 | **Status plată** | `PaymentStatusPage.tsx` | n/a (va fi IAP) | — |

**1.1–1.3 sunt cele mai importante.** `AVPlayer` suportă nativ subtitrări și tracks audio din HLS — efortul e mic, iar absența e foarte vizibilă pentru un serviciu de filme. Modelul iOS are deja `subtitleLocales` și `audioLocales` (`APIModels.swift:176`), deci datele ajung deja în app, doar nu sunt folosite.

**1.7** înseamnă că statisticile de vizionare de pe mobil sunt incomplete față de web — afectează raportările și decontările către deținătorii de drepturi.

---

## Categoria 2 — Backend gata, neconectat de niciun client

Funcționalități complet implementate server-side pe care **nici web-ul, nici iOS-ul nu le folosesc**. Verificate ca fiind componente orfane (neimportate nicăieri) sau endpoint-uri neapelate.

### 2.1 Control parental cu PIN 🔴

- API complet: `profiles/{profile}/parental/pin` (set / clear / unlock), `ParentalControlService`
- Web: `components/ParentalPinModal.tsx` există dar **nu e importat nicăieri**
- iOS: profilul are `is_kids`, dar niciun PIN

Practic, profilurile „kids" filtrează conținutul, dar copilul poate ieși din profil fără nicio barieră. Pentru o platformă de filme cu rating de vârstă, e o lipsă serioasă — și un argument de vânzare pentru familii.

### 2.2 Cupoane 🟠

- API: `coupons/preview`, `CouponService`, modelele `Coupon` + `CouponRedemption`
- Web: `components/CouponField.tsx` **orfan**
- iOS: absent

Instrumentul de marketing e construit dar nefolosit.

### 2.3 Sistem de reclame VAST 🔴 (venit)

- Backend complet: `AdsController` (`ads/vast`, `ads/track`, `ads/events`), `VastService`, `AdCampaign`, `AdCreative`, `AdTargetingRule`, `AdEvent`, agregate, plus webhook Bunny `ad-injection`
- **Niciun client nu îl apelează** — verificat pe web și pe iOS

Ai o infrastructură de monetizare prin publicitate complet funcțională, nefolosită. Pentru titlurile gratuite (`offer_type = free`) ar putea genera venit fără să afecteze vânzările.

### 2.4 Componente orfane pe web

`TrailerAutoplay.tsx`, `LanguageSwitcher.tsx` — scrise, neimportate. De curățat sau de conectat.

---

## Categoria 3 — Calitate și robustețe

### 3.1 Localizare inconsistentă 🔴

Aplicația iOS are texte **hardcodate în română** în majoritatea ecranelor, amestecate cu sistemul `app.t()`:

| Ecran | apeluri `app.t()` |
|---|---|
| `SearchView` | 29 |
| `HomeView` | 7 |
| `AccountView` | 4 |
| `RootView` | 4 |
| `LibraryView` | 3 |
| `ContentDetailView` | 3 |
| **`AuthView`** | **0** |
| **`PlayerView`** | **0** |
| **`ProfilePickerView`** | **0** |

Exemple: `"Autentificare"`, `"SOLD DISPONIBIL"`, `"Conectează televizorul"`, `"Episoade"`, `"Recenzii"`, `"Distribuie"`.

Web-ul e complet tradus prin `i18n/index.ts` (ro/ru/en). **Un utilizator rus sau englez are pe iOS o interfață în română**, deși își setează limba. Pentru Moldova, unde publicul rusofon e semnificativ, e o problemă reală de adopție — nu doar cosmetică.

### 3.2 Ce e deja la paritate ✅

Ca să fie clar ce nu trebuie refăcut: home curatoriat, catalog cu filtre, căutare, detalii conținut, seriale + episoade, recenzii (citire, trimitere, ștergere), favorite, bibliotecă, profiluri (creare/editare/ștergere), continue watching, autentificare + verificare email, schimbare date cont și parolă, comutare limbă, împărtășire film (`ShareLink`), asociere TV, AirPlay, DRM/Bunny Stream.

Structura de secțiuni din cont e identică cu web-ul (Filmele mele / Favorite / Portofel / Setări).

---

## Categoria 4 — Oportunități native iOS

Lucruri care nu există pe web prin natura platformei și care ar diferenția aplicația.

### Prioritate mare

| Funcționalitate | De ce |
|---|---|
| **Picture in Picture** | Așteptare standard pentru orice player video pe iOS |
| **Now Playing / lock screen** | `MPNowPlayingInfoCenter` — control din ecranul blocat, căști, mașină |
| **Notificări push** | Premiere, expirare rental, titluri noi. Momentan **zero** — nu există nici măcar capability |
| **Universal Links** | Link din email/site → deschide direct în app. Critic pentru campanii |

### Prioritate medie

| Funcționalitate | De ce |
|---|---|
| **Descărcare offline** | Cel mai cerut feature pentru filme cumpărate. Necesită FairPlay persistent — efort mare, impact mare |
| **Face ID / Touch ID** | La deschidere sau pentru deblocarea profilului cu PIN (§2.1) |
| **Widget „Continuă vizionarea"** | Vizibilitate pe ecranul principal |
| **Spotlight** | Filmele apar în căutarea sistemului |

### Prioritate mică

SharePlay (complementar Watch Party), Siri Shortcuts / App Intents, Handoff iPhone ↔ TV.

---

## Propunere de prioritizare

### Faza 0 — Deblocare submisie
1. Ștergere cont (backend + web + iOS)
2. Pagini CMS în app (termeni, confidențialitate, contact)
3. IAP conform [ios-in-app-purchase-audit.md](ios-in-app-purchase-audit.md)

### Faza 1 — Paritate esențială
4. Player: subtitrări, calitate, viteză
5. Recuperare parolă
6. Localizare completă (toate ecranele prin `app.t()`)
7. Heartbeat + recomandări

### Faza 2 — Funcționalități care există deja în backend
8. Control parental cu PIN (web + iOS)
9. Cupoane (web + iOS)
10. Reclame VAST pe conținut gratuit (decizie de business întâi)

### Faza 3 — Native „super app"
11. Picture in Picture + Now Playing
12. Notificări push
13. Universal Links
14. Descărcare offline
15. Widget + Spotlight + Face ID

### Faza 4 — Opțional
16. Watch Party pe iOS
17. Premiere countdown
18. SharePlay

---

## Întrebări de decis

| # | Întrebare |
|---|---|
| 1 | La ștergerea contului: ce se întâmplă cu soldul rămas și cu filmele cumpărate? |
| 2 | Reclamele VAST — vrem să le activăm? Pe ce tip de conținut? |
| 3 | Descărcarea offline intră în scop? (efort mare, dar e cel mai cerut feature) |
| 4 | Watch Party pe mobil merită, sau rămâne doar pe web? |
| 5 | Controlul parental — îl conectăm și pe web în același timp? |

---

## Observații laterale

- `film.md-ios/README.md` afirmă că alimentarea e redirecționată către site — **codul contrazice documentația**. De actualizat.
- Target-ul `FilmotecaTV` din proiectul iOS are 41 de linii în total (schelet din template). Aplicația TV reală pare a fi `film.md-tv` / `film.md-tv-web`, proiecte separate — neincluse în acest audit.
