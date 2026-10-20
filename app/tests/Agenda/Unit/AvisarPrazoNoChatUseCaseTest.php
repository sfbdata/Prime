<?php

declare(strict_types=1);

namespace App\Tests\Agenda\Unit;

use App\Agenda\Exception\CompromissoNaoEncontradoException;
use App\Agenda\Service\AvisoDoCompromisso;
use App\Agenda\UseCase\AvisarPrazoNoChatUseCase;
use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Pasta\Entity\Pasta;
use App\Pasta\Entity\PastaPrazo;
use App\Pasta\Repository\PastaPrazoRepository;
use App\Repository\UserRepository;
use App\Service\NotificacaoService;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

#[CoversClass(AvisarPrazoNoChatUseCase::class)]
#[CoversClass(AvisoDoCompromisso::class)]
final class AvisarPrazoNoChatUseCaseTest extends TestCase
{
    #[TestDox('Avisa colegas ativos do escritório sobre o prazo, com o atalho para a pasta; quem avisa e quem é de fora ficam de fora')]
    public function testAvisa(): void
    {
        $eu = $this->usuario(1, 'Samuel Freitas');
        $maria = $this->usuario(2, 'Maria');
        $notificacoes = $this->createMock(NotificacaoService::class);
        $notificacoes->expects(self::once())->method('criarNotificacao')->with(
            $maria,
            self::anything(),
            self::anything(),
            'Agenda: Audiência · Pasta AV-0001',
            '/pasta/7',
            '📅 Samuel marcou você em uma audiência: Audiência · ter, 12/03 às 14:30 · Pasta AV-0001',
        );

        $n = $this->sut($this->audiencia(), [$eu, $maria], $notificacoes)->executar(3, [1, 2, 99], $eu, new Tenant());

        self::assertSame(1, $n);
    }

    #[TestDox('Prazo de outro escritório ou de pasta na lixeira dá "não encontrado"')]
    public function testNaoEncontrado(): void
    {
        $this->expectException(CompromissoNaoEncontradoException::class);

        $this->sut(null, [], $this->createStub(NotificacaoService::class))->executar(3, [2], $this->usuario(1, 'Samuel'), new Tenant());
    }

    private function audiencia(): PastaPrazo
    {
        $pasta = (new Pasta())->setNup('AV-0001');
        (new \ReflectionProperty(Pasta::class, 'id'))->setValue($pasta, 7);
        $prazo = new PastaPrazo(new Tenant(), $pasta, null);
        $prazo->definir(PastaPrazo::TIPO_AUDIENCIA, 'Conciliação', new \DateTimeImmutable('2030-03-12'), '14:30', null);

        return $prazo;
    }

    /** @param list<User> $ativos */
    private function sut(?PastaPrazo $prazo, array $ativos, NotificacaoService $notificacoes): AvisarPrazoNoChatUseCase
    {
        $repo = $this->createStub(PastaPrazoRepository::class);
        $repo->method('doEscritorio')->willReturn($prazo);
        $usuarios = $this->createStub(UserRepository::class);
        $usuarios->method('findColaboradoresAtivosPorTenant')->willReturn($ativos);
        $urls = $this->createStub(UrlGeneratorInterface::class);
        $urls->method('generate')->willReturnCallback(static fn (string $rota, array $p = []): string => 'pasta_show' === $rota ? '/pasta/' . $p['id'] : '/agenda');

        return new AvisarPrazoNoChatUseCase($repo, $usuarios, new AvisoDoCompromisso($notificacoes, $urls));
    }

    private function usuario(int $id, string $nome): User
    {
        $u = new User();
        $u->setFullName($nome);
        (new \ReflectionProperty(User::class, 'id'))->setValue($u, $id);

        return $u;
    }
}
