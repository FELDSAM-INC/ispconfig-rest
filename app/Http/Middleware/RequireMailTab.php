<?php

namespace App\Http\Middleware;

use App\Services\AccountMailService;
use App\Support\IspContext;
use App\Support\Problem;
use App\Support\ProblemType;
use Closure;
use Illuminate\Http\Request;

/**
 * Mailbox tab gate 'mail.tab:{setting}' (spec 025 FR-011, FR-012): writes to
 * a mailbox sub-resource that belongs to a tab of the legacy mailbox form
 * (autoresponder, mail filters) are denied for client and reseller keys when
 * the installation hides that tab (sys_ini [mail] mailbox_show_*_tab = n,
 * mail_user.tform.php:356, :427). Admin keys pass.
 */
class RequireMailTab
{
    public function __construct(protected AccountMailService $mail) {}

    public function handle(Request $request, Closure $next, string $setting)
    {
        if (! $this->mail->tabAllowed(app(IspContext::class)->authScope(), $setting)) {
            return Problem::response(403, 'Forbidden', AccountMailService::TAB_MESSAGES[$setting], [
                'type' => ProblemType::uri(ProblemType::FEATURE_NOT_ALLOWED),
                'feature' => $setting,
            ]);
        }

        return $next($request);
    }
}
