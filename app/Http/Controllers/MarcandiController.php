<?php

namespace App\Http\Controllers;

use App\Models\MarcandiShop;
use App\Models\MarcandiProduct;
use App\Models\MarcandiOrder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

/*
 * Marcandi bestel-tool (duty-free MARCANDI Buitenland).
 *
 * Losstaand van milmap: eigen tabellen (marcandi_*), geen user-koppeling. Leunt
 * alleen op de milmap-admin-auth voor beheer (shops + producten + overzicht/
 * export). Bestellen zelf is publiek, per shop, tot de sluitingsdatum.
 */
class MarcandiController extends Controller
{
    private const BACKUP_EMAIL = 'gianni@onavan.com';

    /* ══════════ Publiek ══════════ */

    /** Shops-overzicht (kaartjes op de homepage). */
    public function shops()
    {
        $shops = MarcandiShop::where('active', true)
            ->withCount(['products as item_count' => fn ($q) => $q->where('kind', 'item')])
            ->orderByDesc('id')
            ->get()
            ->map(fn ($s) => [
                'slug'       => $s->slug,
                'title'      => $s->title,
                'location'   => $s->location,
                'info'       => $s->info,
                'closes_at'  => $s->closes_at?->toIso8601String(),
                'is_open'    => $s->is_open,
                'item_count' => $s->item_count,
            ]);

        return response()->json(['shops' => $shops]);
    }

    /** Eén shop + producten (voor de bestelpagina). Pincode vereist als ingesteld. */
    public function show(Request $request, string $slug)
    {
        $shop = $this->shopBySlug($slug);

        if (!$this->pinOk($request, $shop)) {
            return response()->json([
                'message'      => 'Pincode vereist.',
                'pin_required' => true,
                'shop'         => ['slug' => $shop->slug, 'title' => $shop->title],
            ], 401);
        }

        return response()->json([
            'shop'     => $this->shopPayload($shop),
            'products' => $this->productsPayload($shop),
        ]);
    }

    /** Bestelling plaatsen (alleen als de shop open is). */
    public function storeOrder(Request $request, string $slug)
    {
        $shop = $this->shopBySlug($slug);

        if (!$this->pinOk($request, $shop)) {
            return response()->json(['message' => 'Pincode vereist.', 'pin_required' => true], 401);
        }

        if (!$shop->is_open) {
            return response()->json(['message' => 'Deze shop is gesloten; bestellen kan niet meer.'], 422);
        }

        $data = $request->validate([
            'name'         => ['required', 'string', 'max:150'],
            'email'        => ['required', 'email', 'max:190'],
            'note'         => ['nullable', 'string', 'max:500'],
            'items'        => ['required', 'array', 'min:1'],
            'items.*.product_id' => ['required', 'integer'],
            'items.*.qty'        => ['required', 'integer', 'min:1', 'max:9999'],
        ]);

        [$items, $totalQty, $totalPrice] = $this->buildOrderItems($shop, $data['items']);

        $order = MarcandiOrder::create([
            'shop_id'     => $shop->id,
            'name'        => $data['name'],
            'email'       => $data['email'] ?? null,
            'note'        => $data['note'] ?? null,
            'items'       => $items,
            'total_qty'   => $totalQty,
            'total_price' => $totalPrice,
        ]);

        $this->sendMails($shop, $order);

        return response()->json(['order' => [
            'id'          => $order->id,
            'name'        => $order->name,
            'total_qty'   => $order->total_qty,
            'total_price' => (float) $order->total_price,
            'items'       => $order->items,
        ]], 201);
    }

    /* ══════════ Klant: eigen bestellingen via e-mail-link ══════════ */

    /**
     * Vraag een toegangslink aan. We mailen een link met een token waarin het
     * e-mailadres versleuteld zit; daarmee ziet/bewerkt de klant al z'n
     * bestellingen. Antwoord is altijd generiek (lekt niet of het adres bestaat).
     */
    public function requestAccess(Request $request)
    {
        $data = $request->validate(['email' => ['required', 'email', 'max:190']]);
        $email = strtolower($data['email']);

        if (MarcandiOrder::whereRaw('LOWER(email) = ?', [$email])->exists()) {
            $token = rawurlencode(Crypt::encryptString($email));
            $this->sendAccessMail($request, $email, $token);
        }

        return response()->json([
            'message' => 'Als er bestellingen op dit e-mailadres staan, is er een link verstuurd.',
        ]);
    }

