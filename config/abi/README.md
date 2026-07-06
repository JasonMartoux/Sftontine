# ABI figées — SuperVault (Base mainnet, chainId 8453)

Fragments ABI (lecture seule, M2) vérifiés directement contre le code source
déployé/vérifié sur BaseScan et le skill `superfluid` (`SuperfluidPool.abi.yaml`,
`GDAv1Forwarder.abi.yaml`) — pas de signature devinée.

| Fichier | Contrat | Adresse (Base mainnet) |
|---|---|---|
| `StableYieldSyncVault.json` | Vault ERC-4626 | `0x8C60503C0353ED12c3Eebc3036BF033A3BbB95Aa` |
| `SyncFundManager.json` | Fund manager (`YIELD_POOL()`/`FEE_POOL()`/`stableYieldRate()`) | `0x904103dfE7231e2534e0Be29E6086CB0FF7d76bd` |
| `SuperfluidPool.json` | Pool GDA `YIELD_POOL` | résolu dynamiquement via `SyncFundManager.YIELD_POOL()`, jamais hardcodé |
| `GDAv1Forwarder.json` | Forwarder GDA (`isMemberConnected`) | `0x6DA13Bde224A05a288748d857b9e7DDEffd1dE08` (adresse uniforme multi-réseaux) |

**Pas de `paused()` externe** : `StableYieldSyncVault._isExternallyPaused()` est
`internal` (confirmé dans le code source vérifié), donc non appelable en
`eth_call`. Le signal d'intégrateur documenté est `maxDeposit`/`maxWithdraw`/
`maxRedeem`/`maxMint` qui retournent `0` en état paused.

Écriture (`deposit`, `depositWithPermit`, `connectPool`, `redeem`,
`SyncVaultMacro`/`ClearMacroForwarder`) hors scope ici — voir JAS-18 (M3).

**`stableYieldRate()`** : natspec confirmé sur le code source déployé —
« *expressed in basis point (e.g. 100 <=> 1%)* ». Taux protocole global (pas
par membre), utilisé pour l'APR affiché sur le dashboard et la simulation
« si vous déposiez... ».
