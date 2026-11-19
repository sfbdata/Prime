<?php

declare(strict_types=1);

namespace App\Tests\Conector\Integration;

use App\Conector\DTO\ChamadaDoConector;
use App\Conector\DTO\RespostaDaFerramenta;
use App\Conector\Entity\ClienteDoConector;
use App\Conector\Entity\ConsentimentoDoConector;
use App\Conector\Entity\EscritorioDoConector;
use App\Conector\Enum\TipoDeClienteDoConector;
use App\Conector\Exception\ErroDaFerramenta;
use App\Conector\Ferramenta\ConfirmarAcaoFerramenta;
use App\Conector\Ferramenta\PrazosEMetasDaPastaExecutor;
use App\Conector\Ferramenta\PrazosEMetasDaPastaFerramenta;
use App\Conector\Repository\PropostaDoConectorRepository;
use App\Conector\Service\GuardaDaAcaoDoConector;
use App\Conector\Service\RequisicaoDoConector;
use App\Conector\Service\SegredoOpaco;
use App\Conector\UseCase\ConfirmarPropostaDoConectorUseCase;
use App\Entity\Auth\User;
use App\Entity\Auth\UserTenant;
use App\Entity\Tarefa\Tarefa;
use App\Entity\Tenant\Tenant;
use App\Master\Entity\RecursoEstado;
use App\Pasta\Entity\AbaDaPasta;
use App\Pasta\Entity\AbaOcultaDaPasta;
use App\Pasta\Entity\CadeadoDaPasta;
use App\Pasta\Entity\ModoDoCadeado;
use App\Pasta\Entity\Pasta;
use App\Pasta\Entity\PastaPrazo;
use App\Tenant\Enum\SituacaoNaEquipe;
use App\Pasta\Entity\PastaProcesso;
use App\Processo\Entity\Processo;
use App\Tests\Conector\Support\CenarioDoConectorTrait;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestDox;
use Psr\Clock\ClockInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\NativeClock;

/**
 * `prazos_e_metas_da_pasta` com o banco e o portão de verdade (spec mcp-remoto-oauth 6.2, 6.6, 6.7 e 14.h): criar prazo,
 * cumprir e analisar o prazo da meta e criar meta são diretos; editar prazo existente e excluir prazo vão por proposta,
 * confirmada pela `confirmar_acao`; a reconferência recusa se o prazo mudou; e as recusas (outro escritório, outra
 * pasta, lixeira, segredo, sem editar, aba Metas oculta, módulo, chave, responsável de fora e campo obrigatório).
 */
#[CoversClass(PrazosEMetasDaPastaFerramenta::class)]
final class PrazosEMetasDaPastaFerramentaTest extends KernelTestCase
{
    use CenarioDoConectorTrait;

    private const EDITOR = ['modules.pastas.view', 'modules.tarefas.view', 'resources.pasta.view', 'resources.pasta.edit'];

    private Tenant $escritorio;
    private User $pessoa;
    private User $colega;
    private Pasta $pasta;
    private ClienteDoConector $app;

    /** @var array<int, ConsentimentoDoConector> */
    private array $consentimentos = [];

    protected function setUp(): void
    {
        self::bootKernel();
        $this->escritorio = $this->escritorio();
        $this->pessoa = $this->pessoa();
        $this->vinculo($this->pessoa, $this->escritorio, permissoes: self::EDITOR);
        $this->colega = $this->pessoa();
        $this->colega->setFullName('Maria Colega');
        $this->vinculo($this->colega, $this->escritorio, permissoes: self::EDITOR);
        $doConector = new EscritorioDoConector($this->escritorio);
        $doConector->ligar($this->pessoa, new \DateTimeImmutable());
        $this->em()->persist($doConector);
        $this->app = new ClienteDoConector('https://claude.ai/oauth/' . uniqid() . '/client.json', TipoDeClienteDoConector::Cimd, 'Claude', ['https://claude.ai/api/mcp/auth_callback'], new \DateTimeImmutable('-1 hour'));
        $this->em()->persist($this->app);
        $this->pasta = $this->pasta();
        $this->em()->flush();
    }

    protected function tearDown(): void
    {
        static::getContainer()->get('services_resetter')->reset();
        parent::tearDown();
    }

    // ---------------------------------------------------------------- caminho feliz

    #[TestDox('salvar_prazo sem prazo_id cria direto, com o responsável colega, e a resposta repete data, tipo, responsável e a Agenda')]
    public function testCriarPrazo(): void
    {
        $resposta = $this->executar(['acao' => 'salvar_prazo', 'pasta_id' => $this->pasta->getId(), 'tipo' => 'prazo', 'texto' => 'Contestar a ação', 'data' => '2026-12-04', 'responsavel_id' => $this->colega->getId()]);

        $prazo = $resposta->dados['prazo'];
        self::assertSame(['prazo', 'Prazo', '2026-12-04', '04/12/2026', 'Maria Colega', false], [$prazo['tipo'], $prazo['tipo_rotulo'], $prazo['data'], $prazo['data_br'], $prazo['responsavel'], $prazo['cumprido']]);
        self::assertStringContainsString('Agenda', $resposta->dados['aviso']);
        self::assertSame(
            ['tipo' => 'prazo', 'titulo' => 'Contestar a ação', 'data' => '2026-12-04', 'hora' => null, 'responsavel' => 'Maria Colega'],
            array_diff_key($this->linhaDoPrazo((int) $prazo['id']), ['cumprido_em' => 1]),
        );
        self::assertSame(0, $this->contarPropostas());
        self::assertSame(['pasta' => [$this->pasta->getId()], 'prazo' => [$prazo['id']]], $resposta->ids);
    }

