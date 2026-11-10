<?php

declare(strict_types=1);

namespace App\Tests\Ponto\Functional;

use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Ponto\Controller\EquipeDoPontoController;
use App\Ponto\Controller\PontoController;
use App\Ponto\Entity\JustificativaPonto;
use App\Ponto\Repository\JustificativaPontoRepository;
use App\Ponto\Service\AbonoDeJustificativa;
use App\Ponto\Service\AnaliseDaPropriaJustificativa;
use App\Ponto\UseCase\AbonarJustificativasDaEquipeUseCase;
use App\Repository\UserTenantRepository;
use App\Tenant\Enum\SituacaoNaEquipe;
use App\Tenant\Service\RegraDosPrimarios;
use App\Tests\Functional\JusPrimeWebTestCase;
use App\Tests\Ponto\Support\CenarioDeJornadaPorEscritorioTrait;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;

/**
 * "Abonar selecionadas" da aba Equipe (spec `docs/specs/ponto-desenho-0811.md`, E5, P57): o formulário volta para a
 * /ponto na aba Equipe, com uma mensagem das abonadas e uma por recusa. O isolamento por escritório e o P43 a são
 * deste bloco; o abono em si é o da ficha (`AbonoDeJustificativa`), que aqui é um dublê: guarda o que recebeu (pelos
 * dois caminhos, a própria sozinha e as da equipe juntas) e devolve a recusa combinada. O adaptador de verdade
 * (`AbonoPelaAnaliseDaJustificativa`), com a trilha e o aviso, se prova em AbonoEmLoteLigadoAFichaTest.
 */
#[CoversClass(EquipeDoPontoController::class)]
#[CoversClass(AbonarJustificativasDaEquipeUseCase::class)]
#[Group('ponto')]
final class AbonoEmLoteDaEquipeTest extends JusPrimeWebTestCase
{
    use CenarioDeJornadaPorEscritorioTrait;

