<?php

declare(strict_types=1);

namespace App\Tests\Pasta\Unit;

use App\Entity\Auth\User;
use App\Entity\Auth\UserTenant;
use App\Entity\Tenant\Tenant;
use App\Entity\Tenant\TenantRole;
use App\Pasta\DTO\GrupoDaLixeiraDoEscritorio;
use App\Pasta\Entity\AbaDaPasta;
use App\Pasta\Entity\AbaOcultaDaPasta;
use App\Pasta\Entity\Pasta;
use App\Pasta\Entity\PastaDocumento;
use App\Pasta\Repository\AbaOcultaDaPastaRepository;
use App\Pasta\Repository\CadeadoDaPastaRepository;
use App\Pasta\Repository\PastaDocumentoRepository;
use App\Pasta\Repository\PastaRepository;
use App\Pasta\Repository\PastaSecaoRepository;
use App\Pasta\Service\AcessoAPasta;
use App\Pasta\Service\EquipeQuePodeTerAbaOculta;
use App\Pasta\Service\NivelNoCadeado;
use App\Pasta\Service\VisibilidadeDasAbasDaPasta;
use App\Pasta\UseCase\ListarLixeiraDoEscritorioUseCase;
use App\Pasta\UseCase\ResumoDaLixeiraDaPasta;
use App\Repository\UserRepository;
use App\Repository\UserTenantRepository;
use App\Service\PermissionChecker;
use App\Service\Tenant\TenantContext;
use App\Shared\Doctrine\Filter\AcessoALixeira;
use App\Tenant\Repository\PedidoDeExclusaoDeContaRepository;
use App\Tenant\Service\BloqueioPorPedidoDeExclusao;
use App\Tenant\Service\RegraDosPrimarios;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * A lixeira do escritório: como as linhas da agregação viram grupos para quem lê. Permissão por
 * pasta (EDITAR), aba Documentos oculta, contrato de honorários com o Financeiro oculto, ordem e
 * página. A consulta em si (GROUP BY, tenant, `excluido_em`) é provada com banco no funcional
 * `Expediente/Functional/LixeiraDoEscritorioControllerTest`.
 */
#[CoversClass(ListarLixeiraDoEscritorioUseCase::class)]
#[CoversClass(ResumoDaLixeiraDaPasta::class)]
#[CoversClass(GrupoDaLixeiraDoEscritorio::class)]
final class ListarLixeiraDoEscritorioUseCaseTest extends TestCase
{
    private Tenant $tenant;
    private User $quem;

    /** @var array<int, Pasta> */
    private array $pastas = [];

    /** @var list<array<string, mixed>> */
    private array $linhasDocumentos = [];

    /** @var list<array<string, mixed>> */
    private array $linhasSecoes = [];

    /** @var list<int> pastas em que `$quem` pode EDITAR */
    private array $editaveis = [];

    /** @var array<int, list<AbaOcultaDaPasta>> */
    private array $regras = [];

    /** @var array<int, User> */
    private array $usuarios = [];

    protected function setUp(): void
    {
        $this->tenant = new Tenant();
        self::definirId($this->tenant, 7);
        $this->quem = self::usuario(10, 'Quem Lê');
    }

    #[TestDox('soma documentos e subpastas da pasta, com a data mais antiga e a mais recente e os nomes distintos de quem excluiu')]
    public function testContasDoGrupo(): void
    {
        $this->pasta(1, 'P-1', 'MARIA');
        $this->editaveis = [1];
        $this->usuarios  = [20 => self::usuario(20, 'Bruno'), 21 => self::usuario(21, 'Ana')];
        $this->doc(1, PastaDocumento::CATEGORIA_DEMAIS, 20, 3, '2026-10-01 09:00:00', '2026-10-05 10:00:00');
        $this->doc(1, PastaDocumento::CATEGORIA_DEMAIS, 21, 2, '2026-09-30 08:00:00', '2026-10-02 10:00:00');
        $this->secao(1, 20, 1, '2026-10-05 10:00:00', '2026-10-05 10:00:00');

        $saida = $this->useCase()->executar($this->quem, $this->tenant);

        self::assertCount(1, $saida->grupos);
        $g = $saida->grupos[0];
        self::assertSame([1, 'P-1', 'MARIA', 5, 1], [$g->pastaId, $g->nup, $g->nome, $g->documentos, $g->subpastas]);
        self::assertSame('2026-09-30 08:00:00', $g->maisAntiga->format('Y-m-d H:i:s'));
        self::assertSame('2026-10-05 10:00:00', $g->maisRecente->format('Y-m-d H:i:s'));
        self::assertSame(['Ana', 'Bruno'], $g->excluidoPor);
        self::assertFalse($g->autorSemRegistro);
        self::assertNull($saida->proximo);
    }

