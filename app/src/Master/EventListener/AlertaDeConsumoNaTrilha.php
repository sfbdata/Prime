<?php

declare(strict_types=1);

namespace App\Master\EventListener;

use App\Contribua\Entity\ConfiguracaoDoEspaco;
use App\Contribua\Enum\FaixaDoEspaco;
use App\Contribua\Event\EspacoAtingiuFaixa;
use App\Contribua\Repository\ConfiguracaoDoEspacoRepository;
use App\Master\Repository\PainelMasterRepository;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

/**
 * "Alerta de consumo" do Sistema na trilha do Master (desenho 05 - Perfis 1.2, linhas 481, 604 e 700;
 * spec perfis-1.2-planos-e-limites P11): quando um envio faz a conta subir de faixa, a trilha ganha uma
 * linha com a conta do evento, sem responsável ("Sistema") e o detalhe "Passou de N% do armazenamento",
 * em que N é o início da faixa alcançada (as faixas que o Master configura em Armazenamento).
 *
 * O evento da Contribua sai depois do commit de quem gravou o arquivo: envio desfeito não vira alerta.
 */
#[AsEventListener(event: EspacoAtingiuFaixa::class)]
final class AlertaDeConsumoNaTrilha
{
    public function __construct(
        private readonly PainelMasterRepository $repositorio,
        private readonly ConfiguracaoDoEspacoRepository $configuracoes,
    ) {
    }

    public function __invoke(EspacoAtingiuFaixa $evento): void
    {
        $limiar = self::limiar($evento->faixa, $this->configuracoes->atual());
        if ($limiar === null) {
            return;
        }

        $this->repositorio->registrarAlertaDeConsumo($evento->tenantId, $limiar, $evento->retrato->percentual, $evento->faixa->value);
    }

    private static function limiar(FaixaDoEspaco $faixa, ConfiguracaoDoEspaco $c): ?int
    {
        return match ($faixa) {
            FaixaDoEspaco::Atencao => $c->getFaixaAtencao(),
            FaixaDoEspaco::Alerta => $c->getFaixaAlerta(),
            FaixaDoEspaco::Critico => $c->getFaixaCritico(),
            FaixaDoEspaco::Bloqueio => $c->getFaixaBloqueio(),
            FaixaDoEspaco::Livre => null,
        };
    }
}
