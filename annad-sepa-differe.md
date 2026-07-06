# Annad — Prélèvement SEPA Stripe différé (J+8 après expédition)

> **Document de spécification + code de base** destiné à être finalisé et débogué sous Claude Code.
> Site cible : **annad.fr** (WooCommerce + plugin officiel *WooCommerce Stripe Payment Gateway*).
> Auteur du besoin : Hugo Vial-Jaime / Dahu-Concept.

---

## 1. Contexte et objectif

annad.fr vend en BtoB (kits d'électrification de vélo, solutions composites sur mesure). Certains clients professionnels paient par **prélèvement SEPA via Stripe** (mandat déjà signé).

**Problème actuel** : à chaque expédition, le prélèvement doit être déclenché **manuellement** depuis le dashboard Stripe.

**Objectif** : automatiser entièrement ce déclenchement, avec un délai de grâce de 8 jours, selon ce flux :

```
J1     → Le client passe commande (mandat SEPA signé / existant)
J4     → La commande passe en statut "Terminé" (= expédiée) dans WooCommerce
         → une tâche planifiée est créée à J+8 (soit J12)
J12    → La tâche se déclenche → confirmation du PaymentIntent Stripe
J14-15 → Prélèvement effectif sur le compte du client (délai bancaire SEPA ~2-3 j ouvrés)
```

**Objectif secondaire** : depuis la fiche commande WooCommerce (admin), pouvoir **repousser la date de déclenchement** en saisissant une **nouvelle date calendaire** (ex. passer du 12/04 au 18/04). La tâche planifiée existante est alors replanifiée.

**Hors périmètre** :
- Les paiements CB et toute autre méthode que SEPA → **ignorés silencieusement**.
- Avancer la date (seulement la retarder — mais techniquement le champ date accepte n'importe quelle date future, ce qui est acceptable).
- GoCardless (écarté : le client veut rester sous Stripe).

---

## 2. Contraintes techniques Stripe SEPA — À LIRE AVANT DE CODER

Ces points conditionnent l'architecture. Ils ont été vérifiés dans la documentation Stripe (docs.stripe.com/payments/sepa-debit) :

1. **Pas de `charge_date` chez Stripe.** Contrairement à GoCardless, l'API Stripe ne permet pas de programmer une date de prélèvement future. Le prélèvement part dès la confirmation du PaymentIntent. D'où l'approche : **retarder la confirmation** via une tâche planifiée côté WordPress.

2. **SEPA est asynchrone.** Après confirmation, le PaymentIntent passe en `processing` pendant plusieurs jours (Stripe recommande d'attendre ~6 jours ouvrés avant de considérer le paiement réussi). Le webhook Stripe (déjà configuré par le plugin officiel) mettra à jour la commande quand le paiement aboutit ou échoue.

3. **SEPA ne supporte PAS le mécanisme authorize/capture des cartes.** On ne peut pas "autoriser à la commande puis capturer plus tard" comme avec une CB. Deux stratégies possibles :

   - **Stratégie A — Confirmation différée du PaymentIntent existant** : à la commande, le PaymentIntent est créé mais reste en `requires_confirmation`. On le confirme à J+8. ⚠️ Risque : le comportement du plugin officiel au checkout confirme peut-être le PI immédiatement (selon version et checkout Legacy/UPE). À vérifier en environnement réel.

   - **Stratégie B — Nouveau PaymentIntent off-session à J+8 (RECOMMANDÉE, plus robuste)** : à la commande, on laisse le mandat/payment method être sauvegardé, on empêche le débit immédiat, puis à J+8 on **crée et confirme un nouveau PaymentIntent off-session** en utilisant le customer + payment method SEPA sauvegardés. SEPA est une méthode *réutilisable* chez Stripe : les transactions initiées par le marchand (MIT) off-session sont supportées via le mandat.

   Le code fourni ci-dessous implémente la **Stratégie B avec fallback A** : si un PaymentIntent existant est en `requires_confirmation`, on le confirme ; sinon on crée un PI off-session.

4. **Pré-notification client.** Les règles SEPA imposent de notifier le débiteur avant prélèvement. Quand on utilise le Creditor ID de Stripe, **Stripe envoie automatiquement les emails de notification de débit**. Le mandat Stripe prévoit une notification jusqu'à 2 jours calendaires avant les paiements futurs → le J+8 est conforme. Rien à coder, mais vérifier dans le dashboard Stripe que les emails de notification sont actifs (Settings → Emails).

5. **Identifiants de méthode de paiement dans le plugin officiel** (varie selon checkout Legacy vs New/UPE) :
   - Legacy checkout : `stripe_sepa`
   - New checkout (UPE) : `stripe_sepa_debit`
   → Le code teste les deux.

6. **Meta keys du plugin officiel** (à vérifier sous Claude Code selon la version installée) :
   - `_stripe_intent_id` : ID du PaymentIntent (pi_...)
   - `_stripe_customer_id` : ID du customer Stripe (cus_...)
   - `_stripe_source_id` : ID du payment method / source (pm_... ou src_...)

7. **HPOS (High-Performance Order Storage).** Le code doit être compatible HPOS : toujours passer par `wc_get_order()`, `$order->get_meta()`, `$order->update_meta_data()` + `$order->save()`, et déclarer la compatibilité via `FeaturesUtil`. Le metabox doit être enregistré pour les deux écrans (legacy `shop_order` et HPOS `woocommerce_page_wc-orders`).

8. **Fiabilité de la planification.** WP-Cron ne se déclenche qu'à la visite du site → risque de dérive de plusieurs heures. **WooCommerce embarque Action Scheduler**, beaucoup plus fiable et visible dans l'admin (WooCommerce → Statut → Action Scheduler). Le code utilise **Action Scheduler en priorité** (`as_schedule_single_action`), avec fallback WP-Cron si indisponible.

---

## 3. Architecture de l'extension

```
annad-sepa-differe/
└── annad-sepa-differe.php      (fichier unique, autonome)
```

Composants :

| Composant | Rôle |
|---|---|
| Hook `woocommerce_order_status_completed` | Détecte le passage en "Terminé", planifie l'action à J+8 |
| Action planifiée `annad_sepa_confirm_payment` | Exécute la confirmation Stripe à la date prévue |
| Metabox admin commande | Affiche la date planifiée + champ date calendaire pour replanifier |
| Fonction `annad_sepa_do_confirm()` | Logique API Stripe (Stratégie B + fallback A) |
| Notes de commande | Journalise chaque étape (planification, replanification, succès, erreur) |

Métadonnées de commande utilisées par l'extension :

| Meta key | Contenu |
|---|---|
| `_annad_sepa_scheduled_ts` | Timestamp UTC de la confirmation planifiée |
| `_annad_sepa_done` | `yes` une fois la confirmation effectuée (anti-doublon) |

---

## 4. Code de base

> ⚠️ **À finaliser sous Claude Code** : voir la checklist §5 et le plan de test §6.

```php
<?php
/**
 * Plugin Name: Annad — Prélèvement SEPA Stripe différé
 * Description: Déclenche automatiquement le prélèvement SEPA Stripe 8 jours après le passage d'une commande en "Terminé". Date modifiable depuis la fiche commande.
 * Version:     0.9.0
 * Author:      Dahu-Concept
 * Requires Plugins: woocommerce
 * Text Domain: annad-sepa-differe
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/* ============================================================
 * CONFIGURATION
 * ============================================================ */

// Délai en jours entre le statut "Terminé" et la confirmation Stripe.
define( 'ANNAD_SEPA_DELAY_DAYS', 8 );

// IDs de gateway SEPA du plugin officiel WooCommerce Stripe (legacy + UPE).
define( 'ANNAD_SEPA_GATEWAYS', array( 'stripe_sepa', 'stripe_sepa_debit' ) );

// Hook de l'action planifiée.
define( 'ANNAD_SEPA_HOOK', 'annad_sepa_confirm_payment' );

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
	if ( $order->is_paid() ) {
		$order->add_order_note( 'SEPA différé : commande déjà payée, aucune planification.' );
		return;
	}

	$timestamp = time() + ( ANNAD_SEPA_DELAY_DAYS * DAY_IN_SECONDS );

	annad_sepa_schedule_at( $order, $timestamp );

	$order->add_order_note( sprintf(
		'SEPA différé : prélèvement planifié le %s (J+%d).',
		annad_sepa_format_date( $timestamp ),
		ANNAD_SEPA_DELAY_DAYS
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
	if ( $order->is_paid() ) {
		$order->add_order_note( 'SEPA différé : commande déjà payée au moment du déclenchement. Aucune action.' );
		annad_sepa_mark_done( $order );
		return;
	}

	$secret_key = annad_sepa_get_secret_key();
	if ( ! $secret_key ) {
		$order->add_order_note( '❌ SEPA différé : clé secrète Stripe introuvable. Prélèvement NON déclenché — intervention manuelle requise.' );
		return;
	}

	/* ---- Fallback A : PaymentIntent existant confirmable ? ---- */
	$intent_id = $order->get_meta( '_stripe_intent_id' );
	if ( $intent_id ) {
		$intent = annad_sepa_stripe_request( 'GET', 'payment_intents/' . $intent_id, array(), $secret_key );

		if ( ! is_wp_error( $intent ) && isset( $intent['status'] ) ) {
			if ( 'requires_confirmation' === $intent['status'] ) {
				$confirmed = annad_sepa_stripe_request( 'POST', 'payment_intents/' . $intent_id . '/confirm', array(), $secret_key );
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
		'description'          => sprintf( 'Commande #%s — annad.fr', $order->get_order_number() ),
		'metadata'             => array(
			'order_id'     => (string) $order->get_id(),
			'order_number' => (string) $order->get_order_number(),
			'site_url'     => home_url(),
			'source'       => 'annad-sepa-differe',
		),
	);

	$result = annad_sepa_stripe_request( 'POST', 'payment_intents', $body, $secret_key );
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
		$order->add_order_note(
			'❌ SEPA différé (' . $context . ') : erreur Stripe — ' .
			esc_html( $result['error']['message'] ?? 'inconnue' )
		);
		return;
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

	if ( 'yes' === $order->get_meta( '_annad_sepa_done' ) ) {
		echo '<p>✅ Prélèvement déjà déclenché.</p>';
		return;
	}

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
 * Requête générique vers l'API Stripe (form-encoded, comme attendu par Stripe).
 */
function annad_sepa_stripe_request( $method, $endpoint, $body, $secret_key ) {
	$args = array(
		'method'  => $method,
		'timeout' => 45,
		'headers' => array(
			'Authorization'  => 'Bearer ' . $secret_key,
			'Stripe-Version' => '2024-06-20', // À vérifier / aligner avec la version du compte.
		),
	);

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
```

---

## 5. Checklist de finalisation sous Claude Code

Points à **vérifier et corriger** en environnement réel (le code ci-dessus est une base fonctionnelle, pas testée sur annad.fr) :

1. **ID exact de la gateway SEPA** sur annad.fr : passer une commande test SEPA et lire `$order->get_payment_method()` (ou la colonne « Moyen de paiement »). Ajuster `ANNAD_SEPA_GATEWAYS` si besoin.

2. **Comportement du plugin officiel au checkout SEPA** : vérifier le statut du PaymentIntent juste après une commande test (dashboard Stripe). S'il est déjà en `processing` dès le checkout, la Stratégie A est inutilisable pour les nouvelles commandes → il faudra en plus **empêcher la confirmation immédiate au checkout** (filtre côté plugin Stripe, ex. intercepter la requête de création du PI pour retirer `confirm=true`, ou configurer la gateway pour sauvegarder le payment method sans débiter). C'est LE point le plus sensible du projet.
   - Piste : filtre `wc_stripe_generate_create_intent_request` (legacy) ou équivalent UPE pour forcer `confirm => false` sur les commandes SEPA.
   - Alternative simple : accepter que le client soit débité immédiatement sur la *première* commande, et n'appliquer le différé qu'aux commandes suivantes (payment method déjà sauvegardé, on peut bloquer le paiement au checkout via une gateway custom "SEPA différé" qui ne débite pas). À discuter.

3. **Meta keys réels** : vérifier `_stripe_intent_id`, `_stripe_customer_id`, `_stripe_source_id` sur une commande test (via un plugin de type "Order meta inspector" ou WP-CLI). Les noms varient selon la version du plugin Stripe.

4. **Vérifier que le payment method SEPA est bien sauvegardé** (réutilisable off-session). Dans le dashboard Stripe : Customers → le client → Payment methods. Si le PM n'est pas attaché au customer, la Stratégie B échouera avec une erreur explicite.

5. **Webhooks** : vérifier que le webhook du plugin officiel traite bien les événements du **nouveau** PaymentIntent créé en Stratégie B (le code stocke le nouvel ID dans `_stripe_intent_id` pour ça). Tester `payment_intent.succeeded` et `payment_intent.payment_failed`.

6. **Version d'API Stripe** (`Stripe-Version` header) : aligner sur la version du compte Stripe d'annad.fr, ou supprimer le header pour utiliser la version par défaut du compte.

7. **Sauvegarde metabox HPOS** : vérifier que `woocommerce_process_shop_order_meta` se déclenche bien sur l'écran HPOS d'annad.fr. Sinon ajouter le hook `woocommerce_update_order` avec garde-fou nonce.

8. **Comportement du statut "Terminé"** : le hook réagit à *tout* passage en "Terminé", y compris re-passage après un autre statut. L'anti-doublon (`_annad_sepa_scheduled_ts` déjà présent) couvre ce cas — vérifier que c'est le comportement voulu quand une commande fait Terminé → En attente → Terminé.

9. **i18n** : les chaînes sont en dur en français (choix assumé, site FR). Wrapper en `__()` si distribution prévue un jour.

## 6. Plan de test (mode test Stripe)

1. Activer le mode test dans WooCommerce → Réglages → Paiements → Stripe.
2. Passer une commande avec l'IBAN de test Stripe : `AT611904300234573201` (succès) — nom + email quelconques.
3. Vérifier dans le dashboard Stripe (mode test) le statut du PaymentIntent après checkout → **noter le statut** (conditionne le point 2 de la checklist).
4. Passer la commande en « Terminé » → vérifier :
   - note de commande « prélèvement planifié le … » ;
   - la tâche visible dans **WooCommerce → Statut → Action Scheduler** (rechercher `annad_sepa_confirm_payment`).
5. Tester la **replanification** : metabox → nouvelle date → Enregistrer → vérifier la note + la nouvelle tâche dans Action Scheduler.
6. Test d'exécution immédiate sans attendre 8 jours : dans Action Scheduler, bouton **« Run »** sur la tâche → vérifier la note ✅ et le PaymentIntent `processing` dans Stripe.
7. Tester le cas d'échec avec l'IBAN de test « insufficient funds » : `AT861904300235473202` → vérifier que le webhook fait échouer la commande proprement.
8. Tester une commande **CB** : vérifier qu'absolument rien ne se passe (ni note, ni tâche).
9. Tester l'annulation d'une commande planifiée → vérifier que la tâche disparaît d'Action Scheduler.

## 7. Rappels métier

- **Frais Stripe SEPA** : 0,8 % plafonné à 5 € par transaction.
- **Plafond** : 10 000 € par transaction SEPA (+ limite hebdo de 10 000 € pour les nouveaux comptes, augmente avec l'historique — contacter Stripe si besoin).
- **Litiges** : en SEPA Core, le client peut contester « sans question » jusqu'à 8 semaines après débit (13 mois si non autorisé). À garder en tête pour le BtoB.
- **Pré-notification** : gérée automatiquement par Stripe (emails de notification de débit). Vérifier qu'ils sont activés dans le dashboard.
- Le prélèvement étant **initié** à la date planifiée, le **débit effectif** intervient 2-3 jours ouvrés plus tard. Le réglage par défaut J+8 donne donc un débit vers J+10/J+11 après « Terminé » — conforme à l'objectif.
