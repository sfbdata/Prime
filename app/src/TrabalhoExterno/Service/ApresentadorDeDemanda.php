<?php

declare(strict_types=1);

namespace App\TrabalhoExterno\Service;

use App\TrabalhoExterno\Entity\AvisoWhatsapp;
use App\TrabalhoExterno\Entity\ContatoWhatsapp;
use App\TrabalhoExterno\Entity\ConviteDemanda;
use App\TrabalhoExterno\Entity\DemandaExterna;
use App\TrabalhoExterno\Entity\DocumentoDemanda;
use App\TrabalhoExterno\Entity\HistoricoDemanda;
use App\TrabalhoExterno\Entity\MensagemDemanda;
use App\TrabalhoExterno\Entity\PropostaDemanda;
use App\TrabalhoExterno\Enum\FaseWhatsapp;
use App\TrabalhoExterno\Enum\Perfil;
use App\TrabalhoExterno\Enum\Situacao;
use App\TrabalhoExterno\Enum\SituacaoConvite;
use App\TrabalhoExterno\Enum\SituacaoProposta;
use App\TrabalhoExterno\Enum\TipoMensagem;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Converte a demanda no que a tela desenha, já com o recorte do perfil: notas internas fora para
 * externos, "Central BlueJus" no lugar de quem atende, contato escondido do Convidado. Tudo que é
 * dado sensível é decidido AQUI, no servidor; o JavaScript só pinta.
 *
 * Os rótulos, a ordem dos campos e as cores são os do `renderVals()` do desenho.
 */
final class ApresentadorDeDemanda
{
    /** Os oito marcos de "ANDAMENTO DA DEMANDA". */
    private const ETAPAS = [
        ['nova', 'Solicitada', 'bi-inbox'],
        ['analise', 'Em análise', 'bi-search'],
        ['proposta', 'Proposta', 'bi-file-earmark-text'],
        ['aceita', 'Aceite', 'bi-check2-circle'],
        [Situacao::MARCO_PAGO, 'Pago', 'bi-cash-coin'],
        ['execucao', 'Em execução', 'bi-briefcase'],
        ['validacao', 'Validação', 'bi-hourglass-split'],
        ['concluida', 'Finalizada', 'bi-flag'],
    ];

    public function __construct(
        private readonly PoliticaDaDemanda $politica,
        private readonly UrlGeneratorInterface $urls,
    ) {
    }

    /**
     * A linha da lista. O cliente calcula prazo, KPIs, chips e busca a partir daqui, como o desenho.
     *
     * @return array<string, mixed>
     */
    public function linha(Contexto $ctx, DemandaExterna $d, int $naoLidas): array
    {
        $s = $d->getSituacao();

        return [
            'codigo' => $d->getCodigo(),
            'situacao' => $s->value,
            'situacaoRotulo' => $s->rotulo(),
            'situacaoCor' => $s->cor(),
            'situacaoIcone' => $s->icone(),
            'fechada' => $s->ehFechada(),
            'tipo' => $d->getTipoServico(),
            // Como no painel lateral: sem "Ver instruções", o Convidado não recebe a descrição.
            'descricao' => $this->politica->podeVerDocumentos($ctx, $d) ? $d->getDescricao() : '',
            'escritorio' => $d->getEscritorio(),
            'cidadeOrigem' => (string) $d->getCidadeOrigem(),
            'ufOrigem' => $d->getUfOrigem(),
            'cidadeExecucao' => $d->getCidadeExecucao(),
            'ufExecucao' => $d->getUfExecucao(),
            'processo' => (string) $d->getProcesso(),
            'prazo' => $d->getPrazoFinal()?->format('Y-m-d'),
            'criada' => ($d->getEnviadaEm() ?? $d->getCriadoEm())->format('Y-m-d'),
            'responsavel' => $this->responsavelVisivel($ctx, $d),
            'urgente' => $d->isUrgente(),
            'naoLidas' => $naoLidas,
            'botoes' => $this->politica->botoes($ctx, $d),
        ];
    }

