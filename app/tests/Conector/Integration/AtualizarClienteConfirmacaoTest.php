<?php

declare(strict_types=1);

namespace App\Tests\Conector\Integration;

use App\Cliente\Entity\Cliente;
use App\Cliente\Entity\ClientePF;
use App\Cliente\Entity\EnvioAutomaticoDoCliente;
use App\Cliente\Enum\ChaveDoEnvioAutomatico;
use App\Conector\DTO\ChamadaDoConector;
use App\Conector\DTO\RespostaDaFerramenta;
use App\Conector\Entity\ClienteDoConector;
use App\Conector\Entity\ConsentimentoDoConector;
use App\Conector\Enum\TipoDeClienteDoConector;
use App\Conector\Exception\ErroDaFerramenta;
use App\Conector\Ferramenta\AtualizarClienteCadastro;
use App\Conector\Ferramenta\AtualizarClienteConfirmacao;
use App\Conector\Ferramenta\AtualizarClienteFerramenta;
use App\Conector\Ferramenta\AtualizarClienteLote;
use App\Conector\Ferramenta\AtualizarClienteProposta;
use App\Conector\Ferramenta\AtualizarClienteVinculos;
use App\Conector\Ferramenta\ConfirmarAcaoFerramenta;
use App\Conector\UseCase\ConfirmarPropostaDoConectorUseCase;
use App\Entity\Auth\User;
use App\Entity\Auth\UserTenant;
use App\Entity\Tenant\Tenant;
use App\Entity\Tenant\TenantRolePermission;
use App\Pasta\Entity\Pasta;
use App\Tests\Conector\Support\CenarioDoConectorTrait;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * `atualizar_cliente`, rodada 2: as gravações POR PROPOSTA, pelo fluxo real (a ferramenta propõe pelo
 * `PropostasDoConector` de verdade; a `confirmar_acao` do container confirma pelo executor de verdade), com o banco
 * (spec mcp-remoto-oauth, 6.6, 6.7 e 12, itens 20 e 22): cadastrar de 1 a 50, 51 recusado; CPF já cadastrado e repetido
 * na lista saem com o motivo e o nome; sem pasta exige `resources.carteira.gerenciar`; nada é gravado antes do "sim" e a
 * confirmação grava uma vez; a reconferência recusa sem gravar quando o CPF virou repetido, a permissão saiu, a pasta foi
 * para a lixeira ou o envio automático foi ligado; a troca de valor preenchido e o desvincular vão por proposta; e
 * nenhum valor de documento ou contato do BANCO sai no resumo nem na confirmação.
 */
#[CoversClass(AtualizarClienteFerramenta::class)]
#[CoversClass(AtualizarClienteLote::class)]
#[CoversClass(AtualizarClienteCadastro::class)]
#[CoversClass(AtualizarClienteVinculos::class)]
#[CoversClass(AtualizarClienteConfirmacao::class)]
#[CoversClass(AtualizarClienteProposta::class)]
final class AtualizarClienteConfirmacaoTest extends KernelTestCase
{
    use CenarioDoConectorTrait;

    /** O cliente de fábrica: CPF, e-mail e celular do BANCO, que nunca podem sair numa resposta. */
    private const CPF_DO_BANCO = '529.982.247-25';
    private const EMAIL_DO_BANCO = 'maria.banco.sigiloso@exemplo.com.br';
    private const CELULAR_DO_BANCO = '(61) 98765-4321';
    private const PROFISSAO_DO_BANCO = 'Professora Antiga';

    private const CPF_NOVO = '390.533.447-05';
    private const EMAIL_NOVO = 'novo.mandado@exemplo.com.br';

    private const EDITOR = [
        'modules.clientes.view', 'resources.cliente.view', 'resources.cliente.edit', 'resources.pasta.view', 'resources.pasta.edit',
        'resources.carteira.gerenciar',
    ];

    private Tenant $escritorio;
    private User $pessoa;
    private UserTenant $vinculo;
    private ClienteDoConector $app;
    /** @var array<int, int> pessoa => consentimento */
    private array $consentimentos = [];

    protected function setUp(): void
    {
        self::bootKernel();
        $this->escritorio = $this->escritorio();
        $this->pessoa = $this->pessoa();
        $this->vinculo = $this->vinculo($this->pessoa, $this->escritorio, permissoes: self::EDITOR);
        $this->app = new ClienteDoConector('https://claude.ai/oauth/' . uniqid() . '/client.json', TipoDeClienteDoConector::Cimd, 'Claude', ['https://claude.ai/api/mcp/auth_callback'], new \DateTimeImmutable('-1 hour'));
        $this->em()->persist($this->app);
        $this->em()->flush();
    }

