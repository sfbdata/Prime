<?php

declare(strict_types=1);

namespace App\Tests\Arquitetura;

use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

/**
 * Guarda arquitetural da regra instituída pela E1:
 *
 *   > "Arquivo sem linha própria no banco" NÃO significa "arquivo órfão".
 *
 * Imagens do editor de peças não têm linha no banco — vivem dentro do HTML da peça. Uma rotina que
 * varra um diretório e apague o que "não está no banco" destrói arquivo vivo. A auditoria E0
 * encontrou em produção uma imagem exatamente nessa condição.
 *
 * Este teste não consegue provar que uma futura rotina está CORRETA. O que ele faz é impedir que
 * ela apareça em silêncio: qualquer arquivo novo de `src/` que combine varredura de diretório com
 * remoção quebra a suíte e obriga quem escreveu a justificar a entrada na allowlist — e, ao fazê-lo,
 * a encarar `ArquivosReferenciadosEmPecas`.
 *
 * **Endurecido na E2.5:** o código é lido SEM comentários (um docblock que citasse "o `glob()`
 * antigo" mantinha uma entrada viva ou acusava inocente); os padrões ignoram caixa e cobrem
 * `FilesystemIterator`, `GlobIterator`, `opendir`/`readdir`, o Finder, `rmdir`, a função passada
 * como callable, o Filesystem do Symfony, `->excluirPrefixo(` e a porta de remoção
 * (`->remover(`); e uma entrada da allowlist só vale enquanto o arquivo ainda casa os DOIS
 * padrões — senão é entrada morta. Continua sendo regex: `unlink()` fora de qualquer varredura
 * não é assunto deste teste (é da E2.8).
 */
final class LimpezaDeArquivosArquiteturaTest extends TestCase
{
    /**
     * Único caso legítimo hoje: o backend de disco, que implementa `excluirPrefixo()` (D7). Ele
     * varre o diretório do escritório e apaga tudo — mas só depois de PROVAR que o prefixo pertence
     * exclusivamente àquele escritório (não é link, resolve para `<raiz real>/<id>`, não coincide
     * com raiz nenhuma) e de inventariar a árvore inteira sem seguir link. A decisão de apagar
     * o escritório inteiro é de quem chama (a purga), onde peças, imagens e linhas somem juntas.
     *
     * A purga saiu daqui na E2.5: ela não varre mais nada — pede o prefixo ao backend.
     */
    /**
     * `ExecucaoDoGhostscript` (E2.6A) varre e apaga um diretório que ELE MESMO acabou de criar, por
     * execução, dentro do temporário privado do processo — o `TMPDIR` do gs. Nunca vê o
     * armazenamento: o caminho é montado aqui (`<privado>/<hex aleatório>`), nada externo entra, e
     * o que some são os `gs_*` que o próprio Ghostscript escreveu ali dentro.
     */
    private const ALLOWLIST = [
        'src/Shared/Armazenamento/ArmazenamentoLocal.php',
        'src/Shared/Service/ExecucaoDoGhostscript.php',
    ];

    /** Sem distinção de caixa: o PHP aceita `GLOB(` e `Unlink(`. */
    private const VARREDURA = '/\b(glob|scandir|opendir|readdir)\s*\(|(?<![\w>:$])dir\s*\(|RecursiveDirectoryIterator|DirectoryIterator|FilesystemIterator|GlobIterator|Symfony\\\\Component\\\\Finder/i';

    /**
     * Inclui a porta de remoção da E2.5 (`->remover(` da `RemocaoAposTransacao`), a função passada
     * como callable (`array_map('unlink', …)`) e o Filesystem do Symfony — uma rotina que varresse um
     * diretório plano e mandasse os nomes para qualquer um deles passaria calada.
     */
    private const REMOCAO   = '/\b(unlink|rmdir)\s*\(|[\'"](unlink|rmdir)[\'"]|->\s*(excluir|excluirPrefixo|remover)\s*\(|Symfony\\\\Component\\\\Filesystem/i';