    /**
     * O painel lateral inteiro de uma demanda.
     *
     * @return array<string, mixed>
     */
    public function detalhe(Contexto $ctx, DemandaExterna $d, int $naoLidas): array
    {
        $s = $d->getSituacao();
        $fim = in_array($s, [Situacao::Recusada, Situacao::Cancelada], true);
        $ultimoDaSituacao = null;
        foreach ($d->getHistorico() as $h) {
            if ($h->getPara() === $s->value) {
                $ultimoDaSituacao = $h->getEm();
            }
        }

        return [
            'codigo' => $d->getCodigo(),
            'situacao' => $s->value,
            'situacaoRotulo' => $s->rotulo(),
            'tipo' => $d->getTipoServico(),
            'destino' => $this->local($d->getCidadeExecucao(), $d->getUfExecucao()) . ($d->getOrgao() !== null ? ' · ' . $d->getOrgao() : ''),
            'botoes' => $this->politica->botoes($ctx, $d),
            'abas' => $this->abas($ctx),
            'campos' => $this->campos($ctx, $d),
            // "Ver instruções" do convite: sem ele, o Convidado não lê descrição, objetivo nem instruções.
            'textos' => !$this->politica->podeVerDocumentos($ctx, $d) ? [] : $this->filtrar([
                ['DESCRIÇÃO DA ATIVIDADE', $d->getDescricao()],
                ['OBJETIVO DA DILIGÊNCIA', (string) $d->getObjetivo()],
                ['INSTRUÇÕES ESPECÍFICAS', (string) $d->getInstrucoes()],
            ]),
            'documentos' => $this->politica->podeVerDocumentos($ctx, $d)
                ? array_map(fn (DocumentoDemanda $doc): array => $this->documento($ctx, $doc), $d->getDocumentos())
                : [],
            'semDocumentos' => !$this->politica->podeVerDocumentos($ctx, $d) || $d->getDocumentos() === [],
            'podeAnexar' => $this->politica->podeAnexar($ctx, $d),
            // O Convidado não tem a aba Proposta: valores e condições NÃO saem para ele, nem no JSON.
            'propostas' => $ctx->perfil === Perfil::Convidado ? [] : $this->propostas($ctx, $d),
            'podePropor' => $this->politica->podePropor($ctx, $d),
            // A versão denuncia quantas propostas houve: nem isso sai para o Convidado.
            'propostaTitulo' => $ctx->perfil === Perfil::Convidado ? '' : ($d->getPropostas() !== [] ? sprintf('Nova versão da proposta (v%d)', $d->proximaVersaoDeProposta()) : 'Proposta comercial'),
            'checklist' => array_map(static fn (array $i): array => ['t' => $i['t'], 'ok' => $i['ok']], $d->getChecklist()),
            'podeAndamento' => $this->politica->podeRegistrarAndamento($ctx, $d),
            'mostraConvites' => $ctx->ehInterno(),
            'convites' => $ctx->ehInterno() ? $this->convites($ctx, $d) : [],
            'podeConvidar' => $this->politica->podeConvidar($ctx, $d),
            'permissoesDeConvite' => array_map(static fn (string $k, string $rot): array => ['k' => $k, 'rot' => $rot], array_keys(ConviteDemanda::PERMISSOES), ConviteDemanda::PERMISSOES),
            'validadePadraoConvite' => (new \DateTimeImmutable('today +15 days'))->format('Y-m-d'),
            'mensagens' => $this->mensagens($ctx, $d),
            'naoLidas' => $naoLidas,
            'podeConversar' => $this->politica->podeConversar($ctx, $d),
            'podeNotaInterna' => $this->politica->podeEscreverNotaInterna($ctx, $d),
            'historico' => $this->historico($ctx, $d),
            'etapas' => $this->etapas($ctx, $d, $fim),
            'progresso' => $this->progresso($d),
            'encerrada' => $fim,
            'encerradaTexto' => $fim ? $s->rotulo() . ' · ' . $this->dataHora($ultimoDaSituacao) : '',
            'whatsapp' => $this->politica->podeUsarWhatsapp($ctx, $d) ? $this->whatsapp($d) : null,
            'rascunho' => $ctx->perfil === Perfil::Solicitante && $s === Situacao::Rascunho ? $this->formulario($d) : null,
        ];
    }

