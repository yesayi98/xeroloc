<?php

namespace App\Command;

use App\Service\XeroMockStore;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'app:mock-user:add', description: 'Create or update a mock user credential set for the Xero mock authorize flow.')]
final class AddMockUserCommand extends Command
{
    public function __construct(private readonly XeroMockStore $store)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('email', InputArgument::REQUIRED, 'Mock user email')
            ->addArgument('password', InputArgument::REQUIRED, 'Mock user password')
            ->addOption('first-name', null, InputOption::VALUE_REQUIRED, 'First name', 'Mock')
            ->addOption('last-name', null, InputOption::VALUE_REQUIRED, 'Last name', 'User');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $email = trim((string) $input->getArgument('email'));
        $password = (string) $input->getArgument('password');

        if ($email === '' || $password === '') {
            $io->error('Both email and password are required.');

            return Command::INVALID;
        }

        $user = $this->store->createMockUser(
            email: $email,
            password: $password,
            firstName: trim((string) $input->getOption('first-name')),
            lastName: trim((string) $input->getOption('last-name')),
        );

        $io->success(sprintf(
            'Saved mock user %s (%s %s).',
            $user['Email'],
            $user['FirstName'],
            $user['LastName'],
        ));

        return Command::SUCCESS;
    }
}