    #[TestDox('Nenhuma rotina nova combina varredura de diretório com remoção de arquivo')]
    public function testNaoHaLimpezaIngenuaForaDaAllowlist(): void
    {
        $suspeitos = array_values(array_diff($this->arquivosQueVarremERemovem(), self::ALLOWLIST));

        self::assertSame(
            [],
            $suspeitos,
            "Estes arquivos varrem diretório E removem arquivo:\n  - " . implode("\n  - ", $suspeitos)
            . "\n\nAntes de liberar: um arquivo pode estar vivo sem ter linha no banco (imagens do"
            . "\neditor de peças). Consulte ArquivosReferenciadosEmPecas antes de apagar, e só"
            . "\nentão acrescente o caminho à ALLOWLIST deste teste, com a justificativa.",
        );
    }

    #[TestDox('A allowlist não tem entrada morta — cada entrada ainda varre E remove')]
    public function testAllowlistNaoTemEntradaMorta(): void
    {
        $raiz = \dirname(__DIR__, 2);

        foreach (self::ALLOWLIST as $relativo) {
            self::assertFileExists(
                $raiz . '/' . $relativo,
                "A allowlist cita {$relativo}, que não existe mais — remova a entrada.",
            );
        }

        self::assertSame(
            [],
            array_values(array_diff(self::ALLOWLIST, $this->arquivosQueVarremERemovem())),
            'Entrada da allowlist que já não varre-e-remove é isenção sobrando: remova-a.',
        );
    }

    #[TestDox('Os padrões enxergam as formas de varrer e remover que o teste antigo não via')]
    public function testPadroesEnxergamAsFormasNovas(): void
    {
        foreach (['new \FilesystemIterator($d)', 'new GlobIterator($p)', 'opendir($d)', 'readdir($h)', '$x = dir($d)', 'use Symfony\Component\Finder\Finder;', 'scandir($d)'] as $varre) {
            self::assertSame(1, preg_match(self::VARREDURA, $varre), $varre);
        }
        foreach (['->dir($d)', 'Classe::dir($d)', '$redir($d)'] as $naoVarre) {
            self::assertSame(0, preg_match(self::VARREDURA, $naoVarre), $naoVarre);
        }
        foreach (['GLOB($d)', 'Scandir($d)'] as $varre) {
            self::assertSame(1, preg_match(self::VARREDURA, $varre), $varre);
        }
        foreach (['@rmdir($d)', '$p->excluirPrefixo($e, $c)', '@unlink($a)', '$s->excluir($c)', 'UNLINK($a)', 'RmDir($d)',
            "array_map('unlink', \$lista)", '$this->remocao->remover($chaves, $c)', 'use Symfony\Component\Filesystem\Filesystem;'] as $remove) {
            self::assertSame(1, preg_match(self::REMOCAO, $remove), $remove);
        }
    }

    /** @return list<string> caminhos relativos, ordenados */
    private function arquivosQueVarremERemovem(): array
    {
        $raiz      = \dirname(__DIR__, 2);
        $encontrados = [];

        /** @var \SplFileInfo $arquivo */
        foreach ($this->arquivosPhpDeSrc($raiz) as $arquivo) {
            $codigo = $this->semComentarios((string) file_get_contents($arquivo->getPathname()));

            if (preg_match(self::VARREDURA, $codigo) === 1 && preg_match(self::REMOCAO, $codigo) === 1) {
                $encontrados[] = str_replace($raiz . '/', '', $arquivo->getPathname());
            }
        }

        sort($encontrados);

        return $encontrados;
    }

    private function semComentarios(string $codigo): string
    {
        $limpo = '';

        foreach (token_get_all($codigo) as $token) {
            if (\is_array($token)) {
                if ($token[0] === \T_COMMENT || $token[0] === \T_DOC_COMMENT) {
                    continue;
                }

                $limpo .= $token[1];
                continue;
            }

            $limpo .= $token;
        }

        return $limpo;
    }

    /** @return iterable<\SplFileInfo> */
    private function arquivosPhpDeSrc(string $raiz): iterable
    {
        $iterador = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($raiz . '/src', \FilesystemIterator::SKIP_DOTS),
        );

        foreach ($iterador as $arquivo) {
            if ($arquivo instanceof \SplFileInfo && $arquivo->getExtension() === 'php') {
                yield $arquivo;
            }
        }
    }
}
