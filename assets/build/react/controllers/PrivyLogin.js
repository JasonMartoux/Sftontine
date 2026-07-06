import React from 'react';
import { PrivyProvider, usePrivy, useIdentityToken } from '@privy-io/react-auth';
function LoginButton() {
  const {
    ready,
    authenticated,
    login,
    getAccessToken
  } = usePrivy();
  const {
    identityToken
  } = useIdentityToken();
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
          'privy-id-token': identityToken
        }
      });
      if (response.ok) {
        const {
          redirectUrl
        } = await response.json();
        window.location.href = redirectUrl ?? '/profile';
      }
    })();
  }, [ready, authenticated, getAccessToken, identityToken]);
  if (!ready) {
    return /*#__PURE__*/React.createElement("p", null, "Chargement\u2026");
  }
  if (authenticated) {
    return /*#__PURE__*/React.createElement("p", null, "Connexion en cours\u2026");
  }
  return /*#__PURE__*/React.createElement("button", {
    onClick: handleLogin
  }, "Se connecter avec Privy");
}
export default function PrivyLogin(props) {
  return /*#__PURE__*/React.createElement(PrivyProvider, {
    appId: props.appId,
    clientId: props.clientId,
    config: {
      loginMethods: ['email'],
      embeddedWallets: {
        ethereum: {
          createOnLogin: 'users-without-wallets'
        }
      }
    }
  }, /*#__PURE__*/React.createElement(LoginButton, null));
}