    protected function tearDown(): void
    {
        static::getContainer()->get('services_resetter')->reset();
        parent::tearDown();
    }

    #[TestDox('12.22: cadastrar 1 cliente sem pasta vira proposta e nada grava; o resumo traz os valores mandados; a confirmação grava uma vez e devolve só id e nome')]
    public function testCadastrarUm(): void
    {
        $antes = $this->clientesNoBanco();

        $proposta = $this->chamar(['acao' => 'cadastrar', 'clientes' => [['tipo' => 'pf', 'campos' => ['nome' => 'Joana Nova Cliente', 'cpf' => self::CPF_NOVO, 'email' => self::EMAIL_NOVO]]]]);

        self::assertSame('aguardando_confirmacao', $proposta->dados['estado']);
        $resumo = $proposta->dados['proposta']['resumo'];
        self::assertStringContainsString('Cadastrar 1 cliente no escritório:', $resumo);
        self::assertStringContainsString('1. Pessoa física, sem pasta: nome: Joana Nova Cliente; CPF: ' . self::CPF_NOVO . '; e-mail: ' . self::EMAIL_NOVO, $resumo, '6.2, regra 4: o resumo mostra o que o assistente mandou');
        self::assertArrayNotHasKey('detalhes', $proposta->dados);
        self::assertSame($antes, $this->clientesNoBanco(), 'propor não grava cliente');

        $confirmada = $this->confirmar($proposta);

        self::assertSame(['estado', 'ferramenta', 'acao', 'resultado'], array_keys($confirmada->dados));
        $criado = $confirmada->dados['resultado']['criados'][0];
        self::assertSame(['item' => 1, 'id' => $criado['id'], 'nome' => 'JOANA NOVA CLIENTE', 'pasta_id' => null], $criado);
        self::assertSame([], $confirmada->dados['resultado']['recusados']);
        self::assertSame($antes + 1, $this->clientesNoBanco());
        self::assertSame('39053344705', preg_replace('/\D/', '', (string) $this->em()->getConnection()->fetchOne('SELECT cpf FROM cliente_pf WHERE id = ?', [$criado['id']])));
        self::assertSemValores($confirmada, [self::CPF_NOVO, '39053344705', self::EMAIL_NOVO]);

        $erro = $this->recusaDaConfirmacao($proposta);
        self::assertSame(ConfirmarPropostaDoConectorUseCase::MOTIVO_NAO_VALE, $erro->motivo, 'uso único');
        self::assertSame($antes + 1, $this->clientesNoBanco(), 'grava uma vez');
    }

    #[TestDox('12.22: 50 clientes (com e sem pasta, pessoa física e jurídica) são uma proposta e uma confirmação; todos gravados e os da pasta ligados a ela')]
    public function testCadastrarCinquenta(): void
    {
        $pasta = $this->pasta();
        $clientes = [['tipo' => 'pj', 'campos' => ['razao_social' => 'Empresa Nova Ltda', 'cnpj' => '11.222.333/0001-81']]];
        for ($i = 1; $i < AtualizarClienteLote::MAXIMO; ++$i) {
            $clientes[] = ['tipo' => 'pf', 'campos' => ['nome' => 'Cliente ' . $i, 'cpf' => self::cpf($i)]] + ($i % 2 === 0 ? ['pasta_id' => $pasta->getId()] : []);
        }
        $antes = $this->clientesNoBanco();

        $proposta = $this->chamar(['acao' => 'cadastrar', 'clientes' => $clientes]);
        self::assertStringStartsWith('Cadastrar 50 clientes no escritório:', $proposta->dados['proposta']['resumo']);
        self::assertSame($antes, $this->clientesNoBanco());

        $resultado = $this->confirmar($proposta)->dados['resultado'];

        self::assertCount(50, $resultado['criados']);
        self::assertSame([], $resultado['recusados']);
        self::assertSame($antes + 50, $this->clientesNoBanco());
        self::assertSame(24, $this->vinculos($pasta));
    }