    #[TestDox('salvar_prazo cria audiência com hora')]
    public function testCriarAudiencia(): void
    {
        $resposta = $this->executar(['acao' => 'salvar_prazo', 'pasta_id' => $this->pasta->getId(), 'tipo' => 'audiencia', 'texto' => 'Instrução', 'data' => '2026-12-10', 'hora' => '14:30']);

        $linha = $this->linhaDoPrazo((int) $resposta->dados['prazo']['id']);
        self::assertSame(['audiencia', '14:30', null], [$linha['tipo'], $linha['hora'], $linha['responsavel']]);
        self::assertSame('Audiência', $resposta->dados['prazo']['tipo_rotulo']);
    }

    #[TestDox('cumprir_prazo cumpre direto, repete o prazo e diz que ele saiu de Próximos prazos')]
    public function testCumprirDireto(): void
    {
        $prazo = $this->prazo();

        $resposta = $this->executar(['acao' => 'cumprir_prazo', 'pasta_id' => $this->pasta->getId(), 'prazo_id' => $prazo->getId()]);

        self::assertSame(['2026-11-20', 'Pessoa do Conector'], [$resposta->dados['prazo']['data'], $resposta->dados['prazo']['responsavel']]);
        self::assertNotNull($this->linhaDoPrazo((int) $prazo->getId())['cumprido_em']);
        self::assertSame(0, $this->contarPropostas());
    }

    #[TestDox('analisar_prazo_da_meta devolve a análise e não grava meta nem prazo')]
    public function testAnalisarNaoGrava(): void
    {
        $antes = [$this->contarMetas(), $this->contarPrazos()];

        $resposta = $this->executar(['acao' => 'analisar_prazo_da_meta', 'pasta_id' => $this->pasta->getId(), 'titulo' => 'Apresentar contestação', 'descricao' => 'Contestar a ação no prazo legal', 'data_ciencia' => '2026-11-17']);

        self::assertFalse($resposta->dados['gravou']);
        self::assertIsArray($resposta->dados['analise']);
        self::assertArrayHasKey('prazo_interno', $resposta->dados['analise']);
        self::assertSame($antes, [$this->contarMetas(), $this->contarPrazos()]);
    }

    #[TestDox('analisar_prazo_da_meta com texto curto demais não devolve análise e diz o motivo')]
    public function testAnalisarTextoCurto(): void
    {
        $resposta = $this->executar(['acao' => 'analisar_prazo_da_meta', 'pasta_id' => $this->pasta->getId(), 'titulo' => 'Oi']);

        self::assertNull($resposta->dados['analise']);
        self::assertSame(PrazosEMetasDaPastaFerramenta::TEXTO_CURTO_PARA_ANALISE, $resposta->dados['motivo']);
    }

    #[TestDox('criar_meta cria direto, numerada, com prazo, etapa e o responsável colega')]
    public function testCriarMeta(): void
    {
        $resposta = $this->executar($this->pedidoDeMeta());

        $meta = $resposta->dados['meta'];
        self::assertSame(['2026-12-15', '15/12/2026', 'juridico', [['id' => $this->colega->getId(), 'nome' => 'Maria Colega']]], [$meta['prazo'], $meta['prazo_br'], $meta['etapa'], $meta['responsaveis']]);
        self::assertNotNull($meta['numero']);
        self::assertSame(1, $this->contarMetas());
        $gravada = $this->em()->find(Tarefa::class, $meta['id']);
        self::assertSame($this->pasta->getId(), $gravada?->getPasta()->getId());
        self::assertSame([$this->colega->getId()], array_map(static fn (User $u): ?int => $u->getId(), $gravada->getResponsaveis()->toArray()));
        self::assertSame(PrazosEMetasDaPastaFerramenta::AVISO_META, $resposta->dados['aviso']);
    }

    #[TestDox('criar_meta funciona para quem só VÊ a pasta e tem o módulo Tarefas (a meta não exige editar)')]
    public function testCriarMetaSoVendo(): void
    {
        $leitor = $this->pessoaComPermissoes(['modules.pastas.view', 'modules.tarefas.view', 'resources.pasta.view']);

        $resposta = $this->executar($this->pedidoDeMeta(), $leitor);

        self::assertSame(1, $this->contarMetas());
        self::assertSame('juridico', $resposta->dados['meta']['etapa']);
    }

    // ---------------------------------------------------------------- proposta

