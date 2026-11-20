<?php

declare(strict_types=1);

namespace App\DlqDoctor\Service;

use App\DlqDoctor\Dto\MessageContract;
use InvalidArgumentException;
use ReflectionClass;
use ReflectionEnum;
use Symfony\Component\PropertyInfo\PropertyInfoExtractorInterface;
use Symfony\Component\TypeInfo\Type;
use Symfony\Component\TypeInfo\Type\BuiltinType;
use Symfony\Component\TypeInfo\Type\ObjectType;
use Symfony\Component\TypeInfo\TypeIdentifier;
use Symfony\Component\Validator\Constraints\Choice;
use Symfony\Component\Validator\Constraints\Length;
use Symfony\Component\Validator\Constraints\Negative;
use Symfony\Component\Validator\Constraints\NegativeOrZero;
use Symfony\Component\Validator\Constraints\NotBlank;
use Symfony\Component\Validator\Constraints\NotNull;
use Symfony\Component\Validator\Constraints\Positive;
use Symfony\Component\Validator\Constraints\PositiveOrZero;
use Symfony\Component\Validator\Constraints\Range;
use Symfony\Component\Validator\Mapping\ClassMetadataInterface;
use Symfony\Component\Validator\Mapping\PropertyMetadata;
use Symfony\Component\Validator\Mapping\PropertyMetadataInterface;
use Symfony\Component\Validator\Validator\ValidatorInterface;

