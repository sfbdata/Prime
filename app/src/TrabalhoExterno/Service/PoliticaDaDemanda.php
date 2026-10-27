<?php

declare(strict_types=1);

namespace App\TrabalhoExterno\Service;

use App\Entity\Tenant\Tenant;
use App\Tenant\Enum\PoderSemPrazo;
use App\Tenant\Service\PoderDeEdicaoSemPrazo;
use App\TrabalhoExterno\Entity\DemandaExterna;
use App\TrabalhoExterno\Entity\MensagemDemanda;
use App\TrabalhoExterno\Enum\AcaoDemanda;
use App\TrabalhoExterno\Enum\Perfil;
use App\TrabalhoExterno\Enum\Situacao;
use App\TrabalhoExterno\Enum\TipoMensagem;
use App\TrabalhoExterno\Enum\Visibilidade;

/**
 * Quem vê e quem faz o quê numa demanda. É a tradução, para o servidor, das regras que o protótipo
 * espalhava por `visiveis()`, `acoes()`, `pode()` e `renderVals()` (spec §2, §4, §5). Classe pura:
 * sem banco, sem sessão — o `Contexto` traz tudo.
 *
 * O navegador recebe o resultado (botões, abas, permissões) só para desenhar; **toda ação é
 * conferida de novo aqui** antes de executar.
 *
 * A única pergunta que sai do `Contexto` é a da edição sem prazo (spec `edicao-sem-prazo-master-primario.md`,
 * item 8): o primário do escritório dono da demanda, com a opção ligada, apaga qualquer mensagem. Sem o serviço
 * (testes que montam a política à mão), ninguém tem esse poder e vale a regra de sempre.
 */
final class PoliticaDaDemanda
{
    /** O nome que o leitor externo vê no lugar de quem é da Central, sem `priv.nomeResp` ({@see self::nomeVisivel()}). */
    public const NOME_DA_CENTRAL = 'Central BlueJus';

    public function __construct(
        private readonly ?PoderDeEdicaoSemPrazo $poderSemPrazo = null,
    ) {
    }

    /** Marcos de antes do aceite que o Convidado pode ver (só o marco e a data). */
    private const MARCOS_ADMINISTRATIVOS = ['nova', 'aceita', 'execucao'];

    /** A aba inteira fica bloqueada para este perfil (tela "Acesso restrito"/"indisponível"). */
    public function abaBloqueada(Contexto $ctx): bool
    {
        $vis = $ctx->configuracao->getVisibilidade();

        if ($ctx->perfil === Perfil::Master) {
            return false;
        }
        if ($ctx->perfil === Perfil::Equipe) {
            return $vis === Visibilidade::Oculta || !$ctx->configuracao->equipePode('ver');
        }

        return $vis !== Visibilidade::Todos;
    }

    public function podeVer(Contexto $ctx, DemandaExterna $d): bool
    {
        if ($this->abaBloqueada($ctx)) {
            return false;
        }
        if ($ctx->ehInterno()) {
            return $d->getSituacao() !== Situacao::Rascunho;
        }
        if ($ctx->perfil === Perfil::Solicitante) {
            return $ctx->tenant !== null && $d->getTenant()->getId() === $ctx->tenant->getId();
        }

        // Convidado: só a demanda do PRÓPRIO convite, vigente, e só da aceitação em diante.
        $convite = $ctx->convite;

        return $convite !== null
            && $convite->getDemanda()->getId() === $d->getId()
            && $convite->getConvidado()?->getId() === $ctx->usuario->getId()
            && $convite->vigente(new \DateTimeImmutable('today'))
            && $d->getSituacao()->ehDeExecucao();
    }

    /** A permissão do convite (sempre falsa fora do perfil Convidado). */
    private function convidadoPode(Contexto $ctx, string $permissao): bool
    {
        return $ctx->perfil === Perfil::Convidado && $ctx->convite !== null && $ctx->convite->pode($permissao);
    }

    /** O Master sempre; a Equipe conforme a chave de "EQUIPE DO MASTER" (`Component.pode`). */
    public function pode(Contexto $ctx, string $chave): bool
    {
        return $ctx->perfil === Perfil::Master
            || ($ctx->perfil === Perfil::Equipe && $ctx->configuracao->equipePode($chave));
    }

