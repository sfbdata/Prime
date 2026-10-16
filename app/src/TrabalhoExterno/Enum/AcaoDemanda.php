<?php

declare(strict_types=1);

namespace App\TrabalhoExterno\Enum;

/**
 * Ações de fluxo que passam pela confirmação do desenho (`Component.exec` e `aplicarConf`).
 *
 * Quem pode executar cada uma, e em que situação, é decidido pela `PoliticaDaDemanda`; aqui mora
 * só o que a ação É: rótulo, ícone, texto da confirmação, se exige motivo, para onde leva e o
 * registro que grava no chat. Ações que não confirmam (abrir, editar rascunho) não estão aqui.
 */
enum AcaoDemanda: string
{
    case IniciarAnalise = 'analise';
    case Recusar = 'recusar';
    case SolicitarInformacoes = 'info';
    case RetomarAnalise = 'retomar';
    case ResponderInformacoes = 'responderInfo';
    case Cancelar = 'cancelar';
    case AceitarProposta = 'aceitar';
    case SolicitarAjustes = 'ajustes';
    case RecusarProposta = 'recusarProp';
    case IniciarExecucao = 'iniciar';
    case EnviarParaValidacao = 'validar';
    case DevolverParaExecucao = 'devolver';
    case Concluir = 'concluir';
    case RegistrarPagamento = 'pagamento';
    case Arquivar = 'arquivar';
    case Reabrir = 'reabrir';
    case InformarImpedimento = 'impedimento';
    case SolicitarProrrogacao = 'prorrogar';
    case ExcluirRascunho = 'excluirRasc';

    /** Situação de destino; null quando a ação só registra (pagamento, impedimento, prorrogação). */
    public function destino(): ?Situacao
    {
        return match ($this) {
            self::IniciarAnalise, self::RetomarAnalise, self::ResponderInformacoes, self::SolicitarAjustes, self::Reabrir => Situacao::Analise,
            self::Recusar, self::RecusarProposta => Situacao::Recusada,
            self::SolicitarInformacoes => Situacao::Info,
            self::Cancelar => Situacao::Cancelada,
            self::AceitarProposta => Situacao::Aceita,
            self::IniciarExecucao, self::DevolverParaExecucao => Situacao::Execucao,
            self::EnviarParaValidacao => Situacao::Validacao,
            self::Concluir => Situacao::Concluida,
            self::Arquivar => Situacao::Arquivada,
            self::RegistrarPagamento, self::InformarImpedimento, self::SolicitarProrrogacao, self::ExcluirRascunho => null,
        };
    }

    /**
     * Situações a partir das quais a ação é possível, para QUALQUER perfil. A `PoliticaDaDemanda`
     * estreita por perfil (ex.: o Solicitante cancela de nova a info; a Central, em proposta).
     *
     * @return list<Situacao>
     */
    public function origens(): array
    {
        return match ($this) {
            self::IniciarAnalise => [Situacao::Nova],
            self::Recusar => [Situacao::Nova, Situacao::Analise],
            self::SolicitarInformacoes => [Situacao::Analise],
            self::RetomarAnalise, self::ResponderInformacoes => [Situacao::Info],
            self::Cancelar => [Situacao::Nova, Situacao::Analise, Situacao::Info, Situacao::Proposta],
            self::AceitarProposta, self::SolicitarAjustes, self::RecusarProposta => [Situacao::Proposta],
            self::IniciarExecucao => [Situacao::Aceita],
            self::EnviarParaValidacao, self::InformarImpedimento, self::SolicitarProrrogacao => [Situacao::Execucao],
            self::DevolverParaExecucao, self::Concluir => [Situacao::Validacao],
            self::RegistrarPagamento => [Situacao::Aceita, Situacao::Execucao, Situacao::Validacao, Situacao::Concluida],
            self::Arquivar => [Situacao::Concluida],
            self::Reabrir => [Situacao::Concluida, Situacao::Arquivada, Situacao::Recusada, Situacao::Cancelada],
            self::ExcluirRascunho => [Situacao::Rascunho],
        };
    }

