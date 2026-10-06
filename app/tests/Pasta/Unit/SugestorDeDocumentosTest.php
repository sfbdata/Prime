<?php

declare(strict_types=1);

namespace App\Tests\Pasta\Unit;

use App\Pasta\DTO\DocumentoSugeridoOutput;
use App\Pasta\DTO\SugestaoDeDocumentosOutput;
use App\Pasta\Entity\Pasta;
use App\Pasta\Entity\PastaChecklistItem;
use App\Pasta\Entity\PastaDocumento;
use App\Pasta\Service\CatalogoDeDocumentos;
use App\Pasta\Service\SugestorDeDocumentos;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

/**
 * A regra do "Documentos sugeridos" (bj-docsug.js sem a leitura do processo).
 *
 * As entradas de texto vêm das entidades — `Pasta::setNomeAcao`, `PastaDocumento::setTitulo` e
 * `PastaChecklistItem::setTitulo` gravam em MAIÚSCULAS — para o teste provar que o casamento
 * sobrevive ao que o banco realmente guarda, e não ao texto bonito do catálogo.
 */
#[CoversClass(SugestorDeDocumentos::class)]
#[CoversClass(SugestaoDeDocumentosOutput::class)]
#[CoversClass(DocumentoSugeridoOutput::class)]
final class SugestorDeDocumentosTest extends TestCase
{
    private SugestorDeDocumentos $sugestor;

    protected function setUp(): void
    {
        $this->sugestor = new SugestorDeDocumentos();
    }

    private static function acao(string $texto): string
    {
        return (string) (new Pasta())->setNomeAcao($texto)->getNomeAcao();
    }

    /** @return array{titulo: string, nomeOriginal: string, categoria: string, data: string} */
    private static function arquivo(string $titulo, string $nomeOriginal = 'arquivo.pdf', string $categoria = PastaDocumento::CATEGORIA_DEMAIS): array
    {
        return [
            'titulo'       => (new PastaDocumento())->setTitulo($titulo)->getTitulo(),
            'nomeOriginal' => $nomeOriginal,
            'categoria'    => $categoria,
            'data'         => '05/10/2026',
        ];
    }

    /** @return array{titulo: string, concluido: bool} */
    private static function itemDoChecklist(string $titulo, bool $concluido = false): array
    {
        $item = (new PastaChecklistItem())->setTitulo($titulo)->setConcluido($concluido);

        return ['titulo' => $item->getTitulo(), 'concluido' => $item->isConcluido()];
    }

    /** @return array<string, DocumentoSugeridoOutput> */
    private static function porChave(SugestaoDeDocumentosOutput $s): array
    {
        $mapa = [];
        foreach ($s->itens as $item) {
            $mapa[$item->chave] = $item;
        }

        return $mapa;
    }

    #[TestDox('sem ação e sem classe processual não há catálogo — e nada é sugerido')]
    public function testSemAcaoESemClasseNaoTemCatalogo(): void
    {
        $s = $this->sugestor->sugerir(null, '   ', null, [self::arquivo('Procuração')], []);

        self::assertFalse($s->temCatalogo);
        self::assertSame([], $s->itens);
        self::assertSame([], $s->nomesDosFaltantes());
    }

    #[TestDox('ação sem classe: fase inicial presumida, e a lista é a da fase inicial na ordem do catálogo')]
    public function testAcaoSemClasseUsaFaseInicial(): void
    {
        $acao = self::acao('Ação de indenização por danos morais');
        self::assertSame('AÇÃO DE INDENIZAÇÃO POR DANOS MORAIS', $acao, 'premissa: o setter grava em maiúsculas');

        $s = $this->sugestor->sugerir($acao, null, null, [], []);

        self::assertTrue($s->temCatalogo);
        self::assertSame('inicial', $s->faseChave);
        self::assertSame('Fase inicial', $s->faseNome);
        self::assertSame(
            ['procuracao', 'identidade', 'residencia', 'inicial', 'provasFato', 'hipossuf', 'honorarios', 'notificacao'],
            array_map(static fn (DocumentoSugeridoOutput $i): string => $i->chave, $s->itens),
            'obrigatório primeiro, depois recomendáveis, depois opcionais (ordem estável do catálogo)',
        );
        self::assertSame(
            ['Procuração', 'Documento de identidade', 'Comprovante de residência', 'Petição inicial', 'Provas do fato (fotos, vídeos, laudos)'],
            $s->nomesDosFaltantes(),
            'faltantes = obrigatório + recomendáveis; opcionais não entram',
        );
    }

