# Lokalna wersja post-dashboard (Windows ZIP) — obok hostingu OVH

## Kontekst

`post-dashboard` (Angular 19 SPA + PHP 8.1/Slim 4 API + MySQL) jest dziś uruchamiany
wyłącznie na hostingu OVH (deploy ręczny, opisany w `todo.md`) albo lokalnie w trybie
deweloperskim (`php -S` + `ng serve`, wymaga ręcznie zainstalowanego PHP/MySQL/Node).
Celem jest dodanie **drugiej, opcjonalnej ścieżki uruchomienia**: samodzielna paczka
ZIP na Windows, którą użytkownik rozpakowuje i odpala jednym kliknięciem (`start.bat`),
bez instalowania PHP, MySQL ani Node — a hosting OVH pozostaje głównym, docelowym
środowiskiem produkcyjnym, bez zmian w sposobie jego działania.

Architektura repo już to ułatwia: `backend/scripts/dev_router.php` (istniejący plik,
używany dziś tylko w dev) naśladuje reguły `.htaccess` z produkcji — jeden proces PHP
(`php -S`) obsługuje zarówno statyczny build Angulara, jak i `/api/*` przez Slim.
Front-end woła API relatywną ścieżką `/api` (`frontend/src/app/core/services/api.service.ts:12`),
więc **ten sam build produkcyjny Angulara działa bez zmian zarówno na OVH, jak i lokalnie** —
nie trzeba osobnego builda "local vs OVH".

Ustalenia z użytkownikiem (nie do zmiany bez ponownego pytania):
1. Baza danych: **przenośny MariaDB** w paczce (bez instalacji) — zero zmian w SQL/migracjach.
2. **Przenośny PHP** (Windows NTS zip) w paczce — użytkownik niczego nie instaluje.
3. Forma: **ZIP + `start.bat` / `stop.bat`** (nie pełny instalator .exe na v1).
4. **Tylko Windows** na v1.

## Ustalenia z eksploracji, które trzeba uwzględnić

- **Brak portu w DSN**: `backend/src/Support/Database.php:19-23` buduje DSN jako
  `mysql:host=%s;dbname=%s;charset=utf8mb4` — bez `port`. Żeby uniknąć kolizji z
  ewentualnym istniejącym lokalnym MySQL/MariaDB na porcie 3306, przenośny MariaDB musi
  chodzić na innym porcie (np. 3307+) — **wymaga to małej, wstecznie kompatybilnej zmiany**:
  dodać opcjonalny klucz `db.port` w configu, domyślnie 3306 gdy nieustawiony, dopisywany
  do DSN tylko gdy podany. Nie wpływa na config OVH (tam `port` nigdy nie jest ustawiany).
- **Uploads hardcoded**: `backend/src/Support/ContainerFactory.php:73` —
  `'uploads_dir' => dirname(__DIR__, 2) . '/../public/uploads'` — `backend/` i `public/`
  muszą zostać rodzeństwem na tym samym poziomie katalogów, dokładnie jak w repo dziś.
- **Cookie `secure` flag**: `public/api/index.php:27` —
  `new SessionMiddleware(secureCookies: ($config['app_env'] ?? 'production') === 'production')`.
  Lokalny serwer chodzi po zwykłym HTTP, więc wygenerowany lokalny config musi mieć
  `app_env => 'development'` (tak jak `config.example.php` już ma domyślnie), inaczej
  ciasteczko sesji nie będzie wysyłane i logowanie pozornie "nie zadziała".
- **Origin spójność**: bindować wszystko na `127.0.0.1` (nie `localhost` — to inny origin
  dla przeglądarki/OAuth state) — adres bindowania MariaDB, PHP, otwierany URL,
  `app_base_url`, `meta_redirect_uri` muszą być identyczne wszędzie (`http://127.0.0.1:8888`).
  Meta pozwala zarejestrować wiele Valid OAuth Redirect URI jednocześnie, więc lokalny URL
  można dodać obok produkcyjnego w tej samej aplikacji Meta.
- `seed_user.php` robi upsert (`ON DUPLICATE KEY UPDATE`) — bezpieczne do wielokrotnego
  wywołania, dobry punkt oparcia zarówno dla pierwszego uruchomienia jak i resetu hasła.
