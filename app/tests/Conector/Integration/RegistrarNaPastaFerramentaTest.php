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
use App\Conector\Ferramenta\RegistrarNaPastaExecutor;
use App\Conector\Ferramenta\RegistrarNaPastaFerramenta;
use App\Conector\Repository\PropostaDoConectorRepository;
use App\Conector\Service\GuardaDaAcaoDoConector;
use App\Conector\Service\RequisicaoDoConector;
use App\Conector\Service\SegredoOpaco;
use App\Conector\UseCase\ConfirmarPropostaDoConectorUseCase;
use App\Entity\Auth\User;
use App\Entity\Auth\UserTenant;
use App\Entity\Notificacao;
use App\Entity\Tenant\Tenant;
use App\Master\Entity\RecursoEstado;
use App\Pasta\Entity\AbaDaPasta;
use App\Pasta\Entity\AbaOcultaDaPasta;
use App\Pasta\Entity\CadeadoDaPasta;
use App\Pasta\Entity\ModoDoCadeado;
use App\Pasta\Entity\Pasta;
use App\Pasta\Entity\PastaMensagem;
use App\Pasta\Entity\PastaObservacaoDetalhes;
use App\Pasta\Entity\PastaObservacaoFinanceira;
use App\Pasta\Entity\PastaProcesso;
use App\Pasta\UseCase\EditarMensagemPastaUseCase;
use App\Pasta\UseCase\EditarObservacaoDetalhesUseCase;
use App\Pasta\UseCase\EditarObservacaoFinanceiraUseCase;
use App\Pasta\UseCase\ExcluirMensagemPastaUseCase;
use App\Pasta\UseCase\ExcluirObservacaoDetalhesUseCase;
use App\Pasta\UseCase\ExcluirObservacaoFinanceiraUseCase;
use App\Processo\Entity\Processo;
use App\Tenant\Enum\SituacaoNaEquipe;
use App\Tests\Conector\Support\CenarioDoConectorTrait;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestDox;
use Psr\Clock\ClockInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\NativeClock;

/**
 * `registrar_na_pasta` com o banco e o portão de verdade (spec mcp-remoto-oauth 6.2, 6.6 e 6.7), nos três lugares
 * (Registro da aba Dados, Relatório inicial da aba Detalhes e Relatório financeiro da aba Financeiro): escrever,
 * responder e destacar são diretos; editar e excluir vão por proposta e só gravam na confirmação; a reconferência recusa
 * se o texto mudou; quem edita e exclui é o mesmo que a tela deixa; e as recusas (outro escritório, lixeira, segredo,
 * sem editar, aba oculta, Financeiro, registro de outra pasta, menção a colega de fora).
 */
#[CoversClass(RegistrarNaPastaFerramenta::class)]
final class RegistrarNaPastaFerramentaTest extends KernelTestCase
{
    use CenarioDoConectorTrait;

    private const EDITOR = ['modules.pastas.view', 'resources.pasta.view', 'resources.pasta.edit'];

    private const CLASSES = [
        'registro' => PastaMensagem::class,
        'relatorio_inicial' => PastaObservacaoDetalhes::class,
        'relatorio_financeiro' => PastaObservacaoFinanceira::class,
    ];

    private const TABELAS = [
        'registro' => 'pasta_mensagem',
        'relatorio_inicial' => 'pasta_observacao_detalhes',
        'relatorio_financeiro' => 'pasta_observacao_financeira',
    ];

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
        $doConector->liberarPeloMaster();
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

    /** @return iterable<string, array{string}> */
    public static function lugares(): iterable
    {
        foreach (array_keys(self::CLASSES) as $lugar) {
            yield $lugar => [$lugar];
        }
    }

    // ---------------------------------------------------------------- caminho feliz

    #[DataProvider('lugares')]
    #[TestDox('escrever grava direto pelo UseCase da tela, com a pessoa como autora, e não cria proposta: $_dataName')]
    public function testEscrever(string $lugar): void
    {
        $resposta = $this->executar(['lugar' => $lugar, 'acao' => 'escrever', 'pasta_id' => $this->pasta->getId(), 'texto' => "Cliente ligou.\nPediu retorno amanhã."]);

        $id = (int) $resposta->dados['registro']['id'];
        $linha = $this->linha($lugar, $id);
        self::assertSame('<p>Cliente ligou.</p><p>Pediu retorno amanhã.</p>', $linha['conteudo']);
        self::assertSame($this->pessoa->getId(), (int) $linha['autor_id']);
        self::assertSame("Cliente ligou.\nPediu retorno amanhã.", $resposta->dados['registro']['texto']);
        self::assertSame(RegistrarNaPastaFerramenta::AVISOS[$lugar], $resposta->dados['aviso']);
        self::assertSame(['pasta' => [$this->pasta->getId()], 'registro' => [$id]], $resposta->ids);
        self::assertSame(0, $this->contarPropostas());
    }

