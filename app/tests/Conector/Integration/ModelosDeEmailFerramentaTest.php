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
use App\Conector\Ferramenta\LerComercialFerramenta;
use App\Conector\Ferramenta\ModelosDeEmailFerramenta;
use App\Conector\Ferramenta\TabelaDeAbaEChave;
use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Tests\Conector\Support\CenarioDoConectorTrait;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * `modelos_de_email` com o banco e o portão de verdade (spec mcp-remoto-oauth 6.9, decisões 36 a 41): só o Master passa,
 * na chamada e na reconferência; criar, duplicar e salvar como gravam na hora; editar (próprio e padrão), voltar ao
 * padrão e excluir viram proposta e só gravam confirmados; o modelo mudado entre propor e confirmar recusa; toda
 * gravação sai no `audit_log` com a rota `conector_mcp`.
 */
#[CoversClass(ModelosDeEmailFerramenta::class)]
final class ModelosDeEmailFerramentaTest extends KernelTestCase
{
    use CenarioDoConectorTrait;

    private Tenant $escritorio;
    private User $pessoa;
    private ConsentimentoDoConector $consentimento;

    protected function setUp(): void
    {
        self::bootKernel();
        static::getContainer()->get(RequestStack::class)->push(new Request(attributes: ['_route' => 'conector_mcp']));
        $this->escritorio = $this->escritorio();
        $this->pessoa = $this->pessoa(['ROLE_USER', 'ROLE_SUPER_ADMIN']);
        $this->vinculo($this->pessoa, $this->escritorio);
        $doConector = new EscritorioDoConector($this->escritorio);
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

    /** @return array<string, array{string}> */
    public static function acoes(): array
    {
        return array_combine(ModelosDeEmailFerramenta::ACOES, array_map(static fn (string $a): array => [$a], ModelosDeEmailFerramenta::ACOES));
    }

    #[DataProvider('acoes')]
    #[TestDox('segunda porta: quem não é Master é recusado em toda ação, com a mensagem neutra, sem gravar nem propor')]
    public function testNaoMasterRecusado(string $acao): void
    {
        $comum = $this->pessoa(['ROLE_USER', 'ROLE_ADMIN']);
        $this->vinculo($comum, $this->escritorio);
        $antes = $this->estadoDoBanco();

        try {
            $this->executar($acao === 'criar' ? ['acao' => $acao] : ['acao' => $acao, 'modelo' => 'convite', 'nome' => 'X'], $comum);
            self::fail('devia recusar');
        } catch (ErroDaFerramenta $erro) {
            self::assertSame(ModelosDeEmailFerramenta::MOTIVO_SO_DO_MASTER, $erro->motivo);
            self::assertSame('Ferramenta não disponível.', $erro->getMessage());
        }
        self::assertSame($antes, $this->estadoDoBanco());
        self::assertSame(0, $this->contarPropostas());
    }

    #[TestDox('a tabela de aba e chave tem uma linha por ação, sem módulo nem chave; o esquema é fechado')]
    public function testTabelaEEsquema(): void
    {
        self::assertSame(ModelosDeEmailFerramenta::ACOES, TabelaDeAbaEChave::acoes(ModelosDeEmailFerramenta::NOME));
        foreach (ModelosDeEmailFerramenta::ACOES as $acao) {
            $linha = TabelaDeAbaEChave::linha(ModelosDeEmailFerramenta::NOME, $acao);
            self::assertNotNull($linha, $acao);
            self::assertSame([], $linha->modulos);
            self::assertSame([], $linha->chaves);
        }
        $esquema = static::getContainer()->get(ModelosDeEmailFerramenta::class)->esquemaDeEntrada();
        self::assertFalse($esquema['additionalProperties']);
        self::assertSame(['acao'], $esquema['required']);
        self::assertArrayHasKey('html', $esquema['properties'], 'editar o HTML de modelo HTML vale; só importar fica fora (decisão 41)');
        self::assertArrayNotHasKey('importar', array_flip(ModelosDeEmailFerramenta::ACOES));
    }

    #[TestDox('entrada: ação fora da lista, modelo faltando ou malformado, campo de outra ação ou de tipo errado é entrada inválida')]
    public function testEntradaInvalida(): void
    {
        $invalidas = [
            [], ['acao' => 'importar_html'], ['acao' => 'duplicar'], ['acao' => 'excluir', 'modelo' => 'Cu 1; DROP'], ['acao' => 'criar', 'modelo' => 'convite'],
            ['acao' => 'duplicar', 'modelo' => 'convite', 'assunto' => 'x'], ['acao' => 'salvar_como', 'modelo' => 'convite'],
            ['acao' => 'editar', 'modelo' => 'convite', 'html' => 3], ['acao' => 'editar', 'modelo' => 'convite', 'assunto' => 3],
            ['acao' => 'editar', 'modelo' => 'convite', 'itens' => 'a'], ['acao' => 'editar', 'modelo' => 'validar', 'blocos' => ['x']],
        ];
        foreach ($invalidas as $argumentos) {
            try {
                $this->executar($argumentos);
                self::fail('devia recusar: ' . json_encode($argumentos));
            } catch (ErroDaFerramenta $erro) {
                self::assertSame(ErroDaFerramenta::MOTIVO_ENTRADA_INVALIDA, $erro->motivo, json_encode($argumentos));
            }
        }
        self::assertSame(0, $this->contarPropostas());
    }

    #[TestDox('criar grava na hora, sem proposta; o audit_log sai com modelo_new, rota conector_mcp e escritório nulo')]
    public function testCriarGravaDireto(): void
    {
        $resposta = $this->executar(['acao' => 'criar']);

        self::assertSame('concluida', $resposta->dados['estado']);
        $id = $resposta->dados['modelo']['id'];
        self::assertSame('cu' . $id, $resposta->dados['modelo']['chave']);
        self::assertSame('blocos', $resposta->dados['modelo']['modo']);
        self::assertSame(1, $this->contar('comercial_modelo', 'id = ' . $id));
        self::assertSame(0, $this->contarPropostas());
        $this->exigirTrilha('modelo_new', (string) $id);
    }

    #[TestDox('duplicar copia o próprio e o padrão na hora, com "(cópia)" no nome, sem mexer no original')]
    public function testDuplicar(): void
    {
        $proprio = $this->criarProprio();
        $copiaDoProprio = $this->executar(['acao' => 'duplicar', 'modelo' => $proprio['chave']]);
        $copiaDoPadrao = $this->executar(['acao' => 'duplicar', 'modelo' => 'convite']);

        self::assertSame('Novo modelo (cópia)', $copiaDoProprio->dados['modelo']['nome']);
        self::assertSame('Convite · novos escritórios (cópia)', $copiaDoPadrao->dados['modelo']['nome']);
        self::assertSame('blocos', $copiaDoPadrao->dados['modelo']['modo'], 'o simples vira blocos, como na tela');
        self::assertTrue($copiaDoPadrao->dados['modelo']['proprio']);
        self::assertSame(3, $this->contar('comercial_modelo'));
        self::assertSame(0, $this->contar('comercial_modelo', 'fixo_chave IS NOT NULL'));
        self::assertSame(0, $this->contarPropostas());
        $this->exigirTrilha('modelo_new', (string) $copiaDoPadrao->dados['modelo']['id']);
    }

    #[TestDox('salvar_como guarda a cópia do padrão em Meus modelos com o nome dado, no mesmo modo; nome vazio é recusado')]
    public function testSalvarComo(): void
    {
        $resposta = $this->executar(['acao' => 'salvar_como', 'modelo' => 'convite', 'nome' => '  Meu   convite  ']);

        self::assertSame('Meu convite', $resposta->dados['modelo']['nome']);
        self::assertSame('simples', $resposta->dados['modelo']['modo']);
        self::assertTrue($resposta->dados['modelo']['proprio']);
        self::assertSame(1, $this->contar('comercial_modelo'));
        $this->exigirTrilha('modelo_new', (string) $resposta->dados['modelo']['id']);

        $antes = $this->estadoDoBanco();
        $this->assertRecusa(['acao' => 'salvar_como', 'modelo' => 'convite', 'nome' => '   '], 'Dê um nome ao seu modelo.');
        self::assertSame($antes, $this->estadoDoBanco());
    }

    #[TestDox('modelo que não existe (próprio ou padrão) dá "não encontrado" em toda ação que usa modelo')]
    public function testModeloInexistente(): void
    {
        foreach (['duplicar', 'salvar_como', 'editar', 'voltar_ao_padrao', 'excluir'] as $acao) {
            foreach (['cu999999', 'nao-existe'] as $modelo) {
                try {
                    $this->executar(['acao' => $acao, 'modelo' => $modelo] + ($acao === 'salvar_como' ? ['nome' => 'X'] : []));
                    self::fail('devia recusar');
                } catch (ErroDaFerramenta $erro) {
                    self::assertSame(ErroDaFerramenta::MOTIVO_NAO_ENCONTRADO, $erro->motivo, $acao . ' ' . $modelo);
                }
            }
        }
    }

    #[TestDox('editar modelo próprio vira proposta com assunto e texto novos em texto puro; só grava confirmada; a trilha leva a rota conector_mcp')]
    public function testEditarProprioPorProposta(): void
    {
        $proprio = $this->criarProprio();
        $proposta = $this->executar(['acao' => 'editar', 'modelo' => $proprio['chave'], 'nome' => 'Renovado', 'assunto' => 'Assunto novo', 'titulo' => 'Título novo', 'blocos' => [['t' => 'texto', 'a' => 'Olá, <b>{nome}</b>! Texto inteiro novo.', 'b' => '']]]);

        self::assertSame('aguardando_confirmacao', $proposta->dados['estado']);
        $resumo = $proposta->dados['proposta']['resumo'];
        self::assertStringContainsString('Assunto novo: Assunto novo', $resumo);
        self::assertStringContainsString('Texto inteiro novo.', $resumo);
        self::assertStringNotContainsString('<table', $resumo, 'texto puro, sem o HTML do e-mail');
        self::assertSame('Novo informativo', $this->linha($proprio['id'])['assunto'], 'propor não grava');
        $ultimo = $this->ultimoIdDaAuditoria();

        $confirmada = $this->confirmar($proposta);

        self::assertSame('confirmada', $confirmada->dados['estado']);
        $linha = $this->linha($proprio['id']);
        self::assertSame('Assunto novo', $linha['assunto']);
        self::assertSame('Renovado', $linha['nome']);
        self::assertSame('modelo_upd', $this->trilhaDesde($ultimo)['action']);
        $this->exigirTrilha('modelo_upd', (string) $proprio['id']);
    }

    #[TestDox('editar modelo padrão (convite) grava a versão do Master só depois do sim; o efetivo e a ler_comercial passam a ver o texto editado')]
    public function testEditarPadraoGravaVersaoDoMaster(): void
    {
        $proposta = $this->executar(['acao' => 'editar', 'modelo' => 'convite', 'assunto' => 'Convite do Master', 'corpo' => 'Texto do Master para o convite.']);

        self::assertSame('aguardando_confirmacao', $proposta->dados['estado']);
        self::assertStringContainsString('versão do Master deste modelo padrão', $proposta->dados['proposta']['resumo']);
        self::assertSame(0, $this->contar('comercial_modelo', "fixo_chave = 'convite'"), 'propor não grava');

        $this->confirmar($proposta);

        self::assertSame(1, $this->contar('comercial_modelo', "fixo_chave = 'convite'"));
        $efetivo = static::getContainer()->get(\App\Comercial\Service\ModelosEfetivos::class)->porChave('convite');
        self::assertSame('Convite do Master', $efetivo['assunto']);
        self::assertTrue($efetivo['editado']);
        $previa = $this->lerComercial(['acao' => 'previa', 'modelo' => 'convite']);
        self::assertSame('Convite do Master', $previa->dados['assunto']);
        self::assertStringContainsString('Texto do Master para o convite.', $previa->dados['corpo_em_texto']);
    }

    #[TestDox('editar o padrão de uma data comemorativa (data-<id>) também grava a versão do Master')]
    public function testEditarPadraoDeData(): void
    {
        $chave = (string) static::getContainer()->get(\App\Comercial\Campanha\ModelosDeCampanha::class)->datas()[0]['chave'];
        $proposta = $this->executar(['acao' => 'editar', 'modelo' => $chave, 'assunto' => 'Feliz data do Master']);

        $this->confirmar($proposta);

        self::assertSame(1, $this->contar('comercial_modelo', "fixo_chave = '" . $chave . "'"));
        self::assertSame('Feliz data do Master', $this->lerComercial(['acao' => 'previa', 'modelo' => $chave])->dados['assunto']);
    }

    #[TestDox('editar: campo que não vale para o modo, nome de padrão e "nada mudou" são recusados ou dão nada a fazer, sem proposta')]
    public function testEditarRegras(): void
    {
        $proprio = $this->criarProprio();
        $this->assertRecusa(['acao' => 'editar', 'modelo' => 'convite', 'nome' => 'Outro'], ModelosDeEmailFerramenta::NOME_DE_PADRAO);
        $this->assertRecusa(['acao' => 'editar', 'modelo' => 'convite', 'blocos' => [['t' => 'texto', 'a' => 'x', 'b' => '']]], ModelosDeEmailFerramenta::CAMPO_DE_OUTRO_MODO);
        $this->assertRecusa(['acao' => 'editar', 'modelo' => $proprio['chave'], 'corpo' => 'x'], ModelosDeEmailFerramenta::CAMPO_DE_OUTRO_MODO);
        $this->assertRecusa(['acao' => 'editar', 'modelo' => $proprio['chave'], 'blocos' => [['t' => 'inexistente', 'a' => '', 'b' => '']]], 'Blocos inválidos.');
        self::assertSame(0, $this->contarPropostas());

        $igual = $this->executar(['acao' => 'editar', 'modelo' => $proprio['chave'], 'assunto' => 'Novo informativo']);
        self::assertSame('nada_a_fazer', $igual->dados['estado']);
        self::assertSame(0, $this->contarPropostas());
    }

    #[TestDox('editar o HTML de um modelo HTML: o resumo traz o texto puro e, depois do sim, o script some do que fica gravado')]
    public function testEditarHtml(): void
    {
        $id = $this->inserirModelo('html', 'Modelo HTML', '<p>Antigo</p>');
        $proposta = $this->executar(['acao' => 'editar', 'modelo' => 'cu' . $id, 'html' => '<p>Texto HTML novo</p><script>alert(1)</script>']);

        self::assertSame('aguardando_confirmacao', $proposta->dados['estado']);
        self::assertStringContainsString('Texto HTML novo', $proposta->dados['proposta']['resumo']);
        self::assertStringNotContainsString('<p>', $proposta->dados['proposta']['resumo']);
        self::assertSame('<p>Antigo</p>', $this->linha($id)['conteudo'], 'propor não grava');

        $this->confirmar($proposta);

        $conteudo = (string) $this->linha($id)['conteudo'];
        self::assertStringContainsString('Texto HTML novo', $conteudo);
        self::assertStringNotContainsString('<script', $conteudo);
        $this->assertRecusa(['acao' => 'editar', 'modelo' => 'cu' . $id, 'corpo' => 'x'], ModelosDeEmailFerramenta::CAMPO_DE_OUTRO_MODO);
    }

    #[TestDox('modelo grande: editar só o assunto vira proposta com o resumo cortado, e o modelo é gravado inteiro')]
    public function testModeloGrandeEditarSoOAssunto(): void
    {
        $blocos = array_fill(0, 40, ['t' => 'texto', 'a' => str_repeat('palavra ', 2500), 'b' => '']);
        $id = $this->inserirModelo('blocos', 'Modelo grande', json_encode($blocos, \JSON_THROW_ON_ERROR));
        $tamanho = \strlen((string) $this->linha($id)['conteudo']);

        $proposta = $this->executar(['acao' => 'editar', 'modelo' => 'cu' . $id, 'assunto' => 'Só o assunto']);

        self::assertSame('aguardando_confirmacao', $proposta->dados['estado']);
        self::assertStringContainsString(trim(\App\Conector\Ferramenta\ModelosDeEmailResumos::CORTADO_NO_RESUMO), $proposta->dados['proposta']['resumo']);
        $this->confirmar($proposta);
        $linha = $this->linha($id);
        self::assertSame('Só o assunto', $linha['assunto']);
        self::assertSame($tamanho, \strlen((string) $linha['conteudo']), 'o modelo é gravado inteiro');
    }

    #[TestDox('campo acima do limite do esquema é recusado com o nome e o limite, não cortado em silêncio')]
    public function testAcimaDoLimiteRecusa(): void
    {
        $proprio = $this->criarProprio();
        $casos = [
            [['assunto' => str_repeat('a', 256)], 'O campo assunto passa do limite de 255 caracteres. Encurte e peça de novo.'],
            [['nome' => str_repeat('a', 201)], 'O campo nome passa do limite de 200 caracteres. Encurte e peça de novo.'],
            [['blocos' => array_fill(0, 61, ['t' => 'texto', 'a' => 'x', 'b' => ''])], 'O campo blocos passa do limite de 60 blocos. Encurte e peça de novo.'],
            [['blocos' => [['t' => 'texto', 'a' => str_repeat('a', 20001), 'b' => '']]], 'O campo blocos (campo a de cada bloco) passa do limite de 20000 caracteres. Encurte e peça de novo.'],
            [['itens' => array_fill(0, 31, 'x')], 'O campo itens passa do limite de 30 itens. Encurte e peça de novo.'],
            [['html' => str_repeat('a', 150001)], 'O campo html passa do limite de 150000 bytes. Encurte e peça de novo.'],
        ];
        foreach ($casos as [$campos, $mensagem]) {
            $this->assertRecusa(['acao' => 'editar', 'modelo' => $proprio['chave']] + $campos, $mensagem);
        }
        self::assertSame(0, $this->contarPropostas());
    }

    #[TestDox('o texto do resumo nunca leva e-mail inteiro: endereço no modelo sai mascarado')]
    public function testResumoMascaraEmail(): void
    {
        $proprio = $this->criarProprio();
        $proposta = $this->executar(['acao' => 'editar', 'modelo' => $proprio['chave'], 'blocos' => [['t' => 'texto', 'a' => 'Escreva para joao@exemplo.com.br', 'b' => '']]]);

        self::assertStringNotContainsString('joao@exemplo.com.br', $proposta->dados['proposta']['resumo']);
    }

    #[TestDox('voltar ao padrão vira proposta, só apaga a versão editada depois do sim e a trilha sai com modelo_pad; o efetivo volta ao literal')]
    public function testVoltarAoPadrao(): void
    {
        $this->confirmar($this->executar(['acao' => 'editar', 'modelo' => 'convite', 'assunto' => 'Convite do Master']));
        $proposta = $this->executar(['acao' => 'voltar_ao_padrao', 'modelo' => 'convite']);

        self::assertSame('aguardando_confirmacao', $proposta->dados['estado']);
        $resumo = $proposta->dados['proposta']['resumo'];
        self::assertStringContainsString('Seu escritório 100% digital, de graça', $resumo);
        self::assertStringContainsString('Assunto editado que se perde: Convite do Master', $resumo);
        self::assertStringContainsString('Texto editado que se perde:', $resumo);
        self::assertSame(1, $this->contar('comercial_modelo', "fixo_chave = 'convite'"), 'propor não apaga');

        $this->confirmar($proposta);

        self::assertSame(0, $this->contar('comercial_modelo', "fixo_chave = 'convite'"));
        self::assertSame('Seu escritório 100% digital, de graça', $this->lerComercial(['acao' => 'previa', 'modelo' => 'convite'])->dados['assunto']);
        $this->exigirTrilha('modelo_pad', null);
    }

    #[TestDox('voltar ao padrão recusa o que já está no padrão e o modelo próprio, antes de propor')]
    public function testVoltarRecusas(): void
    {
        $proprio = $this->criarProprio();
        $this->assertRecusa(['acao' => 'voltar_ao_padrao', 'modelo' => 'convite'], ModelosDeEmailFerramenta::NADA_A_VOLTAR);
        $this->assertRecusa(['acao' => 'voltar_ao_padrao', 'modelo' => $proprio['chave']], ModelosDeEmailFerramenta::SO_PADRAO);
        self::assertSame(0, $this->contarPropostas());
    }

    #[TestDox('excluir vira proposta com assunto e texto, só apaga depois do sim, e a trilha sai com modelo_del e rota conector_mcp')]
    public function testExcluirPorProposta(): void
    {
        $proprio = $this->criarProprio();
        $proposta = $this->executar(['acao' => 'excluir', 'modelo' => (string) $proprio['id']]);

        self::assertSame('aguardando_confirmacao', $proposta->dados['estado']);
        self::assertStringContainsString('Novo informativo', $proposta->dados['proposta']['resumo']);
        self::assertSame(1, $this->contar('comercial_modelo', 'id = ' . $proprio['id']), 'propor não apaga');

        $this->confirmar($proposta);

        self::assertSame(0, $this->contar('comercial_modelo', 'id = ' . $proprio['id']));
        $this->exigirTrilha('modelo_del', (string) $proprio['id']);
    }

    #[TestDox('excluir recusa o modelo padrão, antes de propor')]
    public function testExcluirPadraoRecusado(): void
    {
        $this->assertRecusa(['acao' => 'excluir', 'modelo' => 'convite'], ModelosDeEmailFerramenta::SO_PROPRIO);
        self::assertSame(0, $this->contarPropostas());
    }

    #[DataProvider('acoesDeProposta')]
    #[TestDox('reconferir: o modelo mudou depois da proposta, a confirmação recusa e nada é gravado')]
    public function testModeloMudouEntreProporEConfirmar(string $acao): void
    {
        $proprio = $this->criarProprio();
        $this->confirmar($this->executar(['acao' => 'editar', 'modelo' => 'convite', 'assunto' => 'Convite do Master']));
        $argumentos = match ($acao) {
            'editar' => ['acao' => 'editar', 'modelo' => $proprio['chave'], 'assunto' => 'Pretendido'],
            'excluir' => ['acao' => 'excluir', 'modelo' => $proprio['chave']],
            default => ['acao' => 'voltar_ao_padrao', 'modelo' => 'convite'],
        };
        $proposta = $this->executar($argumentos);
        $this->conexao()->executeStatement("UPDATE comercial_modelo SET assunto = 'Mudou por fora' WHERE id = ? OR fixo_chave = 'convite'", [$proprio['id']]);
        $antes = $this->estadoDoBanco();

        $erro = $this->recusaDaConfirmacao($proposta);

        self::assertSame(ModelosDeEmailFerramenta::MUDOU, $erro->getMessage());
        self::assertSame($antes, $this->estadoDoBanco());
    }

    /** @return array<string, array{string}> */
    public static function acoesDeProposta(): array
    {
        return ['editar' => ['editar'], 'excluir' => ['excluir'], 'voltar' => ['voltar_ao_padrao']];
    }

    #[TestDox('reconferir: modelo excluído depois da proposta de edição recusa a confirmação')]
    public function testModeloExcluidoEntreProporEConfirmar(): void
    {
        $proprio = $this->criarProprio();
        $proposta = $this->executar(['acao' => 'editar', 'modelo' => $proprio['chave'], 'assunto' => 'Pretendido']);
        $this->conexao()->executeStatement('DELETE FROM comercial_modelo WHERE id = ?', [$proprio['id']]);

        self::assertSame(ModelosDeEmailFerramenta::MUDOU, $this->recusaDaConfirmacao($proposta)->getMessage());
    }

    #[TestDox('reconferir: a pessoa deixou de ser Master depois da proposta, a confirmação recusa e nada é gravado')]
    public function testReconferirSemMaster(): void
    {
        $proprio = $this->criarProprio();
        $proposta = $this->executar(['acao' => 'excluir', 'modelo' => $proprio['chave']]);
        $this->conexao()->executeStatement('UPDATE "user" SET roles = ? WHERE id = ?', ['["ROLE_USER"]', $this->pessoa->getId()]);
        $this->em()->clear();

        $erro = $this->recusaDaConfirmacao($proposta);

        self::assertSame(ModelosDeEmailFerramenta::MOTIVO_SO_DO_MASTER, $erro->motivo);
        self::assertSame(1, $this->contar('comercial_modelo', 'id = ' . $proprio['id']));
    }

    #[TestDox('executarConfirmada também confere o Master: quem não é Master é recusado mesmo sem passar pelo reconferir')]
    public function testConfirmadaSemMaster(): void
    {
        $comum = $this->pessoa(['ROLE_USER', 'ROLE_ADMIN']);
        $this->vinculo($comum, $this->escritorio);
        $proprio = $this->criarProprio();
        static::getContainer()->get('services_resetter')->reset();

        try {
            static::getContainer()->get(ModelosDeEmailFerramenta::class)->executarConfirmada('excluir', ['modelo' => $proprio['chave'], 'antes' => 'x'], $this->chamada($comum));
            self::fail('deveria recusar quem não é Master');
        } catch (ErroDaFerramenta $e) {
            self::assertSame(ModelosDeEmailFerramenta::MOTIVO_SO_DO_MASTER, $e->motivo);
        }
        self::assertSame(1, $this->contar('comercial_modelo', 'id = ' . $proprio['id']));
    }

    /** Um modelo próprio direto no banco, para os modos que a ferramenta não cria. */
    private function inserirModelo(string $modo, string $nome, string $conteudo): int
    {
        $agora = (new \DateTimeImmutable())->format('Y-m-d H:i:s');
        $this->conexao()->executeStatement(
            'INSERT INTO comercial_modelo (nome, icone, modo, assunto, selo, titulo, conteudo, criado_em, alterado_em) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [$nome, 'bi-file-earmark', $modo, 'Assunto original', 'SELO', 'Título', $conteudo, $agora, $agora],
        );

        return (int) $this->conexao()->lastInsertId();
    }

    /** @return array{id: int, chave: string} */
    private function criarProprio(): array
    {
        $modelo = $this->executar(['acao' => 'criar'])->dados['modelo'];

        return ['id' => $modelo['id'], 'chave' => $modelo['chave']];
    }

    /** @param array<string, mixed> $argumentos */
    private function executar(array $argumentos, ?User $pessoa = null): RespostaDaFerramenta
    {
        static::getContainer()->get('services_resetter')->reset();

        return static::getContainer()->get(ModelosDeEmailFerramenta::class)->executar($argumentos, $this->chamada($pessoa));
    }

    /** @param array<string, mixed> $argumentos */
    private function lerComercial(array $argumentos): RespostaDaFerramenta
    {
        static::getContainer()->get('services_resetter')->reset();

        return static::getContainer()->get(LerComercialFerramenta::class)->executar($argumentos, $this->chamada());
    }

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

    private function chamada(?User $pessoa = null): ChamadaDoConector
    {
        $pessoa ??= $this->pessoa;
        $contexto = $this->portao()->abrir((int) $pessoa->getId(), (int) $this->escritorio->getId());

        return new ChamadaDoConector($contexto, 41, (int) $this->consentimento->getId(), 'claude.ai', ['mcp:leitura', 'mcp:escrita'], new \DateTimeImmutable('+7 days'));
    }

    /** @return array<string, mixed> */
    private function linha(int $id): array
    {
        $linha = $this->conexao()->fetchAssociative('SELECT * FROM comercial_modelo WHERE id = ?', [$id]);
        self::assertIsArray($linha);

        return $linha;
    }

    private function contar(string $tabela, string $onde = 'TRUE'): int
    {
        return (int) $this->conexao()->fetchOne('SELECT COUNT(*) FROM ' . $tabela . ' WHERE ' . $onde);
    }

    private function estadoDoBanco(): string
    {
        return json_encode($this->conexao()->fetchAllAssociative('SELECT id, nome, assunto, conteudo, fixo_chave FROM comercial_modelo ORDER BY id'), \JSON_THROW_ON_ERROR);
    }

    private function contarPropostas(): int
    {
        return $this->contar('conector_ia_proposta');
    }

    private function ultimoIdDaAuditoria(): int
    {
        return (int) $this->conexao()->fetchOne('SELECT COALESCE(MAX(id), 0) FROM audit_log');
    }

    /** @return array<string, mixed> */
    private function trilhaDesde(int $ultimo): array
    {
        $linha = $this->conexao()->fetchAssociative("SELECT * FROM audit_log WHERE id > ? AND entity_class LIKE '%ModeloDeCampanha' ORDER BY id DESC", [$ultimo]);
        self::assertIsArray($linha);

        return $linha;
    }

    /** A última linha do modelo na trilha: a ação esperada, a rota do conector e nenhum escritório (ato de plataforma). */
    private function exigirTrilha(string $acao, ?string $entidade): void
    {
        $linha = $this->conexao()->fetchAssociative("SELECT action, route, tenant_id, entity_id FROM audit_log WHERE entity_class LIKE '%ModeloDeCampanha' AND action = ? ORDER BY id DESC LIMIT 1", [$acao]);
        self::assertIsArray($linha, 'a gravação precisa deixar a trilha ' . $acao);
        self::assertSame('conector_mcp', $linha['route']);
        self::assertNull($linha['tenant_id']);
        if ($entidade !== null) {
            self::assertSame($entidade, (string) $linha['entity_id']);
        }
    }

    private function conexao(): Connection
    {
        return static::getContainer()->get(Connection::class);
    }
}
