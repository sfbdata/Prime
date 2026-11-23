<?php

declare(strict_types=1);

namespace App\Comercial\Campanha;

use App\Master\Repository\ConfiguracaoGlobalRepository;

/**
 * A chave do envio de campanhas (spec §8.1.2). Desde o pedido do Farlei de 23/11/2026 (M8.1) quem manda é o
 * interruptor da Central de envios, gravado em `configuracao_global` ({@see \App\Master\Entity\ConfiguracaoGlobal});
 * enquanto ninguém mexeu nele, vale a variável de ambiente `COMERCIAL_CAMPANHAS_LIGADAS` (padrão desligada).
 *
 * Desligada, nada de campanha sai, mas também nada é recusado nem cancelado (decisão do Coordenador, M8.4): a campanha
 * confirmada ou agendada fica esperando e sai quando a chave for religada. As datas comemorativas não são criadas
 * enquanto ela está desligada. A leitura é feita a cada chamada (o worker vive horas): a troca vale na volta seguinte
 * do expansor, sem reiniciar nada.
 */
final class ChaveDasCampanhas
{
    public const PAINEL = 'painel';
    public const AMBIENTE = 'ambiente';

    /**
     * @param bool $campanhasLigadas o padrão do ambiente, que vale enquanto o painel não gravou nada
     * @param ?ConfiguracaoGlobalRepository $configuracoes null nos testes que fixam a chave
     */
    public function __construct(
        private readonly bool $campanhasLigadas,
        private readonly ?ConfiguracaoGlobalRepository $configuracoes = null,
    ) {
    }

    public function ligada(): bool
    {
        return $this->doPainel() ?? $this->campanhasLigadas;
    }

    /** De onde vem o estado atual: do painel (alguém mexeu) ou do ambiente (o padrão do servidor). */
    public function origem(): string
    {
        return $this->doPainel() === null ? self::AMBIENTE : self::PAINEL;
    }

    private function doPainel(): ?bool
    {
        return $this->configuracoes?->envioDeCampanhas();
    }
}
