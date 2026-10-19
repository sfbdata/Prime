<?php

declare(strict_types=1);

namespace App\Central\Service;

use App\Entity\Notificacao;
use App\Inteligencia\MessageHandler\ProcessarAnaliseDeInteligenciaHandler;
use App\Tarefa\UseCase\AlertarResponsavelDaMetaUseCase;

/**
 * O mapa único entre os tipos REAIS do sino e os tipos do desenho (`bluejus-central.js` L61-87, `TIPOS`/`GRUPOS`), para
 * os avisos na Caixa da Central (F1, `docs/specs/central-comunicacao-f1-avisos.md` §2.3). Cores e ícones do desenho;
 * tipos sem par no desenho ganham rótulo próprio e ficam fora dos filtros de grupo (só em "Tudo").
 */
final class TiposDeAvisoDaCentral
{
    /** Ficam fora da Caixa: gestão segue só no sino (aba Gestão); os da Central já aparecem na conversa (§2.2). */
    public const FORA_DA_CAIXA = [
        ...Notificacao::TIPOS_GESTAO,
        Notificacao::TIPO_CENTRAL_MENCAO,
        Notificacao::TIPO_CENTRAL_GRUPO_ADICIONADO,
    ];

    private const AZUL = '#0a7aad';
    private const PETROLEO = '#0b5f86';
    private const VERDE = '#1f9d61';
    private const AMBAR = '#c8952a';

    /** @return array{grupo: ?string, rotulo: string, icone: string, cor: string, nivel: int} */
    public function para(string $tipo): array
    {
        // O legado tem tipos gravados em maiúsculas (ex.: EVENTO_CRIADO): o mapa compara sem diferenciar.
        return match (strtolower($tipo)) {
            AlertarResponsavelDaMetaUseCase::TIPO_NOTIFICACAO => ['grupo' => 'urgente', 'rotulo' => 'Alerta', 'icone' => 'bi-bell-fill', 'cor' => '#e8590c', 'nivel' => 3],
            Notificacao::TIPO_TAREFA_CRIADA => ['grupo' => 'metas', 'rotulo' => 'Nova meta', 'icone' => 'bi-clipboard2-plus', 'cor' => self::AZUL, 'nivel' => 2],
            Notificacao::TIPO_TAREFA_EM_REVISAO, Notificacao::TIPO_TAREFA_PENDENTE => ['grupo' => 'metas', 'rotulo' => 'Meta atualizada', 'icone' => 'bi-pencil-square', 'cor' => self::PETROLEO, 'nivel' => 1],
            Notificacao::TIPO_TAREFA_CONCLUIDA => ['grupo' => 'metas', 'rotulo' => 'Meta concluída', 'icone' => 'bi-check2-circle', 'cor' => self::VERDE, 'nivel' => 0],
            Notificacao::TIPO_PASTA_MENCAO_REGISTRO, Notificacao::TIPO_NOTA_TECNICA_MENCAO => ['grupo' => 'mencoes', 'rotulo' => 'Menção', 'icone' => 'bi-at', 'cor' => self::AZUL, 'nivel' => 2],
            Notificacao::TIPO_PASTA_RESPOSTA_REGISTRO => ['grupo' => 'atencao', 'rotulo' => 'Para você', 'icone' => 'bi-person-check', 'cor' => self::AMBAR, 'nivel' => 2],
            Notificacao::TIPO_DJEN_PUBLICACAO, Notificacao::TIPO_PUSH_PUBLICACAO_PASTA => ['grupo' => 'atencao', 'rotulo' => 'Publicação', 'icone' => 'bi-newspaper', 'cor' => self::AMBAR, 'nivel' => 2],
            Notificacao::TIPO_EVENTO_CRIADO, Notificacao::TIPO_EVENTO_CANCELADO => ['grupo' => 'atencao', 'rotulo' => 'Agenda', 'icone' => 'bi-calendar-event', 'cor' => self::AMBAR, 'nivel' => 1],
            ProcessarAnaliseDeInteligenciaHandler::TIPO_NOTIFICACAO => ['grupo' => 'mensagens', 'rotulo' => 'Documento analisado', 'icone' => 'bi-file-earmark-check', 'cor' => self::AZUL, 'nivel' => 1],
            default => ['grupo' => null, 'rotulo' => 'Aviso', 'icone' => 'bi-bell', 'cor' => self::PETROLEO, 'nivel' => 1],
        };
    }
}
