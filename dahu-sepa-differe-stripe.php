<?php
/**
 * Plugin Name: Dahu - Sepa Differe Stripe
 * Plugin URI:  https://github.com/Epilouptique/dahu-sepa-differe-stripe
 * Description: Déclenche automatiquement le prélèvement SEPA Stripe un nombre de jours configurable après le passage d'une commande en "Terminé", en réutilisant le mandat déjà enregistré du client. La date de déclenchement est modifiable depuis la fiche commande.
 * Version:     1.7.2
 * Author:      Hugo Vial-Jaime
 * Author URI:  mailto:hugo@vialjaime.fr
 * License:     GPL v2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: dahu-sepa-differe-stripe
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * WC requires at least: 7.0
 * Requires Plugins: woocommerce
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/* ============================================================
 * CONFIGURATION
 * ============================================================ */

// Délai en jours entre le statut "Terminé" et la confirmation Stripe.
// ⚠️ Simple valeur de REPLI : le délai réellement appliqué est résolu par
// annad_sepa_get_delay_days() — délai personnalisé du client (fiche utilisateur)
// > réglage global de la passerelle (Réglages → Paiements) > cette constante.
define( 'ANNAD_SEPA_DELAY_DAYS', 8 );

// Meta utilisateur : délai de prélèvement personnalisé (en jours) pour ce client.
// Vide = utiliser le réglage global de la passerelle.
define( 'ANNAD_SEPA_DELAY_META', '_annad_sepa_delay_days' );

// Meta utilisateur : ID du token SEPA "par défaut" déjà connu de ce client.
// C'est le passage d'un IBAN en "par défaut" (automatique pour le premier ajouté,
// via le bouton « Utiliser par défaut » ensuite) qui vaut SOUMISSION à validation
// et déclenche les emails — pas le simple ajout d'un IBAN supplémentaire.
// Détection par comparaison à chaque passage sur "Mes moyens de paiement" (où
// WooCommerce redirige après ces actions), sans dépendre d'un nom de hook.
define( 'ANNAD_SEPA_KNOWN_DEFAULT_META', '_annad_sepa_known_default_token' );

/**
 * Délai de prélèvement applicable (en jours), par ordre de priorité :
 *   1. délai personnalisé du client (fiche utilisateur, ex. J+30 exigé par certains pros) ;
 *   2. réglage global de la passerelle (Réglages → Paiements → Prélèvement SEPA différé) ;
 *   3. constante ANNAD_SEPA_DELAY_DAYS en dernier recours.
 */
function annad_sepa_get_delay_days( $user_id = 0 ) {
	if ( $user_id ) {
		$custom = get_user_meta( $user_id, ANNAD_SEPA_DELAY_META, true );
		if ( '' !== $custom && (int) $custom > 0 ) {
			return (int) $custom;
		}
	}

	$settings = get_option( 'woocommerce_' . ANNAD_SEPA_GATEWAY_ID . '_settings', array() );
	$global   = is_array( $settings ) && isset( $settings['delay_days'] ) ? (int) $settings['delay_days'] : 0;
	if ( $global > 0 ) {
		return $global;
	}

	return ANNAD_SEPA_DELAY_DAYS;
}

// ID de notre passerelle de paiement dédiée « SEPA différé ».
define( 'ANNAD_SEPA_GATEWAY_ID', 'annad_sepa_deferred' );

// IDs de gateway reconnus par l'extension : notre passerelle dédiée + les IDs SEPA
// du plugin officiel (conservés pour compatibilité / anciennes commandes).
define( 'ANNAD_SEPA_GATEWAYS', array( ANNAD_SEPA_GATEWAY_ID, 'stripe_sepa', 'stripe_sepa_debit' ) );

// Hook de l'action planifiée.
define( 'ANNAD_SEPA_HOOK', 'annad_sepa_confirm_payment' );

// Rôle(s) WordPress considérés comme "particulier" (compte simple), à qui le SEPA
// différé ne doit JAMAIS être proposé, même si autorisé par erreur.
// ⚠️ À vérifier sur le site : Utilisateurs → Profil d'un compte "Client" → rôle
// affiché. Le slug technique par défaut de WooCommerce est "customer" (affiché
// "Client" en français) ; à ajuster ici si le rôle réel porte un autre identifiant.
define( 'ANNAD_SEPA_PARTICULIER_ROLES', array( 'customer' ) );

// Meta utilisateur portant l'autorisation commerciale (indépendante du mandat
// Stripe, qui lui est déjà valide légalement dès sa signature électronique).
define( 'ANNAD_SEPA_AUTHORIZED_META', '_annad_sepa_authorized' );

// Meta utilisateur : ID du token SEPA (WooCommerce) choisi comme mandat actif.
// Le client peut avoir plusieurs IBAN enregistrés chez Stripe ; un seul est
// "actif" pour le prélèvement différé — choisi explicitement par un admin Annad.
define( 'ANNAD_SEPA_ACTIVE_TOKEN_META', '_annad_sepa_active_token_id' );

// Adresse notifiée pour tout événement SEPA côté client : ajout d'un nouvel IBAN
// (validation requise) et remplacement de mandat. Laisser vide pour utiliser
// l'email d'administration du site.
// Surchargeable depuis wp-config.php :
//   define( 'ANNAD_SEPA_NOTIFY_EMAIL', 'administration@exemple.fr' );
if ( ! defined( 'ANNAD_SEPA_NOTIFY_EMAIL' ) ) {
	define( 'ANNAD_SEPA_NOTIFY_EMAIL', '' );
}

// Adresse de contact affichée au client sur "Mon compte" pour toute demande de
// changement de mandat SEPA. Laisser vide pour utiliser l'email d'administration.
if ( ! defined( 'ANNAD_SEPA_CONTACT_EMAIL' ) ) {
	define( 'ANNAD_SEPA_CONTACT_EMAIL', '' );
}

// Mode diagnostic : encarts de diagnostic sur la fiche commande et au checkout,
// dont le bouton de déclenchement manuel du prélèvement. Doit rester à false en
// production ; activable ponctuellement depuis wp-config.php :
//   define( 'ANNAD_SEPA_DEBUG', true );
if ( ! defined( 'ANNAD_SEPA_DEBUG' ) ) {
	define( 'ANNAD_SEPA_DEBUG', false );
}

// ⚠️ Interception du checkout SEPA (voir §5.2 de la spec).
// Empêche le plugin Stripe officiel de CONFIRMER (donc débiter) le PaymentIntent
// dès le checkout, afin que le prélèvement ne parte qu'à J+8.
//
// LAISSER À false TANT QUE CE N'EST PAS VALIDÉ EN MODE TEST :
//   1. activer la constante (true),
//   2. passer une commande SEPA test (IBAN AT611904300234573201),
//   3. vérifier dans le dashboard Stripe que le PaymentIntent reste en
//      "requires_confirmation" (et NON "processing") juste après le checkout,
//   4. vérifier que le payment method est bien attaché au customer.
// Si le PI part quand même en processing, c'est que le filtre ci-dessous ne
// correspond pas à la version du plugin installé : voir annad_sepa_defer_checkout().
define( 'ANNAD_SEPA_DEFER_AT_CHECKOUT', false );

// Secret de signature du webhook Stripe DÉDIÉ à ce plugin (whsec_...).
// À créer dans Stripe → Developers → Webhooks → « Add endpoint » pointant vers :
//   https://votre-site.fr/wp-json/annad-sepa/v1/webhook
// puis copier le « Signing secret ».
//
// ⚠️ NE JAMAIS écrire le secret ici : ce fichier est versionné sur GitHub.
// Le déclarer dans wp-config.php, AVANT « That's all, stop editing! » :
//   define( 'ANNAD_SEPA_WEBHOOK_SECRET', 'whsec_...' );
//
// ⚠️ Le secret du mode test et celui du mode live sont DIFFÉRENTS : au passage en
// production, créer une destination webhook en mode live et reporter son secret.
// Tant que la constante est vide, le webhook est inactif.
if ( ! defined( 'ANNAD_SEPA_WEBHOOK_SECRET' ) ) {
	define( 'ANNAD_SEPA_WEBHOOK_SECRET', '' );
}


/* ============================================================
 * PAGE EXTENSIONS WORDPRESS — LIENS D'ACTION & MÉTA
 * ============================================================ */

add_filter( 'plugin_action_links_' . plugin_basename( __FILE__ ), function ( $links ) {
	$settings = '<a href="' . admin_url( 'admin.php?page=wc-settings&tab=checkout&section=' . ANNAD_SEPA_GATEWAY_ID ) . '">'
		. __( 'Réglages', 'dahu-sepa-differe-stripe' ) . '</a>';
	array_unshift( $links, $settings );
	return $links;
} );

add_filter( 'plugin_row_meta', function ( $links, $file ) {
	if ( plugin_basename( __FILE__ ) === $file ) {
		$links = array(
			'Par Hugo Vial-Jaime — Dahu-Concept',
			'<a href="https://github.com/Epilouptique" target="_blank">Aller sur le site de l\'extension</a>',
			'<a href="https://github.com/Epilouptique/dahu-sepa-differe-stripe" target="_blank">Documentation</a>',
		);
	}
	return $links;
}, 10, 2 );

/* ============================================================
 * COMPATIBILITÉ HPOS
 * ============================================================ */

add_action( 'before_woocommerce_init', function () {
	if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
		\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility(
			'custom_order_tables',
			__FILE__,
			true
		);
	}
} );

/* ============================================================
 * 1. PLANIFICATION AU PASSAGE EN "TERMINÉ"
 * ============================================================ */

add_action( 'woocommerce_order_status_completed', 'annad_sepa_schedule_on_completed', 10, 2 );

function annad_sepa_schedule_on_completed( $order_id, $order = null ) {
	if ( ! $order instanceof WC_Order ) {
		$order = wc_get_order( $order_id );
	}
	if ( ! $order ) {
		return;
	}

	// Uniquement les commandes SEPA Stripe — tout le reste est ignoré silencieusement.
	if ( ! in_array( $order->get_payment_method(), ANNAD_SEPA_GATEWAYS, true ) ) {
		return;
	}

	// Anti-doublon : déjà confirmé ou déjà planifié.
	if ( 'yes' === $order->get_meta( '_annad_sepa_done' ) ) {
		return;
	}
	if ( $order->get_meta( '_annad_sepa_scheduled_ts' ) ) {
		return;
	}

	// Déjà payée (ex. webhook Stripe a déjà tout réglé) → rien à faire.
	// Exception : notre passerelle dédiée met la commande en « Terminé » sans qu'aucun
	// prélèvement n'ait encore eu lieu ; is_paid() y serait trompeur (statut = payé).
	if ( ANNAD_SEPA_GATEWAY_ID !== $order->get_payment_method() && $order->is_paid() ) {
		$order->add_order_note( 'SEPA différé : commande déjà payée, aucune planification.' );
		return;
	}

	$delay_days = annad_sepa_get_delay_days( $order->get_user_id() );
	$timestamp  = time() + ( $delay_days * DAY_IN_SECONDS );

	annad_sepa_schedule_at( $order, $timestamp );

	$order->add_order_note( sprintf(
		'SEPA différé : prélèvement planifié le %s (J+%d).',
		annad_sepa_format_date( $timestamp ),
		$delay_days
	) );
}

/**
 * Planifie (ou replanifie) l'action de confirmation à un timestamp donné.
 * Utilise Action Scheduler si dispo (recommandé, embarqué dans WooCommerce),
 * sinon WP-Cron.
 */
function annad_sepa_schedule_at( WC_Order $order, $timestamp ) {
	$order_id = $order->get_id();

	// Annule toute planification existante.
	annad_sepa_unschedule( $order_id );

	if ( function_exists( 'as_schedule_single_action' ) ) {
		as_schedule_single_action( $timestamp, ANNAD_SEPA_HOOK, array( 'order_id' => $order_id ), 'annad-sepa' );
	} else {
		wp_schedule_single_event( $timestamp, ANNAD_SEPA_HOOK, array( $order_id ) );
	}

	$order->update_meta_data( '_annad_sepa_scheduled_ts', $timestamp );
	$order->save();
}

function annad_sepa_unschedule( $order_id ) {
	if ( function_exists( 'as_unschedule_all_actions' ) ) {
		as_unschedule_all_actions( ANNAD_SEPA_HOOK, array( 'order_id' => $order_id ), 'annad-sepa' );
	}
	wp_clear_scheduled_hook( ANNAD_SEPA_HOOK, array( $order_id ) );
}

/* ============================================================
 * 2. EXÉCUTION DE LA CONFIRMATION (Action Scheduler + WP-Cron)
 * ============================================================ */

// Action Scheduler passe les args nommés ; WP-Cron passe l'arg positionnel.
add_action( ANNAD_SEPA_HOOK, 'annad_sepa_run_scheduled', 10, 1 );

function annad_sepa_run_scheduled( $order_id ) {
	$order_id = absint( $order_id );
	if ( ! $order_id ) {
		return;
	}
	annad_sepa_do_confirm( $order_id );
}

