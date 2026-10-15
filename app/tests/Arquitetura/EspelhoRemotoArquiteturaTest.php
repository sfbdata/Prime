<?php

declare(strict_types=1);

namespace App\Tests\Arquitetura;

use App\Shared\EspelhoRemoto\EspelhoRemoto;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

/**
 * As regras da P2 que o código tem de carregar, não só a spec (spec P2 §0, §4, §9).
 *
 *  - **Nenhuma exclusão remota** (regra 0): o contrato não tem o verbo, e nenhum arquivo de `src/`
 *    chama exclusão do S3.
 *  - **O SDK fica no adaptador**: só `src/Shared/EspelhoRemoto/R2/` conhece o `async-aws`.
 *  - **Nada ligado na P2.2**: o espelho não tem consumidor nem configuração — quem o liga é a P2.3,
 *    com autorização própria. Este teste cai no dia em que alguém o ligar; quem ligar atualiza a
 *    lista e a spec juntos.
 */
#[CoversNothing]
final class EspelhoRemotoArquiteturaTest extends TestCase
{
    private const DIRETORIO_DO_ESPELHO = 'src/Shared/EspelhoRemoto/';
    private const DIRETORIO_DO_ADAPTADOR = 'src/Shared/EspelhoRemoto/R2/';

    #[TestDox('o contrato do espelho só tem enviar, consultar e existe — nenhum verbo de exclusão (regra 0 da P2)')]
    public function testContratoSemExclusao(): void
    {
        $metodos = array_map(
            static fn (\ReflectionMethod $m): string => $m->getName(),
            (new \ReflectionClass(EspelhoRemoto::class))->getMethods(),
        );
        sort($metodos);

        self::assertSame(['consultar', 'enviar', 'existe'], $metodos);
    }

    #[TestDox('nenhum arquivo de src/ chama exclusão do S3 (deleteObject, deleteObjects, DeleteObject*)')]
    public function testNinguemChamaExclusaoRemota(): void
    {
        self::assertSame([], $this->arquivosQueCasam('/\bdeleteObjects?\s*\(|\bDeleteObjects?(?:Request|Input)\b|\bdeleteBucket\w*\s*\(/i'));
    }

    #[TestDox('dentro do adaptador R2 não existe a palavra delete fora de comentário')]
    public function testAdaptadorNaoConheceDelete(): void
    {
        $achados = array_filter(
            $this->arquivosQueCasam('/delete/i'),
            static fn (string $arquivo): bool => str_starts_with($arquivo, self::DIRETORIO_DO_ESPELHO),
        );

        self::assertSame([], array_values($achados));
    }

    #[TestDox('o async-aws só é conhecido pelo adaptador R2')]
    public function testSdkSoNoAdaptador(): void
    {
        $fora = array_filter(
            $this->arquivosQueCasam('/\bAsyncAws\\\\/'),
            static fn (string $arquivo): bool => !str_starts_with($arquivo, self::DIRETORIO_DO_ADAPTADOR),
        );

        self::assertSame([], array_values($fora));
    }

    #[TestDox('o layout de chaves é puro: sem framework, sem SDK, sem ambiente, sem banco')]
    public function testLayoutEhPuro(): void
    {
        $codigo = $this->semComentarios((string) file_get_contents(\dirname(__DIR__, 2) . '/src/Shared/EspelhoRemoto/LayoutDeChaveRemota.php'));

        self::assertDoesNotMatchRegularExpression('/\b(?:Symfony|Doctrine|AsyncAws|getenv|\$_(?:ENV|SERVER)|EntityManager|Connection)\b/', $codigo);
    }

    #[TestDox('P2.2: o espelho não tem consumidor em src/ — a gravação dupla não está ligada')]
    public function testEspelhoNaoEstaLigado(): void
    {
        $fora = array_filter(
            $this->arquivosQueCasam('/\bApp\\\\Shared\\\\EspelhoRemoto\b|\bEspelhoRemoto(?:R2)?\b|\bClienteR2\b/'),
            static fn (string $arquivo): bool => !str_starts_with($arquivo, self::DIRETORIO_DO_ESPELHO),
        );

        self::assertSame([], array_values($fora), 'ligar o espelho é a P2.3, com autorização própria');
    }

    #[TestDox('P2.2: nenhuma configuração liga o espelho nem declara credencial do R2')]
    public function testNadaNaConfiguracao(): void
    {
        $raiz    = \dirname(__DIR__, 2);
        $achados = [];

        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($raiz . '/config', \FilesystemIterator::SKIP_DOTS)) as $arquivo) {
            /** @var \SplFileInfo $arquivo */
            if (preg_match('/EspelhoRemoto|ClienteR2|AsyncAws|\bR2_[A-Z_]+|r2\.cloudflarestorage/i', (string) file_get_contents($arquivo->getPathname())) === 1) {
                $achados[] = substr($arquivo->getPathname(), \strlen($raiz) + 1);
            }
        }

        self::assertSame([], $achados);
    }

    /** @return list<string> */
    private function arquivosQueCasam(string $padrao): array
    {
        $raiz    = \dirname(__DIR__, 2);
        $achados = [];

        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($raiz . '/src', \FilesystemIterator::SKIP_DOTS)) as $arquivo) {
            /** @var \SplFileInfo $arquivo */
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
