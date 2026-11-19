<?php

declare(strict_types=1);

namespace App\Tests\Conector\Integration;

use App\Cliente\Entity\ClientePF;
use App\Cliente\Entity\EnvioAutomaticoDoCliente;
use App\Cliente\Enum\ChaveDoEnvioAutomatico;
use App\Conector\DTO\ChamadaDoConector;
use App\Conector\DTO\RespostaDaFerramenta;
use App\Conector\Entity\ClienteDoConector;
use App\Conector\Entity\ConsentimentoDoConector;
use App\Conector\Entity\EscritorioDoConector;
use App\Conector\Enum\TipoDeClienteDoConector;
use App\Conector\Exception\ErroDaFerramenta;
use App\Conector\Ferramenta\AtualizarPastaCabecalho;
use App\Conector\Ferramenta\AtualizarPastaCiclo;
use App\Conector\Ferramenta\AtualizarPastaExecutor;
use App\Conector\Ferramenta\AtualizarPastaFerramenta;
use App\Conector\Ferramenta\AtualizarPastaPreferencias;
use App\Conector\Ferramenta\CatalogoFechadoDoConector;
use App\Conector\Ferramenta\FerramentaDoConector;
use App\Conector\Ferramenta\TabelaDeAbaEChave;
use App\Entity\Auth\User;
use App\Entity\Auth\UserTenant;
use App\Entity\Tenant\Tenant;
use App\Pasta\Entity\AbaDaPasta;
use App\Pasta\Entity\AbaOcultaDaPasta;
use App\Pasta\Entity\CarteiraDaPasta;
use App\Pasta\Entity\Pasta;
use App\Pasta\Entity\PastaProcesso;
use App\Pasta\Entity\PrioridadePasta;
use App\Pasta\Repository\PastaDestaqueRepository;
use App\Pasta\Repository\PastaFavoritaRepository;
use App\Processo\Entity\Processo;
use App\Tenant\Enum\SituacaoNaEquipe;
use App\Tests\Conector\Support\CenarioDoConectorTrait;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * `atualizar_pasta` com o banco e o portão de verdade (spec mcp-remoto-oauth 6.6, bloco da ferramenta): o esquema, o
 * cabeçalho (situação, responsável, prioridade, carteira), o ciclo (criar, duplicar, restaurar), as preferências de quem
 * olha, o envio automático que recusa Ativo e restaurar, o contrato que só aparece para quem vê o Financeiro, e as
 * negações da guarda (outro escritório, lixeira, segredo, só ver, sem módulo).
 */
#[CoversClass(AtualizarPastaFerramenta::class)]
#[CoversClass(AtualizarPastaExecutor::class)]
#[CoversClass(AtualizarPastaCabecalho::class)]
#[CoversClass(AtualizarPastaCiclo::class)]
#[CoversClass(AtualizarPastaPreferencias::class)]
final class AtualizarPastaFerramentaTest extends KernelTestCase
{
    use CenarioDoConectorTrait;

    private const LEITOR = ['modules.pastas.view', 'resources.pasta.view'];
    private const EDITOR = ['modules.pastas.view', 'modules.expediente.view', 'resources.pasta.view', 'resources.pasta.edit', 'resources.pasta.delete'];

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

    #[TestDox('o nome, o título e o esquema plano e fechado batem com o catálogo e com a tabela de aba e chave')]
    public function testEsquema(): void
    {
        $ferramenta = static::getContainer()->get(AtualizarPastaFerramenta::class);
        $esquema = $ferramenta->esquemaDeEntrada();

        self::assertSame('atualizar_pasta', $ferramenta->nome());
        self::assertSame(CatalogoFechadoDoConector::TITULOS['atualizar_pasta'], $ferramenta->titulo());
        self::assertFalse($esquema['additionalProperties']);
        self::assertSame(['acao'], $esquema['required']);
        self::assertSame(TabelaDeAbaEChave::acoes('atualizar_pasta'), $esquema['properties']['acao']['enum']);
        self::assertSame(['integer', 'null'], $esquema['properties']['responsavel_id']['type']);
        self::assertSame(50, $esquema['properties']['marcadores']['maxItems']);
        self::assertSame(['pasta', 'marcador', 'reu'], $esquema['properties']['alvo']['enum']);
        $json = json_encode([$esquema, $ferramenta->descricao()], \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_UNICODE);
        self::assertStringNotContainsString('"oneOf"', $json);
        self::assertStringNotContainsString("\u{2014}", $json);
        self::assertStringNotContainsString("\u{2013}", $json);
    }

