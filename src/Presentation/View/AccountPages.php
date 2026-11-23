<?php

declare(strict_types=1);

namespace Nowo\ApiTokenBundle\Presentation\View;

use Closure;
use DateTimeImmutable;
use Nowo\ApiTokenBundle\Contract\ApiTokenView;
use Nowo\ApiTokenBundle\NowoApiTokenBundle;
use Nowo\UiKitBundle\View\Action;
use Nowo\UiKitBundle\View\Column;
use Nowo\UiKitBundle\View\Confirm;
use Nowo\UiKitBundle\View\EmptyState;
use Nowo\UiKitBundle\View\FormPage;
use Nowo\UiKitBundle\View\Label;
use Nowo\UiKitBundle\View\ListPage;
use Nowo\UiKitBundle\View\PageHeader;
use Symfony\Component\Form\FormView;

use function assert;
use function substr;

/**
 * Builds the UiKit pages of the account area (contract §8.1): the token list, the *New token* and *Revoke all* forms.
 * Pure: every URL comes from the closure, every text is a translation key of the `NowoApiTokenBundle` domain.
 */
final readonly class AccountPages
{
    /** Translation domain of every label. */
    public const DOMAIN = NowoApiTokenBundle::TRANSLATION_DOMAIN;

    /** Bundle name for per-bundle theme exceptions. */
    public const BUNDLE = 'NowoApiTokenBundle';

    /** An expiry within this many days gets the warning chip. */
    public const EXPIRY_WARNING_DAYS = 7;

    /** Characters of the lookup id shown in the handle. */
    public const HANDLE_LENGTH = 4;

    /**
     * @param Closure(string, array<string, string>=): string $url route name + parameters → URL
     * @param string $prefix `token_prefix`, for the handle
     * @param list<string> $moduleOrder registry order of the module keys
     * @param string|null $layout `web_ui.layout_template` (or `admin_ui`); null = the UiKit admin layout
     */
    public function __construct(private Closure $url, private string $prefix, private array $moduleOrder = [], private ?string $layout = null)
    {
    }

    /**
     * The account list. `$revokeForm` is the CSRF form of the row action; `$revokeAll` whether the header shows
     * *Revoke all* (there are tokens).
     *
     * @param list<ApiTokenView> $tokens
     */
    public function list(array $tokens, FormView $revokeForm, DateTimeImmutable $now): ListPage
    {
        $rows   = $this->rows($tokens, $now);
        $create = new Action('create', $this->label('account.new'), ($this->url)('nowo_api_token_account_new'), variant: 'primary', attributes: ['data-testid' => 'api-token-new']);
        $header = [$create];
        if ($tokens !== []) {
            $header[] = new Action('revoke', $this->label('account.revoke_all'), ($this->url)('nowo_api_token_account_revoke_all'), variant: 'danger', attributes: ['data-testid' => 'api-token-revoke-all']);
        }

        return new ListPage(
            new PageHeader($this->label('account.title'), $this->label('account.intro'), $header),
            $this->columns(false),
            $rows,
            null,
            function (mixed $row) use ($revokeForm): array {
                assert($row instanceof TokenRow);

                return [$this->revoke('nowo_api_token_account_revoke', $row, $revokeForm)];
            },
            null,
            new EmptyState($this->label('account.empty_title'), $this->label('account.empty'), $create),
            caption: $this->label('account.caption'),
            bundle: self::BUNDLE,
            layout: $this->layout,
            attributes: ['data-testid' => 'api-token-list'],
            captionVisible: false,
        );
    }

    /**
     * The *New token* form page (also the dialog fragment's page object).
     */
    public function newToken(FormView $form): FormPage
    {
        return new FormPage(
            new PageHeader($this->label('new.title'), $this->label('new.intro')),
            $form,
            $this->label('new.submit'),
            ($this->url)('nowo_api_token_account'),
            bundle: self::BUNDLE,
            layout: $this->layout,
            attributes: ['data-testid' => 'api-token-new-form'],
        );
    }

    /**
     * The *Revoke all* form page.
     */
    public function revokeAll(FormView $form, int $count): FormPage
    {
        return new FormPage(
            new PageHeader($this->label('revoke_all.title'), $this->label('revoke_all.intro', ['%count%' => $count])),
            $form,
            $this->label('revoke_all.submit'),
            ($this->url)('nowo_api_token_account'),
            bundle: self::BUNDLE,
            layout: $this->layout,
            attributes: ['data-testid' => 'api-token-revoke-all-form'],
        );
    }

    /**
     * Table rows of these tokens.
     *
     * @param list<ApiTokenView> $tokens
     *
     * @return list<TokenRow>
     */
    public function rows(array $tokens, DateTimeImmutable $now): array
    {
        $rows = [];
        foreach ($tokens as $token) {
            $modules     = [];
            $destructive = false;
            foreach ($this->moduleOrder as $module) {
                $level = $token->grants->level($module);
                if ($level === null) {
                    continue;
                }
                $flags     = $token->grants->flags[$module] ?? [];
                $modules[] = ['module' => $module, 'level' => $level, 'flags' => $flags];
                foreach ($flags as $flag) {
                    $destructive = $destructive || $flag === 'destructive';
                }
            }
            $rows[] = new TokenRow(
                $token,
                $this->prefix . substr($token->lookupId->value, 0, self::HANDLE_LENGTH) . '…',
                $modules,
                $token->expiresAt <= $now->modify('+' . self::EXPIRY_WARNING_DAYS . ' days'),
                $destructive,
            );
        }

        return $rows;
    }

    /**
     * The columns of the account (`$operator` false) or operator table.
     *
     * @return list<Column>
     */
    public function columns(bool $operator): array
    {
        $columns = [];
        if ($operator) {
            $columns[] = new Column('owner', $this->label('column.owner'), required: true);
        }

        return [
            ...$columns,
            new Column('name', $this->label('column.name'), required: true, phone: 'primary'),
            new Column('handle', $this->label('column.handle'), required: true),
            new Column('modules', $this->label('column.modules')),
            new Column('reach', $this->label('column.reach'), priority: 2),
            new Column('created', $this->label('column.created'), priority: 3),
            new Column('last_used', $this->label('column.last_used'), priority: 2),
            new Column('expires', $this->label('column.expires')),
        ];
    }

    /**
     * The revoke row action (confirm dialog + CSRF form).
     */
    public function revoke(string $route, TokenRow $row, FormView $form): Action
    {
        return Action::delete(
            ($this->url)($route, ['id' => $row->token->id->value]),
            null,
            new Confirm($this->label('revoke.title', ['%name%' => $row->token->name]), $this->label('revoke.message'), $this->label('revoke.submit')),
            $this->label('revoke.action'),
            $form,
        );
    }

    /**
     * @param array<string, int|string> $params
     */
    public function label(string $key, array $params = []): Label
    {
        return new Label($key, self::DOMAIN, $params);
    }
}
