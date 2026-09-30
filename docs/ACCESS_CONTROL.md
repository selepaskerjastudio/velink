# ACCESS_CONTROL — Per-Server Scoping + Role Admin/Member

> Rencana teknis. Status: Draft v1. Tanggal: 2026-08-26.
> Terkait: [PRD.md](PRD.md) §3 Non-Tujuan, [PLAN.md](PLAN.md) §6 Keamanan, [TODO.md](TODO.md) Lintas-Fase.

## 1. Konteks

Velink panel saat ini **tidak punya lapisan otorisasi sama sekali**. Hasil audit kode:

- `panel/app/Models/User.php:21-25` — fillable cuma `name, email, password`. Tak ada `role`, `is_admin`, `team_id`.
- Tak ada `panel/app/Policies/`. `panel/app/Http/Controllers/Controller.php:5` = `abstract class Controller {}`, tak pakai `AuthorizesRequests` → `$this->authorize()` belum tersedia.
- Nol `Gate::` / `can:` / `@can` di seluruh `app/` dan `routes/` (kecuali `HorizonServiceProvider.php:30` `viewHorizon` dengan allowlist kosong).
- `servers` (`2026_06_15_032247_...:14-26`) dan `applications` (`2026_06_15_032250_...:14-29`) **tak punya `user_id`**. 113 route ber-`auth` dijaga hanya oleh "sudah login atau belum".
- `panel/routes/channels.php:11-13` menulis asumsinya terang-terangan: *"single-tenant internal panel, so any authenticated user may listen"* — output command live bocor ke semua user.
- Registrasi menutup diri setelah user pertama (`panel/app/Http/Middleware/RegistrationEnabled.php:14-16`), jadi sistem de-facto single-user. Tak ada jalan menambah user ke-2 lewat UI.

Konsekuensinya: begitu ada user ke-2, dia langsung punya kendali penuh atas semua server — termasuk web terminal root, hapus server, dan system user.

**Yang dibangun:** dua role (`admin` / `member`) + scoping akses **per-server** lewat pivot `server_user`, plus halaman Users & invite. Member cuma lihat & kelola server yang di-assign ke dia; aksi setara-root tetap admin-only.

**Sengaja TIDAK dibangun:** RBAC granular (spatie/laravel-permission), per-application assignment, multi-tenancy. [PRD.md](PRD.md):34 menyatakan ini bukan SaaS multi-tenant — target "operator tunggal / tim kecil internal". Enum role + Policy bisa dinaikkan ke RBAC penuh nanti tanpa menyentuh call site, asal semua cek lewat satu titik.

### ⚠️ Batasan yang harus ditulis di PR description

**Member di server yang di-assign ≈ root di server itu.** Carve-out di bawah mengecilkan blast radius dan mempertajam audit trail — **bukan** sandbox terhadap member yang berniat jahat. Jalur privileged yang tetap terbuka untuk member: `services.install`, `applications.nginx-config`, `applications.deploy-settings` (deploy script). Jangan pasarkan ini sebagai isolasi keamanan.

---

## 2. Keputusan

| Poin | Keputusan |
|---|---|
| Role | `admin` \| `member` saja |
| Sumbu akses | **Resource**, bukan matriks aksi |
| Unit assignment | **Per server**. App/DB/worker/cron/service ikut server-nya |
| Carve-out admin-only | server destroy/regenerate-token/provision/**restart**/create/store, `system-users.*`, `security.*` (firewall+fail2ban), **ssh-keys deploy/revoke**, `users.*` |
| Terminal | **Semua role** (server ter-assign). Member **hanya user webapp (`velink`)**, admin bebas — enforcement di session token, lihat §Terminal di bawah |
| Git credential | **Deploy-only sharing**: semua user boleh deploy dengan credential milik sendiri ∪ milik admin. Pinjam antar member ditolak; hapus credential tetap hak pemilik — lihat §Git credential di bawah |
| Cron user | Member **dilarang** pakai `root` + user sistem lain. Admin bebas |
| User management | Halaman Users + invite, dengan fallback copy-link (mail belum dikonfigurasi) |

### Terminal — member terbatas ke user webapp

Terminal dibuka untuk member di server yang di-assign, tapi **tidak** lewat filter UI: username hanya query param `user` di URL WebSocket gateway, jadi member bisa edit URL jadi `user=root`. Enforcement ada di panel:

- `TerminalController::generateToken()` menyimpan `is_admin` di payload cache token (TTL 60s, single-use).
- Gateway meneruskan `user` ke `POST /internal/terminal/auth` (`verifier.go` `VerifyTerminal`).
- `TerminalController::auth()` menolak 403 jika `!is_admin && user !== AppTemplates::webappUser()`; param kosong = `root` di sisi agent → juga ditolak (fail-closed). Token dibakar saat penolakan + audit `terminal.session_rejected`.
- `show()` memberikan prop `systemUsers` = `[velink]` untuk member (kenyamanan UI saja); admin dapat daftar lengkap + `root`.

`ssh-keys deploy/revoke` masuk carve-out karena `ServerSshKeyController.php:24` → `resolveTargetUser():83-92` → `SshKeyService::ensureDefaultAdmin()` (`SshKeyService.php:24`, akun `velink-admin`) — member bisa deploy pubkey sendiri lalu SSH masuk sebagai user sudo.

`servers.restart` = `sudo reboot` (`ServerController.php:263-271`) — mematikan semua app di server, termasuk milik member lain yang di-assign ke server yang sama.

### Git credential — dibagikan admin untuk deploy

