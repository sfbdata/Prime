<?php

declare(strict_types=1);

namespace App\Tests\Conector\Integration;

use App\Conector\DTO\ChamadaDoConector;
use App\Conector\DTO\RespostaDaFerramenta;
use App\Conector\Exception\ErroDaFerramenta;
use App\Conector\Ferramenta\DespachoDasFerramentas;
use App\Conector\Ferramenta\LerPastaFerramenta;
use App\Djen\DTO\PublicacaoDjenListaItem;
use App\Djen\Entity\PublicacaoDjen;
use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Inteligencia\Entity\ConfiguracaoDeInteligencia;
use App\Inteligencia\Service\VisibilidadeDoFinanceiroDaPasta;
use App\Pasta\Entity\AbaDaPasta;
use App\Pasta\Entity\AbaOcultaDaPasta;
use App\Pasta\Entity\CadeadoDaPasta;
use App\Pasta\Entity\ModoDoCadeado;
use App\Pasta\Entity\Pasta;
use App\Pasta\Entity\PastaMensagem;
use App\Pasta\Entity\PastaObservacaoDetalhes;
use App\Pasta\Entity\PastaObservacaoFinanceira;
use App\Pasta\Entity\PastaPrazo;
use App\Pasta\Entity\PastaProcesso;
use App\Processo\Entity\Processo;
use App\Tests\Conector\Support\CenarioDoConectorTrait;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * `ler_pasta` com o banco e o portão de verdade (spec mcp-remoto-oauth 6.4 e decisões 4 a 9 do PR 6): cada parte com a
 * sua forma, a aba oculta barrando a parte, a pasta da lixeira, de outro escritório ou trancada como "não encontrado", o
 * segredo de justiça como `em_segredo`, o Registro paginado e a máscara sempre ligada no conector.
 */
#[CoversClass(LerPastaFerramenta::class)]
final class LerPastaFerramentaTest extends KernelTestCase
{
    use CenarioDoConectorTrait;

    private const CPF = '529.982.247-25';
    private const CNJ = '0701134-57.2025.8.07.0007';
    private const OUTRO_CNJ = '0000001-02.2025.8.07.0001';
    private const LEITOR = ['modules.expediente.view', 'modules.pastas.view', 'resources.pasta.view'];

