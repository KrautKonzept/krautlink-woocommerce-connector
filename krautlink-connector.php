<?php
/**
 * Plugin Name: KrautLink Connector
 * Plugin URI:  https://krautkonzept.de
 * Description: Verbindet deinen WooCommerce-Shop mit KrautLink — automatische Order-Weiterleitung, Provisions-Tracking, bidirektionaler Produkt-Sync.
 * Version:     1.2.0
 * Author:      KrautKonzept
 * Author URI:  https://krautkonzept.de
 * License:     GPL-2.0+
 * Requires at least: 5.0
 * Requires PHP: 7.4
 * WC requires at least: 5.0
 */

if (!defined('ABSPATH')) exit;

define('KRAUTLINK_VERSION', '1.2.0');
define('KRAUTLINK_API_BASE', 'https://kraut-supply-connect.base44.app');
define('KRAUTLINK_APP_ID',   '69be01a47c41c176c1008eea');

// ═══════════════════════════════════════════════════════════════════════
// 1. ADMIN SETTINGS
// ═══════════════════════════════════════════════════════════════════════

add_action('admin_menu', function() {
    add_options_page('KrautLink', 'KrautLink', 'manage_options', 'krautlink', 'krautlink_settings_page');
});

add_action('admin_init', function() {
    register_setting('krautlink_options', 'krautlink_api_key',  ['sanitize_callback' => 'sanitize_text_field']);
    register_setting('krautlink_options', 'krautlink_shop_id',  ['sanitize_callback' => 'sanitize_text_field']);
    register_setting('krautlink_options', 'krautlink_auto_sync',['sanitize_callback' => 'absint']);
    register_setting('krautlink_options', 'krautlink_sync_interval', ['sanitize_callback' => 'absint']);
    register_setting('krautlink_options', 'krautlink_webhook_secret', ['sanitize_callback' => 'sanitize_text_field']);
});

