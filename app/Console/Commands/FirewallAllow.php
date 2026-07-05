<?php

namespace App\Console\Commands;

use App\Models\ServerFirewall;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Open a port in this host's ISPConfig firewall by adding it to the server's
 * `firewall` port list through the normal datalog path, so ISPConfig's own
 * firewall plugin (bastille / ufw) reconfigures the running firewall.
 *
 * Exit codes: 0 = port allowed (added or already present); 2 = no ISPConfig
 * firewall record to extend (caller may fall back to the OS firewall);
 * 1 = invalid input.
 */
class FirewallAllow extends Command
{
    protected $signature = 'firewall:allow {port : Port number to open}
                            {--proto=tcp : Protocol (tcp|udp)}
                            {--server= : ISPConfig server_id (default: auto-detect this host)}';

    protected $description = "Add a port to this server's ISPConfig firewall rule (applied via datalog)";

    public function handle(): int
    {
        $port = (int) $this->argument('port');
        if ($port < 1 || $port > 65535) {
            $this->error('Port must be between 1 and 65535.');

            return self::FAILURE;
        }

        $proto = strtolower((string) $this->option('proto'));
        if (! in_array($proto, ['tcp', 'udp'], true)) {
            $this->error("Protocol must be 'tcp' or 'udp'.");

            return self::FAILURE;
        }
        $column = $proto === 'tcp' ? 'tcp_port' : 'udp_port';

        $serverId = $this->resolveServerId();
        if ($serverId === null) {
            return 2;
        }

        $firewall = ServerFirewall::query()->where('server_id', $serverId)->first();
        if ($firewall === null) {
            $this->warn("No ISPConfig firewall record for server {$serverId}.");
            $this->warn('Not creating one — a fresh record would restrict the firewall to only this port.');
            $this->warn('Configure the firewall in the ISPConfig panel first, or open the port in your OS firewall.');

            return 2;
        }

        $list = (string) $firewall->getAttribute($column);
        if ($this->covered($list, $port)) {
            $this->info("Port {$port}/{$proto} is already allowed on server {$serverId}.");

            return self::SUCCESS;
        }

        $firewall->setAttribute($column, $list === '' ? (string) $port : $list.','.$port);
        $firewall->save(); // datalog 'u' → ISPConfig firewall plugin reconfigures

        $this->info("Added port {$port}/{$proto} to the ISPConfig firewall (server {$serverId}).");

        return self::SUCCESS;
    }

    /**
     * Resolve which ISPConfig server this host is: explicit --server, else the
     * only server, else a hostname match against server_name.
     */
    private function resolveServerId(): ?int
    {
        if ($this->option('server')) {
            return (int) $this->option('server');
        }

        $servers = DB::table('server')->get(['server_id', 'server_name']);
        if ($servers->isEmpty()) {
            $this->warn('No servers found in the ISPConfig database.');

            return null;
        }
        if ($servers->count() === 1) {
            return (int) $servers->first()->server_id;
        }

        $host = gethostname() ?: '';
        foreach ($servers as $s) {
            if (strcasecmp((string) $s->server_name, $host) === 0) {
                return (int) $s->server_id;
            }
        }

        $this->warn('Multiple servers present and none matched this hostname — pass --server=ID.');

        return null;
    }

    /**
     * Is $port already covered by a comma-separated list of ports and LOW:HIGH ranges?
     */
    private function covered(string $list, int $port): bool
    {
        foreach (explode(',', $list) as $token) {
            $token = trim($token);
            if ($token === '') {
                continue;
            }
            if (str_contains($token, ':')) {
                [$lo, $hi] = explode(':', $token, 2);
                if ((int) $lo <= $port && $port <= (int) $hi) {
                    return true;
                }
            } elseif ((int) $token === $port) {
                return true;
            }
        }

        return false;
    }
}