    #[TestDox('o executor é classe à parte e confirma as propostas da atualizar_pasta')]
    public function testExecutor(): void
    {
        $executor = static::getContainer()->get(AtualizarPastaExecutor::class);

        self::assertSame('atualizar_pasta', $executor->ferramenta());
        self::assertNotInstanceOf(FerramentaDoConector::class, $executor);
    }

    #[TestDox('criar: grava na hora, com o número gerado pelo sistema e o identificador em maiúsculas')]
    public function testCriar(): void
    {
        $resposta = $this->executar(['acao' => 'criar', 'nome_cliente' => 'Maria da Silva', 'nome_acao' => 'Revisional']);

        self::assertSame('concluida', $resposta->dados['estado']);
        $criada = $this->pastaAtual((int) $resposta->dados['pasta_id']);
        self::assertSame($this->escritorio->getId(), $criada->getTenant()?->getId());
        self::assertNotSame('', (string) $criada->getNup());
        self::assertSame(0, $this->contarPropostas());
    }

    #[TestDox('situacao: arquivar grava na hora, leva o andamento a Finalizada e cita o contrato para quem vê o Financeiro')]
    public function testArquivarParaQuemVe(): void
    {
        $pasta = $this->pasta();

        $resposta = $this->executar(['acao' => 'situacao', 'pasta_id' => $pasta->getId(), 'situacao' => Pasta::SITUACAO_ARQUIVADA]);

        self::assertSame(['Ativo', 'Arquivado'], [$resposta->dados['situacao_anterior'], $resposta->dados['situacao']]);
        self::assertSame(AtualizarPastaCabecalho::ANDAMENTO_FINALIZADO, $resposta->dados['andamento']);
        self::assertSame(AtualizarPastaCabecalho::CONTRATO_ASSINADO, $resposta->dados['contrato']);
        self::assertSame(Pasta::SITUACAO_ARQUIVADA, $this->pastaAtual($pasta)->getSituacao());
        self::assertSame(0, $this->contarPropostas());
    }

    #[TestDox('situacao: arquivar com o Financeiro oculto grava igual, mas a resposta não diz uma palavra do contrato')]
    public function testArquivarParaQuemNaoVe(): void
    {
        $pasta = $this->pasta();
        $this->em()->persist(AbaOcultaDaPasta::paraTodaAEquipe($this->escritorio, $pasta, AbaDaPasta::Financeiro, $this->pessoa(), new \DateTimeImmutable()));
        $this->em()->flush();

        $resposta = $this->executar(['acao' => 'situacao', 'pasta_id' => $pasta->getId(), 'situacao' => Pasta::SITUACAO_ARQUIVADA]);

        self::assertArrayNotHasKey('contrato', $resposta->dados);
        self::assertStringNotContainsString('Financeiro', json_encode($resposta->dados, \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_UNICODE));
        self::assertSame(Pasta::SITUACAO_ARQUIVADA, $this->pastaAtual($pasta)->getSituacao());
    }

    #[TestDox('situacao igual à atual: nada a mudar, sem gravar')]
    public function testSituacaoIgual(): void
    {
        $pasta = $this->pasta();

        $this->assertRecusa(['acao' => 'situacao', 'pasta_id' => $pasta->getId(), 'situacao' => Pasta::SITUACAO_ATIVA], AtualizarPastaFerramenta::NADA_A_MUDAR);
    }

