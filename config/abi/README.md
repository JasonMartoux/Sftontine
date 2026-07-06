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