    #[TestDox('12.22: 51 clientes e a lista vazia recusam com a mensagem fixa, sem proposta')]
    public function testCadastrarCinquentaEUm(): void
    {
        $clientes = array_fill(0, AtualizarClienteLote::MAXIMO + 1, ['tipo' => 'pf', 'campos' => ['nome' => 'Fulano']]);

        foreach ([$clientes, []] as $lista) {
            $erro = $this->recusa(['acao' => 'cadastrar', 'clientes' => $lista]);
            self::assertSame(AtualizarClienteLote::LISTA_FORA_DO_TAMANHO, $erro->getMessage());
        }
        self::assertSame(0, $this->propostasNoBanco());
    }

    #[TestDox('12.22 e 6.7, item 5: o CPF já cadastrado sai com o motivo, o id e o NOME do cadastro existente; o repetido na lista sai apontando o primeiro; sem valor do banco na resposta')]
    public function testDuplicadosSaemDaProposta(): void
    {
        $existente = $this->cliente();

        $proposta = $this->chamar(['acao' => 'cadastrar', 'clientes' => [
            ['tipo' => 'pf', 'campos' => ['nome' => 'Outra Maria', 'cpf' => '52998224725']],
            ['tipo' => 'pf', 'campos' => ['nome' => 'Primeiro do Lote', 'cpf' => self::CPF_NOVO]],
            ['tipo' => 'pf', 'campos' => ['nome' => 'Segundo do Lote', 'cpf' => '390.533.44705']],
            ['tipo' => 'pf', 'campos' => ['nome' => 'Sem Documento']],
        ]]);

        $fora = $proposta->dados['detalhes']['fora_da_lista'];
        self::assertSame([
            ['item' => 1, 'motivo' => AtualizarClienteLote::MOTIVO_JA_CADASTRADO, 'mensagem' => AtualizarClienteLote::JA_CADASTRADO, 'cadastro_existente' => ['id' => (int) $existente->getId(), 'nome' => 'MARIA DO BANCO DA SILVA']],
            ['item' => 3, 'motivo' => AtualizarClienteLote::MOTIVO_REPETIDO_NA_LISTA, 'mensagem' => AtualizarClienteLote::REPETIDO_NA_LISTA, 'igual_ao_item' => 2],
        ], $fora);
        $resumo = $proposta->dados['proposta']['resumo'];
        self::assertStringStartsWith('Cadastrar 2 clientes no escritório:', $resumo);
        self::assertStringContainsString('2. Pessoa física, sem pasta: nome: Primeiro do Lote', $resumo);
        self::assertStringContainsString('4. Pessoa física, sem pasta: nome: Sem Documento', $resumo);
        self::assertStringNotContainsString('Outra Maria', $resumo);
        self::assertSemValores($proposta, [self::EMAIL_DO_BANCO, self::CELULAR_DO_BANCO, self::PROFISSAO_DO_BANCO]);

        $resultado = $this->confirmar($proposta)->dados['resultado'];
        self::assertSame([2, 4], array_column($resultado['criados'], 'item'));
    }

    #[TestDox('12.22: sem resources.carteira.gerenciar, o item sem pasta sai (sem permissão) e o da pasta fica; se nenhum sobra, não há proposta')]
    public function testSemCarteiraGerenciar(): void
    {
        $semCarteira = $this->pessoa();
        $this->vinculo($semCarteira, $this->escritorio, permissoes: array_values(array_diff(self::EDITOR, ['resources.carteira.gerenciar'])));
        $pasta = $this->pasta();

        $proposta = $this->chamar(['acao' => 'cadastrar', 'clientes' => [
            ['tipo' => 'pf', 'campos' => ['nome' => 'Avulso']],
            ['tipo' => 'pf', 'campos' => ['nome' => 'Da Pasta'], 'pasta_id' => $pasta->getId()],
        ]], $semCarteira);
        self::assertSame([['item' => 1, 'motivo' => ErroDaFerramenta::MOTIVO_SEM_PERMISSAO, 'mensagem' => ErroDaFerramenta::SEM_PERMISSAO]], $proposta->dados['detalhes']['fora_da_lista']);
        self::assertStringContainsString('2. Pessoa física, na pasta ATC-', $proposta->dados['proposta']['resumo']);
        $propostas = $this->propostasNoBanco();

        $nada = $this->chamar(['acao' => 'cadastrar', 'clientes' => [['tipo' => 'pf', 'campos' => ['nome' => 'Avulso']]]], $semCarteira);

        self::assertSame('recusada', $nada->dados['estado']);
        self::assertSame(AtualizarClienteLote::NADA_A_CADASTRAR, $nada->dados['mensagem']);
        self::assertSame(ErroDaFerramenta::MOTIVO_SEM_PERMISSAO, $nada->dados['fora_da_lista'][0]['motivo']);
        self::assertArrayNotHasKey('proposta', $nada->dados);
        self::assertSame($propostas, $this->propostasNoBanco(), 'nenhuma proposta nova');
    }

