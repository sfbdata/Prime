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
use App\Conector\Ferramenta\CatalogoFechadoDoConector;
use App\Conector\Ferramenta\ConfirmarAcaoFerramenta;
use App\Conector\Ferramenta\MudarAndamentoFerramenta;
use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Master\Entity\RecursoEstado;
use App\Pasta\Entity\AbaDaPasta;
use App\Pasta\Entity\AbaOcultaDaPasta;
use App\Pasta\Entity\Pasta;
use App\Pasta\Entity\PastaProcesso;
use App\Pasta\Repository\RotuloDeEtapaRepository;
use App\Pasta\Service\AndamentoDaPasta;
use App\Pasta\UseCase\NomearEtapaDaPastaUseCase;
use App\Processo\Entity\Processo;
use App\Tests\Conector\Support\CenarioDoConectorTrait;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * `mudar_andamento` com o banco e o portão de verdade (spec mcp-remoto-oauth 6.6, bloco da ferramenta, e 6.7): avançar e
 * nomear etapa sem nome executam na hora; voltar, reativar, trocar e remover nome viram proposta, confirmada pelo
 * executor; a reconferência recusa quando a pasta mudou; as negações da guarda; e o contrato, que só aparece para quem
 * vê o Financeiro da pasta.
 */
#[CoversClass(MudarAndamentoFerramenta::class)]
final class MudarAndamentoFerramentaTest extends KernelTestCase
{
    use CenarioDoConectorTrait;

    private const LEITOR = ['modules.pastas.view', 'resources.pasta.view'];
    private const EDITOR = ['modules.pastas.view', 'resources.pasta.view', 'resources.pasta.edit'];

