<?php

declare(strict_types=1);

namespace App\Tests\Profile\Functional;

use App\Entity\Auth\User;
use App\Profile\Entity\UserProfile;
use App\Profile\Enum\Atuacao;
use App\Profile\Enum\Genero;
use App\Profile\Repository\UserProfileRepository;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/** Leitor em lote do motor de filtros do Comercial (5d, F2): spec docs/specs/meu-perfil-genero-atuacao.md. */
#[CoversClass(UserProfileRepository::class)]
final class AtributosDoPerfilTest extends KernelTestCase
{
    #[TestDox('atributosDe: strings cruas por userId; sem perfil vem tudo null; UF da OAB principal')]
    public function testAtributosDe(): void
    {
        self::bootKernel();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $ana = $this->usuario($em, 'DF');
        $bruno = $this->usuario($em, null);
        $perfil = new UserProfile($ana);
        $perfil->setGenero(Genero::Feminino)->setAtuacao(Atuacao::Advogado)->setDataNascimento(new \DateTime('1990-05-04'));
        $em->persist($perfil);
        $em->flush();

        $lido = static::getContainer()->get(UserProfileRepository::class)->atributosDe([(int) $ana->getId(), (int) $bruno->getId(), 999999999]);

        self::assertSame(['feminino', 'advogado', '1990-05-04', 'DF'], [
            $lido[$ana->getId()]['genero'], $lido[$ana->getId()]['atuacao'],
            $lido[$ana->getId()]['dataNascimento']?->format('Y-m-d'), $lido[$ana->getId()]['uf'],
        ]);
        self::assertSame(['genero' => null, 'atuacao' => null, 'dataNascimento' => null, 'uf' => null], $lido[$bruno->getId()]);
        self::assertArrayNotHasKey(999999999, $lido);
        self::assertSame([], static::getContainer()->get(UserProfileRepository::class)->atributosDe([]));
    }

    private function usuario(EntityManagerInterface $em, ?string $uf): User
    {
        $u = new User();
        $u->setEmail('atrib_' . uniqid() . '@test.com');
        $u->setFullName('Pessoa Atributos');
        $u->setRoles(['ROLE_USER']);
        $u->setPassword('x');
        $u->setOabUf($uf);
        $em->persist($u);

        return $u;
    }
}
