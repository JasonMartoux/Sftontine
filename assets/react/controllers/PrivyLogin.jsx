import React from 'react';
import { PrivyProvider, usePrivy, useIdentityToken } from '@privy-io/react-auth';

function LoginButton() {
    const { ready, authenticated, login, getAccessToken } = usePrivy();
    const { identityToken } = useIdentityToken();

    async function handleLogin() {
        await login();
    }

    React.useEffect(() => {
        if (!ready || !authenticated) {
            return;
        }

        (async () => {
            const token = await getAccessToken();

            if (!token || !identityToken) {
                return;
            }

            const response = await fetch('/auth/privy', {
                method: 'POST',
                headers: {
                    Authorization: `Bearer ${token}`,
                    'privy-id-token': identityToken,
                },
            });

            if (response.ok) {
                const { redirectUrl } = await response.json();
                window.location.href = redirectUrl ?? '/profile';
            }
        })();
    }, [ready, authenticated, getAccessToken, identityToken]);

    if (!ready) {
        return <p>Chargement…</p>;
    }

    if (authenticated) {
        return <p>Connexion en cours…</p>;
    }

    return <button onClick={handleLogin}>Se connecter avec Privy</button>;
}

export default function PrivyLogin(props) {
    return (
        <PrivyProvider
            appId={props.appId}
            clientId={props.clientId}
            config={{
                loginMethods: ['email'],
                embeddedWallets: {
                    ethereum: { createOnLogin: 'users-without-wallets' },
                },
            }}
        >
            <LoginButton />
        </PrivyProvider>
    );
}
