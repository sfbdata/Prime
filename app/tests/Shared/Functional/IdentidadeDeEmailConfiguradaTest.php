<?php

declare(strict_types=1);

namespace App\Tests\Shared\Functional;

use App\Auth\Service\RedefinicaoSenhaMailer;
use App\Entity\Auth\User;
use App\Master\Entity\ConfiguracaoGlobal;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use Doctrine\ORM\EntityManagerInterface;
use App\Shared\Email\IdentidadeDeEmail;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Mime\Email;

/** Um mailer real do container, com a configuração do Master no banco: o e-mail que sai leva o nome e o remetente dela. */
#[CoversClass(IdentidadeDeEmail::class)]
final class IdentidadeDeEmailConfiguradaTest extends KernelTestCase
{
    private function enviar(): Email
    {
        $user = (new User())->setEmail('usuario@bluejus.test')->setFullName('Pessoa Usuária');
        static::getContainer()->get(RedefinicaoSenhaMailer::class)->enviarLink($user, str_repeat('b', 64));
        $email = self::getMailerMessage();
        self::assertInstanceOf(Email::class, $email);

        return $email;
    }

    private function gravar(array $valores): void
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $linha = new ConfiguracaoGlobal();
        $linha->definirValores($valores, null, new \DateTimeImmutable());
        $em->persist($linha);
        $em->flush();
    }

    #[TestDox('Sem configuração gravada o e-mail de redefinição sai como hoje: "BlueJus <nao-responda@bluejus.com.br>" e "[BlueJus] ..."')]
    public function testSemConfiguracaoSaiComoHoje(): void
    {
        self::bootKernel();

        $email = $this->enviar();

        self::assertSame('"BlueJus" <nao-responda@bluejus.com.br>', $email->getFrom()[0]->toString());
        self::assertStringStartsWith('[BlueJus] ', (string) $email->getSubject());
    }

    #[TestDox('Com nome e remetente gravados pelo Master, o e-mail de redefinição sai com eles')]
    public function testComConfiguracaoGravada(): void
    {
        self::bootKernel();
        $this->gravar(['nome' => 'Acme Jurídico', 'remetente' => 'avisos@acme.test']);

        $email = $this->enviar();

        self::assertSame('"Acme Jurídico" <avisos@acme.test>', $email->getFrom()[0]->toString());
        self::assertStringStartsWith('[Acme Jurídico] ', (string) $email->getSubject());
    }
}
