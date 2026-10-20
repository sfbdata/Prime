<?php

declare(strict_types=1);

namespace App\Tests\Ponto\Unit;

use App\Entity\Auth\User;
use App\Entity\Auth\UserTenant;
use App\Entity\Tenant\Tenant;
use App\Ponto\Repository\RegistroPontoRepository;
use App\Ponto\Service\EntradaAbertaEmOutroEscritorio;
use App\Repository\UserTenantRepository;
use App\Service\PermissionChecker;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * A decisão da trava (spec `ponto-por-escritorio.md` §2.2 e §6). A consulta das batidas em si (última batida, houve
 * entrada, até o `agora`) é provada na integração (`PontoPorEscritorioRepositoryTest`); aqui, o que o serviço faz com o
 * resultado: quem consulta, a janela do dia, o filtro do módulo e a ordem.
 */
#[CoversClass(EntradaAbertaEmOutroEscritorio::class)]
final class EntradaAbertaEmOutroEscritorioTest extends TestCase
{
    private User $user;
    private Tenant $x;
    private Tenant $y;
    private Tenant $z;

    /** @var RegistroPontoRepository&MockObject */
    private RegistroPontoRepository $registros;

    /** @var UserTenantRepository&MockObject */
    private UserTenantRepository $vinculos;

    /** @var PermissionChecker&MockObject */
    private PermissionChecker $permissoes;

    protected function setUp(): void
    {
        $this->user = new User();
        $this->x = $this->tenant(1, 'Escritório X');
        $this->y = $this->tenant(2, 'Escritório Y');
        $this->z = $this->tenant(3, 'Escritório Z');
        $this->registros = $this->createMock(RegistroPontoRepository::class);
        $this->vinculos = $this->createMock(UserTenantRepository::class);
        $this->permissoes = $this->createMock(PermissionChecker::class);
    }

    #[TestDox('Só com vínculo no escritório atual: nem consulta as batidas (N5)')]
    public function testSemOutroVinculoNaoConsulta(): void
    {
        $this->vinculosEm($this->x);
        $this->registros->expects(self::never())->method('pontoAbertoHojeEmOutrosEscritorios');

        self::assertNull($this->servico()->mensagem($this->user, $this->x, new \DateTimeImmutable('2026-10-19 10:00')));
    }

    #[TestDox('Super admin sem vínculo no escritório atual e com vínculo em Y: consulta Y (a condição é "outro vínculo", não "um vínculo só")')]
    public function testSemVinculoNoAtualMasComOutro(): void
    {
        $this->vinculosEm($this->y);
        $this->permissoes->method('canAccessModule')->willReturn(true);
        $this->registros->expects(self::once())->method('pontoAbertoHojeEmOutrosEscritorios')
            ->with($this->user, $this->x, [2])->willReturn([$this->aberto($this->y)]);

        self::assertSame('Escritório Y', $this->servico()->onde($this->user, $this->x, new \DateTimeImmutable('2026-10-19 10:00')));
    }

    #[TestDox('P-C: Y sem o módulo de ponto fica de fora; sem nenhum Y com o módulo, não consulta')]
    public function testSemModuloNaoConta(): void
    {
        $this->vinculosEm($this->x, $this->y);
        $this->permissoes->method('canAccessModule')->willReturnCallback(
            fn (User $u, Tenant $t, string $modulo): bool => $modulo === 'ponto' && $t !== $this->y,
        );
        $this->registros->expects(self::never())->method('pontoAbertoHojeEmOutrosEscritorios');

        self::assertNull($this->servico()->onde($this->user, $this->x, new \DateTimeImmutable('2026-10-19 10:00')));
    }

    #[TestDox('A janela é o dia do agora: 23:59 começa no mesmo dia; 00:00 começa no dia novo (a virada solta a trava)')]
    public function testJanelaDoDia(): void
    {
        $this->vinculosEm($this->x, $this->y);
        $this->permissoes->method('canAccessModule')->willReturn(true);
        $janelas = [];
        $this->registros->method('pontoAbertoHojeEmOutrosEscritorios')->willReturnCallback(
            function (User $u, Tenant $atual, array $ids, \DateTimeImmutable $inicio, \DateTimeImmutable $agora) use (&$janelas): array {
                $janelas[] = [$inicio->format('Y-m-d H:i:s'), $agora->format('Y-m-d H:i:s')];

                return [];
            },
        );

        $this->servico()->onde($this->user, $this->x, new \DateTimeImmutable('2026-10-19 23:59:00'));
        $this->servico()->onde($this->user, $this->x, new \DateTimeImmutable('2026-10-20 00:00:00'));

        self::assertSame([
            ['2026-10-19 00:00:00', '2026-10-19 23:59:00'],
            ['2026-10-20 00:00:00', '2026-10-20 00:00:00'],
        ], $janelas);
    }

    #[TestDox('Mais de um aberto: o primeiro da consulta (que vem em ordem alfabética); a frase é a do dono')]
    public function testPrimeiroEFraseDoDono(): void
    {
        $this->vinculosEm($this->x, $this->y, $this->z);
        $this->permissoes->method('canAccessModule')->willReturn(true);
        $this->registros->method('pontoAbertoHojeEmOutrosEscritorios')
            ->with($this->user, $this->x, [2, 3])
            ->willReturn([$this->aberto($this->y), $this->aberto($this->z)]);

        self::assertSame(
            sprintf(EntradaAbertaEmOutroEscritorio::MENSAGEM_TRAVA, 'Escritório Y'),
            $this->servico()->mensagem($this->user, $this->x, new \DateTimeImmutable('2026-10-19 10:00')),
        );
        self::assertSame('Você está com o ponto aberto em Escritório Y. Registre a saída lá antes.', sprintf(EntradaAbertaEmOutroEscritorio::MENSAGEM_TRAVA, 'Escritório Y'));
    }

    #[TestDox('Nada aberto: a entrada segue')]
    public function testNadaAberto(): void
    {
        $this->vinculosEm($this->x, $this->y);
        $this->permissoes->method('canAccessModule')->willReturn(true);
        $this->registros->method('pontoAbertoHojeEmOutrosEscritorios')->willReturn([]);

        self::assertNull($this->servico()->mensagem($this->user, $this->x, new \DateTimeImmutable('2026-10-19 10:00')));
    }

    private function servico(): EntradaAbertaEmOutroEscritorio
    {
        return new EntradaAbertaEmOutroEscritorio($this->registros, $this->vinculos, $this->permissoes);
    }

    private function vinculosEm(Tenant ...$tenants): void
    {
        $this->vinculos->method('findActiveByUser')->with($this->user)
            ->willReturn(array_map(fn (Tenant $t): UserTenant => new UserTenant($this->user, $t), $tenants));
    }

    /** @return array{tenantId: int, nome: string} */
    private function aberto(Tenant $tenant): array
    {
        return ['tenantId' => (int) $tenant->getId(), 'nome' => (string) $tenant->getName()];
    }

    private function tenant(int $id, string $nome): Tenant
    {
        $tenant = new Tenant();
        $tenant->setName($nome);
        (new \ReflectionProperty(Tenant::class, 'id'))->setValue($tenant, $id);

        return $tenant;
    }
}
