# Tontine Periodicity Extension (Ponctuelle + Semestrielle) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add two new `Periodicity` values to the Tontine domain — `Semiannual` (recurring, `P6M`) and `Punctual` (non-recurring: free contributions, no due-date schedule, no automatic cycle end) — end to end from domain to templates.

**Architecture:** `Periodicity::isRecurring()` is the single new abstraction: `false` only for `Punctual`. Every place that currently calls `dateInterval()` or computes a fixed cycle end unconditionally is guarded by it. `SavingsCycle::$endsAt` becomes nullable to represent "open cycle, closed manually" for Punctual groups. No new abstractions beyond this — `Semiannual` needs zero special-casing, it's just a fourth recurring interval.

**Tech Stack:** PHP 8.5 / Symfony 8.1, Doctrine ORM, PHPUnit, Twig, Symfony UX Live Components. Spec: `docs/superpowers/specs/2026-07-07-tontine-periodicity-extension-design.md`.

## Global Constraints

- Everything runs in Docker — every command below is a `make` target, never `vendor/bin/*`/`bin/console` directly (breaks permissions on the host, see `CLAUDE.md`).
- `make qa` (test + phpstan + deptrac + cs) must stay green after every task; run `make cs-fix` before committing if `make cs` fails.
- Never use `float` for amounts — this plan touches no monetary math, only dates/schedules, so this is inherited context, not a new constraint to apply here.
- Domain (`src/Domain/`) stays framework-free — only `Doctrine\ORM\Mapping` (attributes), `moneyphp/money`, `BcMath\Number` are allowed there (enforced by `make deptrac`).
- `installmentsPerCycle` for `Punctual` is always forced to `1` by the domain (`TontineGroup::create()`), regardless of what any caller passes — the server is the sole source of truth, never trust client input for this.
- French UI strings throughout (existing convention in this codebase).

---

### Task 1: `Periodicity` — add `Semiannual`, `Punctual`, `isRecurring()`

**Files:**
- Modify: `src/Domain/Tontine/Periodicity.php`
- Test: `tests/Domain/Tontine/PeriodicityTest.php`

**Interfaces:**
- Produces: `Periodicity::Semiannual` (value `'semiannual'`), `Periodicity::Punctual` (value `'punctual'`), `Periodicity::isRecurring(): bool` (true for `Weekly`/`Biweekly`/`Monthly`/`Semiannual`, false for `Punctual`). `dateInterval()` gains a `Semiannual => 'P6M'` case but has **no** `Punctual` case — calling it on `Punctual` throws PHP's native `\UnhandledMatchError`, used deliberately as a "should never happen" guard (every call site is protected by `isRecurring()` from Task 2 onward).

- [ ] **Step 1: Write the failing tests**

Replace the full content of `tests/Domain/Tontine/PeriodicityTest.php` with:

```php
<?php

declare(strict_types=1);

namespace App\Tests\Domain\Tontine;

use App\Domain\Tontine\Periodicity;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class PeriodicityTest extends TestCase
{
    /**
     * @return iterable<string, array{Periodicity, string}>
     */
    public static function provideIntervals(): iterable
    {
        yield 'weekly' => [Periodicity::Weekly, 'P7D'];
        yield 'biweekly' => [Periodicity::Biweekly, 'P14D'];
        yield 'monthly' => [Periodicity::Monthly, 'P1M'];
        yield 'semiannual' => [Periodicity::Semiannual, 'P6M'];
    }

    #[DataProvider('provideIntervals')]
    public function testDateIntervalMatchesPeriod(Periodicity $periodicity, string $expectedSpec): void
    {
        $start = new \DateTimeImmutable('2026-01-01T00:00:00+00:00');

        self::assertEquals(
            $start->add(new \DateInterval($expectedSpec)),
            $start->add($periodicity->dateInterval()),
        );
    }

    public function testDateIntervalThrowsForPunctual(): void
    {
        $this->expectException(\UnhandledMatchError::class);

        Periodicity::Punctual->dateInterval();
    }

    /**
     * @return iterable<string, array{Periodicity}>
     */
    public static function provideRecurringPeriodicities(): iterable
    {
        yield 'weekly' => [Periodicity::Weekly];
        yield 'biweekly' => [Periodicity::Biweekly];
        yield 'monthly' => [Periodicity::Monthly];
        yield 'semiannual' => [Periodicity::Semiannual];
    }

    #[DataProvider('provideRecurringPeriodicities')]
    public function testIsRecurringIsTrueForRecurringPeriodicities(Periodicity $periodicity): void
    {
        self::assertTrue($periodicity->isRecurring());
    }

    public function testIsRecurringIsFalseForPunctual(): void
    {
        self::assertFalse(Periodicity::Punctual->isRecurring());
    }
}
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `make test c="--filter=PeriodicityTest"`
Expected: FAIL — `Periodicity::Semiannual` / `Periodicity::Punctual` / `Periodicity::isRecurring()` don't exist yet (fatal error or "Undefined constant"/"Call to undefined method").

- [ ] **Step 3: Implement**

Replace the full content of `src/Domain/Tontine/Periodicity.php` with:

```php
<?php

declare(strict_types=1);

namespace App\Domain\Tontine;

