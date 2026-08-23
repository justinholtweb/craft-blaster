<?php

namespace justinholtweb\blaster\models;

use craft\base\Model;
use ReflectionNamedType;
use ReflectionObject;
use ReflectionProperty;

/**
 * Base for the models stored as JSON on a bar.
 *
 * These are populated from two directions and neither hands over well-typed data: a control panel
 * form posts everything as strings (`'3'`, `''`, `'1'`), and `json_decode` of a column written by
 * an older schema may be missing keys or carrying the wrong type entirely.
 *
 * PHP's coercive typing handles some of that and is a trap for the rest — `''` assigned to a
 * typed `int` property is a `TypeError`, not a zero, so an author clearing a number field would
 * otherwise take down the save with a fatal. {@see applyConfig()} casts against each property's
 * declared type and, crucially, **leaves the default in place when a value cannot be read** rather
 * than inventing a zero. A blank field means "unset", which is what the default is for.
 */
abstract class ConfigModel extends Model
{
    public static function fromArray(?array $config): static
    {
        $model = new static();
        $model->applyConfig((array)$config);
        $model->normalize();

        return $model;
    }

    /** Overridden by models that have repeatable rows or otherwise need squaring up. */
    public function normalize(): void
    {
    }

    public function applyConfig(array $config): void
    {
        $reflection = new ReflectionObject($this);

        foreach ($config as $key => $value) {
            if (!is_string($key) || !$reflection->hasProperty($key)) {
                continue;
            }

            $property = $reflection->getProperty($key);

            if (!$property->isPublic() || $property->isStatic() || $property->isReadOnly()) {
                continue;
            }

            $cast = self::cast($property, $value);

            if ($cast !== self::class) {
                $this->$key = $cast;
            }
        }
    }

    /**
     * The value as this property's type, or the class name as a sentinel meaning "leave it alone".
     *
     * A sentinel rather than `null` because `null` is a legitimate value for the nullable
     * properties here, and conflating "no value" with "the value null" is how a cleared date
     * becomes a date of nothing.
     */
    private static function cast(ReflectionProperty $property, mixed $value): mixed
    {
        $type = $property->getType();

        if (!$type instanceof ReflectionNamedType) {
            return $value;
        }

        $nullable = $type->allowsNull();

        if ($value === null) {
            return $nullable ? null : self::class;
        }

        return match ($type->getName()) {
            'int' => is_numeric($value) ? (int)$value : ($nullable && $value === '' ? null : self::class),
            'float' => is_numeric($value) ? (float)$value : ($nullable && $value === '' ? null : self::class),
            'bool' => self::toBool($value),
            'string' => is_scalar($value) ? ($nullable && $value === '' ? null : (string)$value) : self::class,
            'array' => is_array($value) ? $value : self::class,
            default => $value,
        };
    }

    /**
     * Craft's lightswitch posts `''` when off and `'1'` when on, and a JSON round trip gives back
     * real booleans — so both shapes have to read the same way.
     */
    private static function toBool(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        return filter_var($value, FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE) ?? false;
    }
}
