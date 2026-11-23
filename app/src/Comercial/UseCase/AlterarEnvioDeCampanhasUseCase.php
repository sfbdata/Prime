<?php

declare(strict_types=1);

namespace App\Comercial\UseCase;

use App\Comercial\Campanha\ChaveDasCampanhas;
use App\Comercial\DTO\EstadoDoEnvioDeCampanhas;
use App\Comercial\Repository\CampanhaDeEmailRepository;
use App\Entity\Auth\User;
use App\Master\Entity\ConfiguracaoGlobal;
use App\Master\Repository\ConfiguracaoGlobalRepository;
use App\Shared\Audit\EscopoDeAuditoriaDePlataforma;
use Psr\Clock\ClockInterface;

/**
 * Liga ou desliga o envio de campanhas pela Central de envios (pedido do Farlei M8.1 a M8.4, 23/11/2026).
 *
 * História: o Farlei programou campanhas e nada saiu, porque a chave era uma variável do servidor. Agora o Master a
 * troca no painel. Desligada, nada de campanha sai e nada é cancelado: as confirmadas e agendadas ficam esperando. Ao
 * religar, as que venceram no meio passam a valer agora (senão o expansor cancelaria as que passaram de 24 h de
 * atraso) e saem na volta seguinte, em até 1 minuto. As datas comemorativas obedecem à mesma chave.
 *
 * Grava em `configuracao_global`, no escopo de auditoria de plataforma: a troca entra na Trilha de Auditoria com quem
 * e quando. Trazer as vencidas para agora e gravar a chave vão na mesma transação, nessa ordem, para nenhuma volta do
 * expansor ver a chave ligada com uma vencida ainda no horário antigo.
 */
final class AlterarEnvioDeCampanhasUseCase
{
    public function __construct(
        private readonly ConfiguracaoGlobalRepository $configuracoes,
        private readonly CampanhaDeEmailRepository $campanhas,
        private readonly ChaveDasCampanhas $chave,
        private readonly EscopoDeAuditoriaDePlataforma $escopoDePlataforma,
        private readonly ClockInterface $relogio,
        private readonly ConsultarEnvioDeCampanhasUseCase $consultar,
    ) {
    }

    public function executar(bool $ligada, User $master): EstadoDoEnvioDeCampanhas
    {
        $agora = $this->relogio->now();
        $religando = $ligada && !$this->chave->ligada();

        $this->escopoDePlataforma->executar(function () use ($ligada, $religando, $master, $agora): void {
            $this->campanhas->emTransacao(function () use ($ligada, $religando, $master, $agora): void {
                if ($religando) {
                    $this->campanhas->trazerParaAgoraAsVencidas($agora);
                }
                $linha = $this->configuracoes->buscarUnica() ?? new ConfiguracaoGlobal();
                $linha->definirEnvioDeCampanhas($ligada, $master, $agora);
                $this->configuracoes->salvar($linha, flush: true);
            });
        });

        return $this->consultar->executar();
    }
}
