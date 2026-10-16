<?php

use App\Models\BenchmarkExecution;
use App\Models\BenchmarkPrompt;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

uses(LazilyRefreshDatabase::class);

beforeEach(function () {
    $this->root = storage_path('framework/testing/prompts-'.Str::uuid());
    File::ensureDirectoryExists($this->root.'/prompts');
    File::ensureDirectoryExists($this->root.'/config');
    config()->set('benchforge.root_path', $this->root);
    $this->text = 'Build a complete, production-quality storefront with search and checkout.';
});

afterEach(function () {
    File::deleteDirectory($this->root);
});

test('it lists prompt files and library prompts, but not trashed or other files', function () {
    File::put($this->root.'/prompts/nova-marketplace-v1.txt', $this->text);
    File::put($this->root.'/prompts/notes.md', 'ignored');
    File::ensureDirectoryExists($this->root.'/prompts/.silinen');
    File::put($this->root.'/prompts/.silinen/old-20260101-000000-abcd.txt', 'trashed prompt text here');
    BenchmarkPrompt::factory()->create(['title' => 'PulseGrid', 'content' => 'A pulse dashboard prompt with charts.', 'active' => true]);
    BenchmarkPrompt::factory()->create(['title' => 'Retired', 'active' => false]);

    $this->get(route('prompts.index'))
        ->assertOk()
        ->assertSee('Kayıtlı promptlar · 2')
        ->assertSee('Nova Marketplace V1')
        ->assertSee('prompts/nova-marketplace-v1.txt')
        ->assertSee('PulseGrid')
        ->assertSee('Kütüphane')
        ->assertDontSee('Retired')
        ->assertDontSee('notes.md')
        ->assertDontSee('trashed prompt text');
});

test('the sidebar links to the prompts page', function () {
    $this->get(route('prompts.index'))->assertSee(route('prompts.index'), false)->assertSee('Promptlar');
});

test('it creates a prompt file named after the title', function () {
    $this->post(route('prompts.store'), ['title' => 'Plush Octopus v2', 'content' => "  {$this->text}\r\n"])
        ->assertRedirect(route('prompts.index'))
        ->assertSessionHas('status');

    expect(File::get($this->root.'/prompts/plush-octopus-v2.txt'))->toBe($this->text.PHP_EOL);
});

test('it rejects duplicate names, unusable titles and too short prompts', function (array $input, string $error) {
    File::put($this->root.'/prompts/plush-octopus.txt', $this->text);

    $this->from(route('prompts.create'))
        ->post(route('prompts.store'), $input + ['content' => $this->text])
        ->assertRedirect(route('prompts.create'))
        ->assertSessionHasErrors();

    expect(collect(session('errors')->all())->implode(' '))->toContain($error)
        ->and(File::files($this->root.'/prompts'))->toHaveCount(1);
})->with([
    'existing name' => [['title' => 'Plush Octopus'], 'zaten var'],
    'no letters or digits' => [['title' => '!!!'], 'en az 2 harf'],
    'too short' => [['title' => 'Kısa', 'content' => 'çok kısa'], 'en az 20 karakter'],
]);

test('it updates a prompt file in place', function () {
    File::put($this->root.'/prompts/arc-editorial-v1.txt', $this->text);

    $this->put(route('prompts.update', 'arc-editorial-v1'), ['title' => 'Arc Editorial V1', 'content' => 'Revised editorial prompt with more detail.'])
        ->assertRedirect(route('prompts.index'));

    expect(File::get($this->root.'/prompts/arc-editorial-v1.txt'))->toBe('Revised editorial prompt with more detail.'.PHP_EOL)
        ->and(File::isDirectory($this->root.'/prompts/.silinen'))->toBeFalse();
});

