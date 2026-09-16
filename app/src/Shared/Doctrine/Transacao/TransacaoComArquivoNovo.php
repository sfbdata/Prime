<?php

declare(strict_types=1);

namespace App\Shared\Doctrine\Transacao;

use App\Shared\Armazenamento\ChaveDeArquivo;
use App\Shared\Armazenamento\RemocaoAposTransacao;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * Confirma no banco uma operação que acabou de gravar arquivo novo — e, se ela falhar, só apaga
 * esse arquivo quando for PROVADO que nada foi confirmado (E2.5, INV-6).
 *
 * ## O defeito que isto fecha
 *
 * Os quatro cleanups em caminho de erro (dois controllers de Ponto, o lote de justificativas e o
 * download do Drive) apagavam o arquivo novo em qualquer `Throwable` do `flush`/`commit`. Mas um
 * COMMIT pode chegar ao servidor e perder só a resposta: medido no PG 15 do dev, a transação
 * termina `committed` e o cliente vê uma `DriverException` genérica. Apagar ali deixa registro
 * válido apontando para arquivo inexistente — exatamente o que INV-6 proíbe.
 *
 * ## Como separa "antes do COMMIT" de "o próprio COMMIT"
 *
 * Com `flush()` simples não dá: o UnitOfWork abre e fecha a transação por dentro, e a marca
 * `OptimisticLockException('Commit failed')` que ele põe na falha do COMMIT some dentro de
 * transação explícita (e um `postFlush` que lançasse sairia cru, com o banco confirmado). Então a
 * transação é explícita e a fase é controlada aqui:
 *
 *  1. `beginTransaction()` → trabalho → `flush()` → `pg_current_xact_id()`. Qualquer falha nesta
 *     fase acontece **antes** de o COMMIT ser enviado: o banco desfaz tudo, e o arquivo novo pode
 *     sair ({@see DestinoDaTransacao::NaoConfirmada});
 *  2. `commit()`. Se ele lança, o destino é perguntado ao banco por
 *     {@see ConsultaDeDestinoDaTransacao}: só `aborted` autoriza apagar. `committed`, `in progress`,
 *     sem resposta — o arquivo **fica**, com registro no log.
 *
 * **Transação aberta por fora** (nível de aninhamento > 0 antes de começar): o `commit()` daqui é só
 * `RELEASE SAVEPOINT`, e o destino real é de quem abriu. Qualquer falha é
 * {@see DestinoDaTransacao::Incerta}. Nenhum dos quatro pontos roda aninhado em produção; sob o
 * DAMA o DBAL enxerga nível 0 (a transação do teste mora no driver).
 *
 * ## Contrato preservado
 *
 * Mesma semântica de `EntityManager::wrapInTransaction()` — o trabalho roda dentro, o `flush` é
 * feito aqui, o EntityManager é fechado em qualquer falha — e **a exceção original é relançada**:
 * quem chamava continua capturando os mesmos tipos. A limpeza nunca a mascara.
 */
final readonly class TransacaoComArquivoNovo
{
    public function __construct(
        private EntityManagerInterface $em,
        private ConsultaDeDestinoDaTransacao $consulta,
        private RemocaoAposTransacao $remocao,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * @template T
     *
     * @param callable(): T                     $trabalho      roda dentro da transação; o `flush` vem depois dele
     * @param \Closure(): list<ChaveDeArquivo>  $arquivosNovos consultado só na falha — pode ler variável
     *                                                         preenchida pelo próprio trabalho
     * @param string                            $contexto      quem gravou e por quê, para o log
     *
     * @return T
     */
    public function executar(callable $trabalho, \Closure $arquivosNovos, string $contexto): mixed
    {
        $conexao    = $this->em->getConnection();
        $nivelAntes = $conexao->getTransactionNestingLevel();
        $aninhada   = $nivelAntes > 0;
        $xid        = null;

        try {
            $conexao->beginTransaction();
            $resultado = $trabalho();
            $this->em->flush();
            $xid = (string) $conexao->fetchOne('SELECT pg_current_xact_id()::text');
        } catch (\Throwable $e) {
            $this->em->close();
            $this->desfazer($conexao, $nivelAntes);
            $this->decidir($aninhada ? DestinoDaTransacao::Incerta : DestinoDaTransacao::NaoConfirmada, $arquivosNovos, $contexto, $e, null);

            throw $e;
        }

        try {
            $conexao->commit();
        } catch (\Throwable $e) {
            // Diferente do `flush()` simples, um `commit()` explícito que falha não fecha o EM.
            $this->em->close();
            $this->decidir($this->destinoDoCommit($aninhada, (string) $xid), $arquivosNovos, $contexto, $e, $xid);

            throw $e;
        }

        return $resultado;
    }

    /**
     * Versão para quem já fez os `persist()` antes: a transação só confirma o que está no UnitOfWork.
     *
     * @param \Closure(): list<ChaveDeArquivo> $arquivosNovos
     */
    public function confirmar(\Closure $arquivosNovos, string $contexto): void
    {
        $this->executar(static fn (): null => null, $arquivosNovos, $contexto);
    }

    /**
     * Aninhada: o COMMIT daqui era só um RELEASE, e o destino é de quem abriu por fora. A consulta
     * não deve lançar, mas se lançar (um logger que falha, por exemplo) a resposta é a mesma de
     * qualquer dúvida — e a exceção original não pode ser trocada por esta.
     */
    private function destinoDoCommit(bool $aninhada, string $xid): DestinoDaTransacao
    {
        if ($aninhada) {
            return DestinoDaTransacao::Incerta;
        }

        try {
            return $this->consulta->destinoDe($xid);
        } catch (\Throwable) {
            return DestinoDaTransacao::Incerta;
        }
    }

    /** Desfaz só o nível que ESTA transação abriu — nunca o de quem estava por fora. */
    private function desfazer(Connection $conexao, int $nivelAntes): void
    {
        try {
            if ($conexao->getTransactionNestingLevel() > $nivelAntes) {
                $conexao->rollBack();
            }
        } catch (\Throwable) {
            // A conexão caiu: o servidor aborta a transação sozinho. Nada a fazer aqui.
        }
    }

    /**
     * @param \Closure(): list<ChaveDeArquivo> $arquivosNovos
     */
    private function decidir(
        DestinoDaTransacao $destino,
        \Closure $arquivosNovos,
        string $contexto,
        \Throwable $falha,
        ?string $xid,
    ): void {
        try {
            if ($xid !== null) {
                $this->logger->error('O COMMIT falhou do ponto de vista da aplicação.', [
                    'contexto' => $contexto,
                    'xid'      => $xid,
                    'destino'  => $destino->value,
                    'erro'     => $falha->getMessage(),
                    'classe'   => $falha::class,
                ]);
            }

            $chaves = $arquivosNovos();

            if ($destino->permiteDescartarArquivoNovo()) {
                $this->remocao->remover($chaves, $contexto);

                return;
            }

            $this->remocao->preservar($chaves, $contexto, sprintf('destino da transação: %s', $destino->value));
        } catch (\Throwable $e) {
            // A limpeza nunca mascara a falha original, que quem chamou vai receber.
            $this->logger->error('Falha ao decidir o destino do arquivo novo.', [
                'contexto' => $contexto,
                'erro'     => $e->getMessage(),
            ]);
        }
    }
}
