<?php

use App\Http\Controllers\Api\MobileOperationalController;
use App\Livewire\V3\Concerns\FiltersByWorkDate;
use App\Livewire\V3\Operations\Index;
use App\Models\CleaningSession;
use App\Models\SppgUnit;
use App\Models\User;
use App\Services\CleaningScheduleService;
use App\Support\Mobile\MobileOperationalRecordTransformer;
use App\Support\Mobile\MobileWorkspaceRegistry;
use App\Support\V3\OperationalModuleRegistry;
use App\Support\V3\SystemUnit;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Livewire\Livewire;

class CleaningDateInteractionPage extends Index
{
    protected function currentUnit(): SppgUnit { return SppgUnit::findOrFail(1); }
    protected function shellData(SppgUnit $unit): array { return ['unit' => $unit, 'navigation' => [], 'roleLabel' => 'Penguji']; }
}

// Render the real module templates with empty fixtures; no operational queries.
class OperationalPageStructureProbe extends CleaningDateInteractionPage
{
    public function render(OperationalModuleRegistry $registry)
    {
        return view('livewire.v3.operations.index', [
            ...$this->shellData($this->currentUnit()),
            'navigation' => [[
                'key' => 'kebersihan', 'label' => 'Kebersihan', 'icon' => 'home',
                'active' => true, 'standalone' => false, 'items' => [],
            ]],
            'definition' => $registry->get($this->module),
            'selectedDate' => $this->selectedWorkDate(),
            'records' => new \Illuminate\Pagination\LengthAwarePaginator([], 0, 15),
            'attentionRecords' => collect(), 'cleaningAreas' => collect(),
            'activeRoute' => null, 'availableCount' => 0,
            'washingSummary' => [], 'canCreate' => false,
        ]);
    }
}

// Deliberately no RefreshDatabase or migrations: all fixtures live in a named,
// disposable SQLite memory connection, never the configured operational DB.
beforeEach(function (): void {
    config(['database.default' => 'cleaning_history_test', 'database.connections' => [
        'cleaning_history_test' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => ''],
    ]]);
    $connection = DB::connection('cleaning_history_test');
    expect($connection->getDriverName())->toBe('sqlite')
        ->and($connection->getDatabaseName())->toBe(':memory:');
    $schema = Schema::connection('cleaning_history_test');
    $schema->create('sppg_units', function (Blueprint $table): void {
        $table->id();
        $table->boolean('is_active');
    });
    $schema->create('cleaning_areas', function (Blueprint $table): void {
        $table->id();
        $table->integer('sppg_unit_id');
        $table->string('name');
        $table->boolean('is_active');
        $table->softDeletes();
    });
    $schema->create('cleaning_sessions', function (Blueprint $table): void {
        $table->id();
        $table->integer('sppg_unit_id');
        $table->integer('cleaning_area_id');
        $table->integer('petugas_id')->nullable();
        $table->string('session_number');
        $table->date('scheduled_date');
        $table->string('state');
        $table->string('status')->default('draft');
        $table->timestamps();
        $table->softDeletes();
    });
    $schema->create('cleaning_checklist_items', function (Blueprint $table): void {
        $table->id();
        $table->integer('cleaning_session_id');
        $table->integer('sort_order')->default(0);
        $table->boolean('is_mandatory')->default(true);
        $table->string('result')->default('pending');
    });
    $schema->create('cleaning_findings', function (Blueprint $table): void {
        $table->id();
        $table->integer('cleaning_session_id');
    });
    $this->travelTo(Carbon::parse('2026-09-03 10:00:00'));
    DB::table('sppg_units')->insert(['id' => 1, 'is_active' => true]);
    DB::table('cleaning_areas')->insert(['id' => 1, 'sppg_unit_id' => 1, 'name' => 'Area uji', 'is_active' => true]);
    $actor = new User(['name' => 'Penguji', 'is_super_admin' => true]);
    $actor->id = 1;
    $actor->setRelation('roles', collect());
    Auth::setUser($actor);
});

afterEach(function (): void {
    DB::purge('cleaning_history_test');
    $this->travelBack();
});

function cleaningHistoryFixture(string $date, string $state, int $unitId = 1): int
{
    return DB::table('cleaning_sessions')->insertGetId([
        'sppg_unit_id' => $unitId, 'cleaning_area_id' => 1,
        'session_number' => 'CLEAN-'.$date.'-'.$state, 'scheduled_date' => $date,
        'state' => $state, 'status' => 'draft',
    ]);
}