    /** @return list<string> */
    private function abas(Contexto $ctx): array
    {
        $abas = ['resumo', 'proposta', 'exec', 'chat', 'hist', 'whats'];

        return $ctx->perfil === Perfil::Convidado ? array_values(array_diff($abas, ['proposta', 'whats'])) : $abas;
    }

    /** @return list<array{r: string, v: string}> */
    private function campos(Contexto $ctx, DemandaExterna $d): array
    {
        $oab = $d->getOab() !== '' ? ' · OAB ' . $d->getOab() . '/' . $d->getOabUf() : '';
        $contato = $this->politica->veContato($ctx) ? implode(' · ', array_filter([$d->getEmail(), (string) $d->getTelefone()])) : '';
        $prioridade = $d->getPrioridade() . ($d->isUrgente() ? ' · risco de perda de prazo' : '');

        return $this->filtrar([
            ['ESCRITÓRIO SOLICITANTE', $d->getEscritorio()],
            ['ADVOGADO RESPONSÁVEL', $d->getAdvogado() . $oab],
            ['CONTATO', $contato],
            ['ORIGEM', $this->local((string) $d->getCidadeOrigem(), $d->getUfOrigem())],
            ['LOCAL DE EXECUÇÃO', $this->local($d->getCidadeExecucao(), $d->getUfExecucao())],
            ['ÓRGÃO', (string) $d->getOrgao()],
            ['PROCESSO', (string) $d->getProcesso()],
            ['PARTES', (string) $d->getPartes()],
            ['PRAZO FINAL', $d->getPrazoFinal()?->format('d/m/Y') ?? ''],
            ['DATA E HORÁRIO PRETENDIDOS', $d->getDataPretendida()?->format('d/m/Y H:i') ?? ''],
            ['PRIORIDADE', $prioridade],
            ['RESPONSÁVEL PELA EXECUÇÃO', $this->responsavelNoResumo($ctx, $d)],
        ]);
    }

    /** @return array<string, mixed> */
    private function documento(Contexto $ctx, DocumentoDemanda $doc): array
    {
        $kb = max(1, (int) round($doc->getTamanho() / 1024));

        return [
            'id' => $doc->getId(),
            'nome' => $doc->getNomeOriginal(),
            'info' => sprintf('%d KB · %s · %s', $kb, $this->politica->nomeVisivel($ctx, $doc->getNomeAutor(), $doc->getPerfilAutor()), $this->dataHora($doc->getEm())),
        ];
    }

    /** "PROFISSIONAIS CONVIDADOS" (só a Central vê). @return list<array<string, mixed>> */
    private function convites(Contexto $ctx, DemandaExterna $d): array
    {
        $gerir = $this->politica->podeGerirConvites($ctx, $d);

        return array_map(static function (ConviteDemanda $c) use ($gerir): array {
            $iniciais = implode('', array_map(static fn (string $p): string => mb_strtoupper(mb_substr($p, 0, 1)), array_slice(array_values(array_filter(explode(' ', $c->getNome()))), 0, 2)));

            return [
                'id' => $c->getId(),
                'nome' => $c->getNome(),
                'email' => $c->getEmail(),
                'funcao' => $c->getFuncao() ?? 'Profissional',
                'validade' => $c->getValidade()->format('d/m/Y'),
                'ini' => $iniciais,
                'st' => $c->getSituacao()->rotulo(),
                'stCor' => $c->getSituacao()->cor(),
                'gerir' => $gerir && $c->getSituacao() !== SituacaoConvite::Revogado,
            ];
        }, $d->getConvites());
    }

