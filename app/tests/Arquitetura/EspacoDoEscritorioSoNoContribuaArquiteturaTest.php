<?php

declare(strict_types=1);

namespace App\Tests\Arquitetura;

use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

/**
 * `EspacoDoEscritorio` não é TenantAware (o filtro da sessão faria o Master no escritório X ver o Y
 * como Free). A garantia de isolamento é estrutural: só o domínio Contribua a consulta, e sempre por
 * tenantId explícito. Este teste impede que outro domínio passe a consultá-la (DQL, `findBy`,
 * `getRepository`, SQL cru na tabela) sem passar pela leitura pública `SituacaoDoEspaco`.
 */
final class EspacoDoEscritorioSoNoContribuaArquiteturaTest extends TestCase
{
    /** Fora do Contribua, só a purga de escritório toca a tabela (apaga a linha do escritório). */
    private const FORA_DO_CONTRIBUA_PERMITIDOS = [
        'src/Tenant/UseCase/PurgarEscritorioUseCase.php',
    ];

    #[TestDox('fora do Contribua ninguém cita EspacoDoEscritorio, o repositório dele ou a tabela (salvo a purga)')]
    public function testSoOContribuaConsultaOEspaco(): void
    {
        $achados = array_filter(
            $this->arquivosQueCasam('/\bEspacoDoEscritorio(Repository)?\b|contribua_espaco_do_escritorio/'),
            static fn (string $arquivo): bool => !str_starts_with($arquivo, 'src/Contribua/'),
        );

        self::assertSame(self::FORA_DO_CONTRIBUA_PERMITIDOS, array_values($achados));
    }

    #[TestDox('dentro do Contribua, só o repositório monta consulta à entidade')]
    public function testSoORepositorioConsulta(): void
    {
        $achados = array_filter(
            $this->arquivosQueCasam('/getRepository\s*\(\s*EspacoDoEscritorio::class|FROM\s+[\\\\\w]*EspacoDoEscritorio\b/'),
            static fn (string $arquivo): bool => str_starts_with($arquivo, 'src/Contribua/'),
        );

        self::assertSame([], array_values($achados));
    }

    /** @return list<string> */
    private function arquivosQueCasam(string $padrao): array
    {
        $raiz = \dirname(__DIR__, 2);
        $encontrados = [];
        $iterador = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($raiz . '/src', \FilesystemIterator::SKIP_DOTS));

        foreach ($iterador as $arquivo) {
            if (!$arquivo instanceof \SplFileInfo || $arquivo->getExtension() !== 'php') {
                continue;
            }
            if (preg_match($padrao, $this->semComentarios((string) file_get_contents($arquivo->getPathname()))) === 1) {
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
            if (\is_array($token) && \in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }
            $limpo .= \is_array($token) ? $token[1] : $token;
        }

        return $limpo;
    }
}
