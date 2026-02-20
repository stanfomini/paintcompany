<?php

namespace App\Automation;

use App\Automation\Contracts\ActionInterface;
use App\Automation\Contracts\ConditionInterface;
use App\Automation\Contracts\DynamicValueInterface;
use App\Automation\Contracts\TriggerInterface;
use App\Automation\Contracts\WaitInterface;
use App\Models\WorkflowRun;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use ReflectionClass;

class Registry
{
    /** @var Collection<int, class-string<TriggerInterface>>|null */
    protected ?Collection $triggers = null;

    /** @var Collection<int, class-string<ConditionInterface>>|null */
    protected ?Collection $conditions = null;

    /** @var Collection<int, class-string<ActionInterface>>|null */
    protected ?Collection $actions = null;

    /** @var Collection<int, class-string<WaitInterface>>|null */
    protected ?Collection $waits = null;

    /** @var Collection<int, class-string<DynamicValueInterface>>|null */
    protected ?Collection $dynamicValues = null;

    /**
     * @return Collection<int, class-string<TriggerInterface>>
     */
    public function triggers(): Collection
    {
        if ($this->triggers === null) {
            $this->triggers = $this->discoverClasses(TriggerInterface::class);
        }

        return $this->triggers;
    }

    /**
     * @return Collection<int, class-string<ConditionInterface>>
     */
    public function conditions(): Collection
    {
        if ($this->conditions === null) {
            $this->conditions = $this->discoverClasses(ConditionInterface::class);
        }

        return $this->conditions;
    }

    /**
     * @return Collection<int, class-string<ActionInterface>>
     */
    public function actions(): Collection
    {
        if ($this->actions === null) {
            $this->actions = $this->discoverClasses(ActionInterface::class);
        }

        return $this->actions;
    }

    /**
     * @return Collection<int, class-string<WaitInterface>>
     */
    public function waits(): Collection
    {
        if ($this->waits === null) {
            $this->waits = $this->discoverClasses(WaitInterface::class);
        }

        return $this->waits;
    }

    /**
     * @return Collection<int, class-string<DynamicValueInterface>>
     */
    public function dynamicValues(): Collection
    {
        if ($this->dynamicValues === null) {
            $this->dynamicValues = $this->discoverClasses(DynamicValueInterface::class);
        }

        return $this->dynamicValues;
    }

    /**
     * Get all discovered capabilities grouped by type.
     *
     * @return array{
     *     triggers: Collection<int, class-string<TriggerInterface>>,
     *     conditions: Collection<int, class-string<ConditionInterface>>,
     *     actions: Collection<int, class-string<ActionInterface>>,
     *     waits: Collection<int, class-string<WaitInterface>>,
     *     dynamic_values: Collection<int, class-string<DynamicValueInterface>>
     * }
     */
    public function all(): array
    {
        return [
            'triggers' => $this->triggers(),
            'conditions' => $this->conditions(),
            'actions' => $this->actions(),
            'waits' => $this->waits(),
            'dynamic_values' => $this->dynamicValues(),
        ];
    }

    /**
     * Find and resolve a trigger implementation by class name.
     *
     * @param  class-string<TriggerInterface>  $class
     */
    public function findTrigger(string $class): ?TriggerInterface
    {
        if (! $this->triggers()->contains($class)) {
            return null;
        }

        return app($class);
    }

    /**
     * Find and resolve a condition implementation by class name.
     *
     * @param  class-string<ConditionInterface>  $class
     */
    public function findCondition(string $class): ?ConditionInterface
    {
        if (! $this->conditions()->contains($class)) {
            return null;
        }

        return app($class);
    }

    /**
     * Find and resolve an action implementation by class name.
     *
     * @param  class-string<ActionInterface>  $class
     */
    public function findAction(string $class): ?ActionInterface
    {
        if (! $this->actions()->contains($class)) {
            return null;
        }

        return app($class);
    }

    /**
     * Find and resolve a wait implementation by class name.
     *
     * @param  class-string<WaitInterface>  $class
     */
    public function findWait(string $class): ?WaitInterface
    {
        if (! $this->waits()->contains($class)) {
            return null;
        }

        return app($class);
    }

    /**
     * Find and resolve a dynamic value resolver by its prefix.
     */
    public function findDynamicValue(string $prefix): ?DynamicValueInterface
    {
        $class = $this->dynamicValues()->first(function (string $className) use ($prefix) {
            return $className::getPrefix() === $prefix;
        });

        if (! $class) {
            return null;
        }

        return app($class);
    }

    /**
     * Get all available dynamic fields for a given trigger class.
     *
     * @param  class-string<TriggerInterface>  $triggerClass
     * @return array<int, array{prefix: string, key: string, path: string, label: string}>
     */
    public function getAvailableFieldsForTrigger(string $triggerClass): array
    {
        if (! is_a($triggerClass, TriggerInterface::class, true)) {
            return [];
        }

        $prefixes = $triggerClass::getAvailableDynamicPrefixes();
        $fields = [];

        foreach ($prefixes as $prefix) {
            $resolver = $this->findDynamicValue($prefix);

            if (! $resolver) {
                continue;
            }

            foreach ($resolver::getAvailableKeys() as $key) {
                $fields[] = [
                    'prefix' => $prefix,
                    'key' => $key,
                    'path' => "{$prefix}.{$key}",
                    'label' => Str::title(str_replace('_', ' ', $prefix)).': '.Str::title(str_replace('_', ' ', $key)),
                ];
            }
        }

        return $fields;
    }

    /**
     * Resolve {{variable}} placeholders in a template string using dynamic value resolvers.
     */
    public function resolveDynamicValues(string $template, WorkflowRun $run): string
    {
        return preg_replace_callback('/\{\{(\w+)\.([\w.]+)\}\}/', function (array $matches) use ($run): string {
            $prefix = $matches[1];
            $key = $matches[2];
            $resolver = $this->findDynamicValue($prefix);

            return $resolver ? ($resolver->resolve($key, $run) ?? '') : $matches[0];
        }, $template);
    }

    /**
     * Discover all concrete classes implementing the given interface within the Automation directory.
     *
     * @param  class-string  $interface
     * @return Collection<int, class-string>
     */
    protected function discoverClasses(string $interface): Collection
    {
        $automationPath = app_path('Automation');

        if (! File::isDirectory($automationPath)) {
            return collect();
        }

        return collect(File::allFiles($automationPath))
            ->filter(fn ($file) => $file->getExtension() === 'php')
            ->map(function ($file) {
                $relativePath = str_replace(
                    ['/', '.php'],
                    ['\\', ''],
                    $file->getRelativePathname()
                );

                return 'App\\Automation\\'.$relativePath;
            })
            ->filter(function (string $className) use ($interface): bool {
                if (! class_exists($className)) {
                    return false;
                }

                $reflection = new ReflectionClass($className);

                return $reflection->implementsInterface($interface)
                    && ! $reflection->isAbstract()
                    && ! $reflection->isInterface();
            })
            ->values();
    }
}
