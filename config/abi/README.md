# ABI figées — SuperVault (Base mainnet, chainId 8453)

Fragments ABI vérifiés contre le code source déployé/vérifié sur BaseScan, le
skill `superfluid` (`SuperfluidPool.abi.yaml`, `GDAv1Forwarder.abi.yaml`) et le
[guide d'intégration SuperVault](https://supervault.suplabs.org/docs/integrators/integration-guide)
— pas de signature devinée.

| Fichier | Contrat | Adresse (Base mainnet) |
|---|---|---|
| `StableYieldSyncVault.json` | Vault ERC-4626 | `0x8C60503C0353ED12c3Eebc3036BF033A3BbB95Aa` |
| `SyncFundManager.json` | Fund manager (`YIELD_POOL()`/`FEE_POOL()`/`stableYieldRate()`) | `0x904103dfE7231e2534e0Be29E6086CB0FF7d76bd` |
| `SuperfluidPool.json` | Pool GDA `YIELD_POOL` | résolu dynamiquement via `SyncFundManager.YIELD_POOL()`, jamais hardcodé |
| `GDAv1Forwarder.json` | Forwarder GDA (`isMemberConnected`, `connectPool`) | `0x6DA13Bde224A05a288748d857b9e7DDEffd1dE08` (adresse uniforme multi-réseaux) |
| `USDC.json` | ERC-20 USDC (`approve`, `allowance`, `balanceOf`, `decimals`) | `0x833589fCD6eDb6E08f4c7C32D4f71b54bdA02913` |
| `GnosisSafeProxyFactory.json` | Safe Proxy Factory v1.4.1 (`SafeProxyFactory`) | `0x4e1DCf7AD4e460CfD30791CCC4F9c8a4f820ec67` |
| `GnosisSafe.json` | Safe singleton `SafeL2` v1.4.1 | `0x29fcB43b46531BcA003ddC8FCB67FFE91900C762` |

**Pas de `paused()` externe** : `StableYieldSyncVault._isExternallyPaused()` est
`internal` (confirmé dans le code source vérifié), donc non appelable en
`eth_call`. Le signal d'intégrateur documenté est `maxDeposit`/`maxWithdraw`/
`maxRedeem`/`maxMint` qui retournent `0` en état paused (déclenché côté
FundManager quand la position Morpho sous-jacente est dépréciée).

**`stableYieldRate()`** : natspec confirmé sur le code source déployé —
« *expressed in basis point (e.g. 100 <=> 1%)* ». Taux protocole global (pas
par membre), utilisé pour l'APR affiché sur le dashboard et la simulation
« si vous déposiez... ».

## Écriture — flow de dépôt classique (M3, JAS-18/19)

Le guide d'intégration documente deux chemins pour déposer : un flow
« gasless » (macro EIP-712 relayée via `SyncVaultMacro`/`ClearMacroForwarder`,
bundlant `depositWithPermit()` + `connectPool()` en une seule tx) et un flow
« classique » on-chain, tx signées directement par l'utilisateur. **Sftontine
utilise le flow classique** (wallet embedded Privy, viem) :

```
// 1. Approve le montant exact
USDC.approve(vault, assets)

// 2. Deposit (prévoir un buffer de gas ~2x l'estimation, TX_GAS_BUFFER)
vault.deposit(assets, receiver)   // -> event Deposit(sender, owner, assets, shares)

// 3. Le membre n'est PAS auto-connecté sur ce chemin : connecter le pool
//    séparément si le stream de yield doit compter dans le solde du wallet.
gdaForwarder.connectPool(yieldPoolAddress, "0x")   // yieldPoolAddress = SyncFundManager.YIELD_POOL()
```

- `deposit(uint256 assets, address receiver) returns (uint256 shares)` — ERC-4626
  standard, émet l'event `Deposit(address indexed sender, address indexed owner, uint256 assets, uint256 shares)`.
- `connectPool(address pool, bytes userData) returns (bool)` — exposé par le
  **même** `GDAv1Forwarder` que `isMemberConnected` (M2), pas par le vault
  lui-même. `pool` = adresse du yield pool, résolue dynamiquement (jamais
  hardcodée), `userData` peut être `"0x"` (vide).
- Le buffer de gas 2x (`TX_GAS_BUFFER`) s'applique aux trois opérations
  d'écriture : `deposit`, `redeem`, `connectPool`.
- **`ensureYieldFlowDuration()`** : fonction de maintenance appelée par un
  *opérateur* (pas par le frontend/l'utilisateur) entre deux activités, pour
  réapprovisionner la réserve USDCx et garder le stream solvable. Le
  pre-funding initial du stream (au moins `guaranteedFlowDuration`, minimum
  1 jour) a lieu automatiquement côté FundManager au moment du `deposit` —
  aucune action frontend requise pour ça.
- `depositWithPermit`, `redeem`, `SyncVaultMacro`/`ClearMacroForwarder` (flow
  gasless) restent hors scope de M3 (JAS-19 cible explicitement le flow
  classique approve→deposit→connectPool).

## Coffre de groupe (Safe) — Phase 1

Chaque `TontineGroup` a son propre Safe (seuil 1-of-N, owners = admins du groupe),
déployé via `createProxyWithNonce(singleton, initializer, saltNonce)` où `initializer`
encode l'appel à `setup(owners, 1, address(0), "0x", fallbackHandler, address(0), 0,
address(0))`. `saltNonce` = l'id du groupe (unique par déploiement, cf. Task 6).
Exécuter un appel via le Safe (`connectPool` ici, `redeem` en Phase 2) se fait via
`execTransaction`, signé EIP-712 par un admin (voir `assets/react/lib/safeTransactions.js`).

Adresses **Gnosis Safe v1.4.1** (variante `SafeL2`, requise sur L2 — voir plus bas),
Base mainnet (chainId 8453), sourcées de
[`safe-global/safe-deployments`](https://github.com/safe-global/safe-deployments/tree/main/src/assets/v1.4.1)
(`safe_proxy_factory.json`, `safe_l2.json`, `compatibility_fallback_handler.json` —
entrée `"canonical"` sous `networkAddresses["8453"]`), chacune hand-vérifiée sur
BaseScan (code source vérifié, exact match) :

| Rôle | Adresse | Contrat vérifié (BaseScan) |
|---|---|---|
| `SAFE_PROXY_FACTORY_ADDRESS` | `0x4e1DCf7AD4e460CfD30791CCC4F9c8a4f820ec67` | `SafeProxyFactory` — [code](https://basescan.org/address/0x4e1DCf7AD4e460CfD30791CCC4F9c8a4f820ec67#code) |
| `SAFE_SINGLETON_ADDRESS` | `0x29fcB43b46531BcA003ddC8FCB67FFE91900C762` | `SafeL2` — [code](https://basescan.org/address/0x29fcB43b46531BcA003ddC8FCB67FFE91900C762#code) |
| `SAFE_FALLBACK_HANDLER_ADDRESS` | `0xfd0732Dc9E303f09fCEf3a7388Ad10A83459Ec99` | `CompatibilityFallbackHandler` — [code](https://basescan.org/address/0xfd0732Dc9E303f09fCEf3a7388Ad10A83459Ec99#code) |

Le code source vérifié (BaseScan, exact match) et l'ABI compilée (cross-vérifiée via
[Sourcify](https://sourcify.dev), full match) correspondent au tag
[`v1.4.1`](https://github.com/safe-global/safe-contracts/tree/v1.4.1) de
`safe-global/safe-contracts`. Il n'y a pas d'ABI figée pour le fallback handler : son
adresse est seulement passée en paramètre de `setup()`, il n'est jamais appelé
directement par ce repo.

**Pourquoi `SafeL2` et pas `Safe`** : sur un L2 comme Base, l'indexation par les
explorateurs/indexeurs suppose les events enrichis émis par `SafeL2`
(`SafeMultiSigTransaction`, `SafeModuleTransaction`, en plus des events hérités de
`Safe`). `Safe` (mastercopy L1) fonctionnerait techniquement aussi, mais diverge de
ce que l'écosystème attend sur un déploiement L2.

**Shapes confirmées sur le code source vérifié (BaseScan + tag `v1.4.1` de
`safe-global/safe-contracts`, cross-vérifiées via l'ABI compilée Sourcify)** — la
brief du plan avait cette étape *à vérifier*, et deux détails divergent de sa
description initiale :

- `ProxyCreation(address indexed proxy, address singleton)` — émis par
  `SafeProxyFactory.createProxyWithNonce()`. **`proxy` est indexé** (`singleton` ne
  l'est pas) — `SafeProxyFactory.sol` : `event ProxyCreation(SafeProxy indexed proxy,
  address singleton);`. La brief du plan indiquait à tort que les deux paramètres
  n'étaient pas indexés.
- `ExecutionSuccess(bytes32 indexed txHash, uint256 payment)` /
  `ExecutionFailure(bytes32 indexed txHash, uint256 payment)` — émis par le Safe
  lui-même (`Safe.sol`). **`txHash` est indexé** (`payment` ne l'est pas) — la brief
  indiquait à tort l'inverse. Confirmé à la fois par le code source Solidity taggé
  `v1.4.1` et par l'ABI compilée servie par Sourcify pour l'adresse déployée sur
  Base.
- `execTransaction(...)` a pour `stateMutability` **`payable`** (pas `nonpayable`
  comme suggéré par la brief) — `Safe.sol` : `function execTransaction(...) public
  payable virtual returns (bool success)`. Sftontine appelle toujours avec
  `value = 0` (aucun transfert natif nécessaire pour `connectPool`/`redeem`), donc ça
  ne change rien fonctionnellement, mais l'ABI doit refléter la vraie signature.
- `EIP712Domain(uint256 chainId, address verifyingContract)` — confirmé :
  `Safe.sol` définit `DOMAIN_SEPARATOR_TYPEHASH = keccak256("EIP712Domain(uint256
  chainId,address verifyingContract)")` et `domainSeparator()` retourne
  `keccak256(abi.encode(DOMAIN_SEPARATOR_TYPEHASH, getChainId(), this))` — conforme
  à la description de la brief.
- `execTransaction` self-financé sans remboursement : `safeTxGas=0` (utilise tout le
  gas disponible), `baseGas=0`, **`gasPrice=0`** (c'est ce qui désactive le
  remboursement — `gasToken`/`refundReceiver` deviennent alors indifférents et
  peuvent rester à `address(0)`), confirmé par le guide Safe docs sur
  `execTransaction`. Conforme à la description de la brief.
