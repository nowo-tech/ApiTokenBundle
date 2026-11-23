<?php

declare(strict_types=1);

namespace Nowo\ApiTokenBundle\Tests\Integration\Database;

use Nowo\ApiTokenBundle\Contract\ApiTokenIssuerInterface;
use Nowo\ApiTokenBundle\Contract\ApiTokenQueryInterface;
use Nowo\ApiTokenBundle\Contract\Event\ApiTokenAccessCheckEvent;
use Nowo\ApiTokenBundle\Contract\Event\ApiTokenIssuedEvent;
use Nowo\ApiTokenBundle\Contract\Event\ApiTokenRevokedEvent;
use Nowo\ApiTokenBundle\Contract\Grants;
use Nowo\ApiTokenBundle\Contract\IssuedToken;
use Nowo\ApiTokenBundle\Contract\IssueRequest;
use Nowo\ApiTokenBundle\Contract\Reach;
use Nowo\ApiTokenBundle\Contract\RevocationReason;
use Nowo\ApiTokenBundle\Infrastructure\Security\ApiTokenFirewallAuthenticator;
use Nowo\ApiTokenBundle\Tests\Fixtures\Ddd\MemberReauthGate;
use Nowo\ApiTokenBundle\Tests\Fixtures\ExpireReauthOnSubmit;
use Nowo\ApiTokenBundle\Tests\Fixtures\MemoryLogger;
use Nowo\ApiTokenBundle\Tests\Fixtures\MemoryLoggerPass;
use Nowo\ApiTokenBundle\Tests\Fixtures\PlacesReachProvider;
use Nowo\ApiTokenBundle\Tests\Fixtures\RecordingListener;
use Nowo\ApiTokenBundle\Tests\Fixtures\ShortPolicy;
use Nowo\ApiTokenBundle\Tests\Fixtures\ShortPolicyPass;
use Nowo\ApiTokenBundle\Tests\Fixtures\Standard\ActiveUserChecker;
use Nowo\ApiTokenBundle\Tests\Fixtures\Standard\User;
use Nowo\ApiTokenBundle\Tests\Fixtures\TestUserAuthenticator;
use Nowo\ApiTokenBundle\Tests\Integration\Kernel\Host;
use Nowo\ApiTokenBundle\Tests\Integration\Kernel\KernelTestCase;
use Nowo\ApiTokenBundle\Tests\Integration\Kernel\TestKernel;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\DependencyInjection\Loader\Configurator\ReferenceConfigurator;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\DomCrawler\Field\ChoiceFormField;
use Symfony\Component\DomCrawler\Form;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;

use function array_map;
use function array_replace_recursive;
use function json_encode;
use function serialize;
use function substr;
use function substr_count;

/**
 * The S3 page suite (contract §8, §6.10, §11 "Re-auth", "UI"), once per host kind: the account pages (list, *New
 * token* with the re-auth step, the one-time secret, revoke, *Revoke all*) and the operator page (access, filters,
 * revoke, per-token access event), with the framing and `no-store` headers.
 */
abstract class UiFunctionalTestCase extends KernelTestCase
{
    protected const ACCOUNT = '/account/security/api-tokens';

    protected const ADMIN = '/admin/api-tokens';

    protected TestKernel $kernel;

    abstract protected function host(): Host;

    /** The re-auth value that passes on this host (password, or the DDD gate's code). */
    abstract protected function goodReauth(): string;

    /** The re-auth field of a form on this host (the bundle's password field, or the gate's own form). */
    abstract protected function reauthField(string $form): string;

    public function testAnonymousVisitorsGetNoPage(): void
    {
        $client = $this->boot();
        foreach ([self::ACCOUNT, self::ACCOUNT . '/new', self::ADMIN] as $path) {
            $client->request('GET', $path);
            self::assertContains($client->getResponse()->getStatusCode(), [401, 403], $path);
        }
    }

    public function testTheListSendsTheFramingAndNoStoreHeaders(): void
    {
        $client = $this->boot();
        $this->as($client, 'alice', 'GET', self::ACCOUNT);
        $response = $client->getResponse();

        self::assertSame(200, $response->getStatusCode(), (string) $response->getContent());
        self::assertSame('DENY', $response->headers->get('X-Frame-Options'));
        self::assertContains("frame-ancestors 'none'", $response->headers->all('content-security-policy'));
        self::assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
        self::assertSame('same-origin', $response->headers->get('Referrer-Policy'));
        self::assertStringContainsString('data-testid="api-token-list"', (string) $response->getContent());
    }