function krautlink_settings_page() {
    $api_key       = get_option('krautlink_api_key', '');
    $shop_id       = get_option('krautlink_shop_id', '');
    $auto_sync     = get_option('krautlink_auto_sync', 1);
    $sync_interval = get_option('krautlink_sync_interval', 3600);
    $wh_secret     = get_option('krautlink_webhook_secret', '');
    $last_sync     = get_option('krautlink_last_sync', '');
    $last_sync_count = get_option('krautlink_last_sync_count', '—');
    $test_html     = '';

    // ── Verbindungstest ──────────────────────────────────────────────
    if (isset($_POST['krautlink_test']) && check_admin_referer('krautlink_save') && !empty($api_key)) {
        $url = KRAUTLINK_API_BASE . '/api/apps/' . KRAUTLINK_APP_ID . '/entities/ShopListing?limit=1';
        $resp = wp_remote_get($url, [
            'headers' => ['Authorization' => 'Bearer ' . $api_key],
            'timeout' => 10,
        ]);
        if (is_wp_error($resp)) {
            $test_html = '<div class="notice notice-error"><p>✗ Verbindungsfehler: ' . esc_html($resp->get_error_message()) . '</p></div>';
        } else {
            $code = wp_remote_retrieve_response_code($resp);
            $test_html = ($code < 300)
                ? '<div class="notice notice-success"><p>✓ Verbindung erfolgreich! KrautLink antwortet korrekt (HTTP ' . $code . ').</p></div>'
                : '<div class="notice notice-error"><p>✗ Server antwortete mit HTTP ' . $code . ' — API-Key und Shop-ID prüfen.</p></div>';
        }
    }

    // ── Manueller Sync ───────────────────────────────────────────────
    if (isset($_POST['krautlink_sync_now']) && check_admin_referer('krautlink_save')) {
        $count = krautlink_sync_products();
        $test_html = '<div class="notice notice-success"><p>✓ Sync abgeschlossen: ' . intval($count) . ' Produkte synchronisiert.</p></div>';
    }

    // ── Speichern ────────────────────────────────────────────────────
    if (isset($_POST['krautlink_api_key']) && check_admin_referer('krautlink_save')) {
        update_option('krautlink_api_key', sanitize_text_field($_POST['krautlink_api_key']));
        update_option('krautlink_shop_id', sanitize_text_field($_POST['krautlink_shop_id']));
        update_option('krautlink_auto_sync', isset($_POST['krautlink_auto_sync']) ? 1 : 0);
        update_option('krautlink_sync_interval', absint($_POST['krautlink_sync_interval']));
        if (!empty($_POST['krautlink_webhook_secret'])) {
            update_option('krautlink_webhook_secret', sanitize_text_field($_POST['krautlink_webhook_secret']));
        }
        // Cron neu planen
        wp_clear_scheduled_hook('krautlink_cron_sync');
        if (get_option('krautlink_auto_sync', 1) && !empty(get_option('krautlink_api_key', ''))) {
            wp_schedule_event(time(), 'krautlink_interval', 'krautlink_cron_sync');
        }
        $api_key       = get_option('krautlink_api_key', '');
        $shop_id       = get_option('krautlink_shop_id', '');
        $auto_sync     = get_option('krautlink_auto_sync', 1);
        $sync_interval = get_option('krautlink_sync_interval', 3600);
        $wh_secret     = get_option('krautlink_webhook_secret', '');
        echo '<div class="notice notice-success"><p>✓ Einstellungen gespeichert!</p></div>';
    }

    echo $test_html;
    $next = wp_next_scheduled('krautlink_cron_sync');
    ?>
    <div class="wrap">
        <h1>🌿 KrautLink Connector <span style="font-size:13px;color:#888;font-weight:400">v<?php echo KRAUTLINK_VERSION; ?></span></h1>
        <p>Bidirektionale Verbindung zwischen WooCommerce und KrautLink — Produkt-Sync, Order-Tracking, Provisions-Abrechnung.</p>

        <form method="post">
            <?php wp_nonce_field('krautlink_save'); ?>
            <h2>Verbindung</h2>
            <table class="form-table">
                <tr>
                    <th><label for="krautlink_api_key">API-Key</label></th>
                    <td>
                        <input type="text" id="krautlink_api_key" name="krautlink_api_key"
                               value="<?php echo esc_attr($api_key); ?>"
                               style="width:420px;font-family:monospace"
                               placeholder="Bearer-Token aus KrautLink → API & Integration" />
                        <p class="description">Aus KrautLink unter <strong>Shop → API &amp; Integration</strong> kopieren.</p>
                    </td>
                </tr>
                <tr>
                    <th><label for="krautlink_shop_id">Shop-ID</label></th>
                    <td>
                        <input type="text" id="krautlink_shop_id" name="krautlink_shop_id"
                               value="<?php echo esc_attr($shop_id); ?>"
                               style="width:280px;font-family:monospace"
                               placeholder="Deine Shop-ID aus KrautLink" />
                        <p class="description">Aus KrautLink → Shop-Dashboard → oben rechts hinter deinem Shop-Namen.</p>
                    </td>
                </tr>
                <?php if (!empty($wh_secret)) : ?>
                <tr>
                    <th>Webhook-Secret</th>
                    <td>
                        <input type="text" name="krautlink_webhook_secret"
                               value="<?php echo esc_attr($wh_secret); ?>"
                               style="width:300px;font-family:monospace" />
                        <p class="description">HMAC-Secret zur Verifikation eingehender Webhooks von KrautLink. Leer lassen = kein Check.</p>
                    </td>
                </tr>
                <?php else: ?>
                <tr>
                    <th>Webhook-Secret <span style="color:orange">(optional)</span></th>
                    <td>
                        <input type="text" name="krautlink_webhook_secret"
                               value=""
                               style="width:300px;font-family:monospace"
                               placeholder="Für HMAC-Signatur-Verifikation (optional)" />
                        <p class="description">Falls in KrautLink konfiguriert: hier eintragen für zusätzliche Sicherheit.</p>
                    </td>
                </tr>
                <?php endif; ?>
            </table>

            <h2>Produkt-Sync</h2>
            <table class="form-table">
                <tr>
                    <th>Auto-Sync</th>
                    <td>
                        <label>
                            <input type="checkbox" name="krautlink_auto_sync" value="1" <?php checked($auto_sync, 1); ?> />
                            Produkte automatisch von KrautLink in WooCommerce synchronisieren
                        </label>
                    </td>
                </tr>
                <tr>
                    <th><label for="krautlink_sync_interval">Sync-Intervall</label></th>
                    <td>
                        <select name="krautlink_sync_interval">
                            <option value="3600"  <?php selected($sync_interval, 3600);  ?>>Stündlich</option>
                            <option value="10800" <?php selected($sync_interval, 10800); ?>>Alle 3 Stunden</option>
                            <option value="21600" <?php selected($sync_interval, 21600); ?>>Alle 6 Stunden</option>
                            <option value="86400" <?php selected($sync_interval, 86400); ?>>Täglich</option>
                        </select>
                    </td>
                </tr>
                <?php if ($last_sync) : ?>
                <tr>
                    <th>Letzter Sync</th>
                    <td>
                        <?php echo esc_html($last_sync); ?> — <strong><?php echo esc_html($last_sync_count); ?> Produkte</strong>
                        <?php if ($next): ?>
                        <br><span style="color:#666;font-size:12px">Nächster Auto-Sync: <?php echo human_time_diff($next, time()); ?></span>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endif; ?>
            </table>

            <p class="submit">
                <input type="submit" class="button-primary" value="Speichern" />
                <?php if (!empty($api_key)) : ?>
                &nbsp;
                <input type="submit" name="krautlink_test" class="button" value="🔗 Verbindung testen" />
                &nbsp;
                <input type="submit" name="krautlink_sync_now" class="button button-secondary" value="↻ Jetzt syncen" />
                <?php endif; ?>
            </p>
        </form>

        <?php if (!empty($api_key)) : ?>
        <hr>
        <h2>Status</h2>
        <table class="form-table">
            <tr><th>Verbindung</th><td><span style="color:green">● Aktiv</span></td></tr>
            <tr><th>Webhook-URL (Order → KrautLink)</th>
                <td><code><?php echo esc_html(get_rest_url(null, 'krautlink/v1/order-webhook')); ?></code></td></tr>
            <tr><th>REST-Endpoint (Produkte lesen)</th>
                <td><code><?php echo esc_html(get_rest_url(null, 'krautlink/v1/products')); ?></code>
                <br><small style="color:#666">Header: <code>X-KrautLink-Key: [API-Key]</code></small></td></tr>
            <tr><th>REST-Endpoint (Sync triggern)</th>
                <td><code><?php echo esc_html(get_rest_url(null, 'krautlink/v1/sync')); ?></code>
                <br><small style="color:#666">POST — Header: <code>X-KrautLink-Key: [API-Key]</code></small></td></tr>
        </table>
        <?php endif; ?>

        <hr>
        <h2>Einrichtung</h2>
        <ol>
            <li>In KrautLink einloggen und zu <strong>Shop → API &amp; Integration</strong> navigieren.</li>
            <li>API-Key und Shop-ID kopieren und oben eintragen.</li>
            <li><em>Verbindung testen</em> klicken — bei Erfolg ist alles bereit.</li>
            <li><em>Jetzt syncen</em> klicken um Produkte sofort zu importieren.</li>
            <li>Auto-Sync aktivieren für automatische Aktualisierung.</li>
        </ol>
    </div>
    <?php
}

