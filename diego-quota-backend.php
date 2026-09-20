<?php
/**
 * Diego (assistant MunDrive) — backend complet : quotas serveur, vraie IA
 * (Claude), recherche automatique dans tes articles et tes produits
 * WooCommerce, journal des conversations, et tableau de bord admin.
 *
 * À installer d'une de ces façons :
 *  - Collé à la fin du functions.php de ton thème enfant, OU
 *  - Dans un fichier wp-content/mu-plugins/diego-quota.php (créer le dossier
 *    mu-plugins s'il n'existe pas — ces fichiers s'activent automatiquement), OU
 *  - Via un plugin "Code Snippets" (type WPCode) si tu ne peux pas modifier
 *    les fichiers du thème/serveur directement.
 *
 * IMPORTANT — clé API Claude :
 * Pour activer les vraies réponses IA, crée une clé sur console.anthropic.com
 * puis ajoute cette ligne dans wp-config.php (PAS dans ce fichier, jamais dans
 * un dépôt Git — wp-config.php n'est jamais commité) juste avant la ligne
 * "That's all, stop editing!" :
 *
 *     define('MUNDRIVE_ANTHROPIC_KEY', 'sk-ant-xxxxxxxx');
 *
 * Tant que cette clé n'est pas définie, Diego continue de fonctionner :
 * il répond avec tes vrais articles / produits quand il en trouve, et avec
 * des réponses toutes faites sinon — il ne « casse » jamais, il devient
 * juste plus intelligent une fois la clé ajoutée.
 */

if (!defined('ABSPATH')) exit;

define('MUNDRIVE_DIEGO_LIMITS', [
    'entretien' => 30,
    'compat'    => 30,
    'general'   => 10,
]);
define('MUNDRIVE_DIEGO_MODEL', 'claude-opus-5');

// budget IA global (tous visiteurs confondus) : 5000 réponses IA par quinzaine
// (1er-15 / 16-fin de mois) => jamais plus de 10 000/mois. Au-delà, Diego repasse
// en réponses scriptées (articles/produits trouvés, ou message générique) sans
// appeler l'API — les visiteurs peuvent toujours discuter, juste sans IA.
define('MUNDRIVE_DIEGO_GLOBAL_AI_BUDGET', 5000);

// durée de conservation des questions dans le journal (tableau de bord) avant purge automatique
define('MUNDRIVE_DIEGO_LOG_RETENTION_DAYS', 365);
define('MUNDRIVE_DIEGO_QUOTA_RETENTION_DAYS', 90);

/* ============================================================
   TABLES : quotas quotidiens + journal des conversations
   ============================================================ */

function mundrive_diego_quota_table() {
    global $wpdb;
    return $wpdb->prefix . 'diego_quota';
}
function mundrive_diego_log_table() {
    global $wpdb;
    return $wpdb->prefix . 'diego_log';
}

function mundrive_diego_maybe_create_tables() {
    if (get_option('mundrive_diego_db_v2') === '1') {
        return;
    }
    global $wpdb;
    $charset_collate = $wpdb->get_charset_collate();
    require_once ABSPATH . 'wp-admin/includes/upgrade.php';

    $quota = mundrive_diego_quota_table();
    dbDelta("CREATE TABLE IF NOT EXISTS $quota (
        uid VARCHAR(64) NOT NULL,
        day DATE NOT NULL,
        q_entretien SMALLINT UNSIGNED NOT NULL DEFAULT 0,
        q_compat SMALLINT UNSIGNED NOT NULL DEFAULT 0,
        q_general SMALLINT UNSIGNED NOT NULL DEFAULT 0,
        ip VARCHAR(45) NOT NULL DEFAULT '',
        PRIMARY KEY (uid, day)
    ) $charset_collate;");

    $log = mundrive_diego_log_table();
    dbDelta("CREATE TABLE IF NOT EXISTS $log (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        created_at DATETIME NOT NULL,
        uid VARCHAR(64) NOT NULL,
        category VARCHAR(20) NULL,
        question TEXT NOT NULL,
        matched_article_id BIGINT UNSIGNED NULL,
        matched_product_id BIGINT UNSIGNED NULL,
        answered_via VARCHAR(20) NOT NULL,
        ip VARCHAR(45) NOT NULL DEFAULT '',
        PRIMARY KEY (id),
        KEY created_at (created_at),
        KEY category (category)
    ) $charset_collate;");

    update_option('mundrive_diego_db_v2', '1');
}
add_action('init', 'mundrive_diego_maybe_create_tables');

