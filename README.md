# Annad — Prélèvement SEPA Stripe différé

Extension WooCommerce qui **déclenche automatiquement le prélèvement SEPA Stripe 8 jours après**
le passage d'une commande en statut « Terminé ». La date de déclenchement est modifiable depuis
la fiche commande (admin).

- **Site cible** : annad.fr (WooCommerce + plugin officiel *WooCommerce Stripe Payment Gateway*)
- **Auteur** : Dahu-Concept
- **Version** : 0.9.0
- **Prérequis** : WordPress, WooCommerce, plugin WooCommerce Stripe, mandat SEPA signé côté client

## Fonctionnement

```
Commande (mandat SEPA)  →  « Terminé »  →  tâche planifiée J+8  →  prélèvement Stripe  →  débit J+2-3 ouvrés
```

- Stratégie B (recommandée) : nouveau PaymentIntent off-session à J+8 avec le customer + payment
  method SEPA sauvegardés.
- Fallback A : si un PaymentIntent existant est en `requires_confirmation`, il est simplement confirmé.
- Protection anti-double-prélèvement via **clé d'idempotence** persistante par commande.
- Planification fiable via **Action Scheduler** (embarqué dans WooCommerce), fallback WP-Cron.
- Compatible **HPOS**.

## Installation

1. Récupérer `annad-sepa-differe.zip` (voir « Build » ci-dessous) ou copier le dossier
   `annad-sepa-differe/` dans `wp-content/plugins/`.
2. Extensions → Activer « Annad — Prélèvement SEPA Stripe différé ».
3. Vérifier que le plugin WooCommerce Stripe est configuré (mode test pour valider).

## Configuration

Constantes en tête de `annad-sepa-differe.php` :

| Constante | Défaut | Rôle |
|---|---|---|
| `ANNAD_SEPA_DELAY_DAYS` | `8` | Délai en jours entre « Terminé » et le prélèvement |
| `ANNAD_SEPA_GATEWAYS` | `stripe_sepa`, `stripe_sepa_debit` | IDs de gateway SEPA à surveiller |
| `ANNAD_SEPA_DEFER_AT_CHECKOUT` | `false` | ⚠️ Empêche le débit immédiat au checkout — **à valider en mode test avant activation** |

## À finaliser / valider en environnement réel

Voir la checklist complète dans [annad-sepa-differe.md](annad-sepa-differe.md) §5. Points clés :

1. ID exact de la gateway SEPA sur annad.fr.
2. **Comportement du checkout** : le PaymentIntent est-il confirmé dès la commande ? (conditionne
   l'activation de `ANNAD_SEPA_DEFER_AT_CHECKOUT`). Point le plus sensible.
3. Meta keys réels (`_stripe_intent_id`, `_stripe_customer_id`, `_stripe_source_id`).
4. Payment method SEPA bien attaché au customer (off-session réutilisable).
5. Webhooks traitent le nouveau PaymentIntent.
6. Version d'API Stripe alignée sur le compte.

## Plan de test

Voir [annad-sepa-differe.md](annad-sepa-differe.md) §6 (IBAN de test, Action Scheduler, cas d'échec, commande CB ignorée…).

## Build du zip

```bash
# depuis la racine du dépôt
bash build.sh          # produit dist/annad-sepa-differe.zip
```

## Sécurité

Les clés secrètes Stripe (`sk_...`) ne sont **jamais** stockées dans ce plugin : elles sont lues
depuis les réglages du plugin WooCommerce Stripe officiel. Ne jamais committer de clé secrète.