    #[TestDox('salvar_prazo com prazo_id vira proposta com o antes e o depois, não grava, e a confirmação grava')]
    public function testEditarPrazoPorProposta(): void
    {
        $prazo = $this->prazo();

        $resposta = $this->executar(['acao' => 'salvar_prazo', 'pasta_id' => $this->pasta->getId(), 'prazo_id' => $prazo->getId(), 'tipo' => 'audiencia', 'data' => '2026-12-01', 'texto' => 'Nova descrição', 'responsavel_id' => $this->colega->getId()]);

        self::assertSame('aguardando_confirmacao', $resposta->dados['estado']);
        $resumo = $resposta->dados['proposta']['resumo'];
        foreach (['Tipo: antes Prazo; depois Audiência.', 'Data: antes 20/11/2026; depois 01/12/2026.', 'Responsável: antes Pessoa do Conector; depois Maria Colega.', 'Texto: antes Protocolar a contestação; depois Nova descrição.'] as $linha) {
            self::assertStringContainsString($linha, $resumo);
        }
        self::assertSame(['prazo', 'Protocolar a contestação', '2026-11-20', 'Pessoa do Conector'], $this->valoresDoPrazo((int) $prazo->getId()), 'a proposta não grava');
        self::assertSame(1, $this->contarPropostas());

        $resultado = $this->confirmar($resposta);

        self::assertSame('editar', $resultado->dados['resultado']['operacao']);
        self::assertSame(['audiencia', 'Nova descrição', '2026-12-01', 'Maria Colega'], $this->valoresDoPrazo((int) $prazo->getId()));
        self::assertStringContainsString('Agenda', $resultado->dados['resultado']['aviso']);
    }

    #[TestDox('cumprir_prazo com excluir vira proposta, não apaga, e a confirmação apaga o prazo')]
    public function testExcluirPrazoPorProposta(): void
    {
        $prazo = $this->prazo();

        $resposta = $this->executar(['acao' => 'cumprir_prazo', 'pasta_id' => $this->pasta->getId(), 'prazo_id' => $prazo->getId(), 'excluir' => true]);

        self::assertSame('aguardando_confirmacao', $resposta->dados['estado']);
        self::assertStringContainsString('Excluir o prazo da pasta', $resposta->dados['proposta']['resumo']);
        self::assertStringContainsString('Data: 20/11/2026.', $resposta->dados['proposta']['resumo']);
        self::assertNotNull($this->linhaDoPrazoOuNulo((int) $prazo->getId()), 'a proposta não apaga');

        $resultado = $this->confirmar($resposta);

        self::assertSame($prazo->getId(), $resultado->dados['resultado']['prazo_excluido']['id']);
        self::assertNull($this->linhaDoPrazoOuNulo((int) $prazo->getId()));
    }

    #[TestDox('editar sem mudar nada recusa, e nenhuma proposta nasce')]
    public function testEditarSemMudar(): void
    {
        $prazo = $this->prazo();

        $this->assertRecusada(['acao' => 'salvar_prazo', 'pasta_id' => $this->pasta->getId(), 'prazo_id' => $prazo->getId(), 'data' => '2026-11-20'], PrazosEMetasDaPastaFerramenta::SEM_MUDANCA);
        self::assertSame(0, $this->contarPropostas());
    }

    #[TestDox('reconferir recusa se o prazo mudou desde a proposta (data, tipo, responsável ou texto), e não grava')]
    public function testReconferirRecusaPrazoQueMudou(): void
    {
        foreach ([['2026-11-25', null], [null, 'Outro texto']] as [$data, $texto]) {
            $prazo = $this->prazo();
            $resposta = $this->executar(['acao' => 'salvar_prazo', 'pasta_id' => $this->pasta->getId(), 'prazo_id' => $prazo->getId(), 'texto' => 'Texto novo ' . uniqid()]);
            $dados = $this->dadosDaProposta($resposta);

            $this->gerenciado($prazo)->definir('prazo', $texto ?? 'Protocolar a contestação', new \DateTimeImmutable($data ?? '2026-11-20'), null, 'Pessoa do Conector');
            $this->em()->flush();

            $erro = $this->recusa(fn () => $this->ferramenta()->reconferir('salvar_prazo', $dados, $this->chamada()));
            self::assertSame([ErroDaFerramenta::MOTIVO_RECUSADA, PrazosEMetasDaPastaFerramenta::PRAZO_MUDOU], [$erro->motivo, $erro->getMessage()]);
        }
    }

    #[TestDox('a confirmação com o prazo já mudado recusa, deixa o prazo como está e a proposta pendente')]
    public function testConfirmarComPrazoMudado(): void
    {
        $prazo = $this->prazo();
        $resposta = $this->executar(['acao' => 'cumprir_prazo', 'pasta_id' => $this->pasta->getId(), 'prazo_id' => $prazo->getId(), 'excluir' => true]);
        $this->gerenciado($prazo)->definir('prazo', 'Protocolar a contestação', new \DateTimeImmutable('2026-11-30'), null, 'Pessoa do Conector');
        $this->em()->flush();

        $erro = $this->recusa(fn () => $this->confirmar($resposta));

        self::assertSame(PrazosEMetasDaPastaFerramenta::PRAZO_MUDOU, $erro->getMessage());
        self::assertSame('2026-11-30', $this->linhaDoPrazo((int) $prazo->getId())['data']);
    }

