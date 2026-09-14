# Résumé technique — Dahu SEPA Différé Stripe

> Document interne (français). Contexte du besoin, décisions d'architecture, bugs
> historiques et pièges connus. Le contexte de déploiement de référence est
> **annad.fr** (WooCommerce + WooCommerce Stripe Payment Gateway), mais le plugin
> est générique et fonctionne sur n'importe quel site WooCommerce + Stripe.

---

## 1. Contexte et objectif métier

Certains clients professionnels paient par **prélèvement SEPA via Stripe** (mandat
déjà signé/enregistré). Le besoin : ne plus débiter le client à la commande, mais
**N jours après le passage en statut « Terminé »** (le temps de l'expédition et
d'une marge de sécurité), avec possibilité de repousser cette date depuis la fiche
commande. N vaut 8 par défaut, mais est configurable globalement et client par
client (cf. §3.3), certains pros ayant négocié d'autres conditions de paiement.

Historiquement (contexte annad.fr), les prélèvements SEPA étaient gérés **en
direct via la banque** (mandat papier signé, enregistrement manuel sur la
plateforme bancaire) — un processus lourd. Le client a basculé sur Stripe, où le
mandat est un **e-mandat signé électroniquement** (IBAN + case de consentement),
juridiquement valide sans validation manuelle du marchand. Le vrai besoin restant
n'était donc pas la validation légale du mandat (déjà automatique), mais un
**contrôle commercial** : décider quels clients ont le droit d'utiliser ce mode de
paiement différé.

---

## 2. Contraintes Stripe SEPA — à connaître avant de modifier ce code

1. **Pas de `charge_date` chez Stripe.** L'API ne permet pas de programmer un
   prélèvement à une date future : le débit part dès la confirmation du
   PaymentIntent. D'où l'architecture : retarder la confirmation via une tâche
   planifiée côté WordPress plutôt que côté Stripe.
2. **SEPA est asynchrone.** Après confirmation, le PaymentIntent passe en
   `processing` pendant plusieurs jours avant `succeeded`/`failed`. Le webhook
   dédié (voir §4) gère ces transitions.
3. **Pas d'authorize/capture** comme pour une carte : impossible d'« autoriser
   puis capturer plus tard ».
4. **Pré-notification légale** : Stripe envoie automatiquement l'email de
   pré-notification de débit au client (obligation SEPA). Rien à coder, mais à
   vérifier dans Stripe → Settings → Emails.
5. **HPOS** : le code passe systématiquement par `wc_get_order()`,
   `$order->get_meta()/update_meta_data()/save()`, et déclare la compatibilité
   via `FeaturesUtil`. Le metabox est enregistré sur les deux écrans (legacy
   `shop_order` et HPOS `woocommerce_page_wc-orders`).
6. **Fiabilité de la planification** : Action Scheduler (embarqué dans
   WooCommerce) est utilisé en priorité, avec repli WP-Cron.

---

## 3. Architecture retenue (et pourquoi elle a changé en cours de route)

### 3.1 Approche initiale envisagée, abandonnée
La première approche envisagée était d'intercepter le checkout SEPA natif de
Stripe pour l'empêcher de confirmer immédiatement (`confirm=false` +
`setup_future_usage=off_session`), puis déclencher le vrai prélèvement à J+8. Le
code existe encore dans le plugin (`ANNAD_SEPA_DEFER_AT_CHECKOUT`, désactivé par
défaut) mais **ne fonctionne pas en UPE** : la confirmation du paiement s'y fait
côté navigateur, et au moment de la création du PaymentIntent, WooCommerce ne
connaît pas encore la méthode de paiement choisie (SEPA vs carte) — impossible à
intercepter côté serveur à ce stade.

### 3.2 Architecture finale
Une **passerelle de paiement WooCommerce dédiée** (`annad_sepa_deferred`) :

- Au checkout, elle ne débite **rien** : elle réutilise le mandat SEPA déjà
  enregistré du client (customer + payment method Stripe), le stocke sur la
  commande, et met la commande **« En attente »**.
