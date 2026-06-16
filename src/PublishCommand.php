<?php

declare(strict_types=1);

namespace LesterBarahona\DrupalDevTools;

use Composer\Command\BaseCommand;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class PublishCommand extends BaseCommand
{
    private const VALID_TOOLS = ['phpcs', 'phpstan', 'grumphp', '.editorconfig'];

    protected function configure(): void
    {
        $this
            ->setName('drupal-dev-tools:publish')
            ->setDescription('Publish a config stub to the project root for customization.')
            ->addArgument(
                'tool',
                InputArgument::OPTIONAL,
                'Tool to publish: phpcs, phpstan, grumphp, .editorconfig, all',
                'all'
            )
            ->addOption('force', 'f', InputOption::VALUE_NONE, 'Overwrite existing files.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $tool        = $input->getArgument('tool');
        $force       = $input->getOption('force');
        $projectRoot = getcwd();

        $tools = $tool === 'all' ? self::VALID_TOOLS : [$tool];

        foreach ($tools as $key) {
            if (!in_array($key, self::VALID_TOOLS, true)) {
                $output->writeln("<error>Unknown tool \"{$key}\". Valid options: phpcs, phpstan, grumphp, .editorconfig, all</error>");
                return 1;
            }
        }

        $packageRelPath = Plugin::getPackageRelPath($projectRoot);
        $published      = [];

        foreach ($tools as $key) {
            $filename = $key === 'grumphp' ? 'grumphp.yml' : $key;
            $target   = $projectRoot . '/' . $filename;

            if (file_exists($target) && !$force) {
                $output->writeln("  <comment>skipped</comment>  {$filename} (already exists — use --force to overwrite)");
                continue;
            }

            $content = $this->generateContent($key, $packageRelPath, $tools);
            file_put_contents($target, $content);
            $output->writeln("  <info>created</info>  {$filename}");
            $published[] = $key;
        }

        $this->maybeCreateGrumphpOverride($output, $projectRoot, $published, $force);

        return 0;
    }

    private function generateContent(string $tool, string $packageRelPath, array $allTools): string
    {
        switch ($tool) {
            case 'phpcs':
                return $this->phpcsContent($packageRelPath);

            case 'phpstan':
                return $this->phpstanContent($packageRelPath);

            case 'grumphp':
                $localTools = array_intersect(['phpcs', 'phpstan'], $allTools);
                return $this->grumphpContent(array_values($localTools));

            case '.editorconfig':
                return file_get_contents(\dirname(__DIR__) . '/stubs/.editorconfig');
        }

        return '';
    }

    /**
     * When phpcs or phpstan are published without grumphp, a local grumphp.yml is
     * needed to tell GrumPHP to prefer the local config files over the generated defaults.
     * If no local grumphp.yml exists yet, create one automatically.
     */
    private function maybeCreateGrumphpOverride(
        OutputInterface $output,
        string $projectRoot,
        array $published,
        bool $force
    ): void {
        $needsOverride = array_values(array_intersect(['phpcs', 'phpstan'], $published));

        if (empty($needsOverride) || in_array('grumphp', $published, true)) {
            return;
        }

        $grumphpPath = $projectRoot . '/grumphp.yml';

        if (file_exists($grumphpPath) && !$force) {
            $output->writeln('');
            $output->writeln('  <comment>note</comment>  grumphp.yml already exists. Add these overrides to use your local config:');
            foreach ($needsOverride as $t) {
                if ($t === 'phpcs') {
                    $output->writeln('    grumphp: { tasks: { phpcs: { standard: phpcs.xml } } }');
                }
                if ($t === 'phpstan') {
                    $output->writeln('    grumphp: { tasks: { phpstan: { configuration: phpstan.neon } } }');
                }
            }
            return;
        }

        file_put_contents($grumphpPath, $this->grumphpContent($needsOverride));
        $output->writeln("  <info>created</info>  grumphp.yml (with local config override)");
    }

    private function phpcsContent(string $packageRelPath): string
    {
        return <<<XML
        <?xml version="1.0"?>
        <ruleset name="Project">
          <!--
            Inherits Drupal coding standards from drupal-dev-tools.
            Add project-specific overrides below.

            Examples:
              Disable a rule:
                <rule ref="Drupal.Commenting.FunctionComment"><severity>0</severity></rule>

              Change a rule to warning instead of error:
                <rule ref="Drupal.Commenting.InlineComment"><type>warning</type></rule>

              Add an extra exclusion pattern:
                <exclude-pattern>web/modules/custom/legacy_module/*</exclude-pattern>
          -->
          <rule ref="{$packageRelPath}/config/phpcs.xml"/>
        </ruleset>
        XML;
    }

    private function phpstanContent(string $packageRelPath): string
    {
        return <<<NEON
        includes:
          - {$packageRelPath}/config/phpstan.neon

        parameters:
          # Override the analysis level (0 = loosest, 9 = strictest). Default: 2.
          # level: 4

          # Override if your Drupal root is not "web" (e.g. "docroot").
          # drupal:
          #   drupal_root: docroot

          # Add extra paths to analyse.
          # paths:
          #   - web/modules/custom/my_module

          # Ignore specific errors for this project.
          # ignoreErrors:
          #   - '#Call to an undefined method#'
        NEON;
    }

    private function grumphpContent(array $localTools): string
    {
        $lines = [
            'imports:',
            '  - { resource: .drupal-dev-tools/grumphp.yml }',
        ];

        if (!empty($localTools)) {
            $lines[] = '';
            $lines[] = 'grumphp:';
            $lines[] = '  tasks:';
            if (in_array('phpcs', $localTools, true)) {
                $lines[] = '    phpcs:';
                $lines[] = '      standard: phpcs.xml';
            }
            if (in_array('phpstan', $localTools, true)) {
                $lines[] = '    phpstan:';
                $lines[] = '      configuration: phpstan.neon';
            }
        } else {
            $lines[] = '';
            $lines[] = '# Project overrides. Uncomment and adjust as needed.';
            $lines[] = '#';
            $lines[] = '# grumphp:';
            $lines[] = '#   tasks:';
            $lines[] = '#     phpcs:';
            $lines[] = '#       # Use a local phpcs.xml published via: composer drupal-dev-tools:publish phpcs';
            $lines[] = '#       standard: phpcs.xml';
            $lines[] = '#';
            $lines[] = '#     phpstan:';
            $lines[] = '#       # Use a local phpstan.neon published via: composer drupal-dev-tools:publish phpstan';
            $lines[] = '#       configuration: phpstan.neon';
            $lines[] = '#';
            $lines[] = '#   # Disable a hook entirely.';
            $lines[] = '#   hooks_preset: local';
        }

        return implode("\n", $lines) . "\n";
    }
}
