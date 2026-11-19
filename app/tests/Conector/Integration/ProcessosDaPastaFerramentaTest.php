<?php

declare(strict_types=1);

namespace App\Tests\Conector\Integration;

use App\Cliente\Enum\ChaveDoEnvioAutomatico;
use App\Cliente\Entity\EnvioAutomaticoDoCliente;
use App\Conector\DTO\ChamadaDoConector;
use App\Conector\DTO\RespostaDaFerramenta;
use App\Conector\Entity\ClienteDoConector;
use App\Conector\Entity\ConsentimentoDoConector;
use App\Conector\Entity\EscritorioDoConector;
use App\Conector\Enum\TipoDeClienteDoConector;
use App\Conector\Exception\ErroDaFerramenta;
use App\Conector\Ferramenta\ConfirmarAcaoFerramenta;
use App\Conector\Ferramenta\ProcessosDaPastaFerramenta;
use App\Conector\Ferramenta\ProcessosDaPastaRecusa;
use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Pasta\Entity\AbaDaPasta;
use App\Pasta\Entity\AbaOcultaDaPasta;
use App\Pasta\Entity\Pasta;
use App\Pasta\Entity\TipoDeApenso;
use App\Processo\Entity\NotaTecnica;
use App\Processo\Entity\Processo;
use App\Tests\Conector\Support\CenarioDoConectorTrait;
use App\Tests\Factory\Cliente\ClientePFFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * `processos_da_pasta` com o banco e o portão de verdade (spec mcp-remoto-oauth 6.6, PR 13): as ações diretas gravam
 * pelos UseCases da tela; gravar por cima, editar e excluir nota técnica e desvincular só gravam depois do
 * `confirmar_acao`; sem editar, sem o escopo de escrita, em segredo, com a aba oculta ou com o envio automático ligado:
 * recusa.
 */
#[CoversClass(ProcessosDaPastaFerramenta::class)]
final class ProcessosDaPastaFerramentaTest extends KernelTestCase
{
    use CenarioDoConectorTrait;

    private const EDITOR = ['modules.pastas.view', 'resources.pasta.view', 'resources.pasta.edit', 'resources.processo.view', 'resources.processo.edit'];
    private const SO_PASTA = ['modules.pastas.view', 'resources.pasta.view', 'resources.pasta.edit'];
    private const LEITOR = ['modules.pastas.view', 'resources.pasta.view'];

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

    #[TestDox('vincular cadastrado: liga o processo do escritório à pasta, direto; repetir não duplica')]
    public function testVincularCadastrado(): void
    {
        $pasta = $this->pasta();
        $processo = $this->processo();

        $resposta = $this->executar(['acao' => 'vincular', 'operacao' => 'cadastrado', 'pasta_id' => $pasta->getId(), 'processo_id' => $processo->getId()]);

        self::assertSame('gravado', $resposta->dados['estado']);
        self::assertFalse($resposta->dados['ja_estava_vinculado']);
        self::assertSame(['pasta' => [$pasta->getId()], 'processo' => [$processo->getId()]], $resposta->ids);
        self::assertSame([$processo->getId()], $this->idsDosProcessos($pasta));

        $de_novo = $this->executar(['acao' => 'vincular', 'operacao' => 'cadastrado', 'pasta_id' => $pasta->getId(), 'processo_id' => $processo->getId()]);
        self::assertTrue($de_novo->dados['ja_estava_vinculado']);
        self::assertSame([$processo->getId()], $this->idsDosProcessos($pasta));
    }

    #[TestDox('vincular novo: cadastra o processo no escritório e o liga à pasta; número já cadastrado reaproveita o existente')]
    public function testVincularNovo(): void
    {
        $pasta = $this->pasta();
        $numero = $this->numeroCnj();
        $base = ['acao' => 'vincular', 'operacao' => 'novo', 'pasta_id' => $pasta->getId(), 'numero_processo' => $numero, 'orgao_julgador' => '1ª Vara Cível', 'classe_processual' => 'Procedimento Comum', 'assunto_processual' => 'Indenização'];

        $resposta = $this->executar($base + ['instancia' => 'G2', 'data_distribuicao' => '2026-03-10']);

        $criado = $this->recarregar(Processo::class, (int) $resposta->dados['processo_id']);
        self::assertSame($numero, $criado->getNumeroProcesso());
        self::assertSame($this->escritorio->getId(), $criado->getTenant()?->getId());
        self::assertSame('G2', $criado->getInstancia());
        self::assertSame('2026-03-10', $criado->getDataDistribuicao()?->format('Y-m-d'));
        self::assertSame([$criado->getId()], $this->idsDosProcessos($pasta));

        $outraPasta = $this->pasta();
        $reaproveitado = $this->executar(['pasta_id' => $outraPasta->getId(), 'classe_processual' => 'Outra classe'] + $base);
        self::assertSame($criado->getId(), $reaproveitado->dados['processo_id']);
        self::assertSame(1, (int) $this->em()->getConnection()->fetchOne('SELECT COUNT(*) FROM processo WHERE numero_processo = ?', [$numero]));
    }

    #[TestDox('vincular novo sem os campos obrigatórios: recusa pela regra do UseCase, sem processo solto')]
    public function testVincularNovoIncompleto(): void
    {
        $pasta = $this->pasta();
        $numero = $this->numeroCnj();

        $this->assertMotivo(['acao' => 'vincular', 'operacao' => 'novo', 'pasta_id' => $pasta->getId(), 'numero_processo' => $numero, 'orgao_julgador' => ' ', 'classe_processual' => 'Classe', 'assunto_processual' => 'Assunto'], ErroDaFerramenta::MOTIVO_ENTRADA_INVALIDA);
        self::assertSame(0, (int) $this->em()->getConnection()->fetchOne('SELECT COUNT(*) FROM processo WHERE numero_processo = ?', [$numero]));
    }

    #[TestDox('vincular apensado: entra como apensado do tipo escolhido; o principal da pasta não pode ser apensado')]
    public function testVincularApensado(): void
    {
        $pasta = $this->pasta();
        $principal = $this->processo();
        $this->vincularNaPasta($pasta, $principal);
        $apenso = $this->processo();

        $this->executar(['acao' => 'vincular', 'operacao' => 'apensado', 'pasta_id' => $pasta->getId(), 'processo_id' => $apenso->getId(), 'tipo_apenso' => 'embargos']);

        $viva = $this->recarregar(Pasta::class, (int) $pasta->getId());
        self::assertSame(TipoDeApenso::Embargos, $viva->getVinculoPorProcesso($this->recarregar(Processo::class, (int) $apenso->getId()))?->getTipoApenso());
        $this->assertMotivo(['acao' => 'vincular', 'operacao' => 'apensado', 'pasta_id' => $pasta->getId(), 'processo_id' => $principal->getId(), 'tipo_apenso' => 'outro'], ErroDaFerramenta::MOTIVO_ENTRADA_INVALIDA);
    }