enum Periodicity: string
{
    case Weekly = 'weekly';
    case Biweekly = 'biweekly';
    case Monthly = 'monthly';
    case Semiannual = 'semiannual';
    case Punctual = 'punctual';

    /**
     * False only for Punctual: free contributions, no due-date schedule, never late.
     */
    public function isRecurring(): bool
    {
        return self::Punctual !== $this;
    }

    /**
     * @throws \UnhandledMatchError if called on a non-recurring periodicity (Punctual) —
     *         every call site must guard with isRecurring() first
     */
    public function dateInterval(): \DateInterval
    {
        return new \DateInterval(match ($this) {
            self::Weekly => 'P7D',
            self::Biweekly => 'P14D',
            self::Monthly => 'P1M',
            self::Semiannual => 'P6M',
        });
    }
}
```

- [ ] **Step 4: Run tests to verify they pass**

Run: `make test c="--filter=PeriodicityTest"`
Expected: PASS (4 test methods, 10 total test executions once the two `#[DataProvider]` sets
are expanded: 4 interval cases + 1 throws case + 4 isRecurring-true cases + 1
isRecurring-false case)

- [ ] **Step 5: Commit**

```bash
git add src/Domain/Tontine/Periodicity.php tests/Domain/Tontine/PeriodicityTest.php
git commit -m "Ajoute les périodicités Semestrielle et Ponctuelle à Periodicity"
```

---

### Task 2: `SavingsCycle.endsAt` nullable + `TontineGroup::create()` branches on `isRecurring()`

**Files:**
- Modify: `src/Domain/Tontine/SavingsCycle.php`
- Modify: `src/Domain/Tontine/TontineGroup.php`
- Test: `tests/Domain/Tontine/TontineGroupTest.php`
- Migration: generated via `make sf c=doctrine:migrations:diff` (see Step 6)

**Interfaces:**
- Consumes: `Periodicity::isRecurring()` (Task 1).
- Produces: `SavingsCycle::$endsAt` is now `?\DateTimeImmutable` (was `\DateTimeImmutable`). `SavingsCycle::open(TontineGroup $group, int $number, \DateTimeImmutable $startsAt, ?\DateTimeImmutable $endsAt): self` (param now nullable). `TontineGroup::create(...)` behavior for `Punctual`: `installmentsPerCycle` is always normalized to `1` regardless of the value passed in (no `InvalidCycleLengthException` for out-of-range values), and the created cycle's `endsAt` is `null`. Behavior for recurring periodicities is unchanged.

- [ ] **Step 1: Write the failing tests**

Add these three test methods to `tests/Domain/Tontine/TontineGroupTest.php`, right after `testCreateRejectsTooManyInstallments` (after line 100, before `testJoinAddsMemberWithMemberRoleAndRecordsEvent`):

```php
    public function testCreateWithPunctualPeriodicityForcesOneInstallmentAndNoEndDate(): void
    {
        $now = new \DateTimeImmutable(self::NOW);

        $group = TontineGroup::create(self::alice(), 'Tontine famille', self::usdc('25000000'), Periodicity::Punctual, 12, $now);

        self::assertSame(1, $group->installmentsPerCycle);
        $cycle = $group->currentCycle();
        self::assertNotNull($cycle);
        self::assertNull($cycle->endsAt);
    }

    public function testCreateWithPunctualPeriodicityIgnoresOutOfRangeInstallments(): void
    {
        $group = TontineGroup::create(self::alice(), 'Tontine famille', self::usdc('25000000'), Periodicity::Punctual, 0, new \DateTimeImmutable(self::NOW));

        self::assertSame(1, $group->installmentsPerCycle);
    }

    public function testCreateWithSemiannualPeriodicityComputesEndsAtWithSixMonthInterval(): void
    {
        $now = new \DateTimeImmutable(self::NOW);

        $group = TontineGroup::create(self::alice(), 'Tontine famille', self::usdc('25000000'), Periodicity::Semiannual, 2, $now);

        $cycle = $group->currentCycle();
        self::assertNotNull($cycle);
        self::assertEquals($now->add(new \DateInterval('P12M')), $cycle->endsAt); // 2 × P6M
    }
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `make test c="--filter=TontineGroupTest"`
Expected: FAIL, for two different reasons (both correct — `create()` doesn't know about
`isRecurring()` yet):
- `testCreateWithPunctualPeriodicityForcesOneInstallmentAndNoEndDate` fails with
  `\UnhandledMatchError` — `installmentsPerCycle=12` passes the existing 2–52 range check
  unconditionally, so execution reaches the `for` loop, which calls
  `$periodicity->dateInterval()` on `Punctual` (Task 1 made that throw).
- `testCreateWithPunctualPeriodicityIgnoresOutOfRangeInstallments` fails with an uncaught
  `InvalidCycleLengthException` instead — `installmentsPerCycle=0` is rejected by the
  existing unconditional range check before the loop is ever reached, so this test never
  gets far enough to hit the `dateInterval()` guard at all.

`testCreateWithSemiannualPeriodicityComputesEndsAtWithSixMonthInterval` should already PASS
(Semiannual is already a normal recurring case after Task 1) — confirm it passes now so you
know Task 2's changes don't accidentally break it.

- [ ] **Step 3: Implement — make `endsAt` nullable**

In `src/Domain/Tontine/SavingsCycle.php`, change the constructor parameter (currently line 27-28):

```php
        #[ORM\Column(name: 'ends_at', type: 'datetime_immutable')]
        public readonly \DateTimeImmutable $endsAt,