/**
 * Cœur du plugin : confirme le prélèvement SEPA côté Stripe.
 *
 * Stratégie B (préférée) : créer + confirmer un PaymentIntent off-session
 * avec le customer et le payment method SEPA sauvegardés.
 * Fallback A : si un PaymentIntent existant est en requires_confirmation,
 * on le confirme directement.
 */
function annad_sepa_do_confirm( $order_id ) {
	$order = wc_get_order( $order_id );
	if ( ! $order ) {
		return;
	}

	// Garde-fous.
	if ( 'yes' === $order->get_meta( '_annad_sepa_done' ) ) {
		return;
	}
	if ( ! in_array( $order->get_payment_method(), ANNAD_SEPA_GATEWAYS, true ) ) {
		return;
	}
	// Idem : ne pas se fier à is_paid() pour notre passerelle (statut « Terminé » = payé
	// côté WooCommerce alors qu'aucun prélèvement n'a encore été déclenché).
	if ( ANNAD_SEPA_GATEWAY_ID !== $order->get_payment_method() && $order->is_paid() ) {
		$order->add_order_note( 'SEPA différé : commande déjà payée au moment du déclenchement. Aucune action.' );
		annad_sepa_mark_done( $order );
		return;
	}

	$secret_key = annad_sepa_get_secret_key();
	if ( ! $secret_key ) {
		$order->add_order_note( '❌ SEPA différé : clé secrète Stripe introuvable. Prélèvement NON déclenché — intervention manuelle requise.' );
		return;
	}

	// Clé d'idempotence stable par commande : garantit qu'un rejeu de la tâche
	// (timeout réseau, relance Action Scheduler) ne crée jamais un second prélèvement.
	$idem = annad_sepa_get_idempotency_key( $order );

	/* ---- Fallback A : PaymentIntent existant confirmable ? ---- */
	$intent_id = $order->get_meta( '_stripe_intent_id' );
	if ( $intent_id ) {
		$intent = annad_sepa_stripe_request( 'GET', 'payment_intents/' . $intent_id, array(), $secret_key );

		if ( ! is_wp_error( $intent ) && isset( $intent['status'] ) ) {
			if ( 'requires_confirmation' === $intent['status'] ) {
				$confirmed = annad_sepa_stripe_request( 'POST', 'payment_intents/' . $intent_id . '/confirm', array(), $secret_key, $idem . '_confirm' );
				annad_sepa_handle_result( $order, $confirmed, 'confirmation du PaymentIntent existant' );
				return;
			}
			if ( in_array( $intent['status'], array( 'processing', 'succeeded' ), true ) ) {
				$order->add_order_note( 'SEPA différé : le PaymentIntent existant est déjà en cours (' . esc_html( $intent['status'] ) . '). Aucune action.' );
				annad_sepa_mark_done( $order );
				return;
			}
			// Autres statuts (canceled, requires_payment_method…) → on bascule en Stratégie B.
		}
	}

	/* ---- Stratégie B : nouveau PaymentIntent off-session ---- */
	$customer_id       = $order->get_meta( '_stripe_customer_id' );
	$payment_method_id = $order->get_meta( '_stripe_source_id' );

	if ( ! $customer_id || ! $payment_method_id ) {
		$order->add_order_note(
			'❌ SEPA différé : customer ou payment method Stripe introuvable sur la commande. ' .
			'Prélèvement NON déclenché — déclenchez-le manuellement depuis le dashboard Stripe.'
		);
		return;
	}

	$body = array(
		'amount'               => annad_sepa_amount_in_cents( $order ),
		'currency'             => strtolower( $order->get_currency() ),
		'customer'             => $customer_id,
		'payment_method'       => $payment_method_id,
		'payment_method_types' => array( 'sepa_debit' ),
		'off_session'          => 'true',
		'confirm'              => 'true',
		'description'          => sprintf( 'Commande #%s — %s', $order->get_order_number(), get_bloginfo( 'name' ) ),
		'metadata'             => array(
			'order_id'     => (string) $order->get_id(),
			'order_number' => (string) $order->get_order_number(),
			'site_url'     => home_url(),
			'source'       => 'annad-sepa-differe',
		),
	);

	$result = annad_sepa_stripe_request( 'POST', 'payment_intents', $body, $secret_key, $idem );
	annad_sepa_handle_result( $order, $result, 'création du PaymentIntent off-session' );
}

/**
 * Traite la réponse Stripe (commune aux deux stratégies).
 */
function annad_sepa_handle_result( WC_Order $order, $result, $context ) {
	if ( is_wp_error( $result ) ) {
		$order->add_order_note( '❌ SEPA différé (' . $context . ') : erreur réseau — ' . $result->get_error_message() );
		return;
	}

	if ( isset( $result['error'] ) ) {
		// Cas particulier off-session : Stripe renvoie parfois une "erreur" alors que
		// le PaymentIntent est en réalité parti (processing/succeeded). On ne considère
		// un échec que si le PI joint n'est pas dans un statut d'encaissement.
		$err_intent = $result['error']['payment_intent'] ?? null;
		if ( is_array( $err_intent ) && in_array( $err_intent['status'] ?? '', array( 'processing', 'succeeded' ), true ) ) {
			$result = $err_intent; // On retombe sur le traitement de succès ci-dessous.
		} else {
			$order->add_order_note(
				'❌ SEPA différé (' . $context . ') : erreur Stripe — ' .
				esc_html( $result['error']['message'] ?? 'inconnue' )
			);
			return;
		}
	}

	$status = $result['status'] ?? '';

	if ( in_array( $status, array( 'processing', 'succeeded' ), true ) ) {
		// Stocke le nouvel intent pour que les webhooks Stripe retrouvent la commande.
		if ( ! empty( $result['id'] ) ) {
			$order->update_meta_data( '_stripe_intent_id', sanitize_text_field( $result['id'] ) );
		}
		annad_sepa_mark_done( $order );
		$order->add_order_note(
			'✅ SEPA différé : prélèvement déclenché (' . $context . '). Statut Stripe : ' . esc_html( $status ) . '. ' .
			'Débit effectif attendu sous 2-3 jours ouvrés.'
		);

		if ( 'succeeded' === $status ) {
			$order->payment_complete( $result['id'] ?? '' );
		}
		return;
	}

	$order->add_order_note(
		'⚠️ SEPA différé (' . $context . ') : statut Stripe inattendu « ' . esc_html( $status ) . ' ». ' .
		'Vérifiez le dashboard Stripe. Réponse : ' . wp_json_encode( $result )
	);
}

function annad_sepa_mark_done( WC_Order $order ) {
	$order->update_meta_data( '_annad_sepa_done', 'yes' );
	$order->delete_meta_data( '_annad_sepa_scheduled_ts' );
	$order->save();
	annad_sepa_unschedule( $order->get_id() );
}

/* ============================================================
 * 3. METABOX ADMIN — VOIR / REPOUSSER LA DATE
 * ============================================================ */

add_action( 'add_meta_boxes', 'annad_sepa_register_metabox' );

function annad_sepa_register_metabox() {
	// Écran HPOS + écran legacy.
	$screens = array( 'woocommerce_page_wc-orders', 'shop_order' );

	foreach ( $screens as $screen ) {
		add_meta_box(
			'annad_sepa_metabox',
			'Prélèvement SEPA différé',
			'annad_sepa_render_metabox',
			$screen,
			'side',
			'high'
		);
	}
}

function annad_sepa_render_metabox( $post_or_order ) {
	$order = ( $post_or_order instanceof WC_Order )
		? $post_or_order
		: wc_get_order( $post_or_order->ID );

	if ( ! $order ) {
		echo '<p>Commande introuvable.</p>';
		return;
	}

	if ( ! in_array( $order->get_payment_method(), ANNAD_SEPA_GATEWAYS, true ) ) {
		echo '<p>Commande non payée par SEPA Stripe — rien à gérer ici.</p>';
		return;
	}

	// Une fois le prélèvement déclenché, on masque l'interface de planification
	// mais on laisse le diagnostic visible (utile pour retrouver le pi_ notamment).
	if ( 'yes' === $order->get_meta( '_annad_sepa_done' ) ) {
		echo '<p>✅ Prélèvement déjà déclenché.</p>';
	} else {

	$scheduled_ts = (int) $order->get_meta( '_annad_sepa_scheduled_ts' );

	wp_nonce_field( 'annad_sepa_reschedule', 'annad_sepa_nonce' );

	if ( $scheduled_ts ) {
		echo '<p><strong>Prélèvement planifié le :</strong><br>' . esc_html( annad_sepa_format_date( $scheduled_ts ) ) . '</p>';
	} else {
		echo '<p>Aucune planification (la commande n\'est pas encore passée en « Terminé »).</p>';
	}

	// Champ date calendaire pour repousser.
	$default_value = $scheduled_ts ? wp_date( 'Y-m-d', $scheduled_ts ) : '';
	echo '<p><label for="annad_sepa_new_date"><strong>Nouvelle date de prélèvement :</strong></label><br>';
	echo '<input type="date" id="annad_sepa_new_date" name="annad_sepa_new_date" value="' . esc_attr( $default_value ) . '" min="' . esc_attr( wp_date( 'Y-m-d', time() + DAY_IN_SECONDS ) ) . '"></p>';
	echo '<p class="description">Le prélèvement sera déclenché à 09h00 (heure de Paris) à la date choisie. Compter ensuite 2-3 jours ouvrés de délai bancaire.</p>';
	echo '<p class="description">Laisser vide pour ne rien changer. Enregistrez la commande pour appliquer.</p>';

	} // Fin de l'interface de planification (commandes non encore prélevées).

	// --- Déclenchement manuel immédiat ---
	// Volontairement disponible en permanence (et non sous ANNAD_SEPA_DEBUG) : sert
	// aussi en exploitation courante pour prélever sans attendre Action Scheduler.
	// L'action elle-même reste protégée par capacité + nonce (annad_sepa_handle_run_now).
	if ( 'yes' !== $order->get_meta( '_annad_sepa_done' ) && current_user_can( 'edit_shop_orders' ) ) {
		$run_url = wp_nonce_url(
			admin_url( 'admin-post.php?action=annad_sepa_run_now&order_id=' . $order->get_id() ),
			'annad_sepa_run_now_' . $order->get_id()
		);
		echo '<hr><p><a href="' . esc_url( $run_url ) . '" class="button button-secondary" '
			. 'onclick="return confirm(\'Déclencher le prélèvement SEPA maintenant ? Cette action débite réellement le client.\');">'
			. '▶ Déclencher le prélèvement maintenant</a></p>';
		echo '<p class="description">Lance immédiatement la confirmation Stripe, sans attendre la date planifiée '
			. '(équivaut au « Run » d\'Action Scheduler). La tâche planifiée reste en place mais ne fera rien : '
			. 'la commande est marquée comme prélevée, et la clé d\'idempotence empêche tout second débit.</p>';
	}

	// --- Diagnostic (ANNAD_SEPA_DEBUG) : meta Stripe utiles au réglage. ---
	if ( defined( 'ANNAD_SEPA_DEBUG' ) && ANNAD_SEPA_DEBUG ) {
		echo '<hr><p><strong>🔧 Diagnostic du Dahu 🐐 </br> Promis je le desactiverai ensuite</strong></p>';
		echo '<p style="font-size:11px;line-height:1.5">';
		echo 'payment_method : <code>' . esc_html( $order->get_payment_method() ) . '</code><br>';
		foreach ( array( '_stripe_intent_id', '_stripe_customer_id', '_stripe_source_id', '_stripe_charge_id', '_stripe_upe_payment_type' ) as $key ) {
			$val = $order->get_meta( $key );
			echo esc_html( $key ) . ' : <code>' . ( $val ? esc_html( $val ) : '—' ) . '</code><br>';
		}
		echo '</p>';

		// (Le bouton de déclenchement manuel est désormais affiché en permanence,
		// au-dessus de ce bloc de diagnostic.)

		// Résultat de la sonde d'interception (dernier filtre déclenché, tous clients confondus).
		$probe = get_option( 'annad_sepa_probe' );
		echo '<p style="font-size:11px;line-height:1.5"><strong>Sonde interception</strong> : ';
		if ( is_array( $probe ) ) {
			echo 'dernier hook <code>' . esc_html( $probe['hook'] ) . '</code>, ';
			echo 'order=<code>' . esc_html( $probe['order_type'] ) . '</code>, ';
			echo 'pm=<code>' . esc_html( $probe['payment_meth'] ?: '—' ) . '</code>, ';
			echo 'SEPA détecté=<code>' . esc_html( $probe['is_sepa'] ) . '</code>, ';
			echo 'il y a ' . esc_html( human_time_diff( (int) $probe['time'] ) );
		} else {
			echo '<code>aucun filtre déclenché</code> (le hook n\'existe pas dans cette version → interception UPE impossible par ce biais)';
		}
		echo '</p>';

		// Sonde de diagnostic pour la dédup des mandats SEPA (ajout de moyen de paiement).
		$token_probe = get_option( 'annad_sepa_token_probe' );
		echo '<p style="font-size:11px;line-height:1.5"><strong>Sonde ajout token SEPA</strong> : ';
		if ( is_array( $token_probe ) ) {
			echo 'dernier hook <code>' . esc_html( $token_probe['hook'] ) . '</code>, ';
			echo 'token=<code>' . esc_html( $token_probe['token_id'] ) . '</code>, ';
			echo 'trouvé=<code>' . esc_html( $token_probe['found'] ) . '</code>, ';
			echo 'type=<code>' . esc_html( $token_probe['type'] ?: '—' ) . '</code>, ';
			echo 'gateway=<code>' . esc_html( $token_probe['gateway'] ?: '—' ) . '</code>, ';
			if ( null !== $token_probe['sepa_count'] ) {
				echo 'tokens SEPA trouvés=<code>' . esc_html( $token_probe['sepa_count'] ) . '</code>, ';
			}
			echo 'il y a ' . esc_html( human_time_diff( (int) $token_probe['time'] ) );
		} else {
			echo '<code>aucun hook d\'ajout de moyen de paiement ne s\'est déclenché</code> (ni woocommerce_payment_token_added, ni woocommerce_add_payment_method_success)';
		}
		echo '</p>';
	}
}

