<?php

declare(strict_types=1);

namespace App\Tests\Conector\Unit;

use App\Conector\DTO\ChamadaDoConector;
use App\Conector\DTO\ContextoDoConector;
use App\Conector\Exception\ErroDaFerramenta;
use App\Conector\Ferramenta\TabelaDeAbaEChave;
use App\Conector\Service\GuardaDaAcaoDoConector;
use App\Entity\Auth\User;
use App\Entity\Auth\UserTenant;
use App\Entity\Tenant\Tenant;
use App\Master\Entity\RecursoEstado;
use App\Master\Repository\ContasDoSistemaRepository;
use App\Master\Repository\RecursoEstadoRepository;
use App\Master\Service\RecursosDoSistema;
use App\Master\Service\RegraDosMasters;
use App\Pasta\Entity\AbaDaPasta;
use App\Pasta\Entity\AbaOcultaDaPasta;
use App\Pasta\Entity\Pasta;
use App\Pasta\Repository\AbaOcultaDaPastaRepository;
use App\Pasta\Service\AcessoAPasta;
use App\Pasta\Service\EscopoDePastas;
use App\Pasta\Service\EquipeQuePodeTerAbaOculta;
use App\Pasta\Service\NivelNoCadeado;
use App\Pasta\Service\VisibilidadeDasAbasDaPasta;
use App\Repository\UserTenantRepository;
use App\Service\PermissionChecker;
use App\Tenant\Service\RegraDosPrimarios;
use App\Tests\Inteligencia\Support\DefineId;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * A guarda da ação do conector pela tabela da 6.6 (spec mcp-remoto-oauth 6.2, regras 1, 2 e 11): ação fora da tabela,
 * chave do Master desligada (também para o Master), módulo que falta, pasta de outro escritório ou que a pessoa não vê,
 * aba oculta e o caminho que passa. Os serviços finais (chaves, abas, cadeado) são os de verdade, com o banco trocado
 * por dublês dos repositórios.
 */
#[CoversClass(GuardaDaAcaoDoConector::class)]
final class GuardaDaAcaoDoConectorTest extends TestCase
{
    private Tenant $escritorio;
    private User $pessoa;
    private Pasta $pasta;

    /** @var list<string> */
    private array $modulos = ['pastas', 'expediente', 'agenda', 'tarefas', 'processos', 'clientes'];
    /** @var array<string, RecursoEstado> */
    private array $chavesGravadas = [];
    /** @var list<AbaOcultaDaPasta> */
    private array $regrasDeAba = [];
    private bool $vePasta = true;
    /** @var list<array{string, string}> */
    private array $modulosPerguntados = [];
    private ?User $logadoNoNavegador = null;

    protected function setUp(): void
    {
        $this->escritorio = (new Tenant())->setName('Escritório da Guarda');
        DefineId::em($this->escritorio, 10);
        $this->pessoa = (new User())->setFullName('Pessoa da Guarda');
        DefineId::em($this->pessoa, 20);
        $this->pasta = new Pasta();
        $this->pasta->setTenant($this->escritorio);
        DefineId::em($this->pasta, 30);
    }

    #[TestDox('tudo ok: chave ligada, módulo, pasta visível e aba à mostra passam sem exceção')]
    public function testTudoOk(): void
    {
        $this->guarda()->exigir($this->chamada(), 'ler_pasta', 'prazos', $this->pasta);
        $this->guarda()->exigir($this->chamada(), 'buscar_pastas', 'buscar');

        self::assertContains(['expediente', '10'], $this->modulosPerguntados, 'o módulo é perguntado com a pessoa e o escritório da chamada');
    }

    #[TestDox('ação ou ferramenta fora da tabela: pedido inválido, antes de qualquer outra conferência')]
    public function testForaDaTabela(): void
    {
        $this->recusa(fn () => $this->guarda()->exigir($this->chamada(), 'ler_pasta', 'timeline', $this->pasta), ErroDaFerramenta::MOTIVO_ENTRADA_INVALIDA);
        $this->recusa(fn () => $this->guarda()->exigir($this->chamada(), 'consultar_sql', 'unica'), ErroDaFerramenta::MOTIVO_ENTRADA_INVALIDA);
        self::assertSame([], $this->modulosPerguntados);
    }

