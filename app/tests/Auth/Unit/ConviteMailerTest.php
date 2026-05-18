<?php

declare(strict_types=1);

namespace App\Tests\Auth\Unit;

use App\Auth\Service\ConviteMailer;
use App\Entity\Auth\Invitation;
use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Email;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Twig\Environment;

#[CoversClass(ConviteMailer::class)]
final class ConviteMailerTest extends TestCase
{
    private MailerInterface&MockObject $mailer;
    private Environment&MockObject $twig;
    private UrlGeneratorInterface&MockObject $urlGenerator;
    private ConviteMailer $service;

    private const MAILER_FROM = 'BlueJus <test@bluejus.test>';
    private const FAKE_URL    = 'https://app.jusprime.com.br/convite/abc123';
    private const TOKEN       = 'abc123def456abc123def456abc123def456abc123def456abc123def456abc1';

    protected function setUp(): void
    {
        $this->mailer       = $this->createMock(MailerInterface::class);
        $this->twig         = $this->createMock(Environment::class);
        $this->urlGenerator = $this->createMock(UrlGeneratorInterface::class);

        $this->service = new ConviteMailer(
            $this->mailer,
            $this->twig,
            $this->urlGenerator,
            self::MAILER_FROM,
        );
    }

    // --- Helpers ---

    private function criarConvitePlataforma(): Invitation
    {
        return new Invitation(
            email: 'convidado@exemplo.com',
            token: self::TOKEN,
            type: 'platform',
            expiresAt: new \DateTimeImmutable('+24 hours'),
        );
    }

    private function criarConviteEscritorio(): Invitation
    {
        /** @var Tenant&MockObject $tenant */
        $tenant = $this->createMock(Tenant::class);
        $tenant->method('getName')->willReturn('Escritório Teste');

        /** @var User&MockObject $criador */
        $criador = $this->createMock(User::class);
        $criador->method('getFullName')->willReturn('Dr. Samuel Silva');

        $invitation = new Invitation(
            email: 'colaborador@exemplo.com',
            token: self::TOKEN,
            type: 'office',
            expiresAt: new \DateTimeImmutable('+7 days'),
        );
        $invitation->setTenant($tenant);
        $invitation->setCreatedBy($criador);

        return $invitation;
    }

    private function configurarUrlGenerator(): void
    {
        $this->urlGenerator
            ->method('generate')
            ->with('auth_aceite_convite', ['token' => self::TOKEN], UrlGeneratorInterface::ABSOLUTE_URL)
            ->willReturn(self::FAKE_URL);
    }

    // --- Convite de Plataforma ---

    public function testEnviarConvitePlataformaSubjectCorreto(): void
    {
        $this->configurarUrlGenerator();
        $this->twig->method('render')->willReturn('<html>test</html>');

        $emailCapturado = null;
        $this->mailer->expects($this->once())->method('send')
            ->willReturnCallback(static function (Email $e) use (&$emailCapturado): void {
                $emailCapturado = $e;
            });

        $this->service->enviarConvitePlataforma($this->criarConvitePlataforma());

        self::assertSame('Você foi convidado para o BlueJus', $emailCapturado->getSubject());
    }

    public function testEnviarConvitePlataformaFromCorreto(): void
    {
        $this->configurarUrlGenerator();
        $this->twig->method('render')->willReturn('<html>test</html>');

        $emailCapturado = null;
        $this->mailer->expects($this->once())->method('send')
            ->willReturnCallback(static function (Email $e) use (&$emailCapturado): void {
                $emailCapturado = $e;
            });

        $this->service->enviarConvitePlataforma($this->criarConvitePlataforma());

        self::assertSame('test@bluejus.test', $emailCapturado->getFrom()[0]->getAddress());
        self::assertSame('BlueJus', $emailCapturado->getFrom()[0]->getName());
    }

    public function testEnviarConvitePlataformaToCorreto(): void
    {
        $this->configurarUrlGenerator();
        $this->twig->method('render')->willReturn('<html>test</html>');

        $emailCapturado = null;
        $this->mailer->expects($this->once())->method('send')
            ->willReturnCallback(static function (Email $e) use (&$emailCapturado): void {
                $emailCapturado = $e;
            });

        $this->service->enviarConvitePlataforma($this->criarConvitePlataforma());

        self::assertSame('convidado@exemplo.com', $emailCapturado->getTo()[0]->getAddress());
    }

    public function testEnviarConvitePlataformaUsaTemplateCorreto(): void
    {
        $this->configurarUrlGenerator();

        $templateUsado = null;
        $this->twig->expects($this->once())->method('render')
            ->willReturnCallback(static function (string $t, array $v) use (&$templateUsado): string {
                $templateUsado = $t;

                return '<html>test</html>';
            });

        $this->mailer->method('send');
        $this->service->enviarConvitePlataforma($this->criarConvitePlataforma());

        self::assertSame('email/convite_plataforma.html.twig', $templateUsado);
    }

    public function testEnviarConvitePlataformaPassaLinkEInvitationParaTemplate(): void
    {
        $this->configurarUrlGenerator();
        $invitation = $this->criarConvitePlataforma();

        $variaveisPassadas = null;
        $this->twig->expects($this->once())->method('render')
            ->willReturnCallback(static function (string $t, array $v) use (&$variaveisPassadas): string {
                $variaveisPassadas = $v;

                return '<html>test</html>';
            });

        $this->mailer->method('send');
        $this->service->enviarConvitePlataforma($invitation);

        self::assertArrayHasKey('invitation', $variaveisPassadas);
        self::assertArrayHasKey('link', $variaveisPassadas);
        self::assertSame($invitation, $variaveisPassadas['invitation']);
        self::assertSame(self::FAKE_URL, $variaveisPassadas['link']);
    }

    // --- Convite de Escritório ---

    public function testEnviarConviteEscritorioSubjectCorreto(): void
    {
        $this->configurarUrlGenerator();
        $this->twig->method('render')->willReturn('<html>test</html>');

        $emailCapturado = null;
        $this->mailer->expects($this->once())->method('send')
            ->willReturnCallback(static function (Email $e) use (&$emailCapturado): void {
                $emailCapturado = $e;
            });

        $this->service->enviarConviteEscritorio($this->criarConviteEscritorio());

        self::assertSame(
            'Você foi convidado para colaborar em um escritório no BlueJus',
            $emailCapturado->getSubject(),
        );
    }

    public function testEnviarConviteEscritorioUsaTemplateCorreto(): void
    {
        $this->configurarUrlGenerator();

        $templateUsado = null;
        $this->twig->expects($this->once())->method('render')
            ->willReturnCallback(static function (string $t, array $v) use (&$templateUsado): string {
                $templateUsado = $t;

                return '<html>test</html>';
            });

        $this->mailer->method('send');
        $this->service->enviarConviteEscritorio($this->criarConviteEscritorio());

        self::assertSame('email/convite_escritorio.html.twig', $templateUsado);
    }

    public function testTransportExceptionRelancadaComoRuntimeException(): void
    {
        $this->configurarUrlGenerator();
        $this->twig->method('render')->willReturn('<html>test</html>');

        $transportException = $this->createMock(TransportExceptionInterface::class);
        $this->mailer->method('send')->willThrowException($transportException);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Falha ao enviar convite de plataforma:');

        $this->service->enviarConvitePlataforma($this->criarConvitePlataforma());
    }
}