    #[DataProvider('lugares')]
    #[TestDox('responder grava a resposta presa ao registro e avisa o autor dele no sino, como a tela: $_dataName')]
    public function testResponder(string $lugar): void
    {
        $doColega = $this->registro($lugar, $this->colega);

        $resposta = $this->executar(['lugar' => $lugar, 'acao' => 'responder', 'pasta_id' => $this->pasta->getId(), 'registro_id' => $doColega->getId(), 'texto' => 'Retornei a ligação.']);

        self::assertSame($doColega->getId(), $resposta->dados['registro']['resposta_a']);
        self::assertSame($doColega->getId(), (int) $this->linha($lugar, (int) $resposta->dados['registro']['id'])['resposta_a_id']);
        self::assertSame(1, $this->contarAvisos($this->colega, Notificacao::TIPO_PASTA_RESPOSTA_REGISTRO));
    }

    #[DataProvider('lugares')]
    #[TestDox('mencoes com id de colega ativo entram no começo do texto, como menção, e o colega é avisado: $_dataName')]
    public function testMencao(string $lugar): void
    {
        $this->executar(['lugar' => $lugar, 'acao' => 'escrever', 'pasta_id' => $this->pasta->getId(), 'texto' => 'Veja o contrato.', 'mencoes' => [$this->colega->getId()]]);

        $conteudo = (string) $this->em()->getConnection()->fetchOne('SELECT conteudo FROM ' . self::TABELAS[$lugar] . ' WHERE pasta_id = ? ORDER BY id DESC LIMIT 1', [$this->pasta->getId()]);
        self::assertStringContainsString('[Maria Colega](user:' . $this->colega->getId() . ')', $conteudo);
        self::assertSame(1, $this->contarAvisos($this->colega, Notificacao::TIPO_PASTA_MENCAO_REGISTRO));
    }

    #[TestDox('marcação de menção escrita no texto vira texto comum: menção só entra por mencoes')]
    public function testMarcacaoNoTextoNaoMenciona(): void
    {
        $texto = 'Oi @[Maria Colega](user:' . $this->colega->getId() . ') tudo bem?';

        $resposta = $this->executar(['lugar' => 'registro', 'acao' => 'escrever', 'pasta_id' => $this->pasta->getId(), 'texto' => $texto]);

        self::assertStringNotContainsString('(user:', (string) $this->linha('registro', (int) $resposta->dados['registro']['id'])['conteudo']);
        self::assertSame(0, $this->contarAvisos($this->colega, Notificacao::TIPO_PASTA_MENCAO_REGISTRO));
    }

    #[TestDox('o texto do assistente é escapado: sinal de menor e tag viram texto, nunca marcação')]
    public function testTextoEscapado(): void
    {
        $resposta = $this->executar(['lugar' => 'registro', 'acao' => 'escrever', 'pasta_id' => $this->pasta->getId(), 'texto' => 'Valor < 100 <script>alert(1)</script>']);

        $conteudo = (string) $this->linha('registro', (int) $resposta->dados['registro']['id'])['conteudo'];
        self::assertStringNotContainsString('<script>', $conteudo);
        self::assertStringContainsString('&lt;script&gt;', $conteudo);
    }

    #[DataProvider('lugares')]
    #[TestDox('destacar marca com a cor da paleta e tirar desmarca, direto: $_dataName')]
    public function testDestacar(string $lugar): void
    {
        $registro = $this->registro($lugar, $this->colega);
        $pedido = ['lugar' => $lugar, 'acao' => 'destacar', 'pasta_id' => $this->pasta->getId(), 'registro_id' => $registro->getId()];

        $marcado = $this->executar($pedido + ['cor' => '#6eaef2']);

        self::assertSame([true, '#6eaef2', 'Azul'], [$marcado->dados['destacado'], $marcado->dados['cor'], $marcado->dados['cor_nome']]);
        self::assertSame(1, $this->contarDestaques());

        $tirado = $this->executar($pedido + ['tirar' => true]);

        self::assertFalse($tirado->dados['destacado']);
        self::assertSame(0, $this->contarDestaques());
        self::assertSame(0, $this->contarPropostas());
    }

    #[TestDox('destacar outro registro do mesmo lugar desfaz o destaque anterior e a resposta diz qual foi')]
    public function testDestacarOutroDesfazOAnterior(): void
    {
        $primeiro = $this->registro('registro', $this->colega);
        $segundo = $this->registro('registro', $this->colega);
        $this->executar(['lugar' => 'registro', 'acao' => 'destacar', 'pasta_id' => $this->pasta->getId(), 'registro_id' => $primeiro->getId()]);

        $resposta = $this->executar(['lugar' => 'registro', 'acao' => 'destacar', 'pasta_id' => $this->pasta->getId(), 'registro_id' => $segundo->getId()]);

        self::assertSame(['registro_id' => $primeiro->getId(), 'autor' => 'Maria Colega'], $resposta->dados['destaque_desfeito']);
        self::assertSame(1, $this->contarDestaques());
    }