    #[TestDox('pasta em que quem lê não pode editar não aparece')]
    public function testSemPermissaoNaoAparece(): void
    {
        $this->pasta(1, 'P-1');
        $this->pasta(2, 'P-2');
        $this->editaveis = [2];
        $this->doc(1, PastaDocumento::CATEGORIA_DEMAIS, null, 1, '2026-10-01 09:00:00', '2026-10-01 09:00:00');
        $this->doc(2, PastaDocumento::CATEGORIA_DEMAIS, null, 1, '2026-10-01 08:00:00', '2026-10-01 08:00:00');

        $grupos = $this->useCase()->executar($this->quem, $this->tenant)->grupos;

        self::assertSame([2], array_map(static fn (GrupoDaLixeiraDoEscritorio $g): int => $g->pastaId, $grupos));
        self::assertTrue($grupos[0]->autorSemRegistro, 'autor apagado: sem registro');
        self::assertSame([], $grupos[0]->excluidoPor);
    }

    #[TestDox('contrato de honorários com o Financeiro oculto não conta; pasta só com ele não aparece')]
    public function testContratoOcultoNaoConta(): void
    {
        $this->pasta(1, 'P-1');
        $this->pasta(2, 'P-2');
        $this->editaveis = [1, 2];
        $this->usuarios  = [20 => self::usuario(20, 'Bruno'), 21 => self::usuario(21, 'Carla')];
        $this->doc(1, PastaDocumento::CATEGORIA_DEMAIS, 20, 2, '2026-10-01 09:00:00', '2026-10-01 09:00:00');
        $this->doc(1, PastaDocumento::CATEGORIA_CONTRATO, 21, 1, '2026-10-09 09:00:00', '2026-10-09 09:00:00');
        $this->doc(2, PastaDocumento::CATEGORIA_CONTRATO, 21, 1, '2026-10-08 09:00:00', '2026-10-08 09:00:00');
        $this->ocultar(1, AbaDaPasta::Financeiro);
        $this->ocultar(2, AbaDaPasta::Financeiro);

        $grupos = $this->useCase()->executar($this->quem, $this->tenant)->grupos;

        self::assertCount(1, $grupos);
        self::assertSame([1, 2], [$grupos[0]->pastaId, $grupos[0]->documentos]);
        self::assertSame('2026-10-01 09:00:00', $grupos[0]->maisRecente->format('Y-m-d H:i:s'), 'a data do contrato oculto não aparece');
        self::assertSame(['Bruno'], $grupos[0]->excluidoPor, 'quem só excluiu o contrato oculto não aparece');
    }

    #[TestDox('com o Financeiro visível o contrato conta normalmente')]
    public function testContratoVisivelConta(): void
    {
        $this->pasta(1, 'P-1');
        $this->editaveis = [1];
        $this->doc(1, PastaDocumento::CATEGORIA_CONTRATO, null, 1, '2026-10-09 09:00:00', '2026-10-09 09:00:00');

        self::assertSame(1, $this->useCase()->executar($this->quem, $this->tenant)->grupos[0]->documentos);
    }

