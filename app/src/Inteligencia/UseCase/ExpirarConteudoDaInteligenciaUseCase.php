<?php

declare(strict_types=1);

namespace App\Inteligencia\UseCase;

use App\Inteligencia\Repository\AnaliseDeInteligenciaRepository;

/**
 * As respostas da BlueJus IA e as perguntas da conversa ficam guardadas por {@see MESES_DE_RETENCAO} meses
 * (decisão de 24/10/2026, padrão adotado, o dono pode rever: `docs/specs/ia-anthropic-ligar.md` §10). Depois disso
 * o conteúdo é apagado e a análise sai das telas; ficam só os números (modelo, tokens, datas).
 *
 * Roda todo dia pelo agendador (`app:inteligencia:expirar-conteudo`). Análise ainda em andamento nunca é tocada.
 */
final class ExpirarConteudoDaInteligenciaUseCase
{
    public const MESES_DE_RETENCAO = 12;

    public function __construct(
        private readonly AnaliseDeInteligenciaRepository $analises,
    ) {
    }

    /** @return array{limite: \DateTimeImmutable, analises: int, perguntas: int} */
    public function executar(\DateTimeImmutable $agora): array
    {
        $limite = $agora->modify(sprintf('-%d months', self::MESES_DE_RETENCAO));

        return ['limite' => $limite] + $this->analises->expirarConteudoAntesDe($limite, $agora);
    }
}
