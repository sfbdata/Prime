<?php

declare(strict_types=1);

namespace App\Inteligencia\UseCase;

use App\Entity\Auth\User;
use App\Inteligencia\Repository\ConfiguracaoDaPlataformaDeIaRepository;
use App\Inteligencia\Service\GuardaDoMasterDaIa;
use Psr\Clock\ClockInterface;

/**
 * O Master liga ou desliga a BlueJus IA para todos os escritórios liberados (a chave geral, spec ia-anthropic-ligar
 * §10.1). Desligar corta também a fila: o worker reconfere a disponibilidade antes do provedor (IA-034L).
 */
final class AlterarChaveGeralDaIaUseCase
{
    public function __construct(
        private readonly ConfiguracaoDaPlataformaDeIaRepository $plataforma,
        private readonly ClockInterface $relogio,
    ) {
    }

    public function executar(bool $ligar, User $master): void
    {
        GuardaDoMasterDaIa::exigir($master);
        $configuracao = $this->plataforma->obterParaAlterar();
        if ($ligar) {
            $configuracao->ligarParaTodos($master, $this->relogio->now());
        } else {
            $configuracao->desligarParaTodos($master, $this->relogio->now());
        }
        $this->plataforma->salvar($configuracao, flush: true);
    }
}
