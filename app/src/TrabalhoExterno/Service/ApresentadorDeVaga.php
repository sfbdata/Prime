<?php

declare(strict_types=1);

namespace App\TrabalhoExterno\Service;

use App\TrabalhoExterno\Entity\Candidatura;
use App\TrabalhoExterno\Entity\HistoricoVaga;
use App\TrabalhoExterno\Entity\MarcacaoDoUsuario;
use App\TrabalhoExterno\Entity\Vaga;
use App\TrabalhoExterno\Enum\SituacaoVaga;

/**
 * A vaga no recorte de quem lê, com os textos do desenho 06: cartão (l.1117) e detalhe (`tv`, l.1003).
 * - O contador da oferta é do interno (`tempo()`, l.996); "AO VIVO" é de todos.
 * - "OFERECIDO POR" mostra só o que o ofertante deixou visível, para TODOS (l.1003); o formulário de
 *   edição, só para quem edita, leva os dados inteiros.
 * - O histórico é só do interno (`tvHistOn`, l.1190).
 * - Candidatos (F2b, l.1003): o interno vê todos; o dono externo, só os enviados a ele, sem botões de
 *   status; os outros externos, só a própria candidatura e a compatibilidade com o currículo dele.
 */
final class ApresentadorDeVaga
{
    public function __construct(
        private readonly PoliticaDaVaga $politica,
        private readonly CalculadoraDeImportanciaDaVaga $importancia,
        private readonly ApresentadorDeCandidatura $candidaturas,
    ) {
    }

    /** @return array<string, mixed> */
    public function cartao(Contexto $ctx, Vaga $v, ?MarcacaoDoUsuario $marca, ?\DateTimeImmutable $ultimaVisita, \DateTimeImmutable $agora, ?CandidaturasDaVaga $cand = null): array
    {
        $s = $v->situacaoEm($agora);
        $nova = $s === SituacaoVaga::Aberta && $ultimaVisita !== null && $v->getCriadaEm() > $ultimaVisita;
        $cand ??= new CandidaturasDaVaga();

        return [
            'codigo' => $v->getCodigo(),
            'titulo' => $v->getTitulo(),
            'escritorio' => (string) ($v->getOfertante()['nome'] ?? ''),
            'situacao' => $s->value,
            'situacaoRotulo' => $s->rotulo(),
            'situacaoCor' => $s->cor(),
            'cargo' => $v->getCargo(),
            'contratacao' => $v->getContratacao() !== '' ? $v->getContratacao() : 'Contratação a combinar',
            'local' => $this->local($v),
            'remuneracao' => $v->getRemuneracao() !== '' ? $v->getRemuneracao() : 'A combinar',
            'areas' => $v->getAreas(),
            // "N de Q aceita(s)" do contador também no cartão (`tempo()`, l.996).
            'qtd' => $v->getQtd(),
            'prazo' => $v->getInscricoesAte()?->format('Y-m-d'),
            'prazoTxt' => $this->prazoDoCartao($v, $agora),
            'vivo' => $v->isOferta() && $s === SituacaoVaga::Aberta,
            'nova' => $nova,
            'daCentral' => $v->ehDaCentral(),
            'minha' => $this->politica->ehDono($ctx, $v),
            'estrela' => $marca?->temEstrela() ?? false,
            'lixeira' => $marca?->naLixeira() ?? false,
            'vista' => $marca?->isVista() ?? false,
            'importancia' => $this->importancia->calcular($v, $marca?->getImportante(), $nova, $agora, $cand->compat, $this->politica->ehInterno($ctx) ? $cand->recebidas : 0),
            'podeEditar' => $this->politica->podeEditar($ctx, $v),
            'podeExcluir' => $this->politica->podeExcluir($ctx),
            'podeCompartilhar' => $this->politica->podeCompartilhar($ctx, $v, $agora),
            'rotuloEditar' => $this->rotuloEditar($ctx, $v),
            'botoes' => $this->politica->botoes($ctx, $v, $agora),
        ] + $this->faixa($ctx, $v, $s) + $this->tempo($ctx, $v, $s, $cand) + $this->crus($v) + $this->numeros($ctx, $v, $cand);
    }

