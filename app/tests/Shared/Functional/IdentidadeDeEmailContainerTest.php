<?php

declare(strict_types=1);

namespace App\Tests\Shared\Functional;

use App\Shared\Email\IdentidadeDeEmail;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Mime\Email;

final class IdentidadeDeEmailContainerTest extends KernelTestCase
{
    #[TestDox('MAILER_REPLY_TO ausente usa fallback vazio e omite o cabeçalho')]
    public function testReplyToAusenteEhOmitido(): void
    {
        self::bootKernel();

        $identidade = static::getContainer()->get(IdentidadeDeEmail::class);
        $email = new Email();
        $identidade->aplicar($email, 'Mensagem');

        self::assertSame([], $email->getReplyTo());
    }
}
