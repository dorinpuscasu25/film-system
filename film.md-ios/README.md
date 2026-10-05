# FILMOTECA iOS

Aplicație iOS nativă SwiftUI pentru FILMOTECA.md. Folosește API-ul de producție `https://filmmd-api.veezify.com/api/v1` și necesită iOS 17 sau mai nou.

Arhitectura este MVVM enterprise, cu repositories, dependency injection și separare `Core / Domain / Data / Features / Views`. Detaliile sunt în [ARCHITECTURE.md](ARCHITECTURE.md).

## Rulare

1. Deschide `Filmoteca.xcodeproj` în Xcode.
2. În target-ul **Filmoteca**, selectează echipa Apple Developer la **Signing & Capabilities**.
3. Alege un simulator sau un iPhone și rulează schema **Filmoteca**.

Aplicația include home curatoriat, catalog și căutare, detalii, seriale și episoade, AVPlayer/AirPlay, progres sincronizat, autentificare și verificare email, profiluri, favorite, bibliotecă, recenzii, achiziție din sold și conectare TV. Alimentarea soldului se face prin In-App Purchase (StoreKit 2): pachete de credite ca produse *Consumable*, confirmate de backend (`storefront/wallet/apple-iap/redeem`). Pachetele (product ID → credite) se configurează din admin → Setări prețuri. Detalii: `docs/ios-in-app-purchase-audit.md`.

## Configurare înainte de distribuție

- înlocuiește `DEVELOPMENT_TEAM` cu echipa Apple Developer;
- confirmă bundle ID-ul `md.filmoteca.ios` în App Store Connect;
- creează în App Store Connect produsele Consumable cu aceleași ID-uri ca în admin (`md.filmoteca.ios.credits.*`);
- testează DRM/Bunny Stream pe device real pentru fiecare format publicat.

## Testare plăți local (fără cont Apple)

Schema partajată **Filmoteca** are deja atașat `Filmoteca/Filmoteca.storekit`. Backend-ul de producție refuză intenționat tranzacțiile locale (nu sunt semnate de Apple), deci:

1. pornește backend-ul local (`APP_ENV=local`) pe `http://localhost:8000`;
2. în Xcode: Product → Scheme → Edit Scheme → Run → Arguments → bifează `FILMOTECA_API_BASE_URL`;
3. rulează pe simulator, autentifică-te, Cont → Portofel → Alimentează. Tranzacțiile se văd în Debug → StoreKit → Manage Transactions (acolo poți simula și refund).

## Verificare CLI

```sh
xcodebuild -project Filmoteca.xcodeproj -target Filmoteca -sdk iphoneos \
  -configuration Debug CODE_SIGNING_ALLOWED=NO build
```
