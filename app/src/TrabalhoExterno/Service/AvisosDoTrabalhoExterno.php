<?php

declare(strict_types=1);

namespace App\TrabalhoExterno\Service;

use App\Entity\Auth\User;
use App\Entity\Notificacao;
use App\Entity\Tenant\Tenant;
use App\Service\NotificacaoService;
use App\TrabalhoExterno\Entity\Candidatura;
use App\TrabalhoExterno\Entity\ConfiguracaoCentral;
use App\TrabalhoExterno\Entity\DemandaExterna;
use App\TrabalhoExterno\Entity\PreferenciaDoUsuario;
use App\TrabalhoExterno\Entity\Vaga;
use App\TrabalhoExterno\Enum\Perfil;
use App\TrabalhoExterno\Enum\SituacaoVaga;
use App\TrabalhoExterno\Repository\CurriculoRepository;
use App\TrabalhoExterno\Repository\DestinatariosDosAvisosRepository;
use App\TrabalhoExterno\Repository\PreferenciaDoUsuarioRepository;

/**
 * Os avisos do Trabalho Externo no sino da Central (spec VS 18.10.26 §4.7, tabela "Avisos no sino"):
 * um método por evento, que acha quem recebe e em que escritório, e cria a `Notificacao`.
 *
 * - **Central** (Master e Equipe com `talentos`): no escritório-sede. Sem sede, nenhum aviso.
 * - **Dono** da vaga: os Solicitantes do escritório dela; sem escritório na vaga (Convidado), quem pediu,
 *   no primeiro escritório ativo dele. Sem escritório nenhum, nenhum aviso.
 * - **Candidato**: no escritório gravado na candidatura.
 * - **Alerta de vaga**: quem ligou o aviso, no escritório guardado na preferência, com área em comum
 *   com o currículo (ou sem currículo, ou vaga sem área), só com o `sino` ligado pelo Master.
 *
 * A `Notificacao` é auditada (`app/src/Central/CLAUDE.md`): o título leva o título da vaga ou o código
 * da demanda, nunca o nome nem o contato de um candidato. Quem age não recebe o próprio aviso; o aviso
 * NÃO LIDO do mesmo tipo e link para a mesma pessoa não se repete. Só persiste: o `flush` é do caso de uso.
 */
final class AvisosDoTrabalhoExterno
{
    public const TELA = '/trabalho-externo';

    public function __construct(
        private readonly NotificacaoService $notificacoes,
        private readonly DestinatariosDosAvisosRepository $destinatarios,
        private readonly PreferenciaDoUsuarioRepository $preferencias,
        private readonly CurriculoRepository $curriculos,
        private readonly ResolvedorDePerfil $perfis,
    ) {
    }

    /** Pedido de divulgação novo ou corrigido e reenviado (l.1005): para a Central aprovar. */
    public function pedidoDeDivulgacao(Contexto $ctx, Vaga $vaga, bool $reenviado): void
    {
        $titulo = ($reenviado ? 'Pedido de divulgação reenviado: ' : 'Novo pedido de divulgação de vaga: ') . $vaga->getTitulo();
        $this->paraACentral($ctx, Notificacao::TIPO_TRABALHO_EXTERNO_PEDIDO_DE_VAGA, $titulo, self::urlDaVaga($vaga));
    }

    /**
     * Candidatura nova (l.974) e aceite de oferta (l.978). A candidatura comum vai para a Central, que é
     * quem analisa e envia; o aceite vai para a Central na vaga dela e para o dono na vaga de escritório.
     */
    public function candidatura(Contexto $ctx, Candidatura $candidatura): void
    {
        $vaga = $candidatura->getVaga();
        $url = self::urlDaVaga($vaga);
        if (!$candidatura->isAceiteDeOferta()) {
            $this->paraACentral($ctx, Notificacao::TIPO_TRABALHO_EXTERNO_CANDIDATURA, 'Nova candidatura na vaga ' . $vaga->getTitulo(), $url);

            return;
        }
        $titulo = 'Oferta aceita na vaga ' . $vaga->getTitulo();
        $vaga->ehDaCentral()
            ? $this->paraACentral($ctx, Notificacao::TIPO_TRABALHO_EXTERNO_OFERTA_ACEITA, $titulo, $url)
            : $this->paraODono($ctx, $vaga, Notificacao::TIPO_TRABALHO_EXTERNO_OFERTA_ACEITA, $titulo);
    }

