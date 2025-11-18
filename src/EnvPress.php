<?php

declare(strict_types=1);

namespace EnvPress;

use EnvPress\Exception\InvalidDotenvException;
use EnvPress\Layer\AdminEmailLayer;
use EnvPress\Layer\AdminLayer;
use EnvPress\Layer\ConstLayer;
use EnvPress\Layer\DebugLayer;
use EnvPress\Layer\FeatureLayer;
use EnvPress\Layer\LayerInterface;
use EnvPress\Layer\MailLayer;
use EnvPress\Layer\MultisiteLayer;
use EnvPress\Layer\RolesLayer;
use EnvPress\Layer\SecurityLayer;
use EnvPress\Layer\SentryLayer;
use EnvPress\Layer\TrackingLayer;
use EnvPress\Util\Env;
use EnvPress\Util\Plugin;

/**
 * Public EnvPress package API
 *
 * Example usage in `wp-config.php`:
 * ```php
 * <?php
 * require_once dirname(__DIR__) . '/vendor/autoload.php';
 * \EnvPress\EnvPress::createWithDefaults(__DIR__)->bootstrap();
 * require_once ABSPATH . 'wp-settings.php';
 * ```
 */
class EnvPress {
    /**
     * The path to the folder containing the .env, omitting the trailing slash.
     *
     * @var string
     */
    private string $dotEnvPath;

    /**
     * Configuration layers to be applied.
     *
     * @var LayerInterface[]
     */
    private array $layers;

    /**
     * Create a new EnvPress instance.
     *
     * @param string $dotEnvPath
     * @param LayerInterface[] $layers
     *
     * @return void
     */
    private function __construct(string $dotEnvPath, array $layers)
    {
        $this->dotEnvPath = $dotEnvPath;
        $this->layers = $layers;
    }

    /**
     * Create a new EnvPress instance for the specified folder structure.
     * This method is made private as it may not be considered stable, yet.
     *
     * @param string $instancePath
     * @param string $dotEnvPath
     * @param string $absPath
     * @param string $contentPath
     *
     * @return EnvPress
     */
    private static function create(
        string $instancePath,
        string $dotEnvPath,
        string $absPath,
        string $contentPath
    ): self
    {
        return new self($dotEnvPath, [
            // Configure error reporting as soon as possible
            SentryLayer::create(),
            ConstLayer::create(
                $instancePath,
                $absPath,
                $contentPath
            ),
            MultisiteLayer::create($instancePath),
            DebugLayer::create(),
            SecurityLayer::create(),
            RolesLayer::create(),
            MailLayer::create(),
            FeatureLayer::create(),
            TrackingLayer::create(),
            AdminLayer::create(),
            AdminEmailLayer::create()
        ]);
    }

    /**
     * Create a new EnvPress instance assuming the default folder structure.
     *
     * @param string $instancePath The root path of a WordPress instance,
     * omitting the trailing slash.
     *
     * @return EnvPress
     */
    public static function createWithDefaults(string $instancePath): self
    {
        return self::create(
            $instancePath,
            dirname($instancePath),
            $instancePath . '/wp',
            $instancePath . '/content'
        );
    }

    /**
     * Create a new EnvPress instance assuming the Bedrock folder structure.
     *
     * @param string $instancePath The root path of a WordPress instance,
     * omitting the trailing slash.
     *
     * @return EnvPress
     */
    public static function createWithBedrockDefaults(string $instancePath): self
    {
        return self::create(
            $instancePath,
            dirname($instancePath),
            $instancePath . '/wp',
            $instancePath . '/app'
        );
    }

    /**
     * Bootstrap and configure a WordPress instance from a `wp-config.php` file.
     *
     * @return void
     * @throws InvalidDotenvException
     */
    public function bootstrap(): void
    {
        Env::loadDotEnv($this->dotEnvPath);
        Env::loadProxyEnv();

        foreach ($this->layers as $layer) {
            $hookName = $layer->getHookName();
            if ($hookName === null) {
                $this->applyLayerIfActive($layer);
            } else {
                Plugin::addAction($hookName, function () use ($layer) {
                    $this->applyLayerIfActive($layer);
                });
            }
        }
    }

    private function applyLayerIfActive(LayerInterface $layer): void
    {
        if ($layer->isActive()) {
            $layer->apply();
        }
    }
}
