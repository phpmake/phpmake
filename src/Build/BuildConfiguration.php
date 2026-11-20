<?php

namespace PhpMake\Build;

/**
 * BuildConfiguration Class
 *
 * Represents the configuration for a build process, encapsulating
 * essential metadata and available build targets.
 *
 * @package    PHPMake
 * @subpackage Build
 */
final class BuildConfiguration
{
    /**
     * @param array<string, array<string, mixed>> $targets Validated target definitions.
     *
     * Constructor
     *
     * Initializes the build configuration with provided values.
     *
     * @param string $name           The project name.
     * @param string $description    The project description.
     * @param string $version        The project version.
     * @param array  $targets        List of available build targets.
     * @param string $defaultTarget  Default target for execution.
     */
    public function __construct(private string $name, private string $description, private string $version, private array $targets, private string $defaultTarget) {}

    /**
     * Get project name.
     *
     * @return string The name of build project.
     */
    public function getName(): string
    {
        return $this->name;
    }

    /**
     * Get project description.
     *
     * @return string The description of build process.
     */
    public function getDescription(): string
    {
        return $this->description;
    }

    /**
     * Get project version.
     *
     * @return string The version of project.
     */
    public function getVersion(): string
    {
        return $this->version;
    }

    /**
     * Get available build targets.
     *
     * @return array<string, array<string, mixed>> The validated build targets.
     */
    public function getTargets(): array
    {
        return $this->targets;
    }

    /**
     * Get default build target.
     *
     * @return string The default target for execution.
     */
    public function getDefaultTarget(): string
    {
        return $this->defaultTarget;
    }
}