// ═══════════════════════════════════════════════════════════════════════
// 2. CUSTOM CRON INTERVAL
// ═══════════════════════════════════════════════════════════════════════

add_filter('cron_schedules', function($schedules) {
    $interval = (int) get_option('krautlink_sync_interval', 3600);
    $schedules['krautlink_interval'] = [
        'interval' => $interval,
        'display'  => 'KrautLink Sync (' . ($interval / 3600) . 'h)',
    ];
    return $schedules;
});

// ═══════════════════════════════════════════════════════════════════════
// 3. ADMIN NOTICES
// ═══════════════════════════════════════════════════════════════════════

add_action('admin_notices', function() {
    if (!current_user_can('manage_options')) return;
    if (!is_plugin_active(plugin_basename(__FILE__))) return;

    $api_key = get_option('krautlink_api_key', '');
    $shop_id = get_option('krautlink_shop_id', '');

    if (empty($api_key)) {
        echo '<div class="notice notice-warning is-dismissible">
            <p>🌿 <strong>KrautLink:</strong> API-Key fehlt.
            <a href="' . esc_url(admin_url('options-general.php?page=krautlink')) . '">Jetzt einrichten →</a></p>
        </div>';
    } elseif (empty($shop_id)) {
        echo '<div class="notice notice-warning is-dismissible">
            <p>🌿 <strong>KrautLink:</strong> Shop-ID fehlt — Produkt-Sync nicht möglich.
            <a href="' . esc_url(admin_url('options-general.php?page=krautlink')) . '">Einrichten →</a></p>
        </div>';
    }
});

