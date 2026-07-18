<?php

namespace App\Console\Commands;

use App\Models\Setting;
use Illuminate\Console\Command;

/**
 * Sign a SyncMGR agent release so the fleet's update path will trust it.
 *
 * The agent (and the master's heartbeat updateOffer) verifies a vendor
 * RSA-SHA256 signature over the canonical string "version|sha256" against the
 * SAME ScriptGain public key embedded in OfflineLicenseVerifier / LicenseGuard.
 * This command produces that signature and the four General-Settings values the
 * heartbeat relays: agent_latest_version, agent_download_url,
 * agent_download_sha256, agent_download_signature.
 *
 * The ScriptGain PRIVATE key is NOT deployed to product hosts (only the public
 * key is embedded). Run this where the vendor key lives (cp1) and pass its path
 * with --key, or copy the values it prints into Settings → General on the
 * target instance. Without a reachable private key the command refuses to sign.
 */
class AgentSign extends Command
{
    protected $signature = 'agent:sign
        {version : Release version, e.g. 1.0.0}
        {--file= : Path to the release archive to hash (sha256 computed from it)}
        {--sha256= : Precomputed sha256 (use instead of --file)}
        {--url= : HTTPS download URL for the release}
        {--key= : Path to the ScriptGain RSA private-key PEM (required to sign)}
        {--write : Persist the four values to Settings on this instance}';

    protected $description = 'Sign an agent release (version|sha256) with the ScriptGain vendor key and emit the update settings.';

    public function handle(): int
    {
        $version = trim((string) $this->argument('version'));

        // Resolve the sha256 from --sha256 or by hashing --file.
        $sha256 = strtolower(trim((string) $this->option('sha256')));
        if ($sha256 === '' && $this->option('file')) {
            $file = (string) $this->option('file');
            if (! is_file($file)) {
                $this->error("Release file not found: {$file}");

                return self::FAILURE;
            }
            $sha256 = strtolower((string) hash_file('sha256', $file));
        }
        if (! preg_match('/^[0-9a-f]{64}$/', $sha256)) {
            $this->error('Provide a valid sha256 via --sha256=<64hex> or --file=<path>.');

            return self::FAILURE;
        }

        // The private key must be reachable. Prefer --key, then config/env.
        $keyPath = (string) ($this->option('key')
            ?: config('licensing.sign_key', env('LICENSE_SIGN_KEY', '')));
        if ($keyPath === '' || ! is_readable($keyPath)) {
            $this->error('Signing key not reachable. Pass --key=/path/to/scriptgain-private.pem.');
            $this->line('The ScriptGain private key is NOT deployed to product hosts. Run this on the host');
            $this->line('where the vendor key lives (cp1), or copy the printed values into General Settings.');

            return self::FAILURE;
        }

        $pem = (string) file_get_contents($keyPath);
        $key = openssl_pkey_get_private($pem);
        if ($key === false) {
            $this->error('Could not load the private key from '.$keyPath.'. Is it a valid RSA PEM?');

            return self::FAILURE;
        }

        $canonical = $version.'|'.$sha256;
        $signature = '';
        if (! openssl_sign($canonical, $signature, $key, OPENSSL_ALGO_SHA256)) {
            $this->error('openssl_sign failed.');

            return self::FAILURE;
        }
        $signatureB64 = base64_encode($signature);

        $url = (string) $this->option('url');

        $this->newLine();
        $this->info('Signed agent release '.$version);
        $this->line('  canonical : '.$canonical);
        $this->table(['Setting', 'Value'], [
            ['agent_latest_version', $version],
            ['agent_download_url', $url ?: '(set --url or fill in later)'],
            ['agent_download_sha256', $sha256],
            ['agent_download_signature', $signatureB64],
        ]);

        if ($this->option('write')) {
            Setting::put('agent_latest_version', $version);
            Setting::put('agent_download_sha256', $sha256);
            Setting::put('agent_download_signature', $signatureB64);
            if ($url !== '') {
                Setting::put('agent_download_url', $url);
            }
            $this->info('Persisted to Settings on this instance. Enable "Allow Agent Auto-Update" to advertise it.');
        } else {
            $this->comment('Copy these into Settings → General → Agents (or re-run with --write on the target instance).');
        }

        return self::SUCCESS;
    }
}