    /** Alle bestellingen voor het e-mailadres achter het token. */
    public function myOrders(string $token)
    {
        $email = $this->emailFromToken($token);

        $orders = MarcandiOrder::with('shop')
            ->whereRaw('LOWER(email) = ?', [$email])
            ->orderByDesc('id')->get()
            ->map(fn ($o) => $this->myOrderPayload($o));

        return response()->json(['email' => $email, 'orders' => $orders]);
    }

    /**
     * Eén eigen bestelling (voor de wijzigpagina). Levert ook de productenlijst
     * mee: het token geeft toegang, dus geen aparte (pin-beschermde) shop-call.
     */
    public function myOrder(string $token, int $id)
    {
        $email = $this->emailFromToken($token);
        $order = MarcandiOrder::with('shop')->whereRaw('LOWER(email) = ?', [$email])->findOrFail($id);
        return response()->json([
            'order'    => $this->myOrderPayload($order),
            'products' => $order->shop ? $this->productsPayload($order->shop) : [],
        ]);
    }

    /** Eigen bestelling bijwerken (alleen als de shop nog open is). */
    public function updateMyOrder(Request $request, string $token, int $id)
    {
        $email = $this->emailFromToken($token);
        $order = MarcandiOrder::with('shop')->whereRaw('LOWER(email) = ?', [$email])->findOrFail($id);

        if (!$order->shop || !$order->shop->is_open) {
            return response()->json(['message' => 'Deze shop is gesloten; wijzigen kan niet meer.'], 422);
        }

        $data = $request->validate([
            'name'  => ['sometimes', 'required', 'string', 'max:150'],
            'note'  => ['nullable', 'string', 'max:500'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.product_id' => ['required', 'integer'],
            'items.*.qty'        => ['required', 'integer', 'min:1', 'max:9999'],
        ]);

        [$items, $totalQty, $totalPrice] = $this->buildOrderItems($order->shop, $data['items']);

        $order->update([
            'name'        => $data['name'] ?? $order->name,
            'note'        => $data['note'] ?? null,
            'items'       => $items,
            'total_qty'   => $totalQty,
            'total_price' => $totalPrice,
        ]);

        return response()->json(['order' => $this->myOrderPayload($order->fresh('shop'))]);
    }

    /* ══════════ Admin (milmap-admin) ══════════ */

    /** Alle shops (ook inactieve) voor het beheer. */
    public function adminShops(Request $request)
    {
        $this->assertAdmin($request);

        return response()->json([
            'shops' => MarcandiShop::withCount([
                'products as item_count' => fn ($q) => $q->where('kind', 'item'),
                'orders as order_count',
            ])->orderByDesc('id')->get()->map(fn ($s) => array_merge($this->shopPayload($s), [
                'item_count'  => $s->item_count,
                'order_count' => $s->order_count,
                'active'      => $s->active,
                'pin'         => $s->pin,
            ])),
        ]);
    }

    /** Nieuwe shop; kopieert de MARCANDI-catalogus als startpunt. */
    public function storeShop(Request $request)
    {
        $this->assertAdmin($request);

        $data = $request->validate([
            'title'     => ['required', 'string', 'max:150'],
            'location'  => ['nullable', 'string', 'max:150'],
            'info'      => ['nullable', 'string'],
            'closes_at' => ['nullable', 'date'],
            'pin'       => ['nullable', 'string', 'regex:/^\d{6}$/'],
            'show_note' => ['sometimes', 'boolean'],
        ]);

        $shop = MarcandiShop::create([
            'slug'      => $this->uniqueSlug($data['title']),
            'title'     => $data['title'],
            'location'  => $data['location'] ?? null,
            'info'      => $data['info'] ?? null,
            'closes_at' => $data['closes_at'] ?? null,
            'pin'       => ($data['pin'] ?? '') !== '' ? $data['pin'] : null,
            'show_note' => $data['show_note'] ?? true,
            'active'    => true,
        ]);

        $this->seedProducts($shop);

        return response()->json(['shop' => $this->shopPayload($shop)], 201);
    }

    /** Titel / locatie / info / sluitingsdatum / actief aanpassen. */
    public function updateShop(Request $request, int $id)
    {
        $this->assertAdmin($request);
        $shop = MarcandiShop::findOrFail($id);

        $data = $request->validate([
            'title'     => ['sometimes', 'required', 'string', 'max:150'],
            'location'  => ['nullable', 'string', 'max:150'],
            'info'      => ['nullable', 'string'],
            'closes_at' => ['nullable', 'date'],
            'pin'       => ['nullable', 'string'],
            'show_note' => ['sometimes', 'boolean'],
            'active'    => ['sometimes', 'boolean'],
        ]);

        if (array_key_exists('pin', $data)) {
            if ($data['pin'] === '' || $data['pin'] === null) {
                $data['pin'] = null;
            } elseif (!preg_match('/^\d{6}$/', $data['pin'])) {
                return response()->json(['message' => 'De pincode moet uit precies 6 cijfers bestaan.'], 422);
            }
        }

        $shop->update($data);

        return response()->json(['shop' => $this->shopPayload($shop->fresh())]);
    }

    public function destroyShop(Request $request, int $id)
    {
        $this->assertAdmin($request);
        MarcandiShop::findOrFail($id)->delete();
        return response()->json(['ok' => true]);
    }

    /** Bestellingen van een shop (incl. e-mail) — overzicht + export. */
    public function adminOrders(Request $request, string $slug)
    {
        $this->assertAdmin($request);
        $shop = $this->shopBySlug($slug);

        return response()->json([
            'shop'     => $this->shopPayload($shop),
            'orders'   => $shop->orders()->orderByDesc('id')->get()->map(fn ($o) => [
                'id'          => $o->id,
                'name'        => $o->name,
                'email'       => $o->email,
                'note'        => $o->note,
                'items'       => $o->items,
                'total_qty'   => $o->total_qty,
                'total_price' => (float) $o->total_price,
                'created_at'  => $o->created_at->toIso8601String(),
            ]),
            // Volledige catalogus in originele volgorde (voor blad 2 van de export).
            'catalog'  => $shop->products()->orderBy('sort')->get()->map(fn ($p) => [
                'id'    => $p->id,
                'kind'  => $p->kind,
                'regel' => $p->regel,
                'code'  => $p->code,
                'name'  => $p->name,
                'price' => $p->kind === 'item' ? (float) $p->price : null,
            ]),
        ]);
    }

    /* ── Basis productbeheer (admin) ── */

    public function storeProduct(Request $request, int $shopId)
    {
        $this->assertAdmin($request);
        $shop = MarcandiShop::findOrFail($shopId);
        $data = $request->validate([
            'name'  => ['required', 'string', 'max:255'],
            'code'  => ['nullable', 'string', 'max:30'],
            'price' => ['required', 'numeric', 'min:0'],
            'kind'  => ['nullable', 'in:item,header'],
        ]);
        $p = MarcandiProduct::create([
            'shop_id' => $shop->id,
            'sort'    => (int) $shop->products()->max('sort') + 1,
            'kind'    => $data['kind'] ?? 'item',
            'code'    => $data['code'] ?? null,
            'name'    => $data['name'],
            'price'   => $data['price'],
        ]);
        return response()->json(['product' => $p], 201);
    }

    public function updateProduct(Request $request, int $id)
    {
        $this->assertAdmin($request);
        $p = MarcandiProduct::findOrFail($id);
        $data = $request->validate([
            'name'   => ['sometimes', 'required', 'string', 'max:255'],
            'code'   => ['nullable', 'string', 'max:30'],
            'price'  => ['sometimes', 'required', 'numeric', 'min:0'],
            'active' => ['sometimes', 'boolean'],
        ]);
        $p->update($data);
        return response()->json(['product' => $p]);
    }

    public function destroyProduct(Request $request, int $id)
    {
        $this->assertAdmin($request);
        MarcandiProduct::findOrFail($id)->delete();
        return response()->json(['ok' => true]);
    }

    /* ══════════ Helpers ══════════ */

    private function shopBySlug(string $slug): MarcandiShop
    {
        return MarcandiShop::where('slug', $slug)->firstOrFail();
    }

    /** Bouwt items + totalen op uit input; 404 het token bij een fout. */
    private function buildOrderItems(MarcandiShop $shop, array $rows): array
    {
        $catalog = $shop->products()->where('kind', 'item')->where('active', true)->get()->keyBy('id');
        $items = [];
        $totalQty = 0;
        $totalPrice = 0.0;
        foreach ($rows as $row) {
            $p = $catalog->get($row['product_id']);
            abort_unless($p, 422, "Onbekend product (id {$row['product_id']}).");
            $qty = (int) $row['qty'];
            $price = (float) $p->price;
            $items[] = [
                'product_id' => $p->id,
                'code'       => $p->code,
                'name'       => $p->name,
                'price'      => $price,
                'qty'        => $qty,
            ];
            $totalQty += $qty;
            $totalPrice += $qty * $price;
        }
        return [$items, $totalQty, $totalPrice];
    }

    /** Ontsleutelt het e-mailadres uit een klant-token; 404 bij een ongeldig token. */
    private function emailFromToken(string $token): string
    {
        try {
            return strtolower(Crypt::decryptString(rawurldecode($token)));
        } catch (\Throwable $e) {
            abort(404, 'Ongeldige of verlopen link.');
        }
    }

    private function myOrderPayload(MarcandiOrder $o): array
    {
        return [
            'id'          => $o->id,
            'name'        => $o->name,
            'note'        => $o->note,
            'items'       => $o->items,
            'total_qty'   => $o->total_qty,
            'total_price' => (float) $o->total_price,
            'created_at'  => $o->created_at->toIso8601String(),
            'shop'        => $o->shop ? [
                'slug'    => $o->shop->slug,
                'title'   => $o->shop->title,
                'is_open' => $o->shop->is_open,
            ] : null,
        ];
    }

    private function sendAccessMail(Request $request, string $email, string $token): void
    {
        $origin = (string) $request->headers->get('origin', '');
        $base = preg_match('#^https://([a-z0-9-]+\.)?milmap\.nl$#i', $origin)
            ? $origin
            : rtrim(env('MARCANDI_APP_URL', 'https://oc.milmap.nl'), '/');
        $url = $base . '/#/mijn/' . $token;

        $html = '<div style="font-family:Arial,sans-serif;max-width:520px;margin:0 auto;color:#222">'
            . '<h2>Je bestellingen</h2>'
            . '<p>Via onderstaande link zie je al je bestellingen en kun je ze wijzigen:</p>'
            . '<p><a href="' . e($url) . '" style="display:inline-block;background:#c8102e;color:#fff;'
            . 'text-decoration:none;padding:11px 20px;border-radius:8px;font-weight:600">Mijn bestellingen</a></p>'
            . '<p style="color:#888;font-size:12px;margin-top:22px">Niet aangevraagd? Negeer deze mail.</p></div>';

        try {
            Mail::html($html, function ($m) use ($email) {
                $m->to($email)->subject('Je MARCANDI-bestellingen');
            });
        } catch (\Throwable $e) {
            \Log::warning('Marcandi access-mail faalde: ' . $e->getMessage());
        }
    }

    private function shopPayload(MarcandiShop $s): array
    {
        return [
            'id'        => $s->id,
            'slug'      => $s->slug,
            'title'     => $s->title,
            'location'  => $s->location,
            'info'      => $s->info,
            'closes_at' => $s->closes_at?->toIso8601String(),
            'is_open'   => $s->is_open,
            'has_pin'   => (bool) $s->pin,
            'show_note' => (bool) $s->show_note,
        ];
    }

    private function productsPayload(MarcandiShop $shop)
    {
        return $shop->products()->where('active', true)->get()->map(fn ($p) => [
            'id'    => $p->id,
            'kind'  => $p->kind,
            'level' => $p->level,
            'code'  => $p->code,
            'name'  => $p->name,
            'price' => $p->kind === 'item' ? (float) $p->price : null,
        ]);
    }

    /** Klopt de meegestuurde pincode (header X-Shop-Pin) met die van de shop? */
    private function pinOk(Request $request, MarcandiShop $shop): bool
    {
        if (!$shop->pin) {
            return true;
        }
        $pin = (string) ($request->header('X-Shop-Pin') ?: $request->query('pin', ''));
        return $pin !== '' && hash_equals((string) $shop->pin, $pin);
    }

    private function isAdmin(Request $request): bool
    {
        $user = Auth::guard('sanctum')->user();
        return $user && $user->is_admin && $user->tokenCan('admin');
    }

    private function assertAdmin(Request $request): void
    {
        abort_unless($this->isAdmin($request), 403, 'Alleen voor milmap-admins.');
    }

    private function uniqueSlug(string $title): string
    {
        $base = Str::slug($title) ?: 'shop';
        $slug = $base;
        $i = 2;
        while (MarcandiShop::where('slug', $slug)->exists()) {
            $slug = $base . '-' . $i++;
        }
        return $slug;
    }

    /** Vult een shop met de MARCANDI-catalogus uit het seed-bestand. */
    public function seedProducts(MarcandiShop $shop): void
    {
        $path = database_path('data/marcandi_catalog.json');
        if (!is_file($path)) {
            return;
        }
        $catalog = json_decode(file_get_contents($path), true) ?: [];
        $now = now();
        $rows = [];
        foreach ($catalog as $c) {
            $rows[] = [
                'shop_id'    => $shop->id,
                'sort'       => $c['sort'] ?? 0,
                'kind'       => $c['kind'] ?? 'item',
                'level'      => $c['level'] ?? 2,
                'regel'      => $c['regel'] ?? null,
                'code'       => $c['code'] ?? null,
                'name'       => $c['name'] ?? '',
                'price'      => ($c['kind'] ?? 'item') === 'item' ? ($c['price'] ?? 0) : null,
                'active'     => true,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }
        foreach (array_chunk($rows, 200) as $chunk) {
            MarcandiProduct::insert($chunk);
        }
    }

    private function sendMails(MarcandiShop $shop, MarcandiOrder $order): void
    {
        $rows = collect($order->items)->map(function ($i) {
            return '<tr>'
                . '<td style="padding:4px 10px;border-bottom:1px solid #eee">' . e(($i['code'] ? $i['code'] . ' ' : '') . $i['name']) . '</td>'
                . '<td style="padding:4px 10px;border-bottom:1px solid #eee;text-align:center">' . (int) $i['qty'] . '</td>'
                . '<td style="padding:4px 10px;border-bottom:1px solid #eee;text-align:right">€ ' . number_format($i['price'] * $i['qty'], 2, ',', '.') . '</td>'
                . '</tr>';
        })->implode('');

        $html = '<div style="font-family:Arial,sans-serif;max-width:600px;margin:0 auto;color:#222">'
            . '<h2>Bestelling — ' . e($shop->title) . '</h2>'
            . '<p><strong>Naam:</strong> ' . e($order->name) . '<br>'
            . '<strong>E-mail:</strong> ' . e($order->email ?: '—') . '<br>'
            . ($order->note ? '<strong>Opmerking:</strong> ' . e($order->note) . '<br>' : '')
            . '<strong>Besteld op:</strong> ' . $order->created_at->format('d-m-Y H:i') . '</p>'
            . '<table style="border-collapse:collapse;width:100%;font-size:14px">'
            . '<thead><tr>'
            . '<th style="padding:4px 10px;text-align:left;border-bottom:2px solid #ccc">Product</th>'
            . '<th style="padding:4px 10px;text-align:center;border-bottom:2px solid #ccc">Aantal</th>'
            . '<th style="padding:4px 10px;text-align:right;border-bottom:2px solid #ccc">Subtotaal</th>'
            . '</tr></thead><tbody>' . $rows . '</tbody></table>'
            . '<p style="text-align:right;font-size:16px;margin-top:12px"><strong>Totaal: '
            . $order->total_qty . ' stuk(s) · € ' . number_format($order->total_price, 2, ',', '.') . '</strong></p>'
            . '</div>';

        // Backup naar vast adres.
        try {
            Mail::html($html, function ($m) use ($shop, $order) {
                $m->to(self::BACKUP_EMAIL)->subject('Marcandi bestelling (' . $shop->title . ') — ' . $order->name);
            });
        } catch (\Throwable $e) {
            \Log::warning('Marcandi backup-mail faalde: ' . $e->getMessage());
        }

        // Bevestiging naar de besteller.
        if ($order->email) {
            try {
                Mail::html($html, function ($m) use ($order) {
                    $m->to($order->email)->subject('Bevestiging van je bestelling');
                });
            } catch (\Throwable $e) {
                \Log::warning('Marcandi bevestigingsmail faalde: ' . $e->getMessage());
            }
        }
    }
}