// ═══════════════════════════════════════════════════════════════════════
// 4. PRODUKTE VON KRAUTLINK HERUNTERLADEN & IN WOOCOMMERCE ANLEGEN/UPDATEN
// ═══════════════════════════════════════════════════════════════════════

function krautlink_sync_products() {
    $api_key = get_option('krautlink_api_key', '');
    $shop_id = get_option('krautlink_shop_id', '');

    if (empty($api_key) || empty($shop_id)) {
        error_log('[KrautLink] Sync abgebrochen: API-Key oder Shop-ID fehlt.');
        return 0;
    }

    // ── Listings des Shops abrufen ───────────────────────────────────
    $url = KRAUTLINK_API_BASE . '/api/apps/' . KRAUTLINK_APP_ID
         . '/entities/ShopListing?filters=shop_id:' . rawurlencode($shop_id) . '&limit=200';

    $resp = wp_remote_get($url, [
        'headers' => ['Authorization' => 'Bearer ' . $api_key],
        'timeout' => 30,
    ]);

    if (is_wp_error($resp)) {
        error_log('[KrautLink] Listings-Abruf fehlgeschlagen: ' . $resp->get_error_message());
        return 0;
    }

    $code = wp_remote_retrieve_response_code($resp);
    if ($code !== 200) {
        error_log('[KrautLink] Listings-Abruf HTTP ' . $code);
        return 0;
    }

    $listings = json_decode(wp_remote_retrieve_body($resp), true);
    if (!is_array($listings)) {
        error_log('[KrautLink] Listings-Response kein Array.');
        return 0;
    }

    $synced = 0;

    foreach ($listings as $listing) {
        if (empty($listing['product_id'])) continue;

        // ── Produktdetails abrufen ───────────────────────────────────
        $prod_url = KRAUTLINK_API_BASE . '/api/apps/' . KRAUTLINK_APP_ID
                  . '/entities/Product/' . $listing['product_id'];

        $prod_resp = wp_remote_get($prod_url, [
            'headers' => ['Authorization' => 'Bearer ' . $api_key],
            'timeout' => 15,
        ]);

        if (is_wp_error($prod_resp) || wp_remote_retrieve_response_code($prod_resp) !== 200) {
            error_log('[KrautLink] Produkt ' . $listing['product_id'] . ' nicht abrufbar.');
            continue;
        }

        $product = json_decode(wp_remote_retrieve_body($prod_resp), true);
        if (empty($product) || empty($product['name'])) continue;

        // ── Preis bestimmen ──────────────────────────────────────────
        $price = !empty($listing['custom_price'])
            ? floatval($listing['custom_price'])
            : (!empty($product['price_suggested_retail']) ? floatval($product['price_suggested_retail']) : 0);

        // ── Bestand ──────────────────────────────────────────────────
        $stock_qty = isset($product['stock_qty']) ? intval($product['stock_qty']) : null;

        // ── Vorhandenes WC-Produkt suchen (über Meta _krautlink_id) ──
        $existing_posts = get_posts([
            'post_type'      => 'product',
            'meta_key'       => '_krautlink_id',
            'meta_value'     => $listing['product_id'],
            'posts_per_page' => 1,
            'post_status'    => 'any',
        ]);

        if (!empty($existing_posts)) {
            // ── UPDATE ───────────────────────────────────────────────
            $wc_product = wc_get_product($existing_posts[0]->ID);
            if (!$wc_product) continue;

            $wc_product->set_regular_price($price);
            $wc_product->set_price($price);

            if ($stock_qty !== null) {
                $wc_product->set_stock_quantity($stock_qty);
                $wc_product->set_manage_stock(true);
                $wc_product->set_stock_status($stock_qty > 0 ? 'instock' : 'outofstock');
            }

            $wc_product->save();
            error_log('[KrautLink] Produkt aktualisiert: ' . $product['name']);

        } else {
            // ── CREATE ───────────────────────────────────────────────
            $wc_product = new WC_Product_Simple();
            $wc_product->set_name($product['name']);
            $wc_product->set_description($product['description'] ?? '');
            $wc_product->set_short_description($product['description'] ?? '');
            $wc_product->set_sku(!empty($product['sku']) ? $product['sku'] : 'KL-' . substr($listing['product_id'], 0, 8));
            $wc_product->set_regular_price($price);
            $wc_product->set_price($price);
            $wc_product->set_status('publish');

            if ($stock_qty !== null) {
                $wc_product->set_stock_quantity($stock_qty);
                $wc_product->set_manage_stock(true);
                $wc_product->set_stock_status($stock_qty > 0 ? 'instock' : 'outofstock');
            }

            // Bild setzen wenn URL vorhanden
            if (!empty($product['image_url'])) {
                krautlink_set_product_image($wc_product, $product['image_url'], $product['name']);
            }

            $product_id = $wc_product->save();

            // Meta-Daten speichern
            update_post_meta($product_id, '_krautlink_id', $listing['product_id']);
            update_post_meta($product_id, '_krautlink_listing_id', $listing['id'] ?? '');
            update_post_meta($product_id, '_krautlink_shop_id', $shop_id);
            update_post_meta($product_id, '_krautlink_supplier', $product['supplier_name'] ?? '');
            update_post_meta($product_id, '_krautlink_synced_at', current_time('mysql'));

            // Kategorie setzen
            if (!empty($product['category'])) {
                $term = term_exists($product['category'], 'product_cat');
                if (!$term) {
                    $term = wp_insert_term($product['category'], 'product_cat');
                }
                if (!is_wp_error($term)) {
                    $term_id = is_array($term) ? $term['term_id'] : $term;
                    wp_set_post_terms($product_id, [$term_id], 'product_cat');
                }
            }

            error_log('[KrautLink] Produkt angelegt: ' . $product['name'] . ' (WC-ID: ' . $product_id . ')');
        }

        $synced++;
    }

    // ── Sync-Zeit und Anzahl speichern ───────────────────────────────
    update_option('krautlink_last_sync', current_time('d.m.Y H:i:s'));
    update_option('krautlink_last_sync_count', $synced);

    error_log('[KrautLink] Sync abgeschlossen: ' . $synced . ' Produkte.');
    return $synced;
}

