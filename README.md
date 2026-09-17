# Fantasy Assistant

Assistent de decisió diària per a **LALIGA Fantasy**: connecta's al teu equip real, sincronitza mercat/plantilla/jugadors, i cada dia et diu exactament què fer — comprar, vendre, mantenir, pagar clàusula, etc. — amb la raó i la confiança darrere de cada decisió.

L'app és **read-only**: mai executa compres, vendes, ofertes o clàusules automàticament. Totes les accions són recomanacions per a tu.

## Stack

| Capa | Tecnologia |
|---|---|
| Backend | Laravel 13 (PHP 8.4), API REST |
| Frontend | React 19 + Vite + Tailwind CSS v4 |
| Base de dades | PostgreSQL 16 |
| Auth API | Laravel Sanctum (tokens Bearer) |
| Cua / Scheduler | Laravel Queue (`database` driver, Redis opcional) + Laravel Scheduler |
| LaLiga Fantasy | Client HTTP propi (`app/Services/FantasyApi`), no oficial |

## Arquitectura

```
fantasy/
├── backend/                  Laravel — API REST, sync, motor de recomanacions
│   ├── app/Services/FantasyApi/       Capa d'abstracció de l'API de LaLiga (l'única part que sap d'HTTP/JSON de LaLiga)
│   ├── app/Services/Recommendation/   Tendències, Fantasy Score, motor de recomanacions, configuració
│   ├── app/Services/Sync/             Orquestració de sincronització (compartida per artisan commands i controllers)
│   ├── app/Console/Commands/          fantasy:sync, fantasy:sync-players, fantasy:sync-market, fantasy:sync-team, fantasy:generate-recommendations
│   ├── app/Http/Controllers/Api/      Endpoints REST interns
│   └── database/migrations/           Esquema + taules d'històric (*_snapshots)
├── frontend/                 React SPA — dashboard "Què he de fer avui?", mercat, plantilla, etc.
└── docker-compose.yml        Postgres + Redis + backend (web/queue/scheduler)
```

### Per què està separat així

- **`FantasyApi/*`** és l'únic lloc que coneix endpoints, headers o forma de resposta de LaLiga. Si LaLiga canvia una ruta, només cal tocar aquí — mai els controllers ni el motor de recomanacions.
- **DTOs defensius**: cada `FantasyXxxDTO::fromArray()` prova diverses claus possibles (`marketValue`, `value`, ...) i sempre guarda el payload cru (`raw`). L'API de LaLiga no és pública ni documentada — vam contrastar els endpoints i el flux OAuth amb els projectes comunitaris [`Externoak/LaLigaApp`](https://github.com/Externoak/LaLigaApp) i [`jonortega20/fantasybot`](https://github.com/jonortega20/fantasybot) (temporada 26/27), però pot canviar sense avís.
- **Snapshots, no sobreescriptura**: `fantasy_player_snapshots`, `fantasy_market_snapshots` i `fantasy_standings` guarden historial complet. L'estat "actual" (`fantasy_players.market_value`, etc.) és una còpia cache per accedir-hi ràpid, no la font de veritat de les tendències.
- **Motor determinista**: `FantasyRecommendationEngine` calcula tot amb números reals (`fantasy_settings`). `RecommendationReasoningInterface` deixa un punt d'extensió per afegir un LLM que *expliqui* millor una decisió — mai perquè la calculi (vegeu més avall).

## Instal·lació

### Windows — instal·lació automàtica

Fes doble clic a `install-windows.bat` (arrel del repositori). Comprova si tens PHP 8.4+, Composer, Node.js i PostgreSQL 16 instal·lats i, si no, els instal·la amb `winget`; després activa les extensions PHP necessàries (`pdo_pgsql`, `mbstring`, `zip`, `fileinfo`, `openssl`, `curl`) al `php.ini`, crea la base de dades `fantasy`, i executa els mateixos passos manuals descrits a sota (`composer install`, `.env`, `php artisan key:generate`/`migrate`, `npm install`). Necessita winget (ja ve amb Windows 10/11 actualitzat) — si algun pas falla, mostra la instrucció manual concreta enlloc d'aturar-se en sec. No toca res relacionat amb LaLiga Fantasy — l'onboarding (enganxar el token) es continua fent des de l'app, pas 4 més avall.