Credential Git dulu strictly per-user, jadi member tidak bisa memakai repo yang sudah ditautkan admin: dropdown credential di halaman create app dan tab deploy kosong, dan `git_credential_id` milik admin gagal validasi. Aturannya sekarang: **satu user boleh deploy dengan credential miliknya sendiri ditambah semua credential yang ditautkan admin** (`User::usableGitCredentials()` di `app/Models/User.php`). Pinjam antar **member** tetap ditolak.

- Sharing **deploy-only**. Manajemen credential (`git-credentials.*`, OAuth callback) tetap scope per-user; `destroy` menolak 403 untuk credential milik orang lain (`GitCredentialController.php:64`) — member tidak bisa menghapus/mengubah credential admin.
- Scope ini dipakai di: daftar credential halaman create app (`gitCredentialsFor()`), tab deploy `show()`, validasi `git_credential_id` di `store()`/`updateDeploySettings()`, dan repo picker `github.repos` (`GitHubRepoController.php:18`).
- Token tetap tidak pernah keluar panel: prop Inertia hanya `uuid`/`account_username`/`provider` (+ `shared_by` = nama pemilik untuk label "(shared by …)"); `access_token`/`refresh_token` `$hidden` + cast `encrypted`.
- Efek samping yang disadari: menghapus credential admin me-null-kan `git_credential_id` semua app yang memakainya (`nullOnDelete()`), termasuk app buatan member — app tersebut jatuh ke mode repo publik.

---

## 3. Arsitektur penegakan: middleware choke point + satu fungsi keputusan

Kenapa bukan Policy murni: ~110 route ber-`auth` butuh `$this->authorize()` satu-satu di 41 controller — **fail-open**, lupa satu = route publik.

Kenapa bukan global scope di `Server`: `auth()->user()` null di konteks console/queue (`GatewayInboundProcessor`, `ThresholdChecker`, `WebhookController`, `InstallController`, `Internal/*`) — deny-all merusak queue worker, allow-all bikin scope tak berguna.

**Kunci strukturalnya:** 73 dari 113 route ber-`auth` (65%) bisa dijangkau dari satu titik resolusi model — 34 nested `{server}`, 21 nested `{application}`, 18 flat child model. Satu middleware yang membaca route parameter menutup semuanya sekaligus, fail-closed.

Pembagian tanggung jawab:

- **Middleware** = titik penegakan (hidup di route *group*, tak bisa kelupaan).
- **`User::canAccessServer()`** = logika keputusan (dipakai ulang di Inertia props, broadcast channel, test).
- **`Server::scopeVisibleTo()`** = proyeksi query untuk halaman list.
- **alias `admin`** = carve-out role, ortogonal dengan scoping resource.

---

## 4. Migrasi & model

### 4.1 `..._add_role_and_status_to_users_table.php`

```php
Schema::table('users', function (Blueprint $table) {
    $table->uuid('uuid')->nullable()->after('id');
    $table->string('role', 20)->default('member')->after('password');
    $table->boolean('is_active')->default(true)->after('role');
});

// LOAD-BEARING: user yang sudah ada = operator yang sudah ada.
// Tanpa ini, user tunggal yang ada sekarang terkunci dari panel-nya sendiri.
DB::table('users')->update(['role' => 'admin']);
DB::table('users')->whereNull('uuid')->cursor()->each(
    fn ($u) => DB::table('users')->where('id', $u->id)->update(['uuid' => (string) Str::uuid()])
);

Schema::table('users', fn (Blueprint $t) => $t->uuid('uuid')->nullable(false)->unique()->change());
```

`string(20)`, bukan `enum` — Postgres enum menyakitkan buat migrasi, SQLite (DB test) tak punya. Validasi pakai `Rule::in()` di controller.

**Default kolom `member`, backfill `admin`.** Baris baru dapat privilege terendah; baris lama di-grandfather. Urutan di dalam satu `up()` inilah yang mencegah lockout.

`uuid` di users wajib — hard rule repo: jangan expose `id` mentah di URL (`app/Models/Concerns/HasUuidRouteKey.php`). Halaman Users butuh `/settings/users/{user}`.

### 4.2 `..._create_server_user_table.php`

```php
Schema::create('server_user', function (Blueprint $table) {
    $table->id();
    $table->foreignId('server_id')->constrained()->cascadeOnDelete();
    $table->foreignId('user_id')->constrained()->cascadeOnDelete();
    $table->foreignId('assigned_by_user_id')->nullable()->constrained('users')->nullOnDelete();
    $table->timestamps();
    $table->unique(['server_id', 'user_id']);
    $table->index('user_id');   // dipakai tiap subquery visibleTo()
});
```

### 4.3 `..._create_user_invitations_table.php`

Kolom: `id`, `uuid` (unique), `email` (index, **tanpa** unique — invite bisa dicabut & diterbitkan ulang), `role` (string 20), `token` (string 64, unique), `server_ids` (json), `invited_by_user_id` (nullOnDelete), `expires_at`, `accepted_at` (nullable), timestamps.

`token` disimpan sebagai `hash('sha256', $plain)` — **bukan** cast `hashed` (bcrypt). Harus bisa `where('token', hash('sha256', $incoming))`; bcrypt bikin lookup mustahil.

Tabel terpisah, bukan kolom di `users` — lihat hazard #6.

### 4.4 `app/Models/User.php`

