<?php

declare(strict_types=1);

namespace App\Controller;

use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Throwable;

/**
 * S0 demo home: shows that the bundle is installed (disabled), the published parameters and the MySQL connection.
 */
final class DemoController extends AbstractController
{
    public function __construct(
        private readonly Connection $connection,
        #[Autowire('%nowo_api_token.enabled%')] private readonly bool $enabled,
        #[Autowire('%nowo_api_token.api_prefix%')] private readonly string $apiPrefix,
        #[Autowire('%nowo_api_token.table_prefix%')] private readonly string $tablePrefix,
    ) {
    }

    #[Route('/', name: 'demo_home', methods: ['GET'])]
    public function home(): Response
    {
        try {
            $database = (string) $this->connection->fetchOne('SELECT VERSION()');
        } catch (Throwable) {
            $database = null;
        }

        return $this->render('demo/home.html.twig', [
            'enabled'      => $this->enabled,
            'api_prefix'   => $this->apiPrefix,
            'table_prefix' => $this->tablePrefix,
            'database'     => $database,
            'worker'       => ($_SERVER['FRANKENPHP_WORKER'] ?? null) !== null,
        ]);
    }
}
