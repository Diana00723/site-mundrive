<?php
/**
 * Diego (assistant MunDrive) — quotas quotidiens côté serveur.
 *
 * À installer d'une de ces façons :
 *  - Collé à la fin du functions.php de ton thème enfant, OU
 *  - Dans un fichier wp-content/mu-plugins/diego-quota.php (créer le dossier
 *    mu-plugins s'il n'existe pas — ces fichiers s'activent automatiquement), OU
 *  - Via un plugin "Code Snippets" (type WPCode) si tu ne peux pas modifier
 *    les fichiers du thème/serveur directement.
 *
 * Crée une petite table dans la base WordPress pour compter, par visiteur
 * (cookie anonyme signé, httpOnly — illisible et non modifiable en JavaScript)
 * et par jour, le nombre de questions posées dans chaque catégorie :
 *   - entretien : 30 / jour (questions sourcées par un article du blog)
 *   - compat    : 30 / jour (compatibilité pièces / produits)
 *   - general   : 10 / jour (questions auto générales sans article dédié)
 *
 * L'IP est enregistrée à titre de journal / signal d'abus, mais ne bloque
 * pas automatiquement (pour éviter de pénaliser des visiteurs derrière une
 * même box/IP partagée) — tu peux l'interroger toi-même si besoin.
 */

if (!defined('ABSPATH')) exit;

define('MUNDRIVE_DIEGO_LIMITS', [
    'entretien' => 30,
    'compat'    => 30,
    'general'   => 10,
]);

function mundrive_diego_table() {
    global $wpdb;
    return $wpdb->prefix . 'diego_quota';
}

function mundrive_diego_maybe_create_table() {
    if (get_option('mundrive_diego_db_v1') === '1') {
        return;
    }
    global $wpdb;
    $table           = mundrive_diego_table();
    $charset_collate = $wpdb->get_charset_collate();
    $sql = "CREATE TABLE IF NOT EXISTS $table (
        uid VARCHAR(64) NOT NULL,
        day DATE NOT NULL,
        q_entretien SMALLINT UNSIGNED NOT NULL DEFAULT 0,
        q_compat SMALLINT UNSIGNED NOT NULL DEFAULT 0,
        q_general SMALLINT UNSIGNED NOT NULL DEFAULT 0,
        ip VARCHAR(45) NOT NULL DEFAULT '',
        PRIMARY KEY (uid, day)
    ) $charset_collate;";
    require_once ABSPATH . 'wp-admin/includes/upgrade.php';
    dbDelta($sql);
    update_option('mundrive_diego_db_v1', '1');
}
add_action('init', 'mundrive_diego_maybe_create_table');

function mundrive_diego_get_uid() {
    if (!empty($_COOKIE['diego_uid'])) {
        return preg_replace('/[^a-zA-Z0-9\-]/', '', $_COOKIE['diego_uid']);
    }
    $uid = wp_generate_uuid4();
    setcookie('diego_uid', $uid, time() + 30 * DAY_IN_SECONDS, '/', '', is_ssl(), true);
    return $uid;
}

function mundrive_diego_client_ip() {
    foreach (['HTTP_CF_CONNECTING_IP', 'HTTP_X_FORWARDED_FOR', 'REMOTE_ADDR'] as $key) {
        if (!empty($_SERVER[$key])) {
            $ip = trim(explode(',', $_SERVER[$key])[0]);
            if (filter_var($ip, FILTER_VALIDATE_IP)) {
                return $ip;
            }
        }
    }
    return '';
}

add_action('rest_api_init', function () {
    register_rest_route('mundrive/v1', '/diego-quota', [
        'methods'             => 'POST',
        'callback'            => 'mundrive_diego_quota_check',
        'permission_callback' => '__return_true',
    ]);
});

function mundrive_diego_quota_check(WP_REST_Request $req) {
    global $wpdb;
    $limits  = MUNDRIVE_DIEGO_LIMITS;
    $columns = ['entretien' => 'q_entretien', 'compat' => 'q_compat', 'general' => 'q_general'];

    $cat = sanitize_key((string) $req->get_param('category'));
    if (!isset($limits[$cat])) {
        // catégorie inconnue ou non soumise à quota (ex: merci, newsletter, humain)
        return new WP_REST_Response(['allowed' => true, 'remaining' => null], 200);
    }
    $col = $columns[$cat];

    $table = mundrive_diego_table();
    $uid   = mundrive_diego_get_uid();
    $ip    = mundrive_diego_client_ip();
    $today = current_time('Y-m-d');

    $wpdb->query($wpdb->prepare(
        "INSERT INTO $table (uid, day, ip) VALUES (%s, %s, %s)
         ON DUPLICATE KEY UPDATE ip = VALUES(ip)",
        $uid, $today, $ip
    ));

    $used = (int) $wpdb->get_var($wpdb->prepare(
        "SELECT $col FROM $table WHERE uid = %s AND day = %s", $uid, $today
    ));

    if ($used >= $limits[$cat]) {
        return new WP_REST_Response(['allowed' => false, 'remaining' => 0], 200);
    }

    $wpdb->query($wpdb->prepare(
        "UPDATE $table SET $col = $col + 1 WHERE uid = %s AND day = %s", $uid, $today
    ));

    return new WP_REST_Response(['allowed' => true, 'remaining' => $limits[$cat] - $used - 1], 200);
}
