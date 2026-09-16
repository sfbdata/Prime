<?php

declare(strict_types=1);

namespace App\Tests\Arquitetura;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

/**
 * Trava o que a E2.4A entregou: upload HTTP chega ao armazenamento por UM caminho só — a
 * `FonteDeUploadHttp` —, e o `salvar(UploadedFile)` da interface antiga não tem mais chamador.
 *
 * Regex não faz análise de fluxo — por isso a allowlist é por arquivo e cada entrada diz quando
 * sai. É o mesmo desenho de `EntregaDeArquivoArquiteturaTest`.
 */
#[CoversNothing]
final class UploadPorChaveArquiteturaTest extends TestCase
{
    /**
     * Quem ainda move `UploadedFile` com as próprias mãos.
     *
     *  - `FonteDeUploadHttp` — é quem deve (INV-10);
     *  - `ArquivoStorageService` — o `salvar()` do shim de D2, sem chamador; sai na E2.8;
     *  - `ImportacaoController` — a planilha de importação vai para `import-tmp`, temporário que
     *    atravessa requisições e fica como está nesta E2 (D3);
     *  - `TarefaController` — o anexo de tarefa guarda caminho com `/`, que `ChaveDeArquivo`
     *    recusa (D5); a decisão é da E2.7.
     */
    private const QUEM_MOVE_UPLOAD = [
        'src/Cobranca/Controller/ImportacaoController.php',
        'src/Controller/TarefaController.php',
        'src/Shared/Http/FonteDeUploadHttp.php',
        'src/Shared/Service/ArquivoStorageService.php',
    ];

    /**
     * Regex não sabe o tipo do receptor, então são duas redes: o nome de propriedade que todo
     * consumidor usava (`storage`) e a assinatura antiga — `->salvar($arquivo, $diretorio...)`, com
     * o segundo argumento começando por um diretório (inclusive concatenado, como era a Cobrança:
     * `$this->cobrancasUploadsDir . '/' . $id`). Os `salvar($entidade, flush: ...)` de repositório
     * não casam com nenhuma das duas.
     */
    #[TestDox('ninguém em src/ grava mais pelo salvar(UploadedFile) antigo — os 14 uploads passam pela FonteDeUploadHttp')]
    public function testNinguemChamaOSalvarAntigo(): void
    {
        self::assertSame([], $this->arquivosQueCasam('/storage->salvar\s*\(/i'));
        self::assertSame([], $this->arquivosQueCasam('/->salvar\s*\(\s*\$[\w>-]+\s*,\s*\$[\w>-]*(?:dir|diretorio)/i'));
    }

    #[TestDox('só a FonteDeUploadHttp move upload para o armazenamento (fora as exceções datadas)')]
    public function testSoAPonteMoveUpload(): void
    {
        self::assertSame(self::QUEM_MOVE_UPLOAD, $this->arquivosQueCasam('/->move\s*\(/'));
    }

    /**
     * A prova de origem do upload fica com o framework (`UploadedFile::move()`); chamar
     * `move_uploaded_file()` direto quebraria o modo de teste e duplicaria a checagem.
     */
    #[TestDox('ninguém em src/ chama move_uploaded_file() direto')]
    public function testNinguemChamaMoveUploadedFile(): void
    {
        self::assertSame([], $this->arquivosQueCasam('/\bmove_uploaded_file\s*\(/'));
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
