<?php

declare(strict_types=1);

namespace App\Tests\Contribua\Functional;

use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\DomCrawler\Crawler;
use Twig\Environment;

/**
 * O arranjo do checkout (contribua/_checkout.html.twig) no Pagar.me: o CPF/CNPJ do titular nasce dentro do
 * elemento de montagem, para o contribua-pagarme.js levá-lo à grade Validade/CVV/CPF do desenho 08 (linha
 * 167). Nos outros gateways de formulário ele fica abaixo do campo seguro do SDK, como antes.
 */
final class CheckoutDoPagarmeTemplateTest extends KernelTestCase
{
    private function renderizar(string $gateway, string $modo = 'formulario'): Crawler
    {
        /** @var Environment $twig */
        $twig = self::getContainer()->get(Environment::class);
        $html = $twig->render('contribua/_checkout.html.twig', [
            'retorno' => null,
            'pagamento' => [
                'modo' => $modo,
                'gateway' => $gateway,
                'gatewayNome' => 'Gateway',
                'chavePublica' => 'pk_test_x',
                'pagadorNome' => 'Escritório',
                'email' => 'a@b.c',
                'documento' => '12345678000199',
            ],
        ]);

        return new Crawler($html);
    }

    #[TestDox('Pagar.me: CPF/CNPJ (rótulo do desenho) dentro do elemento que o módulo monta, valor do escritório')]
    public function testPagarmeLevaODocumentoParaOElemento(): void
    {
        $tela = $this->renderizar('pagarme');

        $documento = $tela->filter('.co-form > .co-elemento[data-co-elemento] > label.co-campo > input[data-cartao-documento]');
        self::assertCount(1, $documento);
        self::assertSame('12345678000199', $documento->attr('value'));
        self::assertSame('do titular', $documento->attr('placeholder'));
        self::assertSame('CPF/CNPJ', trim($tela->filter('.co-elemento > label.co-campo')->text()));
        self::assertCount(0, $tela->filter('.co-form > label.co-campo'), 'sem um segundo CPF/CNPJ fora da grade');
        self::assertCount(1, $tela->filter('[data-cartao-documento]'));
    }

    #[TestDox('Stripe e Mercado Pago: o CPF/CNPJ do titular continua abaixo do campo seguro do SDK')]
    public function testOutrosGatewaysDeFormularioNaoMudam(): void
    {
        foreach (['stripe', 'mercado_pago'] as $gateway) {
            $tela = $this->renderizar($gateway);
            self::assertCount(1, $tela->filter('.co-form > .co-elemento[data-co-elemento]'), $gateway);
            self::assertCount(0, $tela->filter('.co-elemento > *'), $gateway . ': elemento vazio para o SDK');
            self::assertCount(1, $tela->filter('.co-form > label.co-campo > input[data-cartao-documento]'), $gateway);
        }
    }

    #[TestDox('nenhum campo do cartão nasce no servidor: número, validade e CVV são do módulo, no navegador')]
    public function testCartaoNaoNasceNoTemplate(): void
    {
        $tela = $this->renderizar('pagarme');
        self::assertCount(0, $tela->filter('[data-cartao]'));
        self::assertCount(0, $tela->filter('input[autocomplete^="cc-"]'));
    }
}
