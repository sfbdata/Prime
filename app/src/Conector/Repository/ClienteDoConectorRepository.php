<?php

declare(strict_types=1);

namespace App\Conector\Repository;

use App\Conector\Entity\ClienteDoConector;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\Persistence\ManagerRegistry;

/**
 * Clientes OAuth do conector. Global por natureza ({@see ClienteDoConector}): a busca pelo identificador não filtra
 * escritório, porque o mesmo app atende todos.
 *
 * @extends ServiceEntityRepository<ClienteDoConector>
 */
class ClienteDoConectorRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ClienteDoConector::class);
    }

    public function salvar(ClienteDoConector $entidade, bool $flush = false): void
    {
        $this->getEntityManager()->persist($entidade);
        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }

    /** O cliente pelo `client_id` que o app mandou (a URL, no CIMD). */
    public function buscarPorIdentificador(string $identificador): ?ClienteDoConector
    {
        return $this->findOneBy(['identificador' => $identificador]);
    }

    /**
     * Grava o cliente novo se ainda não existe um com o mesmo `identificador`; devolve falso quando outro pedido gravou
     * antes (a corrida de dois `/oauth/authorize` do mesmo app ao mesmo tempo). Quem chama relê pelo identificador.
     *
     * SQL direto com `ON CONFLICT DO NOTHING`, como o `PreferenciaDoUsuarioRepository`: um `persist()` + `flush()` que
     * esbarra no índice único lança a exceção e FECHA o EntityManager, e o `/oauth/authorize` ainda precisa dele. A
     * entidade recebida não fica gerenciada: use a relida.
     */
    public function inserirSeAusente(ClienteDoConector $cliente): bool
    {
        $linhas = $this->getEntityManager()->getConnection()->executeStatement(
            'INSERT INTO conector_ia_cliente (identificador, tipo, nome, redirect_uris, metadados_buscados_em, metadados_validos_ate, ativo, criado_em)
             VALUES (:identificador, :tipo, :nome, :redirect_uris, :buscados_em, :validos_ate, :ativo, :criado_em)
             ON CONFLICT (identificador) DO NOTHING',
            [
                'identificador' => $cliente->getIdentificador(),
                'tipo' => $cliente->getTipo()->value,
                'nome' => $cliente->getNome(),
                'redirect_uris' => $cliente->getRedirectUris(),
                'buscados_em' => $cliente->getMetadadosBuscadosEm(),
                'validos_ate' => $cliente->getMetadadosValidosAte(),
                'ativo' => $cliente->isAtivo(),
                'criado_em' => $cliente->getCriadoEm(),
            ],
            [
                'redirect_uris' => Types::JSON,
                'buscados_em' => Types::DATETIME_IMMUTABLE,
                'validos_ate' => Types::DATETIME_IMMUTABLE,
                'ativo' => Types::BOOLEAN,
                'criado_em' => Types::DATETIME_IMMUTABLE,
            ],
        );

        return $linhas === 1;
    }
}