    public function testIssuingNeedsAFreshReauthAndShowsTheSecretExactlyOnce(): void
    {
        $client  = $this->boot();
        $crawler = $this->as($client, 'alice', 'GET', self::ACCOUNT . '/new');
        self::assertSame(200, $client->getResponse()->getStatusCode(), (string) $client->getResponse()->getContent());
        self::assertCount(1, $crawler->filter('input[name="' . $this->reauthField('nowo_api_token') . '"]'));

        $crawler = $this->submitNew($client, $crawler, 'CI', 'wrong');
        self::assertSame(422, $client->getResponse()->getStatusCode());
        self::assertCount(0, $this->query()->ofOwner($this->host()->owner('alice')));

        $form    = $crawler->filter('form[name="nowo_api_token"]')->form();
        $values  = $form->getPhpValues();
        $crawler = $this->submitNew($client, $crawler, 'CI', $this->goodReauth());
        $body    = (string) $client->getResponse()->getContent();
        self::assertSame(200, $client->getResponse()->getStatusCode(), $body);
        $tokens = $this->query()->ofOwner($this->host()->owner('alice'));
        self::assertCount(1, $tokens);
        $secret = $crawler->filter('[data-testid="api-token-secret"]')->text();
        self::assertStringStartsWith('nwt_' . $tokens[0]->lookupId->value . '_', $secret);
        self::assertSame(1, substr_count($body, $secret), 'the secret is printed exactly once');
        self::assertStringContainsString('no-store', (string) $client->getResponse()->headers->get('Cache-Control'));
        self::assertStringNotContainsString($secret, serialize($this->sessionValues($client)), 'never in the session');
        self::assertStringNotContainsString($secret, (string) json_encode(array_map(static fn (object $e): array => (array) $e, $this->listener()->events)), 'never in an event');
        self::assertCount(1, $this->listener()->of(ApiTokenIssuedEvent::class));

        // A reload re-posts the same data: the CSRF token was renewed, so no second token is issued.
        $values['nowo_api_token']['name'] = 'CI again';
        $this->as($client, 'alice', 'POST', self::ACCOUNT . '/new', $values);
        self::assertSame(422, $client->getResponse()->getStatusCode());
        self::assertCount(1, $this->query()->ofOwner($this->host()->owner('alice')));

        // Fresh for reauth.fresh_seconds in this session: no re-auth field now.
        $crawler = $this->as($client, 'alice', 'GET', self::ACCOUNT . '/new');
        self::assertCount(0, $crawler->filter('input[name="' . $this->reauthField('nowo_api_token') . '"]'));
        $this->clock()->modify('+301 seconds');
        $crawler = $this->as($client, 'alice', 'GET', self::ACCOUNT . '/new');
        self::assertCount(1, $crawler->filter('input[name="' . $this->reauthField('nowo_api_token') . '"]'), 'stale after 5 minutes');
    }

    public function testFreshnessIsBoundToTheSessionAndAttemptsAreCapped(): void
    {
        $client  = $this->boot();
        $crawler = $this->as($client, 'alice', 'GET', self::ACCOUNT . '/new');
        $this->submitNew($client, $crawler, 'first', $this->goodReauth());
        self::assertCount(1, $this->query()->ofOwner($this->host()->owner('alice')));

        $other   = $this->client();
        $crawler = $this->as($other, 'alice', 'GET', self::ACCOUNT . '/new');
        self::assertCount(1, $crawler->filter('input[name="' . $this->reauthField('nowo_api_token') . '"]'), 'another session must re-authenticate');
        for ($i = 0; $i < 5; ++$i) {
            $crawler = $this->submitNew($other, $crawler, 'try ' . $i, 'wrong');
            self::assertSame(422, $other->getResponse()->getStatusCode());
        }
        $this->submitNew($other, $crawler, 'blocked', $this->goodReauth());
        self::assertSame(422, $other->getResponse()->getStatusCode());
        self::assertStringContainsString('Too many attempts', (string) $other->getResponse()->getContent());
        self::assertCount(1, $this->query()->ofOwner($this->host()->owner('alice')));
    }

