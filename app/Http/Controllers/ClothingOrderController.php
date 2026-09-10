<?php

namespace App\Http\Controllers;

use App\Models\ClothingCategory;
use App\Models\ClothingOrder;
use App\Models\ClothingProduct;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/*
 * Tijdelijke kledingbestel-lijst. Volledig publiek (geen auth) — bedoeld om
 * later in z'n geheel weer verwijderd te worden. De producten-catalogus is de
 * bron-van-waarheid voor prijzen/maten en wordt server-side gevalideerd zodat
 * de client geen eigen prijzen kan injecteren.
 *
 * Wijzigen kan zonder login: de besteller vraagt een wijzig-link aan op z'n
 * e-mailadres; die link bevat een geheim edit_token waarmee precies één
 * bestelling bewerkt kan worden.
 */
class ClothingOrderController extends Controller
{
    /** E-mailadres dat een backup van elke bestelling ontvangt. */
    private const BACKUP_EMAIL = 'support@milmap.nl';

    /** Actieve producten-catalogus voor de bestelpagina. */
    public function products()
    {
        $products = ClothingProduct::with('category')
            ->where('active', true)
            ->orderBy('sort_order')
            ->get()
            ->map(fn (ClothingProduct $p) => $p->toCatalogArray());

        return response()->json(['products' => $products]);
    }

    /** Categorieën — voor de shop-navigatie en het admin-formulier. */
    public function categories()
    {
        $categories = ClothingCategory::orderBy('sort_order')->get(['id', 'key', 'label', 'sort_order']);
        return response()->json(['categories' => $categories]);
    }

    /* ── Admin: producten- en categoriebeheer ────────────────────────
       Zelfde admin-check als index(): Sanctum-token met is_admin + 'admin'-ability. */

    public function adminProducts(Request $request)
    {
        $this->requireAdmin($request);

        $products = ClothingProduct::with('category')->orderBy('sort_order')->get()->map(fn (ClothingProduct $p) => [
            'id'          => $p->id,
            'key'         => $p->key,
            'name'        => $p->name,
            'color'       => $p->color,
            'swatch'      => $p->swatch,
            'price'       => (float) $p->price,
            'sizes'       => $p->sizes,
            'active'      => $p->active,
            'category_id' => $p->category_id,
            'category'    => $p->category?->only(['id', 'key', 'label']),
            'image'       => $p->image_path ? asset('storage/' . $p->image_path) : null,
        ]);

        return response()->json(['products' => $products]);
    }

    public function adminStoreCategory(Request $request)
    {
        $this->requireAdmin($request);

        $data = $request->validate([
            'key'   => ['required', 'string', 'max:60', 'alpha_dash', 'unique:clothing_categories,key'],
            'label' => ['required', 'string', 'max:100'],
        ]);

        $data['sort_order'] = (int) ClothingCategory::max('sort_order') + 1;
        $category = ClothingCategory::create($data);

        return response()->json(['category' => $category], 201);
    }

    public function adminStoreProduct(Request $request)
    {
        $this->requireAdmin($request);
        $data = $this->validateProductPayload($request);

        if ($request->hasFile('image')) {
            $data['image_path'] = $request->file('image')->store('clothing', 'public');
        }
        $data['sort_order'] = (int) ClothingProduct::max('sort_order') + 1;

        $product = ClothingProduct::create($data);

        return response()->json(['product' => $product->fresh('category')], 201);
    }

    public function adminUpdateProduct(Request $request, int $id)
    {
        $this->requireAdmin($request);
        $product = ClothingProduct::findOrFail($id);
        $data = $this->validateProductPayload($request, $product->id);

        if ($request->hasFile('image')) {
            if ($product->image_path) {
                Storage::disk('public')->delete($product->image_path);
            }
            $data['image_path'] = $request->file('image')->store('clothing', 'public');
        }

        $product->update($data);

        return response()->json(['product' => $product->fresh('category')]);
    }

    public function adminDestroyProduct(Request $request, int $id)
    {
        $this->requireAdmin($request);
        $product = ClothingProduct::findOrFail($id);
        if ($product->image_path) {
            Storage::disk('public')->delete($product->image_path);
        }
        $product->delete();

        return response()->json(['message' => 'Product verwijderd.']);
    }

    private function validateProductPayload(Request $request, ?int $ignoreId = null): array
    {
        $keyRule = Rule::unique('clothing_products', 'key');
        if ($ignoreId) {
            $keyRule->ignore($ignoreId);
        }

        $data = $request->validate([
            'category_id' => ['required', 'integer', 'exists:clothing_categories,id'],
            'key'         => ['required', 'string', 'max:60', 'alpha_dash', $keyRule],
            'name'        => ['required', 'string', 'max:150'],
            'color'       => ['nullable', 'string', 'max:60'],
            'swatch'      => ['required', 'string', 'max:9'],
            'price'       => ['required', 'numeric', 'min:0'],
            'sizes'       => ['required', 'array', 'min:1'],
            'sizes.*'     => ['required', 'string', 'max:10'],
            'active'      => ['nullable', 'boolean'],
            'image'       => ['nullable', 'image', 'max:4096'],
        ]);

        unset($data['image']);
        $data['active'] = $request->boolean('active', true);

        return $data;
    }

