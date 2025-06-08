# SPID Laravel Trentino

Pacchetto Laravel per l'integrazione dell'autenticazione SPID tramite AAC (Access Authorization Component) Trentino.

> Compatibile con Laravel **10+ / 11+** e PHP **8.2+**

---

## 🚀 Funzionalità

- Login con SPID/CIE tramite AAC Trentino (OIDC + PKCE)
- Salvataggio automatico dei token `access_token`, `refresh_token`
- Risoluzione dinamica utente (`user.resolver`)
- Logout locale e globale
- Middleware protettivo
- Componenti Blade e Vue inclusi
- Dispatch Eventi `AacTrentinoLogin` / `AaTrentinoLogout`

---

## 📦 Installazione (con path repository)

Nel tuo progetto Laravel:

```bash
composer require offline-agency/spid-laravel-trentino:dev-master
````

E nel `composer.json`:

```json
"repositories": [
  {
    "type": "path",
    "url": "packages/offline-agency/spid-laravel-trentino",
    "options": {
      "symlink": true
    }
  }
]
```

---

## ⚙️ Configurazione

Pubblica la configurazione:

```bash
php artisan vendor:publish --provider="OfflineAgency\\SpidLaravelAac\\SpidLaravelTrentinoServiceProvider"
```

Aggiungi al `.env`:

```env
TRENTINO_CLIENT_ID=...
AAC_TRENTINO_CLIENT_SECRET=...
AAC_TRENTINO_REDIRECT_URI=https://tuo-sito.it/trentino/callback
AAC_TRENTINO_PROVIDER_URL=https://aac-test.cloud-test.tndigit.it
AAC_TRENTINO_SCOPES=openid profile.codicefiscale.me email offline_access
```

---

## ✅ Utilizzo

### 🧩 Rotte consigliate

```php
use SpidLaravelTrentinoAuth;

Route::get('/trentino/login', fn() => SpidLaravelTrentinoAuth::redirectToLogin())->name('spid-trentino.login');
Route::get('/trentino/callback', fn() => SpidLaravelTrentinoAuth::handleCallback() ?: redirect()->intended('/'))->name('spid-trentino.callback');
Route::post('/trentino/logout', fn() => SpidLaravelTrentinoAuth::logout() ?: redirect('/'))->name('spid-trentino.logout');

Route::middleware(['spid.auth'])->group(function () {
    Route::get('/dashboard', fn() => 'Benvenuto SPID Trentino')->name('dashboard');
});
```

### 🔐 Middleware

Registra nel `Kernel.php`:

```php
'spid.auth' => \OfflineAgency\SpidLaravelAac\Http\Middleware\EnsureSpidTrentinoAuthenticated::class,
```

---

## 🖼 Componenti

### Blade

```blade
<x-spid-laravel-trentino-login-button label="Accedi con SPID Trentino" />
```

### Vue (opzionale)

```vue
<SpidLaravelTrentinoLoginButton login-url="/aac/login">Accedi</SpidLaravelTrentinoLoginButton>
```

---

## 🧪 Testing

```bash
vendor/bin/pest
```

Test GitHub Actions inclusi (`php: 8.2/8.3`, Laravel 10+)

---

## 🧱 Personalizzazioni

Puoi sostituire il comportamento di login utente implementando il binding:

```php
app()->bind('user.resolver', function () {
    return new class {
        public function resolveOrCreate(array $userInfo) {
            // crea o restituisce un utente Laravel
        }
    };
});
```

---

## 📡 Eventi

| Evento               | Scopo                       |
|----------------------| --------------------------- |
| `SpidTrentinoLogin`  | Dispatchato dopo login SPID |
| `SpidTrentinoLogout` | Dispatchato dopo logout     |

---

## 📃 Licenza

MIT © [Offline Agency](https://offlineagency.com)

```
