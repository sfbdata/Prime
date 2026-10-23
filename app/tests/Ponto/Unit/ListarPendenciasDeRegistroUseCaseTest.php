<?php

declare(strict_types=1);

namespace App\Tests\Ponto\Unit;

use App\Entity\Auth\User;
use App\Entity\Auth\UserTenant;
use App\Entity\Tenant\Tenant;
use App\Ponto\Entity\Feriado;
use App\Ponto\Entity\JustificativaPonto;
use App\Ponto\Entity\RegistroPonto;
use App\Ponto\Repository\FeriadoRepository;
use App\Ponto\Repository\JustificativaPontoRepository;
use App\Ponto\Repository\RegistroPontoRepository;
use App\Ponto\Service\CalculadoraJornada;
use App\Ponto\Service\EscolhaDasBatidasDoDia;
use App\Ponto\Service\JornadaResolver;
use App\Ponto\UseCase\ListarPendenciasDeRegistroUseCase;
use App\Repository\UserTenantRepository;
use App\Service\PermissionChecker;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Clock\ClockInterface;

/**
 * A regra da lista de pendências (spec `ponto-esquecimento-nao-trava.md` R5) com os repositórios mockados: o que
 * falta, o fim de semana e o feriado, o pedido já feito, os escritórios consultados, a janela e a ordem. As consultas
 * (escritório escrito e filtro suspenso) são provadas no functional `PendenciasDeRegistroTest`.
 */
#[CoversClass(ListarPendenciasDeRegistroUseCase::class)]
final class ListarPendenciasDeRegistroUseCaseTest extends TestCase
{
    private const AGORA = '2026-10-23 10:00:00';   // sexta-feira

    private User $user;
    private Tenant $x;
    private Tenant $y;
    private UserTenantRepository&MockObject $vinculos;
    private PermissionChecker&MockObject $permissoes;
    private RegistroPontoRepository&MockObject $registros;
    private JustificativaPontoRepository&MockObject $justificativas;
    private FeriadoRepository&MockObject $feriados;

    /** @var array<int, list<RegistroPonto>> */
    private array $batidas = [];

    /** @var array<int, list<JustificativaPonto>> */
    private array $pedidos = [];

    /** @var array<int, list<Feriado>> */
    private array $feriadosDe = [];

    protected function setUp(): void
    {
        $this->user = new User();
        $this->x = $this->tenant(1, 'Escritório X');
        $this->y = $this->tenant(2, 'Escritório Y');
        $this->vinculos = $this->createMock(UserTenantRepository::class);
        $this->permissoes = $this->createMock(PermissionChecker::class);
        $this->registros = $this->createMock(RegistroPontoRepository::class);
        $this->justificativas = $this->createMock(JustificativaPontoRepository::class);
        $this->feriados = $this->createMock(FeriadoRepository::class);
        $this->registros->method('batidasDoPeriodoNoEscritorio')
            ->willReturnCallback(fn (User $u, Tenant $t): array => $this->batidas[(int) $t->getId()] ?? []);
        $this->justificativas->method('esquecimentosDoPeriodoNoEscritorio')
            ->willReturnCallback(fn (User $u, Tenant $t): array => $this->pedidos[(int) $t->getId()] ?? []);
        $this->feriados->method('doEscritorio')
            ->willReturnCallback(fn (Tenant $t): array => $this->feriadosDe[(int) $t->getId()] ?? []);
    }

    #[TestDox('O que falta: entrada, saída e a metade do intervalo; dia completo não entra')]
    public function testOQueFalta(): void
    {
        $this->vinculosEm($this->x);
        $this->batida($this->x, 'entrada', '2026-10-22 08:00');                                    // falta a saída
        $this->batida($this->x, 'saida', '2026-10-21 18:00');                                      // falta a entrada
        $this->batida($this->x, 'entrada', '2026-10-20 08:00');
        $this->batida($this->x, 'repouso', '2026-10-20 12:00');
        $this->batida($this->x, 'saida', '2026-10-20 18:00');                                      // falta o retorno
        $this->batida($this->x, 'entrada', '2026-10-19 08:00');
        $this->batida($this->x, 'saida', '2026-10-19 18:00');                                      // completo

        self::assertSame(
            ['2026-10-22:saida', '2026-10-21:entrada', '2026-10-20:retorno'],
            $this->chaves($this->casoDeUso()->executar($this->user, $this->x)),
        );
    }

    #[TestDox('Fim de semana e feriado do escritório não entram; o pedido pendente ou abonado da mesma batida esconde, o rejeitado não')]
    public function testFimDeSemanaFeriadoEPedido(): void
    {
        $this->vinculosEm($this->x);
        $this->batida($this->x, 'entrada', '2026-10-18 08:00');   // domingo
        $this->batida($this->x, 'entrada', '2026-10-12 08:00');   // feriado
        $this->batida($this->x, 'entrada', '2026-10-22 08:00');   // pedido pendente
        $this->batida($this->x, 'entrada', '2026-10-21 08:00');   // pedido abonado
        $this->batida($this->x, 'entrada', '2026-10-20 08:00');   // pedido rejeitado: continua
        $feriado = new Feriado();
        $feriado->setData(new \DateTime('2026-10-12'));
        $feriado->setRecorrente(false);
        $this->feriadosDe[1] = [$feriado];
        $this->pedido($this->x, '2026-10-22', 'saida', 'pendente');
        $this->pedido($this->x, '2026-10-21', 'saida', 'abonado');
        $this->pedido($this->x, '2026-10-20', 'saida', 'rejeitado');

        self::assertSame(['2026-10-20:saida'], $this->chaves($this->casoDeUso()->executar($this->user, $this->x)));
    }

