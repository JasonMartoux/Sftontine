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