    #[TestDox('principal: troca o processo principal da pasta, direto; processo que não é da pasta: não encontrado')]
    public function testPrincipal(): void
    {
        $pasta = $this->pasta();
        $primeiro = $this->processo();
        $segundo = $this->processo();
        $this->vincularNaPasta($pasta, $primeiro);
        $this->vincularNaPasta($pasta, $segundo);

        $this->executar(['acao' => 'principal', 'pasta_id' => $pasta->getId(), 'processo_id' => $segundo->getId()]);

        $viva = $this->recarregar(Pasta::class, (int) $pasta->getId());
        self::assertSame($segundo->getId(), $viva->getProcessoPrincipal()?->getId());
        $this->assertMotivo(['acao' => 'principal', 'pasta_id' => $pasta->getId(), 'processo_id' => $this->processo()->getId()], ErroDaFerramenta::MOTIVO_NAO_ENCONTRADO);
    }

    #[TestDox('desvincular: é proposta, o processo continua existindo e só sai da pasta depois do confirmar')]
    public function testDesvincular(): void
    {
        $pasta = $this->pasta();
        $processo = $this->processo();
        $this->vincularNaPasta($pasta, $processo);

        $proposta = $this->executar(['acao' => 'vincular', 'operacao' => 'desvincular', 'pasta_id' => $pasta->getId(), 'processo_id' => $processo->getId()]);

        self::assertSame('aguardando_confirmacao', $proposta->dados['estado']);
        self::assertSame([$processo->getId()], $this->idsDosProcessos($pasta), 'a proposta não grava');

        $confirmada = $this->confirmar($proposta);
        self::assertSame([['processo_id' => $processo->getId()]], $confirmada->dados['resultado']['desvinculados']);
        self::assertSame([], $this->idsDosProcessos($pasta));
        self::assertNotNull($this->recarregar(Processo::class, (int) $processo->getId()));
    }

    #[TestDox('desvincular: o processo saiu da pasta depois da proposta, a confirmação é recusada')]
    public function testDesvincularQueJaSaiu(): void
    {
        $pasta = $this->pasta();
        $processo = $this->processo();
        $this->vincularNaPasta($pasta, $processo);
        $proposta = $this->executar(['acao' => 'vincular', 'operacao' => 'desvincular', 'pasta_id' => $pasta->getId(), 'processo_id' => $processo->getId()]);

        $this->em()->getConnection()->executeStatement('DELETE FROM pasta_processo WHERE pasta_id = ?', [$pasta->getId()]);

        $this->assertConfirmacaoRecusada($proposta, ErroDaFerramenta::MOTIVO_NAO_ENCONTRADO);
    }

    #[TestDox('dados_do_cartao: campo vazio e marcas sim ou não gravam direto, sem tocar no segredo; a resposta não traz o texto')]
    public function testDadosDoCartaoDireto(): void
    {
        $pasta = $this->pasta();
        $processo = $this->processo();
        $this->vincularNaPasta($pasta, $processo);

        $resposta = $this->executar(['acao' => 'dados_do_cartao', 'pasta_id' => $pasta->getId(), 'processo_id' => $processo->getId(), 'jurisdicao' => 'Brasília', 'digital' => true, 'valor_causa' => '12.860,00']);

        self::assertSame('gravado', $resposta->dados['estado']);
        self::assertSame(['jurisdicao', 'digital', 'valor_causa'], $resposta->dados['campos']);
        $manual = $this->recarregar(Processo::class, (int) $processo->getId())->getDadosManuais();
        self::assertSame('BRASÍLIA', mb_strtoupper((string) $manual->jurisdicao));
        self::assertSame('12860.00', $manual->valorCausa);
        self::assertTrue($manual->digital);
        self::assertNull($manual->segredo, 'o conector nunca liga nem desliga o segredo');

        $outro = $this->executar(['acao' => 'dados_do_cartao', 'pasta_id' => $pasta->getId(), 'processo_id' => $processo->getId(), 'orgao' => 'TJDFT', 'gratuita' => true]);
        self::assertSame('gravado', $outro->dados['estado']);
        $manual = $this->recarregar(Processo::class, (int) $processo->getId())->getDadosManuais();
        self::assertSame('12860.00', $manual->valorCausa, 'o que a chamada não mandou fica como estava');
        self::assertSame('TJDFT', $manual->orgao);
        self::assertTrue($manual->digital);
    }

    #[TestDox('dados_do_cartao: o "Não" manual do segredo continua como estava depois de gravar outro campo')]
    public function testSegredoManualNaoMuda(): void
    {
        $pasta = $this->pasta();
        $processo = $this->processo();
        $this->vincularNaPasta($pasta, $processo);
        $processo->definirDadosManuais(new \App\Processo\DTO\DadosManuaisDoProcesso(segredo: false), $this->pessoa);
        $this->em()->flush();

        $this->executar(['acao' => 'dados_do_cartao', 'pasta_id' => $pasta->getId(), 'processo_id' => $processo->getId(), 'jurisdicao' => 'Goiânia']);

        self::assertFalse($this->recarregar(Processo::class, (int) $processo->getId())->getDadosManuais()->segredo);
    }

    #[TestDox('dados_do_cartao: trocar campo já preenchido é proposta com o valor de antes; só grava depois do confirmar')]
    public function testDadosDoCartaoPorProposta(): void
    {
        $pasta = $this->pasta();
        $processo = $this->processo();
        $this->vincularNaPasta($pasta, $processo);
        $base = ['acao' => 'dados_do_cartao', 'pasta_id' => $pasta->getId(), 'processo_id' => $processo->getId()];
        $this->executar($base + ['jurisdicao' => 'Brasília']);

        $proposta = $this->executar($base + ['jurisdicao' => 'Goiânia', 'orgao' => 'TJGO']);

        self::assertSame('aguardando_confirmacao', $proposta->dados['estado']);
        self::assertStringContainsString('jurisdição: de "Brasília" para "Goiânia"', $proposta->dados['proposta']['resumo']);
        self::assertStringContainsString('hoje sem valor manual', $proposta->dados['proposta']['resumo'], 'o resumo separa o que é novo do que é troca');
        self::assertNotSame('GOIÂNIA', mb_strtoupper((string) $this->recarregar(Processo::class, (int) $processo->getId())->getDadosManuais()->jurisdicao), 'a proposta não grava');

        $this->confirmar($proposta);
        $manual = $this->recarregar(Processo::class, (int) $processo->getId())->getDadosManuais();
        self::assertSame('GOIÂNIA', mb_strtoupper((string) $manual->jurisdicao));
        self::assertSame('TJGO', $manual->orgao);
    }

    #[TestDox('dados_do_cartao: o valor manual mudou depois da proposta, a confirmação é recusada e nada grava')]
    public function testDadosDoCartaoQueMudou(): void
    {
        $pasta = $this->pasta();
        $processo = $this->processo();
        $this->vincularNaPasta($pasta, $processo);
        $base = ['acao' => 'dados_do_cartao', 'pasta_id' => $pasta->getId(), 'processo_id' => $processo->getId()];
        $this->executar($base + ['jurisdicao' => 'Brasília']);
        $proposta = $this->executar($base + ['jurisdicao' => 'Goiânia']);

        $this->em()->getConnection()->executeStatement("UPDATE processo SET jurisdicao_manual = 'Outra' WHERE id = ?", [$processo->getId()]);

        $this->assertConfirmacaoMudou($proposta);
        self::assertSame('Outra', $this->em()->getConnection()->fetchOne('SELECT jurisdicao_manual FROM processo WHERE id = ?', [$processo->getId()]));
    }

