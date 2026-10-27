<?php

declare(strict_types=1);

namespace App\Tests\Contribua\Contrato;

use App\Contribua\Gateway\AvisoNaoAutenticado;
use App\Contribua\Gateway\FalhaNoGateway;
use App\Contribua\Gateway\GatewayDeAssinatura;
use App\Contribua\Gateway\InicioDoCheckout;
use App\Contribua\Gateway\Pagador;
use App\Contribua\Gateway\PedidoDeCheckout;
use App\Contribua\Gateway\SituacaoDaAssinatura;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

/**
 * O contrato que TODO adaptador de gateway de cartão passa (Asaas, Mercado Pago, Stripe, Pagar.me).
 *
 * Cada adaptador cria `tests/Contribua/Unit/<Gateway>/<Gateway>ContratoTest extends` esta classe e
 * implementa os cenários abaixo, normalmente com o `MockHttpClient` do Symfony respondendo com os
 * exemplos da documentação oficial do gateway (cite a URL da doc no comentário de cada resposta). Os
 * testes daqui não sabem nada do gateway: só exigem o comportamento da interface.
 */
abstract class TesteDeContratoDoGatewayDeAssinatura extends TestCase
{
    /** Gateway cujo HTTP responde com sucesso à criação do checkout. */
    abstract protected function gatewayQueIniciaComSucesso(): GatewayDeAssinatura;

    /** Gateway cujo HTTP falha (500, tempo esgotado ou erro de transporte) em qualquer chamada. */
    abstract protected function gatewayForaDoAr(): GatewayDeAssinatura;

    /**
     * Gateway que, consultado, responde a assinatura na situação pedida (com o JSON real do gateway para
     * aquele estado), e o id dela.
     *
     * @return array{0: GatewayDeAssinatura, 1: string}
     */
    abstract protected function gatewayComAssinaturaNaSituacao(SituacaoDaAssinatura $situacao): array;

    /**
     * Um webhook autêntico do gateway (cabeçalhos em minúsculas e corpo exatamente como o gateway manda)
     * e o que dele se espera.
     *
     * @return array{gateway: GatewayDeAssinatura, cabecalhos: array<string, string>, corpo: string, idDoEvento: string, idDaAssinatura: ?string, referencia: ?string, idDoCliente: ?string}
     */
    abstract protected function avisoAutentico(): array;

    /** O mesmo webhook sem a credencial (ou com ela errada). @return array{gateway: GatewayDeAssinatura, cabecalhos: array<string, string>, corpo: string} */
    abstract protected function avisoSemCredencial(): array;

    /** true para gateway de página hospedada (REDIRECIONAR); false para tokenização no navegador (FORMULARIO). */
    abstract protected function ehHospedado(): bool;

    #[TestDox('Contrato: iniciar devolve página https hospedada ou formulário com chave pública')]
    public function testIniciar(): void
    {
        $inicio = $this->gatewayQueIniciaComSucesso()->iniciar(self::pedido());

        if ($this->ehHospedado()) {
            self::assertSame(InicioDoCheckout::REDIRECIONAR, $inicio->modo);
            self::assertStringStartsWith('https://', (string) $inicio->urlDeRedirecionamento);
        } else {
            self::assertSame(InicioDoCheckout::FORMULARIO, $inicio->modo);
            self::assertNotEmpty($inicio->chavePublica);
        }
    }

    #[TestDox('Contrato: gateway hospedado recusa token de cartão (o cartão nunca passa pelo servidor)')]
    public function testHospedadoRecusaToken(): void
    {
        if (!$this->ehHospedado()) {
            self::markTestSkipped('Gateway com tokenização no navegador.');
        }

        $this->expectException(\LogicException::class);
        $this->gatewayQueIniciaComSucesso()->assinarComToken(self::pedido(), 'tok_qualquer');
    }

    #[TestDox('Contrato: falha de rede vira FalhaNoGateway, nunca exceção do HttpClient')]
    public function testForaDoAr(): void
    {
        $gateway = $this->gatewayForaDoAr();

        foreach ([
            static fn () => $gateway->iniciar(self::pedido()),
            static fn () => $gateway->consultar('sub_qualquer'),
            static fn () => $gateway->cancelar('sub_qualquer'),
        ] as $chamada) {
            try {
                $chamada();
                self::fail('a chamada deveria falhar com FalhaNoGateway');
            } catch (FalhaNoGateway) {
                $this->addToAssertionCount(1);
            }
        }
    }

    #[TestDox('Contrato: consultar traduz cada situação do gateway para a do BlueJus')]
    public function testConsultar(): void
    {
        foreach (SituacaoDaAssinatura::cases() as $situacao) {
            [$gateway, $id] = $this->gatewayComAssinaturaNaSituacao($situacao);

            $lida = $gateway->consultar($id);

            self::assertSame($id, $lida->idDaAssinatura);
            self::assertSame($situacao, $lida->situacao, 'situação ' . $situacao->value);
            if ($situacao === SituacaoDaAssinatura::Ativa) {
                self::assertNotNull($lida->pagaAte, 'assinatura ativa diz até quando o mês está pago');
            }
        }
    }

    #[TestDox('Contrato: aviso autêntico é traduzido com o id do evento e o que reconsultar')]
    public function testAvisoAutentico(): void
    {
        $caso = $this->avisoAutentico();

        $aviso = $caso['gateway']->interpretarAviso($caso['cabecalhos'], $caso['corpo']);

        self::assertNotNull($aviso);
        self::assertSame($caso['idDoEvento'], $aviso->idDoEvento);
        self::assertSame($caso['idDaAssinatura'], $aviso->idDaAssinatura);
        self::assertSame($caso['referencia'], $aviso->referencia);
        self::assertSame($caso['idDoCliente'], $aviso->idDoCliente);
    }

    #[TestDox('Contrato: aviso sem a credencial do gateway é recusado')]
    public function testAvisoSemCredencial(): void
    {
        $caso = $this->avisoSemCredencial();

        $this->expectException(AvisoNaoAutenticado::class);
        $caso['gateway']->interpretarAviso($caso['cabecalhos'], $caso['corpo']);
    }

    #[TestDox('Contrato: o código do gateway é um dos do desenho')]
    public function testCodigo(): void
    {
        self::assertContains($this->gatewayQueIniciaComSucesso()->codigo(), ['asaas', 'mercado_pago', 'stripe', 'pagarme', 'falso']);
    }

    protected static function pedido(): PedidoDeCheckout
    {
        return new PedidoDeCheckout(
            'BJ000007-essencial-42',
            7,
            'essencial',
            'Essencial',
            1990,
            new Pagador('Escritório de Teste', '12345678000195', 'financeiro@escritorio.test'),
            'https://bluejus.com.br/contribua?assinatura=ok',
            'https://bluejus.com.br/contribua#espaco',
            '203.0.113.10',
        );
    }
}
