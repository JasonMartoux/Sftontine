# Symfony Docker

A [Docker](https://www.docker.com/)-based installer and runtime for the [Symfony](https://symfony.com) web framework,
with [FrankenPHP](https://frankenphp.dev) and [Caddy](https://caddyserver.com/) inside!

Coding-agents ready: ships with a [Dev Container](https://containers.dev/) and a [one-page guide](docs/agents.md)
to run [OpenCode](https://opencode.ai), [Claude Code](https://claude.ai/claude-code), or any AI coding assistant,
against a local or a remote model, with an optional network sandbox.

![CI](https://github.com/dunglas/symfony-docker/workflows/CI/badge.svg)

## Getting Started

1. If not already done, [install Docker Compose](https://docs.docker.com/compose/install/) (v2.10+)
2. Run `docker compose build --pull --no-cache` to build fresh images
3. Run `docker compose up --wait` to set up and start a fresh Symfony project
4. Open `https://localhost` in your favorite web browser and [accept the auto-generated TLS certificate](https://stackoverflow.com/a/15076602/1352334)
5. Run `docker compose down --remove-orphans` to stop the Docker containers.

## Features

- Production, development and CI ready
- Just 1 service by default
- Super-readable configuration
- Blazing-fast performance thanks to [the worker mode of FrankenPHP](https://frankenphp.dev/docs/worker/)
- [Installation of extra Docker Compose services](docs/extra-services.md) with Symfony Flex
- Automatic HTTPS (in dev and prod)
- HTTP/3 and [Early Hints](https://symfony.com/blog/new-in-symfony-6-3-early-hints) support
- Real-time messaging thanks to a built-in [Mercure hub](https://symfony.com/doc/current/mercure.html)
- [Vulcain](https://vulcain.rocks) support
- Native [XDebug](docs/xdebug.md) integration
- [Hot Reloading](https://frankenphp.dev/docs/hot-reload/)
- [Dev Container](https://containers.dev/) support
- [AI coding agents](docs/agents.md) with an optional network sandbox
- Rootless, slim production image

**Enjoy!**

## Docs

1. [Options available](docs/options.md)
2. [Using Symfony Docker with an existing project](docs/existing-project.md)
3. [Support for extra services](docs/extra-services.md)
4. [Deploying in production](docs/production.md)
5. [Debugging with Xdebug](docs/xdebug.md)
6. [TLS Certificates](docs/tls.md)
7. [Using MySQL instead of PostgreSQL](docs/mysql.md)
8. [Using Alpine Linux instead of Debian](docs/alpine.md)
9. [Using a Makefile](docs/makefile.md)
10. [Updating the template](docs/updating.md)
11. [Troubleshooting](docs/troubleshooting.md)
12. [Using AI coding agents](docs/agents.md)

## Sftontine — Identité & Privy (M1)

L'authentification repose sur [Privy](https://docs.privy.io) (wallet embarqué EOA, login email/social). Étapes de configuration :

1. Créer une app sur [dashboard.privy.io](https://dashboard.privy.io), activer les embedded wallets (Ethereum) et le login email/social.
2. Ajouter `https://localhost` (et le domaine de prod le cas échéant) aux origins autorisées de l'app.
3. Récupérer `PRIVY_APP_ID`, `PRIVY_APP_CLIENT_ID` et la clé publique de vérification ES256 (JWKS) depuis les paramètres de l'app.
4. Renseigner ces valeurs dans `.env.local` (jamais commit) :
   ```
   PRIVY_APP_ID=...
   PRIVY_APP_CLIENT_ID=...
   PRIVY_VERIFICATION_KEY="-----BEGIN PUBLIC KEY-----
   ...
   -----END PUBLIC KEY-----"
   ```

`PRIVY_APP_ID`/`PRIVY_APP_CLIENT_ID` sont des identifiants publics (comme un `client_id` OAuth) : ils sont exposés côté front sans risque. `PRIVY_VERIFICATION_KEY` est aussi une clé **publique** (ES256), utilisée côté serveur pour vérifier la signature des JWT Privy — ce n'est pas un secret, mais elle n'a pas besoin d'être exposée au front.

### Le SDK `@privy-io/react-auth` est vendorisé, pas résolu via AssetMapper

`@privy-io/react-auth` embarque de nombreux connecteurs (wagmi/viem, WalletConnect, MetaMask SDK…) qui requièrent des versions **incompatibles entre elles** de `@noble/curves`/`@noble/hashes` selon le connecteur. Le modèle d'AssetMapper (une seule version par paquet dans tout l'import map) ne peut pas résoudre cet arbre de dépendances via `importmap:require`.

Le SDK est donc pré-bundlé en un seul fichier ESM autonome (`react`/`react-dom` exclus du bundle pour réutiliser l'instance déjà fournie par l'app) et vendorisé dans `assets/vendor/privy/privy-react-auth.esm.js`, référencé directement par chemin dans `importmap.php`.

Pour régénérer ce bundle (montée de version du SDK, ajout d'un export type `useIdentityToken`…) :

```bash
mkdir /tmp/privy-bundle && cd /tmp/privy-bundle
npm init -y
npm install @privy-io/react-auth@<version> esbuild
echo "export { PrivyProvider, usePrivy, useIdentityToken } from '@privy-io/react-auth';" > entry.js
cat > shim-banner.js << 'EOF'
import * as __ReactNS from "react";
if (typeof globalThis.require === "undefined") {
  globalThis.require = function (name) {
    if (name === "react") return __ReactNS.default ?? __ReactNS;
    throw new Error('No browser shim for require("' + name + '")');
  };
}
EOF
npx esbuild entry.js --bundle --format=esm --platform=browser --target=es2022 \
  --external:react --external:react-dom --external:react/jsx-runtime \
  --banner:js="$(cat shim-banner.js)" \
  --define:process.env.NODE_ENV='"production"' --minify \
  --outfile=privy-react-auth.esm.js
cp privy-react-auth.esm.js <repo>/assets/vendor/privy/privy-react-auth.esm.js
```

Deux pièges non évidents, découverts en testant le login dans un vrai navigateur :

- **`react/jsx-runtime` manquant de l'import map** : certaines sous-dépendances du SDK (icônes, UI des connecteurs wallet) utilisent le JSX runtime automatique. Si le navigateur remonte `Failed to resolve module specifier "react/jsx-runtime"`, lancer `bin/console importmap:require react/jsx-runtime` (même version que `react`).
- **`Dynamic require of "react" is not supported`** : certaines sous-dépendances CJS de la SDK font `require("react")` à l'intérieur d'un wrapper esbuild (`__commonJS`), qu'esbuild ne peut pas convertir statiquement en import ES quand `react` est externalisé. Le banner `shim-banner.js` ci-dessus fournit un polyfill `require()` minimal pour ce seul cas — sans lui, le SDK échoue silencieusement au chargement et rien ne s'affiche.

## Qualité & CI

- `make qa` : lance en local ce que le job **Tests** de la CI vérifie (PHPUnit, PHPStan, Deptrac, php-cs-fixer en dry-run), dans le conteneur `php`.
- `make lint` : reproduit le job **Lint** de la CI (super-linter — editorconfig, dotenv-linter, gitleaks, shfmt, YAML, actionlint…) en local via Docker, avec exactement les mêmes variables d'environnement que `.github/workflows/ci.yaml`.
- `make ci-local` : les deux à la suite — ce que la CI GitHub Actions exécute, avant de pousser.

### Hook `pre-push`

Un hook `pre-push` versionné (`.githooks/pre-push`) lance `make ci-local` avant chaque `git push` et bloque le push si ça échoue. `.git/hooks/` n'étant pas versionné par Git, l'activer une fois par clone :

```bash
git config core.hooksPath .githooks
```

En cas d'urgence, le contourner avec `git push --no-verify`.

## License

Symfony Docker is available under the MIT License.

## Credits

Created by [Kévin Dunglas](https://dunglas.dev), co-maintained by [Maxime Helias](https://twitter.com/maxhelias) and sponsored by [Les-Tilleuls.coop](https://les-tilleuls.coop).