    #[TestDox('12.1: pasta de outro escritório no item dá não encontrado e o item sai da proposta')]
    public function testPastaAlheiaNoItem(): void
    {
        $outro = $this->escritorio('Outro');
        $alheia = $this->pasta(null, $outro);

        $resposta = $this->chamar(['acao' => 'cadastrar', 'clientes' => [['tipo' => 'pf', 'campos' => ['nome' => 'Fulano'], 'pasta_id' => $alheia->getId()]]]);

        self::assertSame(ErroDaFerramenta::MOTIVO_NAO_ENCONTRADO, $resposta->dados['fora_da_lista'][0]['motivo']);
        self::assertSame(0, $this->vinculos($alheia));
    }

    #[TestDox('6.7, item 4: o CPF virou repetido entre propor e confirmar: com um item, a confirmação recusa, nada grava e a proposta fica pendente')]
    public function testCpfVirouRepetidoComUmItem(): void
    {
        $proposta = $this->chamar(['acao' => 'cadastrar', 'clientes' => [['tipo' => 'pf', 'campos' => ['nome' => 'Joana', 'cpf' => self::CPF_NOVO]]]]);
        $this->cliente(cpf: self::CPF_NOVO);
        $antes = $this->clientesNoBanco();

        $erro = $this->recusaDaConfirmacao($proposta);

        self::assertSame(AtualizarClienteLote::MOTIVO_JA_CADASTRADO, $erro->motivo);
        self::assertSame(AtualizarClienteLote::NADA_PASSA_NA_CONFIRMACAO, $erro->getMessage());
        self::assertSame($antes, $this->clientesNoBanco());
        self::assertSame('pendente', $this->estadoDasPropostas()[0]);
    }

    #[TestDox('12.22: o CPF virou repetido num item de dois: resultado parcial, o outro é gravado e o repetido volta recusado com o motivo')]
    public function testCpfVirouRepetidoNoLote(): void
    {
        $proposta = $this->chamar(['acao' => 'cadastrar', 'clientes' => [
            ['tipo' => 'pf', 'campos' => ['nome' => 'Joana', 'cpf' => self::CPF_NOVO]],
            ['tipo' => 'pf', 'campos' => ['nome' => 'Pedro', 'cpf' => self::cpf(7)]],
        ]]);
        $outro = $this->cliente(cpf: self::CPF_NOVO);
        $antes = $this->clientesNoBanco();

        $resultado = $this->confirmar($proposta)->dados['resultado'];

        self::assertSame([2], array_column($resultado['criados'], 'item'));
        self::assertSame(1, $resultado['recusados'][0]['item']);
        self::assertSame(AtualizarClienteLote::MOTIVO_JA_CADASTRADO, $resultado['recusados'][0]['motivo']);
        self::assertSame((int) $outro->getId(), $resultado['recusados'][0]['cadastro_existente']['id']);
        self::assertSame($antes + 1, $this->clientesNoBanco());
    }

    #[TestDox('6.7, item 4: a permissão resources.carteira.gerenciar saiu entre propor e confirmar: recusa e nada grava')]
    public function testPermissaoSaiu(): void
    {
        $proposta = $this->chamar(['acao' => 'cadastrar', 'clientes' => [['tipo' => 'pf', 'campos' => ['nome' => 'Joana']]]]);
        $this->tirarPermissao('resources.carteira.gerenciar');
        $antes = $this->clientesNoBanco();

        $erro = $this->recusaDaConfirmacao($proposta);

        self::assertSame(ErroDaFerramenta::MOTIVO_SEM_PERMISSAO, $erro->motivo);
        self::assertSame($antes, $this->clientesNoBanco());
        self::assertSame('pendente', $this->estadoDasPropostas()[0]);
    }

    #[TestDox('6.7, item 4: a pasta do item foi para a lixeira entre propor e confirmar: recusa e nada grava')]
    public function testPastaFoiParaALixeira(): void
    {
        $pasta = $this->pasta();
        $proposta = $this->chamar(['acao' => 'cadastrar', 'clientes' => [['tipo' => 'pf', 'campos' => ['nome' => 'Joana'], 'pasta_id' => $pasta->getId()]]]);
        $this->ref(Pasta::class, (int) $pasta->getId())->marcarExcluida($this->ref(User::class, (int) $this->pessoa->getId()), new \DateTimeImmutable());
        $this->em()->flush();
        $antes = $this->clientesNoBanco();

        $erro = $this->recusaDaConfirmacao($proposta);

        self::assertSame(ErroDaFerramenta::MOTIVO_NAO_ENCONTRADO, $erro->motivo);
        self::assertSame($antes, $this->clientesNoBanco());
        self::assertSame(0, $this->vinculos($pasta));
    }