    #[TestDox('envio automático ligado para o cliente principal: situacao Ativo e restaurar recusam com a frase do envio')]
    public function testEnvioAutomaticoRecusa(): void
    {
        $arquivada = $this->pastaComEnvioAutomatico();
        $this->gerenciado($arquivada)->setSituacao(Pasta::SITUACAO_ARQUIVADA);
        $this->em()->flush();

        $this->assertMotivo(['acao' => 'situacao', 'pasta_id' => $arquivada->getId(), 'situacao' => Pasta::SITUACAO_ATIVA], ErroDaFerramenta::MOTIVO_ENVIO_AUTOMATICO);
        self::assertSame(Pasta::SITUACAO_ARQUIVADA, $this->pastaAtual($arquivada)->getSituacao());

        $naLixeira = $this->pastaComEnvioAutomatico();
        $this->gerenciado($naLixeira)->marcarExcluida($this->gerenciado($this->pessoa), new \DateTimeImmutable());
        $this->em()->flush();

        $this->assertMotivo(['acao' => 'restaurar', 'pasta_id' => $naLixeira->getId()], ErroDaFerramenta::MOTIVO_ENVIO_AUTOMATICO);
        self::assertTrue($this->pastaAtual($naLixeira)->estaExcluida());
    }

    #[TestDox('envio automático ligado não impede arquivar: só voltar a Ativo estende o envio')]
    public function testEnvioAutomaticoDeixaArquivar(): void
    {
        $pasta = $this->pastaComEnvioAutomatico();

        $resposta = $this->executar(['acao' => 'situacao', 'pasta_id' => $pasta->getId(), 'situacao' => Pasta::SITUACAO_SUSPENSA]);

        self::assertSame('Suspenso', $resposta->dados['situacao']);
    }

    #[TestDox('responsavel: colega ativo grava; nulo tira; colega suspenso no escritório recusa')]
    public function testResponsavel(): void
    {
        $pasta = $this->pasta();
        $colega = $this->pessoa();
        $this->vinculo($colega, $this->escritorio, permissoes: self::LEITOR);

        $resposta = $this->executar(['acao' => 'responsavel', 'pasta_id' => $pasta->getId(), 'responsavel_id' => $colega->getId()]);
        self::assertSame($colega->getId(), $resposta->dados['responsavel']['id']);
        self::assertSame($colega->getId(), $this->pastaAtual($pasta)->getResponsavel()?->getId());

        $this->assertRecusa(['acao' => 'responsavel', 'pasta_id' => $pasta->getId(), 'responsavel_id' => $colega->getId()], AtualizarPastaFerramenta::NADA_A_MUDAR);

        $resposta = $this->executar(['acao' => 'responsavel', 'pasta_id' => $pasta->getId(), 'responsavel_id' => null]);
        self::assertNull($resposta->dados['responsavel']);
        self::assertNull($this->pastaAtual($pasta)->getResponsavel());

        $this->em()->getRepository(UserTenant::class)->findOneBy(['user' => $colega->getId(), 'tenant' => $this->escritorio->getId()])?->alterarSituacao(SituacaoNaEquipe::Suspenso);
        $this->em()->flush();
        $this->assertRecusa(['acao' => 'responsavel', 'pasta_id' => $pasta->getId(), 'responsavel_id' => $colega->getId()], AtualizarPastaCabecalho::RESPONSAVEL_INVALIDO);

        $deOutro = $this->pessoa();
        $this->vinculo($deOutro, $this->escritorio('Outro'), permissoes: self::LEITOR);
        $this->assertRecusa(['acao' => 'responsavel', 'pasta_id' => $pasta->getId(), 'responsavel_id' => $deOutro->getId()], AtualizarPastaCabecalho::RESPONSAVEL_INVALIDO);
    }

    #[TestDox('prioridade e carteira: gravam na hora; pedir o que já está recusa')]
    public function testPrioridadeECarteira(): void
    {
        $pasta = $this->pasta();

        $resposta = $this->executar(['acao' => 'prioridade', 'pasta_id' => $pasta->getId(), 'prioridade' => 'urgente']);
        self::assertSame(PrioridadePasta::Urgente->label(), $resposta->dados['prioridade']);
        self::assertSame(PrioridadePasta::Urgente, $this->pastaAtual($pasta)->getPrioridade());
        $this->assertRecusa(['acao' => 'prioridade', 'pasta_id' => $pasta->getId(), 'prioridade' => 'urgente'], AtualizarPastaFerramenta::NADA_A_MUDAR);

        $resposta = $this->executar(['acao' => 'carteira', 'pasta_id' => $pasta->getId(), 'carteira' => 'trabalhista']);
        self::assertSame(CarteiraDaPasta::Trabalhista->rotulo(), $resposta->dados['carteira']);
        self::assertSame(CarteiraDaPasta::Trabalhista, $this->pastaAtual($pasta)->getCarteira());
        $this->assertRecusa(['acao' => 'carteira', 'pasta_id' => $pasta->getId(), 'carteira' => 'trabalhista'], AtualizarPastaFerramenta::NADA_A_MUDAR);
        self::assertSame(0, $this->contarPropostas());
    }

