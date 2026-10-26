<?php

declare(strict_types=1);

namespace App\Tests\Arquitetura;

use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

/**
 * `BonusDeEspaco` não é TenantAware (como o `EspacoDoEscritorio`: o Master no escritório X lê o Y). O isolamento é
 * estrutural: só o domínio Contribua a consulta, sempre por tenant ou indicação explícitos, pelo
 * `BonusDeEspacoRepository`. Outro domínio chega ao bônus pela leitura pública `SituacaoDoEspaco` ou pelo caso de
 * uso de concessão.
 */
final class BonusDeEspacoSoNoContribuaArquiteturaTest extends TestCase
{
    /** Fora do Contribua, só a purga de escritório toca a tabela (apaga as linhas do escritório). */
    private const FORA_DO_CONTRIBUA_PERMITIDOS = [
        'src/Tenant/UseCase/PurgarEscritorioUseCase.php',
    ];

    #[TestDox('fora do Contribua ninguém cita BonusDeEspaco, o repositório dele ou a tabela (salvo a purga)')]
    public function testSoOContribuaConsultaOBonus(): void
    {
        $achados = array_filter(
            $this->arquivosQueCasam('/\bBonusDeEspaco(Repository)?\b|contribua_bonus_de_espaco/'),
            static fn (string $arquivo): bool => !str_starts_with($arquivo, 'src/Contribua/'),
        );

        self::assertSame(self::FORA_DO_CONTRIBUA_PERMITIDOS, array_values($achados));
    }

    #[TestDox('dentro do Contribua, só o repositório monta consulta à entidade e ninguém usa findAll/findBy do repositório do bônus')]
    public function testSoORepositorioConsulta(): void
    {
        $consultas = array_filter(
            $this->arquivosQueCasam('/getRepository\s*\(\s*BonusDeEspaco::class|FROM\s+[\\\\\w]*BonusDeEspaco\b/'),
            static fn (string $arquivo): bool => str_starts_with($arquivo, 'src/Contribua/'),
        );
        $genericas = array_filter(
            $this->arquivosQueCasam('/BonusDeEspacoRepository[\s\S]*->\s*(findAll|findBy|findOneBy|count)\s*\(/'),
            static fn (string $arquivo): bool => $arquivo !== 'src/Contribua/Repository/BonusDeEspacoRepository.php',
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
