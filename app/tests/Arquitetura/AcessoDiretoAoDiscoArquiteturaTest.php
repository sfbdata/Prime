<?php

declare(strict_types=1);

namespace App\Tests\Arquitetura;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

/**
 * E2.8 (§11.3 da spec da E2; spec P2 §10.2): **arquivo do usuário só se toca pelo armazenamento.**
 *
 * Para a P2 isto deixa de ser higiene e vira garantia: o espelho no R2 é um decorador do
 * `ArmazenamentoDeArquivos`, então toda escrita que contornar a interface é uma escrita que o
 * espelho não vê — arquivo que existe no disco e nunca chega ao R2.
 *
 * A lista é EXATA (não "no máximo"): arquivo novo que mexe em disco derruba o teste, e arquivo que
 * deixou de mexer também — para a allowlist não apodrecer com entradas mortas. Regex não faz análise
 * de fluxo, por isso a lista é por arquivo e cada entrada diz por que está aqui. Comentários são
 * ignorados (lidos pelo tokenizer do PHP, não por regex).
 */
#[CoversNothing]
final class AcessoDiretoAoDiscoArquiteturaTest extends TestCase
{
    /**
     * Funções de arquivo do PHP, mais as outras portas de escrita que existem neste código: GD
     * gravando em caminho, `ZipArchive`, `Symfony\Component\Filesystem`, processo externo
     * (`Process`, `exec` e família — o Ghostscript escreve arquivo), o `openFile()` do
     * `SplFileInfo`, e os escritores de PhpWord/PhpSpreadsheet. `->save(` sozinho fica de fora:
     * casaria com os repositórios Doctrine; o escritor é pego pela criação (`createWriter`, `new Xlsx`).
     */
    private const FUNCOES_DE_DISCO = '/\\b(?:file_get_contents|file_put_contents|fopen|filesize|unlink|rename|copy|glob|scandir|opendir|realpath|is_file|file_exists|is_dir|mkdir|rmdir|move_uploaded_file|tempnam|tmpfile|touch|chmod|chown|symlink|link|fwrite|fputcsv|stream_copy_to_stream|imagepng|imagejpeg|imagegif|imagewebp|imagebmp|exec|shell_exec|passthru|system|proc_open|popen)\\s*\\(|\\bSplFileObject\\b|->openFile\\s*\\(|->move\\s*\\(|\\bZipArchive\\b|Symfony\\\\Component\\\\Filesystem|\\bnew\\s+Process\\b|\\bProcess::fromShellCommandline\\b|IOFactory::createWriter|\\bnew\\s+Xlsx\\s*\\(/';

