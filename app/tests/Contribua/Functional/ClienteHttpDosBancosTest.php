<?php

declare(strict_types=1);

namespace App\Tests\Contribua\Functional;

use App\Contribua\Banco\Bacen\ClientePixBacen;
use App\Contribua\Banco\ClienteHttpDosBancos;
use App\Contribua\Banco\Cora\ClienteCora;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * A app key do BB vai na query string e o `http_client` do framework registra a URL inteira no log. Prova,
 * no container de verdade, que os clientes dos bancos usam o ClienteHttpDosBancos (sem logger, sem
 * redirecionamento) e não o `http_client`.
 */
#[CoversClass(ClienteHttpDosBancos::class)]
final class ClienteHttpDosBancosTest extends KernelTestCase
{
    #[TestDox('Cora e API Pix do BACEN recebem o cliente dos bancos, que não é o http_client do framework e não tem logger')]
    public function testSemLogger(): void
    {
        $container = static::getContainer();
        $dosBancos = $container->get(ClienteHttpDosBancos::class);

        foreach ([ClientePixBacen::class, ClienteCora::class] as $classe) {
            self::assertSame($dosBancos, (new \ReflectionProperty($classe, 'http'))->getValue($container->get($classe)), $classe);
        }
        self::assertNotSame($container->get('http_client'), $dosBancos);
        $interno = (new \ReflectionProperty(ClienteHttpDosBancos::class, 'cliente'))->getValue($dosBancos);
        self::assertIsObject($interno);
        self::assertNull($this->logger($interno), 'o cliente das chamadas aos bancos não loga a URL');
    }

    #[TestDox('O cliente dos bancos nunca segue redirecionamento, mesmo que quem chama peça')]
    public function testSemRedirecionamento(): void
    {
        $dosBancos = new ClienteHttpDosBancos();
        $interno = (new \ReflectionProperty(ClienteHttpDosBancos::class, 'cliente'))->getValue($dosBancos);
        $padrao = (new \ReflectionProperty($interno, 'defaultOptions'))->getValue($interno);

        self::assertSame(0, $padrao['max_redirects']);
        self::assertSame(['max_redirects' => 0], ClienteHttpDosBancos::OPCOES);
    }

    private function logger(object $cliente): ?object
    {
        for ($r = new \ReflectionClass($cliente); false !== $r; $r = $r->getParentClass()) {
            if ($r->hasProperty('logger')) {
                $valor = $r->getProperty('logger')->getValue($cliente);

                return is_object($valor) ? $valor : null;
            }
        }

        return null;
    }
}