    #[TestDox('6.6, gravar por cima: completar que troca um valor e preenche um vazio vira UMA proposta; o banco só muda depois do sim; o resumo não traz valor antigo do banco')]
    public function testTrocaPorProposta(): void
    {
        $cliente = $this->cliente();
        $antes = $this->doBanco($cliente);

        $proposta = $this->chamar(['acao' => 'completar', 'cliente_id' => $cliente->getId(), 'campos' => ['profissao' => 'Engenheira Nova', 'bairro' => 'Centro Novo', 'cpf' => '52998224725']]);

        self::assertSame('aguardando_confirmacao', $proposta->dados['estado']);
        self::assertSame(['campos_que_trocam' => ['profissao'], 'campos_vazios' => ['bairro']], $proposta->dados['detalhes']);
        $resumo = $proposta->dados['proposta']['resumo'];
        self::assertSame(sprintf("Alterar o cadastro do cliente MARIA DO BANCO DA SILVA (id %d):\n- profissão (troca o valor atual): Engenheira Nova\n- bairro (campo vazio): Centro Novo", $cliente->getId()), $resumo);
        self::assertSemValores($proposta, [self::PROFISSAO_DO_BANCO, 'Antiga']);
        self::assertSame($antes, $this->doBanco($cliente), 'nada muda antes do sim');

        $confirmada = $this->confirmar($proposta);

        self::assertEqualsCanonicalizing(['profissao', 'bairro'], $confirmada->dados['resultado']['campos_alterados']);
        self::assertSame(['id' => (int) $cliente->getId(), 'nome' => 'MARIA DO BANCO DA SILVA'], $confirmada->dados['resultado']['cliente']);
        $depois = $this->doBanco($cliente);
        self::assertSame(['ENGENHEIRA NOVA', 'CENTRO NOVO'], [mb_strtoupper($depois['profissao']), mb_strtoupper($depois['bairro'])]);
        self::assertSemValores($confirmada, [self::PROFISSAO_DO_BANCO, 'Engenheira Nova', 'Centro Novo']);
    }

    #[TestDox('6.6: contato que troca o celular vira proposta; o celular só muda depois do sim; apagar o e-mail recusa já na proposta')]
    public function testContatoPorProposta(): void
    {
        $cliente = $this->cliente();

        $proposta = $this->chamar(['acao' => 'contato', 'cliente_id' => $cliente->getId(), 'campo' => 'celular', 'valor' => '61911112222']);

        self::assertSame(sprintf('Trocar o celular do cliente MARIA DO BANCO DA SILVA (id %d) por: 61911112222', $cliente->getId()), $proposta->dados['proposta']['resumo']);
        self::assertSame(self::CELULAR_DO_BANCO, $this->doBanco($cliente)['telefone_celular']);
        self::assertSemValores($proposta, []);

        self::assertSame(['celular'], $this->confirmar($proposta)->dados['resultado']['campos_alterados']);
        self::assertSame('(61) 91111-2222', $this->doBanco($cliente)['telefone_celular']);

        $erro = $this->recusa(['acao' => 'contato', 'cliente_id' => $cliente->getId(), 'campo' => 'email', 'valor' => '']);
        self::assertSame(AtualizarClienteCadastro::CONTATO_RECUSADO, $erro->getMessage());
    }

    #[TestDox('6.6: a troca para um CPF de outro cadastro recusa já na proposta; o CPF que virou repetido depois da proposta recusa na confirmação')]
    public function testTrocaParaCpfRepetido(): void
    {
        $cliente = $this->cliente();
        $this->cliente(cpf: self::CPF_NOVO);

        $erro = $this->recusa(['acao' => 'completar', 'cliente_id' => $cliente->getId(), 'campos' => ['cpf' => self::CPF_NOVO]]);
        self::assertSame(\App\Conector\Ferramenta\AtualizarClienteAcesso::DOCUMENTO_JA_CADASTRADO, $erro->getMessage());

        $proposta = $this->chamar(['acao' => 'completar', 'cliente_id' => $cliente->getId(), 'campos' => ['cpf' => self::cpf(3)]]);
        $this->cliente(cpf: self::cpf(3));

        self::assertSame(\App\Conector\Ferramenta\AtualizarClienteAcesso::DOCUMENTO_JA_CADASTRADO, $this->recusaDaConfirmacao($proposta)->getMessage());
        self::assertSame('52998224725', preg_replace('/\D/', '', $this->doBanco($cliente)['cpf']));
    }