    #[TestDox('dados_do_cartao: campo de segredo, texto longo, data falsa e pedido sem campo nenhum são entrada inválida')]
    public function testDadosDoCartaoInvalido(): void
    {
        $pasta = $this->pasta();
        $processo = $this->processo();
        $this->vincularNaPasta($pasta, $processo);
        $base = ['acao' => 'dados_do_cartao', 'pasta_id' => $pasta->getId(), 'processo_id' => $processo->getId()];

        $this->assertMotivo($base + ['segredo' => true], ErroDaFerramenta::MOTIVO_ENTRADA_INVALIDA);
        $this->assertMotivo($base + ['segredo_de_justica' => false], ErroDaFerramenta::MOTIVO_ENTRADA_INVALIDA);
        $this->assertMotivo($base + ['classe' => str_repeat('a', 256)], ErroDaFerramenta::MOTIVO_ENTRADA_INVALIDA);
        $this->assertMotivo($base + ['distribuicao' => '2026-02-31'], ErroDaFerramenta::MOTIVO_ENTRADA_INVALIDA);
        $this->assertMotivo($base + ['digital' => 'sim'], ErroDaFerramenta::MOTIVO_ENTRADA_INVALIDA);
        $this->assertMotivo($base, ErroDaFerramenta::MOTIVO_ENTRADA_INVALIDA);
        $this->assertMotivo($base + ['valor_causa' => 'abc'], ErroDaFerramenta::MOTIVO_ENTRADA_INVALIDA);
    }

    #[TestDox('pasta_id como texto de dígitos é pedido inválido (o esquema diz integer)')]
    public function testPastaIdComoTexto(): void
    {
        $pasta = $this->pasta();

        $this->assertMotivo(['acao' => 'via_administrativa', 'pasta_id' => (string) $pasta->getId(), 'administrativa' => true], ErroDaFerramenta::MOTIVO_ENTRADA_INVALIDA);
    }

    #[TestDox('dados_do_cartao: ligar uma marca é direto; desligar a que está ligada é proposta e só grava depois do confirmar')]
    public function testDesligarMarca(): void
    {
        $pasta = $this->pasta();
        $processo = $this->processo();
        $this->vincularNaPasta($pasta, $processo);
        $base = ['acao' => 'dados_do_cartao', 'pasta_id' => $pasta->getId(), 'processo_id' => $processo->getId()];
        self::assertSame('gravado', $this->executar($base + ['gratuita' => true])->dados['estado']);

        $proposta = $this->executar($base + ['gratuita' => false]);

        self::assertSame('aguardando_confirmacao', $proposta->dados['estado']);
        self::assertStringContainsString('gratuita: de sim para não', $proposta->dados['proposta']['resumo']);
        self::assertTrue($this->recarregar(Processo::class, (int) $processo->getId())->getDadosManuais()->gratuita, 'a proposta não grava');

        $this->confirmar($proposta);
        self::assertFalse($this->recarregar(Processo::class, (int) $processo->getId())->getDadosManuais()->gratuita);
    }

    #[TestDox('dados_do_cartao: a marca mudou depois da proposta de desligar, a confirmação é recusada')]
    public function testDesligarMarcaQueMudou(): void
    {
        $pasta = $this->pasta();
        $processo = $this->processo();
        $this->vincularNaPasta($pasta, $processo);
        $base = ['acao' => 'dados_do_cartao', 'pasta_id' => $pasta->getId(), 'processo_id' => $processo->getId()];
        $this->executar($base + ['gratuita' => true]);
        $proposta = $this->executar($base + ['gratuita' => false]);

        $this->em()->getConnection()->executeStatement('UPDATE processo SET gratuita_manual = NULL WHERE id = ?', [$processo->getId()]);

        $this->assertConfirmacaoMudou($proposta);
    }

    #[TestDox('acao_da_pasta: ação vazia grava direto; trocar a ação já preenchida é proposta')]
    public function testAcaoDaPasta(): void
    {
        $pasta = $this->pasta();
        $base = ['acao' => 'acao_da_pasta', 'pasta_id' => $pasta->getId()];

        $direta = $this->executar($base + ['nome_da_acao' => 'Ação de cobrança']);
        self::assertSame('gravado', $direta->dados['estado']);
        self::assertTrue($direta->dados['mudou']);
        self::assertSame('AÇÃO DE COBRANÇA', $this->recarregar(Pasta::class, (int) $pasta->getId())->getNomeAcao());

        $igual = $this->executar($base + ['nome_da_acao' => 'ação de cobrança']);
        self::assertSame('gravado', $igual->dados['estado'], 'a mesma ação não é troca');
        self::assertFalse($igual->dados['mudou']);

        $proposta = $this->executar($base + ['nome_da_acao' => 'Ação de despejo']);
        self::assertSame('aguardando_confirmacao', $proposta->dados['estado']);
        self::assertStringContainsString('de "AÇÃO DE COBRANÇA" para "AÇÃO DE DESPEJO"', $proposta->dados['proposta']['resumo']);
        self::assertSame('AÇÃO DE COBRANÇA', $this->recarregar(Pasta::class, (int) $pasta->getId())->getNomeAcao());

        $this->confirmar($proposta);
        self::assertSame('AÇÃO DE DESPEJO', $this->recarregar(Pasta::class, (int) $pasta->getId())->getNomeAcao());
    }

    #[TestDox('acao_da_pasta: a ação mudou depois da proposta, a confirmação é recusada')]
    public function testAcaoDaPastaQueMudou(): void
    {
        $pasta = $this->pasta();
        $base = ['acao' => 'acao_da_pasta', 'pasta_id' => $pasta->getId()];
        $this->executar($base + ['nome_da_acao' => 'Ação de cobrança']);
        $proposta = $this->executar($base + ['nome_da_acao' => 'Ação de despejo']);

        $this->em()->getConnection()->executeStatement("UPDATE pasta SET nome_acao = 'OUTRA' WHERE id = ?", [$pasta->getId()]);

        $this->assertConfirmacaoMudou($proposta);
        self::assertSame('OUTRA', $this->em()->getConnection()->fetchOne('SELECT nome_acao FROM pasta WHERE id = ?', [$pasta->getId()]));
    }

    #[TestDox('via_administrativa: marca e desmarca direto e diz que a trilha do andamento mudou')]
    public function testViaAdministrativa(): void
    {
        $pasta = $this->pasta();

        $marcada = $this->executar(['acao' => 'via_administrativa', 'pasta_id' => $pasta->getId(), 'administrativa' => true]);
        self::assertTrue($marcada->dados['mudou']);
        self::assertSame('administrativa', $marcada->dados['trilha_do_andamento']);
        self::assertTrue($this->recarregar(Pasta::class, (int) $pasta->getId())->isAdministrativa());

        self::assertFalse($this->executar(['acao' => 'via_administrativa', 'pasta_id' => $pasta->getId(), 'administrativa' => true])->dados['mudou']);
        $this->executar(['acao' => 'via_administrativa', 'pasta_id' => $pasta->getId(), 'administrativa' => false]);
        self::assertFalse($this->recarregar(Pasta::class, (int) $pasta->getId())->isAdministrativa());
    }

