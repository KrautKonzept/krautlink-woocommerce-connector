<?php
/**
 * Plugin Name: KrautLink Connector
 * Plugin URI:  https://krautkonzept.de
 * Description: Verbindet deinen WooCommerce-Shop mit KrautLink — automatische Order-Weiterleitung, Provisions-Tracking und Produkt-Sync.
 * Version:     1.0.0
 * Author:      KrautKonzept
 * Author URI:  https://krautkonzept.de
 * License:     GPL-2.0+
 * Requires at least: 5.0
 * Requires PHP: 7.4
 * WC requires at least: 5.0
 */

if (!defined('ABSPATH')) exit;

// ── Admin Settings ────────────────────────────────────────────────────────────
add_action('admin_menu', function() {
    add_options_page('KrautLink', 'KrautLink', 'manage_options', 'krautlink', 'krautlink_settings_page');
});

add_action('admin_init', function() {
    register_setting('krautlink_options', 'krautlink_api_key', ['sanitize_callback' => 'sanitize_text_field']);
});

function krautlink_settings_page() {
    $api_key    = get_option('krautlink_api_key', '');
    $test_html  = '';

    // Verbindungstest
    if (isset($_POST['krautlink_test']) && !empty($api_key) && check_admin_referer('krautlink_save')) {
        $resp = wp_remote_post('https://kraut-link.base44.app/api/ping', [
            'headers' => ['Content-Type' => 'application/json', 'Authorization' => 'Bearer ' . $api_key],
            'body'    => json_encode(['test' => true, 'shop' => get_site_url()]),
            'timeout' => 10,
        ]);
        if (is_wp_error($resp)) {
            $test_html = '<div class="notice notice-error"><p>✗ Fehler: ' . esc_html($resp->get_error_message()) . '</p></div>';
        } else {
            $code = wp_remote_retrieve_response_code($resp);
            $test_html = ($code === 200)
                ? '<div class="notice notice-success"><p>✓ Verbindung erfolgreich! KrautLink antwortet korrekt.</p></div>'
                : '<div class="notice notice-error"><p>✗ Server antwortete mit HTTP ' . $code . ' — API-Key prüfen.</p></div>';
        }
    }

    // Speichern
    if (isset($_POST['krautlink_api_key']) && check_admin_referer('krautlink_save')) {
        update_option('krautlink_api_key', sanitize_text_field($_POST['krautlink_api_key']));
        $api_key = get_option('krautlink_api_key', '');
        echo '<div class="notice notice-success"><p>✓ API-Key gespeichert!</p></div>';
    }

    echo $test_html;
    ?>
    <div class="wrap">
        <h1>🌿 KrautLink Connector <span style="font-size:13px;color:#888;font-weight:400">v1.0.0</span></h1>
        <p>Verbindet deinen WooCommerce-Shop automatisch mit KrautLink. Bestellungen werden in Echtzeit übermittelt.</p>

        <form method="post">
            <?php wp_nonce_field('krautlink_save'); ?>
            <table class="form-table">
                <tr>
                    <th><label for="krautlink_api_key">API-Key</label></th>
                    <td>
                        <input type="text" id="krautlink_api_key" name="krautlink_api_key"
                               value="<?php echo esc_attr($api_key); ?>"
                               style="width:420px;font-family:monospace"
                               placeholder="kl_xxxxxxxxxxxxxxxxxxxxxxxx" />
                        <p class="description">
                            Deinen API-Key findest du in KrautLink unter <strong>Shop → API &amp; Integration</strong>.
                        </p>
                    </td>
                </tr>
                <?php if (!empty($api_key)) : ?>
                <tr>
                    <th>Webhook-URL</th>
                    <td>
                        <code style="background:#f0f0f0;padding:4px 8px;border-radius:3px">
                            https://kraut-link.base44.app/api/webhook/<?php echo esc_html($api_key); ?>
                        </code>
                        <p class="description">Wird automatisch bei jeder neuen Bestellung aufgerufen.</p>
                    </td>
                </tr>
                <tr>
                    <th>Status</th>
                    <td>
                        <span style="color:green;font-weight:500">● Aktiv</span> — 
                        neue Bestellungen werden automatisch an KrautLink übermittelt.
                    </td>
                </tr>
                <?php endif; ?>
            </table>
            <p class="submit">
                <input type="submit" class="button-primary" value="Speichern" />
                <?php if (!empty($api_key)) : ?>
                &nbsp;
                <input type="submit" name="krautlink_test" class="button" value="🔗 Verbindung testen" />
                <?php endif; ?>
            </p>
        </form>

        <hr>
        <h2>So richtest du KrautLink ein</h2>
        <ol>
            <li>Melde dich bei <a href="https://kraut-link.base44.app" target="_blank">KrautLink</a> an.</li>
            <li>Gehe zu <strong>Shop → API &amp; Integration</strong> und kopiere deinen API-Key.</li>
            <li>Trage den Key oben ein und klicke <em>Speichern</em>.</li>
            <li>Klicke <em>Verbindung testen</em> — bei Erfolg ist alles bereit.</li>
            <li>Ab sofort werden neue Bestellungen automatisch an KrautLink übermittelt.</li>
        </ol>
    </div>
    <?php
}

