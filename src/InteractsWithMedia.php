<?php

namespace Spatie\Medialibrary;

use DateTimeInterface;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Http\File;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Spatie\Medialibrary\Conversions\Conversion;
use Spatie\Medialibrary\MediaCollections\Events\CollectionHasBeenCleared;
use Spatie\Medialibrary\MediaCollections\Exceptions\InvalidBase64Data;
use Spatie\Medialibrary\MediaCollections\Exceptions\MimeTypeNotAllowed;
use Spatie\Medialibrary\MediaCollections\Exceptions\UnreachableUrl;
use Spatie\Medialibrary\MediaCollections\Exceptions\MediaCannotBeDeleted;
use Spatie\Medialibrary\MediaCollections\Exceptions\MediaCannotBeUpdated;
use Spatie\Medialibrary\MediaCollections\FileAdder;
use Spatie\Medialibrary\MediaCollections\FileAdderFactory;
use Spatie\Medialibrary\MediaCollections\MediaCollection;
use Spatie\Medialibrary\MediaCollections\MediaRepository;
use Spatie\Medialibrary\MediaCollections\Models\Media;

trait InteractsWithMedia
{
    /** @var Conversion[] */
    public array $mediaConversions = [];

    /** @var MediaCollection[] */
    public array $mediaCollections = [];

    protected bool $deletePreservingMedia = false;

    protected array $unAttachedMedialibraryItems = [];

    public static function bootInteractsWithMedia()
    {
        static::deleting(function (HasMedia $entity) {
            if ($entity->shouldDeletePreservingMedia()) {
                return;
            }

            if (in_array(SoftDeletes::class, class_uses_recursive($entity))) {
                if (! $entity->forceDeleting) {
                    return;
                }
            }

            $entity->media()->get()->each->delete();
        });
    }

    public function media(): MorphMany
    {
        return $this->morphMany(config('medialibrary.media_model'), 'model');
    }

    /**
     * Add a file to the medialibrary.
     *
     * @param string|\Symfony\Component\HttpFoundation\File\UploadedFile $file
     *
     * @return \Spatie\Medialibrary\MediaCollections\FileAdder
     */
    public function addMedia($file): FileAdder
    {
        return app(FileAdderFactory::class)->create($this, $file);
    }

    /**
     * Add a file from the given disk.
     *
     * @param string $key
     * @param string $disk
     *
     * @return \Spatie\Medialibrary\MediaCollections\FileAdder
     */
    public function addMediaFromDisk(string $key, string $disk = null): FileAdder
    {
        return app(FileAdderFactory::class)->createFromDisk($this, $key, $disk ?: config('filesystems.default'));
    }

    /**
     * Add a file from a request.
     *
     * @param string $key
     *
     * @return \Spatie\Medialibrary\MediaCollections\FileAdder
     */
    public function addMediaFromRequest(string $key): FileAdder
    {
        return app(FileAdderFactory::class)->createFromRequest($this, $key);
    }

    /**
     * Add multiple files from a request by keys.
     *
     * @param string[] $keys
     *
     * @return \Spatie\Medialibrary\MediaCollections\FileAdder[]
     */
    public function addMultipleMediaFromRequest(array $keys)
    {
        return app(FileAdderFactory::class)->createMultipleFromRequest($this, $keys);
    }

    /**
     * Add all files from a request.
     *
     * @return \Spatie\Medialibrary\MediaCollections\FileAdder[]
     */
    public function addAllMediaFromRequest(): Collection
    {
        return app(FileAdderFactory::class)->createAllFromRequest($this);
    }