    #[TestDox('Consulta o atual e os outros com o módulo de ponto; o atual vem primeiro no mesmo dia; o mais recente primeiro')]
    public function testEscritoriosEOrdem(): void
    {
        $z = $this->tenant(3, 'Escritório Z');
        $this->vinculosEm($this->x, $this->y, $z);
        $this->permissoes->method('canAccessModule')
            ->willReturnCallback(fn (User $u, Tenant $t, string $m): bool => $m === 'ponto' && $t->getId() !== 3);
        $this->batida($this->y, 'entrada', '2026-10-22 08:00');
        $this->batida($this->x, 'entrada', '2026-10-22 08:00');
        $this->batida($this->y, 'entrada', '2026-10-21 08:00');
        $this->batida($z, 'entrada', '2026-10-22 08:00');

        $lista = $this->casoDeUso()->executar($this->user, $this->x);

        self::assertSame(['2026-10-22:saida@1', '2026-10-22:saida@2', '2026-10-21:saida@2'], array_map(
            static fn ($p): string => $p->dia . ':' . $p->tipo . '@' . $p->escritorioId,
            $lista,
        ));
        self::assertTrue($lista[0]->atual);
        self::assertFalse($lista[1]->atual);
        self::assertSame('Escritório Y', $lista[1]->escritorio);
    }

    #[TestDox('A janela: dos 30 dias antes de hoje até o fim de ontem; hoje fica fora')]
    public function testJanela(): void
    {
        $this->vinculosEm($this->x);
        $janelas = [];
        $this->registros = $this->createMock(RegistroPontoRepository::class);
        $this->registros->method('batidasDoPeriodoNoEscritorio')
            ->willReturnCallback(function (User $u, Tenant $t, \DateTimeImmutable $inicio, \DateTimeImmutable $fim) use (&$janelas): array {
                $janelas[] = [$inicio->format('Y-m-d H:i:s'), $fim->format('Y-m-d H:i:s')];

                return [];
            });

        self::assertSame([], $this->casoDeUso()->executar($this->user, $this->x));
        self::assertSame([['2026-09-23 00:00:00', '2026-10-22 23:59:59']], $janelas);
    }

    // ----------------------------------------------------------------- apoio

    private function casoDeUso(): ListarPendenciasDeRegistroUseCase
    {
        $relogio = $this->createStub(ClockInterface::class);
        $relogio->method('now')->willReturn(new \DateTimeImmutable(self::AGORA));

        return new ListarPendenciasDeRegistroUseCase(
            $this->vinculos,
            $this->permissoes,
            $this->registros,
            $this->justificativas,
            $this->feriados,
            new EscolhaDasBatidasDoDia(),
            new CalculadoraJornada(new JornadaResolver()),
            $relogio,
        );
    }

    /**
     * @param list<\App\Ponto\DTO\PendenciaDeRegistroOutput> $lista
     *
     * @return list<string>
     */
    private function chaves(array $lista): array
    {
        return array_map(static fn ($p): string => $p->dia . ':' . $p->tipo, $lista);
    }

    private function vinculosEm(Tenant ...$tenants): void
    {
        $this->vinculos->method('findActiveByUser')
            ->willReturn(array_map(fn (Tenant $t): UserTenant => new UserTenant($this->user, $t), $tenants));
    }

    private function batida(Tenant $tenant, string $tipo, string $quando): void
    {
        static $id = 0;
        $registro = new RegistroPonto();
        $registro->setUser($this->user);
        $registro->setTenant($tenant);
        $registro->setTipo($tipo);
        $registro->setDataHora(new \DateTime($quando));
        (new \ReflectionProperty(RegistroPonto::class, 'id'))->setValue($registro, ++$id);
        $this->batidas[(int) $tenant->getId()][] = $registro;
    }

    private function pedido(Tenant $tenant, string $dia, string $batida, string $status): void
    {
        $pedido = new JustificativaPonto();
        $pedido->setUser($this->user);
        $pedido->setTenant($tenant);
        $pedido->setData(new \DateTime($dia));
        $pedido->setStatus($status);
        $pedido->setTipo('esquecimento_registro');
        $pedido->setTipoRegistroEsquecido($batida);
        $this->pedidos[(int) $tenant->getId()][] = $pedido;
    }

    private function tenant(int $id, string $nome): Tenant
    {
        $tenant = new Tenant();
        $tenant->setName($nome);
        (new \ReflectionProperty(Tenant::class, 'id'))->setValue($tenant, $id);

        return $tenant;
    }
}
