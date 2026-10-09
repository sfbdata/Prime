<?php

declare(strict_types=1);

namespace App\Tests\Shared\Unit;

use App\Shared\Email\IdentidadeDeEmail;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Mime\Email;

#[CoversClass(IdentidadeDeEmail::class)]
final class IdentidadeDeEmailTest extends TestCase
{
    #[TestDox('Aplica From, Reply-To e prefixo institucional')]
    public function testAplicaIdentidadeCompleta(): void
    {
        $email = new Email();
        $identidade = new IdentidadeDeEmail(
            'BlueJus <nao-responda@bluejus.test>',
            'infra@bluejus.test',
        );

        $identidade->aplicar($email, 'Mensagem automática');

        self::assertSame('nao-responda@bluejus.test', $email->getFrom()[0]->getAddress());
        self::assertSame('BlueJus', $email->getFrom()[0]->getName());
        self::assertSame('infra@bluejus.test', $email->getReplyTo()[0]->getAddress());
        self::assertSame('[BlueJus] Mensagem automática', $email->getSubject());
    }

    #[TestDox('Reply-To vazio é omitido e prefixo existente não é duplicado')]
    public function testConfiguracaoOpcionalSegura(): void
    {
        $email = new Email();
        $identidade = new IdentidadeDeEmail('BlueJus <nao-responda@bluejus.test>', '  ');

        $identidade->aplicar($email, ' [BlueJus] Mensagem automática ');

        self::assertSame([], $email->getReplyTo());
        self::assertSame('[BlueJus] Mensagem automática', $email->getSubject());
    }

    #[TestDox('MAILER_FROM inválido falha sem expor o valor configurado')]
    public function testFromInvalidoFalhaDeFormaControlada(): void
    {
        $valorInvalido = 'segredo-invalido-sem-arroba';

        try {
            new IdentidadeDeEmail($valorInvalido, 'infra@bluejus.test');
            self::fail('A configuração inválida deveria falhar.');
        } catch (\InvalidArgumentException $e) {
            self::assertSame('Configuração de e-mail inválida em MAILER_FROM.', $e->getMessage());
            self::assertStringNotContainsString($valorInvalido, $e->getMessage());
            self::assertNull($e->getPrevious());
        }
    }

    #[TestDox('MAILER_REPLY_TO inválido falha sem expor o valor configurado')]
    public function testReplyToInvalidoFalhaDeFormaControlada(): void
    {
        $valorInvalido = 'outro-segredo-invalido';

        try {
            new IdentidadeDeEmail('BlueJus <nao-responda@bluejus.test>', $valorInvalido);
            self::fail('A configuração inválida deveria falhar.');
        } catch (\InvalidArgumentException $e) {
            self::assertSame('Configuração de e-mail inválida em MAILER_REPLY_TO.', $e->getMessage());
            self::assertStringNotContainsString($valorInvalido, $e->getMessage());
            self::assertNull($e->getPrevious());
        }
    }

    #[TestDox('Preserva nome de exibição personalizado configurado em MAILER_FROM')]
    public function testPreservaNomeDeExibicaoPersonalizado(): void
    {
        $email = new Email();
        $identidade = new IdentidadeDeEmail('Equipe BlueJus <equipe@bluejus.test>', '');

        $identidade->aplicar($email, 'Mensagem');

        self::assertSame('Equipe BlueJus', $email->getFrom()[0]->getName());
        self::assertSame('equipe@bluejus.test', $email->getFrom()[0]->getAddress());
    }
}
