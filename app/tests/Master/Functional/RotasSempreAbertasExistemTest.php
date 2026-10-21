<?php

declare(strict_types=1);

namespace App\Tests\Master\Functional;

use App\Master\Service\CatalogoDeRecursos;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Routing\RouterInterface;

/**
 * Cada nome em `CatalogoDeRecursos::ROTAS_SEMPRE_ABERTAS` é uma rota real: um nome errado (ou uma rota
 * renomeada) deixaria a rota de verdade TRAVADA quando a tela fosse desligada, sem ninguém ver.
 */
#[CoversClass(CatalogoDeRecursos::class)]
final class RotasSempreAbertasExistemTest extends KernelTestCase
{
    /**
     * Rotas de frentes ainda não integradas a esta base: o nome entra antes, a rota chega com a frente. Só são
     * toleradas enquanto ausentes; quando a frente entrar, a rota passa a ser conferida como as outras.
     */
    private const DE_OUTRA_FRENTE = [
        'contribua_webhook_cora' => 'Contribua C4 (#88), webhook do banco',
    ];

    #[TestDox('cada nome de ROTAS_SEMPRE_ABERTAS existe no roteador (salvo as rotas de frentes ainda não integradas)')]
    public function testCadaNomeEhUmaRotaReal(): void
    {
        self::bootKernel();
        $colecao = static::getContainer()->get(RouterInterface::class)->getRouteCollection();

        $faltando = [];
        foreach (CatalogoDeRecursos::ROTAS_SEMPRE_ABERTAS as $nome) {
            if ($colecao->get($nome) === null && !array_key_exists($nome, self::DE_OUTRA_FRENTE)) {
                $faltando[] = $nome;
            }
        }

        self::assertSame([], $faltando);
        self::assertNotNull($colecao->get('pasta_push_links_revogar'), 'o revogar do link externo do Push');
    }
}
