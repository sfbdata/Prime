<?php

declare(strict_types=1);

namespace App\Tests\Ponto\Unit;

use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Ponto\DTO\AbonoEmLoteOutput;
use App\Ponto\Entity\JustificativaPonto;
use App\Ponto\Repository\JustificativaPontoRepository;
use App\Ponto\Service\AbonoDeJustificativa;
use App\Ponto\Service\AnaliseDaPropriaJustificativa;
use App\Ponto\UseCase\AbonarJustificativasDaEquipeUseCase;
use App\Repository\UserTenantRepository;
use App\Tenant\Service\RegraDosPrimarios;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * "Abonar selecionadas" da aba Equipe (spec ponto-desenho-0811, E5, P57): cada id passa pelas portas da ficha, uma
 * recusa não impede as outras, e o abono em si é o da ficha (aqui, um dublê de `AbonoDeJustificativa`).
 */
#[CoversClass(AbonarJustificativasDaEquipeUseCase::class)]
#[CoversClass(AbonoEmLoteOutput::class)]
#[Group('ponto')]
final class AbonarJustificativasDaEquipeUseCaseTest extends TestCase
{
    private JustificativaPontoRepository&MockObject $justificativas;
    private UserTenantRepository&MockObject $vinculos;
    private Tenant $escritorio;
    private User $gestor;
    /** @var list<JustificativaPonto> */
    private array $abonadas = [];
    /** @var array<int, string> recusa do abono, pelo id da justificativa */
    private array $recusasDoAbono = [];

    protected function setUp(): void
    {
        $this->justificativas = $this->createMock(JustificativaPontoRepository::class);
        $this->vinculos = $this->createMock(UserTenantRepository::class);
        $this->vinculos->method('findAtivoPorUserETenant')->willReturn(null);   // ninguém é primário aqui
        $this->escritorio = new Tenant();
        $this->gestor = self::pessoa('Gestora');
    }

    #[TestDox('Abona cada id do escritório, uma vez só; ids repetidos, zerados e negativos não contam')]
    public function testAbonaCadaId(): void
    {
        $ana = self::pessoa('Ana');
        $bia = self::pessoa('Bia');
        $this->achadas([10 => self::justificativa($ana, '2026-11-03'), 11 => self::justificativa($bia, '2026-11-04')], [10, 11]);
        $this->vinculos->method('existeVinculoAtivo')->willReturn(true);

        $resultado = $this->useCase()->executar($this->gestor, $this->escritorio, [10, 11, 10, 0, -3]);

        self::assertSame(2, $resultado->abonadas);
        self::assertSame([], $resultado->recusas);
        self::assertCount(2, $this->abonadas);
        self::assertSame('2 justificativas abonadas.', $resultado->mensagemDasAbonadas());
    }

    #[TestDox('Id de outro escritório ou que não existe, e de quem saiu do escritório: não abona')]
    public function testForaDoEscritorio(): void
    {
        $saiu = self::pessoa('Saiu');
        $this->achadas([12 => self::justificativa($saiu, '2026-11-02')], [99, 12]);
        $this->vinculos->method('existeVinculoAtivo')->willReturn(false);

        $resultado = $this->useCase()->executar($this->gestor, $this->escritorio, [99, 12]);

        self::assertSame(0, $resultado->abonadas);
        self::assertSame([AbonarJustificativasDaEquipeUseCase::NAO_ENCONTRADA, AbonarJustificativasDaEquipeUseCase::NAO_ENCONTRADA], $resultado->recusas);
        self::assertSame([], $this->abonadas);
        self::assertNull($resultado->mensagemDasAbonadas());
    }

    #[TestDox('A própria, sem ser primário nem Master: recusa do P43 a; as da equipe seguem')]
    public function testPropriaSemP43a(): void
    {
        $ana = self::pessoa('Ana');
        $this->achadas([20 => self::justificativa($this->gestor, '2026-11-05'), 21 => self::justificativa($ana, '2026-11-05')], [20, 21]);
        $this->vinculos->method('existeVinculoAtivo')->willReturn(true);

        $resultado = $this->useCase()->executar($this->gestor, $this->escritorio, [20, 21]);

        self::assertSame(1, $resultado->abonadas);
        self::assertSame(['Gestora (05/11/2026): ' . AnaliseDaPropriaJustificativa::RECUSA], $resultado->recusas);
        self::assertSame('1 justificativa abonada.', $resultado->mensagemDasAbonadas());
        self::assertSame($ana, $this->abonadas[0]->getUser());
    }

