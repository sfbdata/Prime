<?php

declare(strict_types=1);

namespace App\Tests\Master\Unit;

use App\Master\Service\MapaDeAtuacao;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

#[CoversClass(MapaDeAtuacao::class)]
final class MapaDeAtuacaoTest extends TestCase
{
    private const HOJE = '2026-10-16 12:00:00';

    #[TestDox('Cidade do escritório com HTML sai sem < > " \' `')]
    public function testCidadeDoEscritorioSegura(): void
    {
        $mapa = MapaDeAtuacao::montar([], [['id' => 1, 'cidade' => '"><script>alert(1)</script>', 'estado' => 'DF']], new \DateTimeImmutable());

        $cidades = array_keys($mapa['escritorios']['C']['DF']);
        self::assertSame(['scriptalert(1)/script'], $cidades);
    }

    #[TestDox('Indicações contam no local de quem indicou: enviadas e contas abertas; "pago" fica zerado; indicador sem local não entra')]
    public function testIndicacoesNoLocalDeQuemIndicou(): void
    {
        $v = static fn (int $t, string $lot): array => ['tenantId' => $t, 'lotacao' => $lot, 'primario' => true, 'ultimoAcesso' => ''];
        $pessoas = [
            ['id' => 1, 'criadoEm' => '2026-01-01 10:00:00', 'primario' => true, 'oabUf' => null, 'vinculos' => [$v(1, 'Maceió/AL')]],
            ['id' => 2, 'criadoEm' => '2026-01-01 10:00:00', 'primario' => true, 'oabUf' => 'SP', 'vinculos' => [$v(2, '')]],
            ['id' => 3, 'criadoEm' => '2026-01-01 10:00:00', 'primario' => true, 'oabUf' => null, 'vinculos' => [$v(3, '')]],
        ];

        $mapa = MapaDeAtuacao::montar($pessoas, [], new \DateTimeImmutable(self::HOJE), [], [
            ['indicadorId' => 1, 'enviada' => true, 'virouConta' => true],
            ['indicadorId' => 1, 'enviada' => true, 'virouConta' => false],
            ['indicadorId' => 1, 'enviada' => false, 'virouConta' => true],
            ['indicadorId' => 2, 'enviada' => true, 'virouConta' => false],
            ['indicadorId' => 3, 'enviada' => true, 'virouConta' => true],
            ['indicadorId' => 99, 'enviada' => true, 'virouConta' => true],
        ]);

        self::assertSame(['AL' => 2, 'SP' => 1], $mapa['indicacoes']['env']['A']);
        self::assertSame(['AL' => ['Maceió' => 2]], $mapa['indicacoes']['env']['C']);
        self::assertSame(['AL' => 2], $mapa['indicacoes']['ok']['A']);
        self::assertSame(['A' => [], 'C' => []], $mapa['indicacoes']['pago']);
    }

    #[TestDox('Pessoas contam uma vez; "Só primários" filtra; tendência compara entradas de 30 e 60 dias; escritórios sem estado vão à parte')]
    public function testAgrega(): void
    {
        $hoje = new \DateTimeImmutable('2026-10-16 12:00:00');
        $pessoa = static fn (string $criado, bool $prim, ?string $oabUf, array $vinculos): array => [
            'criadoEm' => $criado, 'primario' => $prim, 'oabUf' => $oabUf, 'vinculos' => $vinculos,
        ];
        $v = static fn (int $t, string $lot, bool $prim): array => ['tenantId' => $t, 'lotacao' => $lot, 'primario' => $prim, 'ultimoAcesso' => ''];

        $mapa = MapaDeAtuacao::montar([
            // dois vínculos, conta uma vez só, na lotação
            $pessoa('2026-10-10 10:00:00', true, 'DF', [$v(1, 'Maceió/AL', true), $v(2, '', true)]),
            $pessoa('2026-09-01 10:00:00', false, 'AL', [$v(1, '', false)]),
            $pessoa('2025-01-01 10:00:00', false, null, [$v(2, '', false)]),
        ], [
            ['id' => 1, 'cidade' => 'Brasília', 'estado' => 'DF'],
            ['id' => 2, 'cidade' => null, 'estado' => null],
        ], $hoje);

        $geral = $mapa['pessoas']['geral'];
        self::assertSame(['AL' => 2], $geral['A']);
        self::assertSame(['AL' => ['Maceió' => 1]], $geral['C']);
        self::assertSame(['AL' => ['ag' => 1, 'an' => 1]], $geral['tend']);
        self::assertSame(1, $geral['semLoc']);
        self::assertSame(3, $geral['total']);

        self::assertSame(['AL' => 1], $mapa['pessoas']['prim']['A']);
        self::assertSame(1, $mapa['pessoas']['prim']['total']);

        self::assertSame(['DF' => 1], $mapa['escritorios']['A']);
        self::assertSame(['DF' => ['Brasília' => 1]], $mapa['escritorios']['C']);
        self::assertSame(1, $mapa['escritorios']['semEnd']);
    }

