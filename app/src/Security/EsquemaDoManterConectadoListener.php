<?php

declare(strict_types=1);

namespace App\Security;

use Doctrine\Bundle\DoctrineBundle\Attribute\AsDoctrineListener;
use Doctrine\ORM\Tools\Event\GenerateSchemaEventArgs;
use Doctrine\ORM\Tools\ToolEvents;
use Symfony\Bridge\Doctrine\SchemaListener\AbstractSchemaListener;

/**
 * O listener do Symfony só põe a `rememberme_token` no esquema quando o provider é o `DoctrineTokenProvider` puro;
 * com o {@see TokenDoManterConectado} na frente, este faz o mesmo. Sem ele, o `doctrine:schema:validate` acusaria a
 * tabela e um `make:migration` proporia apagá-la.
 */
#[AsDoctrineListener(event: ToolEvents::postGenerateSchema)]
final class EsquemaDoManterConectadoListener extends AbstractSchemaListener
{
    public function __construct(
        private readonly TokenDoManterConectado $provider,
    ) {
    }

    public function postGenerateSchema(GenerateSchemaEventArgs $event): void
    {
        $conexao = $event->getEntityManager()->getConnection();
        $esquema = $event->getSchema();
        if ($conexao !== $this->provider->conexao()) {
            return;
        }
        $mesmoBanco = $this->getIsSameDatabaseChecker($conexao);
        // Nesta versão do ORM o evento não tem setSchema: a tabela entra no próprio objeto do esquema.
        $this->filterSchemaChanges($esquema, $conexao, fn () => $this->provider->configureSchema($esquema, $conexao, $mesmoBanco));
    }
}