    /**
     * Add a remote file to the media library.
     *
     * @param string $url
     * @param string|array ...$allowedMimeTypes
     *
     * @return \Spatie\Medialibrary\MediaCollections\FileAdder
     *
     * @throws \Spatie\Medialibrary\MediaCollections\Exceptions\FileCannotBeAdded
     */
    public function addMediaFromUrl(string $url, ...$allowedMimeTypes): FileAdder
    {
        if (! $stream = @fopen($url, 'r')) {
            throw UnreachableUrl::create($url);
        }

        $temporaryFile = tempnam(sys_get_temp_dir(), 'media-library');
        file_put_contents($temporaryFile, $stream);

        $this->guardAgainstInvalidMimeType($temporaryFile, $allowedMimeTypes);

        $filename = basename(parse_url($url, PHP_URL_PATH));
        $filename = str_replace('%20', ' ', $filename);

        if ($filename === '') {
            $filename = 'file';
        }

        $mediaExtension = explode('/', mime_content_type($temporaryFile));

        if (! Str::contains($filename, '.')) {
            $filename = "{$filename}.{$mediaExtension[1]}";
        }

        return app(FileAdderFactory::class)
            ->create($this, $temporaryFile)
            ->usingName(pathinfo($filename, PATHINFO_FILENAME))
            ->usingFileName($filename);
    }

    /**
     * Add a base64 encoded file to the medialibrary.
     *
     * @param string $base64data
     * @param string|array ...$allowedMimeTypes
     *
     * @return \Spatie\Medialibrary\MediaCollections\FileAdder
     * @throws \Spatie\Medialibrary\MediaCollections\Exceptions\FileCannotBeAdded
     *
     * @throws InvalidBase64Data
     */
    public function addMediaFromBase64(string $base64data, ...$allowedMimeTypes): FileAdder
    {
        // strip out data uri scheme information (see RFC 2397)
        if (strpos($base64data, ';base64') !== false) {
            [$_, $base64data] = explode(';', $base64data);
            [$_, $base64data] = explode(',', $base64data);
        }

        // strict mode filters for non-base64 alphabet characters
        if (base64_decode($base64data, true) === false) {
            throw InvalidBase64Data::create();
        }

        // decoding and then reencoding should not change the data
        if (base64_encode(base64_decode($base64data)) !== $base64data) {
            throw InvalidBase64Data::create();
        }

        $binaryData = base64_decode($base64data);

        // temporarily store the decoded data on the filesystem to be able to pass it to the fileAdder
        $tmpFile = tempnam(sys_get_temp_dir(), 'medialibrary');
        file_put_contents($tmpFile, $binaryData);

        $this->guardAgainstInvalidMimeType($tmpFile, $allowedMimeTypes);

        $file = app(FileAdderFactory::class)->create($this, $tmpFile);

        return $file;
    }

    /**
     * Copy a file to the medialibrary.
     *
     * @param string|\Symfony\Component\HttpFoundation\File\UploadedFile $file
     *
     * @return \Spatie\Medialibrary\MediaCollections\FileAdder
     */
    public function copyMedia($file): FileAdder
    {
        return $this->addMedia($file)->preservingOriginal();
    }

    /*
     * Determine if there is media in the given collection.
     */
    public function hasMedia(string $collectionName = 'default'): bool
    {
        return count($this->getMedia($collectionName)) ? true : false;
    }

    /**
     * Get media collection by its collectionName.
     *
     * @param string $collectionName
     * @param array|callable $filters
     *
     * @return \Illuminate\Support\Collection
     */
    public function getMedia(string $collectionName = 'default', $filters = []): Collection
    {
        return app(MediaRepository::class)->getCollection($this, $collectionName, $filters);
    }

    public function getFirstMedia(string $collectionName = 'default', array $filters = []): ?Media
    {
        $media = $this->getMedia($collectionName, $filters);

        return $media->first();
    }

    /*
     * Get the url of the image for the given conversionName
     * for first media for the given collectionName.
     * If no profile is given, return the source's url.
     */
    public function getFirstMediaUrl(string $collectionName = 'default', string $conversionName = ''): string
    {
        $media = $this->getFirstMedia($collectionName);

        if (! $media) {
            return $this->getFallbackMediaUrl($collectionName) ?: '';
        }

        return $media->getUrl($conversionName);
    }

