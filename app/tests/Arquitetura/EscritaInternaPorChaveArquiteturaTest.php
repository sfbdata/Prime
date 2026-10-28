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
     *  - `AreaTemporariaPrivada` (E2.6C) — escreve SÓ dentro da própria área temporária, que ela
     *    mesma criou com nome aleatório; nunca toca no armazenamento. É onde as imagens do export
     *    são materializadas, e é por isso que o `chroot` do Dompdf pode ser um diretório só daquela
     *    execução.
     */
    private const QUEM_ESCREVE_CRU = [
        'src/Shared/Armazenamento/AreaTemporariaPrivada.php',
        // P2.3: grava o RELATÓRIO da reconciliação (JSON de contagens, ids e classes) no caminho que
        // o operador pediu com `--saida` — não é arquivo do usuário nem passa pelo armazenamento.
        'src/Shared/EspelhoRemoto/Command/ReconciliarEspelhoCommand.php',
    ];

    /**
     * Quem ainda lê arquivo inteiro com `file_get_contents()`:
     *
     *  - `ImportarRelatorioInput` — assinatura dos primeiros bytes do upload ainda temporário,
     *    antes de ele virar arquivo persistido;
     *  - `CampanhaDoMasterController` (F3i, 21/10) — lê a imagem que o Master envia do upload ainda temporário, com
     *    teto de 1 MB checado antes; o UseCase confere e regrava pelo GD e só então grava por chave no armazenamento;
     *  - `ArmazenamentoLocal` — é o backend: é aqui que a leitura por chave vira disco;
     *  - `MapearAcervoCommand`, `ParsearAcervoCommand` — leem arquivos do operador (JSON e
     *    planilhas do acervo), fora do armazenamento;
     *  - `FontesPublicasController` (perfis-master E2, 16/10) — lê a planilha CSV que o Master envia
     *    (população IBGE / Quadro da Advocacia) do upload ainda temporário, com teto de 2 MB checado
     *    antes; vira retrato no armazém de fontes do Master, nunca arquivo do armazenamento;
     *  - `GeradorDePdfComDompdf` (EDT-002, 07/10; extraído do PastaResumoController na spec
     *    relatorio-processos-controladoria) — lê o LOGO estático da aplicação
     *    (`public/images/logo/bluejus_simbolo.svg`) para embutir nos PDFs; não é arquivo do
     *    usuário nem passa pelo armazenamento (o Dompdf roda sem recurso remoto);
     *  - `CatalogoDeQuestoesJuridicas` e `DocumentosJuridicosAtuais` (questões jurídicas do Master, 24/10) — leem
     *    arquivos versionados da APLICAÇÃO (`config/inteligencia/questoes_juridicas.json` e `resources/legal/*`), não
     *    do usuário; as versões ajustadas que o Master envia vão por chave para o armazenamento;
     *  - `CacheDeLeituraDoEspelho` (leitura pelo R2, D3) — lê a cópia que ele mesmo publicou no cache de
     *    leitura, já conferida por SHA; quem pede é o `ArmazenamentoEspelhado::ler()`, por chave.
     */
    private const QUEM_LE_CRU = [
        'src/Cobranca/DTO/ImportarRelatorioInput.php',
        'src/Comercial/Controller/CampanhaDoMasterController.php',
        'src/Command/MapearAcervoCommand.php',
        'src/Command/ParsearAcervoCommand.php',
        'src/Inteligencia/Juridico/CatalogoDeQuestoesJuridicas.php',
        'src/Inteligencia/Juridico/DocumentosJuridicosAtuais.php',
        'src/Master/Controller/FontesPublicasController.php',
        'src/Shared/Armazenamento/ArmazenamentoLocal.php',
        'src/Shared/EspelhoRemoto/CacheDeLeituraDoEspelho.php',
        'src/Shared/Service/GeradorDePdfComDompdf.php',
    ];

    /**
     * Quem ainda depende de `ArquivoStorageInterface`/`ArquivoStorageService` (fora comentários):
     * ninguém. A interface e o serviço saíram na E2.8 (spec P2 §10.2) — desde a E2.6C não sobrava
     * consumidor de produção (a Via A do Drive passou a materializar por chave).
     *
     * Na E2.6B saíram os quatro consumidores do compressor — `ClienteController`, `PastaController`,
     * `UploadPecaUseCase` e `EnviarDocumentoUseCase` —, que agora comprimem pela CHAVE, com o
     * `CompressaoDeArquivoArmazenado`. Nenhum deles pode voltar.
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
    #[TestDox('ninguém em src/ depende mais do storage antigo — o shim saiu na E2.8 e não volta')]
    public function testNinguemUsaOShim(): void
    {
        self::assertSame([], $this->arquivosQueCasam('/\bArquivoStorage(?:Interface|Service)\b/'));
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
