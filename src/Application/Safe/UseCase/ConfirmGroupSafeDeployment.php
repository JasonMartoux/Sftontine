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
