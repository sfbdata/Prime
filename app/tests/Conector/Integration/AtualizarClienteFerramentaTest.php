<?php

declare(strict_types=1);

namespace App\Tests\Conector\Integration;

use App\Cliente\Entity\Cliente;
use App\Cliente\Entity\ClientePF;
use App\Cliente\Entity\EnvioAutomaticoDoCliente;
use App\Cliente\Enum\ChaveDoEnvioAutomatico;
use App\Cliente\Repository\DocumentoVinculadoRepository;
use App\Conector\DTO\ChamadaDoConector;
use App\Conector\DTO\RespostaDaFerramenta;
use App\Conector\Entity\ClienteDoConector;
use App\Conector\Entity\ConsentimentoDoConector;
use App\Conector\Enum\TipoDeClienteDoConector;
use App\Conector\Exception\ErroDaFerramenta;
use App\Conector\Ferramenta\AtualizarClienteAcesso;
use App\Conector\Ferramenta\AtualizarClienteCadastro;
use App\Conector\Ferramenta\AtualizarClienteFerramenta;
use App\Conector\Ferramenta\AtualizarClienteVinculos;
use App\Conector\Service\EnvioAutomaticoLigado;
use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Master\Entity\RecursoEstado;
use App\Pasta\Entity\AbaDaPasta;
use App\Pasta\Entity\AbaOcultaDaPasta;
use App\Pasta\Entity\Pasta;
use App\Pasta\Entity\PastaDocumento;
use App\Pasta\Entity\PastaProcesso;
use App\Processo\Entity\Processo;
use App\Tests\Conector\Support\CenarioDoConectorTrait;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * `atualizar_cliente`, as ações diretas, com o banco, o portão, a guarda e os UseCases de verdade (spec mcp-remoto-oauth,
 * 6.6 e 6.2, regras 1, 3, 4 e 10): campo vazio grava, campo preenchido vira proposta sem gravar nada (conferido no
 * banco; a confirmação está em {@see AtualizarClienteConfirmacaoTest}), o envio automático ligado recusa e nada grava, o
 * isolamento por escritório dá o mesmo "não encontrado", a pasta na lixeira e a pasta em segredo recusam, e nenhum valor
 * mandado volta no JSON da resposta.
 */
#[CoversClass(AtualizarClienteFerramenta::class)]
#[CoversClass(AtualizarClienteCadastro::class)]
#[CoversClass(AtualizarClienteVinculos::class)]
#[CoversClass(AtualizarClienteAcesso::class)]
#[CoversClass(EnvioAutomaticoLigado::class)]
final class AtualizarClienteFerramentaTest extends KernelTestCase
{
    use CenarioDoConectorTrait;

    private const CPF = '529.982.247-25';
    private const EMAIL_NOVO = 'novo.contato.sigiloso@exemplo.com.br';
    private const CELULAR_NOVO = '(61) 99876-1234';
    private const BAIRRO_NOVO = 'Bairro Valor Sigiloso';
    private const COMPLEMENTO_NOVO = 'Bloco Valor Reservado';
    private const EDITOR = [
        'modules.clientes.view', 'resources.cliente.view', 'resources.cliente.edit', 'resources.pasta.view', 'resources.pasta.edit',
    ];

    private Tenant $escritorio;
    private User $pessoa;
    private ClienteDoConector $app;
    /** @var array<int, int> pessoa => consentimento (a proposta exige um consentimento ativo de verdade) */
    private array $consentimentos = [];

    protected function setUp(): void
    {
        self::bootKernel();
        $this->escritorio = $this->escritorio();
        $this->pessoa = $this->pessoa();
        $this->vinculo($this->pessoa, $this->escritorio, permissoes: self::EDITOR);
        $this->app = new ClienteDoConector('https://claude.ai/oauth/' . uniqid() . '/client.json', TipoDeClienteDoConector::Cimd, 'Claude', ['https://claude.ai/api/mcp/auth_callback'], new \DateTimeImmutable('-1 hour'));
        $this->em()->persist($this->app);
        $this->em()->flush();
    }

    protected function tearDown(): void
    {
        static::getContainer()->get('services_resetter')->reset();
        parent::tearDown();
    }

