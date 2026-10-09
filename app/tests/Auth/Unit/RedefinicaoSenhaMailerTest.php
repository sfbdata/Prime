<?php

declare(strict_types=1);

namespace App\Tests\Auth\Unit;

use App\Auth\Service\RedefinicaoSenhaMailer;
use App\Entity\Auth\User;
use App\Shared\Email\IdentidadeDeEmail;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Email;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Twig\Environment;

#[CoversClass(RedefinicaoSenhaMailer::class)]
final class RedefinicaoSenhaMailerTest extends TestCase
{
    #[TestDox('Redefinição usa assunto e identidade institucionais sem alterar destinatário')]
    public function testUsaIdentidadeInstitucional(): void
    {
        $mailer = $this->createMock(MailerInterface::class);
        $twig = $this->createMock(Environment::class);
        $twig->method('render')->willReturn('<html>redefinição</html>');
        $urlGenerator = $this->createMock(UrlGeneratorInterface::class);
        $urlGenerator->method('generate')->willReturn('https://bluejus.test/senha/token');
        $emailCapturado = null;
        $mailer->expects($this->once())->method('send')
            ->willReturnCallback(static function (Email $email) use (&$emailCapturado): void {
                $emailCapturado = $email;
            });
        $user = (new User())
            ->setEmail('usuario@bluejus.test')
            ->setFullName('Pessoa Usuária');
        $service = new RedefinicaoSenhaMailer(
            $mailer,
            $twig,
            $urlGenerator,
            new IdentidadeDeEmail('BlueJus <nao-responda@bluejus.test>', 'infra@bluejus.test'),
        );

        $service->enviarLink($user, str_repeat('b', 64));

        self::assertInstanceOf(Email::class, $emailCapturado);
        self::assertSame('[BlueJus] Redefinição de senha no BlueJus', $emailCapturado->getSubject());
        self::assertSame('nao-responda@bluejus.test', $emailCapturado->getFrom()[0]->getAddress());
        self::assertSame('BlueJus', $emailCapturado->getFrom()[0]->getName());
        self::assertSame('infra@bluejus.test', $emailCapturado->getReplyTo()[0]->getAddress());
        self::assertSame('usuario@bluejus.test', $emailCapturado->getTo()[0]->getAddress());
    }
}
