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
 */
final class LimpezaDeArquivosArquiteturaTest extends TestCase
{
    /**
     * Único caso legítimo hoje: a purga do escritório apaga `uploads/pastas/<tenantId>/` inteiro
     * por `glob` + `unlink`. Ali o tenant está sendo destruído por completo — peças, imagens e
     * linhas somem juntas —, então não existe referência a preservar.
     */
    private const ALLOWLIST = [
        'src/Tenant/UseCase/PurgarEscritorioUseCase.php',
    ];

    private const VARREDURA = '/\b(glob|scandir)\s*\(|RecursiveDirectoryIterator|DirectoryIterator/';
    private const REMOCAO   = '/\bunlink\s*\(|->excluir\s*\(/';

    #[TestDox('Nenhuma rotina nova combina varredura de diretório com remoção de arquivo')]
    public function testNaoHaLimpezaIngenuaForaDaAllowlist(): void
    {
        $raiz        = \dirname(__DIR__, 2);
        $suspeitos   = [];

        /** @var \SplFileInfo $arquivo */
        foreach ($this->arquivosPhpDeSrc($raiz) as $arquivo) {
            $conteudo = (string) file_get_contents($arquivo->getPathname());

            if (preg_match(self::VARREDURA, $conteudo) !== 1) {
                continue;
            }
            if (preg_match(self::REMOCAO, $conteudo) !== 1) {
                continue;
            }

            $relativo = str_replace($raiz . '/', '', $arquivo->getPathname());
            if (!in_array($relativo, self::ALLOWLIST, true)) {
                $suspeitos[] = $relativo;
            }
        }

        self::assertSame(
            [],
            $suspeitos,
            "Estes arquivos varrem diretório E removem arquivo:\n  - " . implode("\n  - ", $suspeitos)
            . "\n\nAntes de liberar: um arquivo pode estar vivo sem ter linha no banco (imagens do"
            . "\neditor de peças). Consulte ArquivosReferenciadosEmPecas antes de apagar, e só"
            . "\nentão acrescente o caminho à ALLOWLIST deste teste, com a justificativa.",
        );
    }

    #[TestDox('A allowlist não tem entrada morta (caminho que não existe mais)')]
    public function testAllowlistNaoTemEntradaMorta(): void
    {
        $raiz = \dirname(__DIR__, 2);

        foreach (self::ALLOWLIST as $relativo) {
            self::assertFileExists(
                $raiz . '/' . $relativo,
                "A allowlist cita {$relativo}, que não existe mais — remova a entrada.",
            );
        }
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
