<?php

declare(strict_types=1);

namespace App\Tests\Conector\Integration;

use App\Conector\DTO\ChamadaDoConector;
use App\Conector\DTO\RespostaDaFerramenta;
use App\Conector\Exception\ErroDaFerramenta;
use App\Conector\Ferramenta\LerProcessosFerramenta;
use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Pasta\Entity\AbaDaPasta;
use App\Pasta\Entity\AbaOcultaDaPasta;
use App\Pasta\Entity\CadeadoDaPasta;
use App\Pasta\Entity\ModoDoCadeado;
use App\Pasta\Entity\Pasta;
use App\Pasta\Entity\PastaProcesso;
use App\Processo\Entity\MovimentacaoProcesso;
use App\Processo\Entity\NotaTecnica;
use App\Processo\Entity\ParteProcesso;
use App\Processo\Entity\Processo;
use App\Processo\Enum\MotivoFalhaDatajud;
use App\Processo\Exception\ConsultaDatajudException;
use App\Processo\Exception\TribunalNaoIdentificadoException;
use App\Processo\Service\DatajudClient;
use App\Tests\Conector\Support\CenarioDoConectorTrait;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\MockObject\MockObject;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * `ler_processos` com o banco e o portão de verdade (spec mcp-remoto-oauth, `ler_processos` e 6.2): buscar, detalhar e
 * para_vincular no escritório da chamada, com o processo oculto pelo cadeado fora, o outro escritório como "não
 * encontrado", o segredo (a lista conta, o id recusa), a pasta que a pessoa não vê fora das vinculadas, as
 * movimentações em páginas e a máscara aplicada antes do corte.
 */
#[CoversClass(LerProcessosFerramenta::class)]
final class LerProcessosFerramentaTest extends KernelTestCase
{
    use CenarioDoConectorTrait;

    private const CPF = '529.982.247-25';
    private const CNJ = '0701134-57.2025.8.07.0007';
    private const LEITOR = ['modules.processos.view', 'resources.processo.view', 'resources.pasta.view'];