    private KernelBrowser $client;
    private Tenant $escritorio;
    private User $gestora;
    private User $ana;
    private User $bruno;
    private \DateTimeImmutable $hoje;
    /** @var object{recebidas: list<int>, recusas: array<int, string>} */
    private object $abono;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->client->disableReboot();
        $this->instalarCsrfFalso();
        $this->instalarDubleDoAbono();
        $this->hoje = new \DateTimeImmutable('today');
        $this->escritorio = $this->escritorio('Escritório do lote');
        $this->gestora = $this->pessoa('Gestora');
        $this->vincular($this->gestora, $this->escritorio, [], true);
        $this->ana = $this->pessoa('Ana Lima');
        $this->vincular($this->ana, $this->escritorio);
        $this->bruno = $this->pessoa('Bruno Reis');
        $this->vincular($this->bruno, $this->escritorio);
    }

    #[TestDox('Abona as selecionadas da equipe e volta para a /ponto na aba Equipe com "2 justificativas abonadas."')]
    public function testAbonaAsSelecionadas(): void
    {
        $daAna = $this->justificativa($this->ana, $this->escritorio, -2);
        $doBruno = $this->justificativa($this->bruno, $this->escritorio, -1);

        $this->abonar($this->gestora, [$daAna->getId(), $doBruno->getId()]);

        self::assertResponseRedirects('/ponto/?aba=equipe');
        self::assertSame([$daAna->getId(), $doBruno->getId()], $this->abono->recebidas);
        self::assertSame(['2 justificativas abonadas.'], $this->flashes(PontoController::FLASH_AVISO_FLUTUANTE), 'E8: no aviso flutuante da /ponto');
        self::assertSame([], $this->flashes('warning'));
    }

    #[TestDox('Recusa do abono da ficha: uma mensagem com a pessoa e o dia; as outras seguem')]
    public function testRecusaDoAbono(): void
    {
        $daAna = $this->justificativa($this->ana, $this->escritorio, -2);
        $doBruno = $this->justificativa($this->bruno, $this->escritorio, -1);
        $this->abono->recusas[(int) $daAna->getId()] = 'Esta batida já foi registrada.';

        $this->abonar($this->gestora, [$daAna->getId(), $doBruno->getId()]);

        self::assertSame(['1 justificativa abonada.'], $this->flashes(PontoController::FLASH_AVISO_FLUTUANTE), 'E8: no aviso flutuante da /ponto');
        self::assertSame([sprintf('Ana Lima (%s): Esta batida já foi registrada.', $this->dia(-2))], $this->flashes('warning'));
    }

    #[TestDox('Isolamento: id de outro escritório, que não existe ou de quem saiu não chega ao abono e não muda no banco')]
    public function testIsolamento(): void
    {
        $outro = $this->escritorio('Outro escritório');
        $gil = $this->pessoa('Gil Souza');
        $this->vincular($gil, $outro);
        $doGil = $this->justificativa($gil, $outro, -1);
        $fred = $this->pessoa('Fred Alves');
        $this->vincular($fred, $this->escritorio)->alterarSituacao(SituacaoNaEquipe::Suspenso);
        $this->em()->flush();
        $doFred = $this->justificativa($fred, $this->escritorio, -1);

        $this->abonar($this->gestora, [$doGil->getId(), 999999999, $doFred->getId()]);

        self::assertResponseRedirects('/ponto/?aba=equipe');
        self::assertSame([], $this->abono->recebidas);
        self::assertSame([], $this->flashes(PontoController::FLASH_AVISO_FLUTUANTE));
        self::assertSame([], $this->flashes('success'));
        self::assertSame(array_fill(0, 3, AbonarJustificativasDaEquipeUseCase::NAO_ENCONTRADA), $this->flashes('warning'));
        self::assertSame(['pendente', 'pendente'], [$this->statusNoBanco($doGil), $this->statusNoBanco($doFred)]);
    }

    #[TestDox('A própria no lote: o gestor pelo perfil recebe a recusa do P43 a; a primária e o Master abonam')]
    public function testPropriaNoLote(): void
    {
        $gestor = $this->pessoa('Gestor do Perfil');
        $this->vincular($gestor, $this->escritorio, ['modules.ponto.view', 'admin.users.manage']);
        $master = $this->pessoa('Master', ['ROLE_SUPER_ADMIN']);
        $this->vincular($master, $this->escritorio);
        $doGestor = $this->justificativa($gestor, $this->escritorio, -3);
        $daGestora = $this->justificativa($this->gestora, $this->escritorio, -3);
        $doMaster = $this->justificativa($master, $this->escritorio, -3);

        $this->abonar($gestor, [$doGestor->getId()]);
        self::assertSame([sprintf('Gestor do Perfil (%s): %s', $this->dia(-3), AnaliseDaPropriaJustificativa::RECUSA)], $this->flashes('warning'));
        self::assertSame([], $this->abono->recebidas);

        $this->abonar($this->gestora, [$daGestora->getId()]);
        $this->abonar($master, [$doMaster->getId()]);
        self::assertSame([$daGestora->getId(), $doMaster->getId()], $this->abono->recebidas);
    }

    #[TestDox('Guardas: colaborador 403; CSRF inválido 403; nada marcado volta com o aviso')]
    public function testGuardas(): void
    {
        $doBruno = $this->justificativa($this->bruno, $this->escritorio, -1);

        $this->abonar($this->ana, [$doBruno->getId()]);
        self::assertResponseStatusCodeSame(403);

        $this->logarComTenant($this->client, $this->gestora, $this->escritorio);
        $this->client->request('POST', '/ponto/equipe/abonar', ['_token' => 'errado', 'ids' => [$doBruno->getId()]]);
        self::assertResponseStatusCodeSame(403);
        self::assertSame([], $this->abono->recebidas);

        $this->abonar($this->gestora, []);
        self::assertResponseRedirects('/ponto/?aba=equipe');
        self::assertSame(['Selecione ao menos uma justificativa.'], $this->flashes('warning'));
        self::assertSame([], $this->abono->recebidas);
    }

    /** @param list<int|null> $ids */
    private function abonar(User $quem, array $ids): void
    {
        $this->logarComTenant($this->client, $quem, $this->escritorio);
        $this->client->request('POST', '/ponto/equipe/abonar', ['_token' => 'TOKEN_ponto_equipe_abonar', 'ids' => $ids]);
    }

    /** @return list<string> */
    private function flashes(string $tipo): array
    {
        return $this->client->getRequest()->getSession()->getFlashBag()->peek($tipo);
    }

    private function justificativa(User $pessoa, Tenant $escritorio, int $dias): JustificativaPonto
    {
        $j = new JustificativaPonto();
        $j->setUser($pessoa);
        $j->setTenant($escritorio);
        $j->setData(new \DateTime($this->hoje->modify($dias . ' days')->format('Y-m-d')));
        $j->setStatus('pendente');
        $j->setTipo('atestado_medico');
        $j->setBatchId(bin2hex(random_bytes(8)));
        $this->em()->persist($j);
        $this->em()->flush();

        return $j;
    }

    private function statusNoBanco(JustificativaPonto $justificativa): string
    {
        return (string) $this->em()->getConnection()->fetchOne('SELECT status FROM justificativa_ponto WHERE id = ?', [$justificativa->getId()]);
    }

    private function dia(int $dias): string
    {
        return $this->hoje->modify($dias . ' days')->format('d/m/Y');
    }

    /**
     * O lote com o dublê no lugar do abono da ficha. O adaptador do abono é usado por um serviço só e o container o
     * embute nele: por isso o dublê entra pelo UseCase do lote, montado com as peças de verdade.
     */
    private function instalarDubleDoAbono(): void
    {
        $this->abono = new class () {
            /** @var list<int> */
            public array $recebidas = [];
            /** @var array<int, string> */
            public array $recusas = [];
        };
        $registro = $this->abono;
        $duble = new class ($registro) implements AbonoDeJustificativa {
            public function __construct(private readonly object $registro)
            {
            }

            public function abonar(JustificativaPonto $justificativa, Tenant $escritorio, User $analista): ?string
            {
                $recusa = $this->registro->recusas[(int) $justificativa->getId()] ?? null;
                if ($recusa === null) {
                    $this->registro->recebidas[] = (int) $justificativa->getId();
                }

                return $recusa;
            }

            public function abonarDaEquipe(array $daEquipe, Tenant $escritorio, User $analista): array
            {
                $recusas = [];
                foreach ($daEquipe as $justificativa) {
                    if (($recusa = $this->abonar($justificativa, $escritorio, $analista)) !== null) {
                        $recusas[(int) $justificativa->getId()] = $recusa;
                    }
                }

                return $recusas;
            }
        };

        $container = static::getContainer();
        $vinculos = $container->get(UserTenantRepository::class);
        $container->set(AbonarJustificativasDaEquipeUseCase::class, new AbonarJustificativasDaEquipeUseCase(
            $container->get(JustificativaPontoRepository::class),
            $vinculos,
            new AnaliseDaPropriaJustificativa(new RegraDosPrimarios($vinculos)),
            $duble,
        ));
    }
}
