<?php

declare(strict_types=1);

namespace Nowo\ApiTokenBundle\Contract;

use Nowo\ApiTokenBundle\Contract\Exception\InvalidApiTokenArgumentException;

use function array_diff;
use function array_unique;
use function array_values;
use function count;
use function is_array;
use function is_string;
use function preg_match;
use function sprintf;
use function str_contains;
use function strlen;
use function strstr;
use function substr;

/**
 * Where a token works (tenant "where", contract §6.5): *everything the person can reach* (optionally minus some
 * entries) or a limited list. An empty list reaches nothing; nothing here ever widens.
 *
 * Entries: `^[A-Za-z0-9._:-]{1,128}$`; a bare entry is a tenant key, `kind:value` names a place of a kind
 * (`wiki:<slug>`, `board:<id>`).
 */
final readonly class Reach
{
    /** Hard cap of entries a reach can hold (the configured `reach.max_entries` is ≤ this). */
    public const MAX_ENTRIES = 1000;

    /** One entry. */
    public const ENTRY_PATTERN = '/^[A-Za-z0-9._:-]{1,128}$/D';

    /**
     * @param list<string> $entries limited entries, or the exceptions of *everything*
     */
    private function __construct(private bool $everything, private array $entries)
    {
    }

    /**
     * Everything the person can reach, minus the given entries.
     *
     * @param list<string> $except
     *
     * @throws InvalidApiTokenArgumentException when an entry is invalid or there are too many
     */
    public static function everything(array $except = []): self
    {
        return new self(true, self::checked($except));
    }

    /**
     * Only these entries; an empty list reaches nothing.
     *
     * @param list<string> $entries
     *
     * @throws InvalidApiTokenArgumentException when an entry is invalid or there are too many
     */
    public static function limitedTo(array $entries): self
    {
        return new self(false, self::checked($entries));
    }

    /**
     * Tolerant reader of a stored row. A damaged entry list fails closed: *limited* keeps the valid entries only, and
     * *everything* with damaged exceptions becomes *nothing* (dropping an exception would widen it).
     */
    public static function fromStored(bool $everything, mixed $entries): self
    {
        $valid   = [];
        $damaged = !is_array($entries);
        foreach (is_array($entries) ? $entries : [] as $entry) {
            if (is_string($entry) && preg_match(self::ENTRY_PATTERN, $entry) === 1) {
                $valid[] = $entry;
            } else {
                $damaged = true;
            }
        }
        $valid = array_values(array_unique($valid));
        if (count($valid) > self::MAX_ENTRIES) {
            return new self(false, []);
        }
        if ($everything) {
            return $damaged ? new self(false, []) : new self(true, $valid);
        }

        return new self(false, $valid);
    }

    /**
     * Whether the token reaches everything the person can reach (minus {@see self::exceptions()}).
     */
    public function isEverything(): bool
    {
        return $this->everything;
    }

    /**
     * The limited entries ([] for *everything*). `$kind` = null returns the bare tenant keys; a kind returns the
     * values of `kind:value` entries of that kind.
     *
     * @return list<string>
     */
    public function entries(?string $kind = null): array
    {
        return $this->everything ? [] : $this->ofKind($this->entries, $kind);
    }

    /**
     * Every limited entry as stored ([] for *everything*).
     *
     * @return list<string>
     */
    public function allEntries(): array
    {
        return $this->everything ? [] : $this->entries;
    }

    /**
     * The exceptions of *everything* ([] for a limited reach), filtered like {@see self::entries()}.
     *
     * @return list<string>
     */
    public function exceptions(?string $kind = null): array
    {
        return $this->everything ? $this->ofKind($this->entries, $kind) : [];
    }

    /**
     * Narrows: removes the entries from a limited reach, or adds them to the exceptions of *everything*.
     *
     * @param list<string> $entries
     *
     * @throws InvalidApiTokenArgumentException when an entry is invalid
     */
    public function without(array $entries): self
    {
        $entries = self::checked($entries, false);
        if ($this->everything) {
            return new self(true, array_values(array_unique([...$this->entries, ...$entries])));
        }

        return new self(false, array_values(array_diff($this->entries, $entries)));
    }

    /**
     * The stored entry list (limited entries, or the exceptions of *everything*).
     *
     * @return list<string>
     */
    public function storedEntries(): array
    {
        return $this->entries;
    }

    /**
     * `everything` or the number of entries, for events and lists (entries can name tenants).
     */
    public function summary(): string|int
    {
        return $this->everything ? 'everything' : count($this->entries);
    }

    /**
     * @param array<mixed> $entries
     *
     * @return list<string>
     */
    private static function checked(array $entries, bool $capped = true): array
    {
        foreach ($entries as $entry) {
            if (!is_string($entry) || preg_match(self::ENTRY_PATTERN, $entry) !== 1) {
                throw new InvalidApiTokenArgumentException('Invalid reach entry: expected ^[A-Za-z0-9._:-]{1,128}$.');
            }
        }
        /** @var list<string> $unique */
        $unique = array_values(array_unique($entries));
        if ($capped && count($unique) > self::MAX_ENTRIES) {
            throw new InvalidApiTokenArgumentException(sprintf('A reach holds at most %d entries.', self::MAX_ENTRIES));
        }

        return $unique;
    }

    /**
     * @param list<string> $entries
     *
     * @return list<string>
     */
    private function ofKind(array $entries, ?string $kind): array
    {
        $result = [];
        foreach ($entries as $entry) {
            if ($kind === null) {
                if (!str_contains($entry, ':')) {
                    $result[] = $entry;
                }
            } elseif (strstr($entry, ':', true) === $kind) {
                $result[] = substr($entry, strlen($kind) + 1);
            }
        }

        return $result;
    }
}