```
to:
```php
        #[ORM\Column(name: 'ends_at', type: 'datetime_immutable', nullable: true)]
        public readonly ?\DateTimeImmutable $endsAt,
```

And change `open()` (currently line 34-37):
```php
    public static function open(TontineGroup $group, int $number, \DateTimeImmutable $startsAt, \DateTimeImmutable $endsAt): self
    {
        return new self($group, $number, $startsAt, $endsAt, SavingsCycleStatus::Open);
    }
```
to:
```php
    public static function open(TontineGroup $group, int $number, \DateTimeImmutable $startsAt, ?\DateTimeImmutable $endsAt): self
    {
        return new self($group, $number, $startsAt, $endsAt, SavingsCycleStatus::Open);
    }
```

- [ ] **Step 4: Implement — branch `TontineGroup::create()` on `isRecurring()`**

In `src/Domain/Tontine/TontineGroup.php`, replace the body of `create()` (currently lines 88-116) with:

```php
    public static function create(User $creator, string $name, Money $contributionAmount, Periodicity $periodicity, int $installmentsPerCycle, \DateTimeImmutable $now): self
    {
        $length = mb_strlen($name);
        if ($length < self::MIN_NAME_LENGTH || $length > self::MAX_NAME_LENGTH) {
            throw InvalidGroupNameException::forValue($name);
        }

        if ($contributionAmount->isZero() || $contributionAmount->isNegative()) {
            throw InvalidContributionAmountException::forNonPositiveAmount();
        }

        if ($periodicity->isRecurring()) {
            if ($installmentsPerCycle < self::MIN_INSTALLMENTS_PER_CYCLE || $installmentsPerCycle > self::MAX_INSTALLMENTS_PER_CYCLE) {
                throw InvalidCycleLengthException::forValue($installmentsPerCycle);
            }
        } else {
            $installmentsPerCycle = 1;
        }

        $group = new self($name, $contributionAmount->getAmount(), $periodicity, $installmentsPerCycle, TontineGroupStatus::Active, $now);

        $endsAt = null;
        if ($periodicity->isRecurring()) {
            $endsAt = $now;
            for ($i = 0; $i < $installmentsPerCycle; ++$i) {
                $endsAt = $endsAt->add($periodicity->dateInterval());
            }
        }
        $group->cycles[] = SavingsCycle::open($group, 1, $now, $endsAt);

        $membership = Membership::admin($group, $creator, $now);
        $group->memberships[] = $membership;
        $group->record(new MemberJoined($group->name, $creator->id, $now));

        return $group;
    }
```

- [ ] **Step 5: Run tests to verify they pass**

Run: `make test c="--filter=TontineGroupTest"`
Expected: PASS (all tests in the file, including the 3 new ones)

Then run the full Tontine domain suite to catch any regression from the `endsAt` type change:
Run: `make test c="--filter=Tontine"`
Expected: PASS — if `ContributionScheduleTest` or `GroupPotReaderTest` fail here, that's expected and fixed in Tasks 3–4, not this task; only investigate failures in `TontineGroupTest` itself right now.

- [ ] **Step 6: Generate and review the migration**

Run: `make sf c=doctrine:migrations:diff`
Expected output: a message like `Generated new migration class to ".../migrations/Version<timestamp>.php"`.

Open the generated file and confirm its `up()` contains exactly one statement equivalent to:
```sql
ALTER TABLE tontine_savings_cycle ALTER ends_at DROP NOT NULL
```
(Doctrine/PostgreSQL may render this as `ALTER COLUMN ends_at DROP NOT NULL` — either form is correct; what matters is it's a single nullability change on `tontine_savings_cycle.ends_at` with no other unrelated statements). Set its `getDescription()` to:
```php
    public function getDescription(): string
    {
        return 'SavingsCycle.ends_at devient nullable (cycles Ponctuels sans date de fin).';
    }