/**
 * Sauvegarde de la nouvelle date — deux hooks pour couvrir HPOS et legacy.
 */
add_action( 'woocommerce_process_shop_order_meta', 'annad_sepa_save_metabox', 20, 1 );

function annad_sepa_save_metabox( $order_id ) {
	if ( ! isset( $_POST['annad_sepa_nonce'] ) || ! wp_verify_nonce( sanitize_key( $_POST['annad_sepa_nonce'] ), 'annad_sepa_reschedule' ) ) {
		return;
	}
	if ( ! current_user_can( 'edit_shop_orders' ) ) {
		return;
	}
	if ( empty( $_POST['annad_sepa_new_date'] ) ) {
		return;
	}

	$order = wc_get_order( $order_id );
	if ( ! $order || ! in_array( $order->get_payment_method(), ANNAD_SEPA_GATEWAYS, true ) ) {
		return;
	}
	if ( 'yes' === $order->get_meta( '_annad_sepa_done' ) ) {
		return;
	}

	$date_str = sanitize_text_field( wp_unslash( $_POST['annad_sepa_new_date'] ) );

	// Interprète la date à 09h00 heure de Paris, convertie en timestamp UTC.
	$tz  = new DateTimeZone( 'Europe/Paris' );
	$dt  = DateTime::createFromFormat( 'Y-m-d H:i', $date_str . ' 09:00', $tz );
	if ( ! $dt ) {
		$order->add_order_note( '⚠️ SEPA différé : date saisie invalide, planification inchangée.' );
		return;
	}
	$timestamp = $dt->getTimestamp();

	if ( $timestamp <= time() ) {
		$order->add_order_note( '⚠️ SEPA différé : la date saisie est dans le passé, planification inchangée.' );
		return;
	}

	$previous_ts = (int) $order->get_meta( '_annad_sepa_scheduled_ts' );

	annad_sepa_schedule_at( $order, $timestamp );

	$order->add_order_note( sprintf(
		'SEPA différé : prélèvement replanifié du %s au %s (modification manuelle).',
		$previous_ts ? annad_sepa_format_date( $previous_ts ) : '(aucune date)',
		annad_sepa_format_date( $timestamp )
	) );
}

/**
 * Déclenchement manuel du prélèvement depuis la fiche commande (mode test).
 * Équivaut au bouton « Run » d'Action Scheduler, sans dépendre de cet écran.
 */
add_action( 'admin_post_annad_sepa_run_now', 'annad_sepa_handle_run_now' );

function annad_sepa_handle_run_now() {
	$order_id = isset( $_GET['order_id'] ) ? absint( $_GET['order_id'] ) : 0;

	if ( ! $order_id
		|| ! current_user_can( 'edit_shop_orders' )
		|| ! isset( $_GET['_wpnonce'] )
		|| ! wp_verify_nonce( sanitize_key( $_GET['_wpnonce'] ), 'annad_sepa_run_now_' . $order_id )
	) {
		wp_die( 'Action non autorisée.' );
	}

	annad_sepa_do_confirm( $order_id );

	// Retour vers la fiche commande (HPOS ou legacy).
	$redirect = wp_get_referer();
	if ( ! $redirect ) {
		$redirect = admin_url( 'admin.php?page=wc-orders&action=edit&id=' . $order_id );
	}
	wp_safe_redirect( $redirect );
	exit;
}

/* ============================================================
 * 4. UTILITAIRES
 * ============================================================ */

/**
 * Clé secrète Stripe depuis les réglages du plugin officiel (live ou test).
 */
function annad_sepa_get_secret_key() {
	$settings  = get_option( 'woocommerce_stripe_settings', array() );
	$test_mode = isset( $settings['testmode'] ) && 'yes' === $settings['testmode'];

	$key = $test_mode ? ( $settings['test_secret_key'] ?? '' ) : ( $settings['secret_key'] ?? '' );

	return $key ? trim( $key ) : '';
}

/**
 * Clé d'idempotence stable et persistante pour une commande.
 * Générée une seule fois puis réutilisée : tous les rejeux de la tâche
 * planifiée partagent la même clé, donc Stripe ne débite qu'une fois.
 */
function annad_sepa_get_idempotency_key( WC_Order $order ) {
	$idem = $order->get_meta( '_annad_sepa_idem' );
	if ( ! $idem ) {
		$idem = 'annad_sepa_' . $order->get_id() . '_' . wp_generate_uuid4();
		$order->update_meta_data( '_annad_sepa_idem', $idem );
		$order->save();
	}
	return $idem;
}

/**
 * Requête générique vers l'API Stripe (form-encoded, comme attendu par Stripe).
 */
function annad_sepa_stripe_request( $method, $endpoint, $body, $secret_key, $idempotency_key = '' ) {
	$args = array(
		'method'  => $method,
		'timeout' => 45,
		// Pas de header Stripe-Version : Stripe applique alors la version d'API du
		// compte, ce qui évite toute divergence entre une version figée ici et celle
		// réellement utilisée par le plugin Stripe officiel.
		'headers' => array(
			'Authorization' => 'Bearer ' . $secret_key,
		),
	);

	// N'a de sens que sur les requêtes mutatives (POST). Protège contre les doubles prélèvements.
	if ( 'POST' === $method && $idempotency_key ) {
		$args['headers']['Idempotency-Key'] = $idempotency_key;
	}

	if ( 'POST' === $method && ! empty( $body ) ) {
		$args['body'] = annad_sepa_flatten_body( $body );
	}

	$response = wp_remote_request( 'https://api.stripe.com/v1/' . $endpoint, $args );

	if ( is_wp_error( $response ) ) {
		return $response;
	}

	$decoded = json_decode( wp_remote_retrieve_body( $response ), true );

	return is_array( $decoded ) ? $decoded : new WP_Error( 'annad_sepa_bad_json', 'Réponse Stripe illisible.' );
}

/**
 * Aplatit un tableau PHP en clés form-encoded Stripe
 * (ex. metadata[order_id], payment_method_types[0]).
 */
function annad_sepa_flatten_body( array $body, $prefix = '' ) {
	$flat = array();
	foreach ( $body as $key => $value ) {
		$full_key = $prefix ? $prefix . '[' . $key . ']' : $key;
		if ( is_array( $value ) ) {
			$flat = array_merge( $flat, annad_sepa_flatten_body( $value, $full_key ) );
		} else {
			$flat[ $full_key ] = $value;
		}
	}
	return $flat;
}

/**
 * Montant de la commande en centimes (Stripe attend un entier).
 */
function annad_sepa_amount_in_cents( WC_Order $order ) {
	return (int) round( (float) $order->get_total() * 100 );
}

/**
 * Formatage d'un timestamp UTC en date/heure de Paris lisible.
 */
function annad_sepa_format_date( $timestamp ) {
	return wp_date( 'd/m/Y à H\hi', $timestamp, new DateTimeZone( 'Europe/Paris' ) );
}

/* ============================================================
 * 4bis. INTERCEPTION DU CHECKOUT — NE PAS DÉBITER TOUT DE SUITE (§5.2)
 * ============================================================
 *
 * Objectif : sur une commande SEPA, laisser le plugin Stripe officiel créer le
 * PaymentIntent et sauvegarder le mandat/payment method, mais SANS le confirmer.
 * Le prélèvement ne partira qu'à J+8 (Stratégie A) ou via un nouveau PI off-session
 * (Stratégie B) déclenché par la tâche planifiée.
 *
 * ⚠️ Les noms de filtres ci-dessous dépendent de la version du plugin Stripe.
 * Rien ne s'exécute tant que ANNAD_SEPA_DEFER_AT_CHECKOUT vaut false.
 */

if ( defined( 'ANNAD_SEPA_DEFER_AT_CHECKOUT' ) && ANNAD_SEPA_DEFER_AT_CHECKOUT ) {

	// --- Piste Legacy checkout : filtre sur les args de création du PaymentIntent. ---
	// Présent dans les versions "legacy" du plugin (classe WC_Stripe_Payment_Gateway).
	add_filter( 'wc_stripe_generate_create_intent_request', 'annad_sepa_defer_checkout', 10, 3 );

	// --- Piste UPE / New checkout : mêmes intentions, filtre d'arguments du PI. ---
	// Le nom exact varie ; ces deux-là couvrent les versions récentes. Le garde-fou
	// interne (méthode de paiement SEPA) rend l'ajout inoffensif si le filtre n'existe pas.
	add_filter( 'wc_stripe_payment_intent_args', 'annad_sepa_defer_checkout_upe', 10, 2 );
	add_filter( 'wc_stripe_create_payment_intent_args', 'annad_sepa_defer_checkout_upe', 10, 2 );
}

/**
 * Legacy : force confirm=false et le setup du mandat pour usage ultérieur off-session.
 *
 * @param array    $request Arguments de la requête PaymentIntent.
 * @param WC_Order $order   Commande (selon version : peut être un WC_Order ou un id).
 * @return array
 */
function annad_sepa_defer_checkout( $request, $order = null, $prepared_source = null ) {
	annad_sepa_probe( 'wc_stripe_generate_create_intent_request', $order );
	if ( ! annad_sepa_order_is_sepa( $order ) ) {
		return $request;
	}

	// Ne pas confirmer/capturer au checkout ; sauvegarder le moyen de paiement pour la MIT.
	$request['confirm']            = 'false';
	$request['setup_future_usage'] = 'off_session';
	unset( $request['capture_method'] );

	return $request;
}

/**
 * UPE / New checkout : même logique sur la structure d'arguments moderne.
 *
 * @param array    $args  Arguments du PaymentIntent.
 * @param WC_Order $order Commande.
 * @return array
 */
function annad_sepa_defer_checkout_upe( $args, $order = null ) {
	annad_sepa_probe( 'wc_stripe_(create_)payment_intent_args', $order );
	if ( ! annad_sepa_order_is_sepa( $order ) ) {
		return $args;
	}

	$args['confirm']            = false;
	$args['setup_future_usage'] = 'off_session';

	return $args;
}

/**
 * Détermine si l'objet reçu (WC_Order, id, ou null) correspond à une commande SEPA.
 * Tolérant aux différentes signatures de filtres selon la version du plugin.
 */
function annad_sepa_order_is_sepa( $order ) {
	if ( $order instanceof WC_Order ) {
		return in_array( $order->get_payment_method(), ANNAD_SEPA_GATEWAYS, true );
	}
	if ( is_numeric( $order ) ) {
		$maybe = wc_get_order( (int) $order );
		return $maybe && in_array( $maybe->get_payment_method(), ANNAD_SEPA_GATEWAYS, true );
	}
	// Impossible de déterminer la commande → on ne touche à rien (sécurité).
	return false;
}

/**
 * Sonde de diagnostic : trace le dernier appel d'un filtre d'interception.
 * Sert uniquement à savoir, pendant le test, si le hook se déclenche et si la
 * commande/méthode de paiement est bien résolue à ce moment-là.
 */
function annad_sepa_probe( $hook, $order ) {
	if ( ! ( defined( 'ANNAD_SEPA_DEBUG' ) && ANNAD_SEPA_DEBUG ) ) {
		return;
	}
	$pm = '';
	if ( $order instanceof WC_Order ) {
		$pm = $order->get_payment_method();
	} elseif ( is_numeric( $order ) ) {
		$o  = wc_get_order( (int) $order );
		$pm = $o ? $o->get_payment_method() : '';
	}
	update_option( 'annad_sepa_probe', array(
		'time'         => time(),
		'hook'         => $hook,
		'order_type'   => is_object( $order ) ? get_class( $order ) : gettype( $order ),
		'payment_meth' => $pm,
		'is_sepa'      => annad_sepa_order_is_sepa( $order ) ? 'oui' : 'non',
	), false );
}

/* ============================================================
 * 4ter. PASSERELLE DE PAIEMENT DÉDIÉE « SEPA DIFFÉRÉ »
 * ============================================================
 *
 * Au checkout : ne débite RIEN. Retrouve le mandat SEPA déjà enregistré du client
 * (payment method pm_ + customer cus_), le stocke sur la commande, met la commande
 * « en attente ». Le prélèvement réel est déclenché à J+8 par la mécanique existante
 * (planification sur « Terminé » → Stratégie B off-session).
 *
 * Ne s'affiche au checkout QUE si le client a effectivement un mandat réutilisable.
 */

