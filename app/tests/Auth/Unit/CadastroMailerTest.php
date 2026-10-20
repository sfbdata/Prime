<?php

declare(strict_types=1);

namespace App\Tests\Auth\Unit;

use App\Auth\Entity\CadastroPendente;
use App\Auth\Service\CadastroMailer;
use App\Entity\Auth\User;
use App\Shared\Email\IdentidadeDeEmail;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Email;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Twig\Environment;

#[CoversClass(CadastroMailer::class)]
final class CadastroMailerTest extends TestCase
{
    #[TestDox('Confirmação usa assunto e identidade institucionais sem alterar destinatário')]
    public function testUsaIdentidadeInstitucional(): void
    {
        $mailer = $this->createMock(MailerInterface::class);
        $twig = $this->createMock(Environment::class);
        $twig->method('render')->willReturn('<html>confirmação</html>');
        $urlGenerator = $this->createMock(UrlGeneratorInterface::class);
        $urlGenerator->method('generate')->willReturn('https://bluejus.test/cadastro/token');
        $emailCapturado = null;
        $mailer->expects($this->once())->method('send')
            ->willReturnCallback(static function (Email $email) use (&$emailCapturado): void {
                $emailCapturado = $email;
            });
        $cadastro = new CadastroPendente(
            email: 'cadastro@bluejus.test',
            token: str_repeat('a', 64),
            nomeCompleto: 'Pessoa Cadastro',
            nomeEscritorio: 'Escritório Teste',
            oabNumero: '12345',
            oabUf: 'SP',
            senhaHash: 'hash',
            ip: '127.0.0.1',
            expiresAt: new \DateTimeImmutable('+1 hour'),
        );
        $service = new CadastroMailer(
            $mailer,
            $twig,
            $urlGenerator,
            new IdentidadeDeEmail('BlueJus <nao-responda@bluejus.test>', 'infra@bluejus.test'),
        );

        $service->enviarConfirmacao($cadastro);

        self::assertInstanceOf(Email::class, $emailCapturado);
        self::assertSame('[BlueJus] Confirme seu cadastro no BlueJus', $emailCapturado->getSubject());
        self::assertSame('nao-responda@bluejus.test', $emailCapturado->getFrom()[0]->getAddress());
        self::assertSame('BlueJus', $emailCapturado->getFrom()[0]->getName());
        self::assertSame('infra@bluejus.test', $emailCapturado->getReplyTo()[0]->getAddress());
        self::assertSame('cadastro@bluejus.test', $emailCapturado->getTo()[0]->getAddress());
    }

    #[TestDox('Confirmação de e-mail da conta leva o token em claro no link absoluto de auth_cadastro_confirmar_email')]
    public function testConfirmacaoDeEmailDaConta(): void
    {
        $mailer = $this->createMock(MailerInterface::class);
        $twig = $this->createMock(Environment::class);
        $twig->expects($this->once())->method('render')
            ->with('auth/cadastro/email_confirmacao.html.twig', ['nome' => 'Ana Souza', 'link' => 'https://bluejus.test/cadastro/confirmar-email/tok'])
            ->willReturn('<html>confirme</html>');
        $urlGenerator = $this->createMock(UrlGeneratorInterface::class);
        $urlGenerator->expects($this->once())->method('generate')
            ->with('auth_cadastro_confirmar_email', ['token' => 'tok'], UrlGeneratorInterface::ABSOLUTE_URL)
            ->willReturn('https://bluejus.test/cadastro/confirmar-email/tok');
        $emailCapturado = null;
        $mailer->expects($this->once())->method('send')
            ->willReturnCallback(static function (Email $email) use (&$emailCapturado): void {
                $emailCapturado = $email;
            });
        $user = (new User())->setEmail('ana@bluejus.test')->setFullName('Ana Souza');
        $service = new CadastroMailer(
            $mailer,
            $twig,
            $urlGenerator,
            new IdentidadeDeEmail('BlueJus <nao-responda@bluejus.test>', 'infra@bluejus.test'),
        );

        $service->enviarConfirmacaoDeEmail($user, 'tok');

        self::assertInstanceOf(Email::class, $emailCapturado);
        self::assertSame('[BlueJus] Confirme seu e-mail no BlueJus', $emailCapturado->getSubject());
        self::assertSame('ana@bluejus.test', $emailCapturado->getTo()[0]->getAddress());
    }
}