final readonly class ContractInspector
{
    public function __construct(
        private PropertyInfoExtractorInterface $propertyInfo,
        private ValidatorInterface $validator,
    ) {
    }

    public function inspect(string $className): MessageContract
    {
        if (!class_exists($className)) {
            throw new InvalidArgumentException(sprintf('Message class "%s" does not exist.', $className));
        }

        $reflection = new ReflectionClass($className);
        if (!$reflection->isInstantiable()) {
            throw new InvalidArgumentException(sprintf('Message class "%s" is not instantiable.', $className));
        }

        $metadata = $this->validator->getMetadataFor($className);
        if (!$metadata instanceof ClassMetadataInterface) {
            throw new InvalidArgumentException(sprintf('Message class "%s" has no class validation metadata.', $className));
        }

        $unsupported = [];
        $defaultGroupAlias = $reflection->getShortName();
        $properties = [];
        $required = [];
        $propertyNames = $this->propertyInfo->getProperties($className) ?? [];
        $constructor = $reflection->getConstructor();

        foreach ($propertyNames as $propertyName) {
            $type = $this->propertyInfo->getType($className, $propertyName);
            if (null === $type) {
                $propertySchema = [];
                $unsupported[] = sprintf('%s::$%s: PHP type could not be inferred', $className, $propertyName);
            } else {
                $propertySchema = $this->schemaForType($type, $unsupported, $className, $propertyName);
            }
            $propertyMetadata = $metadata->getPropertyMetadata($propertyName);
            $constraints = [];

            foreach ($propertyMetadata as $propertyEntry) {
                if ($propertyEntry instanceof PropertyMetadata) {
                    array_push($constraints, ...$propertyEntry->findConstraints('Default'));
                } elseif ($propertyEntry instanceof PropertyMetadataInterface) {
                    $unsupported[] = sprintf('%s::$%s: getter constraints are not represented', $className, $propertyName);
                }
            }

            foreach ($constraints as $constraint) {
                $this->applyConstraint($propertySchema, $constraint, $required, $propertyName, $className, $unsupported);
            }

            $properties[$propertyName] = $propertySchema;
        }

        foreach ($metadata->getConstraints() as $constraint) {
            $defaultGroup = in_array('Default', $constraint->groups, true) || in_array($defaultGroupAlias, $constraint->groups, true);

            if ($defaultGroup) {
                $unsupported[] = sprintf('%s: class-level %s constraint', $className, $constraint::class);
            } else {
                foreach ($constraint->groups as $group) {
                    $unsupported[] = sprintf('%s: validation group "%s"', $className, $group);
                }
            }
        }

        if ($metadata->hasGroupSequence() || $metadata->isGroupSequenceProvider()) {
            $unsupported[] = sprintf('%s: validation group sequence', $className);
        }

        foreach ($metadata->getConstrainedProperties() as $propertyName) {
            if (!array_key_exists($propertyName, $properties)) {
                $unsupported[] = sprintf('%s::$%s: constrained property is not present in extracted message properties', $className, $propertyName);
            }

            foreach ($metadata->getPropertyMetadata($propertyName) as $propertyEntry) {
                foreach ($propertyEntry->getConstraints() as $constraint) {
                    foreach ($constraint->groups as $group) {
                        if (!in_array($group, ['Default', $defaultGroupAlias], true)) {
                            $unsupported[] = sprintf('%s::$%s: validation group "%s"', $className, $propertyName, $group);
                        }
                    }
                }
            }
        }

        if (null !== $constructor) {
            foreach ($constructor->getParameters() as $parameter) {
                if (!$parameter->isOptional() && isset($properties[$parameter->getName()])) {
                    $required[] = $parameter->getName();
                }
            }
        }

        $required = array_values(array_unique($required));
        sort($required);
        $unsupported = array_values(array_unique($unsupported));
        sort($unsupported);
        ksort($properties);

        return new MessageContract(
            $className,
            [
                '$schema' => 'https://json-schema.org/draft/2020-12/schema',
                'title' => $className,
                'type' => 'object',
                'properties' => $properties,
                'required' => $required,
                'additionalProperties' => false,
            ],
            $unsupported,
        );
    }

    /**
     * @param list<string> $unsupported
     *
     * @return array<string, mixed>
     */
    private function schemaForType(Type $type, array &$unsupported, string $className, string $propertyName): array
    {
        $schemas = [];
        foreach ($type->traverse() as $part) {
            if ($part instanceof BuiltinType) {
                $identifier = $part->getTypeIdentifier();
                $jsonType = match ($identifier) {
                    TypeIdentifier::STRING => 'string',
                    TypeIdentifier::INT => 'integer',
                    TypeIdentifier::FLOAT => 'number',
                    TypeIdentifier::BOOL, TypeIdentifier::TRUE, TypeIdentifier::FALSE => 'boolean',
                    TypeIdentifier::ARRAY, TypeIdentifier::ITERABLE => ['array', 'object'],
                    TypeIdentifier::OBJECT => 'object',
                    TypeIdentifier::NULL => 'null',
                    default => null,
                };
                if (null !== $jsonType) {
                    if (is_array($jsonType)) {
                        $schemas[] = ['type' => 'array', 'items' => new \stdClass()];
                        $schemas[] = ['type' => 'object'];
                        $unsupported[] = sprintf('%s::$%s: PHP array shape and item types are not represented', $className, $propertyName);
                    } else {
                        $schemas[] = ['type' => $jsonType];
                    }
                } else {
                    $unsupported[] = sprintf('%s::$%s: PHP type "%s" is not represented', $className, $propertyName, $part);
                }
            } elseif ($part instanceof ObjectType) {
                $nestedClass = $part->getClassName();
                if (enum_exists($nestedClass) && is_subclass_of($nestedClass, \BackedEnum::class)) {
                    $enum = new ReflectionEnum($nestedClass);
                    $backingType = $enum->getBackingType()?->getName();
                    if (in_array($backingType, ['string', 'int'], true)) {
                        $schemas[] = [
                            'type' => 'string' === $backingType ? 'string' : 'integer',
                            'enum' => array_map(
                                static fn (\ReflectionEnumBackedCase $case): string|int => $case->getBackingValue(),
                                $enum->getCases(),
                            ),
                        ];
                    } else {
                        $unsupported[] = sprintf('%s::$%s: backed enum type "%s" is not represented', $className, $propertyName, $nestedClass);
                    }
                } else {
                    $unsupported[] = sprintf('%s::$%s: nested object "%s" is not expanded', $className, $propertyName, $nestedClass);
                }
            }
        }

        if ([] === $schemas) {
            return [];
        }

        $types = array_values(array_unique(array_column($schemas, 'type')));
        $result = ['type' => 1 === count($types) ? $types[0] : $types];
        foreach ($schemas as $schema) {
            if (isset($schema['items'])) {
                $result['items'] = $schema['items'];
                break;
            }
        }
        $enumSchemas = array_values(array_filter($schemas, static fn (array $schema): bool => isset($schema['enum'])));
        $nonNullSchemas = array_values(array_filter($schemas, static fn (array $schema): bool => 'null' !== $schema['type']));
        if (1 === count($enumSchemas) && 1 === count($nonNullSchemas)) {
            $enumValues = $enumSchemas[0]['enum'];
            if (in_array('null', $types, true)) {
                $enumValues[] = null;
            }
            $result['enum'] = $enumValues;
        } elseif ([] !== $enumSchemas) {
            $unsupported[] = sprintf('%s::$%s: enum union type is not represented', $className, $propertyName);
        }

        return $result;
    }

    /**
     * @param array<string, mixed> $schema
     * @param list<string>         $required
     * @param list<string>         $unsupported
     */
    private function applyConstraint(
        array &$schema,
        object $constraint,
        array &$required,
        string $propertyName,
        string $className,
        array &$unsupported,
    ): void {
        $location = sprintf('%s::$%s', $className, $propertyName);

        if ($constraint instanceof NotNull || $constraint instanceof NotBlank) {
            $required[] = $propertyName;
            if (is_array($schema['type'] ?? null)) {
                $schema['type'] = array_values(array_filter($schema['type'], static fn (string $type): bool => 'null' !== $type));
                if (1 === count($schema['type'])) {
                    $schema['type'] = $schema['type'][0];
                } elseif ([] === $schema['type']) {
                    unset($schema['type']);
                    $schema['not'] = ['type' => 'null'];
                }
            } elseif ('null' === ($schema['type'] ?? null)) {
                unset($schema['type']);
            }
            if ($constraint instanceof NotBlank && 'string' === ($schema['type'] ?? null)) {
                $schema['minLength'] = max(1, $schema['minLength'] ?? 0);
            }
            if ($constraint instanceof NotBlank) {
                $unsupported[] = sprintf('%s: NotBlank semantics are only approximated', $location);
            }

            return;
        }

        if ($constraint instanceof Length && null === $constraint->normalizer && Length::COUNT_CODEPOINTS === $constraint->countUnit && $this->schemaAllowsType($schema, 'string')) {
            if (null !== $constraint->min) {
                $schema['minLength'] = max($schema['minLength'] ?? 0, $constraint->min);
            }
            if (null !== $constraint->max) {
                $schema['maxLength'] = isset($schema['maxLength'])
                    ? min($schema['maxLength'], $constraint->max)
                    : $constraint->max;
            }

            return;
        }

        if (
            $constraint instanceof Choice
            && null === $constraint->callback
            && !$constraint->multiple
            && null !== $constraint->choices
            && $this->containsOnlyJsonScalars($constraint->choices)
        ) {
            $schema['enum'] = $constraint->choices;

            return;
        }

        if ($this->schemaAllowsType($schema, 'integer') || $this->schemaAllowsType($schema, 'number')) {
            if ($constraint instanceof Positive) {
                $schema['exclusiveMinimum'] = 0;

                return;
            }
            if ($constraint instanceof PositiveOrZero) {
                $schema['minimum'] = 0;

                return;
            }
            if ($constraint instanceof Negative) {
                $schema['exclusiveMaximum'] = 0;

                return;
            }
            if ($constraint instanceof NegativeOrZero) {
                $schema['maximum'] = 0;

                return;
            }
            if ($constraint instanceof Range) {
                foreach (['min' => 'minimum', 'max' => 'maximum'] as $source => $target) {
                    if (is_int($constraint->{$source}) || is_float($constraint->{$source})) {
                        $schema[$target] = $constraint->{$source};
                    } elseif (null !== $constraint->{$source}) {
                        $unsupported[] = sprintf('%s: Range constraint with non-numeric %s', $location, $source);
                    }
                }

                return;
            }
        }

        $unsupported[] = sprintf('%s: %s constraint', $location, $constraint::class);
    }

    /**
     * @param array<mixed> $values
     */
    private function containsOnlyJsonScalars(array $values): bool
    {
        foreach ($values as $value) {
            if (null !== $value && !is_string($value) && !is_int($value) && !is_float($value) && !is_bool($value)) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param array<string, mixed> $schema
     */
    private function schemaAllowsType(array $schema, string $type): bool
    {
        return $type === ($schema['type'] ?? null) || (is_array($schema['type'] ?? null) && in_array($type, $schema['type'], true));
    }
}