```

- [ ] **Step 7: Run the migration**

Run: `make migrate`
Expected: `Successfully migrated to version: DoctrineMigrations\Version<timestamp>`

Run: `make sf c="doctrine:schema:validate"`
Expected: `[OK] The mapping files are correct.` and `[OK] The database schema is in sync with the mapping files.`

- [ ] **Step 8: Commit**

```bash
git add src/Domain/Tontine/SavingsCycle.php src/Domain/Tontine/TontineGroup.php tests/Domain/Tontine/TontineGroupTest.php migrations/
git commit -m "Rend SavingsCycle.endsAt nullable pour les tontines Ponctuelles"
```

---

### Task 3: `ContributionSchedule::dueDates()` returns no schedule for non-recurring periodicities

**Files:**
- Modify: `src/Domain/Tontine/ContributionSchedule.php`
- Test: `tests/Domain/Tontine/ContributionScheduleTest.php`

**Interfaces:**
- Consumes: `Periodicity::isRecurring()` (Task 1), `SavingsCycle::open(..., ?\DateTimeImmutable $endsAt)` (Task 2).
- Produces: `ContributionSchedule::dueDates()` returns `[]` when `!$periodicity->isRecurring()`. `expectedInstallmentsFor()`/`missedInstallmentsFor()`/`nextDueDateFor()` need **no code changes** — they already iterate over `dueDates()`, so an empty list naturally yields `expected=0`, `missed=0` (`max(0, 0 - paidCount)`), `nextDueDate=null`.

- [ ] **Step 1: Write the failing test**

Add this test method to `tests/Domain/Tontine/ContributionScheduleTest.php`, right after `testDueDatesAreSpacedByPeriodicityStartingAtCycleStart` (after line 40):

```php
    public function testDueDatesIsEmptyForPunctualPeriodicity(): void
    {
        $group = self::makeGroup();
        $cycle = SavingsCycle::open($group, 1, new \DateTimeImmutable('2026-01-01T00:00:00+00:00'), null);

        $dueDates = $this->schedule->dueDates($cycle, Periodicity::Punctual, 1);

        self::assertSame([], $dueDates);
    }

    public function testScheduleMethodsAgreeThereIsNoExpectedInstallmentOrLatenessForPunctual(): void
    {
        $group = self::makeGroup();
        $cycle = SavingsCycle::open($group, 1, new \DateTimeImmutable('2026-01-01T00:00:00+00:00'), null);
        $membership = self::makeMembership(new \DateTimeImmutable('2026-01-01T00:00:00+00:00'));
        $now = new \DateTimeImmutable('2027-01-01T00:00:00+00:00');

        self::assertSame(0, $this->schedule->expectedInstallmentsFor($membership, $cycle, Periodicity::Punctual, 1, $now));
        self::assertSame(0, $this->schedule->missedInstallmentsFor($membership, $cycle, Periodicity::Punctual, 1, $now, paidCount: 0));
        self::assertNull($this->schedule->nextDueDateFor($membership, $cycle, Periodicity::Punctual, 1, $now));
    }
```

- [ ] **Step 2: Run test to verify it fails**

Run: `make test c="--filter=ContributionScheduleTest"`
Expected: FAIL — both new tests fail with `\UnhandledMatchError`, for the same underlying
reason: `dueDates()` currently calls `$periodicity->dateInterval()` unconditionally in its
loop (since `$installmentsPerCycle=1 > 0`, the loop body runs at least once). This directly
breaks `testDueDatesIsEmptyForPunctualPeriodicity` (calls `dueDates()` itself), and
transitively breaks `testScheduleMethodsAgreeThereIsNoExpectedInstallmentOrLatenessForPunctual`
too, since `expectedInstallmentsFor()`/`missedInstallmentsFor()`/`nextDueDateFor()` all call
`dueDates()` internally.

- [ ] **Step 3: Implement**

In `src/Domain/Tontine/ContributionSchedule.php`, replace `dueDates()` (currently lines 12-22):

```php
    /**
     * @return list<\DateTimeImmutable>
     */
    public function dueDates(SavingsCycle $cycle, Periodicity $periodicity, int $installmentsPerCycle): array
    {
        $dueDates = [];
        $dueDate = $cycle->startsAt;
        for ($i = 0; $i < $installmentsPerCycle; ++$i) {
            $dueDates[] = $dueDate;
            $dueDate = $dueDate->add($periodicity->dateInterval());
        }

        return $dueDates;
    }
```
with:
```php
    /**
     * @return list<\DateTimeImmutable>
     */
    public function dueDates(SavingsCycle $cycle, Periodicity $periodicity, int $installmentsPerCycle): array
    {
        if (!$periodicity->isRecurring()) {
            return [];
        }

        $dueDates = [];
        $dueDate = $cycle->startsAt;
        for ($i = 0; $i < $installmentsPerCycle; ++$i) {
            $dueDates[] = $dueDate;
            $dueDate = $dueDate->add($periodicity->dateInterval());
        }

        return $dueDates;
    }
```

- [ ] **Step 4: Run tests to verify they pass**

Run: `make test c="--filter=ContributionScheduleTest"`
Expected: PASS (all tests in the file)

- [ ] **Step 5: Commit**

```bash
git add src/Domain/Tontine/ContributionSchedule.php tests/Domain/Tontine/ContributionScheduleTest.php
git commit -m "ContributionSchedule : aucune échéance pour les périodicités non récurrentes"
```

---

### Task 4: `GroupPotReader`/`GroupPotView` expose the periodicity

**Files:**
- Modify: `src/Application/Tontine/Dto/GroupPotView.php`
- Modify: `src/Application/Tontine/UseCase/GroupPotReader.php`
- Test: `tests/Application/Tontine/UseCase/GroupPotReaderTest.php`

**Interfaces:**
- Consumes: `TontineGroup::$periodicity` (existing), Task 1–3 (domain now correctly returns `null` cycle end and zero schedule for Punctual groups — no code change needed in `GroupPotReader` for the `cap`/`isLate` logic, see Step 1 note).
- Produces: `GroupPotView::$periodicity: string` (new field, the raw enum value e.g. `'punctual'`).

- [ ] **Step 1: Write the failing tests**

In `tests/Application/Tontine/UseCase/GroupPotReaderTest.php`, add this assertion inside
`testBuildsPotViewWithMembersAprAndYield`, right after the existing `self::assertSame('Tontine famille', $view->name);` line (line 60):

```php
        self::assertSame('weekly', $view->periodicity);
