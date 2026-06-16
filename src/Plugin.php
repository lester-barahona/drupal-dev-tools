<?php

declare(strict_types=1);

namespace LesterBarahona\DrupalDevTools;

use Composer\Composer;
use Composer\EventDispatcher\EventSubscriberInterface;
use Composer\IO\IOInterface;
use Composer\Plugin\Capable;
use Composer\Plugin\PluginInterface;
use Composer\Script\Event;
use Composer\Script\ScriptEvents;

class Plugin implements PluginInterface, EventSubscriberInterface, Capable
{
    private const GENERATED_CONFIG_DIR  = '.drupal-dev-tools';
    private const GENERATED_GRUMPHP     = '.drupal-dev-tools/grumphp.yml';

    private Composer $composer;
    private IOInterface $io;

    public function activate(Composer $composer, IOInterface $io): void
    {
        $this->composer = $composer;
        $this->io = $io;
    }

    public function deactivate(Composer $composer, IOInterface $io): void
    {
    }

    public function uninstall(Composer $composer, IOInterface $io): void
    {
    }

    public function getCapabilities(): array
    {
        return [
            'Composer\Plugin\Capability\CommandProvider' => CommandProvider::class,
        ];
    }

    public static function getSubscribedEvents(): array
    {
        return [
            ScriptEvents::POST_INSTALL_CMD => 'setup',
            ScriptEvents::POST_UPDATE_CMD => 'setup',
        ];
    }

    public function setup(Event $event): void
    {
        $io = $event->getIO();
        $projectRoot = getcwd();

        $io->write('');
        $io->write('<info>drupal-dev-tools:</info> Checking project setup...');

        $this->generateGrumphpDefaults($io, $projectRoot);
        $this->ensureGitignore($io, $projectRoot);
        $this->ensureGrumphpConfigPath($io, $projectRoot);
        $this->ensureAllowPlugins($io, $projectRoot);

        $io->write('');
        $io->write('<info>drupal-dev-tools:</info> Done. Available commands:');
        $io->write('  <comment>composer cs</comment>                              – check coding standards');
        $io->write('  <comment>composer cs-fix</comment>                          – auto-fix coding standards');
        $io->write('  <comment>composer analyse</comment>                         – run static analysis');
        $io->write('  <comment>composer drupal-dev-tools:publish [tool]</comment> – publish a config stub to customize');
        $io->write('    Tools: phpcs, phpstan, grumphp, .editorconfig, all');
        $io->write('');
    }

    /**
     * Generates .drupal-dev-tools/grumphp.yml with the correct vendor path
     * computed at runtime, so no package name is ever hardcoded in static files.
     */
    private function generateGrumphpDefaults(IOInterface $io, string $projectRoot): void
    {
        $packageRelPath = $this->getPackageRelPath($projectRoot);
        $configDir      = $projectRoot . DIRECTORY_SEPARATOR . self::GENERATED_CONFIG_DIR;

        if (!is_dir($configDir)) {
            mkdir($configDir, 0755, true);
        }

        $content = implode("\n", [
            'grumphp:',
            '  hooks_dir: ~',
            '  hooks_preset: local',
            '',
            '  tasks:',
            '    phpcs:',
            "      standard: {$packageRelPath}/config/phpcs.xml",
            '      show_sniffs_in_use: false',
            '      metadata:',
            '        priority: 10',
            '        run_on: [pre-commit]',
            '',
            '    phpstan:',
            "      configuration: {$packageRelPath}/config/phpstan.neon",
            '      use_grumphp_paths: false',
            '      metadata:',
            '        priority: 0',
            '        run_on: [pre-push]',
        ]) . "\n";

        file_put_contents($configDir . DIRECTORY_SEPARATOR . 'grumphp.yml', $content);
        $io->write('  <info>generated</info>  ' . self::GENERATED_GRUMPHP);
    }

    private function ensureGitignore(IOInterface $io, string $projectRoot): void
    {
        $gitignorePath = $projectRoot . '/.gitignore';
        $entry         = '/' . self::GENERATED_CONFIG_DIR . '/';

        if (file_exists($gitignorePath)) {
            $contents = file_get_contents($gitignorePath);
            if (strpos($contents, self::GENERATED_CONFIG_DIR) !== false) {
                return;
            }
            file_put_contents($gitignorePath, rtrim($contents) . "\n" . $entry . "\n");
        } else {
            file_put_contents($gitignorePath, $entry . "\n");
        }

        $io->write('  <info>updated</info>  .gitignore → ' . $entry);
    }

    private function ensureGrumphpConfigPath(IOInterface $io, string $projectRoot): void
    {
        $composerJsonPath = $projectRoot . '/composer.json';

        if (!file_exists($composerJsonPath)) {
            return;
        }

        $contents     = file_get_contents($composerJsonPath);
        $composerJson = json_decode($contents, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            $io->writeError('  <warning>warning</warning>  Could not parse project composer.json');
            return;
        }

        $current = $composerJson['extra']['grumphp']['config-default-path'] ?? null;

        if ($current === self::GENERATED_GRUMPHP) {
            return;
        }

        $composerJson['extra']['grumphp']['config-default-path'] = self::GENERATED_GRUMPHP;

        file_put_contents(
            $composerJsonPath,
            json_encode($composerJson, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n"
        );

        $io->write('  <info>updated</info>  grumphp.config-default-path → ' . self::GENERATED_GRUMPHP);
    }

    private function ensureAllowPlugins(IOInterface $io, string $projectRoot): void
    {
        $composerJsonPath = $projectRoot . '/composer.json';

        if (!file_exists($composerJsonPath)) {
            return;
        }

        $contents     = file_get_contents($composerJsonPath);
        $composerJson = json_decode($contents, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            $io->writeError('  <warning>warning</warning>  Could not parse project composer.json');
            return;
        }

        $required = [
            'dealerdirect/phpcodesniffer-composer-installer',
            'phpstan/extension-installer',
            'phpro/grumphp',
        ];

        $allowPlugins = $composerJson['config']['allow-plugins'] ?? [];
        $modified     = false;

        foreach ($required as $plugin) {
            if (!array_key_exists($plugin, $allowPlugins)) {
                $composerJson['config']['allow-plugins'][$plugin] = true;
                $modified = true;
                $io->write("  <info>updated</info>  allow-plugins: {$plugin}");
            }
        }

        if ($modified) {
            file_put_contents(
                $composerJsonPath,
                json_encode($composerJson, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n"
            );
        }
    }

    /**
     * Returns the package path relative to the project root (e.g. "vendor/my-org/drupal-dev-tools").
     * Derived from __DIR__ so it works regardless of the package name.
     */
    public static function getPackageRelPath(string $projectRoot): string
    {
        $packageDir = \dirname(__DIR__);
        $relPath    = ltrim(str_replace($projectRoot, '', $packageDir), DIRECTORY_SEPARATOR . '/');

        return str_replace(DIRECTORY_SEPARATOR, '/', $relPath);
    }
}