    #[TestDox('completar: campo vazio grava; a resposta traz id, nome e só os nomes dos campos, nunca os valores mandados')]
    public function testCompletarCampoVazio(): void
    {
        $cliente = $this->cliente();

        $resposta = $this->chamar(['acao' => 'completar', 'cliente_id' => $cliente->getId(), 'campos' => ['bairro' => self::BAIRRO_NOVO, 'complemento' => self::COMPLEMENTO_NOVO]]);

        self::assertSame('concluida', $resposta->dados['estado']);
        self::assertSame(['id' => (int) $cliente->getId(), 'nome' => 'MARIA COMPLETA DA SILVA'], $resposta->dados['cliente']);
        self::assertEqualsCanonicalizing(['bairro', 'complemento'], $resposta->dados['campos_alterados']);
        self::assertFalse($resposta->dados['nada_mudou']);
        self::assertSame(['cliente' => [(int) $cliente->getId()]], $resposta->ids);
        $gravado = $this->doBanco($cliente);
        self::assertSame(mb_strtoupper(self::BAIRRO_NOVO), mb_strtoupper($gravado['bairro']));
        self::assertSame(mb_strtoupper(self::COMPLEMENTO_NOVO), mb_strtoupper($gravado['complemento']));
        self::assertSemValores($resposta, [self::BAIRRO_NOVO, self::COMPLEMENTO_NOVO, 'Sigiloso', 'Reservado']);
    }

    #[TestDox('completar: um campo preenchido com outro valor faz a chamada inteira virar proposta; nem o campo vazio mandado junto é gravado')]
    public function testCompletarCampoPreenchidoViraProposta(): void
    {
        $cliente = $this->cliente();
        $antes = $this->doBanco($cliente);

        $resposta = $this->chamar(['acao' => 'completar', 'cliente_id' => $cliente->getId(), 'campos' => ['bairro' => self::BAIRRO_NOVO, 'profissao' => 'Engenheira Valor Novo']]);

        self::assertSame('aguardando_confirmacao', $resposta->dados['estado']);
        self::assertSame(['campos_que_trocam' => ['profissao'], 'campos_vazios' => ['bairro']], $resposta->dados['detalhes']);
        self::assertStringNotContainsString('PROFESSORA', mb_strtoupper($resposta->dados['proposta']['resumo']), 'o valor antigo não vai ao resumo');
        self::assertSame($antes, $this->doBanco($cliente), 'nada foi gravado, nem o bairro vazio');
    }

    #[TestDox('completar: apagar um campo preenchido também é troca e vira proposta sem gravar')]
    public function testCompletarApagarViraProposta(): void
    {
        $cliente = $this->cliente();
        $antes = $this->doBanco($cliente);

        $resposta = $this->chamar(['acao' => 'completar', 'cliente_id' => $cliente->getId(), 'campos' => ['profissao' => '']]);

        self::assertStringEndsWith('- profissão (troca o valor atual): apagar o valor atual', $resposta->dados['proposta']['resumo']);
        self::assertSame($antes, $this->doBanco($cliente));
    }

    #[TestDox('completar: o valor igual ao que já está (outra pontuação, outra caixa) não é troca e não muda nada')]
    public function testCompletarValorIgual(): void
    {
        $cliente = $this->cliente();
        $antes = $this->doBanco($cliente);

        $resposta = $this->chamar(['acao' => 'completar', 'cliente_id' => $cliente->getId(), 'campos' => ['cpf' => '52998224725', 'nome' => 'maria  completa da silva', 'profissao' => 'professora']]);

        self::assertTrue($resposta->dados['nada_mudou']);
        self::assertSame([], $resposta->dados['campos_alterados']);
        self::assertSame($antes, $this->doBanco($cliente));
    }

    #[TestDox('completar: campo de pessoa jurídica num cliente pessoa física recusa sem gravar')]
    public function testCompletarCampoDeOutroTipo(): void
    {
        $cliente = $this->cliente();

        $erro = $this->recusa(['acao' => 'completar', 'cliente_id' => $cliente->getId(), 'campos' => ['bairro' => self::BAIRRO_NOVO, 'cnpj' => '11.222.333/0001-81']]);

        self::assertSame(AtualizarClienteCadastro::CAMPO_DE_OUTRO_TIPO, $erro->getMessage());
        self::assertSame('', $this->doBanco($cliente)['bairro']);
    }

    #[TestDox('completar: valor que o cadastro recusa (CPF inválido) dá a mensagem fixa, sem o valor')]
    public function testCompletarValorInvalido(): void
    {
        $cliente = $this->cliente(cpf: '');

        $erro = $this->recusa(['acao' => 'completar', 'cliente_id' => $cliente->getId(), 'campos' => ['cpf' => '123.456.789-00']]);

        self::assertSame(AtualizarClienteCadastro::CADASTRO_RECUSADO, $erro->getMessage());
        self::assertSame('', $this->doBanco($cliente)['cpf']);
    }

