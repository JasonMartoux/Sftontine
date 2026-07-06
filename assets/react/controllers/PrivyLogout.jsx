import React from 'react';
import { usePrivy } from '@privy-io/react-auth';

export default function PrivyLogout() {
    const { logout } = usePrivy();

    async function handleLogout() {
        await logout();
        await fetch('/logout', { method: 'POST' });
        window.location.href = '/';
    }

    return <button onClick={handleLogout}>Se déconnecter</button>;
}