```

Then add this new test method after `testDefaultsAprToZeroWithoutASnapshot` (after line 105):

```php
    public function testBuildsPotViewForPunctualGroupWithNoCycleEndAndNeverLate(): void
    {
        $alice = self::alice();
        $group = TontineGroup::create($alice, 'Tontine famille', self::usdc('25000000'), Periodicity::Punctual, 12, new \DateTimeImmutable(self::CREATED_AT));

        $groups = $this->createStub(TontineGroupRepositoryInterface::class);
        $groups->method('find')->willReturn($group);

        $reader = new GroupPotReader($groups, $this->snapshots(500), $this->clock());
        $view = $reader->read(1);

        self::assertNotNull($view);
        self::assertSame('punctual', $view->periodicity);
        self::assertSame(1, $view->cycleNumber);
        self::assertNull($view->cycleEndsAt);
        self::assertCount(1, $view->members);
        self::assertSame(0, $view->members[0]->expectedInstallments);
        self::assertFalse($view->members[0]->isLate);
    }
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `make test c="--filter=GroupPotReaderTest"`
Expected: FAIL — `GroupPotView` doesn't declare a `$periodicity` property yet, so
`$view->periodicity` triggers a PHP "Undefined property" warning and evaluates to `null`;
both `self::assertSame('weekly', $view->periodicity)` in
`testBuildsPotViewWithMembersAprAndYield` and the equivalent assertion in the new
`testBuildsPotViewForPunctualGroupWithNoCycleEndAndNeverLate` fail as a result.

- [ ] **Step 3: Implement — add the field to `GroupPotView`**

In `src/Application/Tontine/Dto/GroupPotView.php`, add `public string $periodicity,` as a new constructor parameter, right after `public string $name,`:

```php
<?php

declare(strict_types=1);

namespace App\Application\Tontine\Dto;

final readonly class GroupPotView
{
    /**
     * @param list<MemberPotView> $members
     */
    public function __construct(
        public int $groupId,
        public string $name,
        public string $periodicity,
        public string $potTotalDisplay,
        public string $estimatedYieldDisplay,
        public string $flowRatePerSecondDisplay,
        public int $computedAtTimestamp,
        public int $aprBasisPoints,
        public ?int $cycleNumber,
        public ?\DateTimeImmutable $cycleEndsAt,
        public array $members,
    ) {
    }
}
```

- [ ] **Step 4: Implement — pass it from `GroupPotReader`**

In `src/Application/Tontine/UseCase/GroupPotReader.php`, in the `new GroupPotView(...)` call inside `read()` (currently lines 62-73), add `periodicity: $group->periodicity->value,` right after `name: $group->name,`:

```php
        return new GroupPotView(
            groupId: $groupId,
            name: $group->name,
            periodicity: $group->periodicity->value,
            potTotalDisplay: self::display($potTotal),
            estimatedYieldDisplay: self::display($estimatedYield),
            flowRatePerSecondDisplay: self::flowRatePerSecondDisplay($potTotal, $aprBasisPoints),
            computedAtTimestamp: $now->getTimestamp(),
            aprBasisPoints: $aprBasisPoints,
            cycleNumber: $cycle?->number,
            cycleEndsAt: $cycle?->endsAt,
            members: $members,
        );
```

Note: no other change is needed in this file. The existing line
`$cap = $cycle->endsAt ?? $now;` already handles a `null` `$cycle` **and** a `null`
`$cycle->endsAt` correctly — PHP's `??` operator suppresses the "property access on null"
error for its left operand, so this line was already correct for both cases before this task.

- [ ] **Step 5: Run tests to verify they pass**

Run: `make test c="--filter=GroupPotReaderTest"`
Expected: PASS (all tests in the file)

Then check for any other consumer of `GroupPotView`'s constructor that now needs the new
argument:
Run: `make phpstan`
Expected: `[OK] No errors` — if it reports a missing-argument error anywhere else, add
`periodicity: $group->periodicity->value,` there too before proceeding.

- [ ] **Step 6: Commit**

```bash
git add src/Application/Tontine/Dto/GroupPotView.php src/Application/Tontine/UseCase/GroupPotReader.php tests/Application/Tontine/UseCase/GroupPotReaderTest.php
git commit -m "GroupPotView expose la périodicité du groupe"
```

---

### Task 5: French labels + creation form (Semestrielle, Ponctuelle)

**Files:**
- Modify: `src/Presentation/Http/Dto/TontineGroupListItem.php`
- Modify: `templates/tontine/new.html.twig`
- Create: `tests/Presentation/Http/Dto/TontineGroupListItemTest.php`

**Interfaces:**
- Consumes: `Periodicity::Semiannual`/`Periodicity::Punctual` (Task 1).
- Produces: no new interfaces — this task only adds display strings and form options.

- [ ] **Step 1: Write the failing test**

Create `tests/Presentation/Http/Dto/TontineGroupListItemTest.php`:

```php
<?php

declare(strict_types=1);

namespace App\Tests\Presentation\Http\Dto;

use App\Presentation\Http\Dto\TontineGroupListItem;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class TontineGroupListItemTest extends TestCase
{
    /**
     * @return iterable<string, array{string, string}>
     */
    public static function provideLabels(): iterable
    {
        yield 'weekly' => ['weekly', 'Hebdomadaire'];
        yield 'biweekly' => ['biweekly', 'Bimensuelle'];
        yield 'monthly' => ['monthly', 'Mensuelle'];
        yield 'semiannual' => ['semiannual', 'Semestrielle'];
        yield 'punctual' => ['punctual', 'Ponctuelle'];
    }

    #[DataProvider('provideLabels')]
    public function testFrenchLabel(string $value, string $expectedLabel): void
    {
        self::assertSame($expectedLabel, TontineGroupListItem::frenchLabel($value));
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `make test c="--filter=TontineGroupListItemTest"`
Expected: FAIL on the `semiannual`/`punctual` cases — `frenchLabel()` currently falls through
to its `default => $value` branch, returning `'semiannual'`/`'punctual'` instead of the French
labels.

- [ ] **Step 3: Implement — add the labels**

In `src/Presentation/Http/Dto/TontineGroupListItem.php`, replace `frenchLabel()` (currently lines 24-34):

```php
    public static function frenchLabel(mixed $value): string
    {
        \assert(\is_string($value));

        return match ($value) {
            'weekly' => 'Hebdomadaire',
            'biweekly' => 'Bimensuelle',
            'monthly' => 'Mensuelle',
            'semiannual' => 'Semestrielle',
            'punctual' => 'Ponctuelle',
            default => $value,
        };
    }
```

- [ ] **Step 4: Run test to verify it passes**

Run: `make test c="--filter=TontineGroupListItemTest"`
Expected: PASS

- [ ] **Step 5: Update the creation form**

Replace the periodicity `<select>` and the installments `<p>` block in
`templates/tontine/new.html.twig` (currently lines 26-38) with:

```twig
            <p>
                <label for="periodicity">Périodicité</label><br>
                <select id="periodicity" name="periodicity" required style="width: 100%; padding: .5rem;" onchange="document.getElementById('installments-field').style.display = (this.value === 'punctual') ? 'none' : 'block'">
                    <option value="weekly" {{ periodicity|default('') == 'weekly' ? 'selected' : '' }}>Hebdomadaire</option>
                    <option value="biweekly" {{ periodicity|default('') == 'biweekly' ? 'selected' : '' }}>Bimensuelle</option>
                    <option value="monthly" {{ periodicity|default('') == 'monthly' ? 'selected' : '' }}>Mensuelle</option>
                    <option value="semiannual" {{ periodicity|default('') == 'semiannual' ? 'selected' : '' }}>Semestrielle</option>
                    <option value="punctual" {{ periodicity|default('') == 'punctual' ? 'selected' : '' }}>Ponctuelle</option>
                </select>
            </p>

            <p id="installments-field" style="{{ periodicity|default('') == 'punctual' ? 'display: none;' : '' }}">
                <label for="installments">Nombre d'échéances du cycle</label><br>
                <input type="number" id="installments" name="installments" value="{{ installments|default('12') }}" min="2" max="52" style="width: 100%; padding: .5rem;">
            </p>
```

Note: the installments `<input>` loses its `required` attribute deliberately — a
`required` field hidden behind `display: none` can still block form submission in some
browsers, and it's redundant anyway since `TontineGroup::create()` (Task 2) always
overrides the value for `Punctual` server-side regardless of what's submitted.

There is no automated test for this inline `onchange` toggle (it's a pure client-side
convenience, not a correctness guarantee — the server-side normalization from Task 2 is
what's actually tested). Manual check in Step 6 below covers it.

- [ ] **Step 6: Manual check**

Run: `make up` (if not already running), then in a browser (after logging in, see
`README.md`/`CLAUDE.md` for the dev login flow) visit `/tontines/nouvelle`. Selecting
"Ponctuelle" in the périodicité select should hide the "Nombre d'échéances du cycle"
field immediately; selecting any other option should show it again.

- [ ] **Step 7: Commit**

```bash
git add src/Presentation/Http/Dto/TontineGroupListItem.php templates/tontine/new.html.twig tests/Presentation/Http/Dto/TontineGroupListItemTest.php
git commit -m "Ajoute Semestrielle et Ponctuelle au formulaire de création et aux libellés FR"
```

---

### Task 6: Dashboard hides échéances/statut and shows periodicity for Punctual groups

**Files:**
- Modify: `templates/components/TontineGroupDashboard.html.twig`
- Test: `tests/Presentation/TontineGroupDashboardTest.php`

**Interfaces:**
- Consumes: `GroupPotView::$periodicity` (Task 4).
- Produces: no new interfaces — this task only changes rendered HTML.

- [ ] **Step 1: Write the failing test**

Add this test method to `tests/Presentation/TontineGroupDashboardTest.php`, after
`testDashboardShowsPotTotalForAMember` (after line 51):

```php
    public function testDashboardHidesEcheancesAndStatutColumnsForPunctualGroup(): void
    {
        $wallet = '0x4444444444444444444444444444444444444444';
        $client = self::createClient();
        $this->login($client, 'did:privy:dashboard-punctual', $wallet);
        $creator = $this->findUser('did:privy:dashboard-punctual');
        $group = TontineGroupFactory::createOne([
            'creator' => $creator,
            'periodicity' => Periodicity::Punctual,
        ]);

        $client->request('GET', '/tontines/'.$group->id);

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', 'Ponctuelle');
        self::assertSelectorTextContains('body', 'Cotisations libres');
        self::assertSelectorTextNotContains('body', 'Échéances');
        self::assertSelectorTextNotContains('body', 'En retard');
        self::assertSelectorTextNotContains('body', 'À jour');
    }
