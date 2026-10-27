<?php

declare(strict_types=1);

namespace App\Master\Repository;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;

/**
 * Os usuários primários (perfil de sistema do escritório) ativos de uma lista de escritórios, para o aviso de mudança do
 * convênio OAB. Consulta de plataforma, só do Master: o recorte é pela lista de escritórios que o chamador entrega, e a
 * mesma regra de vínculo válido dos públicos de sistema da Comercial (usuário, vínculo e escritório ativos, fora da quarentena).
 */
class PrimariosDosEscritoriosRepository
{
    public function __construct(private readonly Connection $conexao)
    {
    }

    /**
     * Uma linha por endereço (quem é primário em dois escritórios do estado recebe um aviso só).
     *
     * @param list<int> $escritorios
     *
     * @return list<array{email: string, nome: string}>
     */
    public function dos(array $escritorios): array
    {
        if ($escritorios === []) {
            return [];
        }

        $linhas = $this->conexao->fetchAllAssociative(
            'SELECT LOWER(u.email) AS email, MIN(u.full_name) AS nome
               FROM "user" u
               JOIN user_tenant ut ON ut.user_id = u.id AND ut.is_active = true
               JOIN tenant t ON t.id = ut.tenant_id AND t.is_active = true AND t.excluido_em IS NULL
               JOIN tenant_role r ON r.id = ut.tenant_role_id AND r.is_system = true
              WHERE u.is_active = true AND u.email IS NOT NULL AND ut.tenant_id IN (:escritorios)
              GROUP BY LOWER(u.email) ORDER BY MIN(u.id)',
            ['escritorios' => $escritorios],
            ['escritorios' => ArrayParameterType::INTEGER],
        );

        return array_map(static fn (array $l): array => ['email' => (string) $l['email'], 'nome' => (string) $l['nome']], $linhas);
    }
}
