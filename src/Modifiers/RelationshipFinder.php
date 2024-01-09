<?php

namespace SimonHamp\LaravelNovaCsvImport\Modifiers;

use Illuminate\Container\Container;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str as LaravelStr;
use Log;
use SimonHamp\LaravelNovaCsvImport\Contracts\Modifier;
use Str;

class RelationshipFinder implements Modifier
{
    public function title(): string
    {
        return 'Relationship finder';
    }

    public function description(): string
    {
        return "Match relationship on name";
    }

    public function settings(): array
    {
        $availableModels = ['' => ''] + $this->getAvailableModels()->toArray();
        return [
            'model' => [
                'type' => 'select',
                'options' => $availableModels,
            ],
            'single' => [
                'type' => 'select',
                'options' => [
                    'true' => __('Yes'),
                    'false' => __('No'),
                ],
                'default' => 'true',
            ],
        ];
    }

    public function handle($value = null, array $settings = []): Collection|Model
    {
        $toMatch = [];

        if (str_contains($value, ',')) {
            $toMatch = explode(',', $value);
        } else {
            $toMatch = explode(';', $value);
        }

        $findModel = $settings['model'] ?? $this->getBestMatchingModel($settings);
        if (!class_exists($findModel)) {
            throw new \RuntimeException("Cannot find model $findModel");
        }

        $single = $settings['single'] ?? false;
        
        /** @var Model $findModel */

        if (method_exists($findModel, 'search')) {
            $matchedRecords = $findModel::search($single == true ? $value : $toMatch);
        } else {
            $matchedRecords = $single == true ?
                $findModel::query()->whereIn('name', $value)->first() :
                $findModel::query()->whereIn('name', $toMatch)->get();
        }

        return $matchedRecords ?? collect([]);
    }

    public function getAvailableModels(): Collection
    {
        $models = collect(File::allFiles(app_path()))
        ->map(function ($item) {
            $path = $item->getRelativePathName();
            $class = sprintf(
                '\%s%s',
                Container::getInstance()->getNamespace(),
                strtr(substr($path, 0, strrpos($path, '.')), '/', '\\')
            );

            return $class;
        })
        ->filter(function ($class) {
            $valid = false;

            if (class_exists($class)) {
                $reflection = new \ReflectionClass($class);
                $valid = $reflection->isSubclassOf(Model::class) &&
                !$reflection->isAbstract();
            }

            return $valid;
        });
        $keys = $models->values()->toArray();
        $values = $models->values()->map(fn($value) => Str::afterLast($value, '\\'))->toArray();

        return collect(array_combine(
            $keys,
            $values
        ));
    }

    protected function getBestMatchingModel(array $settings): string
    {
        if (
            !isset($settings['for_model'])
            || !isset($settings['key'])
            || !method_exists($settings['for_model'], $settings['key'])
        ) {
            $this->cannotFindSearchRel();
        }

        /** @var Relation */
        $relationship = $settings['for_model']->{$settings['key']}();

        $related = $relationship->getRelated();

        return $related::class;
    }

    protected function cannotFindSearchRel(): void
    {
        throw new \RuntimeException('You must specify the model to find the relationship for');
    }
}