    /** @return iterable<string, array{0: array<string, mixed>}> */
    public static function entradasInvalidas(): iterable
    {
        yield 'cadastrar sem a lista (cliente_id não é campo dele)' => [['acao' => 'cadastrar', 'cliente_id' => 1]];
        yield 'cadastrar com item fora do formato' => [['acao' => 'cadastrar', 'clientes' => [['tipo' => 'pf', 'campos' => ['nome' => 'X'], 'cpf' => '1']]]];
        yield 'cadastrar com tipo fora da lista' => [['acao' => 'cadastrar', 'clientes' => [['tipo' => 'outro', 'campos' => ['nome' => 'X']]]]];
        yield 'cadastrar com a lista em objeto' => [['acao' => 'cadastrar', 'clientes' => ['a' => ['tipo' => 'pf', 'campos' => ['nome' => 'X']]]]];
        yield 'desvincular não é ação: é a operacao do vincular_a_pasta' => [['acao' => 'desvincular', 'cliente_id' => 1, 'pasta_id' => 1]];
        yield 'operacao fora da lista' => [['acao' => 'vincular_a_pasta', 'cliente_id' => 1, 'pasta_id' => 1, 'operacao' => 'apagar']];
        yield 'operacao em ação que não a aceita' => [['acao' => 'principal', 'cliente_id' => 1, 'pasta_id' => 1, 'operacao' => 'desvincular']];
        yield 'campo que a ação não usa' => [['acao' => 'completar', 'cliente_id' => 1, 'campos' => ['bairro' => 'X'], 'pasta_id' => 1]];
        yield 'campo que falta' => [['acao' => 'vincular_a_pasta', 'cliente_id' => 1]];
        yield 'chave fora do cadastro' => [['acao' => 'completar', 'cliente_id' => 1, 'campos' => ['senha' => 'X']]];
        yield 'campos vazio' => [['acao' => 'completar', 'cliente_id' => 1, 'campos' => []]];
        yield 'campos em lista' => [['acao' => 'completar', 'cliente_id' => 1, 'campos' => ['X']]];
        yield 'contato fixo fica na tela' => [['acao' => 'contato', 'cliente_id' => 1, 'campo' => 'fixo', 'valor' => '6133334444']];
        yield 'cliente_id não inteiro' => [['acao' => 'principal', 'cliente_id' => 'abc', 'pasta_id' => 1]];
    }

    /** @param array<string, mixed> $argumentos */
    #[DataProvider('entradasInvalidas')]
    #[TestDox('entrada fechada: ação ou campo fora da lista, campo que falta ou valor de outro tipo dão pedido inválido')]
    public function testEntradaInvalida(array $argumentos): void
    {
        self::assertSame(ErroDaFerramenta::MOTIVO_ENTRADA_INVALIDA, $this->recusa($argumentos)->motivo);
    }

    #[TestDox('valor com caractere de controle: filtro inválido no campo, sem o valor na mensagem')]
    public function testValorComControle(): void
    {
        $cliente = $this->cliente();

        $erro = $this->recusa(['acao' => 'completar', 'cliente_id' => $cliente->getId(), 'campos' => ['bairro' => "Linha\nOutra"]]);

        self::assertSame(ErroDaFerramenta::MOTIVO_FILTRO_INVALIDO, $erro->motivo);
        self::assertStringNotContainsString('Linha', $erro->getMessage());
    }

    #[TestDox('contato: celular vazio grava com a máscara; e-mail preenchido com outro valor vira proposta sem gravar; e-mail igual não muda nada')]
    public function testContato(): void
    {
        $cliente = $this->cliente(celular: null);

        $resposta = $this->chamar(['acao' => 'contato', 'cliente_id' => $cliente->getId(), 'campo' => 'celular', 'valor' => '61998761234']);
        self::assertSame(['celular'], $resposta->dados['campos_alterados']);
        self::assertSame(self::CELULAR_NOVO, $this->doBanco($cliente)['telefone_celular']);
        self::assertSemValores($resposta, ['61998761234', '99876', self::CELULAR_NOVO]);

        $troca = $this->chamar(['acao' => 'contato', 'cliente_id' => $cliente->getId(), 'campo' => 'email', 'valor' => self::EMAIL_NOVO]);
        self::assertSame('aguardando_confirmacao', $troca->dados['estado']);
        self::assertSame('maria.completa@exemplo.com.br', $this->doBanco($cliente)['email']);

        $igual = $this->chamar(['acao' => 'contato', 'cliente_id' => $cliente->getId(), 'campo' => 'email', 'valor' => 'Maria.Completa@exemplo.com.br']);
        self::assertTrue($igual->dados['nada_mudou']);

        $outroCelular = $this->chamar(['acao' => 'contato', 'cliente_id' => $cliente->getId(), 'campo' => 'celular', 'valor' => '61911112222']);
        self::assertSame('aguardando_confirmacao', $outroCelular->dados['estado']);
        self::assertSame(self::CELULAR_NOVO, $this->doBanco($cliente)['telefone_celular']);
    }

