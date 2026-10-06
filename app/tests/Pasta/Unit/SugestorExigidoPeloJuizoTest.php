<?php

declare(strict_types=1);

namespace App\Tests\Pasta\Unit;

use App\Pasta\DTO\DeterminacaoDoJuizoOutput;
use App\Pasta\DTO\DocumentoSugeridoOutput;
use App\Pasta\DTO\LeituraDoJuizoOutput;
use App\Pasta\DTO\SugestaoDeDocumentosOutput;
use App\Pasta\Service\SugestorDeDocumentos;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

/**
 * "Exigido pelo juízo" dentro do painel de sugeridos (DOC-79) e o que a leitura do Push acrescenta
 * (DOC-82: "Processo: …" e "Prazos em curso"). Sem leitura, o Sugestor tem de se comportar como
 * antes — esse é o caso que mantém a tela honesta quando não há publicação.
 */
#[CoversClass(SugestorDeDocumentos::class)]
#[CoversClass(SugestaoDeDocumentosOutput::class)]
#[CoversClass(DocumentoSugeridoOutput::class)]
final class SugestorExigidoPeloJuizoTest extends TestCase
{
    private SugestorDeDocumentos $sugestor;

    protected function setUp(): void
    {
        $this->sugestor = new SugestorDeDocumentos();
    }

