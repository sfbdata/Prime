<?php

declare(strict_types=1);

namespace App\Tests\Ponto\Functional;

use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Ponto\Entity\BlocoJornada;
use App\Ponto\Entity\BlocoJornadaColaborador;
use App\Ponto\Entity\JornadaColaborador;
use App\Ponto\Entity\JornadaTenant;
use App\Shared\Contract\TenantAware;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Fatia 6 de docs/specs/isolamento-multitenant-cobertura.md: os blocos de horário do Ponto no TenantFilter.
 *
 * BlocoJornada (horário do escritório) e BlocoJornadaColaborador (horário de cada pessoa) ganharam tenant_id
 * copiado da jornada dona (Version20261123190000). Aqui se prova a negativa cross-tenant por entidade e a
 * invariante da cópia: o escritório do bloco é sempre o do pai, nunca informado por fora.
 */
#[CoversClass(BlocoJornada::class)]
#[CoversClass(BlocoJornadaColaborador::class)]
final class BlocosDaJornadaIsolamentoTest extends KernelTestCase
{
    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
    }

    /** @return iterable<string, array{class-string}> */
    public static function blocos(): iterable
    {
        yield 'bloco do escritório' => [BlocoJornada::class];
        yield 'bloco da pessoa'     => [BlocoJornadaColaborador::class];
    }

    #[DataProvider('blocos')]
    #[TestDox('O escritório A não enxerga os blocos de horário do escritório B')]
    public function testCadaBlocoIsolaEntreEscritorios(string $classe): void
    {
        $a = $this->criarJornadas('A');
        $b = $this->criarJornadas('B');
        $this->em->clear();

        $repo = $this->em->getRepository($classe);
        $idsDosDois = [$a[$classe]->getId(), $b[$classe]->getId()];

        // Sem filtro (console, worker): enxerga os dois, de propósito.
        self::assertCount(2, $repo->findBy(['id' => $idsDosDois]), 'Cenário mal montado: era para haver 1 de cada.');

        $this->ligarFiltro($a['tenant']);
        $doA = $repo->findBy(['id' => $idsDosDois]);
        self::assertCount(1, $doA, sprintf('%s vazou entre escritórios.', $classe));
        self::assertSame($a[$classe]->getId(), $doA[0]->getId(), sprintf('%s devolveu o bloco do escritório errado.', $classe));

        $this->em->clear();
        self::assertNull($this->em->find($classe, $b[$classe]->getId()), sprintf('%s do escritório B foi alcançado por id a partir do A.', $classe));
    }

    #[DataProvider('blocos')]
    #[TestDox('Os blocos de horário declaram isolamento de escritório')]
    public function testBlocoEhTenantAware(string $classe): void
    {
        self::assertTrue(is_a($classe, TenantAware::class, true), sprintf('%s ficaria fora do TenantFilter, sem erro nenhum.', $classe));
    }

    #[TestDox('O bloco grava o escritório da jornada dona, mesmo quando a jornada ganhou o escritório depois do bloco')]
    public function testBlocoHerdaEscritorioDoPai(): void
    {
        $tenant = $this->novoTenant('herança');

        // Ordem invertida de propósito: o bloco entra antes de a jornada ter escritório.
        $padrao = (new JornadaTenant())->addBloco((new BlocoJornada())->setDiasSemana([1])->setEntrada('08:00')->setSaida('17:00'));
        $padrao->setTenant($tenant);
        $this->em->persist($padrao);

        $daPessoa = (new JornadaColaborador())->addBloco((new BlocoJornadaColaborador())->setDiasSemana([1])->setEntrada('08:00')->setSaida('17:00'));
        $daPessoa->setUser($this->novaPessoa())->setTenant($tenant);
        $this->em->persist($daPessoa);
        $this->em->flush();

        foreach ([[BlocoJornada::class, $padrao->getBlocos()->first()], [BlocoJornadaColaborador::class, $daPessoa->getBlocos()->first()]] as [$classe, $bloco]) {
            $gravado = (int) $this->em->getConnection()->fetchOne(
                sprintf('SELECT tenant_id FROM %s WHERE id = ?', $this->em->getClassMetadata($classe)->getTableName()),
                [$bloco->getId()],
            );
            self::assertSame($tenant->getId(), $gravado, sprintf('%s gravou escritório diferente da jornada dona.', $classe));
        }
    }

    #[TestDox('Bloco de jornada sem escritório não é gravado')]
    public function testBlocoSemEscritorioNaoGrava(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('sem escritório');

        $this->em->persist((new BlocoJornada())->setDiasSemana([1])->setEntrada('08:00')->setSaida('17:00'));
    }

    // ----------------------------------------------------------------- helpers

    private function ligarFiltro(Tenant $tenant): void
    {
        $this->em->getFilters()->enable('tenant')->setParameter('tenant', (int) $tenant->getId(), Types::INTEGER);
    }

    private function novoTenant(string $rotulo): Tenant
    {
        $tenant = new Tenant();
        $tenant->setName('Escritório blocos ' . $rotulo . ' ' . bin2hex(random_bytes(4)));
        $this->em->persist($tenant);

        return $tenant;
    }

    private function novaPessoa(): User
    {
        $user = new User();
        $user->setEmail('blocos_' . uniqid() . '@test.com');
        $user->setFullName('Pessoa Blocos');
        $user->setRoles(['ROLE_USER']);
        $user->setIsActive(true);
        $user->setPassword('x');
        $this->em->persist($user);

        return $user;
    }

    /**
     * Um escritório novo com a jornada padrão (um bloco) e a jornada de uma pessoa (um bloco).
     *
     * @return array<string, mixed> indexado pelo FQCN de cada bloco, mais 'tenant'
     */
    private function criarJornadas(string $rotulo): array
    {
        $tenant = $this->novoTenant($rotulo);

        $blocoPadrao = (new BlocoJornada())->setDiasSemana([1, 2, 3, 4, 5])->setEntrada('09:00')->setSaida('18:00');
        $padrao = (new JornadaTenant())->setTenant($tenant)->addBloco($blocoPadrao);
        $this->em->persist($padrao);

        $blocoDaPessoa = (new BlocoJornadaColaborador())->setDiasSemana([1, 2, 3, 4, 5])->setEntrada('09:00')->setSaida('18:00');
        $daPessoa = (new JornadaColaborador())->setUser($this->novaPessoa())->setTenant($tenant)->addBloco($blocoDaPessoa);
        $this->em->persist($daPessoa);
        $this->em->flush();

        return ['tenant' => $tenant, BlocoJornada::class => $blocoPadrao, BlocoJornadaColaborador::class => $blocoDaPessoa];
    }
}
