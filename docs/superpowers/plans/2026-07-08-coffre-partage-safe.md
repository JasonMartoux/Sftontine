# Coffre Partagé de Groupe (Gnosis Safe) — Phase 1 Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Give every `TontineGroup` its own on-chain Safe (Gnosis Safe multisig, 1-of-N admin owners) that receives group deposits instead of each member's own wallet, connect that Safe to the Superfluid yield pool, and surface its on-chain principal/yield on the group dashboard — the foundation Phase 2 (admin-triggered withdrawal) will build on.

**Architecture:** A new `Domain/Safe` module (mirroring `Domain/Deposit`) tracks Safe deployment/execution transactions the same way `DepositTransaction` tracks deposits: frontend submits a signed tx, backend confirms it from the on-chain receipt. `TontineGroup` gains a nullable `safeAddress`, set once a deployment receipt confirms. The Safe is deployed eagerly (not counterfactually) right after an admin clicks "provisionner le coffre" — deferring deployment buys nothing since connecting the Safe to the yield pool needs a deployed contract anyway. A single reusable frontend helper (`signAndExecuteSafeTransaction`) builds, EIP-712-signs, and submits any Safe `execTransaction` call; this phase uses it only for `connectPool`, and Phase 2's withdrawal reuses it unchanged for `redeem`.

**Tech Stack:** PHP 8.5 / Symfony 8.1, Doctrine ORM, PHPUnit, Twig, Symfony UX (React islands + Live Components), viem + Privy embedded wallets (JS), Gnosis Safe v1.4.1 (SafeL2) contracts on Base mainnet (chainId 8453). Spec: this plan was authored directly from an approved Plan-Mode design (`~/.claude/plans/concernant-le-projet-actuel-woolly-salamander.md`) plus Linear tickets JAS-37→42.

## Global Constraints

- Everything runs in Docker — every command below is a `make` target, never `vendor/bin/*`/`bin/console` directly (breaks permissions on the host, see `CLAUDE.md`).
- `make qa` (test + phpstan + deptrac + cs) must stay green after every task; run `make cs-fix` before committing if `make cs` fails.
- Never use `float` for amounts — reuse `moneyphp/money`/`BcMath\Number` exactly as the existing Deposit/Vault modules do.
- Domain (`src/Domain/`) stays framework-free — only `Doctrine\ORM\Mapping` (attributes), `moneyphp/money`, `BcMath\Number` are allowed there (enforced by `make deptrac`). Application ports only depend on Domain + PSR interfaces; bindings go in `config/services.yaml`, never rely on autowiring alone for interface→adapter.
- Never guess an on-chain address, ABI signature, or EIP-712 domain shape — hand-verify against `safe-global/safe-deployments` + BaseScan's verified source first (Task 1). This mirrors the existing discipline in `config/abi/README.md`.
- French UI strings throughout (existing convention in this codebase).
- `Result<T, E of \BackedEnum>` (`src/Application/Shared/Result.php`) is the pattern for expected business errors (not-found, wrong role, etc.) — never throw for those; only truly unexpected states throw.
- Doctrine repositories in this codebase are thin `EntityManager` wrappers with **no dedicated unit tests** (verified: no `DoctrineDepositTransactionRepositoryTest` exists) — covered indirectly through use-case tests (mocked ports) and the Anvil integration test. Follow that precedent; don't invent a new testing convention for `DoctrineSafeTransactionRepository`.
- `assets/react/controllers/*.jsx` files are auto-discovered by `@symfony/ux-react` by filename — no entry in `assets/controllers.json` needed (verified: `VaultDeposit.jsx` has no such entry either).

---

### Task 1: Pin Safe addresses + ABI fragments (no code, verification + data)

**Files:**
- Create: `config/abi/GnosisSafeProxyFactory.json`
- Create: `config/abi/GnosisSafe.json`
- Modify: `config/abi/README.md`
- Modify: `.env` (placeholders only — real values filled after verification, never guessed)

**Interfaces:**
- Produces: `SAFE_PROXY_FACTORY_ADDRESS`, `SAFE_SINGLETON_ADDRESS`, `SAFE_FALLBACK_HANDLER_ADDRESS` env vars that every later task's `.env`/`services.yaml`/`twig.yaml` wiring consumes.

This task has no PHPUnit cycle — like JAS-18's ABI-pinning ticket, it's research + committed data, verified against primary sources, not unit-tested.

