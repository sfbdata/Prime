<?php

declare(strict_types=1);

namespace App\Tests\Command\Unit;

use App\Command\TestMailerCommand;
use App\Shared\Email\IdentidadeDeEmail;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Mailer\Envelope;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Email;
use Symfony\Component\Mime\RawMessage;

#[CoversClass(TestMailerCommand::class)]
final class TestMailerCommandTest extends TestCase
{
    #[TestDox('Usa remetente configurado e destinatário informado explicitamente')]
    public function testUsaRemetenteConfiguradoEDestinatarioInformado(): void
    {
        $mailer = new MailerCapturador();
        $tester = new CommandTester(new TestMailerCommand(
            $mailer,
            new IdentidadeDeEmail(
                'BlueJus <remetente-configurado@bluejus.test>',
                'infra@bluejus.test',
            ),
        ));

        $codigo = $tester->execute(['destinatario' => 'destino@bluejus.test']);

        self::assertSame(0, $codigo);
        self::assertStringContainsString('Email de teste enviado com sucesso!', $tester->getDisplay());

        $email = $mailer->ultimaMensagem();
        self::assertSame('remetente-configurado@bluejus.test', $email->getFrom()[0]->getAddress());
        self::assertSame('BlueJus', $email->getFrom()[0]->getName());
        self::assertSame('destino@bluejus.test', $email->getTo()[0]->getAddress());
        self::assertSame('infra@bluejus.test', $email->getReplyTo()[0]->getAddress());
        self::assertSame('[BlueJus] Teste de Mailer Symfony', $email->getSubject());
    }

    #[TestDox('Exige destinatário e não tenta enviar quando ele não é informado')]
    public function testExigeDestinatario(): void
    {
        $mailer = new MailerCapturador();
        $tester = new CommandTester(new TestMailerCommand(
            $mailer,
            new IdentidadeDeEmail('BlueJus <remetente-configurado@bluejus.test>', ''),
        ));

        $this->expectException(\RuntimeException::class);

        try {
            $tester->execute([]);
        } finally {
            self::assertSame(0, $mailer->quantidadeDeMensagens());
        }
    }
}

final class MailerCapturador implements MailerInterface
{
    /** @var list<Email> */
    private array $mensagens = [];

    public function send(RawMessage $message, ?Envelope $envelope = null): void
    {
        if (!$message instanceof Email) {
            throw new \LogicException('O teste esperava uma mensagem de e-mail.');
        }

        $this->mensagens[] = $message;
    }

    public function ultimaMensagem(): Email
    {
        return $this->mensagens[array_key_last($this->mensagens)];
    }

    public function quantidadeDeMensagens(): int
    {
        return count($this->mensagens);
    }
}
