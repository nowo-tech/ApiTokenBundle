<?php

declare(strict_types=1);

namespace Nowo\ApiTokenBundle\Infrastructure\Console;

use Nowo\ApiTokenBundle\Contract\ApiTokenIssuerInterface;
use Nowo\ApiTokenBundle\Contract\Exception\ApiTokenIssueRefusedException;
use Nowo\ApiTokenBundle\Contract\Exception\InvalidApiTokenArgumentException;
use Nowo\ApiTokenBundle\Contract\Grants;
use Nowo\ApiTokenBundle\Contract\IssueRequest;
use Nowo\ApiTokenBundle\Contract\OwnerRef;
use Nowo\ApiTokenBundle\Contract\Reach;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\ConsoleOutputInterface;
use Symfony\Component\Console\Output\OutputInterface;

use function explode;
use function is_array;
use function is_numeric;
use function is_string;
use function sprintf;
use function str_contains;

/**
 * `nowo:api-token:issue`: issues a token from the command line (tests and break-glass), channel `cli`. The token is
 * written once to standard output and nowhere else; everything else goes to standard error.
 */
#[AsCommand(name: 'nowo:api-token:issue', description: 'Issue a personal API token (printed once)')]
final class IssueTokenCommand extends Command
{
    /**
     * @param string $ownerType default owner type (`owner.type`)
     * @param int $defaultDays `expiry.default_days`
     */
    public function __construct(
        private readonly ApiTokenIssuerInterface $issuer,
        private readonly string $ownerType = 'user',
        private readonly int $defaultDays = 90,
    ) {
        parent::__construct();
    }

    /**
     * Arguments and options.
     */
    protected function configure(): void
    {
        $this
            ->addArgument('owner-id', InputArgument::REQUIRED, 'Owner id (compared exactly)')
            ->addOption('owner-type', null, InputOption::VALUE_REQUIRED, 'Owner type', $this->ownerType)
            ->addOption('name', null, InputOption::VALUE_REQUIRED, 'Token name (unique per owner)')
            ->addOption('grant', 'g', InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'module:level (repeatable)')
            ->addOption('flag', null, InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'module:flag (repeatable)')
            ->addOption('reach', null, InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'Reach entry (repeatable); none = everything')
            ->addOption('days', null, InputOption::VALUE_REQUIRED, 'Lifetime in days', (string) $this->defaultDays);
    }

    /**
     * Issues and prints the token once.
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $errors = $output instanceof ConsoleOutputInterface ? $output->getErrorOutput() : $output;
        try {
            $request = new IssueRequest(
                new OwnerRef($this->string($input->getOption('owner-type')), $this->string($input->getArgument('owner-id'))),
                $this->string($input->getOption('name')),
                new Grants($this->pairs($input->getOption('grant')), $this->flags($input->getOption('flag'))),
                $this->reach($input->getOption('reach')),
                $this->days($input->getOption('days')),
                'cli',
            );
            $issued = $this->issuer->issue($request);
        } catch (InvalidApiTokenArgumentException|ApiTokenIssueRefusedException $exception) {
            $errors->writeln(sprintf('<error>%s</error>', $exception->getMessage()));

            return Command::INVALID;
        }

        $errors->writeln(sprintf('Token "%s" (id %s, lookup %s) expires %s. It is shown only now:', $issued->token->name, $issued->token->id->value, $issued->token->lookupId->value, $issued->token->expiresAt->format('Y-m-d H:i:s \U\T\C')));
        $output->writeln($issued->secret(), OutputInterface::OUTPUT_RAW);

        return Command::SUCCESS;
    }

    private function string(mixed $value): string
    {
        return is_string($value) ? $value : '';
    }

    private function days(mixed $value): int
    {
        if (!is_numeric($value)) {
            throw new InvalidApiTokenArgumentException('--days must be a number.');
        }

        return (int) $value;
    }

    /**
     * @return array<string, string>
     */
    private function pairs(mixed $values): array
    {
        $pairs = [];
        foreach (is_array($values) ? $values : [] as $value) {
            [$module, $level] = $this->split($value, '--grant');
            $pairs[$module]   = $level;
        }

        return $pairs;
    }

    /**
     * @return array<string, list<string>>
     */
    private function flags(mixed $values): array
    {
        $flags = [];
        foreach (is_array($values) ? $values : [] as $value) {
            [$module, $flag]  = $this->split($value, '--flag');
            $flags[$module][] = $flag;
        }

        return $flags;
    }

    /**
     * @return array{string, string}
     */
    private function split(mixed $value, string $option): array
    {
        if (!is_string($value) || !str_contains($value, ':')) {
            throw new InvalidApiTokenArgumentException(sprintf('%s expects module:value.', $option));
        }
        [$module, $rest] = explode(':', $value, 2);

        return [$module, $rest];
    }

    private function reach(mixed $values): Reach
    {
        $entries = [];
        foreach (is_array($values) ? $values : [] as $value) {
            $entries[] = $this->string($value);
        }

        return $entries === [] ? Reach::everything() : Reach::limitedTo($entries);
    }
}
