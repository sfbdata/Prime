<?php

declare(strict_types=1);

namespace App\TrabalhoExterno\UseCase;

use App\TrabalhoExterno\DTO\RetratoDoEngajamento;
use App\TrabalhoExterno\Entity\Curriculo;
use App\TrabalhoExterno\Entity\PreferenciaDoUsuario;
use App\TrabalhoExterno\Entity\Vaga;
use App\TrabalhoExterno\Enum\SituacaoVaga;
use App\TrabalhoExterno\Enum\StatusCurriculo;
use App\TrabalhoExterno\Enum\StatusDoTermo;
use App\TrabalhoExterno\Repository\CandidaturaRepository;
use App\TrabalhoExterno\Repository\PreferenciaDoUsuarioRepository;
use App\TrabalhoExterno\Repository\TermoDeVoluntariadoRepository;
use App\TrabalhoExterno\Repository\VagaRepository;
use App\TrabalhoExterno\Service\Contexto;
use App\TrabalhoExterno\Service\EngajamentoDoTrabalhoExterno;
use App\TrabalhoExterno\Service\LocalizadorDeCurriculo;
use App\TrabalhoExterno\Service\LocalizadorDeVaga;
use App\TrabalhoExterno\Service\PercentualDoCurriculo;
use App\TrabalhoExterno\Service\PoliticaDaVaga;

/**
 * `GET /trabalho-externo/api/engajamento` (spec VS 18.10.26 §4.7): os selos das abas, o banner com a
 * saudação e os chips, o botão de avisos, o lembrete diário e as boas-vindas pelo link.
 *
 * Quem: qualquer perfil do Trabalho Externo; o Master também pela prévia (`?como=`, só leitura). Tudo
 * sai do que a pessoa já vê (as vagas e os currículos do escopo dela). O lembrete e as boas-vindas são
 * só do externo; com as boas-vindas não há lembrete na mesma visita (l.1078). `ofertas` é a situação
 * agora das ofertas com tempo já publicadas que a tela acompanha (o QUASE da oferta perdida, l.1012), sem nenhum
 * outro dado delas. Consulta: não grava nada.
 */
final class MontarEngajamentoUseCase
{
    public function __construct(
        private readonly LocalizadorDeVaga $vagas,
        private readonly LocalizadorDeCurriculo $curriculos,
        private readonly PoliticaDaVaga $politica,
        private readonly CandidaturaRepository $candidaturas,
        private readonly PreferenciaDoUsuarioRepository $preferencias,
        private readonly PercentualDoCurriculo $percentual,
        private readonly EngajamentoDoTrabalhoExterno $engajamento,
        private readonly VagaRepository $repositorioDeVagas,
        private readonly TermoDeVoluntariadoRepository $termos,
    ) {
    }

    /**
     * @param list<string>|null $indicadas os códigos do link (`?vagas=`); null quando não veio pelo link
     * @param list<string>      $ofertas   os códigos das ofertas que a tela acompanha (`?ofertas=`)
     *
     * @return array<string, mixed>
     */
    public function executar(Contexto $ctx, ?array $indicadas, bool $cadastro, array $ofertas = []): array
    {
        $agora = new \DateTimeImmutable();
        $vagas = $this->vagas->visiveis($ctx, $agora);
        $r = $ctx->ehInterno() ? $this->retratoDoInterno($ctx, $vagas, $agora) : $this->retratoDoExterno($ctx, $vagas, $agora);
        $boasVindas = !$r->interno && $indicadas !== null ? $this->engajamento->boasVindas($r, $cadastro, $this->abertasEntre($vagas, $indicadas, $agora)) : null;

        return [
            'contadores' => [
                'vagas' => $r->talentos && $r->pendentes > 0 ? $r->pendentes : $r->abertas,
                'curriculos' => $r->interno ? $this->novosNoBanco($ctx) : 0,
            ],
            'banner' => [
                'saudacao' => $this->engajamento->saudacao($r->nome, $agora),
                'subtitulo' => $this->engajamento->subtitulo($r->interno),
                'chips' => $this->engajamento->chips($r),
            ],
            'alerta' => ['disponivel' => $this->alertaDisponivel($ctx), 'ligado' => $r->alertaLigado],
            'lembrete' => !$r->interno && $boasVindas === null ? $this->engajamento->lembrete($r) : null,
            'boasVindas' => $boasVindas,
            'motiv' => $ctx->configuracao->getVagas()['motiv'],
            'ofertas' => $this->situacaoDasOfertas($ctx, $ofertas, $agora),
        ];
    }

    /** As mesmas condições do `DefinirAlertaDeVagasUseCase`: externo que vê a aba, com escritório e sino ligado. */
    private function alertaDisponivel(Contexto $ctx): bool
    {
        return !$ctx->ehInterno() && $ctx->tenant !== null && $this->politica->veAba($ctx) && $ctx->configuracao->getVagas()['sino'];
    }

    /**
     * @param list<string> $codigos
     *
     * @return array<string, string>|\stdClass código → aberta, preenchida, expirada, pausada ou encerrada (objeto vazio sem nenhuma)
     */
    private function situacaoDasOfertas(Contexto $ctx, array $codigos, \DateTimeImmutable $agora): array|\stdClass
    {
        if (!$this->politica->veAba($ctx)) {
            return new \stdClass();
        }
        $ids = array_slice(array_values(array_unique(array_filter(array_map(Vaga::idDoCodigo(...), $codigos)))), 0, 50);
        $mapa = [];
        foreach ($this->repositorioDeVagas->publicadasEntre($ids) as $v) {
            $situacao = !$v->isOferta() ? null : match ($v->situacaoEm($agora)) {
                SituacaoVaga::Aberta => 'aberta',
                SituacaoVaga::Ocupada => 'preenchida',
                SituacaoVaga::Expirada => 'expirada',
                SituacaoVaga::Pausada => 'pausada',
                SituacaoVaga::Encerrada => 'encerrada',
                default => null,
            };
            if ($situacao !== null) {
                $mapa[$v->getCodigo()] = $situacao;
            }
        }

        return $mapa === [] ? new \stdClass() : $mapa;
    }

