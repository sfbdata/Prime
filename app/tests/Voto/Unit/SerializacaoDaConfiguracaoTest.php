<?php

declare(strict_types=1);

namespace App\Tests\Voto\Unit;

use App\Entity\Tenant\Tenant;
use App\Voto\Service\SerializacaoDaConfiguracao;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\InMemoryStore;

#[CoversClass(SerializacaoDaConfiguracao::class)]
final class SerializacaoDaConfiguracaoTest extends TestCase
{
    public function testMesmaConfiguracaoFicaReservadaAteOTrabalhoTerminar(): void
    {
        $travas = new LockFactory(new InMemoryStore());
        $serializacao = new SerializacaoDaConfiguracao($travas);
        $tenant = new Tenant();
        (new \ReflectionProperty(Tenant::class, 'id'))->setValue($tenant, 3);
        $outraOperacao = $travas->createLock('voto_configuracao.3.7');
        $outroEvento = $travas->createLock('voto_configuracao.3.8');

        $resultado = $serializacao->executar($tenant, 7, function () use ($outraOperacao, $outroEvento): string {
            self::assertFalse($outraOperacao->acquire());
            self::assertTrue($outroEvento->acquire());
            $outroEvento->release();

            return 'concluído';
        });
        self::assertSame('concluído', $resultado);
        self::assertTrue($outraOperacao->acquire());
        $outraOperacao->release();
    }

    public function testRecusaLiberaAConfiguracaoParaCorrigirETentarNovamente(): void
    {
        $travas = new LockFactory(new InMemoryStore());
        $serializacao = new SerializacaoDaConfiguracao($travas);
        $tenant = new Tenant();
        try {
            $serializacao->executar($tenant, 7, static function (): void {
                throw new \DomainException('Recusado');
            });
            self::fail('Deveria recusar');
        } catch (\DomainException) {
            $outraOperacao = $travas->createLock('voto_configuracao..7');
            self::assertTrue($outraOperacao->acquire());
            $outraOperacao->release();
        }
    }
}