/* ============================================================
   PURGE AUTOMATIQUE — conservation limitée dans le temps (RGPD :
   pas de données gardées indéfiniment sans raison)
   ============================================================ */

add_action('init', function () {
    if (!wp_next_scheduled('mundrive_diego_purge_logs')) {
        wp_schedule_event(time(), 'daily', 'mundrive_diego_purge_logs');
    }
});

add_action('mundrive_diego_purge_logs', function () {
    global $wpdb;
    $log_cutoff = gmdate('Y-m-d H:i:s', strtotime('-' . MUNDRIVE_DIEGO_LOG_RETENTION_DAYS . ' days'));
    $wpdb->query($wpdb->prepare(
        'DELETE FROM ' . mundrive_diego_log_table() . ' WHERE created_at < %s', $log_cutoff
    ));
    $quota_cutoff = gmdate('Y-m-d', strtotime('-' . MUNDRIVE_DIEGO_QUOTA_RETENTION_DAYS . ' days'));
    $wpdb->query($wpdb->prepare(
        'DELETE FROM ' . mundrive_diego_quota_table() . ' WHERE day < %s', $quota_cutoff
    ));
});

/* ============================================================
   IDENTITÉ VISITEUR (cookie anonyme httpOnly) + IP (journal uniquement)
   ============================================================ */

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

/* ============================================================
   QUOTAS — vérification + incrément, réutilisée par les deux routes
   ============================================================ */

function mundrive_diego_enforce_quota($cat) {
    global $wpdb;
    $limits  = MUNDRIVE_DIEGO_LIMITS;
    $columns = ['entretien' => 'q_entretien', 'compat' => 'q_compat', 'general' => 'q_general'];

    if (!isset($limits[$cat])) {
        return ['allowed' => true, 'remaining' => null];
    }
    $col   = $columns[$cat];
    $table = mundrive_diego_quota_table();
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
        return ['allowed' => false, 'remaining' => 0];
    }

    $wpdb->query($wpdb->prepare(
        "UPDATE $table SET $col = $col + 1 WHERE uid = %s AND day = %s", $uid, $today
    ));

    return ['allowed' => true, 'remaining' => $limits[$cat] - $used - 1];
}

/* ============================================================
   JOURNAL — chaque échange est enregistré (pour ton tableau de bord)
   ============================================================ */

function mundrive_diego_log($cat, $question, $article, $product, $answered_via) {
    global $wpdb;
    $wpdb->insert(mundrive_diego_log_table(), [
        'created_at'         => current_time('mysql'),
        'uid'                => mundrive_diego_get_uid(),
        'category'           => $cat,
        'question'           => $question,
        'matched_article_id' => $article ? $article->ID : null,
        'matched_product_id' => $product ? $product->get_id() : null,
        'answered_via'       => $answered_via,
        'ip'                 => mundrive_diego_client_ip(),
    ]);
}

/* ============================================================
   CLASSIFICATION — faite côté serveur (jamais confiée au navigateur,
   pour qu'un visiteur ne puisse pas se faire passer pour une autre
   catégorie et contourner les quotas)
   ============================================================ */

function mundrive_diego_quick_intent($t) {
    if (preg_match('/merci|top|parfait|super/iu', $t)) {
        return "Avec plaisir ! Autre chose sur une pièce, un article ou votre abonnement ?";
    }
    if (preg_match('/newsletter|abonn|infolettre/iu', $t)) {
        return "Avec plaisir — un email par mois avec nos conseils d'atelier et les meilleures offres, sans spam. Voulez-vous que je vous inscrive avec votre adresse email ?";
    }
    if (preg_match('/humain|quelqu.un|conseiller|parler [aà]|sav|service client|probl[eè]me|bloqu|command/iu', $t)) {
        return "Je vous mets en relation avec l'équipe MunDrive : écrivez à contact@mundrive.com en décrivant votre situation, vous aurez une réponse sous 24h.";
    }
    return null;
}

function mundrive_diego_bucket($t) {
    if (preg_match('/compat|r[ée]f[ée]rence|ref-|mod[eè]le|convient|va (avec|sur)|prix|stock|combien coute|combien ça coûte/iu', $t)) {
        return 'compat';
    }
    if (preg_match('/entretien|conseil|guide|comment (faire|changer|poser)|plaquette|frein|distribution|courroie|pneu|batterie|huile|vidange|bougie|embrayage|amortisseur|filtre|essuie|hiver|frimas|moteur|occasion|neuf|reconditionn/iu', $t)) {
        return 'maintenance';
    }
    return null;
}

