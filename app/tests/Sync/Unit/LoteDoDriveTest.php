<?php

declare(strict_types=1);

namespace App\Tests\Sync\Unit;

use App\Sync\DTO\DriveDoTenant;
use App\Sync\Exception\ImportacaoDoDriveRecusadaException;
use App\Sync\Service\ExtratorDeFolderId;
use App\Sync\Service\LoteDoDrive;
use App\Tests\Sync\Support\FakeGoogleDriveClient;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

/**
 * "Importar do Google Drive" (spec importar-do-drive.md): a árvore do Drive chega ao JS do lote com os mesmos
 * caminhos que o navegador dá no lote do computador. Os 11 cenários de `scripts/lote-teste/gerar.py` viram
 * árvores simuladas: se os caminhos são iguais, o resultado do lote (pergunta, níveis, número, recusados) é
 * o mesmo, porque a regra é uma só, no JS.
 */
#[CoversClass(LoteDoDrive::class)]
#[CoversClass(ImportacaoDoDriveRecusadaException::class)]
final class LoteDoDriveTest extends TestCase
{
    private const RAIZ = 'RAIZ_DO_ESCRITORIO';

    private FakeGoogleDriveClient $fake;
    private int $seq = 0;

    protected function setUp(): void
    {
        $this->fake = new FakeGoogleDriveClient();
        $this->fake->seedPasta(self::RAIZ, 'Escritório', '');
    }

    private function drive(): DriveDoTenant
    {
        return new DriveDoTenant($this->fake, self::RAIZ);
    }

    private function lote(): LoteDoDrive
    {
        return new LoteDoDrive(new ExtratorDeFolderId());
    }

    /**
     * Monta no Drive simulado, abaixo de $pai, as pastas e arquivos dos caminhos; devolve o id da pasta do
     * primeiro nível.
     *
     * @param list<string> $caminhos
     */
    private function semear(array $caminhos, string $pai = self::RAIZ, string $mimeType = 'application/pdf'): string
    {
        $ids = [];
        foreach ($caminhos as $caminho) {
            $partes = explode('/', $caminho);
            $nome   = array_pop($partes);
            $atual  = $pai;
            $prefixo = '';
            foreach ($partes as $parte) {
                $prefixo .= '/' . $parte;
                if (!isset($ids[$prefixo])) {
                    $ids[$prefixo] = 'pasta' . str_pad((string) ++$this->seq, 8, '0', \STR_PAD_LEFT);
                    $this->fake->seedPasta($ids[$prefixo], $parte, $atual);
                }
                $atual = $ids[$prefixo];
            }
            $this->fake->seedArquivo('arq' . str_pad((string) ++$this->seq, 10, '0', \STR_PAD_LEFT), $nome, $atual, 10, $mimeType);
        }

        return $ids['/' . explode('/', $caminhos[0])[0]];
    }

    /** @return list<string> */
    private static function caminhos(array $arvore): array
    {
        $caminhos = array_column($arvore['itens'], 'caminho');
        sort($caminhos);

        return $caminhos;
    }

