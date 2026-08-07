# FILMOTECA iOS Architecture

Aplicația folosește MVVM cu dependency injection și repository abstraction. Dependențele sunt construite o singură dată în `AppContainer` și injectate în ViewModel-uri; View-urile nu cunosc networking-ul, Keychain-ul sau implementările concrete.

```text
SwiftUI View
    ↓ user actions / observable state
Feature ViewModel
    ↓ protocol
Domain Repository
    ↓ live implementation
Data Repository
    ↓
APIClient / Keychain
```

## Straturi

- `App`: lifecycle și starea sesiunii.
- `Core/Configuration`: URL-uri și configurarea mediului.
- `Core/DI`: composition root și dependency injection.
- `Core/State`: stări comune pentru loading/error/success.
- `Domain/Repositories`: contracte independente de implementarea HTTP.
- `Data/Repositories`: implementările live ale contractelor.
- `Features/*`: ViewModel-uri izolate pentru fiecare funcționalitate.
- `Views`: stratul SwiftUI declarativ.
- `Services`: transport HTTP și stocare securizată.
- `Models`: DTO-urile Codable, inclusiv normalizarea răspunsurilor Laravel array/dictionary.

## Reguli

1. Un View nu apelează niciodată direct `APIClient`.
2. Un ViewModel depinde numai de protocoale Domain.
3. Tokenurile sunt accesate numai prin repository-ul de sesiune/Keychain.
4. URL-urile sunt furnizate prin `AppConfiguration`.
5. Toate stările asincrone expuse UI-ului folosesc `LoadableState`.
6. Implementările de test pot înlocui repository-urile prin initializer-ul `AppContainer`.

## Playback

- Conținutul Bunny Stream folosește `AVPlayer` și un resource loader FairPlay izolat în `BunnyStreamService`. Certificatul și licența sunt cerute de la endpoint-urile Bunny curente, cu referrer-ul aplicației web și tokenul embed opțional. Cheile API Bunny nu sunt incluse în aplicație.
- MediaCage Enterprise DRM este redat prin FairPlay, gestionat de SDK-ul Bunny.
- HLS/MP4 direct folosește `AVPlayer`.
- YouTube și celelalte trailere embed folosesc playerul izolat `WKWebView`.