```

Add the missing import at the top of the file, next to the other `use App\Domain\Tontine...`
imports (there currently are none in this file — add it after `use App\Domain\Identity\WalletAddress;`, before `use App\Tests\Factory\Tontine\TontineGroupFactory;`):

```php
use App\Domain\Tontine\Periodicity;
```

- [ ] **Step 2: Run test to verify it fails**

Run: `make test c="--filter=TontineGroupDashboardTest"`
Expected: FAIL — the current template always renders "Échéances"/"À jour" (or "En retard")
regardless of periodicity, and never renders "Ponctuelle" or "Cotisations libres" anywhere.

- [ ] **Step 3: Implement**

Replace the full content of `templates/components/TontineGroupDashboard.html.twig` with:

```twig
<div {{ attributes }} data-poll="delay(30000)|$render" style="border: 1px solid #ddd; border-radius: 8px; padding: 1.5rem;">
    {% set pot = this.getPot() %}
    {% if pot is null %}
        <p style="color: #b91c1c;">Accès refusé.</p>
    {% else %}
        {% set periodicityLabels = {'weekly': 'Hebdomadaire', 'biweekly': 'Bimensuelle', 'monthly': 'Mensuelle', 'semiannual': 'Semestrielle', 'punctual': 'Ponctuelle'} %}
        {% set isPunctual = pot.periodicity == 'punctual' %}

        <p style="margin: 0 0 .5rem; text-transform: uppercase; font-size: .75rem; color: #666;">Pot commun · {{ pot.name }}</p>
        <p style="margin: 0 0 .5rem; color: #666;">Périodicité : {{ periodicityLabels[pot.periodicity] ?? pot.periodicity }}</p>
        <p style="margin: 0;">Total cotisé</p>
        <p style="margin: 0 0 1rem; font-size: 1.75rem; font-weight: bold;">${{ pot.potTotalDisplay }}</p>

        <p style="margin: 0;">Yield estimé (temps réel)</p>
        <p style="margin: 0 0 .25rem; font-size: 1.5rem; font-weight: bold;">
            $<span data-controller="flowing-balance"
                data-flowing-balance-balance-value="{{ pot.estimatedYieldDisplay }}"
                data-flowing-balance-timestamp-value="{{ pot.computedAtTimestamp }}"
                data-flowing-balance-flow-rate-per-second-value="{{ pot.flowRatePerSecondDisplay }}"
                data-flowing-balance-decimals-value="6">{{ pot.estimatedYieldDisplay }}</span>
        </p>
        <p style="margin: 0 0 1rem; color: #666; font-size: .75rem;">Estimation off-chain basée sur l'APR courant ({{ (pot.aprBasisPoints / 100)|number_format(2) }} %) — le yield réel streame vers le wallet de chaque membre.</p>

        {% if pot.cycleNumber is not null %}
            <p style="margin: 0 0 1rem; color: #666;">
                Cycle n°{{ pot.cycleNumber }}
                {%- if pot.cycleEndsAt is not null %} — fin le {{ pot.cycleEndsAt|date('d/m/Y') }}{% endif -%}
            </p>
        {% endif %}

        {% if isPunctual %}
            <p style="margin: 0 0 1rem; color: #666; font-size: .875rem;">Cotisations libres — pas d'échéancier pour cette tontine.</p>
        {% endif %}

        <table style="width: 100%; border-collapse: collapse; margin-top: 1rem;">
            <thead>
                <tr style="text-align: left; border-bottom: 1px solid #ddd;">
                    <th style="padding: .5rem 0;">Membre</th>
                    <th style="padding: .5rem 0;">Cotisé</th>
                    <th style="padding: .5rem 0;">Part du yield</th>
                    {% if not isPunctual %}
                        <th style="padding: .5rem 0;">Échéances</th>
                        <th style="padding: .5rem 0;">Statut</th>
                    {% endif %}
                </tr>
            </thead>
            <tbody>
                {% for member in pot.members %}
                    <tr style="border-bottom: 1px solid #eee;">
                        <td style="padding: .5rem 0;">
                            {{ member.email ?? (member.walletAddress|slice(0, 6) ~ '…' ~ member.walletAddress|slice(-4)) }}
                            {% if member.role == 'admin' %}<span style="color: #666; font-size: .75rem;"> (admin)</span>{% endif %}
                        </td>
                        <td style="padding: .5rem 0;">${{ member.contributedDisplay }}</td>
                        <td style="padding: .5rem 0;">${{ member.yieldShareDisplay }}</td>
                        {% if not isPunctual %}
                            <td style="padding: .5rem 0;">
                                {{ member.paidInstallments }}/{{ member.expectedInstallments }}
                                {% if member.nextDueDate is not null %}
                                    <span style="color: #666; font-size: .75rem;"> · prochaine échéance le {{ member.nextDueDate|date('d/m/Y') }}</span>
                                {% endif %}
                            </td>
                            <td style="padding: .5rem 0;">
                                {% if member.isLate %}
                                    <span style="color: #b91c1c;">En retard</span>
                                {% else %}
                                    <span style="color: #15803d;">À jour</span>
                                {% endif %}
                            </td>
                        {% endif %}
                    </tr>
                {% endfor %}
            </tbody>
        </table>
    {% endif %}