/* ============================================================
   RECHERCHE — articles du blog + produits WooCommerce
   ============================================================ */

function mundrive_diego_find_article($question) {
    $q = new WP_Query([
        's'              => $question,
        'post_type'      => 'post',
        'post_status'    => 'publish',
        'posts_per_page' => 1,
        'no_found_rows'  => true,
    ]);
    $post = null;
    if ($q->have_posts()) {
        $q->the_post();
        $post = get_post();
    }
    wp_reset_postdata();
    return $post;
}

function mundrive_diego_find_product($question) {
    if (!class_exists('WooCommerce')) {
        return null;
    }
    $q = new WP_Query([
        's'              => $question,
        'post_type'      => 'product',
        'post_status'    => 'publish',
        'posts_per_page' => 1,
        'no_found_rows'  => true,
    ]);
    $product = null;
    if ($q->have_posts()) {
        $q->the_post();
        $product = function_exists('wc_get_product') ? wc_get_product(get_the_ID()) : null;
    }
    wp_reset_postdata();
    return $product;
}

function mundrive_diego_build_context($article, $product) {
    $parts = [];
    if ($article) {
        $excerpt = has_excerpt($article)
            ? get_the_excerpt($article)
            : wp_trim_words(wp_strip_all_tags($article->post_content), 40);
        $parts[] = 'ARTICLE MUNDRIVE : "' . $article->post_title . '" — ' . $excerpt;
    }
    if ($product) {
        $parts[] = 'PRODUIT MUNDRIVE : "' . $product->get_name() . '", référence ' . $product->get_sku()
            . ', prix ' . wp_strip_all_tags($product->get_price_html())
            . ', ' . ($product->is_in_stock() ? 'en stock' : 'actuellement en rupture de stock');
    }
    return implode("\n", $parts);
}

/* ============================================================
   RÉPONSES SANS IA — utilisées si la clé Claude n'est pas encore
   configurée, ou si l'appel à l'API échoue (le chat ne tombe jamais
   en panne, il redevient juste "scripté" ponctuellement)
   ============================================================ */

function mundrive_diego_scripted_fallback($cat, $article, $product) {
    if ($article) {
        $excerpt = has_excerpt($article)
            ? get_the_excerpt($article)
            : wp_trim_words(wp_strip_all_tags($article->post_content), 30);
        return 'Bonne question ! Notre atelier a justement écrit un article là-dessus : « ' . $article->post_title . ' » — ' . $excerpt;
    }
    if ($product) {
        return 'Nous avons « ' . $product->get_name() . ' » (réf. ' . $product->get_sku() . '), '
            . wp_strip_all_tags($product->get_price_html()) . ', '
            . ($product->is_in_stock() ? 'actuellement en stock.' : 'actuellement en rupture de stock.');
    }
    if ($cat === 'compat') {
        return "Dites-m'en un peu plus : le modèle de votre véhicule, son année, et la référence ou le nom de la pièce — je regarde tout de suite si elle correspond.";
    }
    if ($cat === 'general') {
        return "Je n'ai pas encore d'article dédié à ce sujet sur le site. Pour une réponse fiable, vérifiez le carnet d'entretien de votre véhicule ou demandez à un professionnel.";
    }
    return "Je peux vérifier une compatibilité de pièce, vous conseiller un article du blog, gérer votre abonnement à la newsletter — ou, si vous êtes bloqué·e, vous mettre en relation à contact@mundrive.com.";
}

function mundrive_diego_useful_links() {
    $blog_page = get_option('page_for_posts');
    $blog = $blog_page ? get_permalink($blog_page) : home_url('/blog');
    $shop = (function_exists('wc_get_page_id') && wc_get_page_id('shop') > 0)
        ? get_permalink(wc_get_page_id('shop'))
        : home_url('/boutique');
    return ['blog' => $blog ?: home_url('/'), 'shop' => $shop ?: home_url('/')];
}

/* ============================================================
   BUDGET IA GLOBAL — quinzaine calendaire (1-15 / 16-fin de mois),
   5000 réponses IA chacune => jamais plus de 10 000/mois au total
   ============================================================ */

function mundrive_diego_period_bounds() {
    $day   = (int) current_time('j');
    $year  = (int) current_time('Y');
    $month = (int) current_time('n');
    if ($day <= 15) {
        $start = sprintf('%04d-%02d-01', $year, $month);
        $end   = sprintf('%04d-%02d-15', $year, $month);
    } else {
        $start = sprintf('%04d-%02d-16', $year, $month);
        $end   = date('Y-m-t', strtotime($start));
    }
    return ['start' => $start, 'end' => $end];
}

