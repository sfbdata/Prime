<?php

declare(strict_types=1);

namespace App\Conector\Service;

use App\Conector\Exception\RecusaGravada;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;

/**
 * A transação das trocas do `/oauth/token` (`docs/specs/mcp-remoto-oauth.md`, 9.1). Dentro dela o código ou a
 * renovação é lido travado (`SELECT ... FOR UPDATE`), então duas trocas simultâneas da mesma linha saem uma depois da
 * outra.
 *
 * Três saídas:
 * - o trabalho devolve: grava (`flush`) e confirma;
 * - o trabalho lança {@see RecusaGravada}: grava e CONFIRMA o que a recusa mudou (a concessão revogada) e só depois
 *   relança o `ErroOAuth` de dentro dela. O `wrapInTransaction` do Doctrine desfaria a revogação e fecharia o
 *   EntityManager;
 * - qualquer outra exceção (inclusive `ErroOAuth` sem mudança a gravar): desfaz e relança.
 */
final class TransacaoDoToken
{
    public function __construct(private readonly EntityManagerInterface $em)
    {
    }

    /**
     * @template T
     *
     * @param callable(): T $trabalho
     *
     * @return T
     */
    public function executar(callable $trabalho): mixed
    {
        $conexao = $this->em->getConnection();
        $conexao->beginTransaction();

        try {
            $resultado = $trabalho();
        } catch (RecusaGravada $recusa) {
            $this->confirmar($conexao);

            throw $recusa->erro;
        } catch (\Throwable $erro) {
            $this->desfazer($conexao);

            throw $erro;
        }

        $this->confirmar($conexao);

        return $resultado;
    }

    private function confirmar(Connection $conexao): void
    {
        try {
            $this->em->flush();
            $conexao->commit();
        } catch (\Throwable $erro) {
            $this->desfazer($conexao);

            throw $erro;
        }
    }

    private function desfazer(Connection $conexao): void
    {
        if ($conexao->isTransactionActive()) {
            $conexao->rollBack();
        }
    }
}