    public function testRevokeChecksCsrfAndTheOwner(): void
    {
        $client  = $this->boot();
        $alice   = $this->issue('alice', 'a');
        $bob     = $this->issue('bob', 'b');
        $crawler = $this->as($client, 'alice', 'GET', self::ACCOUNT);
        $token   = $crawler->filter('input[name="_token"]')->first()->attr('value');
        self::assertNotNull($token);

        $this->as($client, 'alice', 'POST', self::ACCOUNT . '/' . $alice->token->id->value . '/revoke', ['confirm' => '1']);
        self::assertSame(403, $client->getResponse()->getStatusCode(), 'no CSRF token');
        $this->as($client, 'alice', 'POST', self::ACCOUNT . '/' . $bob->token->id->value . '/revoke', ['confirm' => '1', '_token' => $token]);
        self::assertSame(404, $client->getResponse()->getStatusCode(), 'another person\'s token');
        $this->as($client, 'alice', 'POST', self::ACCOUNT . '/' . $alice->token->id->value . '/revoke', ['confirm' => '1', '_token' => $token]);
        self::assertSame(303, $client->getResponse()->getStatusCode());
        self::assertCount(0, $this->query()->ofOwner($this->host()->owner('alice')));
        self::assertCount(1, $this->query()->ofOwner($this->host()->owner('bob')));
    }

    public function testRevokeAllNeedsReauthAndTheTick(): void
    {
        $client = $this->boot();
        $this->issue('alice', 'a');
        $this->issue('alice', 'b');
        $crawler = $this->as($client, 'alice', 'GET', self::ACCOUNT . '/revoke-all');
        self::assertSame(200, $client->getResponse()->getStatusCode(), (string) $client->getResponse()->getContent());

        $form                                                  = $crawler->filter('form[name="nowo_api_token_revoke_all"]')->form();
        $form[$this->reauthField('nowo_api_token_revoke_all')] = $this->goodReauth();
        $this->as($client, 'alice', 'POST', self::ACCOUNT . '/revoke-all', $form->getPhpValues());
        self::assertSame(422, $client->getResponse()->getStatusCode(), 'the tick is required');
        self::assertCount(2, $this->query()->ofOwner($this->host()->owner('alice')));

        $crawler = new Crawler((string) $client->getResponse()->getContent(), 'http://localhost' . self::ACCOUNT . '/revoke-all');
        $form    = $crawler->filter('form[name="nowo_api_token_revoke_all"]')->form();
        $this->choice($form, 'nowo_api_token_revoke_all[confirm]')->tick();
        if ($crawler->filter('input[name="' . $this->reauthField('nowo_api_token_revoke_all') . '"]')->count() > 0) {
            $form[$this->reauthField('nowo_api_token_revoke_all')] = $this->goodReauth();
        }
        $this->as($client, 'alice', 'POST', self::ACCOUNT . '/revoke-all', $form->getPhpValues());
        self::assertSame(303, $client->getResponse()->getStatusCode(), (string) $client->getResponse()->getContent());
        self::assertCount(0, $this->query()->ofOwner($this->host()->owner('alice')));
        self::assertCount(2, $this->listener()->of(ApiTokenRevokedEvent::class));
    }

    public function testTheOperatorPageNeedsTheRoleFiltersAndRevokesAsTheOperator(): void
    {
        $client = $this->boot();
        $alice  = $this->issue('alice', 'leaked');
        $this->issue('bob', 'other');

        $this->as($client, 'bob', 'GET', self::ADMIN);
        self::assertSame(403, $client->getResponse()->getStatusCode());

        $crawler = $this->as($client, 'admin', 'GET', self::ADMIN);
        self::assertSame(200, $client->getResponse()->getStatusCode(), (string) $client->getResponse()->getContent());
        self::assertCount(2, $crawler->filter('[data-testid="api-token-handle"]'));
        self::assertSame('DENY', $client->getResponse()->headers->get('X-Frame-Options'));

        $crawler = $this->as($client, 'admin', 'GET', self::ADMIN . '?lookup=nwt_' . $alice->token->lookupId->value);
        self::assertCount(1, $crawler->filter('[data-testid="api-token-handle"]'));
        $crawler = $this->as($client, 'admin', 'GET', self::ADMIN . '?owner=' . $this->host()->owner('bob')->key());
        self::assertCount(1, $crawler->filter('[data-testid="api-token-handle"]'));
        $crawler = $this->as($client, 'admin', 'GET', self::ADMIN . '?owner=nobody&lookup=');
        self::assertCount(0, $crawler->filter('[data-testid="api-token-handle"]'));
        $crawler = $this->as($client, 'admin', 'GET', self::ADMIN . '?lookup=garbage');
        self::assertCount(0, $crawler->filter('[data-testid="api-token-handle"]'));

        $crawler = $this->as($client, 'admin', 'GET', self::ADMIN);
        $token   = $crawler->filter('input[name="_token"]')->first()->attr('value');
        $this->as($client, 'admin', 'POST', self::ADMIN . '/' . $alice->token->id->value . '/revoke', ['confirm' => '1', '_token' => (string) $token]);
        self::assertSame(303, $client->getResponse()->getStatusCode());
        $revoked = $this->listener()->of(ApiTokenRevokedEvent::class);
        self::assertCount(1, $revoked);
        self::assertSame(RevocationReason::Operator, $revoked[0]->reason);
        self::assertNotNull($revoked[0]->actor);

        $bob = $this->query()->ofOwner($this->host()->owner('bob'))[0];
        $this->dispatcher()->addListener(ApiTokenAccessCheckEvent::class, static fn (ApiTokenAccessCheckEvent $event) => $event->deny());
        $this->as($client, 'admin', 'POST', self::ADMIN . '/' . $bob->id->value . '/revoke', ['confirm' => '1', '_token' => (string) $token]);
        self::assertSame(403, $client->getResponse()->getStatusCode(), 'a listener denied it');
        self::assertCount(1, $this->query()->ofOwner($this->host()->owner('bob')));
    }

