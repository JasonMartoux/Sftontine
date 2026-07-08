import React from 'react';
import { PrivyProvider, usePrivy, useWallets } from '@privy-io/react-auth';
import { createWalletClient, custom, publicActions, parseAbi, parseUnits, formatUnits } from 'viem';

// cf. config/abi/README.md — le guide d'intégration SuperVault recommande un
// buffer de gas ~2x l'estimation pour deposit/redeem/connectPool.
const TX_GAS_BUFFER = 2n;
const USDC_DECIMALS = 6;

// Défini à la main (plutôt qu'importé de 'viem/chains') pour éviter de tirer une trentaine
// de sous-modules transitifs (ox/P256, WebAuthn, Secp256k1...) inutiles ici — seuls id/
// nativeCurrency/rpcUrls sont nécessaires à Privy (config PrivyProvider) et viem (createWalletClient).
const base = {
    id: 8453,
    name: 'Base',
    nativeCurrency: { name: 'Ether', symbol: 'ETH', decimals: 18 },
    rpcUrls: { default: { http: ['https://mainnet.base.org'] } },
};

const usdcAbi = parseAbi([
    'function balanceOf(address account) view returns (uint256)',
    'function approve(address spender, uint256 amount) returns (bool)',
]);

const vaultAbi = parseAbi([
    'function maxDeposit(address receiver) view returns (uint256)',
    'function deposit(uint256 assets, address receiver) returns (uint256)',
    'function FUND_MANAGER() view returns (address)',
]);

const fundManagerAbi = parseAbi(['function YIELD_POOL() view returns (address)']);

const gdaForwarderAbi = parseAbi([
    'function isMemberConnected(address pool, address member) view returns (bool)',
    'function connectPool(address pool, bytes userData) returns (bool)',
]);

