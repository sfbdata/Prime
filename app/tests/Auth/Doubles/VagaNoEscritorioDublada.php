<?php

declare(strict_types=1);

namespace App\Tests\Auth\Doubles;

use App\Auth\Service\VagaNoEscritorio;
use App\Contribua\Entity\ConfiguracaoDoEspaco;
use App\Contribua\Entity\Plano;
use App\Contribua\Repository\ConfiguracaoDoEspacoRepository;
use App\Contribua\Repository\ConsumoDeArmazenamentoRepository;
use App\Contribua\Repository\EspacoDoEscritorioRepository;
use App\Contribua\Repository\PlanoRepository;
use App\Contribua\Repository\UsuariosDoEscritorioRepository;
use App\Contribua\Service\SituacaoDoEspaco;

/**
 * `VagaNoEscritorio` real para os testes unitários de convite: `SituacaoDoEspaco` é final, então ela é
 * montada com os repositórios da Contribua dublados. O escritório está no Free (3 usuários).
 */
trait VagaNoEscritorioDublada
{
    private function vagaNoEscritorio(bool $bloqueioLigado = false, int $usuarios = 0): VagaNoEscritorio
    {
        $configuracao = new ConfiguracaoDoEspaco();
        $configuracao->ligarBloqueio($bloqueioLigado);
        $espacos = $this->createMock(EspacoDoEscritorioRepository::class);
        $espacos->method('doTenant')->willReturn(null);
        $planos = $this->createMock(PlanoRepository::class);
        $planos->method('free')->willReturn(new Plano(Plano::CODIGO_FREE, 'Free', 10, 0, 'Grátis', 1, 3));
        $configuracoes = $this->createMock(ConfiguracaoDoEspacoRepository::class);
        $configuracoes->method('atual')->willReturn($configuracao);
        $quantos = $this->createMock(UsuariosDoEscritorioRepository::class);
        $quantos->method('quantos')->willReturn($usuarios);

        return new VagaNoEscritorio(new SituacaoDoEspaco($this->createMock(ConsumoDeArmazenamentoRepository::class), $espacos, $planos, $configuracoes), $quantos);
    }
}