```php
public const ROLE_ADMIN  = 'admin';
public const ROLE_MEMBER = 'member';

use HasFactory, Notifiable, HasUuidRouteKey;   // trait dari app/Models/Concerns/

protected $fillable = ['name', 'email', 'password'];   // TIDAK BERUBAH

protected function casts(): array   // tambah ke array yang ada di :44-53
{
    return [..., 'is_active' => 'boolean'];
}

public function isAdmin(): bool  { return $this->role === self::ROLE_ADMIN; }

public function servers(): BelongsToMany
{
    return $this->belongsToMany(Server::class)->withTimestamps();
}

public function canAccessServer(Server $server): bool
{
    return $this->isAdmin() || $this->servers()->whereKey($server->getKey())->exists();
}
```

> **Jebakan mass-assignment.** `app/Http/Controllers/Settings/ProfileController.php:31` melakukan `$request->user()->fill($request->validated())`. Aman sekarang karena `ProfileUpdateRequest` cuma validasi `name`/`email` — tapi begitu `role` masuk `$fillable`, `fill($request->all())` di masa depan = privilege escalation. **`role` dan `is_active` permanen di luar `$fillable`**; set eksplisit lewat `forceFill()` di `UserController`.

> **Cek `HasUuidRouteKey` di User.** `getRouteKeyName()` jadi `uuid`, `getKey()` tetap `id`. Aman: `Auth::loginUsingId`, session key `login.id` di `AuthenticatedSessionController:39`, channel `App.Models.User.{id}` (pakai `getKey()`), `verify-email/{id}/{hash}` (tidak model-bound). Tak ada `route()` yang mengoper `User` hari ini.

### 4.5 `app/Models/Server.php`

```php
public function users(): BelongsToMany
{
    return $this->belongsToMany(User::class)->withTimestamps();
}

/** Server yang boleh dilihat user. Admin lihat semua, termasuk yang belum di-assign. */
public function scopeVisibleTo(Builder $query, User $user): Builder
{
    return $user->isAdmin()
        ? $query
        : $query->whereIn('servers.id', $user->servers()->select('servers.id'));
}
```

`whereIn` + subquery, **bukan join** — `ServerController::index:23-25` pakai `->get(['uuid','name','hostname',...])`; join bikin nama kolom ambigu + baris duplikat.

---

## 5. Lapisan keputusan

### `app/Contracts/BelongsToServer.php`

Eksplisit dan greppable, bukan `method_exists()` magic:

```php
interface BelongsToServer
{
    /** Server pemilik record ini, untuk keperluan otorisasi. */
    public function owningServer(): ?Server;
}
```

Implement di 10 model (masing-masing 3 baris). Relasi sudah ada semua:

| Model | `owningServer()` |
|---|---|
| `Application`, `Service`, `CronJob`, `DatabaseInstance`, `DatabaseUser`, `SystemUser`, `FirewallRule`, `Backup` | `$this->server` |
| `DnsRecord` (`:34`), `Deployment` (`:39`) | `$this->application?->server` |

Tambah juga `use AuthorizesRequests;` di `app/Http/Controllers/Controller.php` — satu baris, membuka jalan cek per-objek nanti.

---

## 6. Lapisan penegakan 🔴

### 6.1 `app/Http/Middleware/EnforceServerScope.php`

```php
public function handle($request, Closure $next)
{
    $user = $request->user();

    if ($user?->isAdmin()) {
        return $next($request);      // admin bypass scoping resource
    }

    $server = $this->resolveServer($request->route());

    if ($server instanceof Server) {
        abort_unless($user->canAccessServer($server), 403, 'You do not have access to this server.');
        return $next($request);
    }

    $name = $request->route()?->getName();

    if (in_array($name, self::SERVERLESS_ROUTES, true)
        || in_array($request->route()?->uri(), ['confirm-password', 'settings'], true)) {
        return $next($request);
    }

    Log::warning('EnforceServerScope denied an unclassified route', [
        'route' => $name, 'uri' => $request->route()?->uri(), 'user_id' => $user?->id,
    ]);

    abort(403, 'This action is not available.');
}

private function resolveServer(?Route $route): ?Server
{
    foreach (($route?->parameters() ?? []) as $value) {
        if ($value instanceof Server)          { return $value; }
        if ($value instanceof BelongsToServer) { return $value->owningServer(); }
    }
    return null;
}
```

`SERVERLESS_ROUTES` = konstanta berisi nama route yang sah tanpa server: `dashboard`, `servers.index|create|store`, `audit-logs.index`, `alerts.index`, `github.repos`, `logout`, `git-credentials.*`, `profile.*`, `password.*`, `appearance`, `two-factor.*`, `ssh-keys.index|store|destroy` (akun), `cloudflare.*`, `notifications.*`, `verification.*`, `users.*`, `invitations.store|destroy`.

**403, bukan 404** — konsisten dengan konvensi `abort_if(..., 403)` yang sudah ada di `CloudflareTokenController.php:76`, `NotificationChannelController.php:69,81`, `SshKeyController.php:100`, `ServerSshKeyController.php:26,53`, `GitCredentialController.php:64`.

### 6.2 `EnsureUserIsActive` + `EnsureUserIsAdmin`

`EnsureUserIsActive` **wajib**, bukan opsional: `.env:31` `SESSION_DRIVER=redis` → tak bisa force-logout dengan hapus baris tabel `sessions`. Kalau `! $user->is_active`: `Auth::guard('web')->logout()`, invalidate session, regenerate token, redirect ke login dengan error. Blokir juga di `app/Http/Requests/Auth/LoginRequest.php` setelah `Auth::attempt` sukses, supaya tak pernah dapat session.

