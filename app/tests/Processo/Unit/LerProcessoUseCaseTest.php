<?php

declare(strict_types=1);

namespace App\Tests\Processo\Unit;

use App\Entity\Auth\User;
use App\Entity\Permission\AccessRequest;
use App\Entity\Tenant\Tenant;
use App\Pasta\Repository\CadeadoDaPastaRepository;
use App\Pasta\Repository\PastaRepository;
use App\Pasta\Service\AcessoAPasta;
use App\Pasta\Service\NivelNoCadeado;
use App\Processo\Entity\MovimentacaoProcesso;
use App\Processo\Entity\ParteProcesso;
use App\Processo\Entity\Processo;
use App\Processo\Exception\ProcessoEmSegredoDeJusticaException;
use App\Processo\Exception\ProcessoNaoEncontradoException;
use App\Processo\Repository\MovimentacaoProcessoRepository;
use App\Processo\Repository\NotaTecnicaRepository;
use App\Processo\Repository\ProcessoRepository;
use App\Processo\UseCase\LerProcessoUseCase;
use App\Repository\UserTenantRepository;
use App\Service\PermissionChecker;
use App\Service\Tenant\TenantContext;
use App\Tenant\Service\RegraDosPrimarios;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

#[CoversClass(LerProcessoUseCase::class)]
final class LerProcessoUseCaseTest extends TestCase
{
    private ProcessoRepository&MockObject $processos;
    private MovimentacaoProcessoRepository&MockObject $movimentacoes;
    private NotaTecnicaRepository&MockObject $notas;
    private PastaRepository&MockObject $pastas;
    private CadeadoDaPastaRepository&MockObject $cadeados;
    private PermissionChecker&MockObject $permissoes;
    private LerProcessoUseCase $useCase;
    private Tenant $escritorio;
    private User $pessoa;

    protected function setUp(): void
    {
        $this->processos = $this->createMock(ProcessoRepository::class);
        $this->movimentacoes = $this->createMock(MovimentacaoProcessoRepository::class);
        $this->notas = $this->createMock(NotaTecnicaRepository::class);
        $this->pastas = $this->createMock(PastaRepository::class);
        $this->cadeados = $this->createMock(CadeadoDaPastaRepository::class);
        $this->permissoes = $this->createMock(PermissionChecker::class);
        $vinculos = $this->createStub(UserTenantRepository::class);
        // O AcessoAPasta e o NivelNoCadeado são finais: valem os de verdade, com o repositório do cadeado de dublê.
        $acesso = new AcessoAPasta(
            new NivelNoCadeado($this->cadeados, $vinculos, new RegraDosPrimarios($vinculos), (new \ReflectionClass(TenantContext::class))->newInstanceWithoutConstructor()),
            $this->permissoes,
        );
        $this->useCase = new LerProcessoUseCase($this->processos, $this->movimentacoes, $this->notas, $this->pastas, $acesso, $this->permissoes);
        $this->escritorio = new Tenant();
        $this->pessoa = new User();
    }