    #[TestDox('aba Documentos oculta: a pasta não aparece (as rotas da lixeira recusariam)')]
    public function testDocumentosOcultaNaoAparece(): void
    {
        $this->pasta(1, 'P-1');
        $this->editaveis = [1];
        $this->doc(1, PastaDocumento::CATEGORIA_DEMAIS, null, 1, '2026-10-01 09:00:00', '2026-10-01 09:00:00');
        $this->ocultar(1, AbaDaPasta::Documentos);

        self::assertSame([], $this->useCase()->executar($this->quem, $this->tenant)->grupos);
    }

    #[TestDox('pasta que não é deste escritório (o repositório não a devolve) não aparece')]
    public function testPastaQueNaoVoltaDoRepositorio(): void
    {
        $this->editaveis = [9];
        $this->doc(9, PastaDocumento::CATEGORIA_DEMAIS, null, 1, '2026-10-01 09:00:00', '2026-10-01 09:00:00');

        self::assertSame([], $this->useCase()->executar($this->quem, $this->tenant)->grupos);
    }

    #[TestDox('da exclusão mais recente para a mais antiga, em páginas: `proximo` continua de onde parou')]
    public function testOrdemEPagina(): void
    {
        $total = ListarLixeiraDoEscritorioUseCase::PASTAS_POR_PEDIDO + 2;
        for ($id = 1; $id <= $total; ++$id) {
            $this->pasta($id, 'P-' . $id);
            $this->editaveis[] = $id;
            $quando = (new \DateTimeImmutable('2026-10-01 00:00:00'))->modify('+' . $id . ' hours')->format('Y-m-d H:i:s');
            $this->secao($id, null, 1, $quando, $quando);
        }

        $primeira = $this->useCase()->executar($this->quem, $this->tenant);
        self::assertCount(ListarLixeiraDoEscritorioUseCase::PASTAS_POR_PEDIDO, $primeira->grupos);
        self::assertSame($total, $primeira->grupos[0]->pastaId, 'a mais recente primeiro');
        self::assertSame(ListarLixeiraDoEscritorioUseCase::PASTAS_POR_PEDIDO, $primeira->proximo);

        $segunda = $this->useCase()->executar($this->quem, $this->tenant, (int) $primeira->proximo);
        self::assertSame([2, 1], array_map(static fn (GrupoDaLixeiraDoEscritorio $g): int => $g->pastaId, $segunda->grupos));
        self::assertNull($segunda->proximo);
    }

    #[TestDox('pasta excluída (lápide) aparece marcada')]
    public function testLapide(): void
    {
        $pasta = $this->pasta(1, 'P-1');
        $pasta->marcarExcluida($this->quem, new \DateTimeImmutable('2026-10-01'));
        $this->editaveis = [1];
        $this->doc(1, PastaDocumento::CATEGORIA_DEMAIS, null, 1, '2026-10-01 09:00:00', '2026-10-01 09:00:00');

        self::assertTrue($this->useCase()->executar($this->quem, $this->tenant)->grupos[0]->pastaExcluida);
    }

    // ── montagem ───────────────────────────────────────────────────────────────