    #[TestDox('nota_tecnica: criar é direto e fica no processo, com a pessoa do token como autora')]
    public function testCriarNota(): void
    {
        $pasta = $this->pasta();
        $processo = $this->processo();
        $this->vincularNaPasta($pasta, $processo);

        $resposta = $this->executar(['acao' => 'nota_tecnica', 'operacao' => 'criar', 'pasta_id' => $pasta->getId(), 'processo_id' => $processo->getId(), 'conteudo' => 'Prazo corre em dobro.']);

        $nota = $this->recarregar(NotaTecnica::class, (int) $resposta->dados['nota_id']);
        self::assertSame('Prazo corre em dobro.', $nota->getConteudo());
        self::assertSame($processo->getId(), $nota->getProcesso()->getId());
        self::assertSame($this->pessoa->getId(), $nota->getAutor()?->getId());
        self::assertSame($this->escritorio->getId(), $nota->getTenant()?->getId());
    }

    #[TestDox('nota_tecnica: editar é proposta com o texto de antes e só grava depois do confirmar')]
    public function testEditarNota(): void
    {
        [$pasta, $processo, $nota] = $this->comNota('Texto original');
        $base = ['acao' => 'nota_tecnica', 'operacao' => 'editar', 'pasta_id' => $pasta->getId(), 'processo_id' => $processo->getId(), 'nota_id' => $nota->getId(), 'conteudo' => 'Texto novo'];

        $proposta = $this->executar($base);

        self::assertSame('aguardando_confirmacao', $proposta->dados['estado']);
        self::assertStringContainsString('de "Texto original" para "Texto novo"', $proposta->dados['proposta']['resumo']);
        self::assertSame('Texto original', $this->recarregar(NotaTecnica::class, (int) $nota->getId())->getConteudo());

        $this->confirmar($proposta);
        self::assertSame('Texto novo', $this->recarregar(NotaTecnica::class, (int) $nota->getId())->getConteudo());
    }

    #[TestDox('nota_tecnica: o texto mudou depois da proposta, a confirmação é recusada e a nota fica como está')]
    public function testEditarNotaQueMudou(): void
    {
        [$pasta, $processo, $nota] = $this->comNota('Texto original');
        $proposta = $this->executar(['acao' => 'nota_tecnica', 'operacao' => 'editar', 'pasta_id' => $pasta->getId(), 'processo_id' => $processo->getId(), 'nota_id' => $nota->getId(), 'conteudo' => 'Texto novo']);

        $this->em()->getConnection()->executeStatement("UPDATE nota_tecnica SET conteudo = 'Mexida por outro' WHERE id = ?", [$nota->getId()]);

        $this->assertConfirmacaoMudou($proposta);
        self::assertSame('Mexida por outro', $this->recarregar(NotaTecnica::class, (int) $nota->getId())->getConteudo());
    }

    #[TestDox('nota_tecnica: excluir é proposta, diz que não desfaz e só apaga depois do confirmar')]
    public function testExcluirNota(): void
    {
        [$pasta, $processo, $nota] = $this->comNota('Texto original');

        $proposta = $this->executar(['acao' => 'nota_tecnica', 'operacao' => 'excluir', 'pasta_id' => $pasta->getId(), 'processo_id' => $processo->getId(), 'nota_id' => $nota->getId()]);

        self::assertStringContainsString('Não há como desfazer', $proposta->dados['proposta']['resumo']);
        self::assertNotNull($this->recarregar(NotaTecnica::class, (int) $nota->getId()));

        $confirmada = $this->confirmar($proposta);
        self::assertSame([['nota_id' => $nota->getId()]], $confirmada->dados['resultado']['excluidos']);
        self::assertNull($this->recarregar(NotaTecnica::class, (int) $nota->getId()));
    }

    #[TestDox('nota_tecnica: nota de outra pessoa, que a regra da tela não deixa editar ou excluir, é recusada ao propor')]
    public function testNotaDeOutraPessoa(): void
    {
        $pasta = $this->pasta();
        $processo = $this->processo();
        $this->vincularNaPasta($pasta, $processo);
        $colega = $this->pessoa();
        $this->vinculo($colega, $this->escritorio, permissoes: self::EDITOR);
        $nota = new NotaTecnica($this->escritorio, $processo, $colega, 'Da colega');
        $this->em()->persist($nota);
        $this->em()->flush();

        foreach (['editar', 'excluir'] as $operacao) {
            $this->assertMotivo(['acao' => 'nota_tecnica', 'operacao' => $operacao, 'pasta_id' => $pasta->getId(), 'processo_id' => $processo->getId(), 'nota_id' => $nota->getId()] + ($operacao === 'editar' ? ['conteudo' => 'x'] : []), ErroDaFerramenta::MOTIVO_ENTRADA_INVALIDA);
        }
        self::assertSame('Da colega', $this->recarregar(NotaTecnica::class, (int) $nota->getId())->getConteudo());
    }

    #[TestDox('nota_tecnica: nota de outro processo, de outro escritório ou inexistente: não encontrado')]
    public function testNotaAlheia(): void
    {
        [$pasta, $processo] = $this->comNota('Minha');
        $outroEscritorio = $this->escritorio();
        $processoAlheio = $this->processo($outroEscritorio);
        $dePessoaAlheia = new NotaTecnica($outroEscritorio, $processoAlheio, $this->pessoa(), 'Alheia');
        $this->em()->persist($dePessoaAlheia);
        $outroProcesso = $this->processo();
        $this->vincularNaPasta($pasta, $outroProcesso);
        $doOutroProcesso = new NotaTecnica($this->escritorio, $outroProcesso, $this->pessoa, 'De outro processo');
        $this->em()->persist($doOutroProcesso);
        $this->em()->flush();

        foreach ([(int) $dePessoaAlheia->getId(), (int) $doOutroProcesso->getId(), 999_999_999] as $notaId) {
            $this->assertMotivo(['acao' => 'nota_tecnica', 'operacao' => 'excluir', 'pasta_id' => $pasta->getId(), 'processo_id' => $processo->getId(), 'nota_id' => $notaId], ErroDaFerramenta::MOTIVO_NAO_ENCONTRADO);
        }
        $this->assertMotivo(['acao' => 'nota_tecnica', 'operacao' => 'criar', 'pasta_id' => $pasta->getId(), 'processo_id' => $processoAlheio->getId(), 'conteudo' => 'x'], ErroDaFerramenta::MOTIVO_NAO_ENCONTRADO);
    }