    #[TestDox('duplicar: a cópia nasce na hora, a origem fica, e a resposta avisa o que não foi copiado')]
    public function testDuplicar(): void
    {
        $origem = $this->pasta();

        $resposta = $this->executar(['acao' => 'duplicar', 'pasta_id' => $origem->getId()]);

        self::assertSame($origem->getId(), $resposta->dados['origem_id']);
        self::assertNotSame($origem->getId(), $resposta->dados['pasta_id']);
        self::assertSame(AtualizarPastaCiclo::AVISO_DUPLICAR, $resposta->dados['aviso']);
        self::assertSame($this->escritorio->getId(), $this->pastaAtual((int) $resposta->dados['pasta_id'])->getTenant()?->getId());
    }

    #[TestDox('restaurar: a pasta sai da lixeira na hora; pasta viva recusa; sem o recurso de excluir, sem permissão')]
    public function testRestaurar(): void
    {
        $pasta = $this->pasta();
        $this->gerenciado($pasta)->marcarExcluida($this->gerenciado($this->pessoa), new \DateTimeImmutable());
        $this->em()->flush();

        $this->executar(['acao' => 'restaurar', 'pasta_id' => $pasta->getId()]);
        self::assertFalse($this->pastaAtual($pasta)->estaExcluida());

        $this->assertRecusa(['acao' => 'restaurar', 'pasta_id' => $pasta->getId()], AtualizarPastaCiclo::NAO_ESTA_NA_LIXEIRA);

        $this->gerenciado($pasta)->marcarExcluida($this->gerenciado($this->pessoa), new \DateTimeImmutable());
        $this->em()->flush();
        $this->trocarPessoa(['modules.pastas.view', 'resources.pasta.view', 'resources.pasta.edit']);
        $this->assertMotivo(['acao' => 'restaurar', 'pasta_id' => $pasta->getId()], ErroDaFerramenta::MOTIVO_SEM_PERMISSAO);
        self::assertTrue($this->pastaAtual($pasta)->estaExcluida());
    }

    #[TestDox('restaurar pasta da lixeira em segredo de justiça: a frase do segredo')]
    public function testRestaurarEmSegredo(): void
    {
        $pasta = $this->pastaEmSegredo();
        $this->gerenciado($pasta)->marcarExcluida($this->gerenciado($this->pessoa), new \DateTimeImmutable());
        $this->em()->flush();

        $this->assertMotivo(['acao' => 'restaurar', 'pasta_id' => $pasta->getId()], ErroDaFerramenta::MOTIVO_EM_SEGREDO);
    }

    #[TestDox('favoritar, acompanhar e destacar: só ver basta; o estado pedido grava uma vez, e pedir de novo recusa')]
    public function testPreferencias(): void
    {
        $pasta = $this->pasta();
        $this->trocarPessoa(self::LEITOR);

        $resposta = $this->executar(['acao' => 'favoritar', 'pasta_id' => $pasta->getId(), 'ligado' => true]);
        self::assertTrue($resposta->dados['favorita']);
        self::assertSame(AtualizarPastaPreferencias::SO_PARA_VOCE, $resposta->dados['aviso']);
        self::assertNotNull(static::getContainer()->get(PastaFavoritaRepository::class)->buscarDoUsuario($this->pastaAtual($pasta), $this->gerenciado($this->pessoa), $this->gerenciado($this->escritorio)));
        $this->assertRecusa(['acao' => 'favoritar', 'pasta_id' => $pasta->getId(), 'ligado' => true], AtualizarPastaFerramenta::NADA_A_MUDAR);

        $this->assertRecusa(['acao' => 'acompanhar', 'pasta_id' => $pasta->getId(), 'ligado' => true], AtualizarPastaFerramenta::NADA_A_MUDAR);
        $resposta = $this->executar(['acao' => 'acompanhar', 'pasta_id' => $pasta->getId(), 'ligado' => false]);
        self::assertFalse($resposta->dados['acompanha']);

        $resposta = $this->executar(['acao' => 'destacar', 'pasta_id' => $pasta->getId(), 'destaque' => 'verde']);
        self::assertSame('verde', $resposta->dados['destaque']);
        self::assertSame('verde', static::getContainer()->get(PastaDestaqueRepository::class)->buscarDoUsuario($this->pastaAtual($pasta), $this->gerenciado($this->pessoa), $this->gerenciado($this->escritorio))?->getCor());
        $resposta = $this->executar(['acao' => 'destacar', 'pasta_id' => $pasta->getId(), 'destaque' => 'nenhum']);
        self::assertSame('nenhum', $resposta->dados['destaque']);
        $this->assertRecusa(['acao' => 'destacar', 'pasta_id' => $pasta->getId(), 'destaque' => 'nenhum'], AtualizarPastaFerramenta::NADA_A_MUDAR);
    }