    #[TestDox('reconferir refaz as recusas de acesso: pasta na lixeira, sem editar, aba Metas oculta e responsável que saiu do escritório')]
    public function testReconferirRefazAsConferencias(): void
    {
        $prazo = $this->prazo();
        $edicao = $this->dadosDaProposta($this->executar(['acao' => 'salvar_prazo', 'pasta_id' => $this->pasta->getId(), 'prazo_id' => $prazo->getId(), 'responsavel_id' => $this->colega->getId()]));
        $exclusao = $this->dadosDaProposta($this->executar(['acao' => 'cumprir_prazo', 'pasta_id' => $this->pasta->getId(), 'prazo_id' => $prazo->getId(), 'excluir' => true]));

        // Colega que saiu: o responsável deixa de ser colega ativo.
        // "Colega ativo" é o vínculo com o escritório (`findColaboradorAtivoPorIdETenant`), não a conta.
        $this->em()->getRepository(UserTenant::class)->findOneBy(['user' => $this->colega->getId(), 'tenant' => $this->escritorio->getId()])?->alterarSituacao(SituacaoNaEquipe::Suspenso);
        $this->em()->flush();
        $erro = $this->recusa(fn () => $this->ferramenta()->reconferir('salvar_prazo', $edicao, $this->chamada()));
        self::assertSame(PrazosEMetasDaPastaFerramenta::RESPONSAVEL_INVALIDO, $erro->getMessage());

        $this->ocultar($this->pasta, AbaDaPasta::Tarefas);
        $erro = $this->recusa(fn () => $this->ferramenta()->reconferir('cumprir_prazo', $exclusao, $this->chamada()));
        self::assertSame(ErroDaFerramenta::MOTIVO_NAO_ENCONTRADO, $erro->motivo);

        $this->gerenciado($this->pasta)->marcarExcluida($this->gerenciado($this->pessoa), new \DateTimeImmutable());
        $this->em()->flush();
        $erro = $this->recusa(fn () => $this->ferramenta()->reconferir('cumprir_prazo', $exclusao, $this->chamada()));
        self::assertSame(ErroDaFerramenta::MOTIVO_NAO_ENCONTRADO, $erro->motivo);
    }

    // ---------------------------------------------------------------- recusas de acesso

    /** @return iterable<string, array{string}> */
    public static function acoes(): iterable
    {
        foreach (['salvar_prazo', 'cumprir_prazo', 'analisar_prazo_da_meta', 'criar_meta'] as $acao) {
            yield $acao => [$acao];
        }
    }

    /** @return iterable<string, array{string}> */
    public static function acoesDePrazo(): iterable
    {
        yield 'salvar_prazo' => ['salvar_prazo'];
        yield 'cumprir_prazo' => ['cumprir_prazo'];
    }

    #[DataProvider('acoes')]
    #[TestDox('pasta de outro escritório, na lixeira, trancada ou inexistente: não encontrado: $_dataName')]
    public function testPastaNaoEncontrada(string $acao): void
    {
        $outro = $this->escritorio('Outro');
        $deOutro = $this->pasta($outro);
        $naLixeira = $this->pasta();
        $naLixeira->marcarExcluida($this->pessoa, new \DateTimeImmutable());
        $trancada = $this->pasta();
        $this->em()->persist(new CadeadoDaPasta($this->escritorio, $trancada, ModoDoCadeado::Todos, null, new \DateTimeImmutable()));
        $this->em()->flush();

        foreach ([$deOutro->getId(), $naLixeira->getId(), $trancada->getId(), 999_999_999] as $id) {
            $this->assertMotivo($this->pedido($acao, $id), ErroDaFerramenta::MOTIVO_NAO_ENCONTRADO);
        }
    }

    #[DataProvider('acoes')]
    #[TestDox('pasta com processo em segredo de justiça: recusa com a frase do segredo: $_dataName')]
    public function testEmSegredo(string $acao): void
    {
        $pasta = $this->pastaEmSegredo();

        $erro = $this->recusa(fn () => $this->executar($this->pedido($acao, (int) $pasta->getId())));

        self::assertSame([ErroDaFerramenta::MOTIVO_EM_SEGREDO, ErroDaFerramenta::EM_SEGREDO], [$erro->motivo, $erro->getMessage()]);
        self::assertSame(0, $this->contarMetas());
    }

    #[DataProvider('acoesDePrazo')]
    #[TestDox('quem só vê a pasta (sem o recurso de editar) não grava prazo: não encontrado: $_dataName')]
    public function testSemEditar(string $acao): void
    {
        $leitor = $this->pessoaComPermissoes(['modules.pastas.view', 'modules.tarefas.view', 'resources.pasta.view']);

        $this->assertMotivo($this->pedido($acao), ErroDaFerramenta::MOTIVO_NAO_ENCONTRADO, $leitor);
        self::assertLessThanOrEqual(1, $this->contarPrazos(), 'no máximo o prazo que o pedido do teste criou');
    }

    #[DataProvider('acoes')]
    #[TestDox('sem o recurso de ver a pasta: não encontrado: $_dataName')]
    public function testSemVer(string $acao): void
    {
        $semVer = $this->pessoaComPermissoes(['modules.pastas.view', 'modules.tarefas.view']);

        $this->assertMotivo($this->pedido($acao), ErroDaFerramenta::MOTIVO_NAO_ENCONTRADO, $semVer);
    }

    #[DataProvider('acoesDePrazo')]
    #[TestDox('aba Metas oculta para a pessoa barra o prazo como não encontrado: $_dataName')]
    public function testAbaMetasOculta(string $acao): void
    {
        $pedido = $this->pedido($acao);
        $this->ocultar($this->pasta, AbaDaPasta::Tarefas);

        $this->assertMotivo($pedido, ErroDaFerramenta::MOTIVO_NAO_ENCONTRADO);
    }