    private Tenant $escritorio;
    private User $pessoa;
    private User $colega;
    private int $seq = 0;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->escritorio = $this->escritorio();
        $this->pessoa = $this->pessoa();
        $this->vinculo($this->pessoa, $this->escritorio, permissoes: [...self::LEITOR, 'resources.pasta.edit']);
        $this->colega = $this->pessoa();
        $this->colega->setFullName('Colega da Equipe');
        $this->vinculo($this->colega, $this->escritorio, permissoes: self::LEITOR);
    }

    protected function tearDown(): void
    {
        static::getContainer()->get('services_resetter')->reset();
        parent::tearDown();
    }

    #[TestDox('buscar: acha pelo termo no escritório; o de outro escritório não sai; o em segredo sai e conta; a lista tem só o que a tela mostra, sem parte')]
    public function testBuscarPeloTermo(): void
    {
        $comum = $this->processo(classe: 'Classe Buscada Alfa');
        $this->parte($comum, 'Maria da Parte ' . self::CPF, '11122233344');
        $secreto = $this->processo(classe: 'Classe Buscada Alfa', nivelSigilo: 1);
        $deOutro = $this->processo(classe: 'Classe Buscada Alfa', escritorio: $this->escritorio('Outro'));

        $resposta = $this->ler(['acao' => 'buscar', 'termo' => 'Classe Buscada Alfa']);

        self::assertSame([$comum->getId()], array_column($resposta->dados['processos'], 'id'));
        self::assertSame(2, $resposta->dados['total'], 'o em segredo conta no total, como a tela');
        self::assertSame(1, $resposta->dados['omitidos_em_segredo']);
        self::assertNull($resposta->proximaPagina);
        $json = json_encode($resposta->dados, \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_UNICODE);
        self::assertStringNotContainsString($secreto->getNumeroProcesso(), $json);
        self::assertStringNotContainsString($deOutro->getNumeroProcesso(), $json);
        self::assertStringNotContainsString('11122233344', $json, 'o documento da parte nunca sai');
        self::assertStringNotContainsString(self::CPF, $json);
        self::assertStringNotContainsString('MARIA DA PARTE', $json, 'a lista da tela não mostra parte');
        self::assertSame(['id', 'numero', 'tribunal', 'classe', 'assunto', 'situacao'], array_keys($resposta->dados['processos'][0]));
        self::assertSame(['processo' => [$comum->getId()]], $resposta->ids);
        self::assertSame(['processos' => 1, 'total' => 2, 'em_segredo' => 1], $resposta->contagens);
    }

    #[TestDox('buscar: o número CNJ, com ou sem pontuação, acha só o do escritório; em segredo, não acha e conta')]
    public function testBuscarPeloNumero(): void
    {
        $processo = $this->processo(numero: '07011345720258070007');
        $this->processo(numero: '07011345720258070007', escritorio: $this->escritorio('Outro'));
        $secreto = $this->processo(numero: '07011345720258070008', nivelSigilo: 1);

        $pontuado = $this->ler(['acao' => 'buscar', 'termo' => self::CNJ]);
        self::assertSame([$processo->getId()], array_column($pontuado->dados['processos'], 'id'));
        self::assertSame(self::CNJ, $this->formatar($pontuado->dados['processos'][0]['numero']));

        static::getContainer()->get('services_resetter')->reset();
        $puro = $this->ler(['acao' => 'buscar', 'termo' => '07011345720258070007']);
        self::assertSame([$processo->getId()], array_column($puro->dados['processos'], 'id'));

        static::getContainer()->get('services_resetter')->reset();
        $segredo = $this->ler(['acao' => 'buscar', 'termo' => $secreto->getNumeroProcesso()]);
        self::assertSame([], $segredo->dados['processos']);
        self::assertSame(1, $segredo->dados['omitidos_em_segredo']);
    }

    #[TestDox('buscar: o processo cujas pastas são todas ocultas para a pessoa não aparece nem conta, como a tela (K3)')]
    public function testBuscarSemOsOcultos(): void
    {
        $visivel = $this->processo(classe: 'Classe Oculta Beta');
        $oculto = $this->processo(classe: 'Classe Oculta Beta');
        $this->pastaDo($oculto, trancada: true);

        $resposta = $this->ler(['acao' => 'buscar', 'termo' => 'Classe Oculta Beta']);

        self::assertSame([$visivel->getId()], array_column($resposta->dados['processos'], 'id'));
        self::assertSame(1, $resposta->dados['total']);
    }

    #[TestDox('buscar: 26 processos dão duas páginas, e proxima_pagina leva à segunda sem repetir')]
    public function testBuscarPagina(): void
    {
        for ($i = 0; $i < 26; ++$i) {
            $this->processo(classe: 'Classe Paginada Gama');
        }

        $primeira = $this->ler(['acao' => 'buscar', 'termo' => 'Classe Paginada Gama']);
        self::assertCount(25, $primeira->dados['processos']);
        self::assertSame(26, $primeira->dados['total']);
        self::assertSame(2, $primeira->proximaPagina);

        static::getContainer()->get('services_resetter')->reset();
        $segunda = $this->ler(['acao' => 'buscar', 'termo' => 'Classe Paginada Gama', 'pagina' => $primeira->proximaPagina]);
        self::assertCount(1, $segunda->dados['processos']);
        self::assertNull($segunda->proximaPagina);
        self::assertSame([], array_intersect(array_column($primeira->dados['processos'], 'id'), array_column($segunda->dados['processos'], 'id')));
    }

    #[TestDox('sem o módulo processos, as três ações são sem permissão')]
    public function testSemModulo(): void
    {
        $sem = $this->pessoa();
        $this->vinculo($sem, $this->escritorio, permissoes: ['resources.pasta.view', 'resources.processo.view']);
        $processo = $this->processo();
        $pasta = $this->pasta();

        foreach ([['acao' => 'buscar'], ['acao' => 'detalhar', 'processo_id' => $processo->getId()], ['acao' => 'para_vincular', 'pasta_id' => $pasta->getId()]] as $pedido) {
            $this->assertMotivo($pedido, ErroDaFerramenta::MOTIVO_SEM_PERMISSAO, $sem);
        }
    }

    #[TestDox('cnj é do PR 13 e dá pedido inválido; campo de outra ação e valor de tipo errado também')]
    public function testPedidosInvalidos(): void
    {
        $this->assertMotivo(['acao' => 'cnj', 'numero' => self::CNJ], ErroDaFerramenta::MOTIVO_ENTRADA_INVALIDA);
        $this->assertMotivo(['acao' => 'cnj'], ErroDaFerramenta::MOTIVO_ENTRADA_INVALIDA);
        $this->assertMotivo(['acao' => 'buscar', 'pasta_id' => 1], ErroDaFerramenta::MOTIVO_ENTRADA_INVALIDA);
        $this->assertMotivo(['acao' => 'detalhar'], ErroDaFerramenta::MOTIVO_ENTRADA_INVALIDA);
        $this->assertMotivo(['acao' => 'detalhar', 'processo_id' => 'abc'], ErroDaFerramenta::MOTIVO_ENTRADA_INVALIDA);
        $this->assertMotivo(['acao' => 'buscar', 'termo' => ['x']], ErroDaFerramenta::MOTIVO_ENTRADA_INVALIDA);
        $this->assertMotivo(['acao' => 'buscar', 'pagina' => 0], ErroDaFerramenta::MOTIVO_ENTRADA_INVALIDA);
    }

    #[TestDox('detalhar: dados, partes pelo nome, movimentações, notas técnicas e só o número das pastas vinculadas')]
    public function testDetalharCaminhoFeliz(): void
    {
        $processo = $this->processo(numero: '07011345720258070007', classe: 'Procedimento Comum');
        $this->parte($processo, 'Autor Fulano', '52998224725');
        $this->movimentacao($processo, 'Juntada de petição', '2026-10-10');
        $this->nota($processo, '<p>Nota técnica <b>importante</b></p><p>Segunda linha</p>');
        $pasta = $this->pastaDo($processo);

        $resposta = $this->ler(['acao' => 'detalhar', 'processo_id' => $processo->getId()]);

        $d = $resposta->dados;
        self::assertSame('07011345720258070007', $d['processo']['numero']);
        self::assertSame('PROCEDIMENTO COMUM', $d['processo']['classe']);
        self::assertSame(['AUTOR FULANO'], array_column($d['processo']['partes'], 'nome'));
        self::assertSame('JUNTADA DE PETIÇÃO', $d['movimentacoes']['itens'][0]['descricao']);
        self::assertSame('2026-10-10', $d['movimentacoes']['itens'][0]['data']);
        self::assertSame(1, \count($d['notas_tecnicas']));
        self::assertSame("Nota técnica importante\nSegunda linha", $d['notas_tecnicas'][0]['texto']);
        self::assertSame([['pasta_id' => $pasta->getId(), 'numero' => $pasta->getNup(), 'principal' => false]], $d['pastas_vinculadas']['numeros']);
        self::assertStringNotContainsString('52998224725', json_encode($d, \JSON_THROW_ON_ERROR));
        self::assertNull($resposta->proximaPagina);
        self::assertSame(['processo' => [$processo->getId()], 'pasta' => [$pasta->getId()]], $resposta->ids);
    }

    #[TestDox('detalhar: 21 movimentações dão duas páginas, a mais recente primeiro, sem repetir')]
    public function testDetalharPaginaDasMovimentacoes(): void
    {
        $processo = $this->processo();
        for ($i = 1; $i <= 21; ++$i) {
            $this->movimentacao($processo, 'Movimento ' . $i, sprintf('2026-09-%02d', $i));
        }

        $primeira = $this->ler(['acao' => 'detalhar', 'processo_id' => $processo->getId()]);
        $itens = $primeira->dados['movimentacoes']['itens'];
        self::assertCount(20, $itens);
        self::assertSame('MOVIMENTO 21', $itens[0]['descricao']);
        self::assertSame('MOVIMENTO 2', $itens[19]['descricao']);
        self::assertSame(2, $primeira->proximaPagina);

        static::getContainer()->get('services_resetter')->reset();
        $segunda = $this->ler(['acao' => 'detalhar', 'processo_id' => $processo->getId(), 'pagina' => $primeira->proximaPagina]);
        self::assertSame(['MOVIMENTO 1'], array_column($segunda->dados['movimentacoes']['itens'], 'descricao'));
        self::assertNull($segunda->proximaPagina);
    }

    #[TestDox('detalhar: outro escritório, inexistente e oculto pelo cadeado dão "não encontrado"; em segredo, a recusa do segredo')]
    public function testDetalharRecusas(): void
    {
        $deOutro = $this->processo(escritorio: $this->escritorio('Outro'));
        $oculto = $this->processo();
        $this->pastaDo($oculto, trancada: true);
        $secreto = $this->processo(nivelSigilo: 1);

        $this->assertMotivo(['acao' => 'detalhar', 'processo_id' => $deOutro->getId()], ErroDaFerramenta::MOTIVO_NAO_ENCONTRADO);
        $this->assertMotivo(['acao' => 'detalhar', 'processo_id' => 999999999], ErroDaFerramenta::MOTIVO_NAO_ENCONTRADO);
        $this->assertMotivo(['acao' => 'detalhar', 'processo_id' => $oculto->getId()], ErroDaFerramenta::MOTIVO_NAO_ENCONTRADO);
        $this->assertMotivo(['acao' => 'detalhar', 'processo_id' => $secreto->getId()], ErroDaFerramenta::MOTIVO_EM_SEGREDO);
    }

    #[TestDox('detalhar: sem o recurso de ver processo é "não encontrado", antes de dizer que está em segredo')]
    public function testDetalharSemORecursoDeVer(): void
    {
        $sem = $this->pessoa();
        $this->vinculo($sem, $this->escritorio, permissoes: ['modules.processos.view']);
        $secreto = $this->processo(nivelSigilo: 1);

        $this->assertMotivo(['acao' => 'detalhar', 'processo_id' => $secreto->getId()], ErroDaFerramenta::MOTIVO_NAO_ENCONTRADO, $sem);
    }

    #[TestDox('detalhar: a pasta oculta, a na lixeira e a em segredo por outro processo não aparecem; as duas contam à parte')]
    public function testDetalharPastasVinculadasQueAPessoaNaoVe(): void
    {
        $processo = $this->processo();
        $visivel = $this->pastaDo($processo);
        $trancada = $this->pastaDo($processo, trancada: true);
        $naLixeira = $this->pastaDo($processo);
        $naLixeira->marcarExcluida($this->colega, new \DateTimeImmutable());
        $emSegredo = $this->pastaDo($processo);
        $this->vincular($emSegredo, $this->processo(nivelSigilo: 1));
        $this->em()->flush();

        $resposta = $this->ler(['acao' => 'detalhar', 'processo_id' => $processo->getId()]);

        $pastas = $resposta->dados['pastas_vinculadas'];
        self::assertSame([$visivel->getId()], array_column($pastas['numeros'], 'pasta_id'));
        self::assertSame(1, $pastas['omitidas_em_segredo']);
        self::assertSame(1, $pastas['omitidas_sem_acesso'], 'só a trancada; a da lixeira nem conta');
        $json = json_encode($resposta->dados, \JSON_THROW_ON_ERROR);
        foreach ([$trancada, $naLixeira, $emSegredo] as $fora) {
            self::assertStringNotContainsString((string) $fora->getNup(), $json);
        }
    }

    #[TestDox('máscara antes do corte: o CPF no limite do corte da nota e do complemento não sobra pela metade')]
    public function testMascaraNoLimiteDoCorte(): void
    {
        $processo = $this->processo();
        $movimentacao = $this->movimentacao($processo, 'Descrição ' . self::CPF, '2026-10-01');
        $movimentacao->setComplementos([['nome' => str_repeat('x', LerProcessosFerramenta::TAMANHO_DO_COMPLEMENTO - 8) . ' ' . self::CPF . ' fim']]);
        $this->pastaDo($processo);
        $this->nota($processo, '<p>' . str_repeat('y', LerProcessosFerramenta::TAMANHO_DA_NOTA - 8) . ' ' . self::CPF . ' fim</p>');
        $this->em()->flush();

        $resposta = $this->ler(['acao' => 'detalhar', 'processo_id' => $processo->getId()]);

        $json = json_encode($resposta->dados, \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_UNICODE);
        self::assertStringNotContainsString(self::CPF, $json);
        self::assertStringNotContainsString('529.982', $json, 'nenhum pedaço do CPF sobra no corte');
        self::assertStringNotContainsString('247-25', $json);
        $nota = $resposta->dados['notas_tecnicas'][0];
        self::assertTrue($nota['cortado'] || str_contains($nota['texto'], '[CPF]'));
        self::assertStringContainsString('[CPF]', $resposta->dados['movimentacoes']['itens'][0]['descricao']);
        self::assertLessThanOrEqual(LerProcessosFerramenta::TAMANHO_DA_NOTA + 1, mb_strlen($nota['texto']));
    }

    #[TestDox('para_vincular: lista os processos do escritório que cabem na pasta, sem o já vinculado, o em segredo (conta) e o de outro escritório; a origem não traz o número da pasta')]
    public function testParaVincular(): void
    {
        $pasta = $this->pasta();
        $jaVinculado = $this->processo(classe: 'Classe Vincular Delta');
        $this->vincular($pasta, $jaVinculado);
        $livre = $this->processo(classe: 'Classe Vincular Delta');
        $naOutraPasta = $this->processo(classe: 'Classe Vincular Delta');
        $outra = $this->pastaDo($naOutraPasta);
        $secreto = $this->processo(classe: 'Classe Vincular Delta', nivelSigilo: 1);
        $deOutro = $this->processo(classe: 'Classe Vincular Delta', escritorio: $this->escritorio('Outro'));

        $resposta = $this->ler(['acao' => 'para_vincular', 'pasta_id' => $pasta->getId(), 'termo' => 'Classe Vincular Delta']);

        $porId = array_column($resposta->dados['processos'], null, 'id');
        self::assertEqualsCanonicalizing([$livre->getId(), $naOutraPasta->getId()], array_keys($porId));
        self::assertSame(1, $resposta->dados['omitidos_em_segredo']);
        self::assertSame('push', $porId[$livre->getId()]['origem']);
        self::assertSame('pasta', $porId[$naOutraPasta->getId()]['origem']);
        $json = json_encode($resposta->dados, \JSON_THROW_ON_ERROR);
        foreach ([$secreto->getNumeroProcesso(), $deOutro->getNumeroProcesso(), $jaVinculado->getNumeroProcesso(), (string) $outra->getNup()] as $fora) {
            self::assertStringNotContainsString($fora, $json);
        }
        self::assertEqualsCanonicalizing([$livre->getId(), $naOutraPasta->getId()], $resposta->ids['processo']);
    }

    #[TestDox('detalhar: sem pasta vinculada que a pessoa veja fora do segredo, as notas técnicas não saem e o campo diz que não estão disponíveis')]
    public function testDetalharNotasSemPastaVisivel(): void
    {
        $semPasta = $this->processo();
        $this->nota($semPasta, '<p>Nota que a tela não mostraria</p>');
        $naSegredo = $this->processo();
        $this->nota($naSegredo, '<p>Nota na pasta em segredo</p>');
        $pastaEmSegredo = $this->pastaDo($naSegredo);
        $this->vincular($pastaEmSegredo, $this->processo(nivelSigilo: 1));

        foreach ([$semPasta, $naSegredo] as $processo) {
            $resposta = $this->ler(['acao' => 'detalhar', 'processo_id' => $processo->getId()]);
            self::assertSame([], $resposta->dados['notas_tecnicas']);
            self::assertFalse($resposta->dados['notas_tecnicas_disponiveis']);
            self::assertStringNotContainsString('Nota', json_encode($resposta->dados, \JSON_THROW_ON_ERROR));
            static::getContainer()->get('services_resetter')->reset();
        }
    }

    #[TestDox('detalhar: com a aba Processo oculta em todas as pastas visíveis, as notas técnicas não saem; com uma pasta com a aba visível, saem')]
    public function testDetalharNotasDependemDaAbaProcesso(): void
    {
        $soOculta = $this->processo();
        $this->nota($soOculta, '<p>Nota da aba Processo</p>');
        $oculta = $this->pastaDo($soOculta);
        $comUmaVisivel = $this->processo();
        $this->nota($comUmaVisivel, '<p>Nota da aba Processo</p>');
        $outraOculta = $this->pastaDo($comUmaVisivel);
        $this->pastaDo($comUmaVisivel);
        foreach ([$oculta, $outraOculta] as $pasta) {
            $this->em()->persist(AbaOcultaDaPasta::paraTodaAEquipe($this->escritorio, $pasta, AbaDaPasta::Processo, $this->colega, new \DateTimeImmutable()));
        }
        $this->em()->flush();

        $semAba = $this->ler(['acao' => 'detalhar', 'processo_id' => $soOculta->getId()]);
        self::assertSame([], $semAba->dados['notas_tecnicas']);
        self::assertFalse($semAba->dados['notas_tecnicas_disponiveis']);
        static::getContainer()->get('services_resetter')->reset();

        $visivel = $this->ler(['acao' => 'detalhar', 'processo_id' => $comUmaVisivel->getId()]);
        self::assertTrue($visivel->dados['notas_tecnicas_disponiveis']);
        self::assertSame(['Nota da aba Processo'], array_column($visivel->dados['notas_tecnicas'], 'texto'));
    }

    #[TestDox('para_vincular: quem só tem ver na pasta (sem editar) recebe "não encontrado", como a tela recusa a busca')]
    public function testParaVincularSoComVer(): void
    {
        $pasta = $this->pasta();
        $this->processo(classe: 'Classe Vincular Epsilon');

        $this->assertMotivo(['acao' => 'para_vincular', 'pasta_id' => $pasta->getId(), 'termo' => 'Classe Vincular Epsilon'], ErroDaFerramenta::MOTIVO_NAO_ENCONTRADO, $this->colega);
    }

    #[TestDox('para_vincular: pasta de outro escritório, na lixeira, trancada ou com a aba Processo oculta é "não encontrado"; em segredo, a recusa')]
    public function testParaVincularGuardaDaPasta(): void
    {
        $deOutro = $this->pasta($this->escritorio('Outro'));
        $naLixeira = $this->pasta();
        $naLixeira->marcarExcluida($this->colega, new \DateTimeImmutable());
        $trancada = $this->pasta();
        $this->em()->persist(new CadeadoDaPasta($this->escritorio, $trancada, ModoDoCadeado::Todos, null, new \DateTimeImmutable()));
        $semAba = $this->pasta();
        $this->em()->persist(AbaOcultaDaPasta::paraTodaAEquipe($this->escritorio, $semAba, AbaDaPasta::Processo, $this->colega, new \DateTimeImmutable()));
        $secreta = $this->pasta();
        $this->vincular($secreta, $this->processo(nivelSigilo: 1));
        $this->em()->flush();

        foreach ([$deOutro, $naLixeira, $trancada, $semAba] as $pasta) {
            $this->assertMotivo(['acao' => 'para_vincular', 'pasta_id' => $pasta->getId()], ErroDaFerramenta::MOTIVO_NAO_ENCONTRADO);
        }
        $this->assertMotivo(['acao' => 'para_vincular', 'pasta_id' => 999999999], ErroDaFerramenta::MOTIVO_NAO_ENCONTRADO);
        $this->assertMotivo(['acao' => 'para_vincular', 'pasta_id' => $secreta->getId()], ErroDaFerramenta::MOTIVO_EM_SEGREDO);
    }

    #[TestDox('cnj: acha no CNJ um número que o escritório não tem: dados, partes só pelo nome e CPF mascarado, movimentações recentes, sem processo_id; nada é gravado')]
    public function testCnjAchou(): void
    {
        $this->datajudRetorna(['hits' => ['hits' => [['_source' => $this->fonteDoCnj()]]]]);
        $pasta = $this->pasta();
        $antes = $this->em()->getConnection()->fetchOne('SELECT COUNT(*) FROM processo');

        $resposta = $this->ler(['acao' => 'cnj', 'numero_processo' => self::CNJ, 'pasta_id' => $pasta->getId()]);

        self::assertSame('achou', $resposta->dados['desfecho']);
        self::assertFalse($resposta->dados['ja_no_escritorio']);
        self::assertNull($resposta->dados['processo_id']);
        self::assertSame('07011345720258070007', $resposta->dados['processo']['numero']);
        self::assertSame('TJDFT', $resposta->dados['processo']['tribunal']);
        self::assertSame(['tipo', 'papel', 'nome'], array_keys($resposta->dados['processo']['partes'][0]));
        $json = json_encode($resposta->dados, \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_UNICODE);
        self::assertStringNotContainsString('99988877766', $json, 'o documento da parte nunca sai');
        self::assertStringNotContainsString(self::CPF, $json);
        self::assertSame(['MOVIMENTO RECENTE', 'MOVIMENTO ANTIGO'], array_column($resposta->dados['movimentacoes'], 'descricao'));
        self::assertSame(['encontrados' => 1, 'movimentacoes' => 2], $resposta->contagens);
        self::assertSame($antes, $this->em()->getConnection()->fetchOne('SELECT COUNT(*) FROM processo'));
    }

    #[TestDox('cnj: o número que já é de um processo do escritório volta com processo_id; o oculto pelo cadeado para a pessoa não é revelado')]
    public function testCnjJaNoEscritorio(): void
    {
        $this->datajudRetorna(['hits' => ['hits' => [['_source' => $this->fonteDoCnj()]]]]);
        $processo = $this->processo(numero: '07011345720258070007');
        $pasta = $this->pasta();

        $resposta = $this->ler(['acao' => 'cnj', 'numero_processo' => self::CNJ, 'pasta_id' => $pasta->getId()]);
        self::assertTrue($resposta->dados['ja_no_escritorio']);
        self::assertSame($processo->getId(), $resposta->dados['processo_id']);
        self::assertSame(['processo' => [$processo->getId()]], $resposta->ids);

        $this->pastaDo($processo, trancada: true);
        static::getContainer()->get('services_resetter')->reset();
        $oculto = $this->ler(['acao' => 'cnj', 'numero_processo' => self::CNJ, 'pasta_id' => $pasta->getId()]);
        self::assertFalse($oculto->dados['ja_no_escritorio']);
        self::assertNull($oculto->dados['processo_id']);
    }

    #[TestDox('cnj: número inválido, não encontrado e falha do CNJ voltam como desfecho e mensagem, sem erro do pedido')]
    public function testCnjOutrosDesfechos(): void
    {
        $this->datajudComo(static function (string $numero): array {
            if ($numero === '07011345820258070007') {
                return ['hits' => ['hits' => []]];
            }
            if (\strlen($numero) !== 20) {
                throw new TribunalNaoIdentificadoException('fora do padrão');
            }
            throw new ConsultaDatajudException(MotivoFalhaDatajud::Indisponivel);
        });
        $pasta = $this->pasta()->getId();

        $invalido = $this->ler(['acao' => 'cnj', 'numero_processo' => '123', 'pasta_id' => $pasta]);
        self::assertSame('numero_invalido', $invalido->dados['desfecho']);
        self::assertStringContainsString('3 dígito(s)', $invalido->dados['mensagem']);
        static::getContainer()->get('services_resetter')->reset();

        $naoAchou = $this->ler(['acao' => 'cnj', 'numero_processo' => '07011345820258070007', 'pasta_id' => $pasta]);
        self::assertSame('nao_encontrado', $naoAchou->dados['desfecho']);
        self::assertStringContainsString('dígito verificador', $naoAchou->dados['mensagem']);
        static::getContainer()->get('services_resetter')->reset();

        $falhou = $this->ler(['acao' => 'cnj', 'numero_processo' => self::CNJ, 'pasta_id' => $pasta]);
        self::assertSame('falha', $falhou->dados['desfecho']);
        self::assertSame(MotivoFalhaDatajud::Indisponivel->mensagemUsuario('TJDFT'), $falhou->dados['mensagem']);
        self::assertSame(1, $falhou->contagens['falhou']);
    }

    #[TestDox('cnj: o número de processo em segredo do escritório recusa (6.2.3) e o CNJ NÃO é chamado; o segredo de outro escritório não vale; o sigilo vindo do CNJ também recusa')]
    public function testCnjSegredoNaoChamaOCnj(): void
    {
        $cliente = $this->createMock(DatajudClient::class);
        $cliente->expects(self::once())->method('searchByNumeroProcesso')->willReturn(['hits' => ['hits' => [['_source' => $this->fonteDoCnj(sigilo: 2)]]]]);
        static::getContainer()->set(DatajudClient::class, $cliente);
        $pasta = $this->pasta()->getId();
        $this->processo(numero: '07011345720258070008', nivelSigilo: 1);
        $this->processo(numero: '07011345720258070007', nivelSigilo: 1, escritorio: $this->escritorio('Outro'));

        $this->assertMotivo(['acao' => 'cnj', 'numero_processo' => '0701134-57.2025.8.07.0008', 'pasta_id' => $pasta], ErroDaFerramenta::MOTIVO_EM_SEGREDO);
        // O segredo de outro escritório não bloqueia; é o CNJ (1 chamada permitida) que diz o sigilo.
        $this->assertMotivo(['acao' => 'cnj', 'numero_processo' => self::CNJ, 'pasta_id' => $pasta], ErroDaFerramenta::MOTIVO_EM_SEGREDO);
    }

    #[TestDox('cnj: segredo no escritório recusa antes de qualquer chamada (never)')]
    public function testCnjSegredoSemNenhumaChamada(): void
    {
        $cliente = $this->createMock(DatajudClient::class);
        $cliente->expects(self::never())->method('searchByNumeroProcesso');
        static::getContainer()->set(DatajudClient::class, $cliente);
        $pasta = $this->pasta()->getId();
        $this->processo(numero: '07011345720258070007', nivelSigilo: 1);

        $this->assertMotivo(['acao' => 'cnj', 'numero_processo' => self::CNJ, 'pasta_id' => $pasta], ErroDaFerramenta::MOTIVO_EM_SEGREDO);
    }

    #[TestDox('cnj: quem só vê a pasta, a pasta de outro escritório, na lixeira ou trancada, e a aba Processo oculta recusam sem chamar o CNJ')]
    public function testCnjGuardaDaPasta(): void
    {
        $cliente = $this->createMock(DatajudClient::class);
        $cliente->expects(self::never())->method('searchByNumeroProcesso');
        static::getContainer()->set(DatajudClient::class, $cliente);
        $pasta = $this->pasta();
        $deOutro = $this->pasta($this->escritorio('Outro'));
        $naLixeira = $this->pasta();
        $naLixeira->marcarExcluida($this->colega, new \DateTimeImmutable());
        $trancada = $this->pasta();
        $this->em()->persist(new CadeadoDaPasta($this->escritorio, $trancada, ModoDoCadeado::Todos, null, new \DateTimeImmutable()));
        $semAba = $this->pasta();
        $this->em()->persist(AbaOcultaDaPasta::paraTodaAEquipe($this->escritorio, $semAba, AbaDaPasta::Processo, $this->colega, new \DateTimeImmutable()));
        $this->em()->flush();

        $this->assertMotivo(['acao' => 'cnj', 'numero_processo' => self::CNJ, 'pasta_id' => $pasta->getId()], ErroDaFerramenta::MOTIVO_NAO_ENCONTRADO, $this->colega);
        foreach ([$deOutro, $naLixeira, $trancada, $semAba] as $recusada) {
            $this->assertMotivo(['acao' => 'cnj', 'numero_processo' => self::CNJ, 'pasta_id' => $recusada->getId()], ErroDaFerramenta::MOTIVO_NAO_ENCONTRADO);
        }
        $this->assertMotivo(['acao' => 'cnj', 'numero_processo' => self::CNJ, 'pasta_id' => 999999999], ErroDaFerramenta::MOTIVO_NAO_ENCONTRADO);
    }

    #[TestDox('cnj: pedido inválido (sem número, sem pasta, número não texto, vazio ou comprido) recusa sem chamar o CNJ')]
    public function testCnjPedidoInvalido(): void
    {
        $cliente = $this->createMock(DatajudClient::class);
        $cliente->expects(self::never())->method('searchByNumeroProcesso');
        static::getContainer()->set(DatajudClient::class, $cliente);
        $pasta = $this->pasta()->getId();

        foreach ([
            ['acao' => 'cnj', 'pasta_id' => $pasta],
            ['acao' => 'cnj', 'numero_processo' => self::CNJ],
            ['acao' => 'cnj', 'numero_processo' => 7011345720258070007, 'pasta_id' => $pasta],
            ['acao' => 'cnj', 'numero_processo' => '  ', 'pasta_id' => $pasta],
            ['acao' => 'cnj', 'numero_processo' => str_repeat('1', 31), 'pasta_id' => $pasta],
        ] as $pedido) {
            $this->assertMotivo($pedido, ErroDaFerramenta::MOTIVO_ENTRADA_INVALIDA);
        }
    }

    /** @param array<string, mixed> $resposta */
    private function datajudRetorna(array $resposta): MockObject
    {
        $cliente = $this->createMock(DatajudClient::class);
        $cliente->method('searchByNumeroProcesso')->willReturn($resposta);
        static::getContainer()->set(DatajudClient::class, $cliente);

        return $cliente;
    }

    /** @param callable(string): array<string, mixed> $resposta */
    private function datajudComo(callable $resposta): void
    {
        $cliente = $this->createMock(DatajudClient::class);
        $cliente->method('searchByNumeroProcesso')->willReturnCallback($resposta);
        $cliente->method('contarPosterioresNoForo')->willReturn(0);
        static::getContainer()->set(DatajudClient::class, $cliente);
    }

    /** @return array<string, mixed> o `_source` do Datajud, com uma parte com CPF no nome e documento, e dois movimentos fora de ordem */
    private function fonteDoCnj(int $sigilo = 0): array
    {
        return [
            'numeroProcesso' => '07011345720258070007',
            'classe' => ['codigo' => 7, 'nome' => 'Procedimento Comum Cível'],
            'tribunal' => 'TJDFT',
            'nivelSigilo' => $sigilo,
            'id' => 'TJDFT_G1_07011345720258070007',
            'partes' => [['nome' => 'Maria da Parte ' . self::CPF, 'documento' => '99988877766', 'tipo' => 'FISICA', 'papel' => 'AUTOR']],
            'movimentos' => [
                ['nome' => 'Movimento antigo', 'dataHora' => '2025-01-10T10:00:00', 'codigo' => 1],
                ['nome' => 'Movimento recente', 'dataHora' => '2025-06-10T10:00:00', 'codigo' => 2],
            ],
        ];
    }

    /** @param array<string, mixed> $argumentos */
    private function ler(array $argumentos, ?User $pessoa = null): RespostaDaFerramenta
    {
        $pessoa ??= $this->pessoa;
        $contexto = $this->portao()->abrir((int) $pessoa->getId(), (int) $this->escritorio->getId());
        $chamada = new ChamadaDoConector($contexto, 41, 42, 'claude.ai', ['mcp:leitura'], new \DateTimeImmutable('+7 days'));

        return static::getContainer()->get(LerProcessosFerramenta::class)->executar($argumentos, $chamada);
    }

    /** @param array<string, mixed> $argumentos */
    private function assertMotivo(array $argumentos, string $motivo, ?User $pessoa = null): void
    {
        try {
            $this->ler($argumentos, $pessoa);
        } catch (ErroDaFerramenta $e) {
            self::assertSame($motivo, $e->motivo);

            return;
        } finally {
            static::getContainer()->get('services_resetter')->reset();
        }
        self::fail('deveria recusar com ' . $motivo);
    }

    private function formatar(string $numero): string
    {
        return preg_replace('/^(\d{7})(\d{2})(\d{4})(\d)(\d{2})(\d{4})$/', '$1-$2.$3.$4.$5.$6', $numero) ?? $numero;
    }

    private function processo(?string $numero = null, string $classe = 'Procedimento Comum', int $nivelSigilo = 0, ?Tenant $escritorio = null): Processo
    {
        $processo = new Processo();
        $processo->setNumeroProcesso($numero ?? sprintf('07011345720258%06d', ++$this->seq + random_int(0, 899999)));
        $processo->setSiglaTribunal('TJDFT');
        $processo->setClasseProcessual($classe);
        $processo->setAssuntoProcessual('Assunto');
        $processo->setTenant($escritorio ?? $this->escritorio);
        $processo->setNivelSigilo($nivelSigilo);
        $this->em()->persist($processo);
        $this->em()->flush();

        return $processo;
    }

    private function pasta(?Tenant $escritorio = null): Pasta
    {
        $pasta = (new Pasta())->setNup('PRC-' . uniqid());
        $pasta->setTenant($escritorio ?? $this->escritorio);
        $this->em()->persist($pasta);
        $this->em()->flush();

        return $pasta;
    }

    /** Uma pasta nova com o processo vinculado; trancada pelo cadeado (`Todos`) quando pedido. */
    private function pastaDo(Processo $processo, bool $trancada = false): Pasta
    {
        $pasta = $this->pasta();
        $this->vincular($pasta, $processo);
        if ($trancada) {
            $this->em()->persist(new CadeadoDaPasta($this->escritorio, $pasta, ModoDoCadeado::Todos, null, new \DateTimeImmutable()));
            $this->em()->flush();
        }

        return $pasta;
    }

    private function vincular(Pasta $pasta, Processo $processo): void
    {
        $vinculo = new PastaProcesso($pasta, $processo);
        $this->em()->persist($vinculo);
        $pasta->getPastaProcessos()->add($vinculo);
        $this->em()->flush();
    }

    private function parte(Processo $processo, string $nome, ?string $documento): void
    {
        $parte = (new ParteProcesso())->setTipo('FISICA')->setNome($nome)->setDocumento($documento)->setProcesso($processo)->setTenant($processo->getTenant());
        $processo->addParte($parte);
        $this->em()->persist($parte);
        $this->em()->flush();
    }

    private function movimentacao(Processo $processo, string $descricao, string $data): MovimentacaoProcesso
    {
        $movimentacao = (new MovimentacaoProcesso())->setDescricao($descricao)->setDataMovimentacao(new \DateTime($data))->setProcesso($processo)->setTenant($processo->getTenant());
        $this->em()->persist($movimentacao);
        $this->em()->flush();

        return $movimentacao;
    }

    private function nota(Processo $processo, string $html): void
    {
        $this->em()->persist(new NotaTecnica($this->escritorio, $processo, $this->colega, $html));
        $this->em()->flush();
    }
}