    /** Ações que mudam de estado sem passar pelo modal de confirmação (clique direto no desenho). */
    public function confirma(): bool
    {
        return !in_array($this, [self::IniciarAnalise, self::RetomarAnalise, self::IniciarExecucao], true);
    }

    public function exigeMotivo(): bool
    {
        return in_array($this, [
            self::SolicitarInformacoes, self::ResponderInformacoes, self::Recusar, self::Cancelar,
            self::SolicitarAjustes, self::RecusarProposta, self::DevolverParaExecucao, self::Reabrir,
            self::InformarImpedimento, self::SolicitarProrrogacao,
        ], true);
    }

    /** Destrutiva: botão vermelho no modal e no painel. */
    public function perigosa(): bool
    {
        return in_array($this, [self::Recusar, self::Cancelar, self::RecusarProposta, self::ExcluirRascunho], true);
    }

    /** Texto que vai para o chat como registro (`aplicarConf`, segundo item de cada par). */
    public function registro(): string
    {
        return match ($this) {
            self::IniciarAnalise => 'Análise iniciada',
            self::RetomarAnalise => 'Análise retomada',
            self::IniciarExecucao => 'Execução iniciada',
            self::SolicitarInformacoes => 'Informações solicitadas',
            self::ResponderInformacoes => 'Informações enviadas',
            self::Recusar => 'Demanda recusada',
            self::Cancelar => 'Demanda cancelada',
            self::AceitarProposta => 'Proposta aceita',
            self::SolicitarAjustes => 'Ajustes solicitados na proposta',
            self::RecusarProposta => 'Proposta recusada',
            self::EnviarParaValidacao => 'Execução enviada para validação',
            self::Concluir => 'Serviço concluído',
            self::DevolverParaExecucao => 'Devolvido para complementação',
            self::Arquivar => 'Demanda arquivada',
            self::Reabrir => 'Demanda reaberta',
            self::InformarImpedimento => 'Impedimento informado',
            self::RegistrarPagamento => 'Pagamento registrado',
            self::SolicitarProrrogacao => 'Prorrogação solicitada',
            self::ExcluirRascunho => 'Rascunho excluído',
        };
    }

    /**
     * Rótulo do botão no painel e no menu de contexto. O Solicitante vê "Cancelar solicitação" e
     * o Convidado vê "Marcar como concluída": o desenho troca o rótulo pelo perfil.
     */
    public function rotulo(Perfil $perfil): string
    {
        return match ($this) {
            self::IniciarAnalise => 'Iniciar análise',
            self::Recusar => 'Recusar demanda',
            self::SolicitarInformacoes => 'Solicitar informações',
            self::RetomarAnalise => 'Retomar análise',
            self::ResponderInformacoes => 'Enviar informações',
            self::Cancelar => $perfil === Perfil::Solicitante ? 'Cancelar solicitação' : 'Cancelar demanda',
            self::AceitarProposta => 'Aceitar proposta',
            self::SolicitarAjustes => 'Solicitar ajustes',
            self::RecusarProposta => 'Recusar proposta',
            self::IniciarExecucao => 'Iniciar execução',
            self::EnviarParaValidacao => $perfil === Perfil::Convidado ? 'Marcar como concluída' : 'Enviar para validação',
            self::DevolverParaExecucao => 'Devolver para execução',
            self::Concluir => 'Concluir serviço',
            self::RegistrarPagamento => 'Registrar pagamento',
            self::Arquivar => 'Arquivar demanda',
            self::Reabrir => 'Reabrir demanda',
            self::InformarImpedimento => 'Informar impedimento',
            self::SolicitarProrrogacao => 'Solicitar prorrogação',
            self::ExcluirRascunho => 'Excluir rascunho',
        };
    }