- **Permisos d'Administrador**: instal·lar PostgreSQL o PHP amb `winget` sol fallar silenciosament sense permisos elevats (confirmat amb un cas real: `winget no ha pogut installar PostgreSQL`, sense cap més detall). Si l'script detecta que no s'executa com a Administrador, ofereix reiniciar-se sol en una finestra elevada (`Start-Process -Verb RunAs`) abans de tocar cap paquet.
- **`fileinfo`/`openssl`/`curl`**: `php.ini-development` (la plantilla que fa servir una instal·lació nova de PHP via winget) les deixa comentades per defecte. Laravel les necessita sempre — `fileinfo` la fa servir `league/flysystem` (una dependència de Laravel), i `composer install` no ho detecta fins l'instant d'instal·lar, no abans — així que un `php -m` previ que només comprovi `pdo_pgsql`/`mbstring`/`zip` diu "OK" mentre Composer encara peta. Confirmat amb un cas real: una instal·lació nova on `composer install` fallava únicament per `fileinfo`.
- **Cascada d'errors si `composer install` falla**: sense `vendor/autoload.php`, cridar `php artisan key:generate`/`migrate` igualment només aboca un fatal error de PHP en cascada (dues vegades, un per cada ordre) sense donar cap informació nova — l'script ara comprova que `vendor/autoload.php` existeixi abans d'intentar-ho, i si no, ho salta amb un avís clar en lloc del bolcat cru de PHP.
- **`winget install PostgreSQL` "falla" dues execucions seguides mentre un servidor real ja hi és**: confirmat amb un cas real — l'instal·lador d'EDB darrere del paquet de winget es queda esperant una contrasenya de superusuari interactiva (que en un script no interactiu no arriba mai) i/o winget interpreta algun codi de sortida benigne com a fallada, però el servidor arriba a instal·lar-se i queda escoltant al port 5432 igualment. Dos canvis: (1) el pas de PostgreSQL passa `--override "--mode unattended --unattendedmodeui minimal --superpassword postgres --serverport 5432 ..."` a l'instal·lador d'EDB perquè mai es quedi esperant input, i (2) comprova si el port 5432 ja respon (`Test-PortOpen`) abans de reintentar una instal·lació que winget diu que ha fallat però que, de fet, ja hi és — evita el bucle "reintenta i torna a fallar" a cada execució de l'script.
- **`DB_PASSWORD` buida a `.env.example`**: amb un servidor PostgreSQL que exigeix contrasenya (el cas normal, també el que crea l'script amb `--superpassword postgres`), `php artisan migrate` fallava amb `SQLSTATE[08006] ... fe_sendauth: no password supplied` malgrat tota la resta funcionar — confirmat amb un cas real. L'script ara omple `DB_PASSWORD` a `backend\.env` amb la contrasenya local per defecte (`postgres`) **només quan està buida**, mai sobreescrivint una contrasenya real ja configurada; el pas 3 (creació de la base de dades) també prova aquesta mateixa contrasenya per defecte (`$env:PGPASSWORD`) abans de fallar.
- **Sense certificat CA -> totes les peticions HTTPS de l'app fallen**: PHP per a Windows (el zip oficial i el paquet de winget) no porta cap magatzem de certificats CA propi, a diferència de Linux/Mac on cURL fa servir el de l'OS. Sense `curl.cainfo`/`openssl.cafile` configurats a `php.ini`, qualsevol petició HTTPS via Guzzle falla amb un error de xarxa — confirmat amb un cas real: el login interactiu de LaLiga (que fa una petició HTTPS real al servidor de token d'Azure B2C) queia amb "No s'ha pogut contactar amb el servidor de login de LaLiga", però el mateix problema hauria afectat igualment la sincronització amb l'API de LaLiga i amb futbolfantasy.com un cop configurat el compte. L'script ara detecta si `curl.cainfo` no apunta a un fitxer vàlid i, si no, es descarrega `cacert.pem` (la distribució de certificats CA que publica el mateix projecte cURL) i configura les dues directives.
- **`key:generate` es tornava a executar a cada re-instal·lació, invalidant la sessió de LaLiga ja desada**: confirmat amb un cas real — `php artisan key:generate` reescriu `APP_KEY` incondicionalment cada vegada que es crida (sense demanar confirmació fora d'un `APP_ENV=production`), i l'script el cridava sempre que `vendor/autoload.php` existís, no només la primera vegada. Com que `FantasyAccount.access_token`/`refresh_token` es guarden amb el cast `encrypted` de Laravel, canviar `APP_KEY` després de connectar el compte deixa aquestes columnes indesxifrables per sempre — qualsevol sincronització falla amb `DecryptException: The MAC is invalid.` L'script ara només genera `APP_KEY` la primera vegada (`.env` sense cap valor encara); si ja n'hi ha un, no el toca. **`FantasyAccount::hasValidTokens()`** també atrapa aquest `DecryptException` i el tracta exactament igual que no tenir cap token (missatge clar per tornar a connectar la sessió), en lloc de deixar-lo pujar com un crash sense gestionar — defensa en profunditat per si mai torna a passar (p. ex. movent la base de dades a una màquina amb un `APP_KEY` diferent). **Si ja t'ha passat, l'únic arreglo és tornar a connectar el compte** (`/onboarding`) — les dades xifrades amb la clau vella no es poden recuperar.

### Windows — arrencar l'app amb un clic

Un cop `install-windows.bat` ha acabat sense errors, `fantasy_manager.bat` (arrel del repositori) arrenca l'app del dia a dia sense repetir cap comprovació d'instal·lació: obre quatre finestres noves (`php artisan serve` al backend, `npm run dev` al frontend, `php artisan schedule:work --whisper` — vegeu més avall per què cal — i una sincronització manual d'un sol ús) i el navegador a `http://localhost:5173` al cap de 3 segons. Avisa i s'atura si `backend\vendor` o `frontend\node_modules` no existeixen (executa `install-windows.bat` primer) en lloc de deixar fallar `php`/`npm` amb un error críptic. Per tenir-lo a l'escriptori amb un sol clic: clic dret sobre el fitxer → "Enviar a" → "Escriptori (crear drecera)". Per aturar l'app, tanca les finestres de backend, frontend i planificador (la de sincronització inicial es pot tancar en acabar).

- **Sense `schedule:work` (o cron/Task Scheduler), l'app es queda sense dades**: confirmat amb un cas real — després de connectar el compte i triar la lliga, l'onboarding només desa la lliga/equip triats, no sincronitza res més (`FantasySyncService::selectLeague()` és deliberadament lleuger). Tota la resta — catàleg de jugadors, mercat, plantilla, clàusules de rivals, tendències externes — depèn dels 8 `Schedule::command(...)` de `routes/console.php`, i en producció (Linux) qui els dispara és el cron del sistema cridant `php artisan schedule:run` cada minut; **Windows no té cron**, i cap dels scripts d'aquest repositori arrencava mai res equivalent — per això la pàgina de Jugadors, Mercat, Avui, etc. es queden buides indefinidament encara que el login funcioni. `php artisan schedule:work` (el propi comando de Laravel per a desenvolupament local, sense dependre de cron) resol això mentre estigui obert.
- **"No scheduled commands are ready to run" no és un error**: confirmat amb un cas real — és la sortida normal de `schedule:run` (que `schedule:work` crida cada minut per sota) qualsevol minut en què cap tasca tingui la seva `cron()` complerta, i sortia sense parar perquè les freqüències reals són dilatades (`fantasy:sync-market` cada 15 min, `fantasy:sync-players` cada 60, `fantasy:sync-clauses` cada 120, `fantasy:sync-external-trends` cada 180 — vegeu `config('fantasy.sync')`/`config('fantasy.external')`), no cada minut. `fantasy_manager.bat` ara passa `--whisper` (l'opció pròpia de `schedule:work` per silenciar exactament aquest missatge) i, a més, obre una quarta finestra que executa `fantasy:sync && fantasy:sync-clauses && fantasy:sync-external-trends` un sol cop a l'instant — així la primera càrrega de dades no depèn d'esperar fins a 2-3 hores el primer cicle del planificador.

### Requisits

- PHP 8.4+ amb extensions `pdo_pgsql`, `mbstring`, `zip`
- Composer 2
- Node.js 20+ i npm
- PostgreSQL 16 (local o via Docker)
- Redis (opcional — només si vols `QUEUE_CONNECTION=redis` en lloc de `database`)

### 1. Base de dades

```bash
createdb fantasy
```

(o `docker compose up -d postgres` si prefereixes Docker — vegeu més avall)

### 2. Backend

```bash
cd backend
composer install
cp .env.example .env    # ja ve amb DB_CONNECTION=pgsql i DB_DATABASE=fantasy
php artisan key:generate
php artisan migrate
```

Revisa `.env` — com a mínim:

```env
DB_CONNECTION=pgsql
DB_HOST=127.0.0.1
DB_PORT=5432
DB_DATABASE=fantasy
DB_USERNAME=el_teu_usuari
DB_PASSWORD=

FRONTEND_URL=http://localhost:5173
```

Arrenca el servidor:

```bash
php artisan serve   # http://localhost:8000
```

### 3. Frontend

```bash
cd frontend
npm install
cp .env.example .env   # VITE_API_URL=http://localhost:8000/api
npm run dev             # http://localhost:5173
```

### 4. Primer ús

1. Obre `http://localhost:5173`, crea un compte (això és només el login de l'app, **no** el de LaLiga).
2. Se't portarà a `/onboarding`:
   - **Pas 1** — enganxa el `access_token` (i `refresh_token` si el tens) d'una sessió ja iniciada a LaLiga Fantasy. Mai cal la teva contrasenya de LaLiga.
   - **Pas 2** — "Detectar les meves lligues" i selecciona la teva lliga.
   - **Pas 3** — "Sincronitzar ara".
3. Al cap d'unes desenes de segons (la primera sync pot trigar, hi ha molts jugadors), ves al Dashboard: **"Què he de fer avui?"**.

### Com connecto el meu compte de LaLiga Fantasy?

LaLiga Fantasy fa servir OAuth2 / Azure AD B2C. Un "Connectar amb LaLiga" d'un sol clic amb redirecció automàtica **no és viable des d'una web app**: ho vam comprovar empíricament fent una petició al seu `/authorize` amb un `redirect_uri` nostre, i Azure B2C la rebutja amb `AADB2C90006: redirect URI ... is not registered`. Els únics `client_id` coneguts només accepten redirigir a `miliga.laliga.com` (client web) o a l'esquema natiu `authredirect://com.lfp.laligafantasy` (app mòbil/Electron) — cap dels dos torna a un domini que controlem.

**Via recomanada — login interactiu (`/onboarding` pas 1)**: sí que fem servir el flux real Authorization Code + PKCE (el mateix que [Externoak/LaLigaApp](https://github.com/Externoak/LaLigaApp) i [jonortega20/fantasybot](https://github.com/jonortega20/fantasybot), que el documenten des de, respectivament, una app d'Electron i una CLI). L'app genera l'enllaç de login real de LaLiga amb un repte PKCE (`FantasyAuthService::startInteractiveLogin()`), l'obres en una pestanya i inicies sessió (funciona amb Google/Apple, sense contrasenya pròpia de LaLiga). En acabar, el navegador intenta obrir `authredirect://com.lfp.laligafantasy?code=...` i falla — normal, cap navegador d'escriptori té cap aplicació registrada per a aquest esquema. Amb les DevTools (Network, "Preserve log") es veu aquesta petició fallida amb la URL completa; enganxant-la a l'app (`FantasyAuthService::finishInteractiveLogin()`) fem el bescanvi del codi pel parell de tokens **al backend**, amb un `refresh_token` real de fins a 90 dies. És l'únic pas manual que queda — la resta del flux OAuth és real, no un pedaç.

  La sessió PKCE guardada al servidor és d'un sol ús (`Cache::pull`) — confirmat amb un cas real i reproduït directament contra l'API: prémer "2. Connectar" dues vegades seguides (un doble clic abans que React desactivi el botó) fa que la primera petició consumeixi la sessió i la segona, encara que sigui a l'instant, la trobi buida i mostri "L'enllaç de login ha caducat" — un missatge confús que sembla un problema de temps (es va arribar a atribuir a la lentitud del login amb Apple via QR) quan en realitat és un doble enviament. `Onboarding.jsx` ara talla la segona crida en sec amb un guard síncron (`useRef`, comprovat abans de qualsevol `setState`) perquè el `disabled` de React, que només s'aplica al DOM en el següent render, no arribi a temps.

**Alternatives** (`/onboarding`, seccions plegables) per si ja tens una sessió activa i no vols tornar a iniciar sessió:

1. **Bookmarklet**: un botó que arrossegues a la barra de marcadors. Un cop a `fantasy.laliga.com` amb sessió iniciada, el cliques i mostra un panell flotant amb els dos tokens, capturats escoltant la pròpia xarxa del navegador (intercepta `fetch`/`XHR`) — mai surt res del teu navegador. Font: `frontend/src/bookmarklet/tokenGrabber.js`.
2. **Manual**: DevTools del navegador → pestanya Network → busca una petició a `fantasy-api.llt-services.com` → capçalera `Authorization: Bearer ...`.

En qualsevol dels tres casos, l'app **no implementa mai el login amb contrasenya** (evitem tocar la teva contrasenya de LaLiga) i, un cop desat el `refresh_token`, el manté viu automàticament (`FantasyAuthService::refresh()`, amb el `client_id` que va emetre els tokens) sense que hagis de tornar a fer-ho cada dia.

**Desconnectar la sessió**: `/settings` té una secció "Compte de LaLiga Fantasy" amb un botó "Desconnectar LaLiga Fantasy" (amb confirmació d'un sol pas, sense diàleg natiu del navegador) que crida `DELETE /fantasy-account` (`FantasyAccountController::destroy()`, ja existent al backend) — buida només `access_token`/`refresh_token`/`token_client_id`/`token_expires_at`, mai la resta de dades ja sincronitzades (jugadors, mercat, historial), i et torna a `/onboarding` per tornar a connectar. Fins ara l'endpoint no tenia cap botó que el cridés — l'única manera de tornar a connectar una sessió trencada (p. ex. per un `APP_KEY` rotat, vegeu més amunt) era editant la base de dades a mà.

## Sincronització

Comandes disponibles (totes accepten `--account=<id>` per limitar-les a un compte):

```bash
php artisan fantasy:sync                     # players + market + team + recomanacions, en aquest ordre
php artisan fantasy:sync-players              # catàleg complet de jugadors + snapshot
php artisan fantasy:sync-market               # mercat de la lliga activa + snapshot
php artisan fantasy:sync-team                 # plantilla pròpia, saldo i classificació
php artisan fantasy:sync-clauses              # plantilles rivals (clàusules) + Clause Opportunity Score
php artisan fantasy:generate-recommendations  # motor de recomanacions + informe diari
```

També es pot llançar des de la UI (botó "Sincronitzar ara") o `POST /api/fantasy/sync`, que encua `RunFantasySyncJob` — cal un **queue worker** corrent:

```bash
php artisan queue:work
```

### Scheduler

Definit a `routes/console.php`, amb freqüències (minuts) configurables per `.env` — **no facis polling agressiu**, LaLiga limita amb 429:

```env
FANTASY_SYNC_MARKET_FREQUENCY=15
FANTASY_SYNC_TEAM_FREQUENCY=30
FANTASY_SYNC_PLAYERS_FREQUENCY=60
FANTASY_RECOMMENDATIONS_FREQUENCY=30
FANTASY_SYNC_CLAUSES_FREQUENCY=120
FANTASY_SNAPSHOT_DECISIONS_FREQUENCY=720
FANTASY_EVALUATE_DECISIONS_FREQUENCY=720
```

`fantasy:snapshot-decisions` i `fantasy:evaluate-decisions` (vegeu "Rendiment de l'assistent" més avall) hi corren dues vegades al dia per defecte — no cal més sovint, els horitzons que avaluen són de dies, no de minuts.

**`App\Console\CronFrequency::everyMinutes()`** — bug real detectat en producció (confirmat amb `php artisan schedule:list` en viu): el camp de minuts d'una expressió cron només accepta valors 0-59, així que `routes/console.php` construint sempre `"*/{N} * * * *"` fos quin fos `N` col·lapsava silenciosament a "minut 0 de cada hora" per a qualsevol freqüència ≥60 — `fantasy:sync-clauses` (120 min, cada 2h previstes), `fantasy:sync-external-trends` (180 min, cada 3h) i `fantasy:snapshot-decisions`/`fantasy:evaluate-decisions` (720 min, cada 12h) s'executaven totes cada hora en lloc de la freqüència configurada. `CronFrequency::everyMinutes()` ara converteix qualsevol freqüència múltiple net de 60 en una expressió per hores (`0 */H * * *`) en lloc de minuts; per sota de 60 es queda igual que abans.

En producció, un únic cron entry:

```cron
* * * * * cd /path/to/backend && php artisan schedule:run >> /dev/null 2>&1
```

En desenvolupament, `php artisan schedule:work` fa el mateix en primer pla.

### Tendència de valor externa (futbolfantasy.com, no oficial)

Vam comprovar-ho exhaustivament (payload del jugador, catàleg, plantilla): **l'API de LaLiga mai retorna històric de valor**, només el `marketValue` actual. `futbolfantasy.com` sí que en publica (1/2/3/7/14/30 dies) a la seva pàgina pública de mercat — no és de LaLiga, no té login, és HTML pla.

```bash
php artisan fantasy:sync-external-trends
```

- **`FutbolFantasyClient`** fa l'scraping (regex sobre `data-*`, tolerant a canvis de marcatge — si el disseny canvia, retorna menys files, no peta).
- **`PlayerNameMatcher`** hi casa els jugadors per nom (IDs diferents als de LaLiga): nom complet, després cognom sol (moltes entrades nostres només guarden el cognom curt), i també els prefixos de dues o més paraules del nostre nom complet ("Gerard Moreno Balagueró" -> també "gerard moreno") — molts jugadors amb dos cognoms surten publicats amb només el primer, i sense aquest prefix es quedaven sense casar malgrat ser inequívocs (cas real detectat: Gerard Moreno). Desempata per club quan cal. Resultat real: **~84% de coincidència** (568/675); la resta són sobretot jugadors d'equips fora de Primera o absents del nostre catàleg — es queden sense casar en lloc d'arriscar un match erroni.
- Es guarda a `fantasy_external_trends`, exposat a `/api/market` i `/api/players/{id}` com a `externalTrend`, sempre etiquetat "no oficial" a la UI.
- **Mai alimenta `FantasyScoreService` ni el motor de recomanacions** — és merament informatiu. Barrejar una font no verificada de tercers amb els números que decideixen un COMPRAR/VENDRE trencaria la promesa de l'app de no presentar mai una predicció incerta com un fet.
- Font: `github.com/jonortega20/fantasybot` (mòdul `sources/value_history.py`) documenta exactament aquesta mateixa limitació de l'API de LaLiga i fa servir la mateixa font externa.
- **`SparklineHistoryBuilder`** genera la sèrie de punts a partir dels nostres propis `fantasy_player_snapshots` quan n'hi ha prou (≥3 valors diferents), i si no, la completa amb els valors absoluts per dia de futbolfantasy — mai inventats. Per defecte cobreix 7 dies (Mercat/El meu equip/Clàusules, on hi ha un "7D EXT." al costat que ha de quadrar exactament); la fitxa de cada jugador (`/players/{id}`) hi passa una finestra de 30 dies (`dayOffsets: [30, 14, 7, 3, 1]`) per al gràfic complet d'evolució de valor. La resposta inclou `historySource` (`own`/`external`) perquè la UI etiqueti "no oficial" quan calgui.

## Motor de recomanacions (MVP)

`FantasyRecommendationEngine` genera **BUY / SELL / HOLD** (les accions de clàusules/trading/blindatge queden preparades a l'esquema i a `FantasyRecommendation::ACTION_*`, però encara no les genera el motor — vegeu "Estat i properes fases").

- **Tendència** (`TrendAnalysisService`): variació 24h/3d/7d sobre `fantasy_player_snapshots`, classificada en `MOLT_ALCISTA…MOLT_BAIXISTA`.
- **Fantasy Score** (`FantasyScoreService`): 0-100, pesos configurables (`fantasy_settings`, veure `/api/settings`). Dos factors (`calendar`, part de `starter_likelihood`) encara no tenen font de dades real (no hi ha sync de calendari/alineacions probables) i es queden a un 50 neutre en lloc d'inventar-se un número — la `confidence` retornada ho reflecteix.
- **Venda**: no ven només perquè el valor baixa — compara la pèrdua prevista contra el rendiment esportiu (Fantasy Score alt = `HOLD` encara que el valor caigui).
- **Compra**: exigeix pujada de valor + Fantasy Score alt, i calcula oferta recomanada (+3% sobre valor) i oferta màxima (`maximum_bid_over_market_percentage`), sempre acotades pel **capital disponible** (`saldo - reserva mínima configurable`).
- Totes les regles (`sell_daily_drop_threshold`, `buy_growth_threshold`, `minimum_cash_reserve`, ...) viuen a `fantasy_settings` (per compte, amb fallback a `config/fantasy.php`) i s'editen des de `/settings` a la UI.

### Clàusules (Clause Economic Score)

`GET /teams/{teamId}/lineup` de LaLiga només funciona per al teu propi equip — per a qualsevol altre retorna **403**, confirmat en viu. La plantilla (i clàusula) d'un rival només es pot llegir a través de l'endpoint amb àmbit de lliga `GET /v1/competition/1/leagues/{leagueId}/teams/{teamId}`, que a més és l'única resposta que inclou `buyoutClauseLockedEndTime` i `isShielded` (blindatge).

- **`FantasySyncService::syncRivalRosters()`** recorre tots els equips `is_mine = false` de la lliga activa i en desa la plantilla a `fantasy_team_players` (clàusula, bloqueig temporal, blindatge) — un equip que falla (privat, error puntual) no atura la resta.

- **`ClauseEconomicAnalysisService`** respon una única pregunta, matemàticament: *si pago aquesta clàusula avui, recuperaré la prima gràcies a l'evolució del valor de mercat?* És **purament econòmic i prospectiu** — mai Fantasy Score, mai una comparació amb la teva plantilla:
  1. **Taxes de creixement compostes** `growth1d`/`growth3d`/`growth7d` = `(valorActual / valorFaN)^(1/n) - 1` — no un simple % dividit pels dies, perquè així un +3% en un jugador de 5M pesa més que un +3% (mateix €) en un de 20M. Els valors històrics prioritzen sempre els nostres propis `fantasy_player_snapshots` i només recorren a futbolfantasy.com finestra a finestra quan la pròpia no hi és (p. ex. tenim l'1D propi però no el 7D).
  2. **`expectedDailyGrowth`**: mitjana ponderada de les tres taxes, pesos a `config('fantasy.clause_analysis.trend_weights')` (per defecte 50%/30%/20%, validat que sumin 1 — llança excepció si no). Si falta alguna finestra, es renormalitza entre les disponibles en lloc de tractar-la com un 0%.
  3. **Projecció composta amb *trend decay*** (`MarketValueProjector`): mai `valor + increment×dies` (lineal). Cada dia compon `value *= 1 + expectedDailyGrowth × decayFactor(dia)`, amb `decayFactor` llegit de `config('fantasy.clause_analysis.decay_bands')` (per defecte dies 1-3 al 100%, 4-7 al 80%, 8-14 al 50%; un dia més enllà de l'última banda es queda al factor de l'última). D'aquí surten `expectedValue3d/7d/14d`.
  4. **Benefici i ROI sobre el cost total** — mai només la prima: `profitNd = expectedValueNd - clauseValue`, `roiNd = profitNd / clauseValue` (guardat com a decimal, p. ex. `0.125`; la UI el multiplica per 100 en mostrar-lo).
  5. **Break-even simulat dia a dia** (no una fórmula logarítmica, perquè el decay canvia la taxa efectiva a mig camí): `breakEvenDays` és el primer dia en què el valor projectat iguala la clàusula, fins a `config('fantasy.clause_analysis.max_break_even_days')` (30 per defecte) — `null` si no s'hi arriba. Dos casos especials: clàusula ja `<=` valor de mercat → `0` immediatament; creixement `<= 0` amb clàusula per sobre del valor de mercat → `null` directament (matemàticament impossible d'arribar-hi amb un decay que només frena, mai accelera).
  6. **`clauseEconomicScore`** (0-100): `roi14d` interpolat linealment entre -10% (→0) i +10% (→100), multiplicat per un factor segons el break-even (≤5 dies → ×1.00, 6-10 → ×0.90, 11-14 → ×0.75, >14 → ×0.50, sense break-even → ×0). D'aquí surt `classification` (EXCEPTIONAL/VERY_GOOD/GOOD/NEUTRAL/BAD/VERY_BAD) i **`economicRecommendation`** (PAY_CLAUSE ≥75, CONSIDER 60-74, WAIT 40-59, DO_NOT_PAY <40) — deliberadament amb aquest nom perquè no es confongui amb les recomanacions esportives BUY/SELL/HOLD de la resta de l'app.
- **Ni el bloqueig temporal (`isLocked`/`daysUntilUnlock`) ni el blindatge (`isShielded`) ni el capital disponible (`affordable`) bloquegen `economicRecommendation`** — els tres són temporals o poden canviar abans que calgui actuar, així que es mostren a la UI com a informació, no com a filtre. El Fantasy Score es continua retornant per a la taula, però és merament informatiu i no forma part del càlcul.
- `fantasy:sync-clauses` fa el sync de rivals i, a més, deixa un registre històric a `fantasy_clauses` (una fila per jugador rival i execució, amb `analysis` guardant l'objecte complet) — pensat per a backtesting futur, seguint el mateix patró *append-only* que `fantasy_player_snapshots`.
- La pàgina `/clauses` mostra totes les oportunitats analitzades i ordenables per qualsevol columna (per defecte, per `clauseEconomicScore` descendent): valor, clàusula (amb els dies que falten per desbloquejar-se), prima, ROI a 14 dies, dies de recuperació de la prima i l'`economicRecommendation`.

### Compra automàtica de clàusules (ClausePurchaseOrderService)

**La primera acció d'escriptura automatitzada de l'app contra l'API real de LaLiga.** Tots els endpoints d'escriptura (`bid`, `payClause`, `increaseClause`, `shieldPlayer`) han estat deliberadament sense usar mai automàticament — l'app ha estat de només lectura, sempre un humà clicant un botó. Aquí és diferent: l'usuari pot programar la compra d'un jugador rival via clàusula, i el sistema la paga sol quan es desbloqueja, sense confirmació, si el preu no ha pujat.

- **`fantasy_team_players.player_team_id`**: l'id de "aquest jugador en aquest equip", diferent de l'id global del jugador — confirmat en viu com a camp germà de `playerMaster` a `getLeagueTeamRoster()`. `checkShield()` el necessita (`player-team/{id}` — 403 amb l'id global) i és l'únic que identifica sense ambigüitat a qui es paga una clàusula, així que `payClause()` també l'utilitza aquí, tot i que aquest endpoint mai s'havia cridat abans i el seu contracte exacte és una assumpció, no un fet confirmat — si l'assumpció és errònia, la crida falla (`FAILED` + `error_message`), mai gasta diners incorrectament.
- **`ClausePurchaseOrderService::createOrder()`**: desa el `clause_value_at_order` actual i el `player_team_id`, amb el mateix escopament per lliga que la resta de l'app (un jugador rival d'una altra lliga mai pot generar una ordre).
- **`processPendingOrders()`** (`fantasy:process-clause-orders`, cada `FANTASY_SYNC_CLAUSE_ORDERS_FREQUENCY` minuts — 5 per defecte, molt més sovint que els 120 de `fantasy:sync-clauses`, perquè aquí la rapidesa és tot el que importa) **mai es fia de l'últim sync** — a cada execució torna a llegir `getLeagueTeamRoster()` en viu per a cada ordre pendent:
  1. El jugador ja no és d'aquell equip → `FAILED`.
  2. Clàusula encara bloquejada (`buyoutClauseLockedEndTime` futur) → es queda `PENDING`, es torna a provar al següent cicle.
  3. Desbloquejada i preu igual o inferior a `clause_value_at_order` → `payClause()` automàtic, `EXECUTED`.
  4. Desbloquejada i preu superior → `NEEDS_CONFIRMATION`, **mai paga sola** — cal que l'usuari confirmi el preu nou des de la fitxa del jugador o des de l'avís a `/` (Avui).
- Una ordre `NEEDS_CONFIRMATION` o `EXECUTED` fallida (`FAILED`) mai bloqueja crear-ne una de nova pel mateix jugador — només ho fa una `PENDING`/`NEEDS_CONFIRMATION` activa.
- **Si no hi ha prou diners** (o `payClause()` es rebutja per qualsevol altre motiu real), l'app no gasta res que no tingui: LaLiga mateix rebutja la crida, `execute()` no arriba mai a actualitzar l'ordre a `EXECUTED`, i queda `FAILED`. `describeFailure()` intenta mostrar el motiu real que torna l'API (buscant claus habituals `message`/`error`/`errorMessage`/`detail` al cos de la resposta) en lloc d'un genèric "rejected (400)" — el format exacte del cos d'error de `payClause()` no estava confirmat abans d'aquesta funcionalitat, així que si LaLiga fa servir una clau diferent, `error_message` cau al missatge genèric més el cos sencer en JSON, mai es perd la informació.

### Plantilla d'un rival (des de la Classificació)

`/standings` enllaça cada equip a `/standings/{teamId}` (el propi, a `/team` — mai al mateix endpoint), que mostra la plantilla completa d'aquell rival amb el mateix format visual que la teva pròpia plantilla (valor, esparklín, tendència 7D, Fantasy Score), però **sense cap HOLD/SELL** — `PlayerDecisionEngine` respon "hauria de mantenir o vendre *el meu* jugador?", una pregunta que no té sentit sobre la plantilla d'algú altre. En comptes d'això, cada jugador mostra el mateix veredicte de clàusula (`PAY_CLAUSE`/`CONSIDER`/`WAIT`/`DO_NOT_PAY`) que ja es veu a `/clauses` i a la fitxa individual del jugador (context `OWNED_BY_RIVAL`) — reutilitzat via **`RivalClausePresenter`**, extret de `PlayerController` perquè cap d'aquests tres llocs dupliqui el mateix càlcul de favors/riscos.

- **`TeamController::rival()`** (`GET /standings/{team}`) rebutja amb 404 el teu propi equip (`is_mine`) i qualsevol equip fora de la teva lliga activa — mai exposa dades d'una lliga que no és la teva.
- **Dos buits de dades honestos, mai inventats**: `isStarter` és sempre `null` (l'endpoint amb àmbit de lliga que sincronitza els rivals no torna titularitat/banqueta — vegeu la nota de `getLeagueTeamRoster()` més amunt), i el **saldo és sempre `null`** (l'API de LaLiga només exposa `money` per al teu propi equip — mai s'intenta ni es simula per a un rival). `teamValue`, en canvi, sí que es pot calcular sempre encara que `fantasy_teams.team_value` no s'hagi sincronitzat mai per a un rival: es fa amb la mateixa fórmula `sum(marketValue)` que `FantasySyncService::syncTeam()` fa servir per al teu equip.
- Un jugador rival sense clàusula coneguda mostra `—` en lloc de qualsevol veredicte — mateix criteri que la fitxa individual.

### Motor de decisions de la plantilla (HOLD / SELL / LOCK_CLAUSE)

`PlayerDecisionEngine` decideix què fer amb cada jugador **propi** (pàgina `/team`) comparant tres puntuacions 0-100 independents en lloc de reaccionar a un sol senyal (p. ex. "el valor baixa → vendre"). El guanyador només compta si supera el segon per almenys `config('fantasy.player_decision.decision_margin')` punts (8 per defecte) — una diferència petita sempre resol en `HOLD`, deliberadament: el motor només vol senyalar situacions clares, no fer soroll.

- **Hold score**: 40% valor esportiu (punts esperats de les últimes 4 jornades *reals*, ponderats 40/25/15/10% + 10% mitjana de temporada, renormalitzat quan encara no hi ha 4 jornades jugades, multiplicat per una probabilitat de titularitat estimada) + 30% tendència de valor (mateixa taxa composta i pesos que `ClauseEconomicAnalysisService`, `config('fantasy.clause_analysis.trend_weights')`) + 20% escassetat (diferència de Fantasy Score amb el millor jugador disponible al mercat en la mateixa posició — sense alternativa al mercat, escassetat alta per defecte) + 10% protecció de clàusula.
  - **`FantasyPlayer::weekPointsBreakdown()`** — les 4 jornades reals venen d'aquí, no directament de `raw_payload['weekPoints']`. Bug real detectat: aquest camp només té la forma `[{weekNumber, points}]` quan el darrer sync d'un jugador va passar per l'endpoint de mercat/catàleg; quan passa per l'endpoint de plantilla/alineació — és a dir, **sempre** per a qualsevol jugador propi — `weekPoints` queda com un escalar (només l'última jornada) i el desglossament real cau sota `lastStats[].totalPoints` en comptes. Llegir només `weekPoints` feia que `expectedWeeklyPoints()` veiés "zero jornades jugades" per a tota la plantilla, enfonsant `starterProbability`/`sportingScore` (i per tant `holdScore`) de tots els jugadors propis per sota del real — vam detectar-ho amb un cas real (Sergio Canales recomanat a pujar la clàusula amb `starterProbability` de només 30% tot i haver jugat les 4 últimes jornades). Ara normalitza les dues formes, preferint `weekPoints` quan és l'array correcte i caient a `lastStats` si no.
- **Sell score**: 25% liquiditat (com més ajustat el capital disponible respecte a `minimum_cash_reserve`, més puja) + 35% pèrdua evitada (projecció a 7 dies amb `MarketValueProjector`) + 20% millora potencial (alternativa més barata al mercat amb Fantasy Score similar o millor) + 20% valor futur perdut invertit (penalitza vendre qui encara rendirà o pujarà molt).
- **Clause score** (`LOCK_CLAUSE`, només amb clàusula pròpia): 45% risc de robatori + 30% tendència de mercat + 25% eficiència de pujar-la. El risc de robatori es calcula **només** a partir de la prima actual (`(clàusula - valor) / valor`) — deliberadament sense barrejar-hi la tendència de mercat, que ja és un factor separat amb el seu propi 30%; barrejar-los va fer, en una prova amb dades reals, que una clàusula ja molt protegida (42% de prima) sortís recomanada per pujar només perquè el jugador pujava molt de valor. Una clàusula barata i ja desprotegida pot guanyar per si sola (risc de robatori + eficiència) encara que el valor no pugi gens — legítim (protegir-se d'un robatori no depèn de la tendència), però el `reason` ha de dir-ho amb honestedat: la frase "el valor continua a l'alça" només apareix quan la revalorització *real* a 7 dies (`trade.appreciation7d`, no la taxa composta esbiaixada cap a l'1 dia que fa servir la puntuació) és clarament positiva; si és clarament negativa ho diu, i si és ambigua no afirma cap direcció — abans es mostrava sempre, encara que el valor estigués pla o baixant (detectat amb un cas real: Sergio Canales).
- **Decisió i confiança**: `action` = la puntuació més alta si supera la segona pel marge configurat, si no `HOLD`. `confidence` (10-97) combina la mida d'aquest marge amb la qualitat de les dades disponibles (historial de valor, jornades recents, clàusula, alternatives de mercat).
- **`reason`**: explicació determinista en català, 2-3 frases amb xifres reals (mai generada per IA), diferent segons l'acció.
- **Buits de dades honestos** (mai inventats, `confidence` reduïda en conseqüència, mateix esperit que el `calendar` neutre de `FantasyScoreService`): **saldo dels rivals** (no hi ha cap sync que el llegeixi — l'endpoint de plantilla rival no l'exposa), i **minuts jugats / probabilitat real de titularitat** (no existeixen enlloc al payload de LaLiga; `starterProbability()` és una aproximació basada en si el jugador ha puntuat a les jornades recents reals, no un número inventat).
- Tots els pesos (`hold_weights`, `sell_weights`, `clause_weights`, `recent_form_weights`, `trade_weights`) viuen a `config('fantasy.player_decision')`, cada grup validat que sumi 1.0 al constructor (llança excepció si no).

#### Trade Score — vendre per benefici encara que el jugador sigui bo

El Sell Score respon "és mal moment esportiu/econòmic per tenir-lo?". El **Trade Score** respon una pregunta diferent i complementària: *encara que sigui bo, és un bon moment per vendre'l i realitzar el guany?* No és una quarta acció — és un **bonus auxiliar** que només pot fer pujar el Sell Score, mai crear una acció de venda per si sol ni reduir-lo:

- **Revalorització 7d** (25%): `(1+growth7d)^7 - 1`, 0%→0 i 20%→100.
- **Esgotament de momentum** (30%): `null` sense dades de 3d; `0` si `growth3d <= 0` (no hi ha pujada prèvia — això és depreciació, no un moment per fer trading, mai es confonen); si no, `growth1d/growth3d` a prop d'1 (encara pujant igual) → score baix, i a prop de 0 o negatiu (ja s'ha frenat/girat) → score alt.
- **Upside futur** (20%, invertit: `100 - futureUpsideScore`): projecció a 7 dies (`MarketValueProjector`) — com menys marge de pujada li queda, més puja aquest component; `null` (renormalitza la resta) si no hi ha cap dada de tendència en absolut, per no confondre "sense dades" amb "sense recorregut".
- **Prima de l'oferta actual** (15%): `(oferta - valorMercat) / valorMercat` sobre l'oferta pendent més alta rebuda (`FantasyOffer`, `status=PENDING`) pel jugador; `null` si no n'hi ha cap — **mai s'inventa una oferta**.
- **Eficiència del capital** (10%): diferència entre el millor ROI a 7 dies disponible al mercat en la mateixa posició i l'upside propi del jugador — vendre val més la pena com més bona sigui l'alternativa a què es podria destinar el capital.
- Qualsevol component sense dades s'exclou i els pesos restants es renormalitzen (mateix patró que `expectedDailyGrowth`).
- `tradeBonus = max(0, tradeScore - 50) × 0.30`; `adjustedSellScore = clamp(sellScore + tradeBonus, 0, 100)` — per sota de 50 el bonus és sempre 0, així un Trade Score mediocre mai penalitza el Sell Score. `adjustedSellScore` és el que competeix contra `holdScore`/`clauseScore`, no el `sellScore` original (tots dos es retornen).
- Quan `action = SELL`, `sellReasonCode` explica per què: `TRADE_PROFIT` (Trade Score ≥75 i el bonus és rellevant, ≥5 punts) o, si no, el component dominant del Sell Score original (`LIQUIDITY_NEED`, `DEPRECIATION`, `CAPITAL_REALLOCATION`, `LOW_PERFORMANCE`) segons quin va pesar més.
- Reutilitza la mateixa font de tendència (`config('fantasy.clause_analysis.trend_weights')`), el mateix `MarketValueProjector`, i la mateixa consulta d'alternatives de mercat que ja usava `FantasyRecommendationEngine` — no hi ha un segon motor de tendències ni una segona query de mercat.

#### Timing de pujada de clàusula — saber-ho no vol dir fer-ho ja

Que el `clauseScore` digui que val la pena pujar la clàusula no vol dir que calgui fer-ho avui: pujar-la massa aviat gasta capital sense afegir cap protecció addicional mentre encara està bloquejada. El motor separa el senyal econòmic (`shouldRaise`) de si és el moment segur d'executar-lo (`shouldRaiseNow`):

- Llegeix el bloqueig real (`clause_locked_until`, sincronitzat des de l'endpoint amb àmbit de lliga — vegeu més avall) i calcula les hores restants amb precisió de timestamp, no arrodonint a dies quan hi ha l'hora exacta disponible.
- Finestra seguretat: `24 + safety_margin_hours` hores (`config('fantasy.player_decision.clause_timing.safety_margin_hours')`, 6 per defecte) — només dins d'aquesta finestra abans que caduqui la protecció actual és "ara". Si la clàusula ja està desbloquejada, `shouldRaiseNow` és immediatament cert (no hi ha finestra a esperar).
- Quan el guanyador natural és `LOCK_CLAUSE` però `shouldRaiseNow` és fals, `action` es queda en `HOLD` (mai s'inventa una acció nova) i el resultat porta un bloc `clauseTiming` (`shouldRaise`, `shouldRaiseNow`, `daysRemaining`/`hoursRemaining`, `recommendedExecution`) perquè la UI pugui avisar sense empènyer a actuar abans d'hora.
- Es recalcula sencer cada vegada — no hi ha cap pla guardat que s'executi sol; si el Clause Score baixa abans de la finestra d'execució, el pla d'aquell dia queda obsolet i no es puja la clàusula.
- A `/team`, un `HOLD` amb `shouldRaise` però no `shouldRaiseNow` mostra "🔒 Pujar clàusula en N dies" en lloc de repetir "PUJAR CLÀUSULA" dia rere dia; l'acció urgent només apareix el dia real d'execució.
- **`FantasySyncService::syncTeamRoster()`**: per al propi equip, la plantilla completa es llegeix de `getLeagueTeamRoster()` (la mateixa crida amb àmbit de lliga que ja s'usava per als rivals) i no de `getLineup()` — confirmat en viu que `getLineup()` només retorna els jugadors col·locats a la formació de la jornada actual (titulars + el que hi hagi al banc), i ometia en silenci qualsevol jugador de la plantilla que no hi estigués col·locat. Això feia que cada sync esborrés aquests jugadors de `fantasy_team_players` i infravalorés el valor de l'equip. `getLineup()` encara es consulta per al propi equip, però només per saber quins d'aquests jugadors són titulars ara mateix; també és l'única font de `buyoutClauseLockedEndTime`/`isShielded`, que `getLineup()` no dona.

##### Fins a quant pujar-la — dos objectius propis, mai un límit de LaLiga

`FantasyClauseService::increaseClause()` (l'endpoint real que puja una clàusula, `PUT .../buyout/player` amb `factor`/`valueToIncrease`) existeix al codi però **mai s'ha arribat a cridar** — no sabem què signifiquen exactament aquests paràmetres, ni si LaLiga imposa cap límit real de fins on es pot pujar. Per no inventar-nos una xifra i presentar-la com si fos una regla oficial, `clauseTiming()` calcula dos objectius **propis i declarats com a tals**, mai com un límit confirmat:

- **Rendible fins a**: `expectedValue14d` — la mateixa projecció a 14 dies que fa servir la resta del motor (`MarketValueProjector`). Pujar-la per sobre d'això immobilitza capital sense cap suport real en la trajectòria del jugador; si ja és inferior a la clàusula actual, és un senyal real que la clàusula ja està per sobre del que seria rendible.
- **Anti-robatori des de**: `marketValue × 1.5` — exactament la prima (+50%) que `THEFT_PREMIUM_BOUNDS` ja tractava internament com a risc de robatori negligible (`theftRiskScore()`), ara expressada en euros en lloc de només com a puntuació 0-100 interna.

Els dos valors es calculen sempre que hi ha una clàusula coneguda (independentment de l'acció), es guarden a `clauseTiming.profitableTarget`/`clauseTiming.antiTheftTarget`, i es mostren allà on ja es parlava de pujar la clàusula sense duplicar cap càlcul: la frase de `reason` (HOLD amb `shouldRaise` pendent, i LOCK_CLAUSE), les dues noves caselles a la fitxa individual del jugador, i `TodayActionCard`'s targetes de "Pujar clàusula" a `/`.

**Cost real de pujar-la**: confirmat per un usuari real del joc (no per l'API, que mai s'ha arribat a cridar) — pagar X puja la clàusula 2X (`CLAUSE_RAISE_COST_RATIO = 0.5`). Cada objectiu porta el seu propi `*Cost` (`profitableTargetCost`/`antiTheftTargetCost` = `max(0, (target - clàusulaActual) × 0.5)`, `0` quan l'objectiu ja s'ha assolit — mai negatiu, mai cal "baixar" la clàusula), mostrat com a "et costaria X" (o "ja hi ets") sota cada xifra, a tots tres llocs. Aquesta ràtio és coneixement real del joc confirmat per un jugador, no una dada verificada contra l'API en viu — per això es documenta i es tracta amb el mateix nivell de confiança diferenciat que la resta d'aquesta app fa servir per distingir "confirmat en viu" de "sabut per algú que hi juga".

### Mercat (Buy Economic Score)

Al costat de la recomanació esportiva BUY/SELL/HOLD que ja existia a `/market` (Fantasy Score + tendència, columna "Acció"), cada fitxatge del mercat porta ara una segona columna, "Econòmic", amb un veredicte independent que **ignora deliberadament** punts, rendiment, titularitat, rival, posició i necessitat de plantilla — respon una única pregunta: *si compro aquest jugador a aquest preu, és econòmicament rentable?* Les dues columnes sovint divergeixen a propòsit (p. ex. un jugador amb Fantasy Score mediocre però una clàusula/preu molt per sota de mercat surt `MANTENIR` esportivament i `COMPRAR` econòmicament).

- **`MarketBuyAnalysisService`** és el mirall de `ClauseEconomicAnalysisService` per a compres de mercat en lloc de clàusules, i **reutilitza literalment** el mateix motor de tendència en lloc de duplicar-lo:
  - `PlayerValueTrendCalculator` (nou, extret d'aquest mateix `ClauseEconomicAnalysisService`/`ClauseOpportunityService` en aquesta feina) calcula `growth1d/3d/7d` i `expectedDailyGrowth` — els mateixos pesos, `config('fantasy.clause_analysis.trend_weights')`, no una segona configuració.
  - `MarketValueProjector` (el mateix de sempre) projecta `projectedValue3d/7d/14d` amb el mateix decay compost.
  - El break-even dia a dia ara viu en un únic lloc, `MarketValueProjector::daysToReach()` — extret del que abans era un mètode privat dins `ClauseEconomicAnalysisService` — perquè clàusules i mercat comparteixin exactament el mateix simulador en lloc de tenir cadascun el seu.
- `expectedProfitNd`/`expectedROINd` es calculen contra l'**`acquisitionPrice`** (el preu real de sortida de la subhasta, `asking_price`, mai un preu inventat) en lloc del valor de mercat — el mateix jugador pot sortir `COMPRAR` a un preu i `NO_COMPRAR` a un altre; recalcular-ho a un preu diferent és una simple crida amb un altre `acquisitionPrice` (`GET /market/{id}/buy-analysis?acquisition_price=...`, pensat per a un "i si oferto X" a la UI).
- **`BuyEconomicScore`** (0-100): `roi14d` interpolat entre -10% (→0) i +10% (→100), multiplicat per un factor de break-even amb les seves pròpies bandes (més exigents que les de clàusules: ≤3 dies ×1.00, 4-7 ×0.90, 8-10 ×0.80, 11-14 ×0.65, >14 ×0.50, sense break-even ×0 — `config('fantasy.market_buy_analysis.break_even_factor_bands')`) — una subhasta competeix amb altres compradors avui, així que un retorn lent és una aposta més feble aquí que la mateixa clàusula. `classification` (EXCEPTIONAL…VERY_BAD) i `recommendation` (BUY ≥75, CONSIDER 60-74, WAIT 40-59, DO_NOT_BUY <40) segueixen el mateix patró que `economicRecommendation` de clàusules.
- **`MaxBid`** = `projectedValue14d / (1 + requiredROI)` (`config('fantasy.market_buy_analysis.required_roi')`, 5% per defecte) — el preu més alt que encara garanteix aquest retorn mínim. **El nombre d'ofertes mai el modifica.**
- **`RecommendedBid`**: `estimatedWinningBid = valorMercat × (1 + primaGuanyadoraEsperada)`. La prima esperada surt de **`MarketAuctionPremiumEstimator`**, nou, que calcula la mediana de la prima pagada en ofertes acceptades reals d'aquesta lliga (`fantasy_offers`, `type=MARKET_BID`/`status=ACCEPTED`, comparant `amount` amb el valor de mercat del jugador just abans que l'oferta es resolgués) — mediana, no mitjana, perquè una oferta de pànic aïllada no esbiaixi l'estimació. Amb menys de `config('fantasy.market_buy_analysis.min_auction_history_samples')` mostres (5 per defecte, i avui `fantasy_offers` encara no el sincronitza cap procés, mateix buit honest que la Trade Score) cau a un `fallback_winning_premium_pct` configurable (5% per defecte) — mai una xifra inventada presentada com a real; `auctionHistorySource` (`league`/`fallback`) ho deixa explícit a la resposta. Si `estimatedWinningBid > MaxBid`, `RecommendedBid` torna literalment `"DO_NOT_CHASE"` en lloc d'una xifra — ja no compensa perseguir la subhasta — i **`MaxBid` mai puja** per acomodar-ho.
- `bidCount` és el `numberOfOffers` real del payload de LaLiga per a aquell fitxatge concret (mai parsejat fins ara) — merament informatiu, no entra en cap càlcul.
- `dataQuality` (0-100) reflecteix quantes de les tres finestres de tendència (1d/3d/7d) hi ha realment — una finestra de 7 dies que falta el redueix en lloc de simular confiança que no hi és.
- La pàgina `/market` mostra el veredicte econòmic com una insígnia pròpia (COMPRAR/CONSIDERAR/ESPERAR/NO COMPRAR) i, desplegant-la, el desglossament complet: ROI a 14 dies, dies de break-even, Score, Màxim i Oferta recomanada.

### Avui — "Què he de fer avui?"

La pàgina principal (`/`, `Dashboard.jsx`) respon una sola pregunta i res més: què cal fer **avui**. No és una pantalla d'estadístiques — si no hi ha cap acció prioritària ni oportunitat prou bona, ho diu explícitament ("Avui no cal fer res") en lloc d'omplir la pantalla amb contingut mediocre.

- **`TodayActionsService`** és purament un **agregador**, no un quart motor de decisions: cada score/projecció/decisió que fa servir ja existeix — `PlayerDecisionEngine::evaluateRoster()` (Hold/Sell/Adjusted Sell/Trade/Clause score i el `clauseTiming` amb `shouldRaise`/`shouldRaiseNow` de cada jugador propi) i `MarketBuyAnalysisService`/`MarketAuctionPremiumEstimator` (Buy Economic Score, MaxBid, RecommendedBid, break-even de cada fitxatge del mercat). No hi ha cap càlcul nou de rendiment, tendència o clàusula — només ordenació i classificació del que ja se sap.
- Substitueix l'antic `DashboardController::today()`, que llegia `fantasy_recommendations` (l'engine esportiu BUY/SELL/HOLD original, `FantasyRecommendationEngine`) — aquest sistema **es manté intacte** i segueix alimentant `/recommendations`, `/market/opportunities` i la pantalla de Trading; només ha deixat de ser la font d'aquesta pantalla concreta.
- **`PriorityScore`** (0-100, `config('fantasy.today.priority_weights')`, 45% urgència + 35% impacte econòmic + 20% confiança) és un rànquing **separat** de qualsevol score funcional — decideix "com d'aviat i com d'important", no "com de bo és el jugador", perquè una clàusula modesta però que caduca en 2 hores ha de sortir per sobre d'una oportunitat de trading grossa però sense pressa.
  - **Urgència**: bandes per hores fins al deadline (`config('fantasy.today.*_urgency_bands')`, diferents per clàusula/oferta/mercat) — mai inventades quan no hi ha un deadline fiable (un Trade Score alt sense oferta ni data límit es queda en urgència moderada-baixa, `trade_no_deadline_urgency`).
  - **Impacte econòmic**: normalitzat com a % sobre el valor de mercat del jugador (no en euros absoluts — 500k€ és molt en un jugador barat i poc en un de 40M), interpolat sobre `config('fantasy.today.economic_impact_scale')`. Reutilitza mètriques ja existents (`expectedProfit14d` de compra, `currentOffer - projectedValue7d` o `avoidedLoss` de venda); per a la clàusula, on no existeix cap xifra fiable en euros del "valor protegit", usa el propi Clause Score com a proxy i ho marca (`economicImpactQuality: 'proxy'` enfront de `'estimated'`).
  - **Confiança** = `dataQuality`/`confidence` que ja retornava cada motor — mai inventada.
- **Regla de clàusula, respectada estrictament** (la mateixa de `PlayerDecisionEngine::clauseTiming()`): primer decidir si convé pujar-la (`shouldRaise`), després decidir quan (`shouldRaiseNow`). `shouldRaise && shouldRaiseNow` → **Prioritat màxima**; `shouldRaise && !shouldRaiseNow` amb `daysRemaining` dins d'`horitzó` (`config('fantasy.today.planned_horizon_days')`, 7 per defecte) → **Planificat**; més enllà de l'horitzó → **Seguiment** (mai desapareix del tot, per no perdre un senyal real, però tampoc satura Planificat amb accions massa llunyanes).
- **Ofertes reals**: `PlayerDecisionEngine` ja resol quina és l'oferta pendent més alta (`trade.currentOffer`); `TodayActionsService` només hi afegeix la data de caducitat (consultant `fantasy_offers` amb els mateixos filtres, mai re-decidint quina oferta "compta"). Si l'oferta supera el valor projectat a 7 dies, apareix com **"Acceptar oferta"** encara que el veredicte general de `PlayerDecisionEngine` sigui `HOLD` — un senyal estret i ja calculat, no un cinquè motor.
- **Oportunitats, només de dues fonts**: fitxatges del **mercat lliure** (`FantasyMarketPlayer` sense `seller_team_id` — un jugador que un altre mànager ha posat a la venda queda fora) i **clàusules de rivals ja desbloquejades** (reutilitzant `ClauseOpportunityService`/`ClauseEconomicAnalysisService` sencer — el mateix motor que `/clauses` — filtrat a `isLocked = false`; una clàusula encara protegida no és una acció executable avui, per bona que sigui la seva economia).
  - **Mercat**: `Buy Economic Score` ≥ 75 amb `estimatedWinningBid ≤ MaxBid` → Oportunitat (Prioritat màxima si a més el mercat tanca en poques hores); `DO_NOT_CHASE` (`estimatedWinningBid > MaxBid`) es mostra igualment com a informació però mai puja de categoria ni fa pujar `MaxBid`; 60-74 → Seguiment; per sota, no es mostra mai encara que el mercat tanqui avui mateix.
  - **Clàusula de rival**: `economicRecommendation = PAY_CLAUSE` → Oportunitat; `CONSIDER` → Seguiment; sense deadline fiable (ningú sap si un altre mànager la pagarà abans), així que la urgència es queda sempre moderada-baixa, mai Prioritat màxima.
- **"Avui no cal fer res"**: si Prioritat màxima i Oportunitats són buides, la resposta ho indica explícitament (`nothingToDoToday`) en lloc de forçar contingut.
- Res es persisteix ni es cacheja — `build()` recalcula sempre en viu a partir de `PlayerDecisionEngine`/`MarketBuyAnalysisService` (que ja llegeixen les dades sincronitzades més recents), així que un pla planificat que canvia abans d'arribar el dia es reflecteix immediatament, mai s'executa una recomanació obsoleta.

### Fitxa individual del jugador — vista unificada dels motors existents

La pàgina `/players/:id` no és un cinquè motor de decisions: `PlayerController::show()` detecta el **context** real del jugador i delega a l'engine que ja existeix per a aquest context, exactament com ho fan `/team`, `/market` i `/clauses` — mai reimplementa cap fórmula.

- **Context** (`OWNED_BY_ME` / `ON_MARKET` / `OWNED_BY_RIVAL` / `FREE`), derivat de qui té el jugador ara mateix (`FantasyTeamPlayer`) i si hi ha un fitxatge actiu al mercat (`FantasyMarketPlayer`) — un jugador propi que també surt llistat compta com `OWNED_BY_ME` (no es pot "comprar" el que ja és teu).
  - **Bug real de fuita entre comptes, detectat en producció amb usuaris reals i arreglat aquí**: `fantasy_players` és un catàleg global compartit per tots els comptes (el mateix jugador real de LaLiga per a tothom), però la propietat (`FantasyTeamPlayer`) només és certa **dins d'una lliga**. La consulta que resol qui té el jugador buscava la fila més recent per `fantasy_player_id` sense cap filtre de lliga — així que si el mateix jugador real estava fitxat a la lliga d'un altre compte (o a una altra de les teves pròpies lligues) i aquell sync havia anat més tard, la seva fila (propietari, clàusula, context) guanyava per sobre de la que tocava a la lliga que estaves mirant. Ara `PlayerController::index()`/`show()` filtren sempre per `fantasy_league_id = $account->activeLeague->id` abans d'agafar la fila més recent.
- **`OWNED_BY_ME`** → `PlayerDecisionEngine::evaluateRoster()`, el mateix que `/team` — Hold/Sell/Adjusted Sell/Trade/Clause score, `clauseTiming.shouldRaise`/`shouldRaiseNow`, tot intacte a `decision.raw`.
- **`ON_MARKET`** → `MarketBuyAnalysisService` + `MarketAuctionPremiumEstimator` + `PlayerValueTrendCalculator`, el mateix que `/market` i "Avui" — Buy Economic Score, MaxBid, RecommendedBid (`"DO_NOT_CHASE"` inclòs), ROI, break-even.
- **`OWNED_BY_RIVAL`** (amb clàusula) → `ClauseEconomicAnalysisService`, el mateix que `/clauses` — Clause Economic Score, ROI, break-even, prima.
- **`FREE`** → també `MarketBuyAnalysisService`, com `ON_MARKET` — cap fitxatge actiu no impedeix respondre "valdria la pena si aparegués al mercat?"; sense un `FantasyMarketPlayer` real, s'hi passa `listing = null` i el propi `market_value` del jugador fa de preu d'adquisició hipotètic (`acquisitionPrice = currentMarketValue`, mai un preu inventat). La resposta ho marca com a hipotètic; `dataQuality`/`confiança` cauen a 0 si no hi ha cap historial (propi o extern) darrere — mai `decision: null` només per no ser al mercat, ni tampoc un veredicte inflat sense dades.
- **"A favor" / "Riscos"**: l'única lògica genuïnament nova d'aquesta feina — frases determinístiques amb llindars sobre mètriques que l'engine corresponent JA ha calculat (ROI, break-even, `growth1d` vs `growth3d`, prima, `dataQuality`...). Mai punts, titularitat, calendari o rotacions dins d'aquest bloc — substitueix el bloc antic que llegia `pros`/`cons` de `fantasy_recommendations` (el motor esportiu `FantasyRecommendationEngine`), que sí que barrejava aquests factors.
- **`dataQuality`/confiança**: `PlayerValueTrendCalculator::dataQuality()` (extret en aquesta feina d'una duplicació ja detectada a `MarketBuyAnalysisService` i `TodayActionsService` — ara una única implementació) per a mercat i clàusula; `PlayerDecisionEngine`'s pròpia `confidence` per a jugadors propis.
- **Alternatives similars**: reconstruïdes per ser econòmiques, no esportives — abans ordenaven per Fantasy Score; ara són fitxatges reals del mercat a la mateixa posició, cadascun avaluat amb el mateix `MarketBuyAnalysisService`, ordenats per Buy Economic Score.
- **Projeccions i "Veure càlcul"**: el gràfic d'evolució pot afegir un tram discontinu amb `projectedValue3d/7d/14d` (o l'equivalent de clàusula), diferenciat visualment del historial real; un desplegable "Veure càlcul" mostra tots els camps numèrics de `decision.raw` sense exposar noms interns de classes o serveis.
- **Evolució del valor — futbolfantasy.com per defecte**: `SparklineHistoryBuilder::build()` (compartit amb Market/Team/Clàusules) normalment prioritza els nostres propis `fantasy_player_snapshots` en quant en té 3 punts diferents, encara que això només cobreixi els últims dies des que el compte es va connectar. Per a aquest gràfic concret (finestra de 30 dies) això sortia curt i pla la majoria del rang — així que aquesta crida passa `preferExternal: true`, saltant-se aquell llindar i agafant directament l'històric real de futbolfantasy.com (que sí cobreix tot el mes), amb `own` només com a última alternativa si no hi ha cap tendència externa aprofitable. La resta de pantalles (sparklines de 7 dies a Market/Team/Clàusules) no toquen aquest paràmetre — hi segueix guanyant `own` quan n'hi ha prou, sense canvis.
- **`24h`/`7 dies` amb el mateix fallback extern que la resta de l'app**: prioritzen `trend.change24h`/`trend.pctChange7d` (snapshots propis); si són `null`, cauen a `externalTrend.pct1d`/`pct7d` (futbolfantasy.com) amb l'etiqueta `*ext.` — exactament la mateixa convenció que ja feien servir els sparklines de Market/Team/Clàusules, mai un segon càlcul ni una font silenciosa.
- Cap valor mock: quan no hi ha ni dades pròpies ni externes, `24h`/`7 dies` mostren `—` (mai una variació inventada); `decision` només és `null` si no hi ha `league` activa per calcular-lo — mai una recomanació de farciment.

**`pctChange24h`**: fins ara `TrendAnalysisService` només calculava el percentatge de variació a 3 i 7 dies (`pctChange3d`/`pctChange7d`) — les 24h només existien com a delta en euros (`change24h`), mai com a `%`. Calculat exactament igual (`(valorActual - valorFa24h) / valorFa24h × 100`) i afegit a `PlayerTrend`. Encara s'exposa a `trend.pctChange24h`/`externalTrend.pct1d`, però `/team` ara mostra directament l'import en euros com a **primera columna** de la taula (`trend.change24h`, o `externalTrend.delta1d` en fallback, mai el `%`) — el gràfic sparkline "7D" de `/team` s'ha eliminat (Market/Clàusules el mantenen igual). El patró propi-preferit/extern-amb-fallback amb l'etiqueta `*ext.` es manté igual.

- **`externalTrend.delta1d`**: futbolfantasy.com ja guardava `value_now`/`value_1d` (el valor absolut real de fa 1 dia, `data-valor1` de l'scrape — no derivat de `pct_1d`) a `fantasy_external_trends`, però `PlayerTrendPresenter::externalTrendPayload()` mai els exposava — només els percentatges. Ara hi afegeix `delta1d = value_now - value_1d`, un import real de la mateixa font (mai calculat a partir del `%`), `null` quan `value_now` no s'ha capturat.
- **`TeamValueDeltaService`**: extret de `DashboardController` (que ja calculava `teamValueDeltaWeek` a partir de `fantasy_player_snapshots`, mai usat pel frontend fins ara) perquè `TeamController` també el pugui fer servir per al delta de 24h del valor de plantilla (`summary.teamValueDelta24h`/`teamValueDelta24hPct`, mostrat sota "Valor plantilla"). Bug real arreglat durant l'extracció: la banda "passat" no filtrava pels jugadors que encara són a la plantilla ara — un jugador venut deixava un valor vell penjant a `pastTotal` sense res per compensar-lo a `currentTotal`, esbiaixant el delta. Ara `past` es limita als `fantasy_player_id` presents a l'última tanda de snapshots (la plantilla d'ara mateix).
- **Històric diari a `/team`**: reutilitza `GET /history/team-value?range=7d` (cap endpoint nou) i mostra `team_value_change`/`team_value_change_pct` dia a dia sota la taula, amb enllaç a `/history` per al detall complet. `ChangeBadge` (l'import signat + `%`) s'ha extret de `History.jsx` a `frontend/src/components/ChangeBadge.jsx` perquè les dues pàgines el comparteixin.

### Històric del valor de la plantilla

`GET /api/history/team-value` (pàgina `/history`) reconstrueix l'evolució del valor total de la plantilla dia a dia a partir de `fantasy_player_snapshots` — no hi ha una taula de "valor d'equip" separada — acotat a un rang seleccionable (`?range=7d|30d|season`). Com que un sync pot córrer diverses vegades al dia, agafar `SUM(market_value)` directe sobre totes les captures d'un dia sumava cada sincronització per separat i inflava el total (vam detectar-ho amb un cas real: 4.000 M€ en lloc dels ~224 M€ reals). La consulta ara es queda només amb l'última captura de cada jugador per dia (`ROW_NUMBER() OVER (PARTITION BY dia, jugador ORDER BY captured_at DESC)`, portable entre el Postgres de producció i el SQLite dels tests) abans de sumar.

Cada dia de la resposta inclou també `players`: el desglossament individual (nom, posició, valor) de tots els jugadors que formaven la plantilla aquell dia, ordenats de més a menys valor — mateixa consulta amb `JOIN` a `fantasy_players`, agrupada en PHP en lloc de SQL perquè cada dia porti la seva llista niada.

**Saldo i valor total**: `fantasy_teams.money` només guarda el valor actual (sobreescrit a cada sync), així que el saldo històric ve d'un lloc que ja existia i ningú havia mirat fins ara — `fantasy_daily_reports` (escrit cada dia per `fantasy:generate-recommendations`, pensat originalment només com a resum intern). `cash_balance`/`total_value` surten de creuar aquesta taula amb la reconstrucció de valor de jugadors per data; un dia sense `fantasy_daily_reports` mostra `cash_balance`/`total_value` a `null`, mai un valor inventat. El bloc `summary` (valor inicial/actual, creixement, ROI patrimonial) es calcula sobre el primer i últim dia de la mateixa sèrie ja retornada — mai un segon càlcul.

Cada jugador (`change`/`changePct`) i el total del dia (`team_value_change`/`team_value_change_pct`, `total_value_change`/`total_value_change_pct`) porten també la variació respecte al dia anterior *dins d'aquesta llista* (no necessàriament el dia natural anterior, si algun dia no té cap snapshot) — calculada en PHP recorrent els dies en ordre i guardant el valor previ de cada jugador; `null` quan no hi ha res amb què comparar (primer dia de la finestra, o jugador fitxat aquell mateix dia). Com que LaLiga només actualitza el valor de mercat un cop al dia, és normal veure el total canviar per una alta/baixa de jugador mentre cap jugador individual mostra variació encara.

### Rendiment de l'assistent (backtesting)

La segona meitat de `/history` respon una pregunta diferent de l'anterior: no "com ha evolucionat el meu patrimoni" sinó "com d'encertades han estat les recomanacions econòmiques". Cap dels motors econòmics (`PlayerDecisionEngine`, `MarketBuyAnalysisService`, `ClauseEconomicAnalysisService`) persistia mai res — es calculaven en viu a cada càrrega de pàgina — així que "què sabia l'assistent fa 7 dies" era literalment impossible de respondre. Aquesta és la primera peça que ho fa possible, sense duplicar cap dels motors:

- **`fantasy_decision_snapshots`** — una fotografia immutable d'una decisió, escrita per `fantasy:snapshot-decisions` (programat al scheduler, mateix patró que `fantasy:generate-recommendations`). Reutilitza literalment `PlayerDecisionEngine::evaluateRoster()` (tots els jugadors propis, HOLD inclòs) i `TodayActionsService::build()` (mercat/clàusula de rival, ja filtrat a "val la pena mirar-ho" — mai calcula una segona vegada quins jugadors del mercat importen). Una fila per (compte, jugador, acció, dia) — tornar a executar el mateix dia refresca la fila encara `PENDING`, mai la sobreescriu si ja s'ha avaluat.
  - `PUJAR CLÀUSULA` es marca `NOT_EVALUABLE` en el mateix moment de la fotografia — pujar una clàusula compra protecció de risc, no una pujada de preu, i no hi ha manera fiable de mesurar "s'ha evitat que un rival te la robés"; mai s'inventa un benefici per a això.
- **`fantasy:evaluate-decisions`** (també programat) busca fotografies `PENDING` l'horitzó de les quals ja ha passat (7 dies per SELL/HOLD, 14 per BUY/PAY_CLAUSE/DO_NOT_CHASE — `config('fantasy.backtest.horizon_days')`), busca el snapshot real més proper *posterior* a l'horitzó dins d'una tolerància configurable (`fantasy_player_snapshots`, mai un snapshot d'abans — evitar look-ahead és estricte), i calcula el resultat a partir de les dades que la mateixa fotografia ja va guardar en el seu moment — mai recalculat amb l'algoritme o la configuració d'avui. Si no hi ha cap snapshot prou a prop, `INSUFFICIENT_DATA`, mai un valor inventat.
  - BUY i PAY_CLAUSE comparteixen la mateixa fórmula (`realProfit = actualValue - referenceValue`, favorable si `> 0` — el llindar simple de l'enunciat, no el `requiredROI` d'avui, que pot haver canviat).
  - SELL: `avoidedLoss = referenceValue - actualValue` (positiu = el preu va baixar després de vendre, bona decisió).
  - DO_NOT_CHASE: `avoidedLoss = estimatedWinningBid - actualValue`.
  - HOLD: aproximació documentada explícitament com a tal — favorable si el valor no ha baixat, sense comparar-ho amb la millor alternativa disponible en aquell moment.
  - `algorithm_version` (`config('fantasy.backtest.algorithm_version')`) queda gravat a cada fotografia perquè un canvi futur de pesos/llindars no reavaluï mai silenciosament una decisió antiga com si l'hagués pres la versió nova.
- **`GET /api/history/assistant-performance`**: precisió (`correctes / avaluades`, mai comptant les `PENDING`), benefici teòric (suma de `realProfit`/`avoidedLoss` de cada fila avaluada, mai barrejat amb el creixement de valor general), ROI mitjà. Torna `null` (no un fals `0%`) quan encara no hi ha res avaluat.
- **`GET /api/history/decisions`** (paginat) + **`GET /api/history/decisions/{id}`** (detall complet, per auditar l'algoritme): filtres per acció (`BUY`/`SELL`/`HOLD`/`LOCK_CLAUSE`/`PAY_CLAUSE`), per resultat (`outcome=favorable|unfavorable`, `status=PENDING`), per `TRADE_PROFIT` (`trade_only=1`, ja que "trade" és un motiu de venda, no un codi d'acció propi), i per rang de dates.

## API interna (backend)

Totes sota `/api`, autenticades amb Sanctum (`Authorization: Bearer <token>`) excepte `/auth/register` i `/auth/login`.

```
GET  /api/dashboard/today          "Què he de fer avui?" — resum + accions prioritzades
GET  /api/team | /team/analysis | /team/lineup
GET  /api/market | /market/opportunities | /market/{id}/buy-analysis
GET  /api/players | /players/{id}
GET  /api/recommendations | /recommendations/today
GET  /api/trading/opportunities
GET  /api/clauses/opportunities
GET  /api/standings
GET  /api/history/team-value | /history/player/{id}
GET  /api/history/assistant-performance | /history/decisions | /history/decisions/{id}
GET|PUT /api/settings
GET|POST|DELETE /api/fantasy-account, /api/leagues, /api/leagues/{id}/select
POST /api/fantasy/sync
```

## Tests

```bash
cd backend
php artisan test
```

232 tests, sense dependència de l'API real (`Http::fake()` a `FantasyApiClient`, `FantasyClauseService`; base de dades SQLite en memòria via `phpunit.xml`). Cobreixen: `FantasyApiClient` (401/refresh, 429, 5xx), `TrendAnalysisService`, `FantasyScoreService`, `MarketValueProjector`, `ClauseEconomicAnalysisService`, `ClauseOpportunityService`, `PlayerDecisionEngine`, `MarketBuyAnalysisService`, `MarketAuctionPremiumEstimator`, `TodayActionsService`, `PlayerController` (Feature — context OWNED_BY_ME/ON_MARKET/OWNED_BY_RIVAL/FREE), `RivalTeamController` (Feature — `/standings/{team}`: veredicte de clàusula, 404 per al propi equip i per equips fora de la lliga activa, `isStarter`/saldo sempre `null`, `teamValue` amb fallback), `HistoryController` (Feature — valor+saldo+backtesting), `FantasySnapshotDecisionsCommand` i `FantasyEvaluateDecisionsCommand` (Feature — sense look-ahead, sense recalcular amb config actual), el motor de recomanacions (compra/venda/mantenir + límit de capital), i `FantasySettingsService`.

## Docker

Opcional — el flux natiu (`php artisan serve` + `npm run dev`) és més ràpid per desenvolupar. `docker-compose.yml` aixeca Postgres + Redis + backend (web, queue, scheduler):

```bash
docker compose up -d --build
```

El frontend no està dockeritzat (Vite dev server és trivial de córrer amb `npm run dev`); en producció, `npm run build` genera estàtics per servir des d'un Nginx/CDN.

## Seguretat

- Tokens de LaLiga Fantasy (`access_token`/`refresh_token`) es guarden **xifrats** (`encrypted` cast d'Eloquent, `APP_KEY`) i mai s'envien al frontend.
- La contrasenya de LaLiga Fantasy no es demana ni es guarda mai.
- CORS restringit a `FRONTEND_URL`; auth API amb Sanctum, sense cookies stateful.
- Logs de l'API de LaLiga (`storage/logs/fantasy-api.log`) no registren mai tokens.
- `.env` és l'única font de secrets — mai committejat.

## Producció (resum)

1. `composer install --no-dev --optimize-autoloader`, `npm run build` al frontend, servir `frontend/dist` estàticament.
2. `php artisan migrate --force`, `php artisan config:cache`, `php artisan route:cache`.
3. Cron amb `schedule:run` cada minut + un procés `queue:work` supervisat (Supervisor/systemd).
4. `QUEUE_CONNECTION=redis` i `CACHE_STORE=redis` recomanat en producció (per defecte és `database`, vàlid per volums petits/mitjans).

## Estat i properes fases

MVP complet (fases 1-10 del pla original): auth, `FantasyApiClient`, sync de lligues/equip/mercat/jugadors, snapshots, tendències, Fantasy Score, motor BUY/SELL/HOLD, clàusules rivals (*Clause Opportunity Score*), i el dashboard "Què he de fer avui?".

Preparat a l'esquema però **no implementat encara** (properes fases, deliberadament fora d'aquest MVP):

- **Trading**: `/api/trading/opportunities` fa una extrapolació lineal simple sobre la tendència real; és un primer pas, no el sistema complet de "compra/venda/híbrida" de l'espec.
- **Backtesting** i **alertes** (email/Telegram/push): `fantasy_recommendations.outcome` i `fantasy_alerts.channel` ja tenen l'esquema pensat per a això.
- **`RecommendationReasoningInterface`**: interfície llesta per connectar un LLM que *expliqui* millor una recomanació — actualment només hi ha `DeterministicReasoningService`, que mai calcula xifres, només en formata les que ja ha decidit el motor.
