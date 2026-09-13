<?php

namespace ArieTimmerman\Laravel\SCIMServer\Attribute;

use ArieTimmerman\Laravel\SCIMServer\Exceptions\SCIMException;
use ArieTimmerman\Laravel\SCIMServer\Parser\Path;
use Illuminate\Database\Eloquent\Model;

class MutableCollection extends Collection
{
    public function add($value, Model &$object)
    {
        $values = collect($value)->pluck('value')->all();

        $related = $object->{$this->attribute}()->getRelated();

        // Check if objects exist. Keys only: findMany() hydrates a full model
        // per id for a check that never looks past the key.
        $existingObjectIds = $related->newQuery()
            ->whereKey($values)
            ->pluck($related->getKeyName());

        if (($diff = collect($values)->diff($existingObjectIds))->count() > 0) {
            throw new SCIMException(
                sprintf('One or more %s are unknown: %s', $this->attribute, implode(',', $diff->all())),
                500
            );
        }

        // Attach only what is missing. syncWithoutDetaching() routes through
        // sync(), which reads and hydrates every currently attached pivot row
        // no matter how many ids this call touches.
        $alreadyAttached = $object->{$this->attribute}()
            ->whereKey($values)
            ->pluck($related->getQualifiedKeyName());

        $object->{$this->attribute}()->attach($existingObjectIds->diff($alreadyAttached)->all());

        // Drop the stale relation rather than eagerly reloading it: load()
        // rebuilds a model per member even when nothing reads them again.
        $object->unsetRelation($this->attribute);
    }

    public function remove($value, Model &$object, ?Path $path = null)
    {
        if ($path?->getValuePathFilter()?->getComparisonExpression() != null) {
            $attributes = $path?->getValuePathFilter()?->getComparisonExpression()?->attributePath?->attributeNames;
            $operator = $path?->getValuePathFilter()?->getComparisonExpression()?->operator;
            $compareValue = $path?->getValuePathFilter()?->getComparisonExpression()?->compareValue;
            
            if ($value != null) {
                throw new SCIMException('Remove operation with filter requires a null value parameter', 400);
            }

            if (count($attributes) != 1) {
                throw new SCIMException(sprintf('Filter must specify exactly one attribute, found %d attributes', count($attributes)), 400);
            }

            if ($operator != 'eq') {
                throw new SCIMException(sprintf('Unsupported filter operator "%s" - only "eq" is supported', $operator), 400);
            }

            if ($attributes[0] != 'value') {
                throw new SCIMException(sprintf('Cannot filter on "%s" - only filtering on "value" attribute is supported', $attributes[0]), 400);
            }

            if ($value != null) {
                throw new SCIMException('Cannot specify both a filter path and a value parameter simultaneously', 400);
            }

            $object->{$this->attribute}()->detach([$compareValue]);
        } else {
            $values = collect($value)->pluck('value')->all();

            $object->{$this->attribute}()->detach($values);
        }

        // Both detach() calls above are already bounded by the ids being
        // removed; load() then re-reads the entire remaining membership as
        // models. Drop the stale relation instead.
        $object->unsetRelation($this->attribute);
    }

    public function replace($value, Model &$object, ?Path $path = null)
    {
        $values = collect($value)->pluck('value')->all();

        // Check if objects exist
        $existingObjects = $object
        ->{$this->attribute}()
        ->getRelated()
        ::findMany($values);
        $existingObjectIds = $existingObjects
            ->map(fn ($o) => $o->getKey());

        if (($diff = collect($values)->diff($existingObjectIds))->count() > 0) {
            throw new SCIMException(
                sprintf('One or more %s are unknown: %s', $this->attribute, implode(',', $diff->all())),
                500
            );
        }

        // Act like the relation is already saved. This allows running validations, if needed.
        $object->setRelation($this->attribute, $existingObjects);

        $object->saved(function (Model $model) use ($object, $existingObjectIds)  {
            // Save relationships only after the model is saved. Essential if the model is new.
            // Intentionlly `$object` is used instead of `$model`, to avoid accidentially updating the wrong model.
            $object->{$this->attribute}()->sync($existingObjectIds->all());    
        });
        
    }

    public function patch($operation, $value, Model &$object, ?Path $path = null)
    {
        if ($operation == 'add') {
            $this->add($value, $object);
        } elseif ($operation == 'remove') {
            $this->remove($value, $object, $path);
        } elseif ($operation == 'replace') {
            $this->replace($value, $object, $path);
        } else {
            throw new SCIMException('Operation not supported: ' . $operation);
        }
    }
}
