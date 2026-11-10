<?php

declare(strict_types=1);

namespace App\Tests\Ponto\Integration;

use App\Entity\Tenant\Tenant;
use App\Ponto\Entity\ConfiguracaoDoPonto;
use App\Ponto\Service\LimitesDoBancoDeHoras;
use App\Ponto\Service\LimitesDoBancoDeHorasDoEscritorio;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * A tela usa o limite e o prazo salvos no escritório: o contêiner entrega `LimitesDoBancoDeHorasDoEscritorio` para a
 * interface, e ele lê a linha de `ponto_config_escritorio` (E7). Antes da E7 valiam os padrões para todo escritório.
 */
#[CoversClass(LimitesDoBancoDeHorasDoEscritorio::class)]
#[Group('ponto')]
final class LimitesDoBancoDeHorasDaConfiguracaoTest extends KernelTestCase
{
    #[TestDox('O contêiner entrega a leitura pela configuração do escritório')]
    public function testConteinerEntregaALeituraPelaConfiguracao(): void
    {
        self::assertInstanceOf(LimitesDoBancoDeHorasDoEscritorio::class, static::getContainer()->get(LimitesDoBancoDeHoras::class));
    }

    #[TestDox('Escritório sem configuração salva fica com 120 horas e 6 meses; com ela, os valores salvos')]
    public function testValoresSalvosNoEscritorio(): void
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $limites = static::getContainer()->get(LimitesDoBancoDeHoras::class);
        $tenant = (new Tenant())->setName('Tenant LIMITES DO BANCO ' . uniqid());
        $em->persist($tenant);
        $em->flush();

        self::assertSame(120 * 60, $limites->limiteEmMinutos($tenant));
        self::assertSame(6, $limites->prazoEmMeses($tenant));

        $em->persist(new ConfiguracaoDoPonto($tenant));
        $em->flush();
        // A tela de Configurações que grava estes dois campos não existe ainda: o valor entra direto na linha.
        $em->getConnection()->executeStatement(
            'UPDATE ponto_config_escritorio SET banco_limite_horas = 40, banco_prazo_meses = 12 WHERE tenant_id = :t',
            ['t' => $tenant->getId()],
        );
        // Outro pedido: a leitura guarda a linha só durante um pedido (`reset()` entre um e outro).
        $id = $tenant->getId();
        self::ensureKernelShutdown();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $limites = static::getContainer()->get(LimitesDoBancoDeHoras::class);
        $tenant = $em->find(Tenant::class, $id);
        self::assertNotNull($tenant);

        self::assertSame(40 * 60, $limites->limiteEmMinutos($tenant));
        self::assertSame(12, $limites->prazoEmMeses($tenant));
    }
}