    #[TestDox('A própria, sendo o Master: abona')]
    public function testPropriaDoMaster(): void
    {
        $master = self::pessoa('Master')->setRoles(['ROLE_SUPER_ADMIN']);
        $this->achadas([30 => self::justificativa($master, '2026-11-06')], [30]);
        $this->vinculos->method('existeVinculoAtivo')->willReturn(true);

        $resultado = $this->useCase()->executar($master, $this->escritorio, [30]);

        self::assertSame(1, $resultado->abonadas);
        self::assertSame([], $resultado->recusas);
    }

    #[TestDox('O abono da ficha recusa (já analisada, batida já registrada): a recusa sai com a pessoa e o dia')]
    public function testRecusaDoAbono(): void
    {
        $ana = self::pessoa('Ana');
        $this->achadas([40 => self::justificativa($ana, '2026-11-07')], [40]);
        $this->vinculos->method('existeVinculoAtivo')->willReturn(true);
        $this->recusasDoAbono[40] = 'Só dá para abonar o que está em análise.';

        $resultado = $this->useCase()->executar($this->gestor, $this->escritorio, [40]);

        self::assertSame(0, $resultado->abonadas);
        self::assertSame(['Ana (07/11/2026): Só dá para abonar o que está em análise.'], $resultado->recusas);
    }

    #[TestDox('Nada selecionado: não busca nem abona')]
    public function testNadaSelecionado(): void
    {
        $this->justificativas->expects(self::once())->method('doEscritorioPorIds')->with([], $this->escritorio)->willReturn([]);

        $resultado = $this->useCase()->executar($this->gestor, $this->escritorio, []);

        self::assertSame(0, $resultado->abonadas);
        self::assertSame([], $resultado->recusas);
    }

    /**
     * @param array<int, JustificativaPonto> $porId
     * @param list<int>                      $idsPedidos
     */
    private function achadas(array $porId, array $idsPedidos): void
    {
        foreach ($porId as $id => $justificativa) {
            $this->recusasDoAbono[$id] ??= '';
            (new \ReflectionProperty(JustificativaPonto::class, 'id'))->setValue($justificativa, $id);
        }
        $this->justificativas->expects(self::once())->method('doEscritorioPorIds')
            ->with($idsPedidos, $this->escritorio)->willReturn($porId);
    }

    private function useCase(): AbonarJustificativasDaEquipeUseCase
    {
        $duble = new class ($this) implements AbonoDeJustificativa {
            public function __construct(private readonly AbonarJustificativasDaEquipeUseCaseTest $teste)
            {
            }

            public function abonar(JustificativaPonto $justificativa, Tenant $escritorio, User $analista): ?string
            {
                return $this->teste->abonar($justificativa);
            }
        };

        return new AbonarJustificativasDaEquipeUseCase(
            $this->justificativas,
            $this->vinculos,
            new AnaliseDaPropriaJustificativa(new RegraDosPrimarios($this->vinculos)),
            $duble,
        );
    }

    /** O dublê do abono da ficha: guarda a abonada ou devolve a recusa combinada para o id. */
    public function abonar(JustificativaPonto $justificativa): ?string
    {
        $recusa = $this->recusasDoAbono[(int) $justificativa->getId()] ?? '';
        if ($recusa !== '') {
            return $recusa;
        }
        $this->abonadas[] = $justificativa;

        return null;
    }

    private static function pessoa(string $nome): User
    {
        return (new User())->setEmail(strtolower($nome) . '@test.com')->setFullName($nome);
    }

    private static function justificativa(User $dono, string $dia): JustificativaPonto
    {
        $justificativa = new JustificativaPonto();
        $justificativa->setUser($dono);
        $justificativa->setData(new \DateTime($dia));
        $justificativa->setStatus('pendente');

        return $justificativa;
    }
}
