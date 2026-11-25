<?php

declare(strict_types=1);

namespace App\Comercial\UseCase;

use App\Comercial\Campanha\IntervaloDosConvites;
use App\Comercial\DTO\IntervaloDeConviteOutput;
use App\Comercial\Exception\CampanhaRecusada;
use App\Entity\Auth\User;
use App\Master\Entity\ConfiguracaoGlobal;
use App\Master\Repository\ConfiguracaoGlobalRepository;
use App\Shared\Audit\EscopoDeAuditoriaDePlataforma;
use Psr\Clock\ClockInterface;

/**
 * Troca o intervalo mínimo entre dois convites ao mesmo contato salvo (decisão de 25/11/2026: configuração do Master,
 * padrão 7 dias, de 1 a 365). Grava em `configuracao_global`, no escopo de auditoria de plataforma: a troca entra na
 * Trilha de Auditoria com quem e quando, como a chave do envio de campanhas.
 */
final class AlterarIntervaloDeConviteUseCase
{
    public function __construct(
        private readonly ConfiguracaoGlobalRepository $configuracoes,
        private readonly EscopoDeAuditoriaDePlataforma $escopoDePlataforma,
        private readonly ClockInterface $relogio,
        private readonly ConsultarIntervaloDeConviteUseCase $consultar,
    ) {
    }

    public function executar(int $dias, User $master): IntervaloDeConviteOutput
    {
        if (!IntervaloDosConvites::valido($dias)) {
            throw new CampanhaRecusada(sprintf('Informe de %d a %d dias.', IntervaloDosConvites::MINIMO, IntervaloDosConvites::MAXIMO));
        }
        $agora = $this->relogio->now();
        $this->escopoDePlataforma->executar(function () use ($dias, $master, $agora): void {
            $linha = $this->configuracoes->buscarUnica() ?? new ConfiguracaoGlobal();
            $linha->definirIntervaloDeConvite($dias, $master, $agora);
            $this->configuracoes->salvar($linha, flush: true);
        });

        return $this->consultar->executar();
    }
}