    /** Gooit een 403 als de aanvraag niet van een ingelogde MilMap-admin komt. */
    private function requireAdmin(Request $request): void
    {
        if (!$this->isAdmin($request)) {
            abort(403, 'Alleen voor beheerders.');
        }
    }

    /**
     * Alle bestellingen — voor de overzicht-/exportpagina.
     * De e-mailadressen zijn privé: ze worden alleen meegestuurd aan een
     * ingelogd MilMap-admin (Sanctum-token met 'admin'-ability + is_admin).
     */
    public function index(Request $request)
    {
        $isAdmin = $this->isAdmin($request);

        $orders = ClothingOrder::orderByDesc('id')->get();
        if (!$isAdmin) {
            $orders->each->makeHidden('email');
        }

        return response()->json([
            'orders'   => $orders,
            'is_admin' => $isAdmin,
        ]);
    }

    /** Of het verzoek van een ingelogde MilMap-admin komt. */
    private function isAdmin(Request $request): bool
    {
        $user = Auth::guard('sanctum')->user();
        return $user && $user->is_admin && $user->tokenCan('admin');
    }

    /** Nieuwe bestelling opslaan. */
    public function store(Request $request)
    {
        $data = $this->validatePayload($request);
        [$items, $totalQty, $totalPrice] = $this->buildItems($data['items']);

        $order = ClothingOrder::create([
            'name'        => $data['name'],
            'email'       => $data['email'] ?? null,
            'note'        => $data['note'] ?? null,
            'items'       => $items,
            'total_qty'   => $totalQty,
            'total_price' => $totalPrice,
        ]);

        $this->sendBackupMail($order);

        return response()->json(['order' => $order], 201);
    }

    /** Stuurt een backup van de bestelling naar het vaste backup-adres. */
    private function sendBackupMail(ClothingOrder $order): void
    {
        $rows = collect($order->items)->map(function ($i) {
            $color = !empty($i['color']) ? ' — ' . $i['color'] : '';
            return '<tr>'
                . '<td style="padding:4px 10px;border-bottom:1px solid #eee">' . e($i['product']) . e($color) . '</td>'
                . '<td style="padding:4px 10px;border-bottom:1px solid #eee">' . e($i['size']) . '</td>'
                . '<td style="padding:4px 10px;border-bottom:1px solid #eee;text-align:center">' . (int) $i['qty'] . '</td>'
                . '<td style="padding:4px 10px;border-bottom:1px solid #eee;text-align:right">€ ' . number_format($i['price'] * $i['qty'], 2, ',', '.') . '</td>'
                . '</tr>';
        })->implode('');

        $html = '<div style="font-family:Arial,sans-serif;max-width:560px;margin:0 auto;color:#222">'
            . '<h2>Nieuwe kledingbestelling</h2>'
            . '<p><strong>Naam:</strong> ' . e($order->name) . '<br>'
            . '<strong>E-mail:</strong> ' . e($order->email ?: '—') . '<br>'
            . ($order->note ? '<strong>Opmerking:</strong> ' . e($order->note) . '<br>' : '')
            . '<strong>Besteld op:</strong> ' . $order->created_at->format('d-m-Y H:i') . '</p>'
            . '<table style="border-collapse:collapse;width:100%;font-size:14px">'
            . '<thead><tr>'
            . '<th style="padding:4px 10px;text-align:left;border-bottom:2px solid #ccc">Artikel</th>'
            . '<th style="padding:4px 10px;text-align:left;border-bottom:2px solid #ccc">Maat</th>'
            . '<th style="padding:4px 10px;text-align:center;border-bottom:2px solid #ccc">Aantal</th>'
            . '<th style="padding:4px 10px;text-align:right;border-bottom:2px solid #ccc">Subtotaal</th>'
            . '</tr></thead><tbody>' . $rows . '</tbody></table>'
            . '<p style="text-align:right;font-size:16px;margin-top:12px">'
            . '<strong>Totaal: ' . $order->total_qty . ' stuk(s) · € '
            . number_format($order->total_price, 2, ',', '.') . '</strong></p>'
            . '</div>';

        try {
            Mail::html($html, function ($m) use ($order) {
                $m->to(self::BACKUP_EMAIL)->subject('Backup kledingbestelling — ' . $order->name);
            });
        } catch (\Throwable $e) {
            \Log::warning('Kledingbestelling backup-mail kon niet verzonden worden: ' . $e->getMessage());
        }
    }

    /* ── Wijzigen via e-mail-link ─────────────────────────────────── */