`EnsureUserIsAdmin`: `abort_unless($request->user()?->isAdmin(), 403, 'Administrator access required.')`.

### 6.3 `bootstrap/app.php` — sekarang nol alias (`:31-41`)

```php
$middleware->alias(['admin' => EnsureUserIsAdmin::class]);

$middleware->group('panel', [
    'auth',
    EnsureUserIsActive::class,
    EnforceServerScope::class,
]);

// EnforceServerScope membaca model yang sudah di-bind → HARUS setelah SubstituteBindings.
// appendToPriorityList($after, $append) menyimpan per-kelas, jadi satu call per middleware.
$middleware->appendToPriorityList(SubstituteBindings::class, EnsureUserIsActive::class);
$middleware->appendToPriorityList(EnsureUserIsActive::class, EnforceServerScope::class);
$middleware->appendToPriorityList(EnforceServerScope::class, EnsureUserIsAdmin::class);
```

### 6.4 Edit route (mekanis, 16 file)

Di `routes/{servers,applications,databases,database-users,workers,cron,backups,security,cloudflare,notifications,ssh-keys,system-users,services,git-credentials,settings}.php` dan blok ber-`auth` di `routes/web.php:32` dan `routes/auth.php:45`:

```diff
-Route::middleware('auth')->group(function () {
+Route::middleware('panel')->group(function () {
```

Lalu carve-out:

```php
// routes/servers.php — per-route
->middleware('admin')   pada: servers.create, servers.store,
                              servers.provision, servers.restart,
                              servers.regenerate-token, servers.destroy

// routes/system-users.php, routes/security.php — bungkus seluruh group
Route::middleware(['panel', 'admin'])->group(function () { ... });

// routes/ssh-keys.php — hanya route server-scoped (:14, :16)
server.ssh-keys.deploy, server.ssh-keys.revoke  →  ->middleware('admin')
// ssh-keys.index|store|destroy (akun pribadi) tetap terbuka untuk member
```

### 6.5 Cron: blokir root untuk member

`app/Http/Controllers/CronJobController.php:48` sekarang `'user' => [..., 'regex:'.CronTemplates::USER_REGEX]` dan `app/Provisioning/CronTemplates.php:25` `USER_REGEX = /^[a-z_][a-z0-9_-]*$/` — **`root` lolos**, dengan `command` bebas → root RCE.

Tambah closure validation, jangan ubah regex (dipakai admin juga):

```php
'user' => ['required', 'string', 'max:32', 'regex:'.CronTemplates::USER_REGEX,
    function ($attr, $value, $fail) use ($request) {
        if ($request->user()->isAdmin()) { return; }
        if (in_array($value, CronTemplates::MEMBER_FORBIDDEN_USERS, true)) {
            $fail('Members may not schedule cron jobs as this system user.');
        }
    },
],
```

Tambah `MEMBER_FORBIDDEN_USERS` di `CronTemplates`: `root`, `daemon`, `bin`, `sys`, `sync`, `sudo`, `admin`, `velink-admin` (`SshKeyService::DEFAULT_ADMIN_USERNAME`, `SshKeyService.php:24`), `www-data`. Terapkan di `store()` **dan** `update()` (`CronJobController.php:71`).

### 6.6 Fail-closed: dua lapis

**Runtime** — group `panel` + cabang deny-by-default. Route baru di dalam blok `panel` otomatis ter-scope.

**CI** — ini jaminan sebenarnya, karena admin early-return sehingga developer tak akan melihat lubangnya. `tests/Feature/Authorization/RouteCoverageTest.php`:

```php
it('every authenticated route uses the panel group', function () {
    $unclassified = collect(Route::getRoutes()->getRoutes())
        ->filter(fn ($r) => in_array('auth', $r->gatherMiddleware(), true))
        ->reject(fn ($r) => in_array('panel', $r->middleware(), true)
                         || in_array('admin', $r->middleware(), true))
        ->map(fn ($r) => $r->getName() ?? $r->uri())->values();

    expect($unclassified)->toBeEmpty("Route pakai bare 'auth':\n".$unclassified->implode("\n"));
});
```

Plus test kedua: refleksi `SERVERLESS_ROUTES` keluar dari middleware, diff terhadap set route `panel` yang tak punya parameter server-resolvable. Gagal keras kalau ada route baru yang belum diklasifikasi.

---

## 7. Scoping query halaman list

| File:line | Perubahan |
|---|---|
| `ServerController.php:23-25` | `->visibleTo($request->user())`. Signature `index()` → `index(Request $request)` |
| `DashboardController.php:16` | `Server::visibleTo($user)->with('latestMetric')` |
| `DashboardController.php:31-34` | `$base = fn () => Server::visibleTo($user);` lalu tiap count pakai `$base()` |
| `DashboardController.php:49` | `->whereIn('application_id', Application::whereIn('server_id', $visibleIds)->select('id'))` |
| `AuditLogController.php:14-16` | filter dua cabang — lihat di bawah |
| `ServerAlertController.php` | `activeAlerts`, `recentResolved`, `activeCount` → `->whereIn('server_id', $visibleIds)`. Ganti `$alert->server->uuid` (`:19`,`:37`) jadi `?->` |
| `SshKeyController.php:24` | eager load ter-scope: `->with(['servers' => fn ($q) => $q->visibleTo($user)->select(...)])` |

`AuditLogController::index` — `audit_logs.server_id` **nullable**; aksi level akun (`ssh_key.created`, `git_credential.created`, `cloudflare.token_added`) punya `server_id = null`. Filter `whereIn` saja akan menyembunyikan aksi member dari dirinya sendiri:

```php
->when(! $user->isAdmin(), fn ($q) => $q->where(function ($q) use ($visibleIds, $user) {
    $q->whereIn('server_id', $visibleIds)
      ->orWhere(fn ($q2) => $q2->whereNull('server_id')->where('user_id', $user->id));
}))
```

`AuditLogController::serverIndex:35-37` **tak perlu diubah** — middleware sudah menjaga `{server}`.

---

## 8. Broadcast channel 🔴

`routes/channels.php:11-13` sekarang `return $user !== null;` — semua user bisa subscribe ke stream job semua server, membocorkan output command live.

```php
Broadcast::channel('server.{serverUuid}', function (User $user, string $serverUuid) {
    if (! $user->is_active) { return false; }
    $server = Server::where('uuid', $serverUuid)->first();
    return $server !== null && $user->canAccessServer($server);
});
```

Wajib `bool` ketat — mengembalikan model `Server` bikin Laravel memperlakukannya sebagai presence-channel member data. Private channel (`echo.private()` di `resources/js/layouts/server-layout.tsx:150`), jadi query hanya sekali per page load.

`App.Models.User.{id}` jangan disentuh — pakai `getKey()`, sudah cek identitas.

---

## 9. Inertia props

`app/Http/Middleware/HandleInertiaRequests.php:47-49` sekarang share **seluruh model** `$request->user()`. `$hidden` (`User.php:32-37`) menutup password + 2FA secret, jadi `role`/`is_active` aman sebagai data — tapi pola ini berarti **kolom apa pun yang ditambah ke `users` otomatis terbit ke browser**. Persempit:

```php
'auth' => [
    'user' => $request->user() ? [
        'id', 'uuid', 'name', 'email', 'role', 'is_active', 'email_verified_at',
        'two_factor_enabled' => $request->user()->hasEnabledTwoFactorAuthentication(),
    ] : null,
    'can' => ['admin' => (bool) $request->user()?->isAdmin()],
],
```

Karena carve-out murni berbasis role, **satu boolean `auth.can.admin` cukup** untuk destroy / regenerate-token / restart / system-users / security / ssh-key-deploy (terminal kini semua role — pembatasan user-nya bukan di nav tapi di session token). Jangan bikin matriks ability per-route.

Pertahankan `id` dulu — `resources/js/types/index.ts:165-174` mendeklarasikan `id: number`, dikonsumsi `nav-user.tsx` dan `user-info.tsx`. Migrasi ke `uuid` = PR mekanis terpisah.

**Sekalian perbaiki `:43-44`** — `array_merge(parent::share($request), [...parent::share($request), ...])` memanggil `parent::share()` dua kali. Bug lama.

TS di `resources/js/types/index.ts`: tambah `export type UserRole = 'admin' | 'member'`, field `uuid`/`role`/`is_active` di `User` (`:165-174`), dan `can: { admin: boolean }` di `Auth` (`:3-5`).

---

## 10. Gating frontend (kosmetik saja)

> Tulis di PR: **semua penyembunyian di bawah kosmetik.** Server yang menegakkan. Member yang menyusun POST manual tetap dapat 403 dari `EnsureUserIsAdmin`.

`resources/js/hooks/use-permissions.ts`:

```ts
export function useIsAdmin(): boolean {
  return usePage<SharedData>().props.auth?.can?.admin ?? false;
}
```

**`components/app-sidebar.tsx:9-30`** — tambah `adminOnly?: boolean` ke `NavItem` (`types/index.ts:17-22`), filter di dalam `AppSidebar` (bukan di `nav-main.tsx:5-24` — komponen itu dipakai bersama beberapa layout, biarkan bodoh). Tambah item `Users` dengan `adminOnly: true`.

**`layouts/server-layout.tsx:38-58`** — tandai `Security` (`:44`) di `mainNavItems`, `System Users` (`:51`) di `utilityNavItems`, lalu `.filter((i) => !i.adminOnly || isAdmin)`. `Terminal` **tidak** ditandai — semua role melihatnya, member dibatasi ke user webapp oleh session token (lihat §Terminal).

⚠️ `:86` melakukan `mainNavItems.slice(0, 1)` saat `isPending`. **Filter sebelum slice** supaya index 0 tetap Dashboard. Beri komentar — ketergantungan urutan ini rapuh.

**`layouts/settings/layout.tsx:8-42`** — tambah `{ title: 'Users', url: '/settings/users', adminOnly: true }`, filter dengan `useIsAdmin()` di dalam `SettingsLayout`.

**Tombol** — bungkus `{isAdmin && (...)}`, sembunyikan seluruh kartu "Danger Zone", bukan tombolnya saja:

| File:line | Kontrol |
|---|---|
| `pages/servers/connect.tsx:150`, `:187` | regenerate-token, destroy |
| `pages/servers/settings.tsx:213` | destroy |
| `pages/servers/show.tsx:192`, `:238`, `:264` | regenerate-token, destroy |
| `pages/servers/index.tsx` | link "Add Server" → `/servers/create` |

Halaman `security.tsx` dan `system-users.tsx` tak perlu diubah — route 403 sebelum halaman render, link nav sudah hilang. `terminal.tsx` juga tak berubah logikanya: untuk member prop `systemUsers` dari controller hanya berisi `['velink']` sehingga picker otomatis terbatas.

---

## 11. Halaman Users + invite

### 11.1 Route baru `routes/users.php` (di-`require` dari `web.php`)