    #[TestDox('contato: e-mail vazio grava; valor inválido dá a mensagem fixa do contato')]
    public function testContatoEmailVazio(): void
    {
        $cliente = $this->cliente(email: '');

        $invalido = $this->recusa(['acao' => 'contato', 'cliente_id' => $cliente->getId(), 'campo' => 'email', 'valor' => 'sem-arroba']);
        self::assertSame(AtualizarClienteCadastro::CONTATO_RECUSADO, $invalido->getMessage());
        self::assertSame('', $this->doBanco($cliente)['email']);

        $resposta = $this->chamar(['acao' => 'contato', 'cliente_id' => $cliente->getId(), 'campo' => 'email', 'valor' => self::EMAIL_NOVO]);
        self::assertSame(['email'], $resposta->dados['campos_alterados']);
        self::assertSame(self::EMAIL_NOVO, $this->doBanco($cliente)['email']);
        self::assertSemValores($resposta, [self::EMAIL_NOVO, 'novo.contato']);
    }

    /** @return iterable<string, array{0: string}> */
    public static function chavesDoEnvio(): iterable
    {
        foreach (ChaveDoEnvioAutomatico::cases() as $chave) {
            yield $chave->value => [$chave->value];
        }
    }

    #[DataProvider('chavesDoEnvio')]
    #[TestDox('envio automático ligado (qualquer chave): e-mail no completar e no contato, vincular_a_pasta e principal recusam a chamada inteira e nada grava')]
    public function testEnvioAutomaticoLigadoRecusa(string $chave): void
    {
        $cliente = $this->cliente(celular: null, email: '');
        $this->envio($cliente, ChaveDoEnvioAutomatico::from($chave), true);
        $semCliente = $this->pasta();
        $comOutro = $this->pasta($this->cliente(cpf: '390.533.447-05'));
        $comOutro->addCliente($cliente);
        $this->em()->flush();
        $antes = $this->doBanco($cliente);

        foreach ([
            ['acao' => 'completar', 'cliente_id' => $cliente->getId(), 'campos' => ['bairro' => self::BAIRRO_NOVO, 'email' => self::EMAIL_NOVO]],
            ['acao' => 'contato', 'cliente_id' => $cliente->getId(), 'campo' => 'email', 'valor' => self::EMAIL_NOVO],
            ['acao' => 'vincular_a_pasta', 'cliente_id' => $cliente->getId(), 'pasta_id' => $semCliente->getId()],
            ['acao' => 'principal', 'cliente_id' => $cliente->getId(), 'pasta_id' => $comOutro->getId()],
        ] as $argumentos) {
            $erro = $this->recusa($argumentos);
            self::assertSame(ErroDaFerramenta::MOTIVO_ENVIO_AUTOMATICO, $erro->motivo, (string) $argumentos['acao']);
            self::assertSame(ErroDaFerramenta::ENVIO_AUTOMATICO, $erro->getMessage());
        }

        self::assertSame($antes, $this->doBanco($cliente), 'nem o bairro mandado junto com o e-mail');
        self::assertSame(0, $this->vinculos($semCliente));
        self::assertNotSame((int) $cliente->getId(), $this->principalNoBanco($comOutro));

        $celular = $this->chamar(['acao' => 'contato', 'cliente_id' => $cliente->getId(), 'campo' => 'celular', 'valor' => '61911112222']);
        self::assertSame(['celular'], $celular->dados['campos_alterados'], 'o celular não estende o envio e grava');
    }

    #[TestDox('envio automático com a linha e as três chaves desligadas: e-mail, vincular e principal gravam como a tela')]
    public function testEnvioAutomaticoDesligadoGrava(): void
    {
        $cliente = $this->cliente(email: '');
        $this->envio($cliente, ChaveDoEnvioAutomatico::NovasMovimentacoes, false);
        $pasta = $this->pasta($this->cliente(cpf: '390.533.447-05'));

        self::assertSame(['email'], $this->chamar(['acao' => 'contato', 'cliente_id' => $cliente->getId(), 'campo' => 'email', 'valor' => self::EMAIL_NOVO])->dados['campos_alterados']);
        self::assertFalse($this->chamar(['acao' => 'vincular_a_pasta', 'cliente_id' => $cliente->getId(), 'pasta_id' => $pasta->getId()])->dados['e_o_principal_da_pasta']);
        self::assertTrue($this->chamar(['acao' => 'principal', 'cliente_id' => $cliente->getId(), 'pasta_id' => $pasta->getId()])->dados['e_o_principal_da_pasta']);
        self::assertSame((int) $cliente->getId(), $this->principalNoBanco($pasta));
    }