    /**
     * Vraag een wijzig-link aan. De besteller vult z'n e-mailadres in; wij
     * mailen een link met edit_token voor elke bestelling op dat adres.
     * Antwoord is altijd generiek (lekt niet of een adres bestaat).
     */
    public function requestEdit(Request $request)
    {
        $data = $request->validate([
            'email' => ['required', 'email', 'max:190'],
        ]);

        $orders = ClothingOrder::where('email', $data['email'])->orderByDesc('id')->get();

        if ($orders->isNotEmpty()) {
            // Basis-URL voor de wijzig-link: de origin van de aanvragende app als
            // die een (sub)domein van milmap.nl is, anders de env-fallback. Zo werkt
            // het zowel lokaal als op kleding.milmap.nl zonder server-config.
            $origin = (string) $request->headers->get('origin', '');
            $base = preg_match('#^https://([a-z0-9-]+\.)?milmap\.nl$#i', $origin)
                ? $origin
                : rtrim(env('CLOTHING_APP_URL', 'https://kleding.milmap.nl'), '/');

            $links = [];
            foreach ($orders as $order) {
                if (!$order->edit_token) {
                    $order->edit_token = Str::random(48);
                    $order->save();
                }
                $url   = $base . '/#/wijzig/' . $order->edit_token;
                $items = collect($order->items)
                    ->map(fn ($i) => "{$i['product']} ({$i['size']}) ×{$i['qty']}")
                    ->implode(', ');
                $links[] = ['url' => $url, 'summary' => $items];
            }

            $this->sendEditMail($data['email'], $links);
        }

        return response()->json([
            'message' => 'Als er een bestelling op dit e-mailadres staat, is er een wijzig-link verstuurd.',
        ]);
    }

    /** Bestelling ophalen via wijzig-token (voor de wijzigpagina). */
    public function showByToken(string $token)
    {
        $order = ClothingOrder::where('edit_token', $token)->firstOrFail();
        return response()->json(['order' => $order]);
    }

    /** Bestelling bijwerken via wijzig-token. */
    public function updateByToken(Request $request, string $token)
    {
        $order = ClothingOrder::where('edit_token', $token)->firstOrFail();

        $data = $this->validatePayload($request);
        [$items, $totalQty, $totalPrice] = $this->buildItems($data['items']);

        // E-mail blijft de identiteit van de bestelling; alleen naam/opmerking/
        // artikelen mogen via de link wijzigen.
        $order->update([
            'name'        => $data['name'],
            'note'        => $data['note'] ?? null,
            'items'       => $items,
            'total_qty'   => $totalQty,
            'total_price' => $totalPrice,
        ]);

        return response()->json(['order' => $order]);
    }

    /* ── Helpers ──────────────────────────────────────────────────── */

    private function validatePayload(Request $request): array
    {
        $keys = ClothingProduct::where('active', true)->pluck('key');

        return $request->validate([
            'name'         => ['required', 'string', 'max:150'],
            'email'        => ['nullable', 'email', 'max:190'],
            'note'         => ['nullable', 'string', 'max:500'],
            'items'        => ['required', 'array', 'min:1'],
            'items.*.key'  => ['required', 'string', Rule::in($keys)],
            'items.*.size' => ['required', 'string', 'max:10'],
            'items.*.qty'  => ['required', 'integer', 'min:1', 'max:99'],
        ]);
    }

    /** Bouwt de items-lijst + totalen op uit gevalideerde input. */
    private function buildItems(array $rows): array
    {
        $catalog    = ClothingProduct::where('active', true)->get()->keyBy('key');
        $items      = [];
        $totalQty   = 0;
        $totalPrice = 0.0;

        foreach ($rows as $row) {
            $product = $catalog->get($row['key']);
            if (!$product || !in_array($row['size'], $product->sizes, true)) {
                abort(422, "Ongeldige maat '{$row['size']}' voor {$row['key']}.");
            }

            $qty   = (int) $row['qty'];
            $price = (float) $product->price;
            $items[] = [
                'key'     => $product->key,
                'product' => $product->name,
                'color'   => $product->color,
                'size'    => $row['size'],
                'qty'     => $qty,
                'price'   => $price,
            ];
            $totalQty   += $qty;
            $totalPrice += $qty * $price;
        }

        return [$items, $totalQty, $totalPrice];
    }

    private function sendEditMail(string $email, array $links): void
    {
        $rows = collect($links)->map(function ($l) {
            return '<p style="margin:0 0 14px">'
                . '<a href="' . e($l['url']) . '" style="display:inline-block;background:#4f7d4a;color:#fff;'
                . 'text-decoration:none;padding:10px 18px;border-radius:8px;font-weight:600">Wijzig deze bestelling</a>'
                . '<br><span style="color:#666;font-size:13px">' . e($l['summary']) . '</span></p>';
        })->implode('');

        $html = '<div style="font-family:Arial,sans-serif;max-width:520px;margin:0 auto;color:#222">'
            . '<h2>Wijzig je kledingbestelling</h2>'
            . '<p>Via onderstaande link(s) kun je je bestelling aanpassen en opnieuw opslaan:</p>'
            . $rows
            . '<p style="color:#888;font-size:12px;margin-top:24px">Heb je dit niet aangevraagd? Dan kun je deze mail negeren.</p>'
            . '</div>';

        try {
            Mail::html($html, function ($m) use ($email) {
                $m->to($email)->subject('Wijzig je kledingbestelling');
            });
        } catch (\Throwable $e) {
            \Log::warning('Kledingbestelling wijzig-mail kon niet verzonden worden: ' . $e->getMessage());
        }
    }
}