    #[TestDox('módulo negado: sem permissão, com a mensagem fixa')]
    public function testModuloNegado(): void
    {
        $this->modulos = ['expediente'];

        $erro = $this->recusa(fn () => $this->guarda()->exigir($this->chamada(), 'buscar_pastas', 'minhas_demandas'), ErroDaFerramenta::MOTIVO_SEM_PERMISSAO);

        self::assertSame(ErroDaFerramenta::SEM_PERMISSAO, $erro->getMessage());
    }

    #[TestDox('ler a pasta não pede módulo, como a tela da pasta: basta poder ver a pasta e a aba')]
    public function testLerPastaSemModulo(): void
    {
        $this->modulos = [];

        $this->guarda()->exigir($this->chamada(), 'ler_pasta', 'resumo', $this->pasta);

        self::assertSame([], $this->modulosPerguntados, 'nenhum módulo é perguntado para ler a pasta');
    }

    #[TestDox('módulo: basta um dos módulos da linha (criar pasta abre com expediente ou pastas)')]
    public function testBastaUmModulo(): void
    {
        $this->modulos = ['expediente'];

        $this->guarda()->exigir($this->chamada(), 'atualizar_pasta', 'criar');
        $this->recusa(fn () => $this->guarda()->exigir($this->chamada(), 'buscar_pastas', 'minhas_demandas'), ErroDaFerramenta::MOTIVO_SEM_PERMISSAO);
    }

    #[TestDox('chave do Master desligada: função desligada, mesmo para o Master logado (estaAtivo, nunca visivel)')]
    public function testChaveDesligadaTambemParaOMaster(): void
    {
        $this->desligar('expedientes');
        $this->logadoNoNavegador = (new User())->setRoles([RegraDosMasters::PAPEL_MASTER]);
        self::assertTrue($this->recursos()->visivel('expedientes'), 'premissa: para o Master a chave desligada continua visível na tela');

        $erro = $this->recusa(fn () => $this->guarda()->exigir($this->chamada(), 'ler_pasta', 'resumo', $this->pasta), ErroDaFerramenta::MOTIVO_FUNCAO_DESLIGADA);

        self::assertSame(ErroDaFerramenta::FUNCAO_DESLIGADA, $erro->getMessage());
    }

    #[TestDox('chave desligada recusa antes do módulo e da pasta: nada diz se a pasta existe')]
    public function testChaveAntesDaPasta(): void
    {
        $this->desligar('metas');
        $this->vePasta = false;

        $this->recusa(fn () => $this->guarda()->exigir($this->chamada(), 'ler_agenda', 'minhas_metas'), ErroDaFerramenta::MOTIVO_FUNCAO_DESLIGADA);
        $this->guarda()->exigir($this->chamada(), 'ler_agenda', 'agenda');
    }

    #[TestDox('chave só com pasta: atualizar_cliente sem pasta passa com expedientes desligada; com pasta, recusa')]
    public function testChaveSoComPasta(): void
    {
        $this->desligar('expedientes');

        $this->guarda()->exigir($this->chamada(), 'atualizar_cliente', 'completar');
        $this->recusa(fn () => $this->guarda()->exigir($this->chamada(), 'atualizar_cliente', 'completar', $this->pasta), ErroDaFerramenta::MOTIVO_FUNCAO_DESLIGADA);
    }

    #[TestDox('pasta que a pessoa não pode ver (cadeado ou recurso): não encontrado')]
    public function testPastaQueNaoPodeVer(): void
    {
        $this->vePasta = false;

        $erro = $this->recusa(fn () => $this->guarda()->exigir($this->chamada(), 'ler_pasta', 'resumo', $this->pasta), ErroDaFerramenta::MOTIVO_NAO_ENCONTRADO);

        self::assertSame(ErroDaFerramenta::NAO_ENCONTRADO, $erro->getMessage());
    }

    #[TestDox('pasta de outro escritório: não encontrado, mesmo que o recurso respondesse sim')]
    public function testPastaDeOutroEscritorio(): void
    {
        $outro = (new Tenant())->setName('Outro');
        DefineId::em($outro, 11);
        $alheia = new Pasta();
        $alheia->setTenant($outro);
        DefineId::em($alheia, 31);

        $this->recusa(fn () => $this->guarda()->exigir($this->chamada(), 'ler_pasta', 'resumo', $alheia), ErroDaFerramenta::MOTIVO_NAO_ENCONTRADO);
    }