    #[TestDox('envio automático: vincular (novo, cadastrado e apensado) recusa, com a frase fixa, em pasta cujo cliente principal o tem ligado')]
    public function testEnvioAutomatico(): void
    {
        $pasta = $this->pastaComClientePrincipal(ligarEnvio: true);
        $existente = $this->processo();
        $numero = $this->numeroCnj();

        $pedidos = [
            ['acao' => 'vincular', 'operacao' => 'cadastrado', 'processo_id' => $existente->getId()],
            ['acao' => 'vincular', 'operacao' => 'apensado', 'processo_id' => $existente->getId(), 'tipo_apenso' => 'outro'],
            ['acao' => 'vincular', 'operacao' => 'novo', 'numero_processo' => $numero, 'orgao_julgador' => 'Vara', 'classe_processual' => 'Classe', 'assunto_processual' => 'Assunto'],
        ];
        foreach ($pedidos as $pedido) {
            try {
                $this->executar(['pasta_id' => $pasta->getId()] + $pedido);
                self::fail('deveria recusar');
            } catch (ErroDaFerramenta $e) {
                self::assertSame(ProcessosDaPastaRecusa::MOTIVO_ENVIO_AUTOMATICO, $e->motivo);
                self::assertSame(ProcessosDaPastaRecusa::ENVIO_AUTOMATICO, $e->getMessage());
            }
        }
        self::assertSame([], $this->idsDosProcessos($pasta));
        self::assertSame(0, (int) $this->em()->getConnection()->fetchOne('SELECT COUNT(*) FROM processo WHERE numero_processo = ?', [$numero]), 'a recusa vem antes de criar o processo');
    }

    #[TestDox('envio automático: cliente com a linha toda desligada (o padrão) não impede vincular')]
    public function testEnvioAutomaticoDesligado(): void
    {
        $pasta = $this->pastaComClientePrincipal(ligarEnvio: false);
        $processo = $this->processo();

        $this->executar(['acao' => 'vincular', 'operacao' => 'cadastrado', 'pasta_id' => $pasta->getId(), 'processo_id' => $processo->getId()]);

        self::assertSame([$processo->getId()], $this->idsDosProcessos($pasta));
    }

    #[TestDox('envio automático: não atrapalha as outras ações da aba (principal, via, nota)')]
    public function testEnvioAutomaticoSoBarraVincular(): void
    {
        $pasta = $this->pastaComClientePrincipal(ligarEnvio: true);

        $this->executar(['acao' => 'via_administrativa', 'pasta_id' => $pasta->getId(), 'administrativa' => true]);

        self::assertTrue($this->recarregar(Pasta::class, (int) $pasta->getId())->isAdministrativa());
    }

    #[TestDox('segredo: pasta com processo em segredo recusa tudo; processo em segredo (do tribunal ou do cartão) não vincula nem é editado')]
    public function testSegredo(): void
    {
        $pastaEmSegredo = $this->pasta();
        $emSegredo = $this->processo();
        $emSegredo->setNivelSigilo(1);
        $this->vincularNaPasta($pastaEmSegredo, $emSegredo);
        $this->assertMotivo(['acao' => 'via_administrativa', 'pasta_id' => $pastaEmSegredo->getId(), 'administrativa' => true], ErroDaFerramenta::MOTIVO_EM_SEGREDO);
        self::assertFalse($this->recarregar(Pasta::class, (int) $pastaEmSegredo->getId())->isAdministrativa());

        $pasta = $this->pasta();
        $doTribunal = $this->processo();
        $doTribunal->setNivelSigilo(2);
        $doCartao = $this->processo();
        $doCartao->definirDadosManuais(new \App\Processo\DTO\DadosManuaisDoProcesso(segredo: true), $this->pessoa);
        $this->em()->flush();
        foreach ([$doTribunal, $doCartao] as $processo) {
            $this->assertMotivo(['acao' => 'vincular', 'operacao' => 'cadastrado', 'pasta_id' => $pasta->getId(), 'processo_id' => $processo->getId()], ErroDaFerramenta::MOTIVO_EM_SEGREDO);
            $this->assertMotivo(['acao' => 'vincular', 'operacao' => 'apensado', 'pasta_id' => $pasta->getId(), 'processo_id' => $processo->getId(), 'tipo_apenso' => 'outro'], ErroDaFerramenta::MOTIVO_EM_SEGREDO);
        }
        self::assertSame([], $this->idsDosProcessos($pasta));
    }

    #[TestDox('vincular novo com o número de um processo do escritório em segredo: recusa o segredo e nada é ligado')]
    public function testNovoComNumeroEmSegredo(): void
    {
        $pasta = $this->pasta();
        $emSegredo = $this->processo();
        $emSegredo->setNivelSigilo(1);
        $this->em()->flush();

        $this->assertMotivo(['acao' => 'vincular', 'operacao' => 'novo', 'pasta_id' => $pasta->getId(), 'numero_processo' => $emSegredo->getNumeroProcesso(), 'orgao_julgador' => 'Vara', 'classe_processual' => 'Classe', 'assunto_processual' => 'Assunto'], ErroDaFerramenta::MOTIVO_EM_SEGREDO);
        self::assertSame([], $this->idsDosProcessos($pasta));
    }

    /** @return iterable<string, array{array<string, mixed>}> */
    public static function gravacoes(): iterable
    {
        yield 'vincular cadastrado' => [['acao' => 'vincular', 'operacao' => 'cadastrado', 'processo_id' => 1]];
        yield 'vincular novo' => [['acao' => 'vincular', 'operacao' => 'novo', 'numero_processo' => '07011345720258070001', 'orgao_julgador' => 'a', 'classe_processual' => 'b', 'assunto_processual' => 'c']];
        yield 'principal' => [['acao' => 'principal', 'processo_id' => 1]];
        yield 'dados_do_cartao' => [['acao' => 'dados_do_cartao', 'processo_id' => 1, 'digital' => true]];
        yield 'acao_da_pasta' => [['acao' => 'acao_da_pasta', 'nome_da_acao' => 'Ação']];
        yield 'via_administrativa' => [['acao' => 'via_administrativa', 'administrativa' => true]];
        yield 'nota criar' => [['acao' => 'nota_tecnica', 'operacao' => 'criar', 'processo_id' => 1, 'conteudo' => 'x']];
        yield 'desvincular' => [['acao' => 'vincular', 'operacao' => 'desvincular', 'processo_id' => 1]];
    }

    /** @param array<string, mixed> $argumentos */
    #[DataProvider('gravacoes')]
    #[TestDox('pasta de outro escritório, inexistente ou na lixeira: não encontrado e nada gravado: $_dataName')]
    public function testPastaQueNaoServe(array $argumentos): void
    {
        $alheia = $this->pasta($this->escritorio());
        $naLixeira = $this->pasta();
        $naLixeira->marcarExcluida($this->pessoa, new \DateTimeImmutable());
        $this->em()->flush();

        foreach ([(int) $alheia->getId(), (int) $naLixeira->getId(), 999_999_999] as $id) {
            $this->assertMotivo(['pasta_id' => $id] + $argumentos, ErroDaFerramenta::MOTIVO_NAO_ENCONTRADO);
        }
        self::assertFalse((bool) $this->em()->getConnection()->fetchOne('SELECT administrativa FROM pasta WHERE id = ?', [$alheia->getId()]));
        self::assertNull($this->em()->getConnection()->fetchOne('SELECT nome_acao FROM pasta WHERE id = ?', [$alheia->getId()]) ?: null);
    }

