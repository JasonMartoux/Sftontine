<?php

declare(strict_types=1);

/**
 * Returns the importmap for this application.
 *
 * - "path" is a path inside the asset mapper system. Use the
 *     "debug:asset-map" command to see the full list of paths.
 *
 * - "entrypoint" (JavaScript only) set to true for any module that will
 *     be used as an "entrypoint" (and passed to the importmap() Twig function).
 *
 * The "importmap:require" command can be used to add new entries to this file.
 *
 * @return array<string, array{    // Import name as key, description of the imported file as value
 *     path: string,               // Logical, relative or absolute path to the file
 *     type?: 'js'|'css'|'json',   // Type of the file, defaults to 'js'
 *     entrypoint?: bool,          // Whether the file is an entrypoint, for 'js' only
 * }|array{
 *     version: string,            // Version of the remote package
 *     package_specifier?: string, // Remote "package-name/path" specifier, defaults to the import name
 *     type?: 'js'|'css'|'json',
 *     entrypoint?: bool,
 * }>
 */
return [
    'app' => ['path' => './assets/app.js', 'entrypoint' => true],
    '@hotwired/stimulus' => ['version' => '3.2.2'],
    '@symfony/stimulus-bundle' => ['path' => './vendor/symfony/stimulus-bundle/assets/dist/loader.js'],
    '@symfony/ux-live-component' => ['path' => './vendor/symfony/ux-live-component/assets/dist/live_controller.js'],
    'react' => ['version' => '19.2.7'],
    'react-dom/client' => ['version' => '19.2.7'],
    'scheduler' => ['version' => '0.27.0'],
    'react-dom' => ['version' => '19.2.7'],
    '@symfony/ux-react' => ['path' => './vendor/symfony/ux-react/assets/dist/loader.js'],
    '@hotwired/turbo' => ['version' => '8.0.23'],
    '@privy-io/react-auth' => ['path' => './assets/vendor/privy/privy-react-auth.esm.js'],
    'react/jsx-runtime' => ['version' => '19.2.7'],
    'viem' => ['version' => '2.54.6'],
    'abitype' => ['version' => '1.2.3'],
    '@noble/hashes/sha3' => ['version' => '1.8.0'],
    '@noble/hashes/sha256' => ['version' => '1.8.0'],
    'ox/BlockOverrides' => ['version' => '0.14.30'],
    '@noble/hashes/ripemd160' => ['version' => '1.8.0'],
    'ox/erc8010' => ['version' => '0.14.30'],
    'ox/AbiConstructor' => ['version' => '0.14.30'],
    'ox/AbiFunction' => ['version' => '0.14.30'],
    'ox/erc6492' => ['version' => '0.14.30'],
    '@noble/curves/secp256k1' => ['version' => '1.9.1'],
    'viem/_esm/utils/ccip.js' => ['version' => '2.54.6'],
    'isows' => ['version' => '1.0.7'],
    '@noble/hashes/crypto' => ['version' => '1.8.0'],
    '@noble/curves/abstract/utils' => ['version' => '1.9.1'],
    '@noble/hashes/hmac' => ['version' => '1.8.0'],
    '@noble/hashes/sha2' => ['version' => '1.8.0'],
    '@noble/hashes/utils' => ['version' => '1.8.0'],
];