    #[TestDox('6.7, item 4, envio automático: ligado entre a proposta e a confirmação, a troca do e-mail recusa na confirmação e o e-mail não muda (sem a reconferência do envio no executor, este teste cai)')]
    public function testEnvioLigadoEntreProporEConfirmar(): void
    {
        $cliente = $this->cliente();
        $proposta = $this->chamar(['acao' => 'completar', 'cliente_id' => $cliente->getId(), 'campos' => ['email' => self::EMAIL_NOVO]]);
        $this->envio($cliente, ChaveDoEnvioAutomatico::NovasMovimentacoes);

        $erro = $this->recusaDaConfirmacao($proposta);

        self::assertSame(ErroDaFerramenta::MOTIVO_ENVIO_AUTOMATICO, $erro->motivo);
        self::assertSame(self::EMAIL_DO_BANCO, $this->doBanco($cliente)['email']);
        self::assertSame('pendente', $this->estadoDasPropostas()[0]);
    }

    #[TestDox('desvincular (vincular_a_pasta, operacao desvincular): vira proposta; o vínculo só sai depois do sim, o cadastro continua e o outro cliente vira o principal')]
    public function testDesvincular(): void
    {
        $principal = $this->cliente();
        $outro = $this->cliente(cpf: self::CPF_NOVO, nome: 'Pedro Segundo');
        $pasta = $this->pasta($principal);
        $pasta->addCliente($outro);
        $this->em()->flush();

        $proposta = $this->chamar(['acao' => 'vincular_a_pasta', 'operacao' => 'desvincular', 'cliente_id' => $principal->getId(), 'pasta_id' => $pasta->getId()]);

        self::assertStringContainsString(sprintf('Desvincular o cliente MARIA DO BANCO DA SILVA (id %d) da pasta ATC-', $principal->getId()), $proposta->dados['proposta']['resumo']);
        self::assertStringContainsString(sprintf('PEDRO SEGUNDO (id %d) passa a ser o principal', $outro->getId()), $proposta->dados['proposta']['resumo']);
        self::assertSame(2, $this->vinculos($pasta), 'nada muda antes do sim');
        self::assertSemValores($proposta, []);

        $resultado = $this->confirmar($proposta)->dados['resultado'];

        self::assertSame(['id' => (int) $outro->getId(), 'nome' => 'PEDRO SEGUNDO'], $resultado['novo_principal']);
        self::assertSame(1, $this->vinculos($pasta));
        self::assertSame((int) $outro->getId(), $this->principalNoBanco($pasta));
        self::assertSame(1, (int) $this->em()->getConnection()->fetchOne('SELECT COUNT(*) FROM cliente WHERE id = ?', [$principal->getId()]), 'o cadastro continua');
    }

    #[TestDox('abertura da 6.6: desvincular o principal quando o promovido tem o envio ligado recusa na proposta; ligado depois da proposta, recusa na confirmação; nada muda (sem a conferência do promovido, este teste cai)')]
    public function testDesvincularPrincipalComPromovidoDeEnvioLigado(): void
    {
        $principal = $this->cliente();
        $promovido = $this->cliente(cpf: self::CPF_NOVO, nome: 'Pedro Segundo');
        $pasta = $this->pasta($principal);
        $pasta->addCliente($promovido);
        $this->em()->flush();
        $pedido = ['acao' => 'vincular_a_pasta', 'operacao' => 'desvincular', 'cliente_id' => $principal->getId(), 'pasta_id' => $pasta->getId()];
        $proposta = $this->chamar($pedido);
        $this->envio($promovido, ChaveDoEnvioAutomatico::cases()[0]);

        self::assertSame(ErroDaFerramenta::MOTIVO_ENVIO_AUTOMATICO, $this->recusa($pedido)->motivo);
        self::assertSame(ErroDaFerramenta::MOTIVO_ENVIO_AUTOMATICO, $this->recusaDaConfirmacao($proposta)->motivo);
        self::assertSame(2, $this->vinculos($pasta));
        self::assertSame((int) $principal->getId(), $this->principalNoBanco($pasta));
    }

