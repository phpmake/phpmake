<?php

declare(strict_types=1);

namespace PhpMake\Tests\Unit;

use PhpMake\Build\BuildValidator;
use PhpMake\Task\TaskDefinitionRegistry;
use PHPUnit\Framework\TestCase;

/** Keeps runtime task validation aligned with the published JSON Schema. */
final class BuildSchemaParityTest extends TestCase
{
    /** Checks task identifiers, required parameters, and allowed parameter names. */
    public function testRuntimeValidatorMatchesTaskSchemaDefinitions(): void
    {
        // Arrange
        $schemaPath = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'schema'
            . DIRECTORY_SEPARATOR . 'build-schema.json';
        self::assertFileExists($schemaPath);
        $schema = json_decode((string) file_get_contents($schemaPath), true, 512, JSON_THROW_ON_ERROR);
        $definitions = $schema['definitions'];
        $taskTypeEnum = $schema['properties']['targets']['patternProperties']
            ['^(?=.*\\S).+$']['properties']['tasks']['items']['properties']['type']['enum'];
        $registry = TaskDefinitionRegistry::all();
        self::assertEqualsCanonicalizing(array_keys($definitions), array_keys($registry));

        foreach ($definitions as $taskType => $definition) {
            self::assertEqualsCanonicalizing(
                $definition['required'],
                $registry[$taskType]['required'],
                sprintf('%s required parameters must match the JSON Schema.', $taskType)
            );
            self::assertEqualsCanonicalizing(
                array_keys($definition['properties']),
                $registry[$taskType]['allowed'],
                sprintf('%s allowed parameters must match the JSON Schema.', $taskType)
            );
        }

        $validator = new BuildValidator();

        // Assert
        self::assertEqualsCanonicalizing(array_keys($definitions), $taskTypeEnum);

        foreach ($definitions as $taskType => $definition) {
            $properties = $definition['properties'];
            $required = $definition['required'];
            $params = [];

            foreach ($properties as $name => $property) {
                $params[$name] = match ($name) {
                    'args' => [],
                    'timeout' => 1,
                    'message' => '',
                    default => 'valid-value',
                };
            }

            $config = $this->buildConfig($taskType, $params);

            // Act
            $errors = $validator->validate($config);

            // Assert
            self::assertSame([], $errors, sprintf('%s schema-valid parameters must pass runtime validation.', $taskType));

            foreach ($required as $requiredParameter) {
                $missingParams = $params;
                unset($missingParams[$requiredParameter]);
                $errors = $validator->validate($this->buildConfig($taskType, $missingParams));

                self::assertNotSame(
                    [],
                    $errors,
                    sprintf('%s must reject a missing required parameter (%s).', $taskType, $requiredParameter)
                );
            }

            $unknownParams = $params;
            $unknownParams['unexpected'] = true;
            $errors = $validator->validate($this->buildConfig($taskType, $unknownParams));
            self::assertStringContainsString(
                "Unknown parameter 'unexpected'",
                implode("\n", $errors),
                sprintf('%s must reject parameters disallowed by its schema.', $taskType)
            );
        }
    }

    /** Builds the smallest complete build configuration for one task. */
    private function buildConfig(string $taskType, array $params): array
    {
        return [
            'name' => 'Schema parity test',
            'description' => 'Runtime/schema contract',
            'version' => '1.0.0',
            'targets' => [
                'build' => [
                    'tasks' => [
                        ['type' => $taskType, 'params' => $params],
                    ],
                ],
            ],
            'default_target' => 'build',
        ];
    }

    /** Returns every registered task identifier in canonical registry order. */
    public function testTypesReturnsRegisteredTaskIdentifiers(): void
    {
        // Arrange
        $definitions = TaskDefinitionRegistry::all();

        // Act
        $types = TaskDefinitionRegistry::types();

        // Assert
        self::assertSame(array_keys($definitions), $types);
    }
}
