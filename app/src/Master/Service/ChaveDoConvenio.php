<?php

declare(strict_types=1);

namespace App\Master\Service;

use App\Master\Repository\ConfiguracaoGlobalRepository;

/**
 * A chave geral do Convênio OAB (pedido do Farlei M2.1, 24/11/2026), gravada em `configuracao_global` com o nome
 * `convenio.ativo` ({@see \App\Master\Entity\ConfiguracaoGlobal}). O Master a troca em Planos e limites.
 *
 * Enquanto ninguém mexeu, vale ligada: é como o sistema sempre foi. Desligada, somem a faixa e a tag do Plano Free no
 * painel do Master e os selos do Contribua. A cobrança não muda. Só a tela lê esta chave (nenhum worker), então a
 * leitura cacheada por requisição do repositório basta.
 */
final class ChaveDoConvenio
{
    public function __construct(
        private readonly ConfiguracaoGlobalRepository $configuracoes,
    ) {
    }

    public function ativo(): bool
    {
        return $this->configuracoes->atual()?->getConvenioAtivo() ?? true;
    }
}
