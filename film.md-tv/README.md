# FILMOTECA.md — Android TV

Aplicație nativă Android TV (Kotlin + Jetpack Compose for TV) pentru platforma
FILMOTECA.md. Navigare cu telecomanda, login cu cod (device pairing), redare
nativă Bunny Stream prin ExoPlayer, fără achiziții pe TV.

## Cerințe

- Android Studio (Ladybug+) sau JDK 17 + Android SDK
- API-ul Laravel (`film.md-admin-api`) pornit
- Web-client-ul (`film.md-client`) pornit — pentru pagina `/tv` de aprobare a codului

## Configurare URL-uri

URL-urile API/web sunt în `app/build.gradle.kts` ca `buildConfigField`:

- **debug** → `http://10.0.2.2:8000` (API) și `:5173` (web).
  `10.0.2.2` este mașina gazdă văzută din emulatorul Android.
  Pe un TV fizic, înlocuiește cu IP-ul din rețeaua locală (ex. `http://192.168.1.10:8000`).
- **release** → `https://api.filmoteca.md` și `https://filmoteca.md`.

## Rulare

```bash
# din film.md-tv/
./gradlew :app:assembleDebug          # build APK
./gradlew :app:installDebug           # instalează pe emulator/TV conectat
```

Sau deschide folderul `film.md-tv` în Android Studio și apasă Run pe un
emulator de tip **Android TV (1080p)**.

## Fluxul de autentificare (device pairing, RFC 8628)

1. La pornire, dacă nu există token salvat, TV-ul cere un cod
   (`POST /auth/device/code`) și afișează `XXXX-XXXX` + un QR.
2. Userul deschide `filmoteca.md/tv` pe telefon/PC (logat), tastează codul →
   `POST /device/authorize`.
3. TV-ul face polling (`POST /auth/device/token`) și primește un token, salvat
   în DataStore. Rămâne logat la repornire.

## Achiziții

Pe TV NU se cumpără. Când userul nu are acces la un titlu, `playback` întoarce
`403` → se afișează ecranul „Acest titlu se cumpără online” cu un QR spre
`/<web>/movie/{slug}` și butonul **Verifică din nou** (re-cheamă `playback`).

## Structură

```
data/
  remote/        Retrofit ApiService + DTO-uri Moshi + OkHttp auth interceptor
  TokenStore     DataStore (token persistent)
  FilmotecaRepository  logica de pairing/playback
ui/
  theme/         paleta identică cu web-ul (negru #0A0A0F / roșu #E50914)
  pairing/       ecran cod + QR + polling
  home/          hero + carusele (rânduri)
  detail/        pagina titlului + buton Vizionează
  player/        Player nativ Bunny (SDK oficial) + ecran achiziție blocată
```

## Note despre Bunny (player nativ)

Folosim **SDK-ul oficial Bunny Stream** (`net.bunny:player` + `net.bunny:api`,
din Maven Central) — exact playerul lor, ca pe web. Bunny gestionează singur
HLS, DRM (MediaCage) și token-urile (SDK-ul injectează
`Referer: iframe.mediadelivery.net`, deci merge cu setarea bazată pe referrer).

Fluxul: `BunnyStreamApi.initialize(ctx, null, libraryId)` apoi
`BunnyStreamPlayer.playVideo(videoId, libraryId, "")`. `libraryId` (numeric) și
`videoId` (GUID) sunt extrase din `playback.embed_url`
(`.../embed/{lib}/{video}`) în `ui/player/BunnyIds.kt`.

Necesită **minSdk 26**. (Am renunțat la ExoPlayer — dădea ecran negru fiindcă nu
poate reda URL-ul de embed Bunny.)
