<?php

declare(strict_types=1);

namespace App\Tests\Inteligencia\Functional;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\Exception\IrreversibleMigration;
use Doctrine\ORM\EntityManagerInterface;
use DoctrineMigrations\Version20261105120000;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

// A pasta migrations/ não entra no autoload do Composer.
require_once dirname(__DIR__, 3) . '/migrations/Version20261105120000.php';

/**
 * Item 1.18 da auditoria: o texto do modelo (resumo, pontos, quem age, texto da análise, texto bruto e a versão
 * editada) sai das linhas antigas do audit_log da AnaliseDeInteligencia, em diff.changes, diff.after e diff.before.
 * Status, modelo, tokens, datas e quem pediu ficam; as outras entidades ficam como estavam.
 */
#[CoversClass(Version20261105120000::class)]
final class LimpezaDoTextoDaIaNoAuditLogTest extends KernelTestCase
{
    private const ANALISE = 'App\Inteligencia\Entity\AnaliseDeInteligencia';

    /** Os campos de texto com o valor que a linha antiga guardava. */
    private const TEXTO = [
        'resumo' => 'RESUMO-SIGILOSO',
        'pontos' => [['tipo' => 'prazo', 'texto' => 'PONTO-SIGILOSO']],
        'quemAge' => 'QUEM-SIGILOSO',
        'textoDaAnalise' => 'ANALISE-SIGILOSA',
        'textoBruto' => 'BRUTO-SIGILOSO',
        'resumoEditado' => 'EDITADO-SIGILOSO',
        'pontosEditados' => [['tipo' => 'info', 'texto' => 'PONTO-EDITADO-SIGILOSO']],
    ];

    private Connection $banco;

    protected function setUp(): void
    {
        $this->banco = static::getContainer()->get(EntityManagerInterface::class)->getConnection();
    }

    #[TestDox('Criação, alteração e exclusão da análise perdem o texto do modelo; status, modelo, tokens e datas ficam')]
    public function testTiraOTextoDosTresCaminhos(): void
    {
        $criacao = $this->registro(self::ANALISE, 'create', ['after' => self::TEXTO + ['status' => 'pendente', 'modelo' => null, 'tipo' => 'analise_pasta']]);
        $conclusao = $this->registro(self::ANALISE, 'update', ['changes' => self::mudou(self::TEXTO) + self::mudou([
            'status' => 'concluida',
            'modelo' => 'falso-1',
            'tokensEntrada' => 120,
            'tokensSaida' => 40,
        ])]);
        $edicao = $this->registro(self::ANALISE, 'update', ['changes' => self::mudou([
            'resumoEditado' => 'EDITADO-SIGILOSO',
            'pontosEditados' => [['tipo' => 'info', 'texto' => 'PONTO-EDITADO-SIGILOSO']],
            'editadaEm' => '2026-11-01T10:00:00+00:00',
        ])]);
        $exclusao = $this->registro(self::ANALISE, 'delete', ['before' => self::TEXTO + ['status' => 'concluida', 'excluidaEm' => '2026-11-02T10:00:00+00:00']]);

        $this->rodar('up');

        // O jsonb reordena as chaves: a comparação é com as chaves em ordem.
        self::assertSame(self::emOrdem(['status' => 'pendente', 'modelo' => null, 'tipo' => 'analise_pasta']), self::emOrdem($this->diff($criacao)['after']));
        self::assertSame(self::emOrdem(self::mudou(['status' => 'concluida', 'modelo' => 'falso-1', 'tokensEntrada' => 120, 'tokensSaida' => 40])), self::emOrdem($this->diff($conclusao)['changes']));
        self::assertSame(['editadaEm'], array_keys($this->diff($edicao)['changes']));
        self::assertSame(self::emOrdem(['status' => 'concluida', 'excluidaEm' => '2026-11-02T10:00:00+00:00']), self::emOrdem($this->diff($exclusao)['before']));
        foreach ([$criacao, $conclusao, $edicao, $exclusao] as $id) {
            self::assertStringNotContainsString('SIGILOS', $this->changes($id));
            self::assertSame(['route' => 'pasta'], json_decode($this->changes($id), true)['context'], 'o contexto fica');
        }
        self::assertSame(0, (int) $this->banco->fetchOne(
            "SELECT count(*) FROM audit_log WHERE entity_class = :classe AND changes::text ~ '\"(textoBruto|textoDaAnalise|resumo|pontos)\"'",
            ['classe' => self::ANALISE],
        ), 'nenhuma linha da análise guarda textoBruto, textoDaAnalise, resumo ou pontos');
    }