    /**
     * Audit ZAB-S3-M1: a leaked token is searched by POST + CSRF; only its lookup id reaches the redirect, and the
     * secret appears in no URL, Location header, log record or HTML. A whole token in the GET filter matches nothing and
     * is not printed back.
     */
    public function testTheLeakedTokenSearchNeverPutsTheSecretInAUrlALogOrThePage(): void
    {
        $client = $this->boot([], [new MemoryLoggerPass()]);
        $alice  = $this->issue('alice', 'leaked');
        $this->issue('bob', 'other');
        $secret = $alice->secret();
        $lookup = $alice->token->lookupId->value;
        $logger = $this->kernel->getContainer()->get('logger');
        self::assertInstanceOf(MemoryLogger::class, $logger);
        $urls = [];

        $crawler = $this->as($client, 'admin', 'GET', self::ADMIN);
        self::assertSame(200, $client->getResponse()->getStatusCode(), (string) $client->getResponse()->getContent());
        self::assertContains('nwt_' . $lookup, $crawler->filter('[data-testid="api-token-handle"]')->each(static fn (Crawler $node): string => $node->text()), 'the whole lookup id is shown');
        $form = $crawler->filter('form[name="nowo_api_token_lookup"]')->form();
        self::assertSame('POST', $form->getMethod());
        self::assertSame('password', $crawler->filter('input[name="nowo_api_token_lookup[token]"]')->attr('type'));
        $form['nowo_api_token_lookup[token]'] = $secret;
        $urls[]                               = $form->getUri();
        $this->as($client, 'admin', 'POST', self::ADMIN . '/lookup', $form->getPhpValues());
        $response = $client->getResponse();
        self::assertSame(303, $response->getStatusCode(), (string) $response->getContent());
        $location = (string) $response->headers->get('Location');
        self::assertSame(self::ADMIN . '?lookup=' . $lookup, $location);
        $urls[] = $location;

        $crawler = $this->as($client, 'admin', 'GET', $location);
        $body    = (string) $client->getResponse()->getContent();
        self::assertCount(1, $crawler->filter('[data-testid="api-token-handle"]'));
        self::assertSame($lookup, $crawler->filter('input[name="lookup"]')->attr('value'));
        $urls[] = (string) $client->getRequest()->getUri();
        $crawler->filter('a[href], form[action]')->each(static function (Crawler $node) use (&$urls): void {
            $urls[] = (string) ($node->attr('href') ?? $node->attr('action'));
        });

        // Refusals answer the list again with the error, never the value.
        $form                                 = $crawler->filter('form[name="nowo_api_token_lookup"]')->form();
        $form['nowo_api_token_lookup[token]'] = 'zz_' . $secret;
        $this->as($client, 'admin', 'POST', self::ADMIN . '/lookup', $form->getPhpValues());
        self::assertSame(422, $client->getResponse()->getStatusCode());
        $refused = (string) $client->getResponse()->getContent();
        self::assertStringContainsString('not a token, a handle or a lookup id', $refused);
        $values                                    = $form->getPhpValues();
        $values['nowo_api_token_lookup']['token']  = $secret;
        $values['nowo_api_token_lookup']['_token'] = 'forged';
        $this->as($client, 'admin', 'POST', self::ADMIN . '/lookup', $values);
        self::assertSame(422, $client->getResponse()->getStatusCode(), 'a forged CSRF token is refused');
        $forged = (string) $client->getResponse()->getContent();
        $this->as($client, 'bob', 'POST', self::ADMIN . '/lookup', $form->getPhpValues());
        self::assertSame(403, $client->getResponse()->getStatusCode(), 'operators only');

        foreach ([...$urls, $body, $refused, $forged] as $haystack) {
            self::assertStringNotContainsString(substr($secret, 17, 20), $haystack);
        }
        self::assertNotSame([], $logger->records);
        self::assertStringNotContainsString(substr($secret, 17, 20), (string) json_encode($logger->records), 'never in a log record');

        // A whole token typed into the GET filter (or a crafted link): no match, not printed back.
        $crawler = $this->as($client, 'admin', 'GET', self::ADMIN . '?lookup=' . $secret);
        self::assertCount(0, $crawler->filter('[data-testid="api-token-handle"]'));
        self::assertSame('', (string) $crawler->filter('input[name="lookup"]')->attr('value'));
        self::assertStringNotContainsString(substr($secret, 17, 20), (string) $client->getResponse()->getContent());
        $crawler = $this->as($client, 'admin', 'GET', self::ADMIN . '?lookup=' . $lookup . '_x');
        self::assertCount(0, $crawler->filter('[data-testid="api-token-handle"]'), 'only a lookup id or a handle');
        $crawler = $this->as($client, 'admin', 'GET', self::ADMIN . '?lookup=' . $lookup);
        self::assertCount(1, $crawler->filter('[data-testid="api-token-handle"]'));
    }

