<?php

declare(strict_types=1);

namespace App\Tests\Auth\Unit;

use App\Auth\Entity\CadastroPendente;
use App\Auth\Event\ConfirmacaoDeCadastroEnviada;
use App\Auth\Service\CadastroMailer;
use App\Shared\Email\IdentidadeDeEmail;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Email;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Twig\Environment;
use Symfony\Component\EventDispatcher\EventDispatcher;

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
            $eventos = new EventDispatcher(),
        );
        $capturados = [];
        $eventos->addListener(ConfirmacaoDeCadastroEnviada::class, static function (ConfirmacaoDeCadastroEnviada $e) use (&$capturados, &$emailCapturado): void {
            $capturados[] = [$e->email, $e->assunto, $emailCapturado !== null];
        });

        $service->enviarConfirmacao($cadastro);

        self::assertSame([['cadastro@bluejus.test', '[BlueJus] Confirme seu cadastro no BlueJus', true]], $capturados, 'o evento do Comercial sai uma vez, depois do send');

        self::assertInstanceOf(Email::class, $emailCapturado);
        self::assertSame('[BlueJus] Confirme seu cadastro no BlueJus', $emailCapturado->getSubject());
        self::assertSame('nao-responda@bluejus.test', $emailCapturado->getFrom()[0]->getAddress());
        self::assertSame('BlueJus', $emailCapturado->getFrom()[0]->getName());
        self::assertSame('infra@bluejus.test', $emailCapturado->getReplyTo()[0]->getAddress());
        self::assertSame('cadastro@bluejus.test', $emailCapturado->getTo()[0]->getAddress());
    }

    #[TestDox('Falha no envio: nenhum evento para o Comercial (o e-mail não saiu)')]
    public function testFalhaNoEnvioNaoDespachaEvento(): void
    {
        $mailer = $this->createMock(MailerInterface::class);
        $mailer->method('send')->willThrowException(new \Symfony\Component\Mailer\Exception\TransportException('fora do ar'));
        $twig = $this->createMock(Environment::class);
        $twig->method('render')->willReturn('<html>confirmação</html>');
        $urlGenerator = $this->createMock(UrlGeneratorInterface::class);
        $urlGenerator->method('generate')->willReturn('https://bluejus.test/cadastro/token');
        $eventos = new EventDispatcher();
        $capturados = 0;
        $eventos->addListener(ConfirmacaoDeCadastroEnviada::class, static function () use (&$capturados): void {
            ++$capturados;
        });
        $service = new CadastroMailer($mailer, $twig, $urlGenerator, new IdentidadeDeEmail('BlueJus <nao-responda@bluejus.test>', 'infra@bluejus.test'), $eventos);

        try {
            $service->enviarConfirmacao(new CadastroPendente('c@bluejus.test', str_repeat('b', 64), 'P', 'E', '1', 'SP', 'h', '127.0.0.1', new \DateTimeImmutable('+1 hour')));
            self::fail('A falha do transporte deveria virar RuntimeException.');
        } catch (\RuntimeException) {
        }
        self::assertSame(0, $capturados);
    }
}