```php
Route::middleware(['panel', 'admin'])->group(function () {
    Route::get('settings/users',                   [UserController::class, 'index'])->name('users.index');
    Route::patch('settings/users/{user}',          [UserController::class, 'update'])->name('users.update');
    Route::put('settings/users/{user}/servers',    [UserController::class, 'syncServers'])->name('users.servers.sync');
    Route::delete('settings/users/{user}',         [UserController::class, 'destroy'])->name('users.destroy');
    Route::post('settings/users/invitations',      [InvitationController::class, 'store'])->name('invitations.store');
    Route::delete('settings/users/invitations/{invitation}', [InvitationController::class, 'destroy'])->name('invitations.destroy');
});

// Penerimaan invite — sengaja DI LUAR RegistrationEnabled.
Route::middleware('guest')->group(function () {
    Route::get('invitations/{token}',  [InvitationAcceptController::class, 'create'])->name('invitations.accept');
    Route::post('invitations/{token}', [InvitationAcceptController::class, 'store'])->middleware('throttle:10,1');
});
```

`{token}` **tidak** model-bound — resolve lewat hash di controller supaya token invalid = 404 bersih.

### 11.2 `RegistrationEnabled` tidak perlu diubah

Ini jawaban bersih untuk "gimana user undangan bisa masuk". `RegistrationEnabled.php:14-16` tetap menjaga `/register` sebagai bootstrap user pertama saja (`routes/auth.php:16,20`). User undangan lewat jalur guest terpisah yang tak pernah menyentuh middleware itu.

Satu perubahan di `app/Http/Controllers/Auth/RegisteredUserController.php:39` — set role eksplisit, jangan andalkan default kolom:

```php
$user->forceFill(['role' => User::ROLE_ADMIN])->save();   // user pertama selalu admin
```

### 11.3 Mekanika token (tanpa mail)

`.env.example:51` = `MAIL_MAILER=log` — alur harus jalan tanpa mail.

`InvitationController::store`: generate `$plain = Str::random(64)`, simpan `hash('sha256', $plain)`, resolve `server_uuids` → id internal, `expires_at = now()->addDays(7)`. Kirim mail **best-effort** di dalam `try/catch` + skip kalau `config('mail.default') === 'log'`; jangan pernah blokir. Return `back()->with('invite_url', route('invitations.accept', $plain))`.

**Fallback copy-link:** ada preseden langsung — `HandleInertiaRequests.php:51-56` sudah mem-flash `plainAgentToken` dan `installCommand`, dirender dengan tombol copy di `pages/servers/connect.tsx`. Tambah `'inviteUrl'` ke array `flash` yang sama, pakai ulang komponen copy-button. Token plaintext hanya ada di response itu.

`InvitationAcceptController::store`: cari `where('token', hash('sha256', $token))->whereNull('accepted_at')->where('expires_at','>',now())->firstOrFail()`, buat user, `forceFill(['role' => $invite->role, 'is_active' => true])`, `$user->servers()->sync($invite->server_ids ?? [])`, tandai `accepted_at`, `event(new Registered($user))`, `Auth::login($user)`.

### 11.4 🔒 Penjaga anti-lockout di `UserController`

```php
// update()
abort_if($user->is($request->user()) && $validated['role'] !== User::ROLE_ADMIN, 422, 'You cannot demote yourself.');
abort_if($user->is($request->user()) && $validated['is_active'] === false, 422, 'You cannot deactivate yourself.');

// destroy()
abort_if($user->is($request->user()), 422, 'You cannot delete your own account here.');
```

> **Deviasi dari draft awal (yang menyebut 4 guard, termasuk cek "last admin" terpisah):** guard ke-4 dihapus setelah diimplementasikan dan terbukti **dead code**. Alasannya: acting user yang mencapai `UserController::update()`/`destroy()` **selalu** admin aktif — dijamin oleh middleware `EnsureUserIsActive` (menendang user nonaktif sebelum request sampai sini) dan `admin` (menolak non-admin). Karena itu, satu-satunya jalan mencapai nol admin aktif adalah admin terakhir bertindak ke **dirinya sendiri** — dan itu sudah diblokir mutlak oleh tiga guard di atas, tanpa syarat "apakah dia admin terakhir". Untuk aktor yang berbeda dari target, keberadaan aktor itu sendiri (admin aktif, karena lolos middleware) sudah membuktikan minimal satu admin aktif akan tersisa — cek `whereKeyNot($user->getKey())->doesntExist()` selalu `false` di jalur ini. Dikonfirmasi lewat test: admin lain **boleh** menurunkan satu-satunya admin lain (`UserManagementTest.php`, kasus "a different admin CAN demote the only other admin").

**Deactivate lebih dipilih daripada delete** — mempertahankan riwayat `audit_logs.user_id`, dan ditegakkan `EnsureUserIsActive` di request berikutnya (esensial karena `SESSION_DRIVER=redis`). Delete meng-cascade pivot; `audit_logs.user_id` jadi `NULL` (`create_audit_logs_table.php:15` `nullOnDelete`) — baris log tetap hidup dengan aktor anonim.

### 11.5 Halaman

- `resources/js/pages/settings/users.tsx` — pakai ulang `layouts/settings/layout.tsx`. Tabel: nama, email, badge role, badge aktif, jumlah server ter-assign, aksi. Plus seksi "Pending invitations" dengan copy-link + revoke.
- Sheet/Dialog assignment server: checklist semua server, submit ke `users.servers.sync`.
- `resources/js/pages/auth/accept-invitation.tsx` — clone `pages/auth/register.tsx`, email pre-filled read-only.