    /**
     * Audit ZAB-S3-L2: when the host's token firewall also covers the pages, a request signed in with an API token can
     * open neither the account pages nor the operator page.
     */
    public function testAnApiTokenCannotOpenTheAccountOrOperatorPages(): void
    {
        $client = $this->boot([], [], true);
        $alice  = $this->issue('alice', 'narrow');
        $admin  = $this->issue('admin', 'admin');

        foreach ([[$alice, 'GET', self::ACCOUNT], [$alice, 'GET', self::ACCOUNT . '/new'], [$alice, 'POST', self::ACCOUNT . '/new'], [$alice, 'POST', self::ACCOUNT . '/revoke-all'], [$admin, 'GET', self::ADMIN], [$admin, 'POST', self::ADMIN . '/lookup']] as [$token, $method, $path]) {
            $client->request($method, $path, [], [], ['HTTP_AUTHORIZATION' => 'Bearer ' . $token->secret()]);
            self::assertSame(403, $client->getResponse()->getStatusCode(), $method . ' ' . $path);
        }
        $this->as($client, 'admin', 'GET', self::ADMIN);
        self::assertSame(200, $client->getResponse()->getStatusCode(), 'a person still opens it');
        self::assertCount(1, $this->query()->ofOwner($this->host()->owner('alice')), 'nothing revoked');
        self::assertCount(1, $this->query()->ofOwner($this->host()->owner('admin')));
    }

    /**
     * Audit ZAB-S3-I5: a re-auth mark that expires while the form is being checked asks again (422), never a 500.
     */
    public function testAMarkThatExpiresDuringTheSubmitAsksAgain(): void
    {
        $client  = $this->boot();
        $crawler = $this->as($client, 'alice', 'GET', self::ACCOUNT . '/new');
        $this->submitNew($client, $crawler, 'first', $this->goodReauth());
        $crawler = $this->as($client, 'alice', 'GET', self::ACCOUNT . '/new');
        self::assertCount(0, $crawler->filter('input[name="' . $this->reauthField('nowo_api_token') . '"]'), 'fresh: no step');
        $probe = $this->service(ExpireReauthOnSubmit::class);
        self::assertInstanceOf(ExpireReauthOnSubmit::class, $probe);
        $probe->armed = true;

        $this->submitNew($client, $crawler, 'second', '');
        self::assertSame(422, $client->getResponse()->getStatusCode(), (string) $client->getResponse()->getContent());
        self::assertStringContainsString('Your confirmation expired', (string) $client->getResponse()->getContent());
        self::assertCount(1, $this->query()->ofOwner($this->host()->owner('alice')));
        $crawler = $this->as($client, 'alice', 'GET', self::ACCOUNT . '/new');
        self::assertCount(1, $crawler->filter('input[name="' . $this->reauthField('nowo_api_token') . '"]'), 'the step is asked again');
    }

