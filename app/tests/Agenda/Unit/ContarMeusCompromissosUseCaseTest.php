<?php

declare(strict_types=1);

namespace App\Tests\Agenda\Unit;

use App\Agenda\Entity\Evento;
use App\Agenda\Enum\TipoDeCompromisso;
use App\Agenda\Repository\EventoDaAgendaRepository;
use App\Agenda\Service\CalendarioPelaCor;
use App\Agenda\UseCase\ContarMeusCompromissosUseCase;
use App\Entity\Agenda\LegendaCor;
use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Repository\LegendaCorRepository;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

#[CoversClass(ContarMeusCompromissosUseCase::class)]
#[CoversClass(CalendarioPelaCor::class)]
final class ContarMeusCompromissosUseCaseTest extends TestCase
{
    #[TestDox('Conta pelo tipo; o da agenda antiga, sem tipo, conta no calendário da cor (como na grade); zero quando não há')]
    public function testComoNaGrade(): void
    {
        $sut = $this->sut([
            ['tipo' => TipoDeCompromisso::Meus, 'cor' => Evento::COR_AZUL, 'n' => 2],
            ['tipo' => TipoDeCompromisso::Audiencia, 'cor' => Evento::COR_ROXO, 'n' => 1],
            ['tipo' => null, 'cor' => Evento::COR_AZUL, 'n' => 3],
            ['tipo' => null, 'cor' => Evento::COR_ROXO, 'n' => 4],
        ], []);

        self::assertSame(['meus' => 6, 'aud' => 4, 'reun' => 0, 'prazo' => 0, 'urg' => 0, 'pess' => 0, 'lemb' => 0], $sut->executar(new Tenant(), new User()));
    }

    #[TestDox('Com legendas do escritório, a cor vale pelo nome da legenda')]
    public function testPelaLegenda(): void
    {
        $sut = $this->sut([['tipo' => null, 'cor' => '#123456', 'n' => 5]], [(new LegendaCor())->setNome('Reuniões internas')->setCor('#123456')]);

        self::assertSame(['meus' => 0, 'aud' => 0, 'reun' => 5, 'prazo' => 0, 'urg' => 0, 'pess' => 0, 'lemb' => 0], $sut->executar(new Tenant(), new User()));
    }

    /**
     * @param list<array{tipo: ?TipoDeCompromisso, cor: string, n: int}> $linhas
     * @param list<LegendaCor>                                            $legendas
     */
    private function sut(array $linhas, array $legendas): ContarMeusCompromissosUseCase
    {
        $eventos = $this->createStub(EventoDaAgendaRepository::class);
        $eventos->method('contarDoCriadorPorTipoECor')->willReturn($linhas);
        $repoLegendas = $this->createStub(LegendaCorRepository::class);
        $repoLegendas->method('findBy')->willReturn($legendas);

        return new ContarMeusCompromissosUseCase($eventos, $repoLegendas);
    }
}