add_filter( 'woocommerce_payment_gateways', 'annad_sepa_register_gateway' );

function annad_sepa_register_gateway( $methods ) {
	$methods[] = 'Annad_SEPA_Deferred_Gateway';
	return $methods;
}

add_action( 'plugins_loaded', 'annad_sepa_load_gateway_class', 11 );

function annad_sepa_load_gateway_class() {
	if ( ! class_exists( 'WC_Payment_Gateway' ) || class_exists( 'Annad_SEPA_Deferred_Gateway' ) ) {
		return;
	}

	class Annad_SEPA_Deferred_Gateway extends WC_Payment_Gateway {

		public function __construct() {
			$this->id                 = ANNAD_SEPA_GATEWAY_ID;
			$this->method_title       = 'Prélèvement SEPA différé';
			$this->method_description = 'Réutilise le mandat SEPA déjà enregistré du client (via Stripe). '
				. 'Aucun débit au checkout : le prélèvement est déclenché automatiquement '
				. annad_sepa_get_delay_days() . ' jours après le passage de la commande en « Terminé » '
				. '(délai global modifiable ci-dessous, et personnalisable par client sur sa fiche utilisateur).';
			$this->has_fields         = false;

			$this->init_form_fields();
			$this->init_settings();

			$this->title       = $this->get_option( 'title', 'Prélèvement SEPA (différé)' );
			$this->description = $this->get_option( 'description', 'Vous serez prélevé via votre mandat SEPA déjà enregistré, quelques jours après l\'expédition de votre commande.' );
			$this->enabled     = $this->get_option( 'enabled', 'no' );

			add_action( 'woocommerce_update_options_payment_gateways_' . $this->id, array( $this, 'process_admin_options' ) );
		}

		public function init_form_fields() {
			$this->form_fields = array(
				'enabled'     => array(
					'title'   => 'Activer',
					'type'    => 'checkbox',
					'label'   => 'Activer le prélèvement SEPA différé',
					'default' => 'no',
				),
				'title'       => array(
					'title'       => 'Titre',
					'type'        => 'text',
					'description' => 'Intitulé vu par le client au checkout.',
					'default'     => 'Prélèvement SEPA (différé)',
					'desc_tip'    => true,
				),
				'description' => array(
					'title'       => 'Description',
					'type'        => 'textarea',
					'description' => 'Texte vu par le client au checkout. « quelques jours » ou le jeton {delai} '
						. 'sont remplacés automatiquement par le délai applicable au client + 2 jours de délai bancaire.',
					'default'     => 'Vous serez prélevé via votre mandat SEPA déjà enregistré, {delai} jours après l\'expédition de votre commande.',
				),
				'delay_days'  => array(
					'title'             => 'Délai de prélèvement par défaut (jours)',
					'type'              => 'number',
					'description'       => 'Nombre de jours entre le passage de la commande en « Terminé » et le déclenchement '
						. 'du prélèvement. Peut être personnalisé client par client depuis sa fiche utilisateur '
						. '(Utilisateurs → section « Prélèvement SEPA différé »).',
					'default'           => ANNAD_SEPA_DELAY_DAYS,
					'custom_attributes' => array(
						'min'  => 1,
						'step' => 1,
					),
				),
			);
		}

		/**
		 * Disponible uniquement si le client connecté possède un mandat SEPA réutilisable.
		 */
		public function is_available() {
			if ( is_admin() ) {
				return parent::is_available();
			}
			if ( 'yes' !== $this->enabled ) {
				return false;
			}
			$user_id = get_current_user_id();
			if ( ! $user_id || ! annad_sepa_is_authorized( $user_id ) ) {
				return false; // Non identifié, particulier, ou non autorisé commercialement.
			}
			return (bool) annad_sepa_find_saved_mandate( $user_id );
		}

		/**
		 * Ne débite pas : enregistre le mandat sur la commande + met « en attente ».
		 */
		public function process_payment( $order_id ) {
			$order = wc_get_order( $order_id );

			// Garde-fou serveur : re-vérifie l'autorisation même si l'affichage a été contourné.
			if ( ! $order || ! annad_sepa_is_authorized( $order->get_user_id() ) ) {
				wc_add_notice( 'Ce moyen de paiement n\'est pas disponible pour votre compte.', 'error' );
				return array( 'result' => 'failure' );
			}

			$mandate = annad_sepa_find_saved_mandate( $order->get_user_id() );

			if ( ! $mandate ) {
				wc_add_notice( 'Aucun mandat SEPA enregistré n\'a été trouvé pour votre compte. Merci de choisir un autre moyen de paiement.', 'error' );
				return array( 'result' => 'failure' );
			}

			$order->update_meta_data( '_stripe_customer_id', $mandate['customer'] );
			$order->update_meta_data( '_stripe_source_id', $mandate['payment_method'] );
			$order->set_payment_method( $this );

			// « En attente » : commande à traiter/expédier, mais NON payée (pas de débit encore).
			$order->update_status(
				'on-hold',
				sprintf(
					'SEPA différé : mandat enregistré réutilisé (%s). Aucun débit au checkout — '
					. 'le prélèvement sera programmé automatiquement au passage en « Terminé » (J+%d).',
					$mandate['payment_method'],
					annad_sepa_get_delay_days( $order->get_user_id() )
				)
			);
			$order->save();

			wc_reduce_stock_levels( $order_id );

			if ( WC()->cart ) {
				WC()->cart->empty_cart();
			}

			return array(
				'result'   => 'success',
				'redirect' => $this->get_return_url( $order ),
			);
		}
	}
}

/**
 * Autorisation commerciale au SEPA différé : distincte du mandat Stripe (déjà
 * valide légalement dès sa signature électronique). Il n'y a plus de case
 * séparée : choisir un mandat actif EST l'autorisation. Elle exige en plus que
 * le client ait lui-même mis ce même IBAN par défaut de son côté ("Mon compte"),
 * pour éviter qu'un client bascule silencieusement sur un IBAN non validé par Annad.
 */
function annad_sepa_is_authorized( $user_id ) {
	if ( ! $user_id ) {
		return false;
	}

	$user = get_userdata( $user_id );
	if ( ! $user ) {
		return false;
	}

	// Jamais pour un compte "particulier".
	if ( array_intersect( ANNAD_SEPA_PARTICULIER_ROLES, (array) $user->roles ) ) {
		return false;
	}

	return (bool) annad_sepa_find_saved_mandate( $user_id );
}

/* ---- Champ admin : choix du "Mandat actif" sur la fiche utilisateur ---- */

add_action( 'show_user_profile', 'annad_sepa_render_authorization_field' );
add_action( 'edit_user_profile', 'annad_sepa_render_authorization_field' );

