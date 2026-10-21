<?php

declare(strict_types=1);

namespace App\Comercial\Service;

use App\Comercial\Message\EnviarEmail;
use App\Comercial\Repository\EnvioDeEmailRepository;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Recupera envios parados (spec §3.1, M-c): o `enviando` de um worker que morreu volta à fila depois de 15 min, e o
 * `enfileirado` cuja mensagem se perdeu (próxima tentativa vencida há mais de 30 min, por exemplo depois de ir para
 * `failed`) é despachado de novo. A reivindicação do handler torna o redespacho inofensivo.
 *
 * Exceção (spec §8.5): campanha em massa é "no máximo uma vez". O `enviando` parado de origem `campanha:*` pode já ter
 * saído pelo SMTP; vira `incerto` e não é reenviado. Transacionais, consumidores e o `teste_campanha` seguem "pelo
 * menos uma vez".
 */
final class RecuperacaoDeEnvios
{
    public function __construct(
        private readonly EnvioDeEmailRepository $envios,
        private readonly MessageBusInterface $bus,
    ) {
    }

    public function recuperar(\DateTimeImmutable $agora): void
    {
        $this->envios->marcarCampanhaIncerta($agora->modify('-15 minutes'));
        $this->envios->devolverEnviandoParados($agora->modify('-15 minutes'), $agora);
        foreach ($this->envios->enfileiradosPerdidos($agora->modify('-30 minutes'), VarreduraDeAutomacoes::LOTE) as $envioId) {
            $this->bus->dispatch(new EnviarEmail($envioId));
        }
    }
}
