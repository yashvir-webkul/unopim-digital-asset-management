<?php

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Webkul\AiAgent\Http\Client\AiApiClient;
use Webkul\DAM\Models\Directory;
use Webkul\DAM\Models\UploadTracker;
use Webkul\DataTransfer\Helpers\AbstractJob;
use Webkul\DataTransfer\Models\JobTrack;
use Webkul\MagicAI\Models\MagicAIPlatform;

beforeEach(function () {
    Storage::fake(Directory::getAssetDisk());

    config(['dam.ai_tagging.enabled' => true, 'dam.ai_tagging.platform_id' => null, 'dam.ai_tagging.max_tags' => 8]);

    MagicAIPlatform::create([
        'label'      => 'Test OpenAI',
        'provider'   => 'openai',
        'api_url'    => 'https://api.openai.com/v1',
        'api_key'    => 'test-key',
        'models'     => 'gpt-4o',
        'extras'     => [],
        'is_default' => true,
        'status'     => true,
    ]);

    $client = Mockery::mock(AiApiClient::class);
    $client->shouldReceive('configure')->andReturnSelf();
    $client->shouldReceive('chat')->andReturn(['content' => json_encode(['tags' => ['dune']])]);
    app()->instance(AiApiClient::class, $client);

    $name = 'AiGroup'.Str::random(6);
    Storage::disk(Directory::getAssetDisk())->makeDirectory('assets/'.$name);
    $this->directory = Directory::factory()->create(['name' => $name, 'parent_id' => null]);

    $this->baselineJobIds = aiTaggingJobTracks()->pluck('id')->all();
});

function aiTaggingJobTracks()
{
    return JobTrack::whereHas('jobInstance', fn ($q) => $q->where('code', 'dam_ai_tagging'));
}

function aiTaggingImages(int $count): array
{
    return array_map(fn (int $i) => UploadedFile::fake()->image("pic-{$i}.png", 20, 20), range(1, $count));
}

function newAiTaggingJobTracks(array $baseline)
{
    return aiTaggingJobTracks()->whereNotIn('id', $baseline)->get();
}

it('groups AI tagging of a multi-image admin upload into one Job Tracker entry', function () {
    $this->loginAsAdmin();

    $this->postJson(route('admin.dam.assets.upload'), [
        'files'        => aiTaggingImages(3),
        'directory_id' => $this->directory->id,
    ])->assertStatus(201);

    $jobs = newAiTaggingJobTracks($this->baselineJobIds);

    expect($jobs)->toHaveCount(1);
    expect($jobs->first()->state)->toBe(AbstractJob::STATE_COMPLETED);
    expect((int) $jobs->first()->processed_rows_count)->toBe(3);
});

it('groups AI tagging of a multi-image api upload into one Job Tracker entry', function () {
    $headers = $this->getAuthenticationHeaders();

    $this->withHeaders($headers)->post(route('admin.api.dam.assets.upload'), [
        'files'        => aiTaggingImages(3),
        'directory_id' => $this->directory->id,
    ])->assertStatus(201);

    $jobs = newAiTaggingJobTracks($this->baselineJobIds);

    expect($jobs)->toHaveCount(1);
    expect($jobs->first()->state)->toBe(AbstractJob::STATE_COMPLETED);
    expect((int) $jobs->first()->processed_rows_count)->toBe(3);
});

it('groups AI tagging of a session-less folder upload into one Job Tracker entry', function () {
    $this->loginAsAdmin();

    $this->postJson(route('admin.dam.assets.upload_folder'), [
        'files'          => aiTaggingImages(3),
        'relative_paths' => ['Shoot/pic-1.png', 'Shoot/pic-2.png', 'Shoot/pic-3.png'],
        'directory_id'   => $this->directory->id,
    ])->assertStatus(201);

    $jobs = newAiTaggingJobTracks($this->baselineJobIds);

    expect($jobs)->toHaveCount(1);
    expect($jobs->first()->state)->toBe(AbstractJob::STATE_COMPLETED);
    expect((int) $jobs->first()->processed_rows_count)->toBe(3);
});

it('keeps a single-image upload as one standalone Job Tracker entry without an extra tracker', function () {
    $this->loginAsAdmin();

    $trackersBefore = UploadTracker::count();

    $this->postJson(route('admin.dam.assets.upload'), [
        'files'        => aiTaggingImages(1),
        'directory_id' => $this->directory->id,
    ])->assertStatus(201);

    $jobs = newAiTaggingJobTracks($this->baselineJobIds);

    expect($jobs)->toHaveCount(1);
    expect($jobs->first()->state)->toBe(AbstractJob::STATE_COMPLETED);
    expect(UploadTracker::count())->toBe($trackersBefore);
});
