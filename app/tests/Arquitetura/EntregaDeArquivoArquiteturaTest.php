<?php

declare(strict_types=1);

namespace App\Tests\Arquitetura;

use App\Shared\Http\EntregaDeArquivo;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

/**
 * Trava o que a E2.3 entregou: arquivo persistido sai para o navegador por UM caminho só, e esse
 * caminho não conhece disco (D11) nem esconde pane como 404 (D10).
 *
 * Regex não faz análise de fluxo — por isso as allowlists são por arquivo e cada entrada diz
 * quando sai. É o mesmo desenho de `LimpezaDeArquivosArquiteturaTest`.
 */
#[CoversNothing]
final class EntregaDeArquivoArquiteturaTest extends TestCase
{
    /**
     * Quem ainda pode montar `BinaryFileResponse` com as próprias mãos.
     *
     *  - `EntregaDeArquivo` — é quem deve;
     *  - `ArquivoStorageService` — o shim de D2, com o `servir()` que ninguém mais chama; sai na E2.8;
     *  - `TarefaController` — o anexo de tarefa guarda caminho com `/`, que `ChaveDeArquivo` recusa
     *    (D5); a decisão é da E2.7.
     */
    private const QUEM_MONTA_RESPOSTA_DE_ARQUIVO = [
        'src/Controller/TarefaController.php',
        'src/Shared/Http/EntregaDeArquivo.php',
        'src/Shared/Service/ArquivoStorageService.php',
    ];

    #[TestDox('ninguém em src/ chama mais o servir() antigo — as 15 rotas passam pela EntregaDeArquivo')]
    public function testNinguemChamaServir(): void
    {
        self::assertSame([], $this->arquivosQueCasam('/->servir\s*\(/'));
    }

    /**
     * Pega as formas de despejar arquivo que existem no PHP/Symfony sem framework extra:
     * `BinaryFileResponse`, `$this->file()`, `readfile()` e `fpassthru()`. Não pega um
     * `StreamedResponse` montado à mão — hoje o único do sistema é exportação gerada (folha de
     * ponto), não arquivo persistido.
     */
    #[TestDox('só a EntregaDeArquivo despeja arquivo: BinaryFileResponse, file(), readfile(), fpassthru() (fora as duas exceções datadas)')]
    public function testSoAEntregaMontaRespostaDeArquivo(): void
    {
        $achados = $this->arquivosQueCasam('/\bnew\s+\\\\?(?:Symfony\\\\Component\\\\HttpFoundation\\\\)?BinaryFileResponse\b|\$this->file\s*\(|\b(?:readfile|fpassthru)\s*\(/');

        self::assertSame(
            [],
            array_values(array_diff($achados, self::QUEM_MONTA_RESPOSTA_DE_ARQUIVO)),
            'arquivo persistido tem de sair pela EntregaDeArquivo, endereçado por chave',
        );
    }

    #[TestDox('fora do núcleo, ninguém conhece o backend local nem o resolvedor de caminho (D11)')]
    public function testResolvedorEhDetalheDoBackend(): void
    {
        $achados = array_filter(
            $this->arquivosQueCasam('/\b(ResolvedorDeCaminhoLocal|ArmazenamentoLocal)\b/'),
            static fn (string $arquivo): bool => !str_starts_with($arquivo, 'src/Shared/Armazenamento/'),
        );

        self::assertSame([], array_values($achados));
    }