    #[TestDox('documento da pasta com título em maiúsculas e acento casa o tipo e sai dos faltantes')]
    public function testDocumentoPeloTituloViraJaExistente(): void
    {
        $s = $this->sugestor->sugerir(self::acao('Indenização'), null, null, [self::arquivo('Procuração ad judicia et extra')], []);

        $itens = self::porChave($s);
        self::assertSame(DocumentoSugeridoOutput::JA_EXISTE, $itens['procuracao']->status);
        self::assertSame(['Pasta: PROCURAÇÃO AD JUDICIA ET EXTRA · 05/10/2026'], $itens['procuracao']->localizadoEm);
        self::assertNotContains('Procuração', $s->nomesDosFaltantes());
        self::assertFalse($itens['procuracao']->podeIrAoChecklist());
    }

    #[TestDox('o nome do arquivo original também casa, e a categoria do upload diz o tipo mesmo com nome qualquer')]
    public function testNomeOriginalECategoriaDoUpload(): void
    {
        $s = $this->sugestor->sugerir(self::acao('Indenização'), null, null, [
            self::arquivo('Documento 1', 'comprovante de endereço.pdf'),
            self::arquivo('Doc 2', 'scan0001.pdf', PastaDocumento::CATEGORIA_IDENTIFICACAO),
            self::arquivo('Doc 3', 'scan0002.pdf', PastaDocumento::CATEGORIA_PECA),
        ], []);

        $itens = self::porChave($s);
        self::assertSame(DocumentoSugeridoOutput::JA_EXISTE, $itens['residencia']->status);
        self::assertSame(DocumentoSugeridoOutput::JA_EXISTE, $itens['identidade']->status);
        self::assertSame(DocumentoSugeridoOutput::RECOMENDAVEL, $itens['inicial']->status, 'PECA não diz que é a inicial');
    }

    #[TestDox('"contrato de honorários" é honorários, não o contrato discutido (lookahead do JS)')]
    public function testContratoDeHonorariosNaoEContratoDiscutido(): void
    {
        $s = $this->sugestor->sugerir(self::acao('Indenização'), null, null, [self::arquivo('Contrato de honorários assinado')], []);

        self::assertSame(DocumentoSugeridoOutput::JA_EXISTE, self::porChave($s)['honorarios']->status);
        self::assertSame(0, preg_match(CatalogoDeDocumentos::TIPOS['contrato'][1], SugestorDeDocumentos::normalizar('CONTRATO DE HONORÁRIOS')));
        self::assertSame(1, preg_match(CatalogoDeDocumentos::TIPOS['contrato'][1], SugestorDeDocumentos::normalizar('CONTRATO DE LOCAÇÃO')));
    }

    #[TestDox('item do checklist com nome parecido marca "já no checklist" e tira dos faltantes; concluído vira "conferido"')]
    public function testChecklistPorSimilaridade(): void
    {
        $s = $this->sugestor->sugerir(self::acao('Indenização'), null, null, [], [
            self::itemDoChecklist('Comprovante de residência atualizado', true),
            self::itemDoChecklist('procuração'),
            self::itemDoChecklist('RG e CPF'),
        ]);

        $itens = self::porChave($s);
        self::assertTrue($itens['residencia']->noChecklist);
        self::assertTrue($itens['residencia']->conferidoNoChecklist);
        self::assertTrue($itens['procuracao']->noChecklist);
        self::assertFalse($itens['procuracao']->conferidoNoChecklist);
        self::assertFalse($itens['identidade']->noChecklist, '"RG e CPF" não tem palavra em comum com "Documento de identidade" (regra do JS)');

        self::assertSame(
            ['Documento de identidade', 'Petição inicial', 'Provas do fato (fotos, vídeos, laudos)'],
            $s->nomesDosFaltantes(),
        );
    }

    #[TestDox('classe de cumprimento/execução leva à fase de cumprimento; acórdão e pagamento ficam "não aplicável" sem leitura do processo')]
    public function testClasseDeCumprimento(): void
    {
        $s = $this->sugestor->sugerir(null, 'CUMPRIMENTO DE SENTENÇA', '0001234-56.2026.8.07.0001', [], []);

        self::assertSame('cumprimento', $s->faseChave);
        self::assertSame('0001234-56.2026.8.07.0001', $s->numeroProcesso);

        $itens = self::porChave($s);
        self::assertSame(DocumentoSugeridoOutput::OBRIGATORIO, $itens['calculo']->status);
        self::assertSame(DocumentoSugeridoOutput::NAO_APLICAVEL, $itens['acordao']->status);
        self::assertSame(DocumentoSugeridoOutput::NAO_APLICAVEL, $itens['pagamento']->status);
        self::assertFalse($itens['acordao']->podeIrAoChecklist());

        $ultimo = $s->itens[\count($s->itens) - 1];
        self::assertSame(DocumentoSugeridoOutput::NAO_APLICAVEL, $ultimo->status, 'não aplicável vai para o fim');
        self::assertSame(
            ['Memória de cálculo atualizada', 'Procuração', 'Sentença', 'Certidão de trânsito em julgado', 'Petição de cumprimento de sentença'],
            $s->nomesDosFaltantes(),
        );
    }

