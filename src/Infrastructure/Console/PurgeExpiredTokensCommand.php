<?php

declare(strict_types=1);

namespace Nowo\ApiTokenBundle\Infrastructure\Console;

use DateTimeZone;
use Nowo\ApiTokenBundle\Application\Service\ApiTokenRevoker;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\Clock\NativeClock;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Throwable;

use function hrtime;
use function sprintf;

/**
 * `nowo:api-token:purge-expired`: deletes the tokens that expired more than `expiry.purge_after_days` ago, in keyset
 * batches of `messenger.batch_size`, DBAL only, reason `expired_purge` (listeners and events run per token). Stops
 * after `messenger.max_seconds`; run it again for the rest. Logs its start, its result and a failure (REQ-OBS-001). Expired tokens are refused from the moment they expire:
 * the purge only removes the rows.
 */
#[AsCommand(name: 'nowo:api-token:purge-expired', description: 'Delete API tokens that expired before the purge window')]
final class PurgeExpiredTokensCommand extends Command
{
    private ClockInterface $clock;

    /**
     * Wires the command.
     */
    public function __construct(
        private readonly ApiTokenRevoker $revoker,
        private readonly int $purgeAfterDays = 30,
        private readonly int $batchSize = 500,
        private readonly int $maxSeconds = 50,
        ?ClockInterface $clock = null,
        private readonly ?LoggerInterface $logger = null,
    ) {
        parent::__construct();
        $this->clock = $clock ?? new NativeClock();
    }

    /**
     * Purges and prints how many rows went.
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        // @igor-ignore - PSR clock read; DateTimeImmutable returns a new value, nothing is mutated.
        $cutoff   = $this->clock->now()->setTimezone(new DateTimeZone('UTC'))->modify(sprintf('-%d days', $this->purgeAfterDays));
        $deadline = hrtime(true) + $this->maxSeconds * 1_000_000_000;
        $logger   = $this->logger ?? new NullLogger();
        $context  = ['cutoff' => $cutoff->format('Y-m-d H:i:s'), 'batch_size' => $this->batchSize, 'max_seconds' => $this->maxSeconds];
        $logger->info('nowo_api_token: purge of expired tokens started.', $context);
        try {
            $deleted = $this->revoker->purgeExpired($cutoff, $this->batchSize, static fn (): bool => hrtime(true) < $deadline);
        } catch (Throwable $exception) {
            $logger->error('nowo_api_token: purge of expired tokens failed: {class}', $context + ['class' => $exception::class, 'exception' => $exception]);

            throw $exception;
        }
        $logger->info('nowo_api_token: purge of expired tokens finished, {deleted} row(s) deleted.', $context + ['deleted' => $deleted]);
        $output->writeln(sprintf('Purged %d expired API token(s).', $deleted));

        return Command::SUCCESS;
    }
}