    #[DataProvider('acoesDePrazo')]
    #[TestDox('sem o módulo Pastas: sem permissão no prazo: $_dataName')]
    public function testPrazoSemModuloPastas(string $acao): void
    {
        $semModulo = $this->pessoaComPermissoes(['modules.tarefas.view', 'resources.pasta.view', 'resources.pasta.edit']);

        $this->assertMotivo($this->pedido($acao), ErroDaFerramenta::MOTIVO_SEM_PERMISSAO, $semModulo);
    }

    /** @return iterable<string, array{string}> */
    public static function acoesDeMeta(): iterable
    {
        yield 'analisar_prazo_da_meta' => ['analisar_prazo_da_meta'];
        yield 'criar_meta' => ['criar_meta'];
    }

    #[DataProvider('acoesDeMeta')]
    #[TestDox('sem o módulo Tarefas: sem permissão na meta e na análise: $_dataName')]
    public function testMetaSemModuloTarefas(string $acao): void
    {
        $semModulo = $this->pessoaComPermissoes(['modules.pastas.view', 'resources.pasta.view', 'resources.pasta.edit']);

        $this->assertMotivo($this->pedido($acao), ErroDaFerramenta::MOTIVO_SEM_PERMISSAO, $semModulo);
        self::assertSame(0, $this->contarMetas());
    }

    #[DataProvider('acoesDePrazo')]
    #[TestDox('chave expedientes desligada pelo Master: função desligada no prazo: $_dataName')]
    public function testChaveDesligada(string $acao): void
    {
        $pedido = $this->pedido($acao);
        $estado = new RecursoEstado('expedientes');
        $estado->desativar('teste', null, null, new \DateTimeImmutable());
        $this->em()->persist($estado);
        $this->em()->flush();

        $this->assertMotivo($pedido, ErroDaFerramenta::MOTIVO_FUNCAO_DESLIGADA);
    }

    #[TestDox('prazo de outra pasta ou de outro escritório: não encontrado, na edição e no cumprir')]
    public function testPrazoDeOutraPasta(): void
    {
        $outraPasta = $this->pasta();
        $doutraPasta = $this->prazo($outraPasta);
        $outro = $this->escritorio('Outro');
        $alheio = $this->prazo($this->pasta($outro), $outro);

        foreach ([$doutraPasta, $alheio] as $prazo) {
            $this->assertMotivo(['acao' => 'salvar_prazo', 'pasta_id' => $this->pasta->getId(), 'prazo_id' => $prazo->getId(), 'texto' => 'Invadido'], ErroDaFerramenta::MOTIVO_NAO_ENCONTRADO);
            $this->assertMotivo(['acao' => 'cumprir_prazo', 'pasta_id' => $this->pasta->getId(), 'prazo_id' => $prazo->getId()], ErroDaFerramenta::MOTIVO_NAO_ENCONTRADO);
            $this->assertMotivo(['acao' => 'cumprir_prazo', 'pasta_id' => $this->pasta->getId(), 'prazo_id' => $prazo->getId(), 'excluir' => true], ErroDaFerramenta::MOTIVO_NAO_ENCONTRADO);
        }
        self::assertSame(0, $this->contarPropostas());
        self::assertNull($this->linhaDoPrazo((int) $doutraPasta->getId())['cumprido_em']);
    }

    // ---------------------------------------------------------------- recusas de regra e de forma

    #[TestDox('responsável de outro escritório ou sem vínculo ativo: recusa no prazo e na meta, sem gravar')]
    public function testResponsavelDeFora(): void
    {
        $outro = $this->escritorio('Outro');
        $deFora = $this->pessoa();
        $this->vinculo($deFora, $outro, permissoes: self::EDITOR);

        $this->assertRecusada(['acao' => 'salvar_prazo', 'pasta_id' => $this->pasta->getId(), 'tipo' => 'prazo', 'texto' => 'X', 'data' => '2026-12-04', 'responsavel_id' => $deFora->getId()], PrazosEMetasDaPastaFerramenta::RESPONSAVEL_INVALIDO);
        $this->assertRecusada($this->pedidoDeMeta(responsaveis: [(int) $this->colega->getId(), (int) $deFora->getId()]), PrazosEMetasDaPastaFerramenta::RESPONSAVEL_INVALIDO);
        self::assertSame([0, 0], [$this->contarPrazos(), $this->contarMetas()]);
    }

    #[TestDox('campos obrigatórios e valores inválidos recusam com mensagem fixa: prazo sem data, tipo, data que não existe e hora')]
    public function testCamposDoPrazo(): void
    {
        $base = ['acao' => 'salvar_prazo', 'pasta_id' => $this->pasta->getId(), 'tipo' => 'prazo', 'texto' => 'Contestar', 'data' => '2026-12-04'];

        $this->assertRecusada(array_diff_key($base, ['data' => 1]), PrazosEMetasDaPastaFerramenta::PRAZO_INCOMPLETO);
        $this->assertRecusada(array_diff_key($base, ['texto' => 1]), PrazosEMetasDaPastaFerramenta::PRAZO_INCOMPLETO);
        $this->assertRecusada(['tipo' => 'compromisso'] + $base, PrazosEMetasDaPastaFerramenta::TIPO_INVALIDO);
        $this->assertRecusada(['data' => '2026-02-31'] + $base, PrazosEMetasDaPastaFerramenta::DATA_INVALIDA);
        $this->assertRecusada(['data' => '04/12/2026'] + $base, PrazosEMetasDaPastaFerramenta::DATA_INVALIDA);
        $this->assertRecusada(['hora' => '25:00'] + $base, PrazosEMetasDaPastaFerramenta::HORA_INVALIDA);
        $this->assertRecusada(['texto' => str_repeat('a', 256)] + $base, PrazosEMetasDaPastaFerramenta::TEXTO_INVALIDO);
        self::assertSame(0, $this->contarPrazos());
    }