function cleaningHistoryWebIndex(string $date): Index
{
    $index = new class extends Index
    {
        protected function currentUnit(): SppgUnit { return SppgUnit::findOrFail(1); }
        protected function shellData(SppgUnit $unit): array { return ['unit' => $unit, 'navigation' => [], 'roleLabel' => 'Penguji']; }
    };
    $index->module = 'kebersihan';
    $index->workDate = $date;

    return $index;
}

function cleaningHistoryApi(array $filters): array
{
    $request = Request::create('/api/mobile/operational-modules/kebersihan/records', 'GET', $filters);
    $request->setUserResolver(fn () => Auth::user());
    app()->instance('request', $request);
    $registry = Mockery::mock(MobileWorkspaceRegistry::class);
    $definition = [
        'model' => CleaningSession::class, 'number' => 'session_number', 'date' => 'scheduled_date',
        'with' => ['cleaningArea', 'checklistItems'],
    ];
    $registry->shouldReceive('authorize')->once()->andReturn($definition);
    $response = app(MobileOperationalController::class)->index(
        $request, 'kebersihan', $registry, new MobileOperationalRecordTransformer($registry),
        app(SystemUnit::class), app(CleaningScheduleService::class),
    );

    return $response->getData(true);
}

it('shows all work on the selected historical date without creating other sessions', function (): void {
    $expected = [cleaningHistoryFixture('2026-09-02', 'planned'), cleaningHistoryFixture('2026-09-02', 'ready')];
    cleaningHistoryFixture('2026-09-01', 'in_progress');
    cleaningHistoryFixture('2026-09-02', 'ready', 2);
    $data = cleaningHistoryWebIndex('2026-09-02')->render(app(OperationalModuleRegistry::class))->getData();

    expect($data['records']->pluck('id')->sort()->values()->all())->toBe($expected)
        ->and($data['selectedDate'])->toBe('2026-09-02')
        ->and(DB::table('cleaning_sessions')->count())->toBe(4);
});

it('does not mark finished work from other dates as pending', function (): void {
    cleaningHistoryFixture('2026-09-01', 'ready');
    cleaningHistoryFixture('2026-09-01', 'completed');
    $pending = cleaningHistoryFixture('2026-09-01', 'planned');
    $data = cleaningHistoryWebIndex('2026-09-02')->render(app(OperationalModuleRegistry::class))->getData();

    expect($data['attentionRecords']->pluck('id')->all())->toBe([$pending]);
});

it('returns previous day work through the mobile API with accurate completion flags', function (): void {
    $planned = cleaningHistoryFixture('2026-09-02', 'planned');
    $ready = cleaningHistoryFixture('2026-09-02', 'ready');
    cleaningHistoryFixture('2026-09-01', 'ready');
    cleaningHistoryFixture('2026-09-02', 'ready', 2);
    $result = cleaningHistoryApi(['date_from' => '2026-09-02', 'date_to' => '2026-09-02']);
    $records = collect($result['data'])->keyBy('id');

    expect($result['meta']['total'])->toBe(2)
        ->and($records[$planned]['is_history'])->toBeFalse()
        ->and($records[$ready]['is_history'])->toBeTrue()
        ->and($records->pluck('date')->unique()->values()->all())->toBe(['2026-09-02'])
        ->and(DB::table('cleaning_sessions')->count())->toBe(4);
});

it('keeps unstarted previous day cleaning available in the active scope', function (): void {
    $planned = cleaningHistoryFixture('2026-09-01', 'planned');
    $started = cleaningHistoryFixture('2026-09-01', 'in_progress');
    cleaningHistoryFixture('2026-09-01', 'ready');
    $query = CleaningSession::query()->where('sppg_unit_id', 1);
    $method = new ReflectionMethod(MobileOperationalController::class, 'applyActiveWorkflowScope');
    $method->invoke(app(MobileOperationalController::class), $query, 'kebersihan', ['date' => 'scheduled_date']);

    expect($query->orderBy('id')->pluck('id')->all())->toBe([$planned, $started]);
});

it('returns an empty historical day without inventing or creating work', function (): void {
    $result = cleaningHistoryApi(['date_from' => '2026-09-02', 'date_to' => '2026-09-02']);
    expect($result['data'])->toBe([])
        ->and(DB::table('cleaning_sessions')->count())->toBe(0);
});

it('normalizes malformed dates before navigation and resets pagination', function (string $invalid): void {
    $filter = new class
    {
        use FiltersByWorkDate;
        public int $page = 3;
        public function resetPage(): void { $this->page = 1; }
        public function selected(): string { return $this->selectedWorkDate(); }
    };
    $filter->workDate = $invalid;
    $filter->updatedWorkDate();
    expect($filter->selected())->toBe('2026-09-03')->and($filter->page)->toBe(1);
    $filter->previousWorkDate();
    expect($filter->selected())->toBe('2026-09-02');
    $filter->nextWorkDate();
    expect($filter->selected())->toBe('2026-09-03');
})->with(['', 'invalid-date', '2026-02-30', '2026-13-01']);