    /** @return iterable<string, array{0: array<string, mixed>}> */
    public static function acoesComPasta(): iterable
    {
        yield 'situacao' => [['acao' => 'situacao', 'situacao' => Pasta::SITUACAO_SUSPENSA]];
        yield 'prioridade' => [['acao' => 'prioridade', 'prioridade' => 'urgente']];
        yield 'carteira' => [['acao' => 'carteira', 'carteira' => 'trabalhista']];
        yield 'responsavel' => [['acao' => 'responsavel', 'responsavel_id' => null]];
        yield 'duplicar' => [['acao' => 'duplicar']];
        yield 'favoritar' => [['acao' => 'favoritar', 'ligado' => true]];
        yield 'editar_dados' => [['acao' => 'editar_dados', 'nome_cliente' => 'NOVO NOME']];
        yield 'marcadores_da_pasta' => [['acao' => 'marcadores_da_pasta', 'marcadores' => []]];
        yield 'nome_do_cliente' => [['acao' => 'nome_do_cliente', 'nome' => 'Fulano']];
        yield 'cadastro_da_pasta' => [['acao' => 'cadastro_da_pasta', 'contrato' => 'C-1']];
        yield 'reu' => [['acao' => 'reu', 'operacao' => 'adicionar', 'nome' => 'Banco X']];
        yield 'excluir' => [['acao' => 'excluir', 'alvo' => 'pasta']];
    }

    /** @param array<string, mixed> $argumentos */
    #[DataProvider('acoesComPasta')]
    #[TestDox('pasta de outro escritório, inexistente ou na lixeira: não encontrado, sem gravar: $_dataName')]
    public function testNaoEncontrada(array $argumentos): void
    {
        $naLixeira = $this->pasta();
        $naLixeira->marcarExcluida($this->pessoa, new \DateTimeImmutable());
        $deOutro = $this->pasta($this->escritorio('Outro'));
        $this->em()->flush();

        foreach ([$naLixeira->getId(), $deOutro->getId(), 999_999_999] as $id) {
            $this->assertMotivo($argumentos + ['pasta_id' => (int) $id], ErroDaFerramenta::MOTIVO_NAO_ENCONTRADO);
        }
        self::assertSame(0, $this->contarPropostas());
    }

    /** @param array<string, mixed> $argumentos */
    #[DataProvider('acoesComPasta')]
    #[TestDox('pasta em segredo de justiça: a frase do segredo: $_dataName')]
    public function testEmSegredo(array $argumentos): void
    {
        $pasta = $this->pastaEmSegredo();

        try {
            $this->executar($argumentos + ['pasta_id' => (int) $pasta->getId()]);
            self::fail('deveria recusar o segredo');
        } catch (ErroDaFerramenta $e) {
            self::assertSame(ErroDaFerramenta::MOTIVO_EM_SEGREDO, $e->motivo);
            self::assertSame(ErroDaFerramenta::EM_SEGREDO, $e->getMessage());
        }
    }