    /**
     * @return array{saiuEm: string, eraPrimario: bool, tenantId: int, userId: ?int, oabUf: ?string, estado: ?string}
     */
    private static function saida(string $saiuEm, bool $primario = false, ?int $userId = null, ?string $oabUf = null, int $tenantId = 1, ?string $estado = 'GO'): array
    {
        return ['saiuEm' => $saiuEm, 'eraPrimario' => $primario, 'tenantId' => $tenantId, 'userId' => $userId, 'oabUf' => $oabUf, 'estado' => $estado];
    }

    /**
     * @param list<array<string, mixed>> $saidas
     * @param list<array<string, mixed>> $pessoas
     *
     * @return array<string, array<string, array{ag: int, an: int}>>
     */
    private static function tendencias(array $saidas, array $pessoas = []): array
    {
        $mapa = MapaDeAtuacao::montar($pessoas, [['id' => 1, 'cidade' => null, 'estado' => 'GO']], new \DateTimeImmutable(self::HOJE), $saidas);

        return ['geral' => $mapa['pessoas']['geral']['tend'], 'prim' => $mapa['pessoas']['prim']['tend']];
    }

    #[TestDox('Saída subtrai na UF da OAB; sem OAB, na UF do escritório de onde saiu')]
    public function testSaidaSubtraiNaUfCerta(): void
    {
        $t = self::tendencias([
            self::saida('2026-10-10 12:00:00', false, 10, 'DF'),
            self::saida('2026-10-11 12:00:00', false, 11, null),
        ]);

        self::assertSame(['DF' => ['ag' => -1, 'an' => 0], 'GO' => ['ag' => -1, 'an' => 0]], $t['geral']);
    }

    #[TestDox('Janelas das saídas iguais às das entradas: 30 dias exatos é "ag", 60 exatos é "an", acima de 60 fica fora')]
    public function testJanelasEBordas(): void
    {
        $t = self::tendencias([
            self::saida('2026-09-16 12:00:00', false, 1),  // 30 dias exatos
            self::saida('2026-09-16 11:59:59', false, 2),  // 30 dias e 1 segundo
            self::saida('2026-08-17 12:00:00', false, 3),  // 60 dias exatos
            self::saida('2026-08-17 11:59:59', false, 4),  // 60 dias e 1 segundo
            self::saida('2026-10-16 12:00:00', false, 5),  // agora
        ]);
        self::assertSame(['GO' => ['ag' => -2, 'an' => -2]], $t['geral']);

        // As mesmas bordas valem para as entradas (pessoas criadas nos mesmos instantes).
        $v = [['tenantId' => 1, 'lotacao' => '', 'primario' => false, 'ultimoAcesso' => '']];
        $pessoas = array_map(static fn (string $criado): array => ['criadoEm' => $criado, 'primario' => false, 'oabUf' => null, 'vinculos' => $v], [
            '2026-09-16 12:00:00', '2026-09-16 11:59:59', '2026-08-17 12:00:00', '2026-08-17 11:59:59', '2026-10-16 12:00:00',
        ]);
        self::assertSame(['GO' => ['ag' => 2, 'an' => 2]], self::tendencias([], $pessoas)['geral']);
    }

