# FILMOTECA iOS

Aplicație iOS nativă SwiftUI pentru FILMOTECA.md. Folosește API-ul de producție `https://filmmd-api.veezify.com/api/v1` și necesită iOS 17 sau mai nou.

Arhitectura este MVVM enterprise, cu repositories, dependency injection și separare `Core / Domain / Data / Features / Views`. Detaliile sunt în [ARCHITECTURE.md](ARCHITECTURE.md).

## Rulare

1. Deschide `Filmoteca.xcodeproj` în Xcode.
2. În target-ul **Filmoteca**, selectează echipa Apple Developer la **Signing & Capabilities**.
3. Alege un simulator sau un iPhone și rulează schema **Filmoteca**.

Aplicația include home curatoriat, catalog și căutare, detalii, seriale și episoade, AVPlayer/AirPlay, progres sincronizat, autentificare și verificare email, profiluri, favorite, bibliotecă, recenzii, achiziție din sold și conectare TV. Alimentarea soldului este redirecționată către site; nu există procesare de plăți în aplicație.

## Configurare înainte de distribuție

- înlocuiește `DEVELOPMENT_TEAM` cu echipa Apple Developer;
- confirmă bundle ID-ul `md.filmoteca.ios` în App Store Connect;
- verifică URL-ul de alimentare din `FilmotecaTheme.swift` când ruta web finală este stabilită;
- testează DRM/Bunny Stream pe device real pentru fiecare format publicat.

## Verificare CLI

```sh
xcodebuild -project Filmoteca.xcodeproj -target Filmoteca -sdk iphoneos \
  -configuration Debug CODE_SIGNING_ALLOWED=NO build
```