    #[TestDox('aba oculta para a pessoa: não encontrado na parte que vem dela; a parte de outra aba passa')]
    public function testAbaOculta(): void
    {
        $this->regrasDeAba = [AbaOcultaDaPasta::paraTodaAEquipe($this->escritorio, $this->pasta, AbaDaPasta::Financeiro, new User(), new \DateTimeImmutable())];

        $this->recusa(fn () => $this->guarda()->exigir($this->chamada(), 'ler_pasta', 'financeiro', $this->pasta), ErroDaFerramenta::MOTIVO_NAO_ENCONTRADO);
        $this->guarda()->exigir($this->chamada(), 'ler_pasta', 'prazos', $this->pasta);
    }

    #[TestDox('linha com duas abas (usar_modelo: Detalhes e Documentos): basta uma oculta para recusar')]
    public function testDuasAbas(): void
    {
        $this->regrasDeAba = [AbaOcultaDaPasta::paraTodaAEquipe($this->escritorio, $this->pasta, AbaDaPasta::Documentos, new User(), new \DateTimeImmutable())];

        $this->recusa(fn () => $this->guarda()->exigir($this->chamada(), 'documentos_e_pecas', 'usar_modelo', $this->pasta), ErroDaFerramenta::MOTIVO_NAO_ENCONTRADO);
        $this->guarda()->exigir($this->chamada(), 'documentos_e_pecas', 'salvar_modelo', $this->pasta);
    }

    /**
     * 12.9: a tabela da 6.6 linha por linha. Com tudo liberado, cada ação passa (com pasta), o que prova que a recusa dos
     * dois testes seguintes vem da aba ou da chave, e não de outra conferência.
     */
    #[DataProvider('linhasDaTabela')]
    #[TestDox('12.9: $ferramenta $acao passa com tudo liberado')]
    public function testCadaLinhaPassaComTudoLiberado(string $ferramenta, string $acao): void
    {
        $this->guarda()->exigir($this->chamada(), $ferramenta, $acao, $this->pasta);
        $this->addToAssertionCount(1);
    }

    #[DataProvider('abasDaTabela')]
    #[TestDox('12.9: $ferramenta $acao recusa com a aba $aba oculta')]
    public function testCadaLinhaRecusaComAAbaOculta(string $ferramenta, string $acao, string $aba): void
    {
        $this->regrasDeAba = [AbaOcultaDaPasta::paraTodaAEquipe($this->escritorio, $this->pasta, AbaDaPasta::from($aba), new User(), new \DateTimeImmutable())];

        $this->recusa(fn () => $this->guarda()->exigir($this->chamada(), $ferramenta, $acao, $this->pasta), ErroDaFerramenta::MOTIVO_NAO_ENCONTRADO);
    }

    #[DataProvider('chavesDaTabela')]
    #[TestDox('12.9: $ferramenta $acao recusa com a chave $chave desligada')]
    public function testCadaLinhaRecusaComAChaveDesligada(string $ferramenta, string $acao, string $chave): void
    {
        $this->desligar($chave);

        $this->recusa(fn () => $this->guarda()->exigir($this->chamada(), $ferramenta, $acao, $this->pasta), ErroDaFerramenta::MOTIVO_FUNCAO_DESLIGADA);
    }

    /** @return iterable<string, array{string, string}> */
    public static function linhasDaTabela(): iterable
    {
        foreach (array_keys(TabelaDeAbaEChave::LINHAS) as $ferramenta) {
            foreach (TabelaDeAbaEChave::acoes($ferramenta) as $acao) {
                yield $ferramenta . ' ' . $acao => [$ferramenta, $acao];
            }
        }
    }

    /** @return iterable<string, array{string, string, string}> */
    public static function abasDaTabela(): iterable
    {
        foreach (array_keys(TabelaDeAbaEChave::LINHAS) as $ferramenta) {
            foreach (TabelaDeAbaEChave::linhasDa($ferramenta) as $acao => $linha) {
                foreach ($linha->abas as $aba) {
                    yield $ferramenta . ' ' . $acao . ' ' . $aba->value => [$ferramenta, $acao, $aba->value];
                }
            }
        }
    }

