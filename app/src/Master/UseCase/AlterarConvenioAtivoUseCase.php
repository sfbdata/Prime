<?php

declare(strict_types=1);

namespace App\Master\UseCase;

use App\Entity\Auth\User;
use App\Master\Entity\ConfiguracaoGlobal;
use App\Master\Repository\ConfiguracaoGlobalRepository;
use App\Shared\Audit\EscopoDeAuditoriaDePlataforma;
use Psr\Clock\ClockInterface;

/**
 * Liga ou desliga o Convênio OAB na plataforma inteira (pedido do Farlei M2.1, 24/11/2026).
 *
 * História: o Master quer poder tirar o Convênio OAB da vitrine sem mexer em código. Pela chave de Planos e limites,
 * desligar some com a faixa e a tag do Plano Free no painel e com os selos do Contribua; religar traz tudo de volta.
 * Nenhum plano, preço ou assinatura muda: a cobrança segue igual.
 *
 * Grava em `configuracao_global` (`convenio.ativo`), no escopo de auditoria de plataforma: a troca entra na Trilha de
 * Auditoria com quem e quando, sem escritório.
 */
final class AlterarConvenioAtivoUseCase
{
    public function __construct(
        private readonly ConfiguracaoGlobalRepository $configuracoes,
        private readonly EscopoDeAuditoriaDePlataforma $escopoDePlataforma,
        private readonly ClockInterface $relogio,
    ) {
    }

    /** @return bool o estado gravado */
    public function executar(bool $ativo, User $master): bool
    {
        $agora = $this->relogio->now();

        $this->escopoDePlataforma->executar(function () use ($ativo, $master, $agora): void {
            $linha = $this->configuracoes->buscarUnica() ?? new ConfiguracaoGlobal();
            $linha->definirConvenioAtivo($ativo, $master, $agora);
            $this->configuracoes->salvar($linha, flush: true);
        });

        return $ativo;
    }
}