---

## 12. Audit

Pakai `AuditLogger::log()` yang ada (`app/Services/AuditLogger.php:12-27`), `serverId: null` untuk aksi level akun:

`user.invited`, `user.invite_revoked`, `user.invite_accepted`, `user.role_changed`, `user.deactivated`, `user.reactivated`, `user.server_assigned`, `user.server_unassigned` (satu baris per server — diff hasil `sync()`), `user.deleted`.

**Perbaiki `TerminalController`** — `app/Http/Controllers/TerminalController.php:6` meng-import `AuditLogger` tapi **tak pernah memanggilnya**. Ini aksi paling security-relevant yang tak teraudit di seluruh app.

- `show():20` setelah `generateToken()` → log `terminal.session_opened`, `serverId: $server->id`.
- `auth():53` (callback gateway) → payload cache di `:88-92` sudah membawa `user_id`; log `terminal.session_verified` **sebelum** `Cache::forget()`.

**Perbaiki `ServerController::update:244`** — tak ada audit call. Tambah `server.updated`.

---

## 13. Testing

### 13.1 Default `UserFactory` menentukan segalanya

212 `actingAs()` di 36 file. **Set `'role' => User::ROLE_ADMIN` sebagai default `UserFactory`** (`database/factories/UserFactory.php:26-33`) → semuanya tetap hijau, nol edit call site. Tambah state `admin()`, `member()`, `inactive()`.

**Trade-off, katakan terus terang di PR:** default `admin` berarti suite lama tidak membuktikan apa pun soal scoping — route yang lupa dilindungi tetap hijau. Default `member` fail-safe tapi memerahkan ~212 assertion sekaligus, tak bisa dikirim. Penyelesaiannya: jaring pengamannya bukan default, tapi **matriks member eksplisit** di `tests/Feature/Authorization/`.

### 13.2 Helper di `tests/Pest.php` (ganti stub `something()` di `:44`)

```php
function actingAsAdmin(array $attributes = []): User { ... test()->actingAs($user); }
function actingAsMember(?Server $server = null, array $attributes = []): User { ... }
```

Pakai `test()`, bukan `$this` — ini fungsi global, tak terikat TestCase.

### 13.3 File test baru — `tests/Feature/Authorization/`

