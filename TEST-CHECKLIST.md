# Checklist de validation — Annad SEPA différé (mode test Stripe)

> Cocher au fur et à mesure. Tout se fait en **mode test** Stripe (aucun argent réel).
> IBAN de test Stripe : succès `AT611904300234573201` · fonds insuffisants `AT861904300235473202`.
> Nom / email : quelconques.

---

## Phase 0 — Préparation

- [ ] **0.1** Sauvegarde du site (ou environnement de staging) — on modifie le tunnel de paiement.
- [ ] **0.2** WooCommerce → Réglages → Paiements → Stripe → **Mode test activé** (`testmode = yes`).
- [ ] **0.3** Plugin « Annad — Prélèvement SEPA Stripe différé » **installé et activé** (v0.9.0).
- [ ] **0.4** Moyen de paiement **SEPA Direct Debit** activé dans la config Stripe.
- [ ] **0.5** Dashboard Stripe (mode test) ouvert dans un onglet, prêt à inspecter les PaymentIntents.
- [ ] **0.6** Emails de notification de débit SEPA actifs : Stripe → Settings → Emails.
- [ ] **0.7** `ANNAD_SEPA_DEFER_AT_CHECKOUT` laissé à **`false`** pour l'instant (on l'activera en Phase 3).

---

## Phase 1 — Identification (checklist §5.1, §5.3 de la spec)

- [ ] **1.1** Passer une commande test payée en **SEPA** (IBAN succès), montant faible.
- [ ] **1.2** Relever l'**ID exact de la gateway** : commande → « Moyen de paiement », ou
      `$order->get_payment_method()`.
      → Valeur relevée : `________________`
      → Est-elle dans `ANNAD_SEPA_GATEWAYS` (`stripe_sepa`, `stripe_sepa_debit`) ? Sinon **ajuster la constante**.
- [ ] **1.3** Inspecter les meta de la commande (Order meta inspector ou `wp post meta list <id>` / WP-CLI).
      Relever :
      - [ ] `_stripe_intent_id` = `pi_...` → `________________`
      - [ ] `_stripe_customer_id` = `cus_...` → `________________`
      - [ ] `_stripe_source_id` = **`pm_...`** (et NON `src_...`) → `________________`
      → Si un nom diffère, **ajuster** les `get_meta()` correspondants dans le code.
      → Si `_stripe_source_id` est un `src_`, la Stratégie B échouera : voir note A en bas.
- [ ] **1.4** Dashboard Stripe → ouvrir le PaymentIntent → **noter son statut juste après le checkout** :
      → Statut relevé : `________________`
      - `requires_confirmation` → 🎉 Stratégie A possible, checkout ne débite pas.
      - `processing` / `succeeded` → le checkout **débite déjà** → Phase 3 (interception) obligatoire.
