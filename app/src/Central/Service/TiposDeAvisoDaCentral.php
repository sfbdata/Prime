<?php

declare(strict_types=1);

namespace App\Central\Service;

use App\Auth\UseCase\EnviarMensagemSuporteUseCase;
use App\Contribua\Service\AvisoAoMaster;
use App\Contribua\Service\AvisoAoPrimario;
use App\Dashboard\UseCase\PedirZeramentoDoDashboardUseCase;
use App\Entity\Notificacao;
use App\Indicacao\Service\AvisoDeDisparoAlto;
use App\Inteligencia\MessageHandler\ProcessarAnaliseDeInteligenciaHandler;
use App\Pasta\Service\AvisoDoChecklist;
use App\Pasta\UseCase\EnviarRelatorioPastasParadasUseCase;
use App\Pasta\UseCase\EnviarRelatorioSemProcessoUseCase;
use App\Tarefa\UseCase\AlertarResponsavelDaMetaUseCase;
use App\Tarefa\UseCase\LembrarMetaUseCase;
use App\Tenant\UseCase\PedirAcessoAosPerfisUseCase;
use App\Master\UseCase\ResponderNaCentralUseCase;

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

    /**
     * Fora do `match` para ele caber no limite de linhas: o "Pedir acesso" da tela "Acesso restrito" dos Perfis
     * (desenho 05 - Perfis 1.2, L822: tipo "pedido", para o primário) e a resposta do Master a quem escreveu ao
     * BlueJus pela Central de Notificações (sem e-mail para responder).
     */
    private const DOS_PERFIS_E_DA_CENTRAL_DO_MASTER = [
        PedirAcessoAosPerfisUseCase::TIPO_NOTIFICACAO => ['grupo' => 'atencao', 'rotulo' => 'Pedido de acesso', 'icone' => 'bi-shield-lock', 'cor' => self::AMBAR, 'nivel' => 2],
        ResponderNaCentralUseCase::TIPO_NOTIFICACAO => ['grupo' => 'mensagens', 'rotulo' => 'Resposta do BlueJus', 'icone' => 'bi-reply', 'cor' => self::AZUL, 'nivel' => 2],
    ];

    /** Trabalho Externo (F4): avisos de vagas e candidaturas, fora do `match` para ele caber no limite de linhas. */
    private const DO_TRABALHO_EXTERNO = [
        Notificacao::TIPO_TRABALHO_EXTERNO_PEDIDO_DE_VAGA => ['grupo' => 'atencao', 'rotulo' => 'Pedido de vaga', 'icone' => 'bi-inbox', 'cor' => self::AMBAR, 'nivel' => 2],
        Notificacao::TIPO_TRABALHO_EXTERNO_CANDIDATURA => ['grupo' => 'atencao', 'rotulo' => 'Candidatura', 'icone' => 'bi-send-check', 'cor' => self::AZUL, 'nivel' => 1],
        Notificacao::TIPO_TRABALHO_EXTERNO_OFERTA_ACEITA => ['grupo' => 'atencao', 'rotulo' => 'Oferta aceita', 'icone' => 'bi-hand-thumbs-up', 'cor' => self::VERDE, 'nivel' => 2],
        Notificacao::TIPO_TRABALHO_EXTERNO_CANDIDATOS_ENVIADOS => ['grupo' => 'atencao', 'rotulo' => 'Candidatos', 'icone' => 'bi-people', 'cor' => self::AZUL, 'nivel' => 2],
        Notificacao::TIPO_TRABALHO_EXTERNO_VAGA_APROVADA => ['grupo' => 'atencao', 'rotulo' => 'Vaga aprovada', 'icone' => 'bi-check2-circle', 'cor' => self::VERDE, 'nivel' => 1],
        Notificacao::TIPO_TRABALHO_EXTERNO_VAGA_REPROVADA => ['grupo' => 'atencao', 'rotulo' => 'Vaga não aprovada', 'icone' => 'bi-x-circle', 'cor' => self::AMBAR, 'nivel' => 2],
        Notificacao::TIPO_TRABALHO_EXTERNO_CANDIDATURA_STATUS => ['grupo' => 'atencao', 'rotulo' => 'Andamento', 'icone' => 'bi-arrow-repeat', 'cor' => self::PETROLEO, 'nivel' => 1],
        Notificacao::TIPO_TRABALHO_EXTERNO_VAGA_NA_AREA => ['grupo' => 'atencao', 'rotulo' => 'Vaga nova', 'icone' => 'bi-megaphone', 'cor' => self::AZUL, 'nivel' => 1],
        Notificacao::TIPO_TRABALHO_EXTERNO_DEMANDA_INFORMACOES => ['grupo' => 'atencao', 'rotulo' => 'Pedido de informações', 'icone' => 'bi-question-circle', 'cor' => self::AMBAR, 'nivel' => 2],
        Notificacao::TIPO_TRABALHO_EXTERNO_DEMANDA_ANDAMENTO => ['grupo' => 'atencao', 'rotulo' => 'Andamento da demanda', 'icone' => 'bi-briefcase', 'cor' => self::PETROLEO, 'nivel' => 1],
        Notificacao::TIPO_TRABALHO_EXTERNO_DESPACHO_RESPOSTA => ['grupo' => 'atencao', 'rotulo' => 'Resposta ao despacho', 'icone' => 'bi-reply', 'cor' => self::VERDE, 'nivel' => 1],
        Notificacao::TIPO_TRABALHO_EXTERNO_OFERTA_RECUSADA => ['grupo' => 'atencao', 'rotulo' => 'Oferta recusada', 'icone' => 'bi-x-circle', 'cor' => self::AMBAR, 'nivel' => 1],
        Notificacao::TIPO_TRABALHO_EXTERNO_TERMO_ENVIADO => ['grupo' => 'atencao', 'rotulo' => 'Termo para aceitar', 'icone' => 'bi-file-earmark-check', 'cor' => self::AMBAR, 'nivel' => 2],
        Notificacao::TIPO_TRABALHO_EXTERNO_TERMO_ACEITO => ['grupo' => 'atencao', 'rotulo' => 'Termo aceito', 'icone' => 'bi-file-earmark-check', 'cor' => self::VERDE, 'nivel' => 1],
    ];

    /**
     * Metas, fora do `match` para ele caber no limite de linhas. O "Lembrar" da página da meta (desenho 04B L133:
     * `notificar({ tipo: 'lembrete', … })`) não tem par no `TIPOS` do bluejus-central.js: entra no grupo Metas, com o
     * sino do botão e o âmbar de "aguardando".
     */
    private const DAS_METAS = [
        LembrarMetaUseCase::TIPO_NOTIFICACAO => ['grupo' => 'metas', 'rotulo' => 'Lembrete', 'icone' => 'bi-bell', 'cor' => self::AMBAR, 'nivel' => 2],
    ];

    /** Dashboard: o "Pedir para zerar" de uma coluna (desenho 01 - Dashboard 1.2.2; bluejus-central.js `pedirZerar`). */
    private const DO_DASHBOARD = [
        PedirZeramentoDoDashboardUseCase::TIPO_NOTIFICACAO => ['grupo' => 'atencao', 'rotulo' => 'Pedido para zerar', 'icone' => 'bi-arrow-counterclockwise', 'cor' => self::AMBAR, 'nivel' => 2],
    ];

    /**
     * Pasta acompanhada ("Acompanhar alterações" do menu ⋮ da pasta, desenho 02 L4497: "Você vai receber no Chat I.A as
     * alterações da pasta"): grupo Mensagens, o sino do item do menu, azul, nível 1. Fora do `match` pelo limite de linhas.
     */
    private const DA_PASTA_ACOMPANHADA = [
        Notificacao::TIPO_PASTA_ACOMPANHADA => ['grupo' => 'mensagens', 'rotulo' => 'Pasta acompanhada', 'icone' => 'bi-bell', 'cor' => self::AZUL, 'nivel' => 1],
    ];

    /**
     * Rótulo da ação de abrir o aviso, quando o desenho dá um próprio: os avisos do Contribua ao Master usam
     * "Ver sugestão" (desenho 08, `avisaMaster`: `acao: 'Ver sugestão'`). Os demais ficam com o "Abrir" genérico (null).
     */
    private const ACOES = [
        AvisoAoMaster::TIPO_SUGESTAO => 'Ver sugestão',
        AvisoAoMaster::TIPO_APOIO => 'Ver sugestão',
        AvisoAoMaster::TIPO_MOTIVO => 'Ver sugestão',
        AvisoAoMaster::TIPO_PEDIDO_DE_ESPACO => 'Ver sugestão',
        // Desenho 06 de 24/10 (`avisarPartes`): `acao: 'Abrir a demanda'`.
        Notificacao::TIPO_TRABALHO_EXTERNO_DEMANDA_ANDAMENTO => 'Abrir a demanda',
        // Resposta ao despacho, aos Master da Central (decisão do dono de 24/10).
        Notificacao::TIPO_TRABALHO_EXTERNO_DESPACHO_RESPOSTA => 'Abrir a demanda',
    ];

    /** O rótulo da ação de abrir o aviso, ou null para o "Abrir" genérico da Central. */
    public function acao(string $tipo): ?string
    {
        return self::ACOES[strtolower($tipo)] ?? null;
    }

    /** @return array{grupo: ?string, rotulo: string, icone: string, cor: string, nivel: int} */
    public function para(string $tipo): array
    {
        // O legado tem tipos gravados em maiúsculas (ex.: EVENTO_CRIADO): o mapa compara sem diferenciar.
        $tipo = strtolower($tipo);
        $doMapa = self::DO_TRABALHO_EXTERNO[$tipo] ?? self::DOS_PERFIS_E_DA_CENTRAL_DO_MASTER[$tipo] ?? self::DAS_METAS[$tipo] ?? self::DO_DASHBOARD[$tipo] ?? self::DA_PASTA_ACOMPANHADA[$tipo] ?? null;
        if ($doMapa !== null) {
            return $doMapa;
        }

        return match ($tipo) {
            AlertarResponsavelDaMetaUseCase::TIPO_NOTIFICACAO,
            // "Disparo alto de convites" do Indicar o BlueJus: no desenho é o tipo 'alerta' (bj-contas.js L126).
            AvisoDeDisparoAlto::TIPO_NOTIFICACAO => ['grupo' => 'urgente', 'rotulo' => 'Alerta', 'icone' => 'bi-bell-fill', 'cor' => '#e8590c', 'nivel' => 3],
            Notificacao::TIPO_TAREFA_CRIADA => ['grupo' => 'metas', 'rotulo' => 'Nova meta', 'icone' => 'bi-clipboard2-plus', 'cor' => self::AZUL, 'nivel' => 2],
            Notificacao::TIPO_TAREFA_EM_REVISAO, Notificacao::TIPO_TAREFA_PENDENTE => ['grupo' => 'metas', 'rotulo' => 'Meta atualizada', 'icone' => 'bi-pencil-square', 'cor' => self::PETROLEO, 'nivel' => 1],
            Notificacao::TIPO_TAREFA_CONCLUIDA => ['grupo' => 'metas', 'rotulo' => 'Meta concluída', 'icone' => 'bi-check2-circle', 'cor' => self::VERDE, 'nivel' => 0],
            Notificacao::TIPO_PASTA_MENCAO_REGISTRO, Notificacao::TIPO_NOTA_TECNICA_MENCAO => ['grupo' => 'mencoes', 'rotulo' => 'Menção', 'icone' => 'bi-at', 'cor' => self::AZUL, 'nivel' => 2],
            Notificacao::TIPO_PASTA_RESPOSTA_REGISTRO => ['grupo' => 'atencao', 'rotulo' => 'Para você', 'icone' => 'bi-person-check', 'cor' => self::AMBAR, 'nivel' => 2],
            Notificacao::TIPO_CENTRAL_DESPACHO => ['grupo' => 'atencao', 'rotulo' => 'Despacho', 'icone' => 'bi-person-check', 'cor' => self::AMBAR, 'nivel' => 2],
            Notificacao::TIPO_DJEN_PUBLICACAO, Notificacao::TIPO_PUSH_PUBLICACAO_PASTA => ['grupo' => 'atencao', 'rotulo' => 'Publicação', 'icone' => 'bi-newspaper', 'cor' => self::AMBAR, 'nivel' => 2],
            Notificacao::TIPO_PUSH_LINK_RENOVACAO => ['grupo' => 'atencao', 'rotulo' => 'Renovação', 'icone' => 'bi-arrow-clockwise', 'cor' => self::AMBAR, 'nivel' => 2],
            Notificacao::TIPO_CONTA_PEDIDO_EXCLUSAO => ['grupo' => 'atencao', 'rotulo' => 'Pedido de exclusão', 'icone' => 'bi-trash3', 'cor' => self::AMBAR, 'nivel' => 2],
            // Agenda: como o tipo `agenda` do bluejus-central.js de 18/10 (L12 e TIPOS): azul, nível 2.
            Notificacao::TIPO_EVENTO_CRIADO, Notificacao::TIPO_EVENTO_CANCELADO => ['grupo' => 'atencao', 'rotulo' => 'Agenda', 'icone' => 'bi-calendar-event', 'cor' => self::AZUL, 'nivel' => 2],
            // Cobrança automática do checklist da pasta (desenho 02: tipos "cobranca" e "conformidade").
            AvisoDoChecklist::TIPO_COBRANCA => ['grupo' => 'urgente', 'rotulo' => 'Cobrança de documento', 'icone' => 'bi-megaphone-fill', 'cor' => '#c0392f', 'nivel' => 3],
            AvisoDoChecklist::TIPO_PROVIDENCIA => ['grupo' => 'atencao', 'rotulo' => 'Providência', 'icone' => 'bi-clipboard2-check', 'cor' => self::AMBAR, 'nivel' => 2],
            // Relatório de pastas sem processo (spec relatorio-processos-controladoria §4): o "Enviar à Controladoria" do desenho.
            EnviarRelatorioSemProcessoUseCase::TIPO_NOTIFICACAO => ['grupo' => 'atencao', 'rotulo' => 'Relatório', 'icone' => 'bi-file-earmark-bar-graph', 'cor' => self::AMBAR, 'nivel' => 2],
            // Contribua (desenho 08 / bluejus-central.js: tipo "contribua"): sugestão, apoio (informado e confirmado pelo banco), motivo, pedido de espaço e assinatura (ao primário).
            AvisoAoMaster::TIPO_SUGESTAO, AvisoAoMaster::TIPO_APOIO, AvisoAoMaster::TIPO_MOTIVO, AvisoAoMaster::TIPO_PEDIDO_DE_ESPACO, AvisoAoMaster::TIPO_APOIO_CONFIRMADO, AvisoAoPrimario::TIPO_ASSINATURA => ['grupo' => 'atencao', 'rotulo' => 'Contribua', 'icone' => 'bi-heart', 'cor' => '#c0392b', 'nivel' => 2],
            // Relatório mensal de pastas paradas (spec pastas-paradas, "Avisos"): o `paradasNotificar` do desenho.
            EnviarRelatorioPastasParadasUseCase::TIPO_NOTIFICACAO => ['grupo' => 'atencao', 'rotulo' => 'Relatório', 'icone' => 'bi-pause-circle', 'cor' => self::AMBAR, 'nivel' => 2],
            // Gateway de pagamentos (M0b): pagamento divergente ou teste do banco, para o Master decidir.
            AvisoAoMaster::TIPO_PAGAMENTO_DIVERGENTE => ['grupo' => 'atencao', 'rotulo' => 'Pagamento', 'icone' => 'bi-cash-coin', 'cor' => self::AMBAR, 'nivel' => 2],
            ProcessarAnaliseDeInteligenciaHandler::TIPO_NOTIFICACAO => ['grupo' => 'mensagens', 'rotulo' => 'Documento analisado', 'icone' => 'bi-file-earmark-check', 'cor' => self::AZUL, 'nivel' => 1],
            // Suporte da tela de entrada (desenho 10 / bluejus-central.js bjSuporteFila: tipo "mensagem").
            EnviarMensagemSuporteUseCase::TIPO_NOTIFICACAO => ['grupo' => 'mensagens', 'rotulo' => 'Mensagem', 'icone' => 'bi-chat-left-text', 'cor' => self::PETROLEO, 'nivel' => 1],
            default => ['grupo' => null, 'rotulo' => 'Aviso', 'icone' => 'bi-bell', 'cor' => self::PETROLEO, 'nivel' => 1],
        };
    }
}
