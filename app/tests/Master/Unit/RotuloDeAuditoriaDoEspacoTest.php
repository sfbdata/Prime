<?php

declare(strict_types=1);

namespace App\Tests\Master\Unit;

use App\Contribua\Entity\ConfiguracaoDoEspaco;
use App\Contribua\Entity\Plano;
use App\Contribua\Event\EspacoAtingiuFaixa;
use App\Entity\Tenant\Tenant;
use App\Master\Service\RotuloDeAuditoria;
use App\Master\Service\RotuloDeAuditoriaDoEspaco;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

/**
 * Rótulos da trilha para limites, planos, faixas e o alerta de consumo (spec perfis-1.2-planos-e-limites P7
 * e P11; desenho 05 - Perfis 1.2, linhas 481 e 732).
 */
#[CoversClass(RotuloDeAuditoriaDoEspaco::class)]
#[CoversClass(RotuloDeAuditoria::class)]
final class RotuloDeAuditoriaDoEspacoTest extends TestCase
{
    private const ESPACO = 'App\Contribua\Entity\EspacoDoEscritorio';

    /** @return iterable<string, array{string, array<string, mixed>, string, string}> */
    public static function limites(): iterable
    {
        yield 'GB para GB' => ['update', ['cotaGb' => ['from' => 15, 'to' => 20], 'justificativa' => ['from' => 'a', 'to' => 'cliente contratou ampliação']], 'Limite de armazenamento', '15 GB → 20 GB · cliente contratou ampliação'];
        yield 'GB para sem limite' => ['update', ['cotaGb' => ['from' => 20, 'to' => null], 'semLimite' => ['from' => false, 'to' => true], 'justificativa' => ['from' => 'a', 'to' => 'dono']], 'Limite de armazenamento', '20 GB → sem limite · dono'];
        yield 'sem limite para GB' => ['update', ['cotaGb' => ['from' => null, 'to' => 30], 'semLimite' => ['from' => true, 'to' => false]], 'Limite de armazenamento', 'sem limite → 30 GB'];
        yield 'plano para sem limite' => ['update', ['semLimite' => ['from' => false, 'to' => true]], 'Limite de armazenamento', 'limite do plano → sem limite'];
        yield 'usuários' => ['update', ['limiteUsuarios' => ['from' => 4, 'to' => 5], 'justificativa' => ['from' => 'a', 'to' => 'novo estagiário']], 'Limite de usuários', '4 usuários → 5 usuários · novo estagiário'];
        yield 'mesmo valor, justificativa nova' => ['update', ['justificativa' => ['from' => 'a', 'to' => 'revisão']], 'Limite mantido', 'valor sem mudança · revisão'];
    }

    /** @param array<string, mixed> $diff */
    #[DataProvider('limites')]
    #[TestDox('limite: $esperadoAcao, "$esperadoDetalhe"')]
    public function testLimite(string $acao, array $diff, string $esperadoAcao, string $esperadoDetalhe): void
    {
        $rotulo = RotuloDeAuditoria::traduzir($acao, self::ESPACO, (string) json_encode(['diff' => ['changes' => $diff]]));

        self::assertSame(['acao' => $esperadoAcao, 'detalhe' => $esperadoDetalhe], $rotulo);
    }

    #[TestDox('primeira mudança (criação da linha): o anterior é o limite do plano')]
    public function testCriacao(): void
    {
        $armazenamento = ['diff' => ['after' => ['cotaGb' => 25, 'semLimite' => false, 'limiteUsuarios' => null, 'justificativa' => 'ampliação']]];
        $usuarios = ['diff' => ['after' => ['cotaGb' => null, 'semLimite' => false, 'limiteUsuarios' => 5, 'justificativa' => 'estagiário']]];

        self::assertSame(['acao' => 'Limite de armazenamento', 'detalhe' => 'limite do plano → 25 GB · ampliação'], RotuloDeAuditoria::traduzir('create', self::ESPACO, (string) json_encode($armazenamento)));
        self::assertSame(['acao' => 'Limite de usuários', 'detalhe' => 'limite do plano → 5 usuários · estagiário'], RotuloDeAuditoria::traduzir('create', self::ESPACO, (string) json_encode($usuarios)));
    }

