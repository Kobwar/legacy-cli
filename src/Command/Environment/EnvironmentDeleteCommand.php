<?php
declare(strict_types=1);

namespace Platformsh\Cli\Command\Environment;

use Platformsh\Cli\Command\CommandBase;
use Platformsh\Cli\Service\ActivityService;
use Platformsh\Cli\Service\Api;
use Platformsh\Cli\Service\Config;
use Platformsh\Cli\Service\QuestionHelper;
use Platformsh\Cli\Service\Selector;
use Platformsh\Cli\Console\ArrayArgument;
use Platformsh\Cli\Util\Wildcard;
use Platformsh\Client\Model\Environment;
use Platformsh\Client\Model\Project;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class EnvironmentDeleteCommand extends CommandBase
{
    protected static $defaultName = 'environment:delete';
    protected static $defaultDescription = 'Delete one or more environments';

    private $api;
    private $activityService;
    private $config;
    private $questionHelper;
    private $selector;

    public function __construct(
        Api $api,
        ActivityService $activityService,
        Config $config,
        QuestionHelper $questionHelper,
        Selector $selector
    ) {
        $this->api = $api;
        $this->activityService = $activityService;
        $this->config = $config;
        $this->questionHelper = $questionHelper;
        $this->selector = $selector;
        parent::__construct();
        $this->setHiddenAliases(['environment:deactivate']);
    }

    protected function configure()
    {
        $this->addArgument('environment', InputArgument::IS_ARRAY, "The environment(s) to delete.\nThe % character may be used as a wildcard." . "\n" . ArrayArgument::SPLIT_HELP)
            ->addOption('delete-branch', null, InputOption::VALUE_NONE, 'Delete the remote Git branch(es)')
            ->addOption('no-delete-branch', null, InputOption::VALUE_NONE, 'Do not delete the remote Git branch(es)')
            ->addOption('type', null, InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'Delete all environments of a type (adding to any others selected)' . "\n" . ArrayArgument::SPLIT_HELP)
            ->addOption('only-type', 't', InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'Only delete environment(s) of a specific type' . "\n" . ArrayArgument::SPLIT_HELP)
            ->addOption('exclude', null, InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, "Environment(s) not to delete.\nThe % character may be used as a wildcard.\n" . ArrayArgument::SPLIT_HELP)
            ->addOption('exclude-type', null, InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'Environment type(s) of which not to delete' . "\n" . ArrayArgument::SPLIT_HELP)
            ->addOption('inactive', null, InputOption::VALUE_NONE, 'Delete all inactive environments (adding to any others selected')
            ->addOption('merged', null, InputOption::VALUE_NONE, 'Delete all merged environments (adding to any others selected)');

        $definition = $this->getDefinition();
        $this->selector->addEnvironmentOption($definition);
        $this->selector->addProjectOption($definition);
        $this->activityService->configureInput($definition);

        $this->addExample('Delete the currently checked out environment');
        $this->addExample('Delete the environments "test" and "example-1"', 'test example-1');
        $this->addExample('Delete all inactive environments', '--inactive');
        $this->addExample('Delete all environments merged with their parent', '--merged');

        $service = $this->config->get('service.name');
        $this->setHelp(<<<EOF
When a {$service} environment is deleted, it will become "inactive": it will
exist only as a Git branch, containing code but no services, databases nor
files.

This command allows you to delete environment(s) as well as their Git branches.
EOF
        );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $selection = $this->selector->getSelection($input);

        $environments = $this->api->getEnvironments($selection->getProject());

        /**
         * A list of selected environments, keyed by ID to avoid duplication.
         *
         * @var array<string, Environment> $selectedEnvironments
         */
        $selectedEnvironments = [];
        $error = false;

        $anythingSpecified = false;

        // Add the environment(s) specified in the arguments or options.
        $specifiedEnvironmentIds = ArrayArgument::getArgument($input, 'environment');
        if ($input->getOption('environment')) {
            $specifiedEnvironmentIds = array_merge([$input->getOption('environment')], $specifiedEnvironmentIds);
        }
        if ($specifiedEnvironmentIds) {
            $anythingSpecified = true;
            $allIds = \array_map(function (Environment $e) { return $e->id; }, $environments);
            $specifiedEnvironmentIds = Wildcard::select($allIds, $specifiedEnvironmentIds);
            $notFound = array_diff($specifiedEnvironmentIds, array_keys($environments));
            if (!empty($notFound)) {
                // Refresh the environments list if any environment is not found.
                $environments = $this->api->getEnvironments($selection->getProject(), true);
                $notFound = array_diff($specifiedEnvironmentIds, array_keys($environments));
            }
            foreach ($notFound as $notFoundId) {
                $this->stdErr->writeln("Environment not found: <error>$notFoundId</error>");
                $error = true;
            }
            $specifiedEnvironments = array_intersect_key($environments, array_flip($specifiedEnvironmentIds));
            $this->stdErr->writeln(count($specifiedEnvironments) . ' environment(s) found by ID');
            $this->stdErr->writeln('');
            foreach ($specifiedEnvironments as $specifiedEnvironment) {
                $selectedEnvironments[$specifiedEnvironment->id] = $specifiedEnvironment;
            }
        }

        // Gather inactive environments.
        if ($input->getOption('inactive')) {
            $anythingSpecified = true;
            if ($input->getOption('no-delete-branch')) {
                $this->stdErr->writeln('The option --no-delete-branch cannot be combined with --inactive.');

                return 1;
            }
            $inactive = array_filter(
                $environments,
                function ($environment) {
                    return $environment->status == 'inactive';
                }
            );
            $this->stdErr->writeln(count($inactive) . ' inactive environment(s) found.');
            $this->stdErr->writeln('');
            foreach ($inactive as $inactiveEnv) {
                $selectedEnvironments[$inactiveEnv->id] = $inactiveEnv;
            }
        }

        // Gather merged environments.
        if ($input->getOption('merged')) {
            $anythingSpecified = true;
            $merged = [];
            foreach ($environments as $environment) {
                $merge_info = $environment->getProperty('merge_info', false) ?: [];
                if (isset($environment->parent, $merge_info['commits_ahead'], $merge_info['parent_ref']) && $merge_info['commits_ahead'] === 0) {
                    $selectedEnvironments[$environment->id] = $merged[$environment->id] = $environment;
                }
            }
            $this->stdErr->writeln(count($merged) . ' merged environment(s) found.');
            $this->stdErr->writeln('');
        }

        // Gather environments with the specified --type (can be multiple).
        if ($types = ArrayArgument::getOption($input, 'type')) {
            $anythingSpecified = true;
            $withTypes = [];
            foreach ($environments as $environment) {
                if ($environment->hasProperty('type') && \in_array($environment->getProperty('type'), $types)) {
                    $selectedEnvironments[$environment->id] = $withTypes[$environment->id] = $environment;
                }
            }
            $this->stdErr->writeln(count($withTypes) . ' environment(s) found matching type(s): ' . implode(', ', $types));
            $this->stdErr->writeln('');
        }

        // Add the current environment if nothing is otherwise specified.
        if (!$anythingSpecified
            && empty($selectedEnvironments)
            && ($current = $this->selector->getCurrentEnvironment($selection->getProject()))) {
            $this->stdErr->writeln('Nothing specified; selecting the current environment: '. $this->api->getEnvironmentLabel($current));
            $this->stdErr->writeln('');
            $selectedEnvironments[$current->id] = $current;
        }

        // Exclude environment type(s) specified via --exclude-type or --only-type.
        $excludeTypes = ArrayArgument::getOption($input, 'exclude-type');
        $onlyTypes = ArrayArgument::getOption($input, 'only-type');
        $filtered = \array_filter($selectedEnvironments, function (Environment $environment) use ($excludeTypes, $onlyTypes) {
            $type = $environment->getProperty('type', false, false);
            if ($type !== null && \in_array($type, $excludeTypes, true)) {
                return false;
            }
            if (!empty($onlyTypes) && ($type === null || !\in_array($type, $onlyTypes, true))) {
                return false;
            }
            return true;
        });
        if (($numExcluded = count($selectedEnvironments) - count($filtered)) > 0) {
            $this->stdErr->writeln($numExcluded . ' environment(s) excluded by type.');
            $this->stdErr->writeln('');
        }
        $selectedEnvironments = $filtered;

        // Exclude environment ID(s) specified in --exclude.
        $excludeIds = ArrayArgument::getOption($input, 'exclude');
        if (!empty($excludeIds)) {
            $resolved = Wildcard::select(\array_keys($selectedEnvironments), $excludeIds);
            if (count($resolved)) {
                $selectedEnvironments = \array_intersect_key($selectedEnvironments, \array_flip($resolved));
                $this->stdErr->writeln(count($resolved) . ' environment(s) excluded by ID.');
                $this->stdErr->writeln('');
            }
        }

        if (count($selectedEnvironments)) {
            $this->stdErr->writeln('Selected environment(s): ' . $this->listEnvironments($selectedEnvironments));
            $this->stdErr->writeln('');
        }

        // Confirm which of the environments the user wishes to be deleted.
        $this->api->sortResources($selectedEnvironments, 'id');
        $toDeleteBranch = [];
        $toDeactivate = [];
        foreach ($selectedEnvironments as $environment) {
            $environmentId = $environment->id;
            // Check that the environment does not have children.
            // @todo remove this check when Platform's behavior is fixed
            foreach ($environments as $potentialChild) {
                if ($potentialChild->parent === $environment->id) {
                    $this->stdErr->writeln(\sprintf(
                        "The environment %s has children and therefore can't be deleted.",
                        $this->api->getEnvironmentLabel($environment, 'error')
                    ));
                    $this->stdErr->writeln("Please delete the environment's children first.");
                    $error = true;
                    continue 2;
                }
            }
            if ($environment->isActive()) {
                $this->stdErr->writeln(\sprintf(
                    'The environment %s is currently active: deleting it will delete all associated data.',
                    $this->api->getEnvironmentLabel($environment, 'comment')
                ));
                if ($this->questionHelper->confirm('Are you sure you want to delete this environment?')) {
                    $toDeactivate[$environmentId] = $environment;
                    if (!$input->getOption('no-delete-branch')
                        && $this->activityService->shouldWait($input)
                        && ($input->getOption('delete-branch')
                            || (
                                $input->isInteractive()
                                && $this->questionHelper->confirm("Delete the remote Git branch too?")
                            )
                        )) {
                        $toDeleteBranch[$environmentId] = $environment;
                    }
                } else {
                    $error = true;
                }
            } elseif ($environment->status === 'inactive') {
                if ($this->questionHelper->confirm("Are you sure you want to delete the remote Git branch <comment>$environmentId</comment>?")) {
                    $toDeleteBranch[$environmentId] = $environment;
                } else {
                    $error = true;
                }
            } elseif ($environment->status === 'dirty') {
                $this->stdErr->writeln("The environment <error>$environmentId</error> is currently building, and therefore can't be deleted. Please wait.");
                $error = true;
            } elseif ($environment->status === 'deleting') {
                $this->stdErr->writeln("The environment <info>$environmentId</info> is already being deleted.");
            } else {
                $this->stdErr->writeln("The environment <error>$environmentId</error> has an unrecognised status <error>" . $environment->status . "</error>.");
                $error = true;
            }
        }

        if (empty($toDeleteBranch) && empty($toDeactivate)) {
            if ($error) {
                $this->stdErr->writeln('');
            }
            $this->stdErr->writeln('No environment(s) to delete.');
            if (!$anythingSpecified) {
                $this->stdErr->writeln(\sprintf('For help, run: <info>%s help environment:delete</info>', $this->config->get('application.executable')));
            }

            return $error ? 1 : 0;
        }

        $success = $this->deleteMultiple($toDeactivate, $toDeleteBranch, $input, $selection->getProject()) && !$error;

        return $success ? 0 : 1;
    }

    /**
     * @param Environment[] $environments
     *
     * @return string
     */
    private function listEnvironments(array $environments)
    {
        $uniqueIds = \array_unique(\array_map(function(Environment $e) { return $e->id; }, $environments));
        natcasesort($uniqueIds);
        return '<info>' . implode('</info>, <info>', $uniqueIds) . '</info>';
    }

    /**
     * @param array $toDeactivate
     * @param array $toDeleteBranch
     * @param InputInterface $input
     * @param Project $project
     *
     * @return bool
     */
    protected function deleteMultiple(array $toDeactivate, array $toDeleteBranch, InputInterface $input, Project $project): bool
    {
        $error = false;
        $deactivateActivities = [];
        $deactivated = 0;
        /** @var Environment $environment */
        foreach ($toDeactivate as $environmentId => $environment) {
            try {
                $this->stdErr->writeln("Deleting environment <info>$environmentId</info>");
                $deactivateActivities[] = $environment->deactivate();
                $deactivated++;
            } catch (\Exception $e) {
                $this->stdErr->writeln($e->getMessage());
            }
        }

        if ($this->activityService->shouldWait($input)) {
            if (!$this->activityService->waitMultiple($deactivateActivities, $project)) {
                $error = true;
            }
        }

        $deleted = 0;
        foreach ($toDeleteBranch as $environmentId => $environment) {
            try {
                if ($environment->status !== 'inactive') {
                    $environment->refresh();
                    if ($environment->status !== 'inactive') {
                        $this->stdErr->writeln("Cannot delete branch <error>$environmentId</error>: it is not (yet) inactive.");
                        continue;
                    }
                }
                $environment->delete();
                $this->stdErr->writeln("Deleted remote Git branch <info>$environmentId</info>");
                $deleted++;
            } catch (\Exception $e) {
                $this->stdErr->writeln($e->getMessage());
            }
        }

        if ($deleted > 0) {
            $this->stdErr->writeln("Run <info>git fetch --prune</info> to remove deleted branches from your local cache.");
        }

        if ($deleted < count($toDeleteBranch) || $deactivated < count($toDeactivate)) {
            $error = true;
        }

        if (($deleted || $deactivated || $error) && isset($environment)) {
            $this->api->clearEnvironmentsCache($environment->project);
        }

        return !$error;
    }
}
