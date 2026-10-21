<?php

declare(strict_types=1);

namespace App\Tests\Master\Functional;

use App\Auth\Entity\InscricaoOab;
use App\Auth\Enum\TipoInscricaoOab;
use App\Entity\Auth\User;
use App\Entity\Auth\UserTenant;
use App\Entity\Tenant\Tenant;
use App\Master\Entity\DivulgacaoNaSeccional;
use App\Master\Entity\SeccionalOab;
use App\Master\Enum\TipoDeDivulgacao;
use App\Master\Repository\DivulgacaoNaSeccionalRepository;
use App\Master\Repository\SeccionalOabRepository;
use App\Tenant\Enum\SituacaoNaEquipe;
use App\Tests\Factory\Auth\UserFactory;
use App\Tests\Factory\Tenant\TenantFactory;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Zenstruck\Foundry\Test\Factories;

/**
 * As consultas do bloco "Seccionais visíveis" contra o banco (spec master-seccionais-oab): "N usuários" conta
 * inscrição principal e suplementar uma vez por pessoa, só de quem tem vínculo ativo em escritório fora da
 * quarentena; e as divulgações saem cortadas nas 5 mais recentes de cada seccional.
 *
 * O banco de teste é clone de outro com dados: as contagens são conferidas pela DIFERENÇA para antes do arranjo.
 */
#[CoversClass(SeccionalOabRepository::class)]
#[CoversClass(DivulgacaoNaSeccionalRepository::class)]
final class SeccionaisOabContagemTest extends KernelTestCase
{
    use Factories;

    private EntityManagerInterface $em;
    private SeccionalOabRepository $seccionais;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        $this->seccionais = static::getContainer()->get(SeccionalOabRepository::class);
    }

    #[TestDox('principal e suplementar contam; a mesma pessoa em dois escritórios conta uma vez; vínculo inativo, pessoa inativa e escritório em quarentena não contam')]
    public function testUsuariosPorSeccional(): void
    {
        $antes = $this->seccionais->usuariosPorUf();
        $a = TenantFactory::createOne(['name' => 'Escritório A ' . uniqid()])->_real();
        $b = TenantFactory::createOne(['name' => 'Escritório B ' . uniqid()])->_real();
        $quarentena = TenantFactory::createOne(['name' => 'Escritório em quarentena ' . uniqid()])->_real();
        $quarentena->setExcluidoEm(new \DateTimeImmutable('-1 day'));

        $this->pessoa([$a], ['SP' => TipoInscricaoOab::Principal, 'RJ' => TipoInscricaoOab::Suplementar]);
        $this->pessoa([$a, $b], ['SP' => TipoInscricaoOab::Principal]);
        $this->pessoa([$a], ['SP' => TipoInscricaoOab::Principal], vinculoAtivo: false);
        $this->pessoa([$a], ['RJ' => TipoInscricaoOab::Principal], pessoaAtiva: false);
        $this->pessoa([$quarentena], ['RJ' => TipoInscricaoOab::Principal]);
        $this->em->flush();

        $depois = $this->seccionais->usuariosPorUf();
        self::assertSame(2, ($depois['SP'] ?? 0) - ($antes['SP'] ?? 0), 'duas pessoas em SP (uma delas em dois escritórios)');
        self::assertSame(1, ($depois['RJ'] ?? 0) - ($antes['RJ'] ?? 0), 'só a suplementar de quem tem vínculo ativo');
    }

    #[TestDox('as 5 divulgações mais recentes de cada seccional, da mais nova para a mais antiga, com o autor')]
    public function testCincoUltimas(): void
    {
        $am = $this->seccionais->buscarPorUf('AM');
        self::assertInstanceOf(SeccionalOab::class, $am);
        $autor = UserFactory::createOne(['fullName' => 'Autora da Divulgação'])->_real();
        foreach (['2026-10-01', '2026-10-05', '2026-10-03', '2026-10-09', '2026-10-07', '2026-10-09'] as $i => $data) {
            $nota = 'nota ' . $i;
            $this->em->persist(new DivulgacaoNaSeccional($am, TipoDeDivulgacao::Material, new \DateTimeImmutable($data), $nota, $autor, new \DateTimeImmutable()));
        }
        $this->em->flush();

        $lista = static::getContainer()->get(DivulgacaoNaSeccionalRepository::class)->ultimasPorUf(5)['AM'] ?? [];

        self::assertCount(5, $lista);
        self::assertSame(['2026-10-09', '2026-10-09', '2026-10-07', '2026-10-05', '2026-10-03'], array_column($lista, 'data'));
        self::assertSame('nota 5', $lista[0]['nota'], 'mesma data: a registrada por último vem antes');
        self::assertSame('Autora da Divulgação', $lista[0]['autor']);
        self::assertSame('material', $lista[0]['tipo']);
    }

    /**
     * @param list<Tenant>                     $escritorios
     * @param array<string, TipoInscricaoOab> $inscricoes UF => tipo
     */
    private function pessoa(array $escritorios, array $inscricoes, bool $vinculoAtivo = true, bool $pessoaAtiva = true): User
    {
        $user = UserFactory::createOne()->_real();
        $user->setIsActive($pessoaAtiva);
        foreach ($escritorios as $escritorio) {
            $vinculo = new UserTenant($user, $escritorio);
            if (!$vinculoAtivo) {
                $vinculo->alterarSituacao(SituacaoNaEquipe::Suspenso);
            }
            $this->em->persist($vinculo);
        }
        foreach ($inscricoes as $uf => $tipo) {
            $this->em->persist(new InscricaoOab($user, (string) random_int(100000, 999999), $uf, $tipo));
        }

        return $user;
    }
}
