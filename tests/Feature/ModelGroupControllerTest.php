<?php

use App\Models\OpenRouterModel;
use App\Services\BenchmarkRunner;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

uses(LazilyRefreshDatabase::class);

beforeEach(function () {
    $this->root = storage_path('framework/testing/model-groups-'.Str::uuid());
    File::ensureDirectoryExists($this->root.'/config');
    File::ensureDirectoryExists($this->root.'/prompts');
    config()->set('benchforge.root_path', $this->root);
});

afterEach(function () {
    File::deleteDirectory($this->root);
});

test('it lists saved groups and flags models missing from the catalogs', function () {
    OpenRouterModel::factory()->create(['model_id' => 'acme/nova', 'available' => true, 'agent_compatible' => true]);
    File::put($this->root.'/config/pilot.conf', "# max_parallel=2\nnova|openrouter/acme/nova|3101\nlocal|ollama/qwen|3102\n");
    File::put($this->root.'/config/broken.conf', "not a model line\n");
    File::put($this->root.'/config/pilot.conf.backup', "ignored|openrouter/x/y|3103\n");

    $this->get(route('model-groups.index'))
        ->assertOk()
        ->assertSee('Kayıtlı gruplar · 2')
        ->assertSee('openrouter/acme/nova')
        ->assertSee('ollama/qwen')
        ->assertSee('KATALOGDA YOK')
        ->assertSee('geçerli bir model kaydı değil', false)
        ->assertDontSee('pilot.conf.backup');
});

test('it creates a group with generated folders and ports', function () {
    $this->post(route('model-groups.store'), [
        'name' => 'yeni-grup',
        'models' => "openrouter/acme/nova\n\n  opencode-go/glm-5.3-flash  \nopenrouter/acme/nova\n",
    ])->assertRedirect(route('model-groups.index'));

    $lines = File::lines($this->root.'/config/yeni-grup.conf')->filter()->values()->all();

    expect($lines)->toHaveCount(2)
        ->and($lines[0])->toStartWith('or-nova-')->toEndWith('|openrouter/acme/nova|3251')
        ->and($lines[1])->toStartWith('go-glm-53-flash-')->toEndWith('|opencode-go/glm-5.3-flash|3252');

    $options = app(BenchmarkRunner::class)->options();
    expect(collect($options['configs'])->firstWhere('value', 'config/yeni-grup.conf')['modelCount'])->toBe(2);
});

test('it keeps folders ports and comments of models that stay when a group is edited', function () {
    File::put($this->root.'/config/pilot.conf', "# Notum\n# max_parallel=3\nnova|openrouter/acme/nova|3399\nold|openrouter/acme/old|3398\nthird|openrouter/acme/third|3397\n");

    $this->put(route('model-groups.update', 'pilot'), [
        'name' => 'pilot',
        'models' => "openrouter/acme/nova\nopenrouter/acme/fresh",
    ])->assertRedirect(route('model-groups.index'));

    $lines = File::lines($this->root.'/config/pilot.conf')->filter()->values()->all();

    expect($lines[0])->toBe('# Notum')
        ->and($lines[1])->toBe('# max_parallel=2')
        ->and($lines[2])->toBe('nova|openrouter/acme/nova|3399')
        ->and($lines[3])->toEndWith('|openrouter/acme/fresh|3251')
        ->and($lines)->toHaveCount(4);
});

test('renaming a group moves the old file to the trash', function () {
    File::put($this->root.'/config/pilot.conf', "nova|openrouter/acme/nova|3101\n");

    $this->put(route('model-groups.update', 'pilot'), ['name' => 'pilot-yeni', 'models' => 'openrouter/acme/nova'])
        ->assertRedirect(route('model-groups.index'));

    expect(File::exists($this->root.'/config/pilot.conf'))->toBeFalse()
        ->and(File::get($this->root.'/config/pilot-yeni.conf'))->toBe("nova|openrouter/acme/nova|3101\n")
        ->and(File::files($this->root.'/config/.silinen'))->toHaveCount(1);
});

test('it refuses invalid groups without touching existing files', function (array $payload, string $error) {
    File::put($this->root.'/config/pilot.conf', "nova|openrouter/acme/nova|3101\n");

    $this->from(route('model-groups.index'))
        ->post(route('model-groups.store'), $payload)
        ->assertRedirect(route('model-groups.index'))
        ->assertSessionHasErrors();

    expect(session('errors')->first())->toContain($error)
        ->and(File::get($this->root.'/config/pilot.conf'))->toBe("nova|openrouter/acme/nova|3101\n");
})->with([
    'existing name' => [['name' => 'pilot', 'models' => 'openrouter/acme/x'], 'zaten var'],
    'bad name' => [['name' => '../evil', 'models' => 'openrouter/acme/x'], 'Grup adı'],
    'model without provider' => [['name' => 'yeni', 'models' => 'nova'], 'Geçersiz model kimliği'],
    'model with separator' => [['name' => 'yeni', 'models' => 'openrouter/acme|x'], 'Geçersiz model kimliği'],
    'too many models' => [['name' => 'yeni', 'models' => implode("\n", array_map(fn (int $i): string => "openrouter/acme/m{$i}", range(1, 11)))], '1–10'],
]);

test('deleting a group moves it to the trash and hides it from the runner', function () {
    File::put($this->root.'/config/pilot.conf', "nova|openrouter/acme/nova|3101\n");
    File::put($this->root.'/config/other.conf', "nova|openrouter/acme/nova|3101\n");

    $this->delete(route('model-groups.destroy', 'pilot'))->assertRedirect(route('model-groups.index'));

    expect(File::exists($this->root.'/config/pilot.conf'))->toBeFalse()
        ->and(File::files($this->root.'/config/.silinen'))->toHaveCount(1)
        ->and(array_column(app(BenchmarkRunner::class)->options()['configs'], 'value'))->toBe(['config/other.conf']);

    $this->delete(route('model-groups.destroy', 'pilot'))->assertSessionHasErrors('group');
    $this->get(route('model-groups.edit', 'pilot'))->assertNotFound();
});

test('it saves a catalog selection from the runner as a group', function () {
    $this->from(route('runner.create'))->post(route('model-groups.selection.store'), [
        'group_name' => 'go-secimi',
        'model_mode' => 'opencode_go',
        'go_models' => ['glm-5.3-flash', 'kimi-k3'],
        'models' => ['acme/ignored'],
        'run_id' => 'deneme',
    ])->assertRedirect(route('runner.create'))->assertSessionHas('group_status');

    $contents = File::get($this->root.'/config/go-secimi.conf');

    expect($contents)->toContain('|opencode-go/glm-5.3-flash|3251')
        ->toContain('|opencode-go/kimi-k3|3252')
        ->not->toContain('acme/ignored')
        ->and(session()->getOldInput('run_id'))->toBe('deneme');
});

test('it normalises a free-form group name before saving a selection', function () {
    $this->from(route('runner.create'))->post(route('model-groups.selection.store'), [
        'group_name' => 'Kurtuluş Savaşı',
        'model_mode' => 'opencode_go',
        'go_models' => ['glm-5.3-flash'],
    ])->assertRedirect(route('runner.create'))->assertSessionHas('group_status');

    expect(File::exists($this->root.'/config/kurtulus-savasi.conf'))->toBeTrue();
});

test('it does not save a saved-group selection as a new group', function () {
    $this->from(route('runner.create'))->post(route('model-groups.selection.store'), [
        'group_name' => 'grup',
        'model_mode' => 'group',
    ])->assertSessionHasErrors('model_mode');
});