    /**
     * Quem pode chamar função de disco, e por quê.
     *
     * @var array<string, string>
     */
    private const QUEM_TOCA_O_DISCO = [
        // O núcleo do armazenamento: é aqui que chave vira disco, e os temporários privados dele.
        'src/Shared/Armazenamento/ArmazenamentoLocal.php'          => 'o backend local',
        'src/Shared/Armazenamento/AreaTemporariaPrivada.php'       => 'diretório aleatório por operação, apagado no finally (E2.6C)',
        'src/Shared/Armazenamento/ArquivoGeradoParaEntrega.php'    => 'arquivo GERADO (o .zip), nunca persistido',
        'src/Shared/Armazenamento/ArquivoTemporarioPossuido.php'   => 'cópia possuída, com ciclo de vida (D9)',
        'src/Shared/Armazenamento/DiretorioTemporarioPrivado.php'  => 'o temporário privado do processo (D29)',
        'src/Shared/Armazenamento/FonteDeConteudo.php'             => 'abre a origem que o chamador entregou',
        'src/Shared/Armazenamento/Sha256DeArquivo.php'             => 'lê para medir',
        // Borda HTTP do upload: move o UploadedFile para o temporário (INV-10).
        'src/Shared/Http/FonteDeUploadHttp.php'                    => 'a ponte do upload',
        // Compressão: só sobre a cópia gravável e os temporários do Ghostscript (D4, D26-D28).
        'src/Shared/Service/CompressorArquivo.php'                 => 'trabalha no temporário que recebe',
        'src/Shared/Service/ValidadorDeArquivoComprimido.php'      => 'lê o resultado da compressão no temporário',
        'src/Shared/Service/ExecucaoDoGhostscript.php'             => 'processo do Ghostscript: escreve só no TMPDIR privado e na cópia gravável',
        'src/Pasta/Service/ContadorDePaginasPorGhostscript.php'    => 'só lê: confere o upload em trânsito ou o materializado antes do Ghostscript',
        // Zip da aba Documentos: lock por escritório e o tamanho do .zip gerado.
        'src/Pasta/Service/VagasDeZipDoEscritorio.php'             => 'lock files das vagas de zip',
        'src/Pasta/UseCase/MontarZipDeDocumentosUseCase.php'       => 'monta e mede o .zip gerado (ZipArchive), na área temporária',
        'src/Pasta/UseCase/UploadPecaUseCase.php'                  => 'só lê: abre o upload em trânsito com ZipArchive para conferir o DOCX',
        'src/Pasta/Service/PreviaDeDocumentoOffice.php'            => 'só lê: ZipArchive somente leitura confere o índice do pacote (zip bomb) do arquivo já materializado na área temporária',
        'src/ChatIa/Service/ExtratorDeTextoDocx.php'               => 'só lê: ZipArchive somente leitura abre o DOCX materializado na AreaTemporariaPrivada (apagada no finally)',
        'src/Pasta/Service/DetectorDeAssinaturaDigital.php'        => 'só lê as pontas do upload em trânsito (selo Assinado); o acervo ele lê pelo stream que o armazenamento abre',
        'src/Comercial/Campanha/PreparadorDeImagemDaCampanha.php'  => 'GD gera os bytes em memória (destino null); grava pelo armazenamento (COMERCIAL_IMAGEM)',
        'src/Tenant/Service/PreparadorDeLogoDoEscritorio.php'      => 'GD gera os bytes em memória (destino null); grava pelo armazenamento (TENANT_LOGO)',
        'src/Comercial/Controller/CampanhaDoMasterController.php'  => 'só lê o upload em trânsito da imagem; quem grava é o armazenamento',
        'src/Inteligencia/Juridico/CatalogoDeQuestoesJuridicas.php' => 'catálogo do repositório (config/inteligencia), não arquivo de usuário',
        'src/Inteligencia/Juridico/DocumentosJuridicosAtuais.php'   => 'modelos do repositório (resources/legal), não arquivo de usuário',
        'src/Master/Controller/FontesPublicasController.php'       => 'só lê o upload em trânsito da tabela do Master (vai para o cache, não para o acervo)',
        'src/TrabalhoExterno/Service/ArmazenadorDeArquivosDoCurriculo.php' => 'mede o upload em trânsito e recorta a foto em memória (GD, destino null); grava pelo armazenamento',
        'src/Master/Fontes/ArmazemDeFontes.php'                    => 'lock files das fontes públicas em var/master-fontes',
        // Export: PhpWord/PhpSpreadsheet escrevem em php://output, nunca no acervo.
        'src/Pasta/UseCase/ExportarPecaTextoUseCase.php'           => 'escritor do PhpWord para php://output (imagens só pela AreaTemporariaPrivada)',
        'src/Ponto/Service/FolhaPontoXlsxExporter.php'             => 'escritor Xlsx para php://output',
        'src/Cobranca/Service/ListaCarteiraXlsxExporter.php'       => 'escritor Xlsx para php://output',
        'src/Djen/Controller/ProcessosDaOabController.php'         => 'CSV da lista de processos escrito em php://output',
        // Drive: o temporário do download e do upload em blocos (DT-8: descarte do download que falhou).
        'src/Sync/Service/GoogleDriveClient.php'                   => 'temporário do download/upload do Drive',
        'src/Sync/Service/ReconciliadorDePasta.php'                => 'cria e descarta o temporário do download',
        // Cobrança: import-tmp (D3 da E2 — temporário pela intenção, persistente pelo lugar; fora do espelho).
        'src/Cobranca/Controller/ImportacaoController.php'         => 'import-tmp (D3)',
        'src/Cobranca/DTO/ImportarRelatorioInput.php'              => 'assinatura dos primeiros bytes do upload temporário',
        // Recurso estático da aplicação, não arquivo do usuário.
        'src/Shared/Service/GeradorDePdfComDompdf.php'             => 'logo estático no PDF (o do resumo da pasta mudou para cá)',
        'src/TrabalhoExterno/Service/DocumentoDoCurriculo.php'     => 'logo estático no PDF do currículo (as imagens do currículo saem pela AreaTemporariaPrivada)',
        'src/Novidades/Service/LeitorDeNovidades.php'              => 'lê o arquivo de novidades da própria aplicação',
        // Commands de operador: leem arquivos do operador ou abrem lock/saída, fora do armazenamento.
        'src/AtualizacaoMonetaria/Command/ImportarIndicesMonetariosCommand.php' => 'planilha do operador',
        'src/Cobranca/Command/CarregarEspelhoRelatorioCommand.php'  => 'planilha do operador',
        'src/Cobranca/Command/ImportarRelatorioCobrancaCommand.php' => 'planilha do operador',
        'src/Command/CopiarArquivosAcervoCommand.php'              => 'lê a origem do operador; grava pelo armazenamento (D15)',
        'src/Command/ImportarAcervoCommand.php'                    => 'CSV do operador',
        'src/Command/MapearAcervoCommand.php'                      => 'JSON/planilhas do operador',
        'src/Command/ParsearAcervoCommand.php'                     => 'JSON/planilhas do operador',
        'src/Command/PurgarDadosExpiradosCommand.php'              => 'lock file da purga',
        'src/Djen/Command/SincronizarDjenCommand.php'              => 'lock file do DJEN',
        'src/Sync/Command/BackfillPastasCommand.php'               => 'lock/arquivo do operador',
        'src/Sync/Command/ReconciliarCommand.php'                  => 'lock file do sync',
        'src/Pasta/Command/RelatorioPastasParadasCommand.php'      => 'lock file do relatório',
        'src/Pasta/Command/RelatorioSemProcessoCommand.php'        => 'lock file do relatório',
        'src/Pasta/Command/ResumoSemanalAoClienteCommand.php'      => 'lock file do resumo semanal',
        'src/Profile/Command/ResumoDiarioCommand.php'              => 'lock file do resumo diário',
        // Falso positivo do regex: método chamado `link()`, que casa com a função `link(` do PHP. Não tocam o disco.
        'src/Agenda/Service/AvisoDoCompromisso.php'                => 'falso positivo: método link()',
        'src/Auth/Controller/SuporteEntradaController.php'         => 'falso positivo: método link()',
        'src/Comercial/Campanha/RenderizadorDeBlocos.php'          => 'falso positivo: método link()',
        'src/TrabalhoExterno/Controller/VagasPublicasController.php' => 'falso positivo: método link()',
        'src/TrabalhoExterno/UseCase/ExportarEmailDaCampanhaUseCase.php' => 'falso positivo: método link()',
        'src/Shared/EspelhoRemoto/Command/ReconciliarEspelhoCommand.php' => 'grava o relatório JSON pedido em --saida (P2.3)',
        'src/Shared/EspelhoRemoto/CacheDeLeituraDoEspelho.php'     => 'cache de leitura do que veio do R2, nome = SHA, publicado só depois de conferido (leitura de recuperação, D3)',
        'src/Shared/EspelhoRemoto/R2/EspelhoRemotoR2.php'          => 'fwrite só no stream de destino que o chamador entregou ao baixar',
    ];

