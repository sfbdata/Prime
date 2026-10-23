<?php

declare(strict_types=1);

namespace App\Master\Repository;

use App\Master\Entity\TemaConfig;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\Exception\TableNotFoundException;
use Doctrine\Persistence\ManagerRegistry;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * A linha única de `tema_config`. Sem filtro de tenant de propósito: é configuração GLOBAL da plataforma
 * (ver {@see TemaConfig}).
 *
 * `atual()` roda em toda página logada (o tema do layout): uma consulta por requisição PRINCIPAL (WeakMap, como o
 * RecursosDoSistema), e não por instância, porque o serviço sobrevive entre requisições no mesmo kernel (testes com
 * disableReboot, worker). Sem a tabela (código publicado antes da migration), devolve nulo com aviso no log: vale o
 * automático com todos os temas ligados, e a página não cai.
 *
 * @extends ServiceEntityRepository<TemaConfig>
 */
class TemaConfigRepository extends ServiceEntityRepository
{
    /** @var \WeakMap<Request, array{0: ?TemaConfig}> */
    private \WeakMap $porRequisicao;

    public function __construct(
        ManagerRegistry $registry,
        private readonly RequestStack $requestStack,
        private readonly LoggerInterface $logger,
    ) {
        parent::__construct($registry, TemaConfig::class);
        $this->porRequisicao = new \WeakMap();
    }

    /** Leitura cacheada por requisição, para o layout. */
    public function atual(): ?TemaConfig
    {
        $requisicao = $this->requestStack->getMainRequest();
        if ($requisicao === null) {
            return $this->carregar();
        }

        $this->porRequisicao[$requisicao] ??= [$this->carregar()];

        return $this->porRequisicao[$requisicao][0];
    }

    /** Leitura direta (sem cache), para quem vai gravar. */
    public function buscarUnica(): ?TemaConfig
    {
        return $this->find(TemaConfig::ID_UNICO);
    }

    public function salvar(TemaConfig $config, bool $flush = false): void
    {
        $this->getEntityManager()->persist($config);
        if ($flush) {
            $this->getEntityManager()->flush();
        }

        $requisicao = $this->requestStack->getMainRequest();
        if ($requisicao !== null) {
            $this->porRequisicao[$requisicao] = [$config];
        }
    }

    private function carregar(): ?TemaConfig
    {
        try {
            return $this->buscarUnica();
        } catch (TableNotFoundException $e) {
            $this->logger->warning('Temas comemorativos: tabela tema_config inexistente (migration pendente); vale o automático.', ['exception' => $e]);

            return null;
        }
    }
}