    #[TestDox('processo de outro escritório ou inexistente: não encontrado, em todas as ações por processo')]
    public function testProcessoAlheio(): void
    {
        $pasta = $this->pasta();
        $alheio = $this->processo($this->escritorio());

        foreach ([(int) $alheio->getId(), 999_999_999] as $id) {
            $this->assertMotivo(['acao' => 'vincular', 'operacao' => 'cadastrado', 'pasta_id' => $pasta->getId(), 'processo_id' => $id], ErroDaFerramenta::MOTIVO_NAO_ENCONTRADO);
            $this->assertMotivo(['acao' => 'vincular', 'operacao' => 'apensado', 'pasta_id' => $pasta->getId(), 'processo_id' => $id, 'tipo_apenso' => 'outro'], ErroDaFerramenta::MOTIVO_NAO_ENCONTRADO);
            $this->assertMotivo(['acao' => 'vincular', 'operacao' => 'desvincular', 'pasta_id' => $pasta->getId(), 'processo_id' => $id], ErroDaFerramenta::MOTIVO_NAO_ENCONTRADO);
            $this->assertMotivo(['acao' => 'dados_do_cartao', 'pasta_id' => $pasta->getId(), 'processo_id' => $id, 'digital' => true], ErroDaFerramenta::MOTIVO_NAO_ENCONTRADO);
            $this->assertMotivo(['acao' => 'nota_tecnica', 'operacao' => 'criar', 'pasta_id' => $pasta->getId(), 'processo_id' => $id, 'conteudo' => 'x'], ErroDaFerramenta::MOTIVO_NAO_ENCONTRADO);
        }
        self::assertSame([], $this->idsDosProcessos($pasta));
        self::assertSame(0, (int) $this->em()->getConnection()->fetchOne('SELECT COUNT(*) FROM pasta_processo WHERE pasta_id = ?', [$pasta->getId()]));
    }

    #[TestDox('processo do escritório que não está nesta pasta: dados e nota são "não encontrado"')]
    public function testProcessoDeOutraPasta(): void
    {
        $pasta = $this->pasta();
        $daOutraPasta = $this->processo();
        $this->vincularNaPasta($this->pasta(), $daOutraPasta);

        $this->assertMotivo(['acao' => 'dados_do_cartao', 'pasta_id' => $pasta->getId(), 'processo_id' => $daOutraPasta->getId(), 'digital' => true], ErroDaFerramenta::MOTIVO_NAO_ENCONTRADO);
        $this->assertMotivo(['acao' => 'nota_tecnica', 'operacao' => 'criar', 'pasta_id' => $pasta->getId(), 'processo_id' => $daOutraPasta->getId(), 'conteudo' => 'x'], ErroDaFerramenta::MOTIVO_NAO_ENCONTRADO);
    }

    #[TestDox('aba Processo oculta para a pessoa: não encontrado em toda ação, menos a nota técnica, que não tem aba')]
    public function testAbaOculta(): void
    {
        $pasta = $this->pasta();
        $processo = $this->processo();
        $this->vincularNaPasta($pasta, $processo);
        $this->em()->persist(AbaOcultaDaPasta::paraTodaAEquipe($this->escritorio, $pasta, AbaDaPasta::Processo, $this->pessoa(), new \DateTimeImmutable()));
        $this->em()->flush();

        $this->assertMotivo(['acao' => 'via_administrativa', 'pasta_id' => $pasta->getId(), 'administrativa' => true], ErroDaFerramenta::MOTIVO_NAO_ENCONTRADO);
        $this->assertMotivo(['acao' => 'acao_da_pasta', 'pasta_id' => $pasta->getId(), 'nome_da_acao' => 'X'], ErroDaFerramenta::MOTIVO_NAO_ENCONTRADO);
        $this->assertMotivo(['acao' => 'principal', 'pasta_id' => $pasta->getId(), 'processo_id' => $processo->getId()], ErroDaFerramenta::MOTIVO_NAO_ENCONTRADO);
        self::assertFalse($this->recarregar(Pasta::class, (int) $pasta->getId())->isAdministrativa());

        $nota = $this->executar(['acao' => 'nota_tecnica', 'operacao' => 'criar', 'pasta_id' => $pasta->getId(), 'processo_id' => $processo->getId(), 'conteudo' => 'Vale']);
        self::assertSame('gravado', $nota->dados['estado']);
    }

    #[TestDox('quem só vê a pasta, sem o recurso de editar: não encontrado e nada grava')]
    public function testSemEditar(): void
    {
        $this->comoOutraPessoa(self::LEITOR);
        $pasta = $this->pasta();

        $this->assertMotivo(['acao' => 'via_administrativa', 'pasta_id' => $pasta->getId(), 'administrativa' => true], ErroDaFerramenta::MOTIVO_NAO_ENCONTRADO);
        self::assertFalse($this->recarregar(Pasta::class, (int) $pasta->getId())->isAdministrativa());
    }

    #[TestDox('sem o recurso de editar processo: dados do cartão e nota técnica dão sem permissão, e nada grava')]
    public function testSemEditarProcesso(): void
    {
        $this->comoOutraPessoa(self::SO_PASTA);
        $pasta = $this->pasta();
        $processo = $this->processo();
        $this->vincularNaPasta($pasta, $processo);

        $this->assertMotivo(['acao' => 'dados_do_cartao', 'pasta_id' => $pasta->getId(), 'processo_id' => $processo->getId(), 'digital' => true], ErroDaFerramenta::MOTIVO_SEM_PERMISSAO);
        $this->assertMotivo(['acao' => 'nota_tecnica', 'operacao' => 'criar', 'pasta_id' => $pasta->getId(), 'processo_id' => $processo->getId(), 'conteudo' => 'x'], ErroDaFerramenta::MOTIVO_SEM_PERMISSAO);
        self::assertSame(0, (int) $this->em()->getConnection()->fetchOne('SELECT COUNT(*) FROM nota_tecnica WHERE processo_id = ?', [$processo->getId()]));
    }

    #[TestDox('sem o módulo Pastas: sem permissão')]
    public function testSemModulo(): void
    {
        $this->comoOutraPessoa(['resources.pasta.view', 'resources.pasta.edit']);
        $pasta = $this->pasta();

        $this->assertMotivo(['acao' => 'via_administrativa', 'pasta_id' => $pasta->getId(), 'administrativa' => true], ErroDaFerramenta::MOTIVO_SEM_PERMISSAO);
    }

    #[TestDox('sem o escopo mcp:escrita a ferramenta recusa, mesmo na ação direta, e nada grava')]
    public function testSemEscopoDeEscrita(): void
    {
        $pasta = $this->pasta();

        try {
            static::getContainer()->get(ProcessosDaPastaFerramenta::class)->executar(['acao' => 'via_administrativa', 'pasta_id' => $pasta->getId(), 'administrativa' => true], $this->chamada(['mcp:leitura']));
            self::fail('deveria recusar');
        } catch (ErroDaFerramenta $e) {
            self::assertSame(ErroDaFerramenta::MOTIVO_SEM_PERMISSAO, $e->motivo);
        }
        self::assertFalse($this->recarregar(Pasta::class, (int) $pasta->getId())->isAdministrativa());
    }