    // ---------------------------------------------------------------- proposta

    #[DataProvider('lugares')]
    #[TestDox('editar vira proposta com o texto de agora e o novo, não grava, e a confirmação grava: $_dataName')]
    public function testEditarPorProposta(string $lugar): void
    {
        $meu = $this->registro($lugar, $this->pessoa, '<p>Texto antigo</p>');

        $resposta = $this->executar(['lugar' => $lugar, 'acao' => 'editar', 'pasta_id' => $this->pasta->getId(), 'registro_id' => $meu->getId(), 'texto' => 'Texto corrigido']);

        self::assertSame('aguardando_confirmacao', $resposta->dados['estado']);
        $resumo = $resposta->dados['proposta']['resumo'];
        self::assertStringContainsString('Texto de agora: Texto antigo', $resumo);
        self::assertStringContainsString('Texto novo: Texto corrigido', $resumo);
        self::assertSame('<p>Texto antigo</p>', $this->linha($lugar, (int) $meu->getId())['conteudo'], 'a proposta não grava');
        self::assertSame(1, $this->contarPropostas());

        $resultado = $this->confirmar($resposta);

        self::assertSame('editar', $resultado->dados['resultado']['operacao']);
        $linha = $this->linha($lugar, (int) $meu->getId());
        self::assertSame(['<p>Texto corrigido</p>', '<p>Texto antigo</p>'], [$linha['conteudo'], $linha['conteudo_anterior']]);
    }

    #[DataProvider('lugares')]
    #[TestDox('excluir vira proposta, não apaga, e a confirmação apaga a linha: $_dataName')]
    public function testExcluirPorProposta(string $lugar): void
    {
        $meu = $this->registro($lugar, $this->pessoa, '<p>Anotação errada</p>');

        $resposta = $this->executar(['lugar' => $lugar, 'acao' => 'excluir', 'pasta_id' => $this->pasta->getId(), 'registro_id' => $meu->getId()]);

        self::assertSame('aguardando_confirmacao', $resposta->dados['estado']);
        self::assertStringContainsString('Anotação errada', $resposta->dados['proposta']['resumo']);
        self::assertNotNull($this->linhaOuNulo($lugar, (int) $meu->getId()), 'a proposta não apaga');

        $resultado = $this->confirmar($resposta);

        self::assertSame($meu->getId(), $resultado->dados['resultado']['registro_excluido']);
        self::assertNull($this->linhaOuNulo($lugar, (int) $meu->getId()));
    }

    #[TestDox('editar com menção: a proposta valida o colega e a confirmação grava a menção e avisa')]
    public function testEditarComMencao(): void
    {
        $meu = $this->registro('relatorio_inicial', $this->pessoa);

        $resposta = $this->executar(['lugar' => 'relatorio_inicial', 'acao' => 'editar', 'pasta_id' => $this->pasta->getId(), 'registro_id' => $meu->getId(), 'texto' => 'Agora com menção', 'mencoes' => [$this->colega->getId()]]);
        self::assertStringContainsString('Texto novo: @Maria Colega Agora com menção', $resposta->dados['proposta']['resumo']);
        self::assertSame(0, $this->contarAvisos($this->colega, Notificacao::TIPO_PASTA_MENCAO_REGISTRO));

        $this->confirmar($resposta);

        self::assertStringContainsString('(user:' . $this->colega->getId() . ')', (string) $this->linha('relatorio_inicial', (int) $meu->getId())['conteudo']);
        self::assertSame(1, $this->contarAvisos($this->colega, Notificacao::TIPO_PASTA_MENCAO_REGISTRO));
    }

    #[DataProvider('lugares')]
    #[TestDox('reconferir recusa se o texto mudou desde a proposta, e a confirmação deixa o texto como está: $_dataName')]
    public function testReconferirRecusaTextoQueMudou(string $lugar): void
    {
        $meu = $this->registro($lugar, $this->pessoa, '<p>Texto antigo</p>');
        $resposta = $this->executar(['lugar' => $lugar, 'acao' => 'editar', 'pasta_id' => $this->pasta->getId(), 'registro_id' => $meu->getId(), 'texto' => 'Texto novo']);
        $dados = $this->dadosDaProposta($resposta);
        $this->gerenciado($meu)->setConteudo('<p>Mudado na tela</p>');
        $this->em()->flush();

        $erro = $this->recusa(fn () => $this->ferramenta()->reconferir($lugar, $dados, $this->chamada()));
        self::assertSame([ErroDaFerramenta::MOTIVO_RECUSADA, RegistrarNaPastaFerramenta::REGISTRO_MUDOU], [$erro->motivo, $erro->getMessage()]);

        $erro = $this->recusa(fn () => $this->confirmar($resposta));
        self::assertSame(RegistrarNaPastaFerramenta::REGISTRO_MUDOU, $erro->getMessage());
        self::assertSame('<p>Mudado na tela</p>', $this->linha($lugar, (int) $meu->getId())['conteudo']);
    }