    #[TestDox('"Só primários" conta só a saída com era_primario; "Geral" conta todas')]
    public function testSoPrimarios(): void
    {
        $t = self::tendencias([
            self::saida('2026-10-10 12:00:00', true, 1),
            self::saida('2026-10-10 12:00:00', false, 2),
            self::saida('2026-09-01 12:00:00', false, 3),
        ]);

        self::assertSame(['GO' => ['ag' => -2, 'an' => -1]], $t['geral']);
        self::assertSame(['GO' => ['ag' => -1, 'an' => 0]], $t['prim']);
    }

    #[TestDox('Sem usuário (user_id nulo) a saída cai na UF do escritório e cada linha conta')]
    public function testSemUsuarioCaiNoEscritorio(): void
    {
        $t = self::tendencias([
            self::saida('2026-10-10 12:00:00', true, null, null, 7, 'ba'),
            self::saida('2026-10-12 12:00:00', true, null, null, 7, 'BA'),
        ]);

        self::assertSame(['BA' => ['ag' => -2, 'an' => 0]], $t['geral']);
        self::assertSame(['BA' => ['ag' => -2, 'an' => 0]], $t['prim']);
    }

    #[TestDox('Saída sem UF (sem OAB e escritório sem estado válido) é ignorada')]
    public function testSemUfIgnorada(): void
    {
        $t = self::tendencias([
            self::saida('2026-10-10 12:00:00', false, 1, null, 2, null),
            self::saida('2026-10-10 12:00:00', false, 2, 'XX', 2, 'Goiás'),
        ]);

        self::assertSame([], $t['geral']);
    }

    #[TestDox('Quem saiu de um escritório e segue ativo em outro não conta como saída')]
    public function testQuemSegueAtivoNaoConta(): void
    {
        $ativa = ['id' => 42, 'criadoEm' => '2025-01-01 10:00:00', 'primario' => true, 'oabUf' => 'DF',
            'vinculos' => [['tenantId' => 1, 'lotacao' => '', 'primario' => true, 'ultimoAcesso' => '']]];

        $t = self::tendencias([self::saida('2026-10-10 12:00:00', true, 42, 'DF')], [$ativa]);

        self::assertSame(['DF' => ['ag' => 0, 'an' => 0]], $t['geral']);
        self::assertSame(['DF' => ['ag' => 0, 'an' => 0]], $t['prim']);
    }

    #[TestDox('A mesma pessoa conta uma saída só, a mais recente, mesmo saindo de dois escritórios')]
    public function testMesmaPessoaContaUmaVez(): void
    {
        $t = self::tendencias([
            self::saida('2026-10-10 12:00:00', false, 9, null, 1, 'GO'),
            self::saida('2026-09-01 12:00:00', true, 9, null, 3, 'SP'),
        ]);

        self::assertSame(['GO' => ['ag' => -1, 'an' => 0]], $t['geral'], 'geral: a mais recente, no escritório dela');
        self::assertSame(['SP' => ['ag' => 0, 'an' => -1]], $t['prim'], 'só primários: a mais recente entre as de primário');
    }

    #[TestDox('Saldo: entradas menos saídas, podendo ficar negativo')]
    public function testSaldoNegativo(): void
    {
        $v = [['tenantId' => 1, 'lotacao' => '', 'primario' => false, 'ultimoAcesso' => '']];
        $entrou = ['id' => 1, 'criadoEm' => '2026-10-05 10:00:00', 'primario' => false, 'oabUf' => null, 'vinculos' => $v];

        $t = self::tendencias([
            self::saida('2026-10-10 12:00:00', false, 2),
            self::saida('2026-10-11 12:00:00', false, 3),
            self::saida('2026-10-12 12:00:00', false, 4),
            self::saida('2026-09-10 12:00:00', false, 5),
        ], [$entrou]);

        self::assertSame(['GO' => ['ag' => -2, 'an' => -1]], $t['geral']);
    }
}
