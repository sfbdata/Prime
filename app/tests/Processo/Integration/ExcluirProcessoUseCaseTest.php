<?php

declare(strict_types=1);

namespace App\Tests\Processo\Integration;

use App\Entity\Tenant\Tenant;
use App\Pasta\Entity\Pasta;
use App\Processo\Entity\NotaTecnica;
use App\Processo\Entity\Processo;
use App\Processo\Exception\ProcessoForaDoEscopoException;
use App\Processo\UseCase\ExcluirProcessoUseCase;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

#[CoversClass(ExcluirProcessoUseCase::class)]
final class ExcluirProcessoUseCaseTest extends KernelTestCase
{
    #[TestDox('Apaga o processo do escritório e o que depende dele; a pasta continua e perde só o vínculo')]
    public function testApagaOProcessoESeusDependentes(): void
    {
        self::bootKernel();
        $em = $this->em();
        $tenant = $this->tenant();
        $pasta = (new Pasta())->setNup('EXC-' . uniqid());
        $pasta->setTenant($tenant);
        $processo = $this->processo($tenant);
        $em->persist($pasta);
        $em->persist($pasta->vincularProcesso($processo));
        $em->persist(new NotaTecnica($tenant, $processo, $this->usuario($tenant), 'Nota'));
        $em->flush();
        [$processoId, $pastaId] = [(int) $processo->getId(), (int) $pasta->getId()];

        static::getContainer()->get(ExcluirProcessoUseCase::class)->executar($processo, $tenant);
        $em->clear();

        self::assertNull($em->find(Processo::class, $processoId));
        self::assertSame(0, (int) $em->getConnection()->fetchOne('SELECT COUNT(*) FROM nota_tecnica WHERE processo_id = ?', [$processoId]));
        self::assertSame(0, (int) $em->getConnection()->fetchOne('SELECT COUNT(*) FROM pasta_processo WHERE processo_id = ?', [$processoId]));
        self::assertNotNull($em->find(Pasta::class, $pastaId));
    }

    #[TestDox('Processo de outro escritório: recusa e nada é apagado')]
    public function testRecusaProcessoDeOutroEscritorio(): void
    {
        self::bootKernel();
        $meu = $this->tenant();
        $alheio = $this->processo($this->tenant());
        $this->em()->flush();
        $id = (int) $alheio->getId();

        try {
            static::getContainer()->get(ExcluirProcessoUseCase::class)->executar($alheio, $meu);
            self::fail('deveria recusar');
        } catch (ProcessoForaDoEscopoException) {
        }

        $this->em()->clear();
        self::assertNotNull($this->em()->find(Processo::class, $id));
    }

    private function em(): EntityManagerInterface
    {
        return static::getContainer()->get(EntityManagerInterface::class);
    }

    private function tenant(): Tenant
    {
        $tenant = (new Tenant())->setName('Escritório ' . uniqid())->setIsActive(true);
        $this->em()->persist($tenant);
        $this->em()->flush();

        return $tenant;
    }

    private function usuario(Tenant $tenant): \App\Entity\Auth\User
    {
        $usuario = (new \App\Entity\Auth\User())->setEmail('exc_' . uniqid() . '@teste.com')->setFullName('Autor')->setRoles(['ROLE_USER'])->setIsActive(true);
        $usuario->setPassword('sem-uso');
        $this->em()->persist($usuario);

        return $usuario;
    }

    private function processo(Tenant $tenant): Processo
    {
        $processo = new Processo();
        $processo->setNumeroProcesso(str_pad((string) random_int(1, 99_999_999), 8, '0', \STR_PAD_LEFT) . '2025807' . str_pad((string) random_int(1, 99_999), 5, '0', \STR_PAD_LEFT));
        $processo->setSiglaTribunal('TJDFT');
        $processo->setClasseProcessual('Procedimento Comum');
        $processo->setAssuntoProcessual('Assunto');
        $processo->setTenant($tenant);
        $this->em()->persist($processo);
        $this->em()->flush();

        return $processo;
    }
}
