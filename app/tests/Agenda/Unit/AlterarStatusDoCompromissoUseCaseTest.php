<?php

declare(strict_types=1);

namespace App\Tests\Agenda\Unit;

use App\Agenda\Entity\Evento;
use App\Agenda\Repository\EventoDaAgendaRepository;
use App\Agenda\UseCase\AlterarStatusDoCompromissoUseCase;
use App\Entity\Auth\User;
use App\Entity\Notificacao;
use App\Entity\Tenant\Tenant;
use App\Service\NotificacaoService;
use App\Service\PermissionChecker;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

#[CoversClass(AlterarStatusDoCompromissoUseCase::class)]
final class AlterarStatusDoCompromissoUseCaseTest extends TestCase
{
    #[TestDox('Cancelar avisa cada participante uma vez, como na agenda antiga; concluir e reabrir não avisam')]
    public function testCancelarAvisa(): void
    {
        $eu = $this->usuario(1);
        $maria = $this->usuario(2);
        $evento = (new Evento())->setCriador($eu)->setTenant(new Tenant())->setTitulo('Reunião')->setDataInicio(new \DateTimeImmutable('2030-03-10 09:00'))->addParticipante($maria)->addParticipante($eu);
        $notificacoes = $this->createMock(NotificacaoService::class);
        $notificacoes->expects(self::once())->method('criarNotificacao')
            ->with($maria, self::anything(), Notificacao::TIPO_EVENTO_CANCELADO, 'O evento foi cancelado: Reunião', '/agenda#ev=ev--2030-03-10');
        $sut = $this->sut($evento, $notificacoes);

        $sut->executar(5, Evento::STATUS_CONCLUIDO, $eu, new Tenant());
        $sut->executar(5, Evento::STATUS_CANCELADO, $eu, new Tenant());
        $sut->executar(5, Evento::STATUS_CANCELADO, $eu, new Tenant());
        self::assertSame(Evento::STATUS_CANCELADO, $evento->getStatus());
        $sut->executar(5, Evento::STATUS_AGENDADO, $eu, new Tenant());
        self::assertSame(Evento::STATUS_AGENDADO, $evento->getStatus());
    }

    #[TestDox('Aviso de cancelamento com título comprido cabe nos 255 caracteres')]
    public function testAvisoCompridoCabe(): void
    {
        $eu = $this->usuario(1);
        $evento = (new Evento())->setCriador($eu)->setTenant(new Tenant())->setTitulo(str_repeat('a', 255))->setDataInicio(new \DateTimeImmutable('2030-03-10'))->addParticipante($this->usuario(2));
        $notificacoes = $this->createMock(NotificacaoService::class);
        $notificacoes->expects(self::once())->method('criarNotificacao')
            ->with(self::anything(), self::anything(), self::anything(), self::callback(static fn (string $m): bool => 255 === mb_strlen($m)));

        $this->sut($evento, $notificacoes)->executar(5, Evento::STATUS_CANCELADO, $eu, new Tenant());
    }

    #[TestDox('Colega sem administrar usuários não muda a situação; compromisso invisível dá não encontrado')]
    public function testRecusas(): void
    {
        $evento = (new Evento())->setCriador($this->usuario(2));
        try {
            $this->sut($evento, $this->createStub(NotificacaoService::class))->executar(5, Evento::STATUS_CONCLUIDO, $this->usuario(1), new Tenant());
            self::fail('Devia recusar');
        } catch (\App\Agenda\Exception\SemPermissaoNoCompromissoException) {
        }
        $repo = $this->createStub(EventoDaAgendaRepository::class);
        $repo->method('visivelPara')->willReturn(null);
        $this->expectException(\App\Agenda\Exception\CompromissoNaoEncontradoException::class);
        (new AlterarStatusDoCompromissoUseCase($repo, $this->createStub(PermissionChecker::class), $this->createStub(NotificacaoService::class), $this->createStub(UrlGeneratorInterface::class)))
            ->executar(5, Evento::STATUS_CONCLUIDO, $this->usuario(1), new Tenant());
    }

    #[TestDox('Situação fora da lista é recusada')]
    public function testSituacaoInvalida(): void
    {
        $this->expectException(\DomainException::class);

        $this->sut(new Evento(), $this->createStub(NotificacaoService::class))->executar(5, 'arquivado', $this->usuario(1), new Tenant());
    }

    private function sut(Evento $evento, NotificacaoService $notificacoes): AlterarStatusDoCompromissoUseCase
    {
        $repo = $this->createStub(EventoDaAgendaRepository::class);
        $repo->method('visivelPara')->willReturn($evento);
        $urls = $this->createStub(UrlGeneratorInterface::class);
        $urls->method('generate')->willReturn('/agenda');

        return new AlterarStatusDoCompromissoUseCase($repo, $this->createStub(PermissionChecker::class), $notificacoes, $urls);
    }

    private function usuario(int $id): User
    {
        $u = new User();
        (new \ReflectionProperty(User::class, 'id'))->setValue($u, $id);

        return $u;
    }
}