    #[TestDox('a confirmação confere de novo: pasta que foi para a lixeira depois da proposta não grava')]
    public function testConfirmacaoComPastaNaLixeira(): void
    {
        $pasta = $this->pasta();
        $this->executar(['acao' => 'acao_da_pasta', 'pasta_id' => $pasta->getId(), 'nome_da_acao' => 'Ação A']);
        $proposta = $this->executar(['acao' => 'acao_da_pasta', 'pasta_id' => $pasta->getId(), 'nome_da_acao' => 'Ação B']);

        $quem = $this->recarregar(User::class, (int) $this->pessoa->getId());
        $this->em()->find(Pasta::class, (int) $pasta->getId())->marcarExcluida($quem, new \DateTimeImmutable());
        $this->em()->flush();

        $this->assertConfirmacaoRecusada($proposta, ErroDaFerramenta::MOTIVO_NAO_ENCONTRADO);
        self::assertSame('AÇÃO A', $this->em()->getConnection()->fetchOne('SELECT nome_acao FROM pasta WHERE id = ?', [$pasta->getId()]));
    }

    #[TestDox('a confirmação confere de novo: processo que entrou em segredo depois da proposta não grava')]
    public function testConfirmacaoComProcessoQueEntrouEmSegredo(): void
    {
        $pasta = $this->pasta();
        $processo = $this->processo();
        $this->vincularNaPasta($pasta, $processo);
        $proposta = $this->executar(['acao' => 'vincular', 'operacao' => 'desvincular', 'pasta_id' => $pasta->getId(), 'processo_id' => $processo->getId()]);

        $this->em()->getConnection()->executeStatement('UPDATE processo SET nivel_sigilo = 1 WHERE id = ?', [$processo->getId()]);

        $this->assertConfirmacaoRecusada($proposta, ErroDaFerramenta::MOTIVO_EM_SEGREDO);
        self::assertSame([$processo->getId()], $this->idsDosProcessos($pasta));
    }

    /** @return iterable<string, array{array<string, mixed>}> */
    public static function pedidosInvalidos(): iterable
    {
        yield 'ação fora da lista' => [['acao' => 'apagar_tudo']];
        yield 'excluir processo fica fora deste PR' => [['acao' => 'excluir_processo', 'processo_id' => 1]];
        yield 'vincular excluir processo' => [['acao' => 'vincular', 'operacao' => 'excluir', 'processo_id' => 1]];
        yield 'vincular sem operacao' => [['acao' => 'vincular', 'processo_id' => 1]];
        yield 'vincular cadastrado sem processo_id' => [['acao' => 'vincular', 'operacao' => 'cadastrado']];
        yield 'apensado sem tipo' => [['acao' => 'vincular', 'operacao' => 'apensado', 'processo_id' => 1]];
        yield 'apensado com tipo fora da lista' => [['acao' => 'vincular', 'operacao' => 'apensado', 'processo_id' => 1, 'tipo_apenso' => 'inventado']];
        yield 'novo sem número' => [['acao' => 'vincular', 'operacao' => 'novo', 'orgao_julgador' => 'a', 'classe_processual' => 'b', 'assunto_processual' => 'c']];
        yield 'novo com instância fora da lista' => [['acao' => 'vincular', 'operacao' => 'novo', 'numero_processo' => '1', 'orgao_julgador' => 'a', 'classe_processual' => 'b', 'assunto_processual' => 'c', 'instancia' => 'G9']];
        yield 'novo com campo do cartão' => [['acao' => 'vincular', 'operacao' => 'novo', 'numero_processo' => '1', 'orgao_julgador' => 'a', 'classe_processual' => 'b', 'assunto_processual' => 'c', 'digital' => true]];
        yield 'principal com operacao' => [['acao' => 'principal', 'operacao' => 'novo', 'processo_id' => 1]];
        yield 'processo_id zero' => [['acao' => 'principal', 'processo_id' => 0]];
        yield 'processo_id como texto de dígitos' => [['acao' => 'principal', 'processo_id' => '1']];
        yield 'nota_id como texto de dígitos' => [['acao' => 'nota_tecnica', 'operacao' => 'excluir', 'processo_id' => 1, 'nota_id' => '1']];
        yield 'processo_id texto' => [['acao' => 'principal', 'processo_id' => 'abc']];
        yield 'acao_da_pasta sem texto' => [['acao' => 'acao_da_pasta']];
        yield 'acao_da_pasta acima do tamanho' => [['acao' => 'acao_da_pasta', 'nome_da_acao' => str_repeat('a', 256)]];
        yield 'acao_da_pasta com número' => [['acao' => 'acao_da_pasta', 'nome_da_acao' => 123]];
        yield 'via com texto' => [['acao' => 'via_administrativa', 'administrativa' => 'sim']];
        yield 'nota sem operacao' => [['acao' => 'nota_tecnica', 'processo_id' => 1, 'conteudo' => 'x']];
        yield 'nota editar sem nota_id' => [['acao' => 'nota_tecnica', 'operacao' => 'editar', 'processo_id' => 1, 'conteudo' => 'x']];
        yield 'nota excluir com conteudo' => [['acao' => 'nota_tecnica', 'operacao' => 'excluir', 'processo_id' => 1, 'nota_id' => 1, 'conteudo' => 'x']];
        yield 'nota acima do tamanho' => [['acao' => 'nota_tecnica', 'operacao' => 'criar', 'processo_id' => 1, 'conteudo' => str_repeat('a', 20001)]];
    }

    /** @param array<string, mixed> $argumentos */
    #[DataProvider('pedidosInvalidos')]
    #[TestDox('entrada fechada: $_dataName é pedido inválido')]
    public function testPedidoInvalido(array $argumentos): void
    {
        $pasta = $this->pasta();

        $this->assertMotivo(['pasta_id' => $pasta->getId()] + $argumentos, ErroDaFerramenta::MOTIVO_ENTRADA_INVALIDA);
    }

    #[TestDox('o esquema tem as seis ações da tabela, fecha os campos e não tem campo de segredo')]
    public function testEsquema(): void
    {
        $esquema = static::getContainer()->get(ProcessosDaPastaFerramenta::class)->esquemaDeEntrada();

        self::assertSame(['vincular', 'principal', 'dados_do_cartao', 'acao_da_pasta', 'via_administrativa', 'nota_tecnica'], $esquema['properties']['acao']['enum']);
        self::assertFalse($esquema['additionalProperties']);
        self::assertSame(['acao', 'pasta_id'], $esquema['required']);
        self::assertSame([], array_values(array_filter(array_keys($esquema['properties']), static fn (string $campo): bool => str_contains($campo, 'segredo'))));
        foreach ($esquema['properties'] as $campo => $regra) {
            if (($regra['type'] ?? null) === 'string' && !isset($regra['enum'])) {
                self::assertArrayHasKey('maxLength', $regra, $campo);
            }
        }
    }