    /*
     * Get the url of the image for the given conversionName
     * for first media for the given collectionName.
     *
     * If no profile is given, return the source's url.
     */
    public function getFirstTemporaryUrl(DateTimeInterface $expiration, string $collectionName = 'default', string $conversionName = ''): string
    {
        $media = $this->getFirstMedia($collectionName);

        if (! $media) {
            return $this->getFallbackMediaUrl($collectionName) ?: '';
        }

        return $media->getTemporaryUrl($expiration, $conversionName);
    }

    public function getMediaCollection(string $collectionName = 'default'): ?MediaCollection
    {
        $this->registerMediaCollections();

        return collect($this->mediaCollections)
            ->first(fn(MediaCollection $collection) => $collection->name === $collectionName);
    }

    public function getFallbackMediaUrl(string $collectionName = 'default'): string
    {
        return optional($this->getMediaCollection($collectionName))->fallbackUrl ?? '';
    }

    public function getFallbackMediaPath(string $collectionName = 'default'): string
    {
        return optional($this->getMediaCollection($collectionName))->fallbackPath ?? '';
    }

    /*
     * Get the url of the image for the given conversionName
     * for first media for the given collectionName.
     * If no profile is given, return the source's url.
     */
    public function getFirstMediaPath(string $collectionName = 'default', string $conversionName = ''): string
    {
        $media = $this->getFirstMedia($collectionName);

        if (! $media) {
            return $this->getFallbackMediaPath($collectionName) ?: '';
        }

        return $media->getPath($conversionName);
    }

    /**
     * Update a media collection by deleting and inserting again with new values.
     *
     * @param array $newMediaArray
     * @param string $collectionName
     *
     * @return \Illuminate\Support\Collection
     *
     * @throws \Spatie\Medialibrary\MediaCollections\Exceptions\MediaCannotBeUpdated
     */
    public function updateMedia(array $newMediaArray, string $collectionName = 'default'): Collection
    {
        $this->removeMediaItemsNotPresentInArray($newMediaArray, $collectionName);

        $mediaClass = config('medialibrary.media_model');
        $mediaInstance = new $mediaClass();
        $keyName = $mediaInstance->getKeyName();

        return collect($newMediaArray)
            ->map(function (array $newMediaItem) use ($collectionName, $mediaClass, $keyName) {
                static $orderColumn = 1;

                $currentMedia = $mediaClass::findOrFail($newMediaItem[$keyName]);

                if ($currentMedia->collection_name !== $collectionName) {
                    throw MediaCannotBeUpdated::doesNotBelongToCollection($collectionName, $currentMedia);
                }

                if (array_key_exists('name', $newMediaItem)) {
                    $currentMedia->name = $newMediaItem['name'];
                }

                if (array_key_exists('custom_properties', $newMediaItem)) {
                    $currentMedia->custom_properties = $newMediaItem['custom_properties'];
                }

                $currentMedia->order_column = $orderColumn++;

                $currentMedia->save();

                return $currentMedia;
            });
    }

    protected function removeMediaItemsNotPresentInArray(array $newMediaArray, string $collectionName = 'default'): void
    {
        $this->getMedia($collectionName)
            ->reject(fn(Media $currentMediaItem) => in_array($currentMediaItem->getKey(), array_column($newMediaArray, $currentMediaItem->getKeyName())))
            ->each->delete();
    }

    public function clearMediaCollection(string $collectionName = 'default'): self
    {
        $this->getMedia($collectionName)
            ->each->delete();

        event(new CollectionHasBeenCleared($this, $collectionName));

        if ($this->mediaIsPreloaded()) {
            unset($this->media);
        }

        return $this;
    }

    /**
     * Remove all media in the given collection except some.
     *
     * @param string $collectionName
     * @param \Spatie\Medialibrary\MediaCollections\Models\Media[]|\Illuminate\Support\Collection $excludedMedia
     *
     * @return $this
     */
    public function clearMediaCollectionExcept(string $collectionName = 'default', $excludedMedia = []): self
    {
        if ($excludedMedia instanceof Media) {
            $excludedMedia = collect()->push($excludedMedia);
        }

        $excludedMedia = collect($excludedMedia);

        if ($excludedMedia->isEmpty()) {
            return $this->clearMediaCollection($collectionName);
        }

        $this->getMedia($collectionName)
            ->reject(fn(Media $media) => $excludedMedia->where($media->getKeyName(), $media->getKey())->count())
            ->each->delete();

        if ($this->mediaIsPreloaded()) {
            unset($this->media);
        }

        if ($this->getMedia($collectionName)->isEmpty()) {
            event(new CollectionHasBeenCleared($this, $collectionName));
        }

        return $this;
    }

