<?php

declare(strict_types=1);

namespace App\Ponto\Service;

use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Ponto\Repository\TrilhaDoPontoRepository;
use Psr\Clock\ClockInterface;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;

/**
 * Grava um evento na trilha de auditoria do Ponto (spec `ponto-desenho-0811.md`, E9, P64): uma cadeia por escritório,
 * cada linha selada junto com a anterior ({@see SeloDaTrilhaDoPonto}). O `selo_anterior` de cada linha é o último selo
 * da cadeia (o da linha de antes, ou o último que existia se ela entrou sem selo), o mesmo elo que a
 * {@see ConferenciaDaCadeiaDoPonto} confere.
 *
 * ## Mesma transação do fato
 *
 * O evento comita junto com o que ele registra: `registrar` roda na transação de quem chama e só abre uma própria
 * quando não há nenhuma ativa. Quem grava o fato por `flush()` solto (sem transação) usa `emTransacao` em volta do
 * `flush()` e do `registrar`; assim, se a batida falha, a trilha não ganha linha, e se a trilha falha, a batida não
 * entra.
 *
 * ## Ordem sem buraco
 *
 * Dentro da transação, `pg_advisory_xact_lock(4203, escritório)` segura a trilha do escritório até o COMMIT: o
 * próximo evento lê o último elo (sequência e selo) só depois que o anterior comitou, então a sequência não repete nem
 * pula, e o índice único (escritório, sequência) é a rede de proteção. Escritórios diferentes não se esperam. Esta
 * trava é a última que o fluxo pega (depois das linhas da justificativa), para não inverter a ordem com outra.
 *
 * A gravação é por DBAL: nunca dá `flush()` na unidade de trabalho de quem chama. Sem `PONTO_HMAC_SECRET`, a linha
 * entra sem selo e sem versão, e nada falha.
 */
final class TrilhaDoPonto
{
    public function __construct(
        private readonly TrilhaDoPontoRepository $repositorio,
        private readonly ChavesDoHmacDoPonto $chaves,
        private readonly ClockInterface $relogio,
        private readonly TokenStorageInterface $sessao,
    ) {
    }

    /**
     * @param string               $acao    código de {@see \App\Ponto\Enum\AcaoDaTrilhaDoPonto}
     * @param array<string, mixed> $detalhe o que o evento precisa para ser lido depois (sem segredo nem arquivo)
     */
    public function registrar(Tenant $tenant, ?User $autor, string $acao, array $detalhe): void
    {
        $tenantId = $tenant->getId() ?? throw new \LogicException('A trilha do Ponto precisa do escritório já gravado.');

        $this->repositorio->emTransacao(function () use ($tenantId, $autor, $acao, $detalhe): void {
            $this->repositorio->travar($tenantId);
            $ultimo = $this->repositorio->ultimoElo($tenantId);

            $linha = [
                'selo_anterior' => $ultimo['selo'] ?? null,
                'tenant_id'     => $tenantId,
                'sequencia'     => ($ultimo['sequencia'] ?? 0) + 1,
                'em'            => $this->relogio->now()->format('Y-m-d H:i:s'),
                'autor_id'      => $autor?->getId(),
                'autor_nome'    => $autor === null ? null : self::nomeDe($autor),
                'acao'          => $acao,
                'detalhe'       => $detalhe,
            ];

            $versao = $this->chaves->versaoAtual();
            $chave  = $versao === null ? null : $this->chaves->chave(ChavesDoHmacDoPonto::FINALIDADE_TRILHA, $versao);

            $this->repositorio->inserir($linha + [
                'selo'            => $chave === null ? null : SeloDaTrilhaDoPonto::calcular($chave, $linha),
                'versao_da_chave' => $chave === null ? null : $versao,
            ]);
        });
    }

    /**
     * Roda o trabalho numa transação (a de quem chama, se houver): para quem grava o fato com `flush()` solto e precisa
     * do evento no mesmo COMMIT.
     *
     * @template T
     *
     * @param \Closure(): T $trabalho
     *
     * @return T
     */
    public function emTransacao(\Closure $trabalho): mixed
    {
        return $this->repositorio->emTransacao($trabalho);
    }

    /** Quem está na sessão, para os UseCases que não recebem o autor (versões da jornada). */
    public function autorDaSessao(): ?User
    {
        $usuario = $this->sessao->getToken()?->getUser();

        return $usuario instanceof User ? $usuario : null;
    }

    private static function nomeDe(User $autor): string
    {
        $nome = trim((string) $autor->getFullName());

        return $nome !== '' ? $nome : (string) $autor->getEmail();
    }
}