    #[TestDox('vincular_a_pasta: liga o cliente; na pasta sem cliente ele vira o principal; de novo, recusa sem duplicar; a resposta não traz o CPF')]
    public function testVincularAPasta(): void
    {
        $cliente = $this->cliente();
        $pasta = $this->pasta();

        $resposta = $this->chamar(['acao' => 'vincular_a_pasta', 'cliente_id' => $cliente->getId(), 'pasta_id' => $pasta->getId()]);

        self::assertSame((int) $pasta->getId(), $resposta->dados['pasta']['id']);
        self::assertStringStartsWith('ATC-', $resposta->dados['pasta']['numero']);
        self::assertTrue($resposta->dados['e_o_principal_da_pasta']);
        self::assertSame(1, $this->vinculos($pasta));
        self::assertSame((int) $cliente->getId(), $this->principalNoBanco($pasta));
        self::assertSemValores($resposta, [self::CPF, '52998224725', 'maria.completa']);

        $erro = $this->recusa(['acao' => 'vincular_a_pasta', 'cliente_id' => $cliente->getId(), 'pasta_id' => $pasta->getId()]);
        self::assertSame(AtualizarClienteVinculos::JA_VINCULADO, $erro->getMessage());
        self::assertSame(1, $this->vinculos($pasta));
    }

    #[TestDox('principal: marca o cliente da pasta; já principal não muda nada; cliente fora da pasta recusa')]
    public function testPrincipal(): void
    {
        $primeiro = $this->cliente(cpf: '390.533.447-05');
        $segundo = $this->cliente();
        $pasta = $this->pasta($primeiro);
        $pasta->addCliente($segundo);
        $this->em()->flush();
        $fora = $this->cliente(cpf: '');

        $resposta = $this->chamar(['acao' => 'principal', 'cliente_id' => $segundo->getId(), 'pasta_id' => $pasta->getId()]);
        self::assertTrue($resposta->dados['e_o_principal_da_pasta']);
        self::assertFalse($resposta->dados['nada_mudou']);
        self::assertSame((int) $segundo->getId(), $this->principalNoBanco($pasta));

        self::assertTrue($this->chamar(['acao' => 'principal', 'cliente_id' => $segundo->getId(), 'pasta_id' => $pasta->getId()])->dados['nada_mudou']);

        $erro = $this->recusa(['acao' => 'principal', 'cliente_id' => $fora->getId(), 'pasta_id' => $pasta->getId()]);
        self::assertSame(AtualizarClienteVinculos::NAO_VINCULADO, $erro->getMessage());
        self::assertSame((int) $segundo->getId(), $this->principalNoBanco($pasta));
    }

    #[TestDox('ligar_documento: com a aba Documentos oculta, não encontrado; o documento achado na pasta visível liga ao cadastro, uma vez só')]
    public function testLigarDocumento(): void
    {
        $cliente = $this->cliente();
        // Primeiro a pasta oculta: enquanto nada está ligado, só a aba oculta pode explicar a recusa.
        $oculta = $this->pasta($cliente);
        $escondido = $this->documento($oculta, 'rg-escondido.pdf');
        $this->em()->persist(AbaOcultaDaPasta::paraTodaAEquipe($this->escritorio, $oculta, AbaDaPasta::Documentos, $this->pessoa(), new \DateTimeImmutable()));
        $this->em()->flush();
        $pasta = $this->pasta($cliente);
        $documento = $this->documento($pasta, 'rg-da-maria.pdf');
        $erro = $this->recusa(['acao' => 'ligar_documento', 'cliente_id' => $cliente->getId(), 'documento_id' => $escondido->getId()]);
        self::assertSame(ErroDaFerramenta::MOTIVO_NAO_ENCONTRADO, $erro->motivo);
        self::assertSame([], $this->ligados($cliente));

        $resposta = $this->chamar(['acao' => 'ligar_documento', 'cliente_id' => $cliente->getId(), 'documento_id' => $documento->getId()]);
        self::assertFalse($resposta->dados['ja_estava_ligado']);
        self::assertSame((int) $pasta->getId(), $resposta->dados['pasta_id']);
        self::assertSame([(int) $documento->getId()], $this->ligados($cliente));
        self::assertSemValores($resposta, []);
        self::assertTrue($this->chamar(['acao' => 'ligar_documento', 'cliente_id' => $cliente->getId(), 'documento_id' => $documento->getId()])->dados['ja_estava_ligado']);
    }

