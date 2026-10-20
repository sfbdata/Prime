<?php

declare(strict_types=1);

namespace App\Tests\Agenda\Unit;

use App\Agenda\Entity\Evento;
use App\Agenda\Exception\CompromissoNaoEncontradoException;
use App\Agenda\Repository\EventoDaAgendaRepository;
use App\Agenda\Service\AvisoDoCompromisso;
use App\Agenda\UseCase\AvisarNoChatUseCase;
use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Repository\UserRepository;
use App\Service\NotificacaoService;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

#[CoversClass(AvisarNoChatUseCase::class)]
final class AvisarNoChatUseCaseTest extends TestCase
{
    #[TestDox('Avisa só colegas ativos do escritório, uma vez só')]
    public function testAvisaUmaVez(): void
    {
        $eu = $this->usuario(1, 'Samuel');
        $maria = $this->usuario(2, 'Maria');
        $evento = (new Evento())->setCriador($eu)->setTenant(new Tenant());
        $notificacoes = $this->createMock(NotificacaoService::class);
        $notificacoes->expects(self::once())->method('criarNotificacao')->with($maria);
        $sut = $this->sut($evento, [$eu, $maria], $notificacoes);

        self::assertSame(1, $sut->executar(5, [2, 3, 99], $eu, new Tenant()));
        self::assertSame(0, $sut->executar(5, [2], $eu, new Tenant()));
        self::assertSame([2], $evento->getAvisados());
    }

    #[TestDox('Compromisso "Só eu" não é avisado')]
    public function testSoEu(): void
    {
        $eu = $this->usuario(1, 'Samuel');
        $evento = (new Evento())->setCriador($eu)->setVisibilidade(Evento::VISIBILIDADE_SOMENTE_EU);
        $this->expectException(\DomainException::class);

        $this->sut($evento, [$eu], $this->createStub(NotificacaoService::class))->executar(5, [2], $eu, new Tenant());
    }

    #[TestDox('Compromisso que não vejo dá "não encontrado"')]
    public function testInvisivel(): void
    {
        $this->expectException(CompromissoNaoEncontradoException::class);

        $this->sut(null, [], $this->createStub(NotificacaoService::class))->executar(5, [2], $this->usuario(1, 'Samuel'), new Tenant());
    }

    /** @param list<User> $ativos */
    private function sut(?Evento $evento, array $ativos, NotificacaoService $notificacoes): AvisarNoChatUseCase
    {
        $repo = $this->createStub(EventoDaAgendaRepository::class);
        $repo->method('visivelPara')->willReturn($evento);
        $usuarios = $this->createStub(UserRepository::class);
        $usuarios->method('findColaboradoresAtivosPorTenant')->willReturn($ativos);
        $urls = $this->createStub(UrlGeneratorInterface::class);
        $urls->method('generate')->willReturn('/agenda/nova');

        return new AvisarNoChatUseCase($repo, $usuarios, new AvisoDoCompromisso($notificacoes, $urls));
    }

    private function usuario(int $id, string $nome): User
    {
        $u = new User();
        $u->setFullName($nome);
        (new \ReflectionProperty(User::class, 'id'))->setValue($u, $id);

        return $u;
    }
}
