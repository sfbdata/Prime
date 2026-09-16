<?php

declare(strict_types=1);

namespace App\Tests\Arquitetura;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

/**
 * Trava o que a E2.4B entregou: as escritas internas (peça nova, peça editada, cópia do acervo,
 * download do Drive) e as leituras de peça passam pelo `ArmazenamentoDeArquivos`, por chave.
 *
 * Sem este guarda, nada impedia um `salvarConteudo()` ou um `file_put_contents()` sobre
 * `caminho()` de voltar: `UploadPorChaveArquiteturaTest` só olha `salvar()` e `move()`.
 *
 * Primitiva por primitiva a rede é fácil de contornar (`copy()`, `fopen(..., 'w')`...). Por isso o
 * primeiro teste trava pela ESTRUTURA: quem ainda depende do storage antigo é uma lista fechada,
 * que só diminui — um consumidor novo, ou a volta de um migrado, derruba o teste.
 *
 * Regex não faz análise de fluxo — a allowlist é por arquivo, e cada entrada diz por que está lá e
 * quando sai. Mesmo desenho de `UploadPorChaveArquiteturaTest`.
 */
#[CoversNothing]
final class EscritaInternaPorChaveArquiteturaTest extends TestCase
{
    /**
     * Quem ainda escreve arquivo com `file_put_contents()`:
     *
     *  - `ArquivoStorageService` — o `salvarConteudo()` do shim de D2, sem chamador; sai na E2.8.
     */
    private const QUEM_ESCREVE_CRU = [
        'src/Shared/Service/ArquivoStorageService.php',
    ];

    /**
     * Quem ainda lê arquivo inteiro com `file_get_contents()`:
     *
     *  - `ImportarRelatorioInput` — assinatura dos primeiros bytes do upload ainda temporário,
     *    antes de ele virar arquivo persistido;
     *  - `CompressorArquivo` — recebe caminho por contrato (`CompressorArquivoInterface`); o
     *    chamador passa a entregar cópia gravável na E2.6;
     *  - `ArmazenamentoLocal` — é o backend: é aqui que a leitura por chave vira disco;
     *  - `MapearAcervoCommand`, `ParsearAcervoCommand` — leem arquivos do operador (JSON e
     *    planilhas do acervo), fora do armazenamento.
     */
    private const QUEM_LE_CRU = [
        'src/Cobranca/DTO/ImportarRelatorioInput.php',
        'src/Command/MapearAcervoCommand.php',
        'src/Command/ParsearAcervoCommand.php',
        'src/Shared/Armazenamento/ArmazenamentoLocal.php',
        'src/Shared/Service/CompressorArquivo.php',
    ];

    /**
     * Quem ainda depende de `ArquivoStorageInterface`/`ArquivoStorageService` (fora comentários), e
     * por quê. A lista só pode diminuir; cada entrada sai na fatia indicada.
     *
     *  - E2.6 (`caminho()` para o compressor e para o envio ao Drive): `ClienteController`,
     *    `PastaController`, `EnviarDocumentoUseCase`, `UploadPecaUseCase`, `ReconciliadorDePasta`;
     *  - E2.8: a própria interface e o serviço.
     *
     * Os seis pontos da E2.4B (`SalvarPecaTextoUseCase`, `EditarPecaTextoUseCase`,
     * `ExportarPecaTextoUseCase`, `ArquivosReferenciadosEmPecas`, `CopiarArquivosAcervoCommand` e a
     * gravação do `ReconciliadorDePasta`) saíram — os cinco primeiros não podem voltar. Na E2.5
     * saíram os doze que só excluíam por caminho: Cobrança ×4, `ExcluirPastaUseCase`,
     * `PastaSecaoController`, `ArquivosDeAnexoDoKanban`, `AtualizarFotoPerfilUseCase`,
     * `SubstituirAnexoDoLoteUseCase`, `PontoController`, `TenantController` e
     * `PurgarEscritorioUseCase`. Nenhum deles pode voltar; os três que ficam não chamam mais o
     * `excluir()` antigo (`ExclusaoAposTransacaoArquiteturaTest`).
     */
    private const QUEM_AINDA_USA_O_SHIM = [
        'src/Cliente/Controller/ClienteController.php',
        'src/Cobranca/UseCase/EnviarDocumentoUseCase.php',
        'src/Controller/PastaController.php',
        'src/Pasta/UseCase/UploadPecaUseCase.php',
        'src/Shared/Service/ArquivoStorageInterface.php',
        'src/Shared/Service/ArquivoStorageService.php',
        'src/Sync/Service/ReconciliadorDePasta.php',
    ];

    #[TestDox('só os consumidores conhecidos ainda dependem do storage antigo — a lista só diminui')]
    public function testQuemAindaUsaOShimEhAListaConhecida(): void
    {
        self::assertSame(
            self::QUEM_AINDA_USA_O_SHIM,
            $this->arquivosQueCasam('/\bArquivoStorage(?:Interface|Service)\b/'),
        );
    }

    #[TestDox('ninguém em src/ grava mais por salvarConteudo() nem moverParaArmazenamento()')]
    public function testNinguemChamaAsGravacoesInternasAntigas(): void
    {
        self::assertSame([], $this->arquivosQueCasam('/->\s*(?:salvarConteudo|moverParaArmazenamento)\s*\(/i'));
    }

    #[TestDox('file_put_contents() só no shim antigo — a peça editada grava por chave')]
    public function testSoOShimEscreveCru(): void
    {
        self::assertSame(self::QUEM_ESCREVE_CRU, $this->arquivosQueCasam('/\bfile_put_contents\s*\(/i'));
    }

    #[TestDox('file_get_contents() só nas exceções datadas — o HTML da peça é lido por chave')]
    public function testSoAsExcecoesLeemCru(): void
    {
        self::assertSame(self::QUEM_LE_CRU, $this->arquivosQueCasam('/\bfile_get_contents\s*\(/i'));
    }

    /** @return list<string> */
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
