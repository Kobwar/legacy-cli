<?php
namespace Platformsh\Cli\Command\Integration;

use Platformsh\Client\Exception\OperationUnavailableException;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class IntegrationValidateCommand extends IntegrationCommandBase
{
    protected static $defaultName = 'integration:validate';
    protected static $defaultDescription = 'Validate an existing integration';

    /**
     * {@inheritdoc}
     */
    protected function configure()
    {
        $this->addArgument('id', InputArgument::OPTIONAL, 'An integration ID. Leave blank to choose from a list.');
        $this->selector->addProjectOption($this->getDefinition());
        $this->setHelp(<<<EOF
This command allows you to check whether an integration is valid.

An exit code of 0 means the integration is valid, while 4 means it is invalid.
Any other exit code indicates an unexpected error.

Integrations are validated automatically on creation and on update. However,
because they involve external resources, it is possible for a valid integration
to become invalid. For example, an access token may be revoked, or an external
repository may be deleted.
EOF
        );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $project = $this->selector->getSelection($input)->getProject();

        $integration = $this->selectIntegration($project, $input->getArgument('id'), $input->isInteractive());
        if (!$integration) {
            return 1;
        }

        $this->stdErr->writeln(sprintf(
            'Validating the integration <info>%s</info> (type: %s)...',
            $integration->id,
            $integration->type
        ));

        try {
            $errors = $integration->validate();
        } catch (OperationUnavailableException $e) {
            $this->stdErr->writeln('This integration does not support validation.');

            return 1;
        }
        if (empty($errors)) {
            $this->stdErr->writeln('The integration is valid.');

            return 0;
        }

        $this->stdErr->writeln('');

        $this->listValidationErrors($errors, $output);

        // The exit code for an invalid integration (see the command help).
        return 4;
    }
}