function DepositForm(props) {
    const { ready, authenticated } = usePrivy();
    const { wallets } = useWallets();
    const [amount, setAmount] = React.useState('');
    const [balance, setBalance] = React.useState(null);
    const [maxDeposit, setMaxDeposit] = React.useState(null);
    const [yieldPoolAddress, setYieldPoolAddress] = React.useState(null);
    const [status, setStatus] = React.useState('idle');
    const [error, setError] = React.useState(null);

    const wallet = wallets.find((w) => w.walletClientType === 'privy');
    const receiver = props.receiverAddress ?? wallet?.address;
    const busy = status === 'approving' || status === 'depositing' || status === 'connecting';

    const getClient = React.useCallback(async () => {
        // Le wallet embedded Privy ne bascule pas automatiquement sur Base : sans ce switch,
        // ses appels ciblent le réseau par défaut et renvoient "0x" (aucun contrat à cette
        // adresse sur cette chaîne), comme si le contrat n'existait pas.
        await wallet.switchChain(base.id);
        const provider = await wallet.getEthereumProvider();

        return createWalletClient({
            chain: base,
            transport: custom(provider),
            account: wallet.address,
        }).extend(publicActions);
    }, [wallet]);

    React.useEffect(() => {
        if (!ready || !authenticated || !wallet) {
            return;
        }

        let cancelled = false;

        (async () => {
            const client = await getClient();
            const [bal, maxDep, fundManagerAddress] = await Promise.all([
                client.readContract({
                    address: props.usdcAddress,
                    abi: usdcAbi,
                    functionName: 'balanceOf',
                    args: [wallet.address],
                }),
                client.readContract({
                    address: props.vaultAddress,
                    abi: vaultAbi,
                    functionName: 'maxDeposit',
                    args: [receiver],
                }),
                client.readContract({
                    address: props.vaultAddress,
                    abi: vaultAbi,
                    functionName: 'FUND_MANAGER',
                }),
            ]);
            // Le yield pool n'est jamais hardcodé (cf. config/abi/README.md) : il est
            // résolu dynamiquement vault.FUND_MANAGER() -> fundManager.YIELD_POOL().
            const poolAddress = await client.readContract({
                address: fundManagerAddress,
                abi: fundManagerAbi,
                functionName: 'YIELD_POOL',
            });

            if (!cancelled) {
                setBalance(bal);
                setMaxDeposit(maxDep);
                setYieldPoolAddress(poolAddress);
            }
        })();

        return () => {
            cancelled = true;
        };
    }, [ready, authenticated, wallet, getClient, props.usdcAddress, props.vaultAddress]);

    async function handleDeposit(event) {
        event.preventDefault();
        setError(null);

        if (!wallet) {
            return;
        }

        let assets;
        try {
            assets = parseUnits(amount, USDC_DECIMALS);
        } catch {
            setError('Montant invalide.');
            return;
        }

        if (assets <= 0n) {
            setError('Le montant doit être supérieur à zéro.');
            return;
        }

        if (maxDeposit !== null && assets > maxDeposit) {
            setError(
                maxDeposit === 0n
                    ? 'Les dépôts sont actuellement suspendus (vault en pause).'
                    : 'Montant supérieur au maximum autorisé.',
            );
            return;
        }

        if (balance !== null && assets > balance) {
            setError('Solde USDC insuffisant.');
            return;
        }

        try {
            const client = await getClient();

            setStatus('approving');
            const approveHash = await client.writeContract({
                address: props.usdcAddress,
                abi: usdcAbi,
                functionName: 'approve',
                args: [props.vaultAddress, assets],
            });
            await client.waitForTransactionReceipt({ hash: approveHash });

            setStatus('depositing');
            const depositGas = await client.estimateContractGas({
                address: props.vaultAddress,
                abi: vaultAbi,
                functionName: 'deposit',
                args: [assets, receiver],
                account: wallet.address,
            });
            const depositHash = await client.writeContract({
                address: props.vaultAddress,
                abi: vaultAbi,
                functionName: 'deposit',
                args: [assets, receiver],
                gas: depositGas * TX_GAS_BUFFER,
            });
            await client.waitForTransactionReceipt({ hash: depositHash });

            const alreadyConnected = await client.readContract({
                address: props.gdaForwarderAddress,
                abi: gdaForwarderAbi,
                functionName: 'isMemberConnected',
                args: [yieldPoolAddress, wallet.address],
            });

            if (!alreadyConnected && !props.receiverAddress) {
                setStatus('connecting');
                const connectGas = await client.estimateContractGas({
                    address: props.gdaForwarderAddress,
                    abi: gdaForwarderAbi,
                    functionName: 'connectPool',
                    args: [yieldPoolAddress, '0x'],
                    account: wallet.address,
                });
                const connectHash = await client.writeContract({
                    address: props.gdaForwarderAddress,
                    abi: gdaForwarderAbi,
                    functionName: 'connectPool',
                    args: [yieldPoolAddress, '0x'],
                    gas: connectGas * TX_GAS_BUFFER,
                });
                await client.waitForTransactionReceipt({ hash: connectHash });
            }

            await fetch('/vault/deposit/confirm', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ txHash: depositHash }),
            });

            setStatus('done');
            setAmount('');
            setBalance(balance - assets);
        } catch (err) {
            setStatus('error');
            setError(err?.shortMessage ?? err?.message ?? 'La transaction a échoué.');
        }
    }

    if (!ready) {
        return <p>Chargement…</p>;
    }

    if (!authenticated || !wallet) {
        return null;
    }

    return (
        <form onSubmit={handleDeposit}>
            <h2>Déposer des USDC</h2>

            {balance !== null && <p>Solde disponible : {formatUnits(balance, USDC_DECIMALS)} USDC</p>}

            <label htmlFor="deposit-amount">Montant à déposer (USDC)</label>
            <input
                id="deposit-amount"
                type="number"
                min="0"
                step="0.000001"
                value={amount}
                onChange={(event) => setAmount(event.target.value)}
                disabled={busy}
                required
            />

            <button type="submit" disabled={!amount || busy}>
                Déposer
            </button>

            {status !== 'idle' && status !== 'error' && <p>Statut : {status}</p>}
            {error && <p role="alert">{error}</p>}
        </form>
    );
}

export default function VaultDeposit(props) {
    return (
        <PrivyProvider
            appId={props.appId}
            clientId={props.clientId}
            config={{
                loginMethods: ['email'],
                embeddedWallets: { ethereum: { createOnLogin: 'users-without-wallets' } },
                // Sans ça, l'embedded wallet démarre sur le réseau par défaut de Privy
                // (pas Base) et switchChain(base.id) échoue car Base n'est pas "supported".
                defaultChain: base,
                supportedChains: [base],
            }}
        >
            <DepositForm {...props} />
        </PrivyProvider>
    );
}