    public function icone(Perfil $perfil): string
    {
        return match ($this) {
            self::IniciarAnalise => 'bi-search',
            self::Recusar, self::RecusarProposta => 'bi-x-circle',
            self::SolicitarInformacoes => 'bi-question-circle',
            self::RetomarAnalise, self::Reabrir => 'bi-arrow-counterclockwise',
            self::ResponderInformacoes => 'bi-reply',
            self::Cancelar => 'bi-slash-circle',
            self::AceitarProposta => 'bi-check2-circle',
            self::SolicitarAjustes => 'bi-pencil-square',
            self::IniciarExecucao => 'bi-play-circle',
            self::EnviarParaValidacao => $perfil === Perfil::Convidado ? 'bi-check2-circle' : 'bi-hourglass-split',
            self::DevolverParaExecucao => 'bi-arrow-return-left',
            self::Concluir => 'bi-check2-all',
            self::RegistrarPagamento => 'bi-cash-coin',
            self::Arquivar => 'bi-archive',
            self::InformarImpedimento => 'bi-exclamation-triangle',
            self::SolicitarProrrogacao => 'bi-calendar-plus',
            self::ExcluirRascunho => 'bi-trash',
        };
    }

    /**
     * Título, texto e botão do modal de confirmação (`exec`, terceiro a quinto argumentos de `C`).
     *
     * @return array{titulo: string, texto: string, botao: string}
     */
    public function confirmacao(): array
    {
        [$titulo, $texto, $botao] = match ($this) {
            self::SolicitarInformacoes => ['Solicitar informações', 'A mensagem vai para o chat da demanda e o solicitante é notificado.', 'Solicitar'],
            self::ResponderInformacoes => ['Enviar informações', 'Descreva as informações solicitadas. A demanda volta para análise.', 'Enviar'],
            self::Recusar => ['Recusar demanda', 'A recusa é registrada no histórico e pode ser revertida com "Reabrir".', 'Recusar'],
            self::Cancelar => ['Cancelar demanda', 'O cancelamento é registrado no histórico.', 'Cancelar demanda'],
            self::AceitarProposta => ['Aceitar proposta', 'Ao aceitar, você autoriza a execução nas condições da proposta mais recente.', 'Aceitar proposta'],
            self::SolicitarAjustes => ['Solicitar ajustes', 'Explique o que precisa ser ajustado na proposta.', 'Solicitar ajustes'],
            self::RecusarProposta => ['Recusar proposta', 'A demanda será encerrada como recusada.', 'Recusar'],
            self::EnviarParaValidacao => ['Enviar para validação', 'O Master confere a execução antes de concluir.', 'Confirmar'],
            self::Concluir => ['Concluir serviço', 'O responsável e o horário da conclusão ficam registrados.', 'Concluir'],
            self::DevolverParaExecucao => ['Devolver para execução', 'Informe o que precisa ser complementado.', 'Devolver'],
            self::RegistrarPagamento => ['Registrar pagamento', 'Confirma o recebimento do pagamento desta demanda? A data fica registrada no histórico.', 'Registrar'],
            self::Arquivar => ['Arquivar demanda', 'A demanda sai das listas ativas, sem perder o histórico.', 'Arquivar'],
            self::Reabrir => ['Reabrir demanda', 'A demanda volta para análise. Informe o motivo.', 'Reabrir'],
            self::InformarImpedimento => ['Informar impedimento', 'Descreva o que está impedindo a execução.', 'Informar'],
            self::SolicitarProrrogacao => ['Solicitar prorrogação', 'Informe o novo prazo pretendido e o motivo.', 'Solicitar'],
            self::ExcluirRascunho => ['Excluir rascunho', 'O rascunho será removido definitivamente.', 'Excluir'],
            self::IniciarAnalise, self::RetomarAnalise, self::IniciarExecucao => [$this->rotulo(Perfil::Master), '', $this->rotulo(Perfil::Master)],
        };

        return ['titulo' => $titulo, 'texto' => $texto, 'botao' => $botao];
    }
}
