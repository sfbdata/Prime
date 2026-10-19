<?php

declare(strict_types=1);

namespace App\Tests\Ponto\Functional;

use App\Entity\Auth\User;
use App\Entity\Auth\UserTenant;
use App\Entity\Tenant\Tenant;
use App\Ponto\Entity\RegistroPonto;
use App\Ponto\Repository\RegistroPontoRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * As consultas do ponto por escritório (spec `ponto-por-escritorio.md` §2 e §4), contra o banco. As duas da regra da
 * batida (interjornada e repouso) com o filtro DESLIGADO: o escritório tem de vir do parâmetro. A cross-tenant de
 * propósito (SQL nativo) também com o filtro do escritório atual LIGADO: o escopo é todo dela.
 */
#[CoversClass(RegistroPontoRepository::class)]
final class PontoPorEscritorioRepositoryTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private RegistroPontoRepository $registros;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        $this->registros = static::getContainer()->get(RegistroPontoRepository::class);
        $this->em->getFilters()->isEnabled('tenant') && $this->em->getFilters()->disable('tenant');
    }

    #[TestDox('findUltimaSaida, filtro desligado: só a saída do escritório pedido (a de outro escritório não conta na interjornada)')]
    public function testUltimaSaidaNoEscritorio(): void
    {
        [$x, $y] = [$this->tenant('X'), $this->tenant('Y')];
        $user = $this->user();
        $deX = $this->batida($user, $x, RegistroPonto::TIPO_SAIDA, '2026-10-18 18:00:00');
        $this->batida($user, $y, RegistroPonto::TIPO_SAIDA, '2026-10-18 22:00:00');

        self::assertSame($deX->getId(), $this->registros->findUltimaSaida($user, $x)?->getId());
        self::assertNotSame($deX->getId(), $this->registros->findUltimaSaida($user, $y)?->getId());
    }

    #[TestDox('findRepousoDoDia, filtro desligado: só o repouso do escritório pedido')]
    public function testRepousoDoDiaNoEscritorio(): void
    {
        [$x, $y] = [$this->tenant('X'), $this->tenant('Y')];
        $user = $this->user();
        $this->batida($user, $y, RegistroPonto::TIPO_REPOUSO, '2026-10-19 11:00:00');
        $deX = $this->batida($user, $x, RegistroPonto::TIPO_REPOUSO, '2026-10-19 12:00:00');

        self::assertSame($deX->getId(), $this->registros->findRepousoDoDia($user, $x, new \DateTimeImmutable('2026-10-19'))?->getId());
        self::assertNull($this->registros->findRepousoDoDia($user, $this->tenant('Z'), new \DateTimeImmutable('2026-10-19')));
    }

    #[TestDox('Ponto aberto hoje: só entrada, reentrada e repouso sem retorno abrem; entrada e saída fecha; em ordem alfabética')]
    public function testUltimaBatidaPorEscritorio(): void
    {
        $x = $this->tenant('X');
        $user = $this->user();
        $soEntrada = $this->vinculado($user, 'C só entrada', [['entrada', '08:00']]);
        $reentrada = $this->vinculado($user, 'A reentrada', [['entrada', '08:00'], ['saida', '12:00'], ['entrada', '14:00']]);
        $repouso = $this->vinculado($user, 'B repouso', [['entrada', '08:00'], ['repouso', '12:00']]);
        $fechado = $this->vinculado($user, 'D fechado', [['entrada', '08:00'], ['saida', '12:00']]);
        $semEntrada = $this->vinculado($user, 'E só repouso e retorno', [['repouso', '12:00'], ['retorno', '13:00']]);

        $nomes = $this->abertos($user, $x, [$soEntrada, $reentrada, $repouso, $fechado, $semEntrada], '2026-10-19 15:00:00');

        self::assertSame([$reentrada->getName(), $repouso->getName(), $soEntrada->getName()], $nomes);
    }

    #[TestDox('Ponto aberto hoje: batida depois do agora não conta; ontem não conta; empate de horário decide pelo id')]
    public function testJanelaEEmpate(): void
    {
        $x = $this->tenant('X');
        $user = $this->user();
        $saidaFutura = $this->vinculado($user, 'Saída lançada para depois', [['entrada', '08:00'], ['saida', '18:00']]);
        $entradaFutura = $this->vinculado($user, 'Entrada lançada para depois', [['entrada', '16:00']]);
        $ontem = $this->tenant('Ontem');
        $this->vincular($user, $ontem);
        $this->batida($user, $ontem, 'entrada', '2026-10-18 23:50:00');
        $empateFecha = $this->vinculado($user, 'Empate fecha', [['entrada', '09:00'], ['saida', '09:00']]);
        $empateAbre = $this->vinculado($user, 'Empate abre', [['saida', '09:00'], ['entrada', '09:00']]);

        $nomes = $this->abertos($user, $x, [$saidaFutura, $entradaFutura, $ontem, $empateFecha, $empateAbre], '2026-10-19 10:00:00');

        self::assertSame([$empateAbre->getName(), $saidaFutura->getName()], $nomes);
    }

    #[TestDox('Ponto aberto hoje, filtro de X ligado: vínculo inativo, escritório excluído, outro usuário e o próprio X ficam de fora')]
    public function testEscopo(): void
    {
        $x = $this->tenant('X');
        $user = $this->user();
        $vale = $this->vinculado($user, 'Vale', [['entrada', '08:00']]);
        $semVinculo = $this->vinculado($user, 'Sem vínculo ativo', [['entrada', '08:00']]);
        $this->em->getConnection()->executeStatement('UPDATE user_tenant SET is_active = false WHERE user_id = ? AND tenant_id = ?', [$user->getId(), $semVinculo->getId()]);
        $excluido = $this->vinculado($user, 'Excluído', [['entrada', '08:00']]);
        $excluido->setIsActive(false);
        $deOutro = $this->tenant('De outro usuário');
        $this->vincular($user, $deOutro);
        $this->batida($this->user(), $deOutro, 'entrada', '2026-10-19 08:00:00');
        $this->vincular($user, $x);
        $this->batida($user, $x, 'entrada', '2026-10-19 08:00:00');
        $this->em->flush();
        $this->em->getFilters()->enable('tenant')->setParameter('tenant', (int) $x->getId(), Types::INTEGER);

        $nomes = $this->abertos($user, $x, [$vale, $semVinculo, $excluido, $deOutro, $x], '2026-10-19 10:00:00');

        self::assertSame([$vale->getName()], $nomes);
    }

    /**
     * @param list<Tenant> $escritorios
     *
     * @return list<string>
     */
    private function abertos(User $user, Tenant $atual, array $escritorios, string $agora): array
    {
        $linhas = $this->registros->pontoAbertoHojeEmOutrosEscritorios(
            $user,
            $atual,
            array_map(static fn (Tenant $t): int => (int) $t->getId(), $escritorios),
            new \DateTimeImmutable(substr($agora, 0, 10) . ' 00:00:00'),
            new \DateTimeImmutable($agora),
        );

        return array_map(static fn (array $l): string => $l['nome'], $linhas);
    }

    /** @param list<array{0: string, 1: string}> $batidas tipo e hora de 19/10, na ordem de gravação */
    private function vinculado(User $user, string $nome, array $batidas): Tenant
    {
        $tenant = $this->tenant($nome);
        $this->vincular($user, $tenant);
        foreach ($batidas as [$tipo, $hora]) {
            $this->batida($user, $tenant, $tipo, '2026-10-19 ' . $hora . ':00');
        }

        return $tenant;
    }

    private function vincular(User $user, Tenant $tenant): void
    {
        $this->em->persist(new UserTenant($user, $tenant));
        $this->em->flush();
    }

    private function batida(User $user, Tenant $tenant, string $tipo, string $dataHora): RegistroPonto
    {
        $registro = new RegistroPonto();
        $registro->setUser($user);
        $registro->setTenant($tenant);
        $registro->setTipo($tipo);
        $registro->setDataHora(new \DateTime($dataHora));
        $this->em->persist($registro);
        $this->em->flush();

        return $registro;
    }

    private function tenant(string $nome): Tenant
    {
        $tenant = new Tenant();
        $tenant->setName($nome . ' ' . uniqid());
        $this->em->persist($tenant);
        $this->em->flush();

        return $tenant;
    }

    private function user(): User
    {
        $user = new User();
        $user->setEmail('ponto_escritorio_' . uniqid() . '@test.com');
        $user->setFullName('Ponto por escritório');
        $user->setIsActive(true);
        $this->em->persist($user);
        $this->em->flush();

        return $user;
    }
}
