# Pachete de credite — App Store Connect

Două produse **Consumable**: `md.filmoteca.wallet.100` și `md.filmoteca.wallet.200`.
1 credit = 1 MDL, pe toate platformele. Prețul filmelor nu se schimbă; doar creditele costă mai mult pe iOS,
ca să acopere comisionul Apple.

## Cum se calculează prețul

```
preț USD = credite ÷ curs USD/MDL ÷ (1 − comision Apple) ÷ (1 − marjă conversie)
```

Apoi alegi în App Store Connect **cel mai apropiat preț ≥ rezultat**. Calculul e făcut și în admin:
Setări prețuri → „Pachete de credite App Store”, unde se văd netul pe pachet și prețul minim.

| Pachet | Necesar (curs 17,5 · 30% · 3%) | Preț ales | Net estimat | Rezistă până la cursul |
|---|---|---|---|---|
| 100 credite | $8.42 | **$8.99** | ≈ 106,8 MDL | 16,4 MDL/USD |
| 200 credite | $16.83 | **$17.99** | ≈ 213,8 MDL | 16,4 MDL/USD |

Cu Small Business Program (15%), aceleași pachete ar costa **$6.99** și **$13.99**.

Verificare finală: în App Store Connect, la Price Schedule → „View all prices”, coloana **Proceeds** pentru
Moldova (sau pentru țara de bază) trebuie să fie ≥ credite ÷ curs. Proceeds include deja taxele reținute
de Apple.

## Ce completezi la fiecare produs

| Câmp | 100 | 200 |
|---|---|---|
| Availability | toate țările (sau cele în care e disponibilă aplicația) | idem |
| Price | $8.99 | $17.99 |
| Localizare **Romanian** — Display Name | 100 credite | 200 credite |
| Romanian — Description | 100 credite Filmoteca (1 credit = 1 MDL) | 200 credite Filmoteca (1 credit = 1 MDL) |
| Localizare **Russian** — Display Name | 100 кредитов | 200 кредитов |
| Russian — Description | 100 кредитов Filmoteca (1 кредит = 1 MDL) | 200 кредитов Filmoteca (1 кредит = 1 MDL) |
| Localizare **English (U.S.)** — Display Name | 100 credits | 200 credits |
| English — Description | 100 Filmoteca credits (1 credit = 1 MDL) | 200 Filmoteca credits (1 credit = 1 MDL) |
| Image (promovare, opțional) | `wallet-100.png` | `wallet-200.png` |
| Review Screenshot | `review-screenshot.png` | `review-screenshot.png` |

Limite Apple: Display Name ≤ 35 caractere, Description ≤ 55. Imaginea: 1024×1024 PNG, fără transparență și
fără preț scris pe ea.

### Review Notes (aceleași pentru ambele)

```
Filmoteca sells consumable credit packs. 1 credit = 1 MDL and credits never expire.
Credits are spent inside the app to rent or buy films; the film price is the same on every platform.

To test: sign in with the demo account below, open the "Cont" (Account) tab and tap "Alimentează"
(Add funds). Choose a pack; after the purchase the balance updates immediately.

Demo account: <email> / <parolă>
```

## Prima trimitere

La prima versiune cu In-App Purchases, produsele se trimit **împreună cu build-ul**:
în pagina versiunii aplicației (1.0 Prepare for Submission) → secțiunea „In-App Purchases and Subscriptions”
→ adaugi ambele produse, apoi trimiți versiunea la review.

După ce schimbi prețurile în App Store Connect, actualizează-le și în admin, ca să fie corect calculul netului.

Imaginile se regenerează cu `swift make-images.swift`, rulat din acest folder.
