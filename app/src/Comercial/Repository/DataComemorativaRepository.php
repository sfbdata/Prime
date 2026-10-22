<?php

declare(strict_types=1);

namespace App\Comercial\Repository;

use App\Comercial\Entity\DataComemorativa;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * O estado das datas comemorativas (spec `comercial-e-emails.md` §13, F4). A leitura é por SQL (a tela e o passo
 * `datas` da varredura, que roda no worker sem sessão); a alteração do Master é pela entidade, auditada
 * ({@see \App\Comercial\UseCase\AlterarDataComemorativaUseCase}). Plataforma: a tabela não tem `tenant_id`.
 *
 * @phpstan-type EstadoDaData array{ligada: bool, antecedencia: int, publico: string}
 *
 * @extends ServiceEntityRepository<DataComemorativa>
 */
class DataComemorativaRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, DataComemorativa::class);
    }

    /** @return array<string, EstadoDaData> por chave; a chave sem linha fica de fora */
    public function estados(): array
    {
        $linhas = $this->getEntityManager()->getConnection()->fetchAllAssociative(
            'SELECT chave, ligada, antecedencia, publico FROM comercial_data_comemorativa',
        );
        $estados = [];
        foreach ($linhas as $l) {
            $estados[(string) $l['chave']] = ['ligada' => (bool) $l['ligada'], 'antecedencia' => (int) $l['antecedencia'], 'publico' => (string) $l['publico']];
        }

        return $estados;
    }

    public function porChave(string $chave): ?DataComemorativa
    {
        return $this->find($chave);
    }

    public function salvar(): void
    {
        $this->getEntityManager()->flush();
    }
}
