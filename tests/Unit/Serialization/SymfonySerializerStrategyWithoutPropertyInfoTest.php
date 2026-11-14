<?php

namespace Zeusi\JsonSchemaExtractor\Tests\Unit\Serialization;

use Composer\Autoload\ClassLoader;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;
use Symfony\Component\PropertyInfo\Extractor\ReflectionExtractor;
use Symfony\Component\Serializer\Mapping\Factory\ClassMetadataFactory;
use Symfony\Component\Serializer\Mapping\Loader\AttributeLoader;
use Zeusi\JsonSchemaExtractor\Context\ExtractionContext;
use Zeusi\JsonSchemaExtractor\Discoverer\ReflectionDiscoverer;
use Zeusi\JsonSchemaExtractor\Serialization\State\NeutralProjectionState;
use Zeusi\JsonSchemaExtractor\Serialization\SymfonySerializerStrategy;
use Zeusi\JsonSchemaExtractor\Tests\Fixtures\CustomerProfile;
use Zeusi\JsonSchemaExtractor\Tests\Support\TypeTestHelperTrait;

/**
 * Covers the deprecated fallback for applications without the PropertyInfo component.
 *
 * Kept apart from SymfonySerializerStrategyTest because it needs a process where
 * PropertyInfo cannot be autoloaded, and that class builds the strategy, and so loads
 * PropertyInfo, in setUp().
 *
 * @todo 4.0: delete together with the fallback.
 */
#[CoversClass(SymfonySerializerStrategy::class)]
final class SymfonySerializerStrategyWithoutPropertyInfoTest extends TestCase
{
    use TypeTestHelperTrait;

    #[RunInSeparateProcess]
    public function testFallsBackToDiscoveredPropertiesAndTriggersADeprecation(): void
    {
        if (class_exists(ReflectionExtractor::class, false)) {
            self::markTestSkipped('PropertyInfo is already loaded in this process, so it cannot be hidden.');
        }

        self::hidePropertyInfo();

        $deprecations = 0;
        set_error_handler(static function () use (&$deprecations): bool {
            ++$deprecations;

            return true;
        }, \E_USER_DEPRECATED);

        try {
            $strategy = new SymfonySerializerStrategy(new ClassMetadataFactory(new AttributeLoader()));
        } finally {
            restore_error_handler();
        }

        self::assertSame(1, $deprecations);

        $projected = $this->requireRootObject($strategy->project(
            (new ReflectionDiscoverer())->discover(CustomerProfile::class),
            new ExtractionContext(),
            NeutralProjectionState::instance()
        ));

        // The behavior before PropertyInfo: every discovered property, no getter-only attribute.
        self::assertEqualsCanonicalizing(
            ['displayName', 'email', 'verified', 'internalNotes'],
            array_keys($projected->properties)
        );
    }

    /**
     * Replaces Composer's autoloaders with ones that refuse the PropertyInfo namespace.
     */
    private static function hidePropertyInfo(): void
    {
        foreach (ClassLoader::getRegisteredLoaders() as $loader) {
            $loader->unregister();
            spl_autoload_register(static function (string $class) use ($loader): void {
                if (!str_starts_with($class, 'Symfony\\Component\\PropertyInfo\\')) {
                    $loader->loadClass($class);
                }
            });
        }
    }
}
