# Extension de `Periodicity` : Ponctuelle et Semestrielle

**Date** : 2026-07-07
**Statut** : Approuvé (design), en attente du plan d'implémentation

## Contexte

`App\Domain\Tontine\Periodicity` ne connaît aujourd'hui que trois valeurs récurrentes
(`Weekly`, `Biweekly`, `Monthly`), chacune associée à un `\DateInterval` utilisé pour :
1. calculer la date de fin du premier cycle à la création du groupe
   (`TontineGroup::create()` : `startsAt + installmentsPerCycle × interval`) ;
2. générer l'échéancier de cotisations (`ContributionSchedule::dueDates()`), d'où
   découlent le nombre d'échéances attendues/payées/manquées et le statut « À jour »/
   « En retard » affichés dans `TontineGroupDashboard`.

Cette spec ajoute deux nouvelles périodicités :
- **Semestrielle** — récurrente comme les trois existantes, intervalle `P6M`. Aucun
  changement structurel : s'insère dans le modèle existant à l'identique de `Monthly`.
- **Ponctuelle** — non récurrente : cotisations libres, sans échéancier ni notion de
  retard, cycle sans date de fin (clôturé uniquement à la main via
  `closeCurrentCycle()`, déjà existant mais non exposé côté UI). Nécessite une nouvelle
  distinction structurelle « récurrente / non récurrente » qui n'existe pas encore.

## Décisions validées avec l'utilisateur

- Ponctuelle = cotisations libres, sans échéancier fixe (pas de dates d'échéance, pas
  de détection de retard).
- Fin de cycle pour Ponctuelle = clôture manuelle uniquement (pas de date de fin fixée
  à la création).
- `installmentsPerCycle` pour Ponctuelle = masqué dans le formulaire de création, forcé
  à `1` en interne par le domaine (le serveur reste seul garant, indépendamment de ce
  que le client envoie).

## Approche retenue : `Periodicity::isRecurring(): bool`

Un booléen calculé par la nouvelle méthode `isRecurring()` (`false` uniquement pour
`Punctual`) sert de garde à chaque point du code qui utilisait `dateInterval()` sans
condition. `dateInterval()` n'a **pas de `case Punctual`** dans son `match` : si du code
l'appelait par erreur pour une périodicité non récurrente, PHP lève nativement une
`\UnhandledMatchError` — garde-fou obtenu gratuitement, sans exception custom à écrire
ni à tester.

**Approche écartée** : donner à `Punctual` un `dateInterval()` factice très long
(`P100Y`) pour ne rien changer ailleurs. Rejetée : avec `installmentsPerCycle` forcé à
`1`, la première (et unique) échéance tomberait à la date de création du cycle, donc
tout membre n'ayant pas encore cotisé serait affiché « En retard » dès la création du
groupe — contraire à la sémantique validée (« pas de notion de retard »).

## Design détaillé

### Domaine (`src/Domain/Tontine/`)

**`Periodicity`** — deux nouveaux cases :
```php
case Semiannual = 'semiannual';  // dateInterval() -> P6M, comme les 3 existantes
case Punctual = 'punctual';      // isRecurring() -> false
```
Nouvelle méthode `isRecurring(): bool` — `true` pour `Weekly`/`Biweekly`/`Monthly`/
`Semiannual`, `false` pour `Punctual`. `dateInterval()` reste inchangé pour les 4 cas
récurrents ; aucun `case Punctual` n'y est ajouté (cf. garde-fou ci-dessus).

**`TontineGroup::create()`** :
- Si `$periodicity->isRecurring()` est vrai : comportement actuel inchangé (validation
  `installmentsPerCycle` 2–52, calcul de `endsAt` par boucle).
- Sinon (Ponctuelle) : la valeur reçue pour `installmentsPerCycle` est ignorée, le
  domaine stocke `1` ; le premier `SavingsCycle` est créé avec `endsAt = null` (pas de
  boucle de calcul).

**`SavingsCycle::$endsAt`** devient `?\DateTimeImmutable` (readonly, nullable).
`isOpen()`/`close()` ne changent pas : ils reposent sur le statut (`Open`/`Closed`),
jamais sur la date — aucun code n'auto-clôture un cycle à l'échéance de `endsAt`
aujourd'hui, donc ce champ n'est utilisé qu'à l'affichage et pour le plafonnement du
calcul de yield (voir `GroupPotReader` ci-dessous).

**`ContributionSchedule::dueDates()`** : retourne `[]` si `!$periodicity->isRecurring()`.
Les trois autres méthodes (`expectedInstallmentsFor`, `missedInstallmentsFor`,
`nextDueDateFor`) n'ont **aucun changement** à faire : elles itèrent déjà sur
`dueDates()`, donc `[]` en entrée donne naturellement `expected=0`, `missed=0`
(`max(0, 0 - paidCount)`), `nextDueDate=null` → `isLate` toujours faux pour une tontine
Ponctuelle, quel que soit le nombre de cotisations déjà enregistrées.

