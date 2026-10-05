# Film.md backup

## Backup din admin (recomandat)

Backup-urile se gestionează din admin, la **Setări → Backup-uri** (`/backups`). Pagina este disponibilă rolurilor cu permisiunea `settings.manage_backups`; rolul Admin o primește automat la migrare.

Din admin poți:

- vedea istoricul: ce a reușit, ce a eșuat, cât a durat, mărimea și log-ul complet al fiecărei rulări;
- porni un backup acum, cu componentele alese;
- configura programarea (zilnic, la 6/12 ore, săptămânal sau cron), fusul orar, componentele, retenția, copia off-site, criptarea și notificările pe email;
- descărca fișierele prin link-uri semnate, valabile 10 minute;
- bloca un backup ca să nu fie șters de retenție și adăuga o notă;
- rula un **test de restaurare**: dump-ul este restaurat într-o bază temporară, se compară numărul de rânduri din tabelele importante, apoi baza temporară se șterge;
- vedea și descărca dump-urile pre-migrare.

Componente:

| Componentă | Ce conține | Format |
|---|---|---|
| Baza de date principală | toate datele aplicației | `pg_dump --format=custom`, verificat cu `pg_restore --list` |
| Baza analytics | agregatele de vizionări și reclame | la fel; sărită dacă e aceeași bază |
| Redis | cozi, sesiuni, buffer analytics | `redis-cli --rdb`, gzip |
| Oglindă media | bucket-ul S3/R2 | `rclone sync` incremental în destinația off-site; fișierele șterse din bucket sunt mutate în `media-deleted/<backup>` |

Meilisearch nu are nevoie de backup; indexul se reconstruiește cu `php artisan search:reindex-content`.

### Cum rulează

- `scheduler` rulează `backups:schedule` în fiecare minut și pune un backup în coadă când programarea din admin este scadentă;
- containerul `backup-worker` execută backup-urile (coada `backups` pe conexiunea `redis-backups`), separat de `queue`, ca un dump lung să nu întârzie analytics;
- fișierele stau în volumul Docker `backups` (`/var/backups/film-md`), montat în `app` (pentru descărcare) și în `backup-worker`;
- `backups:monitor` rulează din oră în oră: marchează eșuate backup-urile al căror worker a murit și trimite email dacă nu a reușit niciun backup în intervalul setat;
- retenția: ultimele N backup-uri reușite se păstrează mereu, restul se șterg după X zile; backup-urile blocate nu se șterg.

După deploy, pornește și noul container:

```sh
cd film.md-admin-api
docker compose up -d --build
```

### Copie off-site

În admin, la **Copie off-site (rclone)**:

1. Pe calculatorul tău: `rclone config`, creezi remote-ul (Google Drive, S3, R2, B2, SFTP etc.).
2. `rclone config file` arată unde e fișierul; lipești în admin conținutul lui (poate fi doar secțiunea remote-ului folosit). Se stochează criptat cu `APP_KEY` și nu se mai afișează.
3. Destinație: `nume-remote:folder`, de exemplu `dorin-gdrive:film-md-backups`.
4. Salvezi și apeși **Testează conexiunea**.

Fiecare backup se copiază în `destinație/<nume-backup>`. Dacă upload-ul eșuează, backup-ul este marcat **parțial** și primești email.

### Criptare

Setează în `.env` o parolă și păstreaz-o și în afara serverului (manager de parole). Fără ea, backup-urile criptate nu pot fi restaurate.

```dotenv
BACKUP_ENCRYPTION_PASSPHRASE=<openssl rand -base64 32>
```

Apoi activează criptarea din admin. Fișierele devin `*.enc` (AES-256, `openssl enc -aes-256-cbc -pbkdf2 -iter 200000`).

### Restaurare completă

Restaurarea peste baza live se face din terminal, nu din admin. Comanda verifică checksum-ul, face întâi un backup de siguranță (blocat), cere confirmarea numelui bazei și păstrează istoricul backup-urilor:

```sh
cd film.md-admin-api
docker compose stop app queue scheduler
docker compose run --rm backup-worker php artisan backups:restore NUME-BACKUP
docker compose start app queue scheduler
docker compose exec app php artisan optimize:clear
docker compose exec app php artisan search:reindex-content
```

Pentru baza analytics: `--component=analytics`.

Pe un server nou, fără istoric în baza de date, aduci backup-ul din off-site cu `rclone copy`, îl decriptezi dacă e `.enc` și rulezi direct `pg_restore` (pașii exacți sunt în tab-ul **Restaurare** din admin).

### Comenzi utile

```sh
php artisan backups:run                        # backup acum, în procesul curent
php artisan backups:run --component=database   # doar baza principală
php artisan backups:prune                      # aplică retenția
php artisan backups:monitor                    # verifică backup-uri blocate / lipsă
```

### Ce nu acoperă

Fișierele `.env` nu sunt incluse în backup-ul din admin. Păstrează-le într-un manager de parole sau folosește în continuare `scripts/backup.sh` pe host (secțiunile de mai jos).

## Protecția de deploy

În producție (`APP_ENV=production`), containerul aplicației creează automat un dump PostgreSQL verificat **înainte** de `php artisan migrate --force`. Dacă dump-ul sau verificarea lui eșuează, migrarea și pornirea aplicației se opresc; baza nu este modificată.

Dump-urile de dinaintea migrărilor sunt păstrate în volumul Docker `database_backups`, în `/var/backups/postgres`, timp de 14 zile. Retenția poate fi schimbată în `.env`:

```dotenv
DATABASE_BACKUP_RETENTION_DAYS=30
```

Poți lista și copia un dump pe host astfel:

```sh
docker compose -f film.md-admin-api/docker-compose.yml exec app ls -lh /var/backups/postgres
docker compose -f film.md-admin-api/docker-compose.yml cp app:/var/backups/postgres ./pre-migration-backups
```

Comanda `db:seed` este blocată complet în producție, inclusiv cu `--force`. Laravel blochează și comenzile distructive `db:wipe`, `migrate:fresh`, `migrate:refresh`, `migrate:reset` și `migrate:rollback`. Deploy-ul normal rulează doar migrațiile incrementale și nu apelează niciun seeder.

Este esențial ca `.env` de pe server să conțină:

```dotenv
APP_ENV=production
APP_DEBUG=false
```

Backup-ul pre-migrare protejează deploy-ul, dar nu înlocuiește backup-ul zilnic și copia off-site descrise mai jos.

Backup-ul este făcut de `scripts/backup.sh`. Scriptul salvează local:

- dump SQL pentru baza principală Postgres;
- dump SQL pentru baza `analytics`, dacă este configurată separat;
- `storage/app` și `public/storage` din Laravel;
- fișierele `.env` găsite în proiect, fiindcă nu sunt în git și conțin chei necesare la restore;
- Redis RDB și date Meilisearch, dacă serviciile Docker rulează;
- opțional, un remote rclone extra pentru storage extern, de exemplu S3/Bunny-compatible storage.

Backup-urile locale se pun implicit în `./backups/film-md-YYYYMMDD-HHMMSS`.

## Rulare simplă

```sh
chmod +x scripts/backup.sh
scripts/backup.sh
```

## Sync cu Google Drive

Instalează și configurează `rclone`:

```sh
brew install rclone
rclone config
```

În `rclone config`, creează un remote pentru Google Drive. În cazul tău, remote-ul se numește `dorin-gdrive`.

Rulează backup + upload:

```sh
BACKUP_RCLONE_DEST="dorin-gdrive:film-md-backups" scripts/backup.sh
```

Implicit, scriptul folosește `rclone copy`, adică adaugă backup-uri noi în Google Drive fără să șteargă fișiere vechi de acolo. Dacă vrei ca Google Drive să fie oglinda exactă a folderului local `backups`, folosește:

```sh
BACKUP_SYNC_MODE=sync BACKUP_RCLONE_DEST="dorin-gdrive:film-md-backups" scripts/backup.sh
```

## Recomandare pentru server

Pe server, ține backup-urile într-un folder în afara repo-ului:

```sh
BACKUP_ROOT="$HOME/film-md-backups" \
BACKUP_RCLONE_DEST="dorin-gdrive:film-md-backups" \
scripts/backup.sh
```

Pentru producție recomandăm `BACKUP_SYNC_MODE=copy`, ca ștergerea accidentală a unui backup local să nu fie propagată către copia off-site.

## Cron zilnic

Scriptul `scripts/backup.sh` nu rulează singur. Pentru backup automat în fiecare noapte, instalează cron-ul o singură dată:

```sh
chmod +x scripts/install-backup-cron.sh
scripts/install-backup-cron.sh
```

Implicit, cron-ul rulează în fiecare noapte la 03:00, salvează backup-urile locale în `$HOME/film-md-backups` și le copiază în `dorin-gdrive:film-md-backups`.

Dacă vrei altă oră sau alt remote Google Drive:

```sh
BACKUP_CRON_SCHEDULE="30 2 * * *" \
BACKUP_RCLONE_DEST="dorin-gdrive:film-md-production-backups" \
scripts/install-backup-cron.sh
```

Poți verifica intrarea instalată cu:

```sh
crontab -l
```

Log-ul este aici:

```sh
tail -f "$HOME/film-md-backup.log"
```

Exemplu manual pentru rulare zilnică la 03:00, dacă vrei să editezi crontab-ul singur:

```cron
0 3 * * * cd /path/to/film.md-project && BACKUP_ROOT="$HOME/film-md-backups" BACKUP_RCLONE_DEST="dorin-gdrive:film-md-backups" scripts/backup.sh >> "$HOME/film-md-backup.log" 2>&1
```

## Storage extern

Dacă fișierele reale sunt într-un storage extern și ai remote rclone configurat pentru el, poți să-l incluzi în backup:

```sh
BACKUP_EXTRA_RCLONE_SOURCE="s3film:bucket-name" \
BACKUP_RCLONE_DEST="dorin-gdrive:film-md-backups" \
scripts/backup.sh
```

Pentru Bunny Stream, metadatele și referințele sunt în baza de date, dar fișierele video originale trebuie păstrate și într-un storage separat dacă vrei restore complet independent de Bunny.

## Restore rapid

1. Descarcă folderul de backup din Google Drive.
2. Verifică integritatea:

```sh
cd film-md-YYYYMMDD-HHMMSS
shasum -a 256 -c SHA256SUMS
```

3. Restaurează DB:

```sh
gunzip -c databases/main-film_md.sql.gz | psql -h HOST -p PORT -U USER -d film_md
```

4. Restaurează storage:

```sh
tar -xzf files/laravel-storage.tar.gz -C /path/to/film.md-admin-api
```

5. Restaurează `.env` doar pe serverul potrivit, fiindcă arhiva conține secrete.