    #[TestDox('reconferir refaz as recusas: colega mencionado que saiu, aba Detalhes oculta e pasta na lixeira')]
    public function testReconferirRefazAsConferencias(): void
    {
        $meu = $this->registro('relatorio_inicial', $this->pessoa);
        $dados = $this->dadosDaProposta($this->executar(['lugar' => 'relatorio_inicial', 'acao' => 'editar', 'pasta_id' => $this->pasta->getId(), 'registro_id' => $meu->getId(), 'texto' => 'Novo', 'mencoes' => [$this->colega->getId()]]));

        // "Colega ativo" é o vínculo com o escritório (`findColaboradorAtivoPorIdETenant`), não a conta.
        $this->em()->getRepository(UserTenant::class)->findOneBy(['user' => $this->colega->getId(), 'tenant' => $this->escritorio->getId()])?->alterarSituacao(SituacaoNaEquipe::Suspenso);
        $this->em()->flush();
        $erro = $this->recusa(fn () => $this->ferramenta()->reconferir('relatorio_inicial', $dados, $this->chamada()));
        self::assertSame(RegistrarNaPastaFerramenta::MENCAO_INVALIDA, $erro->getMessage());

        $this->ocultar($this->pasta, AbaDaPasta::Detalhes);
        $erro = $this->recusa(fn () => $this->ferramenta()->reconferir('relatorio_inicial', $dados, $this->chamada()));
        self::assertSame(ErroDaFerramenta::MOTIVO_NAO_ENCONTRADO, $erro->motivo);

        $this->gerenciado($this->pasta)->marcarExcluida($this->gerenciado($this->pessoa), new \DateTimeImmutable());
        $this->em()->flush();
        $erro = $this->recusa(fn () => $this->ferramenta()->reconferir('relatorio_inicial', $dados, $this->chamada()));
        self::assertSame(ErroDaFerramenta::MOTIVO_NAO_ENCONTRADO, $erro->motivo);
    }

    // ---------------------------------------------------------------- paridade com a tela

    #[DataProvider('lugares')]
    #[TestDox('editar e excluir: o conector deixa exatamente o que a tela deixa (o meu dentro da janela sim; o do colega e o meu antigo não): $_dataName')]
    public function testParidadeComATelaNoEditarEExcluir(string $lugar): void
    {
        $meu = $this->registro($lugar, $this->pessoa);
        $doColega = $this->registro($lugar, $this->colega);
        $antigo = $this->registro($lugar, $this->pessoa);
        $this->em()->getConnection()->executeStatement('UPDATE ' . self::TABELAS[$lugar] . ' SET criada_em = ? WHERE id = ?', [(new \DateTimeImmutable('-20 minutes'))->format('Y-m-d H:i:s'), $antigo->getId()]);

        foreach ([[$meu, true], [$doColega, false], [$antigo, false]] as [$registro, $deixa]) {
            foreach (['editar' => RegistrarNaPastaFerramenta::NAO_EDITA, 'excluir' => RegistrarNaPastaFerramenta::NAO_EXCLUI] as $acao => $recusa) {
                $pedido = ['lugar' => $lugar, 'acao' => $acao, 'pasta_id' => $this->pasta->getId(), 'registro_id' => $registro->getId()] + ($acao === 'editar' ? ['texto' => 'Novo ' . uniqid()] : []);
                $conector = $this->tentar($pedido);
                self::assertSame($deixa, $this->telaDeixa($lugar, $acao, $registro), 'a tela');
                self::assertSame($deixa ? 'aguardando_confirmacao' : $recusa, $conector, 'o conector');
            }
        }
    }

    #[TestDox('cartão automático do sistema no Registro: não se edita, não se exclui e não se responde')]
    public function testCartaoDoSistema(): void
    {
        $cartao = $this->registro('registro', $this->pessoa, '<p>Fase mudou</p>', 'andamento');
        $pedido = ['lugar' => 'registro', 'pasta_id' => $this->pasta->getId(), 'registro_id' => $cartao->getId()];

        self::assertSame(RegistrarNaPastaFerramenta::NAO_EDITA, $this->tentar($pedido + ['acao' => 'editar', 'texto' => 'Outro']));
        self::assertSame(RegistrarNaPastaFerramenta::NAO_EXCLUI, $this->tentar($pedido + ['acao' => 'excluir']));
        self::assertSame(RegistrarNaPastaFerramenta::NAO_RESPONDE_SISTEMA, $this->tentar($pedido + ['acao' => 'responder', 'texto' => 'Resposta']));
        self::assertSame(0, $this->contarPropostas());
    }

    // ---------------------------------------------------------------- recusas de acesso