    /**
     * Compatibilidade (só o externo com currículo), "já me candidatei" e os números do cartão (l.1117):
     * o interno vê quantos se candidataram; o dono, "{n} indicado(s) pelo BlueJus".
     *
     * @return array<string, mixed>
     */
    private function numeros(Contexto $ctx, Vaga $v, CandidaturasDaVaga $cand): array
    {
        $interno = $this->politica->ehInterno($ctx);

        return [
            'compat' => $interno ? null : $cand->compat,
            'compatCor' => !$interno && $cand->compat !== null ? CompatibilidadeDaVaga::cor($cand->compat) : null,
            'jaFoi' => !$interno && $cand->minha !== null,
            'indicadosN' => $this->politica->ehDono($ctx, $v) ? $cand->enviados : null,
            'nCand' => $interno ? $cand->total : null,
        ];
    }

    /** Os dados crus, para a mensagem padrão do "Divulgar vaga(s)" (`msgPadrao`, l.1075). @return array<string, string> */
    private function crus(Vaga $v): array
    {
        return [
            'contrato' => $v->getContratacao(),
            'modalidade' => $v->getModalidade(),
            'cidade' => $v->getCidade(),
            'uf' => $v->getUf(),
        ];
    }

    /** @return array<string, mixed> */
    public function detalhe(Contexto $ctx, Vaga $v, ?MarcacaoDoUsuario $marca, ?\DateTimeImmutable $ultimaVisita, \DateTimeImmutable $agora, ?CandidaturasDaVaga $cand = null): array
    {
        $interno = $this->politica->ehInterno($ctx);
        $cand ??= new CandidaturasDaVaga();

        return $this->cartao($ctx, $v, $marca, $ultimaVisita, $agora, $cand) + $this->candidatos($ctx, $v, $cand, $agora) + [
            'requisitos' => $v->getRequisitos() !== '' ? $v->getRequisitos() : 'Sem descrição.',
            'oab' => $v->isExigeOab() ? 'Exige OAB ativa' : 'OAB não obrigatória',
            'prazoDetalhe' => $this->prazoDoDetalhe($v),
            'em' => $v->getCriadaEm()->format('d/m/Y'),
            'ofertante' => $this->ofertante($v),
            'historico' => $interno ? array_reverse(array_map(static fn (HistoricoVaga $h): array => [
                'ic' => $h->getIcone(),
                'cor' => $h->getCor(),
                'txt' => $h->getTexto(),
                'em' => $h->getEm()->format('d/m/Y H:i'),
            ], $v->getHistorico())) : [],
            'formulario' => $this->politica->podeEditar($ctx, $v) ? $this->formulario($v) : null,
        ];
    }

    /** Faixa de pendente e de não aprovada, com o texto de cada lado (l.1117). @return array<string, mixed> */
    private function faixa(Contexto $ctx, Vaga $v, SituacaoVaga $s): array
    {
        $interno = $this->politica->ehInterno($ctx);
        $quem = (string) ($v->getOfertante()['nome'] ?? '');

        return [
            'pend' => $s === SituacaoVaga::Pendente || $s === SituacaoVaga::Reprovada,
            'pendTxt' => match (true) {
                $s === SituacaoVaga::Pendente && $interno => 'Pedido de divulgação de ' . ($quem !== '' ? $quem : 'escritório') . ' · revise e publique',
                $s === SituacaoVaga::Pendente => 'Aguardando aprovação do BlueJus para ser publicada',
                default => 'Não aprovada' . ($v->getMotivo() !== null ? ': ' . $v->getMotivo() : '') . ($interno ? '' : ' · corrija e reenvie'),
            },
            'pendCor' => $s === SituacaoVaga::Pendente ? '#9a5b00' : '#a3232b',
            'pendBg' => $s === SituacaoVaga::Pendente ? '#fff8e6' : '#fdf1f2',
        ];
    }