- [ ] **Step 1: Source and hand-verify the addresses**

  Look up `safe-global/safe-deployments` (GitHub) for Safe **v1.4.1**, network `8453` (Base):
  - `src/assets/v1.4.1/proxy_factory.json` → `networkAddresses["8453"]` → this is `SAFE_PROXY_FACTORY_ADDRESS`.
  - `src/assets/v1.4.1/safe_l2.json` → `networkAddresses["8453"]` → this is `SAFE_SINGLETON_ADDRESS` (use **`SafeL2`**, not plain `Safe` — Base is an L2 and `SafeL2` emits the richer `ExecutionSuccess`/`ExecutionFailure` events with more indexed data that L2 indexers expect; plain `Safe` on an L2 deployment would still work but diverges from what block explorers/indexers assume).
  - `src/assets/v1.4.1/compatibility_fallback_handler.json` → `networkAddresses["8453"]` → this is `SAFE_FALLBACK_HANDLER_ADDRESS` (used so the Safe can validate EIP-1271 signatures / receive ERC-721/1155 safely; harmless to include even though this MVP doesn't need those flows).
  - Cross-check every address resolves to **verified** source code on BaseScan (`https://basescan.org/address/<addr>#code`), and that the verified source matches the official `safe-global/safe-contracts` v1.4.1 tag.

- [ ] **Step 2: Confirm event and function shapes from the verified BaseScan source**

  Record (for use in Tasks 9/16/19):
  - `ProxyCreation(address proxy, address singleton)` — emitted by the factory, both params **not** indexed (confirm from ABI on BaseScan).
  - `ExecutionSuccess(bytes32 txHash, uint256 payment)` / `ExecutionFailure(bytes32 txHash, uint256 payment)` — emitted by the Safe itself, `txHash` **not** indexed (confirm from ABI on BaseScan).
  - `EIP712Domain(uint256 chainId, address verifyingContract)` — Safe ≥1.3.0 includes `chainId` (confirm on the verified source's `domainSeparator()` / `_domainSeparator` implementation).
  - `execTransaction`'s self-financed no-refund parameter set: `safeTxGas=0, baseGas=0, gasPrice=0, gasToken=0x0, refundReceiver=0x0` (confirm against the Safe docs' "Sending a transaction" guide, not memory).

- [ ] **Step 3: Write the ABI fragments**

  `config/abi/GnosisSafeProxyFactory.json`:
  ```json
  [
      {
          "type": "function",
          "name": "createProxyWithNonce",
          "stateMutability": "nonpayable",
          "inputs": [
              { "name": "_singleton", "type": "address" },
              { "name": "initializer", "type": "bytes" },
              { "name": "saltNonce", "type": "uint256" }
          ],
          "outputs": [{ "name": "proxy", "type": "address" }]
      },
      {
          "type": "event",
          "name": "ProxyCreation",
          "inputs": [
              { "name": "proxy", "type": "address", "indexed": false },
              { "name": "singleton", "type": "address", "indexed": false }
          ]
      }
  ]
  ```

  `config/abi/GnosisSafe.json`:
  ```json
  [
      {
          "type": "function",
          "name": "setup",
          "stateMutability": "nonpayable",
          "inputs": [
              { "name": "_owners", "type": "address[]" },
              { "name": "_threshold", "type": "uint256" },
              { "name": "to", "type": "address" },
              { "name": "data", "type": "bytes" },
              { "name": "fallbackHandler", "type": "address" },
              { "name": "paymentToken", "type": "address" },
              { "name": "payment", "type": "uint256" },
              { "name": "paymentReceiver", "type": "address" }
          ],
          "outputs": []
      },
      {
          "type": "function",
          "name": "nonce",
          "stateMutability": "view",
          "inputs": [],
          "outputs": [{ "name": "", "type": "uint256" }]
      },
      {
          "type": "function",
          "name": "getTransactionHash",
          "stateMutability": "view",
          "inputs": [
              { "name": "to", "type": "address" },
              { "name": "value", "type": "uint256" },
              { "name": "data", "type": "bytes" },
              { "name": "operation", "type": "uint8" },
              { "name": "safeTxGas", "type": "uint256" },
              { "name": "baseGas", "type": "uint256" },
              { "name": "gasPrice", "type": "uint256" },
              { "name": "gasToken", "type": "address" },
              { "name": "refundReceiver", "type": "address" },
              { "name": "_nonce", "type": "uint256" }
          ],
          "outputs": [{ "name": "", "type": "bytes32" }]
      },
      {
          "type": "function",
          "name": "execTransaction",
          "stateMutability": "nonpayable",
          "inputs": [
              { "name": "to", "type": "address" },
              { "name": "value", "type": "uint256" },
              { "name": "data", "type": "bytes" },
              { "name": "operation", "type": "uint8" },
              { "name": "safeTxGas", "type": "uint256" },
              { "name": "baseGas", "type": "uint256" },
              { "name": "gasPrice", "type": "uint256" },
              { "name": "gasToken", "type": "address" },
              { "name": "refundReceiver", "type": "address" },
              { "name": "signatures", "type": "bytes" }
          ],
          "outputs": [{ "name": "", "type": "bool" }]
      },
      {
          "type": "event",
          "name": "ExecutionSuccess",
          "inputs": [
              { "name": "txHash", "type": "bytes32", "indexed": false },
              { "name": "payment", "type": "uint256", "indexed": false }
          ]
      },
      {
          "type": "event",
          "name": "ExecutionFailure",
          "inputs": [
              { "name": "txHash", "type": "bytes32", "indexed": false },
              { "name": "payment", "type": "uint256", "indexed": false }
          ]
      }
  ]
  ```

- [ ] **Step 4: Document in `config/abi/README.md`**

  Add a new row to the address table and a new section mirroring the existing "Écriture — flow de dépôt classique" section, e.g.:
  ```markdown
  | `GnosisSafeProxyFactory.json` | Safe Proxy Factory v1.4.1 | (adresse vérifiée Task 1) |
  | `GnosisSafe.json` | Safe singleton `SafeL2` v1.4.1 | (adresse vérifiée Task 1) |

  ## Coffre de groupe (Safe) — Phase 1

  Chaque `TontineGroup` a son propre Safe (seuil 1-of-N, owners = admins du groupe),
  déployé via `createProxyWithNonce(singleton, initializer, saltNonce)` où `initializer`
  encode l'appel à `setup(owners, 1, address(0), "0x", fallbackHandler, address(0), 0,
  address(0))`. `saltNonce` = l'id du groupe (unique par déploiement, cf. Task 6).
  Exécuter un appel via le Safe (`connectPool` ici, `redeem` en Phase 2) se fait via
  `execTransaction`, signé EIP-712 par un admin (voir `assets/react/lib/safeTransactions.js`).
  ```

- [ ] **Step 5: Add `.env` placeholders**

  ```
  ###> app/safe ###
  # SAFE_PROXY_FACTORY_ADDRESS / SAFE_SINGLETON_ADDRESS / SAFE_FALLBACK_HANDLER_ADDRESS :
  # Gnosis Safe v1.4.1 (SafeL2), Base mainnet (chainId 8453) — sourcées de
  # safe-global/safe-deployments, hand-vérifiées sur BaseScan (voir config/abi/README.md).
  SAFE_PROXY_FACTORY_ADDRESS=
  SAFE_SINGLETON_ADDRESS=
  SAFE_FALLBACK_HANDLER_ADDRESS=
  ###< app/safe ###
  ```

- [ ] **Step 6: Commit**

  ```bash
  git add config/abi/GnosisSafeProxyFactory.json config/abi/GnosisSafe.json config/abi/README.md .env
  git commit -m "feat: pin Gnosis Safe v1.4.1 ABI fragments and addresses for Base"
  ```

---

### Task 2: Fix `DepositTransaction::applyReceipt` to validate `sender`, not `owner`

**Files:**
- Modify: `src/Domain/Deposit/DepositTransaction.php`
- Modify: `tests/Domain/Deposit/DepositTransactionTest.php`

**Interfaces:**
- Consumes: nothing new.
- Produces: `DepositTransaction::applyReceipt()` unchanged signature, corrected invariant — later tasks (group deposits, Task 12 onward) rely on this being fixed first, since a group deposit's `receiver` (`owner` in the `Deposit` event) will be the group's Safe, never the depositing member's own wallet.

Once group deposits set `receiver = safeAddress`, `owner` in the emitted `Deposit` event will never equal the depositing member's wallet — every group deposit would be wrongly marked `Failed` under the current `owner`-based check. `sender` (`msg.sender`, whoever actually signed) is the correct, strictly-more-general invariant: identical to today's behavior for personal deposits (`sender == owner == wallet.address`).

- [ ] **Step 1: Update the existing test to assert the new (correct) invariant**

  In `tests/Domain/Deposit/DepositTransactionTest.php`, replace `testSuccessfulReceiptForADifferentOwnerIsMarkedFailed` with two tests:

  ```php
  public function testSuccessfulReceiptWithMatchingSenderButDifferentOwnerConfirms(): void
  {
      $tx = $this->makePending();

      $tx->applyReceipt(new TransactionReceipt(
          success: true,
          depositEvent: new DepositEvent(
              sender: self::WALLET,
              owner: '0x3333333333333333333333333333333333333333',
              assets: new Number('1000000'),
              shares: new Number('1000000'),
          ),
      ));

      self::assertSame(DepositTransactionStatus::Confirmed, $tx->status);
  }

  public function testSuccessfulReceiptForADifferentSenderIsMarkedFailed(): void
  {
      $tx = $this->makePending();

      $tx->applyReceipt(new TransactionReceipt(
          success: true,
          depositEvent: new DepositEvent(
              sender: '0x3333333333333333333333333333333333333333',
              owner: self::WALLET,
              assets: new Number('1000000'),
              shares: new Number('1000000'),
          ),
      ));

      self::assertSame(DepositTransactionStatus::Failed, $tx->status);
  }
  ```

  Also rename `testSuccessfulReceiptWithMatchingOwnerConfirmsAndRecordsAmount` to `testSuccessfulReceiptWithMatchingSenderConfirmsAndRecordsAmount` (same body — `sender == owner == WALLET` already, still a valid case, just renamed for accuracy).

- [ ] **Step 2: Run to verify the new/changed tests fail**

  Run: `make test c="--filter=DepositTransactionTest"`
  Expected: FAIL — `testSuccessfulReceiptWithMatchingSenderButDifferentOwnerConfirms` fails because the code still checks `owner`, not `sender`.

- [ ] **Step 3: Apply the fix**

  In `src/Domain/Deposit/DepositTransaction.php`, in `applyReceipt()`:
  ```php
  public function applyReceipt(TransactionReceipt $receipt): void
  {
      if ($receipt->success
          && null !== $receipt->depositEvent
          && $this->walletAddress->equals(new WalletAddress($receipt->depositEvent->sender))
      ) {
          $this->amountMinorUnits = (string) $receipt->depositEvent->assets;
          $this->status = DepositTransactionStatus::Confirmed;
      } else {
          $this->status = DepositTransactionStatus::Failed;
      }

      $this->confirmedAt = new \DateTimeImmutable();
  }
  ```
  Update the docblock above it: replace "whose `owner` matches this transaction's wallet" with "whose `sender` (the actual signer) matches this transaction's wallet — not `owner`/`receiver`, which for a group deposit is the group's Safe, never the depositing member's own wallet".

- [ ] **Step 4: Run to verify all Deposit tests pass**

  Run: `make test c="--filter=DepositTransactionTest"`
  Expected: PASS, all cases green.

- [ ] **Step 5: Run the full deposit-related suite for regressions**

  Run: `make test c="--filter=Deposit"`
  Expected: PASS — in particular `tests/Presentation/TontineJourneyTest.php`'s `seedConfirmedDeposit` helper seeds `sender == owner == walletAddress`, so it is unaffected by this change (verified by inspection before this task started).

- [ ] **Step 6: Commit**

  ```bash
  git add src/Domain/Deposit/DepositTransaction.php tests/Domain/Deposit/DepositTransactionTest.php
  git commit -m "fix: validate deposit confirmation against sender, not owner/receiver"
  ```

---

### Task 3: `Domain/Safe` — status/purpose enums + `SafeTransaction` entity

**Files:**
- Create: `src/Domain/Safe/SafeTransactionPurpose.php`
- Create: `src/Domain/Safe/SafeTransactionStatus.php`
- Create: `src/Domain/Safe/SafeTransaction.php`
- Create: `tests/Domain/Safe/SafeTransactionTest.php`

**Interfaces:**
- Produces: `SafeTransactionPurpose::Deployment`, `SafeTransactionPurpose::ConnectYieldPool`; `SafeTransactionStatus::Pending`/`Confirmed`/`Failed`; `SafeTransaction::pendingDeployment(int $groupId, TransactionHash $txHash): self`, `SafeTransaction::pendingExecution(int $groupId, SafeTransactionPurpose $purpose, TransactionHash $txHash, TransactionHash $safeTxHash): self`, `->applyDeploymentReceipt(bool $success, ?WalletAddress $proxyAddress): void`, `->applyExecutionReceipt(bool $success): void`, `->isPending(): bool`, public readonly-ish properties `groupId`, `purpose`, `txHash`, `safeTxHash` (nullable), `safeAddress` (nullable), `status`. Consumed by Task 6/7/8 use cases and Task 10 repository.

Mirrors `Domain/Deposit/DepositTransaction` exactly: no FK to `TontineGroup` (same precedent as `DepositTransaction`↔wallet — `groupId` is a plain column). `safeTxHash` is the Safe's own `bytes32` transaction hash (from `getTransactionHash()`), same shape as an Ethereum tx hash, so it reuses the `TransactionHash` VO — only populated for `ConnectYieldPool`/future `Redeem` purposes, never for `Deployment` (a factory deployment has no separate "Safe tx hash", only the Ethereum `txHash` of the `createProxyWithNonce` call).

- [ ] **Step 1: Write the failing tests**

  ```php
  <?php

  declare(strict_types=1);

  namespace App\Tests\Domain\Safe;

  use App\Domain\Deposit\TransactionHash;
  use App\Domain\Identity\WalletAddress;
  use App\Domain\Safe\SafeTransaction;
  use App\Domain\Safe\SafeTransactionPurpose;
  use App\Domain\Safe\SafeTransactionStatus;
  use PHPUnit\Framework\TestCase;

  final class SafeTransactionTest extends TestCase
  {
      private const SAFE_ADDRESS = '0x4444444444444444444444444444444444444444';

      public function testPendingDeploymentStartsPendingWithNoSafeAddress(): void
      {
          $tx = SafeTransaction::pendingDeployment(1, $this->txHash('a1'));

          self::assertTrue($tx->isPending());
          self::assertSame(SafeTransactionStatus::Pending, $tx->status);
          self::assertSame(SafeTransactionPurpose::Deployment, $tx->purpose);
          self::assertNull($tx->safeAddress);
          self::assertNull($tx->safeTxHash);
      }

      public function testSuccessfulDeploymentReceiptConfirmsAndRecordsSafeAddress(): void
      {
          $tx = SafeTransaction::pendingDeployment(1, $this->txHash('a1'));

          $tx->applyDeploymentReceipt(true, new WalletAddress(self::SAFE_ADDRESS));

          self::assertSame(SafeTransactionStatus::Confirmed, $tx->status);
          self::assertNotNull($tx->safeAddress);
          self::assertSame(self::SAFE_ADDRESS, $tx->safeAddress->value);
      }

      public function testFailedDeploymentReceiptIsMarkedFailed(): void
      {
          $tx = SafeTransaction::pendingDeployment(1, $this->txHash('a1'));

          $tx->applyDeploymentReceipt(false, null);

          self::assertSame(SafeTransactionStatus::Failed, $tx->status);
          self::assertNull($tx->safeAddress);
      }

      public function testSuccessfulDeploymentWithoutAnAddressIsMarkedFailed(): void
      {
          $tx = SafeTransaction::pendingDeployment(1, $this->txHash('a1'));

          $tx->applyDeploymentReceipt(true, null);

          self::assertSame(SafeTransactionStatus::Failed, $tx->status);
      }

      public function testPendingExecutionStartsPendingWithSafeTxHash(): void
      {
          $tx = SafeTransaction::pendingExecution(1, SafeTransactionPurpose::ConnectYieldPool, $this->txHash('a1'), $this->txHash('b2'));

          self::assertTrue($tx->isPending());
          self::assertSame(SafeTransactionPurpose::ConnectYieldPool, $tx->purpose);
          self::assertNotNull($tx->safeTxHash);
          self::assertSame('0x'.str_repeat('b2', 32), $tx->safeTxHash->value);
      }

      public function testSuccessfulExecutionReceiptConfirms(): void
      {
          $tx = SafeTransaction::pendingExecution(1, SafeTransactionPurpose::ConnectYieldPool, $this->txHash('a1'), $this->txHash('b2'));

          $tx->applyExecutionReceipt(true);

          self::assertSame(SafeTransactionStatus::Confirmed, $tx->status);
      }

      public function testFailedExecutionReceiptIsMarkedFailed(): void
      {
          $tx = SafeTransaction::pendingExecution(1, SafeTransactionPurpose::ConnectYieldPool, $this->txHash('a1'), $this->txHash('b2'));

          $tx->applyExecutionReceipt(false);

          self::assertSame(SafeTransactionStatus::Failed, $tx->status);
      }

      private function txHash(string $pair): TransactionHash
      {
          return new TransactionHash('0x'.str_repeat($pair, 32));
      }
  }
  ```

- [ ] **Step 2: Run to verify it fails**

  Run: `make test c="--filter=SafeTransactionTest"`
  Expected: FAIL — class `App\Domain\Safe\SafeTransaction` not found.

- [ ] **Step 3: Write the enums and entity**

  `src/Domain/Safe/SafeTransactionPurpose.php`:
  ```php
  <?php

  declare(strict_types=1);

  namespace App\Domain\Safe;

  enum SafeTransactionPurpose: string
  {
      case Deployment = 'deployment';
      case ConnectYieldPool = 'connect_yield_pool';
  }
  ```

  `src/Domain/Safe/SafeTransactionStatus.php`:
  ```php
  <?php

  declare(strict_types=1);

  namespace App\Domain\Safe;

  enum SafeTransactionStatus: string
  {
      case Pending = 'pending';
      case Confirmed = 'confirmed';
      case Failed = 'failed';
  }
  ```

  `src/Domain/Safe/SafeTransaction.php`:
  ```php
  <?php

  declare(strict_types=1);

  namespace App\Domain\Safe;

  use App\Domain\Deposit\TransactionHash;
  use App\Domain\Identity\WalletAddress;
  use Doctrine\ORM\Mapping as ORM;

  /**
   * Tracks a Safe deployment or execTransaction from the moment its txHash is submitted
   * (pending) until its receipt is confirmed on-chain (confirmed/failed) — same lifecycle
   * shape as DepositTransaction, but for a group's Safe rather than a member's deposit.
   * Not FK'd to TontineGroup (same precedent as DepositTransaction<->wallet): groupId is a
   * plain column, looked up via the group repository when a side effect is needed.
   */
  #[ORM\Entity]
  #[ORM\Table(name: 'safe_transaction')]
  #[ORM\Index(columns: ['status'], name: 'idx_safe_transaction_status')]
  #[ORM\Index(columns: ['group_id', 'purpose'], name: 'idx_safe_transaction_group_purpose')]
  final class SafeTransaction
  {
      #[ORM\Id]
      #[ORM\GeneratedValue(strategy: 'IDENTITY')]
      #[ORM\Column(type: 'integer')]
      public private(set) ?int $id = null;

      private function __construct(
          #[ORM\Column(name: 'group_id', type: 'integer')]
          public private(set) int $groupId,
          #[ORM\Column(name: 'purpose', type: 'string', length: 32, enumType: SafeTransactionPurpose::class)]
          public private(set) SafeTransactionPurpose $purpose,
          #[ORM\Column(name: 'tx_hash', type: 'transaction_hash', length: 66, unique: true)]
          public private(set) TransactionHash $txHash,
          #[ORM\Column(name: 'safe_tx_hash', type: 'transaction_hash', length: 66, nullable: true)]
          public private(set) ?TransactionHash $safeTxHash,
          #[ORM\Column(name: 'safe_address', type: 'wallet_address', length: 42, nullable: true)]
          public private(set) ?WalletAddress $safeAddress,
          #[ORM\Column(name: 'status', type: 'string', length: 16, enumType: SafeTransactionStatus::class)]
          public private(set) SafeTransactionStatus $status,
          #[ORM\Column(name: 'created_at', type: 'datetime_immutable')]
          public readonly \DateTimeImmutable $createdAt,
          #[ORM\Column(name: 'confirmed_at', type: 'datetime_immutable', nullable: true)]
          public private(set) ?\DateTimeImmutable $confirmedAt,
      ) {
      }

      public static function pendingDeployment(int $groupId, TransactionHash $txHash): self
      {
          return new self($groupId, SafeTransactionPurpose::Deployment, $txHash, null, null, SafeTransactionStatus::Pending, new \DateTimeImmutable(), null);
      }

      public static function pendingExecution(int $groupId, SafeTransactionPurpose $purpose, TransactionHash $txHash, TransactionHash $safeTxHash): self
      {
          return new self($groupId, $purpose, $txHash, $safeTxHash, null, SafeTransactionStatus::Pending, new \DateTimeImmutable(), null);
      }

      public function applyDeploymentReceipt(bool $success, ?WalletAddress $proxyAddress): void
      {
          if ($success && null !== $proxyAddress) {
              $this->safeAddress = $proxyAddress;
              $this->status = SafeTransactionStatus::Confirmed;
          } else {
              $this->status = SafeTransactionStatus::Failed;
          }

          $this->confirmedAt = new \DateTimeImmutable();
      }

      public function applyExecutionReceipt(bool $success): void
      {
          $this->status = $success ? SafeTransactionStatus::Confirmed : SafeTransactionStatus::Failed;
          $this->confirmedAt = new \DateTimeImmutable();
      }

      public function isPending(): bool
      {
          return SafeTransactionStatus::Pending === $this->status;
      }
  }
  ```

- [ ] **Step 4: Run to verify it passes**

  Run: `make test c="--filter=SafeTransactionTest"`
  Expected: PASS, all 7 cases green.

- [ ] **Step 5: Commit**

  ```bash
  git add src/Domain/Safe tests/Domain/Safe
  git commit -m "feat: add Domain/Safe module (SafeTransaction lifecycle)"
  ```

---

### Task 4: `TontineGroup` — `safeAddress`, `provisionSafe()`, `adminWalletAddresses()`, `hasSafe()`

**Files:**
- Modify: `src/Domain/Tontine/TontineGroup.php`
- Modify: `tests/Domain/Tontine/TontineGroupTest.php`

**Interfaces:**
- Consumes: `App\Domain\Identity\WalletAddress` (new import), `MembershipRole` (already in-namespace, no import needed).
- Produces: `TontineGroup::$safeAddress` (public, nullable `WalletAddress`), `->provisionSafe(WalletAddress $safeAddress): void`, `->hasSafe(): bool`, `->adminWalletAddresses(): array` (`list<string>`) — consumed by Task 6 (`ConfirmGroupSafeDeployment`), Task 11 (`SyncVaultPositions` group loop), Task 12 (`GroupPotReader`), and the frontend (Task 14 controller exposes it to the template).

- [ ] **Step 1: Write the failing tests**

  Add to `tests/Domain/Tontine/TontineGroupTest.php` (add `use App\Domain\Identity\WalletAddress;` if not already imported — it is not currently imported in this file even though `WalletAddress` is used via `self::alice()`'s `User::registerFromPrivy(..., new WalletAddress(...))`; check the existing `use` block and add it if missing):

  ```php
  public function testHasNoSafeByDefault(): void
  {
      $group = $this->makeGroup();

      self::assertFalse($group->hasSafe());
      self::assertNull($group->safeAddress);
  }

  public function testProvisionSafeSetsAddress(): void
  {
      $group = $this->makeGroup();
      $safeAddress = new WalletAddress('0x4444444444444444444444444444444444444444');

      $group->provisionSafe($safeAddress);

      self::assertTrue($group->hasSafe());
      self::assertNotNull($group->safeAddress);
      self::assertTrue($group->safeAddress->equals($safeAddress));
  }

  public function testProvisionSafeIsIdempotentForTheSameAddress(): void
  {
      $group = $this->makeGroup();
      $safeAddress = new WalletAddress('0x4444444444444444444444444444444444444444');

      $group->provisionSafe($safeAddress);
      $group->provisionSafe($safeAddress);

      self::assertTrue($group->hasSafe());
  }

  public function testProvisionSafeRejectsChangingToADifferentAddress(): void
  {
      $group = $this->makeGroup();
      $group->provisionSafe(new WalletAddress('0x4444444444444444444444444444444444444444'));

      $this->expectException(\LogicException::class);
      $group->provisionSafe(new WalletAddress('0x5555555555555555555555555555555555555555'));
  }

  public function testAdminWalletAddressesReturnsOnlyTheCreator(): void
  {
      $group = $this->makeGroup();
      $group->join(self::bob(), new \DateTimeImmutable(self::NOW));

      self::assertSame(['0x1111111111111111111111111111111111111111'], $group->adminWalletAddresses());
  }
  ```

  Note: `makeGroup()` is the existing private helper at the bottom of `TontineGroupTest.php` (line ~295, `return TontineGroup::create(...)`) — reuse it, don't duplicate group construction. Check its exact name in the file (visible at line 295 as an unnamed private helper returning `TontineGroup::create(...)`) and call it as-is.

- [ ] **Step 2: Run to verify it fails**

  Run: `make test c="--filter=TontineGroupTest"`
  Expected: FAIL — `hasSafe()`/`provisionSafe()`/`adminWalletAddresses()` don't exist.

- [ ] **Step 3: Implement**

  In `src/Domain/Tontine/TontineGroup.php`:
  1. Add `use App\Domain\Identity\WalletAddress;` to the `use` block.
  2. Add a new property right after `public private(set) ?int $id = null;`:
     ```php
     #[ORM\Column(name: 'safe_address', type: 'wallet_address', length: 42, nullable: true)]
     public private(set) ?WalletAddress $safeAddress = null;
     ```
  3. Add these methods (near `potTotal()`/`membershipFor()`):
     ```php
     public function provisionSafe(WalletAddress $safeAddress): void
     {
         if (null !== $this->safeAddress && !$this->safeAddress->equals($safeAddress)) {
             throw new \LogicException('This group already has a different Safe provisioned.');
         }

         $this->safeAddress = $safeAddress;
     }

     public function hasSafe(): bool
     {
         return null !== $this->safeAddress;
     }

     /**
      * @return list<string>
      */
     public function adminWalletAddresses(): array
     {
         $addresses = [];
         foreach ($this->memberships as $membership) {
             if (MembershipRole::Admin === $membership->role) {
                 $addresses[] = $membership->user->walletAddress->value;
             }
         }

         return $addresses;
     }
     ```

- [ ] **Step 4: Run to verify it passes**

  Run: `make test c="--filter=TontineGroupTest"`
  Expected: PASS, all cases (existing + new) green.

- [ ] **Step 5: Commit**

  ```bash
  git add src/Domain/Tontine/TontineGroup.php tests/Domain/Tontine/TontineGroupTest.php
  git commit -m "feat: add safeAddress/provisionSafe/adminWalletAddresses to TontineGroup"
  ```

---

### Task 5: Migration — `tontine_group.safe_address` + `safe_transaction` table

**Files:**
- Create: `migrations/VersionYYYYMMDDHHMMSS.php` (timestamp auto-generated by the tool — filename will differ from this placeholder, that's expected and not a plan gap)

No PHPUnit cycle — schema migrations in this codebase aren't unit-tested, verified end-to-end by running `make migrate` against the dev database.

- [ ] **Step 1: Generate the migration skeleton**

  Run: `make sf c="doctrine:migrations:diff"`
  Expected: a new `migrations/VersionYYYYMMDDHHMMSS.php` file is created, auto-detecting the `TontineGroup::$safeAddress` column from Task 4 and the new `SafeTransaction` entity/table from Task 3.

- [ ] **Step 2: Verify and, if needed, hand-correct the generated SQL**

  Open the generated file and confirm it matches (edit `up()`/`down()` to match exactly if the diff tool produced something different, e.g. a different column order):
  ```php
  public function getDescription(): string
  {
      return 'Coffre de groupe (Safe) : tontine_group.safe_address + table safe_transaction.';
  }

  public function up(Schema $schema): void
  {
      $this->addSql('ALTER TABLE tontine_group ADD safe_address VARCHAR(42) DEFAULT NULL');
      $this->addSql(<<<'SQL'
          CREATE TABLE safe_transaction (
              id SERIAL NOT NULL,
              group_id INT NOT NULL,
              purpose VARCHAR(32) NOT NULL,
              tx_hash VARCHAR(66) NOT NULL,
              safe_tx_hash VARCHAR(66) DEFAULT NULL,
              safe_address VARCHAR(42) DEFAULT NULL,
              status VARCHAR(16) NOT NULL,
              created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
              confirmed_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL,
              PRIMARY KEY(id)
          )
      SQL);
      $this->addSql('CREATE UNIQUE INDEX UNIQ_safe_transaction_tx_hash ON safe_transaction (tx_hash)');
      $this->addSql('CREATE INDEX idx_safe_transaction_status ON safe_transaction (status)');
      $this->addSql('CREATE INDEX idx_safe_transaction_group_purpose ON safe_transaction (group_id, purpose)');
  }

  public function down(Schema $schema): void
  {
      $this->addSql('DROP TABLE safe_transaction');
      $this->addSql('ALTER TABLE tontine_group DROP safe_address');
  }
  ```

- [ ] **Step 3: Run the migration**

  Run: `make migrate`
  Expected: migration applies cleanly, no errors.

- [ ] **Step 4: Commit**

  ```bash
  git add migrations/
  git commit -m "feat: add migration for group Safe (tontine_group.safe_address + safe_transaction)"
  ```

---

### Task 6: `Application/Safe` ports + DTOs + `ConfirmGroupSafeDeployment`

**Files:**
- Create: `src/Application/Safe/Port/SafeTransactionRepositoryInterface.php`
- Create: `src/Application/Safe/Port/SafeReceiptReaderInterface.php`
- Create: `src/Application/Safe/Dto/SafeDeploymentReceipt.php`
- Create: `src/Application/Safe/Dto/SafeExecutionReceipt.php`
- Create: `src/Application/Safe/Dto/GroupSafeView.php`
- Create: `src/Application/Safe/UseCase/GroupSafeError.php`
- Create: `src/Application/Safe/UseCase/ConfirmGroupSafeDeployment.php`
- Create: `tests/Application/Safe/UseCase/ConfirmGroupSafeDeploymentTest.php`

**Interfaces:**
- Consumes: `TontineGroupRepositoryInterface` (existing), `SafeTransactionRepositoryInterface`/`SafeReceiptReaderInterface` (new, this task), `App\Domain\Safe\SafeTransaction`/`SafeTransactionPurpose` (Task 3), `TontineGroup::provisionSafe()`/`membershipFor()` (Task 4).
- Produces: `ConfirmGroupSafeDeployment.__invoke(User $admin, int $groupId, TransactionHash $txHash): Result<GroupSafeView, GroupSafeError>` — consumed by Task 14's `GroupSafeController`.

- [ ] **Step 1: Write the ports and DTOs (no test — plain interfaces/DTOs, same as `DepositTransactionRepositoryInterface`)**

  `src/Application/Safe/Dto/SafeDeploymentReceipt.php`:
  ```php
  <?php

  declare(strict_types=1);

  namespace App\Application\Safe\Dto;

  final readonly class SafeDeploymentReceipt
  {
      public function __construct(
          public bool $success,
          public ?string $proxyAddress,
      ) {
      }
  }
  ```

  `src/Application/Safe/Dto/SafeExecutionReceipt.php`:
  ```php
  <?php

  declare(strict_types=1);

  namespace App\Application\Safe\Dto;

  final readonly class SafeExecutionReceipt
  {
      public function __construct(
          public bool $success,
      ) {
      }
  }
  ```

  `src/Application/Safe/Dto/GroupSafeView.php`:
  ```php
  <?php

  declare(strict_types=1);

  namespace App\Application\Safe\Dto;

  final readonly class GroupSafeView
  {
      public function __construct(
          public int $groupId,
          public ?string $safeAddress,
          public string $status,
      ) {
      }
  }
  ```

  `src/Application/Safe/Port/SafeTransactionRepositoryInterface.php`:
  ```php
  <?php

  declare(strict_types=1);

  namespace App\Application\Safe\Port;

  use App\Domain\Deposit\TransactionHash;
  use App\Domain\Safe\SafeTransaction;
  use App\Domain\Safe\SafeTransactionPurpose;

  interface SafeTransactionRepositoryInterface
  {
      public function save(SafeTransaction $transaction): void;

      public function findByTxHash(TransactionHash $txHash): ?SafeTransaction;

      /**
       * @return list<SafeTransaction>
       */
      public function findAllPending(): array;

      public function findLatestForGroup(int $groupId, SafeTransactionPurpose $purpose): ?SafeTransaction;
  }
  ```

  `src/Application/Safe/Port/SafeReceiptReaderInterface.php`:
  ```php
  <?php

  declare(strict_types=1);

  namespace App\Application\Safe\Port;

  use App\Application\Safe\Dto\SafeDeploymentReceipt;
  use App\Application\Safe\Dto\SafeExecutionReceipt;
  use App\Domain\Deposit\TransactionHash;

  interface SafeReceiptReaderInterface
  {
      /**
       * @return SafeDeploymentReceipt|null null when the transaction is not yet mined
       */
      public function getDeploymentReceipt(TransactionHash $txHash): ?SafeDeploymentReceipt;

      /**
       * @return SafeExecutionReceipt|null null when the transaction is not yet mined
       */
      public function getExecutionReceipt(TransactionHash $txHash, TransactionHash $safeTxHash): ?SafeExecutionReceipt;
  }
  ```

  `src/Application/Safe/UseCase/GroupSafeError.php`:
  ```php
  <?php

  declare(strict_types=1);

  namespace App\Application\Safe\UseCase;

  enum GroupSafeError: string
  {
      case GroupNotFound = 'group_not_found';
      case NotAnAdmin = 'not_an_admin';
  }
  ```

- [ ] **Step 2: Write the failing test for `ConfirmGroupSafeDeployment`**

  ```php
  <?php

  declare(strict_types=1);

  namespace App\Tests\Application\Safe\UseCase;

  use App\Application\Safe\Dto\SafeDeploymentReceipt;
  use App\Application\Safe\Port\SafeReceiptReaderInterface;
  use App\Application\Safe\Port\SafeTransactionRepositoryInterface;
  use App\Application\Safe\UseCase\ConfirmGroupSafeDeployment;
  use App\Application\Safe\UseCase\GroupSafeError;
  use App\Application\Tontine\Port\TontineGroupRepositoryInterface;
  use App\Domain\Deposit\TransactionHash;
  use App\Domain\Identity\User;
  use App\Domain\Identity\WalletAddress;
  use App\Domain\Safe\SafeTransaction;
  use App\Domain\Safe\SafeTransactionStatus;
  use App\Domain\Tontine\Periodicity;
  use App\Domain\Tontine\TontineGroup;
  use Money\Currency;
  use Money\Money;
  use PHPUnit\Framework\TestCase;

  final class ConfirmGroupSafeDeploymentTest extends TestCase
  {
      private const ADMIN_WALLET = '0x1111111111111111111111111111111111111111';
      private const MEMBER_WALLET = '0x2222222222222222222222222222222222222222';
      private const SAFE_ADDRESS = '0x4444444444444444444444444444444444444444';

      public function testReturnsGroupNotFound(): void
      {
          $groups = $this->createStub(TontineGroupRepositoryInterface::class);
          $groups->method('find')->willReturn(null);

          $useCase = new ConfirmGroupSafeDeployment($groups, $this->createStub(SafeTransactionRepositoryInterface::class), $this->createStub(SafeReceiptReaderInterface::class));
          $result = $useCase($this->admin(), 1, $this->txHash());

          self::assertFalse($result->isSuccess);
          self::assertSame(GroupSafeError::GroupNotFound, $result->error());
      }

      public function testReturnsNotAnAdminForARegularMember(): void
      {
          $group = $this->makeGroup();
          $group->join($this->member(), new \DateTimeImmutable('2026-01-01T00:00:00+00:00'));

          $groups = $this->createStub(TontineGroupRepositoryInterface::class);
          $groups->method('find')->willReturn($group);

          $useCase = new ConfirmGroupSafeDeployment($groups, $this->createStub(SafeTransactionRepositoryInterface::class), $this->createStub(SafeReceiptReaderInterface::class));
          $result = $useCase($this->member(), 1, $this->txHash());

          self::assertFalse($result->isSuccess);
          self::assertSame(GroupSafeError::NotAnAdmin, $result->error());
      }

      public function testConfirmsDeploymentAndProvisionsTheGroupsSafe(): void
      {
          $group = $this->makeGroup();

          $groups = $this->createMock(TontineGroupRepositoryInterface::class);
          $groups->method('find')->willReturn($group);
          $groups->expects(self::once())->method('save')->with($group);

          $safeTransactions = $this->createMock(SafeTransactionRepositoryInterface::class);
          $safeTransactions->method('findByTxHash')->willReturn(null);
          $safeTransactions->expects(self::once())->method('save');

          $receiptReader = $this->createStub(SafeReceiptReaderInterface::class);
          $receiptReader->method('getDeploymentReceipt')->willReturn(new SafeDeploymentReceipt(true, self::SAFE_ADDRESS));

          $useCase = new ConfirmGroupSafeDeployment($groups, $safeTransactions, $receiptReader);
          $result = $useCase($this->admin(), 1, $this->txHash());

          self::assertTrue($result->isSuccess);
          self::assertSame(self::SAFE_ADDRESS, $result->value()->safeAddress);
          self::assertSame(SafeTransactionStatus::Confirmed->value, $result->value()->status);
          self::assertTrue($group->hasSafe());
      }

      public function testStaysPendingWhenReceiptNotYetAvailable(): void
      {
          $group = $this->makeGroup();

          $groups = $this->createMock(TontineGroupRepositoryInterface::class);
          $groups->method('find')->willReturn($group);
          $groups->expects(self::never())->method('save');

          $safeTransactions = $this->createMock(SafeTransactionRepositoryInterface::class);
          $safeTransactions->method('findByTxHash')->willReturn(null);
          $safeTransactions->expects(self::once())->method('save');

          $receiptReader = $this->createStub(SafeReceiptReaderInterface::class);
          $receiptReader->method('getDeploymentReceipt')->willReturn(null);

          $useCase = new ConfirmGroupSafeDeployment($groups, $safeTransactions, $receiptReader);
          $result = $useCase($this->admin(), 1, $this->txHash());

          self::assertTrue($result->isSuccess);
          self::assertSame(SafeTransactionStatus::Pending->value, $result->value()->status);
          self::assertFalse($group->hasSafe());
      }

      private function admin(): User
      {
          return User::registerFromPrivy('did:privy:admin', 'admin@example.com', new WalletAddress(self::ADMIN_WALLET));
      }

      private function member(): User
      {
          return User::registerFromPrivy('did:privy:member', 'member@example.com', new WalletAddress(self::MEMBER_WALLET));
      }

      private function makeGroup(): TontineGroup
      {
          return TontineGroup::create($this->admin(), 'Tontine famille', new Money('25000000', new Currency('USDC')), Periodicity::Weekly, 12, new \DateTimeImmutable('2026-01-01T00:00:00+00:00'));
      }

      private function txHash(): TransactionHash
      {
          return new TransactionHash('0x'.str_repeat('a1', 32));
      }
  }
  ```

- [ ] **Step 3: Run to verify it fails**

  Run: `make test c="--filter=ConfirmGroupSafeDeploymentTest"`
  Expected: FAIL — class `ConfirmGroupSafeDeployment` not found.

- [ ] **Step 4: Implement**

  `src/Application/Safe/UseCase/ConfirmGroupSafeDeployment.php`:
  ```php
  <?php

  declare(strict_types=1);

  namespace App\Application\Safe\UseCase;

  use App\Application\Safe\Dto\GroupSafeView;
  use App\Application\Safe\Port\SafeReceiptReaderInterface;
  use App\Application\Safe\Port\SafeTransactionRepositoryInterface;
  use App\Application\Shared\Result;
  use App\Application\Tontine\Port\TontineGroupRepositoryInterface;
  use App\Domain\Deposit\TransactionHash;
  use App\Domain\Identity\User;
  use App\Domain\Identity\WalletAddress;
  use App\Domain\Safe\SafeTransaction;
  use App\Domain\Safe\SafeTransactionStatus;
  use App\Domain\Tontine\MembershipRole;

  final readonly class ConfirmGroupSafeDeployment
  {
      public function __construct(
          private TontineGroupRepositoryInterface $groups,
          private SafeTransactionRepositoryInterface $safeTransactions,
          private SafeReceiptReaderInterface $receiptReader,
      ) {
      }

      /**
       * @return Result<GroupSafeView, GroupSafeError>
       */
      public function __invoke(User $admin, int $groupId, TransactionHash $txHash): Result
      {
          $group = $this->groups->find($groupId);
          if (null === $group) {
              return Result::failure(GroupSafeError::GroupNotFound);
          }

          $membership = $group->membershipFor($admin);
          if (null === $membership || MembershipRole::Admin !== $membership->role) {
              return Result::failure(GroupSafeError::NotAnAdmin);
          }

          $safeTransaction = $this->safeTransactions->findByTxHash($txHash)
              ?? SafeTransaction::pendingDeployment($groupId, $txHash);

          $receipt = $this->receiptReader->getDeploymentReceipt($txHash);

          if (null !== $receipt) {
              $proxyAddress = null !== $receipt->proxyAddress ? new WalletAddress($receipt->proxyAddress) : null;
              $safeTransaction->applyDeploymentReceipt($receipt->success, $proxyAddress);

              if (SafeTransactionStatus::Confirmed === $safeTransaction->status && null !== $proxyAddress) {
                  $group->provisionSafe($proxyAddress);
                  $this->groups->save($group);
              }
          }

          $this->safeTransactions->save($safeTransaction);

          return Result::success(new GroupSafeView($groupId, $group->safeAddress?->value, $safeTransaction->status->value));
      }
  }
  ```

- [ ] **Step 5: Run to verify it passes**

  Run: `make test c="--filter=ConfirmGroupSafeDeploymentTest"`
  Expected: PASS, all 4 cases green.

- [ ] **Step 6: Commit**

  ```bash
  git add src/Application/Safe tests/Application/Safe
  git commit -m "feat: add ConfirmGroupSafeDeployment use case"
  ```

---

### Task 7: `ConfirmGroupSafeExecution`

**Files:**
- Create: `src/Application/Safe/UseCase/ConfirmGroupSafeExecution.php`
- Create: `tests/Application/Safe/UseCase/ConfirmGroupSafeExecutionTest.php`

**Interfaces:**
- Consumes: same ports as Task 6.
- Produces: `ConfirmGroupSafeExecution.__invoke(User $admin, int $groupId, SafeTransactionPurpose $purpose, TransactionHash $txHash, TransactionHash $safeTxHash): Result<GroupSafeView, GroupSafeError>` — consumed by Task 14's controller for `connectPool`, and unchanged by Phase 2 for `redeem` (only the `$purpose`/`{to,data}` on the frontend side differs).

- [ ] **Step 1: Write the failing test**

  ```php
  <?php

  declare(strict_types=1);

  namespace App\Tests\Application\Safe\UseCase;

  use App\Application\Safe\Dto\SafeExecutionReceipt;
  use App\Application\Safe\Port\SafeReceiptReaderInterface;
  use App\Application\Safe\Port\SafeTransactionRepositoryInterface;
  use App\Application\Safe\UseCase\ConfirmGroupSafeExecution;
  use App\Application\Safe\UseCase\GroupSafeError;
  use App\Application\Tontine\Port\TontineGroupRepositoryInterface;
  use App\Domain\Deposit\TransactionHash;
  use App\Domain\Identity\User;
  use App\Domain\Identity\WalletAddress;
  use App\Domain\Safe\SafeTransactionPurpose;
  use App\Domain\Safe\SafeTransactionStatus;
  use App\Domain\Tontine\Periodicity;
  use App\Domain\Tontine\TontineGroup;
  use Money\Currency;
  use Money\Money;
  use PHPUnit\Framework\TestCase;

  final class ConfirmGroupSafeExecutionTest extends TestCase
  {
      private const ADMIN_WALLET = '0x1111111111111111111111111111111111111111';

      // IMPORTANT: reuse the same $admin instance across a test (store it, don't call a
      // fresh User::registerFromPrivy(...) more than once) — Membership::isFor() falls
      // back to object identity (===) when both users are unpersisted (id === null), so
      // two separately-constructed "admin" User objects with identical data are NOT
      // recognized as the same member and membershipFor() would wrongly return null.
      // Task 6 hit exactly this bug; avoid it here by building $admin once per test.
      private User $admin;

      protected function setUp(): void
      {
          $this->admin = User::registerFromPrivy('did:privy:admin', 'admin@example.com', new WalletAddress(self::ADMIN_WALLET));
      }

      public function testReturnsGroupNotFound(): void
      {
          $groups = $this->createStub(TontineGroupRepositoryInterface::class);
          $groups->method('find')->willReturn(null);

          $useCase = new ConfirmGroupSafeExecution($groups, $this->createStub(SafeTransactionRepositoryInterface::class), $this->createStub(SafeReceiptReaderInterface::class));
          $result = $useCase($this->admin, 1, SafeTransactionPurpose::ConnectYieldPool, $this->txHash('a1'), $this->txHash('b2'));

          self::assertFalse($result->isSuccess);
          self::assertSame(GroupSafeError::GroupNotFound, $result->error());
      }

      public function testConfirmsExecution(): void
      {
          $group = $this->makeGroup();

          $groups = $this->createStub(TontineGroupRepositoryInterface::class);
          $groups->method('find')->willReturn($group);

          $safeTransactions = $this->createMock(SafeTransactionRepositoryInterface::class);
          $safeTransactions->method('findByTxHash')->willReturn(null);
          $safeTransactions->expects(self::once())->method('save');

          $receiptReader = $this->createStub(SafeReceiptReaderInterface::class);
          $receiptReader->method('getExecutionReceipt')->willReturn(new SafeExecutionReceipt(true));

          $useCase = new ConfirmGroupSafeExecution($groups, $safeTransactions, $receiptReader);
          $result = $useCase($this->admin, 1, SafeTransactionPurpose::ConnectYieldPool, $this->txHash('a1'), $this->txHash('b2'));

          self::assertTrue($result->isSuccess);
          self::assertSame(SafeTransactionStatus::Confirmed->value, $result->value()->status);
      }

      public function testFailedExecutionIsReportedAsFailed(): void
      {
          $group = $this->makeGroup();

          $groups = $this->createStub(TontineGroupRepositoryInterface::class);
          $groups->method('find')->willReturn($group);

          $safeTransactions = $this->createMock(SafeTransactionRepositoryInterface::class);
          $safeTransactions->method('findByTxHash')->willReturn(null);
          $safeTransactions->expects(self::once())->method('save');

          $receiptReader = $this->createStub(SafeReceiptReaderInterface::class);
          $receiptReader->method('getExecutionReceipt')->willReturn(new SafeExecutionReceipt(false));

          $useCase = new ConfirmGroupSafeExecution($groups, $safeTransactions, $receiptReader);
          $result = $useCase($this->admin, 1, SafeTransactionPurpose::ConnectYieldPool, $this->txHash('a1'), $this->txHash('b2'));

          self::assertTrue($result->isSuccess);
          self::assertSame(SafeTransactionStatus::Failed->value, $result->value()->status);
      }

      private function makeGroup(): TontineGroup
      {
          return TontineGroup::create($this->admin, 'Tontine famille', new Money('25000000', new Currency('USDC')), Periodicity::Weekly, 12, new \DateTimeImmutable('2026-01-01T00:00:00+00:00'));
      }

      private function txHash(string $pair): TransactionHash
      {
          return new TransactionHash('0x'.str_repeat($pair, 32));
      }
  }
  ```

- [ ] **Step 2: Run to verify it fails**

  Run: `make test c="--filter=ConfirmGroupSafeExecutionTest"`
  Expected: FAIL — class not found.

- [ ] **Step 3: Implement**

  `src/Application/Safe/UseCase/ConfirmGroupSafeExecution.php`:
  ```php
  <?php

  declare(strict_types=1);

  namespace App\Application\Safe\UseCase;

  use App\Application\Safe\Dto\GroupSafeView;
  use App\Application\Safe\Port\SafeReceiptReaderInterface;
  use App\Application\Safe\Port\SafeTransactionRepositoryInterface;
  use App\Application\Shared\Result;
  use App\Application\Tontine\Port\TontineGroupRepositoryInterface;
  use App\Domain\Deposit\TransactionHash;
  use App\Domain\Identity\User;
  use App\Domain\Safe\SafeTransaction;
  use App\Domain\Safe\SafeTransactionPurpose;
  use App\Domain\Tontine\MembershipRole;

  final readonly class ConfirmGroupSafeExecution
  {
      public function __construct(
          private TontineGroupRepositoryInterface $groups,
          private SafeTransactionRepositoryInterface $safeTransactions,
          private SafeReceiptReaderInterface $receiptReader,
      ) {
      }

      /**
       * @return Result<GroupSafeView, GroupSafeError>
       */
      public function __invoke(User $admin, int $groupId, SafeTransactionPurpose $purpose, TransactionHash $txHash, TransactionHash $safeTxHash): Result
      {
          $group = $this->groups->find($groupId);
          if (null === $group) {
              return Result::failure(GroupSafeError::GroupNotFound);
          }

          $membership = $group->membershipFor($admin);
          if (null === $membership || MembershipRole::Admin !== $membership->role) {
              return Result::failure(GroupSafeError::NotAnAdmin);
          }

          $safeTransaction = $this->safeTransactions->findByTxHash($txHash)
              ?? SafeTransaction::pendingExecution($groupId, $purpose, $txHash, $safeTxHash);

          $receipt = $this->receiptReader->getExecutionReceipt($txHash, $safeTxHash);
          if (null !== $receipt) {
              $safeTransaction->applyExecutionReceipt($receipt->success);
          }

          $this->safeTransactions->save($safeTransaction);

          return Result::success(new GroupSafeView($groupId, $group->safeAddress?->value, $safeTransaction->status->value));
      }
  }
  ```

- [ ] **Step 4: Run to verify it passes**

  Run: `make test c="--filter=ConfirmGroupSafeExecutionTest"`
  Expected: PASS.

- [ ] **Step 5: Commit**

  ```bash
  git add src/Application/Safe/UseCase/ConfirmGroupSafeExecution.php tests/Application/Safe/UseCase/ConfirmGroupSafeExecutionTest.php
  git commit -m "feat: add ConfirmGroupSafeExecution use case (reused by Phase 2 withdrawal)"
  ```

---

### Task 8: `RecheckPendingSafeTransactions`

**Files:**
- Create: `src/Application/Safe/UseCase/RecheckPendingSafeTransactions.php`
- Create: `tests/Application/Safe/UseCase/RecheckPendingSafeTransactionsTest.php`
- Create: `src/Infrastructure/Scheduler/RecheckPendingSafeTransactionsTask.php`

**Interfaces:**
- Consumes: `SafeTransactionRepositoryInterface::findAllPending()`, `SafeReceiptReaderInterface`, `TontineGroupRepositoryInterface`.
- Produces: `RecheckPendingSafeTransactions.__invoke(): void`, periodic task wired the same way as `RecheckPendingDepositTransactionsTask`.

- [ ] **Step 1: Write the failing tests**

  ```php
  <?php

  declare(strict_types=1);

  namespace App\Tests\Application\Safe\UseCase;

  use App\Application\Safe\Dto\SafeDeploymentReceipt;
  use App\Application\Safe\Port\SafeReceiptReaderInterface;
  use App\Application\Safe\Port\SafeTransactionRepositoryInterface;
  use App\Application\Safe\UseCase\RecheckPendingSafeTransactions;
  use App\Application\Tontine\Port\TontineGroupRepositoryInterface;
  use App\Application\Vault\Exception\BlockchainCallException;
  use App\Domain\Deposit\TransactionHash;
  use App\Domain\Identity\User;
  use App\Domain\Identity\WalletAddress;
  use App\Domain\Safe\SafeTransaction;
  use App\Domain\Safe\SafeTransactionStatus;
  use App\Domain\Tontine\Periodicity;
  use App\Domain\Tontine\TontineGroup;
  use Money\Currency;
  use Money\Money;
  use PHPUnit\Framework\TestCase;
  use Psr\Log\NullLogger;

  final class RecheckPendingSafeTransactionsTest extends TestCase
  {
      private const ADMIN_WALLET = '0x1111111111111111111111111111111111111111';
      private const SAFE_ADDRESS = '0x4444444444444444444444444444444444444444';

      public function testConfirmsAPendingDeploymentAndProvisionsTheGroup(): void
      {
          $group = $this->makeGroup();
          $pending = SafeTransaction::pendingDeployment(1, $this->txHash('a1'));

          $safeTransactions = $this->createMock(SafeTransactionRepositoryInterface::class);
          $safeTransactions->method('findAllPending')->willReturn([$pending]);
          $safeTransactions->expects(self::once())->method('save')->with($pending);

          $receiptReader = $this->createStub(SafeReceiptReaderInterface::class);
          $receiptReader->method('getDeploymentReceipt')->willReturn(new SafeDeploymentReceipt(true, self::SAFE_ADDRESS));

          $groups = $this->createMock(TontineGroupRepositoryInterface::class);
          $groups->method('find')->willReturn($group);
          $groups->expects(self::once())->method('save')->with($group);

          (new RecheckPendingSafeTransactions($safeTransactions, $groups, $receiptReader, new NullLogger()))();

          self::assertSame(SafeTransactionStatus::Confirmed, $pending->status);
          self::assertTrue($group->hasSafe());
      }

      public function testLeavesADeploymentPendingWhenNoReceiptYet(): void
      {
          $pending = SafeTransaction::pendingDeployment(1, $this->txHash('a1'));

          $safeTransactions = $this->createMock(SafeTransactionRepositoryInterface::class);
          $safeTransactions->method('findAllPending')->willReturn([$pending]);
          $safeTransactions->expects(self::never())->method('save');

          $receiptReader = $this->createStub(SafeReceiptReaderInterface::class);
          $receiptReader->method('getDeploymentReceipt')->willReturn(null);

          $groups = $this->createMock(TontineGroupRepositoryInterface::class);
          $groups->expects(self::never())->method('save');

          (new RecheckPendingSafeTransactions($safeTransactions, $groups, $receiptReader, new NullLogger()))();

          self::assertTrue($pending->isPending());
      }

      public function testOneTransactionsRpcFailureDoesNotAbortTheOthers(): void
      {
          $failing = SafeTransaction::pendingDeployment(1, $this->txHash('a1'));
          $succeeding = SafeTransaction::pendingDeployment(2, $this->txHash('b2'));

          $safeTransactions = $this->createMock(SafeTransactionRepositoryInterface::class);
          $safeTransactions->method('findAllPending')->willReturn([$failing, $succeeding]);
          $safeTransactions->expects(self::once())->method('save')->with($succeeding);

          $receiptReader = $this->createStub(SafeReceiptReaderInterface::class);
          $receiptReader->method('getDeploymentReceipt')->willReturnCallback(
              static fn (TransactionHash $txHash): SafeDeploymentReceipt => $txHash->equals($failing->txHash)
                  ? throw BlockchainCallException::rpcError('timeout') : new SafeDeploymentReceipt(false, null),
          );

          $groups = $this->createStub(TontineGroupRepositoryInterface::class);

          (new RecheckPendingSafeTransactions($safeTransactions, $groups, $receiptReader, new NullLogger()))();

          self::assertTrue($failing->isPending());
          self::assertSame(SafeTransactionStatus::Failed, $succeeding->status);
      }

      private function makeGroup(): TontineGroup
      {
          $admin = User::registerFromPrivy('did:privy:admin', 'admin@example.com', new WalletAddress(self::ADMIN_WALLET));

          return TontineGroup::create($admin, 'Tontine famille', new Money('25000000', new Currency('USDC')), Periodicity::Weekly, 12, new \DateTimeImmutable('2026-01-01T00:00:00+00:00'));
      }

      private function txHash(string $pair): TransactionHash
      {
          return new TransactionHash('0x'.str_repeat($pair, 32));
      }
  }
  ```

- [ ] **Step 2: Run to verify it fails**

  Run: `make test c="--filter=RecheckPendingSafeTransactionsTest"`
  Expected: FAIL — class not found.

- [ ] **Step 3: Implement the use case**

  `src/Application/Safe/UseCase/RecheckPendingSafeTransactions.php`:
  ```php
  <?php

  declare(strict_types=1);

  namespace App\Application\Safe\UseCase;

  use App\Application\Safe\Port\SafeReceiptReaderInterface;
  use App\Application\Safe\Port\SafeTransactionRepositoryInterface;
  use App\Application\Tontine\Port\TontineGroupRepositoryInterface;
  use App\Application\Vault\Exception\BlockchainCallException;
  use App\Domain\Identity\WalletAddress;
  use App\Domain\Safe\SafeTransactionPurpose;
  use App\Domain\Safe\SafeTransactionStatus;
  use Psr\Log\LoggerInterface;

  /**
   * Periodic catch-up for Safe transactions (deployment or execTransaction) whose txHash was
   * submitted but not yet mined at confirmation time. Mirrors RecheckPendingDepositTransactions:
   * one transaction's RPC failure must not abort the recheck for the others.
   */
  final readonly class RecheckPendingSafeTransactions
  {
      public function __construct(
          private SafeTransactionRepositoryInterface $safeTransactions,
          private TontineGroupRepositoryInterface $groups,
          private SafeReceiptReaderInterface $receiptReader,
          private LoggerInterface $logger,
      ) {
      }

      public function __invoke(): void
      {
          foreach ($this->safeTransactions->findAllPending() as $safeTransaction) {
              try {
                  if (SafeTransactionPurpose::Deployment === $safeTransaction->purpose) {
                      $receipt = $this->receiptReader->getDeploymentReceipt($safeTransaction->txHash);
                      if (null === $receipt) {
                          continue;
                      }

                      $proxyAddress = null !== $receipt->proxyAddress ? new WalletAddress($receipt->proxyAddress) : null;
                      $safeTransaction->applyDeploymentReceipt($receipt->success, $proxyAddress);

                      if (SafeTransactionStatus::Confirmed === $safeTransaction->status && null !== $proxyAddress) {
                          $group = $this->groups->find($safeTransaction->groupId);
                          if (null !== $group) {
                              $group->provisionSafe($proxyAddress);
                              $this->groups->save($group);
                          }
                      }
                  } else {
                      \assert(null !== $safeTransaction->safeTxHash);
                      $receipt = $this->receiptReader->getExecutionReceipt($safeTransaction->txHash, $safeTransaction->safeTxHash);
                      if (null === $receipt) {
                          continue;
                      }

                      $safeTransaction->applyExecutionReceipt($receipt->success);
                  }

                  $this->safeTransactions->save($safeTransaction);
              } catch (BlockchainCallException $e) {
                  $this->logger->error('Safe tx recheck failed for {txHash}: {message}', [
                      'txHash' => (string) $safeTransaction->txHash,
                      'message' => $e->getMessage(),
                  ]);
              }
          }
      }
  }
  ```

- [ ] **Step 4: Run to verify it passes**

  Run: `make test c="--filter=RecheckPendingSafeTransactionsTest"`
  Expected: PASS.

- [ ] **Step 5: Wire the periodic task**

  `src/Infrastructure/Scheduler/RecheckPendingSafeTransactionsTask.php` (mirror `RecheckPendingDepositTransactionsTask.php` exactly — read that file first to copy its `#[AsPeriodicTask]` frequency/attribute usage verbatim, only renaming the invoked use case):
  ```php
  <?php

  declare(strict_types=1);

  namespace App\Infrastructure\Scheduler;

  use App\Application\Safe\UseCase\RecheckPendingSafeTransactions;
  use Symfony\Component\Scheduler\Attribute\AsPeriodicTask;

  #[AsPeriodicTask(frequency: 60, schedule: 'default')]
  final readonly class RecheckPendingSafeTransactionsTask
  {
      public function __construct(
          private RecheckPendingSafeTransactions $recheck,
      ) {
      }

      public function __invoke(): void
      {
          ($this->recheck)();
      }
  }
  ```
  Before finalizing, open `src/Infrastructure/Scheduler/RecheckPendingDepositTransactionsTask.php` and match its exact `frequency`/`schedule` attribute values instead of guessing — copy them verbatim.

- [ ] **Step 6: Commit**

  ```bash
  git add src/Application/Safe/UseCase/RecheckPendingSafeTransactions.php tests/Application/Safe/UseCase/RecheckPendingSafeTransactionsTest.php src/Infrastructure/Scheduler/RecheckPendingSafeTransactionsTask.php
  git commit -m "feat: add periodic recheck for pending Safe transactions"
  ```

---

### Task 9: `EthSafeReceiptReader`

**Files:**
- Create: `src/Infrastructure/Gateways/EthSafeReceiptReader.php`
- Create: `tests/Infrastructure/Gateways/EthSafeReceiptReaderTest.php`

**Interfaces:**
- Produces: implements `SafeReceiptReaderInterface` (Task 6), constructor `(HttpClientInterface $httpClient, string $baseRpcUrl, string $proxyFactoryAddress)`.

Mirrors `EthReceiptReader` exactly: one `eth_getTransactionReceipt` call, manual log decoding (topic0 via keccak, no generic ABI/event library). `getDeploymentReceipt` scans for `ProxyCreation(address,address)` emitted by `$proxyFactoryAddress`; `getExecutionReceipt` scans for `ExecutionSuccess`/`ExecutionFailure(bytes32,uint256)` emitted by the Safe itself (`log.address` is implicitly the Safe — no separate address filter needed since `expectedSafeTxHash` already disambiguates, but for correctness only accept logs whose decoded `txHash` matches `$safeTxHash`).

**Important correction from Task 1's verified ABI** (`config/abi/README.md`'s "Coffre de groupe (Safe)" section — read it before writing this task): both events have an **indexed** parameter, which the original plan draft got wrong. The real signatures are `event ProxyCreation(address indexed proxy, address singleton)` and `event ExecutionSuccess(bytes32 indexed txHash, uint256 payment)` / `ExecutionFailure(bytes32 indexed txHash, uint256 payment)`. An indexed parameter is encoded in the log's `topics` array, **not** in `data` — `data` only holds the non-indexed trailing parameters. This is exactly the same shape `EthReceiptReader::decodeDepositEvent` already handles for the vault's `Deposit(address indexed sender, address indexed owner, uint256 assets, uint256 shares)` event (`sender`/`owner` come from `topics[1]`/`topics[2]`, only `assets`/`shares` come from `data`) — follow that precedent, not a data-word offset.

- `ProxyCreation`: `proxy` (indexed) is `topics[1]`, a 32-byte word — the address is its last 40 hex chars (`'0x'.substr($topics[1], 26)`, same slicing `EthReceiptReader` uses for `sender`/`owner`). `singleton` (not indexed) would be in `data`, but this reader never needs it.
- `ExecutionSuccess`/`ExecutionFailure`: `txHash` (indexed) is `topics[1]` **in full** — it's already a `bytes32`, so no slicing needed, just compare it directly against the expected `$safeTxHash->value` (case-insensitively). `payment` (not indexed) would be in `data`, but this reader never needs it either.

- [ ] **Step 1: Write the failing tests**

  ```php
  <?php

  declare(strict_types=1);

  namespace App\Tests\Infrastructure\Gateways;

  use App\Application\Vault\Exception\BlockchainCallException;
  use App\Domain\Deposit\TransactionHash;
  use App\Infrastructure\Gateways\EthSafeReceiptReader;
  use PHPUnit\Framework\TestCase;
  use Symfony\Component\HttpClient\MockHttpClient;
  use Symfony\Component\HttpClient\Response\MockResponse;

  final class EthSafeReceiptReaderTest extends TestCase
  {
      private const PROXY_FACTORY = '0x6666666666666666666666666666666666666666';
      private const PROXY_ADDRESS = '0x4444444444444444444444444444444444444444';
      private const SAFE_ADDRESS = '0x4444444444444444444444444444444444444444';

      // keccak256("ProxyCreation(address,address)") — independently verifiable.
      private const PROXY_CREATION_TOPIC0 = '0x4f51faf6c4561ff95f067657e43439f0f856d97c04d9ec9070a6199ad418e235';
      // keccak256("ExecutionSuccess(bytes32,uint256)") — independently verifiable.
      private const EXECUTION_SUCCESS_TOPIC0 = '0x442e715f626346e8c54381002da614f62bee8d27386535b2521ec8540898556c';
      // keccak256("ExecutionFailure(bytes32,uint256)") — independently verifiable.
      private const EXECUTION_FAILURE_TOPIC0 = '0x23428b18acfb3ea64b08dc0c1d296ea9c09702c09083ca5272e64d115b687d23';

      public function testDeploymentReceiptDecodesProxyCreation(): void
      {
          // ProxyCreation(address indexed proxy, address singleton) — proxy is indexed
          // (topics[1]), singleton is not (data). This reader only needs proxy.
          $log = [
              'address' => self::PROXY_FACTORY,
              'topics' => [
                  self::PROXY_CREATION_TOPIC0,
                  '0x'.str_pad(strtolower(substr(self::PROXY_ADDRESS, 2)), 64, '0', \STR_PAD_LEFT),
              ],
              'data' => '0x'.str_pad(strtolower('55'.str_repeat('5', 39)), 64, '0', \STR_PAD_LEFT),
          ];

          $reader = new EthSafeReceiptReader($this->clientReturning($this->receiptPayload('0x1', [$log])), 'https://rpc.example/', self::PROXY_FACTORY);
          $receipt = $reader->getDeploymentReceipt($this->txHash('a1'));

          self::assertNotNull($receipt);
          self::assertTrue($receipt->success);
          self::assertSame(strtolower(self::PROXY_ADDRESS), strtolower((string) $receipt->proxyAddress));
      }

      public function testFailedDeploymentTransactionHasNoProxyAddress(): void
      {
          $reader = new EthSafeReceiptReader($this->clientReturning($this->receiptPayload('0x0', [])), 'https://rpc.example/', self::PROXY_FACTORY);
          $receipt = $reader->getDeploymentReceipt($this->txHash('a1'));

          self::assertNotNull($receipt);
          self::assertFalse($receipt->success);
          self::assertNull($receipt->proxyAddress);
      }

      public function testDeploymentNullResultMeansNotYetMined(): void
      {
          $reader = new EthSafeReceiptReader($this->clientReturning(['jsonrpc' => '2.0', 'id' => 1, 'result' => null]), 'https://rpc.example/', self::PROXY_FACTORY);

          self::assertNull($reader->getDeploymentReceipt($this->txHash('a1')));
      }

      public function testExecutionReceiptMatchesExecutionSuccess(): void
      {
          // ExecutionSuccess(bytes32 indexed txHash, uint256 payment) — txHash is indexed
          // (topics[1], the full bytes32 word, no slicing needed), payment is not (data).
          $safeTxHash = $this->txHash('b2');
          $log = [
              'address' => self::SAFE_ADDRESS,
              'topics' => [self::EXECUTION_SUCCESS_TOPIC0, $safeTxHash->value],
              'data' => '0x'.str_pad('0', 64, '0', \STR_PAD_LEFT),
          ];

          $reader = new EthSafeReceiptReader($this->clientReturning($this->receiptPayload('0x1', [$log])), 'https://rpc.example/', self::PROXY_FACTORY);
          $receipt = $reader->getExecutionReceipt($this->txHash('a1'), $safeTxHash);

          self::assertNotNull($receipt);
          self::assertTrue($receipt->success);
      }

      public function testExecutionReceiptMatchesExecutionFailure(): void
      {
          $safeTxHash = $this->txHash('b2');
          $log = [
              'address' => self::SAFE_ADDRESS,
              'topics' => [self::EXECUTION_FAILURE_TOPIC0, $safeTxHash->value],
              'data' => '0x'.str_pad('0', 64, '0', \STR_PAD_LEFT),
          ];

          $reader = new EthSafeReceiptReader($this->clientReturning($this->receiptPayload('0x1', [$log])), 'https://rpc.example/', self::PROXY_FACTORY);
          $receipt = $reader->getExecutionReceipt($this->txHash('a1'), $safeTxHash);

          self::assertNotNull($receipt);
          self::assertFalse($receipt->success);
      }

      public function testExecutionReceiptForADifferentSafeTxHashIsIgnored(): void
      {
          $log = [
              'address' => self::SAFE_ADDRESS,
              'topics' => [self::EXECUTION_SUCCESS_TOPIC0, $this->txHash('c3')->value],
              'data' => '0x'.str_pad('0', 64, '0', \STR_PAD_LEFT),
          ];

          $reader = new EthSafeReceiptReader($this->clientReturning($this->receiptPayload('0x1', [$log])), 'https://rpc.example/', self::PROXY_FACTORY);

          self::assertNull($reader->getExecutionReceipt($this->txHash('a1'), $this->txHash('b2')));
      }

      public function testJsonRpcErrorFieldIsTranslatedToDomainException(): void
      {
          $reader = new EthSafeReceiptReader(
              $this->clientReturning(['jsonrpc' => '2.0', 'id' => 1, 'error' => ['code' => -32000, 'message' => 'not found']]),
              'https://rpc.example/',
              self::PROXY_FACTORY,
          );

          $this->expectException(BlockchainCallException::class);
          $reader->getDeploymentReceipt($this->txHash('a1'));
      }

      /**
       * @param list<array{address: string, topics: list<string>, data: string}> $logs
       *
       * @return array{jsonrpc: string, id: int, result: array{status: string, logs: list<array{address: string, topics: list<string>, data: string}>}}
       */
      private function receiptPayload(string $status, array $logs): array
      {
          return ['jsonrpc' => '2.0', 'id' => 1, 'result' => ['status' => $status, 'logs' => $logs]];
      }

      private function txHash(string $pair): TransactionHash
      {
          return new TransactionHash('0x'.str_repeat($pair, 32));
      }

      /**
       * @param array<string, mixed> $payload
       */
      private function clientReturning(array $payload): MockHttpClient
      {
          return new MockHttpClient(static fn () => new MockResponse(json_encode($payload, \JSON_THROW_ON_ERROR)));
      }
  }
  ```

  Before running, double-check the three `keccak256(...)` topic0 constants above by computing them independently (e.g. via a throwaway `php -r` using `kornrunner/keccak` inside the container, or an online verified keccak256 calculator) rather than trusting them blindly — same discipline as the existing `EthReceiptReaderTest::DEPOSIT_TOPIC0` constant.

- [ ] **Step 2: Run to verify it fails**

  Run: `make test c="--filter=EthSafeReceiptReaderTest"`
  Expected: FAIL — class not found.

- [ ] **Step 3: Implement**

  `src/Infrastructure/Gateways/EthSafeReceiptReader.php`:
  ```php
  <?php

  declare(strict_types=1);

  namespace App\Infrastructure\Gateways;

  use App\Application\Safe\Dto\SafeDeploymentReceipt;
  use App\Application\Safe\Dto\SafeExecutionReceipt;
  use App\Application\Safe\Port\SafeReceiptReaderInterface;
  use App\Application\Vault\Exception\BlockchainCallException;
  use App\Domain\Deposit\TransactionHash;
  use kornrunner\Keccak;
  use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
  use Symfony\Contracts\HttpClient\HttpClientInterface;

  /**
   * Reads a Safe deployment or execTransaction receipt via a single JSON-RPC
   * `eth_getTransactionReceipt`, manually decoding the ProxyCreation/ExecutionSuccess/
   * ExecutionFailure events — same manual ABI approach as EthReceiptReader.
   */
  final readonly class EthSafeReceiptReader implements SafeReceiptReaderInterface
  {
      private const PROXY_CREATION_SIGNATURE = 'ProxyCreation(address,address)';
      private const EXECUTION_SUCCESS_SIGNATURE = 'ExecutionSuccess(bytes32,uint256)';
      private const EXECUTION_FAILURE_SIGNATURE = 'ExecutionFailure(bytes32,uint256)';

      public function __construct(
          private HttpClientInterface $httpClient,
          private string $baseRpcUrl,
          private string $proxyFactoryAddress,
      ) {
      }

      public function getDeploymentReceipt(TransactionHash $txHash): ?SafeDeploymentReceipt
      {
          $result = $this->fetchReceipt($txHash);
          if (null === $result) {
              return null;
          }

          [$success, $logs] = $result;
          if (!$success) {
              return new SafeDeploymentReceipt(false, null);
          }

          $topic0 = '0x'.Keccak::hash(self::PROXY_CREATION_SIGNATURE, 256);

          foreach ($logs as $log) {
              if (!$this->isFromAddress($log, $this->proxyFactoryAddress) || !$this->hasTopic0($log, $topic0)) {
                  continue;
              }

              // `proxy` is indexed (topics[1]) — ProxyCreation(address indexed proxy, address singleton).
              $topics = $log['topics'] ?? null;
              $proxyTopic = \is_array($topics) ? ($topics[1] ?? null) : null;
              if (!\is_string($proxyTopic)) {
                  continue;
              }

              return new SafeDeploymentReceipt(true, '0x'.substr($proxyTopic, 26));
          }

          return new SafeDeploymentReceipt(false, null);
      }

      public function getExecutionReceipt(TransactionHash $txHash, TransactionHash $safeTxHash): ?SafeExecutionReceipt
      {
          $result = $this->fetchReceipt($txHash);
          if (null === $result) {
              return null;
          }

          [, $logs] = $result;

          $successTopic0 = '0x'.Keccak::hash(self::EXECUTION_SUCCESS_SIGNATURE, 256);
          $failureTopic0 = '0x'.Keccak::hash(self::EXECUTION_FAILURE_SIGNATURE, 256);
          $expected = strtolower($safeTxHash->value);

          foreach ($logs as $log) {
              // `txHash` is indexed (topics[1]) — ExecutionSuccess/Failure(bytes32 indexed
              // txHash, uint256 payment). It's already a full bytes32, no slicing needed.
              $topics = $log['topics'] ?? null;
              $loggedTxHash = \is_array($topics) && \is_string($topics[1] ?? null) ? strtolower($topics[1]) : null;

              if ($loggedTxHash !== $expected) {
                  continue;
              }

              if ($this->hasTopic0($log, $successTopic0)) {
                  return new SafeExecutionReceipt(true);
              }

              if ($this->hasTopic0($log, $failureTopic0)) {
                  return new SafeExecutionReceipt(false);
              }
          }

          return null;
      }

      /**
       * @return array{0: bool, 1: list<array<string, mixed>>}|null
       */
      private function fetchReceipt(TransactionHash $txHash): ?array
      {
          try {
              $response = $this->httpClient->request('POST', $this->baseRpcUrl, [
                  'json' => [
                      'jsonrpc' => '2.0',
                      'id' => 1,
                      'method' => 'eth_getTransactionReceipt',
                      'params' => [(string) $txHash],
                  ],
              ]);

              $payload = $response->toArray(false);
          } catch (TransportExceptionInterface $e) {
              throw BlockchainCallException::rpcError($e->getMessage());
          }

          if (isset($payload['error'])) {
              $message = \is_array($payload['error']) ? ($payload['error']['message'] ?? 'unknown error') : 'unknown error';
              throw BlockchainCallException::rpcError(\is_string($message) ? $message : 'unknown error');
          }

          $result = $payload['result'] ?? null;
          if (null === $result) {
              return null;
          }

          if (!\is_array($result)) {
              throw BlockchainCallException::malformedResponse('Missing "result" field in eth_getTransactionReceipt response.');
          }

          $success = '0x1' === ($result['status'] ?? null);
          $logs = \is_array($result['logs'] ?? null) ? $result['logs'] : [];

          return [$success, $logs];
      }

      /**
       * @param array<string, mixed> $log
       */
      private function isFromAddress(array $log, string $address): bool
      {
          $logAddress = $log['address'] ?? null;

          return \is_string($logAddress) && strtolower($logAddress) === strtolower($address);
      }

      /**
       * @param array<string, mixed> $log
       */
      private function hasTopic0(array $log, string $topic0): bool
      {
          $topics = $log['topics'] ?? null;
          if (!\is_array($topics)) {
              return false;
          }

          $eventTopic = $topics[0] ?? null;

          return \is_string($eventTopic) && strtolower($eventTopic) === strtolower($topic0);
      }
  }
  ```

- [ ] **Step 4: Run to verify it passes**

  Run: `make test c="--filter=EthSafeReceiptReaderTest"`
  Expected: PASS, all cases green.

- [ ] **Step 5: Commit**

  ```bash
  git add src/Infrastructure/Gateways/EthSafeReceiptReader.php tests/Infrastructure/Gateways/EthSafeReceiptReaderTest.php
  git commit -m "feat: add EthSafeReceiptReader for Safe deployment/execution receipts"
  ```

---

### Task 10: `DoctrineSafeTransactionRepository` + `findAllWithSafeAddress()` + bindings

**Files:**
- Create: `src/Infrastructure/Persistence/DoctrineSafeTransactionRepository.php`
- Modify: `src/Application/Tontine/Port/TontineGroupRepositoryInterface.php`
- Modify: `src/Infrastructure/Persistence/DoctrineTontineGroupRepository.php`
- Modify: `config/services.yaml`
- Modify: `.env` (already has the Safe section from Task 1 — no change needed here, just confirm `BASE_RPC_URL` reuse)

**Interfaces:**
- Produces: `TontineGroupRepositoryInterface::findAllWithSafeAddress(): array` (`list<TontineGroup>`) — consumed by Task 11's `SyncVaultPositions`.

No dedicated unit test for the repository classes (established precedent — see Global Constraints); `findAllWithSafeAddress()`'s query logic is exercised indirectly by Task 11's use-case test (mocked interface) and the Task 19 Anvil integration test (real database).

- [ ] **Step 1: Add the interface method**

  In `src/Application/Tontine/Port/TontineGroupRepositoryInterface.php`, add:
  ```php
  /**
   * @return list<TontineGroup>
   */
  public function findAllWithSafeAddress(): array;
  ```

- [ ] **Step 2: Implement in `DoctrineTontineGroupRepository`**

  Add to `src/Infrastructure/Persistence/DoctrineTontineGroupRepository.php`:
  ```php
  public function findAllWithSafeAddress(): array
  {
      $ids = $this->entityManager->createQueryBuilder()
          ->select('g.id')
          ->from(TontineGroup::class, 'g')
          ->where('g.safeAddress IS NOT NULL')
          ->getQuery()
          ->getSingleColumnResult();

      $groups = [];
      foreach ($ids as $id) {
          \assert(is_numeric($id));
          $group = $this->find((int) $id);
          if (null !== $group) {
              $groups[] = $group;
          }
      }

      return $groups;
  }
  ```
  Routing through `$this->find()` (not a direct hydration) reuses the existing `attachPersistedChildren()` guard, exactly like `findAllForUserId()` above it in the same file.

- [ ] **Step 3: Implement `DoctrineSafeTransactionRepository`**

  ```php
  <?php

  declare(strict_types=1);

  namespace App\Infrastructure\Persistence;

  use App\Application\Safe\Port\SafeTransactionRepositoryInterface;
  use App\Domain\Deposit\TransactionHash;
  use App\Domain\Safe\SafeTransaction;
  use App\Domain\Safe\SafeTransactionPurpose;
  use App\Domain\Safe\SafeTransactionStatus;
  use Doctrine\ORM\EntityManagerInterface;

  final readonly class DoctrineSafeTransactionRepository implements SafeTransactionRepositoryInterface
  {
      public function __construct(
          private EntityManagerInterface $entityManager,
      ) {
      }

      public function save(SafeTransaction $transaction): void
      {
          $this->entityManager->persist($transaction);
          $this->entityManager->flush();
      }

      public function findByTxHash(TransactionHash $txHash): ?SafeTransaction
      {
          return $this->entityManager->getRepository(SafeTransaction::class)->findOneBy(['txHash' => $txHash]);
      }

      public function findAllPending(): array
      {
          return $this->entityManager->getRepository(SafeTransaction::class)->findBy(
              ['status' => SafeTransactionStatus::Pending],
          );
      }

      public function findLatestForGroup(int $groupId, SafeTransactionPurpose $purpose): ?SafeTransaction
      {
          return $this->entityManager->getRepository(SafeTransaction::class)->findOneBy(
              ['groupId' => $groupId, 'purpose' => $purpose],
              ['createdAt' => 'DESC'],
          );
      }
  }
  ```

- [ ] **Step 4: Wire bindings in `config/services.yaml`**

  Add next to the existing Deposit/Vault bindings:
  ```yaml
  App\Application\Safe\Port\SafeTransactionRepositoryInterface: '@App\Infrastructure\Persistence\DoctrineSafeTransactionRepository'
  App\Application\Safe\Port\SafeReceiptReaderInterface: '@App\Infrastructure\Gateways\EthSafeReceiptReader'

  App\Infrastructure\Gateways\EthSafeReceiptReader:
      arguments:
          $baseRpcUrl: '%env(BASE_RPC_URL)%'
          $proxyFactoryAddress: '%env(SAFE_PROXY_FACTORY_ADDRESS)%'
  ```

- [ ] **Step 5: Run the full suite to check nothing broke**

  Run: `make test`
  Expected: PASS.

- [ ] **Step 6: Commit**

  ```bash
  git add src/Infrastructure/Persistence/DoctrineSafeTransactionRepository.php src/Infrastructure/Persistence/DoctrineTontineGroupRepository.php src/Application/Tontine/Port/TontineGroupRepositoryInterface.php config/services.yaml
  git commit -m "feat: wire Safe repository/receipt-reader bindings + findAllWithSafeAddress"
  ```

---

### Task 11: `SyncVaultPositions` — sync group Safe positions too

**Files:**
- Modify: `src/Application/Vault/UseCase/SyncVaultPositions.php`
- Create: `tests/Application/Vault/UseCase/SyncVaultPositionsTest.php` (did not previously exist — verified by search; this task adds first-time coverage for this use case, both the pre-existing per-user loop and the new group loop)

**Interfaces:**
- Consumes: `TontineGroupRepositoryInterface::findAllWithSafeAddress()` (Task 10).
- Produces: no new public interface — `SyncVaultPositions.__invoke()` behavior extended, still called unchanged by `SyncVaultPositionsTask`.

- [ ] **Step 1: Write the failing tests**

  ```php
  <?php

  declare(strict_types=1);

  namespace App\Tests\Application\Vault\UseCase;

  use App\Application\Identity\Port\UserRepositoryInterface;
  use App\Application\Tontine\Port\TontineGroupRepositoryInterface;
  use App\Application\Vault\Exception\BlockchainCallException;
  use App\Application\Vault\Port\YieldSnapshotRepositoryInterface;
  use App\Application\Vault\UseCase\SyncVaultPositions;
  use App\Application\Vault\UseCase\VaultPositionReader;
  use App\Domain\Identity\User;
  use App\Domain\Identity\WalletAddress;
  use App\Domain\Tontine\Periodicity;
  use App\Domain\Tontine\TontineGroup;
  use App\Domain\Vault\VaultPosition;
  use BcMath\Number;
  use Money\Currency;
  use Money\Money;
  use PHPUnit\Framework\TestCase;
  use Psr\Log\NullLogger;

  final class SyncVaultPositionsTest extends TestCase
  {
      private const USER_WALLET = '0x1111111111111111111111111111111111111111';
      private const SAFE_ADDRESS = '0x4444444444444444444444444444444444444444';

      public function testSyncsEveryUserAndEveryGroupWithASafe(): void
      {
          $user = User::registerFromPrivy('did:privy:alice', 'alice@example.com', new WalletAddress(self::USER_WALLET));
          $group = TontineGroup::create($user, 'Tontine famille', new Money('25000000', new Currency('USDC')), Periodicity::Weekly, 12, new \DateTimeImmutable('2026-01-01T00:00:00+00:00'));
          $group->provisionSafe(new WalletAddress(self::SAFE_ADDRESS));

          $userRepository = $this->createStub(UserRepositoryInterface::class);
          $userRepository->method('findAll')->willReturn([$user]);

          $groups = $this->createStub(TontineGroupRepositoryInterface::class);
          $groups->method('findAllWithSafeAddress')->willReturn([$group]);

          $positionReader = $this->createStub(VaultPositionReader::class);
          $positionReader->method('read')->willReturn($this->samplePosition());

          $snapshots = $this->createMock(YieldSnapshotRepositoryInterface::class);
          $snapshots->expects(self::exactly(2))->method('save');

          (new SyncVaultPositions($userRepository, $groups, $positionReader, $snapshots, new NullLogger()))();
      }

      public function testOneGroupsRpcFailureDoesNotAbortTheOthers(): void
      {
          $user = User::registerFromPrivy('did:privy:alice', 'alice@example.com', new WalletAddress(self::USER_WALLET));
          $failingGroup = TontineGroup::create($user, 'Tontine A', new Money('25000000', new Currency('USDC')), Periodicity::Weekly, 12, new \DateTimeImmutable('2026-01-01T00:00:00+00:00'));
          $failingGroup->provisionSafe(new WalletAddress(self::SAFE_ADDRESS));
          $succeedingGroup = TontineGroup::create($user, 'Tontine B', new Money('25000000', new Currency('USDC')), Periodicity::Weekly, 12, new \DateTimeImmutable('2026-01-01T00:00:00+00:00'));
          $succeedingGroup->provisionSafe(new WalletAddress('0x5555555555555555555555555555555555555555'));

          $userRepository = $this->createStub(UserRepositoryInterface::class);
          $userRepository->method('findAll')->willReturn([]);

          $groups = $this->createStub(TontineGroupRepositoryInterface::class);
          $groups->method('findAllWithSafeAddress')->willReturn([$failingGroup, $succeedingGroup]);

          $samplePosition = $this->samplePosition();
          $positionReader = $this->createStub(VaultPositionReader::class);
          $positionReader->method('read')->willReturnCallback(
              static fn (WalletAddress $wallet): VaultPosition => $wallet->equals(new WalletAddress(self::SAFE_ADDRESS))
                  ? throw BlockchainCallException::rpcError('timeout') : $samplePosition,
          );

          $snapshots = $this->createMock(YieldSnapshotRepositoryInterface::class);
          $snapshots->expects(self::once())->method('save');

          (new SyncVaultPositions($userRepository, $groups, $positionReader, $snapshots, new NullLogger()))();
      }

      private function samplePosition(): VaultPosition
      {
          return new VaultPosition(
              shares: new Number('0'),
              principal: new Money('0', new Currency('USDC')),
              yieldReceived: new Number('0'),
              flowRate: new Number('0'),
              connected: false,
              paused: false,
              aprBasisPoints: new Number('500'),
          );
      }
  }
  ```

- [ ] **Step 2: Run to verify it fails**

  Run: `make test c="--filter=SyncVaultPositionsTest"`
  Expected: FAIL — constructor signature mismatch (no `TontineGroupRepositoryInterface` parameter yet).

- [ ] **Step 3: Implement**

  ```php
  <?php

  declare(strict_types=1);

  namespace App\Application\Vault\UseCase;

  use App\Application\Identity\Port\UserRepositoryInterface;
  use App\Application\Tontine\Port\TontineGroupRepositoryInterface;
  use App\Application\Vault\Exception\BlockchainCallException;
  use App\Application\Vault\Port\YieldSnapshotRepositoryInterface;
  use App\Domain\Vault\YieldSnapshot;
  use Psr\Log\LoggerInterface;

  /**
   * Reads every registered member's vault position, plus every tontine group's Safe
   * position, and persists a snapshot each. One position's RPC failure must not abort the
   * sync for the others — each is isolated and logged.
   */
  final readonly class SyncVaultPositions
  {
      public function __construct(
          private UserRepositoryInterface $userRepository,
          private TontineGroupRepositoryInterface $groupRepository,
          private VaultPositionReader $positionReader,
          private YieldSnapshotRepositoryInterface $snapshotRepository,
          private LoggerInterface $logger,
      ) {
      }

      public function __invoke(): void
      {
          foreach ($this->userRepository->findAll() as $user) {
              try {
                  $position = $this->positionReader->read($user->walletAddress);
                  $this->snapshotRepository->save(YieldSnapshot::fromPosition($user->walletAddress, $position));
              } catch (BlockchainCallException $e) {
                  $this->logger->error('Vault position sync failed for {wallet}: {message}', [
                      'wallet' => (string) $user->walletAddress,
                      'message' => $e->getMessage(),
                  ]);
              }
          }

          foreach ($this->groupRepository->findAllWithSafeAddress() as $group) {
              \assert(null !== $group->safeAddress);

              try {
                  $position = $this->positionReader->read($group->safeAddress);
                  $this->snapshotRepository->save(YieldSnapshot::fromPosition($group->safeAddress, $position));
              } catch (BlockchainCallException $e) {
                  $this->logger->error('Vault position sync failed for group Safe {wallet}: {message}', [
                      'wallet' => (string) $group->safeAddress,
                      'message' => $e->getMessage(),
                  ]);
              }
          }
      }
  }
  ```

- [ ] **Step 4: Run to verify it passes**

  Run: `make test c="--filter=SyncVaultPositionsTest"`
  Expected: PASS.

- [ ] **Step 5: Run the full suite for regressions (constructor signature changed)**

  Run: `make test`
  Expected: PASS — check `config/services.yaml` doesn't need an explicit autowiring hint for the new constructor argument (it's a concrete class + interface types, should autowire; if `make test` reveals a container compilation error, add an explicit `arguments:` block for `App\Application\Vault\UseCase\SyncVaultPositions` in `config/services.yaml`).

- [ ] **Step 6: Commit**

  ```bash
  git add src/Application/Vault/UseCase/SyncVaultPositions.php tests/Application/Vault/UseCase/SyncVaultPositionsTest.php
  git commit -m "feat: sync group Safe vault positions alongside member positions"
  ```

---

### Task 12: `GroupPotReader` — surface the group Safe's on-chain position

**Files:**
- Modify: `src/Application/Tontine/Dto/GroupPotView.php`
- Modify: `src/Application/Tontine/UseCase/GroupPotReader.php`
- Modify: `src/Domain/Vault/YieldSnapshot.php`
- Modify: `tests/Application/Tontine/UseCase/GroupPotReaderTest.php`

**Interfaces:**
- Consumes: `YieldSnapshotRepositoryInterface::findLatestFor(WalletAddress)` (already exists, unmodified).
- Produces: new nullable `GroupPotView` fields consumed by Task 13's template.

Purely additive: existing off-chain fields (`potTotalDisplay`, `members`, etc.) are untouched.

- [ ] **Step 1: Write the failing test**

  Add to `tests/Application/Tontine/UseCase/GroupPotReaderTest.php`:

  ```php
  public function testIncludesOnChainSafePositionWhenGroupHasASafeAndASnapshot(): void
  {
      $alice = self::alice();
      $group = TontineGroup::create($alice, 'Tontine famille', self::usdc('25000000'), Periodicity::Weekly, 2, new \DateTimeImmutable(self::CREATED_AT));
      $group->provisionSafe(new WalletAddress(self::SAFE_ADDRESS));

      $groups = $this->createStub(TontineGroupRepositoryInterface::class);
      $groups->method('find')->willReturn($group);

      $safeSnapshot = YieldSnapshot::fromPosition(new WalletAddress(self::SAFE_ADDRESS), new VaultPosition(
          shares: new Number('0'),
          principal: self::usdc('25000000'),
          yieldReceived: new Number('1000000000000000000'),
          flowRate: new Number('100'),
          connected: true,
          paused: false,
          aprBasisPoints: new Number('500'),
      ));

      $snapshots = $this->createStub(YieldSnapshotRepositoryInterface::class);
      $snapshots->method('findMostRecent')->willReturn($safeSnapshot);
      $snapshots->method('findLatestFor')->willReturn($safeSnapshot);

      $reader = new GroupPotReader($groups, $snapshots, $this->clock());
      $view = $reader->read(1);

      self::assertNotNull($view);
      self::assertSame(self::SAFE_ADDRESS, $view->safeAddress);
      self::assertSame('25.000000', $view->onChainPrincipalDisplay);
      self::assertTrue($view->onChainConnected);
      self::assertFalse($view->onChainPaused);
  }

  public function testOmitsOnChainPositionWhenGroupHasNoSafeYet(): void
  {
      $alice = self::alice();
      $group = TontineGroup::create($alice, 'Tontine famille', self::usdc('25000000'), Periodicity::Weekly, 2, new \DateTimeImmutable(self::CREATED_AT));

      $groups = $this->createStub(TontineGroupRepositoryInterface::class);
      $groups->method('find')->willReturn($group);

      $reader = new GroupPotReader($groups, $this->snapshots(500), $this->clock());
      $view = $reader->read(1);

      self::assertNotNull($view);
      self::assertNull($view->safeAddress);
      self::assertNull($view->onChainPrincipalDisplay);
  }
  ```

  Add `use App\Domain\Identity\WalletAddress;`, `use App\Domain\Vault\VaultPosition;`, `use App\Domain\Vault\YieldSnapshot;` to the test file's imports if not already present (`WalletAddress`/`VaultPosition`/`YieldSnapshot` are already imported — confirmed by reading the file), and a new constant `private const SAFE_ADDRESS = '0x4444444444444444444444444444444444444444';`.

- [ ] **Step 2: Run to verify it fails**

  Run: `make test c="--filter=GroupPotReaderTest"`
  Expected: FAIL — `GroupPotView::$safeAddress` doesn't exist.

- [ ] **Step 3: Add display helpers to `YieldSnapshot`**

  In `src/Domain/Vault/YieldSnapshot.php`, add (matching `TontineGroup::contributionAmountDisplay()`'s convention):
  ```php
  public function principalDisplay(): string
  {
      \assert(is_numeric($this->principalMinorUnits));

      return (string) (new Number($this->principalMinorUnits))->div('1000000', 6);
  }

  public function yieldReceivedDisplay(): string
  {
      \assert(is_numeric($this->yieldReceived));

      return (string) (new Number($this->yieldReceived))->div('1000000000000000000', 6);
  }

  public function flowRatePerSecondDisplay(): string
  {
      \assert(is_numeric($this->flowRate));

      return (string) (new Number($this->flowRate))->div('1000000000000000000', 6);
  }
  ```
  Add `use BcMath\Number;` to the top of the file if not already imported (it is not currently imported — `YieldSnapshot` currently has no `Number` usage in its own body, only via `VaultPosition`).

- [ ] **Step 4: Extend `GroupPotView`**

  In `src/Application/Tontine/Dto/GroupPotView.php`, add to the constructor (after `members`):
  ```php
  public ?string $safeAddress = null,
  public ?string $onChainPrincipalDisplay = null,
  public ?string $onChainYieldReceivedDisplay = null,
  public ?string $onChainFlowRatePerSecondDisplay = null,
  public ?bool $onChainConnected = null,
  public ?bool $onChainPaused = null,
  public ?int $onChainCapturedAtTimestamp = null,
  ```

- [ ] **Step 5: Populate the new fields in `GroupPotReader::read()`**

  In `src/Application/Tontine/UseCase/GroupPotReader.php`, before the final `return new GroupPotView(...)`, add:
  ```php
  $safeSnapshot = null !== $group->safeAddress ? $this->snapshots->findLatestFor($group->safeAddress) : null;
  ```
  Then extend the `return new GroupPotView(...)` call with:
  ```php
  safeAddress: $group->safeAddress?->value,
  onChainPrincipalDisplay: $safeSnapshot?->principalDisplay(),
  onChainYieldReceivedDisplay: $safeSnapshot?->yieldReceivedDisplay(),
  onChainFlowRatePerSecondDisplay: $safeSnapshot?->flowRatePerSecondDisplay(),
  onChainConnected: $safeSnapshot?->connected,
  onChainPaused: $safeSnapshot?->paused,
  onChainCapturedAtTimestamp: $safeSnapshot?->capturedAt->getTimestamp(),
  ```

- [ ] **Step 6: Run to verify it passes**

  Run: `make test c="--filter=GroupPotReaderTest"`
  Expected: PASS, including pre-existing cases (unaffected — new constructor params are nullable-with-default).

- [ ] **Step 7: Commit**

  ```bash
  git add src/Application/Tontine/Dto/GroupPotView.php src/Application/Tontine/UseCase/GroupPotReader.php src/Domain/Vault/YieldSnapshot.php tests/Application/Tontine/UseCase/GroupPotReaderTest.php
  git commit -m "feat: surface group Safe on-chain position in GroupPotView"
  ```

---

### Task 13: `GroupSafeController`

**Files:**
- Create: `src/Presentation/Http/GroupSafeController.php`
- Create: `tests/Presentation/GroupSafeControllerTest.php`

**Interfaces:**
- Consumes: `ConfirmGroupSafeDeployment`, `ConfirmGroupSafeExecution` (Tasks 6/7).
- Produces: `POST /tontines/{id}/safe/deploy/confirm`, `POST /tontines/{id}/safe/connect-pool/confirm` — consumed by Task 16's `GroupSafeAdmin.jsx`.

- [ ] **Step 1: Write the failing tests (auth-guard/payload-validation only, same scope as `DepositConfirmationControllerTest`)**

  ```php
  <?php

  declare(strict_types=1);

  namespace App\Tests\Presentation;

  use Firebase\JWT\JWT;
  use Symfony\Bundle\FrameworkBundle\KernelBrowser;
  use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

  /**
   * Only exercises paths that return before reaching the confirmation use cases (auth guard,
   * payload validation) — a real confirmation needs a live RPC node (see the Anvil fork
   * integration test instead). The use cases themselves are covered by
   * ConfirmGroupSafeDeploymentTest/ConfirmGroupSafeExecutionTest with mocked ports.
   */
  final class GroupSafeControllerTest extends WebTestCase
  {
      private const APP_ID = 'test-privy-app';

      public function testDeployConfirmRequiresAuthentication(): void
      {
          $client = self::createClient();

          $client->request('POST', '/tontines/1/safe/deploy/confirm', content: json_encode(['txHash' => '0x'.str_repeat('a1', 32)], \JSON_THROW_ON_ERROR));

          self::assertResponseStatusCodeSame(401);
      }

      public function testDeployConfirmRejectsMissingTxHash(): void
      {
          $client = self::createClient();
          $this->login($client, 'did:privy:safe-deploy-missing-hash', '0x1111111111111111111111111111111111111111');

          $client->request('POST', '/tontines/1/safe/deploy/confirm', content: json_encode([], \JSON_THROW_ON_ERROR));

          self::assertResponseStatusCodeSame(400);
      }

      public function testConnectPoolConfirmRequiresAuthentication(): void
      {
          $client = self::createClient();

          $client->request('POST', '/tontines/1/safe/connect-pool/confirm', content: json_encode(['txHash' => '0x'.str_repeat('a1', 32), 'safeTxHash' => '0x'.str_repeat('b2', 32)], \JSON_THROW_ON_ERROR));

          self::assertResponseStatusCodeSame(401);
      }

      public function testConnectPoolConfirmRejectsMissingSafeTxHash(): void
      {
          $client = self::createClient();
          $this->login($client, 'did:privy:safe-connect-missing-hash', '0x2222222222222222222222222222222222222222');

          $client->request('POST', '/tontines/1/safe/connect-pool/confirm', content: json_encode(['txHash' => '0x'.str_repeat('a1', 32)], \JSON_THROW_ON_ERROR));

          self::assertResponseStatusCodeSame(400);
      }

      public function testDeployConfirmForAnUnknownGroupReturns404(): void
      {
          $client = self::createClient();
          $this->login($client, 'did:privy:safe-deploy-unknown-group', '0x3333333333333333333333333333333333333333');

          $client->request('POST', '/tontines/999999/safe/deploy/confirm', content: json_encode(['txHash' => '0x'.str_repeat('a1', 32)], \JSON_THROW_ON_ERROR));

          self::assertResponseStatusCodeSame(404);
      }

      private function login(KernelBrowser $client, string $subject, string $walletAddress): void
      {
          $client->request(
              'POST',
              '/auth/privy',
              server: [
                  'HTTP_AUTHORIZATION' => 'Bearer '.$this->makeAccessToken($subject),
                  'HTTP_PRIVY_ID_TOKEN' => $this->makeIdentityToken($subject, $walletAddress),
              ],
          );

          self::assertResponseIsSuccessful();
      }

      private function makeAccessToken(string $subject): string
      {
          return JWT::encode([
              'iss' => 'privy.io',
              'aud' => self::APP_ID,
              'sub' => $subject,
              'iat' => time(),
              'exp' => time() + 3600,
          ], $this->privateKey(), 'ES256');
      }

      private function makeIdentityToken(string $subject, string $walletAddress): string
      {
          return JWT::encode([
              'iss' => 'privy.io',
              'aud' => self::APP_ID,
              'sub' => $subject,
              'iat' => time(),
              'exp' => time() + 3600,
              'linked_accounts' => json_encode([
                  ['type' => 'email', 'address' => $subject.'@example.com'],
                  ['type' => 'wallet', 'address' => $walletAddress, 'chain_type' => 'ethereum'],
              ], \JSON_THROW_ON_ERROR),
          ], $this->privateKey(), 'ES256');
      }

      private function privateKey(): string
      {
          $privateKey = file_get_contents(__DIR__.'/../Fixtures/privy_test_private_key.pem');
          if (false === $privateKey) {
              throw new \RuntimeException('Missing test fixture: privy_test_private_key.pem.');
          }

          return $privateKey;
      }
  }
  ```

- [ ] **Step 2: Run to verify it fails**

  Run: `make test c="--filter=GroupSafeControllerTest"`
  Expected: FAIL — route not found (404 for auth test expecting 401, etc.).

- [ ] **Step 3: Implement**

  ```php
  <?php

  declare(strict_types=1);

  namespace App\Presentation\Http;

  use App\Application\Identity\Port\AuthenticatedUserInterface;
  use App\Application\Safe\UseCase\ConfirmGroupSafeDeployment;
  use App\Application\Safe\UseCase\ConfirmGroupSafeExecution;
  use App\Application\Safe\UseCase\GroupSafeError;
  use App\Domain\Deposit\TransactionHash;
  use App\Domain\Identity\User;
  use App\Domain\Safe\SafeTransactionPurpose;
  use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
  use Symfony\Component\HttpFoundation\JsonResponse;
  use Symfony\Component\HttpFoundation\Request;
  use Symfony\Component\HttpFoundation\Response;
  use Symfony\Component\Routing\Attribute\Route;
  use Symfony\Component\Security\Http\Attribute\IsGranted;

  #[IsGranted('ROLE_USER')]
  final class GroupSafeController extends AbstractController
  {
      public function __construct(
          private readonly ConfirmGroupSafeDeployment $confirmDeployment,
          private readonly ConfirmGroupSafeExecution $confirmExecution,
      ) {
      }

      #[Route('/tontines/{id}/safe/deploy/confirm', name: 'app_tontine_safe_deploy_confirm', requirements: ['id' => '\d+'], methods: ['POST'])]
      public function confirmDeploy(int $id, Request $request): JsonResponse
      {
          $user = $this->requireDomainUser();

          $txHash = self::parseTxHash($request, 'txHash');
          if (null === $txHash) {
              return $this->json(['error' => 'Missing or invalid "txHash".'], Response::HTTP_BAD_REQUEST);
          }

          $result = ($this->confirmDeployment)($user, $id, $txHash);

          if (!$result->isSuccess) {
              return $this->json(['error' => self::errorMessage($result->error())], self::statusFor($result->error()));
          }

          return $this->json(['status' => $result->value()->status, 'safeAddress' => $result->value()->safeAddress]);
      }

      #[Route('/tontines/{id}/safe/connect-pool/confirm', name: 'app_tontine_safe_connect_pool_confirm', requirements: ['id' => '\d+'], methods: ['POST'])]
      public function confirmConnectPool(int $id, Request $request): JsonResponse
      {
          $user = $this->requireDomainUser();

          $txHash = self::parseTxHash($request, 'txHash');
          $safeTxHash = self::parseTxHash($request, 'safeTxHash');
          if (null === $txHash || null === $safeTxHash) {
              return $this->json(['error' => 'Missing or invalid "txHash"/"safeTxHash".'], Response::HTTP_BAD_REQUEST);
          }

          $result = ($this->confirmExecution)($user, $id, SafeTransactionPurpose::ConnectYieldPool, $txHash, $safeTxHash);

          if (!$result->isSuccess) {
              return $this->json(['error' => self::errorMessage($result->error())], self::statusFor($result->error()));
          }

          return $this->json(['status' => $result->value()->status]);
      }

      private function requireDomainUser(): User
      {
          $user = $this->getUser();

          if (!$user instanceof AuthenticatedUserInterface) {
              throw new \LogicException('Expected an authenticated user implementing AuthenticatedUserInterface.');
          }

          return $user->getDomainUser();
      }

      private static function parseTxHash(Request $request, string $field): ?TransactionHash
      {
          $payload = json_decode($request->getContent(), associative: true);
          $value = \is_array($payload) ? ($payload[$field] ?? null) : null;

          if (!\is_string($value)) {
              return null;
          }

          try {
              return new TransactionHash($value);
          } catch (\InvalidArgumentException) {
              return null;
          }
      }

      private static function statusFor(GroupSafeError $error): int
      {
          return match ($error) {
              GroupSafeError::GroupNotFound => Response::HTTP_NOT_FOUND,
              GroupSafeError::NotAnAdmin => Response::HTTP_FORBIDDEN,
          };
      }

      private static function errorMessage(GroupSafeError $error): string
      {
          return match ($error) {
              GroupSafeError::GroupNotFound => 'Tontine introuvable.',
              GroupSafeError::NotAnAdmin => "Seul l'administrateur du groupe peut effectuer cette action.",
          };
      }
  }
  ```

- [ ] **Step 4: Run to verify it passes**

  Run: `make test c="--filter=GroupSafeControllerTest"`
  Expected: PASS.

- [ ] **Step 5: Commit**

  ```bash
  git add src/Presentation/Http/GroupSafeController.php tests/Presentation/GroupSafeControllerTest.php
  git commit -m "feat: add GroupSafeController (deploy/connect-pool confirmation endpoints)"
  ```

---

### Task 14: `TontineGroupController::show()` exposes `isAdmin` + twig globals

**Files:**
- Modify: `src/Presentation/Http/TontineGroupController.php`
- Modify: `config/packages/twig.yaml`

**Interfaces:**
- Produces: `isAdmin` template variable on `tontine/show.html.twig` (Task 15); twig globals `safe_proxy_factory_address`/`safe_singleton_address`/`safe_fallback_handler_address`.

- [ ] **Step 1: Expose `isAdmin` in `show()`**

  In `src/Presentation/Http/TontineGroupController.php::show()`, the `$membership` variable is already computed. Change the final `return $this->render(...)` call to add one line:
  ```php
  return $this->render('tontine/show.html.twig', [
      'group' => $group,
      'invitationUrl' => $invitationUrl,
      'latestDeposit' => $this->deposits->findLatestFor($user->walletAddress),
      'isAdmin' => MembershipRole::Admin === $membership->role,
  ]);
  ```
  (`MembershipRole` is already imported in this file.)

- [ ] **Step 2: Add twig globals**

  In `config/packages/twig.yaml`, alongside the existing `vault_address`/`usdc_address`/`gda_forwarder_address` globals:
  ```yaml
  safe_proxy_factory_address: '%env(SAFE_PROXY_FACTORY_ADDRESS)%'
  safe_singleton_address: '%env(SAFE_SINGLETON_ADDRESS)%'
  safe_fallback_handler_address: '%env(SAFE_FALLBACK_HANDLER_ADDRESS)%'
  ```

- [ ] **Step 3: Run the existing controller test suite to check for regressions**

  Run: `make test c="--filter=TontineGroupController"`
  Expected: PASS (no existing test asserts on the full template variable set in a way this breaks — `isAdmin` is additive).

- [ ] **Step 4: Commit**

  ```bash
  git add src/Presentation/Http/TontineGroupController.php config/packages/twig.yaml
  git commit -m "feat: expose isAdmin + Safe addresses to the tontine group template"
  ```

---

### Task 15: Templates — `tontine/show.html.twig` + `TontineGroupDashboard.html.twig`

**Files:**
- Modify: `templates/tontine/show.html.twig`
- Modify: `templates/components/TontineGroupDashboard.html.twig`

No PHPUnit cycle (Twig templates aren't unit-tested in this codebase) — verified manually in Task 20's browser walkthrough.

- [ ] **Step 1: Gate the deposit UI on `group.safeAddress` and pass `receiverAddress`**

  In `templates/tontine/show.html.twig`, wrap the existing deposit block (`<h2>Enregistrer une cotisation</h2>` through the contribution form) in a conditional, and add `receiverAddress` to the `react_component('VaultDeposit', {...})` call:
  ```twig
  {% if group.safeAddress is not null %}
      <h2>Enregistrer une cotisation</h2>
      <div {{ react_component('VaultDeposit', {
          appId: privy_app_id,
          clientId: privy_app_client_id,
          vaultAddress: vault_address,
          usdcAddress: usdc_address,
          gdaForwarderAddress: gda_forwarder_address,
          receiverAddress: group.safeAddress,
      }) }}></div>

      <form method="post" action="{{ path('app_tontine_contribute', {id: group.id}) }}">
          {# ...unchanged existing form body... #}
      </form>
  {% else %}
      <p>En attente de la configuration du coffre du groupe par l'administrateur.</p>
  {% endif %}
  ```
  (Copy the exact existing prop list from the current `react_component('VaultDeposit', {...})` call rather than guessing — only add `receiverAddress: group.safeAddress` to whatever is already there.)

- [ ] **Step 2: Add the admin-only Safe provisioning block**

  Immediately after the block from Step 1 (still inside `tontine/show.html.twig`):
  ```twig
  {% if isAdmin %}
      <div {{ react_component('GroupSafeAdmin', {
          appId: privy_app_id,
          clientId: privy_app_client_id,
          groupId: group.id,
          safeAddress: group.safeAddress,
          ownerAddresses: group.adminWalletAddresses,
          proxyFactoryAddress: safe_proxy_factory_address,
          safeSingletonAddress: safe_singleton_address,
          safeFallbackHandlerAddress: safe_fallback_handler_address,
          gdaForwarderAddress: gda_forwarder_address,
          vaultAddress: vault_address,
      }) }}></div>
  {% endif %}
  ```

- [ ] **Step 3: Surface the on-chain group position in `TontineGroupDashboard.html.twig`**

  Add a new block after the existing "Yield estimé" paragraph:
  ```twig
  {% if pot.safeAddress is not null %}
      <p style="margin: 1.5rem 0 0; text-transform: uppercase; font-size: .75rem; color: #666;">Position on-chain du coffre</p>
      <p style="margin: 0;">Principal déposé</p>
      <p style="margin: 0 0 .5rem; font-size: 1.25rem; font-weight: bold;">${{ pot.onChainPrincipalDisplay }}</p>
      <p style="margin: 0;">Yield reçu (cumulé)</p>
      <p style="margin: 0 0 .5rem; font-size: 1.25rem; font-weight: bold;">${{ pot.onChainYieldReceivedDisplay }}</p>
      <p style="margin: 0 0 1rem; color: #666; font-size: .75rem;">
          Coffre {{ pot.onChainConnected ? 'connecté au' : 'non connecté au' }} pool de rendement
          {%- if pot.onChainPaused %} — <span style="color: #b91c1c;">dépôts suspendus (vault en pause)</span>{% endif -%}
      </p>
  {% else %}
      <p style="margin: 1.5rem 0 0; color: #666; font-size: .875rem;">Coffre pas encore provisionné pour ce groupe.</p>
  {% endif %}
  ```

- [ ] **Step 4: Commit**

  ```bash
  git add templates/tontine/show.html.twig templates/components/TontineGroupDashboard.html.twig
  git commit -m "feat: gate group deposit UI on Safe provisioning, show on-chain group position"
  ```

---

### Task 16: `assets/react/lib/safeTransactions.js`

**Files:**
- Create: `assets/react/lib/safeTransactions.js`

No PHPUnit cycle — this is a plain JS module, exercised by Task 17's `GroupSafeAdmin.jsx` and verified end-to-end by Task 19's Anvil integration test + Task 20's manual browser walkthrough. There is no existing JS test harness in this codebase (`VaultDeposit.jsx` has none either) — don't introduce one unilaterally for this module alone.

**Interfaces:**
- Produces: `buildSafeSetupInitializer({ owners, fallbackHandler })`, `signAndExecuteSafeTransaction({ client, wallet, chainId, safeAddress, to, data })` — consumed by `GroupSafeAdmin.jsx` (Task 17), and unchanged by Phase 2's withdrawal (only `{to, data}` differs).

- [ ] **Step 1: Write the module**

  ```js
  import { encodeFunctionData, hashTypedData, parseAbi, zeroAddress } from 'viem';

  const TX_GAS_BUFFER = 2n;

  const safeAbi = parseAbi([
      'function setup(address[] _owners, uint256 _threshold, address to, bytes data, address fallbackHandler, address paymentToken, uint256 payment, address paymentReceiver)',
      'function nonce() view returns (uint256)',
      'function getTransactionHash(address to, uint256 value, bytes data, uint8 operation, uint256 safeTxGas, uint256 baseGas, uint256 gasPrice, address gasToken, address refundReceiver, uint256 _nonce) view returns (bytes32)',
      'function execTransaction(address to, uint256 value, bytes data, uint8 operation, uint256 safeTxGas, uint256 baseGas, uint256 gasPrice, address gasToken, address refundReceiver, bytes signatures) returns (bool)',
  ]);

  const SAFE_TX_TYPES = {
      SafeTx: [
          { name: 'to', type: 'address' },
          { name: 'value', type: 'uint256' },
          { name: 'data', type: 'bytes' },
          { name: 'operation', type: 'uint8' },
          { name: 'safeTxGas', type: 'uint256' },
          { name: 'baseGas', type: 'uint256' },
          { name: 'gasPrice', type: 'uint256' },
          { name: 'gasToken', type: 'address' },
          { name: 'refundReceiver', type: 'address' },
          { name: 'nonce', type: 'uint256' },
      ],
  };

  export { safeAbi };

  export function buildSafeSetupInitializer({ owners, fallbackHandler }) {
      return encodeFunctionData({
          abi: safeAbi,
          functionName: 'setup',
          args: [owners, 1n, zeroAddress, '0x', fallbackHandler, zeroAddress, 0n, zeroAddress],
      });
  }

  /**
   * Builds, EIP-712-signs, and submits a Safe execTransaction for an arbitrary {to, data}
   * call — the single reusable entry point for every Safe-signed action (connectPool here,
   * redeem in Phase 2). Reads the Safe's own on-chain nonce (mutable state, must be fresh)
   * then cross-checks the locally-computed EIP-712 digest against the contract's own
   * getTransactionHash() before signing — a mismatch means a domain/struct encoding bug,
   * never something to silently sign anyway.
   */
  export async function signAndExecuteSafeTransaction({ client, wallet, chainId, safeAddress, to, data }) {
      const nonce = await client.readContract({ address: safeAddress, abi: safeAbi, functionName: 'nonce' });

      const message = {
          to,
          value: 0n,
          data,
          operation: 0,
          safeTxGas: 0n,
          baseGas: 0n,
          gasPrice: 0n,
          gasToken: zeroAddress,
          refundReceiver: zeroAddress,
          nonce,
      };
      const domain = { chainId, verifyingContract: safeAddress };

      const [onChainHash, localHash] = await Promise.all([
          client.readContract({
              address: safeAddress,
              abi: safeAbi,
              functionName: 'getTransactionHash',
              args: [to, 0n, data, 0, 0n, 0n, 0n, zeroAddress, zeroAddress, nonce],
          }),
          hashTypedData({ domain, types: SAFE_TX_TYPES, primaryType: 'SafeTx', message }),
      ]);

      if (onChainHash !== localHash) {
          throw new Error('Safe transaction hash mismatch between local EIP-712 encoding and on-chain getTransactionHash().');
      }

      const signature = await wallet.signTypedData({ domain, types: SAFE_TX_TYPES, primaryType: 'SafeTx', message });

      const execArgs = [to, 0n, data, 0, 0n, 0n, 0n, zeroAddress, zeroAddress, signature];
      const gas = await client.estimateContractGas({
          address: safeAddress,
          abi: safeAbi,
          functionName: 'execTransaction',
          args: execArgs,
          account: wallet.address,
      });

      const txHash = await client.writeContract({
          address: safeAddress,
          abi: safeAbi,
          functionName: 'execTransaction',
          args: execArgs,
          gas: gas * TX_GAS_BUFFER,
      });
      await client.waitForTransactionReceipt({ hash: txHash });

      return { txHash, safeTxHash: onChainHash };
  }
  ```

- [ ] **Step 2: Commit**

  ```bash
  git add assets/react/lib/safeTransactions.js
  git commit -m "feat: add reusable Safe execTransaction signing helper"
  ```

---

### Task 17: `GroupSafeAdmin.jsx` + `VaultDeposit.jsx` changes

**Files:**
- Create: `assets/react/controllers/GroupSafeAdmin.jsx`
- Modify: `assets/react/controllers/VaultDeposit.jsx`

**Interfaces:**
- Consumes: `safeTransactions.js` (Task 16), `GroupSafeController` endpoints (Task 13).
- Produces: admin UI to provision the group's Safe and connect it to the yield pool.

- [ ] **Step 1: `VaultDeposit.jsx` — optional `receiverAddress` prop + auto-connect guard**

  Three localized changes to `assets/react/controllers/VaultDeposit.jsx`'s `DepositForm` function:

  1. Right after `const wallet = wallets.find(...)` line, add:
     ```js
     const receiver = props.receiverAddress ?? wallet?.address;
     ```
  2. In the `React.useEffect` data-loading block, change `args: [wallet.address]` (the `maxDeposit` call) to `args: [receiver]`.
  3. In `handleDeposit`, change both `args: [assets, wallet.address]` occurrences (gas estimate + `writeContract`) to `args: [assets, receiver]`.
  4. Change `if (!alreadyConnected) {` to `if (!alreadyConnected && !props.receiverAddress) {` — when depositing into a group's Safe, connecting the Safe to the yield pool is a separate Safe-signed action (`GroupSafeAdmin.jsx`), not something the depositing member triggers.

  `/profile`'s existing usage passes no `receiverAddress`, so `receiver` falls back to `wallet.address` and behavior is unchanged there.

- [ ] **Step 2: `GroupSafeAdmin.jsx`**

  ```jsx
  import React from 'react';
  import { PrivyProvider, usePrivy, useWallets } from '@privy-io/react-auth';
  import { createWalletClient, custom, publicActions, parseAbi } from 'viem';
  import { buildSafeSetupInitializer, safeAbi, signAndExecuteSafeTransaction } from '../lib/safeTransactions.js';

  const TX_GAS_BUFFER = 2n;

  const base = {
      id: 8453,
      name: 'Base',
      nativeCurrency: { name: 'Ether', symbol: 'ETH', decimals: 18 },
      rpcUrls: { default: { http: ['https://mainnet.base.org'] } },
  };

  const proxyFactoryAbi = parseAbi([
      'function createProxyWithNonce(address _singleton, bytes initializer, uint256 saltNonce) returns (address)',
  ]);

  const gdaForwarderAbi = parseAbi([
      'function isMemberConnected(address pool, address member) view returns (bool)',
      'function connectPool(address pool, bytes userData) returns (bool)',
  ]);

  const fundManagerAbi = parseAbi(['function YIELD_POOL() view returns (address)']);
  const vaultAbi = parseAbi(['function FUND_MANAGER() view returns (address)']);

  function GroupSafeAdminPanel(props) {
      const { ready, authenticated } = usePrivy();
      const { wallets } = useWallets();
      const [status, setStatus] = React.useState('idle');
      const [error, setError] = React.useState(null);
      const [connected, setConnected] = React.useState(null);

      const wallet = wallets.find((w) => w.walletClientType === 'privy');
      const busy = status === 'deploying' || status === 'connecting';

      const getClient = React.useCallback(async () => {
          await wallet.switchChain(base.id);
          const provider = await wallet.getEthereumProvider();

          return createWalletClient({ chain: base, transport: custom(provider), account: wallet.address }).extend(publicActions);
      }, [wallet]);

      React.useEffect(() => {
          if (!ready || !authenticated || !wallet || !props.safeAddress) {
              return;
          }

          let cancelled = false;

          (async () => {
              const client = await getClient();
              const fundManagerAddress = await client.readContract({ address: props.vaultAddress, abi: vaultAbi, functionName: 'FUND_MANAGER' });
              const yieldPoolAddress = await client.readContract({ address: fundManagerAddress, abi: fundManagerAbi, functionName: 'YIELD_POOL' });
              const isConnected = await client.readContract({
                  address: props.gdaForwarderAddress,
                  abi: gdaForwarderAbi,
                  functionName: 'isMemberConnected',
                  args: [yieldPoolAddress, props.safeAddress],
              });

              if (!cancelled) {
                  setConnected(isConnected);
              }
          })();

          return () => {
              cancelled = true;
          };
      }, [ready, authenticated, wallet, props.safeAddress, props.vaultAddress, props.gdaForwarderAddress, getClient]);

      async function handleDeploySafe() {
          setError(null);
          setStatus('deploying');

          try {
              const client = await getClient();
              const initializer = buildSafeSetupInitializer({
                  owners: props.ownerAddresses,
                  fallbackHandler: props.safeFallbackHandlerAddress,
              });

              const gas = await client.estimateContractGas({
                  address: props.proxyFactoryAddress,
                  abi: proxyFactoryAbi,
                  functionName: 'createProxyWithNonce',
                  args: [props.safeSingletonAddress, initializer, BigInt(props.groupId)],
                  account: wallet.address,
              });
              const txHash = await client.writeContract({
                  address: props.proxyFactoryAddress,
                  abi: proxyFactoryAbi,
                  functionName: 'createProxyWithNonce',
                  args: [props.safeSingletonAddress, initializer, BigInt(props.groupId)],
                  gas: gas * TX_GAS_BUFFER,
              });
              await client.waitForTransactionReceipt({ hash: txHash });

              await fetch(`/tontines/${props.groupId}/safe/deploy/confirm`, {
                  method: 'POST',
                  headers: { 'Content-Type': 'application/json' },
                  body: JSON.stringify({ txHash }),
              });

              setStatus('done');
              window.location.reload();
          } catch (err) {
              setStatus('error');
              setError(err?.shortMessage ?? err?.message ?? 'Le déploiement du coffre a échoué.');
          }
      }

      async function handleConnectPool() {
          setError(null);
          setStatus('connecting');

          try {
              const client = await getClient();
              const fundManagerAddress = await client.readContract({ address: props.vaultAddress, abi: vaultAbi, functionName: 'FUND_MANAGER' });
              const yieldPoolAddress = await client.readContract({ address: fundManagerAddress, abi: fundManagerAbi, functionName: 'YIELD_POOL' });

              const data = client.encodeFunctionData
                  ? client.encodeFunctionData({ abi: gdaForwarderAbi, functionName: 'connectPool', args: [yieldPoolAddress, '0x'] })
                  : (await import('viem')).encodeFunctionData({ abi: gdaForwarderAbi, functionName: 'connectPool', args: [yieldPoolAddress, '0x'] });

              const { txHash, safeTxHash } = await signAndExecuteSafeTransaction({
                  client,
                  wallet,
                  chainId: base.id,
                  safeAddress: props.safeAddress,
                  to: props.gdaForwarderAddress,
                  data,
              });

              await fetch(`/tontines/${props.groupId}/safe/connect-pool/confirm`, {
                  method: 'POST',
                  headers: { 'Content-Type': 'application/json' },
                  body: JSON.stringify({ txHash, safeTxHash }),
              });

              setStatus('done');
              setConnected(true);
          } catch (err) {
              setStatus('error');
              setError(err?.shortMessage ?? err?.message ?? 'La connexion au pool de rendement a échoué.');
          }
      }

      if (!ready || !authenticated || !wallet) {
          return null;
      }

      return (
          <div style={{ border: '1px dashed #999', borderRadius: 8, padding: '1rem', marginTop: '1rem' }}>
              <h3>Administration du coffre</h3>

              {!props.safeAddress && (
                  <button type="button" onClick={handleDeploySafe} disabled={busy}>
                      Provisionner le coffre du groupe
                  </button>
              )}

              {props.safeAddress && connected === false && (
                  <button type="button" onClick={handleConnectPool} disabled={busy}>
                      Connecter le pool de rendement
                  </button>
              )}

              {props.safeAddress && connected === true && <p>Coffre actif et connecté au pool de rendement.</p>}

              {status !== 'idle' && status !== 'error' && <p>Statut : {status}</p>}
              {error && <p role="alert">{error}</p>}
          </div>
      );
  }

  export default function GroupSafeAdmin(props) {
      return (
          <PrivyProvider
              appId={props.appId}
              clientId={props.clientId}
              config={{
                  loginMethods: ['email'],
                  embeddedWallets: { ethereum: { createOnLogin: 'users-without-wallets' } },
                  defaultChain: base,
                  supportedChains: [base],
              }}
          >
              <GroupSafeAdminPanel {...props} />
          </PrivyProvider>
      );
  }
  ```

  Note the `handleConnectPool` function imports `encodeFunctionData` from `viem` directly rather than through a `client.encodeFunctionData` (which doesn't exist on a viem wallet client) — **fix this before committing**: add `encodeFunctionData` to the top-level `import { createWalletClient, custom, publicActions, parseAbi, encodeFunctionData } from 'viem';` and simplify the body to a single direct call:
  ```js
  const data = encodeFunctionData({ abi: gdaForwarderAbi, functionName: 'connectPool', args: [yieldPoolAddress, '0x'] });
  ```
  (removing the conditional/dynamic-import fallback entirely — it was a placeholder mistake, not a real pattern used anywhere else in this codebase).

- [ ] **Step 3: Manual smoke check (no JS test harness exists in this repo to automate this)**

  Run: `make up`, log in as a group creator, visit `/tontines/{id}`, confirm the "Provisionner le coffre" button renders for the admin and not for a regular member (test both roles). Full functional verification happens in Task 20 against the Anvil fork.

- [ ] **Step 4: Commit**

  ```bash
  git add assets/react/controllers/GroupSafeAdmin.jsx assets/react/controllers/VaultDeposit.jsx
  git commit -m "feat: add GroupSafeAdmin component, redirect group deposits to the Safe"
  ```

---

### Task 18: `make qa` checkpoint

**Files:** none (verification only)

- [ ] **Step 1: Run the full quality gate**

  Run: `make qa`
  Expected: PASS — tests, PHPStan, Deptrac (in particular: `Domain/Safe` and `Application/Safe` must show zero inward-pointing violations), php-cs-fixer.

- [ ] **Step 2: Fix any Deptrac violation**

  If Deptrac flags anything, it's almost certainly a stray `use` of a Doctrine class inside `Domain/Safe` bypassing the mapping-attribute allowance, or an Application class reaching into Infrastructure directly — fix the import, don't suppress the rule.

- [ ] **Step 3: Fix any cs-fixer violation**

  Run: `make cs-fix` if `make cs` (part of `make qa`) fails, then re-run `make qa`.

- [ ] **Step 4: Commit any autofix changes**

  ```bash
  git add -A
  git commit -m "chore: apply cs-fixer autofixes"
  ```
  (Only if Step 3 produced changes — skip this commit otherwise.)

---

### Task 19: Integration test — `GroupSafeFlowTest.php` (Anvil fork)

**Files:**
- Create: `tests/Integration/Safe/GroupSafeFlowTest.php`
- Modify: `tests/Integration/Deposit/Support/AbiEncoder.php` (add the encoders below — it currently only has 3 fixed-shape helpers for the deposit flow's `approve`/`deposit`/`connectPool`, none of which cover Safe's dynamic-array/nested-bytes calls; there is **no** generic `encodeCall()` on it today, don't assume one)
- Modify: `composer.json` / `composer.lock` (new dev dependency for secp256k1 signing — no such library exists in this project yet; `firebase/php-jwt` only covers ES256/secp256r1 for Privy JWTs, a different curve)

**Interfaces:**
- Exercises the full stack end-to-end against a real Base fork: `createProxyWithNonce` → `ConfirmGroupSafeDeployment` → build+sign `execTransaction` for `connectPool` → `ConfirmGroupSafeExecution`.

This is the practical proof that Task 1's pinned addresses and Task 9's event decoding are correct against real deployed bytecode — not just mocked unit tests.

- [ ] **Step 1: Read the existing pattern first**

  Open `tests/Integration/Deposit/DepositFlowTest.php` in full and its `Support/AbiEncoder.php`/`Support/AnvilTestClient.php` helpers before writing this test — reuse `AnvilTestClient` as-is. `AbiEncoder`'s existing 3 methods (`approve`/`deposit`/`connectPool`) are fixed-shape, hand-encoded helpers with no dynamic-array support — Safe's `setup`/`createProxyWithNonce`/`execTransaction` need `address[]` and nested `bytes` arguments, so Step 2 below adds a small generic dynamic encoder to the same file rather than 3 more fixed one-off methods (the fixed-shape style doesn't scale to nested dynamic types without duplicating the offset arithmetic error-prone by hand each time).

- [ ] **Step 2: Add a generic dynamic-type encoder to `AbiEncoder`**

  Extend `tests/Integration/Deposit/Support/AbiEncoder.php` with a small encoder covering exactly the types this test needs (`address`, `uint256`, `uint8`, `bytes`, `address[]`), implementing the standard ABI head/tail scheme (a dynamic param's head slot holds a byte offset to its tail data, tails appended in param order) instead of hand-computing per-call byte offsets, which is exactly the kind of arithmetic that's easy to get subtly wrong by hand:

  ```php
  /**
   * @param list<array{type: string, value: mixed}> $params
   */
  public static function encodeDynamic(string $signature, array $params): string
  {
      $headWordCount = \count($params);
      $head = [];
      $tail = '';

      foreach ($params as $param) {
          if (self::isDynamicType($param['type'])) {
              $offset = $headWordCount * 32 + \strlen($tail) / 2; // $tail is hex chars, /2 for bytes
              $head[] = self::uintWord((string) $offset);
              $tail .= self::encodeDynamicTail($param['type'], $param['value']);
          } else {
              $head[] = self::encodeStaticWord($param['type'], $param['value']);
          }
      }

      return '0x'.self::selector($signature).implode('', $head).$tail;
  }

  private static function isDynamicType(string $type): bool
  {
      return 'bytes' === $type || str_ends_with($type, '[]');
  }

  private static function encodeStaticWord(string $type, mixed $value): string
  {
      \assert(\is_string($value));

      return match ($type) {
          'address' => self::addressWord($value),
          'uint256', 'uint8' => self::uintWord($value),
          default => throw new \InvalidArgumentException(\sprintf('Unsupported static type: %s', $type)),
      };
  }

  private static function encodeDynamicTail(string $type, mixed $value): string
  {
      if ('bytes' === $type) {
          \assert(\is_string($value));
          $hex = str_starts_with($value, '0x') ? substr($value, 2) : $value;
          $lengthBytes = (int) (\strlen($hex) / 2);
          $paddedHexLength = (int) (ceil($lengthBytes / 32) * 32) * 2;

          return self::uintWord((string) $lengthBytes).str_pad($hex, $paddedHexLength, '0', \STR_PAD_RIGHT);
      }

      if ('address[]' === $type) {
          \assert(\is_array($value));
          $encoded = self::uintWord((string) \count($value));
          foreach ($value as $address) {
              \assert(\is_string($address));
              $encoded .= self::addressWord($address);
          }

          return $encoded;
      }

      throw new \InvalidArgumentException(\sprintf('Unsupported dynamic type: %s', $type));
  }
  ```

  Note the deliberate `str_starts_with(...) ? substr($value, 2) : $value` instead of `ltrim($value, '0x')` — `ltrim`'s second argument is a character mask, not a prefix, so it would incorrectly strip leading `0` characters from real hex data.

- [ ] **Step 3: Add a secp256k1 signing capability — verify the library's real API before wiring it, don't guess**

  No secp256k1 signer exists in this project yet (only `kornrunner/keccak` for hashing and `firebase/php-jwt`'s ES256/secp256r1 for Privy — a different curve entirely). Add one:

  ```bash
  make composer c='require --dev kornrunner/secp256k1'
  ```

  (Same maintainer as the already-trusted `kornrunner/keccak` dependency — a reasonable default choice; if unavailable or unmaintained, `simplito/elliptic-php` is the other common pure-PHP alternative.) After installing, **read its actual README/source** (e.g. `docker compose exec php cat vendor/kornrunner/secp256k1/README.md`, or browse `vendor/kornrunner/secp256k1/src/`) to find its real `sign()` method signature and return shape (raw `r`/`s`/recovery-id vs. a formatted signature object) — do not assume an API. Then add to `AbiEncoder`:

  ```php
  /**
   * Signs a 32-byte digest with a raw secp256k1 private key, returning a 65-byte Safe/
   * Ethereum-style signature (r || s || v, v in {27,28}) — verified against the installed
   * library's actual sign() API/return shape (Task 19, Step 3), not guessed.
   */
  public static function signDigest(string $digestHex, string $privateKeyHex): string
  {
      // Implement against kornrunner/secp256k1's verified API: sign the digest, extract r/s
      // (each padded to 32 bytes) and recovery id, map recovery id (0/1) to v (27/28), and
      // concatenate as '0x' . r . s . v — matching the (r, s, v) byte layout Safe's
      // checkNSignatures expects for a single EOA-owner signature.
  }
  ```

  This method's body is intentionally left to be filled in against the verified library API from this step — every other code block in this plan is complete, but faking this one's internals would mean shipping a signature routine never checked against its actual dependency, which is worse than leaving it explicit.

- [ ] **Step 4: Write the test**

  ```php
  <?php

  declare(strict_types=1);

  namespace App\Tests\Integration\Safe;

  use App\Application\Safe\UseCase\ConfirmGroupSafeDeployment;
  use App\Application\Safe\UseCase\ConfirmGroupSafeExecution;
  use App\Application\Tontine\Port\TontineGroupRepositoryInterface;
  use App\Domain\Deposit\TransactionHash;
  use App\Domain\Identity\User;
  use App\Domain\Identity\WalletAddress;
  use App\Domain\Safe\SafeTransactionPurpose;
  use App\Domain\Tontine\Periodicity;
  use App\Domain\Tontine\TontineGroup;
  use App\Tests\Integration\Deposit\Support\AbiEncoder;
  use App\Tests\Integration\Deposit\Support\AnvilTestClient;
  use Money\Currency;
  use Money\Money;
  use PHPUnit\Framework\Attributes\Group;
  use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
  use Symfony\Component\HttpClient\HttpClient;

  /**
   * Exercises the full group-Safe flow (deploy -> confirm -> connectPool via execTransaction
   * -> confirm) against a live Anvil fork of Base mainnet — see `make anvil-up` /
   * `make test-integration`. Unlike the rest of the integration suite, signing the Safe's
   * execTransaction requires a *real* private key (a raw ECDSA signature over the Safe's
   * EIP-712 digest, which Anvil's unsigned-tx impersonation trick cannot produce) — this
   * test uses one of Anvil's well-known default dev-account private keys, a deliberate,
   * contained exception scoped to this one test.
   */
  #[Group('integration')]
  final class GroupSafeFlowTest extends KernelTestCase
  {
      // Anvil's default account #0 — well-known, funded automatically by anvil --fork-url,
      // never used outside this integration test.
      private const ADMIN_PRIVATE_KEY = '0xac0974bec39a17e36ba4a6b4d238ff944bacb478cbed5efcae784d7bf4f2ff80';
      private const ADMIN_WALLET = '0xf39Fd6e51aad88F6F4ce6aB8827279cffFb92266';

      public function testDeploysConfirmsAndConnectsTheGroupSafeToTheYieldPool(): void
      {
          self::bootKernel();
          $container = self::getContainer();

          $rpcUrl = $this->requiredEnv('BASE_RPC_URL');
          $proxyFactoryAddress = $this->requiredEnv('SAFE_PROXY_FACTORY_ADDRESS');
          $safeSingletonAddress = $this->requiredEnv('SAFE_SINGLETON_ADDRESS');
          $fallbackHandlerAddress = $this->requiredEnv('SAFE_FALLBACK_HANDLER_ADDRESS');
          $gdaForwarderAddress = $this->requiredEnv('GDA_FORWARDER_ADDRESS');
          $vaultAddress = $this->requiredEnv('VAULT_ADDRESS');

          $groups = $container->get(TontineGroupRepositoryInterface::class);
          $confirmDeployment = $container->get(ConfirmGroupSafeDeployment::class);
          $confirmExecution = $container->get(ConfirmGroupSafeExecution::class);
          \assert($groups instanceof TontineGroupRepositoryInterface);
          \assert($confirmDeployment instanceof ConfirmGroupSafeDeployment);
          \assert($confirmExecution instanceof ConfirmGroupSafeExecution);

          $admin = User::registerFromPrivy('did:privy:safe-admin', 'safe-admin@example.com', new WalletAddress(self::ADMIN_WALLET));
          $group = TontineGroup::create($admin, 'Tontine intégration', new Money('25000000', new Currency('USDC')), Periodicity::Weekly, 12, new \DateTimeImmutable());
          $groups->save($group);
          \assert(null !== $group->id);

          $anvil = new AnvilTestClient(HttpClient::create(), $rpcUrl);
          $anvil->setBalance(self::ADMIN_WALLET, '0x56BC75E2D63100000');
          $anvil->impersonateAccount(self::ADMIN_WALLET);

          // 1. Deploy the Safe via createProxyWithNonce, owner = the admin's wallet.
          $initializer = AbiEncoder::encodeDynamic('setup(address[],uint256,address,bytes,address,address,uint256,address)', [
              ['type' => 'address[]', 'value' => [self::ADMIN_WALLET]],
              ['type' => 'uint256', 'value' => '1'],
              ['type' => 'address', 'value' => '0x0000000000000000000000000000000000000000'],
              ['type' => 'bytes', 'value' => ''],
              ['type' => 'address', 'value' => $fallbackHandlerAddress],
              ['type' => 'address', 'value' => '0x0000000000000000000000000000000000000000'],
              ['type' => 'uint256', 'value' => '0'],
              ['type' => 'address', 'value' => '0x0000000000000000000000000000000000000000'],
          ]);
          $deployData = AbiEncoder::encodeDynamic('createProxyWithNonce(address,bytes,uint256)', [
              ['type' => 'address', 'value' => $safeSingletonAddress],
              ['type' => 'bytes', 'value' => $initializer],
              ['type' => 'uint256', 'value' => (string) $group->id],
          ]);

          $deployTxHash = $anvil->sendTransaction(['from' => self::ADMIN_WALLET, 'to' => $proxyFactoryAddress, 'data' => $deployData, 'gas' => '0x2DC6C0']);
          $anvil->mine();

          $deployResult = ($confirmDeployment)($admin, $group->id, new TransactionHash($deployTxHash));
          self::assertTrue($deployResult->isSuccess);
          self::assertNotNull($deployResult->value()->safeAddress);

          $reloadedGroup = $groups->find($group->id);
          self::assertNotNull($reloadedGroup);
          self::assertTrue($reloadedGroup->hasSafe());

          $safeAddress = $deployResult->value()->safeAddress;
          \assert(null !== $safeAddress);

          // 2. Fund the Safe with the exact USDC amount it will need for a subsequent deposit
          //    is out of scope for this test (Task 19 only covers deploy + connectPool) — no
          //    funding needed to exercise execTransaction for connectPool, which moves no funds.

          // 3. Resolve the yield pool address the same way the frontend does.
          $blockchainReader = $container->get(\App\Application\Vault\Port\BlockchainReaderInterface::class);
          \assert($blockchainReader instanceof \App\Application\Vault\Port\BlockchainReaderInterface);
          [$fundManagerAddress] = $blockchainReader->call($vaultAddress, 'FUND_MANAGER()', [], ['address']);
          \assert(\is_string($fundManagerAddress));
          [$yieldPoolAddress] = $blockchainReader->call($fundManagerAddress, 'YIELD_POOL()', [], ['address']);
          \assert(\is_string($yieldPoolAddress));

          // 4. Build, sign (real private key — see class docblock), and execute connectPool
          //    via the Safe's execTransaction.
          $connectPoolData = AbiEncoder::encodeDynamic('connectPool(address,bytes)', [
              ['type' => 'address', 'value' => $yieldPoolAddress],
              ['type' => 'bytes', 'value' => ''],
          ]);

          [$nonce] = $blockchainReader->call($safeAddress, 'nonce()', [], ['uint256']);
          \assert(\is_string($nonce));

          $safeTxHashHex = $this->computeAndSignSafeTransaction(
              $blockchainReader,
              $safeAddress,
              $gdaForwarderAddress,
              $connectPoolData,
              $nonce,
              self::ADMIN_PRIVATE_KEY,
          );

          $execData = AbiEncoder::encodeDynamic('execTransaction(address,uint256,bytes,uint8,uint256,uint256,uint256,address,address,bytes)', [
              ['type' => 'address', 'value' => $gdaForwarderAddress],
              ['type' => 'uint256', 'value' => '0'],
              ['type' => 'bytes', 'value' => $connectPoolData],
              ['type' => 'uint8', 'value' => '0'],
              ['type' => 'uint256', 'value' => '0'],
              ['type' => 'uint256', 'value' => '0'],
              ['type' => 'uint256', 'value' => '0'],
              ['type' => 'address', 'value' => '0x0000000000000000000000000000000000000000'],
              ['type' => 'address', 'value' => '0x0000000000000000000000000000000000000000'],
              ['type' => 'bytes', 'value' => $safeTxHashHex['signature']],
          ]);

          $execTxHash = $anvil->sendTransaction(['from' => self::ADMIN_WALLET, 'to' => $safeAddress, 'data' => $execData, 'gas' => '0x2DC6C0']);
          $anvil->mine();

          $executionResult = ($confirmExecution)($admin, $group->id, SafeTransactionPurpose::ConnectYieldPool, new TransactionHash($execTxHash), new TransactionHash($safeTxHashHex['hash']));
          self::assertTrue($executionResult->isSuccess);
          self::assertSame('confirmed', $executionResult->value()->status);
      }

      private function requiredEnv(string $name): string
      {
          $value = $_ENV[$name] ?? getenv($name);
          if (!\is_string($value) || '' === $value) {
              throw new \RuntimeException(\sprintf('Missing required env var "%s" for the integration test.', $name));
          }

          return $value;
      }

      /**
       * @return array{hash: string, signature: string}
       */
      private function computeAndSignSafeTransaction(
          \App\Application\Vault\Port\BlockchainReaderInterface $blockchainReader,
          string $safeAddress,
          string $to,
          string $data,
          string $nonce,
          string $privateKey,
      ): array {
          [$hash] = $blockchainReader->call($safeAddress, 'getTransactionHash(address,uint256,bytes,uint8,uint256,uint256,uint256,address,address,uint256)', [
              $to, '0', $data, '0', '0', '0', '0', '0x0000000000000000000000000000000000000000', '0x0000000000000000000000000000000000000000', $nonce,
          ], ['uint256']);
          \assert(\is_string($hash));

          // Convert back to a 0x-prefixed bytes32 hex string for signing — $hash comes back
          // as a decimal string per BlockchainReaderInterface's uint256 decoding convention.
          $hashHex = '0x'.str_pad(gmp_strval(gmp_init($hash, 10), 16), 64, '0', \STR_PAD_LEFT);

          // secp256k1 sign over the raw 32-byte digest (v in {27,28}) — this is exactly what
          // Safe's checkNSignatures expects for a direct-hash (non-eth_sign) signature, and
          // exactly what an EIP-712 signTypedData signature would produce, since
          // getTransactionHash() IS the final EIP-712 digest.
          $signature = \App\Tests\Integration\Deposit\Support\AbiEncoder::signDigest($hashHex, $privateKey);

          return ['hash' => $hashHex, 'signature' => $signature];
      }
  }
  ```

  `AbiEncoder::signDigest()` is the method added in Step 3 above — its body depends on the verified `kornrunner/secp256k1` API, not guessed here.

- [ ] **Step 5: Run against the fork**

  Run: `make anvil-up` then `make test-integration`
  Expected: PASS. If it fails with a signature error (`GS026`), re-verify Task 1's EIP-712 domain/struct assumptions against the actual deployed Safe version on the fork before changing anything else — and double check `AbiEncoder::encodeDynamic()`'s offset arithmetic against a known-good encoded call (e.g. compare against what `viem`'s `encodeFunctionData` produces for the same `setup(...)` call, from a Node REPL, as an independent cross-check).

- [ ] **Step 6: Tear down**

  Run: `make anvil-down`

- [ ] **Step 7: Commit**

  ```bash
  git add tests/Integration/Safe/GroupSafeFlowTest.php tests/Integration/Deposit/Support/AbiEncoder.php
  git commit -m "test: add Anvil integration test for the group Safe deploy+connect flow"
  ```

---

### Task 20: Final verification

**Files:** none (verification only)

- [ ] **Step 1: Full quality gate**

  Run: `make ci-local` (equivalent to what the pre-push hook runs: `make qa` + `make lint`)
  Expected: PASS.

- [ ] **Step 2: Integration suite**

  Run: `make anvil-up && make test-integration && make anvil-down`
  Expected: PASS, including the new `GroupSafeFlowTest`.

- [ ] **Step 3: Manual browser walkthrough**

  Run: `make up`, then in a browser:
  1. Log in, create a new tontine group (you become its admin).
  2. Visit the group page — confirm the "Provisionner le coffre du groupe" button is visible (admin-only) and the deposit section shows "En attente de la configuration du coffre..." instead of the deposit form.
  3. Click it, sign the deployment transaction, wait for confirmation — confirm the page now shows the Safe's address and the deposit form appears.
  4. Click "Connecter le pool de rendement", sign the Safe transaction — confirm status flips to "Coffre actif et connecté au pool de rendement."
  5. Log in as a **second**, non-admin member (join via the invitation link) and deposit USDC — confirm the deposit is recorded as `Confirmed` (not `Failed`) even though the vault's `owner`/`receiver` is the Safe, not this member's own wallet (this specifically exercises Task 2's fix).
  6. Wait for (or manually trigger) the next `SyncVaultPositionsCommand` run — confirm the group dashboard's "Position on-chain du coffre" section now shows a non-zero principal.

- [ ] **Step 4: Report status**

  If all of the above pass, Phase 1 is complete. Update Linear tickets JAS-37→41 to Done (JAS-42 once its integration test task/Task 19 is confirmed passing), leave JAS-26 (Phase 2 withdrawal) in Backlog.
