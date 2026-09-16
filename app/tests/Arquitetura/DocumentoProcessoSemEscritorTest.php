<?php

declare(strict_types=1);

namespace App\Tests\Arquitetura;

use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

/**
 * `documento_processo` NÃO tem escritor — e por isso saiu da purga na E2.5 (decisão do dono).
 *
 * ## A premissa, e por que ela precisa de guarda
 *
 * A entidade e o repositório existem, mas nada em `src/` grava `caminho_arquivo` fora da fixture
 * (que grava `fixtures/<nome>`, um valor que `ChaveDeArquivo` recusaria). A tabela está vazia em
 * produção e no `saas_ux`, não há diretório de upload para ela e não existe categoria em
 * `CategoriaDeArquivo`. A purga consultava a coluna e tentava cada nome em quatro diretórios
 * alheios — um produto cartesiano que só não apagava nada porque não havia nada.
 *
 * Tirar a consulta é honesto **enquanto** a premissa valer. No dia em que alguém criar um upload
 * de documento de processo, este teste cai e obriga a decidir, junto: a 10ª categoria, a fábrica
 * de chave, a exclusão pós-COMMIT e a volta à purga — senão os arquivos do escritório purgado
 * ficariam para sempre no disco.
 *
 * ## O que ele cerca, e o limite
 *
 * As portas honestas: citar a classe (DQL por FQCN, FormType, factory), instanciá-la, citar a
 * tabela em SQL, e migration que escreva a coluna. Não pega nome montado dinamicamente
 * (`'documento_' . 'processo'`) — é cerca para mudança de boa-fé, não prova contra autor malicioso.
 */
final class DocumentoProcessoSemEscritorTest extends TestCase
{
    /**
     * Quem cita a classe hoje, e por quê:
     *
     *  - a entidade, o repositório (vazio) e a coleção em `Processo`;
     *  - `AppFixtures` — só dev/test (`config/bundles.php`), grava `fixtures/<nome>`.
     */
    private const QUEM_CITA_A_CLASSE = [
        'src/DataFixtures/AppFixtures.php',
        'src/Processo/Entity/DocumentoProcesso.php',
        'src/Processo/Entity/Processo.php',
        'src/Processo/Repository/DocumentoProcessoRepository.php',
    ];

    #[TestDox('só a entidade, o repositório, o Processo e a fixture citam DocumentoProcesso')]
    public function testQuemCitaAClasseEhAListaConhecida(): void
    {
        self::assertSame(
            self::QUEM_CITA_A_CLASSE,
            $this->arquivosQueCasam('src', '/\bDocumentoProcesso\b/'),
            'Apareceu um novo usuário de DocumentoProcesso. Se ele grava arquivo, a E2.5 precisa ser '
            . 'revista: categoria nova, fábrica de chave, exclusão pós-COMMIT e volta à purga.',
        );
    }

    #[TestDox('só a fixture instancia DocumentoProcesso')]
    public function testSoAFixtureInstancia(): void
    {
        self::assertSame(
            ['src/DataFixtures/AppFixtures.php'],
            $this->arquivosQueCasam('src', '/\bnew\s+\\\\?(App\\\\Processo\\\\Entity\\\\)?DocumentoProcesso\b/'),
        );
    }

    #[TestDox('nenhum SQL de src/, config/ ou templates/ cita a tabela documento_processo')]
    public function testNinguemCitaATabela(): void
    {
        foreach (['src', 'config', 'templates'] as $diretorio) {
            self::assertSame(
                [],
                $this->arquivosQueCasam($diretorio, '/\bdocumento_processo\b/i'),
                "{$diretorio}/ passou a citar documento_processo — a purga voltou a precisar dela?",
            );
        }
    }

    #[TestDox('nenhuma migration grava caminho_arquivo em documento_processo')]
    public function testNenhumaMigrationGravaOCaminho(): void
    {
        self::assertSame(
            [],
            $this->arquivosQueCasam('migrations', '/\b(INSERT\s+INTO|UPDATE|COPY)\s+"?documento_processo"?\b[^;]*\bcaminho_arquivo\b/i'),
        );
    }

    #[TestDox('a purga não consulta mais documento_processo')]
    public function testAPurgaNaoConsulta(): void
    {
        $codigo = $this->semComentarios(
            (string) file_get_contents(\dirname(__DIR__, 2) . '/src/Tenant/UseCase/PurgarEscritorioUseCase.php'),
        );

        self::assertDoesNotMatchRegularExpression('/documento_processo/i', $codigo);
    }

    /** @return list<string> */
    private function arquivosQueCasam(string $diretorio, string $padrao): array
    {
        $raiz        = \dirname(__DIR__, 2);
        $encontrados = [];

        if (!is_dir($raiz . '/' . $diretorio)) {
            return [];
        }

        $iterador = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($raiz . '/' . $diretorio, \FilesystemIterator::SKIP_DOTS),
        );

        foreach ($iterador as $arquivo) {
            if (!$arquivo instanceof \SplFileInfo || !\in_array($arquivo->getExtension(), ['php', 'yaml', 'yml', 'twig', 'sql'], true)) {
                continue;
            }

            $conteudo = (string) file_get_contents($arquivo->getPathname());
            if ($arquivo->getExtension() === 'php') {
                $conteudo = $this->semComentarios($conteudo);
            }

            if (preg_match($padrao, $conteudo) === 1) {
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
                if ($token[0] !== \T_COMMENT && $token[0] !== \T_DOC_COMMENT) {
                    $limpo .= $token[1];
                }

                continue;
            }

            $limpo .= $token;
        }

        return $limpo;
    }
}