    /** @return iterable<string, array{0: array<string, mixed>}> */
    public static function acoesQueEditam(): iterable
    {
        yield 'situacao' => [['acao' => 'situacao', 'situacao' => Pasta::SITUACAO_SUSPENSA]];
        yield 'prioridade' => [['acao' => 'prioridade', 'prioridade' => 'urgente']];
        yield 'carteira' => [['acao' => 'carteira', 'carteira' => 'trabalhista']];
        yield 'responsavel' => [['acao' => 'responsavel', 'responsavel_id' => null]];
        yield 'editar_dados' => [['acao' => 'editar_dados', 'nome_cliente' => 'NOVO NOME']];
        yield 'marcadores_da_pasta' => [['acao' => 'marcadores_da_pasta', 'marcadores' => []]];
        yield 'nome_do_cliente' => [['acao' => 'nome_do_cliente', 'nome' => 'Fulano']];
        yield 'cadastro_da_pasta' => [['acao' => 'cadastro_da_pasta', 'contrato' => 'C-1']];
        yield 'reu' => [['acao' => 'reu', 'operacao' => 'adicionar', 'nome' => 'Banco X']];
        yield 'enviar_relatorio_sem_processo' => [['acao' => 'enviar_relatorio_sem_processo']];
    }

    /** @param array<string, mixed> $argumentos */
    #[DataProvider('acoesQueEditam')]
    #[TestDox('só vê a pasta, sem o recurso de editar: não encontrado, sem gravar: $_dataName')]
    public function testSemEditar(array $argumentos): void
    {
        $pasta = $this->pasta();
        $this->trocarPessoa(self::LEITOR);

        $this->assertMotivo($argumentos + ['pasta_id' => (int) $pasta->getId()], ErroDaFerramenta::MOTIVO_NAO_ENCONTRADO);
        $atual = $this->pastaAtual($pasta);
        self::assertSame([Pasta::SITUACAO_ATIVA, PrioridadePasta::Normal, null], [$atual->getSituacao(), $atual->getPrioridade(), $atual->getCarteira()]);
        self::assertSame(0, $this->contarPropostas());
    }

    #[TestDox('sem o módulo Pastas: sem permissão, também no criar (que pede Pastas, como a tela), mesmo com Expediente')]
    public function testSemModulo(): void
    {
        $pasta = $this->pasta();
        $this->trocarPessoa(['resources.pasta.view', 'resources.pasta.edit']);

        $this->assertMotivo(['acao' => 'prioridade', 'pasta_id' => $pasta->getId(), 'prioridade' => 'urgente'], ErroDaFerramenta::MOTIVO_SEM_PERMISSAO);
        $this->assertMotivo(['acao' => 'criar', 'nome_cliente' => 'Fulano'], ErroDaFerramenta::MOTIVO_SEM_PERMISSAO);

        $this->trocarPessoa(['modules.expediente.view', 'resources.pasta.view', 'resources.pasta.edit']);
        $this->assertMotivo(['acao' => 'criar', 'nome_cliente' => 'Fulano'], ErroDaFerramenta::MOTIVO_SEM_PERMISSAO);
        self::assertSame(0, $this->contarPropostas());
    }

    #[TestDox('ação fora da lista, campo de outra ação, valor fora da lista fechada ou tipo errado: pedido inválido')]
    public function testEntradaInvalida(): void
    {
        $id = (int) $this->pasta()->getId();

        $this->assertMotivo(['acao' => 'apagar_tudo', 'pasta_id' => $id], ErroDaFerramenta::MOTIVO_ENTRADA_INVALIDA);
        $this->assertMotivo(['acao' => 'prioridade', 'pasta_id' => $id, 'prioridade' => 'altissima'], ErroDaFerramenta::MOTIVO_ENTRADA_INVALIDA);
        $this->assertMotivo(['acao' => 'prioridade', 'pasta_id' => $id, 'prioridade' => 'urgente', 'carteira' => 'trabalhista'], ErroDaFerramenta::MOTIVO_ENTRADA_INVALIDA);
        $this->assertMotivo(['acao' => 'situacao', 'pasta_id' => $id], ErroDaFerramenta::MOTIVO_ENTRADA_INVALIDA);
        $this->assertMotivo(['acao' => 'favoritar', 'pasta_id' => $id, 'ligado' => 'sim'], ErroDaFerramenta::MOTIVO_ENTRADA_INVALIDA);
        $this->assertMotivo(['acao' => 'situacao', 'pasta_id' => 'abc', 'situacao' => Pasta::SITUACAO_ATIVA], ErroDaFerramenta::MOTIVO_ENTRADA_INVALIDA);
        $this->assertMotivo(['acao' => 'marcadores_da_pasta', 'pasta_id' => $id, 'marcadores' => range(1, 51)], ErroDaFerramenta::MOTIVO_ENTRADA_INVALIDA);
        $this->assertMotivo(['acao' => 'excluir', 'alvo' => 'pasta', 'pasta_id' => $id, 'reu_id' => 3], ErroDaFerramenta::MOTIVO_ENTRADA_INVALIDA);
    }