    /**
     * O contador da oferta, só para o interno (`tempo()`, l.996): os aceites da rodada atual e, no
     * detalhe, quem aceitou ("aceita por …").
     *
     * @return array<string, mixed>
     */
    private function tempo(Contexto $ctx, Vaga $v, SituacaoVaga $s, CandidaturasDaVaga $cand): array
    {
        if (!$v->isOferta() || !$this->politica->ehInterno($ctx)) {
            return ['oferta' => false, 'expiraEm' => null, 'duracao' => null, 'aceites' => 0, 'aceitosPor' => []];
        }

        return [
            'oferta' => true,
            'expiraEm' => $s === SituacaoVaga::Aberta ? $v->getExpiraEm()?->format(\DATE_ATOM) : null,
            'duracao' => $v->getDuracaoDaOferta(),
            'aceites' => $cand->aceites,
            'aceitosPor' => $cand->aceitosPor,
        ];
    }

    /**
     * Os candidatos do detalhe (`tv.cands`, `tvCandTit`, `tvEnvTxt`, l.1003 e 1190): o interno vê todos;
     * o dono externo, só os enviados ao escritório dele; os outros, nenhum. "SUA CANDIDATURA" e o botão
     * do externo saem daqui também.
     *
     * @return array<string, mixed>
     */
    private function candidatos(Contexto $ctx, Vaga $v, CandidaturasDaVaga $cand, \DateTimeImmutable $agora): array
    {
        $interno = $this->politica->ehInterno($ctx);
        $dono = $this->politica->ehDono($ctx, $v);
        $lista = $interno ? $cand->candidatos : ($dono ? array_values(array_filter($cand->candidatos, static fn (Candidatura $c): bool => $c->isEnviadoAoEscritorio())) : []);
        $minEnvio = $ctx->configuracao->getVagas()['minEnvio'];

        return [
            'candidatos' => array_map(fn (Candidatura $c): array => $this->candidaturas->candidato($c, $agora), $lista),
            'candTitulo' => match (true) {
                $dono => sprintf('CANDIDATOS INDICADOS PELO BLUEJUS (%d) · O BLUEJUS ATUALIZA O ANDAMENTO', $cand->enviados),
                $interno => sprintf('CANDIDATOS (%d)', $cand->total),
                default => null,
            },
            'envioInfo' => $interno && $v->temDono() ? sprintf('%d enviado(s) ao escritório · padrão: %d ou mais', $cand->enviados, $minEnvio) : null,
            'podeEnviar' => $this->politica->podeEnviarCandidatos($ctx, $v),
            'podeMudarStatus' => $this->politica->podeGerirCandidatos($ctx),
            'minEnvio' => $minEnvio,
            'enviadosN' => $this->politica->veCandidatos($ctx, $v) ? $cand->enviados : 0,
            'minhaCandidatura' => !$interno && $cand->minha !== null ? $this->candidaturas->minha($cand->minha, $agora) : null,
            'botaoExterno' => $this->botaoExterno($ctx, $v, $cand, $agora),
        ];
    }

    /** O botão principal do externo no detalhe (l.1095); a vaga própria não mostra "Candidatar-se". */
    private function botaoExterno(Contexto $ctx, Vaga $v, CandidaturasDaVaga $cand, \DateTimeImmutable $agora): ?string
    {
        return match (true) {
            $this->politica->ehInterno($ctx), $this->politica->ehDono($ctx, $v) => null,
            $cand->minha !== null => 'enviada',
            !$v->isOferta() => 'candidatar',
            $v->situacaoEm($agora) === SituacaoVaga::Aberta => 'aceitar',
            default => 'indisponivel',
        };
    }

    private function prazoDoCartao(Vaga $v, \DateTimeImmutable $agora): string
    {
        $prazo = $v->getInscricoesAte();
        if ($prazo === null) {
            return 'Sem prazo definido';
        }

        return ($prazo < $agora->setTime(0, 0) ? 'Inscrições encerradas em ' : 'Inscrições até ') . $prazo->format('d/m/Y');
    }