function mundrive_diego_global_ai_used() {
    global $wpdb;
    $p = mundrive_diego_period_bounds();
    return (int) $wpdb->get_var($wpdb->prepare(
        "SELECT COUNT(*) FROM " . mundrive_diego_log_table() . " WHERE answered_via = 'ai' AND created_at >= %s AND created_at < %s",
        $p['start'] . ' 00:00:00', date('Y-m-d', strtotime($p['end'] . ' +1 day')) . ' 00:00:00'
    ));
}

function mundrive_diego_global_ai_budget_available() {
    return mundrive_diego_global_ai_used() < MUNDRIVE_DIEGO_GLOBAL_AI_BUDGET;
}

/* ============================================================
   LITIGE / SAV — garantie, remboursement, accident : jamais répondu
   par l'IA (décision commerciale), toujours transmis par email à
   l'équipe MunDrive avec la politique de retour rappelée au visiteur
   ============================================================ */

function mundrive_diego_litige_intent($t) {
    return (bool) preg_match(
        '/garantie|litige|rembours|avarie|cass[ée]e?|d[ée]fectueux|accident|endommag[ée]|retourner (la|ma|une) pi[eè]ce|renvoyer (la|ma|une) pi[eè]ce/iu',
        $t
    );
}

function mundrive_diego_notify_litige($question) {
    $to      = get_option('admin_email');
    $subject = '[Diego] Nouveau signalement SAV / litige';
    $body    = "Un visiteur a signalé un problème via le chatbot Diego :\n\n"
        . "Message : " . $question . "\n"
        . "Date : " . current_time('d/m/Y H:i') . "\n"
        . "IP : " . mundrive_diego_client_ip() . "\n"
        . "Identifiant visiteur (cookie) : " . mundrive_diego_get_uid() . "\n\n"
        . "Rappel de la politique communiquée au visiteur : réponse sous 24h, retour "
        . "accepté sous 14 jours si la pièce revient dans le même état qu'à la réception.";
    wp_mail($to, $subject, $body);
}

function mundrive_diego_quota_blocked_payload($cat) {
    $links = mundrive_diego_useful_links();
    if ($cat === 'entretien') {
        return [
            'text' => "Vous avez atteint la limite de 30 conseils entretien sourcés pour aujourd'hui. En attendant demain, vous pouvez parcourir tous nos articles ici.",
            'link' => ['label' => 'Voir tous les articles', 'url' => $links['blog']],
        ];
    }
    if ($cat === 'compat') {
        return [
            'text' => "Vous avez atteint la limite de 30 questions compatibilité / produits pour aujourd'hui. Chaque fiche produit de la boutique indique sa compatibilité détaillée.",
            'link' => ['label' => 'Voir la boutique', 'url' => $links['shop']],
        ];
    }
    return [
        'text' => "Vous avez atteint la limite de 10 questions générales pour aujourd'hui. Revenez demain, ou écrivez directement à contact@mundrive.com pour une réponse plus rapide.",
        'link' => ['label' => 'Nous écrire', 'url' => 'mailto:contact@mundrive.com'],
    ];
}

/* ============================================================
   APPEL À CLAUDE (raw HTTP via wp_remote_post — pas de Composer
   nécessaire sur l'hébergement WordPress)
   ============================================================ */

function mundrive_diego_key_configured() {
    return defined('MUNDRIVE_ANTHROPIC_KEY') && MUNDRIVE_ANTHROPIC_KEY !== '';
}