// ── Admin-Bar Hinweis wenn kein API-Key ───────────────────────────────────────
add_action('admin_notices', function() {
    if (!current_user_can('manage_options')) return;
    if (get_option('krautlink_api_key', '')) return;
    echo '<div class="notice notice-warning is-dismissible">
        <p>🌿 <strong>KrautLink Connector:</strong> Noch kein API-Key konfiguriert. 
        <a href="' . admin_url('options-general.php?page=krautlink') . '">Jetzt einrichten →</a></p>
    </div>';
});

// ── New Order Webhook ─────────────────────────────────────────────────────────
add_action('woocommerce_new_order', function($order_id) {
    $api_key = get_option('krautlink_api_key', '');
    if (empty($api_key)) return;

    $order = wc_get_order($order_id);
    if (!$order) return;

    $items = array_values(array_map(function($item) {
        $product = $item->get_product();
        return [
            'name'       => $item->get_name(),
            'qty'        => (int) $item->get_quantity(),
            'price'      => (float) $item->get_total(),
            'unit_price' => (float) ($item->get_total() / max(1, $item->get_quantity())),
            'sku'        => $product ? $product->get_sku() : '',
            'product_id' => $item->get_product_id(),
        ];
    }, $order->get_items()));

    $payload = [
        'event'          => 'new_order',
        'order_id'       => $order_id,
        'order_number'   => $order->get_order_number(),
        'total'          => (float) $order->get_total(),
        'subtotal'       => (float) $order->get_subtotal(),
        'tax'            => (float) $order->get_total_tax(),
        'currency'       => $order->get_currency(),
        'payment_method' => $order->get_payment_method_title(),
        'items'          => $items,
        'item_count'     => count($items),
        'customer_email' => $order->get_billing_email(),
        'customer_name'  => $order->get_billing_first_name() . ' ' . $order->get_billing_last_name(),
        'billing'        => [
            'first_name' => $order->get_billing_first_name(),
            'last_name'  => $order->get_billing_last_name(),
            'company'    => $order->get_billing_company(),
            'address_1'  => $order->get_billing_address_1(),
            'address_2'  => $order->get_billing_address_2(),
            'city'       => $order->get_billing_city(),
            'postcode'   => $order->get_billing_postcode(),
            'country'    => $order->get_billing_country(),
            'phone'      => $order->get_billing_phone(),
        ],
        'shipping'       => [
            'first_name' => $order->get_shipping_first_name(),
            'last_name'  => $order->get_shipping_last_name(),
            'address_1'  => $order->get_shipping_address_1(),
            'address_2'  => $order->get_shipping_address_2(),
            'city'       => $order->get_shipping_city(),
            'postcode'   => $order->get_shipping_postcode(),
            'country'    => $order->get_shipping_country(),
        ],
        'shop_url'       => get_site_url(),
        'timestamp'      => current_time('c'),
        'plugin_version' => '1.0.0',
    ];

    wp_remote_post('https://kraut-link.base44.app/api/webhook/' . $api_key, [
        'body'    => json_encode($payload),
        'headers' => [
            'Content-Type'        => 'application/json',
            'X-KrautLink-Version' => '1.0.0',
            'X-Shop-URL'          => get_site_url(),
        ],
        'timeout'   => 15,
        'blocking'  => false, // non-blocking: Shop-Performance nicht beeinträchtigen
    ]);
}, 10, 1);

// ── Order Status Changed ──────────────────────────────────────────────────────
add_action('woocommerce_order_status_changed', function($order_id, $old_status, $new_status) {
    $api_key = get_option('krautlink_api_key', '');
    if (empty($api_key)) return;

    wp_remote_post('https://kraut-link.base44.app/api/webhook/' . $api_key, [
        'body'    => json_encode([
            'event'      => 'order_status_changed',
            'order_id'   => $order_id,
            'old_status' => $old_status,
            'new_status' => $new_status,
            'shop_url'   => get_site_url(),
            'timestamp'  => current_time('c'),
        ]),
        'headers'  => ['Content-Type' => 'application/json'],
        'timeout'  => 10,
        'blocking' => false,
    ]);
}, 10, 3);

// ── Product Sync (on demand via REST) ────────────────────────────────────────
add_action('rest_api_init', function() {
    register_rest_route('krautlink/v1', '/products', [
        'methods'  => 'GET',
        'callback' => function($request) {
            $api_key = $request->get_header('x_krautlink_key');
            if ($api_key !== get_option('krautlink_api_key', '')) {
                return new WP_Error('unauthorized', 'Invalid API key', ['status' => 401]);
            }
            $products = wc_get_products(['limit' => 100, 'status' => 'publish']);
            $data = array_map(function($p) {
                return [
                    'id'          => $p->get_id(),
                    'name'        => $p->get_name(),
                    'sku'         => $p->get_sku(),
                    'price'       => $p->get_price(),
                    'regular_price' => $p->get_regular_price(),
                    'stock_qty'   => $p->get_stock_quantity(),
                    'in_stock'    => $p->is_in_stock(),
                    'description' => $p->get_short_description(),
                    'image'       => wp_get_attachment_url($p->get_image_id()),
                    'categories'  => wp_list_pluck($p->get_category_ids(), null),
                ];
            }, $products);
            return rest_ensure_response($data);
        },
        'permission_callback' => '__return_true',
    ]);
});