    #[TestDox('isolamento: cliente, pasta ou documento de outro escritório, inexistente ou que a pessoa não vê dão o mesmo não encontrado, em toda ação')]
    public function testIsolamento(): void
    {
        $meu = $this->cliente();
        $minhaPasta = $this->pasta($meu);
        $outro = $this->escritorio('Outro');
        $alheio = $this->cliente($outro, cpf: '');
        $pastaAlheia = $this->pasta($alheio, $outro);
        $documentoAlheio = $this->documento($pastaAlheia, 'rg-alheio.pdf');
        $semVer = $this->pessoa();
        $this->vinculo($semVer, $this->escritorio, permissoes: ['modules.clientes.view', 'resources.pasta.view', 'resources.pasta.edit']);

        $casos = [];
        foreach ([$alheio->getId(), 999_999_999] as $clienteId) {
            $casos[] = [['acao' => 'completar', 'cliente_id' => $clienteId, 'campos' => ['bairro' => 'X']], null];
            $casos[] = [['acao' => 'contato', 'cliente_id' => $clienteId, 'campo' => 'celular', 'valor' => '61911112222'], null];
            $casos[] = [['acao' => 'vincular_a_pasta', 'cliente_id' => $clienteId, 'pasta_id' => $minhaPasta->getId()], null];
            $casos[] = [['acao' => 'principal', 'cliente_id' => $clienteId, 'pasta_id' => $minhaPasta->getId()], null];
            $casos[] = [['acao' => 'ligar_documento', 'cliente_id' => $clienteId, 'documento_id' => $documentoAlheio->getId()], null];
        }
        $casos[] = [['acao' => 'vincular_a_pasta', 'cliente_id' => $meu->getId(), 'pasta_id' => $pastaAlheia->getId()], null];
        $casos[] = [['acao' => 'principal', 'cliente_id' => $meu->getId(), 'pasta_id' => 999_999_999], null];
        $casos[] = [['acao' => 'ligar_documento', 'cliente_id' => $meu->getId(), 'documento_id' => $documentoAlheio->getId()], null];
        $casos[] = [['acao' => 'completar', 'cliente_id' => $meu->getId(), 'campos' => ['bairro' => 'X']], $semVer];
        $casos[] = [['acao' => 'principal', 'cliente_id' => $meu->getId(), 'pasta_id' => $minhaPasta->getId()], $semVer];

        foreach ($casos as [$argumentos, $quem]) {
            $erro = $this->recusa($argumentos, $quem);
            self::assertSame(ErroDaFerramenta::MOTIVO_NAO_ENCONTRADO, $erro->motivo, json_encode($argumentos, \JSON_THROW_ON_ERROR));
            self::assertSame(ErroDaFerramenta::NAO_ENCONTRADO, $erro->getMessage());
        }
        self::assertSame('', $this->doBanco($alheio)['bairro']);
        self::assertSame(1, $this->vinculos($pastaAlheia));
    }

    #[TestDox('permissão: sem o módulo clientes ou sem mcp:escrita, sem permissão; quem só vê o cliente não completa; quem só vê a pasta não vincula (não encontrado)')]
    public function testPermissao(): void
    {
        $cliente = $this->cliente();
        $pasta = $this->pasta();
        $semModulo = $this->pessoa();
        $this->vinculo($semModulo, $this->escritorio, permissoes: ['resources.cliente.view', 'resources.cliente.edit', 'resources.pasta.view', 'resources.pasta.edit']);
        $soVeCliente = $this->pessoa();
        $this->vinculo($soVeCliente, $this->escritorio, permissoes: ['modules.clientes.view', 'resources.cliente.view', 'resources.pasta.view', 'resources.pasta.edit']);
        $soVePasta = $this->pessoa();
        $this->vinculo($soVePasta, $this->escritorio, permissoes: ['modules.clientes.view', 'resources.cliente.view', 'resources.cliente.edit', 'resources.pasta.view']);
        $completar = ['acao' => 'completar', 'cliente_id' => $cliente->getId(), 'campos' => ['bairro' => self::BAIRRO_NOVO]];
        $contato = ['acao' => 'contato', 'cliente_id' => $cliente->getId(), 'campo' => 'celular', 'valor' => '61911112222'];
        $vincular = ['acao' => 'vincular_a_pasta', 'cliente_id' => $cliente->getId(), 'pasta_id' => $pasta->getId()];

        self::assertSame(ErroDaFerramenta::MOTIVO_SEM_PERMISSAO, $this->recusa($completar, $semModulo)->motivo);
        self::assertSame(ErroDaFerramenta::MOTIVO_SEM_PERMISSAO, $this->recusa($completar, escopos: ['mcp:leitura'])->motivo);
        self::assertSame(ErroDaFerramenta::MOTIVO_SEM_PERMISSAO, $this->recusa($completar, $soVeCliente)->motivo);
        self::assertSame(ErroDaFerramenta::MOTIVO_SEM_PERMISSAO, $this->recusa($contato, $soVeCliente)->motivo);
        self::assertSame(ErroDaFerramenta::MOTIVO_NAO_ENCONTRADO, $this->recusa($vincular, $soVePasta)->motivo);
        self::assertSame('', $this->doBanco($cliente)['bairro']);
        self::assertSame(0, $this->vinculos($pasta));
    }