</div>
```

- [ ] **Step 4: Run tests to verify they pass**

Run: `make test c="--filter=TontineGroupDashboardTest"`
Expected: PASS (both tests in the file — re-run the pre-existing
`testDashboardShowsPotTotalForAMember` too, to confirm the periodicity-label line and the
`isPunctual` branching didn't break the default weekly-group rendering).

- [ ] **Step 5: Commit**

```bash
git add templates/components/TontineGroupDashboard.html.twig tests/Presentation/TontineGroupDashboardTest.php
git commit -m "Dashboard : affiche la périodicité, masque échéances/statut pour Ponctuelle"
```

---

### Task 7: End-to-end functional coverage (creation form accepts both new periodicities)

**Files:**
- Modify: `tests/Presentation/TontineGroupControllerTest.php`

**Interfaces:**
- Consumes: everything from Tasks 1–6.
- Produces: nothing new — this task closes the loop with a functional test through the real HTTP form, matching the spec's testing section.

- [ ] **Step 1: Write the failing test**

Add this test method to `tests/Presentation/TontineGroupControllerTest.php`, after
`testCreateWithValidDataRedirectsToShowPage` (after line 58):

```php
    public function testCreateWithPunctualPeriodicityHidesInstallmentsAndSucceeds(): void
    {
        $client = self::createClient();
        $this->login($client, 'did:privy:tontine-create-punctual', '0xaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa');

        $crawler = $client->request('GET', '/tontines/nouvelle');
        $token = (string) $crawler->filter('input[name="_token"]')->attr('value');

        $client->request('POST', '/tontines', [
            '_token' => $token,
            'name' => 'Cagnotte anniversaire',
            'amount' => '25',
            'periodicity' => 'punctual',
            // No "installments" field submitted at all — the field is hidden client-side
            // for Ponctuelle, and the server must not require it.
        ]);

        self::assertResponseRedirects();
        $client->followRedirect();
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'Cagnotte anniversaire');
        self::assertSelectorTextContains('body', 'Ponctuelle');
    }

    public function testCreateWithSemiannualPeriodicitySucceeds(): void
    {
        $client = self::createClient();
        $this->login($client, 'did:privy:tontine-create-semiannual', '0xbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb');

        $crawler = $client->request('GET', '/tontines/nouvelle');
        $token = (string) $crawler->filter('input[name="_token"]')->attr('value');

        $client->request('POST', '/tontines', [
            '_token' => $token,
            'name' => 'Tontine semestrielle',
            'amount' => '100',
            'periodicity' => 'semiannual',
            'installments' => '2',
        ]);

        self::assertResponseRedirects();
        $client->followRedirect();
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'Tontine semestrielle');
    }
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `make test c="--filter=TontineGroupControllerTest"`
Expected: FAIL for `testCreateWithPunctualPeriodicityHidesInstallmentsAndSucceeds` and
`testCreateWithSemiannualPeriodicitySucceeds` if any prior task is incomplete (e.g. if Task 5's
form options aren't present, `periodicity=punctual`/`periodicity=semiannual` would still be
accepted by the controller — since browsers/HTTP clients don't validate `<option>` presence,
these tests actually exercise the **backend** path end-to-end regardless of Task 5's HTML, so
they should already PASS if Tasks 1–4 are done correctly). Run this before assuming failure —
if both already pass, that confirms Tasks 1–4 are solid; keep the tests as regression coverage
and proceed directly to Step 4 (skip Step 3, there's nothing to implement).

If either fails, it means a prior task has a bug — stop and fix that task, don't patch around
it here.

- [ ] **Step 3: (Only if Step 2 failed) Fix the root cause in the relevant earlier task**

Re-read the failure message, identify which task (1-6) owns the broken behavior, and fix it
there — do not add special-casing in the controller.

- [ ] **Step 4: Run the full test suite**

Run: `make test`
Expected: PASS — all tests in the project, not just Tontine-related ones (confirms no
regression elsewhere from the `SavingsCycle.endsAt` nullability change in Task 2, e.g. in
`tests/Presentation/TontineJourneyTest.php` or `tests/Presentation/TontineInvitationControllerTest.php`).

- [ ] **Step 5: Run full QA**

Run: `make qa`
Expected: PASS — PHPUnit + PHPStan (0 errors) + Deptrac (0 violations) + php-cs-fixer (0 files
to fix). If `make cs` reports fixable files, run `make cs-fix` and re-run `make qa`.

- [ ] **Step 6: Commit**

```bash
git add tests/Presentation/TontineGroupControllerTest.php
git commit -m "Teste la création de tontines Ponctuelle et Semestrielle de bout en bout"
```

---

## Final verification checklist

- [ ] `make qa` green (test + phpstan + deptrac + cs)
- [ ] `make sf c="doctrine:schema:validate"` reports the DB in sync with mappings
- [ ] Manual check in browser: `/tontines/nouvelle` shows both new options, hides installments
  for Ponctuelle; a Ponctuelle group's page hides Échéances/Statut columns and shows
  "Cotisations libres"; a Semestrielle group behaves exactly like Mensuelle otherwise
