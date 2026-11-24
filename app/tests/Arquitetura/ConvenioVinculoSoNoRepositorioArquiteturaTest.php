<?php

declare(strict_types=1);

namespace App\Tests\Arquitetura;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

/**
 * `ConvenioVinculo` não é TenantAware (spec docs/specs/convenios.md, §3.2, D12), como o `BonusDeEspaco`. O isolamento é
 * estrutural: só o `ConvenioVinculoRepository` monta consulta, sempre por tenant explícito. Os outros chegam aos vínculos
 * pela leitura `ConveniosDoEscritorio` ou pelos casos de uso do domínio Master.
 */
#[CoversNothing]
final class ConvenioVinculoSoNoRepositorioArquiteturaTest extends TestCase
{
    /** Fora do Master, só a purga de escritório toca a tabela (apaga as linhas do escritório). */
    private const FORA_DO_MASTER_PERMITIDOS = [
        'src/Tenant/UseCase/PurgarEscritorioUseCase.php',
    ];

    #[TestDox('fora do domínio Master ninguém cita ConvenioVinculo, o repositório dele ou a tabela (salvo a purga)')]
    public function testSoOMasterCitaOVinculo(): void
    {
        $achados = array_filter(
            $this->arquivosQueCasam('/\bConvenioVinculo(Repository)?\b|convenio_vinculo/'),
            static fn (string $arquivo): bool => !str_starts_with($arquivo, 'src/Master/'),
        );

        self::assertSame(self::FORA_DO_MASTER_PERMITIDOS, array_values($achados));
    }

    #[TestDox('só o repositório monta consulta ao vínculo, e ninguém usa findAll/findBy/count do repositório dele')]
    public function testSoORepositorioConsulta(): void
    {
        $consultas = array_filter(
            $this->arquivosQueCasam('/getRepository\s*\(\s*ConvenioVinculo::class|FROM\s+[\\\\\w]*ConvenioVinculo\b|FROM\s+convenio_vinculo\b/'),
            static fn (string $arquivo): bool => $arquivo !== 'src/Master/Repository/ConvenioVinculoRepository.php',
        );
        $genericas = array_filter(
            $this->arquivosQueCasam('/ConvenioVinculoRepository[\s\S]*->\s*(findAll|findBy|findOneBy|count)\s*\(/'),
            static fn (string $arquivo): bool => $arquivo !== 'src/Master/Repository/ConvenioVinculoRepository.php',
        );

        self::assertSame([], array_values($consultas));
        self::assertSame([], array_values($genericas));
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
