# Film.md backup

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