1. `RouteCoverageTest.php` — §6.6, jaminan deny-by-default.
2. `MemberServerScopeTest.php` — dataset matriks: 34 route `{server}` + perwakilan tiap keluarga `{application}`/flat-child. Member tanpa assignment → 403; member dengan assignment → sukses. **Uji kedua arah** — memverifikasi urutan middleware (hazard #2).
3. `AdminOnlyRouteTest.php` — member **yang ter-assign** tetap 403 di: `servers.destroy|regenerate-token|provision|restart|create|store`, `system-users.*` (5), `security.*` (6), `server.ssh-keys.deploy|revoke`, `users.*`. Terminal diuji terpisah di `TerminalControllerTest.php` (member: halaman OK + prop `systemUsers=[velink]`; token member + `user=root` → 403 + audit `terminal.session_rejected`).
4. `MemberCronRestrictionTest.php` — member gagal validasi saat `user: root`; admin lolos.
5. `VisibilityScopeTest.php` — `servers.index` hanya yang ter-assign; count dashboard; audit log mengecualikan server lain **tapi menyertakan** baris `server_id = null` milik member sendiri; alerts terfilter.
6. `BroadcastChannelTest.php` — `POST /broadcasting/auth` dengan `channel_name=private-server.{uuid}`: ter-assign 200, tidak ter-assign 403.
7. `DeactivatedUserTest.php` — logout di request berikutnya; tak bisa login.
8. `UserManagementTest.php` — invite → copy link → accept → role terpasang → server ter-assign; last-admin guard; self-demote guard; baris audit tertulis.
9. `FirstUserIsAdminTest.php` — registrasi pertama = admin; registrasi kedua 403.

Update test lama yang akan pecah: grep `assertInertia` + `auth` (bentuk props `auth.user` berubah), dan `DashboardControllerTest` kalau menegaskan count absolut.

---

## 14. Urutan rilis

| # | Langkah | Risiko | Model |
|---|---|---|---|
| 1 | Migrasi (§4) + perubahan model `User`/`Server` + default `UserFactory` = admin. **Belum ada penegakan.** | Rendah — tapi backfill load-bearing | 🟢 Sonnet |
| 2 | Lapisan keputusan (§5): interface `BelongsToServer` + 10 impl, `canAccessServer()`, `scopeVisibleTo()`, `AuthorizesRequests`. Unit test keduanya. | Rendah | 🟢 Sonnet |
| 3 | **Penegakan** (§6): 3 middleware, `bootstrap/app.php` group/alias/priority, 16 file route `auth`→`panel`, carve-out admin, batasan cron, `RouteCoverageTest`. | **🔴 TINGGI — langkah yang bisa mengunci semua orang atau diam-diam meninggalkan lubang** | 🔴 **Opus** |
| 4 | Scoping query (§7). | Rendah | 🟢 Sonnet |
| 5 | Broadcast channel (§8) + test. | 🔴 Sedang | 🔴 Opus |
| 6 | Inertia props + `auth.can.admin` + tipe TS + fix double `parent::share()`. | Rendah | 🟢 Sonnet |
| 7 | Gating nav/tombol frontend (§10). Kosmetik. | Rendah | 🟢 Sonnet |
| 8 | Halaman Users + invite (§11) + anti-lockout guard. | Sedang | 🟢 Sonnet, 🔴 Opus untuk token + guard |
| 9 | Audit (§12) + fix `TerminalController` + `ServerController::update`. | Rendah | 🟢 Sonnet |
| 10 | Matriks test otorisasi penuh (§13.3 item 2-9). | Sedang | 🟢 Sonnet |

**Kirim 1+2 bersamaan** — schema tanpa penegakan itu inert.
**Kirim 3+4+5 bersamaan** — 3 tanpa 4 = member dapat daftar server yang semuanya 403 saat diklik; 3 tanpa 5 = kebocoran WebSocket tetap terbuka, padahal itu justru lubang yang sedang ditutup.

---

## 15. Hazard

1. **🔴 Lockout via default migrasi.** Kalau `users.role` default `member` dan penegakan dikirim sebelum backfill, user tunggal yang ada jadi member tanpa server — tak ada server, tak ada halaman Users, tak ada cara promosi diri. Backfill **wajib** di `up()` yang sama (§4.1). Escape hatch: `php artisan tinker --execute="App\Models\User::query()->update(['role'=>'admin']);"`.

2. **🔴 Urutan middleware.** `EnforceServerScope` membaca model ter-bind. Kalau jalan sebelum `SubstituteBindings`, `$route->parameters()` mengembalikan string mentah, `resolveServer()` return `null`, dan **semua route server jatuh ke cabang deny** — lockout total member yang tak akan disadari admin karena admin early-return. Rantai `appendToPriorityList` wajib. Uji bahwa member **BISA** menjangkau server ter-assign, bukan cuma bahwa dia tak bisa menjangkau yang tak ter-assign.

3. **🔴 Admin bypass cabang deny runtime**, jadi route baru yang kelupaan tak terlihat oleh developer. `RouteCoverageTest` satu-satunya yang menangkap. Jangan skip test di langkah 3.

4. **🟠 Tiga route tanpa nama.** `POST confirm-password`, redirect `GET settings`, `POST login` punya `name = null`. Allowlist berbasis nama. Beri nama (lebih baik, satu baris) atau pertahankan fallback URI. Jangan biarkan `null` diam-diam cocok dengan entry `null`.

5. **🟠 Mass assignment.** `role`/`is_active` jangan pernah masuk `User::$fillable` — `ProfileController.php:31` pakai `fill()`.

6. **🟠 Share whole-model Inertia** berarti kolom `users` apa pun di masa depan otomatis terbit ke browser. Itu sebabnya token invite hidup di `user_invitations`, tak pernah di `users`.

7. **🟠 `audit_logs.server_id` nullable.** Filter `whereIn('server_id', $ids)` saja diam-diam menyembunyikan aksi akun member dari dirinya sendiri. Pakai filter dua cabang (§7).

8. **🟠 `SESSION_DRIVER=redis`** (`.env:31`) — deaktivasi tak bisa ditegakkan dengan hapus baris `sessions`. `EnsureUserIsActive` bukan opsional.

9. **🟠 `Service`, `CronJob`, `Worker` belum pakai `HasUuidRouteKey`** — `workers/{worker}`, `cron/{cronJob}`, `services/{service}` bind by integer `id`, membocorkan id internal berurutan di URL. Melanggar hard rule UUID repo ([PLAN.md](PLAN.md) §4). Bukan bug akses (tetap 403), tapi jadi permukaan enumerasi. **Di luar scope — buat follow-up issue.**

10. **🟠 Ziggy** (`resources/views/app.blade.php:12` `@routes`) mengirim seluruh tabel route ke tiap browser, termasuk member. Bukan kebocoran data, tapi member bisa mengenumerasi nama route admin. Hardening opsional prioritas rendah.

11. **🟢 Suite memerah massal** hanya kalau `UserFactory` default `member`. Default-nya `admin`. Verifikasi dengan `php artisan test` langsung setelah langkah 1 — harus hijau sebelum lanjut.

---

## 16. Verifikasi end-to-end

Dari `/panel`:

```bash
php artisan migrate            # cek: SELECT role FROM users → semua 'admin'
php artisan test               # harus hijau setelah langkah 1 & 2, tanpa edit call site
php artisan test --filter Authorization   # matriks baru setelah langkah 3 & 10
npm run lint && npm run build
```

Manual, dua browser / dua sesi:

1. Login sebagai user existing → masih admin, semua server terlihat, semua tombol ada.
2. `/settings/users` → invite `member@test`, assign hanya Server A → salin invite link dari flash.
3. Sesi kedua (incognito), buka link, set password → auto-login sebagai member.
4. Member: `/servers` hanya menampilkan Server A. Dashboard menghitung 1 server. `/servers/{B-uuid}` → 403.
5. Member di Server A: buat app, deploy, kelola DB/worker/cron — **sukses**. Nav tak menampilkan Security / System Users. Terminal tampil; buka → picker hanya `velink`; coba paksa `user=root` di URL WS → auth ditolak.
6. Member `curl -X POST /servers/{A}/regenerate-token` (dengan CSRF) → **403**.
7. Member coba buat cron `user: root` → **error validasi**.
8. DevTools sesi member: WebSocket hanya subscribe `private-server.{A}`. Paksa subscribe ke `{B}` → `/broadcasting/auth` **403**.
9. Admin nonaktifkan member → request berikutnya di sesi member langsung terlempar ke login.
10. Admin coba demote diri sendiri / nonaktifkan admin terakhir → **422**.
11. `/audit-logs` sebagai member: baris Server A + aksi akunnya sendiri; nol baris Server B.