    #[TestDox('pasta na lixeira dá não encontrado e pasta ligada a processo em segredo recusa, sem gravar')]
    public function testLixeiraESegredo(): void
    {
        $cliente = $this->cliente();
        $excluida = $this->pasta();
        $excluida->marcarExcluida($this->pessoa, new \DateTimeImmutable());
        $secreta = $this->pasta();
        $this->processoEmSegredo($secreta);
        $this->em()->flush();

        $lixeira = $this->recusa(['acao' => 'vincular_a_pasta', 'cliente_id' => $cliente->getId(), 'pasta_id' => $excluida->getId()]);
        self::assertSame(ErroDaFerramenta::MOTIVO_NAO_ENCONTRADO, $lixeira->motivo);
        $segredo = $this->recusa(['acao' => 'vincular_a_pasta', 'cliente_id' => $cliente->getId(), 'pasta_id' => $secreta->getId()]);
        self::assertSame(ErroDaFerramenta::MOTIVO_EM_SEGREDO, $segredo->motivo);
        self::assertSame(0, $this->vinculos($excluida) + $this->vinculos($secreta));
    }

    #[TestDox('chave expedientes desligada: a ação pela pasta recusa (função desligada); o completar, sem pasta, continua')]
    public function testChaveExpedientes(): void
    {
        $cliente = $this->cliente();
        $pasta = $this->pasta();
        $estado = $this->em()->getRepository(RecursoEstado::class)->findOneBy(['chave' => 'expedientes']) ?? new RecursoEstado('expedientes');
        $estado->desativar('Pausa', null, null, new \DateTimeImmutable());
        $this->em()->persist($estado);
        $this->em()->flush();

        $erro = $this->recusa(['acao' => 'vincular_a_pasta', 'cliente_id' => $cliente->getId(), 'pasta_id' => $pasta->getId()]);
        self::assertSame(ErroDaFerramenta::MOTIVO_FUNCAO_DESLIGADA, $erro->motivo);
        self::assertSame(['bairro'], $this->chamar(['acao' => 'completar', 'cliente_id' => $cliente->getId(), 'campos' => ['bairro' => self::BAIRRO_NOVO]])->dados['campos_alterados']);
    }

    /**
     * Nenhum dos valores mandados (nem o CPF, o e-mail e o telefone de fábrica) no JSON inteiro da resposta (6.2, regra 4).
     *
     * @param list<string> $valores
     */
    private static function assertSemValores(RespostaDaFerramenta $resposta, array $valores): void
    {
        $json = json_encode($resposta->dados, \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES);
        $soDigitos = (string) preg_replace('#[.\-()/\s]#', '', $json);
        foreach ([...$valores, self::CPF, 'maria.completa@exemplo.com.br', '(61) 98765-4321'] as $valor) {
            self::assertStringNotContainsString(mb_strtoupper($valor), mb_strtoupper($json), 'o valor não sai: ' . $valor);
        }
        foreach (['52998224725', '61987654321'] as $digitos) {
            self::assertStringNotContainsString($digitos, $soDigitos);
        }
    }

    /**
     * @param array<string, mixed> $argumentos
     * @param list<string>         $escopos
     */
    private function chamar(array $argumentos, ?User $pessoa = null, array $escopos = ['mcp:leitura', 'mcp:escrita']): RespostaDaFerramenta
    {
        try {
            $quem = $pessoa ?? $this->pessoa;
            $consentimento = $this->consentimentoDe($quem);
            $contexto = $this->portao()->abrir((int) $quem->getId(), (int) $this->escritorio->getId());
            $chamada = new ChamadaDoConector($contexto, 41, $consentimento, 'claude.ai', $escopos, new \DateTimeImmutable('+7 days'));

            return static::getContainer()->get(AtualizarClienteFerramenta::class)->executar($argumentos, $chamada);
        } finally {
            static::getContainer()->get('services_resetter')->reset();
        }
    }

    /**
     * @param array<string, mixed> $argumentos
     * @param list<string>         $escopos
     */
    private function recusa(array $argumentos, ?User $pessoa = null, array $escopos = ['mcp:leitura', 'mcp:escrita']): ErroDaFerramenta
    {
        try {
            $this->chamar($argumentos, $pessoa, $escopos);
        } catch (ErroDaFerramenta $e) {
            return $e;
        }
        self::fail('deveria recusar: ' . json_encode($argumentos, \JSON_THROW_ON_ERROR));
    }

    private function consentimentoDe(User $pessoa): int
    {
        $id = (int) $pessoa->getId();
        if (!isset($this->consentimentos[$id])) {
            // Pelo id: o `services_resetter` de cada chamada limpa o EntityManager e solta as entidades do cenário.
            $em = $this->em();
            $consentimento = new ConsentimentoDoConector($em->getReference(User::class, $id), $em->getReference(Tenant::class, $this->escritorio->getId()), $em->getReference(ClienteDoConector::class, $this->app->getId()), ['mcp:leitura', 'mcp:escrita'], new \DateTimeImmutable('-1 hour'));
            $this->em()->persist($consentimento);
            $this->em()->flush();
            $this->consentimentos[$id] = (int) $consentimento->getId();
        }

        return $this->consentimentos[$id];
    }

