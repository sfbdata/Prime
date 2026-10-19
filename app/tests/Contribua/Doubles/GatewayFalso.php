<?php

declare(strict_types=1);

namespace App\Tests\Contribua\Doubles;

use App\Contribua\Gateway\AvisoDoGateway;
use App\Contribua\Gateway\AvisoNaoAutenticado;
use App\Contribua\Gateway\FalhaNoGateway;
use App\Contribua\Gateway\GatewayDeAssinatura;
use App\Contribua\Gateway\InicioDoCheckout;
use App\Contribua\Gateway\PedidoDeCheckout;
use App\Contribua\Gateway\SituacaoDaAssinatura;
use App\Contribua\Gateway\SituacaoNoGateway;

/**
 * Gateway em memória, para os testes da C3 comum e como referência do contrato. Mora em tests/ de
 * propósito: em src/ ele seria a única implementação da interface e o autowiring o injetaria em produção.
 */
final class GatewayFalso implements GatewayDeAssinatura
{
    public const TOKEN_DO_AVISO = 'token-falso';

    /** @var array<string, SituacaoNoGateway> */
    public array $assinaturas = [];

    /** @var list<string> */
    public array $canceladas = [];

    public bool $foraDoAr = false;

    /** @var array<string, string> o que chegou no último `interpretarAviso` */
    public array $ultimosCabecalhos = [];

    public function __construct(
        /** Mutável: o teste funcional troca o modo do gateway que já está no container. */
        public string $modo = InicioDoCheckout::REDIRECIONAR,
    ) {
    }

    public function codigo(): string
    {
        return 'falso';
    }

    public function iniciar(PedidoDeCheckout $pedido): InicioDoCheckout
    {
        $this->exigirNoAr();
        $id = 'sub_' . substr(sha1($pedido->referencia), 0, 12);
        $this->assinaturas[$id] = new SituacaoNoGateway($id, SituacaoDaAssinatura::Aguardando, null, $pedido->referencia, null, $pedido->valorCentavos);

        return $this->modo === InicioDoCheckout::FORMULARIO
            ? InicioDoCheckout::formulario('chave-publica-falsa')
            : InicioDoCheckout::redirecionar('https://gateway.falso/checkout/' . $id, $id);
    }

    public function assinarComToken(PedidoDeCheckout $pedido, string $tokenDoCartao): SituacaoNoGateway
    {
        $this->exigirNoAr();
        if ($this->modo !== InicioDoCheckout::FORMULARIO) {
            throw new \LogicException('Este gateway só trabalha com página hospedada.');
        }
        $id = 'sub_' . substr(sha1($pedido->referencia), 0, 12);

        return $this->assinaturas[$id] = new SituacaoNoGateway($id, SituacaoDaAssinatura::Ativa, new \DateTimeImmutable('+1 month'), $pedido->referencia, null, $pedido->valorCentavos);
    }

    public function consultar(string $idDaAssinatura): SituacaoNoGateway
    {
        $this->exigirNoAr();

        return $this->assinaturas[$idDaAssinatura] ?? throw new FalhaNoGateway('Assinatura desconhecida no gateway falso: ' . $idDaAssinatura);
    }

    public function interpretarAviso(array $cabecalhos, string $corpo): ?AvisoDoGateway
    {
        $this->ultimosCabecalhos = $cabecalhos;
        if (($cabecalhos['x-token'] ?? null) !== self::TOKEN_DO_AVISO) {
            throw new AvisoNaoAutenticado('Token do aviso ausente ou errado.');
        }
        $dados = json_decode($corpo, true);
        if (!\is_array($dados) || !isset($dados['id'], $dados['evento'])) {
            return null;
        }

        return new AvisoDoGateway((string) $dados['id'], (string) $dados['evento'], isset($dados['assinatura']) ? (string) $dados['assinatura'] : null);
    }

    public function cancelar(string $idDaAssinatura): void
    {
        $this->exigirNoAr();
        $atual = $this->assinaturas[$idDaAssinatura] ?? null;
        if ($atual !== null) {
            $this->assinaturas[$idDaAssinatura] = new SituacaoNoGateway($idDaAssinatura, SituacaoDaAssinatura::Cancelada, $atual->pagaAte, $atual->referencia, null, $atual->valorCentavos);
        }
        $this->canceladas[] = $idDaAssinatura;
    }

    /** Simula o pagamento confirmado (o que o gateway real faria ao cobrar o cartão). */
    public function marcarComoPaga(string $idDaAssinatura, \DateTimeImmutable $pagaAte): void
    {
        $atual = $this->consultar($idDaAssinatura);
        $this->assinaturas[$idDaAssinatura] = new SituacaoNoGateway($idDaAssinatura, SituacaoDaAssinatura::Ativa, $pagaAte, $atual->referencia, null, $atual->valorCentavos);
    }

    private function exigirNoAr(): void
    {
        if ($this->foraDoAr) {
            throw new FalhaNoGateway('Gateway falso fora do ar.');
        }
    }
}
