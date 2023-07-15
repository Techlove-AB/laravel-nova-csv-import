<?php

namespace SimonHamp\LaravelNovaCsvImport;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\Relations\MorphToMany;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Laravel\Nova\Resource;
use Maatwebsite\Excel\Concerns\Importable;
use Maatwebsite\Excel\Concerns\SkipsEmptyRows;
use Maatwebsite\Excel\Concerns\SkipsErrors;
use Maatwebsite\Excel\Concerns\SkipsFailures;
use Maatwebsite\Excel\Concerns\SkipsOnError;
use Maatwebsite\Excel\Concerns\SkipsOnFailure;
use Maatwebsite\Excel\Concerns\ToModel;
use Maatwebsite\Excel\Concerns\WithBatchInserts;
use Maatwebsite\Excel\Concerns\WithChunkReading;
use Maatwebsite\Excel\Concerns\WithHeadingRow;
use Maatwebsite\Excel\Concerns\WithMapping;
use Maatwebsite\Excel\Concerns\WithUpserts;
use Maatwebsite\Excel\Concerns\WithValidation;
use ReflectionClass;
use SimonHamp\LaravelNovaCsvImport\Concerns\HasModifiers;

class Importer implements
    ToModel,
    WithValidation,
    WithHeadingRow,
    WithMapping,
    WithBatchInserts,
    WithChunkReading,
    SkipsOnFailure,
    SkipsOnError,
    SkipsEmptyRows,
    WithUpserts
{
    use SkipsFailures;
    use SkipsErrors;
    use HasModifiers;
    use Importable {
        Importable::toCollection as toCollectionOld;
    }

    /** @var Resource */
    protected $resource;

    protected $attribute_map = [];

    protected $rules;

    protected string $model_class;

    protected $meta_values = [];

    protected $custom_values = [];

    public function __construct()
    {
        $this->bootHasModifiers();
    }

    public function map($row): array
    {
        if (empty($this->attribute_map)) {
            return $row;
        }

        $data = [];

        $model = $this->getModel();

        foreach ($this->attribute_map as $attribute => $column) {
            if (! $column) {
                continue;
            }

            $value = $this->modifyValue(
                $this->getFieldValue($row, $column, $attribute),
                $this->getModifiers($attribute)
            );


            $data[$attribute] = $value;
        }

        return $data;
    }

    /**
     * Convert the row to a model
     *
     * @param array<string,mixed> $row
     */
    public function model(array $row): Model
    {
        /** @var Model */
        $model = $this->resource::newModel();
        $collections = [];

        foreach ($row as $key => $value) {
            if ($value instanceof Collection) {
                $collections[$key] = $value;
                unset($row[$key]);
            }
        }

        $model->fill($row);
        foreach ($collections as $key => $collection) {
            if (!$collection->isEmpty()) {
                $this->handleCollection($model, $key, $collection, true);
            }
        }

        // Because of what we're about to do with the relationships, we need to make
        // sure that the record is saved.
        if (!$model->exists) {
            $model->save();
            $model->refresh();
        }

        if (!empty($collections)) {
            /** @var Collection[] $collections */
            foreach ($collections as $key => $collection) {
                if (!$collection->isEmpty()) {
                    $this->handleCollection($model, $key, $collection);
                }
            }
        }


        return $model;
    }

    public function rules(): array
    {
        return $this->rules;
    }

    public function batchSize(): int
    {
        return 100;
    }

    public function chunkSize(): int
    {
        return 100;
    }

    public function getAttributeMap(): array
    {
        return $this->attribute_map;
    }

    public function setAttributeMap(array $map): self
    {
        $this->attribute_map = $map;

        return $this;
    }

    public function getMeta($key = null)
    {
        if ($key && ! empty($this->meta_values[$key])) {
            return $this->meta_values[$key];
        }

        return $this->meta_values;
    }

    public function setMeta(array $meta): self
    {
        $this->meta_values = $meta;

        return $this;
    }

    public function getCustomValues($key = null)
    {
        if ($key) {
            return $this->custom_values[$key] ?? '';
        }

        return $this->custom_values;
    }

    public function setCustomValues(array $map): self
    {
        $this->custom_values = $map;

        return $this;
    }

    public function setRules(array $rules): self
    {
        $this->rules = $rules;

        return $this;
    }

    public function getModelClass(): string
    {
        return $this->model_class;
    }

    public function setModelClass(string $model_class): self
    {
        $this->model_class = $model_class;

        return $this;
    }

    public function getModel(): Model
    {
        /** @var Model */
        $model = $this->resource::newModel();

        return $model;
    }

    public function setResource(Resource $resource): self
    {
        $this->resource = $resource;

        return $this;
    }

    public function uniqueBy(): string
    {
        return 'id';
    }

    protected function handleCollection(
        Model $model,
        string $key,
        Collection $collection,
        bool $beforeSave = false
    ): void {
        if (!method_exists($model, $key)) {
            return;
        }

        $returnType = (new ReflectionClass($model))->getMethod($key)->getReturnType();
        if (!method_exists($returnType, 'getName')) {
            return;
        }

        $returnType->getName();

        switch ($returnType->getName()) {
            case HasMany::class:
            case BelongsToMany::class:
            case MorphMany::class:
            case MorphToMany::class:
                if (!$beforeSave) {
                    $model->$key()->attach($collection);
                }
                break;
            case BelongsTo::class:
            case MorphTo::class:
                if ($beforeSave) {
                    $model->$key()->associate($collection->first());
                }
                break;
            default:
                if (!$beforeSave) {
                    $model->$key()->associate($collection->first());
                }
                break;
        }
    }

    protected function getFieldValue(array $row, string $mapping, string $attribute)
    {
        if (array_key_exists($mapping, $row)) {
            return $row[$mapping];
        } elseif (Str::startsWith($mapping, 'meta')) {
            return $this->getMeta(Str::remove('@meta.', "@{$mapping}"));
        } elseif ($mapping === 'custom') {
            return $this->getCustomValues($attribute);
        }
    }

    /**
     * @param  string|UploadedFile|null  $filePath
     * @param  string|null  $disk
     * @param  string|null  $readerType
     * @return Collection
     *
     * @throws NoFilePathGivenException
     */
    public function toCollection($filePath = null, string $disk = null, string $readerType = null): Collection
    {
        $filePath = $this->getFilePath($filePath);

        $collection = $this->getImporter()->toCollection(
            $this,
            $filePath,
            $disk ?? $this->disk ?? null,
            $readerType ?? $this->readerType ?? null
        );

        return $collection;
    }
}
