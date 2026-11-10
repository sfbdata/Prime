<?php

declare(strict_types=1);

namespace App\AssinaturaEletronica\Service\Cadeia;

use App\AssinaturaEletronica\Enum\NivelDeAssinatura;
use App\AssinaturaEletronica\Service\ConferenciaDePdf\ResultadoDaConferencia;
use Psr\Log\LoggerInterface;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;

/**
 * A conferência da cadeia de um PDF devolvido com gov.br ou ICP (etapa 6, bloco 6B): a regra da E6-6 antes do
 * validador, o limitador da seção 2.1, o validador e a regra depois dele. Quem chama (a 5C, no
 * `AssinarComCertificadoUseCase`) grava o desfecho na versão ({@see DecisaoDaCadeia::gravarEm()}, E6-7) e só então usa
 * o CPF que o motor leu.
 *
 * Limitador `assinatura_validador_conferencia` (`config/packages/assinatura_eletronica.yaml`): cada conferência ocupa
 * uma das 2 vagas do validador por até 30 s, então ele conta por signatário ({@see quemSignatario()}) ou, sem
 * signatário identificado, por IP ({@see quemIp()}). Só a chamada ao validador conta; a recusa do motor antes dela não.
 * Passou do limite: {@see CadeiaIndisponivel}, como o validador ocupado ("tente de novo em alguns minutos").
 */
final class ConferenciaDaCadeia
{
    public function __construct(
        private readonly ValidadorDeCadeia $validador,
        private readonly RegraDaCadeia $regra,
        private readonly RateLimiterFactoryInterface $assinaturaValidadorConferenciaLimiter,
        private readonly LoggerInterface $logger,
    ) {
    }

    public static function quemSignatario(int $signatarioId): string
    {
        return 'signatario:' . $signatarioId;
    }

    public static function quemIp(string $ip): string
    {
        return 'ip:' . $ip;
    }

    /**
     * @param string $pdf   os mesmos bytes que o motor conferiu
     * @param string $quem  a chave do limitador: {@see quemSignatario()} ou {@see quemIp()}
     *
     * @throws CadeiaIndisponivel não configurado, fora do ar, ocupado, tempo esgotado ou limite de conferências
     * @throws \LogicException    nível que não passa pelo validador (Simples, Reforçada)
     */
    public function conferir(string $pdf, ResultadoDaConferencia $motor, NivelDeAssinatura $nivel, string $quem): DecisaoDaCadeia
    {
        $ancora = RegraDaCadeia::ancoraDoNivel($nivel);
        $antes = $this->regra->antesDoValidador($motor);
        if ($antes !== null) {
            return DecisaoDaCadeia::recusada($antes, null);
        }
        if (!$this->assinaturaValidadorConferenciaLimiter->create($quem)->consume()->isAccepted()) {
            $this->logger->warning('Validador de cadeia: limite de conferências atingido.', ['tipo' => strstr($quem, ':', true) ?: $quem]);

            throw new CadeiaIndisponivel('Limite de conferências da cadeia atingido.');
        }

        return $this->regra->decidir($motor, $nivel, $this->validador->conferir($pdf, $ancora));
    }
}