    #[TestDox('criar_meta: título, descricao, prazo, etapa e responsável são obrigatórios; análise não confirmada recusa; nada é gravado')]
    public function testCamposDaMeta(): void
    {
        foreach (['titulo', 'descricao', 'prazo', 'etapa', 'responsaveis'] as $campo) {
            $this->assertRecusada(array_diff_key($this->pedidoDeMeta(), [$campo => 1]), PrazosEMetasDaPastaFerramenta::META_INVALIDA);
        }
        $this->assertRecusada(['responsaveis' => []] + $this->pedidoDeMeta(), PrazosEMetasDaPastaFerramenta::META_INVALIDA);
        $this->assertRecusada(['prazo' => '2026-02-31'] + $this->pedidoDeMeta(), PrazosEMetasDaPastaFerramenta::DATA_INVALIDA);
        $this->assertRecusada(['confirmado' => false, 'data_ciencia' => '2026-11-17'] + $this->pedidoDeMeta(), PrazosEMetasDaPastaFerramenta::ANALISE_RECUSADA);
        self::assertSame(0, $this->contarMetas());
    }

    #[TestDox('prazo cumprido não se edita nem se cumpre de novo; o lido de um registro não se edita, cumpre nem exclui')]
    public function testPrazoQueNaoSeMexe(): void
    {
        $cumprido = $this->prazo();
        $cumprido->cumprir($this->pessoa);
        $deRegistro = PastaPrazo::doRegistro($this->escritorio, $this->pasta, 'expedientes:1', new \DateTimeImmutable(), $this->pessoa, 'prazo', 'Lido do registro', new \DateTimeImmutable('2026-12-01'), null, false);
        $this->em()->persist($deRegistro);
        $this->em()->flush();
        $pasta = $this->pasta->getId();

        $this->assertRecusada(['acao' => 'salvar_prazo', 'pasta_id' => $pasta, 'prazo_id' => $cumprido->getId(), 'texto' => 'Novo'], PrazosEMetasDaPastaFerramenta::PRAZO_CUMPRIDO);
        $this->assertRecusada(['acao' => 'cumprir_prazo', 'pasta_id' => $pasta, 'prazo_id' => $cumprido->getId()], PrazosEMetasDaPastaFerramenta::JA_CUMPRIDO);
        $this->assertRecusada(['acao' => 'salvar_prazo', 'pasta_id' => $pasta, 'prazo_id' => $deRegistro->getId(), 'texto' => 'Novo'], PrazosEMetasDaPastaFerramenta::NAO_EDITA);
        $this->assertRecusada(['acao' => 'cumprir_prazo', 'pasta_id' => $pasta, 'prazo_id' => $deRegistro->getId()], PrazosEMetasDaPastaFerramenta::NAO_CUMPRE);
        $this->assertRecusada(['acao' => 'cumprir_prazo', 'pasta_id' => $pasta, 'prazo_id' => $deRegistro->getId(), 'excluir' => true], PrazosEMetasDaPastaFerramenta::NAO_CUMPRE);
        self::assertSame(0, $this->contarPropostas());
    }

    #[TestDox('campo fora do esquema da ação, ação fora da lista, cumprir_prazo sem prazo_id e tipo errado: pedido inválido')]
    public function testEntradaInvalida(): void
    {
        $pasta = $this->pasta->getId();
        $casos = [
            ['acao' => 'salvar_prazo', 'pasta_id' => $pasta, 'excluir' => true],
            ['acao' => 'criar_meta', 'pasta_id' => $pasta, 'prazo_id' => 1],
            ['acao' => 'apagar_tudo', 'pasta_id' => $pasta],
            ['acao' => 'cumprir_prazo', 'pasta_id' => $pasta],
            ['acao' => 'analisar_prazo_da_meta', 'pasta_id' => $pasta],
            ['acao' => 'cumprir_prazo', 'pasta_id' => $pasta, 'prazo_id' => 1, 'excluir' => 'sim'],
            ['acao' => 'salvar_prazo', 'pasta_id' => 0],
            ['acao' => 'criar_meta', 'pasta_id' => $pasta, 'responsaveis' => 'todos'],
        ];
        foreach ($casos as $argumentos) {
            $this->assertMotivo($argumentos, ErroDaFerramenta::MOTIVO_ENTRADA_INVALIDA);
        }
    }

    #[TestDox('o texto do banco sai protegido: CPF no texto do prazo e no resumo da proposta vira [CPF]')]
    public function testTextoProtegido(): void
    {
        $prazo = $this->prazo();
        $prazo->definir('prazo', 'Chamar 529.982.247-25 para assinar', new \DateTimeImmutable('2026-11-20'), null, 'Pessoa do Conector');
        $this->em()->flush();

        $resposta = $this->executar(['acao' => 'cumprir_prazo', 'pasta_id' => $this->pasta->getId(), 'prazo_id' => $prazo->getId(), 'excluir' => true]);

        $resumo = $resposta->dados['proposta']['resumo'];
        self::assertStringNotContainsString('529.982.247-25', $resumo);
        self::assertStringContainsString('[CPF]', $resumo);
    }