function mundrive_diego_call_claude($question, $cat, $context) {
    if (!mundrive_diego_key_configured()) {
        return null;
    }

    $system = "Tu es Diego, l'assistant du site e-commerce français MunDrive (pièces auto neuves et d'occasion). "
        . "Réponds toujours en français, en 2 à 4 phrases maximum, ton chaleureux et professionnel d'un vrai passionné d'automobile. "
        . "Règles strictes, à respecter absolument : "
        . "1) Ne cite, ne recommande et ne mentionne JAMAIS un site, une marque ou un magasin concurrent, sous aucun prétexte. "
        . "2) Reste factuellement exact : si tu n'es pas sûr d'un chiffre, donne une fourchette réaliste et invite à vérifier le carnet d'entretien du véhicule ou à demander à un professionnel — ne présente jamais une supposition comme une certitude. "
        . "3) Si un ARTICLE MUNDRIVE est fourni ci-dessous, base ta réponse dessus et mentionne naturellement que MunDrive a un article sur le sujet (ne donne pas d'URL toi-même, elle est ajoutée automatiquement après ta réponse). "
        . "4) Si un PRODUIT MUNDRIVE est fourni ci-dessous, utilise exactement ces données (nom, référence, prix, stock) sans en inventer d'autres. "
        . "5) Ne te présente jamais comme un humain ; tu es un assistant automatisé."
        . ($context !== ''
            ? "\n\nCONTEXTE DISPONIBLE :\n" . $context
            : "\n\nAucun article ni fiche produit MunDrive ne correspond à cette question : réponds avec des informations générales fiables sur l'automobile.");

    $body = [
        'model'         => MUNDRIVE_DIEGO_MODEL,
        'max_tokens'    => 1024,
        'output_config' => ['effort' => 'low'],
        'system'        => $system,
        'messages'      => [
            ['role' => 'user', 'content' => $question],
        ],
    ];

    $res = wp_remote_post('https://api.anthropic.com/v1/messages', [
        'timeout' => 20,
        'headers' => [
            'x-api-key'         => MUNDRIVE_ANTHROPIC_KEY,
            'anthropic-version' => '2023-06-01',
            'content-type'      => 'application/json',
        ],
        'body' => wp_json_encode($body),
    ]);

    if (is_wp_error($res)) {
        return null;
    }
    $code = wp_remote_retrieve_response_code($res);
    $data = json_decode(wp_remote_retrieve_body($res), true);
    if ($code !== 200 || !is_array($data)) {
        return null;
    }
    if (($data['stop_reason'] ?? '') === 'refusal') {
        return null;
    }
    foreach (($data['content'] ?? []) as $block) {
        if (($block['type'] ?? '') === 'text' && !empty($block['text'])) {
            return trim($block['text']);
        }
    }
    return null;
}

/* ============================================================
   ROUTES REST
   ============================================================ */

add_action('rest_api_init', function () {
    register_rest_route('mundrive/v1', '/diego-quota', [
        'methods'             => 'POST',
        'callback'            => 'mundrive_diego_quota_route',
        'permission_callback' => '__return_true',
    ]);
    register_rest_route('mundrive/v1', '/diego-ask', [
        'methods'             => 'POST',
        'callback'            => 'mundrive_diego_ask_route',
        'permission_callback' => '__return_true',
    ]);
});

// conservée pour compatibilité — /diego-ask fait tout en un seul appel désormais
function mundrive_diego_quota_route(WP_REST_Request $req) {
    $cat = sanitize_key((string) $req->get_param('category'));
    return new WP_REST_Response(mundrive_diego_enforce_quota($cat), 200);
}

function mundrive_diego_ask_route(WP_REST_Request $req) {
    $question = trim(sanitize_text_field((string) $req->get_param('question')));
    if ($question === '') {
        return new WP_REST_Response(['error' => 'empty_question'], 400);
    }
    if (mb_strlen($question) > 600) {
        $question = mb_substr($question, 0, 600);
    }

    // 1) garantie / remboursement / accident : jamais l'IA, toujours transmis par email,
    //    ni IA ni quota (c'est une décision commerciale, pas une question support)
    if (mundrive_diego_litige_intent($question)) {
        mundrive_diego_notify_litige($question);
        mundrive_diego_log(null, $question, null, null, 'litige');
        $reply = "Je suis désolé pour ce désagrément. J'ai transmis votre message à notre équipe, qui vous répondra sous 24h avec une décision. "
            . "Pour rappel, vous disposez de 14 jours pour nous retourner une pièce, à condition qu'elle revienne dans le même état qu'à la réception. "
            . "Pour un suivi encore plus rapide, écrivez aussi à contact@mundrive.com avec votre numéro de commande.";
        return new WP_REST_Response(['allowed' => true, 'answer' => $reply, 'article' => null, 'product' => null, 'link' => null], 200);
    }

    // 2) salutations / newsletter / humain générique : ni IA, ni quota
    $quick = mundrive_diego_quick_intent($question);
    if ($quick !== null) {
        mundrive_diego_log(null, $question, null, null, 'quick');
        return new WP_REST_Response(['allowed' => true, 'answer' => $quick, 'article' => null, 'product' => null, 'link' => null], 200);
    }

    // 3) catégorie brute (décidée par le serveur, jamais par le client)
    $bucket  = mundrive_diego_bucket($question); // 'compat' | 'maintenance' | null
    $article = null;
    $product = null;
    $cat     = null;

    if ($bucket === 'compat') {
        $cat     = 'compat';
        $product = mundrive_diego_find_product($question);
    } elseif ($bucket === 'maintenance') {
        $article = mundrive_diego_find_article($question);
        // sourcé par un article -> compteur "entretien" (30/j) ; sinon -> "general" (10/j)
        $cat = $article ? 'entretien' : 'general';
    }

    if ($cat !== null) {
        $quota = mundrive_diego_enforce_quota($cat);
        if (!$quota['allowed']) {
            $payload = mundrive_diego_quota_blocked_payload($cat);
            mundrive_diego_log($cat, $question, $article, $product, 'quota-blocked');
            return new WP_REST_Response(['allowed' => false, 'answer' => $payload['text'], 'link' => $payload['link'], 'article' => null, 'product' => null], 200);
        }
    }

    $context   = mundrive_diego_build_context($article, $product);
    // le budget IA global protège la facture : au-delà, on répond quand même
    // (articles/produits trouvés automatiquement, sinon message générique),
    // simplement sans appeler Claude jusqu'à la prochaine quinzaine.
    $ai_answer = mundrive_diego_global_ai_budget_available()
        ? mundrive_diego_call_claude($question, $cat, $context)
        : null;

    if ($ai_answer !== null) {
        $answer = $ai_answer;
        $via    = 'ai';
    } else {
        $answer = mundrive_diego_scripted_fallback($cat, $article, $product);
        $via    = $article ? 'article' : ($product ? 'product' : 'fallback');
    }

    mundrive_diego_log($cat, $question, $article, $product, $via);

    return new WP_REST_Response([
        'allowed' => true,
        'answer'  => $answer,
        'article' => $article ? ['title' => $article->post_title, 'url' => get_permalink($article)] : null,
        'product' => $product ? ['name' => $product->get_name(), 'url' => $product->get_permalink()] : null,
        'link'    => null,
    ], 200);
}