    /** @return list<array<string, mixed>> */
    private function propostas(Contexto $ctx, DemandaExterna $d): array
    {
        $lista = array_reverse($d->getPropostas());

        return array_map(function (PropostaDemanda $p, int $i) use ($ctx): array {
            $situacao = $p->getSituacao();
            $texto = match ($situacao) {
                SituacaoProposta::Enviada => 'Aguardando aceite',
                SituacaoProposta::Aceita => 'Aceita em ' . $this->dataHora($p->getAceitaEm())
                    . ($p->getNomeAceitaPor() !== null && $p->getPerfilAceitaPor() !== null ? ' por ' . $this->politica->nomeVisivel($ctx, $p->getNomeAceitaPor(), $p->getPerfilAceitaPor()) : ''),
                SituacaoProposta::Ajustes => 'Ajustes solicitados',
                SituacaoProposta::Recusada => 'Recusada',
                SituacaoProposta::Substituida => 'Substituída',
            };

            return [
                'v' => $p->getVersao(),
                'em' => $this->dataHora($p->getEnviadaEm()),
                'st' => $texto,
                'stCor' => match ($situacao) {
                    SituacaoProposta::Aceita => '#1e7b45',
                    SituacaoProposta::Recusada => '#a3232b',
                    default => '#5b3f9e',
                },
                'destaque' => $i === 0,
                'campos' => [
                    ['r' => 'Valor', 'v' => $p->valorFormatado()],
                    ['r' => 'Despesas previstas', 'v' => PropostaDemanda::brl($p->getDespesas())],
                    ['r' => 'Prazo estimado', 'v' => $p->getPrazoEstimado() ?? 'Não informado'],
                    ['r' => 'Validade', 'v' => $p->getValidade()->format('d/m/Y')],
                    ['r' => 'Pagamento', 'v' => $p->getPagamento() ?? 'Não informado'],
                    ['r' => 'Escopo', 'v' => $p->getEscopo() ?? 'Não informado'],
                    ['r' => 'Não incluído', 'v' => $p->getNaoInclui() ?? 'Não informado'],
                ],
            ];
        }, $lista, array_keys($lista));
    }

    /** @return list<array<string, mixed>> */
    private function mensagens(Contexto $ctx, DemandaExterna $d): array
    {
        $visiveis = array_filter(
            $d->getMensagens(),
            fn (MensagemDemanda $m): bool => $this->politica->mensagemVisivel($ctx, $d, $m),
        );

        return array_values(array_map(function (MensagemDemanda $m) use ($ctx): array {
            $tipo = $m->getTipo();
            $estilo = match (true) {
                $tipo === TipoMensagem::Interna => 'interna',
                $tipo === TipoMensagem::Sistema => 'sistema',
                $m->getAutor() !== null && $m->getAutor()->getId() === $ctx->usuario->getId() => 'meu',
                default => 'outro',
            };

            return [
                'id' => $m->getId(),
                'de' => $this->politica->nomeVisivel($ctx, $m->getNomeAutor(), $m->getPerfilAutor()),
                'em' => $this->dataHora($m->getEm()),
                'txt' => $m->getTexto(),
                'tipo' => $tipo->sufixo(),
                'estilo' => $estilo,
                'meu' => $m->getAutor() !== null && $m->getAutor()->getId() === $ctx->usuario->getId(),
                'nomeCor' => match ($m->getPerfilAutor()) {
                    Perfil::Master => '#0a6fa0',
                    Perfil::Convidado => '#1e7b45',
                    default => '#5b3f9e',
                },
            ];
        }, $visiveis));
    }

    /**
     * De antes do aceite, o Convidado recebe só os marcos administrativos, sem origem, autor nem
     * motivo ({@see PoliticaDaDemanda::marcoReservado}); o resto nem entra no JSON.
     *
     * @return list<array<string, mixed>>
     */
    private function historico(Contexto $ctx, DemandaExterna $d): array
    {
        $visiveis = array_filter(
            array_reverse($d->getHistorico()),
            fn (HistoricoDemanda $h): bool => !$this->politica->marcoReservado($ctx, $h->getPara(), $h->getEm()),
        );

        return array_values(array_map(function (HistoricoDemanda $h) use ($ctx): array {
            [$rotulo, $cor, $icone] = $this->marco($h->getPara());
            if ($this->politica->anteriorAoAceite($ctx, $h->getEm())) {
                return ['para' => $rotulo, 'de' => '', 'quem' => '', 'em' => $this->dataHora($h->getEm()), 'motivo' => '', 'cor' => $cor, 'ic' => $icone];
            }

            return [
                'para' => $rotulo,
                'de' => $h->getDe() !== null ? 'de ' . $this->marco($h->getDe())[0] : '',
                'quem' => $this->politica->nomeVisivel($ctx, $h->getNomeAutor(), $h->getPerfilAutor()),
                'em' => $this->dataHora($h->getEm()),
                'motivo' => (string) $h->getMotivo(),
                'cor' => $cor,
                'ic' => $icone,
            ];
        }, $visiveis));
    }