    private Tenant $escritorio;
    private User $pessoa;
    private User $colega;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->escritorio = $this->escritorio();
        $this->pessoa = $this->pessoa();
        $this->vinculo($this->pessoa, $this->escritorio, permissoes: self::LEITOR);
        $this->colega = $this->pessoa();
        $this->colega->setFullName('Colega da Equipe');
        $this->vinculo($this->colega, $this->escritorio, permissoes: self::LEITOR);
    }

    protected function tearDown(): void
    {
        static::getContainer()->get('services_resetter')->reset();
        parent::tearDown();
    }

    #[TestDox('sem partes, sai o resumo: número, ação, responsável, clientes e processos em blocos delimitados')]
    public function testResumoPorPadrao(): void
    {
        $pasta = $this->pasta();
        $pasta->setNomeAcao('Revisão contratual')->setResponsavel($this->pessoa);
        $this->em()->flush();

        $resposta = $this->ler(['pasta_id' => $pasta->getId()]);

        self::assertSame(['pasta_id', 'resumo'], array_keys($resposta->dados));
        $resumo = $resposta->dados['resumo'];
        self::assertSame(['numero', 'acao', 'situacao', 'prioridade', 'responsavel', 'aberta_em', 'carteira', 'marcadores', 'clientes', 'processos'], array_keys($resumo));
        self::assertStringContainsString((string) $pasta->getNup(), (string) $resumo['numero']);
        self::assertStringStartsWith('<clientes>', $resumo['clientes']['texto']);
        self::assertStringStartsWith('<processos_vinculados>', $resumo['processos']['texto']);
        self::assertSame(0, $resumo['processos']['total']);
        self::assertSame(['pasta' => [$pasta->getId()]], $resposta->ids);
        self::assertSame(['partes' => 1], $resposta->contagens);
    }

    #[TestDox('todas as partes do PR 6 de uma vez numa pasta cheia (50 mensagens longas, 50 prazos): cada uma com a sua forma e a resposta abaixo de 50 mil')]
    public function testTodasAsPartes(): void
    {
        $pasta = $this->pasta();
        $longa = str_repeat("Linha com \"aspas\", barra / e acento ção.\n", 60);
        for ($i = 1; $i <= 50; ++$i) {
            $this->mensagem($pasta, '<p>' . $i . ' ' . $longa . '</p>');
            $this->prazo($pasta, str_repeat('Prazo de título longo ', 12), '+' . $i . ' days', str_repeat('R', 120));
        }
        $todas = ['resumo', 'andamento', 'registro', 'equipe', 'cadastro', 'prazos', 'metas', 'detalhes', 'checklist', 'financeiro'];

        $resposta = $this->ler(['pasta_id' => $pasta->getId(), 'partes' => $todas]);

        $dados = $resposta->dados;
        self::assertSame(['pasta_id', ...$todas], array_keys($dados));
        foreach (['metas' => '<metas>', 'detalhes' => '<observacoes>', 'checklist' => '<checklist>', 'financeiro' => '<financeiro>'] as $parte => $tag) {
            self::assertSame(['texto', 'omitidas'], array_keys($dados[$parte]), $parte);
            self::assertStringStartsWith($tag, $dados[$parte]['texto'], $parte);
        }
        self::assertSame(['via', 'atual', 'finalizada', 'estimada', 'base_da_estimativa', 'duracao', 'fases', 'sugestoes'], array_keys($dados['andamento']));
        self::assertCount(50, $dados['registro']['mensagens'], 'os 50 por página não mudam');
        self::assertTrue($dados['registro']['mensagens'][0]['cortado']);
        self::assertSame(['campos', 'reu_completo', 'reus'], array_keys($dados['cadastro']));
        self::assertCount(50, $dados['prazos']['prazos']);
        self::assertSame(0, $dados['prazos']['omitidos']);
        $json = json_encode($dados + ['proxima_pagina' => $resposta->proximaPagina], \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE | \JSON_THROW_ON_ERROR);
        self::assertLessThan(LerPastaFerramenta::TETO_DA_RESPOSTA + 1, mb_strlen($json));
        self::assertLessThan(DespachoDasFerramentas::TETO_DE_CARACTERES, mb_strlen($json));
    }

    #[TestDox('detalhes e financeiro: cada observação sai com o [registro_id N] dela, para o registrar_na_pasta; as outras linhas do financeiro não')]
    public function testObservacoesComId(): void
    {
        $pasta = $this->pasta();
        $pasta->setValorCausa('1000.00');
        $detalhes = (new PastaObservacaoDetalhes())->setTenant($this->escritorio)->setPasta($pasta)->setAutor($this->colega)->setConteudo('Cliente trouxe os documentos');
        $financeira = (new PastaObservacaoFinanceira())->setTenant($this->escritorio)->setPasta($pasta)->setAutor($this->colega)->setConteudo('Pagamento combinado em 3 vezes');
        $this->em()->persist($detalhes);
        $this->em()->persist($financeira);
        $this->em()->flush();

        $dados = $this->ler(['pasta_id' => $pasta->getId(), 'partes' => ['detalhes', 'financeiro']])->dados;

        self::assertStringContainsString('• [registro_id ' . $detalhes->getId() . '] ', $dados['detalhes']['texto']);
        self::assertStringContainsString('Cliente trouxe os documentos', $dados['detalhes']['texto']);
        self::assertStringContainsString('• [registro_id ' . $financeira->getId() . '] observação financeira', $dados['financeiro']['texto']);
        self::assertSame(1, substr_count($dados['financeiro']['texto'], '[registro_id '), 'o valor da causa não ganha id');
    }

    #[TestDox('registro: a máscara vem antes do corte; CPF e e-mail no limite dos 500 saem mascarados, não pela metade')]
    public function testRegistroMascaraAntesDoCorte(): void
    {
        $pasta = $this->pasta();
        $cpf = $this->mensagem($pasta, str_repeat('a', 490) . ' CPF ' . self::CPF . ' ' . str_repeat('b', 100));
        $email = $this->mensagem($pasta, str_repeat('a', 492) . ' fulano.tal@exemplo.com.br ' . str_repeat('b', 100));

        $mensagens = array_column($this->ler(['pasta_id' => $pasta->getId(), 'partes' => ['registro']])->dados['registro']['mensagens'], null, 'id');

        self::assertTrue($mensagens[$cpf->getId()]['cortado']);
        self::assertStringNotContainsString('529', $mensagens[$cpf->getId()]['texto']);
        self::assertTrue($mensagens[$email->getId()]['cortado']);
        self::assertStringNotContainsString('fulan', $mensagens[$email->getId()]['texto']);
    }

    /** @return array<string, array{string, string}> dado pessoal e o que não pode sobrar dele na saída */
    public static function dadosNoLimite(): array
    {
        return [
            'CPF' => ['529.982.247-25', '529'],
            'e-mail' => ['fulano.tal@exemplo.com.br', 'fulano'],
            'telefone' => ['(61) 99999-1234', '999'],
        ];
    }

    #[DataProvider('dadosNoLimite')]
    #[TestDox('prazos: a máscara vem antes do corte; o dado que atravessa o limite do título sai mascarado, não pela metade')]
    public function testTituloDoPrazoMascaraAntesDoCorte(string $dado, string $resto): void
    {
        $pasta = $this->pasta();
        $inicio = LerPastaFerramenta::TAMANHO_MAXIMO_DO_TITULO - intdiv(mb_strlen($dado), 2);
        $this->prazo($pasta, str_repeat('a', $inicio - 1) . ' ' . $dado . ' ' . str_repeat('b', 80), '+3 days');

        $titulo = (string) $this->ler(['pasta_id' => $pasta->getId(), 'partes' => ['prazos']])->dados['prazos']['prazos'][0]['titulo'];

        self::assertStringEndsWith('…', $titulo, 'o título saiu cortado');
        self::assertStringNotContainsString($resto, $titulo);
        self::assertStringNotContainsString('@', $titulo);
    }

    #[TestDox('resumo: com a aba Processo oculta, os processos vinculados continuam no resumo (a aba Dados os mostra)')]
    public function testResumoComProcessoOcultoMantemOsProcessos(): void
    {
        $pasta = $this->pastaComProcesso();
        $this->ocultar($pasta, AbaDaPasta::Processo);

        $processos = $this->ler(['pasta_id' => $pasta->getId()])->dados['resumo']['processos'];

        self::assertSame(1, $processos['total']);
        self::assertStringContainsString(self::CNJ, $processos['texto']);
    }

    #[TestDox('resumo: com as abas Dados e Processo ocultas, os processos somem, o número do processo não sai e a resposta diz sem acesso, não "nenhum"')]
    public function testResumoComDadosEProcessoOcultosTiraOsProcessos(): void
    {
        $pasta = $this->pastaComProcesso();
        $this->ocultar($pasta, AbaDaPasta::Processo);
        $this->ocultar($pasta, AbaDaPasta::Dados);

        $resposta = $this->ler(['pasta_id' => $pasta->getId()]);

        self::assertNull($resposta->dados['resumo']['processos']['total'], 'sem acesso não é zero');
        self::assertTrue($resposta->dados['resumo']['processos']['sem_acesso']);
        self::assertStringContainsString('sem acesso', $resposta->dados['resumo']['processos']['texto']);
        self::assertStringNotContainsString('nenhum processo vinculado', $resposta->dados['resumo']['processos']['texto']);
        self::assertStringNotContainsString(self::CNJ, json_encode($resposta->dados, \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_UNICODE));
    }

    #[TestDox('financeiro: com a aba visível mas a VisibilidadeDoFinanceiroDaPasta negando, a parte é recusada como não encontrado')]
    public function testFinanceiroRecusadoPelaVisibilidade(): void
    {
        $pasta = $this->pasta();
        $nega = $this->createStub(VisibilidadeDoFinanceiroDaPasta::class);
        $nega->method('podeVer')->willReturn(false);
        static::getContainer()->set(VisibilidadeDoFinanceiroDaPasta::class, $nega);

        $this->assertMotivo(['pasta_id' => $pasta->getId(), 'partes' => ['financeiro']], ErroDaFerramenta::MOTIVO_NAO_ENCONTRADO);
        static::getContainer()->get('services_resetter')->reset();
        $resumo = $this->ler(['pasta_id' => $pasta->getId(), 'partes' => ['resumo']]);
        self::assertArrayNotHasKey('financeiro', $resumo->dados, 'o resto da pasta continua');
    }

    #[TestDox('pasta de outro escritório em que a mesma pessoa tem vínculo: não encontrado pela chamada deste escritório')]
    public function testOutroEscritorioComVinculo(): void
    {
        $outro = $this->escritorio('Outro');
        $this->vinculo($this->pessoa, $outro, permissoes: self::LEITOR);
        $doOutro = $this->pasta($outro);

        $this->assertMotivo(['pasta_id' => $doOutro->getId()], ErroDaFerramenta::MOTIVO_NAO_ENCONTRADO);
    }

    #[TestDox('registro: 55 mensagens dão a página 1 com as 50 mais recentes e proxima_pagina 2; a página 2 traz as 5 restantes')]
    public function testRegistroPaginado(): void
    {
        $pasta = $this->pasta();
        $ids = [];
        for ($i = 1; $i <= 55; ++$i) {
            $ids[] = $this->mensagem($pasta, '<p>Mensagem ' . $i . '</p>')->getId();
        }
        $this->em()->flush();

        $primeira = $this->ler(['pasta_id' => $pasta->getId(), 'partes' => ['registro']]);
        $mensagens = $primeira->dados['registro']['mensagens'];
        self::assertCount(50, $mensagens);
        self::assertSame(max($ids), $mensagens[0]['id'], 'a mais recente primeiro');
        self::assertSame('Mensagem 55', $mensagens[0]['texto']);
        self::assertSame(['id', 'em', 'autor', 'do_sistema', 'resposta_a', 'editada', 'texto', 'cortado'], array_keys($mensagens[0]));
        self::assertSame(2, $primeira->proximaPagina);

        static::getContainer()->get('services_resetter')->reset();
        $segunda = $this->ler(['pasta_id' => $pasta->getId(), 'partes' => ['registro'], 'pagina' => 2]);
        self::assertCount(5, $segunda->dados['registro']['mensagens']);
        self::assertNull($segunda->proximaPagina);
        self::assertSame(min($ids), $segunda->dados['registro']['mensagens'][4]['id']);
    }

    #[TestDox('registro: com o Financeiro oculto, o cartão de contrato do sistema não sai; mensagem longa é cortada')]
    public function testRegistroSemFinanceiro(): void
    {
        $pasta = $this->pasta();
        $comum = $this->mensagem($pasta, str_repeat('a', 600));
        $contrato = $this->mensagem($pasta, 'Contrato assinado');
        $contrato->setSistema('contrato');
        $this->ocultar($pasta, AbaDaPasta::Financeiro);

        $mensagens = $this->ler(['pasta_id' => $pasta->getId(), 'partes' => ['registro']])->dados['registro']['mensagens'];

        self::assertSame([$comum->getId()], array_column($mensagens, 'id'));
        self::assertTrue($mensagens[0]['cortado']);
        self::assertSame(501, mb_strlen($mensagens[0]['texto']));
    }

    /** @return iterable<string, array{AbaDaPasta, string}> */
    public static function abaQueBarra(): iterable
    {
        yield 'registro pela aba Dados' => [AbaDaPasta::Dados, 'registro'];
        yield 'financeiro pela aba Financeiro' => [AbaDaPasta::Financeiro, 'financeiro'];
        yield 'detalhes pela aba Detalhes' => [AbaDaPasta::Detalhes, 'detalhes'];
        yield 'checklist pela aba Documentos' => [AbaDaPasta::Documentos, 'checklist'];
        yield 'metas pela aba Tarefas' => [AbaDaPasta::Tarefas, 'metas'];
        yield 'controladoria pela aba Detalhes' => [AbaDaPasta::Detalhes, 'controladoria'];
        yield 'prazos pela aba Tarefas (Metas)' => [AbaDaPasta::Tarefas, 'prazos'];
    }

    #[DataProvider('abaQueBarra')]
    #[TestDox('aba oculta para a pessoa barra a parte como não encontrado: $_dataName')]
    public function testAbaOcultaBarra(AbaDaPasta $aba, string $parte): void
    {
        $pasta = $this->pasta();
        $this->ocultar($pasta, $aba);

        $this->assertMotivo(['pasta_id' => $pasta->getId(), 'partes' => [$parte]], ErroDaFerramenta::MOTIVO_NAO_ENCONTRADO);
        static::getContainer()->get('services_resetter')->reset();
        self::assertArrayHasKey('resumo', $this->ler(['pasta_id' => $pasta->getId()])->dados, 'o resumo continua');
    }

    #[TestDox('não encontrado: a pasta da lixeira, a de outro escritório, a trancada e a que não existe')]
    public function testNaoEncontrado(): void
    {
        $naLixeira = $this->pasta();
        $naLixeira->marcarExcluida($this->pessoa, new \DateTimeImmutable());
        $deOutro = $this->pasta($this->escritorio('Outro'));
        $trancada = $this->pasta();
        $this->em()->persist(new CadeadoDaPasta($this->escritorio, $trancada, ModoDoCadeado::Todos, null, new \DateTimeImmutable()));
        $this->em()->flush();

        foreach ([$naLixeira->getId(), $deOutro->getId(), $trancada->getId(), 999_999_999] as $id) {
            $this->assertMotivo(['pasta_id' => $id], ErroDaFerramenta::MOTIVO_NAO_ENCONTRADO);
            static::getContainer()->get('services_resetter')->reset();
        }
    }

    #[TestDox('pasta com processo em segredo de justiça: em_segredo, em qualquer parte')]
    public function testEmSegredo(): void
    {
        $pasta = $this->pasta(comSegredo: true);

        $this->assertMotivo(['pasta_id' => $pasta->getId()], ErroDaFerramenta::MOTIVO_EM_SEGREDO);
        static::getContainer()->get('services_resetter')->reset();
        $this->assertMotivo(['pasta_id' => $pasta->getId(), 'partes' => ['registro']], ErroDaFerramenta::MOTIVO_EM_SEGREDO);
    }

    #[TestDox('timeline: os acontecimentos da pasta com o texto mascarado, sem link nem texto de antes da edição')]
    public function testTimeline(): void
    {
        $pasta = $this->pasta();
        $this->mensagem($pasta, '<p>Registro do CPF ' . self::CPF . ' e nota UNICA-REGISTRO</p>');

        $resposta = $this->ler(['pasta_id' => $pasta->getId(), 'partes' => ['timeline']]);

        self::assertSame(['pasta_id', 'timeline'], array_keys($resposta->dados));
        $timeline = $resposta->dados['timeline'];
        self::assertSame(['eventos', 'total', 'truncado', 'contagens_por_categoria', 'resumo', 'precisa_de_atencao'], array_keys($timeline));
        self::assertSame(['id', 'tipo', 'categoria', 'quando', 'titulo', 'texto', 'autor', 'fonte', 'prioridade', 'pendente', 'editado', 'cortado'], array_keys($timeline['eventos'][0]));
        $json = json_encode($timeline, \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_UNICODE);
        self::assertStringContainsString('UNICA-REGISTRO', $json);
        self::assertStringNotContainsString(self::CPF, $json, 'no conector o CPF sai mascarado');
        self::assertStringNotContainsString('"link"', $json);
        self::assertStringNotContainsString('"antes"', $json);
        self::assertSame(['pasta' => [(int) $pasta->getId()]], $resposta->ids);
    }

    #[TestDox('timeline: com a aba Dados oculta, o registro some da linha do tempo e a parte continua; sem a visibilidade do financeiro, a observação financeira não sai')]
    public function testTimelineRespeitaAbasEFinanceiro(): void
    {
        $pasta = $this->pasta();
        $this->mensagem($pasta, 'UNICA-REGISTRO');
        $financeira = (new PastaObservacaoFinanceira())->setTenant($this->escritorio)->setPasta($pasta)->setAutor($this->colega)->setConteudo('UNICA-FINANCEIRA');
        $this->em()->persist($financeira);
        $this->em()->flush();
        $nega = $this->createStub(VisibilidadeDoFinanceiroDaPasta::class);
        $nega->method('podeVer')->willReturn(false);
        static::getContainer()->set(VisibilidadeDoFinanceiroDaPasta::class, $nega);
        $this->ocultar($pasta, AbaDaPasta::Dados);

        $json = json_encode($this->ler(['pasta_id' => $pasta->getId(), 'partes' => ['timeline']])->dados, \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_UNICODE);

        self::assertStringNotContainsString('UNICA-REGISTRO', $json);
        self::assertStringNotContainsString('UNICA-FINANCEIRA', $json);
    }

    #[TestDox('timeline: 80 registros longos viram no máximo 30 eventos, truncado, e a resposta com o Registro junto cabe no teto')]
    public function testTimelineCabeNoTeto(): void
    {
        $pasta = $this->pasta();
        $longa = str_repeat('Linha longa com acento ção. ', 40);
        for ($i = 1; $i <= 80; ++$i) {
            $this->mensagem($pasta, '<p>' . $i . ' ' . $longa . '</p>');
        }

        $resposta = $this->ler(['pasta_id' => $pasta->getId(), 'partes' => ['timeline', 'registro']]);

        self::assertLessThanOrEqual(LerPastaFerramenta::EVENTOS_DA_LINHA_DO_TEMPO, \count($resposta->dados['timeline']['eventos']));
        self::assertTrue($resposta->dados['timeline']['truncado']);
        $json = json_encode($resposta->dados + ['proxima_pagina' => $resposta->proximaPagina], \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE | \JSON_THROW_ON_ERROR);
        self::assertLessThan(LerPastaFerramenta::TETO_DA_RESPOSTA + 1, mb_strlen($json));
    }

    #[TestDox('timeline e controladoria: pasta trancada, de outro escritório, na lixeira ou inexistente são não encontrado; em segredo é em_segredo')]
    public function testTimelineEControladoriaBarreiras(): void
    {
        $naLixeira = $this->pasta();
        $naLixeira->marcarExcluida($this->pessoa, new \DateTimeImmutable());
        $deOutro = $this->pasta($this->escritorio('Outro'));
        $trancada = $this->pasta();
        $this->em()->persist(new CadeadoDaPasta($this->escritorio, $trancada, ModoDoCadeado::Todos, null, new \DateTimeImmutable()));
        $secreta = $this->pasta(comSegredo: true);
        $this->em()->flush();

        foreach (['timeline', 'controladoria'] as $parte) {
            foreach ([$naLixeira->getId(), $deOutro->getId(), $trancada->getId(), 999_999_999] as $id) {
                $this->assertMotivo(['pasta_id' => $id, 'partes' => [$parte]], ErroDaFerramenta::MOTIVO_NAO_ENCONTRADO);
                static::getContainer()->get('services_resetter')->reset();
            }
            $this->assertMotivo(['pasta_id' => $secreta->getId(), 'partes' => [$parte]], ErroDaFerramenta::MOTIVO_EM_SEGREDO);
            static::getContainer()->get('services_resetter')->reset();
        }
    }

    #[TestDox('controladoria: o prazo vencido vira alerta e os pontos do relatório inicial dizem o que está registrado')]
    public function testControladoria(): void
    {
        $pasta = $this->pasta();
        $this->prazo($pasta, 'Contestação UNICA-PRAZO', '-3 days');
        $detalhes = (new PastaObservacaoDetalhes())->setTenant($this->escritorio)->setPasta($pasta)->setAutor($this->colega)->setConteudo('<p>O objetivo do cliente é revisar o contrato</p>');
        $this->em()->persist($detalhes);
        $this->em()->flush();

        $resposta = $this->ler(['pasta_id' => $pasta->getId(), 'partes' => ['controladoria']]);

        $controladoria = $resposta->dados['controladoria'];
        self::assertSame(['alertas', 'urgentes', 'pontos_do_relatorio_inicial'], array_keys($controladoria));
        $porTipo = array_column($controladoria['alertas'], null, 'tipo');
        self::assertSame('alta', $porTipo['prazos']['nivel']);
        self::assertStringContainsString('UNICA-PRAZO', $porTipo['prazos']['detalhe']);
        self::assertGreaterThanOrEqual(1, $controladoria['urgentes']);
        $pontos = array_column($controladoria['pontos_do_relatorio_inicial']['itens'], 'registrado', 'ponto');
        self::assertTrue($pontos['objetivos']);
        self::assertFalse($pontos['acordos']);
        self::assertFalse($controladoria['pontos_do_relatorio_inicial']['completo']);
    }

    #[TestDox('controladoria: com a aba Metas oculta, o alerta de prazo vencido não sai e a parte continua')]
    public function testControladoriaSemMetas(): void
    {
        $pasta = $this->pasta();
        $this->prazo($pasta, 'Contestação UNICA-PRAZO', '-3 days');
        $this->ocultar($pasta, AbaDaPasta::Tarefas);

        $alertas = $this->ler(['pasta_id' => $pasta->getId(), 'partes' => ['controladoria']])->dados['controladoria']['alertas'];

        self::assertArrayNotHasKey('prazos', array_column($alertas, null, 'tipo'));
        self::assertStringNotContainsString('UNICA-PRAZO', json_encode($alertas, \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_UNICODE));
    }

    #[TestDox('controladoria: o alerta de contrato repetido só sai a quem vê o financeiro, e nunca com o detalhe (cita outra pasta e o contrato)')]
    public function testControladoriaContratoRepetido(): void
    {
        $pasta = $this->pasta();
        $outra = $this->pasta();
        $pasta->setContrato('CONTRATO-UNICO-77');
        $outra->setContrato('CONTRATO-UNICO-77');
        $this->em()->flush();
        $ve = $this->createStub(VisibilidadeDoFinanceiroDaPasta::class);
        $ve->method('podeVer')->willReturn(true);
        static::getContainer()->set(VisibilidadeDoFinanceiroDaPasta::class, $ve);

        $comFinanceiro = $this->ler(['pasta_id' => $pasta->getId(), 'partes' => ['controladoria']])->dados['controladoria'];

        $porTipo = array_column($comFinanceiro['alertas'], null, 'tipo');
        self::assertSame('', $porTipo['contrato']['detalhe']);
        $json = json_encode($comFinanceiro, \JSON_THROW_ON_ERROR);
        self::assertStringNotContainsString('CONTRATO-UNICO-77', $json);
        self::assertStringNotContainsString((string) $outra->getNup(), $json);
    }

    #[TestDox('controladoria: sem a visibilidade do financeiro, o alerta de contrato repetido não sai')]
    public function testControladoriaContratoSemFinanceiro(): void
    {
        $pasta = $this->pasta();
        $outra = $this->pasta();
        $pasta->setContrato('CONTRATO-UNICO-77');
        $outra->setContrato('CONTRATO-UNICO-77');
        $this->em()->flush();
        $nega = $this->createStub(VisibilidadeDoFinanceiroDaPasta::class);
        $nega->method('podeVer')->willReturn(false);
        static::getContainer()->set(VisibilidadeDoFinanceiroDaPasta::class, $nega);

        $alertas = $this->ler(['pasta_id' => $pasta->getId(), 'partes' => ['controladoria']])->dados['controladoria']['alertas'];

        self::assertArrayNotHasKey('contrato', array_column($alertas, null, 'tipo'));
    }

    #[TestDox('prazos: só os em aberto, com tipo, título e data')]
    public function testPrazosSoEmAberto(): void
    {
        $pasta = $this->pasta();
        $aberto = $this->prazo($pasta, 'Contestação', '+5 days');
        $cumprido = $this->prazo($pasta, 'Réplica', '+2 days');
        $cumprido->cumprir($this->pessoa);
        $this->em()->flush();

        $prazos = $this->ler(['pasta_id' => $pasta->getId(), 'partes' => ['prazos']])->dados['prazos'];

        self::assertSame([$aberto->getId()], array_column($prazos['prazos'], 'id'));
        self::assertSame('prazo', $prazos['prazos'][0]['tipo']);
        self::assertSame('Contestação', $prazos['prazos'][0]['titulo']);
        self::assertSame((new \DateTimeImmutable('+5 days'))->format('Y-m-d'), $prazos['prazos'][0]['data']);
    }

    #[TestDox('cadastro: só preenchido ou falta, sem nenhum valor; equipe: só id e nome')]
    public function testCadastroEEquipe(): void
    {
        $pasta = $this->pasta();

        $dados = $this->ler(['pasta_id' => $pasta->getId(), 'partes' => ['cadastro', 'equipe']])->dados;

        self::assertSame(['falta'], array_values(array_unique($dados['cadastro']['campos'])));
        self::assertFalse($dados['cadastro']['reu_completo']);
        self::assertSame(0, $dados['cadastro']['reus']);
        self::assertNotSame([], $dados['equipe']['pessoas']);
        foreach ($dados['equipe']['pessoas'] as $pessoa) {
            self::assertSame(['id', 'nome'], array_keys($pessoa));
        }
        self::assertContains($this->colega->getId(), array_column($dados['equipe']['pessoas'], 'id'));
    }

    #[TestDox('máscara: com o escritório tendo desligado a máscara, o CPF sai mascarado no conector')]
    public function testMascaraSempreLigada(): void
    {
        $configuracao = new ConfiguracaoDeInteligencia($this->escritorio);
        $configuracao->atualizar(true, 10, 100, false, $this->pessoa);
        $this->em()->persist($configuracao);
        $pasta = $this->pasta();
        $pasta->setNomeAcao('Revisão do CPF ' . self::CPF);
        $this->mensagem($pasta, 'O CPF do cliente é ' . self::CPF);
        $this->em()->flush();

        $dados = $this->ler(['pasta_id' => $pasta->getId(), 'partes' => ['resumo', 'registro']])->dados;

        $json = json_encode($dados, \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_UNICODE);
        self::assertStringNotContainsString(self::CPF, $json);
        self::assertStringContainsString('[CPF]', $dados['registro']['mensagens'][0]['texto']);
    }

    #[TestDox('publicacoes: a lista traz id, data, tribunal, tipo, processo e resumo, a mais recente primeiro, e NÃO marca nada como lida')]
    public function testPublicacoesListaSemGravarLida(): void
    {
        $pasta = $this->pastaComProcesso();
        $antiga = $this->publicacao(self::CNJ, '<p>Despacho antigo.</p>', '2026-11-01');
        $recente = $this->publicacao(self::CNJ, '<p>Intimação para <b>manifestar</b> em 15 dias.</p>', '2026-11-10');
        $this->publicacao(self::OUTRO_CNJ, 'De um processo que não é desta pasta.');

        $resposta = $this->ler(['pasta_id' => $pasta->getId(), 'partes' => ['publicacoes']]);

        $lista = $resposta->dados['publicacoes'];
        self::assertSame(['itens', 'pagina', 'por_pagina'], array_keys($lista));
        self::assertSame([$recente->getId(), $antiga->getId()], array_column($lista['itens'], 'id'));
        self::assertSame(['id', 'data', 'tribunal', 'tipo', 'processo', 'lida', 'resumo'], array_keys($lista['itens'][0]));
        self::assertSame('2026-11-10', $lista['itens'][0]['data']);
        self::assertSame('TJDFT', $lista['itens'][0]['tribunal']);
        self::assertSame(self::CNJ, $lista['itens'][0]['processo']);
        self::assertSame('Intimação para manifestar em 15 dias.', $lista['itens'][0]['resumo']);
        self::assertNull($resposta->proximaPagina);
        self::assertSame(0, $this->totalDeLidas(), 'montar a lista não grava lida');
    }

    #[TestDox('publicacoes: com publicacao_id, o texto inteiro da publicação desta pasta, sem marcar como lida')]
    public function testPublicacaoInteiraSemGravarLida(): void
    {
        $pasta = $this->pastaComProcesso();
        $publicacao = $this->publicacao(self::CNJ, '<p>Primeiro parágrafo.</p><p>Segundo &amp; último.</p>');
        $outra = $this->publicacao(self::CNJ, 'Outra, que continua não lida.');

        $resposta = $this->ler(['pasta_id' => $pasta->getId(), 'partes' => ['publicacoes'], 'publicacao_id' => $publicacao->getId()]);

        $unica = $resposta->dados['publicacoes']['publicacao'];
        self::assertSame(['id', 'data', 'tribunal', 'tipo', 'processo', 'lida', 'texto', 'cortado'], array_keys($unica));
        self::assertSame($publicacao->getId(), $unica['id']);
        self::assertSame('Primeiro parágrafo. Segundo & último.', $unica['texto']);
        self::assertFalse($unica['cortado']);
        self::assertFalse($unica['lida']);
        self::assertNotNull($outra->getId());
        self::assertSame(0, $this->totalDeLidas(), 'ler o texto inteiro não grava lida');
    }

    #[TestDox('publicacoes: a publicação de outra pasta, de outro escritório (mesmo número de processo) ou inexistente: não encontrado')]
    public function testPublicacaoDeForaDaPastaNaoEncontrada(): void
    {
        $pasta = $this->pastaComProcesso();
        $outraPasta = $this->pastaComProcesso(self::OUTRO_CNJ);
        $deOutraPasta = $this->publicacao(self::OUTRO_CNJ, 'Teor da outra pasta.');
        $outro = $this->escritorio('Outro');
        $deOutroEscritorio = $this->publicacao(self::CNJ, 'Teor de outro escritório.', escritorio: $outro);
        self::assertNotNull($outraPasta->getId());

        foreach ([$deOutraPasta->getId(), $deOutroEscritorio->getId(), 999_999_999] as $id) {
            $this->assertMotivo(['pasta_id' => $pasta->getId(), 'partes' => ['publicacoes'], 'publicacao_id' => $id], ErroDaFerramenta::MOTIVO_NAO_ENCONTRADO);
            static::getContainer()->get('services_resetter')->reset();
        }
        $lista = $this->ler(['pasta_id' => $pasta->getId(), 'partes' => ['publicacoes']])->dados['publicacoes']['itens'];
        self::assertSame([], $lista, 'a lista da pasta não traz a de outro escritório nem a de outra pasta');
    }

    #[TestDox('publicacoes: com a aba Push oculta, a parte é recusada como não encontrado (a lista e o texto); o resumo continua')]
    public function testPublicacoesComPushOculto(): void
    {
        $pasta = $this->pastaComProcesso();
        $publicacao = $this->publicacao(self::CNJ, 'Teor.');
        $this->ocultar($pasta, AbaDaPasta::Push);

        $this->assertMotivo(['pasta_id' => $pasta->getId(), 'partes' => ['publicacoes']], ErroDaFerramenta::MOTIVO_NAO_ENCONTRADO);
        static::getContainer()->get('services_resetter')->reset();
        $this->assertMotivo(['pasta_id' => $pasta->getId(), 'partes' => ['publicacoes'], 'publicacao_id' => $publicacao->getId()], ErroDaFerramenta::MOTIVO_NAO_ENCONTRADO);
        static::getContainer()->get('services_resetter')->reset();
        self::assertArrayHasKey('resumo', $this->ler(['pasta_id' => $pasta->getId()])->dados);
    }

    #[TestDox('publicacoes: publicacao_id sem a parte publicacoes é pedido inválido')]
    public function testPublicacaoIdSemAParte(): void
    {
        $pasta = $this->pastaComProcesso();
        $publicacao = $this->publicacao(self::CNJ, 'Teor.');

        $this->assertMotivo(['pasta_id' => $pasta->getId(), 'publicacao_id' => $publicacao->getId()], ErroDaFerramenta::MOTIVO_ENTRADA_INVALIDA);
        static::getContainer()->get('services_resetter')->reset();
        $this->assertMotivo(['pasta_id' => $pasta->getId(), 'partes' => ['resumo'], 'publicacao_id' => $publicacao->getId()], ErroDaFerramenta::MOTIVO_ENTRADA_INVALIDA);
    }

    #[TestDox('publicacoes: pasta da lixeira, de outro escritório, trancada ou em segredo de justiça seguem barradas')]
    public function testPublicacoesComAsBarreirasDaPasta(): void
    {
        $naLixeira = $this->pastaComProcesso();
        $naLixeira->marcarExcluida($this->pessoa, new \DateTimeImmutable());
        $outro = $this->escritorio('Outro');
        $deOutro = $this->pastaComProcesso(self::OUTRO_CNJ, $outro);
        $trancada = $this->pasta();
        $this->em()->persist(new CadeadoDaPasta($this->escritorio, $trancada, ModoDoCadeado::Todos, null, new \DateTimeImmutable()));
        $segredo = $this->pasta(comSegredo: true);
        $this->em()->flush();

        foreach ([$naLixeira->getId(), $deOutro->getId(), $trancada->getId()] as $id) {
            $this->assertMotivo(['pasta_id' => $id, 'partes' => ['publicacoes']], ErroDaFerramenta::MOTIVO_NAO_ENCONTRADO);
            static::getContainer()->get('services_resetter')->reset();
        }
        $this->assertMotivo(['pasta_id' => $segredo->getId(), 'partes' => ['publicacoes']], ErroDaFerramenta::MOTIVO_EM_SEGREDO);
    }

    #[TestDox('publicacoes: 30 publicações dão a página 1 com 25 e proxima_pagina 2; a página 2 traz as 5 restantes')]
    public function testPublicacoesPaginadas(): void
    {
        $pasta = $this->pastaComProcesso();
        for ($i = 1; $i <= 30; ++$i) {
            $this->publicacao(self::CNJ, 'Publicação ' . $i, '2026-10-' . str_pad((string) $i, 2, '0', \STR_PAD_LEFT));
        }

        $primeira = $this->ler(['pasta_id' => $pasta->getId(), 'partes' => ['publicacoes']]);
        self::assertCount(25, $primeira->dados['publicacoes']['itens']);
        self::assertSame('Publicação 30', $primeira->dados['publicacoes']['itens'][0]['resumo']);
        self::assertSame(2, $primeira->proximaPagina);

        static::getContainer()->get('services_resetter')->reset();
        $segunda = $this->ler(['pasta_id' => $pasta->getId(), 'partes' => ['publicacoes'], 'pagina' => 2]);
        self::assertCount(5, $segunda->dados['publicacoes']['itens']);
        self::assertSame(2, $segunda->dados['publicacoes']['pagina']);
        self::assertNull($segunda->proximaPagina);
    }

    #[TestDox('pagina com registro e publicacoes juntos é pedido inválido; sem pagina, as duas vêm na página 1 e a próxima de cada uma vai dentro da parte')]
    public function testPaginaComRegistroEPublicacoesJuntos(): void
    {
        $pasta = $this->pastaComProcesso();
        for ($i = 1; $i <= 26; ++$i) {
            $this->publicacao(self::CNJ, 'Publicação ' . $i, '2026-10-' . str_pad((string) $i, 2, '0', \STR_PAD_LEFT));
        }
        for ($i = 1; $i <= 51; ++$i) {
            $this->mensagem($pasta, 'Mensagem ' . $i);
        }
        $this->em()->flush();

        $this->assertMotivo(['pasta_id' => $pasta->getId(), 'partes' => ['registro', 'publicacoes'], 'pagina' => 2], ErroDaFerramenta::MOTIVO_ENTRADA_INVALIDA);

        static::getContainer()->get('services_resetter')->reset();
        $juntas = $this->ler(['pasta_id' => $pasta->getId(), 'partes' => ['registro', 'publicacoes']]);
        self::assertNull($juntas->proximaPagina, 'com duas partes paginadas não há proxima_pagina de cima');
        self::assertSame(2, $juntas->dados['registro']['proxima_pagina']);
        self::assertSame(2, $juntas->dados['publicacoes']['proxima_pagina']);

        static::getContainer()->get('services_resetter')->reset();
        $so = $this->ler(['pasta_id' => $pasta->getId(), 'partes' => ['publicacoes']]);
        self::assertSame(2, $so->proximaPagina, 'com uma parte paginada a próxima de cima continua');
    }

    #[DataProvider('dadosNoLimite')]
    #[TestDox('publicacoes: a máscara vem antes do corte do resumo; o dado que atravessa o limite dos 220 sai mascarado, não pela metade')]
    public function testResumoDaPublicacaoMascaraAntesDoCorte(string $dado, string $resto): void
    {
        $pasta = $this->pastaComProcesso();
        $inicio = PublicacaoDjenListaItem::LIMITE_RESUMO - intdiv(mb_strlen($dado), 2);
        $this->publicacao(self::CNJ, str_repeat('a', $inicio - 1) . ' ' . $dado . ' ' . str_repeat('b', 80));

        $resumo = (string) $this->ler(['pasta_id' => $pasta->getId(), 'partes' => ['publicacoes']])->dados['publicacoes']['itens'][0]['resumo'];

        self::assertStringEndsWith('…', $resumo, 'o resumo saiu cortado');
        self::assertStringNotContainsString($resto, $resumo);
        self::assertStringNotContainsString('@', $resumo);
    }

    #[TestDox('publicacoes: o texto inteiro acima do teto é cortado DEPOIS da máscara e marcado cortado; o CPF no limite não sobra pela metade')]
    public function testTextoInteiroCortadoDepoisDaMascara(): void
    {
        $pasta = $this->pastaComProcesso();
        $falta = LerPastaFerramenta::TETO_DO_TEOR - 4;
        $publicacao = $this->publicacao(self::CNJ, str_repeat('a', $falta) . ' ' . self::CPF . ' ' . str_repeat('b', 20_000));

        $unica = $this->ler(['pasta_id' => $pasta->getId(), 'partes' => ['publicacoes'], 'publicacao_id' => $publicacao->getId()])->dados['publicacoes']['publicacao'];

        self::assertTrue($unica['cortado']);
        self::assertStringEndsWith('…', $unica['texto']);
        self::assertLessThan(LerPastaFerramenta::TETO_DO_TEOR + 2, mb_strlen($unica['texto']));
        self::assertStringNotContainsString('529', $unica['texto']);
        self::assertStringNotContainsString('982', $unica['texto']);
    }

    #[TestDox('publicacoes: o texto inteiro, mascarado com a máscara do escritório desligada, e a resposta cabe no teto do despacho')]
    public function testTextoInteiroMascaradoECabeNoTeto(): void
    {
        $configuracao = new ConfiguracaoDeInteligencia($this->escritorio);
        $configuracao->atualizar(true, 10, 100, false, $this->pessoa);
        $this->em()->persist($configuracao);
        $pasta = $this->pastaComProcesso();
        $publicacao = $this->publicacao(self::CNJ, 'Cliente CPF ' . self::CPF . '. ' . str_repeat('Texto "com aspas" e barra / longo. ', 3_000));

        $resposta = $this->ler(['pasta_id' => $pasta->getId(), 'partes' => ['resumo', 'publicacoes'], 'publicacao_id' => $publicacao->getId()]);

        $texto = $resposta->dados['publicacoes']['publicacao']['texto'];
        self::assertStringContainsString('[CPF]', $texto);
        self::assertStringNotContainsString(self::CPF, $texto);
        $json = json_encode($resposta->dados, \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE | \JSON_THROW_ON_ERROR);
        self::assertLessThan(LerPastaFerramenta::TETO_DA_RESPOSTA + 1, mb_strlen($json));
    }

    /** @param array<string, mixed> $argumentos */
    private function ler(array $argumentos): RespostaDaFerramenta
    {
        $contexto = $this->portao()->abrir((int) $this->pessoa->getId(), (int) $this->escritorio->getId());
        $chamada = new ChamadaDoConector($contexto, 41, 42, 'claude.ai', ['mcp:leitura'], new \DateTimeImmutable('+7 days'));

        return static::getContainer()->get(LerPastaFerramenta::class)->executar($argumentos, $chamada);
    }

    /** @param array<string, mixed> $argumentos */
    private function assertMotivo(array $argumentos, string $motivo): void
    {
        try {
            $this->ler($argumentos);
        } catch (ErroDaFerramenta $e) {
            self::assertSame($motivo, $e->motivo);

            return;
        }
        self::fail('deveria recusar com ' . $motivo);
    }

    private function pasta(?Tenant $escritorio = null, bool $comSegredo = false): Pasta
    {
        $escritorio ??= $this->escritorio;
        $pasta = (new Pasta())->setNup('LER-' . uniqid());
        $pasta->setTenant($escritorio);
        $this->em()->persist($pasta);
        if ($comSegredo) {
            $processo = new Processo();
            $processo->setNumeroProcesso('07011345720258070001' . uniqid());
            $processo->setSiglaTribunal('TJDFT');
            $processo->setClasseProcessual('Procedimento Comum');
            $processo->setAssuntoProcessual('Assunto');
            $processo->setTenant($escritorio);
            $processo->setNivelSigilo(1);
            $this->em()->persist($processo);
            $vinculo = new PastaProcesso($pasta, $processo);
            $this->em()->persist($vinculo);
            $pasta->getPastaProcessos()->add($vinculo);
        }
        $this->em()->flush();

        return $pasta;
    }

    private function mensagem(Pasta $pasta, string $conteudo): PastaMensagem
    {
        $mensagem = (new PastaMensagem())->setPasta($pasta)->setTenant($this->escritorio)->setAutor($this->colega)->setConteudo($conteudo);
        $this->em()->persist($mensagem);
        $this->em()->flush();

        return $mensagem;
    }

    private function prazo(Pasta $pasta, string $titulo, string $quando, ?string $responsavel = null): PastaPrazo
    {
        $prazo = new PastaPrazo($this->escritorio, $pasta, $this->colega);
        $prazo->definir(PastaPrazo::TIPO_PRAZO, $titulo, new \DateTimeImmutable($quando), null, $responsavel);
        $this->em()->persist($prazo);
        $this->em()->flush();

        return $prazo;
    }

    private function ocultar(Pasta $pasta, AbaDaPasta $aba): void
    {
        $this->em()->persist(AbaOcultaDaPasta::paraTodaAEquipe($this->escritorio, $pasta, $aba, $this->colega, new \DateTimeImmutable()));
        $this->em()->flush();
    }

    /** Pasta com um processo público vinculado (o `pasta()` só cria processo em segredo). */
    private function pastaComProcesso(string $cnj = self::CNJ, ?Tenant $escritorio = null): Pasta
    {
        $escritorio ??= $this->escritorio;
        $pasta = $this->pasta($escritorio);
        $processo = new Processo();
        $processo->setNumeroProcesso($cnj);
        $processo->setSiglaTribunal('TJDFT');
        $processo->setClasseProcessual('Procedimento Comum');
        $processo->setAssuntoProcessual('Assunto');
        $processo->setTenant($escritorio);
        $this->em()->persist($processo);
        $vinculo = new PastaProcesso($pasta, $processo);
        $this->em()->persist($vinculo);
        $pasta->getPastaProcessos()->add($vinculo);
        $this->em()->flush();

        return $pasta;
    }

    /** Uma publicação do Diário (não lida) do processo de número `$cnj`, no escritório `$escritorio`. */
    private function publicacao(string $cnj, string $texto, string $data = '2026-11-10', ?Tenant $escritorio = null): PublicacaoDjen
    {
        $publicacao = (new PublicacaoDjen())
            ->setTenant($escritorio ?? $this->escritorio)
            ->setDjenId((string) random_int(100_000_000, 999_999_999_999))
            ->setNumeroProcesso((string) preg_replace('/\D/', '', $cnj))
            ->setNumeroProcessoComMascara($cnj)
            ->setSiglaTribunal('TJDFT')
            ->setTipoComunicacao('Intimação')
            ->setNomeOrgao('1ª Vara Cível')
            ->setDataDisponibilizacao(new \DateTimeImmutable($data))
            ->setTexto($texto);
        $this->em()->persist($publicacao);
        $this->em()->flush();

        return $publicacao;
    }

    /** Quantas publicações do banco estão marcadas como lidas (a contagem vem do banco, não da memória). */
    private function totalDeLidas(): int
    {
        $unidade = $this->em()->getUnitOfWork();
        $unidade->computeChangeSets();
        self::assertSame([], $unidade->getScheduledEntityUpdates(), 'nada agendado para gravar, nem lida');
        self::assertSame([], $unidade->getScheduledEntityInsertions());
        self::assertSame([], $unidade->getScheduledEntityDeletions());
        $this->em()->clear();

        return (int) $this->em()->getConnection()->fetchOne('SELECT COUNT(*) FROM publicacao_djen WHERE lida = true');
    }
}