/* ============================================================
   TABLEAU DE BORD ADMIN — wp-admin > Diego
   ============================================================ */

add_action('admin_menu', function () {
    add_menu_page(
        'Diego — Tableau de bord',
        'Diego',
        'manage_options',
        'mundrive-diego',
        'mundrive_diego_render_dashboard',
        'dashicons-format-chat',
        58
    );
});

function mundrive_diego_render_chart($daily_rows) {
    $days = [];
    for ($i = 13; $i >= 0; $i--) {
        $days[] = date('Y-m-d', strtotime("-$i days"));
    }
    $map = [];
    foreach ($daily_rows as $row) {
        $map[$row['d']][$row['category']] = (int) $row['n'];
    }

    $cats   = ['entretien', 'compat', 'general'];
    $colors = ['entretien' => '#2a78d6', 'compat' => '#eb6834', 'general' => '#1baf7a'];
    $labels = ['entretien' => 'Conseils entretien', 'compat' => 'Compatibilité / produits', 'general' => 'Questions générales'];

    $max = 1;
    foreach ($days as $d) {
        $sum = 0;
        foreach ($cats as $cat) { $sum += $map[$d][$cat] ?? 0; }
        $max = max($max, $sum);
    }

    $w = 700; $h = 200; $padBottom = 24; $padTop = 10;
    $barGap = 6;
    $count  = count($days);
    $barWidth = ($w - $barGap * ($count - 1)) / $count;
    $chartH = $h - $padBottom - $padTop;

    ob_start();
    ?>
    <svg viewBox="0 0 <?php echo $w; ?> <?php echo $h; ?>" width="100%" style="max-width:760px;height:auto;">
        <line x1="0" y1="<?php echo $h - $padBottom; ?>" x2="<?php echo $w; ?>" y2="<?php echo $h - $padBottom; ?>" stroke="#c3c2b7" stroke-width="1"></line>
        <?php foreach ($days as $i => $d):
            $x = $i * ($barWidth + $barGap);
            $yCursor = $h - $padBottom;
            foreach ($cats as $cat):
                $n = $map[$d][$cat] ?? 0;
                if ($n <= 0) { continue; }
                $segH = ($n / $max) * $chartH;
                $y = $yCursor - $segH;
                ?>
                <rect x="<?php echo round($x, 1); ?>" y="<?php echo round($y, 1); ?>" width="<?php echo round($barWidth, 1); ?>" height="<?php echo round($segH, 1); ?>" fill="<?php echo esc_attr($colors[$cat]); ?>" rx="2">
                    <title><?php echo esc_html(date_i18n('d/m', strtotime($d)) . ' — ' . $labels[$cat] . ' : ' . $n); ?></title>
                </rect>
                <?php
                $yCursor = $y;
            endforeach;
            if ($i % 2 === 0):
            ?>
            <text x="<?php echo round($x + $barWidth / 2, 1); ?>" y="<?php echo $h - 6; ?>" font-size="9" fill="#898781" text-anchor="middle"><?php echo esc_html(date_i18n('d/m', strtotime($d))); ?></text>
            <?php endif; ?>
        <?php endforeach; ?>
    </svg>
    <div style="display:flex;gap:16px;margin-top:8px;font-size:13px;color:#52514e;flex-wrap:wrap;">
        <?php foreach ($labels as $cat => $label): ?>
        <span style="display:inline-flex;align-items:center;gap:6px;"><span style="width:10px;height:10px;border-radius:2px;background:<?php echo esc_attr($colors[$cat]); ?>;display:inline-block;"></span><?php echo esc_html($label); ?></span>
        <?php endforeach; ?>
    </div>
    <?php
    return ob_get_clean();
}