    /** @param list<Vaga> $vagas */
    private function retratoDoInterno(Contexto $ctx, array $vagas, \DateTimeImmutable $agora): RetratoDoEngajamento
    {
        $semana = $agora->modify('-7 days');
        $noBanco = array_filter($this->curriculos->visiveis($ctx), static fn (Curriculo $cv): bool => $cv->getRecebidoEm() > $semana);

        return new RetratoDoEngajamento(
            nome: (string) $ctx->usuario->getFullName(),
            interno: true,
            agora: $agora,
            totalVagas: count($vagas),
            abertas: count($this->naSituacao($vagas, SituacaoVaga::Aberta, $agora)),
            pendentes: count($this->naSituacao($vagas, SituacaoVaga::Pendente, $agora)),
            talentos: $this->politica->ehDaCentral($ctx),
            podeCriar: $this->politica->podeCriar($ctx),
            ofertasAoVivo: $this->ofertasAoVivo($vagas, $agora),
            curriculosNaSemana: count($noBanco),
            candidaturasNaSemana: $this->candidaturas->contarDesde($semana),
            minutosAteOAceite: $this->minutosAteOAceite(),
        );
    }

    /** @param list<Vaga> $vagas */
    private function retratoDoExterno(Contexto $ctx, array $vagas, \DateTimeImmutable $agora): RetratoDoEngajamento
    {
        $meu = $this->curriculos->meu($ctx);
        $abertas = $this->naSituacao($vagas, SituacaoVaga::Aberta, $agora);
        $visita = $ctx->previa ? null : $this->preferencias->doUsuario($ctx->usuario, PreferenciaDoUsuario::ULTIMA_VISITA_VAGAS)?->visitaAnterior();
        $alerta = $ctx->previa ? null : $this->preferencias->doUsuario($ctx->usuario, PreferenciaDoUsuario::ALERTA_DE_VAGAS)?->getValor();
        $percentual = $meu !== null ? $this->percentual->calcular($meu) : null;

        return new RetratoDoEngajamento(
            nome: (string) $ctx->usuario->getFullName(),
            interno: false,
            agora: $agora,
            totalVagas: count($vagas),
            abertas: count($abertas),
            ofertasAoVivo: $this->ofertasAoVivo($vagas, $agora),
            novasDesdeAVisita: $visita === null ? 0 : count(array_filter($abertas, static fn (Vaga $v): bool => $v->getCriadaEm() > $visita)),
            percentual: $percentual !== null ? ['pct' => $percentual['pct'], 'faltas' => $percentual['faltas']] : null,
            atualizadoEm: $meu?->getAtualizadoEm(),
            candidatou: $meu !== null && $this->candidaturas->doCurriculo($meu) !== [],
            alertaLigado: ($alerta['ligado'] ?? false) === true,
            termoParaAceitar: $meu !== null && $this->termos->maisRecenteDo($meu)?->getStatus() === StatusDoTermo::Enviado,
        );
    }

    /**
     * @param list<Vaga> $vagas
     *
     * @return list<Vaga>
     */
    private function naSituacao(array $vagas, SituacaoVaga $situacao, \DateTimeImmutable $agora): array
    {
        return array_values(array_filter($vagas, static fn (Vaga $v): bool => $v->situacaoEm($agora) === $situacao));
    }

    /**
     * @param list<Vaga> $vagas
     *
     * @return list<\DateTimeImmutable> o fim de cada oferta com tempo aberta, da que termina antes
     */
    private function ofertasAoVivo(array $vagas, \DateTimeImmutable $agora): array
    {
        $fins = [];
        foreach ($this->naSituacao($vagas, SituacaoVaga::Aberta, $agora) as $v) {
            if ($v->isOferta() && $v->getExpiraEm() !== null) {
                $fins[] = $v->getExpiraEm();
            }
        }
        sort($fins);

        return $fins;
    }

    /** A média, em minutos, do início da rodada ao primeiro aceite (`tMed`, l.1018); null sem aceite. */
    private function minutosAteOAceite(): ?float
    {
        return EngajamentoDoTrabalhoExterno::minutosAteOAceite($this->candidaturas->primeirosAceitesDasRodadas());
    }

    private function novosNoBanco(Contexto $ctx): int
    {
        return count(array_filter($this->curriculos->visiveis($ctx), static fn (Curriculo $cv): bool => $cv->getStatus() === StatusCurriculo::Novo));
    }

    /**
     * Os códigos do link que a pessoa vê abertos agora, na ordem do link (o código do link pode vir sem
     * os zeros: `VG-1` é `VG-0001`).
     *
     * @param list<Vaga>   $vagas
     * @param list<string> $codigos
     *
     * @return list<string>
     */
    private function abertasEntre(array $vagas, array $codigos, \DateTimeImmutable $agora): array
    {
        $abertas = [];
        foreach ($this->naSituacao($vagas, SituacaoVaga::Aberta, $agora) as $v) {
            $abertas[(int) $v->getId()] = $v->getCodigo();
        }
        $indicadas = [];
        foreach ($codigos as $codigo) {
            $id = Vaga::idDoCodigo($codigo);
            if ($id !== null && isset($abertas[$id])) {
                $indicadas[$id] = $abertas[$id];
            }
        }

        return array_values($indicadas);
    }
}