test('renaming a prompt moves the old file to the trash', function () {
    File::put($this->root.'/prompts/arc-editorial-v1.txt', $this->text);

    $this->put(route('prompts.update', 'arc-editorial-v1'), ['title' => 'Arc Editorial V2', 'content' => $this->text])
        ->assertRedirect(route('prompts.index'));

    expect(File::exists($this->root.'/prompts/arc-editorial-v2.txt'))->toBeTrue()
        ->and(File::exists($this->root.'/prompts/arc-editorial-v1.txt'))->toBeFalse()
        ->and(File::files($this->root.'/prompts/.silinen'))->toHaveCount(1);
});

test('deleting a prompt file moves it to the trash', function () {
    File::put($this->root.'/prompts/red-queen-v2.txt', $this->text);

    $this->delete(route('prompts.destroy', 'red-queen-v2'))
        ->assertRedirect(route('prompts.index'))
        ->assertSessionHas('status', fn (string $status): bool => str_contains($status, 'prompts/.silinen'));

    $trashed = File::files($this->root.'/prompts/.silinen');
    expect(File::exists($this->root.'/prompts/red-queen-v2.txt'))->toBeFalse()
        ->and($trashed)->toHaveCount(1)
        ->and(File::get($trashed[0]->getPathname()))->toBe($this->text);
});

test('library prompts can be edited and deleted without touching the files', function () {
    $prompt = BenchmarkPrompt::factory()->create(['title' => 'PulseGrid', 'content' => 'A pulse dashboard prompt with charts.', 'active' => true]);

    $this->get(route('prompts.edit', "kutuphane-{$prompt->id}"))->assertOk()->assertSee('kütüphaneye kaydedilmiş');

    $this->put(route('prompts.update', "kutuphane-{$prompt->id}"), ['title' => 'PulseGrid 2', 'content' => $this->text])
        ->assertRedirect(route('prompts.index'));

    expect($prompt->fresh())
        ->title->toBe('PulseGrid 2')
        ->content->toBe($this->text)
        ->content_hash->toBe(hash('sha256', $this->text));

    $this->delete(route('prompts.destroy', "kutuphane-{$prompt->id}"))->assertRedirect(route('prompts.index'));

    expect($prompt->fresh()->active)->toBeFalse()
        ->and(File::files($this->root.'/prompts'))->toBeEmpty();
});

test('a prompt used by a running benchmark cannot be changed or deleted', function () {
    File::put($this->root.'/prompts/nova-marketplace-v1.txt', $this->text);
    BenchmarkExecution::factory()->create(['status' => 'running', 'prompt_path' => 'prompts/nova-marketplace-v1.txt']);

    $this->get(route('prompts.index'))->assertSee('Koşuda kullanılıyor');

    $this->from(route('prompts.index'))
        ->put(route('prompts.update', 'nova-marketplace-v1'), ['title' => 'Nova Marketplace V1', 'content' => 'A different prompt text entirely.'])
        ->assertSessionHasErrors('prompt');
    $this->from(route('prompts.index'))
        ->delete(route('prompts.destroy', 'nova-marketplace-v1'))
        ->assertSessionHasErrors('prompt');

    expect(File::get($this->root.'/prompts/nova-marketplace-v1.txt'))->toBe($this->text);
});

test('unknown or unsafe prompt keys are not found', function (string $key) {
    File::put($this->root.'/secret.txt', 'outside the prompts directory');

    $this->get(route('prompts.edit', $key))->assertNotFound();
})->with(['missing', 'kutuphane-999', '..secret']);

test('the runner preselects a prompt passed in the link', function () {
    File::put($this->root.'/prompts/aeron-commerce-v1.txt', $this->text);
    File::put($this->root.'/prompts/nova-marketplace-v1.txt', $this->text);

    $this->get(route('runner.create', ['prompt' => 'prompts/nova-marketplace-v1.txt']))
        ->assertOk()
        ->assertSee('<option value="prompts/nova-marketplace-v1.txt" selected>', false)
        ->assertSee(route('prompts.index'), false);
});