    public function testDialogRequestsGetFragmentsAndTheSecretInTheDialog(): void
    {
        $client  = $this->boot();
        $dialog  = ['HTTP_X_TEST_USER' => 'alice', 'HTTP_X_NOWO_DIALOG' => '1'];
        $crawler = $client->request('GET', self::ACCOUNT . '/new', [], [], $dialog);
        self::assertSame(200, $client->getResponse()->getStatusCode());
        self::assertStringNotContainsString('<html', (string) $client->getResponse()->getContent());
        $form                         = $crawler->filter('form[name="nowo_api_token"]')->form();
        $form['nowo_api_token[name]'] = 'agent';
        $this->choice($form, 'nowo_api_token[module_tasks]')->select('read');
        $form[$this->reauthField('nowo_api_token')] = $this->goodReauth();
        $crawler                                    = $client->request('POST', self::ACCOUNT . '/new', $form->getPhpValues(), [], $dialog);
        self::assertSame(200, $client->getResponse()->getStatusCode(), (string) $client->getResponse()->getContent());
        self::assertStringNotContainsString('<html', (string) $client->getResponse()->getContent());
        self::assertCount(1, $crawler->filter('[data-testid="api-token-secret"]'));

        $crawler = $client->request('GET', self::ACCOUNT . '/revoke-all', [], [], $dialog);
        $form    = $crawler->filter('form[name="nowo_api_token_revoke_all"]')->form();
        $this->choice($form, 'nowo_api_token_revoke_all[confirm]')->tick();
        $client->request('POST', self::ACCOUNT . '/revoke-all', $form->getPhpValues(), [], $dialog);
        self::assertSame(204, $client->getResponse()->getStatusCode(), 'fresh: no re-auth needed, saved');
        self::assertSame(self::ACCOUNT, $client->getResponse()->headers->get('X-Nowo-Redirect'));
        self::assertCount(0, $this->query()->ofOwner($this->host()->owner('alice')));
    }

    public function testLimitedReachFlagsAndRefusalsAreKeptInTheForm(): void
    {
        $client                       = $this->boot(['rate_limit' => ['issue_per_owner' => ['limit' => 3, 'interval' => '1 hour']]]);
        $crawler                      = $this->as($client, 'alice', 'GET', self::ACCOUNT . '/new');
        $form                         = $crawler->filter('form[name="nowo_api_token"]')->form();
        $form['nowo_api_token[name]'] = 'agent';
        $this->choice($form, 'nowo_api_token[module_tasks]')->select('read');
        $this->choice($form, 'nowo_api_token[module_mcp]')->select('on');
        $this->choice($form, 'nowo_api_token[flag_mcp_destructive]')->tick();
        $this->choice($form, 'nowo_api_token[reach]')->select('limited');
        $this->choice($form, 'nowo_api_token[places][0]')->tick();
        $form[$this->reauthField('nowo_api_token')] = $this->goodReauth();
        $this->as($client, 'alice', 'POST', self::ACCOUNT . '/new', $form->getPhpValues());
        self::assertSame(200, $client->getResponse()->getStatusCode(), (string) $client->getResponse()->getContent());
        $token = $this->query()->ofOwner($this->host()->owner('alice'))[0];
        self::assertSame(['p1'], $token->reach->allEntries());
        self::assertTrue($token->grants->hasFlag('mcp', 'destructive'));

        // Fresh now: the same name is refused by the issuer (and counts against the issue budget).
        $crawler = $this->as($client, 'alice', 'GET', self::ACCOUNT);
        self::assertStringContainsString('Agents may delete', (string) $client->getResponse()->getContent());
        $crawler = $this->as($client, 'alice', 'GET', self::ACCOUNT . '/new');
        $this->submitNew($client, $crawler, 'agent', '');
        self::assertSame(422, $client->getResponse()->getStatusCode());
        self::assertStringContainsString('already have a token with this name', (string) $client->getResponse()->getContent());
        $crawler = $this->as($client, 'alice', 'GET', self::ACCOUNT . '/new');
        $this->submitNew($client, $crawler, "bad\u{1}name", '');
        self::assertSame(422, $client->getResponse()->getStatusCode());
        self::assertStringContainsString('a value is not valid', (string) $client->getResponse()->getContent());
        $crawler = $this->as($client, 'alice', 'GET', self::ACCOUNT . '/new');
        $this->submitNew($client, $crawler, 'third', '');
        self::assertSame(422, $client->getResponse()->getStatusCode());
        self::assertStringContainsString('many tokens in a short time', (string) $client->getResponse()->getContent());
    }