- Klucz API Anthropic **nie jest** wartością configu — użytkownik wkleja go w UI
  (Ustawienia) po zalogowaniu, zapisywany zaszyfrowany w tabeli `settings`. Paczka nic
  z tym nie musi robić.
- Brak dziś jakiegokolwiek trackingu wersji migracji (5 plikow `.sql`, aplikowane ręcznie).
  Dla paczki lokalnej potrzebny minimalny runner z tabelą `schema_migrations`, żeby
  `start.bat` mógł być bezpiecznie uruchamiany wielokrotnie i żeby przyszłe aktualizacje
  paczki (nowe pliki `006_*.sql` itd.) aplikowały się automatycznie bez błędów na już
  zastosowanych migracjach.
- Codzienny cron `backend/cron/refresh_fb_token.php` (na OVH: cron w panelu OVH) nie ma
  lokalnego odpowiednika w stylu "zawsze włączony" — token FB żyje ~60 dni, więc przy
  sporadycznym lokalnym użyciu to akceptowalne ograniczenie, do udokumentowania w README
  (opcjonalnie: wpis do harmonogramu zadań Windows jako "best effort").

## Zakres zmian w repo (kod aplikacji)

1. **`backend/src/Support/Database.php`** — dodać opcjonalny port do DSN:
   ```php
   $dsn = sprintf(
       'mysql:host=%s;dbname=%s;charset=utf8mb4%s',
       $config['host'], $config['name'],
       isset($config['port']) ? ';port=' . (int) $config['port'] : '',
   );
   ```
2. **`backend/config/config.example.php`** — udokumentować (komentarzem) opcjonalny
   klucz `db.port` (bez zmiany domyślnego zachowania na OVH).
3. **Nowy `backend/scripts/migrate.php`** — minimalny runner migracji:
   - `CREATE TABLE IF NOT EXISTS schema_migrations (filename VARCHAR(255) PRIMARY KEY, applied_at DATETIME DEFAULT CURRENT_TIMESTAMP)`
   - iteruje `backend/migrations/*.sql` posortowane leksykalnie (istniejący prefiks `NNN_`
     już to gwarantuje), pomija już zastosowane, wykonuje i odnotowuje pozostałe.
   - błąd SQL przerywa bez oznaczenia pliku jako zastosowanego (można bezpiecznie ponowić).
4. **Nowy `backend/config/config.local.template.php`** — szablon z placeholderami
   (`__ENCRYPTION_KEY__`, `__SESSION_SECRET__`, `__DB_PORT__`, `__DB_PASS__`) podmienianymi
   przez `start.ps1` przy pierwszym uruchomieniu — strukturalnie wyklucza wyciek prawdziwego
   klucza do repo/paczki.

Te 2 pierwsze zmiany są jedynymi modyfikacjami wspólnego kodu backendu — w pełni wstecznie
kompatybilne, nie wpływają na deployment OVH.

## Nowy katalog `packaging/` (narzędzia budowania paczki, nieużywane przez end-usera)

```
packaging/
├── build-local-package.ps1     # buduje ZIP z czystego checkoutu (developer uruchamia ręcznie)
├── fetch-vendor.ps1             # jednorazowo pobiera portable PHP + MariaDB do cache, weryfikuje SHA256
├── vendor-cache/                 # gitignored — portable PHP + MariaDB, pobrane raz przez fetch-vendor.ps1
├── package-template/
│   ├── start.bat
│   ├── stop.bat
│   ├── README.txt
│   └── scripts/
│       ├── start.ps1
│       ├── stop.ps1
│       └── lib/common.ps1
└── dist/                          # gitignored — gotowe ZIP-y + .sha256
```

`build-local-package.ps1` (uruchamiany ręcznie przez dewelopera przy wydaniu nowej wersji):
1. `frontend`: `npm ci` + `ng build --configuration production` → `frontend/dist/frontend/browser/`
2. `backend`: `composer install --no-dev --optimize-autoloader`
3. Składa katalog roboczy: `app/backend/` (bez `config.local.php`), `app/public/`
   (build Angulara + `public/api/`), `runtime/php/` i `runtime/mariadb/` (kopiowane z
   `vendor-cache/`), `start.bat`/`stop.bat`/`scripts/`/`README.txt` z `package-template/`.
4. `Compress-Archive` → `packaging/dist/post-dashboard-local-vX.Y.Z.zip` + plik `.sha256`.