    #[DataProvider('lugares')]
    #[TestDox('pasta de outro escritório, na lixeira, trancada ou inexistente: não encontrado: $_dataName')]
    public function testPastaNaoEncontrada(string $lugar): void
    {
        $outro = $this->escritorio('Outro');
        $deOutro = $this->pasta($outro);
        $naLixeira = $this->pasta();
        $naLixeira->marcarExcluida($this->pessoa, new \DateTimeImmutable());
        $trancada = $this->pasta();
        $this->em()->persist(new CadeadoDaPasta($this->escritorio, $trancada, ModoDoCadeado::Todos, null, new \DateTimeImmutable()));
        $this->em()->flush();

        foreach ([$deOutro->getId(), $naLixeira->getId(), $trancada->getId(), 999_999_999] as $id) {
            $this->assertMotivo(['lugar' => $lugar, 'acao' => 'escrever', 'pasta_id' => $id, 'texto' => 'Invasão'], ErroDaFerramenta::MOTIVO_NAO_ENCONTRADO);
        }
        self::assertSame(0, $this->contarRegistros($lugar));
    }

    #[DataProvider('lugares')]
    #[TestDox('pasta com processo em segredo de justiça: recusa com a frase do segredo: $_dataName')]
    public function testEmSegredo(string $lugar): void
    {
        $pasta = $this->pastaEmSegredo();

        $erro = $this->recusa(fn () => $this->executar(['lugar' => $lugar, 'acao' => 'escrever', 'pasta_id' => $pasta->getId(), 'texto' => 'Texto']));

        self::assertSame([ErroDaFerramenta::MOTIVO_EM_SEGREDO, ErroDaFerramenta::EM_SEGREDO], [$erro->motivo, $erro->getMessage()]);
        self::assertSame(0, $this->contarRegistros($lugar));
    }

    #[DataProvider('lugares')]
    #[TestDox('quem só vê a pasta (sem o recurso de editar) não escreve nem destaca: não encontrado: $_dataName')]
    public function testSemEditar(string $lugar): void
    {
        $leitor = $this->pessoaComPermissoes(['modules.pastas.view', 'resources.pasta.view']);
        $registro = $this->registro($lugar, $this->colega);

        $this->assertMotivo(['lugar' => $lugar, 'acao' => 'escrever', 'pasta_id' => $this->pasta->getId(), 'texto' => 'Texto'], ErroDaFerramenta::MOTIVO_NAO_ENCONTRADO, $leitor);
        $this->assertMotivo(['lugar' => $lugar, 'acao' => 'destacar', 'pasta_id' => $this->pasta->getId(), 'registro_id' => $registro->getId()], ErroDaFerramenta::MOTIVO_NAO_ENCONTRADO, $leitor);
        self::assertSame([1, 0], [$this->contarRegistros($lugar), $this->contarDestaques()]);
    }

    #[TestDox('aba Detalhes oculta barra o Relatório inicial e aba Financeiro oculta barra o Relatório financeiro: não encontrado')]
    public function testAbaDoLugarOculta(): void
    {
        $this->ocultar($this->pasta, AbaDaPasta::Detalhes);
        $this->ocultar($this->pasta, AbaDaPasta::Financeiro);

        foreach (['relatorio_inicial', 'relatorio_financeiro'] as $lugar) {
            $this->assertMotivo(['lugar' => $lugar, 'acao' => 'escrever', 'pasta_id' => $this->pasta->getId(), 'texto' => 'Texto'], ErroDaFerramenta::MOTIVO_NAO_ENCONTRADO);
        }
        $this->executar(['lugar' => 'registro', 'acao' => 'escrever', 'pasta_id' => $this->pasta->getId(), 'texto' => 'O Registro não depende delas']);
        self::assertSame([1, 0, 0], [$this->contarRegistros('registro'), $this->contarRegistros('relatorio_inicial'), $this->contarRegistros('relatorio_financeiro')]);
    }

    #[TestDox('no Registro, com a aba Dados oculta, o registro por id some (não encontrado); escrever segue a tabela da 6.6, sem aba')]
    public function testAbaDadosOcultaNoRegistroPorId(): void
    {
        $registro = $this->registro('registro', $this->colega);
        $this->ocultar($this->pasta, AbaDaPasta::Dados);

        foreach (['responder' => ['texto' => 'Oi'], 'destacar' => [], 'excluir' => []] as $acao => $extra) {
            $this->assertMotivo(['lugar' => 'registro', 'acao' => $acao, 'pasta_id' => $this->pasta->getId(), 'registro_id' => $registro->getId()] + $extra, ErroDaFerramenta::MOTIVO_NAO_ENCONTRADO);
        }
        $this->executar(['lugar' => 'registro', 'acao' => 'escrever', 'pasta_id' => $this->pasta->getId(), 'texto' => 'Texto']);
        self::assertSame(2, $this->contarRegistros('registro'));
    }