function annad_sepa_render_authorization_field( $user ) {
	if ( ! current_user_can( 'edit_users' ) ) {
		return;
	}
	$is_particulier = array_intersect( ANNAD_SEPA_PARTICULIER_ROLES, (array) $user->roles );
	?>
	<h2>Prélèvement SEPA différé</h2>
	<table class="form-table">
		<tr>
			<th><label>Mandat actif</label></th>
			<td>
				<?php if ( $is_particulier ) : ?>
					<p class="description">Compte particulier — le SEPA différé n'est jamais proposé à ce type de compte.</p>
				<?php else : ?>
					<?php
					$sepa_tokens = array();
					if ( class_exists( 'WC_Payment_Tokens' ) ) {
						foreach ( WC_Payment_Tokens::get_customer_tokens( $user->ID ) as $token ) {
							if ( 'SEPA' === $token->get_type() || false !== stripos( $token->get_type(), 'sepa' ) ) {
								$sepa_tokens[] = $token;
							}
						}
					}
					$active_id = get_user_meta( $user->ID, ANNAD_SEPA_ACTIVE_TOKEN_META, true );
					?>
					<label style="display:block;margin-bottom:4px;">
						<input type="radio" name="annad_sepa_active_token" value="" <?php checked( empty( $active_id ) ); ?>>
						&lt;aucun&gt; — ce client ne verra pas le prélèvement SEPA différé
					</label>
					<?php if ( empty( $sepa_tokens ) ) : ?>
						<p class="description">Ce client n'a enregistré aucun IBAN chez Stripe pour l'instant.</p>
					<?php else : ?>
						<?php foreach ( $sepa_tokens as $token ) : ?>
							<label style="display:block;margin-bottom:4px;">
								<input type="radio" name="annad_sepa_active_token" value="<?php echo esc_attr( $token->get_id() ); ?>"
									<?php checked( (string) $active_id === (string) $token->get_id() ); ?>>
								IBAN se terminant par <?php echo esc_html( method_exists( $token, 'get_last4' ) ? $token->get_last4() : '????' ); ?>
								<?php if ( $token->is_default() ) : ?>
									<span class="description">(actuellement par défaut côté client)</span>
								<?php endif; ?>
							</label>
						<?php endforeach; ?>
					<?php endif; ?>
					<p class="description">
						Choisir un IBAN ici l'autorise pour le prélèvement différé. Le client doit
						EN PLUS avoir mis ce même IBAN par défaut de son côté ("Mon compte → Mes
						moyens de paiement") pour que le paiement lui soit réellement proposé —
						cela évite qu'il bascule seul vers un IBAN non validé par vous.
					</p>
					<?php
					$custom_delay = get_user_meta( $user->ID, ANNAD_SEPA_DELAY_META, true );
					$settings_gw  = get_option( 'woocommerce_' . ANNAD_SEPA_GATEWAY_ID . '_settings', array() );
					$global_delay = ( is_array( $settings_gw ) && (int) ( $settings_gw['delay_days'] ?? 0 ) > 0 )
						? (int) $settings_gw['delay_days']
						: ANNAD_SEPA_DELAY_DAYS;
					?>
					<p style="margin-top:15px;">
						<strong>Délai de prélèvement pour ce client :</strong> J+
						<input type="number" name="annad_sepa_delay_days" min="1" step="1" style="width:70px;"
							value="<?php echo esc_attr( $custom_delay ); ?>"
							placeholder="<?php echo esc_attr( $global_delay ); ?>">
					</p>
					<p class="description">
						Laisser vide pour utiliser le délai global (actuellement J+<?php echo esc_html( $global_delay ); ?>,
						modifiable dans Réglages → Paiements → Prélèvement SEPA différé). Renseigner un nombre
						de jours pour ce client uniquement (ex. 30 pour les clients exigeant du J+30).
						S'applique aux prochaines commandes ; les commandes déjà planifiées ne sont pas modifiées.
					</p>
					<?php if ( defined( 'ANNAD_SEPA_DEBUG' ) && ANNAD_SEPA_DEBUG ) : ?>
						<?php
						// Diagnostic pas-à-pas : évalue chaque condition de disponibilité de la
						// passerelle POUR CE CLIENT, telle que la verrait la page de paiement.
						$diag = array();

						$gw_settings       = get_option( 'woocommerce_' . ANNAD_SEPA_GATEWAY_ID . '_settings', array() );
						$diag['passerelle activée (réglages WooCommerce)'] = ( is_array( $gw_settings ) && 'yes' === ( $gw_settings['enabled'] ?? '' ) ) ? '✅ oui' : '❌ NON';

						$diag['rôle non-particulier'] = array_intersect( ANNAD_SEPA_PARTICULIER_ROLES, (array) $user->roles ) ? '❌ NON (rôle particulier)' : '✅ oui (' . implode( ', ', (array) $user->roles ) . ')';

						if ( $active_id ) {
							$t = WC_Payment_Tokens::get( (int) $active_id );
							$diag[ 'mandat actif choisi (token #' . (int) $active_id . ')' ] = $t ? '✅ trouvé' : '❌ INTROUVABLE (supprimé ?)';
							if ( $t ) {
								$diag['token appartient bien à ce client'] = ( (int) $t->get_user_id() === (int) $user->ID ) ? '✅ oui' : '❌ NON';
								$diag['token "par défaut" côté client']    = $t->is_default() ? '✅ oui' : '❌ NON';
								$diag['token est un pm_ Stripe']           = ( 0 === strpos( (string) $t->get_token(), 'pm_' ) ) ? '✅ oui (' . esc_html( $t->get_token() ) . ')' : '❌ NON (' . esc_html( $t->get_token() ) . ')';
							}
						} else {
							$diag['mandat actif choisi'] = '❌ AUCUN (radio <aucun>)';
						}

						$mandate = annad_sepa_find_saved_mandate( $user->ID );

						$cus = $mandate ? $mandate['customer'] : annad_sepa_resolve_customer_id( $user->ID );
						$diag['customer Stripe (cus_) résolu'] = $cus ? '✅ ' . esc_html( $cus ) : '❌ INTROUVABLE (API Stripe, metas et commandes)';
						$diag['→ RÉSULTAT FINAL (mandat utilisable)'] = $mandate ? '✅ ' . esc_html( wp_json_encode( $mandate ) ) : '❌ false → la passerelle est masquée au checkout';
						?>
						<div style="border:1px dashed #d63638;padding:8px;margin-top:10px;font-size:12px;">
							<strong>🔧 Diagnostic du Dahu 🐐 — disponibilité de la passerelle pour ce client</strong><br>
							<?php foreach ( $diag as $label => $value ) : ?>
								<?php echo esc_html( $label ); ?> : <?php echo wp_kses_post( $value ); ?><br>
							<?php endforeach; ?>
						</div>
					<?php endif; ?>
				<?php endif; ?>
			</td>
		</tr>
	</table>
	<?php
}

add_action( 'personal_options_update', 'annad_sepa_save_authorization_field' );
add_action( 'edit_user_profile_update', 'annad_sepa_save_authorization_field' );

function annad_sepa_save_authorization_field( $user_id ) {
	if ( ! current_user_can( 'edit_users' ) ) {
		return;
	}
	if ( ! isset( $_POST['_wpnonce'] ) || ! wp_verify_nonce( sanitize_key( $_POST['_wpnonce'] ), 'update-user_' . $user_id ) ) {
		return;
	}

	if ( isset( $_POST['annad_sepa_active_token'] ) ) {
		$new_active = absint( $_POST['annad_sepa_active_token'] );
		$old_active = (int) get_user_meta( $user_id, ANNAD_SEPA_ACTIVE_TOKEN_META, true );
		update_user_meta( $user_id, ANNAD_SEPA_ACTIVE_TOKEN_META, $new_active );

		// Email au client uniquement quand un IBAN vient d'être validé (pas au
		// passage à <aucun>, ni si la sélection est inchangée).
		if ( $new_active && $new_active !== $old_active ) {
			annad_sepa_notify_iban_validated( $user_id, $new_active );
		}
	}

	if ( isset( $_POST['annad_sepa_delay_days'] ) ) {
		$delay = sanitize_text_field( wp_unslash( $_POST['annad_sepa_delay_days'] ) );
		if ( '' === $delay || (int) $delay < 1 ) {
			delete_user_meta( $user_id, ANNAD_SEPA_DELAY_META ); // Vide → délai global.
		} else {
			update_user_meta( $user_id, ANNAD_SEPA_DELAY_META, (int) $delay );
		}
	}
}

/* ---- Un seul mandat SEPA actif par client (remplacement notifié, pas bloqué) ---- */

// ⚠️ DÉSACTIVÉS temporairement pendant le diagnostic (juillet 2026) : faire
// coexister ce mécanisme "à l'ajout" avec celui basé sur la page compte
// (annad_sepa_dedupe_on_account_page, plus bas) créait une suppression en double,
// vidant complètement la liste des mandats. Un seul mécanisme actif à la fois
// pendant qu'on isole la cause exacte. Fonctions conservées, non appelées.
if ( defined( 'ANNAD_SEPA_DEDUPE_ON_ADD_HOOKS' ) && ANNAD_SEPA_DEDUPE_ON_ADD_HOOKS ) {
	add_action( 'woocommerce_payment_token_added', 'annad_sepa_enforce_single_mandate', 10, 1 );
	add_action( 'woocommerce_add_payment_method_success', 'annad_sepa_handle_add_payment_method_success', 10, 2 );
}

function annad_sepa_handle_add_payment_method_success( $payment_method_id, $token_or_id = null ) {
	$token_id = null;
	if ( is_object( $token_or_id ) && method_exists( $token_or_id, 'get_id' ) ) {
		$token_id = $token_or_id->get_id();
	} elseif ( is_numeric( $token_or_id ) ) {
		$token_id = (int) $token_or_id;
	}
	if ( $token_id ) {
		annad_sepa_enforce_single_mandate( $token_id );
	}
}

function annad_sepa_enforce_single_mandate( $token_id ) {
	if ( ! class_exists( 'WC_Payment_Tokens' ) ) {
		return;
	}
	$new_token = WC_Payment_Tokens::get( $token_id );

	// Sonde : trace chaque appel, même si le token est introuvable ou n'est pas SEPA,
	// pour diagnostiquer pourquoi la déduplication ne se déclenche pas.
	annad_sepa_probe_token( current_action(), $token_id, $new_token );

	if ( ! $new_token ) {
		return;
	}

	$is_sepa = ( 'SEPA' === $new_token->get_type() ) || ( false !== stripos( $new_token->get_type(), 'sepa' ) );
	if ( ! $is_sepa ) {
		return;
	}

	// Supprime les anciens mandats SEPA du client : un seul mandat actif à la fois,
	// conformément à la contrainte métier (un client = un mandat). Le remplacement
	// n'est pas bloqué (le client peut avoir une vraie raison de changer de RIB),
	// mais Annad est notifié pour vérifier/valider le changement a posteriori.
	foreach ( WC_Payment_Tokens::get_customer_tokens( $new_token->get_user_id() ) as $existing ) {
		$existing_is_sepa = ( 'SEPA' === $existing->get_type() ) || ( false !== stripos( $existing->get_type(), 'sepa' ) );
		if ( $existing_is_sepa && $existing->get_id() !== $new_token->get_id() ) {
			annad_sepa_notify_mandate_replaced( $new_token, $existing );
			WC_Payment_Tokens::delete( $existing->get_id() );
		}
	}
}

/* ---- ABANDONNÉ (juillet 2026) : suppression automatique des IBAN en trop.
 * Remplacé par la solution retenue : Stripe autorise librement plusieurs IBAN,
 * et un admin Annad choisit explicitement lequel est actif (radio ci-dessus,
 * sur la fiche utilisateur). Fonction conservée mais non appelée. ---- */

if ( defined( 'ANNAD_SEPA_AUTO_DEDUPE_TOKENS' ) && ANNAD_SEPA_AUTO_DEDUPE_TOKENS ) {
	add_action( 'woocommerce_account_payment-methods_endpoint', 'annad_sepa_dedupe_on_account_page', 5 );
}

function annad_sepa_dedupe_on_account_page() {
	$user_id = get_current_user_id();
	if ( ! $user_id || ! class_exists( 'WC_Payment_Tokens' ) ) {
		return;
	}

	$sepa_tokens = array();
	foreach ( WC_Payment_Tokens::get_customer_tokens( $user_id ) as $token ) {
		if ( 'SEPA' === $token->get_type() || false !== stripos( $token->get_type(), 'sepa' ) ) {
			$sepa_tokens[] = $token;
		}
	}

	annad_sepa_probe_token( 'woocommerce_account_payment-methods_endpoint', null, null, count( $sepa_tokens ) );

	if ( count( $sepa_tokens ) < 2 ) {
		return;
	}

	// Le token le plus récent (ID le plus élevé) est conservé ; les autres, supprimés + notifiés.
	usort( $sepa_tokens, function ( $a, $b ) {
		return $b->get_id() <=> $a->get_id();
	} );
	$newest = array_shift( $sepa_tokens );

	foreach ( $sepa_tokens as $old ) {
		annad_sepa_notify_mandate_replaced( $newest, $old );
		WC_Payment_Tokens::delete( $old->get_id() );
	}
}

/**
 * Sonde de diagnostic : trace chaque appel des hooks candidats de dédup SEPA,
 * pour savoir si/quand ils se déclenchent réellement et quel type de token WooCommerce voit.
 */
function annad_sepa_probe_token( $hook, $token_id, $token, $sepa_count = null ) {
	if ( ! ( defined( 'ANNAD_SEPA_DEBUG' ) && ANNAD_SEPA_DEBUG ) ) {
		return;
	}
	update_option( 'annad_sepa_token_probe', array(
		'time'       => time(),
		'hook'       => $hook,
		'token_id'   => $token_id,
		'found'      => $token ? 'oui' : 'non',
		'type'       => $token ? $token->get_type() : '',
		'gateway'    => ( $token && method_exists( $token, 'get_gateway_id' ) ) ? $token->get_gateway_id() : '',
		'user_id'    => $token ? $token->get_user_id() : '',
		'sepa_count' => $sepa_count,
	), false );
}

/**
 * Prévient Annad par email qu'un client a remplacé son mandat SEPA, pour
 * vérification manuelle a posteriori (RIB à recontrôler).
 */
function annad_sepa_notify_mandate_replaced( $new_token, $old_token ) {
	$to = ANNAD_SEPA_NOTIFY_EMAIL ? ANNAD_SEPA_NOTIFY_EMAIL : get_option( 'admin_email' );
	if ( ! $to ) {
		return;
	}

	$user = get_userdata( $new_token->get_user_id() );

	$subject = 'SEPA différé : mandat remplacé — vérification requise';
	$body    = sprintf(
		"Un client a enregistré un nouveau mandat SEPA, remplaçant le précédent.\n\n" .
		"Client : %s (%s)\n" .
		"Ancien IBAN se terminant par : %s\n" .
		"Nouveau IBAN se terminant par : %s\n" .
		"Date : %s\n\n" .
		"Merci de vérifier le nouveau RIB avant le prochain prélèvement différé.",
		$user ? $user->display_name : 'inconnu',
		$user ? $user->user_email : 'inconnu',
		method_exists( $old_token, 'get_last4' ) ? $old_token->get_last4() : '????',
		method_exists( $new_token, 'get_last4' ) ? $new_token->get_last4() : '????',
		wp_date( 'd/m/Y à H\hi', time(), new DateTimeZone( 'Europe/Paris' ) )
	);

	wp_mail( $to, $subject, $body );
}

/* ============================================================
 * 4septies. EMAILS — AJOUT ET VALIDATION D'IBAN
 * ============================================================
 *
 * 1) Quand un client ajoute un IBAN : email à Annad (validation requise)
 *    + email au client (demande bien enregistrée).
 * 2) Quand un admin valide un IBAN (choix du "Mandat actif") : email au client.
 *
 * La détection d'ajout compare les tokens SEPA actuels du client à la liste
 * déjà connue (meta), à chaque affichage de "Mes moyens de paiement" — la page
 * où WooCommerce redirige systématiquement après un ajout. Le hook standard
 * woocommerce_payment_token_added est branché en plus : s'il fonctionne sur la
 * version installée, la notification part immédiatement ; sinon le passage sur
 * la page prend le relais. La liste connue sert de garde anti-doublon.
 */

add_action( 'woocommerce_account_payment-methods_endpoint', function () {
	annad_sepa_check_default_iban( get_current_user_id() );
}, 1 );

// Bonus si ces hooks fonctionnent sur la version installée : notification immédiate,
// sans attendre le passage sur la page. Sinon, la page prend le relais.
add_action( 'woocommerce_payment_token_added', function ( $token_id ) {
	if ( class_exists( 'WC_Payment_Tokens' ) ) {
		$token = WC_Payment_Tokens::get( (int) $token_id );
		if ( $token ) {
			annad_sepa_check_default_iban( $token->get_user_id() );
		}
	}
} );
add_action( 'woocommerce_payment_token_set_default', function ( $token_id, $token = null ) {
	if ( $token && method_exists( $token, 'get_user_id' ) ) {
		annad_sepa_check_default_iban( $token->get_user_id() );
	}
}, 10, 2 );

/**
 * Token SEPA actuellement "par défaut" du client, ou null.
 */
function annad_sepa_get_default_sepa_token( $user_id ) {
	if ( ! $user_id || ! class_exists( 'WC_Payment_Tokens' ) ) {
		return null;
	}
	foreach ( WC_Payment_Tokens::get_customer_tokens( $user_id ) as $token ) {
		$is_sepa = ( 'SEPA' === $token->get_type() ) || ( false !== stripos( $token->get_type(), 'sepa' ) );
		if ( $is_sepa && $token->is_default() ) {
			return $token;
		}
	}
	return null;
}

/**
 * Détecte un CHANGEMENT d'IBAN par défaut (= soumission à validation) et envoie
 * les emails. Un ajout d'IBAN supplémentaire sans changement de défaut ne
 * déclenche rien. Si le nouveau défaut est l'IBAN déjà validé par Annad (retour
 * en arrière du client), rien non plus : il n'y a rien à re-valider.
 */
function annad_sepa_check_default_iban( $user_id ) {
	if ( ! $user_id ) {
		return;
	}

	$default = annad_sepa_get_default_sepa_token( $user_id );
	$known   = (int) get_user_meta( $user_id, ANNAD_SEPA_KNOWN_DEFAULT_META, true );

	if ( ! $default ) {
		// Plus d'IBAN par défaut (supprimé) : réinitialiser pour re-détecter plus tard.
		if ( $known ) {
			update_user_meta( $user_id, ANNAD_SEPA_KNOWN_DEFAULT_META, 0 );
		}
		return;
	}

	if ( (int) $default->get_id() === $known ) {
		return; // Rien de nouveau.
	}

	update_user_meta( $user_id, ANNAD_SEPA_KNOWN_DEFAULT_META, (int) $default->get_id() );

	// Déjà validé par Annad → pas de "soumission", inutile de notifier.
	$active = (int) get_user_meta( $user_id, ANNAD_SEPA_ACTIVE_TOKEN_META, true );
	if ( (int) $default->get_id() === $active ) {
		return;
	}

	$last4 = method_exists( $default, 'get_last4' ) ? $default->get_last4() : '????';
	annad_sepa_notify_iban_added( $user_id, $last4 );
}

/**
 * Emails envoyés à l'ajout d'un IBAN : un à Annad, un au client.
 */
function annad_sepa_notify_iban_added( $user_id, $last4 ) {
	$user = get_userdata( $user_id );
	if ( ! $user ) {
		return;
	}

	// --- Email à Annad : validation requise. ---
	$to_admin = ANNAD_SEPA_NOTIFY_EMAIL ? ANNAD_SEPA_NOTIFY_EMAIL : get_option( 'admin_email' );
	wp_mail(
		$to_admin,
		'SEPA différé : nouvel IBAN soumis — validation requise sous 72h',
		sprintf(
			"Un client vient de soumettre un IBAN à validation (nouvel IBAN par défaut " .
			"dans « Mes moyens de paiement »).\n\n" .
			"Client : %s (%s)\nIBAN se terminant par : %s\nDate : %s\n\n" .
			"Pour le valider comme mandat actif, ouvrez sa fiche utilisateur :\n%s\n\n" .
			"Le client a été informé qu'une confirmation lui parviendra sous 72h. " .
			"Tant que l'IBAN n'est pas validé, le prélèvement SEPA différé ne lui est pas proposé.",
			$user->display_name,
			$user->user_email,
			$last4,
			wp_date( 'd/m/Y à H\hi', time(), new DateTimeZone( 'Europe/Paris' ) ),
			admin_url( 'user-edit.php?user_id=' . $user_id . '#annad-sepa' )
		)
	);

	// --- Email au client : soumission enregistrée. ---
	wp_mail(
		$user->user_email,
		'Votre IBAN a bien été soumis',
		sprintf(
			"Bonjour %s,\n\n" .
			"Votre IBAN se terminant par %s a bien été soumis.\n\n" .
			"Nous vous confirmerons sa validation par e-mail sous 72h. Une fois validé, " .
			"vous pourrez régler vos commandes par prélèvement SEPA différé.\n\n" .
			"Cordialement,\nL'équipe %s",
			$user->display_name,
			$last4,
			get_bloginfo( 'name' )
		)
	);
}

/**
 * Email envoyé au client quand un admin valide son IBAN (choix du mandat actif).
 */
function annad_sepa_notify_iban_validated( $user_id, $token_id ) {
	$user = get_userdata( $user_id );
	if ( ! $user || ! class_exists( 'WC_Payment_Tokens' ) ) {
		return;
	}
	$token = WC_Payment_Tokens::get( (int) $token_id );
	if ( ! $token ) {
		return;
	}
	$last4 = method_exists( $token, 'get_last4' ) ? $token->get_last4() : '????';

	wp_mail(
		$user->user_email,
		'Votre IBAN a été validé pour le prélèvement différé',
		sprintf(
			"Bonjour %s,\n\n" .
			"Bonne nouvelle : votre IBAN se terminant par %s a été validé pour le " .
			"prélèvement SEPA différé.\n\n" .
			"Vous pouvez désormais choisir « Prélèvement SEPA (différé) » lors de vos " .
			"commandes : aucun débit à la commande, le prélèvement est déclenché %d jours " .
			"après l'expédition (comptez ensuite 2-3 jours ouvrés de délai bancaire).\n\n" .
			"Cordialement,\nL'équipe %s",
			$user->display_name,
			$last4,
			annad_sepa_get_delay_days( $user_id ),
			get_bloginfo( 'name' )
		)
	);
}

/**
 * Diagnostic visible au checkout (admin/débogage) : explique pourquoi la
 * passerelle SEPA différé est ou non disponible pour l'utilisateur connecté.
 */
add_action( 'woocommerce_before_checkout_form', 'annad_sepa_checkout_diagnostic' );

function annad_sepa_checkout_diagnostic() {
	if ( ! ( defined( 'ANNAD_SEPA_DEBUG' ) && ANNAD_SEPA_DEBUG ) || ! current_user_can( 'manage_woocommerce' ) ) {
		return;
	}
	$user_id = get_current_user_id();
	$mandate = $user_id ? annad_sepa_find_saved_mandate( $user_id ) : false;
	echo '<div style="border:2px dashed #d63638;padding:10px;margin-bottom:15px;font-size:12px;">';
	echo '<strong>🔧 Diagnostic checkout SEPA différé</strong><br>';
	echo 'user_id : <code>' . esc_html( $user_id ) . '</code><br>';
	echo 'annad_sepa_is_authorized : <code>' . ( annad_sepa_is_authorized( $user_id ) ? 'oui' : 'non' ) . '</code><br>';
	$active_token_id = get_user_meta( $user_id, ANNAD_SEPA_ACTIVE_TOKEN_META, true );
	echo 'active_token_id (meta) : <code>' . esc_html( $active_token_id ?: '(vide)' ) . '</code><br>';
	if ( $active_token_id && class_exists( 'WC_Payment_Tokens' ) ) {
		$t = WC_Payment_Tokens::get( (int) $active_token_id );
		echo 'ce token est-il "par défaut" côté client : <code>' . ( $t && $t->is_default() ? 'oui' : 'non' ) . '</code><br>';
	}
	echo 'annad_sepa_find_saved_mandate : <code>' . ( $mandate ? esc_html( wp_json_encode( $mandate ) ) : 'false' ) . '</code><br>';
	$gw_settings = get_option( 'woocommerce_' . ANNAD_SEPA_GATEWAY_ID . '_settings', array() );
	echo 'gateway enabled (réglages) : <code>' . esc_html( is_array( $gw_settings ) ? ( $gw_settings['enabled'] ?? '(introuvable)' ) : '(introuvable)' ) . '</code><br>';
	$badge_probe = get_option( 'annad_sepa_badge_probe' );
	echo 'sonde badge "Mes moyens de paiement" : <code>'
		. ( $badge_probe ? esc_html( wp_json_encode( $badge_probe ) ) : 'jamais déclenchée — le filtre woocommerce_saved_payment_methods_list ne s\'applique pas sur cette version' )
		. '</code><br>';

	// Qui filtre les moyens de paiement ? Liste chaque callback accroché à
	// woocommerce_available_payment_gateways avec son fichier:ligne d'origine —
	// permet de localiser un plugin/snippet qui masque ou restreint une passerelle.
	echo '<br><strong>Filtres accrochés à woocommerce_available_payment_gateways :</strong><br>';
	global $wp_filter;
	if ( isset( $wp_filter['woocommerce_available_payment_gateways'] ) ) {
		foreach ( $wp_filter['woocommerce_available_payment_gateways']->callbacks as $priority => $callbacks ) {
			foreach ( $callbacks as $cb ) {
				echo '[priorité ' . esc_html( $priority ) . '] <code>'
					. esc_html( annad_sepa_describe_callback( $cb['function'] ) ) . '</code><br>';
			}
		}
	} else {
		echo '<code>aucun</code><br>';
	}

	// Captures à l'exécution : les 5 derniers chargements du checkout, tous
	// utilisateurs confondus (permet de comparer un compte pro vs admin).
	echo '<br><strong>Captures à l\'exécution du filtre (5 derniers passages checkout) :</strong><br>';
	$probe_history = get_option( 'annad_sepa_gateway_filter_probe', array() );
	if ( is_array( $probe_history ) && $probe_history ) {
		foreach ( array_reverse( $probe_history ) as $capture ) {
			echo '— <strong>' . esc_html( wp_date( 'd/m H:i', (int) $capture['time'], new DateTimeZone( 'Europe/Paris' ) ) ) . '</strong>'
				. ' | user #' . esc_html( $capture['user_id'] )
				. ' (' . esc_html( $capture['roles'] ?: 'aucun rôle' ) . ')'
				. '<br>&nbsp;&nbsp;passerelles finales : <code>' . esc_html( $capture['gateways_finaux'] ?: '(aucune)' ) . '</code><br>';
			foreach ( (array) $capture['callbacks'] as $cb_line ) {
				echo '&nbsp;&nbsp;<code style="font-size:10px;">' . esc_html( $cb_line ) . '</code><br>';
			}
		}
	} else {
		echo '<code>aucune capture — recharger le checkout (avec le compte pro notamment)</code><br>';
	}
	echo '</div>';
}

/**
 * Capture à L'EXÉCUTION du filtre (priorité maximale = passe en dernier) :
 * enregistre qui était accroché à ce moment-là (y compris les plugins qui
 * s'enregistrent tardivement), la liste FINALE des passerelles servies, et
 * l'utilisateur concerné. Conserve les 5 derniers passages front (tous
 * utilisateurs, donc aussi les comptes pro), consultables dans le diagnostic admin.
 */
add_filter( 'woocommerce_available_payment_gateways', 'annad_sepa_capture_gateway_filters', PHP_INT_MAX );

function annad_sepa_capture_gateway_filters( $gateways ) {
	if ( ! ( defined( 'ANNAD_SEPA_DEBUG' ) && ANNAD_SEPA_DEBUG ) || is_admin() ) {
		return $gateways;
	}
	// Ne tracer que le checkout, pour ne pas polluer avec le panier/mini-panier.
	if ( ! function_exists( 'is_checkout' ) || ! is_checkout() ) {
		return $gateways;
	}

	global $wp_filter;
	$callbacks = array();
	if ( isset( $wp_filter['woocommerce_available_payment_gateways'] ) ) {
		foreach ( $wp_filter['woocommerce_available_payment_gateways']->callbacks as $priority => $cbs ) {
			foreach ( $cbs as $cb ) {
				$callbacks[] = '[' . $priority . '] ' . annad_sepa_describe_callback( $cb['function'] );
			}
		}
	}

	$user = wp_get_current_user();

	$history   = get_option( 'annad_sepa_gateway_filter_probe', array() );
	$history   = is_array( $history ) ? $history : array();
	$history[] = array(
		'time'            => time(),
		'user_id'         => $user->ID,
		'roles'           => implode( ', ', (array) $user->roles ),
		'gateways_finaux' => implode( ', ', array_keys( (array) $gateways ) ),
		'callbacks'       => $callbacks,
	);
	update_option( 'annad_sepa_gateway_filter_probe', array_slice( $history, -5 ), false );

	return $gateways;
}

/**
 * Décrit un callback WordPress : nom + fichier:ligne d'origine (via réflexion).
 * Sert au diagnostic pour localiser quel plugin accroche quoi sur un filtre.
 */
function annad_sepa_describe_callback( $callback ) {
	try {
		if ( is_string( $callback ) && function_exists( $callback ) ) {
			$ref  = new ReflectionFunction( $callback );
			$name = $callback . '()';
		} elseif ( $callback instanceof Closure ) {
			$ref  = new ReflectionFunction( $callback );
			$name = '(fonction anonyme)';
		} elseif ( is_array( $callback ) && 2 === count( $callback ) ) {
			$class = is_object( $callback[0] ) ? get_class( $callback[0] ) : (string) $callback[0];
			$ref   = new ReflectionMethod( $class, $callback[1] );
			$name  = $class . '::' . $callback[1] . '()';
		} else {
			return 'callback non identifiable';
		}

		$file = $ref->getFileName();
		// Raccourcir le chemin : ne garder qu'à partir de wp-content/ si possible.
		$pos = strpos( (string) $file, 'wp-content' );
		if ( false !== $pos ) {
			$file = substr( $file, $pos );
		}

		return $name . ' — ' . $file . ':' . $ref->getStartLine();
	} catch ( Exception $e ) {
		return 'callback non analysable (' . $e->getMessage() . ')';
	}
}

/**
 * Retrouve le mandat SEPA à utiliser pour le prélèvement différé d'un client.
 *
 * Double condition stricte (les deux doivent correspondre) :
 *   1) Un admin Annad a choisi CET IBAN comme "mandat actif" (fiche utilisateur) ;
 *   2) Le client a lui-même mis CE MÊME IBAN par défaut de son côté ("Mon compte").
 * Si Annad choisit un IBAN mais que le client en utilise un autre par défaut,
 * ou si Annad n'a rien choisi ("<aucun>"), aucun mandat n'est retourné : la
 * passerelle SEPA différé n'est alors pas proposée.
 *
 * @return array{customer:string,payment_method:string}|false
 */
function annad_sepa_find_saved_mandate( $user_id ) {
	if ( ! $user_id || ! class_exists( 'WC_Payment_Tokens' ) ) {
		return false;
	}

	$active_token_id = get_user_meta( $user_id, ANNAD_SEPA_ACTIVE_TOKEN_META, true );
	if ( ! $active_token_id ) {
		return false; // Annad n'a choisi aucun mandat ("<aucun>").
	}

	$token = WC_Payment_Tokens::get( (int) $active_token_id );
	if ( ! $token || (int) $token->get_user_id() !== (int) $user_id ) {
		return false; // Le mandat choisi par Annad n'existe plus / ne correspond plus au client.
	}

	// Le client doit avoir choisi CE MÊME IBAN comme moyen par défaut de son côté.
	if ( ! $token->is_default() ) {
		return false;
	}

	$pm_id = $token->get_token();
	if ( 0 !== strpos( (string) $pm_id, 'pm_' ) ) {
		return false;
	}

	// Source de vérité : l'API Stripe (à quel customer appartient CE pm_ ?),
	// avec cache lié au pm_ — si le mandat change (nouvel IBAN, changement de
	// compte Stripe), le cache est automatiquement invalidé et re-résolu.
	$customer = annad_sepa_fetch_customer_from_stripe( $pm_id, $user_id );
	if ( ! $customer ) {
		// Repli historique (clé API indisponible, etc.) : metas et commandes locales.
		$customer = annad_sepa_resolve_customer_id( $user_id );
	}
	if ( ! $customer ) {
		return false;
	}

	return array( 'customer' => $customer, 'payment_method' => $pm_id );
}

/**
 * Retrouve via l'API Stripe le customer (cus_...) propriétaire d'un payment
 * method (pm_...). Cache en meta utilisateur, LIÉ AU pm_ : un changement de
 * mandat (ou de compte Stripe) invalide le cache au lieu de servir une valeur
 * périmée d'un autre environnement.
 */
function annad_sepa_fetch_customer_from_stripe( $pm_id, $user_id ) {
	$cached = get_user_meta( $user_id, '_annad_sepa_customer_for_pm', true );
	if ( is_array( $cached )
		&& ( $cached['pm'] ?? '' ) === $pm_id
		&& 0 === strpos( (string) ( $cached['cus'] ?? '' ), 'cus_' )
	) {
		return $cached['cus'];
	}

	$secret_key = annad_sepa_get_secret_key();
	if ( ! $secret_key ) {
		return '';
	}

	$pm = annad_sepa_stripe_request( 'GET', 'payment_methods/' . $pm_id, array(), $secret_key );
	if ( is_wp_error( $pm ) || empty( $pm['customer'] ) || 0 !== strpos( (string) $pm['customer'], 'cus_' ) ) {
		return '';
	}

	$customer = sanitize_text_field( $pm['customer'] );
	update_user_meta( $user_id, '_annad_sepa_customer_for_pm', array(
		'pm'  => $pm_id,
		'cus' => $customer,
	) );

	return $customer;
}

/**
 * Badge sur l'IBAN choisi comme mandat actif, dans "Mon compte → Mes moyens de
 * paiement". Le filtre PHP officiel woocommerce_saved_payment_methods_list ne
 * se déclenche pas sur cette version (vérifié par sonde) : on se base donc sur
 * le HTML réellement affiché plutôt que sur une structure de données devinée —
 * un petit script repère la ligne dont le texte contient les 4 derniers
 * chiffres de l'IBAN actif et y ajoute le badge.
 */
add_action( 'woocommerce_account_payment-methods_endpoint', 'annad_sepa_badge_active_mandate_js', 20 );

function annad_sepa_badge_active_mandate_js() {
	$user_id = get_current_user_id();
	$active_id = $user_id ? get_user_meta( $user_id, ANNAD_SEPA_ACTIVE_TOKEN_META, true ) : '';
	if ( ! $active_id || ! class_exists( 'WC_Payment_Tokens' ) ) {
		return;
	}
	$active_token = WC_Payment_Tokens::get( (int) $active_id );
	if ( ! $active_token || ! method_exists( $active_token, 'get_last4' ) ) {
		return;
	}
	$last4 = $active_token->get_last4();
	if ( ! $last4 ) {
		return;
	}
	?>
	<script>
	( function () {
		var last4 = <?php echo wp_json_encode( $last4 ); ?>;
		document.querySelectorAll( '.payment-method-method, .woocommerce-PaymentMethod--method' ).forEach( function ( cell ) {
			if ( cell.textContent.indexOf( last4 ) !== -1 && cell.textContent.indexOf( '✅' ) === -1 ) {
				cell.innerHTML = '✅ ' + cell.innerHTML.trim() + ' — mandat validé pour le prélèvement différé';
			}
		} );
	} )();
	</script>
	<?php
}

/**
 * Texte explicatif sous le tableau "Mes moyens de paiement" : le client ne
 * peut pas changer lui-même son mandat SEPA différé, il doit passer par Annad.
 */
add_action( 'woocommerce_account_payment-methods_endpoint', 'annad_sepa_account_payment_methods_footer_note', 20 );

function annad_sepa_account_payment_methods_footer_note() {
	$user_id = get_current_user_id();

	$default = $user_id ? annad_sepa_get_default_sepa_token( $user_id ) : null;
	$active  = $user_id ? (int) get_user_meta( $user_id, ANNAD_SEPA_ACTIVE_TOKEN_META, true ) : 0;

	if ( $user_id && annad_sepa_is_authorized( $user_id ) ) {
		// État 3 — IBAN validé et actif : le badge ✅ figure déjà sur la ligne de
		// l'IBAN, on affiche l'information de délai applicable.
		echo '<p style="padding-top:10px;">Votre prélèvement SEPA différé est déclenché <strong>'
			. esc_html( annad_sepa_get_delay_days( $user_id ) )
			. ' jours</strong> après l\'expédition de votre commande (comptez ensuite 2-3 jours ouvrés de délai bancaire).</p>';
	} elseif ( $default && (int) $default->get_id() !== $active ) {
		// État 2 — un IBAN par défaut est soumis et PAS ENCORE validé par Annad.
		echo '<p style="padding-top:10px;">✅ Votre IBAN a bien été soumis. '
			. 'Nous vous confirmerons sa validation par e-mail sous 72h.</p>';
	} else {
		// État 1 — aucun IBAN en attente : inviter à en soumettre un.
		echo '<p style="padding-top:10px;">Paiement SEPA : cliquez ci-dessus sur '
			. '« Ajouter un moyen de paiement » pour soumettre votre IBAN. '
			. 'Nous vous confirmerons sa validation par e-mail sous 72h.</p>';
	}

	// Renomme « Utiliser par défaut » en « Soumettre à validation » sur les lignes
	// IBAN/SEPA uniquement (les cartes éventuelles gardent le libellé standard) :
	// le clic sur ce bouton EST la soumission dans notre flux de validation.
	?>
	<script>
	( function () {
		document.querySelectorAll( '.account-payment-methods-table tr.payment-method' ).forEach( function ( row ) {
			var method = row.querySelector( '.payment-method-method' );
			var btn    = row.querySelector( '.payment-method-actions .button.default' );
			if ( method && btn && /sepa|iban/i.test( method.textContent ) ) {
				btn.textContent = 'Soumettre à validation';
			}
		} );
	} )();
	</script>
	<?php
}

/**
 * Retrouve l'ID customer Stripe (cus_...) d'un utilisateur.
 */
function annad_sepa_resolve_customer_id( $user_id ) {
	// NB : l'ancienne clé de cache '_annad_sepa_customer_id' (v1.6.3) a été retirée
	// de cette liste — elle pouvait servir une valeur d'un autre compte Stripe.
	foreach ( array( '_stripe_customer_id', 'stripe_customer_id', 'wp_stripe_customer_id' ) as $meta_key ) {
		$val = get_user_meta( $user_id, $meta_key, true );
		if ( $val && 0 === strpos( (string) $val, 'cus_' ) ) {
			return $val;
		}
	}
	// Repli via les commandes : cibler directement celles qui PORTENT la meta,
	// et non les N dernières aveuglément — sinon une série de commandes de test
	// (CB, annulées…) sans _stripe_customer_id suffit à faire échouer la
	// résolution alors qu'une commande SEPA plus ancienne l'a bien.
	$orders = wc_get_orders( array(
		'customer_id'  => $user_id,
		'limit'        => 1,
		'orderby'      => 'date',
		'order'        => 'DESC',
		'meta_key'     => '_stripe_customer_id',
		'meta_compare' => 'EXISTS',
	) );
	foreach ( $orders as $o ) {
		$val = $o->get_meta( '_stripe_customer_id' );
		if ( $val && 0 === strpos( (string) $val, 'cus_' ) ) {
			return $val;
		}
	}
	return '';
}

/**
 * Description de la passerelle au checkout : remplace « quelques jours » (ou le
 * jeton {delai}) par le délai réel applicable AU CLIENT CONNECTÉ + 2 jours de
 * délai bancaire — ex. « … 10 jours après l'expédition de votre commande. »
 */
add_filter( 'woocommerce_gateway_description', 'annad_sepa_dynamic_gateway_description', 10, 2 );

function annad_sepa_dynamic_gateway_description( $description, $gateway_id ) {
	if ( ANNAD_SEPA_GATEWAY_ID !== $gateway_id || is_admin() ) {
		return $description;
	}

	$days = annad_sepa_get_delay_days( get_current_user_id() ) + 2;

	$description = str_replace( '{delai}', $days, $description );
	$description = str_replace( 'quelques jours', $days . ' jours', $description );

	return $description;
}

/* ============================================================
 * 4sexies. MASQUER LA PASSERELLE SEPA NATIVE AU CHECKOUT (mode classique UPE)
 * ============================================================
 *
 * En mode "Optimized Checkout" désactivé, Stripe enregistre le SEPA natif comme
 * une passerelle WooCommerce à part entière (stripe_sepa_debit / stripe_sepa),
 * au même titre que notre passerelle "annad_sepa_deferred". On peut donc la
 * retirer avec le filtre STANDARD de WooCommerce (indépendant du code interne
 * de Stripe), uniquement au checkout — jamais sur "Mon compte", où le client
 * doit toujours pouvoir enregistrer un mandat.
 */

add_filter( 'woocommerce_available_payment_gateways', 'annad_sepa_hide_native_sepa_gateway' );

function annad_sepa_hide_native_sepa_gateway( $gateways ) {
	if ( is_admin() ) {
		return $gateways;
	}
	if ( function_exists( 'is_add_payment_method_page' ) && is_add_payment_method_page() ) {
		return $gateways; // Toujours disponible pour enregistrer un mandat.
	}
	if ( ! function_exists( 'is_checkout' ) || ! is_checkout() ) {
		return $gateways;
	}

	unset( $gateways['stripe_sepa_debit'] );
	unset( $gateways['stripe_sepa'] );

	return $gateways;
}

/* ============================================================
 * 4quater. WEBHOOK STRIPE DÉDIÉ — FINALISATION / ÉCHEC / LITIGE
 * ============================================================
 *
 * SEPA est asynchrone : le prélèvement déclenché à J+8 part en « processing »
 * puis réussit ou échoue quelques jours plus tard. Ce webhook écoute ces
 * événements et met la commande à jour. Il ne traite QUE nos propres prélèvements
 * (passerelle annad_sepa_deferred / metadata source=annad-sepa-differe), donc il
 * n'interfère pas avec le webhook du plugin Stripe officiel.
 *
 * Endpoint : /wp-json/annad-sepa/v1/webhook
 */

add_action( 'rest_api_init', function () {
	register_rest_route( 'annad-sepa/v1', '/webhook', array(
		'methods'             => 'POST',
		'callback'            => 'annad_sepa_webhook_handler',
		'permission_callback' => '__return_true', // Sécurité assurée par la signature Stripe.
	) );
} );

function annad_sepa_webhook_handler( WP_REST_Request $request ) {
	$payload = $request->get_body();

	if ( ! annad_sepa_verify_webhook( $payload, $request->get_header( 'stripe-signature' ) ) ) {
		return new WP_REST_Response( 'invalid signature', 400 );
	}

	$event = json_decode( $payload, true );
	if ( ! is_array( $event ) ) {
		return new WP_REST_Response( 'bad payload', 400 );
	}

	$type = $event['type'] ?? '';
	$obj  = $event['data']['object'] ?? array();

	$order = annad_sepa_resolve_webhook_order( $obj );
	if ( ! $order ) {
		return new WP_REST_Response( 'no matching order', 200 );
	}

	// Ne traiter que NOS prélèvements (garde-fou anti-interférence).
	$is_ours = ( ANNAD_SEPA_GATEWAY_ID === $order->get_payment_method() )
		|| ( 'annad-sepa-differe' === ( $obj['metadata']['source'] ?? '' ) );
	if ( ! $is_ours ) {
		return new WP_REST_Response( 'not ours', 200 );
	}

	switch ( $type ) {
		case 'payment_intent.succeeded':
			if ( ! $order->is_paid() ) {
				$order->payment_complete( $obj['id'] ?? '' );
			}
			$order->add_order_note( '✅ SEPA différé : prélèvement encaissé avec succès (webhook Stripe).' );
			break;

		case 'payment_intent.payment_failed':
			$reason = $obj['last_payment_error']['message'] ?? 'raison non précisée';
			$order->update_status( 'failed', '❌ SEPA différé : prélèvement ÉCHOUÉ (webhook Stripe) — ' . $reason );
			break;

		case 'charge.dispute.created':
			$order->add_order_note( '⚠️ SEPA différé : LITIGE / contestation ouvert sur ce prélèvement (webhook Stripe). Vérifier le dashboard.' );
			break;

		default:
			// Événement non géré : accusé de réception pour éviter les relances Stripe.
			break;
	}

	return new WP_REST_Response( 'ok', 200 );
}

/**
 * Retrouve la commande liée à l'objet Stripe reçu :
 *   1) via metadata.order_id (posé par la Stratégie B) ;
 *   2) sinon via l'ID de PaymentIntent stocké dans _stripe_intent_id.
 */
function annad_sepa_resolve_webhook_order( $obj ) {
	$order_id = absint( $obj['metadata']['order_id'] ?? 0 );
	if ( $order_id ) {
		$order = wc_get_order( $order_id );
		if ( $order ) {
			return $order;
		}
	}

	// Pour un PaymentIntent, l'id est dans $obj['id'] ; pour un litige, dans $obj['payment_intent'].
	$pi = $obj['payment_intent'] ?? ( $obj['id'] ?? '' );
	if ( $pi && 0 === strpos( (string) $pi, 'pi_' ) ) {
		$orders = wc_get_orders( array(
			'limit'      => 1,
			'meta_key'   => '_stripe_intent_id',
			'meta_value' => $pi,
			'orderby'    => 'date',
			'order'      => 'DESC',
		) );
		if ( $orders ) {
			return $orders[0];
		}
	}

	return false;
}

/**
 * Vérifie la signature d'un webhook Stripe (schéma v1, sans SDK).
 */
function annad_sepa_verify_webhook( $payload, $sig_header ) {
	$secret = ANNAD_SEPA_WEBHOOK_SECRET;
	if ( ! $secret || ! $sig_header || ! $payload ) {
		return false;
	}

	$parts = array();
	foreach ( explode( ',', $sig_header ) as $pair ) {
		$kv = explode( '=', $pair, 2 );
		if ( 2 === count( $kv ) ) {
			$parts[ trim( $kv[0] ) ] = trim( $kv[1] );
		}
	}

	$timestamp = $parts['t'] ?? '';
	$signature = $parts['v1'] ?? '';
	if ( ! $timestamp || ! $signature ) {
		return false;
	}

	// Tolérance de 5 minutes contre le rejeu.
	if ( abs( time() - (int) $timestamp ) > 300 ) {
		return false;
	}

	$expected = hash_hmac( 'sha256', $timestamp . '.' . $payload, $secret );

	return hash_equals( $expected, $signature );
}

/* ============================================================
 * 4quinquies. MASQUAGE DU SEPA NATIF STRIPE AU CHECKOUT (UNIQUEMENT)
 * ============================================================
 *
 * La case « SEPA Direct Debit » des réglages Stripe pilote À LA FOIS le checkout
 * ET la page « Mon compte → Moyens de paiement ». On veut garder le SEPA actif
 * pour "Mon compte" (mandat signé côté client), mais ne plus le proposer comme
 * choix concurrent au checkout.
 *
 * Point d'accroche confirmé en lisant le code source officiel du plugin
 * (includes/payment-methods/class-wc-stripe-upe-payment-gateway.php,
 * méthode javascript_params()) : juste avant d'envoyer les paramètres au
 * JavaScript qui construit l'élément de paiement, le plugin applique
 * apply_filters( 'wc_stripe_upe_params', $this->javascript_params() ).
 * Le tableau contient paymentMethodsConfig, keyé par identifiant de moyen de
 * paiement (ex. 'card', 'sepa_debit') — on retire 'sepa_debit' de cette liste,
 * uniquement au checkout (jamais sur "Mon compte → Ajouter un moyen de paiement").
 */

add_filter( 'wc_stripe_upe_params', 'annad_sepa_hide_sepa_from_upe_params' );

function annad_sepa_hide_sepa_from_upe_params( $params ) {
	if ( ! is_array( $params ) || ! function_exists( 'is_checkout' ) || ! is_checkout() ) {
		return $params;
	}
	if ( function_exists( 'is_add_payment_method_page' ) && is_add_payment_method_page() ) {
		return $params; // Ne jamais toucher à "Mon compte → Ajouter un moyen de paiement".
	}
	if ( empty( $params['paymentMethodsConfig'] ) || ! is_array( $params['paymentMethodsConfig'] ) ) {
		return $params;
	}

	// Mode classique : une clé par moyen de paiement (ex. 'sepa_debit').
	unset( $params['paymentMethodsConfig']['sepa_debit'] );

	// Mode "Optimized Checkout" : tout est fusionné sous une seule clé ('card'),
	// dont la sous-liste 'enabledPaymentMethods' pilote réellement l'affichage.
	foreach ( $params['paymentMethodsConfig'] as $method_id => &$config ) {
		if ( isset( $config['enabledPaymentMethods'] ) && is_array( $config['enabledPaymentMethods'] ) ) {
			$config['enabledPaymentMethods'] = array_values(
				array_diff( $config['enabledPaymentMethods'], array( 'sepa_debit' ) )
			);
		}
	}
	unset( $config );

	return $params;
}

/* ============================================================
 * 4octies. RAPPORT « PRÉLÈVEMENTS SEPA À VENIR »
 * ============================================================
 *
 * Onglet dans WooCommerce → Rapports → Commandes : liste les prélèvements
 * différés pas encore débités, pour anticiper la trésorerie entrante.
 * Deux populations :
 *   - commandes « Terminé » avec prélèvement PLANIFIÉ (date connue) ;
 *   - commandes payées via la passerelle mais pas encore expédiées
 *     (date estimée seulement, dépend du passage en « Terminé »).
 * Les montants et dates de réception sont des ESTIMATIONS (délai bancaire
 * SEPA ~2-3 jours ouvrés, non vérifiable ici).
 */

add_filter( 'woocommerce_admin_reports', 'annad_sepa_register_report' );

function annad_sepa_register_report( $reports ) {
	if ( isset( $reports['orders'] ) ) {
		$reports['orders']['reports']['annad_sepa_upcoming'] = array(
			'title'       => 'Prélèvements SEPA à venir',
			'description' => '',
			'hide_title'  => true,
			'callback'    => 'annad_sepa_render_upcoming_report',
		);
	}
	return $reports;
}

/**
 * Ajoute N jours OUVRÉS (lun-ven) à un timestamp — pour estimer la date de
 * réception du crédit après le déclenchement du prélèvement.
 */
function annad_sepa_add_business_days( $timestamp, $days ) {
	while ( $days > 0 ) {
		$timestamp += DAY_IN_SECONDS;
		if ( (int) wp_date( 'N', $timestamp ) < 6 ) {
			$days--;
		}
	}
	return $timestamp;
}

function annad_sepa_render_upcoming_report() {
	$rows = array();

	// Option : inclure aussi les commandes pas encore expédiées (masquées par défaut).
	$show_pending = ! empty( $_GET['annad_sepa_show_pending'] ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended

	// 1) Prélèvements planifiés (commande « Terminé », date de déclenchement connue).
	$scheduled_orders = wc_get_orders( array(
		'limit'        => -1,
		'meta_key'     => '_annad_sepa_scheduled_ts',
		'meta_compare' => 'EXISTS',
	) );
	foreach ( $scheduled_orders as $order ) {
		$ts = (int) $order->get_meta( '_annad_sepa_scheduled_ts' );
		if ( ! $ts || 'yes' === $order->get_meta( '_annad_sepa_done' ) ) {
			continue;
		}
		$rows[ $order->get_id() ] = array(
			'order'     => $order,
			'charge_ts' => $ts,
		);
	}

	// 2) Optionnel : commandes payées via la passerelle, pas encore expédiées.
	if ( $show_pending ) {
		$pending_orders = wc_get_orders( array(
			'limit'          => -1,
			'status'         => array( 'on-hold', 'processing' ),
			'payment_method' => ANNAD_SEPA_GATEWAY_ID,
		) );
		foreach ( $pending_orders as $order ) {
			if ( isset( $rows[ $order->get_id() ] ) || 'yes' === $order->get_meta( '_annad_sepa_done' ) ) {
				continue;
			}
			$rows[ $order->get_id() ] = array(
				'order'     => $order,
				'charge_ts' => 0, // Pas encore planifié : dépend du passage en « Terminé ».
			);
		}
	}

	// Tri : planifiés d'abord (par date de prélèvement croissante), puis non planifiés.
	uasort( $rows, function ( $a, $b ) {
		if ( $a['charge_ts'] && $b['charge_ts'] ) {
			return $a['charge_ts'] <=> $b['charge_ts'];
		}
		if ( $a['charge_ts'] ) {
			return -1;
		}
		if ( $b['charge_ts'] ) {
			return 1;
		}
		return $b['order']->get_id() <=> $a['order']->get_id();
	} );

	$total = 0;
	foreach ( $rows as $row ) {
		$total += (float) $row['order']->get_total();
	}

	echo '<div style="padding:20px;">';
	echo '<h3>Prélèvements SEPA à venir</h3>';
	echo '<p style="font-size:15px;">Montant total attendu : '
		. '<strong style="font-size:1.4em;color:#1a7a2e;">' . wp_kses_post( wc_price( $total ) ) . '</strong>'
		. ' (' . count( $rows ) . ' commande' . ( count( $rows ) > 1 ? 's' : '' ) . ')</p>';

	// Case à cocher : afficher aussi les commandes pas encore expédiées.
	echo '<form method="get" style="margin:10px 0;">';
	// Conserver les paramètres de la page de rapports.
	foreach ( array( 'page', 'tab', 'report' ) as $param ) {
		if ( isset( $_GET[ $param ] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			echo '<input type="hidden" name="' . esc_attr( $param ) . '" value="'
				. esc_attr( sanitize_text_field( wp_unslash( $_GET[ $param ] ) ) ) . '">'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		}
	}
	echo '<label><input type="checkbox" name="annad_sepa_show_pending" value="1" '
		. checked( $show_pending, true, false ) . ' onchange="this.form.submit();"> '
		. 'Afficher aussi les commandes « en attente » (payées en SEPA différé, pas encore expédiées)</label>';
	echo '</form>';

	echo '<p class="description">Estimations : la date de réception suppose un délai bancaire SEPA de '
		. '~3 jours ouvrés après le déclenchement du prélèvement, et n\'est pas vérifiable depuis WooCommerce.'
		. ( $show_pending ? ' Les commandes non expédiées n\'ont pas encore de date — le délai court à partir du passage en « Terminé ».' : '' )
		. '</p>';

	if ( ! $rows ) {
		echo '<p>Aucun prélèvement en attente. 🎉</p></div>';
		return;
	}

	echo '<table class="widefat striped" style="max-width:950px;">';
	echo '<thead><tr>'
		. '<th>Commande</th>'
		. '<th>Date de commande</th>'
		. '<th>Montant</th>'
		. '<th>Délai</th>'
		. '<th>Date Prélèvement</th>'
		. '<th>Date (estimée)</th>'
		. '</tr></thead><tbody>';

	foreach ( $rows as $row ) {
		$order = $row['order'];
		$delay = annad_sepa_get_delay_days( $order->get_user_id() );
		$url   = $order->get_edit_order_url();

		echo '<tr>';
		echo '<td><a href="' . esc_url( $url ) . '"><strong>#' . esc_html( $order->get_order_number() ) . '</strong></a><br>'
			. '<span style="color:#777;">' . esc_html( wc_get_order_status_name( $order->get_status() ) ) . '</span></td>';
		echo '<td>' . esc_html( $order->get_date_created() ? $order->get_date_created()->date_i18n( 'd/m/Y' ) : '—' ) . '</td>';
		echo '<td><strong style="font-size:1.15em;color:#1a7a2e;">' . wp_kses_post( wc_price( $order->get_total() ) ) . '</strong></td>';
		echo '<td>J+' . esc_html( $delay ) . '</td>';

		if ( $row['charge_ts'] ) {
			$credit_ts = annad_sepa_add_business_days( $row['charge_ts'], 3 );
			echo '<td>' . esc_html( annad_sepa_format_date( $row['charge_ts'] ) ) . '</td>';
			echo '<td><strong style="background:#fff3cd;padding:2px 6px;border-radius:3px;">'
				. esc_html( wp_date( 'd/m/Y', $credit_ts, new DateTimeZone( 'Europe/Paris' ) ) ) . '</strong></td>';
		} else {
			echo '<td><em>En attente d\'expédition</em></td>';
			echo '<td><em>~ expédition + ' . esc_html( $delay ) . ' j + 3 j ouvrés</em></td>';
		}

		echo '</tr>';
	}

	echo '</tbody></table></div>';
}

/* ============================================================
 * 5. NETTOYAGE
 * ============================================================ */

// Annule la planification si la commande est annulée ou remboursée entre-temps.
add_action( 'woocommerce_order_status_cancelled', 'annad_sepa_cancel_schedule' );
add_action( 'woocommerce_order_status_refunded', 'annad_sepa_cancel_schedule' );

function annad_sepa_cancel_schedule( $order_id ) {
	$order = wc_get_order( $order_id );
	if ( ! $order || ! $order->get_meta( '_annad_sepa_scheduled_ts' ) ) {
		return;
	}
	annad_sepa_unschedule( $order_id );
	$order->delete_meta_data( '_annad_sepa_scheduled_ts' );
	$order->save();
	$order->add_order_note( 'SEPA différé : planification annulée (commande annulée/remboursée).' );
}