    /**
     * Delete the associated media with the given id.
     * You may also pass a media object.
     *
     * @param int|\Spatie\Medialibrary\MediaCollections\Models\Media $mediaId
     *
     * @throws \Spatie\Medialibrary\MediaCollections\Exceptions\MediaCannotBeDeleted
     */
    public function deleteMedia($mediaId): void
    {
        if ($mediaId instanceof Media) {
            $mediaId = $mediaId->getKey();
        }

        $media = $this->media->find($mediaId);

        if (! $media) {
            throw MediaCannotBeDeleted::doesNotBelongToModel($mediaId, $this);
        }

        $media->delete();
    }

    public function addMediaConversion(string $name): Conversion
    {
        $conversion = Conversion::create($name);

        $this->mediaConversions[] = $conversion;

        return $conversion;
    }

    public function addMediaCollection(string $name): MediaCollection
    {
        $mediaCollection = MediaCollection::create($name);

        $this->mediaCollections[] = $mediaCollection;

        return $mediaCollection;
    }

    public function deletePreservingMedia(): bool
    {
        $this->deletePreservingMedia = true;

        return $this->delete();
    }

    public function shouldDeletePreservingMedia(): bool
    {
        return $this->deletePreservingMedia ?? false;
    }

    protected function mediaIsPreloaded(): bool
    {
        return $this->relationLoaded('media');
    }

    /**
     * Cache the media on the object.
     *
     * @param string $collectionName
     *
     * @return mixed
     */
    public function loadMedia(string $collectionName)
    {
        $collection = $this->exists
            ? $this->media
            : collect($this->unAttachedMedialibraryItems)->pluck('media');

        return $collection
            ->filter(fn(Media $mediaItem) => $mediaItem->collection_name === $collectionName)
            ->sortBy('order_column')
            ->values();
    }

    public function prepareToAttachMedia(Media $media, FileAdder $fileAdder): void
    {
        $this->unAttachedMedialibraryItems[] = compact('media', 'fileAdder');
    }

    public function processUnattachedMedia(callable $callable): void
    {
        foreach ($this->unAttachedMedialibraryItems as $item) {
            $callable($item['media'], $item['fileAdder']);
        }

        $this->unAttachedMedialibraryItems = [];
    }

    protected function guardAgainstInvalidMimeType(string $file, ...$allowedMimeTypes)
    {
        $allowedMimeTypes = Arr::flatten($allowedMimeTypes);

        if (empty($allowedMimeTypes)) {
            return;
        }

        $validation = Validator::make(
            ['file' => new File($file)],
            ['file' => 'mimetypes:'.implode(',', $allowedMimeTypes)]
        );

        if ($validation->fails()) {
            throw MimeTypeNotAllowed::create($file, $allowedMimeTypes);
        }
    }

    public function registerMediaConversions(Media $media = null): void
    {
    }

    public function registerMediaCollections(): void
    {
    }

    public function registerAllMediaConversions(Media $media = null): void
    {
        $this->registerMediaCollections();

        collect($this->mediaCollections)->each(function (MediaCollection $mediaCollection) use ($media) {
            $actualMediaConversions = $this->mediaConversions;

            $this->mediaConversions = [];

            ($mediaCollection->mediaConversionRegistrations)($media);

            $preparedMediaConversions = collect($this->mediaConversions)
                ->each(function (Conversion $conversion) use ($mediaCollection) {
                    $conversion->performOnCollections($mediaCollection->name);
                })
                ->values()
                ->toArray();

            $this->mediaConversions = [...$actualMediaConversions, ...$preparedMediaConversions];
        });

        $this->registerMediaConversions($media);
    }
}
