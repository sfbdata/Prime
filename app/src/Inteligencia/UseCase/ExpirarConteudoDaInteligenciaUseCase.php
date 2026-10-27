<?php

declare(strict_types=1);

namespace App\Inteligencia\UseCase;

use App\Inteligencia\Contract\ConversaDaIaNaCentral;
use App\Inteligencia\Repository\AnaliseDeInteligenciaRepository;
use App\Inteligencia\Repository\ConfiguracaoDaPlataformaDeIaRepository;

/**
 * As respostas da BlueJus IA e as perguntas da conversa (da pasta e do chat da Central) ficam guardadas pelos meses que o Master escolhe na aba
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
        private readonly ConversaDaIaNaCentral $conversasDaIa,
    ) {
    }

    /** @return array{meses: int, limite: \DateTimeImmutable, analises: int, perguntas: int, mensagens: int} */
    public function executar(\DateTimeImmutable $agora): array
    {
        $meses = $this->plataforma->obter()->getMesesDeRetencao();
        $limite = $agora->modify(sprintf('-%d months', $meses));

        $expiradas = $this->analises->expirarConteudoAntesDe($limite, $agora);

        // Mesmo limite: as mensagens do chat da Central (pergunta e resposta, as duas pontas) saem junto.
        return ['meses' => $meses, 'limite' => $limite] + $expiradas + ['mensagens' => $this->conversasDaIa->apagarMensagensDaIaAntesDe($limite)];
    }
}
