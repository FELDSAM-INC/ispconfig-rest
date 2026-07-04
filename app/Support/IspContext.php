<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;

/**
 * The acting ISPConfig identity for the current request (or CLI/test run).
 *
 * Legacy ISPConfig attributes every write to the logged-in interface user
 * ($_SESSION['s']['user']). This API has no session — ApiKeyAuth resolves the
 * key to a sys_userid/sys_groupid pair and stores it here (registered as a
 * scoped singleton, so the identity lives exactly as long as one request).
 * Models and services read from this context instead of reaching into
 * request(), which keeps datalog writes working from CLI commands and tests
 * where no HTTP request exists (defaults: sys_userid 1 / sys_groupid 1, the
 * ISPConfig admin).
 */
class IspContext
{
    protected int $sysUserId = 1;

    protected int $sysGroupId = 1;

    protected ?string $username = null;

    protected ?string $sessionId = null;

    protected ?AuthScope $authScope = null;

    /**
     * Set the acting identity (called by ApiKeyAuth after key validation).
     */
    public function actAs(int $sysUserId, int $sysGroupId): void
    {
        $this->sysUserId = $sysUserId;
        $this->sysGroupId = $sysGroupId;
        $this->username = null;
        $this->authScope = null;
    }

    /**
     * Park the resolved authorization scope (called by ApiKeyAuth after
     * actAs(); spec 011 FR-001).
     */
    public function actAsScope(AuthScope $scope): void
    {
        $this->authScope = $scope;
    }

    /**
     * The acting authorization scope. Outside an authenticated HTTP request
     * (CLI commands, tests seeding data) no scope was resolved — default to
     * an admin scope carrying the current identity so datalog attribution
     * keeps working unrestricted (spec 011 FR-025).
     */
    public function authScope(): AuthScope
    {
        return $this->authScope ??= AuthScope::admin($this->sysUserId, $this->sysGroupId);
    }

    public function sysUserId(): int
    {
        return $this->sysUserId;
    }

    public function sysGroupId(): int
    {
        return $this->sysGroupId;
    }

    /**
     * Username written into sys_datalog.user.
     *
     * Legacy uses $_SESSION['s']['user']['username'] with fallback 'admin'
     * (db_mysql.inc.php::datalogSave). We resolve it from sys_user.username
     * by the acting sys_userid; the fallback stays 'admin'.
     */
    public function username(): string
    {
        if ($this->username === null) {
            $username = DB::table('sys_user')
                ->where('userid', $this->sysUserId)
                ->value('username');

            $this->username = ($username !== null && $username !== '') ? $username : 'admin';
        }

        return $this->username;
    }

    /**
     * Session id written into sys_datalog.session_id.
     *
     * Legacy writes PHP's session_id(), which groups all datalog rows of one
     * interface request (e.g. a delete cascade). We generate one stable id
     * per request-scoped context for the same grouping semantics.
     */
    public function sessionId(): string
    {
        if ($this->sessionId === null) {
            $this->sessionId = bin2hex(random_bytes(16));
        }

        return $this->sessionId;
    }
}