    /**
     * Os botões da demanda, na ordem do desenho. Além das {@see AcaoDemanda}, entram os atalhos
     * que só abrem uma aba ou o modal: `proposta` ("Elaborar proposta"/"Nova versão da proposta")
     * e `editar` ("Continuar rascunho").
     *
     * @return list<array{k: string, rot: string, ic: string, tipo: string, atalho: bool, confirmacao: ?array<string, mixed>}>
     */
    public function botoes(Contexto $ctx, DemandaExterna $d): array
    {
        if ($ctx->somenteLeitura()) {
            return [];
        }
        $s = $d->getSituacao();
        $p = $ctx->perfil;
        $botoes = [];
        $acao = static function (AcaoDemanda $a, string $tipo = '') use (&$botoes, $p): void {
            $botoes[] = ['k' => $a->value, 'rot' => $a->rotulo($p), 'ic' => $a->icone($p), 'tipo' => $tipo, 'equipe' => 'situacao'];
        };
        $atalho = static function (string $k, string $rot, string $ic, string $tipo, string $equipe) use (&$botoes): void {
            $botoes[] = ['k' => $k, 'rot' => $rot, 'ic' => $ic, 'tipo' => $tipo, 'equipe' => $equipe];
        };

        if ($ctx->ehInterno()) {
            switch ($s) {
                case Situacao::Nova:
                    $acao(AcaoDemanda::IniciarAnalise, 'pri');
                    $acao(AcaoDemanda::Recusar, 'perigo');
                    break;
                case Situacao::Analise:
                    $atalho('proposta', 'Elaborar proposta', 'bi-file-earmark-text', 'pri', 'proposta');
                    $acao(AcaoDemanda::SolicitarInformacoes);
                    $acao(AcaoDemanda::Recusar, 'perigo');
                    break;
                case Situacao::Info:
                    $acao(AcaoDemanda::RetomarAnalise);
                    break;
                case Situacao::Proposta:
                    $atalho('proposta', 'Nova versão da proposta', 'bi-file-earmark-plus', '', 'proposta');
                    $acao(AcaoDemanda::Cancelar, 'perigo');
                    break;
                case Situacao::Aceita:
                    $acao(AcaoDemanda::IniciarExecucao, 'pri');
                    $atalho('convidar', 'Convidar profissional', 'bi-envelope', '', 'convidar');
                    break;
                case Situacao::Execucao:
                    $atalho('convidar', 'Convidar profissional', 'bi-envelope', '', 'convidar');
                    $acao(AcaoDemanda::EnviarParaValidacao);
                    break;
                case Situacao::Validacao:
                    $acao(AcaoDemanda::Concluir, 'pri');
                    $acao(AcaoDemanda::DevolverParaExecucao);
                    break;
                default:
                    break;
            }
            if ($s->ehDeExecucao() && $d->getPagoEm() === null) {
                $acao(AcaoDemanda::RegistrarPagamento);
            }
            if ($s === Situacao::Concluida) {
                $acao(AcaoDemanda::Arquivar);
            }
            if (in_array($s, AcaoDemanda::Reabrir->origens(), true)) {
                $acao(AcaoDemanda::Reabrir);
            }
            if ($p === Perfil::Equipe) {
                $botoes = array_values(array_filter($botoes, fn (array $b): bool => $ctx->configuracao->equipePode($b['equipe'])));
            }
        }

        if ($p === Perfil::Solicitante && $s === Situacao::Rascunho) {
            $atalho('editar', 'Continuar rascunho', 'bi-pencil', 'pri', '');
            $acao(AcaoDemanda::ExcluirRascunho, 'perigo');
        }
        // O Solicitante; e o Master nas demandas de um escritório DELE (dois papéis, spec §9.2),
        // com o rótulo de Solicitante ("Cancelar solicitação").
        if ($p === Perfil::Solicitante || $this->agePeloEscritorio($ctx, $d)) {
            foreach (self::acoesDoSolicitante($s) as [$a, $tipo]) {
                $botoes[] = ['k' => $a->value, 'rot' => $a->rotulo(Perfil::Solicitante), 'ic' => $a->icone(Perfil::Solicitante), 'tipo' => $tipo, 'equipe' => ''];
            }
        }

        if ($p === Perfil::Convidado && $s === Situacao::Execucao) {
            if ($this->convidadoPode($ctx, 'concluir')) {
                $acao(AcaoDemanda::EnviarParaValidacao, 'pri');
            }
            $acao(AcaoDemanda::InformarImpedimento);
            $acao(AcaoDemanda::SolicitarProrrogacao);
        }

        return array_map(static function (array $b): array {
            $acao = AcaoDemanda::tryFrom($b['k']);
            $confirmacao = $acao?->confirma() ? $acao->confirmacao() : null;

            return [
                'k' => $b['k'],
                'rot' => $b['rot'],
                'ic' => $b['ic'],
                'tipo' => $b['tipo'],
                // Atalho (abre aba ou modal) não tem confirmação; ação de clique direto também não.
                'atalho' => $acao === null,
                'confirmacao' => $confirmacao === null ? null : $confirmacao + [
                    'motivo' => $acao->exigeMotivo(),
                    'perigo' => $acao->perigosa(),
                ],
            ];
        }, $botoes);
    }

