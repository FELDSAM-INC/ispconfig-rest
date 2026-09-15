<?php

namespace Tests\Feature;

use App\Http\Concerns\HandlesListQuery;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Tests\Support\SitesSchema;
use Tests\TestCase;

/**
 * HandlesListQuery optional arguments (spec 018 research R6): `defaultOrder`
 * for lists whose natural order is newest first and `sortAliases` mapping
 * public sort names to columns. Existing callers keep ascending order.
 */
class ListQueryAliasTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        SitesSchema::create();

        foreach ([100, 300, 200] as $tstamp) {
            DB::table('web_backup')->insert(['parent_domain_id' => 1, 'tstamp' => $tstamp, 'filename' => "f{$tstamp}"]);
        }
    }

    private function lister(): object
    {
        return new class
        {
            use HandlesListQuery;

            public function run(mixed ...$arguments): array
            {
                return $this->listQuery(...$arguments);
            }
        };
    }

    private function query(): Builder
    {
        $model = new class extends Model
        {
            protected $table = 'web_backup';

            protected $primaryKey = 'backup_id';

            public $timestamps = false;
        };

        return $model->newQuery();
    }

    /**
     * @return array<int, int>
     */
    private function tstamps(array $result): array
    {
        return $result['data']->pluck('tstamp')->map(fn ($value): int => (int) $value)->all();
    }

    public function test_default_order_desc_is_honoured(): void
    {
        $result = $this->lister()->run(
            $this->query(),
            Request::create('/x', 'GET'),
            sortable: ['created_at', 'id'],
            defaultSort: 'created_at',
            defaultOrder: 'desc',
            sortAliases: ['created_at' => 'tstamp', 'id' => 'backup_id'],
        );

        $this->assertSame([300, 200, 100], $this->tstamps($result));
    }

    public function test_sort_alias_maps_public_name_to_column(): void
    {
        $result = $this->lister()->run(
            $this->query(),
            Request::create('/x', 'GET', ['sort' => 'id', 'order' => 'asc']),
            sortable: ['created_at', 'id'],
            defaultSort: 'created_at',
            defaultOrder: 'desc',
            sortAliases: ['created_at' => 'tstamp', 'id' => 'backup_id'],
        );

        $this->assertSame([100, 300, 200], $this->tstamps($result));
    }

    public function test_column_names_behind_an_alias_are_not_sortable(): void
    {
        $this->expectException(BadRequestHttpException::class);

        $this->lister()->run(
            $this->query(),
            Request::create('/x', 'GET', ['sort' => 'tstamp']),
            sortable: ['created_at', 'id'],
            defaultSort: 'created_at',
            defaultOrder: 'desc',
            sortAliases: ['created_at' => 'tstamp', 'id' => 'backup_id'],
        );
    }

    public function test_existing_callers_still_default_to_ascending(): void
    {
        $result = $this->lister()->run(
            $this->query(),
            Request::create('/x', 'GET'),
            sortable: ['tstamp'],
            defaultSort: 'tstamp',
        );

        $this->assertSame([100, 200, 300], $this->tstamps($result));
    }
}