    public function testAMarkThatExpiresBetweenTheFormAndThePostAsksAgain(): void
    {
        $client  = $this->boot();
        $crawler = $this->as($client, 'alice', 'GET', self::ACCOUNT . '/new');
        $this->submitNew($client, $crawler, 'first', $this->goodReauth());
        $crawler = $this->as($client, 'alice', 'GET', self::ACCOUNT . '/new');
        $this->clock()->modify('+301 seconds');
        $this->submitNew($client, $crawler, 'second', '');
        self::assertSame(422, $client->getResponse()->getStatusCode());
        self::assertStringContainsString('did not confirm it is you', (string) $client->getResponse()->getContent());
        self::assertCount(1, (new Crawler((string) $client->getResponse()->getContent()))->filter('input[name="' . $this->reauthField('nowo_api_token') . '"]'), 'the step is asked again');
        self::assertCount(1, $this->query()->ofOwner($this->host()->owner('alice')));
    }

    public function testAPolicyBelowEveryChoiceOffersItsOwnMaximum(): void
    {
        $client  = $this->boot([], [new ShortPolicyPass()]);
        $crawler = $this->as($client, 'alice', 'GET', self::ACCOUNT . '/new');
        self::assertSame(['3'], $crawler->filter('select[name="nowo_api_token[expires_in]"] option')->each(static fn (Crawler $option): string => (string) $option->attr('value')));
        self::assertCount(0, $crawler->filter('select[name="nowo_api_token[module_wiki]"]'), 'modules outside the plan are not offered');
    }

    public function testOperatorEdgeCases(): void
    {
        $client = $this->boot();
        $alice  = $this->issue('alice', 'a');
        $this->as($client, 'admin', 'GET', self::ADMIN . '?after=00000000-0000-0000-0000-000000000000');
        self::assertSame(404, $client->getResponse()->getStatusCode(), 'not a token id');
        $crawler = $this->as($client, 'admin', 'GET', self::ADMIN . '?owner=Bad Type:x');
        self::assertCount(0, $crawler->filter('[data-testid="api-token-handle"]'));
        $this->as($client, 'admin', 'POST', self::ADMIN . '/' . $alice->token->id->value . '/revoke', ['confirm' => '1']);
        self::assertSame(403, $client->getResponse()->getStatusCode(), 'no CSRF token');
    }

    public function testADemoOperatorPageWithoutSignIn(): void
    {
        $client  = $this->boot(['admin_ui' => ['security' => ['allow_unauthenticated' => true]]]);
        $alice   = $this->issue('alice', 'a');
        $crawler = $client->request('GET', self::ADMIN);
        self::assertSame(200, $client->getResponse()->getStatusCode());
        $token = (string) $crawler->filter('input[name="_token"]')->first()->attr('value');
        $client->request('POST', self::ADMIN . '/' . $alice->token->id->value . '/revoke', ['confirm' => '1', '_token' => $token]);
        self::assertSame(303, $client->getResponse()->getStatusCode());
        self::assertSame('operator:anonymous', $this->listener()->of(ApiTokenRevokedEvent::class)[0]->actor?->key());
    }

    /**
     * @param array<string, mixed> $config
     * @param list<CompilerPassInterface> $passes
     * @param bool $tokenOnPages the pages firewall also lists the API-token authenticator
     */
    protected function boot(array $config = [], array $passes = [], bool $tokenOnPages = false): KernelBrowser
    {
        $host     = $this->host();
        $kind     = $host->kind;
        $type     = $kind === Host::STANDARD ? 'user' : 'member';
        $pages    = $tokenOnPages ? [TestUserAuthenticator::class, ApiTokenFirewallAuthenticator::class] : [TestUserAuthenticator::class];
        $security = ['extra_firewalls' => ['pages' => ['pattern' => '^/(account|admin)', 'stateless' => true, 'provider' => $kind === Host::STANDARD ? 'users' : 'none', 'custom_authenticators' => $pages]]];
        if ($kind === Host::STANDARD) {
            $security['password_hashers'] = [User::class => 'plaintext'];
        }
        $base      = $host->configure($this->apiFirewall(), $security);
        $configure = static function (ContainerConfigurator $container, string $url) use ($base, $type): void {
            $base($container, $url);
            $container->services()->set(TestUserAuthenticator::class)->args([new ReferenceConfigurator('Nowo\ApiTokenBundle\Contract\OwnerResolverInterface'), $type]);
            $container->services()->set(MemberReauthGate::class);
            $container->services()->set(PlacesReachProvider::class);
            $container->services()->set(ShortPolicy::class);
            $container->services()->set(ExpireReauthOnSubmit::class)->args([new ReferenceConfigurator('clock')])->tag('form.type_extension')->public();
        };
        $extra = array_replace_recursive(['web_ui' => ['enabled' => true], 'admin_ui' => ['enabled' => true], 'reach' => ['provider' => PlacesReachProvider::class]], $config);
        if ($kind === Host::DDD) {
            $extra['reauth'] = ['gate' => MemberReauthGate::class];
        }
        $this->kernel = $this->bootKernel($host->bundleConfig($extra), Host::databaseUrl(), $configure, passes: $passes, ui: true);
        $host->resetSchema($this->kernel);
        foreach (['alice', 'bob', 'admin'] as $id) {
            $host->createUser($this->kernel, $id);
        }
        $this->listener()->clear();

        return $this->client();
    }

