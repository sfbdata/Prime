<?php

declare(strict_types=1);

namespace App\Comercial\Service;

use App\Comercial\Entity\EnvioDeEmail;
use App\Comercial\Envio\CarteiroInterface;
use App\Comercial\Envio\MensagemDeEmail;
use App\Comercial\Envio\ResultadoDoEnvio;
use App\Comercial\Envio\TipoDeEnvio;
use App\Comercial\Message\EnviarEmail;
use App\Comercial\Repository\EnvioDeEmailRepository;
use App\Shared\Email\Descadastro\DescadastroDeEmailInterface;
use Psr\Clock\ClockInterface;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * A porta única de envio (spec `comercial-e-emails.md` §3.1). Numa transação só da conexão (o registro, o conteúdo e
 * a mensagem no transporte doctrine `comercial`, que usa a mesma conexão): nunca sobra registro sem mensagem nem
 * mensagem sem registro, e nada do EntityManager do chamador é gravado (sem `flush`).
 */
final class Carteiro implements CarteiroInterface
{
    public function __construct(
        private readonly EnvioDeEmailRepository $envios,
        private readonly DescadastroDeEmailInterface $descadastro,
        private readonly MessageBusInterface $bus,
        private readonly ClockInterface $relogio,
    ) {
    }

    public function enviar(MensagemDeEmail $mensagem): ResultadoDoEnvio
    {
        return $this->envios->emTransacao(fn (): ResultadoDoEnvio => $this->registrar($mensagem));
    }

    private function registrar(MensagemDeEmail $mensagem): ResultadoDoEnvio
    {
        $agora = $this->relogio->now();
        if ($this->descadastrado($mensagem)) {
            $id = $this->envios->inserir($mensagem, self::token(), EnvioDeEmail::BLOQUEADO_DESCADASTRO, $agora, null);

            return new ResultadoDoEnvio($id, ResultadoDoEnvio::BLOQUEADO_DESCADASTRO);
        }

        $id = $this->envios->inserir($mensagem, self::token(), EnvioDeEmail::ENFILEIRADO, $agora, $agora);
        $this->envios->inserirConteudo($id, $mensagem);
        $this->bus->dispatch(new EnviarEmail($id));

        return new ResultadoDoEnvio($id, ResultadoDoEnvio::ENFILEIRADO);
    }

    /** Só `lista` consulta o descadastro; o transacional (aviso da própria conta) não. */
    private function descadastrado(MensagemDeEmail $mensagem): bool
    {
        if ($mensagem->tipo !== TipoDeEnvio::Lista || $mensagem->lista === null) {
            return false;
        }

        return $this->descadastro->estaDescadastrado($mensagem->escopo, $mensagem->para, $mensagem->lista);
    }

    /** 32 hex aleatórios: o identificador público do envio (pixel e clique). */
    private static function token(): string
    {
        return bin2hex(random_bytes(16));
    }
}