    #[TestDox('editar só a data: o texto do banco que segue igual no "depois" também sai protegido no resumo')]
    public function testDepoisDaEdicaoProtegido(): void
    {
        $prazo = $this->prazo();
        $prazo->definir('prazo', 'Chamar 529.982.247-25', new \DateTimeImmutable('2026-11-20'), null, 'Pessoa do Conector');
        $this->em()->flush();

        $resposta = $this->executar(['acao' => 'salvar_prazo', 'pasta_id' => $this->pasta->getId(), 'prazo_id' => $prazo->getId(), 'data' => '2026-12-01']);

        $resumo = $resposta->dados['proposta']['resumo'];
        self::assertStringContainsString('Data: antes 20/11/2026; depois 01/12/2026.', $resumo);
        self::assertStringNotContainsString('529.982.247-25', $resumo);
        self::assertStringContainsString('depois Chamar [CPF].', $resumo);
    }

    // ---------------------------------------------------------------- apoio

    /** O pedido mínimo e válido de cada ação, na pasta dada (a própria, se não vier). */
    private function pedido(string $acao, ?int $pastaId = null): array
    {
        $pasta = $pastaId ?? $this->pasta->getId();

        return match ($acao) {
            'salvar_prazo' => ['acao' => $acao, 'pasta_id' => $pasta, 'tipo' => 'prazo', 'texto' => 'Contestar', 'data' => '2026-12-04'],
            'cumprir_prazo' => ['acao' => $acao, 'pasta_id' => $pasta, 'prazo_id' => $this->prazoDoPedido()->getId()],
            'analisar_prazo_da_meta' => ['acao' => $acao, 'pasta_id' => $pasta, 'titulo' => 'Apresentar contestação', 'descricao' => 'Contestar a ação'],
            default => ['pasta_id' => $pasta] + $this->pedidoDeMeta(),
        };
    }

    private ?PastaPrazo $prazoDoPedido = null;

    private function prazoDoPedido(): PastaPrazo
    {
        return $this->prazoDoPedido ??= $this->prazo();
    }

    /**
     * @param list<int>|null $responsaveis
     *
     * @return array<string, mixed>
     */
    private function pedidoDeMeta(?array $responsaveis = null): array
    {
        return [
            'acao' => 'criar_meta',
            'pasta_id' => $this->pasta->getId(),
            'titulo' => 'Preparar a réplica',
            'descricao' => 'Ler a contestação e responder ponto a ponto.',
            'prazo' => '2026-12-15',
            'etapa' => 'juridico',
            'responsaveis' => $responsaveis ?? [(int) $this->colega->getId()],
        ];
    }

    /** @param array<string, mixed> $argumentos */
    private function executar(array $argumentos, ?User $pessoa = null): RespostaDaFerramenta
    {
        return $this->ferramenta()->executar($argumentos, $this->chamada($pessoa));
    }

    private function ferramenta(): PrazosEMetasDaPastaFerramenta
    {
        return static::getContainer()->get(PrazosEMetasDaPastaFerramenta::class);
    }

    /** @param array<string, mixed> $argumentos */
    private function assertMotivo(array $argumentos, string $motivo, ?User $pessoa = null): void
    {
        $erro = $this->recusa(fn () => $this->executar($argumentos, $pessoa));
        self::assertSame($motivo, $erro->motivo);
    }

    /** @param array<string, mixed> $argumentos */
    private function assertRecusada(array $argumentos, string $mensagem): void
    {
        $erro = $this->recusa(fn () => $this->executar($argumentos));
        self::assertSame([ErroDaFerramenta::MOTIVO_RECUSADA, $mensagem], [$erro->motivo, $erro->getMessage()]);
    }

    private function recusa(callable $chamar): ErroDaFerramenta
    {
        try {
            $chamar();
        } catch (ErroDaFerramenta $erro) {
            return $erro;
        }
        self::fail('a chamada deveria recusar');
    }

    /** @param list<string> $escopos */
    private function chamada(?User $pessoa = null, array $escopos = ['mcp:leitura', 'mcp:escrita']): ChamadaDoConector
    {
        $pessoa ??= $this->pessoa;
        $consentimento = $this->consentimentos[(int) $pessoa->getId()] ??= $this->novoConsentimento($pessoa);
        static::getContainer()->get('services_resetter')->reset();
        $contexto = $this->portao()->abrir((int) $pessoa->getId(), (int) $this->escritorio->getId());

        return new ChamadaDoConector($contexto, 41, (int) $consentimento->getId(), 'claude.ai', $escopos, new \DateTimeImmutable('+7 days'));
    }

    private function novoConsentimento(User $pessoa): ConsentimentoDoConector
    {
        $consentimento = new ConsentimentoDoConector($pessoa, $this->escritorio, $this->app, ['mcp:leitura', 'mcp:escrita'], new \DateTimeImmutable('-1 hour'));
        $this->em()->persist($consentimento);
        $this->em()->flush();

        return $consentimento;
    }

