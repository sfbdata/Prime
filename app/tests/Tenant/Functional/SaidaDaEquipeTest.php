<?php

declare(strict_types=1);

namespace App\Tests\Tenant\Functional;

use App\Entity\Auth\User;
use App\Entity\Auth\UserTenant;
use App\Entity\Tenant\Tenant;
use App\Entity\Tenant\TenantRole;
use App\Tenant\Entity\SaidaDaEquipe;
use App\Tenant\Enum\MotivoDaSaida;
use App\Tenant\Enum\SituacaoNaEquipe;
use App\Tenant\Repository\SaidaDaEquipeRepository;
use App\Tenant\UseCase\AlterarSituacaoNaEquipeUseCase;
use App\Tenant\UseCase\DemitirDaEquipeUseCase;
use App\Tenant\UseCase\RemoverColaboradorDoEscritorioUseCase;
use App\Tenant\UseCase\SairDoEscritorioUseCase;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Saída da equipe (spec docs/specs/saida-da-equipe.md): demitir e sair gravam a saída na mesma transação da
 * remoção; suspender não grava; o leitor do painel do Master conta por período.
 */
#[CoversClass(SaidaDaEquipe::class)]
#[CoversClass(SaidaDaEquipeRepository::class)]
#[CoversClass(RemoverColaboradorDoEscritorioUseCase::class)]
final class SaidaDaEquipeTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private Tenant $tenant;
    private User $primario;
    private TenantRole $secundario;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);

        $this->tenant = new Tenant();
        $this->tenant->setName('Escritório Saídas ' . uniqid());
        $this->em->persist($this->tenant);
        $sistema          = $this->perfil(true);
        $this->secundario = $this->perfil(false);
        $this->primario   = $this->usuario('Primário Saídas');
        $this->vincular($this->primario, $sistema);
        $this->tenant->setCriadoPor($this->primario);
        $this->em->flush();
    }

    #[TestDox('demitir (mesmo suspensa) grava uma saída "removido", não primária, com a data de agora')]
    public function testDemitirGrava(): void
    {
        $jessica = $this->usuario('Jéssica Saídas');
        $vinculo = $this->vincular($jessica, $this->secundario);
        $this->em->flush();
        $this->servico(AlterarSituacaoNaEquipeUseCase::class)->executar($this->primario, $this->tenant, (int) $vinculo->getId(), SituacaoNaEquipe::Suspenso);
        self::assertSame(0, $this->contar(), 'suspender não é saída');

        $antes = new \DateTimeImmutable('-1 second');
        $this->servico(DemitirDaEquipeUseCase::class)->executar($this->primario, $this->tenant, (int) $vinculo->getId());

        $saidas = $this->saidas();
        self::assertCount(1, $saidas);
        self::assertSame([MotivoDaSaida::Removido, false, $jessica->getId()], [$saidas[0]->getMotivo(), $saidas[0]->eraPrimario(), $saidas[0]->getUsuario()?->getId()]);
        self::assertGreaterThanOrEqual($antes, $saidas[0]->getSaiuEm());
    }

    #[TestDox('sair do escritório grava uma saída "saiu"')]
    public function testSairGrava(): void
    {
        $samuel = $this->usuario('Samuel Saídas');
        $this->vincular($samuel, $this->secundario);
        $this->em->flush();

        $this->servico(SairDoEscritorioUseCase::class)->executar($samuel, $this->tenant);

        $saidas = $this->saidas();
        self::assertCount(1, $saidas);
        self::assertSame(MotivoDaSaida::Saiu, $saidas[0]->getMotivo());
    }

    #[TestDox('uma remoção recusada não grava saída (mesma transação)')]
    public function testRecusaNaoGrava(): void
    {
        try {
            $this->servico(SairDoEscritorioUseCase::class)->executar($this->primario, $this->tenant);
            self::fail('o titular não pode sair');
        } catch (\InvalidArgumentException) {
        }

        self::assertSame(0, $this->contar());
    }

    #[TestDox('o leitor do painel conta as saídas do período, com e sem o filtro "só primários"')]
    public function testLeitorPorPeriodo(): void
    {
        $repo   = $this->servico(SaidaDaEquipeRepository::class);
        $agora  = new \DateTimeImmutable();
        $antes  = $repo->contarNoPeriodo($agora->modify('-30 days'), $agora->modify('+1 minute'));
        $antesP = $repo->contarNoPeriodo($agora->modify('-30 days'), $agora->modify('+1 minute'), true);
        $repo->registrar(new SaidaDaEquipe($this->tenant, null, MotivoDaSaida::Removido, true));
        $repo->registrar(new SaidaDaEquipe($this->tenant, null, MotivoDaSaida::Saiu, false));
        $this->em->flush();

        self::assertSame($antes + 2, $repo->contarNoPeriodo($agora->modify('-30 days'), $agora->modify('+1 minute')));
        self::assertSame($antesP + 1, $repo->contarNoPeriodo($agora->modify('-30 days'), $agora->modify('+1 minute'), true));
        self::assertSame(0, $repo->contarNoPeriodo($agora->modify('+1 day'), $agora->modify('+2 days')));
    }

    /**
     * @template T of object
     *
     * @param class-string<T> $classe
     *
     * @return T
     */
    private function servico(string $classe): object
    {
        return static::getContainer()->get($classe);
    }

    private function contar(): int
    {
        return (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM saida_da_equipe WHERE tenant_id = ?', [$this->tenant->getId()]);
    }

    /** @return list<SaidaDaEquipe> */
    private function saidas(): array
    {
        $this->em->clear();

        return array_values($this->em->getRepository(SaidaDaEquipe::class)->findBy(['tenant' => $this->tenant->getId()]));
    }

    private function perfil(bool $sistema): TenantRole
    {
        $perfil = new TenantRole();
        $perfil->setTenant($this->tenant);
        $perfil->setName(($sistema ? 'Administrador ' : 'Equipe ') . uniqid());
        $perfil->setIsSystem($sistema);
        $this->em->persist($perfil);

        return $perfil;
    }

    private function usuario(string $nome): User
    {
        $u = new User();
        $u->setEmail('saida_' . uniqid() . '@test.com');
        $u->setFullName($nome);
        $u->setRoles(['ROLE_USER']);
        $u->setIsActive(true);
        $u->setPassword('x');
        $this->em->persist($u);

        return $u;
    }

    private function vincular(User $u, TenantRole $perfil): UserTenant
    {
        $v = new UserTenant($u, $this->tenant);
        $v->setTenantRole($perfil);
        $this->em->persist($v);

        return $v;
    }
}
