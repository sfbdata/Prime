<?php

declare(strict_types=1);

namespace App\Inteligencia\UseCase;

use App\Entity\Auth\User;
use App\Inteligencia\DTO\AlterarRetencaoDaIaInput;
use App\Inteligencia\Repository\ConfiguracaoDaPlataformaDeIaRepository;
use App\Inteligencia\Service\GuardaDoMasterDaIa;
use Psr\Clock\ClockInterface;

/**
 * O Master define por quantos meses as respostas e perguntas da BlueJus IA ficam guardadas (decisão do dono de
 * 24/10; padrão 12). Quem apaga o que passou do prazo é a tarefa agendada de expiração, que lê este valor.
 */
final class AlterarRetencaoDaIaUseCase
{
    public function __construct(
        private readonly ConfiguracaoDaPlataformaDeIaRepository $plataforma,
        private readonly ClockInterface $relogio,
    ) {
    }

    public function executar(AlterarRetencaoDaIaInput $input, User $master): void
    {
        GuardaDoMasterDaIa::exigir($master);
        $configuracao = $this->plataforma->obterParaAlterar();
        $configuracao->definirRetencao($input->meses, $master, $this->relogio->now());
        $this->plataforma->salvar($configuracao, flush: true);
    }
}
