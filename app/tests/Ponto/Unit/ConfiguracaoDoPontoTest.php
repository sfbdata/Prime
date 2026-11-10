<?php

declare(strict_types=1);

namespace App\Tests\Ponto\Unit;

use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Ponto\Entity\ConfiguracaoDoPonto;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

/**
 * A configuração do Ponto do escritório (E7): os padrões que as etapas seguintes leem (P70, P73, P74) e o mapa de
 * chaves, em que chave ausente vale ligada (P96).
 */
#[CoversClass(ConfiguracaoDoPonto::class)]
#[Group('ponto')]
final class ConfiguracaoDoPontoTest extends TestCase
{
    #[TestDox('Nasce com os padrões: 150 m, 120 h, 6 meses, trabalho externo ligado e nenhuma chave')]
    public function testPadroes(): void
    {
        $tenant = new Tenant();
        $configuracao = new ConfiguracaoDoPonto($tenant);

        self::assertSame([150, 120, 6, true], [
            ConfiguracaoDoPonto::RAIO_PADRAO_METROS,
            ConfiguracaoDoPonto::BANCO_LIMITE_HORAS_PADRAO,
            ConfiguracaoDoPonto::BANCO_PRAZO_MESES_PADRAO,
            ConfiguracaoDoPonto::PERMITE_TRABALHO_EXTERNO_PADRAO,
        ]);
        self::assertSame(150, $configuracao->getRaioPadraoMetros());
        self::assertSame(120, $configuracao->getBancoLimiteHoras());
        self::assertSame(6, $configuracao->getBancoPrazoMeses());
        self::assertTrue($configuracao->permiteTrabalhoExterno());
        self::assertSame([], $configuracao->getChaves());
        self::assertSame($tenant, $configuracao->getTenant());
        self::assertNull($configuracao->getAtualizadoPor());
        self::assertTrue($configuracao->estaLigada('ponto-geo'), 'chave ausente vale ligada');
    }

    #[TestDox('definir grava a chave e carimba quem e quando, sem mexer nas outras')]
    public function testDefinir(): void
    {
        $configuracao = new ConfiguracaoDoPonto(new Tenant());
        $quem = new User();
        $quando = new \DateTimeImmutable('2026-11-09 10:30:00');

        $configuracao->definir('ponto-geo', false, $quem, $quando);

        self::assertFalse($configuracao->estaLigada('ponto-geo'));
        self::assertTrue($configuracao->estaLigada('ponto-facial'));
        self::assertSame(['ponto-geo' => false], $configuracao->getChaves());
        self::assertSame($quem, $configuracao->getAtualizadoPor());
        self::assertSame($quando, $configuracao->getAtualizadoEm());

        $configuracao->definir('ponto-geo', true, $quem, $quando);
        self::assertSame(['ponto-geo' => true], $configuracao->getChaves());
    }
}
