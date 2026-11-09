<?php

declare(strict_types=1);

namespace App\Tests\Voto\Functional;

use App\Master\Service\RecursosDoSistema;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * A migration `Version20261109120000` (spec bluejus-voto §7, M1) desce e sobe de verdade no banco de teste, dentro da
 * transação do DAMA (o banco volta como estava). Prova o que nenhum teste de tela prova: o Voto NASCE desligado. Sem
 * linha em `recurso_estado` a chave vale como ativa, e ligada ela abre o Voto para o perfil de sistema de todo
 * escritório (bypass do PermissionChecker) antes de existir a liberação por escritório.
 */
#[CoversNothing]
final class MigracaoDoRecursoVotoTest extends KernelTestCase
{
    private const VERSAO = 'DoctrineMigrations\\Version20261109120000';

    #[TestDox('down tira a chave e a permissão; up cria a chave desligada e a permissão sem concessão; repetir o up não duplica')]
    public function testDownEUp(): void
    {
        self::bootKernel();
        self::assertSame(0, $this->executar('--down'));
        $banco = $this->banco();
        self::assertSame(0, $this->contar($banco, 'recurso_estado', 'chave', 'voto'));
        self::assertSame(0, $this->contar($banco, 'permission', 'code', 'modules.voto.view'));
        self::assertTrue(static::getContainer()->get(RecursosDoSistema::class)->estaAtivo('voto'), 'sem linha, a chave vale como ativa');

        // A migration executada fica congelada na fábrica de dependências: cada execução precisa de um kernel novo.
        // A conexão do DAMA é estática e segue a mesma, dentro da mesma transação.
        $this->subir();
        $banco = $this->banco();
        self::assertSame(
            ['ativo' => false, 'motivo' => null, 'ate' => null],
            $banco->fetchAssociative("SELECT ativo, motivo, ate FROM recurso_estado WHERE chave = 'voto'"),
        );
        self::assertFalse(static::getContainer()->get(RecursosDoSistema::class)->estaAtivo('voto'), 'o Voto nasce desligado');
        self::assertSame(
            ['description' => 'Acesso ao módulo Voto (eleições e votações)', 'group' => 'modules'],
            $banco->fetchAssociative("SELECT description, \"group\" FROM permission WHERE code = 'modules.voto.view'"),
        );
        self::assertSame(0, (int) $banco->fetchOne(
            "SELECT COUNT(*) FROM tenant_role_permission trp JOIN permission p ON p.id = trp.permission_id WHERE p.code = 'modules.voto.view'",
        ), 'nenhum perfil recebe a permissão');

        // Repetir o up: tira o registro da versão (senão o Doctrine recusa) e roda de novo sobre os dados já criados.
        $banco->executeStatement('DELETE FROM doctrine_migration_versions WHERE version = ?', [self::VERSAO]);
        $this->subir();
        $banco = $this->banco();
        self::assertSame(1, $this->contar($banco, 'recurso_estado', 'chave', 'voto'));
        self::assertSame(1, $this->contar($banco, 'permission', 'code', 'modules.voto.view'));
    }

    private function subir(): void
    {
        self::ensureKernelShutdown();
        self::bootKernel();
        self::assertSame(0, $this->executar('--up'));
    }

    private function executar(string $sentido): int
    {
        $application = new Application(self::$kernel);
        $tester = new CommandTester($application->find('doctrine:migrations:execute'));

        return $tester->execute(['versions' => [self::VERSAO], $sentido => true], ['interactive' => false]);
    }

    private function banco(): Connection
    {
        return static::getContainer()->get(EntityManagerInterface::class)->getConnection();
    }

    private function contar(Connection $banco, string $tabela, string $coluna, string $valor): int
    {
        return (int) $banco->fetchOne(sprintf('SELECT COUNT(*) FROM %s WHERE %s = ?', $tabela, $coluna), [$valor]);
    }
}