    #[TestDox('desvincular o cliente que já saiu da pasta entre propor e confirmar recusa (cliente não vinculado); fora da pasta, nem propõe')]
    public function testDesvincularQuemJaSaiu(): void
    {
        $cliente = $this->cliente();
        $pasta = $this->pasta($cliente);
        $pedido = ['acao' => 'vincular_a_pasta', 'operacao' => 'desvincular', 'cliente_id' => $cliente->getId(), 'pasta_id' => $pasta->getId()];
        $proposta = $this->chamar($pedido);
        $this->em()->getConnection()->executeStatement('DELETE FROM pasta_cliente WHERE pasta_id = ?', [$pasta->getId()]);
        $this->em()->clear();

        self::assertSame(AtualizarClienteVinculos::NAO_VINCULADO, $this->recusaDaConfirmacao($proposta)->getMessage());
        self::assertSame(AtualizarClienteVinculos::NAO_VINCULADO, $this->recusa($pedido)->getMessage());
    }

    /**
     * Nenhum valor de documento ou contato do BANCO (nem os mandados, quando a lista os inclui) no JSON inteiro.
     *
     * @param list<string> $valores
     */
    private static function assertSemValores(RespostaDaFerramenta $resposta, array $valores): void
    {
        $json = json_encode($resposta->dados, \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES);
        $soDigitos = (string) preg_replace('#[.\-()/\s]#', '', $json);
        foreach ([...$valores, self::CPF_DO_BANCO, self::EMAIL_DO_BANCO, self::CELULAR_DO_BANCO] as $valor) {
            self::assertStringNotContainsString(mb_strtoupper($valor), mb_strtoupper($json), 'o valor não sai: ' . $valor);
        }
        foreach (['52998224725', '61987654321'] as $digitos) {
            self::assertStringNotContainsString($digitos, $soDigitos);
        }
    }

    /** @param array<string, mixed> $argumentos */
    private function chamar(array $argumentos, ?User $pessoa = null): RespostaDaFerramenta
    {
        try {
            return static::getContainer()->get(AtualizarClienteFerramenta::class)->executar($argumentos, $this->chamada($pessoa ?? $this->pessoa));
        } finally {
            static::getContainer()->get('services_resetter')->reset();
        }
    }

    /** @param array<string, mixed> $argumentos */
    private function recusa(array $argumentos, ?User $pessoa = null): ErroDaFerramenta
    {
        try {
            $this->chamar($argumentos, $pessoa);
        } catch (ErroDaFerramenta $e) {
            return $e;
        }
        self::fail('deveria recusar: ' . json_encode($argumentos, \JSON_THROW_ON_ERROR));
    }

