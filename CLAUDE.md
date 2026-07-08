# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## What this is

Sftontine: a web3 collective-savings ("tontine") app. Members deposit USDC from **Privy** embedded
wallets (EOA) into the official **SuperVault** (Suplabs) on **Base mainnet** (chainId 8453); the
shared pot itself is accounted off-chain. There is no on-chain tontine/ROSCA contract yet — that's a
later phase. Built on Symfony 8.1 / PHP 8.5 via `symfony-docker` (FrankenPHP + Caddy).

Key on-chain addresses (Base mainnet) are pinned in `.env` / `config/abi/README.md`: vault
`0x8C60...b95Aa`, fund manager `0x9041...D76bd`, GDA forwarder `0x6DA1...1dE08`, USDC
`0x8335...02913`. ABI fragments in `config/abi/*.json` are hand-verified against BaseScan/the
SuperVault integration guide — never guess a signature, extend `config/abi/README.md` instead.

## Everything runs in Docker — never call `vendor/bin/*` or `bin/console` directly

`var/` and the container's PHP are owned by the container user; running tools from the host breaks
permissions. Always go through `make`:

```bash
make up                 # start the stack (docker compose up --detach)
make sh / make bash      # shell into the php (FrankenPHP) container

make test c="--filter=WalletAddressTest"   # PHPUnit (pass extra args via c=)
make phpstan             # PHPStan (memory-limit=512M)
make deptrac              # architecture boundary check (no-cache)
make cs / make cs-fix     # php-cs-fixer dry-run / fix
make qa                   # test + phpstan + deptrac + cs — what CI's "Tests" job runs
make lint                 # super-linter locally, same env as CI's "Lint" job
make ci-local              # qa + lint — run before pushing

make sf c=about            # bin/console wrapper, pass a command via c=
make migrate                # doctrine:migrations:migrate --no-interaction
```

A versioned `pre-push` hook runs `make ci-local` and blocks the push on failure. Enable it once per
clone with `git config core.hooksPath .githooks` (bypass in an emergency with `git push --no-verify`).

### Integration tests against a real Base fork

`tests/Integration/Deposit/DepositFlowTest.php` exercises the full approve→deposit→connectPool flow
against a real Anvil fork of Base mainnet — no private key is ever used; the test wallet is funded via
`anvil_setStorageAt` and sends unsigned txs via `anvil_impersonateAccount`. It's excluded from
`make test`/`make qa` (PHPUnit group `integration`, see `phpunit.dist.xml`).

```bash
make anvil-up            # start the fork (docker compose profile "integration")
make test-integration     # run only #[Group('integration')] tests
make anvil-down            # stop the fork
```

If Anvil crashes with a 403 "Archive requests require a personal token" from the free public RPC,
`docker compose --profile integration up -d anvil --force-recreate` usually recovers it; for heavy use
point `ANVIL_FORK_RPC_URL` at an archive-capable provider (Alchemy/Infura/QuickNode) via `.env.local`.

### AI Mate MCP tools

`symfony/ai-mate` (+ monolog/symfony extensions) is installed and exposes MCP tools — prefer them over
raw CLI: `server-info` instead of `php -v`/`php -m`, `monolog-tail`/`monolog-search` instead of
`tail`/`grep` on `var/log`, `symfony-services` instead of `bin/console debug:container`,
`symfony-profiler-list`/`symfony-profiler-get` for profiler data. Full instructions in
`mate/AGENT_INSTRUCTIONS.md`.

## Architecture: hexagonal, enforced by Deptrac

`src/` is split into four layers (`deptrac.yaml`), dependencies point strictly inward:

```
Presentation  →  Application  →  Domain
Infrastructure → Application, Domain
```

- **`src/Domain/`** — pure business classes (entities, value objects, domain exceptions). **Never
  registered as services** (`config/services.yaml` explicitly excludes `src/Domain/` from
  autowiring) — no framework, no DI, construct with `new`. The only allowed vendor deps are
  `moneyphp/money`, `BcMath\Number`, and Doctrine ORM *mapping attributes* (metadata only, read via
  reflection — not the same as depending on Doctrine itself).
- **`src/Application/`** — use cases (`UseCase/`) and the ports they depend on (`Port/`, interfaces
  only). May depend on Domain, Money, BcMath, and PSR interfaces (clock, logger) — never a concrete
  implementation.
- **`src/Infrastructure/`** — adapters implementing Application ports: `Gateways/` (on-chain RPC
  reads), `Persistence/` (Doctrine repositories + custom DBAL types), `Privy/` (JWT verification),
  `Mercure/` (real-time push), `Security/` (Symfony authenticator/user provider), `Scheduler/`
  (recurring tasks). May depend on anything inward plus Symfony/Doctrine/PSR.