    /** @return iterable<string, array{list<string>}> */
    public static function cenariosDoLote(): iterable
    {
        yield '01 plano' => [['Clientes/ANA_SOUZA_TESTE/procuracao.pdf', 'Clientes/ANA_SOUZA_TESTE/RG.jpg', 'Clientes/BRUNO-LIMA-TESTE/contrato.docx', 'Clientes/CARLA MENDES TESTE/inicial.pdf']];
        yield '02 cliente com subpastas e soltos' => [['MARIA FICTICIA/Documentos/comprovante.pdf', 'MARIA FICTICIA/Documentos/RG.jpg', 'MARIA FICTICIA/Processo/contestacao.docx', 'MARIA FICTICIA/Processo/Inicial.pdf', 'MARIA FICTICIA/procuracao.pdf']];
        yield '03 cliente só com subpastas' => [['PAULO FICTICIO/Documentos/CPF.pdf', 'PAULO FICTICIO/Documentos/RG.jpg', 'PAULO FICTICIO/Processo/Inicial.pdf']];
        yield '04 numeradas' => [['Clientes/001 - Fulano Ficticio/inicial.pdf', 'Clientes/002 - Beltrana_Teste/procuracao.pdf', 'Clientes/010 - Sicrano Teste/RG.jpg']];
        yield '05 três níveis' => [['Escritorio/2024/JOAO FICTICIO/Processo 1/inicial.pdf', 'Escritorio/2024/JOAO FICTICIO/Processo 1/sentenca.pdf', 'Escritorio/2024/LUIZA TESTE/Processo A/inicial.pdf', 'Escritorio/2025/JOAO FICTICIO/Processo 2/inicial.pdf']];
        yield '06 soltos e subpastas de vários clientes' => [['Clientes/ANA TESTE/procuracao.pdf', 'Clientes/BRUNO TESTE/RG.jpg', 'Clientes/planilha-geral.pdf']];
        yield '07 lixo (o JS ignora, como no computador)' => [['Clientes/ANA TESTE/.~lock.contrato.docx#', 'Clientes/ANA TESTE/procuracao.pdf', 'Clientes/ANA TESTE/Thumbs.db', 'Clientes/desktop.ini', 'Clientes/.DS_Store', 'Clientes/.ocultos/rascunho.pdf', 'Clientes/Thumbs.db']];
        yield '08 acentos e separadores' => [['Clientes/ÁUREA-MÜLLER/RG.jpg', 'Clientes/édson lima/inicial.pdf', 'Clientes/JOSÉ_DA_CONCEIÇÃO/procuração.pdf', 'Clientes/Zé__Ninguém--Teste/contrato.docx']];
        yield '09 mesmo cliente, dois casos' => [['Clientes/PEDRO FICTICIO - FAMILIA/inicial.pdf', 'Clientes/PEDRO FICTICIO - TRABALHISTA/ctps.jpg', 'Clientes/PEDRO FICTICIO - TRABALHISTA/inicial.pdf']];
        yield '10 recusados (o upload recusa, como no computador)' => [['Clientes/RITA TESTE/contrato.docx', 'Clientes/RITA TESTE/foto-grande.jpg', 'Clientes/RITA TESTE/instalador.exe', 'Clientes/RITA TESTE/ok.pdf']];
        yield '11 subpastas fundas' => [['Clientes/ANA TESTE/N1/N2/N3/N4/N5/N6/N7/N8/N9/N10/N11/N12/fundo.pdf', 'Clientes/ANA TESTE/Processo/inicial.pdf', 'Clientes/ANA TESTE/Processo/Recursos/apelacao.pdf']];
    }

    /** @param list<string> $caminhos */
    #[DataProvider('cenariosDoLote')]
    #[TestDox('cenário $_dataName: a árvore do Drive dá os mesmos caminhos do lote do computador')]
    public function testCenarioDoLoteDaOsMesmosCaminhos(array $caminhos): void
    {
        $pasta  = $this->semear($caminhos);
        $arvore = $this->lote()->arvore($this->drive(), 'https://drive.google.com/drive/folders/' . $pasta);

        $esperados = $caminhos;
        sort($esperados);
        self::assertSame($esperados, self::caminhos($arvore));
        self::assertFalse($arvore['truncado']);
        self::assertSame(array_fill(0, \count($caminhos), null), array_column($arvore['itens'], 'recusa'));
    }

    #[TestDox('a árvore traz o id de cada pasta pelo caminho, para ligar a pasta criada à de origem')]
    public function testArvoreTrazIdDasPastas(): void
    {
        $pasta  = $this->semear(['Clientes/ANA/procuracao.pdf', 'Clientes/BRUNO/RG.jpg']);
        $arvore = $this->lote()->arvore($this->drive(), $pasta);

        $porCaminho = array_column($arvore['pastas'], 'id', 'caminho');
        self::assertSame($pasta, $porCaminho['Clientes']);
        self::assertArrayHasKey('Clientes/ANA', $porCaminho);
        self::assertArrayHasKey('Clientes/BRUNO', $porCaminho);
    }

    #[TestDox('pasta fora da pasta do escritório é recusada (o token enxerga o Drive inteiro)')]
    public function testPastaForaDaRaizRecusada(): void
    {
        $this->fake->seedPasta('OUTRA_RAIZ_PESSOAL', 'Meu Drive', '');
        $pasta = $this->semear(['Pessoal/extrato.pdf'], 'OUTRA_RAIZ_PESSOAL');

        $this->expectExceptionObject(ImportacaoDoDriveRecusadaException::foraDaRaiz());
        $this->lote()->arvore($this->drive(), $pasta);
    }

    #[TestDox('arquivo fora da pasta do escritório é recusado no envio, sem baixar')]
    public function testArquivoForaDaRaizRecusadoSemBaixar(): void
    {
        $this->fake->seedPasta('OUTRA_RAIZ_PESSOAL', 'Meu Drive', '');
        $this->fake->seedArquivo('arquivo_pessoal_1', 'extrato.pdf', 'OUTRA_RAIZ_PESSOAL');

        try {
            $this->lote()->baixar($this->drive(), 'arquivo_pessoal_1', sys_get_temp_dir() . '/nao-usado');
            self::fail('deveria recusar');
        } catch (ImportacaoDoDriveRecusadaException $e) {
            self::assertSame(ImportacaoDoDriveRecusadaException::FORA_DA_RAIZ, $e->getMessage());
        }
        self::assertSame([], $this->fake->destinosDeDownload);
    }