    /** Os oito diretórios de upload e os binds: só o resolvedor os conhece. */
    private const DIRETORIOS_DE_UPLOAD = '/\$(?:uploadsDir|justificativasUploadsDir|chamadosUploadsDir|clientesUploadsDir|fotosPerfilDir|cobrancasUploadsDir|kanbanUploadsDir|tarefasUploadsDir)\b|%(?:uploads_dir|justificativas_uploads_dir|chamados_uploads_dir|clientes_uploads_dir|fotos_perfil_dir|cobrancas_uploads_dir|kanban_uploads_dir|tarefas_uploads_dir)%/';

    /** @var list<string> */
    private const QUEM_CONHECE_OS_DIRETORIOS = [
        'src/Cobranca/Controller/ImportacaoController.php',      // import-tmp (D3)
        'src/Shared/Armazenamento/ResolvedorDeCaminhoLocal.php', // o único que deve
    ];

    #[TestDox('só os arquivos da lista tocam o disco direto — a lista é exata, cada entrada justificada')]
    public function testSoQuemEstaNaListaTocaODisco(): void
    {
        $esperados = array_keys(self::QUEM_TOCA_O_DISCO);
        sort($esperados);

        self::assertSame(
            $esperados,
            $this->arquivosQueCasam(self::FUNCOES_DE_DISCO),
            'arquivo de usuário é endereçado por chave; acesso novo ao disco escapa do espelho no R2 (P2). '
            . 'Se a entrada saiu porque o arquivo deixou de tocar o disco, tire-a da lista.',
        );
    }

    #[TestDox('os oito diretórios de upload só são conhecidos pelo resolvedor (e pelo import-tmp, D3)')]
    public function testSoOResolvedorConheceOsDiretorios(): void
    {
        self::assertSame(self::QUEM_CONHECE_OS_DIRETORIOS, $this->arquivosQueCasam(self::DIRETORIOS_DE_UPLOAD));
    }

    #[TestDox('nenhum código em src/ monta caminho com public/uploads à mão')]
    public function testNinguemMontaPublicUploads(): void
    {
        self::assertSame([], $this->arquivosQueCasam('#public/uploads#'));
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

            if (preg_match($padrao, $this->semComentarios((string) file_get_contents($arquivo->getPathname()))) === 1) {
                $achados[] = substr($arquivo->getPathname(), \strlen($raiz) + 1);
            }
        }

        sort($achados);

        return $achados;
    }

    private function semComentarios(string $codigo): string
    {
        $limpo = '';
        foreach (token_get_all($codigo) as $token) {
            if (\is_array($token) && \in_array($token[0], [\T_COMMENT, \T_DOC_COMMENT], true)) {
                continue;
            }
            $limpo .= \is_array($token) ? $token[1] : $token;
        }

        return $limpo;
    }
}
