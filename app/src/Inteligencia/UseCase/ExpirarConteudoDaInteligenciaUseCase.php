<?php

declare(strict_types=1);

namespace App\Inteligencia\UseCase;

use App\Inteligencia\Contract\ConversaDaIaNaCentral;
use App\Inteligencia\Contract\RetencaoDoChatIa;
use App\Inteligencia\Repository\AnaliseDeInteligenciaRepository;
use App\Inteligencia\Repository\ConfiguracaoDaPlataformaDeIaRepository;

/**
 * As respostas da BlueJus IA e as perguntas da conversa (da pasta e do chat da Central) ficam guardadas pelos meses que o Master escolhe na aba
 * "Configurações da I.A." (12 por padrão, sem a linha da plataforma; decidido pelo dono em 24/10/2026:
 * `docs/specs/ia-anthropic-ligar.md` §10). Depois disso o conteúdo é apagado e a análise sai das telas; ficam só os
 * números (modelo, tokens, datas).
 *
 * As falas do Chat I.A em página inteira (`chat_ia_mensagem`, inclusive a cópia importada da Central com a data
 * original) saem com o MESMO limite, junto com os anexos delas (o arquivo depois do COMMIT) e a conversa que ficou sem
 * fala (auditoria 1.19, decisões do Coordenador de 05/11/2026).
 *
 * Roda todo dia pelo agendador (`app:inteligencia:expirar-conteudo`). Análise ainda em andamento nunca é tocada.
 */
final class ExpirarConteudoDaInteligenciaUseCase
{
    public function __construct(
        private readonly AnaliseDeInteligenciaRepository $analises,
        private readonly ConfiguracaoDaPlataformaDeIaRepository $plataforma,
        private readonly ConversaDaIaNaCentral $conversasDaIa,
        private readonly RetencaoDoChatIa $chatIa,
    ) {
    }

    /**
     * @return array{meses: int, limite: \DateTimeImmutable, analises: int, perguntas: int, mensagens: int,
     *               mensagensDoChatIa: int, anexosDoChatIa: int, conversasDoChatIa: int}
     */
    public function executar(\DateTimeImmutable $agora): array
    {
        $meses = $this->plataforma->obter()->getMesesDeRetencao();
        $limite = $agora->modify(sprintf('-%d months', $meses));

        $expiradas = $this->analises->expirarConteudoAntesDe($limite, $agora);

        // Mesmo limite: as mensagens do chat da Central (pergunta e resposta, as duas pontas) saem junto.
        $mensagens = $this->conversasDaIa->apagarMensagensDaIaAntesDe($limite);

        // Mesmo limite: as falas do Chat I.A, os anexos delas e a conversa que ficou vazia.
        $chatIa = $this->chatIa->apagarAntesDe($limite);

        return ['meses' => $meses, 'limite' => $limite] + $expiradas + [
            'mensagens' => $mensagens,
            'mensagensDoChatIa' => $chatIa['mensagens'],
            'anexosDoChatIa' => $chatIa['anexos'],
            'conversasDoChatIa' => $chatIa['conversas'],
        ];
    }
}