// ── Produktbild aus URL importieren ─────────────────────────────────────────
function krautlink_set_product_image($wc_product, $image_url, $product_name) {
    if (!function_exists('media_sideload_image')) {
        require_once ABSPATH . 'wp-admin/includes/media.php';
        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/image.php';
    }
    $attachment_id = media_sideload_image($image_url, $wc_product->get_id(), $product_name, 'id');
    if (!is_wp_error($attachment_id)) {
        $wc_product->set_image_id($attachment_id);
    }
}

// ═══════════════════════════════════════════════════════════════════════
// 5. AUTO-SYNC via WP-CRON
// ═══════════════════════════════════════════════════════════════════════

add_action('krautlink_cron_sync', 'krautlink_sync_products');

add_action('plugins_loaded', function() {
    $api_key   = get_option('krautlink_api_key', '');
    $auto_sync = get_option('krautlink_auto_sync', 1);

    if ($auto_sync && !empty($api_key) && !wp_next_scheduled('krautlink_cron_sync')) {
        wp_schedule_event(time(), 'krautlink_interval', 'krautlink_cron_sync');
    }
});

register_deactivation_hook(__FILE__, function() {
    wp_clear_scheduled_hook('krautlink_cron_sync');
});

// ═══════════════════════════════════════════════════════════════════════
// 6. BESTAND ZURÜCK AN KRAUTLINK MELDEN (WC → KrautLink)
// ═══════════════════════════════════════════════════════════════════════

add_action('woocommerce_product_set_stock', function($wc_product) {
    $api_key      = get_option('krautlink_api_key', '');
    $krautlink_id = get_post_meta($wc_product->get_id(), '_krautlink_id', true);

    if (empty($api_key) || empty($krautlink_id)) return;

    $url = KRAUTLINK_API_BASE . '/api/apps/' . KRAUTLINK_APP_ID
         . '/entities/Product/' . $krautlink_id;

    wp_remote_request($url, [
        'method'  => 'PUT',
        'headers' => [
            'Authorization' => 'Bearer ' . $api_key,
            'Content-Type'  => 'application/json',
        ],
        'body'      => json_encode([
            'reported_stock_wc' => $wc_product->get_stock_quantity(),
            'last_wc_update'    => current_time('c'),
        ]),
        'timeout'   => 10,
        'blocking'  => false,
    ]);
});

