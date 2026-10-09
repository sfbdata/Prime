<?php

declare(strict_types=1);

namespace App\Tests\Service\Functional;

use App\Entity\Auth\User;
use App\Service\InvitationService;
use App\Shared\Email\IdentidadeDeEmail;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Mailer\Envelope;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Email;
use Symfony\Component\Mime\RawMessage;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

#[CoversClass(InvitationService::class)]
final class InvitationServiceTest extends KernelTestCase
{
    #[TestDox('O convite legado usa o remetente configurado e preserva destinatário e conteúdo')]
    public function testUsaRemetenteConfigurado(): void
    {
        self::bootKernel();

        $mailer = new MailerCapturador();
        $service = new InvitationService(
            static::getContainer()->get(EntityManagerInterface::class),
            $mailer,
            static::getContainer()->get(UrlGeneratorInterface::class),
            new IdentidadeDeEmail(
                'BlueJus <remetente-configurado@bluejus.test>',
                'infra@bluejus.test',
            ),
        );
        $user = (new User())
            ->setEmail('convidado@bluejus.test')
            ->setFullName('Pessoa Convidada');

        $resultado = $service->sendInvitation($user, 'Assunto preservado');

        self::assertTrue($resultado['sent']);
        self::assertFalse($resultado['duplicateEmail']);
        self::assertNull($resultado['error']);
        self::assertNotNull($resultado['link']);
        self::assertFalse($user->isActive());
        self::assertNotNull($user->getInvitationToken());

        $email = $mailer->ultimaMensagem();
        self::assertSame('remetente-configurado@bluejus.test', $email->getFrom()[0]->getAddress());
        self::assertSame('BlueJus', $email->getFrom()[0]->getName());
        self::assertSame('convidado@bluejus.test', $email->getTo()[0]->getAddress());
        self::assertSame('infra@bluejus.test', $email->getReplyTo()[0]->getAddress());
        self::assertSame('[BlueJus] Assunto preservado', $email->getSubject());
        self::assertStringContainsString('Olá Pessoa Convidada', (string) $email->getTextBody());
        self::assertStringContainsString((string) $resultado['link'], (string) $email->getTextBody());
    }

    #[TestDox('Não duplica o prefixo BlueJus em assunto personalizado')]
    public function testNaoDuplicaPrefixoDoAssunto(): void
    {
        self::bootKernel();

        $mailer = new MailerCapturador();
        $service = new InvitationService(
            static::getContainer()->get(EntityManagerInterface::class),
            $mailer,
            static::getContainer()->get(UrlGeneratorInterface::class),
            new IdentidadeDeEmail('BlueJus <remetente@bluejus.test>', ''),
        );
        $user = (new User())
            ->setEmail('prefixo_' . uniqid() . '@bluejus.test')
            ->setFullName('Pessoa Convidada');

        $service->sendInvitation($user, '[BlueJus] Assunto pronto');

        $email = $mailer->ultimaMensagem();
        self::assertSame('[BlueJus] Assunto pronto', $email->getSubject());
        self::assertSame([], $email->getReplyTo());
    }

    #[TestDox('Configuração inválida impede persistência antes de iniciar o convite')]
    public function testConfiguracaoInvalidaNaoPersisteUsuario(): void
    {
        self::bootKernel();

        $em = static::getContainer()->get(EntityManagerInterface::class);
        $quantidadeAntes = $em->getRepository(User::class)->count([]);
        $user = (new User())
            ->setEmail('nao_persistir_' . uniqid() . '@bluejus.test')
            ->setFullName('Não Persistir');

        try {
            $identidade = new IdentidadeDeEmail('valor-invalido', 'infra@bluejus.test');
            $service = new InvitationService(
                $em,
                new MailerCapturador(),
                static::getContainer()->get(UrlGeneratorInterface::class),
                $identidade,
            );
            $service->sendInvitation($user);
            self::fail('A configuração inválida deveria impedir a criação do serviço.');
        } catch (\InvalidArgumentException $e) {
            self::assertSame('Configuração de e-mail inválida em MAILER_FROM.', $e->getMessage());
        }

        self::assertSame($quantidadeAntes, $em->getRepository(User::class)->count([]));
        self::assertTrue($user->isActive());
        self::assertNull($user->getInvitationToken());
        self::assertFalse($em->contains($user));
    }
}

final class MailerCapturador implements MailerInterface
{
    private ?Email $mensagem = null;

    public function send(RawMessage $message, ?Envelope $envelope = null): void
    {
        self::assertInstanceOf(Email::class, $message);
        $this->mensagem = $message;
    }

    public function ultimaMensagem(): Email
    {
        self::assertNotNull($this->mensagem);

        return $this->mensagem;
    }

    private static function assertInstanceOf(string $classe, object $objeto): void
    {
        if (!$objeto instanceof $classe) {
            throw new \LogicException('O teste esperava uma mensagem de e-mail.');
        }
    }

    private static function assertNotNull(?object $objeto): void
    {
        if ($objeto === null) {
            throw new \LogicException('Nenhuma mensagem foi capturada.');
        }
    }
}
