<?php

declare(strict_types=1);

namespace App\Tests\Arquitetura;

use App\Shared\VersaoDoSistema;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Trava juntos a versão do código e o CHANGELOG.md da raiz do repositório.
 *
 * A versão em {@see VersaoDoSistema::ATUAL} é a ÚLTIMA LANÇADA (a que tem tag vX.Y.Z). Ela só
 * muda no PR de release, que no mesmo commit transforma a seção "[Não lançado]" do CHANGELOG em
 * "[X.Y.Z] - AAAA-MM-DD". Se uma das duas mudar sem a outra, este teste — que roda no portão
 * Qualidade de todo PR — fica vermelho. Política: docs/versionamento.md.
 */
#[CoversClass(VersaoDoSistema::class)]
final class VersaoDoSistemaTest extends TestCase
{
    private const CHANGELOG = __DIR__ . '/../../../CHANGELOG.md';
    private const SEMVER = '(0|[1-9]\d*)\.(0|[1-9]\d*)\.(0|[1-9]\d*)';
    private const REPOSITORIO = 'https://github.com/sfbdata/Prime';

    public function testVersaoAtualSegueSemVerSemPrefixo(): void
    {
        self::assertMatchesRegularExpression('/^' . self::SEMVER . '$/', VersaoDoSistema::ATUAL);
    }

    public function testChangelogComecaPelaSecaoNaoLancado(): void
    {
        self::assertSame('## [Não lançado]', $this->titulosDeSecao()[0] ?? null);
    }

    public function testTodaSecaoEhNaoLancadoOuVersaoComData(): void
    {
        foreach (array_slice($this->titulosDeSecao(), 1) as $titulo) {
            self::assertMatchesRegularExpression(
                '/^## \[' . self::SEMVER . '\] - \d{4}-\d{2}-\d{2}$/',
                $titulo,
                'Seção fora do formato "## [X.Y.Z] - AAAA-MM-DD".',
            );
        }
    }

    public function testVersaoAtualEhAMaisRecenteLancadaNoChangelog(): void
    {
        $lancadas = array_keys($this->versoesLancadas());

        self::assertNotEmpty($lancadas, 'O CHANGELOG não tem nenhuma versão lançada.');
        self::assertSame(
            VersaoDoSistema::ATUAL,
            $lancadas[0],
            'VersaoDoSistema::ATUAL e a versão mais recente do CHANGELOG divergem — mude as duas no mesmo PR de release.',
        );
    }

    public function testVersoesEmOrdemDecrescenteComDatasReais(): void
    {
        $anterior = null;
        $dataAnterior = null;
        foreach ($this->versoesLancadas() as $versao => $data) {
            [$ano, $mes, $dia] = array_map('intval', explode('-', $data));
            self::assertTrue(checkdate($mes, $dia, $ano), "Data inválida na versão $versao: $data.");

            if ($anterior !== null) {
                self::assertTrue(
                    version_compare($versao, $anterior, '<'),
                    "Versões fora de ordem: $versao aparece depois de $anterior.",
                );
                self::assertLessThanOrEqual($dataAnterior, $data, "A versão $versao tem data posterior à de $anterior.");
            }
            $anterior = $versao;
            $dataAnterior = $data;
        }
    }

    public function testCadaSecaoTemSeuLinkDeReferencia(): void
    {
        $conteudo = $this->changelog();

        self::assertStringContainsString(
            '[Não lançado]: ' . self::REPOSITORIO . '/compare/v' . VersaoDoSistema::ATUAL . '...HEAD',
            $conteudo,
            'O link de [Não lançado] deve comparar a partir da versão atual.',
        );
        foreach (array_keys($this->versoesLancadas()) as $versao) {
            self::assertMatchesRegularExpression(
                '#^\[' . preg_quote($versao, '#') . '\]: ' . preg_quote(self::REPOSITORIO, '#') . '/(compare/v[\d.]+\.\.\.v|releases/tag/v)' . preg_quote($versao, '#') . '$#m',
                $conteudo,
                "Falta o link de referência da versão $versao.",
            );
        }
    }

    /** @return list<string> */
    private function titulosDeSecao(): array
    {
        preg_match_all('/^## .*$/m', $this->changelog(), $m);

        return $m[0];
    }

    /** @return array<string, string> versão => data, na ordem do arquivo */
    private function versoesLancadas(): array
    {
        preg_match_all('/^## \[(' . self::SEMVER . ')\] - (\d{4}-\d{2}-\d{2})$/m', $this->changelog(), $m, PREG_SET_ORDER);

        $versoes = [];
        foreach ($m as $linha) {
            $versoes[$linha[1]] = $linha[5];
        }

        return $versoes;
    }

    private function changelog(): string
    {
        self::assertFileExists(self::CHANGELOG, 'CHANGELOG.md ausente na raiz do repositório.');

        return (string) file_get_contents(self::CHANGELOG);
    }
}