// ═══════════════════════════════════════════════════════════════════════
// 7. ORDER WEBHOOK (WC → KrautLink) — neue Bestellung
// ═══════════════════════════════════════════════════════════════════════

add_action('woocommerce_payment_complete', function($order_id) {
    krautlink_send_order_webhook($order_id, 'payment_complete');
}, 10, 1);

add_action('woocommerce_new_order', function($order_id) {
    krautlink_send_order_webhook($order_id, 'new_order');
}, 10, 1);

function krautlink_send_order_webhook($order_id, $event = 'new_order') {
    $api_key = get_option('krautlink_api_key', '');
    $shop_id = get_option('krautlink_shop_id', '');
    if (empty($api_key)) return;

    $order = wc_get_order($order_id);
    if (!$order) return;

    // Nur KrautLink-Produkte übermitteln
    $items = [];
    foreach ($order->get_items() as $item) {
        $product      = $item->get_product();
        $krautlink_id = $product ? get_post_meta($product->get_id(), '_krautlink_id', true) : '';
        $listing_id   = $product ? get_post_meta($product->get_id(), '_krautlink_listing_id', true) : '';
        $items[] = [
            'name'          => $item->get_name(),
            'qty'           => (int) $item->get_quantity(),
            'price'         => (float) $item->get_total(),
            'unit_price'    => (float) ($item->get_total() / max(1, $item->get_quantity())),
            'sku'           => $product ? $product->get_sku() : '',
            'product_id'    => $item->get_product_id(),
            'krautlink_id'  => $krautlink_id,
            'listing_id'    => $listing_id,
        ];
    }

    $payload = [
        'event'          => $event,
        'order_id'       => $order_id,
        'order_number'   => $order->get_order_number(),
        'shop_id'        => $shop_id,
        'total'          => (float) $order->get_total(),
        'subtotal'       => (float) $order->get_subtotal(),
        'tax'            => (float) $order->get_total_tax(),
        'currency'       => $order->get_currency(),
        'payment_method' => $order->get_payment_method_title(),
        'status'         => $order->get_status(),
        'items'          => $items,
        'item_count'     => count($items),
        'customer_email' => $order->get_billing_email(),
        'customer_name'  => trim($order->get_billing_first_name() . ' ' . $order->get_billing_last_name()),
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
        'plugin_version' => KRAUTLINK_VERSION,
    ];

    // HMAC-Signatur
    $body    = json_encode($payload);
    $headers = [
        'Content-Type'        => 'application/json',
        'X-KrautLink-Version' => KRAUTLINK_VERSION,
        'X-Shop-URL'          => get_site_url(),
        'Authorization'       => 'Bearer ' . $api_key,
    ];
    $wh_secret = get_option('krautlink_webhook_secret', '');
    if (!empty($wh_secret)) {
        $headers['X-KrautLink-Signature'] = 'sha256=' . hash_hmac('sha256', $body, $wh_secret);
    }

    wp_remote_post(KRAUTLINK_API_BASE . '/api/apps/' . KRAUTLINK_APP_ID . '/entities/Order', [
        'body'     => $body,
        'headers'  => $headers,
        'timeout'  => 15,
        'blocking' => false,
    ]);
}

// ═══════════════════════════════════════════════════════════════════════
// 8. ORDER STATUS CHANGE WEBHOOK
// ═══════════════════════════════════════════════════════════════════════

add_action('woocommerce_order_status_changed', function($order_id, $old_status, $new_status) {
    $api_key = get_option('krautlink_api_key', '');
    if (empty($api_key)) return;

    $order   = wc_get_order($order_id);
    $shop_id = get_option('krautlink_shop_id', '');
    $body    = json_encode([
        'event'      => 'order_status_changed',
        'order_id'   => $order_id,
        'shop_id'    => $shop_id,
        'old_status' => $old_status,
        'new_status' => $new_status,
        'wc_order_id'=> (string) $order_id,
        'shop_url'   => get_site_url(),
        'timestamp'  => current_time('c'),
    ]);

    $headers = [
        'Content-Type'  => 'application/json',
        'Authorization' => 'Bearer ' . $api_key,
    ];
    $wh_secret = get_option('krautlink_webhook_secret', '');
    if (!empty($wh_secret)) {
        $headers['X-KrautLink-Signature'] = 'sha256=' . hash_hmac('sha256', $body, $wh_secret);
    }

    wp_remote_post(KRAUTLINK_API_BASE . '/api/webhook/' . $api_key, [
        'body'     => $body,
        'headers'  => $headers,
        'timeout'  => 10,
        'blocking' => false,
    ]);
}, 10, 3);