    #[TestDox('link que não é de pasta, ou que não existe, é recusado')]
    public function testLinkInvalido(): void
    {
        $this->fake->seedArquivo('arquivo_solto_1', 'x.pdf', self::RAIZ);

        try {
            $this->lote()->arvore($this->drive(), 'arquivo_solto_1');
            self::fail('deveria recusar');
        } catch (ImportacaoDoDriveRecusadaException $e) {
            self::assertSame(ImportacaoDoDriveRecusadaException::NAO_E_PASTA, $e->getMessage());
        }

        $this->expectExceptionObject(ImportacaoDoDriveRecusadaException::naoEncontrado());
        $this->lote()->arvore($this->drive(), 'nao_existe_123');
    }

    #[TestDox('Docs, Planilhas e Apresentações entram como .docx, .xlsx e .pdf; formulário e atalho vão para os recusados')]
    public function testNativosDoGoogle(): void
    {
        $pasta = $this->semear(['ANA/Contrato'], self::RAIZ, 'application/vnd.google-apps.document');
        $this->fake->seedArquivo('planilha_123', 'Custas', $pasta, 0, 'application/vnd.google-apps.spreadsheet');
        $this->fake->seedArquivo('slides_1234', 'Sustentação.pdf', $pasta, 0, 'application/vnd.google-apps.presentation');
        $this->fake->seedArquivo('form_123456', 'Questionário', $pasta, 0, 'application/vnd.google-apps.form');
        $this->fake->seedArquivo('atalho_1234', 'Atalho', $pasta, 0, 'application/vnd.google-apps.shortcut');

        $recusas = array_column($this->lote()->arvore($this->drive(), $pasta)['itens'], 'recusa', 'caminho');

        self::assertNull($recusas['ANA/Contrato.docx']);
        self::assertNull($recusas['ANA/Custas.xlsx']);
        self::assertNull($recusas['ANA/Sustentação.pdf'], 'não repete a extensão');
        self::assertNotNull($recusas['ANA/Questionário']);
        self::assertNotNull($recusas['ANA/Atalho']);
    }

    #[TestDox('o envio exporta o Doc do Google como DOCX e baixa o arquivo comum')]
    public function testBaixarExportaOuBaixa(): void
    {
        $pasta = $this->semear(['ANA/procuracao.pdf']);
        $this->fake->seedArquivo('documento_1', 'Contrato', $pasta, 0, 'application/vnd.google-apps.document');
        $destino = (string) tempnam(sys_get_temp_dir(), 'lote-drive-teste-');

        try {
            self::assertSame('Contrato.docx', $this->lote()->baixar($this->drive(), 'documento_1', $destino));
            self::assertSame([['fileId' => 'documento_1', 'mimeType' => LoteDoDrive::EXPORTAVEIS['application/vnd.google-apps.document'][0]]], $this->fake->exportacoes);

            $comum = array_key_first(array_filter($this->fake->arquivos, static fn (array $a): bool => $a['nome'] === 'procuracao.pdf'));
            self::assertSame('procuracao.pdf', $this->lote()->baixar($this->drive(), (string) $comum, $destino));
            self::assertSame('conteudo-fake-' . $comum, file_get_contents($destino));
        } finally {
            @unlink($destino);
        }
    }

    #[TestDox('formulário do Google é recusado no envio')]
    public function testFormularioRecusadoNoEnvio(): void
    {
        $pasta = $this->semear(['ANA/procuracao.pdf']);
        $this->fake->seedArquivo('formulario_1', 'Questionário', $pasta, 0, 'application/vnd.google-apps.form');

        $this->expectException(ImportacaoDoDriveRecusadaException::class);
        $this->lote()->baixar($this->drive(), 'formulario_1', sys_get_temp_dir() . '/nao-usado');
    }

    #[TestDox('arquivo acima do maior limite do upload sai recusado pelo tamanho, sem baixar')]
    public function testArquivoGrandeDemais(): void
    {
        self::assertNotNull(LoteDoDrive::recusa('video/mp4', LoteDoDrive::TAMANHO_MAXIMO + 1));
        self::assertNull(LoteDoDrive::recusa('video/mp4', LoteDoDrive::TAMANHO_MAXIMO));
    }

    #[TestDox('acima de 2.000 arquivos a árvore para e avisa que truncou')]
    public function testTruncaNoLimite(): void
    {
        $caminhos = [];
        for ($i = 0; $i <= LoteDoDrive::LIMITE_DE_ARQUIVOS; ++$i) {
            $caminhos[] = 'Clientes/doc' . $i . '.pdf';
        }
        $arvore = $this->lote()->arvore($this->drive(), $this->semear($caminhos));

        self::assertTrue($arvore['truncado']);
        self::assertCount(LoteDoDrive::LIMITE_DE_ARQUIVOS, $arvore['itens']);
    }
}