Portable binaria: PHP z `windows.php.net/download/` (build **NTS x64 Zip**, wersja pinowana
w skrypcie, ≥8.1), MariaDB z `mariadb.org/download/` (**Zip file**, seria LTS). Pobierane
raz do `packaging/vendor-cache/` przez `fetch-vendor.ps1` — nie commitowane do gita, nie
pobierane od nowa przy każdym buildzie.

## Struktura paczki ZIP (co dostaje użytkownik)

```
post-dashboard-local-v1.0.0/
├── start.bat / stop.bat            # cienkie wrappery -> powershell -ExecutionPolicy Bypass
├── README.txt                       # instrukcja, ograniczenia, bezpieczeństwo
├── VERSION.txt
├── scripts/{start.ps1, stop.ps1, lib/common.ps1}
├── runtime/{php/, mariadb/}         # binaria przenośne, bez instalacji
├── app/
│   ├── backend/ (src, config/, migrations/, scripts/, vendor/)
│   └── public/ (build Angulara + api/ + uploads/ jako junction do data/uploads)
├── data/                            # STAN — nigdy nie nadpisywany przy aktualizacji paczki
│   ├── mariadb/{datadir/, client.cnf, port.txt}
│   ├── run/{mariadbd.pid, php.pid}
│   └── uploads/
└── logs/{mariadb.log, php-server.log, start.log}
```

`app/` = kod (wymienialny przy update), `data/` = stan (przetrwa update). Prawdziwe pliki
uploadów trzymane w `data/uploads/`, spięte z wymaganą ścieżką `app/public/uploads` przez
**junction NTFS** (`mklink /J`, nie wymaga uprawnień administratora, w przeciwieństwie do
symlinków).

## `start.bat` → `start.ps1` — logika

1. **Sprawdzenie "już działa"**: jeśli `data/run/php.pid` wskazuje żywy proces i port 8888
   odpowiada → otwórz przeglądarkę i zakończ. Martwy plik pid → usuń, kontynuuj normalnie.
2. **Wykrycie pierwszego uruchomienia**: brak `data/mariadb/datadir/mysql/` = pierwsze
   uruchomienie (a nie sam fakt istnienia pustego katalogu).
3. **Wybór portu MariaDB**: przy pierwszym uruchomieniu wyszukaj wolny port od 3307 wzwyż,
   zapisz do `data/mariadb/port.txt`; przy kolejnych — odczytaj i zweryfikuj dostępność.
   Port PHP (8888) jest stały (zależy od niego zarejestrowany Meta redirect URI) — kolizja
   = czytelny błąd, bez automatycznej zmiany.
4. **Przy pierwszym uruchomieniu**:
   - `mariadb-install-db.exe` na świeżym datadir, start `mariadbd.exe --bind-address=127.0.0.1
     --port=<wybrany> --pid-file=data/run/mariadbd.pid`, polling aż odpowiada.
   - Wygeneruj losowe hasło root + hasło użytkownika `post_dashboard` (per-install, nigdy
     nie reużywane), utwórz bazę + użytkownika, zapisz `data/mariadb/client.cnf` (root creds,
     do późniejszego gracefulnego shutdown przez `stop.ps1`).
   - Uruchom `migrate.php` (tworzy `schema_migrations`, aplikuje `001`–`005`).
   - Wygeneruj `app/backend/config/config.local.php` z `config.local.template.php`:
     świeży `encryption_key`/`session_secret` (`random_bytes(32)` przez `php.exe -r ...`),
     `app_env=development`, `db.port=<wybrany>`, `meta_redirect_uri`/`app_base_url` =
     `http://127.0.0.1:8888`, `meta_app_id/secret` puste (użytkownik uzupełnia sam).
   - **Interaktywnie** poproś o login/hasło właściciela (`Read-Host`/`-AsSecureString`,
     walidacja długości i potwierdzenia) → `seed_user.php <login> <hasło>`. Rekomendacja:
     brak sztywnego domyślnego hasła w publicznie dystrybuowanym ZIP-ie (dziś w dev to
     `owner`/`dev_password_123` — nie do powielenia w paczce).
5. **Przy kolejnych uruchomieniach**: start `mariadbd.exe` na zapisanym porcie/datadir (bez
   reinicjalizacji), `migrate.php` uruchamiany zawsze (idempotentny — to jest mechanizm
   automatycznego stosowania nowych migracji po aktualizacji paczki), `config.local.php`
   **nigdy nie jest regenerowany** (regeneracja rotowałaby `encryption_key` i zepsuła
   odszyfrowanie już zapisanych sekretów — klucza Anthropic, tokenów FB).
