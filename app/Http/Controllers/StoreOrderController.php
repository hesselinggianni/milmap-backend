<?php

namespace App\Http\Controllers;

use App\Models\StoreOrder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\Rule;

/*
 * MilMap Store: locatie-herinneringskaarten (digitaal, poster of t-shirt).
 * Bestellen kan zonder account (digitale download); ingelogd (zelfde
 * MilMap-account als de app) wordt de bestelling gekoppeld en zichtbaar op
 * /account/orders. Bezorging (fysieke print) is beperkt tot Nederland en
 * België. Prijzen komen server-side uit de catalogus hieronder — de client
 * stuurt alleen product/maat, nooit de prijs zelf (voorkomt manipulatie).
 */
class StoreOrderController extends Controller
{
    private const BACKUP_EMAIL = 'support@milmap.nl';

    /** Moet exact overeenkomen met PRODUCTS in milmap-store/app/stores/cart.js. */
    private const CATALOG = [
        'digital'   => ['price' => 9.99, 'physical' => false, 'sizes' => null],
        'poster_a3' => ['price' => 24.95, 'physical' => true, 'sizes' => null],
        'poster_a2' => ['price' => 34.95, 'physical' => true, 'sizes' => null],
        'tshirt'    => ['price' => 29.95, 'physical' => true, 'sizes' => ['S', 'M', 'L', 'XL', 'XXL']],
    ];

    /** Bestellingen van de ingelogde gebruiker. */
    public function index(Request $request)
    {
        $orders = StoreOrder::where('user_id', $request->user()->id)
            ->orderByDesc('id')
            ->get();

        return response()->json(['orders' => $orders]);
    }

    /** Nieuwe bestelling plaatsen. Koppelt aan de ingelogde gebruiker indien aanwezig. */
    public function store(Request $request)
    {
        $data = $this->validatePayload($request);
        [$items, $totalQty, $totalPrice, $hasPhysical] = $this->buildItems($data['items']);

        if ($hasPhysical) {
            $request->validate([
                'recipient_name' => ['required', 'string', 'max:150'],
                'address_line'   => ['required', 'string', 'max:190'],
                'postal_code'    => ['required', 'string', 'max:12'],
                'city'           => ['required', 'string', 'max:100'],
                'country'        => ['required', 'string', Rule::in(['NL', 'BE'])],
            ]);
        }

        $order = StoreOrder::create([
            'user_id'       => Auth::guard('sanctum')->user()?->id,
            'items'         => $items,
            'total_qty'     => $totalQty,
            'total_price'   => $totalPrice,
            'contact_email' => $data['contact_email'],
            'contact_phone' => $data['contact_phone'],
            'recipient_name' => $request->input('recipient_name'),
            'address_line'   => $request->input('address_line'),
            'postal_code'    => $request->input('postal_code'),
            'city'           => $request->input('city'),
            'country'        => $request->input('country'),
        ]);

        $this->sendBackupMail($order);

        return response()->json(['order' => $order], 201);
    }

    private function validatePayload(Request $request): array
    {
        return $request->validate([
            'contact_email'        => ['required', 'email', 'max:190'],
            'contact_phone'        => ['required', 'string', 'max:30'],
            'items'                => ['required', 'array', 'min:1'],
            'items.*.product_id'   => ['required', 'string', Rule::in(array_keys(self::CATALOG))],
            'items.*.size'         => ['nullable', 'string', 'max:10'],
            'items.*.qty'          => ['required', 'integer', 'min:1', 'max:20'],
            'items.*.title'        => ['required', 'string', 'max:80'],
            'items.*.subtitle'     => ['nullable', 'string', 'max:100'],
            'items.*.date'         => ['nullable', 'date'],
            'items.*.lat'          => ['required', 'numeric', 'between:-90,90'],
            'items.*.lng'          => ['required', 'numeric', 'between:-180,180'],
            'items.*.area_size'    => ['required', 'string', Rule::in(['small', 'medium', 'large'])],
            'items.*.theme'        => ['required', 'string', Rule::in([
                'classic', 'dark', 'minimal', 'tangerine', 'electric_plum', 'cherry_petrol', 'riviera_breeze',
            ])],
            'items.*.layout'       => ['nullable', 'string', Rule::in([
                'below', 'overlay-top', 'overlay-center', 'overlay-bottom', 'border',
            ])],
            'items.*.show_coordinates' => ['nullable', 'boolean'],
        ]);
    }