    /**
     * O lado do Solicitante numa demanda, por situação (o rascunho fica à parte: só o próprio
     * escritório o vê).
     *
     * @return list<array{0: AcaoDemanda, 1: string}>
     */
    private static function acoesDoSolicitante(Situacao $s): array
    {
        return match ($s) {
            Situacao::Nova, Situacao::Analise => [[AcaoDemanda::Cancelar, 'perigo']],
            Situacao::Info => [[AcaoDemanda::ResponderInformacoes, 'pri'], [AcaoDemanda::Cancelar, 'perigo']],
            Situacao::Proposta => [[AcaoDemanda::AceitarProposta, 'pri'], [AcaoDemanda::SolicitarAjustes, ''], [AcaoDemanda::RecusarProposta, 'perigo']],
            default => [],
        };
    }

    /**
     * Dois papéis (spec §9.2): o Master que é usuário (vínculo ativo) do escritório solicitante da
     * demanda age também como o Solicitante dela. Nunca na pré-visualização.
     */
    public function agePeloEscritorio(Contexto $ctx, DemandaExterna $d): bool
    {
        return $ctx->perfil === Perfil::Master
            && !$ctx->previa
            && in_array((int) $d->getTenant()->getId(), $ctx->escritoriosDoPrimario, true);
    }

    /**
     * Em que papel a ação é registrada: a do lado do Solicitante, feita pelo Master num escritório
     * dele, fica como Solicitante (é assim que o histórico e o "aceita por" devem contar).
     */
    public function papelNaAcao(Contexto $ctx, DemandaExterna $d, AcaoDemanda $acao): Perfil
    {
        if ($this->agePeloEscritorio($ctx, $d)) {
            foreach (self::acoesDoSolicitante($d->getSituacao()) as [$a]) {
                if ($a === $acao) {
                    return Perfil::Solicitante;
                }
            }
        }

        return $ctx->perfil;
    }

    /** A ação está entre os botões que este perfil vê agora: é a mesma regra que desenhou a tela. */
    public function podeExecutar(Contexto $ctx, DemandaExterna $d, AcaoDemanda $acao): bool
    {
        if (!$this->podeVer($ctx, $d)) {
            return false;
        }

        foreach ($this->botoes($ctx, $d) as $b) {
            if ($b['k'] === $acao->value) {
                return true;
            }
        }

        return false;
    }

    public function podePropor(Contexto $ctx, DemandaExterna $d): bool
    {
        return !$ctx->previa
            && $this->podeVer($ctx, $d)
            && $this->pode($ctx, 'proposta')
            && in_array($d->getSituacao(), [Situacao::Analise, Situacao::Proposta], true);
    }

    public function podeConversar(Contexto $ctx, DemandaExterna $d): bool
    {
        if ($ctx->somenteLeitura() || !$this->podeVer($ctx, $d) || $d->getSituacao() === Situacao::Rascunho) {
            return false;
        }

        return match ($ctx->perfil) {
            Perfil::Equipe => $ctx->configuracao->equipePode('chat'),
            Perfil::Convidado => $this->convidadoPode($ctx, 'chat'),
            default => true,
        };
    }

