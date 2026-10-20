<?php

declare(strict_types=1);

namespace App\Tests\Agenda\Unit;

use App\Agenda\DTO\CompromissoOutput as C;
use App\Agenda\Repository\EventoDaAgendaRepository;
use App\Agenda\Service\OcorrenciasDoEvento;
use App\Agenda\UseCase\ListarCompromissosUseCase;
use App\Entity\Agenda\Evento;
use App\Entity\Agenda\LegendaCor;
use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Pasta\Repository\PastaPrazoRepository;
use App\Profile\Repository\UserProfileRepository;
use App\Repository\LegendaCorRepository;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

#[CoversClass(ListarCompromissosUseCase::class)]
#[CoversClass(C::class)]
final class ListarCompromissosUseCaseTest extends TestCase
{
    #[TestDox('Calendário do evento sai da legenda de cor do escritório')]
    public function testCalendarioPelaLegenda(): void
    {
        $legenda = (new LegendaCor())->setNome('Audiências')->setCor('#123456');
        $itens = $this->executar([$this->evento('#123456'), $this->evento('#999999')], [$legenda]);

        self::assertSame([C::CAL_AUD, C::CAL_MEUS], array_map(static fn (C $c): string => $c->calendario, $itens));
    }

    #[TestDox('Sem legendas, vale a cor padrão do formulário antigo')]
    public function testCoresPadrao(): void
    {
        $itens = $this->executar([$this->evento(Evento::COR_AMARELO)], []);

        self::assertSame(C::CAL_REUNIAO, $itens[0]->calendario);
    }

    #[TestDox('Evento de dia inteiro com três dias aparece em cada dia')]
    public function testDiaInteiroVariosDias(): void
    {
        $e = $this->evento(Evento::COR_ROXO, '2030-03-10 00:00', '2030-03-12 23:59');
        $e->setDiaInteiro(true);
        $itens = $this->executar([$e], []);

        self::assertSame(['2030-03-10', '2030-03-11', '2030-03-12'], array_map(static fn (C $c): string => $c->data, $itens));
        self::assertTrue($itens[0]->dia);
        self::assertSame('', $itens[0]->h1);
    }

    #[TestDox('Dia inteiro com fim à meia-noite (gravado pelo arrastar da agenda antiga) não ganha um dia a mais')]
    public function testFimAMeiaNoite(): void
    {
        $e = $this->evento(Evento::COR_ROXO, '2030-03-10 00:00', '2030-03-11 00:00');
        $e->setDiaInteiro(true);

        self::assertSame(['2030-03-10'], array_map(static fn (C $c): string => $c->data, $this->executar([$e], [])));
    }

    #[TestDox('Dia inteiro longo mostra os dias do período, mesmo começando meses antes')]
    public function testDiaInteiroLongo(): void
    {
        $e = $this->evento(Evento::COR_ROXO, '2029-12-01 00:00', '2030-03-05 23:59');
        $e->setDiaInteiro(true);

        self::assertSame(['2030-03-01', '2030-03-02', '2030-03-03', '2030-03-04', '2030-03-05'], array_map(static fn (C $c): string => $c->data, $this->executar([$e], [])));
    }

    #[TestDox('Aniversário em 29/02 cai em 28/02 no ano que não é bissexto')]
    public function testAniversario2902(): void
    {
        $perfis = $this->createStub(UserProfileRepository::class);
        $perfis->method('aniversariantesDoEscritorio')->willReturn([['id' => 7, 'nome' => 'Ana Paula Reis', 'nascimento' => new \DateTimeImmutable('1992-02-29')]]);
        $sut = new ListarCompromissosUseCase($this->eventos([]), new OcorrenciasDoEvento(), $this->createStub(PastaPrazoRepository::class), $perfis, $this->legendas([]));

        $itens = $sut->executar(new Tenant(), new User(), new \DateTimeImmutable('2030-02-01'), new \DateTimeImmutable('2030-03-31'), false, false);

        self::assertSame('2030-02-28', $itens[0]->data);
        self::assertSame('🎂 Aniversário de Ana Paula', $itens[0]->titulo);
    }

    /**
     * @param list<Evento>     $eventos
     * @param list<LegendaCor> $legendas
     *
     * @return list<C>
     */
    private function executar(array $eventos, array $legendas): array
    {
        $perfis = $this->createStub(UserProfileRepository::class);
        $perfis->method('aniversariantesDoEscritorio')->willReturn([]);
        $sut = new ListarCompromissosUseCase($this->eventos($eventos), new OcorrenciasDoEvento(), $this->createStub(PastaPrazoRepository::class), $perfis, $this->legendas($legendas));

        return $sut->executar(new Tenant(), new User(), new \DateTimeImmutable('2030-03-01'), new \DateTimeImmutable('2030-03-31'), false, false);
    }

    /** @param list<Evento> $eventos */
    private function eventos(array $eventos): EventoDaAgendaRepository
    {
        $repo = $this->createStub(EventoDaAgendaRepository::class);
        $repo->method('doEscritorioNoPeriodo')->willReturn($eventos);

        return $repo;
    }

    /** @param list<LegendaCor> $legendas */
    private function legendas(array $legendas): LegendaCorRepository
    {
        $repo = $this->createStub(LegendaCorRepository::class);
        $repo->method('findBy')->willReturn($legendas);

        return $repo;
    }

    private function evento(string $cor, string $inicio = '2030-03-10 09:00', string $fim = '2030-03-10 10:00'): Evento
    {
        $e = new Evento();
        $e->setTitulo('Evento');
        $e->setCor($cor);
        $e->setDataInicio(new \DateTimeImmutable($inicio));
        $e->setDataFim(new \DateTimeImmutable($fim));

        return $e;
    }
}