    /**
     * A aba WhatsApp (`dz.wa` do desenho). O link da demanda é absoluto e exige login (D4).
     *
     * @return array<string, mixed>
     */
    private function whatsapp(DemandaExterna $d): array
    {
        $link = $this->urls->generate('trabalho_externo_index', ['te' => $d->getCodigo()], UrlGeneratorInterface::ABSOLUTE_URL);
        $comLink = static fn (string $texto): string => $texto . "\n\nAcompanhe pelo BlueJus: " . $link;
        $avisos = $d->getAvisosWhatsapp();
        $fila = array_values(array_filter($avisos, static fn (AvisoWhatsapp $a): bool => $a->getEnviadoEm() === null));
        $enviadas = array_reverse(array_values(array_filter($avisos, static fn (AvisoWhatsapp $a): bool => $a->getEnviadoEm() !== null)));
        $contatos = $d->getContatosWhatsapp();
        $fase = $d->faseDeWhatsapp();

        return [
            'contatos' => array_map(fn (ContatoWhatsapp $c): array => [
                'id' => $c->getId(),
                'nome' => $c->getNome(),
                'tel' => self::telefone($c->getTelefone()),
                'ativo' => $c->isAtivo(),
                'fases' => array_map(static fn (FaseWhatsapp $f): array => ['k' => $f->value, 'rot' => $f->rotulo(), 'ok' => (bool) ($c->getFases()[$f->value] ?? false)], FaseWhatsapp::cases()),
            ], $contatos),
            'fila' => array_map(fn (AvisoWhatsapp $a): array => [
                'id' => $a->getId(),
                'fase' => $a->getFase()?->rotulo() ?? '',
                'para' => $a->getNomeContato() . ' · ' . self::telefone($a->getTelefone()),
                'txt' => $comLink($a->getTexto()),
                'em' => $this->dataHora($a->getCriadoEm()),
                'href' => 'https://wa.me/55' . $a->getTelefone() . '?text=' . rawurlencode($comLink($a->getTexto())),
            ], $fila),
            'enviadas' => array_map(fn (AvisoWhatsapp $a): array => [
                'fase' => $a->getFase()?->rotulo() ?? 'Conexão',
                'para' => $a->getNomeContato(),
                'em' => $this->dataHora($a->getEnviadoEm()),
            ], array_slice($enviadas, 0, 15)),
            'faseAtual' => $fase?->rotulo(),
            'temAtivos' => $fase !== null && array_filter($contatos, static fn (ContatoWhatsapp $c): bool => $c->isAtivo()) !== [],
            'link' => $link,
        ];
    }

    /** "61999990000" → "(61) 99999-0000" (`fmtTel` do desenho). */
    private static function telefone(string $digitos): string
    {
        return strlen($digitos) >= 10
            ? sprintf('(%s) %s-%s', substr($digitos, 0, 2), substr($digitos, 2, -4), substr($digitos, -4))
            : $digitos;
    }

    /** @return array{0: string, 1: string, 2: string} rótulo, cor e ícone de uma situação ou do marco "pago" */
    private function marco(string $valor): array
    {
        if ($valor === Situacao::MARCO_PAGO) {
            return ['Pagamento registrado', '#1e7b45', 'bi-cash-coin'];
        }
        $s = Situacao::tryFrom($valor);

        return $s !== null ? [$s->rotulo(), $s->cor(), $s->icone()] : [$valor, '#6b8494', 'bi-dot'];
    }