    /** @return array<string, mixed> a linha do cliente lida direto do banco, sem o EntityManager */
    private function doBanco(Cliente $cliente): array
    {
        $linha = $this->em()->getConnection()->fetchAssociative(
            'SELECT c.bairro, c.complemento, c.email, c.telefone_celular, pf.cpf, pf.profissao, pf.nome_completo FROM cliente c LEFT JOIN cliente_pf pf ON pf.id = c.id WHERE c.id = :id',
            ['id' => $cliente->getId()],
        );
        self::assertIsArray($linha);
        $linha['bairro'] = (string) $linha['bairro'];
        $linha['complemento'] = (string) $linha['complemento'];
        $linha['cpf'] = (string) $linha['cpf'];

        return $linha;
    }

    private function vinculos(Pasta $pasta): int
    {
        return (int) $this->em()->getConnection()->fetchOne('SELECT COUNT(*) FROM pasta_cliente WHERE pasta_id = :p', ['p' => $pasta->getId()]);
    }

    private function principalNoBanco(Pasta $pasta): ?int
    {
        $id = $this->em()->getConnection()->fetchOne('SELECT cliente_principal_id FROM pasta WHERE id = :p', ['p' => $pasta->getId()]);

        return $id === null || $id === false ? null : (int) $id;
    }

    /** @return list<int> */
    private function ligados(Cliente $cliente): array
    {
        return static::getContainer()->get(DocumentoVinculadoRepository::class)->idsDePastaDocumentoVinculados($cliente, $this->escritorio);
    }

    private function cliente(?Tenant $escritorio = null, string $cpf = self::CPF, ?string $celular = '(61) 98765-4321', string $email = 'maria.completa@exemplo.com.br'): ClientePF
    {
        $cliente = (new ClientePF())->setNomeCompleto('Maria Completa da Silva')->setCpf($cpf)->setRg('')->setRgOrgaoExpedidor('')->setProfissao('Professora')->setNacionalidade('brasileira');
        $cliente->setEmail($email)->setTelefoneCelular($celular)->setCep('')->setEndereco('')->setCidade('Brasília')->setEstado('DF')->setTenant($escritorio ?? $this->escritorio);
        $this->em()->persist($cliente);
        $this->em()->flush();

        return $cliente;
    }

    private function pasta(?Cliente $cliente = null, ?Tenant $escritorio = null): Pasta
    {
        $pasta = (new Pasta())->setNup('ATC-' . uniqid());
        $pasta->setTenant($escritorio ?? $this->escritorio);
        if ($cliente !== null) {
            $pasta->addCliente($cliente);
        }
        $this->em()->persist($pasta);
        $this->em()->flush();

        return $pasta;
    }

    private function envio(Cliente $cliente, ChaveDoEnvioAutomatico $chave, bool $ligada): void
    {
        $linha = new EnvioAutomaticoDoCliente($this->escritorio, $cliente, $this->pessoa, new \DateTimeImmutable());
        $linha->definir($chave, $ligada, $this->pessoa, new \DateTimeImmutable());
        $this->em()->persist($linha);
        $this->em()->flush();
    }

    private function documento(Pasta $pasta, string $nome): PastaDocumento
    {
        $documento = (new PastaDocumento())->setPasta($pasta)->setTenant($pasta->getTenant())->setCategoria(PastaDocumento::CATEGORIA_IDENTIFICACAO)
            ->setTitulo($nome)->setNomeOriginal($nome)->setCaminhoArquivo('atc_' . bin2hex(random_bytes(6)) . '.pdf')
            ->setMimeType('application/pdf')->setTamanhoBytes(1234);
        $pasta->getDocumentos()->add($documento);
        $this->em()->persist($documento);
        $this->em()->flush();

        return $documento;
    }

    private function processoEmSegredo(Pasta $pasta): void
    {
        $processo = new Processo();
        $processo->setNumeroProcesso(sprintf('0701134572025807%04d', random_int(0, 9999)));
        $processo->setSiglaTribunal('TJDFT');
        $processo->setClasseProcessual('Procedimento Comum');
        $processo->setAssuntoProcessual('Assunto');
        $processo->setTenant($this->escritorio);
        $processo->setNivelSigilo(1);
        $this->em()->persist($processo);
        $vinculo = new PastaProcesso($pasta, $processo);
        $this->em()->persist($vinculo);
        $pasta->getPastaProcessos()->add($vinculo);
    }
}
