<?php

declare(strict_types=1);

namespace App\Tests\Auth\Integration;

use App\Auth\DTO\IdentidadeDoGoogle;
use App\Auth\Event\EmailDaContaConfirmado;
use App\Auth\UseCase\LigarContaAoGoogleUseCase;
use App\Entity\Auth\User;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

#[CoversClass(LigarContaAoGoogleUseCase::class)]
final class LigarContaAoGoogleTest extends KernelTestCase
{
    #[TestDox('Liga o sub e confirma o e-mail "a confirmar"; o evento sai uma vez só; ligar de novo com o mesmo sub não muda nada')]
    public function testLigaEConfirmaUmaVez(): void
    {
        $conta = $this->conta();
        $conta->marcarEmailAConfirmar(new \DateTimeImmutable());
        $this->em()->flush();
        $eventos = 0;
        static::getContainer()->get('event_dispatcher')->addListener(EmailDaContaConfirmado::class, static function () use (&$eventos): void {
            ++$eventos;
        });
        $sut = static::getContainer()->get(LigarContaAoGoogleUseCase::class);

        $sut->executar($conta, new IdentidadeDoGoogle('sub-1', (string) $conta->getEmail(), 'Pessoa'));
        $sut->executar($conta, new IdentidadeDoGoogle('sub-1', (string) $conta->getEmail(), 'Pessoa'));

        self::assertSame(1, $eventos);
        $linha = $this->em()->getConnection()->fetchAssociative('SELECT google_sub, email_a_confirmar_desde FROM "user" WHERE id = :id', ['id' => $conta->getId()]);
        self::assertSame(['google_sub' => 'sub-1', 'email_a_confirmar_desde' => null], $linha);
    }

    #[TestDox('Conta já ligada a outro sub: recusa sem trocar')]
    public function testOutroSub(): void
    {
        $conta = $this->conta();
        $sut = static::getContainer()->get(LigarContaAoGoogleUseCase::class);
        $sut->executar($conta, new IdentidadeDoGoogle('sub-1', (string) $conta->getEmail(), 'Pessoa'));

        $this->expectException(\DomainException::class);
        $sut->executar($conta, new IdentidadeDoGoogle('sub-2', (string) $conta->getEmail(), 'Pessoa'));
    }

    private function conta(): User
    {
        $u = (new User())->setEmail('ligar_' . uniqid() . '@gmail.com')->setFullName('Pessoa')->setRoles(['ROLE_USER'])->setIsActive(true);
        $this->em()->persist($u);
        $this->em()->flush();

        return $u;
    }

    private function em(): EntityManagerInterface
    {
        return static::getContainer()->get(EntityManagerInterface::class);
    }
}