    #[TestDox('Financeiro: sem ver o Financeiro, o Relatório financeiro e o cartão de contrato do Registro dão não encontrado; vendo, passam')]
    public function testFinanceiroSoParaQuemVe(): void
    {
        $cartao = $this->registro('registro', $this->colega, '<p>Contrato assinado</p>', 'contrato');
        $destacarCartao = ['lugar' => 'registro', 'acao' => 'destacar', 'pasta_id' => $this->pasta->getId(), 'registro_id' => $cartao->getId()];
        $this->executar($destacarCartao);
        self::assertSame(1, $this->contarDestaques(), 'quem vê o Financeiro destaca o cartão');

        $this->ocultar($this->pasta, AbaDaPasta::Financeiro);

        $this->assertMotivo($destacarCartao + ['tirar' => true], ErroDaFerramenta::MOTIVO_NAO_ENCONTRADO);
        $this->assertMotivo(['lugar' => 'relatorio_financeiro', 'acao' => 'escrever', 'pasta_id' => $this->pasta->getId(), 'texto' => 'Texto'], ErroDaFerramenta::MOTIVO_NAO_ENCONTRADO);
        self::assertSame(1, $this->contarDestaques());
    }

    #[TestDox('sem o módulo Pastas: sem permissão')]
    public function testSemModulo(): void
    {
        $semModulo = $this->pessoaComPermissoes(['resources.pasta.view', 'resources.pasta.edit']);
        $pedido = ['lugar' => 'registro', 'acao' => 'escrever', 'pasta_id' => $this->pasta->getId(), 'texto' => 'Texto'];

        $this->assertMotivo($pedido, ErroDaFerramenta::MOTIVO_SEM_PERMISSAO, $semModulo);
    }

    #[TestDox('chave expedientes desligada: função desligada')]
    public function testChaveDesligada(): void
    {
        $pedido = ['lugar' => 'registro', 'acao' => 'escrever', 'pasta_id' => $this->pasta->getId(), 'texto' => 'Texto'];
        $estado = new RecursoEstado('expedientes');
        $estado->desativar('teste', null, null, new \DateTimeImmutable());
        $this->em()->persist($estado);
        $this->em()->flush();
        $this->assertMotivo($pedido, ErroDaFerramenta::MOTIVO_FUNCAO_DESLIGADA);
    }

    #[TestDox('registro de outra pasta, de outro escritório ou de outro lugar: não encontrado em responder, editar, destacar e excluir')]
    public function testRegistroDeOutraPasta(): void
    {
        $outro = $this->escritorio('Outro');
        $alheios = [
            $this->registro('registro', $this->pessoa, pasta: $this->pasta()),
            $this->registro('registro', $this->pessoa, pasta: $this->pasta($outro), escritorio: $outro),
            $this->registro('relatorio_inicial', $this->pessoa),
        ];

        foreach ($alheios as $registro) {
            foreach (['responder' => ['texto' => 'Oi'], 'editar' => ['texto' => 'Oi'], 'destacar' => [], 'excluir' => []] as $acao => $extra) {
                $this->assertMotivo(['lugar' => 'registro', 'acao' => $acao, 'pasta_id' => $this->pasta->getId(), 'registro_id' => $registro->getId()] + $extra, ErroDaFerramenta::MOTIVO_NAO_ENCONTRADO);
            }
        }
        self::assertSame([0, 0], [$this->contarPropostas(), $this->contarDestaques()]);
    }

    // ---------------------------------------------------------------- recusas de regra e de forma

    #[TestDox('menção a colega suspenso, sem vínculo ou de outro escritório: recusa, sem gravar nada')]
    public function testMencaoAColegaDeFora(): void
    {
        $suspenso = $this->pessoa();
        $this->vinculo($suspenso, $this->escritorio, permissoes: self::EDITOR)->alterarSituacao(SituacaoNaEquipe::Suspenso);
        $deFora = $this->pessoa();
        $this->vinculo($deFora, $this->escritorio('Outro'), permissoes: self::EDITOR);
        $this->em()->flush();

        foreach ([$suspenso, $deFora] as $quem) {
            $this->assertRecusada(['lugar' => 'registro', 'acao' => 'escrever', 'pasta_id' => $this->pasta->getId(), 'texto' => 'Oi', 'mencoes' => [$this->colega->getId(), $quem->getId()]], RegistrarNaPastaFerramenta::MENCAO_INVALIDA);
        }
        self::assertSame(0, $this->contarRegistros('registro'));
    }

