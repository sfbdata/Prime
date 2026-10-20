<?php

declare(strict_types=1);

namespace App\TrabalhoExterno\UseCase;

use App\TrabalhoExterno\Repository\LinkDeVagasRepository;
use App\TrabalhoExterno\Repository\MetricaPublicaRepository;
use App\TrabalhoExterno\Service\EngajamentoDoTrabalhoExterno;
use Psr\Log\LoggerInterface;

/**
 * Os contadores anônimos da página pública de vagas (spec VS 18.10.26 §4.9), sem login e sem dado
 * pessoal:
 * - VISITA: cada GET de `/vagas-juridicas/{token}` com um link que existe (token desconhecido não conta);
 * - CLIQUE: cada clique em "Criar conta" na página, com ou sem token (token desconhecido conta sem link).
 *
 * Robô conhecido (pelo User-Agent) e pedido sem User-Agent não contam. "bot" só vale como fim de palavra
 * seguido de `/`, `;`, `+`, `)`, `_` ou `-` (Googlebot/2.1, bingbot/2.0, Slackbot-LinkExpanding): o
 * celular "CUBOT NOTE 7" é gente. O dia é o de Brasília. A gravação nunca derruba a página nem o 204:
 * a falha vira aviso no log e o evento não conta. Devolve se contou.
 */
final class RegistrarMetricaPublicaUseCase
{
    public const VISITA = 'visita';
    public const CLIQUE = 'clique';
    private const ROBOS = '/(bot|crawler|spider)[\/;+)_-]|bingpreview|facebookexternalhit|whatsapp|slurp|preview/i';

    public function __construct(
        private readonly LinkDeVagasRepository $links,
        private readonly MetricaPublicaRepository $metricas,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function executar(string $evento, ?string $token, string $userAgent): bool
    {
        if (self::ehRobo($userAgent) || !in_array($evento, [self::VISITA, self::CLIQUE], true)) {
            return false;
        }
        try {
            return $this->contar($evento, $token);
        } catch (\Throwable $e) {
            $this->logger->warning('Trabalho Externo: métrica pública não gravada', ['evento' => $evento, 'erro' => $e->getMessage()]);

            return false;
        }
    }

    private function contar(string $evento, ?string $token): bool
    {
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
