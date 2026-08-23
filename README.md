<div align="center">
    <img align="center" src="https://github.com/timgws/laravel-expo-updates/raw/development/assets/expo_updates_icon.png" />
    <h3 align="center">Laravel Expo Updates</h3>
    <p align="center">
        ⚓ Ship like you mean it 💥
    </p>
</div>
<pre style="margin: 0 auto; width: 50%;">
# composer require timgws/laravel-expo-updates
</pre>

### Did you use an older version of this repository?

See the [Upgrading Guide](docs/UPGRADING.md) for detailed migration instructions. The package includes an automatic migration that converts existing assets to the new isolated structure.

## What is Laravel Expo Updates?

A lightweight Laravel package that lets you **self-host over-the-air (OTA) updates** for React Native apps using the
**Expo Updates protocol**.

It takes care of the boring parts (manifest generation, asset serving, protocol compliance) so your existing Laravel
backend becomes the control plane for fast, reliable updates.

`expo-updates` is a React Native library that enables your app to manage remote updates to your application code.
It communicates with the configured remote update service to get information about available updates.

`Laravel Expo Updates` is designed to be a PHP implementation of the [Expo Updates protocol](https://docs.expo.dev/technical-specs/expo-updates-1/),
making it compatible with any React Native app using the `expo-updates` library. If you write your app's backend in PHP,
this package lets you keep everything in one stack.

### Why use it?

* **One stack**: Keep everything server-side in PHP/Laravel. Simpler infrastructure, fewer moving parts.
* **Self-hosted**: Own your release cadence, storage, and logs.
* **Full control**: Gate who gets which updates and when.

## Documentation

- **[Upgrading Guide](docs/UPGRADING.md)** - Migration steps for upgrading to immutable manifest architecture
- **[Manifest Management](docs/MANIFEST_MANAGEMENT.md)** - How to query, display, and manage manifests in your Laravel app

## Reporting Bugs

Spotted a bug? Thanks for helping improve Laravel Expo Updates!
[Please open a GitHub issue](../../issues/new?labels=bug).

## Installation (Server Side)

```bash
composer require timgws/laravel-expo-updates
```

After installing the package, publish the configuration file:

```bash
php artisan vendor:publish --provider="LaravelExpoUpdates\ExpoUpdatesServiceProvider" --tag="config"
```

Run the migrations:

```bash
php artisan migrate
```

## Server side configuration

Sane defaults are provided, but you should review and adjust the configuration file that is published to your config
folder.

The configuration file is located at `config/expo-updates.php`. Here you can configure:

- Route prefix for the update endpoints
- Code signing settings
- Asset storage settings
- Cache settings

Make sure to set the appropriate environment variables in your `.env` file. Here are the relevant variables:

```shell
# cat .env | grep ^EXPO_
EXPO_UPDATES_ROUTE_PREFIX=updates
EXPO_UPDATES_DEFAULT_PROJECT=default
EXPO_UPDATES_CODE_SIGNING_ENABLED=true
EXPO_UPDATES_CERTIFICATE_PATH=app/expo-updates/public.key
EXPO_UPDATES_PRIVATE_KEY_PATH=app/expo-updates/private.key
EXPO_UPDATES_SIGNATURE_CACHE_ENABLED=true
EXPO_UPDATES_SIGNATURE_CACHE_TTL=600
EXPO_UPDATES_ASSETS_DISK=local
EXPO_UPDATES_ASSETS_PATH=expo-updates/assets
EXPO_UPDATES_ASSETS_URL=https://www.myapplication.com/assets/
EXPO_UPDATES_CACHE_ENABLED=true
EXPO_UPDATES_CACHE_TTL=60
```

## Installation (Client Side)
Check out the [expo-updates configuration guide](https://docs.expo.dev/versions/latest/sdk/updates/#usage).

> [!WARNING]  
> The `updates.url` must point to `https://YOUR-DOMAIN/updates/{projectSlug}/manifest` where `{projectSlug}` matches the project slug in your Laravel database. Pointing to the wrong path or using the wrong slug will cause OTA updates to fail.

### Mobile App Configuration

Configure your React Native/Expo app's `app.config.js` or `app.json` with the correct update URL:

**Find your project slug:**
```bash
php artisan tinker
>>> $project = \LaravelExpoUpdates\Models\Project::first();
>>> echo $project->slug;
```

**Configure app.config.js:**
```javascript
export default {
  expo: {
    // ... other config
    updates: {
      url: "https://YOUR-DOMAIN/updates/YOUR-PROJECT-SLUG/manifest",
      enabled: true,
      checkOnLaunch: "ALWAYS",  // or "WIFI_ONLY" or "NEVER"
      fallbackToCacheTimeout: 15000,
      codeSigningCertificate: "./ota-certificate.pem",  // Path to your public certificate
      codeSigningMetadata: {
        keyid: "main",
        alg: "rsa-v1_5-sha256"
      }
    },
    runtimeVersion: {
      policy: "sdkVersion"  // or "appVersion" or "nativeVersion"
    }
  }
}
```

**Complete example (app.json):**
```json
{
  "expo": {
    "updates": {
      "url": "https://app.meepha.com/updates/my-app/manifest",
      "enabled": true,
      "checkOnLaunch": "ALWAYS",
      "fallbackToCacheTimeout": 15000,
      "codeSigningCertificate": "./ota-certificate.pem",
      "codeSigningMetadata": {
        "keyid": "main",
        "alg": "rsa-v1_5-sha256"
      }
    }
  }
}
```

**Important:** The `projectSlug` in the URL must **exactly match** the `slug` field in your `expo_projects` database table. Mismatches will cause 404 errors and prevent OTA updates from working.

**Verify your configuration:**
```bash
# Replace with your actual domain and project slug
curl -H "expo-platform: ios" \
     -H "expo-runtime-version: 1.0.0" \
     -H "expo-protocol-version: 1" \
     -H "accept: application/json" \
     "https://YOUR-DOMAIN/updates/YOUR-PROJECT-SLUG/manifest"
```

If you receive a valid JSON manifest response, your configuration is correct. If you get HTML or errors, check:
- The project slug matches your database
- The route prefix matches your `EXPO_UPDATES_ROUTE_PREFIX` config
- Your Laravel routes are properly registered (`php artisan route:list | grep updates`)

## Replacing the default models

The package comes with default Eloquent models for `Update`, `Asset`, and `Manifest`.

You can replace the default models by creating your own models that extend the package's models and updating the
configuration file to use them.

Alternatively, you can create new implementations, as long as they fulfil the Interfaces provided.

## Usage

### Routes

The package automatically registers the following routes:

- `GET /expo-updates/manifest` - Returns the latest manifest for the specified platform and runtime version
- `GET /expo-updates/asset/{key}` - Serves an asset file

### Headers

The manifest endpoint requires the following headers:

- `expo-protocol-version: 1`
- `expo-platform: ios|android`
- `expo-runtime-version: *`
- `accept: application/expo+json, application/json, multipart/mixed`

Optional headers:

- `expo-manifest-filters: *` - Filter manifests by metadata
- `expo-expect-signature: *` - Request code signing

### Code Signing

To enable code signing:

1. Set `EXPO_UPDATES_CODE_SIGNING_ENABLED=true` in your `.env` file
2. Configure the paths to your certificate and private key:
   ```
   EXPO_UPDATES_CERTIFICATE_PATH=/path/to/certificate.pem
   EXPO_UPDATES_PRIVATE_KEY_PATH=/path/to/private.key
   ```

#### Generating Code Signing Keys

You can generate your code signing keys using the official Expo package:

```bash
npm install @expo/code-signing-certificates
```

Create a script `generate-keys.js`:

```javascript
const {
  generateKeyPair,
  convertKeyPairToPEM,
  generateSelfSignedCodeSigningCertificate,
  convertCertificateToCertificatePEM,
} = require('@expo/code-signing-certificates');
const fs = require('fs');

// 1. Generate key pair
const keyPair = generateKeyPair();

// 2. Set validity period (10 years)
const validityNotBefore = new Date();
const validityNotAfter = new Date();
validityNotAfter.setFullYear(validityNotAfter.getFullYear() + 10);

// 3. Create self-signed certificate
const certificate = generateSelfSignedCodeSigningCertificate({
  keyPair,
  validityNotBefore,
  validityNotAfter,
  commonName: 'Your App Name',
});

// 4. Convert to PEM format
const keyPairPEM = convertKeyPairToPEM(keyPair);
const certificatePEM = convertCertificateToCertificatePEM(certificate);

// 5. Save files
fs.writeFileSync('./ota-private.pem', keyPairPEM.privateKeyPEM);
fs.writeFileSync('./ota-public.pem', keyPairPEM.publicKeyPEM);
fs.writeFileSync('./ota-certificate.pem', certificatePEM);

console.log('✅ Keys generated successfully!');
console.log('- Private key: ./ota-private.pem (keep this SECRET on your server)');
console.log('- Public key: ./ota-public.pem');
console.log('- Certificate: ./ota-certificate.pem (add this to your mobile app)');
```

Run the script:

```bash
node generate-keys.js
```

**Important:**
- Keep `ota-private.pem` **secret** and secure on your Laravel server
- Add `ota-certificate.pem` to your React Native/Expo app (configure in `app.json`)
- Never commit private keys to version control (add to `.gitignore`)

### Asset Storage

Assets are stored using Laravel's storage system. By default, they are stored in the `public` disk under the
`expo-updates` directory. You can configure this in the config file.

### Quick checks & commands

Confirm the package routes are registered:

```bash
php artisan route:list | grep updates
```

You should see routes like:
- `GET|HEAD  updates/manifest`
- `GET|HEAD  updates/{projectSlug}/manifest`
- `GET|HEAD  updates/{projectSlug}/asset/{key}`
- `POST      updates/{projectSlug}/upload`

Fetch a manifest like the client would (replace values with your actual configuration):

```bash
curl -i \
-H "Accept: multipart/mixed" \
-H "Expo-Platform: ios" \
-H "Expo-Runtime-Version: 1.0.0" \
-H "Expo-Protocol-Version: 1" \
https://your-domain.example/updates/your-project-slug/manifest
```

> [!NOTE]  
> If you receive HTML instead of JSON/multipart, check:
> - The URL path includes the correct `{projectSlug}`
> - The project exists in your database with matching slug
> - Laravel routes are loaded (`php artisan optimize:clear` if needed)
> - You're using the correct route prefix from your config


## License

This project is licensed under the terms of the MIT license (MIT). Please see [License File](LICENSE.md) for more information. 
