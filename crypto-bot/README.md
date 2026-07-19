# Crypto Trading Bot 🤖

Een **standalone** paper-trading crypto-bot (Bitvavo) met risico-management, twee
strategieën — een deterministische EMA/RSI-strategie en een **Claude/LLM-gestuurde
strategie** — en een Vue 3 dashboard. Volledig los van andere projecten.

> ⚠️ **Lees dit eerst — eerlijke realiteitscheck.**
> Geautomatiseerd retail crypto-traden **verliest na fees en slippage meestal geld**.
> Een EMA/RSI- of LLM-strategie heeft op zichzelf **geen edge** en dit is **geen
> gegarandeerde inkomstenbron**. Fase 1 beweegt **nul euro**: de bot draait op echte
> live prijzen maar handelt uitsluitend in simulatie tegen een virtueel saldo. Echt
> geld komt er pas in een latere, afgeschermde fase — met microscopische limieten.
> Behandel elk bedrag dat je ooit inzet als "leergeld dat weg kan".

## Architectuur

```
crypto-bot/
  backend/    Laravel 11 API + bot-engine (PHP 8.2, brick/math voor exacte bedragen)
  frontend/   Vue 3 + Vite dashboard (equity-curve, posities, trades, kill-switch)
```

### Veiligheid (de kern van het project)
- **Drie sloten tegen echt geld:** de `PaperExchange` is standaard gebonden; de
  `BitvavoClient` privé-methodes weigeren zonder `BOT_REAL_ENABLED=true` én API-keys;
  een boot-assertion weigert te starten bij een onveilige config.
- **Kill-switch** (`bot:halt` of via het dashboard) halt alle handel direct.
- **Circuit-breaker:** bij te veel dagverlies of drawdown vanaf de piek worden nieuwe
  entries automatisch geblokkeerd (exits mogen blijven om risico te sluiten).
- **Positielimieten:** max % van equity én absolute EUR-cap, plus minimum-order-check.
- **Auto-inleg met harde cap:** virtuele top-ups stoppen automatisch bij de lifetime-cap.

## Backend draaien

```bash
cd backend
composer install
cp .env.example .env
php artisan key:generate
php artisan migrate
php artisan bot:seed-account          # paper account + €1000 + actieve ema_cross_rsi config

# Zet in .env: BOT_ENABLED=true   (real trading blijft uit)
php artisan bot:tick                  # één tick handmatig
php artisan bot:report                # equity, posities, P&L
php artisan test                      # indicatoren, money, strategie, feature-tick

php artisan serve                     # API op http://localhost:8000
php artisan schedule:work             # laat de bot per minuut tikken
```

### Console-commando's
| Commando | Doel |
|---|---|
| `bot:seed-account` | Paper account + wallet + strategie-config aanmaken |
| `bot:tick` | Eén trading-tick (candles → strategie → risk → paper-order) |
| `bot:top-up` | Geplande virtuele inleg (met harde cap) |
| `bot:report` | Portfolio-overzicht |
| `bot:halt [--resume]` | Kill-switch aan/uit |

### De Claude-strategie inschakelen
Zet `ANTHROPIC_API_KEY` in `.env`, maak een `llm_claude`-config aan
(`php artisan bot:seed-account --strategy=llm_claude`) en activeer die. De LLM geeft
via strict tool-use een gestructureerd signaal; bij elke fout valt hij veilig terug op
`hold`, en elk signaal gaat alsnog door de `RiskManager`.

## Frontend draaien

```bash
cd frontend
npm install
cp .env.example .env    # zet VITE_API_BASE_URL (+ VITE_API_TOKEN indien gezet)
npm run dev             # dashboard op http://localhost:5173
```

## Fases
1. **Paper-MVP (nu):** simulatie op echte prijzen, 1 pair, 1h candles, €1000 virtueel.
2. **Backtesting:** historische candles door dezelfde strategie + fill-model.
3. **Gated echt traden:** Bitvavo privé-API + HMAC, microscopische caps, handmatige funding.

## Deze map naar een eigen repo verhuizen
Dit project is bewust zelfstandig. Om het (met historie) naar een eigen Git-repo te
tillen:

```bash
# vanuit de root van deze repo
git subtree split --prefix=crypto-bot -b crypto-bot-export
cd /pad/naar/nieuwe/crypto-trading-bot && git init
git pull /pad/naar/deze/repo crypto-bot-export
git remote add origin git@github.com:<jij>/crypto-trading-bot.git
git push -u origin main
```

## Disclaimer
Dit is educatieve software, geen financieel advies. Gebruik op eigen risico.
