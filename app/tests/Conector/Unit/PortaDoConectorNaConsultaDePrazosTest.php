<?php

declare(strict_types=1);

namespace App\Tests\Conector\Unit;

use App\Conector\Entity\EscritorioDoConector;
use App\Conector\Exception\ErroDaFerramenta;
use App\Conector\Repository\EscritorioDoConectorRepository;
use App\Conector\Service\LiberacaoDoConector;
use App\Conector\Service\PortaDoConectorNaConsultaDePrazos;
use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Master\Entity\RecursoEstado;
use App\Master\Repository\ContasDoSistemaRepository;
use App\Master\Repository\RecursoEstadoRepository;
use App\Master\Service\RecursosDoSistema;
use App\Master\Service\RegraDosMasters;
use App\Service\PermissionChecker;
use App\Tarefa\Service\AcessoAoModuloTarefas;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * A porta do conector na consulta de prazos e metas sem banco (spec mcp-remoto-oauth 6.4 e 14.i): pede o módulo Tarefas
 * e a liberação do conector nos dois níveis, e não olha o aceite da BlueJus I.A.
 */
#[CoversClass(PortaDoConectorNaConsultaDePrazos::class)]
final class PortaDoConectorNaConsultaDePrazosTest extends TestCase
{
    private Tenant $escritorio;
    private User $pessoa;
    private bool $temTarefas = true;
    private ?EscritorioDoConector $doConector = null;
    /** @var array<string, RecursoEstado> */
    private array $chaves = [];
    /** @var list<array{string, ?Tenant}> */
    private array $modulosPerguntados = [];

    protected function setUp(): void
    {
        $this->escritorio = (new Tenant())->setName('Escritório da Porta');
        $this->pessoa = (new User())->setFullName('Pessoa da Porta');
        $this->doConector = new EscritorioDoConector($this->escritorio);
        $this->doConector->ligar(new User(), new \DateTimeImmutable('2026-11-16 09:00:00'));
    }

    #[TestDox('com o módulo Tarefas e o conector liberado e ligado: passa, perguntando o módulo tarefas no escritório dado')]
    public function testPassa(): void
    {
        $this->porta()->exigir($this->pessoa, $this->escritorio);

        self::assertSame([['tarefas', $this->escritorio]], $this->modulosPerguntados);
    }

    #[TestDox('sem o módulo Tarefas: sem permissão')]
    public function testSemModulo(): void
    {
        $this->temTarefas = false;

        $this->exigirRecusa();
    }

    #[TestDox('escritório sem linha do conector: sem permissão')]
    public function testSemLinhaDoConector(): void
    {
        $this->doConector = null;

        $this->exigirRecusa();
    }

    #[TestDox('conector desligado pelo administrador do escritório: sem permissão')]
    public function testConectorDesligado(): void
    {
        $this->doConector?->desligar();

        $this->exigirRecusa();
    }

    #[TestDox('chave geral do conector desligada pelo Master: sem permissão')]
    public function testChaveGeralDesligada(): void
    {
        $estado = new RecursoEstado(LiberacaoDoConector::CHAVE_GERAL);
        $estado->desativar(null, null, null, new \DateTimeImmutable('2026-11-01'));
        $this->chaves[LiberacaoDoConector::CHAVE_GERAL] = $estado;

        $this->exigirRecusa();
    }

    private function exigirRecusa(): void
    {
        try {
            $this->porta()->exigir($this->pessoa, $this->escritorio);
            self::fail('devia recusar');
        } catch (ErroDaFerramenta $erro) {
            self::assertSame(ErroDaFerramenta::MOTIVO_SEM_PERMISSAO, $erro->motivo);
            self::assertSame(ErroDaFerramenta::SEM_PERMISSAO, $erro->getMessage());
        }
    }

    private function porta(): PortaDoConectorNaConsultaDePrazos
    {
        $permissoes = $this->createStub(PermissionChecker::class);
        $permissoes->method('canAccessModule')->willReturnCallback(function (User $quem, ?Tenant $onde, string $modulo): bool {
            $this->modulosPerguntados[] = [$modulo, $onde];

            return $quem === $this->pessoa && $this->temTarefas && $modulo === 'tarefas';
        });
        $escritorios = $this->createStub(EscritorioDoConectorRepository::class);
        $escritorios->method('buscarDoEscritorio')->willReturnCallback(fn (): ?EscritorioDoConector => $this->doConector);

        return new PortaDoConectorNaConsultaDePrazos(new AcessoAoModuloTarefas($permissoes), new LiberacaoDoConector($this->recursos(), $escritorios));
    }

    private function recursos(): RecursosDoSistema
    {
        $estados = $this->createStub(RecursoEstadoRepository::class);
        $estados->method('todosPorChave')->willReturnCallback(fn (): array => $this->chaves);

        return new RecursosDoSistema(
            $estados,
            new MockClock('2026-11-16 10:00:00'),
            $this->createStub(Security::class),
            new RegraDosMasters($this->createStub(ContasDoSistemaRepository::class)),
            new RequestStack(),
            new NullLogger(),
        );
    }
}