it('accepts Indonesian display date formats in the web cleaning date filter', function (string $value): void {
    $filter = new class
    {
        use FiltersByWorkDate;
        public function selected(): string { return $this->selectedWorkDate(); }
    };
    $filter->workDate = $value;

    expect($filter->selected())->toBe('2026-09-02')
        ->and($filter->workDate)->toBe('2026-09-02');
})->with(['02-09-2026', '02/09/2026']);

it('rerenders the web page when selecting September 2 after September 4', function (): void {
    $previous = cleaningHistoryFixture('2026-09-02', 'ready');
    $later = cleaningHistoryFixture('2026-09-04', 'ready');
    Livewire::withQueryParams(['tanggal' => '2026-09-04'])
        ->test(CleaningDateInteractionPage::class, ['module' => 'kebersihan'])
        ->assertSet('workDate', '2026-09-04')
        ->assertViewHas('records', fn ($records) => $records->pluck('id')->all() === [$later])
        ->assertSee('selectWorkDate($event.target.value)', false)
        ->call('setPage', 3)
        ->call('selectWorkDate', '2026-09-02')
        ->assertSet('workDate', '2026-09-02')
        ->assertSet('paginators.page', 1)
        ->assertViewHas('selectedDate', '2026-09-02')
        ->assertViewHas('records', fn ($records) => $records->pluck('id')->all() === [$previous])
        ->assertSee('Pekerjaan tanggal 02 September 2026')
        ->assertDontSee('Pekerjaan tanggal 04 September 2026')
        ->call('previousWorkDate')
        ->assertViewHas('selectedDate', '2026-09-01')
        ->assertViewHas('records', fn ($records) => $records->isEmpty())
        ->call('nextWorkDate')
        ->assertViewHas('records', fn ($records) => $records->pluck('id')->all() === [$previous]);
    expect(DB::table('cleaning_sessions')->count())->toBe(2);
});

it('compiles the cleaning date list and detail templates', function (string $view): void {
    $compiled = app('blade.compiler')->compileString(file_get_contents(resource_path('views/livewire/v3/operations/'.$view.'.blade.php')));

    expect(token_get_all($compiled, TOKEN_PARSE))->not->toBeEmpty();
})->with(['cleaning-index', 'cleaning-form']);

it('keeps every date input and action inside the Livewire root', function (string $module): void {
    $page = Livewire::withQueryParams(['tanggal' => '2026-09-02'])
        ->test(OperationalPageStructureProbe::class, ['module' => $module]);

    foreach (['2026-09-02', '2026-09-01'] as $date) {
        $page->assertSet('workDate', $date);
        $dom = new DOMDocument();
        @$dom->loadHTML('<?xml encoding="UTF-8">'.$page->html());
        $xpath = new DOMXPath($dom);
        $root = $xpath->query('//*[@*[name()="wire:id"]]')->item(0);
        expect($root)->not->toBeNull();
        $inputs = $xpath->query('//input[@type="date"]');
        expect($inputs->length)->toBe($module === 'kebersihan' ? 3 : 1);
        foreach ($inputs as $input) {
            $ancestors = $xpath->query('ancestor::*[@*[name()="wire:id"]]', $input);
            expect($ancestors->length)->toBe(1);
        }
        $buttons = $xpath->query('.//button[@*[name()="wire:click"]]', $root);
        expect($buttons->length)->toBeGreaterThanOrEqual(3);
        // Updates return the snapshot separately from the morphed HTML.
        $snapshot = $page->snapshot;
        expect($snapshot['data']['workDate'])->toBe($date);
        if ($date === '2026-09-02') {
            $page->call('previousWorkDate');
        }
    }
})->with(['distribusi', 'pencucian', 'kebersihan']);

it('compiles the sidebar icon expression instead of sending a raw Blade directive', function (): void {
    $page = Livewire::withQueryParams(['tanggal' => '2026-09-02'])
        ->test(OperationalPageStructureProbe::class, ['module' => 'kebersihan']);
    $dom = new DOMDocument();
    @$dom->loadHTML('<?xml encoding="UTF-8">'.$page->html());
    $xpath = new DOMXPath($dom);
    $icons = $xpath->query('//svg[@*[name()="x-bind:class"]]');
    expect($icons->length)->toBe(1);
    $expression = $icons->item(0)->getAttribute('x-bind:class');
    expect($expression)->not->toContain('@js', '$group')
        ->and($expression)->toContain("openModule === 'kebersihan'", 'rotate-180');
});
