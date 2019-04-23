<?php

namespace hamburgscleanest\NovaPolymorphicField;

use Laravel\Nova\Fields\Field;
use Laravel\Nova\Http\Requests\NovaRequest;
use Illuminate\Database\Eloquent\Relations\Relation;

/**
 * Class PolymorphicField
 * @package hamburgscleanest\NovaPolymorphicField
 */
class PolymorphicField extends Field
{

    /**
     * The field's component.
     *
     * @var string
     */
    public $component = 'polymorphic-field';

    /**
     * PolymorphicField constructor.
     *
     * @param string $name
     * @param string|null $attribute
     */
    public function __construct(string $name, string $attribute = null)
    {
        parent::__construct($name, $attribute);

        $this
            ->withMeta(['types' => []])
            ->displayUsing(function($value)
            {
                foreach ($this->meta['types'] as $type)
                {
                    if ($this->mapToKey($type['value']) === $value)
                    {
                        return $type['label'];
                    }
                }

                return null;
            });
    }

    /**
     * @param string $typeClass
     * @param string $label
     * @param array $fields
     * @return PolymorphicField
     */
    public function type(string $label, string $typeClass, array $fields) : PolymorphicField
    {
        $this->meta['types'][] = [
            'value'  => $typeClass,
            'label'  => $label,
            'fields' => $fields
        ];

        return $this;
    }

    /**
     * @param object $model
     * @param string|null $attribute
     */
    public function resolveForDisplay($model, $attribute = null) : void
    {
        parent::resolveForDisplay($model, $this->attribute . '_type');

        foreach ($this->meta['types'] as &$type)
        {
            $type['active'] = $this->mapToKey($type['value']) === $model->{$this->attribute . '_type'};

            foreach ($type['active'] ? $type['fields'] : [] as $field)
            {
                try
                {
                    $field->resolveForDisplay($model->{$this->attribute});
                }
                catch (\Exception $e)
                {
                    if (\app()->environment('local'))
                    {
                        throw $e;
                    }
                }
            }
        }
    }

    /**
     * Retrieve values of dependency fields
     *
     * @param object $model
     * @param string $attribute
     * @return array|mixed
     */
    protected function resolveAttribute($model, $attribute)
    {
        $attribute = $attribute ?? $this->attribute;

        foreach ($this->meta['types'] as $type)
        {
            $relatedModel = new $type['value'];

            if ($this->mapToKey($type['value']) === $model->{$attribute . '_type'})
            {
                $relatedModel = $relatedModel->newQuery()->findOrFail($model->{$attribute . '_id'});
            }

            foreach ($type['fields'] as $field)
            {
                $field->resolve($relatedModel);
            }

        }

        $anwerableClass = $model->{$attribute . '_type'};

        return $anwerableClass ? $this->mapToClass($anwerableClass) : null;
    }

    /**
     * Fills the attributes of the model within the container if the dependencies for the container are satisfied.
     *
     * @param NovaRequest $request
     * @param string $requestAttribute
     * @param object $model
     * @param string $attribute
     */
    protected function fillAttributeFromRequest(NovaRequest $request, $requestAttribute, $model, $attribute) : void
    {
        $attribute = $attribute ?? $this->attribute;

        foreach ($this->meta['types'] as $type)
        {
            if ($request->get($attribute) === $type['value'])
            {
                $relatedModel = new $type['value'];

                if ($this->mapToKey($type['value']) === $model->{$attribute . '_type'})
                {
                    $relatedModel = $relatedModel->newQuery()->findOrFail($model->{$attribute . '_id'});
                } elseif (!\is_null($model->{$attribute . '_type'}))
                {
                    $oldRelatedClass = $this->mapToClass($model->{$attribute . '_type'});
                    $oldRelatedModel = (new $oldRelatedClass)->newQuery()->findOrFail($model->{$attribute . '_id'});
                    $oldRelatedModel->delete();
                }

                foreach ($type['fields'] as $field)
                {
                    $field->fill($request, $relatedModel);
                }

                $relatedModel->save();

                $model->{$this->attribute . '_id'} = $relatedModel->id;
                $model->{$this->attribute . '_type'} = $this->mapToKey($type['value']);
            }
        }
    }

    /**
     * @param string $class
     * @return string
     */
    protected function mapToKey(string $class) : string
    {
        return \array_search($class, Relation::$morphMap) ?: $class;
    }

    /**
     * @param string $key
     * @return string
     */
    protected function mapToClass(string $key) : string
    {
        return Relation::$morphMap[$key] ?? $key;
    }

    /**
     * When set to true, the field should not be displayed when updating the resource. This can be
     * used when you do not want the user to change the type once a relationship has been created.
     *
     * @return self
     */
    public function hideTypeWhenUpdating() : self
    {
        return $this->withMeta([
            'hideTypeWhenUpdating' => true,
        ]);
    }
}
