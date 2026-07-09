# Coffre partagé de groupe (Gnosis Safe) — Phase 1

**Date** : 2026-07-08
**Statut** : Approuvé (design), plan d'implémentation écrit et en cours d'exécution
(`docs/superpowers/plans/2026-07-08-coffre-partage-safe.md`)

## Contexte

Objectif produit : donner aux membres d'un groupe tontine un vrai accès de gestion à
leur cotisation dans le SuperVault (solde, yield, historique), avec un retrait piloté
par l'admin du groupe plutôt que par chaque membre individuellement — la logique
« cagnotte » plutôt que « chacun sa part ».

Investigation menée en amont : le flow de dépôt actuel
(`assets/react/controllers/VaultDeposit.jsx`) fait déposer chaque membre **vers son
propre wallet** (`vault.deposit(assets, wallet.address)`) — il n'existe aujourd'hui
**aucun coffre partagé**. Les wallets Privy sont des embedded wallets non-custodiaux :
un admin ne peut techniquement pas signer un retrait pour le wallet d'un autre membre.
Un vrai pot commun suppose donc soit un compte custodial contrôlé par l'app, soit un
contrat on-chain partagé.

## Décisions validées avec l'utilisateur

- Le pot est une vraie **cagnotte commune**, pas des parts individuelles détenues par
  chaque wallet membre. L'admin peut retirer n'importe quel montant du pot commun
  (pas de logique de rotation façon ROSCA classique).
- Seuls certains membres (les **admins** du groupe) peuvent déclencher un retrait —
  restriction basée sur le rôle `MembershipRole::Admin` existant, pas sur une notion de
  bénéficiaire de cycle.
- Mécanisme de garde comparé et tranché : **Safe (Gnosis Safe multisig) plutôt qu'un
  Privy Server Wallet**. Voir « Approche retenue » ci-dessous pour le comparatif complet.
- Seuil MVP retenu : **1-of-N** (propriétaires = admins du groupe ; aujourd'hui toujours
  un seul admin, le créateur — pas encore de mécanisme de promotion multi-admin).
- **Le retrait effectif est explicitement hors périmètre de cette phase** — Phase 2. Le
  ticket Linear JAS-26 (initialement « retrait individuel `maxWithdraw` ») a été réécrit
  en conséquence pour décrire un retrait piloté par l'admin via `execTransaction`.

## Approche retenue : Safe (Gnosis Safe) par groupe

**Comparatif Safe vs Privy Server Wallet** (les deux options sérieusement évaluées) :

