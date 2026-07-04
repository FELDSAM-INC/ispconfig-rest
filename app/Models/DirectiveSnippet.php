<?php

namespace App\Models;

use App\Casts\YesNoBoolean;
use Illuminate\Database\Eloquent\Casts\Attribute;

/**
 * directive_snippets — reusable Apache/nginx/PHP/proxy web-server
 * configuration snippets referenced by web_domain.directive_snippets_id
 * (contract: api/components/schemas/DirectiveSnippet.yaml; legacy:
 * source_code/interface/web/admin/form/directive_snippets.tform.php,
 * db_history=yes).
 *
 * The (name, type) pair is unique at the application level (legacy
 * validate_server_directive_snippets::validate_snippet — no DB UNIQUE
 * constraint); the controller answers duplicates with 409 per the contract.
 * master_directive_snippets_id is intentionally unexposed.
 */
class DirectiveSnippet extends BaseModel
{
    public const TYPES = ['apache', 'nginx', 'php', 'proxy'];

    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'directive_snippets';

    /**
     * The primary key for the model.
     *
     * @var string
     */
    protected $primaryKey = 'directive_snippets_id';

    /**
     * Writable fields per the contract. System fields come from IspContext
     * (BaseModel); master_directive_snippets_id is unexposed.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'name',
        'type',
        'snippet',
        'customer_viewable',
        'active',
        'update_sites',
        'required_php_snippets',
    ];

    /**
     * directive_snippets enums are lowercase 'n'/'y' (ispconfig3.sql), so
     * the default YesNoBoolean case applies.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'customer_viewable' => YesNoBoolean::class,
        'active' => YesNoBoolean::class,
        'update_sites' => YesNoBoolean::class,
        'sys_userid' => 'integer',
        'sys_groupid' => 'integer',
    ];

    /**
     * The contract exposes the primary key as `id` and hides
     * master_directive_snippets_id.
     *
     * @var array<int, string>
     */
    protected $hidden = [
        'directive_snippets_id',
        'master_directive_snippets_id',
    ];

    /**
     * @var array<int, string>
     */
    protected $appends = [
        'id',
    ];

    /**
     * Raw column defaults mirroring the legacy tform/DB defaults
     * (customer_viewable 'n', active 'y', update_sites 'n' — the DB default;
     * the legacy edit form's pre-check of update_sites only matters on
     * update). Values are DB-native — $attributes bypasses the casts.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'snippet' => '',
        'customer_viewable' => 'n',
        'active' => 'y',
        'update_sites' => 'n',
        'required_php_snippets' => '',
        'master_directive_snippets_id' => 0,
    ];

    /**
     * The contract exposes the primary key as `id`.
     */
    protected function id(): Attribute
    {
        return Attribute::get(fn () => $this->getKey());
    }

    /**
     * IDs listed in required_php_snippets (CSV, legacy separator ',').
     *
     * @return array<int, int>
     */
    public function requiredPhpSnippetIds(): array
    {
        $csv = trim((string) $this->getRawOriginal('required_php_snippets', $this->required_php_snippets ?? ''));

        if ($csv === '') {
            return [];
        }

        return array_values(array_map('intval', array_filter(array_map('trim', explode(',', $csv)), fn ($v) => $v !== '')));
    }
}