    #[TestDox('Linha da análise sem diff, com diff vazio em lista ou sem texto passa sem erro e sem mudar')]
    public function testLinhaSemTextoFicaComoEstava(): void
    {
        $semDiff = (int) $this->banco->fetchOne(
            "INSERT INTO audit_log (action, entity_class, entity_id, changes, created_at) VALUES ('update', :c, '999000001', NULL, NOW()) RETURNING id",
            ['c' => self::ANALISE],
        );
        $listaVazia = $this->registro(self::ANALISE, 'update', ['changes' => [], 'conteudo_particular' => true]);
        $semTexto = $this->registro(self::ANALISE, 'update', ['changes' => self::mudou(['lidaEm' => '2026-11-03T10:00:00+00:00'])]);
        $antes = [$listaVazia => $this->changes($listaVazia), $semTexto => $this->changes($semTexto)];

        $this->rodar('up');

        self::assertNull($this->banco->fetchOne('SELECT changes FROM audit_log WHERE id = :id', ['id' => $semDiff]));
        foreach ($antes as $id => $changes) {
            self::assertSame($changes, $this->changes($id), 'a linha sem texto não é reescrita');
        }
    }

    #[TestDox('Outras entidades com campos de mesmo nome ficam como estavam')]
    public function testOutraEntidadeFicaComoEstava(): void
    {
        $artigo = $this->registro('App\Blog\Entity\Artigo', 'update', ['changes' => self::mudou(['resumo' => 'Resumo público do artigo'])]);
        $antes = $this->changes($artigo);

        $this->rodar('up');

        self::assertSame($antes, $this->changes($artigo));
    }

    #[TestDox('O down recusa: o texto apagado não volta')]
    public function testDownIrreversivel(): void
    {
        $this->expectException(IrreversibleMigration::class);

        $this->rodar('down');
    }

    private function rodar(string $direcao): void
    {
        $migration = new Version20261105120000($this->banco, new NullLogger());
        $migration->{$direcao}(new Schema());
        foreach ($migration->getSql() as $query) {
            $this->banco->executeStatement($query->getStatement(), $query->getParameters());
        }
    }

    /**
     * O formato do subscriber numa alteração: cada campo vira {to, from}.
     *
     * @param array<string, mixed> $campos
     *
     * @return array<string, array{to: mixed, from: null}>
     */
    private static function mudou(array $campos): array
    {
        return array_map(static fn (mixed $valor): array => ['to' => $valor, 'from' => null], $campos);
    }

    /**
     * @param array<string, mixed> $valores
     *
     * @return array<string, mixed>
     */
    private static function emOrdem(array $valores): array
    {
        $valores = array_map(static fn (mixed $v): mixed => is_array($v) ? self::emOrdem($v) : $v, $valores);
        ksort($valores);

        return $valores;
    }

    /** @param array<string, mixed> $diff */
    private function registro(string $classe, string $acao, array $diff): int
    {
        return (int) $this->banco->fetchOne(
            'INSERT INTO audit_log (action, entity_class, entity_id, changes, created_at) VALUES (:a, :c, :e, :j, NOW()) RETURNING id',
            ['a' => $acao, 'c' => $classe, 'e' => '999000001', 'j' => json_encode(['diff' => $diff, 'context' => ['route' => 'pasta']])],
        );
    }

    private function changes(int $id): string
    {
        return (string) $this->banco->fetchOne('SELECT changes::text FROM audit_log WHERE id = :id', ['id' => $id]);
    }

    /** @return array<string, mixed> */
    private function diff(int $id): array
    {
        return json_decode($this->changes($id), true)['diff'];
    }
}