    public function podeEscreverNotaInterna(Contexto $ctx, DemandaExterna $d): bool
    {
        return $ctx->ehInterno() && $this->podeConversar($ctx, $d);
    }

    /** Checklist e "Registrar andamento": só em execução, pela Central ou pelo Convidado com "andamento". */
    public function podeRegistrarAndamento(Contexto $ctx, DemandaExterna $d): bool
    {
        return !$ctx->somenteLeitura()
            && $this->podeVer($ctx, $d)
            && $d->getSituacao() === Situacao::Execucao
            && ($ctx->ehInterno() || $this->convidadoPode($ctx, 'andamento'));
    }

    public function podeAnexar(Contexto $ctx, DemandaExterna $d): bool
    {
        return !$ctx->somenteLeitura()
            && $this->podeVer($ctx, $d)
            && $d->getSituacao() !== Situacao::Rascunho
            && ($ctx->perfil !== Perfil::Convidado || $this->convidadoPode($ctx, 'enviar'));
    }

    /**
     * DESPACHO (desenho 06 de 24/10, `dpPode`, l.1392): publicar e ligar "Aceitar resposta" é só do Master. A
     * Equipe não despacha (decisão do coordenador, 24/10).
     */
    public function podeDespachar(Contexto $ctx, DemandaExterna $d): bool
    {
        return $ctx->perfil === Perfil::Master
            && !$ctx->somenteLeitura()
            && $this->podeVer($ctx, $d);
    }

    /**
     * Responder ao despacho (`dpPodeResp: p !== 'master'`): todo perfil menos o Master (quem pediu, a Equipe e o
     * profissional do convite vigente, que vê a demanda), com "Aceitar resposta" ligado e ao menos um despacho
     * publicado. Decisão do dono de 24/10: a Equipe responde, como no desenho.
     */
    public function podeResponderAoDespacho(Contexto $ctx, DemandaExterna $d): bool
    {
        return $ctx->perfil !== Perfil::Master
            && !$ctx->somenteLeitura()
            && $this->podeVer($ctx, $d)
            && $d->aceitaRespostaAoDespacho();
    }

    /** A aba Pagamento (e o pedido do PIX dinâmico dela): todo perfil que vê a demanda, menos o Convidado, fora da prévia para escrever. */
    public function podeVerAbaPagamento(Contexto $ctx, DemandaExterna $d): bool
    {
        return $ctx->perfil !== Perfil::Convidado && $this->podeVer($ctx, $d);
    }

    /**
     * "Já paguei · enviar comprovante" (desenho 25/10, `pg.podeComp`): só o Solicitante da própria demanda (o Master
     * que age pelo escritório, o interno e o Convidado não), fora da prévia, e só onde a confirmação ainda vale.
     */
    public function podeEnviarComprovante(Contexto $ctx, DemandaExterna $d): bool
    {
        return $ctx->perfil === Perfil::Solicitante
            && !$ctx->somenteLeitura()
            && $this->podeVer($ctx, $d)
            && in_array($d->getSituacao(), AcaoDemanda::RegistrarPagamento->origens(), true);
    }

    /**
     * "Confirmar recebimento" e "Enviar no chat" (`pg.podeConf`): quem pode registrar o pagamento (interno, e a
     * Equipe só com "Alterar a situação"), nunca na prévia. É a mesma guarda do botão "Registrar pagamento".
     */
    public function podeConfirmarRecebimento(Contexto $ctx, DemandaExterna $d): bool
    {
        return $ctx->ehInterno() && $this->podeExecutar($ctx, $d, AcaoDemanda::RegistrarPagamento);
    }

    /**
     * O lápis do valor da aba Pagamento: só o Master (nunca na prévia), com a demanda no estado editável (proposta
     * aceita, não paga, sem comprovante, nas situações do pagamento).
     */
    public function podeAjustarValorDoPagamento(Contexto $ctx, DemandaExterna $d): bool
    {
        return $ctx->perfil === Perfil::Master
            && !$ctx->somenteLeitura()
            && $this->podeVer($ctx, $d)
            && $d->valorDoPagamentoEditavel();
    }

