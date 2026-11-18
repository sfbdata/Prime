<?php

declare(strict_types=1);

namespace App\Tests\Indicacao\Functional;

use App\Entity\Auth\User;
use App\Indicacao\Entity\Indicacao;
use App\Indicacao\Enum\CanalDeIndicacao;
use App\Indicacao\Service\ColegasCadastradosPorIndicacao;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/** Quantos colegas liberam I.A. a quem indicou: só indicação do cadastro, com a conta nova de e-mail confirmado. */
#[CoversClass(ColegasCadastradosPorIndicacao::class)]
final class ColegasCadastradosPorIndicacaoTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private ColegasCadastradosPorIndicacao $sut;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        $this->sut = static::getContainer()->get(ColegasCadastradosPorIndicacao::class);
    }

    private function usuario(string $nome, bool $confirmado = true): User
    {
        $user = new User();
        $user->setEmail(uniqid('col_') . '@exemplo.com');
        $user->setFullName($nome);
        $user->setPassword('x');
        $user->setCreatedAt(new \DateTimeImmutable('2026-01-01'));
        if (!$confirmado) {
            $user->marcarEmailAConfirmar(new \DateTimeImmutable('2026-01-01'));
        }
        $this->em->persist($user);

        return $user;
    }

    #[TestDox('conta só o cadastro com e-mail confirmado, do indicador certo; convite por canal e e-mail pendente ficam de fora')]
    public function testContaSoCadastroConfirmado(): void
    {
        $ana = $this->usuario('Ana');
        $bia = $this->usuario('Bia');
        $confirmado1 = $this->usuario('Novo 1');
        $confirmado2 = $this->usuario('Novo 2');
        $pendente = $this->usuario('Novo 3', false);
        $daBia = $this->usuario('Novo 4');
        $this->em->persist(Indicacao::porCadastroDoLink($ana, null, $confirmado1, new \DateTimeImmutable('2026-02-01')));
        $this->em->persist(Indicacao::porCadastroDoLink($ana, null, $confirmado2, new \DateTimeImmutable('2026-02-02')));
        $this->em->persist(Indicacao::porCadastroDoLink($ana, null, $pendente, new \DateTimeImmutable('2026-02-03')));
        $this->em->persist(Indicacao::porCadastroDoLink($bia, null, $daBia, new \DateTimeImmutable('2026-02-04')));
        $this->em->persist(Indicacao::porCanal($ana, null, CanalDeIndicacao::Link, new \DateTimeImmutable('2026-02-05')));
        $this->em->flush();

        self::assertSame(2, $this->sut->contar((int) $ana->getId()));
        self::assertSame(1, $this->sut->contar((int) $bia->getId()));

        $pendente->confirmarEmail();
        $this->em->flush();

        self::assertSame(3, $this->sut->contar((int) $ana->getId()), 'ao confirmar o e-mail, a indicação passa a contar');
    }

    #[TestDox('quem não indicou ninguém tem zero')]
    public function testSemIndicacoes(): void
    {
        $sozinha = $this->usuario('Sozinha');
        $this->em->flush();

        self::assertSame(0, $this->sut->contar((int) $sozinha->getId()));
    }
}
