<?php

declare(strict_types=1);

namespace App\Comercial\UseCase;

use App\Comercial\Entity\EnvioDeEmail;
use App\Comercial\Enum\Automacao;
use App\Comercial\Service\RegistroDeEnvioExterno;
use Psr\Clock\ClockInterface;

/**
 * "Simular novo cadastro" do desenho 05 (L.263, L.566): põe em "Últimos disparos" a confirmação e as boas-vindas de um
 * cadastro de exemplo, sem enviar nada nem criar conta. Situação `simulado`: fica fora de qualquer número.
 */
final class SimularNovoCadastroUseCase
{
    public const DESTINATARIO = 'novo.cliente@exemplo.com';
    public const MENSAGEM = 'Simulação: um novo cadastro disparou a confirmação e, após validar, as boas-vindas.';

    public function __construct(
        private readonly RegistroDeEnvioExterno $registro,
        private readonly ClockInterface $clock,
    ) {
    }

    public function executar(): string
    {
        $agora = $this->clock->now();
        $this->registro->registrar(self::DESTINATARIO, 'Confirme seu e-mail para ativar o BlueJus', 'simulacao:' . Automacao::Cad->value, EnvioDeEmail::SIMULADO, $agora);
        $this->registro->registrar(self::DESTINATARIO, 'Bem-vindo ao BlueJus, seu escritório digital', 'simulacao:' . Automacao::Val->value, EnvioDeEmail::SIMULADO, $agora->modify('+1 second'));

        return self::MENSAGEM;
    }
}