    /**
     * "Recusar comprovante" da aba Pagamento: só o Master (nunca na prévia), com comprovante enviado, sem pagar, nas
     * situações do pagamento.
     */
    public function podeRecusarComprovante(Contexto $ctx, DemandaExterna $d): bool
    {
        return $ctx->perfil === Perfil::Master
            && !$ctx->somenteLeitura()
            && $this->podeVer($ctx, $d)
            && $d->comprovanteRecusavel();
    }

    /** A lista de documentos: o Convidado só a vê com "Ver instruções" (`cp.ver` do desenho). */
    public function podeVerDocumentos(Contexto $ctx, DemandaExterna $d): bool
    {
        return $this->podeVer($ctx, $d) && ($ctx->perfil !== Perfil::Convidado || $this->convidadoPode($ctx, 'ver'));
    }

    public function podeBaixar(Contexto $ctx, DemandaExterna $d): bool
    {
        return $this->podeVerDocumentos($ctx, $d) && ($ctx->perfil !== Perfil::Convidado || $this->convidadoPode($ctx, 'baixar'));
    }

    /** Novo convite: Master, ou Equipe com "convidar", em demanda aceita ou em execução. */
    public function podeConvidar(Contexto $ctx, DemandaExterna $d): bool
    {
        return $this->podeGerirConvites($ctx, $d) && in_array($d->getSituacao(), [Situacao::Aceita, Situacao::Execucao], true);
    }

    /** Reenviar e revogar: a mesma permissão, em qualquer situação (`c.gerir` do desenho). */
    public function podeGerirConvites(Contexto $ctx, DemandaExterna $d): bool
    {
        return !$ctx->previa && $this->podeVer($ctx, $d) && $this->pode($ctx, 'convidar');
    }

    /** Aba WhatsApp: quem vê a demanda, menos o Convidado (o desenho tira a aba dele). */
    public function podeUsarWhatsapp(Contexto $ctx, DemandaExterna $d): bool
    {
        return $this->podeVer($ctx, $d)
            && $ctx->perfil !== Perfil::Convidado
            && $d->getSituacao() !== Situacao::Rascunho;
    }

    /**
     * O leitor lê esta mensagem no chat? É a regra ÚNICA: o chat, o contador de não lidas e o aviso
     * "Abrir conversa" passam todos por aqui. Nota interna nunca sai da Central. O Convidado lê só
     * da aceitação do convite em diante (o que veio antes, inclusive o registro da proposta com o
     * valor, é da Central com o cliente), não lê os registros de pagamento (têm o valor) e, sem "Ver instruções", não lê os registros de arquivo
     * (eles nomeiam os documentos).
     */
    public function mensagemVisivel(Contexto $ctx, DemandaExterna $d, MensagemDemanda $m): bool
    {
        if ($ctx->ehInterno()) {
            return true;
        }
        if ($m->getTipo() === TipoMensagem::Interna) {
            return false;
        }
        if ($ctx->perfil !== Perfil::Convidado) {
            return true;
        }
        // Dinheiro (valor do PIX, comprovante): o Convidado não lê, como não lê a proposta.
        if ($m->getTipo() === TipoMensagem::Pagamento) {
            return false;
        }

        return $ctx->convite !== null
            && $ctx->convite->mensagemVisivel($m)
            && ($m->getTipo() !== TipoMensagem::Arquivo || $this->podeVerDocumentos($ctx, $d));
    }

    /** O autor apaga para todos até este prazo depois de enviar; o Master, sempre (desenho l.1233). */
    public const MINUTOS_PARA_O_AUTOR_APAGAR = 60;

    /**
     * "Apagar para todos" (spec VS 18.10.26 §3.4, desenho l.1233): o Master em qualquer mensagem; o
     * autor nas próprias, até 60 min após o envio; e, pela edição sem prazo, o primário do escritório dono
     * da demanda (com a opção ligada). Registro do sistema não se apaga.
     */
    public function podeApagarMensagem(Contexto $ctx, MensagemDemanda $m, ?\DateTimeImmutable $agora = null): bool
    {
        return $this->apagaPelaRegraDeSempre($ctx, $m, $agora) || $this->seloDoApagar($ctx, $m, $agora) !== null;
    }