    #[TestDox('texto vazio recusa com mensagem fixa; cor fora da paleta recusa; tirar com cor é pedido inválido')]
    public function testValores(): void
    {
        $registro = $this->registro('registro', $this->colega);
        $destacar = ['lugar' => 'registro', 'acao' => 'destacar', 'pasta_id' => $this->pasta->getId(), 'registro_id' => $registro->getId()];

        $this->assertRecusada(['lugar' => 'registro', 'acao' => 'escrever', 'pasta_id' => $this->pasta->getId(), 'texto' => "  \n "], RegistrarNaPastaFerramenta::TEXTO_INVALIDO);
        $this->assertRecusada($destacar + ['cor' => '#000000'], RegistrarNaPastaFerramenta::COR_INVALIDA);
        $this->assertMotivo($destacar + ['cor' => '#6eaef2', 'tirar' => true], ErroDaFerramenta::MOTIVO_ENTRADA_INVALIDA);
        self::assertSame([1, 0], [$this->contarRegistros('registro'), $this->contarDestaques()]);
    }

    #[TestDox('lugar ou ação fora da lista, campo fora da ação, obrigatório faltando e tipo errado: pedido inválido')]
    public function testEntradaInvalida(): void
    {
        $pasta = $this->pasta->getId();
        $casos = [
            ['acao' => 'escrever', 'pasta_id' => $pasta, 'texto' => 'Oi'],
            ['lugar' => 'push', 'acao' => 'escrever', 'pasta_id' => $pasta, 'texto' => 'Oi'],
            ['lugar' => 'registro', 'acao' => 'apagar_tudo', 'pasta_id' => $pasta],
            ['lugar' => 'registro', 'acao' => 'escrever', 'pasta_id' => $pasta, 'texto' => 'Oi', 'registro_id' => 1],
            ['lugar' => 'registro', 'acao' => 'excluir', 'pasta_id' => $pasta, 'registro_id' => 1, 'texto' => 'Oi'],
            ['lugar' => 'registro', 'acao' => 'responder', 'pasta_id' => $pasta, 'texto' => 'Oi'],
            ['lugar' => 'registro', 'acao' => 'editar', 'pasta_id' => $pasta, 'registro_id' => 1],
            ['lugar' => 'registro', 'acao' => 'escrever', 'pasta_id' => $pasta, 'texto' => 'Oi', 'mencoes' => 'todos'],
            ['lugar' => 'registro', 'acao' => 'destacar', 'pasta_id' => $pasta, 'registro_id' => 1, 'tirar' => 'sim'],
            ['lugar' => 'registro', 'acao' => 'escrever', 'pasta_id' => 0, 'texto' => 'Oi'],
        ];
        foreach ($casos as $argumentos) {
            $this->assertMotivo($argumentos, ErroDaFerramenta::MOTIVO_ENTRADA_INVALIDA);
        }
    }

    #[TestDox('o texto do banco sai protegido: CPF no registro e no resumo da proposta vira [CPF]')]
    public function testTextoProtegido(): void
    {
        $meu = $this->registro('registro', $this->pessoa, '<p>Chamar 529.982.247-25 para assinar</p>');

        $resposta = $this->executar(['lugar' => 'registro', 'acao' => 'excluir', 'pasta_id' => $this->pasta->getId(), 'registro_id' => $meu->getId()]);

        $resumo = $resposta->dados['proposta']['resumo'];
        self::assertStringNotContainsString('529.982.247-25', $resumo);
        self::assertStringContainsString('[CPF]', $resumo);
    }

    // ---------------------------------------------------------------- apoio

    /** @param array<string, mixed> $argumentos */
    private function executar(array $argumentos, ?User $pessoa = null): RespostaDaFerramenta
    {
        return $this->ferramenta()->executar($argumentos, $this->chamada($pessoa));
    }

    /**
     * O estado da proposta, se a chamada propôs, ou a mensagem da recusa.
     *
     * @param array<string, mixed> $argumentos
     */
    private function tentar(array $argumentos): string
    {
        try {
            return (string) $this->executar($argumentos)->dados['estado'];
        } catch (ErroDaFerramenta $erro) {
            return $erro->getMessage();
        }
    }

    /** O que a tela responde (o `podeEditar`/`podeExcluir` do UseCase dela) para a pessoa e o registro. */
    private function telaDeixa(string $lugar, string $acao, PastaMensagem|PastaObservacaoDetalhes|PastaObservacaoFinanceira $registro): bool
    {
        $c = static::getContainer();
        $registro = $this->gerenciado($registro);
        $pessoa = $this->gerenciado($this->pessoa);
        $escritorio = $this->gerenciado($this->escritorio);
        $useCase = $c->get(match ($lugar) {
            'registro' => $acao === 'editar' ? EditarMensagemPastaUseCase::class : ExcluirMensagemPastaUseCase::class,
            'relatorio_inicial' => $acao === 'editar' ? EditarObservacaoDetalhesUseCase::class : ExcluirObservacaoDetalhesUseCase::class,
            default => $acao === 'editar' ? EditarObservacaoFinanceiraUseCase::class : ExcluirObservacaoFinanceiraUseCase::class,
        });

        return $acao === 'editar' ? $useCase->podeEditar($registro, $pessoa, $escritorio) : $useCase->podeExcluir($registro, $pessoa, $escritorio);
    }

