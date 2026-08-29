<?php

declare(strict_types=1);

namespace App\Pasta\Service;

use App\Entity\Tenant\Tenant;
use Doctrine\ORM\EntityManagerInterface;

/**
 * A sequência de números de pasta de um escritório: a trava que a serializa e a leitura do que
 * já foi usado.
 *
 * Existe para ter UM lugar só. Dois pontos do sistema precisam concordar sobre o que é "o número
 * de uma pasta": o GerarNumeroDePasta, que atribui o próximo, e o ExcluirPastaUseCase, que decide
 * se a pasta sendo excluída é a última da fila (apaga de verdade) ou tem posterior (vira lápide).
 * Com a expressão do prefixo copiada nos dois, bastava alguém ajustar um lado para o sistema
 * passar a atribuir um número e a raciocinar sobre outro — sem erro nenhum aparecendo.
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
     * Expressão do prefixo numérico do NUP. Mantida como constante porque é o ponto exato em que
     * geração e exclusão precisam concordar — ver o docblock da classe.
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
     * silenciosa: dois números iguais, ou uma pasta apagada de verdade que já tinha posterior.
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

    /**
     * Existe no escritório alguma pasta com número ESTRITAMENTE maior que o deste NUP?
     *
     * É a pergunta que decide entre lápide e exclusão real. Estritamente maior de propósito: a
     * própria pasta consultada não conta, e a gêmea de número repetido (o NUP não é único — ver
     * Version20260701144054) também não, porque apagar uma das duas não libera número nenhum.
     *
     * NUP sem prefixo numérico devolve `true`: a pasta não está na sequência, apagá-la não
     * devolveria número algum, e na dúvida o registro fica.
     */
    public function existeNumeroMaiorQue(Tenant $tenant, ?string $nup): bool
    {
        $numero = self::prefixoNumerico($nup);

        if ($numero === null) {
            return true;
        }

        $existe = $this->em->getConnection()->fetchOne(
            "SELECT EXISTS (
                SELECT 1 FROM pasta
                 WHERE tenant_id = ?
                   AND nup ~ '^[0-9]'
                   AND " . self::SQL_PREFIXO . " > ?
             )",
            [$this->idDo($tenant), $numero]
        );

        return (bool) $existe;
    }

    /** O mesmo recorte da SQL_PREFIXO, do lado do PHP. `null` quando o NUP não começa por dígito. */
    public static function prefixoNumerico(?string $nup): ?int
    {
        if ($nup === null || preg_match('/^[0-9]{1,18}/', $nup, $casou) !== 1) {
            return null;
        }

        return (int) $casou[0];
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