- [ ] **1.5** Stripe → Customers → le client → **Payment methods** : le mandat SEPA `pm_...` est bien
      **attaché au customer** (indispensable pour l'off-session).

---

## Phase 2 — Planification & replanification (sans encore prélever)

- [ ] **2.1** Passer la commande test en statut **« Terminé »**.
- [ ] **2.2** Note de commande présente : « SEPA différé : prélèvement planifié le … (J+8) ».
- [ ] **2.3** WooCommerce → Statut → **Action Scheduler** → rechercher `annad_sepa_confirm_payment` :
      tâche **planifiée** visible à la bonne date (J+8, 09h heure de Paris si replanifiée).
- [ ] **2.4** Meta `_annad_sepa_scheduled_ts` présente sur la commande.
- [ ] **2.5** **Replanification** : fiche commande → metabox « Prélèvement SEPA différé » → saisir une
      **nouvelle date** future → Enregistrer.
      - [ ] Note « prélèvement replanifié du … au … ».
      - [ ] Ancienne tâche remplacée par une nouvelle dans Action Scheduler (pas de doublon).
- [ ] **2.6** Test **date passée / invalide** dans le metabox → note d'avertissement, planification inchangée.

---

## Phase 3 — Interception du checkout (tâche 2 — §5.2) — SEULEMENT si 1.4 = processing/succeeded

> ⚠️ Modifie le checkout. À faire en mode test uniquement, puis re-tester une commande complète.

- [ ] **3.1** Passer `ANNAD_SEPA_DEFER_AT_CHECKOUT` à **`true`** dans `annad-sepa-differe.php`.
- [ ] **3.2** Nouvelle commande test SEPA (IBAN succès).
- [ ] **3.3** Dashboard Stripe → le PaymentIntent reste en **`requires_confirmation`** (et NON `processing`)
      juste après le checkout. ✅ = interception OK.
- [ ] **3.4** Le payment method est **quand même sauvegardé / attaché au customer** (Phase 1.5).
- [ ] **3.5** Si le PI part encore en `processing` → le filtre ne correspond pas à la version du plugin.
      → Repasser `ANNAD_SEPA_DEFER_AT_CHECKOUT` à `false` et me remonter la **version du plugin
        WooCommerce Stripe** (Extensions) + Legacy ou UPE → j'ajuste les noms de filtres.
- [ ] **3.6** Vérifier qu'une commande **CB** (carte de test `4242 4242 4242 4242`) n'est **pas** impactée
      par l'interception (elle doit se payer normalement).

---

## Phase 4 — Exécution du prélèvement (le cœur)

> On ne va pas attendre 8 jours : on déclenche la tâche à la main.

- [ ] **4.1** Action Scheduler → tâche `annad_sepa_confirm_payment` de la commande → bouton **« Run »**.
- [ ] **4.2** Note de commande **✅** « prélèvement déclenché … Statut Stripe : processing ».
- [ ] **4.3** Dashboard Stripe → un PaymentIntent en **`processing`** pour le bon montant / customer.
- [ ] **4.4** Meta `_annad_sepa_done = yes` ; `_annad_sepa_scheduled_ts` supprimée ; tâche disparue d'Action Scheduler.
- [ ] **4.5** **Test idempotence (tâche 1)** : re-cliquer **« Run »** (ou recréer/relancer la tâche) →
      **aucun second PaymentIntent** créé côté Stripe (grâce à `Idempotency-Key` + garde `_annad_sepa_done`).
      → C'est LE test qui protège du double prélèvement. À valider absolument.

---

## Phase 5 — Webhooks & finalisation

- [ ] **5.1** Attendre / simuler la réussite : Stripe (mode test) fait passer le PI de `processing` à
      `succeeded` → le **webhook du plugin officiel** met la commande en payée/terminée.
- [ ] **5.2** Vérifier que le webhook retrouve bien la commande via `_stripe_intent_id` (mis à jour par
      la Stratégie B avec le **nouvel** ID de PI).
- [ ] **5.3** Test **échec** : nouvelle commande avec IBAN fonds insuffisants `AT861904300235473202`,
      la faire aboutir jusqu'au « Run » → le webhook `payment_intent.payment_failed` fait
      **échouer proprement** la commande (note d'échec, statut adéquat).

---

## Phase 6 — Cas limites & nettoyage

- [ ] **6.1** Commande **CB** classique → « Terminé » : **aucune** note « SEPA différé », **aucune** tâche planifiée.
- [ ] **6.2** **Annulation** d'une commande SEPA planifiée → la tâche disparaît d'Action Scheduler +
      note « planification annulée ».
- [ ] **6.3** **Remboursement** d'une commande planifiée → même comportement (tâche annulée).
- [ ] **6.4** Commande déjà payée avant le déclenchement → note « déjà payée, aucune action » + marquée done.
- [ ] **6.5** Re-passage de statut Terminé → En attente → Terminé : **pas** de double planification
      (anti-doublon `_annad_sepa_scheduled_ts`).

---

## Phase 7 — Passage en production

- [ ] **7.1** Toutes les phases 1–6 vertes en mode test.
- [ ] **7.2** `Stripe-Version` du code (`2024-06-20`) alignée sur la version du compte Stripe, ou header retiré.
- [ ] **7.3** Repasser Stripe en **mode live**.
- [ ] **7.4** Si Phase 3 nécessaire : `ANNAD_SEPA_DEFER_AT_CHECKOUT = true` confirmé et testé.
- [ ] **7.5** **Une** vraie commande SEPA de bout en bout sous surveillance (petit montant) avant ouverture générale.

---

### Note A — si `_stripe_source_id` est un `src_` et non `pm_`
La Stratégie B (`payment_method` = `pm_...`) échouera. Deux options :
- privilégier la Stratégie A (confirmation du PI existant), ou
- convertir/retrouver le PaymentMethod off-session du customer.
→ Me remonter la valeur relevée en 1.3, j'adapte le code.

### Ce dont j'ai besoin de ta part pour ajuster le code
- Résultat de **1.2** (gateway), **1.3** (meta keys + type de source), **1.4** (statut PI au checkout).
- En cas de Phase 3.5 : **version** du plugin WooCommerce Stripe + Legacy/UPE.
