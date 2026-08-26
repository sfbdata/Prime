<?php

declare(strict_types=1);

namespace App\Tests\Djen\Unit;

use App\DataFixtures\PermissionFixture;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Trava o contrato entre a migration `Version20260819160000` e o catálogo de permissões.
 *
 * O rótulo do módulo na tela de papéis (`TenantRoleType` monta a lista com
 * `$permission->getDescription()`) vem de DUAS fontes que ninguém obriga a concordar:
 *
 *   - `PermissionFixture` — semeia dev e teste;
 *   - `Version20260819160000` — é o que corrige **produção**.
 *
 * Se as duas divergirem, dev e produção passam a exibir textos diferentes para a mesma permissão, e
 * nada quebra: nenhum teste lê essa `description`, e o banco da suíte nem tem a linha (o clone do
 * `saas_test` vem com `permission` vazia). O defeito só apareceria na tela de um cliente.
 *
 * As migrations não estão no autoload do composer — o Doctrine as carrega por caminho —, daí o
 * `require_once` explícito abaixo.
 */
final class RenomeacaoPushProcessualTest extends TestCase
{
    private const ARQUIVO_MIGRATION = __DIR__ . '/../../../migrations/Version20260819160000.php';
    private const ARQUIVO_MIGRATION_ORIGINAL = __DIR__ . '/../../../migrations/Version20260706195821.php';

    public static function setUpBeforeClass(): void
    {
        require_once self::ARQUIVO_MIGRATION;
    }

    /**
     * @return array{code: string, description: string, group: string}
     */
    private function permissaoDoCatalogo(string $code): array
    {
        $reflexao = new \ReflectionClass(PermissionFixture::class);
        /** @var list<array{code: string, description: string, group: string}> $todas */
        $todas = $reflexao->getConstant('PERMISSIONS');

        foreach ($todas as $permissao) {
            if ($permissao['code'] === $code) {
                return $permissao;
            }
        }

        self::fail(sprintf('permissão "%s" não existe em PermissionFixture', $code));
    }

    #[Test]
    public function aMigrationEAFixtureGravamExatamenteOMesmoRotulo(): void
    {
        $doCatalogo = $this->permissaoDoCatalogo(\DoctrineMigrations\Version20260819160000::CODIGO);

        self::assertSame(
            \DoctrineMigrations\Version20260819160000::DESCRICAO_NOVA,
            $doCatalogo['description'],
            'a migration (que conserta a PRODUÇÃO) e a fixture (que semeia dev/teste) divergiram — '
            . 'a tela de papéis passaria a mostrar textos diferentes em cada ambiente'
        );
    }

    /**
     * O `down()` só é uma reversão de verdade se restaurar o valor que **realmente** estava lá. O valor
     * verdadeiro é o que a `Version20260706195821` inseriu quando criou a permissão; se alguém editar a
     * `DESCRICAO_ANTIGA` sem olhar, o rollback grava um texto que nunca existiu.
     */
    #[Test]
    public function oValorDeRollbackEExatamenteOQueAMigrationOriginalInseriu(): void
    {
        $original = file_get_contents(self::ARQUIVO_MIGRATION_ORIGINAL);
        self::assertIsString($original);

        // Extrai o literal EXATO do INSERT original. `assertStringContainsString` não serviria:
        // 'Acesso ao módulo DJEN' é substring do texto completo, então uma DESCRICAO_ANTIGA truncada
        // passaria — medido, o teste não pegava o defeito.
        $achou = preg_match(
            "/SELECT\s+'modules\\.djen\\.view',\s*'([^']*)'/u",
            $original,
            $captura
        );
        self::assertSame(1, $achou, 'não achei o INSERT da permissão em Version20260706195821');

        self::assertSame(
            $captura[1],
            \DoctrineMigrations\Version20260819160000::DESCRICAO_ANTIGA,
            'DESCRICAO_ANTIGA não bate byte a byte com o texto que Version20260706195821 inseriu — '
            . 'o down() restauraria um valor que nunca existiu no banco'
        );
    }

    /**
     * O `code` é a chave que o `PermissionChecker` compara e que os vínculos de papel referenciam
     * (5 linhas em `tenant_role_permission` na produção, medidas em 19/08/2026). A renomeação foi de
     * rótulo; se o code entrar no sed de alguém, os papéis perdem a permissão em silêncio.
     */
    #[Test]
    public function oCodigoDaPermissaoNaoFoiRenomeado(): void
    {
        self::assertSame('modules.djen.view', \DoctrineMigrations\Version20260819160000::CODIGO);
        self::assertSame('modules.djen.view', $this->permissaoDoCatalogo('modules.djen.view')['code']);
    }
}
