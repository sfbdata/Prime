<?php

declare(strict_types=1);

namespace App\TrabalhoExterno\Service;

use App\TrabalhoExterno\Entity\DemandaExterna;
use App\TrabalhoExterno\Enum\AcaoDemanda;
use App\TrabalhoExterno\Enum\Perfil;
use App\TrabalhoExterno\Enum\Situacao;
use App\TrabalhoExterno\Enum\Visibilidade;

/**
 * Quem vê e quem faz o quê numa demanda. É a tradução, para o servidor, das regras que o protótipo
 * espalhava por `visiveis()`, `acoes()`, `pode()` e `renderVals()` (spec §2, §4, §5). Classe pura:
 * sem banco, sem sessão — o `Contexto` traz tudo.
 *
 * O navegador recebe o resultado (botões, abas, permissões) só para desenhar; **toda ação é
 * conferida de novo aqui** antes de executar.
 */
final class PoliticaDaDemanda
{
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

        return false;
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
     * @return list<array{k: string, rot: string, ic: string, tipo: string}>
     */
    public function botoes(Contexto $ctx, DemandaExterna $d): array
    {
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
                    break;
                case Situacao::Execucao:
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

        if ($p === Perfil::Solicitante) {
            if ($s === Situacao::Rascunho) {
                $atalho('editar', 'Continuar rascunho', 'bi-pencil', 'pri', '');
                $acao(AcaoDemanda::ExcluirRascunho, 'perigo');
            }
            if ($s === Situacao::Info) {
                $acao(AcaoDemanda::ResponderInformacoes, 'pri');
            }
            if ($s === Situacao::Proposta) {
                $acao(AcaoDemanda::AceitarProposta, 'pri');
                $acao(AcaoDemanda::SolicitarAjustes);
                $acao(AcaoDemanda::RecusarProposta, 'perigo');
            }
            if (in_array($s, [Situacao::Nova, Situacao::Analise, Situacao::Info], true)) {
                $acao(AcaoDemanda::Cancelar, 'perigo');
            }
        }

        return array_map(static fn (array $b): array => ['k' => $b['k'], 'rot' => $b['rot'], 'ic' => $b['ic'], 'tipo' => $b['tipo']], $botoes);
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
        return $this->podeVer($ctx, $d)
            && $this->pode($ctx, 'proposta')
            && in_array($d->getSituacao(), [Situacao::Analise, Situacao::Proposta], true);
    }

    public function podeConversar(Contexto $ctx, DemandaExterna $d): bool
    {
        if (!$this->podeVer($ctx, $d) || $d->getSituacao() === Situacao::Rascunho) {
            return false;
        }

        return $ctx->perfil !== Perfil::Equipe || $ctx->configuracao->equipePode('chat');
    }

    public function podeEscreverNotaInterna(Contexto $ctx, DemandaExterna $d): bool
    {
        return $ctx->ehInterno() && $this->podeConversar($ctx, $d);
    }

    /** Checklist e "Registrar andamento": só em execução, pela Central (ou convidado com permissão, F3). */
    public function podeRegistrarAndamento(Contexto $ctx, DemandaExterna $d): bool
    {
        return $this->podeVer($ctx, $d)
            && $d->getSituacao() === Situacao::Execucao
            && $ctx->ehInterno();
    }

    public function podeAnexar(Contexto $ctx, DemandaExterna $d): bool
    {
        return $this->podeVer($ctx, $d) && $d->getSituacao() !== Situacao::Rascunho;
    }

    public function podeBaixar(Contexto $ctx, DemandaExterna $d): bool
    {
        return $this->podeVer($ctx, $d);
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
            return 'Central BlueJus';
        }

        return $nome;
    }
}