6. **Zawsze**: start `php.exe -S 127.0.0.1:8888 -t app/public app/backend/scripts/dev_router.php`
   w tle (`Start-Process -WindowStyle Hidden`, PID → `data/run/php.pid`), polling do
   odpowiedzi, otwarcie domyślnej przeglądarki na `http://127.0.0.1:8888`.
7. Błędy na każdym etapie: log do `logs/start.log` + `logs/mariadb.log`/`logs/php-server.log`,
   czytelny komunikat w konsoli, best-effort sprzątanie uruchomionych już procesów.

## `stop.bat` → `stop.ps1` — logika

- PHP: zweryfikuj że PID z `data/run/php.pid` to rzeczywiście nasz proces (nazwa obrazu +
  command line zawiera `dev_router.php`) zanim go zatrzymasz — nie dotykaj cudzego `php.exe`.
- MariaDB: **graceful shutdown** przez `mariadb-admin.exe --defaults-extra-file=data/mariadb/client.cnf
  shutdown` (nie hard-kill — chroni datadir przed uszkodzeniem), polling do faktycznego
  zakończenia procesu, z limitem czasu i czytelnym ostrzeżeniem jeśli się przeciąga.

## Bezpieczeństwo / ograniczenia zakresu (do README.txt)

- MariaDB i PHP bindowane wyłącznie na `127.0.0.1` — nigdy `0.0.0.0`; jawnie napisane w
  README, że to narzędzie jednoosobowe/lokalne, niedostępne z sieci/internetu.
- `encryption_key`/`session_secret` generowane od nowa przy każdej instalacji, nigdy nie
  kopiowane z configu OVH ani z przykładowego pliku.
- Brak automatycznego odświeżania tokenu Facebooka lokalnie — do udokumentowania.
- PHP built-in server jest jednowątkowy/dev-grade — OK dla jednego użytkownika, nie do
  współdzielenia.
- Windows Defender/SmartScreen może na pierwszy rzut oka oflagować niepodpisane `.bat`/`.ps1`
  lub dołączone binaria — spodziewane, opisane w README, łagodzone publikacją `.sha256` i
  dystrybucją z wiarygodnego miejsca (np. GitHub Releases).
- Brak wymogu uprawnień administratora w całym flow (junction NTFS, porty >1024).
- Wymaganie wstępne: Microsoft Visual C++ Redistributable (zależność binarna przenośnego
  MariaDB) — do wymienienia w README z linkiem, `start.ps1` powinien rozpoznać ten konkretny
  tryb awarii (natychmiastowe wyjście procesu / DLL not found w logu) i dać czytelny komunikat
  zamiast generycznego timeoutu.

## Weryfikacja end-to-end

1. Uruchomić `packaging/build-local-package.ps1` na czystym checkoutcie, sprawdzić że ZIP
   się tworzy i ma sensowny rozmiar.
2. Rozpakować ZIP na czystej maszynie/VM Windows (bez PHP/MySQL/Node zainstalowanych) i
   odpalić `start.bat` — potwierdzić: inicjalizację MariaDB, aplikację migracji, wygenerowanie
   `config.local.php`, utworzenie konta właściciela, otwarcie przeglądarki na działającej
   aplikacji.
3. Zamknąć i ponownie odpalić `start.bat` — potwierdzić brak reinicjalizacji, brak regeneracji
   configu, poprawne wznowienie działania.
4. Przetestować pełny przepływ: logowanie → zapis klucza Anthropic → "Połącz konto Facebook"
   (z dodanym lokalnie `http://127.0.0.1:8888/api/social/connect/callback` w Meta App) →
   nowy post → generacja podpisu → upload zdjęcia → publikacja na Facebook/Instagram.
5. `stop.bat` → potwierdzić graceful shutdown MariaDB (brak błędów przy kolejnym starcie) i
   zatrzymanie PHP.
6. Test aktualizacji: rozpakować nową wersję ZIP obok starej, skopiować `data/` i
   `config.local.php` ze starej instalacji, dodać nowy plik migracji `006_*.sql`, odpalić
   `start.bat` — potwierdzić że tylko nowa migracja się aplikuje, dane/uploady zachowane.