    /**
     * @return array<string, mixed>
     */
    private function apiFirewall(): array
    {
        $firewall = ['pattern' => '^/api', 'stateless' => true, 'custom_authenticators' => [ApiTokenFirewallAuthenticator::class]];

        return $this->host()->kind === Host::STANDARD ? $firewall + ['provider' => 'users', 'user_checker' => ActiveUserChecker::class] : $firewall;
    }

    private function client(): KernelBrowser
    {
        $client = $this->kernel->getContainer()->get('test.client');
        self::assertInstanceOf(KernelBrowser::class, $client);
        $client->disableReboot();

        return $client;
    }

    /**
     * @param array<string, mixed> $parameters
     */
    private function as(KernelBrowser $client, string $user, string $method, string $path, array $parameters = []): Crawler
    {
        return $client->request($method, $path, $parameters, [], ['HTTP_X_TEST_USER' => $user]);
    }

    private function submitNew(KernelBrowser $client, Crawler $crawler, string $name, string $reauth): Crawler
    {
        $form                         = $crawler->filter('form[name="nowo_api_token"]')->form();
        $form['nowo_api_token[name]'] = $name;
        $this->choice($form, 'nowo_api_token[module_tasks]')->select('read');
        if ($crawler->filter('input[name="' . $this->reauthField('nowo_api_token') . '"]')->count() > 0) {
            $form[$this->reauthField('nowo_api_token')] = $reauth;
        }

        return $this->as($client, 'alice', 'POST', self::ACCOUNT . '/new', $form->getPhpValues());
    }

    /**
     * @return array<string, mixed>
     */
    private function sessionValues(KernelBrowser $client): array
    {
        $request = $client->getRequest();

        return $request->hasSession() ? $request->getSession()->all() : [];
    }

    private function issue(string $owner, string $name): IssuedToken
    {
        $issuer = $this->service(ApiTokenIssuerInterface::class);
        self::assertInstanceOf(ApiTokenIssuerInterface::class, $issuer);

        return $issuer->issue(new IssueRequest($this->host()->owner($owner), $name, new Grants(['tasks' => 'read']), Reach::everything(), 30));
    }

    private function query(): ApiTokenQueryInterface
    {
        $query = $this->service(ApiTokenQueryInterface::class);
        self::assertInstanceOf(ApiTokenQueryInterface::class, $query);

        return $query;
    }

    private function clock(): MockClock
    {
        $clock = $this->service('clock');
        self::assertInstanceOf(MockClock::class, $clock);

        return $clock;
    }

    private function listener(): RecordingListener
    {
        $listener = $this->service(RecordingListener::class);
        self::assertInstanceOf(RecordingListener::class, $listener);

        return $listener;
    }

    private function dispatcher(): EventDispatcherInterface
    {
        $dispatcher = $this->kernel->getContainer()->get('event_dispatcher');
        self::assertInstanceOf(EventDispatcherInterface::class, $dispatcher);

        return $dispatcher;
    }

    private function service(string $id): object
    {
        $container = $this->kernel->getContainer()->get('test.service_container');
        self::assertInstanceOf(ContainerInterface::class, $container);
        $service = $container->get($id);
        self::assertIsObject($service);

        return $service;
    }

    private function choice(Form $form, string $name): ChoiceFormField
    {
        $field = $form->get($name);
        self::assertInstanceOf(ChoiceFormField::class, $field);

        return $field;
    }
}