    #[TestDox('com o plano de hoje da conta, o anterior sai em número, como no desenho: "10 GB → 12 GB", "3 usuários → 5 usuários"')]
    public function testAnteriorComOPlanoDaConta(): void
    {
        $free = ['cotaGb' => 10, 'limiteUsuarios' => 3];
        $armazenamento = ['diff' => ['after' => ['cotaGb' => 12, 'semLimite' => false, 'limiteUsuarios' => null, 'justificativa' => 'ampliação']]];
        $usuarios = ['diff' => ['changes' => ['limiteUsuarios' => ['from' => null, 'to' => 5]]]];
        $deSemLimiteAoPlano = ['diff' => ['changes' => ['semLimite' => ['from' => true, 'to' => false]]]];

        self::assertSame('10 GB → 12 GB · ampliação', RotuloDeAuditoria::traduzir('create', self::ESPACO, (string) json_encode($armazenamento), $free)['detalhe']);
        self::assertSame('3 usuários → 5 usuários', RotuloDeAuditoria::traduzir('update', self::ESPACO, (string) json_encode($usuarios), $free)['detalhe']);
        self::assertSame('sem limite → 10 GB', RotuloDeAuditoria::traduzir('update', self::ESPACO, (string) json_encode($deSemLimiteAoPlano), $free)['detalhe']);
        self::assertSame('sem limite → 5 usuários', RotuloDeAuditoria::traduzir('update', self::ESPACO, (string) json_encode($usuarios), ['cotaGb' => 100, 'limiteUsuarios' => null])['detalhe'], 'Avançado: usuários sem limite');
    }

    #[TestDox('plano: "Plano alterado" com cada campo que mudou, preço em reais')]
    public function testPlano(): void
    {
        $diff = ['diff' => ['changes' => ['precoCentavos' => ['from' => 1990, 'to' => 18900], 'limiteUsuarios' => ['from' => null, 'to' => 20], 'ativo' => ['from' => true, 'to' => false]]]];

        self::assertSame(
            ['acao' => 'Plano alterado', 'detalhe' => 'preço: R$ 19,90 → R$ 189; usuários: sem limite → 20; situação: ativo → inativo'],
            RotuloDeAuditoria::traduzir('update', Plano::class, (string) json_encode($diff)),
        );
    }

    #[TestDox('faixas: "Faixas de alerta alteradas" com as faixas e a chave do bloqueio')]
    public function testFaixas(): void
    {
        $diff = ['diff' => ['changes' => ['faixaCritico' => ['from' => 95, 'to' => 97], 'bloqueioAtivo' => ['from' => true, 'to' => false]]]];

        self::assertSame(
            ['acao' => 'Faixas de alerta alteradas', 'detalhe' => 'Crítico: 95% → 97%; bloqueio de envio: ligado → desligado'],
            RotuloDeAuditoria::traduzir('update', ConfiguracaoDoEspaco::class, (string) json_encode($diff)),
        );
    }

    #[TestDox('alerta de consumo do Sistema: "Passou de N% do armazenamento"')]
    public function testAlertaDeConsumo(): void
    {
        self::assertSame(
            ['acao' => 'Alerta de consumo', 'detalhe' => 'Passou de 85% do armazenamento'],
            RotuloDeAuditoria::traduzir('alerta', EspacoAtingiuFaixa::class, '{"limiar":85,"percentual":88,"faixa":"alerta"}'),
        );
    }

    #[TestDox('as outras entidades continuam com o rótulo de antes (só nomes de campo)')]
    public function testOutrasEntidadesNaoMudam(): void
    {
        self::assertNull(RotuloDeAuditoriaDoEspaco::traduzir('update', Tenant::class, []));
        self::assertSame('Campos alterados: nome', RotuloDeAuditoria::traduzir('update', Tenant::class, '{"diff":{"changes":{"name":{"from":"a","to":"b"}}}}')['detalhe']);
    }

    #[TestDox('preço nos cartões e na trilha: "R$ 0", "R$ 189", "R$ 19,90", "R$ 1.990"')]
    public function testReais(): void
    {
        self::assertSame(['R$ 0', 'R$ 189', 'R$ 19,90', 'R$ 1.990'], array_map(RotuloDeAuditoriaDoEspaco::reais(...), [0, 18900, 1990, 199000]));
    }
}
