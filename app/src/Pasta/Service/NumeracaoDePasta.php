<?php

declare(strict_types=1);

namespace App\Pasta\Service;

use App\Entity\Tenant\Tenant;
use Doctrine\ORM\EntityManagerInterface;

/**
 * A sequência de números de pasta de um escritório: a trava que a serializa e a leitura do que
 * já foi usado.
 *
 * Existe para ter UM lugar só com a expressão do prefixo e a trava por escritório, que o
 * GerarNumeroDePasta usa para atribuir o próximo número. A exclusão de pasta não consulta mais a
 * sequência: toda pasta excluída vira lápide e continua ocupando o número dela.
 *
 * O sufixo de letra do legado (10A/10B) conta pelo prefixo: 10A é o número 10. Nunca é gerado.
 */
final class NumeracaoDePasta implements NumeracaoDePastaInterface
{
    /**
     * Espaço de nomes da trava consultiva. Advisory lock é global ao banco inteiro, então a chave
     * precisa de duas partes: esta constante isola "numeração de pasta" de qualquer outro uso
     * futuro, e o tenant_id isola um escritório do outro (dois escritórios mexem na própria
     * sequência ao mesmo tempo sem esperar um pelo outro).
     */
    private const CLASSE_TRAVA = 4713;

    /**
     * Expressão do prefixo numérico do NUP: o 10A/10B do legado conta como 10.
     */
    private const SQL_PREFIXO = "CAST(substring(nup FROM '^[0-9]{1,18}') AS BIGINT)";

    public function __construct(
        private readonly EntityManagerInterface $em,
    ) {
    }

    /**
     * Serializa quem mexe na sequência deste escritório. Quem chegar depois espera aqui e só lê
     * quando o anterior já gravou.
     *
     * `pg_advisory_xact_lock` só é liberada no fim da TRANSAÇÃO. Sem uma transação aberta ela
     * cairia no mesmo instante e a trava viraria enfeite — a corrida voltaria, e de forma
     * silenciosa: dois números iguais.
     * Por isso o chamador é obrigado a abrir a transação (`wrapInTransaction`).
     */
    public function travar(Tenant $tenant): void
    {
        $conn = $this->em->getConnection();

        if (!$conn->isTransactionActive()) {
            throw new \LogicException(
                'A numeração de pasta exige uma transação aberta: a trava por escritório só '
                . 'segura até o commit. Envolva a operação em wrapInTransaction().'
            );
        }

        $conn->executeStatement(
            'SELECT pg_advisory_xact_lock(?, ?)',
            [self::CLASSE_TRAVA, $this->idDo($tenant)]
        );
    }

    /** O maior número já usado no escritório; 0 se não há nenhum número aproveitável. */
    public function maiorNumero(Tenant $tenant): int
    {
        // SQL cru NÃO passa pelo TenantFilter do Doctrine (o filtro age no ORM). O `tenant_id = ?`
        // é o isolamento — não é redundante, é o único que existe aqui.
        $maior = $this->em->getConnection()->fetchOne(
            "SELECT MAX(CASE WHEN nup ~ '^[0-9]' THEN " . self::SQL_PREFIXO . " END)
             FROM pasta WHERE tenant_id = ?",
            [$this->idDo($tenant)]
        );

        return (int) $maior;
    }

    private function idDo(Tenant $tenant): int
    {
        $id = $tenant->getId();

        if ($id === null) {
            throw new \InvalidArgumentException('O escritório precisa estar persistido para numerar pastas.');
        }

        return $id;
    }
}