    /** Prazo do detalhe (`tv.prazo`, l.1003). */
    private function prazoDoDetalhe(Vaga $v): string
    {
        $prazo = $v->getInscricoesAte() ?? $v->getFimDivulgacao();
        if ($v->getPeriodoDias() > 0) {
            return $v->getSituacao() === SituacaoVaga::Pendente
                ? sprintf('Divulgação por %d dia(s) após aprovação', $v->getPeriodoDias())
                : 'Disponível até ' . ($prazo?->format('d/m/Y') ?? '');
        }

        return 'Disponível até ser preenchida';
    }

    private function rotuloEditar(Contexto $ctx, Vaga $v): string
    {
        return match (true) {
            $this->politica->ehDaCentral($ctx) => 'Editar vaga',
            $v->getSituacao() === SituacaoVaga::Reprovada => 'Corrigir e reenviar',
            default => 'Editar pedido',
        };
    }

    /**
     * "OFERECIDO POR" (l.1003): nome, e só os dados com o olho aberto. "Contato pelo BlueJus, ao se
     * candidatar." quando nem o e-mail nem o telefone estão visíveis.
     *
     * @return array{nome: string, info: list<array{ic: string, v: string}>, oculto: bool}
     */
    private function ofertante(Vaga $v): array
    {
        $o = $v->getOfertante();
        $ocultos = is_array($o['ocultos'] ?? null) ? $o['ocultos'] : [];
        $ve = static fn (string $k): bool => !in_array($k, $ocultos, true);
        $local = implode('/', array_filter([(string) ($o['cidade'] ?? ''), (string) ($o['uf'] ?? '')]));
        $info = array_values(array_filter([
            $ve('resp') && ($o['resp'] ?? '') !== '' ? ['ic' => 'bi-person', 'v' => (string) $o['resp']] : null,
            $ve('local') && $local !== '' ? ['ic' => 'bi-geo-alt', 'v' => $local] : null,
            $ve('email') && ($o['email'] ?? '') !== '' ? ['ic' => 'bi-envelope', 'v' => (string) $o['email']] : null,
            $ve('tel') && ($o['tel'] ?? '') !== '' ? ['ic' => 'bi-whatsapp', 'v' => (string) $o['tel']] : null,
            $ve('site') && ($o['site'] ?? '') !== '' ? ['ic' => 'bi-globe2', 'v' => (string) $o['site']] : null,
        ]));

        return [
            'nome' => (string) ($o['nome'] ?? ''),
            'info' => $info,
            'oculto' => !($ve('email') && ($o['email'] ?? '') !== '') && !($ve('tel') && ($o['tel'] ?? '') !== ''),
        ];
    }

    /** Os dados para o formulário de edição, inteiros (só para quem edita). @return array<string, mixed> */
    private function formulario(Vaga $v): array
    {
        return [
            'titulo' => $v->getTitulo(),
            'cargo' => $v->getCargo(),
            'contratacao' => $v->getContratacao(),
            'modalidade' => $v->getModalidade(),
            'cidade' => $v->getCidade(),
            'uf' => $v->getUf(),
            'remuneracao' => $v->getRemuneracao(),
            'areas' => $v->getAreas(),
            'requisitos' => $v->getRequisitos(),
            'inscricoesAte' => $v->getInscricoesAte()?->format('Y-m-d'),
            'exigeOab' => $v->isExigeOab(),
            'periodoDias' => $v->getPeriodoDias(),
            'oferta' => $v->isOferta(),
            'duracaoDaOferta' => $v->getDuracaoDaOferta(),
            'qtd' => $v->getQtd(),
            'ofertante' => $v->getOfertante(),
        ];
    }

    /** "{modalidade ou Modalidade a combinar} · {cidade/UF ou Brasil}" (l.1117). */
    private function local(Vaga $v): string
    {
        $cidadeUf = implode('/', array_filter([$v->getCidade(), $v->getUf()]));

        return ($v->getModalidade() !== '' ? $v->getModalidade() : 'Modalidade a combinar') . ' · ' . ($cidadeUf !== '' ? $cidadeUf : 'Brasil');
    }
}