function mundrive_diego_render_dashboard() {
    if (!current_user_can('manage_options')) {
        return;
    }
    global $wpdb;
    $log   = mundrive_diego_log_table();
    $quota = mundrive_diego_quota_table();

    $today       = current_time('Y-m-d');
    $week_start  = date('Y-m-d', strtotime('-6 days', strtotime($today)));
    $month_start = date('Y-m-01', strtotime($today));

    $today_counts = $wpdb->get_row($wpdb->prepare(
        "SELECT SUM(q_entretien) e, SUM(q_compat) c, SUM(q_general) g FROM $quota WHERE day = %s", $today
    ), ARRAY_A);
    $today_total = (int) ($today_counts['e'] ?? 0) + (int) ($today_counts['c'] ?? 0) + (int) ($today_counts['g'] ?? 0);

    $week_total  = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $log WHERE created_at >= %s", $week_start . ' 00:00:00'));
    $month_total = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $log WHERE created_at >= %s", $month_start . ' 00:00:00'));
    $blocked_today = (int) $wpdb->get_var($wpdb->prepare(
        "SELECT COUNT(*) FROM $log WHERE created_at >= %s AND answered_via = 'quota-blocked'", $today . ' 00:00:00'
    ));

    $daily = $wpdb->get_results($wpdb->prepare(
        "SELECT DATE(created_at) d, category, COUNT(*) n FROM $log WHERE created_at >= %s AND category IS NOT NULL GROUP BY d, category ORDER BY d ASC",
        date('Y-m-d', strtotime('-13 days', strtotime($today))) . ' 00:00:00'
    ), ARRAY_A);

    $unanswered = $wpdb->get_results(
        "SELECT question, COUNT(*) n FROM $log WHERE answered_via IN ('fallback') OR (answered_via = 'ai' AND matched_article_id IS NULL AND matched_product_id IS NULL)
         GROUP BY question ORDER BY n DESC LIMIT 30",
        ARRAY_A
    );

    $recent = $wpdb->get_results("SELECT * FROM $log ORDER BY created_at DESC LIMIT 50", ARRAY_A);

    $key_ok = mundrive_diego_key_configured();
    $period = mundrive_diego_period_bounds();
    $ai_used = mundrive_diego_global_ai_used();
    ?>
    <div class="wrap">
        <h1>Diego — Tableau de bord</h1>

        <?php if (!$key_ok): ?>
        <div class="notice notice-warning"><p>
            <strong>Clé Claude non configurée.</strong> Diego répond pour l'instant avec des réponses toutes faites
            (articles/produits trouvés automatiquement, sinon un message générique). Ajoute
            <code>define('MUNDRIVE_ANTHROPIC_KEY', 'sk-ant-...');</code> dans <code>wp-config.php</code> pour activer
            les vraies réponses IA.
        </p></div>
        <?php endif; ?>

        <div style="display:flex;gap:16px;flex-wrap:wrap;margin:20px 0;">
            <div style="background:#fff;border:1px solid #e1e0d9;border-radius:8px;padding:16px 20px;min-width:160px;">
                <div style="font-size:12px;color:#898781;text-transform:uppercase;letter-spacing:.04em;">Aujourd'hui</div>
                <div style="font-size:28px;font-weight:700;color:#0b0b0b;"><?php echo (int) $today_total; ?></div>
                <div style="font-size:12px;color:#52514e;">
                    entretien <?php echo (int) ($today_counts['e'] ?? 0); ?>/30 ·
                    compat <?php echo (int) ($today_counts['c'] ?? 0); ?>/30 ·
                    général <?php echo (int) ($today_counts['g'] ?? 0); ?>/10
                </div>
            </div>
            <div style="background:#fff;border:1px solid #e1e0d9;border-radius:8px;padding:16px 20px;min-width:160px;">
                <div style="font-size:12px;color:#898781;text-transform:uppercase;letter-spacing:.04em;">7 derniers jours</div>
                <div style="font-size:28px;font-weight:700;color:#0b0b0b;"><?php echo (int) $week_total; ?></div>
            </div>
            <div style="background:#fff;border:1px solid #e1e0d9;border-radius:8px;padding:16px 20px;min-width:160px;">
                <div style="font-size:12px;color:#898781;text-transform:uppercase;letter-spacing:.04em;">Ce mois-ci</div>
                <div style="font-size:28px;font-weight:700;color:#0b0b0b;"><?php echo (int) $month_total; ?></div>
            </div>
            <div style="background:#fff;border:1px solid #e1e0d9;border-radius:8px;padding:16px 20px;min-width:160px;">
                <div style="font-size:12px;color:#898781;text-transform:uppercase;letter-spacing:.04em;">Quotas atteints aujourd'hui</div>
                <div style="font-size:28px;font-weight:700;color:#0b0b0b;"><?php echo (int) $blocked_today; ?></div>
            </div>
            <div style="background:#fff;border:1px solid #e1e0d9;border-radius:8px;padding:16px 20px;min-width:220px;">
                <div style="font-size:12px;color:#898781;text-transform:uppercase;letter-spacing:.04em;">
                    Budget IA — quinzaine du <?php echo esc_html(date_i18n('d/m', strtotime($period['start']))); ?> au <?php echo esc_html(date_i18n('d/m', strtotime($period['end']))); ?>
                </div>
                <div style="font-size:28px;font-weight:700;color:<?php echo $ai_used >= MUNDRIVE_DIEGO_GLOBAL_AI_BUDGET ? '#d03b3b' : '#0b0b0b'; ?>;">
                    <?php echo (int) $ai_used; ?> / <?php echo (int) MUNDRIVE_DIEGO_GLOBAL_AI_BUDGET; ?>
                </div>
                <?php if ($ai_used >= MUNDRIVE_DIEGO_GLOBAL_AI_BUDGET): ?>
                <div style="font-size:12px;color:#d03b3b;">Budget épuisé — Diego répond en mode scripté jusqu'à la prochaine quinzaine.</div>
                <?php endif; ?>
            </div>
        </div>

        <div style="background:#fff;border:1px solid #e1e0d9;border-radius:8px;padding:20px;margin-bottom:24px;">
            <h2 style="margin-top:0;">Volume par jour (14 derniers jours)</h2>
            <?php echo mundrive_diego_render_chart($daily); ?>
        </div>

        <div style="background:#fff;border:1px solid #e1e0d9;border-radius:8px;padding:20px;margin-bottom:24px;">
            <h2 style="margin-top:0;">Questions sans bonne réponse (à écrire en priorité)</h2>
            <p style="color:#52514e;">Ces questions n'ont trouvé ni article ni fiche produit correspondant — elles indiquent les sujets d'articles les plus demandés.</p>
            <?php if (empty($unanswered)): ?>
                <p><em>Aucune pour l'instant.</em></p>
            <?php else: ?>
            <table class="widefat striped">
                <thead><tr><th>Question</th><th style="width:100px;">Occurrences</th></tr></thead>
                <tbody>
                <?php foreach ($unanswered as $row): ?>
                    <tr><td><?php echo esc_html($row['question']); ?></td><td><?php echo (int) $row['n']; ?></td></tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            <?php endif; ?>
        </div>

        <div style="background:#fff;border:1px solid #e1e0d9;border-radius:8px;padding:20px;">
            <h2 style="margin-top:0;">Conversations récentes</h2>
            <table class="widefat striped">
                <thead><tr><th style="width:140px;">Date</th><th style="width:110px;">Catégorie</th><th>Question</th><th style="width:110px;">Répondu via</th></tr></thead>
                <tbody>
                <?php foreach ($recent as $row): ?>
                    <tr>
                        <td><?php echo esc_html(mysql2date('d/m/Y H:i', $row['created_at'])); ?></td>
                        <td><?php echo esc_html($row['category'] ?: '—'); ?></td>
                        <td><?php echo esc_html($row['question']); ?></td>
                        <td><?php echo esc_html($row['answered_via']); ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
    <?php
}
