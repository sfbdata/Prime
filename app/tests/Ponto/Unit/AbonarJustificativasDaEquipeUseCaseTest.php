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
 * "Abonar selecionadas" da aba Equipe (spec ponto-desenho-0811, E5, P57; desenho L818): cada id passa pelas portas da
 * ficha, uma recusa não impede as outras, e o abono em si é o da ficha (aqui, um dublê de `AbonoDeJustificativa`). A
 * própria de quem analisa vai sozinha (`abonar`); as da equipe vão juntas, numa chamada só (`abonarDaEquipe`).
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
    /** @var list<JustificativaPonto> as abonadas, pelos dois caminhos */
    private array $abonadas = [];
    /** @var list<JustificativaPonto> as que foram pelo `abonar` (a própria, sozinha) */
    private array $sozinhas = [];
    /** @var list<list<JustificativaPonto>> cada chamada do `abonarDaEquipe` */
    private array $lotesDaEquipe = [];
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
        self::assertCount(1, $this->lotesDaEquipe, 'as da equipe vão juntas, numa chamada só');
        self::assertSame([], $this->sozinhas);
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
        self::assertCount(1, $this->sozinhas, 'a própria vai sozinha, com o evento dela');
        self::assertSame([], $this->lotesDaEquipe, 'sem nenhuma da equipe, o abono da equipe não é chamado');
    }

    #[TestDox('Desenho L818: a própria vai sozinha e as da equipe vão juntas, numa chamada só, sem a própria')]
    public function testPropriaSozinhaEquipeJunta(): void
    {
        $master = self::pessoa('Master')->setRoles(['ROLE_SUPER_ADMIN']);
        (new \ReflectionProperty(User::class, 'id'))->setValue($master, 900);
        $ana = self::pessoa('Ana');
        $bia = self::pessoa('Bia');
        $propria = self::justificativa($master, '2026-11-06');
        $daAna = self::justificativa($ana, '2026-11-06');
        $daBia = self::justificativa($bia, '2026-11-07');
        $this->achadas([31 => $daAna, 30 => $propria, 32 => $daBia], [31, 30, 32]);
        $this->vinculos->method('existeVinculoAtivo')->willReturn(true);

        $resultado = $this->useCase()->executar($master, $this->escritorio, [31, 30, 32]);

        self::assertSame(3, $resultado->abonadas);
        self::assertSame([$propria], $this->sozinhas);
        self::assertSame([[$daAna, $daBia]], $this->lotesDaEquipe);
        self::assertSame('3 justificativas abonadas.', $resultado->mensagemDasAbonadas());
    }

    #[TestDox('Recusas da equipe e da própria saem com a pessoa e o dia, na ordem em que os ids vieram; as outras contam')]
    public function testRecusasNaOrdemDosIds(): void
    {
        $master = self::pessoa('Master')->setRoles(['ROLE_SUPER_ADMIN']);
        (new \ReflectionProperty(User::class, 'id'))->setValue($master, 900);
        $ana = self::pessoa('Ana');
        $bia = self::pessoa('Bia');
        $this->achadas([
            50 => self::justificativa($bia, '2026-11-02'),
            51 => self::justificativa($master, '2026-11-03'),
            52 => self::justificativa($ana, '2026-11-04'),
        ], [52, 51, 99, 50]);
        $this->vinculos->method('existeVinculoAtivo')->willReturn(true);
        $this->recusasDoAbono[52] = 'Esta batida já foi registrada.';
        $this->recusasDoAbono[51] = 'Só dá para abonar o que está em análise.';

        $resultado = $this->useCase()->executar($master, $this->escritorio, [52, 51, 99, 50]);

        self::assertSame(1, $resultado->abonadas);
        self::assertSame([
            'Ana (04/11/2026): Esta batida já foi registrada.',
            'Master (03/11/2026): Só dá para abonar o que está em análise.',
            AbonarJustificativasDaEquipeUseCase::NAO_ENCONTRADA,
        ], $resultado->recusas);
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
                return $this->teste->abonarSozinha($justificativa);
            }

            public function abonarDaEquipe(array $daEquipe, Tenant $escritorio, User $analista): array
            {
                return $this->teste->abonarDaEquipe($daEquipe);
            }
        };

        return new AbonarJustificativasDaEquipeUseCase(
            $this->justificativas,
            $this->vinculos,
            new AnaliseDaPropriaJustificativa(new RegraDosPrimarios($this->vinculos)),
            $duble,
        );
    }

    /** O dublê do abono da própria: guarda a abonada ou devolve a recusa combinada para o id. */
    public function abonarSozinha(JustificativaPonto $justificativa): ?string
    {
        $this->sozinhas[] = $justificativa;

        return $this->abonar($justificativa);
    }

    /**
     * O dublê do abono da equipe: guarda a chamada e devolve as recusas combinadas, pelo id.
     *
     * @param list<JustificativaPonto> $daEquipe
     *
     * @return array<int, string>
     */
    public function abonarDaEquipe(array $daEquipe): array
    {
        $this->lotesDaEquipe[] = $daEquipe;
        $recusas = [];
        foreach ($daEquipe as $justificativa) {
            if (($recusa = $this->abonar($justificativa)) !== null) {
                $recusas[(int) $justificativa->getId()] = $recusa;
            }
        }

        return $recusas;
    }

    private function abonar(JustificativaPonto $justificativa): ?string
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