    /** A `confirmar_acao` do container, com o id e o resumo exatos da proposta. */
    private function confirmar(RespostaDaFerramenta $proposta, ?User $pessoa = null): RespostaDaFerramenta
    {
        $dados = $proposta->dados['proposta'] ?? self::fail('a resposta não é uma proposta');
        try {
            return static::getContainer()->get(ConfirmarAcaoFerramenta::class)->executar(['proposta_id' => $dados['proposta_id'], 'resumo' => $dados['resumo']], $this->chamada($pessoa ?? $this->pessoa));
        } finally {
            static::getContainer()->get('services_resetter')->reset();
        }
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

    private function chamada(User $pessoa): ChamadaDoConector
    {
        $id = (int) $pessoa->getId();
        if (!isset($this->consentimentos[$id])) {
            $consentimento = new ConsentimentoDoConector($this->ref(User::class, $id), $this->doEscritorio(), $this->ref(ClienteDoConector::class, (int) $this->app->getId()), ['mcp:leitura', 'mcp:escrita'], new \DateTimeImmutable('-1 hour'));
            $this->em()->persist($consentimento);
            $this->em()->flush();
            $this->consentimentos[$id] = (int) $consentimento->getId();
        }
        $contexto = $this->portao()->abrir($id, (int) $this->escritorio->getId());

        return new ChamadaDoConector($contexto, 41, $this->consentimentos[$id], 'claude.ai', ['mcp:leitura', 'mcp:escrita'], new \DateTimeImmutable('+7 days'));
    }

    /**
     * A entidade gerenciada pelo id: o `services_resetter` de cada chamada (e a confirmação recusada) limpa o
     * EntityManager e solta as entidades do cenário.
     *
     * @template T of object
     *
     * @param class-string<T> $classe
     *
     * @return T
     */
    private function ref(string $classe, int $id): object
    {
        return $this->em()->find($classe, $id) ?? self::fail('sumiu: ' . $classe);
    }

    private function doEscritorio(): Tenant
    {
        return $this->ref(Tenant::class, (int) $this->escritorio->getId());
    }

    private function tirarPermissao(string $codigo): void
    {
        $vinculo = $this->ref(UserTenant::class, (int) $this->vinculo->getId());
        foreach ($vinculo->getTenantRole()?->getTenantRolePermissions() ?? [] as $ligacao) {
            \assert($ligacao instanceof TenantRolePermission);
            if ($ligacao->getPermission()?->getCode() === $codigo) {
                $this->em()->remove($ligacao);
            }
        }
        $this->em()->flush();
        static::getContainer()->get('services_resetter')->reset();
    }

    private function clientesNoBanco(): int
    {
        return (int) $this->em()->getConnection()->fetchOne('SELECT COUNT(*) FROM cliente WHERE tenant_id = ?', [$this->escritorio->getId()]);
    }

    private function propostasNoBanco(): int
    {
        return (int) $this->em()->getConnection()->fetchOne('SELECT COUNT(*) FROM conector_ia_proposta WHERE tenant_id = ?', [$this->escritorio->getId()]);
    }

    /** @return list<string> */
    private function estadoDasPropostas(): array
    {
        return array_map('strval', $this->em()->getConnection()->fetchFirstColumn('SELECT estado FROM conector_ia_proposta WHERE tenant_id = ? ORDER BY id', [$this->escritorio->getId()]));
    }

    /** @return array<string, mixed> a linha do cliente lida direto do banco */
    private function doBanco(Cliente $cliente): array
    {
        $linha = $this->em()->getConnection()->fetchAssociative(
            'SELECT c.bairro, c.email, c.telefone_celular, pf.cpf, pf.profissao FROM cliente c LEFT JOIN cliente_pf pf ON pf.id = c.id WHERE c.id = :id',
            ['id' => $cliente->getId()],
        );
        self::assertIsArray($linha);

        return array_map(static fn (mixed $v): string => (string) $v, $linha);
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

    private function cliente(string $cpf = self::CPF_DO_BANCO, string $nome = 'Maria do Banco da Silva'): ClientePF
    {
        $cliente = (new ClientePF())->setNomeCompleto($nome)->setCpf($cpf)->setRg('')->setRgOrgaoExpedidor('')->setProfissao(self::PROFISSAO_DO_BANCO)->setNacionalidade('brasileira');
        $cliente->setEmail(self::EMAIL_DO_BANCO)->setTelefoneCelular(self::CELULAR_DO_BANCO)->setCep('')->setEndereco('')->setCidade('Brasília')->setEstado('DF')->setTenant($this->doEscritorio());
        $this->em()->persist($cliente);
        $this->em()->flush();

        return $cliente;
    }

    private function pasta(?Cliente $cliente = null, ?Tenant $escritorio = null): Pasta
    {
        $pasta = (new Pasta())->setNup('ATC-' . uniqid());
        $pasta->setTenant($escritorio ?? $this->doEscritorio());
        if ($cliente !== null) {
            $pasta->addCliente($cliente);
        }
        $this->em()->persist($pasta);
        $this->em()->flush();

        return $pasta;
    }

    private function envio(Cliente $cliente, ChaveDoEnvioAutomatico $chave): void
    {
        $pessoa = $this->ref(User::class, (int) $this->pessoa->getId());
        $linha = new EnvioAutomaticoDoCliente($this->doEscritorio(), $this->ref(Cliente::class, (int) $cliente->getId()), $pessoa, new \DateTimeImmutable());
        $linha->definir($chave, true, $pessoa, new \DateTimeImmutable());
        $this->em()->persist($linha);
        $this->em()->flush();
    }

    /** Um CPF válido e diferente para cada `$n` (os dois dígitos verificadores calculados). */
    private static function cpf(int $n): string
    {
        $base = str_pad((string) (123_456_000 + $n), 9, '0', \STR_PAD_LEFT);
        foreach ([10, 11] as $peso) {
            $soma = 0;
            foreach (str_split($base) as $i => $digito) {
                $soma += (int) $digito * ($peso - $i);
            }
            $resto = ($soma * 10) % 11;
            $base .= (string) ($resto === 10 ? 0 : $resto);
        }

        return $base;
    }
}