    /** "Enviar N ao escritório" (l.981). */
    public function candidatosEnviados(Contexto $ctx, Vaga $vaga): void
    {
        $this->paraODono($ctx, $vaga, Notificacao::TIPO_TRABALHO_EXTERNO_CANDIDATOS_ENVIADOS, 'Candidatos indicados pelo BlueJus na vaga ' . $vaga->getTitulo());
    }

    /** "Aprovar e publicar" (l.979). */
    public function vagaAprovada(Contexto $ctx, Vaga $vaga): void
    {
        $this->paraODono($ctx, $vaga, Notificacao::TIPO_TRABALHO_EXTERNO_VAGA_APROVADA, 'Sua vaga foi aprovada e publicada: ' . $vaga->getTitulo());
    }

    /** "Não aprovar", com o motivo que o solicitante verá (l.980). */
    public function vagaReprovada(Contexto $ctx, Vaga $vaga): void
    {
        $motivo = $vaga->getMotivo();
        $this->paraODono($ctx, $vaga, Notificacao::TIPO_TRABALHO_EXTERNO_VAGA_REPROVADA, 'Sua vaga não foi aprovada: ' . $vaga->getTitulo(), $motivo !== null ? 'Motivo: ' . $motivo : null);
    }

    /** Mudança de status do candidato (l.985, 1040): no escritório gravado na candidatura. */
    public function andamentoDaCandidatura(Contexto $ctx, Candidatura $candidatura): void
    {
        $escritorio = $candidatura->getEscritorioDoCandidato();
        if ($escritorio === null) {
            return;
        }
        $vaga = $candidatura->getVaga();
        $this->avisar($ctx, [$candidatura->getCurriculo()->getUsuario()], $escritorio, Notificacao::TIPO_TRABALHO_EXTERNO_CANDIDATURA_STATUS, 'Andamento atualizado na sua candidatura à vaga ' . $vaga->getTitulo(), self::urlDaVaga($vaga));
    }

    /** Vaga nova ou reaberta na área (l.1012, 1107, 1160): aprovada, publicada pela Central, reaberta ou ofertada de novo. */
    public function vagaNaArea(Contexto $ctx, Vaga $vaga, \DateTimeImmutable $agora): void
    {
        $cfg = $ctx->configuracao;
        if (!$cfg->getVagas()['sino'] || $vaga->situacaoEm($agora) !== SituacaoVaga::Aberta) {
            return;
        }
        foreach ($this->preferencias->comChave(PreferenciaDoUsuario::ALERTA_DE_VAGAS) as $preferencia) {
            $escritorio = $this->escritorioDoInteressado($preferencia, $vaga, $cfg);
            if ($escritorio !== null) {
                $this->avisar($ctx, [$preferencia->getUsuario()], $escritorio, Notificacao::TIPO_TRABALHO_EXTERNO_VAGA_NA_AREA, 'Nova vaga na sua área: ' . $vaga->getTitulo(), self::urlDaVaga($vaga));
            }
        }
    }

    /**
     * "Solicitar informações" numa demanda (l.784: "o solicitante é notificado"): quem solicitou, se ainda
     * está no escritório da demanda; senão, os Solicitantes dele.
     */
    public function pedidoDeInformacoes(Contexto $ctx, DemandaExterna $demanda): void
    {
        $escritorio = $demanda->getTenant();
        $autor = $demanda->getCriadoPor();
        $pessoas = $autor !== null && $this->destinatarios->escritorioDaPessoa($autor, (int) $escritorio->getId()) !== null
            ? [$autor]
            : $this->solicitantes($escritorio, $ctx->configuracao);
        $this->avisar($ctx, $pessoas, $escritorio, Notificacao::TIPO_TRABALHO_EXTERNO_DEMANDA_INFORMACOES, 'Pedido de informações na demanda ' . $demanda->getCodigo(), self::TELA . '?te=' . $demanda->getCodigo());
    }