    #[TestDox('a confirmação de uma ação direta (sem proposta) recusa como pedido inválido')]
    public function testAcaoDiretaNaoConfirma(): void
    {
        $executor = static::getContainer()->get(AtualizarPastaExecutor::class);

        try {
            $executor->reconferir('situacao', ['pasta_id' => 1], $this->chamada());
            self::fail('ação direta não tem proposta');
        } catch (ErroDaFerramenta $e) {
            self::assertSame(ErroDaFerramenta::MOTIVO_ENTRADA_INVALIDA, $e->motivo);
        }
    }

    // ------------------------------------------------------------------ apoio

    /** @param array<string, mixed> $argumentos */
    private function executar(array $argumentos): RespostaDaFerramenta
    {
        static::getContainer()->get('services_resetter')->reset();

        return static::getContainer()->get(AtualizarPastaFerramenta::class)->executar($argumentos, $this->chamada());
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

    /** @param list<string> $permissoes */
    private function trocarPessoa(array $permissoes): void
    {
        $outra = $this->pessoa();
        $this->vinculo($outra, $this->gerenciado($this->escritorio), permissoes: $permissoes);
        $consentimento = new ConsentimentoDoConector($outra, $this->gerenciado($this->escritorio), $this->gerenciado($this->consentimento)->getCliente(), ['mcp:leitura', 'mcp:escrita'], new \DateTimeImmutable());
        $this->em()->persist($consentimento);
        $this->em()->flush();
        $this->pessoa = $outra;
        $this->consentimento = $consentimento;
    }

    private function contarPropostas(): int
    {
        return (int) $this->em()->getConnection()->fetchOne('SELECT COUNT(*) FROM conector_ia_proposta');
    }

    private function pasta(?Tenant $escritorio = null): Pasta
    {
        $pasta = (new Pasta())->setNup('ATU-' . uniqid());
        $pasta->setTenant($this->gerenciado($escritorio ?? $this->escritorio));
        $this->em()->persist($pasta);
        $this->em()->flush();

        return $pasta;
    }

    /** A pasta recarregada do banco (o EntityManager é limpo: o que o teste segura vira desligado). */
    private function pastaAtual(Pasta|int $pasta): Pasta
    {
        $this->em()->clear();

        return $this->em()->find(Pasta::class, $pasta instanceof Pasta ? $pasta->getId() : $pasta) ?? throw new \LogicException('pasta sumiu');
    }

    private function pastaComEnvioAutomatico(): Pasta
    {
        $pasta = $this->gerenciado($this->pasta());
        $escritorio = $this->gerenciado($this->escritorio);
        $cliente = new ClientePF();
        $cliente->setTenant($escritorio);
        $cliente->setNomeCompleto('Cliente do Envio ' . uniqid());
        $cliente->setCpf(sprintf('%011d', random_int(10_000_000_000, 99_999_999_999)));
        $cliente->setRg('');
        $cliente->setRgOrgaoExpedidor('');
        $cliente->setEmail('envio_' . uniqid() . '@test.com');
        $cliente->setCep('');
        $cliente->setEndereco('');
        $cliente->setCidade('');
        $cliente->setEstado('');
        $this->em()->persist($cliente);
        $pasta->addCliente($cliente);
        $envio = new EnvioAutomaticoDoCliente($escritorio, $cliente, null, new \DateTimeImmutable());
        $envio->definir(ChaveDoEnvioAutomatico::NovasMovimentacoes, true, $this->gerenciado($this->pessoa), new \DateTimeImmutable());
        $this->em()->persist($envio);
        $this->em()->flush();

        return $pasta;
    }

    private function pastaEmSegredo(): Pasta
    {
        $pasta = $this->gerenciado($this->pasta());
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

    /**
     * A entidade presa ao EntityManager de agora (o `pastaAtual()` limpa, e o `services_resetter` pode trocá-lo).
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
