<?php

declare(strict_types=1);

namespace App\Tests\Pasta\Functional;

use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Pasta\Entity\Pasta;
use App\Pasta\UseCase\DesvincularProcessoUseCase;
use App\Processo\Entity\Processo;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/** O cartão "processo N desvinculado da pasta." só sai quando o processo estava vinculado e há autor. */
#[CoversClass(DesvincularProcessoUseCase::class)]
final class DesvincularProcessoRegistroTest extends KernelTestCase
{
    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
    }

    #[TestDox('desvincular processo que não estava vinculado: nada muda e não há registro')]
    public function testNaoVinculadoNaoRegistra(): void
    {
        [$pasta, $processo, $usuario] = $this->cenario();

        static::getContainer()->get(DesvincularProcessoUseCase::class)->executar($pasta, $processo, $usuario);

        self::assertSame(0, $this->registros($pasta));
    }

    #[TestDox('desvincular sem usuário (rotina do sistema): desvincula, mas sem registro em nome de ninguém')]
    public function testSemUsuarioDesvinculaSemRegistro(): void
    {
        [$pasta, $processo, $usuario] = $this->cenario();
        $pasta->vincularProcesso($processo, $usuario);
        $this->em->flush();

        static::getContainer()->get(DesvincularProcessoUseCase::class)->executar($pasta, $processo, null);

        self::assertFalse($pasta->temProcesso($processo));
        self::assertSame(0, $this->registros($pasta));
    }

    /** @return array{Pasta, Processo, User} */
    private function cenario(): array
    {
        $tenant = (new Tenant())->setName('Escritório desvincular ' . uniqid());
        $usuario = (new User())->setEmail('desv_' . uniqid() . '@test.com')->setFullName('Quem desvincula')->setRoles(['ROLE_USER'])->setIsActive(true);
        $usuario->setPassword('x');
        $pasta = new Pasta();
        $pasta->setNup('TEST-DESV-' . uniqid());
        $pasta->setTenant($tenant);
        $processo = new Processo();
        $processo->setTenant($tenant);
        $processo->setNumeroProcesso('22222222222222222222');
        $processo->setClasseProcessual('PROCEDIMENTO');
        foreach ([$tenant, $usuario, $pasta, $processo] as $entidade) {
            $this->em->persist($entidade);
        }
        $this->em->flush();

        return [$pasta, $processo, $usuario];
    }

    private function registros(Pasta $pasta): int
    {
        return (int) $this->em->getConnection()->fetchOne(
            "SELECT count(*) FROM pasta_mensagem WHERE pasta_id = :p AND sistema = 'proc'",
            ['p' => $pasta->getId()],
        );
    }
}