    /** Bouwt de items-lijst + totalen op, met prijs/maat server-side uit de catalogus. */
    private function buildItems(array $rows): array
    {
        $items = [];
        $totalQty = 0;
        $totalPrice = 0.0;
        $hasPhysical = false;

        foreach ($rows as $row) {
            $product = self::CATALOG[$row['product_id']];

            if ($product['sizes'] && !in_array($row['size'] ?? null, $product['sizes'], true)) {
                abort(422, "Ongeldige maat voor {$row['product_id']}.");
            }

            $qty = (int) $row['qty'];
            $items[] = [
                'product_id'       => $row['product_id'],
                'size'             => $row['size'] ?? null,
                'qty'              => $qty,
                'price'            => $product['price'],
                'title'            => $row['title'],
                'subtitle'         => $row['subtitle'] ?? null,
                'date'             => $row['date'] ?? null,
                'lat'              => (float) $row['lat'],
                'lng'              => (float) $row['lng'],
                'area_size'        => $row['area_size'],
                'theme'            => $row['theme'],
                'layout'           => $row['layout'] ?? 'below',
                'show_coordinates' => (bool) ($row['show_coordinates'] ?? false),
            ];

            $totalQty += $qty;
            $totalPrice += $qty * $product['price'];
            if ($product['physical']) $hasPhysical = true;
        }

        return [$items, $totalQty, $totalPrice, $hasPhysical];
    }

    private function sendBackupMail(StoreOrder $order): void
    {
        $rows = collect($order->items)->map(function ($i) {
            return '<tr>'
                . '<td style="padding:4px 10px;border-bottom:1px solid #eee">' . e($i['product_id']) . ($i['size'] ? ' — ' . e($i['size']) : '') . '</td>'
                . '<td style="padding:4px 10px;border-bottom:1px solid #eee">' . e($i['title']) . '</td>'
                . '<td style="padding:4px 10px;border-bottom:1px solid #eee;text-align:center">' . (int) $i['qty'] . '</td>'
                . '<td style="padding:4px 10px;border-bottom:1px solid #eee;text-align:right">€ ' . number_format($i['price'] * $i['qty'], 2, ',', '.') . '</td>'
                . '</tr>';
        })->implode('');

        $html = '<div style="font-family:Arial,sans-serif;max-width:560px;margin:0 auto;color:#222">'
            . '<h2>Nieuwe MilMap Store-bestelling</h2>'
            . '<p><strong>E-mail:</strong> ' . e($order->contact_email) . '<br>'
            . '<strong>Telefoon:</strong> ' . e($order->contact_phone) . '<br>'
            . ($order->address_line
                ? '<strong>Adres:</strong> ' . e($order->address_line) . ', ' . e($order->postal_code) . ' ' . e($order->city) . ', ' . e($order->country) . '<br>'
                : '')
            . '</p>'
            . '<table style="border-collapse:collapse;width:100%;font-size:14px">'
            . '<thead><tr>'
            . '<th style="padding:4px 10px;text-align:left;border-bottom:2px solid #ccc">Product</th>'
            . '<th style="padding:4px 10px;text-align:left;border-bottom:2px solid #ccc">Titel</th>'
            . '<th style="padding:4px 10px;text-align:center;border-bottom:2px solid #ccc">Aantal</th>'
            . '<th style="padding:4px 10px;text-align:right;border-bottom:2px solid #ccc">Subtotaal</th>'
            . '</tr></thead><tbody>' . $rows . '</tbody></table>'
            . '<p style="text-align:right;font-size:16px;margin-top:12px">'
            . '<strong>Totaal: € ' . number_format($order->total_price, 2, ',', '.') . '</strong></p>'
            . '</div>';

        try {
            Mail::html($html, function ($m) use ($order) {
                $m->to(self::BACKUP_EMAIL)->subject('Nieuwe MilMap Store-bestelling');
            });
        } catch (\Throwable $e) {
            \Log::warning('MilMap Store backup-mail kon niet verzonden worden: ' . $e->getMessage());
        }
    }
}
