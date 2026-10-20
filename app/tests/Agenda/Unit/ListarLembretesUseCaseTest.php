<?php

declare(strict_types=1);

namespace App\Tests\Agenda\Unit;

use App\Agenda\DTO\LembreteOutput as L;
use App\Agenda\Entity\Evento;
use App\Agenda\Enum\TipoDeCompromisso;
use App\Agenda\Repository\EventoDaAgendaRepository;
use App\Agenda\Service\OcorrenciasDoEvento;
use App\Agenda\UseCase\ListarLembretesUseCase;
use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Pasta\Entity\Pasta;
use App\Pasta\Entity\PastaPrazo;
use App\Pasta\Repository\PastaPrazoRepository;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

#[CoversClass(ListarLembretesUseCase::class)]
#[CoversClass(L::class)]
final class ListarLembretesUseCaseTest extends TestCase
{
    private const AGORA = '2030-03-10 08:00';

    #[TestDox('Lembrete escolhido, padrão do tipo e "Sem lembrete"; dia inteiro, cancelado e concluído ficam de fora')]
    public function testRegras(): void
    {
        $eventos = [
            $this->evento(1, 'Escolhido', '2030-03-10 10:00')->setLembreteMinutos(30),
            $this->evento(2, 'Audiência padrão', '2030-03-10 14:00')->setTipo(TipoDeCompromisso::Audiencia),
            $this->evento(3, 'Sem lembrete', '2030-03-10 15:00')->setLembreteMinutos(0),
            $this->evento(4, 'Dia inteiro', '2030-03-10 00:00')->setDiaInteiro(true),
            $this->evento(5, 'Cancelado', '2030-03-10 16:00')->setStatus(Evento::STATUS_CANCELADO),
            $this->evento(6, 'Concluído', '2030-03-10 17:00')->setStatus(Evento::STATUS_CONCLUIDO),
            $this->evento(7, 'Antigo sem tipo', '2030-03-10 09:00'),
        ];

        $itens = $this->sut($eventos)->executar(new Tenant(), new User(), new \DateTimeImmutable(self::AGORA), false);
        $porTitulo = array_column(array_map(static fn (L $l): array => $l->toArray(), $itens), null, 'titulo');

        self::assertSame(['Antigo sem tipo', 'Escolhido', 'Audiência padrão'], array_column(array_map(static fn (L $l): array => $l->toArray(), $itens), 'titulo'));
        self::assertStringStartsWith('2030-03-10T09:30', $porTitulo['Escolhido']['aviso']);
        self::assertStringStartsWith('2030-03-10T13:00', $porTitulo['Audiência padrão']['aviso']);
        self::assertSame('aud', $porTitulo['Audiência padrão']['tipo']);
        self::assertStringStartsWith('2030-03-10T08:45', $porTitulo['Antigo sem tipo']['aviso'], 'evento antigo sem tipo usa 15 min');
        self::assertSame('ag:1:2030-03-10T10:00', $porTitulo['Escolhido']['k']);
    }

    #[TestDox('Só entra o que avisa nas próximas 24 horas e o que começou há menos de 5 horas (o navegador corta em 2, na hora local)')]
    public function testJanela(): void
    {
        $eventos = [
            $this->evento(1, 'Daqui a dois dias', '2030-03-12 10:00'),
            $this->evento(2, 'Amanhã cedo', '2030-03-11 07:30'),
            $this->evento(3, 'Começou faz 6 horas', '2030-03-10 02:00'),
        ];

        $itens = $this->sut($eventos)->executar(new Tenant(), new User(), new \DateTimeImmutable(self::AGORA), false);

        self::assertSame(['Amanhã cedo'], array_map(static fn (L $l): string => $l->titulo, $itens));
    }

    #[TestDox('Prazos de hoje com hora: audiência 60 min antes, prazo 15; sem hora fica de fora; só com acesso às pastas')]
    public function testPrazos(): void
    {
        $pasta = (new Pasta())->setNup('1180');
        (new \ReflectionProperty(Pasta::class, 'id'))->setValue($pasta, 9);
        $prazos = [$this->prazo($pasta, PastaPrazo::TIPO_AUDIENCIA, 'Conciliação', '14:30', 'Maria'), $this->prazo($pasta, PastaPrazo::TIPO_PRAZO, 'Contestação', '18:00', null), $this->prazo($pasta, PastaPrazo::TIPO_PRAZO, 'Sem hora', null, null)];

        $itens = $this->sut([], $prazos)->executar(new Tenant(), new User(), new \DateTimeImmutable(self::AGORA), true);
        $sem = $this->sut([], $prazos)->executar(new Tenant(), new User(), new \DateTimeImmutable(self::AGORA), false);

        self::assertSame(['Audiência · Pasta 1180', 'Contestação · Pasta 1180'], array_map(static fn (L $l): string => $l->titulo, $itens));
        self::assertSame(['2030-03-10T13:30:00', '2030-03-10T17:45:00'], array_map(static fn (L $l): string => $l->aviso, $itens));
        self::assertSame(['aud', 'prazo'], array_map(static fn (L $l): string => $l->tipo, $itens));
        self::assertSame('Responsável: Maria', $itens[0]->observacao);
        self::assertSame(9, $itens[0]->pastaId);
        self::assertSame([], $sem, 'sem acesso às pastas, nenhum prazo');
    }

    #[TestDox('Sem acesso às pastas, o lembrete do compromisso não leva a pasta; repetição gera uma chave por ocorrência')]
    public function testPastaEscondidaERepeticao(): void
    {
        $pasta = (new Pasta())->setNup('1180');
        (new \ReflectionProperty(Pasta::class, 'id'))->setValue($pasta, 9);
        $diario = $this->evento(1, 'Diário', '2030-03-01 09:00')->setPasta($pasta)->setRecorrente(true)->setTipoRecorrencia('diario')->setLembreteMinutos(60);

        $itens = $this->sut([$diario])->executar(new Tenant(), new User(), new \DateTimeImmutable(self::AGORA), false);

        self::assertSame(['ag:1:2030-03-10T09:00', 'ag:1:2030-03-11T09:00'], array_map(static fn (L $l): string => $l->k, $itens));
        self::assertNull($itens[0]->pastaId);
        self::assertSame('2030-03-10T09:00:00', $itens[0]->quando, 'hora de parede, sem fuso');
    }

    /**
     * @param list<Evento>     $eventos
     * @param list<PastaPrazo> $prazos
     */
    private function sut(array $eventos, array $prazos = []): ListarLembretesUseCase
    {
        $repo = $this->createStub(EventoDaAgendaRepository::class);
        $repo->method('doEscritorioNoPeriodo')->willReturn($eventos);
        $pz = $this->createStub(PastaPrazoRepository::class);
        $pz->method('abertosDoEscritorioNoPeriodo')->willReturn($prazos);

        return new ListarLembretesUseCase($repo, new OcorrenciasDoEvento(), $pz);
    }

    private function prazo(Pasta $pasta, string $tipo, string $titulo, ?string $hora, ?string $resp): PastaPrazo
    {
        $z = new PastaPrazo(new Tenant(), $pasta, null);
        $z->definir($tipo, $titulo, new \DateTimeImmutable('2030-03-10'), $hora, $resp);

        return $z;
    }

    private function evento(int $id, string $titulo, string $inicio): Evento
    {
        $e = (new Evento())->setTitulo($titulo)->setDataInicio(new \DateTimeImmutable($inicio))->setDataFim((new \DateTimeImmutable($inicio))->modify('+1 hour'));
        (new \ReflectionProperty(Evento::class, 'id'))->setValue($e, $id);

        return $e;
    }
}
