# FILMOTECA.md — TV Web App (VIDAA / Tizen / webOS)

O aplicație web HTML5 optimizată pentru televizoare, navigabilă 100% cu
telecomanda. Aceeași aplicație rulează pe **Hisense (VIDAA)**, **Samsung
(Tizen)** și **LG (webOS)** — toate folosesc browsere web pentru aplicații.

Vanilla JS (fără build, fără framework) ca să meargă și pe browserele mai vechi
de TV. Playerul e **iframe-ul Bunny** (exact ca pe web) — Bunny gestionează HLS,
DRM și token-urile.

## Ce conține

- Login cu cod (device pairing) — același flux ca pe Android TV
- Acasă: hero + rânduri de postere (din `/public/home`)
- Detaliu: descriere, buton Vizionează, listă de episoade la seriale
- Player Bunny pe tot ecranul (iframe)
- Ecran „cumpără online" când nu ai acces (403)
- Navigare cu săgeți / OK / Înapoi

## Fișiere

```
index.html   structura + overlay player
config.js    API_BASE / WEB_BASE (editează aici)
spatial.js   motor de navigație cu telecomanda (focus D-pad)
api.js       client API
app.js       ecrane + rutare + Back
app.css      stil 10-foot (negru/roșu, ca pe web)
```

## Testare rapidă

### În browser (pe Mac/PC)
```bash
cd film.md-tv-web
python3 -m http.server 8080
# deschide http://localhost:8080
# navighează cu săgețile + Enter; "Backspace" = Înapoi
```

### Pe televizorul Hisense (din aceeași rețea)
1. Pornește serverul local (comanda de mai sus, `--bind 0.0.0.0`)
2. Pe TV deschide **browserul VIDAA** și intră pe `http://IP_MAC:8080`
   (ex. `http://192.168.100.50:8080`)
3. Testează cu telecomanda

> Notă: serverul local e HTTP. Pentru producție folosește HTTPS (vezi mai jos) —
> unele funcții de TV și instalarea VIDAA cer HTTPS.

## Deploy în producție

Sunt fișiere statice — pune-le pe orice hosting HTTPS (ex. un subdomeniu
`tv.film.veezify.com`, Cloudflare Pages, Netlify, nginx). Nu necesită Node.

```
tv.film.veezify.com/  →  index.html, config.js, spatial.js, api.js, app.js, app.css
```

Editează `config.js` dacă schimbi domeniile.

## Instalare ca aplicație pe VIDAA

VIDAA nu are sideload de APK — o „aplicație" e un **URL** către un web-app TV.

- **Test acum:** deschide URL-ul în browserul VIDAA (vezi mai sus).
- **Aplicație lansabilă (dev):** VIDAA instalează app-uri via funcția
  `Hisense_installApp()`, care rulează doar de pe `vidaahub.com`. Comunitatea
  ocolește asta redirectând `vidaahub.com` prin DNS local către un mic server
  (vezi proiectul `trialuser/vidaa-appstore`). App-ul = URL-ul tău HTTPS + o
  iconiță PNG 400×400.
- **Publicare oficială în store-ul VIDAA:** necesită parteneriat cu VIDAA
  (Partner Engagement) — ei fac submisia. Web-app-ul acesta e exact ce le
  trimiți.

Aceiași pași (cu specificul lor) se aplică pentru **Samsung Tizen** și
**LG webOS** — același web-app, împachetat per platformă.

## Taste telecomandă suportate

Săgeți (37–40), OK/Enter (13/32), Înapoi (8 / Esc / 461 VIDAA / 10009 Tizen).
Dacă o anumită telecomandă trimite alt cod pentru „Înapoi", adaugă-l în
`spatial.js` → `KEY.back`.
