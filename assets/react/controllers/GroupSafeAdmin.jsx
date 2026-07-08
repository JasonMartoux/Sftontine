import React from 'react';
import { PrivyProvider, usePrivy, useWallets } from '@privy-io/react-auth';
import { createWalletClient, custom, publicActions, parseAbi, encodeFunctionData } from 'viem';
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

            const data = encodeFunctionData({ abi: gdaForwarderAbi, functionName: 'connectPool', args: [yieldPoolAddress, '0x'] });

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