    /** @return list<array<string, mixed>> */
    private function etapas(Contexto $ctx, DemandaExterna $d, bool $fim): array
    {
        $alcancadoEm = [];
        foreach ($d->getHistorico() as $h) {
            $alcancadoEm[$h->getPara()] ??= $h->getEm();
        }
        if ($d->getPagoEm() !== null) {
            $alcancadoEm[Situacao::MARCO_PAGO] ??= $d->getPagoEm();
        }

        $ultimo = -1;
        foreach (self::ETAPAS as $i => [$chave]) {
            if (isset($alcancadoEm[$chave])) {
                $ultimo = $i;
            }
        }
        $encerrouBem = in_array($d->getSituacao(), [Situacao::Concluida, Situacao::Arquivada], true);

        $etapas = [];
        foreach (self::ETAPAS as $i => [$chave, $rotulo, $icone]) {
            $feito = isset($alcancadoEm[$chave]);
            // Marco da negociação alcançado antes do aceite: o Convidado vê que passou, não quando.
            $reservado = $feito && $this->politica->marcoReservado($ctx, $chave, $alcancadoEm[$chave]);
            $etapas[] = [
                'rot' => $rotulo,
                'ic' => $icone,
                'dt' => match (true) {
                    $reservado => '',
                    $feito => $this->dataHora($alcancadoEm[$chave]),
                    default => 'pendente',
                },
                'feito' => $feito,
                'atual' => $feito && $i === $ultimo && !$fim && !$encerrouBem,
            ];
        }

        return $etapas;
    }

    /** Fração da barra de progresso (0 a 1) até o último marco alcançado. */
    private function progresso(DemandaExterna $d): float
    {
        $alcancados = array_map(static fn (HistoricoDemanda $h): string => $h->getPara(), $d->getHistorico());
        $ultimo = 0;
        foreach (self::ETAPAS as $i => [$chave]) {
            if (in_array($chave, $alcancados, true) || ($chave === Situacao::MARCO_PAGO && $d->getPagoEm() !== null)) {
                $ultimo = $i;
            }
        }

        return round($ultimo / (count(self::ETAPAS) - 1), 3);
    }

    /** O formulário do rascunho, para o "Continuar rascunho" reabrir o modal preenchido. @return array<string, mixed> */
    private function formulario(DemandaExterna $d): array
    {
        return [
            'escritorio' => $d->getEscritorio(), 'email' => $d->getEmail(), 'telefone' => (string) $d->getTelefone(),
            'ufOrigem' => $d->getUfOrigem(), 'cidadeOrigem' => (string) $d->getCidadeOrigem(),
            'emNomeDeOutro' => $d->isEmNomeDeOutro(), 'advogado' => $d->getAdvogado(), 'oab' => $d->getOab(), 'oabUf' => $d->getOabUf(),
            'ufExecucao' => $d->getUfExecucao(), 'cidadeExecucao' => $d->getCidadeExecucao(), 'orgao' => (string) $d->getOrgao(),
            'processo' => (string) $d->getProcesso(), 'partes' => (string) $d->getPartes(), 'tipoServico' => $d->getTipoServico(),
            'prioridade' => $d->getPrioridade(), 'prazoFinal' => $d->getPrazoFinal()?->format('Y-m-d') ?? '',
            'dataPretendida' => $d->getDataPretendida()?->format('Y-m-d\TH:i') ?? '', 'descricao' => $d->getDescricao(),
            'objetivo' => (string) $d->getObjetivo(), 'instrucoes' => (string) $d->getInstrucoes(), 'urgente' => $d->isUrgente(),
            'documentos' => array_map(static fn (DocumentoDemanda $doc): string => $doc->getNomeOriginal(), $d->getDocumentos()),
        ];
    }

    private function responsavelVisivel(Contexto $ctx, DemandaExterna $d): string
    {
        $resp = $d->getResponsavel();
        if ($resp === null) {
            return 'A definir';
        }

        return !$ctx->ehInterno() && !$ctx->configuracao->privacidade('nomeResp') ? 'Profissional designado pela Central' : $resp;
    }

    private function responsavelNoResumo(Contexto $ctx, DemandaExterna $d): string
    {
        return $d->getResponsavel() === null ? '' : $this->responsavelVisivel($ctx, $d);
    }

    private function local(string $cidade, string $uf): string
    {
        return $cidade !== '' ? $cidade . '/' . $uf : $uf;
    }

    private function dataHora(?\DateTimeImmutable $em): string
    {
        return $em?->format('d/m/Y H:i') ?? '';
    }

    /**
     * @param list<array{0: string, 1: string}> $pares
     *
     * @return list<array{r: string, v: string}>
     */
    private function filtrar(array $pares): array
    {
        $saida = [];
        foreach ($pares as [$rotulo, $valor]) {
            if (trim($valor) !== '') {
                $saida[] = ['r' => $rotulo, 'v' => $valor];
            }
        }

        return $saida;
    }
}