    #[TestDox('acórdão já na pasta deixa de ser "não aplicável" e vira "já existente"')]
    public function testNaoAplicavelCedeAoQueJaEstaNaPasta(): void
    {
        $s = $this->sugestor->sugerir(null, 'Execução de título extrajudicial', null, [self::arquivo('Acórdão TJDFT')], []);

        self::assertSame('cumprimento', $s->faseChave);
        self::assertSame(DocumentoSugeridoOutput::JA_EXISTE, self::porChave($s)['acordao']->status);
    }

    #[TestDox('classe que não é de cumprimento não muda a fase: continua a inicial presumida')]
    public function testClasseComumFicaNaFaseInicial(): void
    {
        $s = $this->sugestor->sugerir(null, 'PROCEDIMENTO COMUM CÍVEL', null, [], []);

        self::assertSame('inicial', $s->faseChave);
    }

    /** @return iterable<string, array{string, list<string>}> */
    public static function acoesComAcrescimo(): iterable
    {
        yield 'saúde' => ['Obrigação de fazer — plano de saúde', ['relMedico', 'negativa']];
        yield 'imóvel' => ['Usucapião extraordinária', ['matricula']];
        yield 'posse' => ['Reintegração de posse', ['matricula']];
        yield 'sem acréscimo' => ['Indenização por danos morais', []];
    }

    /** @param list<string> $esperadas */
    #[DataProvider('acoesComAcrescimo')]
    #[TestDox('o tipo de ação acrescenta os documentos do catálogo por ação')]
    public function testAcrescimosPorTipoDeAcao(string $acao, array $esperadas): void
    {
        $s      = $this->sugestor->sugerir(self::acao($acao), null, null, [], []);
        $chaves = array_map(static fn (DocumentoSugeridoOutput $i): string => $i->chave, $s->itens);

        foreach ($esperadas as $chave) {
            self::assertContains($chave, $chaves);
        }
        foreach (array_diff(['relMedico', 'negativa', 'matricula'], $esperadas) as $chave) {
            self::assertNotContains($chave, $chaves);
        }
        self::assertSame(\count($chaves), \count(array_unique($chaves)), 'cada tipo entra uma vez só');
    }

    #[TestDox('"obrigação de fazer" não duplica a notificação, que a fase inicial já pede')]
    public function testAcrescimoNaoDuplicaOQueAFaseJaPede(): void
    {
        $s = $this->sugestor->sugerir(self::acao('Obrigação de fazer'), null, null, [], []);

        $notificacoes = array_filter($s->itens, static fn (DocumentoSugeridoOutput $i): bool => $i->chave === 'notificacao');
        self::assertCount(1, $notificacoes);
        self::assertSame('mostra tentativa prévia de solução (Fase inicial)', array_values($notificacoes)[0]->porque, 'vale a primeira entrada, a da fase');
    }

    #[TestDox('normalizar tira acento e põe em minúsculas, como o norm() do JS')]
    public function testNormalizar(): void
    {
        self::assertSame('procuracao ad judicia', SugestorDeDocumentos::normalizar('PROCURAÇÃO AD JUDICIA'));
        self::assertSame('certidao de transito em julgado', SugestorDeDocumentos::normalizar('Certidão de Trânsito em Julgado'));
    }

    /** @return iterable<string, array{string, string, float}> */
    public static function pares(): iterable
    {
        yield 'mesmo nome em caixa e acento diferentes' => ['PROCURAÇÃO', 'Procuração', 1.0];
        yield 'nenhuma palavra em comum' => ['RG e CPF', 'Documento de identidade', 0.0];
        yield 'palavras de ruído não contam' => ['procuracao_assinada_final_v2.pdf', 'Procuração', 1.0];
        yield 'parcial pelo menor conjunto' => ['Comprovante de residência', 'Comprovante de pagamento / depósito judicial', 0.5];
        yield 'vazio' => ['', 'Procuração', 0.0];
    }

    #[DataProvider('pares')]
    #[TestDox('similaridade (similar do JS)')]
    public function testSimilaridade(string $a, string $b, float $esperado): void
    {
        self::assertEqualsWithDelta($esperado, SugestorDeDocumentos::similaridade($a, $b), 0.0001);
    }
}
