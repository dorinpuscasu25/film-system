# Audit paritate funcțională — Web vs. iOS

**Data:** 11 august 2026 · **Re-verificat direct în cod:** 23 septembrie 2026
**Scop:** ce există pe web (`film.md-client`) și în API (`film.md-admin-api`) dar lipsește din aplicația iOS (`film.md-ios`), plus oportunități native pentru a face aplicația competitivă.

**Metodă:** comparație între rutele API (`routes/api.php`), paginile și componentele web (`film.md-client/src`), și suprafața iOS (`APIClient.swift`, `Views/`, `Features/`).

> **Notă la re-verificare:** între 11 august și 23 septembrie o parte din Categoria 0 și Categoria 1 a fost deja construită (nu doar documentul a stat pe loc). Secțiunile de mai jos sunt actualizate să reflecte codul de azi; ce a fost verificat ca *rezolvat* e marcat explicit ✅, restul rămâne valabil ca înainte.

---

## Rezumat

1. **Blocantul real rămas de App Store e doar IAP-ul** — ștergerea contului ✅ și paginile legale ✅ există acum peste tot (API + web + iOS). Rămâne doar formularul PayFilmoteca deschis într-un `InAppBrowser` pe iOS ([detaliat în ios-in-app-purchase-audit.md](ios-in-app-purchase-audit.md)) — confirmat încă prezent în cod, nemodificat.
2. **Playerul iOS a ajuns la paritate cu web-ul** — subtitrări, calitate, viteză de redare sunt implementate (`PlayerView.swift`, comentariu explicit în cod: *"the web player has had these"*). Ce rămâne pe partea de conținut: recomandări, heartbeat analytics, watch party, premiere countdown — toate încă absente.
3. **Funcționalități backend încă neconectate:** control parental cu PIN, cupoane, reclame VAST — verificate din nou, tot absente din iOS *și* din web (componentele `ParentalPinModal.tsx`, `CouponField.tsx` rămân orfane, neimportate nicăieri).
4. **Localizarea e parțial rezolvată** — `AuthView`, `PlayerView`, `HomeView`, `CmsPageView`, `RootView` folosesc acum `app.t()` consecvent. `AccountView` (12 texte hardcodate), `ContentDetailView` (15), `ProfilePickerView` (5, zero `app.t()`) rămân netraduse.

---

## Categoria 0 — Blocante App Store

Trebuie rezolvate înainte de orice submisie.

### 0.1 Ștergerea contului — ✅ REZOLVAT (re-verificat 23 septembrie 2026)

`AccountDeletionService` există (`app/Services/AccountDeletionService.php`) — șterge sesiuni/token-uri, anonimizează userul, păstrează wallet/entitlements/audit_log conform obligațiilor contabile, forfeitează soldul rămas printr-o tranzacție de ajustare. Endpoint: `DELETE /api/v1/settings/account` (`SettingsController::destroyAccount`, cere parola curentă).

- **Web:** `UserDashboardPage.tsx` — flux complet cu confirmare prin tastarea unui cuvânt, listă de consecințe (sold pierdut, X titluri, date șterse).
- **iOS:** `AccountView.swift` → `DeleteAccountSheet` — parolă + motiv opțional, apelează exact același endpoint (`APIClient.deleteAccount`).

Nimic de făcut aici.

### 0.2 Pagini legale inaccesibile în aplicație — ✅ REZOLVAT (re-verificat 23 septembrie 2026)

`Filmoteca/Views/CmsPageView.swift` există și randează paginile CMS. De verificat doar dacă meniul de footer din iOS chiar leagă fiecare link către el (nu am urmărit fiecare punct de intrare), dar componenta de bază — care lipsea complet pe 11 august — există acum.

### 0.3 Formularul de alimentare PayFilmoteca

Documentat separat în [ios-in-app-purchase-audit.md](ios-in-app-purchase-audit.md) §2. Respingere garantată sub 3.1.1.

---

## Categoria 1 — Paritate lipsă (web are, iOS nu)

| # | Funcționalitate | Web | iOS | Impact |
|---|---|---|---|---|
| 1.1 | **Subtitrări în player** | `VideoPlayer.tsx` — `textTracks`, panou dedicat | ✅ `PlayerView.swift` | — |
| 1.2 | **Selecție calitate** | `VideoPlayer.tsx` — panou `quality` | ✅ `PlayerView.swift` | — |
| 1.3 | **Viteză de redare** | `VideoPlayer.tsx` — panou `speed` | ✅ `PlayerView.swift` | — |
| 1.4 | **Recuperare parolă** | `AuthModal.tsx:328` + `auth/forgot-password` | ✅ `AuthView.swift` | — |
| 1.5 | **Pagini CMS** | `CmsPage`, `ContactPage`, `PricingPolicyPage` | ✅ `CmsPageView.swift` | — |
| 1.6 | **Recomandări** | `session.ts:647` → `content/{id}/recommendations` | ✅ implementat 23 sept. (`MediaRow` sub recenzii, `ContentDetailViewModel.loadRecommendations()`) | — |
| 1.7 | **Heartbeat analytics** | web trimite `event_type` prin `tracking/watch-progress`, nu `tracking/heartbeat` (audit inițial avea referința greșită) | Constatare corectată: **exista deja** — `PlayerViewModel.report(event:)` trimite `"progress"` la fiecare 10s către `storefront/tracking/watch-progress`. Lipsea doar `"play"`/`"complete"`/`"stop"` (trimitea `"pause"` la închiderea playerului) → `counted_as_view` nu se seta niciodată. **Fixat 23 sept.** | — |
| 1.8 | **Watch Party** | `WatchPartyPage.tsx` complet | ❌ absent (re-verificat) | 🟡 mică |
| 1.9 | **Premiere countdown** | `PremiereCountdown.tsx` | model există, UI ❌ (re-verificat) | 🟡 mică |
| 1.10 | **Status plată** | `PaymentStatusPage.tsx` | n/a (va fi IAP) | — |