    #[TestDox('lê o processo do escritório com partes, uma página de movimentações, notas e pastas, e diz se há mais movimentações')]
    public function testLeOProcesso(): void
    {
        $processo = $this->processo(7)->addParte((new ParteProcesso())->setTipo('FISICA')->setNome('Ana')->setDocumento('12345678909'));
        $this->processos->expects(self::once())->method('findOneByIdDoTenant')->with(7, $this->escritorio)->willReturn($processo);
        $this->cadeados->method('processosSoDestasPastas')->willReturn([]);
        $this->permissoes->method('canAccessResource')->with($this->pessoa, $this->escritorio, AccessRequest::RESOURCE_PROCESSO, 7, AccessRequest::ACTION_VIEW)->willReturn(true);
        $linhas = array_map(fn (int $i): MovimentacaoProcesso => (new MovimentacaoProcesso())->setDescricao('M' . $i), range(1, LerProcessoUseCase::MOVIMENTACOES_POR_PAGINA + 1));
        $this->movimentacoes->expects(self::once())->method('paginaDoProcesso')->with($processo, $this->escritorio, 2, LerProcessoUseCase::MOVIMENTACOES_POR_PAGINA)->willReturn($linhas);
        $this->notas->expects(self::once())->method('listarPorProcesso')->with($processo, $this->escritorio, LerProcessoUseCase::LIMITE_DE_NOTAS)->willReturn([]);
        $this->pastas->expects(self::once())->method('listarVinculadasAoProcesso')->with($processo, $this->escritorio, $this->pessoa)->willReturn([]);

        $saida = $this->useCase->executar(7, 2, $this->pessoa, $this->escritorio);

        self::assertSame(7, $saida->id);
        self::assertSame([['tipo' => 'FISICA', 'papel' => null, 'nome' => 'ANA']], $saida->partes, 'o documento da parte não entra');
        self::assertCount(LerProcessoUseCase::MOVIMENTACOES_POR_PAGINA, $saida->movimentacoes);
        self::assertTrue($saida->haMaisMovimentacoes);
        self::assertSame(2, $saida->paginaDasMovimentacoes);
    }

    #[TestDox('processo de outro escritório ou inexistente: não encontrado, sem consultar nada além da busca por escritório')]
    public function testOutroEscritorioOuInexistente(): void
    {
        $this->processos->method('findOneByIdDoTenant')->willReturn(null);
        $this->movimentacoes->expects(self::never())->method('paginaDoProcesso');
        $this->cadeados->expects(self::never())->method('processosSoDestasPastas');

        $this->expectException(ProcessoNaoEncontradoException::class);
        $this->useCase->executar(7, 1, $this->pessoa, $this->escritorio);
    }

    #[TestDox('processo oculto pelo cadeado: não encontrado, e o recurso de ver nem é perguntado (a ordem da tela)')]
    public function testOcultoPeloCadeado(): void
    {
        $this->processos->method('findOneByIdDoTenant')->willReturn($this->processo(7));
        $this->cadeados->method('processosSoDestasPastas')->willReturn([3, 7]);
        $this->permissoes->expects(self::never())->method('canAccessResource');

        $this->expectException(ProcessoNaoEncontradoException::class);
        $this->useCase->executar(7, 1, $this->pessoa, $this->escritorio);
    }

    #[TestDox('sem o recurso de ver o processo: não encontrado, mesmo em segredo')]
    public function testSemOReCursoDeVer(): void
    {
        $this->processos->method('findOneByIdDoTenant')->willReturn($this->processo(7)->setNivelSigilo(1));
        $this->cadeados->method('processosSoDestasPastas')->willReturn([]);
        $this->permissoes->method('canAccessResource')->willReturn(false);

        $this->expectException(ProcessoNaoEncontradoException::class);
        $this->useCase->executar(7, 1, $this->pessoa, $this->escritorio);
    }

    #[TestDox('processo em segredo de justiça (do tribunal): recusa, sem ler movimentações, notas nem pastas')]
    public function testEmSegredo(): void
    {
        $this->processos->method('findOneByIdDoTenant')->willReturn($this->processo(7)->setNivelSigilo(2));
        $this->cadeados->method('processosSoDestasPastas')->willReturn([]);
        $this->permissoes->method('canAccessResource')->willReturn(true);
        $this->movimentacoes->expects(self::never())->method('paginaDoProcesso');
        $this->notas->expects(self::never())->method('listarPorProcesso');
        $this->pastas->expects(self::never())->method('listarVinculadasAoProcesso');

        $this->expectException(ProcessoEmSegredoDeJusticaException::class);
        $this->useCase->executar(7, 1, $this->pessoa, $this->escritorio);
    }

    private function processo(int $id): Processo
    {
        $processo = (new Processo())->setTenant($this->escritorio)->setNumeroProcesso('07011345720258070007');
        (new \ReflectionProperty(Processo::class, 'id'))->setValue($processo, $id);

        return $processo;
    }
}
