<?php

declare(strict_types=1);

namespace App\Inteligencia\UseCase;

use App\Inteligencia\Repository\AnaliseDeInteligenciaRepository;
use App\Inteligencia\Repository\ConfiguracaoDaPlataformaDeIaRepository;

/**
 * As respostas da BlueJus IA e as perguntas da conversa ficam guardadas pelos meses que o Master escolhe na aba
 * "Configurações da I.A." (12 por padrão, sem a linha da plataforma; decidido pelo dono em 24/10/2026:
 * `docs/specs/ia-anthropic-ligar.md` §10). Depois disso o conteúdo é apagado e a análise sai das telas; ficam só os
 * números (modelo, tokens, datas).
 *
 * Roda todo dia pelo agendador (`app:inteligencia:expirar-conteudo`). Análise ainda em andamento nunca é tocada.
 */
final class ExpirarConteudoDaInteligenciaUseCase
{
    public function __construct(
        private readonly AnaliseDeInteligenciaRepository $analises,
        private readonly ConfiguracaoDaPlataformaDeIaRepository $plataforma,
    ) {
    }

    /** @return array{meses: int, limite: \DateTimeImmutable, analises: int, perguntas: int} */
    public function executar(\DateTimeImmutable $agora): array
    {
        $meses = $this->plataforma->obter()->getMesesDeRetencao();
        $limite = $agora->modify(sprintf('-%d months', $meses));

        return ['meses' => $meses, 'limite' => $limite] + $this->analises->expirarConteudoAntesDe($limite, $agora);
    }
}
