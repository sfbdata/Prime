<?php

declare(strict_types=1);

namespace App\TrabalhoExterno\UseCase;

use App\TrabalhoExterno\Repository\LinkDeVagasRepository;
use App\TrabalhoExterno\Repository\MetricaPublicaRepository;
use App\TrabalhoExterno\Service\EngajamentoDoTrabalhoExterno;

/**
 * Os contadores anônimos da página pública de vagas (spec VS 18.10.26 §4.9), sem login e sem dado
 * pessoal:
 * - VISITA: cada GET de `/vagas-juridicas/{token}` com um link que existe (token desconhecido não conta);
 * - CLIQUE: cada clique em "Criar conta" na página, com ou sem token (token desconhecido conta sem link).
 *
 * Robô conhecido (pelo User-Agent) e pedido sem User-Agent não contam. O dia é o de Brasília. Devolve
 * se contou.
 */
final class RegistrarMetricaPublicaUseCase
{
    public const VISITA = 'visita';
    public const CLIQUE = 'clique';
    private const ROBOS = '/bot|crawler|spider|preview|facebookexternalhit|whatsapp/i';

    public function __construct(
        private readonly LinkDeVagasRepository $links,
        private readonly MetricaPublicaRepository $metricas,
    ) {
    }

    public function executar(string $evento, ?string $token, string $userAgent): bool
    {
        if (self::ehRobo($userAgent) || !in_array($evento, [self::VISITA, self::CLIQUE], true)) {
            return false;
        }
        $link = $token !== null && $token !== '' ? $this->links->doToken($token) : null;
        if ($evento === self::VISITA && $link === null) {
            return false;
        }
        $hoje = new \DateTimeImmutable('today', new \DateTimeZone(EngajamentoDoTrabalhoExterno::FUSO));
        $this->metricas->somar($hoje, $link, $evento === self::VISITA ? 1 : 0, $evento === self::CLIQUE ? 1 : 0);

        return true;
    }

    private static function ehRobo(string $userAgent): bool
    {
        return trim($userAgent) === '' || preg_match(self::ROBOS, $userAgent) === 1;
    }
}