    private Tenant $escritorio;
    private User $pessoa;
    private ConsentimentoDoConector $consentimento;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->escritorio = $this->escritorio();
        $this->pessoa = $this->pessoa();
        $this->vinculo($this->pessoa, $this->escritorio, permissoes: self::EDITOR);
        $doConector = new EscritorioDoConector($this->escritorio);
        $doConector->liberarPeloMaster();
        $doConector->ligar($this->pessoa, new \DateTimeImmutable());
        $this->em()->persist($doConector);
        $app = new ClienteDoConector('https://claude.ai/oauth/' . uniqid() . '/client.json', TipoDeClienteDoConector::Cimd, 'Claude', ['https://claude.ai/api/mcp/auth_callback'], new \DateTimeImmutable());
        $this->em()->persist($app);
        $this->consentimento = new ConsentimentoDoConector($this->pessoa, $this->escritorio, $app, ['mcp:leitura', 'mcp:escrita'], new \DateTimeImmutable());
        $this->em()->persist($this->consentimento);
        $this->em()->flush();
    }

    protected function tearDown(): void
    {
        static::getContainer()->get('services_resetter')->reset();
        parent::tearDown();
    }

    #[TestDox('o nome, o título e o esquema fechado batem com o catálogo e com a 6.2 (regra 10)')]
    public function testEsquema(): void
    {
        $ferramenta = static::getContainer()->get(MudarAndamentoFerramenta::class);
        $esquema = $ferramenta->esquemaDeEntrada();

        self::assertSame('mudar_andamento', $ferramenta->nome());
        self::assertSame(CatalogoFechadoDoConector::TITULOS['mudar_andamento'], $ferramenta->titulo());
        self::assertFalse($esquema['additionalProperties']);
        self::assertSame(['mudar_fase', 'nomear_etapa'], $esquema['properties']['acao']['enum']);
        self::assertSame(['acao', 'pasta_id'], $esquema['required']);
        self::assertStringNotContainsString("\u{2014}", $ferramenta->descricao());
        self::assertStringNotContainsString("\u{2013}", $ferramenta->descricao());
    }

    #[TestDox('mudar_fase avançando: executa na hora, pelo nome da trilha, sem proposta')]
    public function testAvancarDireto(): void
    {
        $pasta = $this->pasta();

        $resposta = $this->executar(['acao' => 'mudar_fase', 'pasta_id' => $pasta->getId(), 'fase' => 'protocolo', 'motivo' => 'Protocolado']);

        self::assertSame('concluida', $resposta->dados['estado']);
        self::assertSame(['Abertura', 'Protocolo'], [$resposta->dados['fase_anterior'], $resposta->dados['fase']]);
        self::assertSame(4, $this->faseAtual($pasta));
        self::assertSame(0, $this->contarPropostas());
        self::assertSame(['pasta' => [$pasta->getId()]], $resposta->ids);
    }

    #[TestDox('mudar_fase pelo número da posição: 6 é "Em andamento"')]
    public function testPeloNumero(): void
    {
        $pasta = $this->pasta();

        $resposta = $this->executar(['acao' => 'mudar_fase', 'pasta_id' => $pasta->getId(), 'fase' => 6]);

        self::assertSame('Em andamento', $resposta->dados['fase']);
        self::assertSame(5, $this->faseAtual($pasta));
    }

    #[TestDox('chegar a Contrato marca o contrato assinado e a resposta diz, para quem vê o Financeiro')]
    public function testContratoParaQuemVe(): void
    {
        $pasta = $this->pasta();

        $resposta = $this->executar(['acao' => 'mudar_fase', 'pasta_id' => $pasta->getId(), 'fase' => 'Contrato', 'sem_pular' => true]);

        self::assertSame('O contrato do Financeiro foi marcado como assinado.', $resposta->dados['contrato']);
        self::assertSame('REGULAR', $this->pastaAtual($pasta)->getSituacaoContrato());
    }

    #[TestDox('chegar a Contrato com o Financeiro oculto: o contrato é marcado como na tela, mas a resposta não diz uma palavra dele')]
    public function testContratoParaQuemNaoVe(): void
    {
        $pasta = $this->pasta();
        $this->ocultarFinanceiro($pasta);

        $resposta = $this->executar(['acao' => 'mudar_fase', 'pasta_id' => $pasta->getId(), 'fase' => 'Contrato', 'sem_pular' => true]);

        self::assertArrayNotHasKey('contrato', $resposta->dados);
        $json = json_encode($resposta->dados, \JSON_THROW_ON_ERROR);
        self::assertStringNotContainsString('assinado', $json);
        self::assertStringNotContainsString('Financeiro', $json);
        self::assertSame('REGULAR', $this->pastaAtual($pasta)->getSituacaoContrato());
    }

    #[TestDox('voltar a fase vira proposta, não muda nada ao propor, e o resumo diz que o contrato reabre; confirmar grava e reabre')]
    public function testVoltarFaseComContrato(): void
    {
        $pasta = $this->pasta();
        $this->executar(['acao' => 'mudar_fase', 'pasta_id' => $pasta->getId(), 'fase' => 'Contrato', 'sem_pular' => true]);

        $proposta = $this->executar(['acao' => 'mudar_fase', 'pasta_id' => $pasta->getId(), 'fase' => 'Documentação', 'motivo' => 'Erro de clique']);

        self::assertSame('aguardando_confirmacao', $proposta->dados['estado']);
        $resumo = $proposta->dados['proposta']['resumo'];
        self::assertStringContainsString('de "Contrato" para "Documentação"', $resumo);
        self::assertStringContainsString('Motivo: Erro de clique.', $resumo);
        self::assertStringContainsString('O contrato do Financeiro volta a ficar pendente.', $resumo);
        self::assertSame(2, $this->faseAtual($pasta), 'propor não grava');
        self::assertSame('REGULAR', $this->pastaAtual($pasta)->getSituacaoContrato());

        $confirmada = $this->confirmar($proposta);

        self::assertSame('Documentação', $confirmada->dados['resultado']['fase']);
        self::assertSame('O contrato do Financeiro voltou a ficar pendente.', $confirmada->dados['resultado']['contrato']);
        self::assertSame(1, $this->faseAtual($pasta));
        self::assertSame('PENDENTE', $this->pastaAtual($pasta)->getSituacaoContrato());
    }

    #[TestDox('voltar a fase com o Financeiro oculto: nem o resumo nem o resultado falam do contrato, e ele reabre como na tela')]
    public function testVoltarFaseSemVerOFinanceiro(): void
    {
        $pasta = $this->pasta();
        $this->ocultarFinanceiro($pasta);
        $this->executar(['acao' => 'mudar_fase', 'pasta_id' => $pasta->getId(), 'fase' => 'Contrato', 'sem_pular' => true]);

        $proposta = $this->executar(['acao' => 'mudar_fase', 'pasta_id' => $pasta->getId(), 'fase' => 1]);

        $resumo = $proposta->dados['proposta']['resumo'];
        self::assertStringNotContainsString('contrato do Financeiro', $resumo);
        self::assertStringNotContainsString('Financeiro', $resumo);
        $confirmada = $this->confirmar($proposta);
        self::assertArrayNotHasKey('contrato', $confirmada->dados['resultado']);
        self::assertSame('PENDENTE', $this->pastaAtual($pasta)->getSituacaoContrato());
    }

    #[TestDox('reativar a pasta finalizada (Finalizada sobre Finalizada) vira proposta; confirmar reativa e o histórico fica')]
    public function testReativarFinalizada(): void
    {
        $pasta = $this->pasta();
        $this->executar(['acao' => 'mudar_fase', 'pasta_id' => $pasta->getId(), 'fase' => 'Finalizada']);
        self::assertSame(9, $this->faseAtual($pasta));

        $proposta = $this->executar(['acao' => 'mudar_fase', 'pasta_id' => $pasta->getId(), 'fase' => 'Finalizada']);

        self::assertSame('aguardando_confirmacao', $proposta->dados['estado']);
        self::assertStringContainsString('Reativar a pasta', $proposta->dados['proposta']['resumo']);
        self::assertStringContainsString('O contrato do Financeiro não muda.', $proposta->dados['proposta']['resumo']);
        self::assertSame(9, $this->faseAtual($pasta));

        $this->confirmar($proposta);

        $estado = $this->estado($pasta);
        // Como a tela (PastaAndamentoControllerTest::testFinalizarEReativar): entram Reativada, Em andamento e uma nova
        // Finalizada, e a pasta fica em "Em andamento".
        self::assertSame(11, $estado['atual']);
        self::assertSame('Reativada', $estado['fases'][10][0]);
        self::assertSame('Em andamento', $estado['fases'][11][0]);
        self::assertSame('Finalizada', $estado['fases'][12][0]);
    }

    #[TestDox('já na fase pedida, fase que não existe: recusa com mensagem fixa da classe')]
    public function testFaseRecusada(): void
    {
        $pasta = $this->pasta();

        $this->assertRecusa(['acao' => 'mudar_fase', 'pasta_id' => $pasta->getId(), 'fase' => 'Abertura'], MudarAndamentoFerramenta::JA_NA_FASE);
        foreach (['Inexistente', 99, 0, '', 'Reativada'] as $fase) {
            $this->assertRecusa(['acao' => 'mudar_fase', 'pasta_id' => $pasta->getId(), 'fase' => $fase], MudarAndamentoFerramenta::FASE_INVALIDA);
        }
    }

    #[TestDox('nomear_etapa sem nome dado: grava na hora; o nome sai pelo rótulo protegido')]
    public function testNomearSemNome(): void
    {
        $pasta = $this->pasta();

        $resposta = $this->executar(['acao' => 'nomear_etapa', 'pasta_id' => $pasta->getId(), 'fase_de_inicio' => 'Abertura', 'texto' => '  Triagem  ']);

        self::assertSame('concluida', $resposta->dados['estado']);
        self::assertSame('Triagem', $resposta->dados['nome']);
        self::assertSame(['Abertura', 'Documentação'], $resposta->dados['entre']);
        self::assertSame('Triagem', $this->nomeDaEtapa($pasta, 0));
        self::assertSame(0, $this->contarPropostas());
    }

    #[TestDox('nomear_etapa trocando um nome já dado: proposta; só depois de confirmar o nome muda')]
    public function testTrocarNome(): void
    {
        $pasta = $this->pasta();
        $this->executar(['acao' => 'nomear_etapa', 'pasta_id' => $pasta->getId(), 'fase_de_inicio' => 1, 'texto' => 'Triagem']);

        $proposta = $this->executar(['acao' => 'nomear_etapa', 'pasta_id' => $pasta->getId(), 'fase_de_inicio' => 1, 'texto' => 'Análise']);

        self::assertSame('aguardando_confirmacao', $proposta->dados['estado']);
        self::assertStringContainsString('de "Triagem" para "Análise"', $proposta->dados['proposta']['resumo']);
        self::assertSame('Triagem', $this->nomeDaEtapa($pasta, 0));

        $confirmada = $this->confirmar($proposta);

        self::assertSame('Análise', $confirmada->dados['resultado']['nome']);
        self::assertSame('Análise', $this->nomeDaEtapa($pasta, 0));
    }

    #[TestDox('nomear_etapa com texto vazio remove o nome: proposta; confirmar remove')]
    public function testRemoverNome(): void
    {
        $pasta = $this->pasta();
        $this->executar(['acao' => 'nomear_etapa', 'pasta_id' => $pasta->getId(), 'fase_de_inicio' => 'Abertura', 'texto' => 'Triagem']);

        $proposta = $this->executar(['acao' => 'nomear_etapa', 'pasta_id' => $pasta->getId(), 'fase_de_inicio' => 'Abertura', 'texto' => '   ']);

        self::assertStringContainsString('Remover o nome "Triagem"', $proposta->dados['proposta']['resumo']);
        self::assertSame('Triagem', $this->nomeDaEtapa($pasta, 0));

        $confirmada = $this->confirmar($proposta);

        self::assertNull($confirmada->dados['resultado']['nome']);
        self::assertNull($this->nomeDaEtapa($pasta, 0));
    }

    #[TestDox('nomear_etapa: texto longo demais, com caractere inválido, igual ao atual, remover o que não existe e etapa inexistente recusam')]
    public function testNomearRecusas(): void
    {
        $pasta = $this->pasta();
        $base = ['acao' => 'nomear_etapa', 'pasta_id' => $pasta->getId(), 'fase_de_inicio' => 'Abertura'];

        $this->assertRecusa($base + ['texto' => 'ABCDEFGHIJKLM'], MudarAndamentoFerramenta::TEXTO_LONGO);
        $this->assertRecusa($base + ['texto' => 'a<b>'], MudarAndamentoFerramenta::TEXTO_INVALIDO);
        $this->assertRecusa($base + ['texto' => ''], MudarAndamentoFerramenta::SEM_MUDANCA);
        $this->assertRecusa(['fase_de_inicio' => 'Finalizada'] + $base + ['texto' => 'X'], MudarAndamentoFerramenta::FASE_INVALIDA);
        $this->assertRecusa(['fase_de_inicio' => 'Nada'] + $base + ['texto' => 'X'], MudarAndamentoFerramenta::FASE_INVALIDA);
        $this->executar($base + ['texto' => 'Triagem']);
        $this->assertRecusa($base + ['texto' => 'Triagem'], MudarAndamentoFerramenta::SEM_MUDANCA);
        self::assertSame(0, $this->contarPropostas());
    }

    #[TestDox('reconferir: a fase mudou depois da proposta, a confirmação recusa e nada é gravado')]
    public function testReconferirFaseMudou(): void
    {
        $pasta = $this->pasta();
        $this->executar(['acao' => 'mudar_fase', 'pasta_id' => $pasta->getId(), 'fase' => 'Protocolo']);
        $proposta = $this->executar(['acao' => 'mudar_fase', 'pasta_id' => $pasta->getId(), 'fase' => 'Abertura']);
        $this->executar(['acao' => 'mudar_fase', 'pasta_id' => $pasta->getId(), 'fase' => 'Em andamento']);

        $erro = $this->recusaDaConfirmacao($proposta);

        self::assertSame(MudarAndamentoFerramenta::MUDOU, $erro->getMessage());
        self::assertSame(5, $this->faseAtual($pasta));
    }

    #[TestDox('reconferir: o contrato do Financeiro mudou depois da proposta de voltar a fase, a confirmação recusa e nada é gravado')]
    public function testReconferirContratoMudou(): void
    {
        $pasta = $this->pasta();
        $this->executar(['acao' => 'mudar_fase', 'pasta_id' => $pasta->getId(), 'fase' => 'Protocolo', 'sem_pular' => true]);
        $this->trocarContrato($pasta, 'PENDENTE');
        $fase = $this->faseAtual($pasta);
        $proposta = $this->executar(['acao' => 'mudar_fase', 'pasta_id' => $pasta->getId(), 'fase' => 'Documentação']);
        self::assertStringContainsString('O contrato do Financeiro não muda.', $proposta->dados['proposta']['resumo']);
        $this->trocarContrato($pasta, 'REGULAR');

        $erro = $this->recusaDaConfirmacao($proposta);

        self::assertSame(MudarAndamentoFerramenta::MUDOU, $erro->getMessage());
        self::assertSame($fase, $this->faseAtual($pasta), 'a fase não muda');
        self::assertSame('REGULAR', $this->pastaAtual($pasta)->getSituacaoContrato());
    }

    #[TestDox('reconferir: o nome da etapa mudou depois da proposta, a confirmação recusa')]
    public function testReconferirNomeMudou(): void
    {
        $pasta = $this->pasta();
        $this->executar(['acao' => 'nomear_etapa', 'pasta_id' => $pasta->getId(), 'fase_de_inicio' => 'Abertura', 'texto' => 'Triagem']);
        $proposta = $this->executar(['acao' => 'nomear_etapa', 'pasta_id' => $pasta->getId(), 'fase_de_inicio' => 'Abertura', 'texto' => 'Análise']);
        static::getContainer()->get('services_resetter')->reset();
        // A pasta antes do portão: o pastaAtual() limpa o EntityManager, e o UseCase compara o escritório por identidade.
        $agora = $this->pastaAtual($pasta);
        $contexto = $this->portao()->abrir((int) $this->pessoa->getId(), (int) $this->escritorio->getId());
        static::getContainer()->get(NomearEtapaDaPastaUseCase::class)->executar($agora, $contexto->pessoa, $contexto->escritorio, 0, 'Outro');

        $erro = $this->recusaDaConfirmacao($proposta);

        self::assertSame(MudarAndamentoFerramenta::MUDOU, $erro->getMessage());
        self::assertSame('Outro', $this->nomeDaEtapa($pasta, 0));
    }

    #[TestDox('reconferir: a pasta foi para a lixeira depois da proposta, a confirmação recusa com "não encontrado"')]
    public function testReconferirPastaNaLixeira(): void
    {
        $pasta = $this->pasta();
        $this->executar(['acao' => 'mudar_fase', 'pasta_id' => $pasta->getId(), 'fase' => 'Protocolo']);
        $proposta = $this->executar(['acao' => 'mudar_fase', 'pasta_id' => $pasta->getId(), 'fase' => 'Abertura']);
        $this->pastaAtual($pasta)->marcarExcluida($this->em()->getReference(User::class, $this->pessoa->getId()), new \DateTimeImmutable());
        $this->em()->flush();

        $erro = $this->recusaDaConfirmacao($proposta);

        self::assertSame(ErroDaFerramenta::MOTIVO_NAO_ENCONTRADO, $erro->motivo);
    }

    /** @return iterable<string, array{0: string}> */
    public static function acoes(): iterable
    {
        yield 'mudar_fase' => ['mudar_fase'];
        yield 'nomear_etapa' => ['nomear_etapa'];
    }

    #[DataProvider('acoes')]
    #[TestDox('pasta de outro escritório, inexistente ou na lixeira: não encontrado: $_dataName')]
    public function testNaoEncontrada(string $acao): void
    {
        $naLixeira = $this->pasta();
        $naLixeira->marcarExcluida($this->pessoa, new \DateTimeImmutable());
        $outro = $this->escritorio('Outro');
        $deOutro = $this->pasta($outro);
        $this->em()->flush();

        foreach ([$naLixeira->getId(), $deOutro->getId(), 999_999_999] as $id) {
            $this->assertMotivo($this->argumentos($acao, (int) $id), ErroDaFerramenta::MOTIVO_NAO_ENCONTRADO);
        }
    }

    #[DataProvider('acoes')]
    #[TestDox('pasta em segredo de justiça: a frase do segredo: $_dataName')]
    public function testEmSegredo(string $acao): void
    {
        $pasta = $this->pastaEmSegredo();

        try {
            $this->executar($this->argumentos($acao, (int) $pasta->getId()));
            self::fail('deveria recusar o segredo');
        } catch (ErroDaFerramenta $e) {
            self::assertSame(ErroDaFerramenta::MOTIVO_EM_SEGREDO, $e->motivo);
            self::assertSame(ErroDaFerramenta::EM_SEGREDO, $e->getMessage());
        }
    }

    #[DataProvider('acoes')]
    #[TestDox('só vê a pasta, sem o recurso de editar: não encontrado: $_dataName')]
    public function testSemEditar(string $acao): void
    {
        $pasta = $this->pasta();
        $leitor = $this->pessoa();
        $this->vinculo($leitor, $this->escritorio, permissoes: self::LEITOR);
        $this->pessoa = $leitor;
        $this->consentimento = $this->consentimentoDe($leitor);

        $this->assertMotivo($this->argumentos($acao, (int) $pasta->getId()), ErroDaFerramenta::MOTIVO_NAO_ENCONTRADO);
        self::assertSame(0, $this->faseAtual($pasta));
    }

    #[DataProvider('acoes')]
    #[TestDox('sem o módulo Pastas: sem permissão: $_dataName')]
    public function testSemModulo(string $acao): void
    {
        $pasta = $this->pasta();
        $semModulo = $this->pessoa();
        $this->vinculo($semModulo, $this->escritorio, permissoes: ['resources.pasta.view', 'resources.pasta.edit']);
        $this->pessoa = $semModulo;
        $this->consentimento = $this->consentimentoDe($semModulo);

        $this->assertMotivo($this->argumentos($acao, (int) $pasta->getId()), ErroDaFerramenta::MOTIVO_SEM_PERMISSAO);
    }

    #[DataProvider('acoes')]
    #[TestDox('chave do Master "expedientes" desligada: função desligada: $_dataName')]
    public function testChaveDesligada(string $acao): void
    {
        $pasta = $this->pasta();
        $estado = new RecursoEstado('expedientes');
        $estado->desativar('Pausa', null, null, new \DateTimeImmutable());
        $this->em()->persist($estado);
        $this->em()->flush();

        $this->assertMotivo($this->argumentos($acao, (int) $pasta->getId()), ErroDaFerramenta::MOTIVO_FUNCAO_DESLIGADA);
    }

    #[TestDox('campo fora da ação, sem a fase ou com tipo errado: pedido inválido')]
    public function testEntradaInvalida(): void
    {
        $pasta = $this->pasta();
        $id = $pasta->getId();

        $this->assertMotivo(['acao' => 'mudar_fase', 'pasta_id' => $id, 'fase' => 'Contrato', 'texto' => 'x'], ErroDaFerramenta::MOTIVO_ENTRADA_INVALIDA);
        $this->assertMotivo(['acao' => 'mudar_fase', 'pasta_id' => $id], ErroDaFerramenta::MOTIVO_ENTRADA_INVALIDA);
        $this->assertMotivo(['acao' => 'mudar_fase', 'pasta_id' => $id, 'fase' => 2.5], ErroDaFerramenta::MOTIVO_ENTRADA_INVALIDA);
        $this->assertMotivo(['acao' => 'mudar_fase', 'pasta_id' => $id, 'fase' => 'Contrato', 'sem_pular' => 'sim'], ErroDaFerramenta::MOTIVO_ENTRADA_INVALIDA);
        $this->assertMotivo(['acao' => 'mudar_fase', 'pasta_id' => $id, 'fase' => 'Contrato', 'motivo' => str_repeat('x', 201)], ErroDaFerramenta::MOTIVO_ENTRADA_INVALIDA);
        $this->assertMotivo(['acao' => 'nomear_etapa', 'pasta_id' => $id, 'fase_de_inicio' => 1], ErroDaFerramenta::MOTIVO_ENTRADA_INVALIDA);
        $this->assertMotivo(['acao' => 'apagar', 'pasta_id' => $id], ErroDaFerramenta::MOTIVO_ENTRADA_INVALIDA);
        $this->assertMotivo(['acao' => 'mudar_fase', 'pasta_id' => 'abc', 'fase' => 1], ErroDaFerramenta::MOTIVO_ENTRADA_INVALIDA);
    }

    /** @return array<string, mixed> */
    private function argumentos(string $acao, int $pastaId): array
    {
        return $acao === 'mudar_fase'
            ? ['acao' => $acao, 'pasta_id' => $pastaId, 'fase' => 'Documentação']
            : ['acao' => $acao, 'pasta_id' => $pastaId, 'fase_de_inicio' => 'Abertura', 'texto' => 'Triagem'];
    }

    /** @param array<string, mixed> $argumentos */
    private function executar(array $argumentos): RespostaDaFerramenta
    {
        static::getContainer()->get('services_resetter')->reset();

        return static::getContainer()->get(MudarAndamentoFerramenta::class)->executar($argumentos, $this->chamada());
    }

    /** Confirma pela `confirmar_acao` de verdade, com o resumo exato da proposta. */
    private function confirmar(RespostaDaFerramenta $proposta): RespostaDaFerramenta
    {
        static::getContainer()->get('services_resetter')->reset();
        $argumentos = ['proposta_id' => $proposta->dados['proposta']['proposta_id'], 'resumo' => $proposta->dados['proposta']['resumo']];

        return static::getContainer()->get(ConfirmarAcaoFerramenta::class)->executar($argumentos, $this->chamada());
    }

    private function recusaDaConfirmacao(RespostaDaFerramenta $proposta): ErroDaFerramenta
    {
        try {
            $this->confirmar($proposta);
        } catch (ErroDaFerramenta $e) {
            return $e;
        }
        self::fail('a confirmação deveria recusar');
    }

    /** @param array<string, mixed> $argumentos */
    private function assertRecusa(array $argumentos, string $mensagem): void
    {
        try {
            $this->executar($argumentos);
        } catch (ErroDaFerramenta $e) {
            self::assertSame(ErroDaFerramenta::MOTIVO_RECUSADA, $e->motivo);
            self::assertSame($mensagem, $e->getMessage());

            return;
        }
        self::fail('deveria recusar: ' . $mensagem);
    }

    /** @param array<string, mixed> $argumentos */
    private function assertMotivo(array $argumentos, string $motivo): void
    {
        try {
            $this->executar($argumentos);
        } catch (ErroDaFerramenta $e) {
            self::assertSame($motivo, $e->motivo);

            return;
        }
        self::fail('deveria recusar com ' . $motivo);
    }

    private function chamada(): ChamadaDoConector
    {
        $contexto = $this->portao()->abrir((int) $this->pessoa->getId(), (int) $this->escritorio->getId());

        return new ChamadaDoConector($contexto, 41, (int) $this->consentimento->getId(), 'claude.ai', ['mcp:leitura', 'mcp:escrita'], new \DateTimeImmutable('+7 days'));
    }

    private function consentimentoDe(User $pessoa): ConsentimentoDoConector
    {
        $consentimento = new ConsentimentoDoConector($pessoa, $this->escritorio, $this->consentimento->getCliente(), ['mcp:leitura', 'mcp:escrita'], new \DateTimeImmutable());
        $this->em()->persist($consentimento);
        $this->em()->flush();

        return $consentimento;
    }

    private function contarPropostas(): int
    {
        return (int) $this->em()->getConnection()->fetchOne('SELECT COUNT(*) FROM conector_ia_proposta');
    }

    private function pasta(?Tenant $escritorio = null): Pasta
    {
        $pasta = (new Pasta())->setNup('AND-' . uniqid());
        $pasta->setTenant($escritorio ?? $this->escritorio);
        $this->em()->persist($pasta);
        $this->em()->flush();

        return $pasta;
    }

    private function pastaAtual(Pasta $pasta): Pasta
    {
        $this->em()->clear();

        return $this->em()->find(Pasta::class, $pasta->getId()) ?? throw new \LogicException('pasta sumiu');
    }

    /** Troca o contrato direto no banco, como faria outra pessoa pela tela do Financeiro. */
    private function trocarContrato(Pasta $pasta, string $situacao): void
    {
        $this->pastaAtual($pasta)->setSituacaoContrato($situacao);
        $this->em()->flush();
    }

    /** @return array{via: string, fases: list<array{0: string, 1: string}>, atual: int} */
    private function estado(Pasta $pasta, ?Tenant $escritorio = null): array
    {
        return static::getContainer()->get(AndamentoDaPasta::class)->estado($this->pastaAtual($pasta), $escritorio ?? $this->escritorio);
    }

    private function faseAtual(Pasta $pasta, ?Tenant $escritorio = null): int
    {
        return $this->estado($pasta, $escritorio)['atual'];
    }

    private function nomeDaEtapa(Pasta $pasta, int $origem): ?string
    {
        $atual = $this->pastaAtual($pasta);

        return static::getContainer()->get(RotuloDeEtapaRepository::class)->daOrigem($atual, AndamentoDaPasta::via($atual), $origem, $this->escritorio)?->getTexto();
    }

    private function ocultarFinanceiro(Pasta $pasta): void
    {
        $this->em()->persist(AbaOcultaDaPasta::paraTodaAEquipe($this->escritorio, $pasta, AbaDaPasta::Financeiro, $this->pessoa(), new \DateTimeImmutable()));
        $this->em()->flush();
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
}