    private function useCase(): ListarLixeiraDoEscritorioUseCase
    {
        $documentos = $this->createStub(PastaDocumentoRepository::class);
        $documentos->method('resumoDaLixeiraDoEscritorio')->willReturnCallback(fn (): array => $this->linhasDocumentos);
        $secoes = $this->createStub(PastaSecaoRepository::class);
        $secoes->method('resumoDaLixeiraDoEscritorio')->willReturnCallback(fn (): array => $this->linhasSecoes);
        $pastas = $this->createStub(PastaRepository::class);
        $pastas->method('findBy')->willReturnCallback(fn (array $c): array => array_values(array_filter($this->pastas, static fn (Pasta $p): bool => \in_array($p->getId(), $c['id'], true))));
        $usuarios = $this->createStub(UserRepository::class);
        $usuarios->method('findBy')->willReturnCallback(fn (array $c): array => array_values(array_filter($this->usuarios, static fn (User $u): bool => \in_array($u->getId(), $c['id'], true))));

        $vinculos = $this->createStub(UserTenantRepository::class);
        $vinculos->method('findAtivoPorUserETenant')->willReturn($this->vinculoComum());
        $permissoes = $this->createStub(PermissionChecker::class);
        $permissoes->method('canAccessResourceComVinculo')->willReturnCallback(fn (User $u, UserTenant $v, string $tipo, int $id, string $acao): bool => $acao === 'edit' && \in_array($id, $this->editaveis, true));

        $regraDosPrimarios = new RegraDosPrimarios($vinculos);
        $regras = $this->createStub(AbaOcultaDaPastaRepository::class);
        $regras->method('daPasta')->willReturnCallback(fn (Pasta $p): array => $this->regras[(int) $p->getId()] ?? []);
        $abas = new VisibilidadeDasAbasDaPasta($regras, $regraDosPrimarios, new EquipeQuePodeTerAbaOculta($vinculos, $regraDosPrimarios));

        return new ListarLixeiraDoEscritorioUseCase(
            $documentos,
            $secoes,
            $pastas,
            $usuarios,
            $vinculos,
            $permissoes,
            new AcessoAPasta($this->nivelNoCadeado($vinculos, $regraDosPrimarios), $permissoes),
            $abas,
            new AcessoALixeira($this->createStub(EntityManagerInterface::class)),
        );
    }

    private function nivelNoCadeado(UserTenantRepository $vinculos, RegraDosPrimarios $regra): NivelNoCadeado
    {
        $cadeados = $this->createStub(CadeadoDaPastaRepository::class);
        $cadeados->method('dasPastas')->willReturn([]);
        $contexto = new TenantContext(new RequestStack(), $this->createStub(Security::class), $this->createStub(EntityManagerInterface::class), new BloqueioPorPedidoDeExclusao($this->createStub(PedidoDeExclusaoDeContaRepository::class), $regra));

        return new NivelNoCadeado($cadeados, $vinculos, $regra, $contexto);
    }

    /** Vínculo ativo com papel comum (não-sistema): nem Master, nem primário. */
    private function vinculoComum(): UserTenant
    {
        $papel = new TenantRole();
        $papel->setTenant($this->tenant);
        $papel->setName('Colaborador');
        $papel->setIsSystem(false);
        $vinculo = new UserTenant($this->quem, $this->tenant);
        $vinculo->setTenantRole($papel);

        return $vinculo;
    }

    private function pasta(int $id, string $nup, ?string $cliente = null): Pasta
    {
        $pasta = (new Pasta())->setTenant($this->tenant);
        $pasta->setNup($nup);
        $pasta->setNomeCliente($cliente);
        self::definirId($pasta, $id);

        return $this->pastas[$id] = $pasta;
    }

    private function doc(int $pastaId, string $categoria, ?int $autorId, int $total, string $antiga, string $recente): void
    {
        $this->linhasDocumentos[] = ['pastaId' => $pastaId, 'categoria' => $categoria, 'autorId' => $autorId, 'total' => $total, 'maisAntiga' => $antiga, 'maisRecente' => $recente];
    }

    private function secao(int $pastaId, ?int $autorId, int $total, string $antiga, string $recente): void
    {
        $this->linhasSecoes[] = ['pastaId' => $pastaId, 'autorId' => $autorId, 'total' => $total, 'maisAntiga' => $antiga, 'maisRecente' => $recente];
    }

    private function ocultar(int $pastaId, AbaDaPasta $aba): void
    {
        $this->regras[$pastaId][] = AbaOcultaDaPasta::paraTodaAEquipe($this->tenant, $this->pastas[$pastaId], $aba, $this->quem, new \DateTimeImmutable());
    }

    private static function usuario(int $id, string $nome): User
    {
        $user = (new User())->setEmail('u' . $id . '@test.com');
        $user->setFullName($nome);
        $user->setIsActive(true);
        self::definirId($user, $id);

        return $user;
    }

    private static function definirId(object $entidade, int $id): void
    {
        (new \ReflectionProperty($entidade, 'id'))->setValue($entidade, $id);
    }
}
