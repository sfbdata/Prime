<?php

declare(strict_types=1);

namespace App\Tests\Auth\Unit;

use App\Auth\EventListener\RegistrarAcessoNoLoginListener;
use App\Auth\Repository\AcessoConhecidoRepository;
use App\Auth\Service\AvisoDeAcessoNovoMailerInterface;
use App\Auth\Service\ImpressaoDoAcesso;
use App\Auth\UseCase\RegistrarAcessoEAvisarUseCase;
use App\Entity\Auth\User;
use App\Tests\Support\ConfiguracaoDaPlataformaFalsa;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Http\Authenticator\AuthenticatorInterface;
use Symfony\Component\Security\Http\Authenticator\Passport\SelfValidatingPassport;
use Symfony\Component\Security\Http\Authenticator\RememberMeAuthenticator;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\UserBadge;
use Symfony\Component\Security\Http\Event\LoginSuccessEvent;

#[CoversClass(RegistrarAcessoNoLoginListener::class)]
final class RegistrarAcessoNoLoginListenerTest extends TestCase
{
    private function evento(AuthenticatorInterface $autenticador, User $user): LoginSuccessEvent
    {
        $token = $this->createStub(TokenInterface::class);
        $token->method('getUser')->willReturn($user);

        return new LoginSuccessEvent(
            $autenticador,
            new SelfValidatingPassport(new UserBadge('ana@adv.com', static fn () => $user)),
            $token,
            Request::create('/login', 'POST', server: ['HTTP_USER_AGENT' => 'Chrome/120', 'REMOTE_ADDR' => '200.10.20.5']),
            null,
            'main',
        );
    }

    private function listener(int $chamadasEsperadas): RegistrarAcessoNoLoginListener
    {
        $repository = $this->createMock(AcessoConhecidoRepository::class);
        $repository->expects(self::exactly($chamadasEsperadas))->method('usuarioTemAcessos')
            ->willReturn(false);
        $useCase = new RegistrarAcessoEAvisarUseCase(
            $repository,
            new ImpressaoDoAcesso('s'),
            $this->createStub(AvisoDeAcessoNovoMailerInterface::class),
            new ConfiguracaoDaPlataformaFalsa(),
            new MockClock(),
            new NullLogger(),
        );

        return new RegistrarAcessoNoLoginListener($useCase, new NullLogger());
    }

    #[TestDox('lembrar-me e autenticador desconhecido (API) não registram; o login pelo formulário é provado no teste funcional')]
    public function testLembrarMeNaoRegistra(): void
    {
        $user = (new User())->setEmail('ana@adv.com');
        $listener = $this->listener(0);

        $listener($this->evento($this->createStub(RememberMeAuthenticator::class), $user));
        $listener($this->evento($this->createStub(AuthenticatorInterface::class), $user));
    }
}