    /**
     * O materializador devolve CAMINHO FÍSICO. Quem o injeta enxerga disco — é a porta por onde um
     * controller voltaria a conhecer o path (D11). Consumidor novo entra aqui com justificativa:
     *
     *  - `EntregaDeArquivo` (E2.3) — a resposta HTTP é montada sobre um caminho real;
     *  - `CompressaoDeArquivoArmazenado` (E2.6A, D31) — o compressor (Ghostscript, GD) só escreve em
     *    caminho; é o ÚNICO ponto que pede cópia gravável, e os cinco uploads chamam só ele;
     *  - `ReconciliadorDePasta` (E2.6C, Via A) — o cliente do Drive envia lendo por path (§14); o
     *    empréstimo é cópia zero, e é o que substituiu o `caminho()` do shim.
     *
     * O export de peça (E2.6C) NÃO entra aqui de propósito: ele não pede caminho de arquivo
     * persistido. Lê a imagem por chave e grava uma cópia na própria área temporária — é por isso
     * que o `chroot` do Dompdf pode ser um diretório que só tem o que este export colocou lá.
     */
    private const QUEM_PODE_MATERIALIZAR = [
        'src/Shared/Http/EntregaDeArquivo.php',
        'src/Shared/Service/CompressaoDeArquivoArmazenado.php',
        'src/Sync/Service/ReconciliadorDePasta.php',
    ];

    #[TestDox('fora do núcleo, só quem está na lista pede caminho materializado (D11)')]
    public function testSoAEntregaMaterializa(): void
    {
        $achados = array_filter(
            $this->arquivosQueCasam('/\bMaterializadorDeArquivo\b|->(?:paraLeitura|copiaGravavel)\s*\(/'),
            static fn (string $arquivo): bool => !str_starts_with($arquivo, 'src/Shared/Armazenamento/'),
        );

        self::assertSame(self::QUEM_PODE_MATERIALIZAR, array_values($achados));
    }

    #[TestDox('nenhum controller captura FalhaDeArmazenamento — pane do storage não vira resposta amigável (D10)')]
    public function testControllerNaoCapturaPane(): void
    {
        $achados = array_filter(
            $this->arquivosQueCasam('/catch\s*\([^)]*\bFalhaDeArmazenamento\b/'),
            static fn (string $arquivo): bool => str_contains($arquivo, '/Controller/'),
        );

        self::assertSame([], array_values($achados));
    }

    #[TestDox('a entrega nunca liga deleteFileAfterSend: o arquivo é emprestado (INV-9)')]
    public function testEntregaNaoApagaDepoisDeEnviar(): void
    {
        self::assertDoesNotMatchRegularExpression('/deleteFileAfterSend/', $this->codigoDaEntrega());
    }

    #[TestDox('a entrega só transforma em 404 o arquivo ausente — nada de catch amplo (D10)')]
    public function testEntregaNaoMascaraPane(): void
    {
        preg_match_all('/catch\s*\(([^)]*)\)/', $this->codigoDaEntrega(), $capturas);

        self::assertSame(['ArquivoNaoEncontrado $e'], array_map('trim', $capturas[1]));
    }

    // ------------------------------------------------------------------ apoio

    private function codigoDaEntrega(): string
    {
        $arquivo = (new \ReflectionClass(EntregaDeArquivo::class))->getFileName();

        return $this->semComentarios((string) file_get_contents((string) $arquivo));
    }

    /** @return list<string> caminhos relativos a app/, ordenados */
    private function arquivosQueCasam(string $padrao): array
    {
        $raiz    = \dirname(__DIR__, 2);
        $achados = [];

        $iterador = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($raiz . '/src', \FilesystemIterator::SKIP_DOTS),
        );

        /** @var \SplFileInfo $arquivo */
        foreach ($iterador as $arquivo) {
            if ($arquivo->getExtension() !== 'php') {
                continue;
            }

            $codigo = $this->semComentarios((string) file_get_contents($arquivo->getPathname()));
            if (preg_match($padrao, $codigo) === 1) {
                $achados[] = str_replace($raiz . '/', '', $arquivo->getPathname());
            }
        }

        sort($achados);

        return $achados;
    }

    /** Docblocks citam `servir()` e `BinaryFileResponse` de propósito; o guarda olha código. */
    private function semComentarios(string $codigo): string
    {
        $limpo = '';
        foreach (token_get_all($codigo) as $token) {
            if (\is_array($token) && \in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }
            $limpo .= \is_array($token) ? $token[1] : $token;
        }

        return $limpo;
    }
}
