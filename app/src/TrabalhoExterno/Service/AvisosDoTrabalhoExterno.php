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
use App\TrabalhoExterno\Entity\DespachoDemanda;
use App\TrabalhoExterno\Entity\MensagemDemanda;
use App\TrabalhoExterno\Entity\PreferenciaDoUsuario;
use App\TrabalhoExterno\Entity\TermoDeVoluntariado;
use App\TrabalhoExterno\Entity\Vaga;
use App\TrabalhoExterno\Enum\Perfil;
use App\TrabalhoExterno\Enum\SituacaoVaga;
use App\TrabalhoExterno\Repository\ConviteDemandaRepository;
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
 * - **Candidato**: no escritório gravado na candidatura, se ainda tem vínculo ativo lá; senão no primeiro
 *   escritório ativo dele; sem nenhum, nenhum aviso.
 * - **Alerta de vaga**: quem ligou o aviso e ainda vê a aba Vagas como externo (Solicitante ou Convidado)
 *   no escritório guardado na preferência, com área em comum com o currículo (ou sem currículo, ou vaga
 *   sem área), só com o `sino` ligado pelo Master.
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
        private readonly PoliticaDaVaga $politicaDaVaga,
        private readonly ConviteDemandaRepository $convites,
        private readonly PoliticaDaDemanda $politicaDaDemanda,
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

    /**
     * "Enviar N ao escritório" (l.981): o dono e cada candidato enviado (o andamento dele virou "Em análise").
     *
     * @param list<Candidatura> $enviadas
     */
    public function candidatosEnviados(Contexto $ctx, Vaga $vaga, array $enviadas = []): void
    {
        $this->paraODono($ctx, $vaga, Notificacao::TIPO_TRABALHO_EXTERNO_CANDIDATOS_ENVIADOS, 'Candidatos indicados pelo BlueJus na vaga ' . $vaga->getTitulo());
        foreach ($enviadas as $candidatura) {
            $this->andamentoDaCandidatura($ctx, $candidatura);
        }
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

    /**
     * "Recusar" a oferta (D11b, l.1169; P7): o dono da vaga (os solicitantes do escritório que pediu, ou quem
     * divulgou sem escritório) e os Master da Central, no escritório da Central. Quem recusou fica de fora; o dono não vê o nome dela, os Master veem.
     */
    public function ofertaRecusada(Contexto $ctx, Vaga $vaga, string $quem): void
    {
        $titulo = 'Oferta recusada na vaga ' . $vaga->getTitulo();
        $mensagem = 'Oferta recusada por ' . $quem;
        // O escritório dono não sabe quem recusou (como na "Oferta aceita"); o nome fica no histórico e no aviso aos Master.
        $this->paraODono($ctx, $vaga, Notificacao::TIPO_TRABALHO_EXTERNO_OFERTA_RECUSADA, $titulo, 'Uma oferta sua foi recusada.');
        $sede = $ctx->configuracao->getSede();
        if ($sede !== null) {
            $this->avisar($ctx, $this->destinatarios->masters(), $sede, Notificacao::TIPO_TRABALHO_EXTERNO_OFERTA_RECUSADA, $titulo, self::urlDaVaga($vaga), $mensagem);
        }
    }

    /** Mudança de status do candidato (l.985, 1040), no escritório dele ({@see escritorioDoCandidato()}). */
    public function andamentoDaCandidatura(Contexto $ctx, Candidatura $candidatura): void
    {
        $candidato = $candidatura->getCurriculo()->getUsuario();
        $escritorio = $this->escritorioDoCandidato($candidatura, $candidato);
        if ($escritorio === null) {
            return;
        }
        $vaga = $candidatura->getVaga();
        $this->avisar($ctx, [$candidato], $escritorio, Notificacao::TIPO_TRABALHO_EXTERNO_CANDIDATURA_STATUS, 'Andamento atualizado na sua candidatura à vaga ' . $vaga->getTitulo(), self::urlDaVaga($vaga));
    }

    /** Vaga nova ou reaberta na área (l.1012, 1107, 1160): aprovada, publicada pela Central, reaberta ou ofertada de novo. */
    public function vagaNaArea(Contexto $ctx, Vaga $vaga, \DateTimeImmutable $agora): void
    {
        $cfg = $ctx->configuracao;
        if (!$cfg->getVagas()['sino'] || $vaga->situacaoEm($agora) !== SituacaoVaga::Aberta) {
            return;
        }
        foreach ($this->preferencias->alertasDeVagasLigados() as $preferencia) {
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
        $this->avisar($ctx, $this->solicitantesDaDemanda($demanda, $ctx->configuracao), $escritorio, Notificacao::TIPO_TRABALHO_EXTERNO_DEMANDA_INFORMACOES, 'Pedido de informações na demanda ' . $demanda->getCodigo(), self::TELA . '?te=' . $demanda->getCodigo());
    }

    /**
     * Andamento da demanda às partes (desenho 06 de 24/10, `avisarPartes`, l.806): cada mudança de situação e
     * cada mensagem de sistema, arquivo ou andamento que a demanda juntou nesta requisição vira UM aviso, com o
     * título "{ID} · {serviço}" e o link `?te=`. Recebem o solicitante (quem pediu, se ainda está no escritório
     * da demanda; senão os Solicitantes dele) e o convidado de cada convite aceito e vigente, no primeiro
     * escritório dele (sem escritório, no da demanda: o aviso é só dele). Quem agiu e Master/Equipe ficam de
     * fora. Um andamento novo não é "o mesmo aviso" do anterior: aqui o pendente igual não segura o próximo.
     * Cada pessoa recebe UM aviso por andamento (quem pediu e também é o convidado, ou dois convites dela), e o
     * convidado só recebe o que leria no chat ({@see PoliticaDaDemanda::mensagemVisivel()}: sem a permissão
     * "ver", nem o nome dos documentos).
     */
    public function andamentoDaDemanda(Contexto $ctx, DemandaExterna $demanda): void
    {
        $andamentos = $demanda->retirarAndamentosParaAvisar();
        if ($andamentos === []) {
            return;
        }
        $servico = trim($demanda->getTipoServico());
        $titulo = mb_substr($demanda->getCodigo() . ' · ' . ($servico !== '' ? $servico : 'Trabalho externo'), 0, 255);
        $url = self::TELA . '?te=' . $demanda->getCodigo();
        $partes = $this->partesDaDemanda($ctx, $demanda);
        foreach ($andamentos as $andamento) {
            foreach ($partes as [$pessoa, $escritorio, $comoConvidado]) {
                if ($comoConvidado !== null && !$this->convidadoLeria($comoConvidado, $demanda, $andamento['mensagem'])) {
                    continue;
                }
                $this->notificacoes->criar($pessoa, $escritorio, Notificacao::TIPO_TRABALHO_EXTERNO_DEMANDA_ANDAMENTO, $titulo, $andamento['texto'])->setUrl($url);
            }
        }
    }

    /**
     * Resposta ao despacho (D8, decisão do dono de 24/10): cada Master da Central recebe um aviso no sino, menos
     * quem respondeu (o Master que age também como solicitante do próprio escritório), no escritório da Central.
     * Sem sede definida não há onde avisar. Cada resposta é um aviso (não segura no pendente igual).
     */
    public function respostaAoDespacho(Contexto $ctx, DemandaExterna $demanda, DespachoDemanda $resposta): void
    {
        $sede = $ctx->configuracao->getSede();
        if ($sede === null) {
            return;
        }
        $servico = trim($demanda->getTipoServico());
        $titulo = mb_substr($demanda->getCodigo() . ' · ' . ($servico !== '' ? $servico : 'Trabalho externo'), 0, 255);
        $mensagem = 'Resposta ao despacho de ' . $resposta->getNomeAutor() . '.';
        $url = self::TELA . '?te=' . $demanda->getCodigo();
        $vistos = [(int) $ctx->usuario->getId() => true];
        foreach ($this->destinatarios->masters() as $master) {
            if (isset($vistos[(int) $master->getId()])) {
                continue;
            }
            $vistos[(int) $master->getId()] = true;
            $this->notificacoes->criar($master, $sede, Notificacao::TIPO_TRABALHO_EXTERNO_DESPACHO_RESPOSTA, $titulo, $mensagem)->setUrl($url);
        }
    }

    /**
     * Termo de voluntariado enviado para aceite (desenho 06 de 25/10, l.1123): o voluntário recebe "Você tem um termo
     * de voluntariado para aceitar", no primeiro escritório ativo dele; sem escritório, nenhum aviso. Sem CPF, RG nem
     * nome no texto: o título é fixo e o link leva a "Meu currículo".
     */
    public function termoEnviado(Contexto $ctx, TermoDeVoluntariado $termo): void
    {
        $voluntario = $termo->getCurriculo()->getUsuario();
        $escritorio = $this->destinatarios->primeiroEscritorio($voluntario);
        if ($escritorio !== null) {
            $this->avisar($ctx, [$voluntario], $escritorio, Notificacao::TIPO_TRABALHO_EXTERNO_TERMO_ENVIADO, 'Você tem um termo de voluntariado para aceitar', self::urlDoCurriculo($termo));
        }
    }

    /**
     * Termo aceito (`aceitarTermo`, l.1135: "O escritório foi avisado"): a Central (Master e Equipe com `talentos`),
     * no escritório-sede. O texto leva só os códigos do termo e do currículo, nunca o nome nem os documentos.
     */
    public function termoAceito(Contexto $ctx, TermoDeVoluntariado $termo): void
    {
        $this->paraACentral($ctx, Notificacao::TIPO_TRABALHO_EXTERNO_TERMO_ACEITO, 'Termo de voluntariado aceito: ' . $termo->getCodigo() . ' (currículo ' . $termo->getCurriculo()->getCodigo() . ')', self::urlDoCurriculo($termo));
    }

    private static function urlDoCurriculo(TermoDeVoluntariado $termo): string
    {
        return self::TELA . '#cv=' . $termo->getCurriculo()->getCodigo();
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

    /**
     * Quem recebe o andamento, uma vez cada: os solicitantes no escritório da demanda e o convidado de cada
     * convite aceito e vigente no primeiro escritório dele (ou no da demanda), sem quem agiu nem Master/Equipe.
     * O terceiro item é o contexto de Convidado (null para quem pediu, que lê o chat inteiro).
     *
     * @return list<array{0: User, 1: Tenant, 2: ?Contexto}>
     */
    private function partesDaDemanda(Contexto $ctx, DemandaExterna $demanda): array
    {
        $cfg = $ctx->configuracao;
        $escritorio = $demanda->getTenant();
        $vistos = [(int) $ctx->usuario->getId() => true];
        $partes = [];
        foreach ($this->semAInterna($this->solicitantesDaDemanda($demanda, $cfg), $escritorio, $cfg) as $pessoa) {
            if (!isset($vistos[(int) $pessoa->getId()])) {
                $vistos[(int) $pessoa->getId()] = true;
                $partes[] = [$pessoa, $escritorio, null];
            }
        }
        $hoje = new \DateTimeImmutable('today');
        foreach ($demanda->getConvites() as $convite) {
            $convidado = $convite->getConvidado();
            if ($convidado === null || isset($vistos[(int) $convidado->getId()]) || !$convite->vigente($hoje)) {
                continue;
            }
            $seu = $this->destinatarios->primeiroEscritorio($convidado) ?? $escritorio;
            if ($this->semAInterna([$convidado], $seu, $cfg) === []) {
                continue;
            }
            $vistos[(int) $convidado->getId()] = true;
            $partes[] = [$convidado, $seu, new Contexto($convidado, $seu, Perfil::Convidado, $cfg, $convite)];
        }

        return $partes;
    }

    /** O convidado leria este andamento no chat? A mudança de situação, se vê a demanda; a mensagem, pela regra do chat. */
    private function convidadoLeria(Contexto $comoConvidado, DemandaExterna $demanda, ?MensagemDemanda $mensagem): bool
    {
        return $this->politicaDaDemanda->podeVer($comoConvidado, $demanda)
            && ($mensagem === null || $this->politicaDaDemanda->mensagemVisivel($comoConvidado, $demanda, $mensagem));
    }

    /** Quem pediu, se ainda tem vínculo ativo no escritório da demanda; senão os Solicitantes dele (como em {@see pedidoDeInformacoes()}). @return list<User> */
    private function solicitantesDaDemanda(DemandaExterna $demanda, ConfiguracaoCentral $cfg): array
    {
        $escritorio = $demanda->getTenant();
        $autor = $demanda->getCriadoPor();

        return $autor !== null && $this->destinatarios->escritorioDaPessoa($autor, (int) $escritorio->getId()) !== null
            ? [$autor]
            : $this->solicitantes($escritorio, $cfg);
    }

    /**
     * Sem Master nem Equipe (o andamento é para as partes de fora da Central).
     *
     * @param list<User> $pessoas
     *
     * @return list<User>
     */
    private function semAInterna(array $pessoas, Tenant $escritorio, ConfiguracaoCentral $cfg): array
    {
        return array_values(array_filter(
            $pessoas,
            fn (User $u): bool => !in_array($this->perfis->resolver($u, $escritorio, $cfg), [Perfil::Master, Perfil::Equipe], true),
        ));
    }

    /** @return list<User> quem tem o perfil Solicitante no escritório (permissão do módulo, sem ser Master) */
    private function solicitantes(Tenant $escritorio, ConfiguracaoCentral $cfg): array
    {
        return array_values(array_filter(
            $this->destinatarios->ativosDoEscritorio($escritorio),
            fn (User $u): bool => $this->perfis->resolver($u, $escritorio, $cfg) === Perfil::Solicitante,
        ));
    }

    /** O gravado na candidatura, se ele ainda tem vínculo ativo lá; senão o primeiro escritório ativo dele. */
    private function escritorioDoCandidato(Candidatura $candidatura, User $candidato): ?Tenant
    {
        $gravado = $candidatura->getEscritorioDoCandidato();
        $ainda = $gravado !== null ? $this->destinatarios->escritorioDaPessoa($candidato, (int) $gravado->getId()) : null;

        return $ainda ?? $this->destinatarios->primeiroEscritorio($candidato);
    }

    /**
     * O escritório em que o interessado recebe o alerta desta vaga, ou null se não recebe: aviso ligado,
     * vínculo ativo no escritório guardado, externo nele que vê a aba Vagas ({@see veAVagaComoExterno()}),
     * não é o dono da vaga e tem área em comum com ela (ou não tem currículo, ou a vaga não tem área),
     * como o desenho (l.1012).
     */
    private function escritorioDoInteressado(PreferenciaDoUsuario $preferencia, Vaga $vaga, ConfiguracaoCentral $cfg): ?Tenant
    {
        $valor = $preferencia->getValor();
        $pessoa = $preferencia->getUsuario();
        if (($valor['ligado'] ?? false) !== true || !is_int($valor['escritorioId'] ?? null) || $vaga->getCriadaPor()?->getId() === $pessoa->getId()) {
            return null;
        }
        $escritorio = $this->destinatarios->escritorioDaPessoa($pessoa, $valor['escritorioId']);
        if ($escritorio === null || $escritorio->getId() === $vaga->getEscritorio()?->getId() || !$this->veAVagaComoExterno($pessoa, $escritorio, $cfg)) {
            return null;
        }
        $cv = $this->curriculos->doUsuario($pessoa);

        return $cv === null || $vaga->getAreas() === [] || array_intersect($vaga->getAreas(), $cv->getAreas()) !== [] ? $escritorio : null;
    }

    /**
     * O perfil que a pessoa teria entrando por este escritório é externo (Solicitante, ou Convidado com
     * convite vigente, como a `FabricaDeContexto`) e esse perfil vê a aba Vagas.
     */
    private function veAVagaComoExterno(User $pessoa, Tenant $escritorio, ConfiguracaoCentral $cfg): bool
    {
        $perfil = $this->perfis->resolver($pessoa, $escritorio, $cfg)
            ?? ($this->convites->vigentesDo($pessoa, new \DateTimeImmutable('today')) !== [] ? Perfil::Convidado : null);

        return in_array($perfil, [Perfil::Solicitante, Perfil::Convidado], true)
            && $this->politicaDaVaga->veAba(new Contexto($pessoa, $escritorio, $perfil, $cfg));
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
