<?php

declare(strict_types=1);

namespace Nowo\ApiTokenBundle\Presentation\View;

use Closure;
use DateTimeImmutable;
use Nowo\ApiTokenBundle\Contract\ApiTokenView;
use Nowo\UiKitBundle\View\Action;
use Nowo\UiKitBundle\View\EmptyState;
use Nowo\UiKitBundle\View\FilterField;
use Nowo\UiKitBundle\View\ListPage;
use Nowo\UiKitBundle\View\PageHeader;
use Symfony\Component\Form\FormView;

use function assert;

/**
 * Builds the operator page (contract §8.2): every token (owner, name, full handle `<prefix><lookup id>`, modules,
 * dates), keyset pages, filters by owner key and lookup id, *Revoke*. No *issue on behalf* in v1.
 */
final readonly class OperatorPages
{
    /**
     * @param Closure(string, array<string, string>=): string $url
     */
    public function __construct(private Closure $url, private AccountPages $account)
    {
    }

    /**
     * The operator list.
     *
     * @param list<ApiTokenView> $tokens
     */
    public function list(array $tokens, FormView $filters, bool $filtered, FormView $revokeForm, DateTimeImmutable $now): ListPage
    {
        return new ListPage(
            new PageHeader($this->account->label('operator.title'), $this->account->label('operator.intro')),
            $this->account->columns(true),
            // The whole lookup id (public, stored in clear): operators match a reported token by eye, no paste needed.
            $this->account->rows($tokens, $now, true),
            $filters,
            function (mixed $row) use ($revokeForm): array {
                assert($row instanceof TokenRow);

                return [$this->account->revoke('nowo_api_token_operator_revoke', $row, $revokeForm)];
            },
            null,
            new EmptyState($this->account->label($filtered ? 'operator.empty_filtered' : 'operator.empty')),
            $filtered ? ($this->url)('nowo_api_token_operator') : null,
            caption: $this->account->label('operator.caption'),
            bundle: AccountPages::BUNDLE,
            attributes: ['data-testid' => 'api-token-operator-list'],
            filterFields: [new FilterField('owner', width: 'md'), new FilterField('lookup', width: 'md')],
            filtersSubmit: new Action('filter', $this->account->label('operator.filter')),
            captionVisible: false,
        );
    }
}