- **`src/Presentation/`** — `Http/` controllers, `Console/` commands, `Twig/Components/` (UX Live
  Components / TwigComponent). Same inward-dependency rule as Infrastructure, minus Doctrine.

**Composition root**: `config/services.yaml` is where Application ports get bound to Infrastructure
adapters (`App\Application\...Interface: '@App\Infrastructure\...'`). When adding a new port,
register the binding there, not via autowiring type-hints alone.

Run `make deptrac` after moving/adding classes across these boundaries — CI enforces it and it fails
loudly on any inward-pointing violation.

### Money and blockchain values

Never use `float` for amounts. Monetary values use `moneyphp/money`; raw on-chain uint256/int96
values use `BcMath\Number` (native PHP extension). Both are explicitly allowed in `Domain` by
`deptrac.yaml` because they're pure value libraries, not framework coupling.

### Decorator pattern for side effects on persistence

`DepositTransactionStatusPublisher` (`Infrastructure/Mercure/`) wraps
`DoctrineDepositTransactionRepository` and is bound to
`DepositTransactionRepositoryInterface` in `config/services.yaml` (`$inner: '@...Doctrine...'`) —
every `save()` also pushes a Turbo Stream update over Mercure. Follow this pattern (decorate the
Doctrine adapter, don't bake side effects into it) if a repository needs another cross-cutting
concern.

## Identity: Privy

Auth is JWT-based via Privy (embedded EOA wallet, email/social login), verified server-side with
`firebase/php-jwt` (deliberately not LexikJWTAuthenticationBundle). `PrivyAuthenticator` /
`SecurityUser` / `SecurityUserProvider` live in `Infrastructure/Security/`; token verification in
`Infrastructure/Privy/` (`PrivyJwtVerifier`, `PrivyIdentityTokenVerifier`).

`PRIVY_APP_ID`/`PRIVY_APP_CLIENT_ID` are public identifiers (like an OAuth client_id) safe to expose
client-side; `PRIVY_VERIFICATION_KEY` is a public ES256 key used server-side to verify JWT
signatures — not a secret, but doesn't need frontend exposure either. Real values go in `.env.local`
(never committed); `.env`/`.env.dev` only carry placeholders/non-secret defaults.

### The Privy SDK is vendored, not resolved via AssetMapper

`@privy-io/react-auth` pulls in wallet connectors (wagmi/viem, WalletConnect, MetaMask SDK) that need
mutually **incompatible** versions of `@noble/curves`/`@noble/hashes` — AssetMapper's one-version-
per-package import map can't resolve that. The SDK is pre-bundled with esbuild into a single ESM file
(`react`/`react-dom` excluded to reuse the app's instance) and vendored at
`assets/vendor/privy/privy-react-auth.esm.js`, referenced by path in `importmap.php`. The full
regeneration recipe (needed when bumping the SDK or adding an export like `useIdentityToken`) is in
`README.md` under "Le SDK `@privy-io/react-auth` est vendorisé". Non-obvious pitfalls documented
there: a missing `react/jsx-runtime` importmap entry breaks some connector UI, CJS `require("react")`
inside `__commonJS` wrappers needs a manual shim banner, and the bundle only exports what's listed in
`entry.js` — `make qa` won't catch a missing export, only a real browser load will.

By contrast **`viem` is resolved normally** via AssetMapper (`bin/console importmap:require viem`) —
no vendoring needed.

## Deposits (on-chain writes)

Flow documented in full in `config/abi/README.md`: `approve(vault, assets)` →
`vault.deposit(assets, receiver)` → `gdaForwarder.connectPool(yieldPoolAddress, "0x")`. This is the
"classic" signed-tx flow (not the gasless EIP-712 macro path, which is out of scope). Apply a 2x gas
buffer (`TX_GAS_BUFFER`) to all three writes. The yield pool address is always resolved dynamically
on-chain via `SyncFundManager.YIELD_POOL()`, never hardcoded.

`assets/react/controllers/VaultDeposit.jsx` gets the EIP-1193 provider from the Privy embedded wallet
via `useWallets()` + `wallet.getEthereumProvider()`, and resolves the yield pool address on-chain
itself rather than from the backend, so `/profile` never blocks rendering on an RPC round-trip.

Transaction status updates use real **Turbo Streams pushed via Mercure**
(`symfony/mercure-bundle`; the hub already runs inside the FrankenPHP/Caddy container). Templates use
`<turbo-mercure-stream-source>` (not `<turbo-stream-source>`) — auto-loaded by the `turbo-core`
Stimulus controller; the `mercure-turbo-stream` controller is deprecated, don't enable it.

There is no externally-callable `paused()` — `StableYieldSyncVault._isExternallyPaused()` is
`internal`. Use `maxDeposit`/`maxWithdraw`/`maxRedeem`/`maxMint` (return `0` when paused) as the
integrator-facing pause signal instead.

## Tontines (off-chain pot)

`src/Domain/Tontine/` (groups, memberships, savings cycles, contributions) sits on top of the
Deposit module: a `Contribution` links to a confirmed `DepositTransaction` by `TransactionHash`
(unique column, no FK — same precedent as Deposit↔User by `WalletAddress`), never by reusing a
deposit twice. `RecordContribution` (`Application/Tontine/UseCase/`) re-verifies the deposit is
`Confirmed` and belongs to the caller's wallet before delegating to the domain.

### No `Doctrine\Common\Collections` in `TontineGroup`

`deptrac.yaml` only allows `Doctrine\ORM\Mapping` (attributes) in Domain, not
`Doctrine\Common\Collections` — so `TontineGroup`'s memberships/cycles/contributions are plain
`array` properties, **not** Doctrine `OneToMany`. `Membership`/`SavingsCycle`/`Contribution` each
have a unidirectional `ManyToOne` back to the group (no `inversedBy`). Doctrine hydrates the
group's own columns via reflection on load but leaves these arrays at their `[]` default, so
`DoctrineTontineGroupRepository::find()` queries the children separately and calls
`TontineGroup::attachPersistedChildren()` once to repopulate them (guards against being called
twice via the identity map). Tests seeding a `TontineGroup` through
`tests/Factory/Tontine/TontineGroupFactory.php` must go through this same repository's `save()`
in an `afterPersist` hook — Foundry's default single-entity persist doesn't know about the
children either.

### `Result<T, E>` for expected business errors

`src/Application/Shared/Result.php` — a small covariant success/failure wrapper (`E of
\BackedEnum`) used by `CreateTontineGroup`/`JoinTontineGroup`/`RecordContribution` instead of
throwing for *expected* outcomes (invalid input, not a member, deposit not confirmed, etc.).
Each use case declares its own string-backed error enum next to it; controllers `match` on it
for the French message. Unexpected states still throw.

### ObjectMapper only at the Presentation boundary

`symfony/object-mapper` (`ObjectMapperInterface`, autowired as `object_mapper`) is used only in
`src/Presentation/Http/Dto/` — `Symfony\Component\ObjectMapper\Attribute\Map` is forbidden in
Application by Deptrac. Inbound: `CreateTontineGroupInput` (`#[Map(target:
CreateTontineGroupCommand::class)]`) maps form fields to the use-case command. Outbound:
`TontineGroupListItem` (`#[Map(source: GroupSummary::class)]`) maps the Application DTO for the
list page, with a property-level `transform` for the French periodicity label. Never map
directly into Domain entities (private constructors + invariants) — always pass through a use
case or a named domain constructor.

### Signed invitation links

Group invites use Symfony's built-in `UriSigner` (autowired as `uri_signer`, HMAC over
`APP_SECRET`, 7-day expiration) rather than a token table — `TontineInvitationController` calls
`$uriSigner->verify($request)` on every GET/POST to `/tontines/{id}/rejoindre` before doing
anything else. The join form's `action` is set to the exact current signed URL (not left empty)
so the `_hash`/`_expiration` query params survive the POST. An anonymous visitor is shown
`PrivyLogin` with a `returnTo` prop; `PrivyAuthenticator::onAuthenticationSuccess` only honors
`returnTo` if it's a same-origin path (`/…`, not `//…`) to avoid an open redirect.

### Live Component re-checks membership on every render

`TontineGroupDashboard` (`Presentation/Twig/Components/`) takes `groupId` as a `LiveProp` and
re-verifies the current user is still a member on every render, not just on mount — the
component's own AJAX re-render endpoint (`ux_live_component`) bypasses the controller's
membership guard, so a tampered `groupId` must be rejected by the component itself. Tested via
`Symfony\UX\LiveComponent\Test\InteractsWithLiveComponents::createLiveComponent()`, which hits
the component directly rather than through `/tontines/{id}`.

## Knowledge graph

`graphify-out/` holds a generated code knowledge graph (`graph.json`, `GRAPH_REPORT.md`,
`manifest.json`). Built from a specific commit — check staleness with `git rev-parse HEAD` against
"Graph Freshness" in `GRAPH_REPORT.md`, and refresh with `graphify update .` (no API cost) rather than
rebuilding from scratch.
