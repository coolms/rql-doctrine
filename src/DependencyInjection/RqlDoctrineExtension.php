<?php

declare(strict_types=1);

namespace CoolMS\RqlDoctrine\DependencyInjection;

use CoolMS\RqlDoctrine\DQL\JsonArrayHasFunction;
use CoolMS\RqlDoctrine\DQL\JsonArrayHasScalarFunction;
use CoolMS\RqlDoctrine\DQL\JsonbCastFunction;
use CoolMS\RqlDoctrine\DQL\JsonbContainsFunction;
use CoolMS\RqlDoctrine\DQL\JsonbExistsFunction;
use CoolMS\RqlDoctrine\DQL\JsonbGetTextFunction;
use CoolMS\RqlDoctrine\DQL\JsonbVColFunction;
use CoolMS\RqlDoctrine\DQL\JsonValueFunction;
use CoolMS\RqlDoctrine\DQL\SqliteJsonExtractFunction;
use Symfony\Component\Config\FileLocator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Extension\Extension;
use Symfony\Component\DependencyInjection\Extension\PrependExtensionInterface;
use Symfony\Component\DependencyInjection\Loader\PhpFileLoader;

final class RqlDoctrineExtension extends Extension implements PrependExtensionInterface
{
    /**
     * The JSON expressions the platform visitors emit are not portable DQL on
     * their own; each needs a function registered with Doctrine. Prepending
     * them means installing the bundle is enough, and an application that
     * copies them into its own config gets a duplicate-function error rather
     * than a silent mismatch.
     *
     * @var array<string, class-string>
     */
    private const array STRING_FUNCTIONS = [
        'JSONB_CAST' => JsonbCastFunction::class,
        'JSONB_VCOL' => JsonbVColFunction::class,
        'JSONB_CONTAINS' => JsonbContainsFunction::class,
        'JSONB_EXISTS' => JsonbExistsFunction::class,
        'JSON_ARRAY_HAS' => JsonArrayHasFunction::class,
        'JSON_ARRAY_HAS_SCALAR' => JsonArrayHasScalarFunction::class,
        'JSONB_GET_TEXT' => JsonbGetTextFunction::class,
        'JSON_VALUE_FN' => JsonValueFunction::class,
        'SQLITE_JSON_EXTRACT' => SqliteJsonExtractFunction::class,
    ];

    public function prepend(ContainerBuilder $container): void
    {
        if (!$container->hasExtension('doctrine')) {
            return;
        }

        // prepend() runs before the config is processed, so read the raw
        // arrays. Processing them here instead would resolve environment
        // placeholders too early.
        $config = $this->processConfiguration(
            new Configuration(),
            $container->getExtensionConfig('rql_doctrine'),
        );

        if (!$config['register_dql_functions']) {
            return;
        }

        $managers = [];
        foreach ($config['entity_managers'] as $name) {
            $managers[$name] = ['dql' => ['string_functions' => self::STRING_FUNCTIONS]];
        }

        $container->prependExtensionConfig('doctrine', ['orm' => ['entity_managers' => $managers]]);
    }

    public function load(array $configs, ContainerBuilder $container): void
    {
        $loader = new PhpFileLoader($container, new FileLocator(__DIR__ . '/../../config'));
        $loader->load('services.php');
    }

    public function getAlias(): string
    {
        return 'rql_doctrine';
    }
}