    /** A `confirmar_acao` real, com esta ferramenta como executora. */
    private function confirmar(RespostaDaFerramenta $proposta): RespostaDaFerramenta
    {
        $c = static::getContainer();
        $relogio = $c->has(ClockInterface::class) ? $c->get(ClockInterface::class) : new NativeClock();
        $useCase = new ConfirmarPropostaDoConectorUseCase(
            $c->get(PropostaDoConectorRepository::class),
            $c->get(GuardaDaAcaoDoConector::class),
            new SegredoOpaco(),
            $this->em(),
            $relogio,
            [new PrazosEMetasDaPastaExecutor($this->ferramenta())],
        );
        $confirmar = new ConfirmarAcaoFerramenta($useCase, $c->get('limiter.conector_confirmacao'), new RequisicaoDoConector());
        $argumentos = ['proposta_id' => $proposta->dados['proposta']['proposta_id'], 'resumo' => $proposta->dados['proposta']['resumo']];

        return $confirmar->executar($argumentos, $this->chamada());
    }

    /** @return array<string, mixed> */
    private function dadosDaProposta(RespostaDaFerramenta $resposta): array
    {
        $hash = hash('sha256', $resposta->dados['proposta']['proposta_id']);

        return static::getContainer()->get(PropostaDoConectorRepository::class)->buscarPorIdentificadorHash($hash, $this->escritorio)?->getDados() ?? self::fail('proposta não gravada');
    }

    private function prazo(?Pasta $pasta = null, ?Tenant $escritorio = null): PastaPrazo
    {
        $prazo = new PastaPrazo($this->gerenciado($escritorio ?? $this->escritorio), $this->gerenciado($pasta ?? $this->pasta), $this->gerenciado($this->pessoa));
        $prazo->definir('prazo', 'Protocolar a contestação', new \DateTimeImmutable('2026-11-20'), null, 'Pessoa do Conector');
        $this->em()->persist($prazo);
        $this->em()->flush();

        return $prazo;
    }

    /** @return array<string, mixed> */
    private function linhaDoPrazo(int $id): array
    {
        return $this->linhaDoPrazoOuNulo($id) ?? self::fail('prazo não existe');
    }

    /** @return array<string, mixed>|null */
    private function linhaDoPrazoOuNulo(int $id): ?array
    {
        $linha = $this->em()->getConnection()->fetchAssociative('SELECT tipo, titulo, data, hora, responsavel, cumprido_em FROM pasta_prazo WHERE id = ?', [$id]);

        return $linha === false ? null : $linha;
    }

    /** @return list<mixed> tipo, texto, data e responsável gravados */
    private function valoresDoPrazo(int $id): array
    {
        $linha = $this->linhaDoPrazo($id);

        return [$linha['tipo'], $linha['titulo'], $linha['data'], $linha['responsavel']];
    }

    private function contarPrazos(): int
    {
        return (int) $this->em()->getConnection()->fetchOne('SELECT COUNT(*) FROM pasta_prazo WHERE tenant_id = ?', [$this->escritorio->getId()]);
    }

    private function contarMetas(): int
    {
        $tabela = $this->em()->getClassMetadata(Tarefa::class)->getTableName();

        return (int) $this->em()->getConnection()->fetchOne('SELECT COUNT(*) FROM ' . $tabela . ' WHERE tenant_id = ?', [$this->escritorio->getId()]);
    }

    private function contarPropostas(): int
    {
        return (int) $this->em()->getConnection()->fetchOne('SELECT COUNT(*) FROM conector_ia_proposta WHERE tenant_id = ?', [$this->escritorio->getId()]);
    }

    /** @param list<string> $permissoes */
    private function pessoaComPermissoes(array $permissoes): User
    {
        $pessoa = $this->pessoa();
        $this->vinculo($pessoa, $this->escritorio, permissoes: $permissoes);

        return $pessoa;
    }

    private function pasta(?Tenant $escritorio = null): Pasta
    {
        $pasta = (new Pasta())->setNup('PRZ-' . uniqid());
        $pasta->setTenant($escritorio ?? $this->escritorio);
        $this->em()->persist($pasta);
        $this->em()->flush();

        return $pasta;
    }

    private function pastaEmSegredo(): Pasta
    {
        $pasta = $this->pasta();
        $processo = new Processo();
        $processo->setNumeroProcesso('07011345720258070001' . uniqid());
        $processo->setSiglaTribunal('TJDFT');
        $processo->setClasseProcessual('Procedimento Comum');
        $processo->setAssuntoProcessual('Assunto');
        $processo->setTenant($this->escritorio);
        $processo->setNivelSigilo(1);
        $this->em()->persist($processo);
        $vinculo = new PastaProcesso($pasta, $processo);
        $this->em()->persist($vinculo);
        $pasta->getPastaProcessos()->add($vinculo);
        $this->em()->flush();

        return $pasta;
    }

    private function ocultar(Pasta $pasta, AbaDaPasta $aba): void
    {
        $this->em()->persist(AbaOcultaDaPasta::paraTodaAEquipe($this->gerenciado($this->escritorio), $this->gerenciado($pasta), $aba, $this->pessoa(), new \DateTimeImmutable()));
        $this->em()->flush();
    }

    /**
     * A mesma entidade, presa ao EntityManager de agora: cada chamada reinicia os serviços (`services_resetter`), e o
     * que o teste mudar numa entidade solta não chega ao banco no `flush`.
     *
     * @template T of object
     *
     * @param T $entidade
     *
     * @return T
     */
    private function gerenciado(object $entidade): object
    {
        if ($this->em()->contains($entidade)) {
            return $entidade;
        }

        return $this->em()->find($entidade::class, $entidade->getId()) ?? throw new \LogicException('entidade sumiu');
    }
}
