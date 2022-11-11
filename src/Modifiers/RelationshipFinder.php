<?php

namespace SimonHamp\LaravelNovaCsvImport\Modifiers;

use Illuminate\Container\Container;
use Illuminate\Database\Eloquent\Model;
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
        $availableModels = $this->getAvailableModels()->toArray();
        Log::critical('Models', $availableModels);
        return [
            'model' => [
                'type' => 'select',
                'options' => $availableModels,
            ],
        ];
    }

    public function handle($value = null, array $settings = []): Collection
    {
        $toMatch = [];

        if (str_contains($value, ',')) {
            $toMatch = explode(',', $value);
        } else {
            $toMatch = explode(';', $value);
        }

        $findModel = $settings['model'];
        if (!class_exists($findModel)) {
            throw new \RuntimeException("Cannot find model $findModel");
        }

        /** @var Model $findModel */

        if (function_exists("$findModel::search")) {
            $matchedRecords = $findModel::search($toMatch);
        } else {
            $matchedRecords = $findModel::query()->whereIn('name', $toMatch)->get();
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
}