### Application (`src/Application/Tontine/`)

**`CreateTontineGroup`/`CreateTontineGroupCommand`** : aucun changement. Le use case
continue de transmettre tel quel l'entier parsé depuis le formulaire ; c'est
`TontineGroup::create()` (domaine) qui normalise à `1` pour Ponctuelle quelle que soit
la valeur reçue (y compris `0` si le champ masqué n'est pas soumis) — un seul endroit
garantit l'invariant, pas de duplication de logique entre couches.

**`GroupPotReader`** :
- `cap = $cycle->endsAt ?? $now` (le code actuel s'écrit déjà presque ainsi ; il suffit
  du changement de type nullable côté domaine pour que ce soit correct pour Ponctuelle
  — pas de plafonnement de date, le yield s'accumule jusqu'à « maintenant »).
- `GroupPotView` gagne un champ `periodicity: string` (valeur brute de l'enum, ex.
  `'punctual'`) pour permettre au template de piloter l'affichage conditionnel sans
  dépendre implicitement de la nullité de `cycleEndsAt`.

### Présentation

**`templates/tontine/new.html.twig`** :
- Ajoute deux options au `<select name="periodicity">` : `Semestrielle` (`semiannual`)
  et `Ponctuelle` (`punctual`).
- Quand `Ponctuelle` est sélectionné, le champ « nombre d'échéances » est masqué par un
  petit script inline (`<script>` sur l'événement `change` du select) — pas de
  contrôleur Stimulus dédié pour un simple `display: none`/`block`. Le serveur reste
  seul garant de la valeur réelle (`1` forcé côté domaine), le masquage n'est qu'un
  confort d'UI, pas une validation.

**`src/Presentation/Http/Dto/TontineGroupListItem.php`** — `frenchLabel()` gagne :
```php
'semiannual' => 'Semestrielle',
'punctual' => 'Ponctuelle',
```

**`templates/components/TontineGroupDashboard.html.twig`** :
- Affiche désormais le libellé de périodicité (absent du dashboard aujourd'hui).
- Si `pot.periodicity == 'punctual'` : masque les colonnes « Échéances » et « Statut »
  du tableau des membres (qui afficheraient sinon systématiquement « 0/0 » et
  « À jour », trompeur pour une tontine sans échéancier) et affiche à la place une
  mention « cotisations libres ».
- Masque la ligne « Cycle n°… — fin le … » quand `pot.cycleEndsAt` est `null`.

### Migration

Une nouvelle migration Doctrine :
```sql
ALTER TABLE tontine_savings_cycle ALTER COLUMN ends_at DROP NOT NULL;
```
Aucune migration nécessaire pour la colonne `periodicity` (`varchar(16)` — `'semiannual'`
et `'punctual'` tiennent dans la longueur existante).

### Tests

- `PeriodicityTest` : cas `Semiannual` (intervalle `P6M`) et `Punctual`
  (`isRecurring()` faux) ; `dateInterval()` sur `Punctual` doit lever
  `\UnhandledMatchError` (comportement natif, testé pour documenter l'intention).
- `TontineGroupTest` : `testCreateWithPunctualPeriodicityHasNoEndsAtAndForcesOneInstallment`
  (peu importe la valeur d'`installmentsPerCycle` transmise, le cycle créé a
  `installmentsPerCycle === 1` et `currentCycle()->endsAt === null`).
- `ContributionScheduleTest` : `testDueDatesEmptyForPunctual`, et vérifie que
  `expectedInstallmentsFor`/`missedInstallmentsFor` renvoient `0` et `nextDueDateFor`
  renvoie `null` peu importe l'ancienneté de l'adhésion.
- `GroupPotReaderTest` : cas Ponctuelle — `cycleEndsAt` nul, `cap` = « maintenant » (pas
  de plafonnement de date dans le calcul de yield), aucun membre marqué en retard.
- Fonctionnel : formulaire de création accepte `periodicity=punctual` et
  `periodicity=semiannual` ; page groupe/dashboard n'affiche pas les colonnes
  Échéances/Statut ni la ligne « fin le » pour une tontine Ponctuelle.

## Hors périmètre

- La clôture automatique de cycle (basée sur une date) n'existe pas aujourd'hui et
  n'est pas ajoutée par cette spec — `closeCurrentCycle()` reste un appel manuel,
  non exposé côté UI (comme documenté dans le plan M4 initial).
- Aucun changement au montant de cotisation (`contributionAmount` reste fixe pour
  toutes les périodicités, y compris Ponctuelle) — seule la notion d'échéancier est
  supprimée pour Ponctuelle, pas la notion de montant cible.
