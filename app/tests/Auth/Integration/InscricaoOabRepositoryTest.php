<?php

declare(strict_types=1);

namespace App\Tests\Auth\Integration;

use App\Auth\Entity\InscricaoOab;
use App\Auth\Enum\StatusOab;
use App\Auth\Enum\TipoInscricaoOab;
use App\Auth\Repository\InscricaoOabRepository;
use App\Entity\Auth\User;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/** As consultas do leitor público das inscrições e as travas do banco (spec meu-perfil-oab-inscricoes §2 e §3). */
#[CoversClass(InscricaoOabRepository::class)]
final class InscricaoOabRepositoryTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private InscricaoOabRepository $repo;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em   = static::getContainer()->get(EntityManagerInterface::class);
        $this->repo = static::getContainer()->get(InscricaoOabRepository::class);
    }

    #[TestDox('doUsuario: principal primeiro, depois por UF; paraRevisao filtra por situação e busca por número')]
    public function testConsultas(): void
    {
        $user = $this->usuario();
        $this->inscrever($user, '700', 'SP', TipoInscricaoOab::Suplementar);
        $this->inscrever($user, '49613', 'DF', TipoInscricaoOab::Principal, StatusOab::Confirmada);
        $this->inscrever($user, '800', 'GO', TipoInscricaoOab::Suplementar);
        $this->em->flush();

        self::assertSame(['DF', 'GO', 'SP'], array_map(static fn (InscricaoOab $i): string => $i->getUf(), $this->repo->doUsuario($user)));
        $pendentes = $this->repo->paraRevisao([StatusOab::NaoVerificada], (string) $user->getEmail());
        $ufs = array_map(static fn (InscricaoOab $i): string => $i->getUf(), $pendentes);
        sort($ufs);
        self::assertSame(['GO', 'SP'], $ufs, 'só as pendentes da pessoa');
        self::assertSame(1, $this->repo->contarParaRevisao([], '49613'));
    }

    #[TestDox('o banco recusa uma segunda principal para a mesma pessoa')]
    public function testUmaPrincipal(): void
    {
        $user = $this->usuario();
        $this->inscrever($user, '1', 'DF', TipoInscricaoOab::Principal);
        $this->inscrever($user, '2', 'SP', TipoInscricaoOab::Principal);

        $this->expectException(UniqueConstraintViolationException::class);
        $this->em->flush();
    }

    private function usuario(): User
    {
        $u = new User();
        $u->setEmail(uniqid('oab_') . '@test.com');
        $u->setFullName('Pessoa OAB');
        $u->setRoles(['ROLE_USER']);
        $u->setIsActive(true);
        $u->setPassword('x');
        $this->em->persist($u);

        return $u;
    }

    private function inscrever(User $user, string $numero, string $uf, TipoInscricaoOab $tipo, StatusOab $status = StatusOab::NaoVerificada): void
    {
        $i = new InscricaoOab($user, $numero, $uf, $tipo);
        $i->registrarVerificacao($status, null);
        $this->em->persist($i);
    }
}