| | Safe (retenu) | Privy Server Wallet (écarté) |
|---|---|---|
| **Pour** | Sécurité appliquée **on-chain** par le contrat lui-même : même si un bug d'autorisation existe côté Symfony ou qu'une clé serveur fuite, personne ne peut vider le coffre sans réunir M signatures parmi les wallets Privy réels des admins — le contrat l'impose, pas une vérification de rôle contournable. Standard de l'industrie, auditée, vérifiable par n'importe quel membre sur BaseScan. Les *signers* du Safe peuvent être directement les wallets Privy embedded déjà utilisés — pas de nouveau type de wallet à introduire. | Retrait en un clic pour l'admin (signature serveur automatique sous réserve de *policies*). Réutilise l'infra Privy déjà en place. |
| **Contre** | Friction réelle si seuil > 1 (plusieurs membres doivent signer). Un peu plus de code d'intégration (construction de tx Safe, EIP-712). | La sécurité repose sur l'**infra applicative** (clé d'autorisation serveur + policies Privy) : un bug d'autorisation ou une fuite de clé permettrait un retrait non désiré, sans qu'aucune vérification on-chain ne s'y oppose. |

Décision : **Safe**, avec seuil 1-of-N pour le MVP (friction minimale tout en gardant la
garantie on-chain), réévaluable à N>1 quand un mécanisme de promotion multi-admin
existera.

**Alternative écartée pour le déploiement** : calcul contrefactuel de l'adresse du Safe
(CREATE2) + déploiement paresseux au premier besoin. Rejetée : connecter le Safe au
yield pool (`connectPool`) nécessite de toute façon un contrat déployé pour appeler
`execTransaction`, donc différer le déploiement n'évite rien, ça ajoute juste du
branchement conditionnel partout ailleurs. Déploiement **immédiat**, déclenché par un
bouton admin sur la page du groupe.

**Alternative écartée pour l'exécution de transaction Safe** : `@safe-global/protocol-kit`
(SDK officiel TypeScript). Rejetée pour le MVP : pour un seul owner à seuil 1-of-1,
produire la signature `execTransaction` est un simple `signTypedData` EIP-712 via viem —
exactement le même style que `VaultDeposit.jsx` fait déjà pour `approve`/`deposit`/
`connectPool`, sans dépendance supplémentaire. Point de réévaluation explicite si le
seuil passe à N>1 (collecte/ordre de plusieurs signatures devient non trivial à la main).

## Design détaillé

### Domaine

- `TontineGroup` gagne un `safeAddress` (nullable), `provisionSafe()` (idempotent pour
  la même adresse, lève si tentative de changement vers une adresse différente),
  `hasSafe()`, `adminWalletAddresses()` (dérivé à la volée de `Membership::role ===
  Admin`, **jamais persisté séparément** — un seul point de vérité).
- Nouveau module `Domain/Safe/` (miroir de `Domain/Deposit/`, même précédent
  « pas de FK vers l'agrégat, juste un `groupId` en colonne simple ») : `SafeTransaction`
  suit le même cycle de vie pending → confirmed/failed que `DepositTransaction`, pour
  deux usages (`SafeTransactionPurpose::Deployment` / `ConnectYieldPool` — `Redeem`
  s'ajoutera en Phase 2 sans changement de forme).
- **Correctif sur du code déjà livré** : `DepositTransaction::applyReceipt()` validait le
  wallet confirmant contre le champ `owner` de l'event `Deposit` (= le `receiver`
  ERC-4626). Une fois `receiver = safeAddress` pour un dépôt de groupe, `owner` ne
  correspond plus jamais au wallet du membre déposant — chaque dépôt de groupe serait à
  tort marqué `Failed`. Corrigé pour valider contre **`sender`** (`msg.sender`, celui qui
  a réellement signé), invariant strictement plus correct et identique au comportement
  actuel pour un dépôt personnel (`sender == owner == wallet.address`).

### Application

- `ConfirmGroupSafeDeployment` / `ConfirmGroupSafeExecution` (générique sur
  `SafeTransactionPurpose` — le retrait de Phase 2 réutilisera `ConfirmGroupSafeExecution`
  tel quel avec un `{to, data}` différent, sans nouveau use case) / 
  `RecheckPendingSafeTransactions`, suivant le pattern `Result<T, E>` déjà établi
  (`RecordContribution`) pour les erreurs métier attendues (`GroupNotFound`,
  `NotAnAdmin`).
- `SyncVaultPositions` étendu avec une boucle sur les groupes ayant un `safeAddress`
  (même isolation d'erreurs par groupe que la boucle par utilisateur existante) —
  aucune modification de `VaultPositionReader`/`EthCallBlockchainReader`, déjà
  génériques sur une adresse de wallet quelconque.
- `GroupPotReader`/`GroupPotView` étendus avec des champs on-chain nullable
  (`safeAddress`, `onChainPrincipalDisplay`, `onChainYieldReceivedDisplay`,
  `onChainConnected`, `onChainPaused`), purement additifs — les totaux hors-chaîne
  existants (`potTotalDisplay`, `MemberPotView`) restent inchangés.

### Infrastructure

- `EthSafeReceiptReader` (miroir manuel de `EthReceiptReader` — pas de librairie
  d'ABI/event générique) décode les receipts de déploiement (`ProxyCreation`) et
  d'exécution (`ExecutionSuccess`/`ExecutionFailure`). Point de vérification important
  découvert en implémentant Task 1 : les deux events ont un paramètre **indexé**
  (`proxy` et `txHash` respectivement) — ils se lisent depuis les `topics` du log, pas
  depuis `data`, exactement comme `sender`/`owner` sur l'event `Deposit` du vault.
- `DoctrineSafeTransactionRepository` + `TontineGroupRepositoryInterface::
  findAllWithSafeAddress()`, mêmes conventions Doctrine que l'existant (types custom
  `wallet_address`/`transaction_hash` réutilisés tels quels).

### Présentation

- `GroupSafeController` (2 endpoints de confirmation, même style que
  `DepositConfirmationController`) ; `TontineGroupController::show()` expose `isAdmin` ;
  templates : dépôt de groupe masqué tant que `group.safeAddress` est nul, bloc
  admin-only pour provisionner le coffre et le connecter au yield pool, affichage de la
  position on-chain du groupe dans le dashboard.
- `assets/react/lib/safeTransactions.js` : mécanisme générique et réutilisable
  d'exécution de transaction Safe (`signAndExecuteSafeTransaction`) — lit le nonce
  on-chain, construit le typed-data EIP-712 localement (nécessaire pour un
  `signTypedData` non aveugle côté wallet), et **croise le résultat avec
  `getTransactionHash()` on-chain** avant de signer (garde-fou contre une divergence de
  domaine/struct qui échouerait sinon silencieusement on-chain). Utilisé pour
  `connectPool` dans cette phase, réutilisé tel quel par le retrait en Phase 2.
- `VaultDeposit.jsx` : `receiver` devient un prop optionnel (`props.receiverAddress ??
  wallet.address`), l'auto-connect au yield pool est désactivé quand on dépose vers un
  Safe de groupe (cette connexion devient une action Safe-signée distincte, pas quelque
  chose que le membre déposant déclenche).

### Vérification des adresses/ABI on-chain

Adresses Safe v1.4.1 (`SafeL2`) sur Base (chainId 8453) hand-vérifiées (jamais devinées)
contre `safe-global/safe-deployments`, BaseScan (« Exact Match ») et Sourcify, croisées
avec le code source Solidity tagué `v1.4.1` — voir `config/abi/README.md` pour le détail
complet des sources et deux corrections trouvées en cours de route (indexation des
events, `stateMutability` de `execTransaction`).

## Hors périmètre

- **Le retrait effectif** (`redeem`/`withdraw` exécuté depuis le Safe) — Phase 2,
  réutilisera `ConfirmGroupSafeExecution` et `signAndExecuteSafeTransaction` sans
  modification, seul le `{to, data}` change.
- **Seuil multisig > 1** et mécanisme de promotion multi-admin — le seuil 1-of-N reste
  fonctionnellement 1-of-1 tant qu'il n'existe qu'un admin par groupe ; réévaluer
  `@safe-global/protocol-kit` si la collecte de plusieurs signatures devient nécessaire.
- **Relecture on-chain des owners/seuil du Safe après déploiement** —
  `EthCallBlockchainReader::decodeOutputs` ne décode pas les tableaux dynamiques
  (`address[]`), donc impossible d'appeler `Safe.getOwners()` pour re-vérifier après
  coup. La confiance repose sur la vérification du rôle admin côté serveur avant
  d'autoriser le déploiement, pas sur une relecture on-chain des owners.