    /** @return iterable<string, array{string, string, string}> */
    public static function chavesDaTabela(): iterable
    {
        foreach (array_keys(TabelaDeAbaEChave::LINHAS) as $ferramenta) {
            foreach (TabelaDeAbaEChave::linhasDa($ferramenta) as $acao => $linha) {
                foreach ($linha->chavesExigidas(true) as $chave) {
                    yield $ferramenta . ' ' . $acao . ' ' . $chave => [$ferramenta, $acao, $chave];
                }
            }
        }
    }

    private function recusa(\Closure $chamada, string $motivo): ErroDaFerramenta
    {
        try {
            $chamada();
        } catch (ErroDaFerramenta $erro) {
            self::assertSame($motivo, $erro->motivo);

            return $erro;
        }
        self::fail('esperava a recusa ' . $motivo);
    }

    private function desligar(string $chave): void
    {
        $estado = new RecursoEstado($chave);
        $estado->desativar(null, null, null, new \DateTimeImmutable('2026-11-01'));
        $this->chavesGravadas[$chave] = $estado;
    }

    private function chamada(): ChamadaDoConector
    {
        return new ChamadaDoConector(
            new ContextoDoConector($this->pessoa, $this->escritorio, new UserTenant($this->pessoa, $this->escritorio)),
            concessaoId: 1,
            consentimentoId: 2,
            hostDoCliente: 'claude.ai',
            escopos: ['mcp:leitura'],
            conexaoValeAte: new \DateTimeImmutable('2026-11-23 10:00:00'),
        );
    }

    private function recursos(): RecursosDoSistema
    {
        $estados = $this->createStub(RecursoEstadoRepository::class);
        $estados->method('todosPorChave')->willReturnCallback(fn (): array => $this->chavesGravadas);
        $security = $this->createStub(Security::class);
        $security->method('getUser')->willReturnCallback(fn (): ?User => $this->logadoNoNavegador);

        return new RecursosDoSistema(
            $estados,
            new MockClock('2026-11-16 10:00:00'),
            $security,
            new RegraDosMasters($this->createStub(ContasDoSistemaRepository::class)),
            new RequestStack(),
            new NullLogger(),
        );
    }

    private function guarda(): GuardaDaAcaoDoConector
    {
        $permissoes = $this->createStub(PermissionChecker::class);
        $permissoes->method('canAccessModule')->willReturnCallback(function (User $quem, ?Tenant $onde, string $modulo): bool {
            $this->modulosPerguntados[] = [$modulo, (string) $onde?->getId()];

            return $quem === $this->pessoa && \in_array($modulo, $this->modulos, true);
        });
        $permissoes->method('canAccessResource')->willReturnCallback(fn (): bool => $this->vePasta);

        $regras = $this->createStub(AbaOcultaDaPastaRepository::class);
        $regras->method('daPasta')->willReturnCallback(fn (): array => $this->regrasDeAba);
        $vinculos = $this->createStub(UserTenantRepository::class);
        $primarios = new RegraDosPrimarios($vinculos);

        return new GuardaDaAcaoDoConector(
            $permissoes,
            $this->recursos(),
            new VisibilidadeDasAbasDaPasta($regras, $primarios, new EquipeQuePodeTerAbaOculta($vinculos, $primarios)),
            new AcessoAPasta((new \ReflectionClass(NivelNoCadeado::class))->newInstanceWithoutConstructor(), $permissoes),
            $this->escopoSemRestrito($vinculos),
        );
    }

    /** Escopo cujo vínculo nunca é achado: ninguém aqui é restrito (o restrito tem teste de integração próprio). */
    private function escopoSemRestrito(UserTenantRepository $vinculos): EscopoDePastas
    {
        $escopo = (new \ReflectionClass(EscopoDePastas::class))->newInstanceWithoutConstructor();
        \Closure::bind(function () use ($vinculos): void {
            $this->userTenantRepository = $vinculos;
        }, $escopo, EscopoDePastas::class)();

        return $escopo;
    }
}