    /** A regra de antes da edição sem prazo: o Master do TE sempre; o autor até 60 min. */
    public function apagaPelaRegraDeSempre(Contexto $ctx, MensagemDemanda $m, ?\DateTimeImmutable $agora = null): bool
    {
        if ($ctx->somenteLeitura() || !$m->podeSerApagada()) {
            return false;
        }
        if ($ctx->perfil === Perfil::Master) {
            return true;
        }
        $agora ??= new \DateTimeImmutable();

        return $m->getAutor()?->getId() === $ctx->usuario->getId()
            && $m->getEm() >= $agora->modify('-' . self::MINUTOS_PARA_O_AUTOR_APAGAR . ' minutes');
    }

    /**
     * O selo "EXCLUSIVO DO PRIMÁRIO" quando só o poder sem prazo libera o apagar: o primário do escritório DONO
     * da demanda (não o de quem lê), em mensagem de alguém da equipe do dono. O Master do TE já apaga pela regra de
     * sempre, então não leva selo.
     */
    public function seloDoApagar(Contexto $ctx, MensagemDemanda $m, ?\DateTimeImmutable $agora = null): ?PoderSemPrazo
    {
        if ($this->poderSemPrazo === null || $ctx->somenteLeitura() || !$m->podeSerApagada() || $this->apagaPelaRegraDeSempre($ctx, $m, $agora)) {
            return null;
        }
        $dono = $m->getDemanda()->getTenant();
        $poder = $this->poderSemPrazo->de($ctx->usuario, $dono);

        return $poder === PoderSemPrazo::Primario && $this->autorDaEquipeDoDono($m, $dono) ? $poder : null;
    }

    /**
     * Decisão do dono (23/10): o primário só apaga mensagem de quem tem vínculo ativo no escritório dono. Ficam de
     * fora a Central (Master e Equipe) e os convidados de outros escritórios.
     */
    private function autorDaEquipeDoDono(MensagemDemanda $m, Tenant $dono): bool
    {
        $autor = $m->getAutor();

        return $autor !== null
            && !in_array($m->getPerfilAutor(), [Perfil::Master, Perfil::Equipe], true)
            && $this->poderSemPrazo?->temVinculoAtivo($autor, $dono) === true;
    }

    /**
     * O evento é anterior ao aceite do convite de quem lê (só vale para o Convidado). As datas
     * são gravadas em segundos: o que caiu no MESMO segundo do aceite conta como anterior, para
     * a dúvida esconder em vez de mostrar.
     */
    public function anteriorAoAceite(Contexto $ctx, \DateTimeImmutable $em): bool
    {
        if ($ctx->perfil !== Perfil::Convidado) {
            return false;
        }
        $aceitoEm = $ctx->convite?->getAceitoEm();

        return $aceitoEm === null || $em <= $aceitoEm;
    }

    /**
     * De antes do aceite, o Convidado vê só os marcos administrativos (solicitada, aceita, em
     * execução), sem autor nem motivo. Análise, pedido de informação, propostas, ajustes e
     * pagamento são a negociação da Central com o cliente e não chegam a ele.
     */
    public function marcoReservado(Contexto $ctx, string $marco, \DateTimeImmutable $em): bool
    {
        return $this->anteriorAoAceite($ctx, $em) && !in_array($marco, self::MARCOS_ADMINISTRATIVOS, true);
    }

    /** E-mail e telefone do solicitante: escondidos do Convidado sem `priv.contatoConv`. */
    public function veContato(Contexto $ctx): bool
    {
        return $ctx->perfil !== Perfil::Convidado || $ctx->configuracao->privacidade('contatoConv');
    }

    /**
     * Nome de quem fez algo, como o leitor deve ver. Para externos, sem `priv.nomeResp`, quem é da
     * Central aparece como "Central BlueJus" (`mask` do desenho).
     */
    public function nomeVisivel(Contexto $ctx, string $nome, Perfil $perfilDoAutor): string
    {
        if (!$ctx->ehInterno() && $perfilDoAutor->ehInterno() && !$ctx->configuracao->privacidade('nomeResp')) {
            return self::NOME_DA_CENTRAL;
        }

        return $nome;
    }
}