**1.1–1.5 sunt rezolvate** — verificat direct în `PlayerView.swift` (comentariu explicit în cod: *"Subtitle, audio, quality and speed controls — the web player has had these"*), `AuthView.swift` (flux complet de recuperare parolă) și `CmsPageView.swift`.

**Rămân deschise 1.6–1.9.** **1.7** înseamnă că statisticile de vizionare de pe mobil sunt încă incomplete față de web — afectează raportările și decontările către deținătorii de drepturi (exact sistemul de raportare pe care tocmai l-am construit în `RightsReportingService`).

---

## Categoria 2 — Backend gata, neconectat de niciun client

Funcționalități complet implementate server-side pe care **nici web-ul, nici iOS-ul nu le folosesc**. Verificate ca fiind componente orfane (neimportate nicăieri) sau endpoint-uri neapelate.

### 2.1 Control parental cu PIN 🔴

- API complet: `profiles/{profile}/parental/pin` (set / clear / unlock), `ParentalControlService`
- Web: `components/ParentalPinModal.tsx` există dar **nu e importat nicăieri** (re-verificat, tot orfan)
- iOS: profilul are `is_kids`, dar niciun PIN (re-verificat, absent)

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

### 3.1 Localizare parțial rezolvată — actualizat 23 septembrie 2026

`AuthView` și `PlayerView` au trecut de la 0 la localizare completă între timp (dovadă că lucrul la player a inclus și traducerea). Rămân de rezolvat `AccountView`, `ContentDetailView`, `ProfilePickerView`, `LibraryView` — numărătoare re-verificată direct în cod (`Text("...")` hardcodat vs. apeluri `app.t()`):

| Ecran | `Text("…")` hardcodat | `app.t()` | Status |
|---|---|---|---|
| `SearchView` | 1 | 29 | ✅ aproape complet |
| `HomeView` | 0 | 7 | ✅ complet |
| `RootView` | 0 | 4 | ✅ complet |
| `CmsPageView` | 0 | 3 | ✅ complet |
| `AuthView` | 3 | 12 | ✅ rezolvat (era 0 → 12) |
| `PlayerView` | 2 | 10 | ✅ rezolvat (era 0 → 10) |
| `LibraryView` | 2 | 3 | 🟡 aproape |
| **`AccountView`** | **12** | 4 | 🔴 tot netradus în mare parte |
| **`ContentDetailView`** | **15** | 3 | 🔴 tot netradus în mare parte |
| **`ProfilePickerView`** | **5** | **0** | 🔴 zero localizare |

Web-ul e complet tradus prin `i18n/index.ts` (ro/ru/en). Rămâne o problemă reală de adopție pentru publicul rusofon din Moldova — concentrată acum în 3 ecrane specifice, nu răspândită peste tot aplicația cum părea inițial.

### 3.2 Ce e deja la paritate ✅

Ca să fie clar ce nu trebuie refăcut: home curatoriat, catalog cu filtre, căutare, detalii conținut, seriale + episoade, recenzii (citire, trimitere, ștergere), favorite, bibliotecă, profiluri (creare/editare/ștergere), continue watching, autentificare + verificare email, recuperare parolă, schimbare date cont și parolă, comutare limbă, împărtășire film (`ShareLink`), asociere TV, AirPlay, DRM/Bunny Stream, **ștergere cont**, **pagini legale (CMS)**, **subtitrări/calitate/viteză în player**.

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

## Propunere de prioritizare — actualizată 23 septembrie 2026

### Faza 0 — Deblocare submisie
1. ~~Ștergere cont (backend + web + iOS)~~ ✅ făcut
2. ~~Pagini CMS în app~~ ✅ făcut
3. **IAP conform [ios-in-app-purchase-audit.md](ios-in-app-purchase-audit.md)** — singurul blocant rămas, confirmat încă prezent în cod (`WalletTopUpSheet` + `InAppBrowser` către `pay.filmoteca.md`). Depinde de pași de business (banking Moldova, Paid Applications Agreement) neconfirmați încă. Partea de cod (Nivelul 1, testabilă fără setup financiar) poate începe oricând.

### Faza 1 — Paritate esențială
4. ~~Player: subtitrări, calitate, viteză~~ ✅ făcut
5. ~~Recuperare parolă~~ ✅ făcut
6. ~~Heartbeat (evenimente play/complete/stop) + recomandări~~ ✅ făcut 23 sept.
7. Localizare — restrânsă acum la 3 ecrane: `AccountView`, `ContentDetailView`, `ProfilePickerView`
8. Watch Party + premiere countdown — încă deschis

### Faza 2 — Funcționalități care există deja în backend
9. Control parental cu PIN (web + iOS) — încă deschis
10. Cupoane (web + iOS) — încă deschis
11. Reclame VAST pe conținut gratuit (decizie de business întâi) — încă deschis

### Faza 3 — Native „super app"
12. Picture in Picture + Now Playing
13. Notificări push
14. Universal Links
15. Descărcare offline
16. Widget + Spotlight + Face ID

### Faza 4 — Opțional
17. SharePlay

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