- Elle n'est disponible au checkout **que si** :
  1. un administrateur a choisi cet IBAN précis comme « mandat actif » sur la
     fiche du client (WordPress → Utilisateurs), **et**
  2. le client a lui-même mis ce même IBAN par défaut de son côté (« Mon
     compte → Mes moyens de paiement »).

  Cette double condition évite qu'un client bascule seul vers un IBAN non
  validé par l'administrateur, sans bloquer l'ajout libre de plusieurs IBAN côté
  Stripe (voir §5.2 pour l'historique de cette décision).
- Au passage de la commande en **« Terminé »**, le prélèvement réel est planifié
  à J+`annad_sepa_get_delay_days( $user_id )` : **Stratégie B** (nouveau
  PaymentIntent off-session avec le customer + payment method sauvegardés), avec
  **Fallback A** (confirmation d'un PaymentIntent existant s'il est encore en
  `requires_confirmation`).
- Une **clé d'idempotence persistante par commande** protège contre le double
  prélèvement en cas de rejeu de la tâche planifiée (timeout réseau, relance
  Action Scheduler).
- Un **webhook Stripe dédié** (`/wp-json/annad-sepa/v1/webhook`, signature
  vérifiée en HMAC-SHA256 sans SDK) traite `payment_intent.succeeded`,
  `payment_intent.payment_failed` et `charge.dispute.created` pour finaliser ou
  faire échouer la commande — indépendant du webhook du plugin Stripe officiel
  (ne traite que les prélèvements de ce plugin, via metadata `source` ou l'ID de
  gateway).

### 3.3 Résolution du délai de prélèvement

Le délai n'est plus une constante figée. `annad_sepa_get_delay_days( $user_id )`
le résout par ordre de priorité :

1. **Meta utilisateur** `_annad_sepa_delay_days` (`ANNAD_SEPA_DELAY_META`) —
   saisie sur la fiche du client, section « Prélèvement SEPA différé ». Sert aux
   pros ayant négocié d'autres conditions de paiement (ex. J+30).
2. **Réglage global de la passerelle** — clé `delay_days` de l'option
   `woocommerce_annad_sepa_deferred_settings` (Réglages → Paiements).
3. **`ANNAD_SEPA_DELAY_DAYS`** (8) — dernier recours uniquement.

Le délai est résolu **au moment de la planification** (passage en « Terminé ») et
non au checkout : modifier le réglage n'affecte donc pas les commandes déjà
planifiées, qu'il faut replanifier une par une depuis le metabox.

### 3.4 Déclenchement manuel du prélèvement

Le bouton « Déclencher le prélèvement maintenant » de la fiche commande était
initialement une sonde de debug. Depuis la 1.7.1 il est **affiché en permanence**
(hors `ANNAD_SEPA_DEBUG`) : il sert aussi en exploitation courante, pour prélever
un client sans attendre la date planifiée.

Ce n'est pas un affaiblissement de la sécurité : l'affichage n'est qu'un lien, et
le handler `annad_sepa_handle_run_now()` vérifie la capacité
`edit_shop_orders` **et** un nonce par commande — ces contrôles n'ont jamais
dépendu de `ANNAD_SEPA_DEBUG`.

À savoir : le déclenchement manuel **ne désinscrit pas** la tâche Action
Scheduler déjà planifiée. Celle-ci s'exécutera bien à la date prévue, mais ne
fera rien — `annad_sepa_do_confirm()` sort immédiatement si la meta
`_annad_sepa_done` vaut `yes`, et la clé d'idempotence persistante bloque de
toute façon un second débit côté Stripe.

### 3.5 Secrets et configuration par environnement

`ANNAD_SEPA_WEBHOOK_SECRET`, `ANNAD_SEPA_DEBUG`, `ANNAD_SEPA_NOTIFY_EMAIL` et
`ANNAD_SEPA_CONTACT_EMAIL` sont déclarées en `if ( ! defined( ... ) )` : elles se
surchargent depuis `wp-config.php` sans toucher au fichier du plugin.

Le secret de webhook a été retiré du code : il avait été committé en clair sur
le dépôt public. C'était celui de l'endpoint live d'annad.fr, qui a donc été
renouvelé côté Stripe au passage en 1.7.0 — retirer une valeur du fichier ne
l'efface pas de l'historique Git, seul le renouvellement la révoque. **À ne
jamais réintroduire dans le fichier versionné.** Rappel : les secrets test et live sont différents — un secret test
laissé en place fait rejeter 100 % des notifications live, et les commandes
restent bloquées en « processing » sans jamais passer à « payée ».

Les **clés API Stripe**, elles, ne sont jamais stockées par ce plugin : elles
sont lues dans les réglages du plugin Stripe officiel
(`annad_sepa_get_secret_key()`, qui suit le flag `testmode`).

---

## 4. Masquer le SEPA natif Stripe au checkout — piège important

Le client final ne veut pas que le SEPA natif de Stripe (débit immédiat) reste
proposé en parallèle de la passerelle différée au checkout, tout en gardant le
SEPA disponible sur « Mon compte » (pour que les clients puissent y enregistrer
un mandat).

**Plusieurs pistes ont échoué avant de trouver la bonne :**

1. Décocher « SEPA Direct Debit » dans les réglages du plugin Stripe → désactive
   SEPA **partout**, y compris sur « Mon compte ». Rejeté.
2. Forcer `confirm=false` via des filtres devinés (`wc_stripe_generate_create_intent_request`,
   `wc_stripe_payment_intent_args`, etc.) → aucun effet en UPE (cf. §3.1).
3. Filtrer l'option `woocommerce_stripe_settings` (`upe_checkout_experience_accepted_payments`)
   selon la page → **aucun effet** si le compte Stripe utilise le mode *Payment
   Method Configurations* (PMC, une fonctionnalité Stripe plus récente) : dans ce
   cas, cette clé n'est même pas consultée, la liste vient directement de
   Stripe.
4. Deviner le filtre `wc_stripe_upe_enabled_payment_method_ids` (et variantes) →
   n'existe pas dans le code réel du plugin.

**Solution qui fonctionne**, trouvée en lisant le code source officiel du plugin
(`woocommerce/woocommerce-gateway-stripe` sur GitHub, fichier
`includes/payment-methods/class-wc-stripe-upe-payment-gateway.php`) :

- Le filtre **`wc_stripe_upe_params`** est appliqué juste avant l'envoi des
  paramètres JS construisant l'élément de paiement Stripe
  (`apply_filters( 'wc_stripe_upe_params', $this->javascript_params() )`).
- En mode **classique** (Optimized Checkout désactivé), `paymentMethodsConfig`
  contient une clé par moyen de paiement (`card`, `sepa_debit`, …) → on retire
  `sepa_debit`.
- En mode **« Optimized Checkout »**, tout est fusionné sous une seule clé
  (littéralement `'card'`, car `WC_Stripe_Payment_Methods::OC === 'card'`), dont
  la sous-liste `enabledPaymentMethods` pilote réellement l'affichage → on filtre
  cette sous-liste.
- **En complément**, en mode classique, Stripe enregistre en réalité SEPA comme
  une **passerelle WooCommerce séparée** (`stripe_sepa_debit`). On la retire
  aussi via le filtre standard `woocommerce_available_payment_gateways`,
  uniquement au checkout (jamais sur `is_add_payment_method_page()`).

**Point de configuration nécessaire côté site** : dans les réglages Stripe →
Paramètres avancés, l'option **« Activer la suite de paiement optimisée »**
(Optimized Checkout) doit être **désactivée** pour que le masquage fonctionne de
façon fiable et vérifiable (en mode OC, seule la piste `enabledPaymentMethods` a
été implémentée mais n'a pas pu être validée visuellement lors du développement).

---

## 5. Autres bugs historiques et pièges rencontrés

### 5.1 `is_paid()` trompeur pour la passerelle dédiée
Le garde-fou standard « ne rien faire si la commande est déjà payée » (`is_paid()`)
est trompeur pour notre passerelle : elle passe la commande en `on-hold` puis en
`completed` sans qu'aucun débit n'ait eu lieu. Ce garde-fou est donc désactivé
spécifiquement pour le gateway `annad_sepa_deferred` (dans
`annad_sepa_schedule_on_completed()` et `annad_sepa_do_confirm()`).

### 5.2 Un seul mandat actif par client : plusieurs itérations
1. Première tentative : bloquer l'ajout d'un 2ᵉ IBAN. Abandonnée car trop
   fragile côté UX Stripe (le plugin officiel refuse alors l'ajout avec un
   message générique peu clair).
2. Deuxième tentative : suppression automatique des IBAN en trop, sur plusieurs
   hooks candidats (`woocommerce_payment_token_added`,
   `woocommerce_add_payment_method_success`, puis un mécanisme basé sur
   l'affichage de la page compte). Un bug de **double-suppression** (deux
   mécanismes actifs en parallèle) a fini par supprimer tous les mandats d'un
   client testé. Ces mécanismes sont **conservés dans le code mais désactivés**
   (`ANNAD_SEPA_DEDUPE_ON_ADD_HOOKS`, `ANNAD_SEPA_AUTO_DEDUPE_TOKENS`), pour
   référence.
3. **Solution retenue** : ne rien bloquer côté Stripe. Le client peut enregistrer
   autant d'IBAN qu'il veut ; un administrateur choisit explicitement lequel est
   « actif » sur la fiche utilisateur (bouton radio), et le client doit
   lui-même le mettre par défaut de son côté pour que la passerelle s'active
   (double condition, voir §3.2). Un email de notification est envoyé (adresse
   configurable via `ANNAD_SEPA_NOTIFY_EMAIL`) quand un client change de mandat
   par défaut, pour vérification manuelle du RIB a posteriori.

### 5.3 Badge visuel « mandat actif » sur « Mon compte »
Le filtre core WooCommerce `woocommerce_saved_payment_methods_list` ne se
déclenchait pas du tout sur le site de test (vérifié par sonde de diagnostic).
Solution retenue : un petit script injecté uniquement sur la page « Mes moyens de
paiement », qui repère dans le HTML affiché le texte contenant les 4 derniers
chiffres de l'IBAN actif et y ajoute le badge — indépendant de la structure de
données interne de WooCommerce.

### 5.4 Outils de diagnostic intégrés au plugin
Pour accélérer le débogage sur un environnement de production/staging sans accès
SSH facile, plusieurs sondes de diagnostic sont intégrées, actives uniquement si
`ANNAD_SEPA_DEBUG` vaut `true` :
- Encart dans la fiche commande (meta Stripe brutes, sonde d'interception).
- Encart réservé aux administrateurs sur la page de paiement (pourquoi la
  passerelle est ou non disponible pour l'utilisateur connecté).

La constante vaut **`false` par défaut** depuis la 1.7.0 (le bouton de
déclenchement manuel du prélèvement ne doit pas traîner en production). Pour
l'activer temporairement sur un site, ajouter dans `wp-config.php` :

```php
define( 'ANNAD_SEPA_DEBUG', true );
```

puis retirer la ligne une fois le diagnostic terminé — sans jamais rééditer le
fichier du plugin.

### 5.5 Transition depuis l'ancien SEPA manuel (retirée en 1.7.2)
Contexte annad.fr : avant ce plugin, les revendeurs historiques payaient par un
formulaire de prélèvement SEPA **manuel** (mandat papier, saisie à la banque),
exposé au checkout via la passerelle WooCommerce **`cod`** (« paiement à la
livraison ») détournée et renommée « Prélèvement Sepa ANCIEN SYSTEME ». Sa
restriction aux seuls pros était assurée par du CSS dans le plugin
**dahu-pricing**, pas par ce plugin.

Pendant la migration, `annad_sepa_hide_native_sepa_gateway()` retirait `cod` du
checkout pour tout client déjà autorisé sur le nouveau système, afin qu'il ne
puisse plus retomber sur l'ancien circuit.

Une fois l'ancien formulaire supprimé du site, ce bloc a été retiré (1.7.2) :
- il ne faisait plus rien ;
- il aurait **masqué silencieusement un vrai « paiement à la livraison »** aux
  clients SEPA autorisés si `cod` était un jour réactivé pour son usage normal ;
- il enfreignait la règle « rien de spécifique à annad.fr dans le code générique ».

`annad_sepa_is_authorized()`, qu'il appelait, est **conservée** : elle reste au
cœur de la disponibilité de la passerelle, de la vérification serveur au
paiement et de l'affichage sur « Mon compte ».

Reste à faire **hors de ce dépôt** : retirer de dahu-pricing le CSS qui
restreignait l'ancien bloc SEPA aux pros — inoffensif mais devenu mort.

---

## 6. Checklist de validation (mode test Stripe)

> IBAN de test Stripe : succès `AT611904300234573201` · fonds insuffisants
> `AT861904300235473202`.

### Phase 0 — Préparation
- [ ] Sauvegarde du site / environnement de staging.
- [ ] Mode test Stripe activé.
- [ ] Plugin installé et activé.
- [ ] SEPA Direct Debit activé côté Stripe.
- [ ] Emails de pré-notification SEPA actifs (Stripe → Settings → Emails).

### Phase 1 — Identification
- [ ] Relever l'ID exact de la gateway SEPA native (`$order->get_payment_method()`).
- [ ] Relever les meta `_stripe_intent_id` (`pi_...`), `_stripe_customer_id`
      (`cus_...`), `_stripe_source_id` (**`pm_...`**, pas `src_...`).
- [ ] Relever le statut du PaymentIntent juste après un checkout SEPA natif.
- [ ] Vérifier que le mandat est bien attaché au customer (Stripe → Customers →
      Payment methods).

### Phase 2 — Planification
- [ ] Commande → « Terminé » → note de planification J+8 + tâche visible dans
      Action Scheduler.
- [ ] Replanification manuelle depuis le metabox → note + tâche mise à jour.
- [ ] Date invalide/passée → avertissement, rien de changé.

### Phase 3 — Masquage du SEPA natif au checkout
- [ ] Optimized Checkout désactivé dans les réglages Stripe.
- [ ] SEPA disparaît du checkout, reste disponible sur « Mon compte ».
- [ ] Une commande carte bancaire n'est pas impactée.

### Phase 4 — Exécution du prélèvement
- [ ] Déclenchement (bouton manuel ou Action Scheduler) → note ✅ + PaymentIntent
      `processing` côté Stripe.
- [ ] **Test idempotence** : redéclencher → aucun second PaymentIntent créé.

### Phase 5 — Webhook
- [ ] `payment_intent.succeeded` → commande marquée payée + note ✅.
- [ ] `payment_intent.payment_failed` (IBAN fonds insuffisants) → commande en échec.

### Phase 6 — Cas limites
- [ ] Commande carte bancaire : aucune note, aucune tâche SEPA différé.
- [ ] Annulation/remboursement d'une commande planifiée → tâche supprimée + note.
- [ ] Re-passage de statut (Terminé → En attente → Terminé) : pas de double
      planification.

### Phase 7 — Passage en production
- [ ] Toutes les phases précédentes vertes en mode test.
- [x] Header `Stripe-Version` retiré du code (1.7.0) : la version d'API du compte
      s'applique. Rien à aligner.
- [ ] `ANNAD_SEPA_DEBUG` absent de `wp-config.php` (donc `false`).
- [ ] Slug du rôle « particulier » vérifié sur le site
      (`ANNAD_SEPA_PARTICULIER_ROLES`, cf. §7).
- [ ] Ancien endpoint webhook de test supprimé côté Stripe (son secret a été
      exposé publiquement).
- [ ] Stripe repassé en mode live + nouvelle destination webhook live
      (`https://<site>/wp-json/annad-sepa/v1/webhook`, 3 événements), secret
      `whsec_` **live** reporté dans `wp-config.php`.
- [ ] Optimized Checkout désactivé côté Stripe (cf. §4).
- [ ] Délai global renseigné dans les réglages de la passerelle, et délais
      personnalisés saisis pour les clients concernés.
- [ ] Emails de pré-notification SEPA actifs (Stripe → Settings → Emails).
- [ ] Au moins un client autorisé (mandat actif choisi sur sa fiche) — sinon la
      passerelle n'apparaît pour personne.
- [ ] Une vraie commande de bout en bout, petit montant, sous surveillance.

---

## 7. Limites connues / dette technique

- Les identifiants internes (constantes `ANNAD_SEPA_*`, fonctions `annad_sepa_*`,
  route REST `annad-sepa/v1`) conservent le préfixe historique `annad` bien que
  le plugin ait été renommé `dahu-sepa-differe-stripe` — un renommage complet
  n'a pas été fait pour éviter de casser des sites où le plugin tournerait déjà
  avec ces identifiants. Sans impact fonctionnel.
- Le rôle WordPress considéré comme « particulier » est supposé être `customer`
  (`ANNAD_SEPA_PARTICULIER_ROLES`) — à vérifier sur chaque nouveau site avant mise
  en production, plusieurs rôles « pro » pouvant coexister avec un seul rôle
  particulier.