// ═══════════════════════════════════════════════════════════════════════
// 9. REST ENDPOINTS
// ═══════════════════════════════════════════════════════════════════════

add_action('rest_api_init', function() {

    // ── A) Produkte aus WC zurückgeben (KrautLink zieht WC-Produkte) ──
    register_rest_route('krautlink/v1', '/products', [
        'methods'             => 'GET',
        'callback'            => function(WP_REST_Request $request) {
            if (!krautlink_verify_api_key($request)) {
                return new WP_Error('unauthorized', 'Invalid API key', ['status' => 401]);
            }
            $products = wc_get_products(['limit' => 200, 'status' => 'publish']);
            $data = array_map(function($p) {
                return [
                    'id'               => $p->get_id(),
                    'name'             => $p->get_name(),
                    'sku'              => $p->get_sku(),
                    'price'            => $p->get_price(),
                    'regular_price'    => $p->get_regular_price(),
                    'sale_price'       => $p->get_sale_price(),
                    'stock_quantity'   => $p->get_stock_quantity(),
                    'in_stock'         => $p->is_in_stock(),
                    'description'      => $p->get_short_description(),
                    'image'            => wp_get_attachment_url($p->get_image_id()),
                    'krautlink_id'     => get_post_meta($p->get_id(), '_krautlink_id', true),
                    'krautlink_listing'=> get_post_meta($p->get_id(), '_krautlink_listing_id', true),
                    'synced_at'        => get_post_meta($p->get_id(), '_krautlink_synced_at', true),
                ];
            }, $products);
            return rest_ensure_response([
                'products'     => $data,
                'total'        => count($data),
                'shop_url'     => get_site_url(),
                'last_updated' => current_time('c'),
                'plugin_version' => KRAUTLINK_VERSION,
            ]);
        },
        'permission_callback' => '__return_true',
    ]);

    // ── B) Manueller Sync-Trigger von KrautLink aus ──────────────────
    register_rest_route('krautlink/v1', '/sync', [
        'methods'             => 'POST',
        'callback'            => function(WP_REST_Request $request) {
            if (!krautlink_verify_api_key($request)) {
                return new WP_Error('unauthorized', 'Invalid API key', ['status' => 401]);
            }
            $count = krautlink_sync_products();
            return rest_ensure_response([
                'success'    => true,
                'synced'     => $count,
                'timestamp'  => current_time('c'),
                'shop_url'   => get_site_url(),
            ]);
        },
        'permission_callback' => '__return_true',
    ]);

    // ── C) Eingehende Webhooks von KrautLink (z.B. Preisänderung) ────
    register_rest_route('krautlink/v1', '/order-webhook', [
        'methods'             => 'POST',
        'callback'            => function(WP_REST_Request $request) {
            // HMAC-Signatur prüfen wenn Secret gesetzt
            $wh_secret = get_option('krautlink_webhook_secret', '');
            if (!empty($wh_secret)) {
                $sig      = $request->get_header('x_krautlink_signature');
                $expected = 'sha256=' . hash_hmac('sha256', $request->get_body(), $wh_secret);
                if (!hash_equals($expected, (string)$sig)) {
                    return new WP_Error('forbidden', 'Invalid signature', ['status' => 403]);
                }
            }
            // Hier könnten eingehende Events verarbeitet werden
            return rest_ensure_response(['received' => true]);
        },
        'permission_callback' => '__return_true',
    ]);

});

// ── API-Key Verifikation (gemeinsame Funktion) ───────────────────────────────
function krautlink_verify_api_key(WP_REST_Request $request) {
    $api_key       = get_option('krautlink_api_key', '');
    $provided_key  = $request->get_header('x_krautlink_key');

    // Fallback: Bearer Token im Authorization-Header
    if (empty($provided_key)) {
        $auth = $request->get_header('authorization');
        if ($auth && strpos($auth, 'Bearer ') === 0) {
            $provided_key = substr($auth, 7);
        }
    }

    return !empty($api_key) && hash_equals($api_key, (string)$provided_key);
}
