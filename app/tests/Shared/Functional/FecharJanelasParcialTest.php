<?php

declare(strict_types=1);

namespace App\Tests\Shared\Functional;

use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Twig\Environment;

/** O parcial que o `layout/_globais.html.twig` inclui (frente menu-do-usuario) carrega o CSS e o JS do X de fechar. */
final class FecharJanelasParcialTest extends KernelTestCase
{
    #[TestDox('layout/_globais/fechar.html.twig carrega fechar-janelas.css e fechar-janelas.js (defer)')]
    public function testParcial(): void
    {
        self::bootKernel();
        $html = static::getContainer()->get(Environment::class)->render('layout/_globais/fechar.html.twig');

        self::assertMatchesRegularExpression('#<link rel="stylesheet" href="[^"]*/css/fechar-janelas\.css">#', $html);
        self::assertMatchesRegularExpression('#<script src="[^"]*/js/fechar-janelas\.js" defer></script>#', $html);
    }
}