    public static function urlDaVaga(Vaga $vaga): string
    {
        return self::TELA . '#vaga=' . $vaga->getCodigo();
    }

    private function paraACentral(Contexto $ctx, string $tipo, string $titulo, string $url): void
    {
        $sede = $ctx->configuracao->getSede();
        if ($sede === null) {
            return;
        }
        $equipe = $ctx->configuracao->equipePode('talentos')
            ? array_filter($this->destinatarios->ativosDoEscritorio($sede), fn (User $u): bool => $this->perfis->resolver($u, $sede, $ctx->configuracao) === Perfil::Equipe)
            : [];
        $this->avisar($ctx, [...$this->destinatarios->masters(), ...$equipe], $sede, $tipo, $titulo, $url);
    }

    private function paraODono(Contexto $ctx, Vaga $vaga, string $tipo, string $titulo, ?string $mensagem = null): void
    {
        if ($vaga->ehDaCentral()) {
            return;
        }
        $escritorio = $vaga->getEscritorio();
        if ($escritorio !== null) {
            $this->avisar($ctx, $this->solicitantes($escritorio, $ctx->configuracao), $escritorio, $tipo, $titulo, self::urlDaVaga($vaga), $mensagem);

            return;
        }
        $quemPediu = $vaga->getCriadaPor();
        $seu = $quemPediu !== null ? $this->destinatarios->primeiroEscritorio($quemPediu) : null;
        if ($quemPediu !== null && $seu !== null) {
            $this->avisar($ctx, [$quemPediu], $seu, $tipo, $titulo, self::urlDaVaga($vaga), $mensagem);
        }
    }

    /** @return list<User> quem tem o perfil Solicitante no escritório (permissão do módulo, sem ser Master) */
    private function solicitantes(Tenant $escritorio, ConfiguracaoCentral $cfg): array
    {
        return array_values(array_filter(
            $this->destinatarios->ativosDoEscritorio($escritorio),
            fn (User $u): bool => $this->perfis->resolver($u, $escritorio, $cfg) === Perfil::Solicitante,
        ));
    }

    /**
     * O escritório em que o interessado recebe o alerta desta vaga, ou null se não recebe: aviso ligado,
     * vínculo ativo no escritório guardado, externo nele (nem Master nem Equipe), não é o dono da vaga e
     * tem área em comum com ela (ou não tem currículo, ou a vaga não tem área), como o desenho (l.1012).
     */
    private function escritorioDoInteressado(PreferenciaDoUsuario $preferencia, Vaga $vaga, ConfiguracaoCentral $cfg): ?Tenant
    {
        $valor = $preferencia->getValor();
        $pessoa = $preferencia->getUsuario();
        if (($valor['ligado'] ?? false) !== true || !is_int($valor['escritorioId'] ?? null) || $vaga->getCriadaPor()?->getId() === $pessoa->getId()) {
            return null;
        }
        $escritorio = $this->destinatarios->escritorioDaPessoa($pessoa, $valor['escritorioId']);
        if ($escritorio === null || $escritorio->getId() === $vaga->getEscritorio()?->getId() || in_array($this->perfis->resolver($pessoa, $escritorio, $cfg), [Perfil::Master, Perfil::Equipe], true)) {
            return null;
        }
        $cv = $this->curriculos->doUsuario($pessoa);

        return $cv === null || $vaga->getAreas() === [] || array_intersect($vaga->getAreas(), $cv->getAreas()) !== [] ? $escritorio : null;
    }

    /** @param list<User> $pessoas */
    private function avisar(Contexto $ctx, array $pessoas, Tenant $escritorio, string $tipo, string $titulo, string $url, ?string $mensagem = null): void
    {
        $titulo = mb_substr($titulo, 0, 255);
        $vistos = [(int) $ctx->usuario->getId() => true];
        foreach ($pessoas as $pessoa) {
            $id = (int) $pessoa->getId();
            if (isset($vistos[$id]) || $this->destinatarios->temPendente($pessoa, $escritorio, $tipo, $url)) {
                continue;
            }
            $vistos[$id] = true;
            $this->notificacoes->criar($pessoa, $escritorio, $tipo, $titulo, $mensagem)->setUrl($url);
        }
    }
}
