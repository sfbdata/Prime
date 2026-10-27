<?php

declare(strict_types=1);

namespace App\Tests\Dashboard\Unit\Inteligencia;

use App\Dashboard\Exception\PreferenciaInvalidaException;
use App\Dashboard\Inteligencia\MotorAvancado;
use App\Dashboard\Preferencia\CatalogoDePreferenciasDoDashboard;
use App\Dashboard\Preferencia\MarcasDosAlertas;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

/** Formato e alternância das marcas pessoais dos alertas (Resolvido, Ignorar, Aplicar, Não se aplica). */
#[CoversClass(MarcasDosAlertas::class)]
#[Group('dashboard')]
final class MarcasDosAlertasTest extends TestCase
{
    #[TestDox('Marcar status e retorno de um alerta; repetir a marca ativa a desfaz')]
    public function testAlternar(): void
    {
        $mapa = MarcasDosAlertas::alternar([], MotorAvancado::ID_VENCIDAS, 'resolvido');
        self::assertSame(['status' => 'resolvido', 'feedback' => null], $mapa['vencidas']);

        $mapa = MarcasDosAlertas::alternar($mapa, 'vencidas', 'sim');
        self::assertSame(['status' => 'resolvido', 'feedback' => 'sim'], $mapa['vencidas']);

        $mapa = MarcasDosAlertas::alternar($mapa, 'vencidas', 'ignorado');
        self::assertSame('ignorado', $mapa['vencidas']['status'], 'trocar de status substitui');

        $mapa = MarcasDosAlertas::alternar($mapa, 'vencidas', 'ignorado');
        self::assertNull($mapa['vencidas']['status'], 'repetir desfaz');

        $mapa = MarcasDosAlertas::alternar($mapa, 'vencidas', 'sim');
        self::assertArrayNotHasKey('vencidas', $mapa, 'marca vazia não é guardada');
    }

    #[TestDox('Alerta ou ação desconhecidos são recusados')]
    public function testRecusaDesconhecidos(): void
    {
        $this->expectException(PreferenciaInvalidaException::class);
        MarcasDosAlertas::alternar([], 'inventado', 'resolvido');
    }

    #[TestDox('Ação desconhecida é recusada')]
    public function testRecusaAcao(): void
    {
        $this->expectException(PreferenciaInvalidaException::class);
        MarcasDosAlertas::alternar([], 'vencidas', 'apagar');
    }

    #[TestDox('Normalizar aceita o mapa válido e recusa lista, id estranho e valor fora da lista')]
    public function testNormalizarValida(): void
    {
        self::assertSame([], MarcasDosAlertas::normalizar([]));
        self::assertSame(
            ['prazos' => ['status' => 'ignorado', 'feedback' => null]],
            MarcasDosAlertas::normalizar(['prazos' => ['status' => 'ignorado']]),
        );

        foreach ([['a'], ['x' => ['status' => 'resolvido']], ['prazos' => ['status' => 'tarefa']], 'texto'] as $ruim) {
            try {
                MarcasDosAlertas::normalizar($ruim);
                self::fail('deveria recusar ' . json_encode($ruim));
            } catch (PreferenciaInvalidaException) {
                self::addToAssertionCount(1);
            }
        }
    }

    #[TestDox('Leitura do banco com marca de alerta que não existe mais cai no vazio, sem quebrar')]
    public function testLerOuVazio(): void
    {
        self::assertSame([], MarcasDosAlertas::lerOuVazio(['antigo' => ['status' => 'resolvido']]));
        self::assertSame([], MarcasDosAlertas::lerOuVazio(null));
    }

    #[TestDox('Todos os ids da Central são aceitos; a chave fica fora do catálogo do menu (Restaurar padrão não a toca)')]
    public function testIdsEMenu(): void
    {
        foreach (MotorAvancado::IDS as $id) {
            self::assertArrayHasKey($id, MarcasDosAlertas::alternar([], $id, 'resolvido'));
        }
        self::assertNotContains(MarcasDosAlertas::CHAVE, CatalogoDePreferenciasDoDashboard::chaves());
    }
}
