<?php

declare(strict_types=1);

namespace App\Tests\TrabalhoExterno\Unit;

use App\Entity\Auth\User;
use App\Repository\UserTenantRepository;
use App\Security\GoogleAuthenticator;
use App\TrabalhoExterno\Entity\ConviteDemanda;
use App\TrabalhoExterno\EventListener\EntradaDoConvidadoListener;
use App\TrabalhoExterno\Repository\ConviteDemandaRepository;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Http\Authenticator\AuthenticatorInterface;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\UserBadge;
use Symfony\Component\Security\Http\Authenticator\Passport\SelfValidatingPassport;
use Symfony\Component\Security\Http\Authenticator\Token\PostAuthenticationToken;
use Symfony\Component\Security\Http\Event\LoginSuccessEvent;

/** O convidado do Trabalho Externo sem escritório que entra pelo Google vai ao Trabalho Externo, como pela senha. */
#[CoversClass(EntradaDoConvidadoListener::class)]
final class EntradaDoConvidadoPeloGoogleTest extends TestCase
{
    #[TestDox('Pelo Google, o convidado sem escritório e com convite vigente vai ao Trabalho Externo; por outro autenticador (cookie), segue')]
    public function testPeloGoogle(): void
    {
        $vinculos = $this->createStub(UserTenantRepository::class);
        $vinculos->method('findActiveByUser')->willReturn([]);
        $convites = $this->createStub(ConviteDemandaRepository::class);
        $convites->method('vigentesDo')->willReturn([$this->createStub(ConviteDemanda::class)]);
        $urls = $this->createStub(UrlGeneratorInterface::class);
        $urls->method('generate')->willReturn('/trabalho-externo');
        $listener = new EntradaDoConvidadoListener($vinculos, $convites, $urls);
        $google = (new \ReflectionClass(GoogleAuthenticator::class))->newInstanceWithoutConstructor();

        $peloGoogle = $this->evento($google);
        $listener($peloGoogle);
        $peloCookie = $this->evento($this->createStub(AuthenticatorInterface::class));
        $listener($peloCookie);

        self::assertInstanceOf(RedirectResponse::class, $peloGoogle->getResponse());
        self::assertSame('/trabalho-externo', $peloGoogle->getResponse()->getTargetUrl());
        self::assertNull($peloCookie->getResponse());
    }

    private function evento(AuthenticatorInterface $autenticador): LoginSuccessEvent
    {
        $usuario = (new User())->setEmail('convidado@gmail.com')->setRoles(['ROLE_USER']);

        return new LoginSuccessEvent($autenticador, new SelfValidatingPassport(new UserBadge('convidado@gmail.com', static fn (): User => $usuario)), new PostAuthenticationToken($usuario, 'main', ['ROLE_USER']), new Request(), null, 'main');
    }
}