    /** @param array<string, mixed> $argumentos */
    private function executar(array $argumentos): RespostaDaFerramenta
    {
        return static::getContainer()->get(ProcessosDaPastaFerramenta::class)->executar($argumentos, $this->chamada());
    }

    private function confirmar(RespostaDaFerramenta $proposta): RespostaDaFerramenta
    {
        $chamada = $this->chamada();

        return static::getContainer()->get(ConfirmarAcaoFerramenta::class)->executar([
            'proposta_id' => $proposta->dados['proposta']['proposta_id'],
            'resumo' => $proposta->dados['proposta']['resumo'],
        ], $chamada);
    }

    private function assertConfirmacaoMudou(RespostaDaFerramenta $proposta): void
    {
        try {
            $this->confirmar($proposta);
            self::fail('deveria recusar');
        } catch (ErroDaFerramenta $e) {
            self::assertSame(ProcessosDaPastaFerramenta::MOTIVO_MUDOU, $e->motivo);
            self::assertSame(ProcessosDaPastaFerramenta::MUDOU, $e->getMessage());
        }
    }

    private function assertConfirmacaoRecusada(RespostaDaFerramenta $proposta, string $motivo): void
    {
        try {
            $this->confirmar($proposta);
            self::fail('deveria recusar');
        } catch (ErroDaFerramenta $e) {
            self::assertSame($motivo, $e->motivo, $e->getMessage());
        }
    }

    /** @param array<string, mixed> $argumentos */
    private function assertMotivo(array $argumentos, string $motivo): void
    {
        try {
            $this->executar($argumentos);
        } catch (ErroDaFerramenta $e) {
            self::assertSame($motivo, $e->motivo, $e->getMessage());

            return;
        }
        self::fail('deveria recusar com ' . $motivo);
    }

    /**
     * Uma chamada nova, com o modo do conector ligado pelo portão para a pessoa e o escritório do cenário.
     *
     * @param list<string> $escopos
     */
    private function chamada(array $escopos = ['mcp:leitura', 'mcp:escrita']): ChamadaDoConector
    {
        static::getContainer()->get('services_resetter')->reset();
        $contexto = $this->portao()->abrir((int) $this->pessoa->getId(), (int) $this->escritorio->getId());
        $consentimento = $this->consentimentoDe($this->pessoa);

        return new ChamadaDoConector($contexto, 41, (int) $consentimento->getId(), 'claude.ai', $escopos, new \DateTimeImmutable('+7 days'));
    }

    private function consentimentoDe(User $pessoa): ConsentimentoDoConector
    {
        if ($pessoa->getId() === $this->consentimento->getUser()?->getId()) {
            return $this->consentimento;
        }
        $app = $this->consentimento->getCliente();
        $consentimento = new ConsentimentoDoConector($pessoa, $this->escritorio, $app, ['mcp:leitura', 'mcp:escrita'], new \DateTimeImmutable());
        $this->em()->persist($consentimento);
        $this->em()->flush();
        $this->consentimento = $consentimento;

        return $consentimento;
    }

    /** @param list<string> $permissoes a partir daqui, quem chama é outra pessoa do escritório, com estas permissões */
    private function comoOutraPessoa(array $permissoes): void
    {
        $outra = $this->pessoa();
        $this->vinculo($outra, $this->escritorio, permissoes: $permissoes);
        $this->pessoa = $outra;
        $this->consentimentoDe($outra);
    }

    /**
     * @template T of object
     *
     * @param class-string<T> $classe
     *
     * @return T|null
     */
    private function recarregar(string $classe, int $id): ?object
    {
        $this->reanexar();

        return $this->em()->find($classe, $id);
    }

    /** Limpa o EntityManager e volta a ter, gerenciados, o escritório, a pessoa e o consentimento do cenário. */
    private function reanexar(): void
    {
        $idDaPessoa = (int) $this->pessoa->getId();
        $idDoEscritorio = (int) $this->escritorio->getId();
        $idDoConsentimento = (int) $this->consentimento->getId();
        $this->em()->clear();
        $this->escritorio = $this->em()->find(Tenant::class, $idDoEscritorio);
        $this->pessoa = $this->em()->find(User::class, $idDaPessoa);
        $this->consentimento = $this->em()->find(ConsentimentoDoConector::class, $idDoConsentimento);
    }

    private function pasta(?Tenant $escritorio = null): Pasta
    {
        $pasta = (new Pasta())->setNup('PROC-' . uniqid());
        $pasta->setTenant($escritorio ?? $this->escritorio);
        $this->em()->persist($pasta);
        $this->em()->flush();

        return $pasta;
    }

    private function pastaComClientePrincipal(bool $ligarEnvio): Pasta
    {
        $pasta = $this->pasta();
        $cliente = ClientePFFactory::createOne(['tenant' => $this->escritorio, 'nomeCompleto' => 'Cliente Envio ' . uniqid()])->_real();
        $pasta->addCliente($cliente);
        $agora = new \DateTimeImmutable();
        $linha = new EnvioAutomaticoDoCliente($this->escritorio, $cliente, $this->pessoa, $agora);
        if ($ligarEnvio) {
            $linha->definir(ChaveDoEnvioAutomatico::ResumoSemanal, true, $this->pessoa, $agora);
        }
        $this->em()->persist($linha);
        $this->em()->flush();

        return $pasta;
    }

    private function numeroCnj(): string
    {
        return str_pad((string) random_int(1, 99_999_999), 8, '0', \STR_PAD_LEFT) . '2025807' . str_pad((string) random_int(1, 99_999), 5, '0', \STR_PAD_LEFT);
    }

    private function processo(?Tenant $escritorio = null): Processo
    {
        $processo = new Processo();
        $processo->setNumeroProcesso($this->numeroCnj());
        $processo->setSiglaTribunal('TJDFT');
        $processo->setClasseProcessual('Procedimento Comum');
        $processo->setAssuntoProcessual('Assunto');
        $processo->setTenant($escritorio ?? $this->escritorio);
        $this->em()->persist($processo);
        $this->em()->flush();

        return $processo;
    }

    private function vincularNaPasta(Pasta $pasta, Processo $processo): void
    {
        $vinculo = $pasta->vincularProcesso($processo, $this->pessoa);
        $this->em()->persist($vinculo);
        $this->em()->flush();
    }

    /** @return array{Pasta, Processo, NotaTecnica} */
    private function comNota(string $texto): array
    {
        $pasta = $this->pasta();
        $processo = $this->processo();
        $this->vincularNaPasta($pasta, $processo);
        $nota = new NotaTecnica($this->escritorio, $processo, $this->pessoa, $texto);
        $this->em()->persist($nota);
        $this->em()->flush();

        return [$pasta, $processo, $nota];
    }

    /** @return list<int> */
    private function idsDosProcessos(Pasta $pasta): array
    {
        $this->reanexar();
        $viva = $this->em()->find(Pasta::class, (int) $pasta->getId());

        return array_values(array_map(static fn (Processo $p): int => (int) $p->getId(), $viva->getProcessos()));
    }
}
