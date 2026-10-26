<?php

declare(strict_types=1);

namespace App\Master\Repository;

use App\Master\Entity\ConfiguracaoGlobal;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\Exception\TableNotFoundException;
use Doctrine\Persistence\ManagerRegistry;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * A linha única de `configuracao_global`. Sem filtro de tenant de propósito: é configuração GLOBAL da plataforma
 * (ver {@see ConfiguracaoGlobal}).
 *
 * `atual()` roda em toda página logada (o aviso de manutenção do layout): uma consulta por requisição PRINCIPAL
 * (WeakMap), como o {@see TemaConfigRepository}. Sem a tabela (código publicado antes da migration), devolve nulo
 * com aviso no log e a página não cai.
 *
 * @extends ServiceEntityRepository<ConfiguracaoGlobal>
 */
class ConfiguracaoGlobalRepository extends ServiceEntityRepository
{
    /** @var \WeakMap<Request, array{0: ?ConfiguracaoGlobal}> */
    private \WeakMap $porRequisicao;

    public function __construct(
        ManagerRegistry $registry,
        private readonly RequestStack $requestStack,
        private readonly LoggerInterface $logger,
    ) {
        parent::__construct($registry, ConfiguracaoGlobal::class);
        $this->porRequisicao = new \WeakMap();
    }

    /** Leitura cacheada por requisição, para o layout. */
    public function atual(): ?ConfiguracaoGlobal
    {
        $requisicao = $this->requestStack->getMainRequest();
        if ($requisicao === null) {
            return $this->carregar();
        }

        $this->porRequisicao[$requisicao] ??= [$this->carregar()];

        return $this->porRequisicao[$requisicao][0];
    }

    /** Leitura direta (sem cache), para quem vai gravar. */
    public function buscarUnica(): ?ConfiguracaoGlobal
    {
        return $this->find(ConfiguracaoGlobal::ID_UNICO);
    }

    public function salvar(ConfiguracaoGlobal $configuracao, bool $flush = false): void
    {
        $this->getEntityManager()->persist($configuracao);
        if ($flush) {
            $this->getEntityManager()->flush();
        }

        $requisicao = $this->requestStack->getMainRequest();
        if ($requisicao !== null) {
            $this->porRequisicao[$requisicao] = [$configuracao];
        }
    }

    private function carregar(): ?ConfiguracaoGlobal
    {
        try {
            return $this->buscarUnica();
        } catch (TableNotFoundException $e) {
            $this->logger->warning('Configurações globais: tabela configuracao_global inexistente (migration pendente); valem os padrões.', ['exception' => $e]);

            return null;
        }
    }
}
