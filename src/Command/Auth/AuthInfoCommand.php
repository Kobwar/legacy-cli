<?php
declare(strict_types=1);

namespace Platformsh\Cli\Command\Auth;

use Platformsh\Cli\Command\CommandBase;
use Platformsh\Cli\Service\Api;
use Platformsh\Cli\Service\Config;
use Platformsh\Cli\Service\PropertyFormatter;
use Platformsh\Cli\Service\Table;
use Symfony\Component\Console\Exception\InvalidArgumentException;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class AuthInfoCommand extends CommandBase
{
    protected static $defaultName = 'auth:info';
    protected static $defaultDescription = 'Display your account information';

    private $api;
    private $config;
    private $formatter;
    private $table;

    public function __construct(Api $api, Config $config, PropertyFormatter $formatter, Table $table)
    {
        $this->api = $api;
        $this->config = $config;
        $this->formatter = $formatter;
        $this->table = $table;
        parent::__construct();
    }

    protected function configure()
    {
        $this->addArgument('property', InputArgument::OPTIONAL, 'The account property to view')
            ->addOption('no-auto-login', null, InputOption::VALUE_NONE, 'Skips auto login. Nothing will be output if not logged in, and the exit code will be 0, assuming no other errors.')
            ->addOption('property', 'P', InputOption::VALUE_REQUIRED, 'The account property to view (alternate syntax)')
            ->addOption('refresh', null, InputOption::VALUE_NONE, 'Whether to refresh the cache');

        $this->table->configureInput($this->getDefinition());

        $this->addExample('Print your user ID', 'id');
        $this->addExample('Print your email address', 'email');
        $this->addExample('Print your user ID (or nothing if not logged in)', 'id --no-auto-login');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        if ($input->getOption('no-auto-login') && !$this->api->isLoggedIn()) {
            $this->stdErr->writeln('Not logged in', OutputInterface::VERBOSITY_VERBOSE);
            return 0;
        }

        if ($this->api->authApiEnabled()) {
            $info = $this->api->getUser()->getProperties();
        } else {
            // Backwards compatibility.
            $account = $this->api->getMyAccount((bool) $input->getOption('refresh'));
            $info = [
                'id' => $account['id'],
                'first_name' => '',
                'last_name' => '',
                'email' => $account['mail'],
                'username' => $account['username'],
            ];
            if (isset($account['display_name'])) {
                $parts = \explode(' ', $account['display_name'], 2);
                if (count($parts) === 2) {
                    [$info['first_name'], $info['last_name']] = $parts;
                } else {
                    $info['last_name'] = $account['display_name'];
                }
            }
        }

        $propertiesToDisplay = ['id', 'first_name', 'last_name', 'username', 'email'];
        $info = array_intersect_key($info, array_flip($propertiesToDisplay));

        $property = $input->getArgument('property');
        if ($input->getOption('property')) {
            if ($property) {
                throw new InvalidArgumentException(
                    sprintf(
                        'You cannot use both the <%s> argument and the --%s option',
                        'property',
                        'property'
                    )
                );
            }
            $property = $input->getOption('property');
        }

        if ($property) {
            if (!isset($info[$property])) {
                // Backwards compatibility.
                if ($property === 'display_name' && isset($info['first_name'], $info['last_name'])) {
                    $this->stdErr->writeln('<options=reverse>Deprecated:</> the "display_name" property has been replaced by "first_name" and "last_name".');
                    $info[$property] = \sprintf('%s %s', $info['first_name'], $info['last_name']);
                } elseif ($property === 'mail' && isset($info['email'])) {
                    $this->stdErr->writeln('<options=reverse>Deprecated:</> the "mail" property is now named "email".');
                    $info[$property] = $info['email'];
                } elseif ($property === 'uuid' && isset($info['id'])) {
                    $this->stdErr->writeln('<options=reverse>Deprecated:</> the "uuid" property is now named "id".');
                    $info[$property] = $info['id'];
                } else {
                    throw new InvalidArgumentException('Property not found: ' . $property);
                }
            }
            $output->writeln($this->formatter->format($info[$property], $property));

            return 0;
        }

        $values = [];
        $header = [];
        foreach ($propertiesToDisplay as $property) {
            if (isset($info[$property])) {
                $values[] = $this->formatter->format($info[$property], $property);
                $header[] = $property;
            }
        }
        $this->table->renderSimple($values, $header);

        if (!$this->table->formatIsMachineReadable() && ($this->config->getSessionId() !== 'default' || count($this->api->listSessionIds()) > 1)) {
            $this->stdErr->writeln('');
            $this->stdErr->writeln(sprintf('The current session ID is: <info>%s</info>', $this->config->getSessionId()));
            if (!$this->config->isSessionIdFromEnv()) {
                $this->stdErr->writeln(sprintf('Change this using: <info>%s session:switch</info>', $this->config->get('application.executable')));
            }
        }

        return 0;
    }
}