    private function ferramenta(): RegistrarNaPastaFerramenta
    {
        return static::getContainer()->get(RegistrarNaPastaFerramenta::class);
    }

    /** @param array<string, mixed> $argumentos */
    private function assertMotivo(array $argumentos, string $motivo, ?User $pessoa = null): void
    {
        $erro = $this->recusa(fn () => $this->executar($argumentos, $pessoa));
        self::assertSame($motivo, $erro->motivo, (string) json_encode($argumentos));
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
        $consentimento = new ConsentimentoDoConector($this->gerenciado($pessoa), $this->gerenciado($this->escritorio), $this->gerenciado($this->app), ['mcp:leitura', 'mcp:escrita'], new \DateTimeImmutable('-1 hour'));
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
            [new RegistrarNaPastaExecutor($this->ferramenta())],
        );
        $confirmar = new ConfirmarAcaoFerramenta($useCase, $c->get('limiter.conector_confirmacao'), new RequisicaoDoConector());
        $argumentos = ['proposta_id' => $proposta->dados['proposta']['proposta_id'], 'resumo' => $proposta->dados['proposta']['resumo']];

        return $confirmar->executar($argumentos, $this->chamada());
    }

    /** @return array<string, mixed> */
    private function dadosDaProposta(RespostaDaFerramenta $resposta): array
    {
        $hash = hash('sha256', $resposta->dados['proposta']['proposta_id']);

        return static::getContainer()->get(PropostaDoConectorRepository::class)->buscarPorIdentificadorHash($hash, $this->gerenciado($this->escritorio))?->getDados() ?? self::fail('proposta não gravada');
    }

    /** Um registro do lugar, gravado direto (sem o UseCase), com criação de agora. */
    private function registro(string $lugar, User $autor, string $conteudo = '<p>Texto do registro</p>', ?string $sistema = null, ?Pasta $pasta = null, ?Tenant $escritorio = null): PastaMensagem|PastaObservacaoDetalhes|PastaObservacaoFinanceira
    {
        $classe = self::CLASSES[$lugar];
        $registro = new $classe();
        $registro->setPasta($this->gerenciado($pasta ?? $this->pasta));
        $registro->setAutor($this->gerenciado($autor));
        $registro->setTenant($this->gerenciado($escritorio ?? $this->escritorio));
        $registro->setConteudo($conteudo);
        if ($registro instanceof PastaMensagem) {
            $registro->setSistema($sistema);
        }
        $this->em()->persist($registro);
        $this->em()->flush();

        return $registro;
    }

    /** @return array<string, mixed> */
    private function linha(string $lugar, int $id): array
    {
        return $this->linhaOuNulo($lugar, $id) ?? self::fail('registro não existe');
    }

    /** @return array<string, mixed>|null */
    private function linhaOuNulo(string $lugar, int $id): ?array
    {
        $linha = $this->em()->getConnection()->fetchAssociative('SELECT conteudo, conteudo_anterior, autor_id, resposta_a_id FROM ' . self::TABELAS[$lugar] . ' WHERE id = ?', [$id]);

        return $linha === false ? null : $linha;
    }

    private function contarRegistros(string $lugar): int
    {
        return (int) $this->em()->getConnection()->fetchOne('SELECT COUNT(*) FROM ' . self::TABELAS[$lugar] . ' WHERE pasta_id = ?', [$this->pasta->getId()]);
    }

    private function contarDestaques(): int
    {
        return (int) $this->em()->getConnection()->fetchOne('SELECT COUNT(*) FROM pasta_comentario_destaque WHERE pasta_id = ?', [$this->pasta->getId()]);
    }

    private function contarAvisos(User $para, string $tipo): int
    {
        $tabela = $this->em()->getClassMetadata(Notificacao::class)->getTableName();

        return (int) $this->em()->getConnection()->fetchOne('SELECT COUNT(*) FROM ' . $tabela . ' WHERE usuario_id = ? AND tipo = ?', [$para->getId(), $tipo]);
    }

    private function contarPropostas(): int
    {
        return (int) $this->em()->getConnection()->fetchOne('SELECT COUNT(*) FROM conector_ia_proposta WHERE tenant_id = ?', [$this->escritorio->getId()]);
    }

    /** @param list<string> $permissoes */
    private function pessoaComPermissoes(array $permissoes): User
    {
        $pessoa = $this->pessoa();
        $this->vinculo($pessoa, $this->gerenciado($this->escritorio), permissoes: $permissoes);

        return $pessoa;
    }

    private function pasta(?Tenant $escritorio = null): Pasta
    {
        $pasta = (new Pasta())->setNup('REG-' . uniqid());
        $pasta->setTenant($this->gerenciado($escritorio ?? $this->escritorio));
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
        $processo->setTenant($this->gerenciado($this->escritorio));
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
