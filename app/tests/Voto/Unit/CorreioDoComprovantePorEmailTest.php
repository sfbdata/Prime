<?php

declare(strict_types=1);

namespace App\Tests\Voto\Unit;

use App\Shared\Email\IdentidadeDeEmail;
use App\Shared\Service\GeradorDePdfComDompdf;
use App\Voto\DTO\ComprovanteDeParticipacao;
use App\Voto\Service\CorreioDoComprovantePorEmail;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Email;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;

#[CoversClass(CorreioDoComprovantePorEmail::class)]
#[Group('voto')]
final class CorreioDoComprovantePorEmailTest extends TestCase
{
    public function testEnviaPdfRealComDadosEscapadosESemLinkPessoal(): void
    {
        $comprovante = new ComprovanteDeParticipacao('AGO <img src="https://example.invalid/externa">', 'Contas & despesas', 'A 101', new \DateTimeImmutable('2026-11-13 12:00:00'), 'BJV-1234-5678-90AB');
        $mailer = $this->createMock(MailerInterface::class);
        $mailer->expects(self::once())->method('send')->with(self::callback(function (Email $email): bool {
            self::assertSame('editado@example.invalid', $email->getTo()[0]->getAddress());
            self::assertSame('[BlueJus] Comprovante de participação', $email->getSubject());
            self::assertStringContainsString('&lt;img', (string) $email->getHtmlBody());
            self::assertStringContainsString('BJV-1234-5678-90AB', (string) $email->getTextBody());
            self::assertStringNotContainsString('/votar/', (string) $email->getHtmlBody());
            self::assertCount(1, $email->getAttachments());
            $anexo = $email->getAttachments()[0];
            self::assertSame('BJV-1234-5678-90AB.pdf', $anexo->getFilename());
            self::assertSame('application', $anexo->getMediaType());
            self::assertSame('pdf', $anexo->getMediaSubtype());
            self::assertStringStartsWith('%PDF-', $anexo->getBody());

            return true;
        }));
        $app = \dirname(__DIR__, 3);
        $correio = new CorreioDoComprovantePorEmail($mailer, new Environment(new FilesystemLoader($app . '/templates'), ['autoescape' => 'html']), new IdentidadeDeEmail('BlueJus <teste@example.invalid>', ''), new GeradorDePdfComDompdf($app));
        $correio->enviar('editado@example.invalid', $comprovante);
    }
}
