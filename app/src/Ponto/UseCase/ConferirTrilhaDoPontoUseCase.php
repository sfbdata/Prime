<?php

declare(strict_types=1);

namespace App\Ponto\UseCase;

use App\Entity\Tenant\Tenant;
use App\Ponto\DTO\ConferenciaDaTrilhaDoPontoOutput;
use App\Ponto\Repository\TrilhaDoPontoRepository;
use App\Ponto\Service\ChavesDoHmacDoPonto;
use App\Ponto\Service\ConferenciaDaCadeiaDoPonto;

/**
 * Confere a trilha de auditoria do Ponto de um escritório (E9, cabeçalho da aba Auditoria): íntegra, alterada (com a
 * sequência do primeiro evento que não confere) ou não selada. A conta é da {@see ConferenciaDaCadeiaDoPonto}; aqui
 * fica a leitura da cadeia em blocos (sem carregar a trilha inteira de uma vez) e a escolha da chave pela versão
 * gravada em cada linha.
 *
 * Sem `PONTO_HMAC_SECRET` no servidor, nada pode ser conferido: "não selada", sem segredo, mesmo que haja linhas
 * seladas de antes.
 *
 * Quem: só lê; a permissão de ver a trilha (`GestaoDoPonto::veAuditoria`) é de quem chama.
 */
final class ConferirTrilhaDoPontoUseCase
{
    private const BLOCO = 500;

    public function __construct(
        private readonly TrilhaDoPontoRepository $trilha,
        private readonly ChavesDoHmacDoPonto $chaves,
    ) {
    }

    public function executar(Tenant $escritorio): ConferenciaDaTrilhaDoPontoOutput
    {
        if (!$this->chaves->disponivel()) {
            return ConferenciaDaTrilhaDoPontoOutput::semSegredo();
        }

        $tenantId = $escritorio->getId() ?? throw new \LogicException('Escritório sem id na conferência da trilha.');

        return ConferenciaDaCadeiaDoPonto::conferir(
            $this->cadeia($tenantId),
            fn (int $versao): ?string => $this->chaves->chave(ChavesDoHmacDoPonto::FINALIDADE_TRILHA, $versao),
        );
    }

    /**
     * @return \Generator<int, array{id: int, tenant_id: int, sequencia: int, em: string, autor_id: ?int,
     *     autor_nome: ?string, acao: string, detalhe: array<string, mixed>, selo_anterior: ?string, selo: ?string,
     *     versao_da_chave: ?int}>
     */
    private function cadeia(int $tenantId): \Generator
    {
        $depoisDe = 0;
        do {
            $bloco = $this->trilha->blocoDaCadeia($tenantId, $depoisDe, self::BLOCO);
            foreach ($bloco as $linha) {
                yield $linha;
                $depoisDe = $linha['sequencia'];
            }
        } while (\count($bloco) === self::BLOCO);
    }
}
