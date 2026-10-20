<?php

declare(strict_types=1);

namespace App\Tests\Agenda\Unit;

use App\Agenda\Entity\Evento;
use App\Agenda\Exception\SemPermissaoNoCompromissoException;
use App\Agenda\Repository\EventoDaAgendaRepository;
use App\Agenda\UseCase\MoverCompromissoUseCase;
use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Service\PermissionChecker;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

#[CoversClass(MoverCompromissoUseCase::class)]
final class MoverCompromissoUseCaseTest extends TestCase
{
    #[TestDox('Arrastar para outro dia mantém a hora e a duração; para outra hora, troca a hora')]
    public function testMove(): void
    {
        $eu = $this->usuario(1);
        $evento = $this->evento($eu, '2030-03-10 09:00', '2030-03-10 10:30');
        $sut = new MoverCompromissoUseCase($this->repo($evento), $this->createStub(PermissionChecker::class));

        self::assertSame('2030-03-12', $sut->executar(5, '2030-03-12', null, $eu, new Tenant()));
        self::assertSame('2030-03-12 09:00 2030-03-12 10:30', $evento->getDataInicio()->format('Y-m-d H:i') . ' ' . $evento->getDataFim()->format('Y-m-d H:i'));

        $sut->executar(5, '2030-03-13', '14:00', $eu, new Tenant());
        self::assertSame('2030-03-13 14:00 2030-03-13 15:30', $evento->getDataInicio()->format('Y-m-d H:i') . ' ' . $evento->getDataFim()->format('Y-m-d H:i'));
    }

    #[TestDox('Compromisso de 31 dias continua com 31 dias ao passar de janeiro para fevereiro')]
    public function testDuracaoLonga(): void
    {
        $eu = $this->usuario(1);
        $evento = $this->evento($eu, '2030-01-30 00:00', '2030-03-02 00:00');

        (new MoverCompromissoUseCase($this->repo($evento), $this->createStub(PermissionChecker::class)))->executar(5, '2030-02-01', null, $eu, new Tenant());

        self::assertSame('2030-03-04', $evento->getDataFim()->format('Y-m-d'));
    }

    #[TestDox('Dia inteiro arrastado para uma hora continua dia inteiro')]
    public function testDiaInteiro(): void
    {
        $eu = $this->usuario(1);
        $evento = $this->evento($eu, '2030-03-10 00:00', '2030-03-11 23:59:59')->setDiaInteiro(true);

        (new MoverCompromissoUseCase($this->repo($evento), $this->createStub(PermissionChecker::class)))->executar(5, '2030-03-20', '14:00', $eu, new Tenant());

        self::assertSame('2030-03-20 00:00 2030-03-21 23:59', $evento->getDataInicio()->format('Y-m-d H:i') . ' ' . $evento->getDataFim()->format('Y-m-d H:i'));
    }

    #[TestDox('Série não se arrasta; colega sem administrar usuários também não')]
    public function testRecusas(): void
    {
        $eu = $this->usuario(1);
        $serie = $this->evento($eu, '2030-03-10 09:00', '2030-03-10 10:00')->setRecorrente(true);
        try {
            (new MoverCompromissoUseCase($this->repo($serie), $this->createStub(PermissionChecker::class)))->executar(5, '2030-03-12', null, $eu, new Tenant());
            self::fail('Série devia ser recusada');
        } catch (\DomainException) {
        }

        $deOutro = $this->evento($this->usuario(2), '2030-03-10 09:00', '2030-03-10 10:00');
        $this->expectException(SemPermissaoNoCompromissoException::class);
        (new MoverCompromissoUseCase($this->repo($deOutro), $this->createStub(PermissionChecker::class)))->executar(5, '2030-03-12', null, $eu, new Tenant());
    }

    private function repo(Evento $evento): EventoDaAgendaRepository
    {
        $repo = $this->createStub(EventoDaAgendaRepository::class);
        $repo->method('visivelPara')->willReturn($evento);

        return $repo;
    }

    private function evento(User $criador, string $inicio, string $fim): Evento
    {
        return (new Evento())->setCriador($criador)->setDataInicio(new \DateTimeImmutable($inicio))->setDataFim(new \DateTimeImmutable($fim));
    }

    private function usuario(int $id): User
    {
        $u = new User();
        (new \ReflectionProperty(User::class, 'id'))->setValue($u, $id);

        return $u;
    }
}