    /** @param list<string> $documentos */
    private static function determinacao(array $documentos, ?int $prazo = 15, string $data = '2026-09-03', int $publicacaoId = 77): DeterminacaoDoJuizoOutput
    {
        return new DeterminacaoDoJuizoOutput(
            $publicacaoId,
            'Decisão',
            new \DateTimeImmutable($data),
            '987654321',
            'Manifestação',
            'Intime-se a parte autora para, no prazo de 15 dias, juntar declaração de hipossuficiência.',
            $prazo,
            $prazo !== null ? 'dias' : null,
            $prazo !== null ? 'uteis' : null,
            $documentos,
        );
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

    #[TestDox('sem leitura do Push: nenhum item do juízo, nada lido, nenhum prazo — como antes')]
    public function testSemLeituraNadaMuda(): void
    {
        $s = $this->sugestor->sugerir('Indenização', null, null, [], [], null, new \DateTimeImmutable('2026-09-05'));

        self::assertSame([], $s->comStatus(DocumentoSugeridoOutput::EXIGIDO_PELO_JUIZO));
        self::assertFalse($s->leuOProcesso());
        self::assertSame(0, $s->publicacoesLidas);
        self::assertSame([], $s->prazosEmCurso);
        foreach ($s->itens as $item) {
            self::assertNull($item->origemPublicacaoId);
        }
    }

    #[TestDox('documento exigido pela decisão entra PRIMEIRO, como "juizo", com o trecho no porquê e a origem da publicação')]
    public function testExigidoEntraPrimeiroComOrigem(): void
    {
        $leitura = new LeituraDoJuizoOutput(1, [self::determinacao(['hipossuf'])], []);

        $s    = $this->sugestor->sugerir('Indenização', null, null, [], [], $leitura, new \DateTimeImmutable('2026-09-05'));
        $item = self::porChave($s)['hipossuf'];

        self::assertSame('hipossuf', $s->itens[0]->chave, 'exigido pelo juízo vem antes do obrigatório');
        self::assertSame(DocumentoSugeridoOutput::EXIGIDO_PELO_JUIZO, $item->status, 'na fase inicial era só "opcional"; o juízo exigiu');
        self::assertStringStartsWith('Determinação expressa do juízo, prazo de 15 dias úteis: "Intime-se', $item->porque);
        self::assertSame(77, $item->origemPublicacaoId);
        self::assertSame('Origem: Decisão de 03/09/2026 (ID 987654321)', $item->origemTexto);
        self::assertTrue($item->podeIrAoChecklist());
        self::assertContains('Declaração de hipossuficiência', $s->nomesDosFaltantes(), 'exigido pelo juízo entra no "Adicionar faltantes"');
        self::assertTrue($s->leuOProcesso());
    }

    #[TestDox('exigido que já está na pasta vira "já existente", e continua dizendo a origem')]
    public function testExigidoJaNaPastaViraExistente(): void
    {
        $leitura = new LeituraDoJuizoOutput(1, [self::determinacao(['hipossuf'])], []);
        $arquivo = ['titulo' => 'DECLARAÇÃO DE HIPOSSUFICIÊNCIA', 'nomeOriginal' => 'decl.pdf', 'categoria' => 'DEMAIS', 'data' => '04/09/2026'];

        $item = self::porChave($this->sugestor->sugerir('Indenização', null, null, [$arquivo], [], $leitura))['hipossuf'];

        self::assertSame(DocumentoSugeridoOutput::JA_EXISTE, $item->status);
        self::assertSame(77, $item->origemPublicacaoId);
        self::assertFalse($item->ehFaltante());
    }

    #[TestDox('"não aplicável" não rebaixa o que o juízo exigiu (comprovante de pagamento)')]
    public function testJuizoNaoViraNaoAplicavel(): void
    {
        $leitura = new LeituraDoJuizoOutput(1, [self::determinacao(['pagamento'])], []);

        $item = self::porChave($this->sugestor->sugerir('Cobrança', 'Cumprimento de sentença', null, [], [], $leitura))['pagamento'];

        self::assertSame(DocumentoSugeridoOutput::EXIGIDO_PELO_JUIZO, $item->status);
    }

    #[TestDox('a determinação mais recente (primeira da lista) é a origem quando duas exigem o mesmo documento')]
    public function testMaisRecenteVence(): void
    {
        $leitura = new LeituraDoJuizoOutput(2, [
            self::determinacao(['procuracao'], 10, '2026-09-20', 900),
            self::determinacao(['procuracao'], 15, '2026-08-01', 100),
        ], []);

        $item = self::porChave($this->sugestor->sugerir('Indenização', null, null, [], [], $leitura))['procuracao'];

        self::assertSame(DocumentoSugeridoOutput::EXIGIDO_PELO_JUIZO, $item->status);
        self::assertSame(900, $item->origemPublicacaoId);
    }

    #[TestDox('"Processo: Sentença (ID x) · data" marca a sentença como já existente na fase de cumprimento')]
    public function testDocumentoDoProcessoViraExistente(): void
    {
        $leitura = new LeituraDoJuizoOutput(1, [], [['chave' => 'sentenca', 'rotulo' => 'Processo: Sentença (ID 555) · 20/08/2026']]);

        $item = self::porChave($this->sugestor->sugerir(null, 'Cumprimento de sentença', null, [], [], $leitura))['sentenca'];

        self::assertSame(DocumentoSugeridoOutput::JA_EXISTE, $item->status);
        self::assertSame(['Processo: Sentença (ID 555) · 20/08/2026'], $item->localizadoEm);
    }

    #[TestDox('"Prazos em curso": só o prazo explícito que certamente corre hoje; vencido ou sem prazo não aparece; sem "hoje", nada')]
    public function testPrazosEmCurso(): void
    {
        $leitura = new LeituraDoJuizoOutput(3, [
            self::determinacao([], 15, '2026-09-01'),
            self::determinacao([], 5, '2026-08-01'),
            self::determinacao([], null, '2026-09-01'),
        ], []);

        $s = $this->sugestor->sugerir('Indenização', null, null, [], [], $leitura, new \DateTimeImmutable('2026-09-10'));
        self::assertSame(['Manifestação: prazo de 15 dias úteis (Decisão de 01/09/2026)'], $s->prazosEmCurso);

        $vencido = $this->sugestor->sugerir('Indenização', null, null, [], [], $leitura, new \DateTimeImmutable('2026-12-01'));
        self::assertSame([], $vencido->prazosEmCurso, 'o filtro remove tudo: nenhum prazo certamente em curso');

        self::assertSame([], $this->sugestor->sugerir('Indenização', null, null, [], [], $leitura)->prazosEmCurso);
    }

    #[TestDox('determinação sem documento não cria item do juízo')]
    public function testDeterminacaoSemDocumentoNaoCriaItem(): void
    {
        $leitura = new LeituraDoJuizoOutput(1, [self::determinacao([])], []);

        $s = $this->sugestor->sugerir('Indenização', null, null, [], [], $leitura);

        self::assertSame([], $s->comStatus(DocumentoSugeridoOutput::EXIGIDO_PELO_JUIZO));
        self::assertTrue($s->leuOProcesso());
    }
}
