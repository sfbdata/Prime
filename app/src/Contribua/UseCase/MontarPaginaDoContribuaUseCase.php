<?php

declare(strict_types=1);

namespace App\Contribua\UseCase;

use App\Contribua\DTO\RetratoDoEspaco;
use App\Contribua\DTO\Solicitante;
use App\Contribua\Entity\MotivoDeNaoApoiar;
use App\Contribua\Entity\Plano;
use App\Contribua\Entity\Sugestao;
use App\Contribua\Enum\FaixaDoEspaco;
use App\Contribua\Enum\TipoDeSugestao;
use App\Contribua\Repository\ApoioRepository;
use App\Contribua\Repository\MotivoDeNaoApoiarRepository;
use App\Contribua\Repository\PlanoRepository;
use App\Contribua\Repository\SugestaoRepository;
use App\Contribua\Service\CodigoPixDaConta;
use App\Contribua\Service\SituacaoDoEspaco;
use App\Contribua\Service\VisaoDoMaster;

/**
 * Tudo o que a página "Quem somos e contribua" (desenho 08) mostra, já calculado: espaço, pacotes,
 * PIX do escritório, estado do apoio e da pergunta de motivo, sugestões e números.
 *
 * As regras de apresentação vêm do script do desenho (`renderVals`): o pacote recomendado, os
 * textos e cores do cabeçalho do espaço e o rótulo de cada botão. O Master vê as sugestões e os
 * números de todos os escritórios; os demais, só do próprio.
 */
final class MontarPaginaDoContribuaUseCase
{
    private const VALOR_PADRAO_CENTAVOS = 2000;

    public function __construct(
        private readonly SituacaoDoEspaco $situacao,
        private readonly PlanoRepository $planos,
        private readonly SugestaoRepository $sugestoes,
        private readonly ApoioRepository $apoios,
        private readonly MotivoDeNaoApoiarRepository $motivos,
        private readonly VisaoDoMaster $visaoDoMaster,
        private readonly CodigoPixDaConta $codigoPix,
    ) {
    }

    /** @return array<string, mixed> */
    public function executar(Solicitante $quem): array
    {
        $retrato = $this->situacao->doEscritorio($quem->tenantId());
        $faz30Dias = new \DateTimeImmutable('-30 days');
        $apoiou = $this->apoios->apoiouDesde($quem->tenantId(), $faz30Dias);
        $respondeu = $this->motivos->respondeuDesde($quem->tenantId(), $faz30Dias);

        return [
            'escritorio' => $quem->escritorio->getName(),
            'ehMaster' => $quem->ehMaster,
            'podePedirEspaco' => $quem->ehPrimario || $quem->ehMaster,
            'espaco' => self::cabecalhoDoEspaco($retrato, $planos = $this->planos->ativosEmOrdem()),
            'pacotes' => self::pacotes($planos, $retrato),
            'pix' => ['conta' => $quem->numeroDaConta(), ...$this->codigoPix->para($quem, self::VALOR_PADRAO_CENTAVOS)],
            'apoiouNoMes' => $apoiou,
            'perguntarMotivo' => !$apoiou && !$respondeu,
            'motivoRespondido' => !$apoiou && $respondeu,
            'motivos' => MotivoDeNaoApoiar::MOTIVOS,
            'tipos' => array_map(static fn (TipoDeSugestao $t): array => ['codigo' => $t->value, 'rotulo' => $t->rotulo(), 'icone' => $t->icone(), 'cor' => $t->cores()[0], 'fundo' => $t->cores()[1], 'dica' => $t->dica()], TipoDeSugestao::cases()),
            ...$this->sugestoesENumeros($quem),
        ];
    }

    /**
     * Escritório sem limite (só o que o Master define no painel; decisões do Samuel, 30/10): o uso aparece contra a cota
     * do plano, com o percentual real mesmo acima de 100% e a barra cheia, e a faixa de alerta segue o desenho. Nunca
     * "pausados": sem limite não há bloqueio.
     *
     * @param list<Plano> $planos
     *
     * @return array<string, mixed>
     */
    private static function cabecalhoDoEspaco(RetratoDoEspaco $r, array $planos): array
    {
        $limite = $r->bytesLimite;
        $pct = $r->percentual;
        if ($r->semLimite()) {
            $cotas = array_map(static fn (Plano $p): int => $p->getCotaGb(), array_filter($planos, static fn (Plano $p): bool => $p->getCodigo() === $r->planoCodigo));
            $limite = $cotas === [] || reset($cotas) <= 0 ? null : reset($cotas) * RetratoDoEspaco::BYTES_POR_GB;
            $pct = $limite === null ? null : (int) round($r->bytesUsados / $limite * 100);
        }
        // "Seu espaço acabou: novos envios estão pausados" só quando é verdade: bloqueio ligado e faixa
        // Bloqueio atingida. Com o bloqueio desligado, acima de 100% os envios continuam passando.
        $cheio = !$r->semLimite() && $r->bloqueioAtivo && $r->faixa === FaixaDoEspaco::Bloqueio;
        $alto = ($pct ?? 0) >= 80;

        return [
            'titulo' => $cheio ? 'Seu espaço acabou: novos envios estão pausados' : ($alto ? 'Seu escritório está com ' . $pct . '% do espaço ocupado' : 'Mais espaço quando o escritório crescer'),
            'subtitulo' => $alto ? 'Amplie agora e continue anexando documentos sem interrupção. Nada do que você já guardou é apagado.' : 'O BlueJus continua gratuito. Amplie o espaço só quando precisar, mês a mês.',
            'icone' => $alto ? 'bi-exclamation-triangle' : 'bi-hdd-stack',
            // Cores pelos limiares literais do desenho (100 vermelho, 90 âmbar). Acima de 100% com o bloqueio
            // desligado o texto é o de uso alto (decisão da coordenação, 19/10).
            'fundo' => $cheio || $pct >= 100 ? '#fdf1f2' : ($alto ? '#fff8e6' : '#f8f9fa'),
            'cor' => $cheio || $pct >= 100 ? '#b02a37' : ($pct >= 90 ? '#c08a12' : '#0a6fa0'),
            'uso' => RetratoDoEspaco::formatarGb($r->bytesUsados),
            // Nulo só no sem limite com plano fora do catálogo: a tela mostra o uso sem barra.
            'limite' => $limite === null ? null : RetratoDoEspaco::formatarGb($limite),
            'plano' => $r->planoNome,
            // "Inclui 5 GB de bônus até dd/mm/aaaa": só com bônus vigente (o texto vem por tradução).
            'bonusGb' => $r->bytesBonus > 0 ? intdiv($r->bytesBonus, RetratoDoEspaco::BYTES_POR_GB) : null,
            'bonusAte' => $r->bytesBonus > 0 ? $r->bonusAte?->format('d/m/Y') : null,
            'percentual' => $pct,
            'largura' => $pct === null ? null : min(100, $pct) . '%',
        ];
    }

    /**
     * @param list<Plano> $planos
     *
     * @return list<array<string, mixed>>
     */
    private static function pacotes(array $planos, RetratoDoEspaco $r): array
    {
        // Os pacotes se comparam com o limite BASE (sem o bônus de 30 dias): o bônus não esconde nem desabilita plano pago.
        if ($r->semLimite()) {
            return array_map(static fn (Plano $p): array => self::pacoteSemComparacao($p, $r->planoCodigo === $p->getCodigo()), $planos);
        }
        $limiteGb = intdiv((int) $r->bytesLimite - $r->bytesBonus, RetratoDoEspaco::BYTES_POR_GB);
        $usoGb = $r->bytesUsados / RetratoDoEspaco::BYTES_POR_GB;
        $recomendado = self::recomendado($planos, $limiteGb, $usoGb);

        return array_map(
            static fn (Plano $p): array => self::pacote($p, $r->planoCodigo === $p->getCodigo(), $limiteGb, $recomendado === $p->getCodigo()),
            $planos,
        );
    }

    /** O primeiro plano pago com pelo menos o dobro do uso e mais que o limite; senão o maior. */
    private static function recomendado(array $planos, int $limiteGb, float $usoGb): ?string
    {
        $pagos = array_values(array_filter($planos, static fn (Plano $p): bool => !$p->ehGratuito()));
        foreach ($pagos as $plano) {
            if ($plano->getCotaGb() >= max($limiteGb + 1, $usoGb * 2)) {
                return $plano->getCodigo();
            }
        }

        return $pagos === [] ? null : end($pagos)->getCodigo();
    }

    /** @return array<string, mixed> */
    private static function pacote(Plano $p, bool $atual, int $limiteGb, bool $recomendado): array
    {
        $gb = $p->getCotaGb();
        $menor = !$atual && $gb <= $limiteGb;
        $desabilitado = $atual || $menor || $p->ehGratuito();
        $topo = $recomendado && !$atual;
        $ganho = $gb - min($limiteGb, $gb);

        return [
            'codigo' => $p->getCodigo(),
            'nome' => $p->getNome(),
            'gbNumero' => $gb,
            'centavos' => $p->getPrecoCentavos(),
            'hojeGb' => min($limiteGb, $gb),
            'rotulo' => mb_strtoupper($p->getNome()),
            'gb' => $gb . ' GB',
            'preco' => $p->ehGratuito() ? 'Grátis' : RegistrarApoioUseCase::reais($p->getPrecoCentavos()),
            'periodo' => $p->ehGratuito() ? ($atual ? 'seu plano atual' : '') : '/ mês',
            'porDia' => $p->ehGratuito() ? '' : 'menos de ' . RegistrarApoioUseCase::reais((int) ceil($p->getPrecoCentavos() / 30)) . ' por dia',
            'ganho' => '+' . $ganho . ' GB',
            'temGanho' => !$atual && $gb > $limiteGb,
            'descricao' => $p->getDescricao(),
            'recomendado' => $topo,
            'atual' => $atual,
            'desabilitado' => $desabilitado,
            // Selo do convênio OAB: só no plano gratuito, o que o convênio dá sem custo.
            'oab' => $p->ehGratuito(),
            // Nuvem "+ Espaço": só nos pagos que ampliam o espaço de hoje; verde no recomendado.
            'temNuvem' => !$p->ehGratuito() && !$atual && !$menor,
            'nuvemRecomendada' => $topo,
            'botao' => $atual ? 'Seu plano atual' : ($p->ehGratuito() ? 'Incluído' : ($menor ? 'Menor que o atual' : 'Quero +' . $ganho . ' GB')),
        ];
    }

    /**
     * Sem limite não há o que ampliar nem com o que comparar: nenhum "Menor que o atual", "+N GB", nuvem ou
     * recomendado. Os planos que não são o atual aparecem como "Incluído", desabilitados.
     *
     * @return array<string, mixed>
     */
    private static function pacoteSemComparacao(Plano $p, bool $atual): array
    {
        return [
            ...self::pacote($p, $atual, $p->getCotaGb(), false),
            'hojeGb' => $p->getCotaGb(),
            'ganho' => '',
            'temGanho' => false,
            'desabilitado' => true,
            'temNuvem' => false,
            'botao' => $atual ? 'Seu plano atual' : 'Incluído',
        ];
    }

    /** @return array{sugestoes: list<array<string, mixed>>, numeros: array<string, int>} */
    private function sugestoesENumeros(Solicitante $quem): array
    {
        $escopo = $quem->ehMaster ? null : $quem->tenantId();
        $ler = fn (): array => [$this->sugestoes->doEscritorio($escopo), $this->apoios->quantos($escopo)];
        [$lista, $apoios] = $quem->ehMaster ? $this->visaoDoMaster->deTodosOsEscritorios($ler) : $ler();
        $atendidas = \count(array_filter($lista, static fn (Sugestao $s): bool => $s->isAtendida()));

        return [
            'sugestoes' => array_map(self::sugestao(...), $lista),
            'numeros' => ['recebidas' => \count($lista), 'emAberto' => \count($lista) - $atendidas, 'atendidas' => $atendidas, 'apoios' => $apoios],
        ];
    }

    /** @return array<string, mixed> */
    private static function sugestao(Sugestao $s): array
    {
        [$cor, $fundo] = $s->getTipo()->cores();

        return [
            'id' => $s->getId(),
            'tipo' => $s->getTipo()->rotulo(),
            'icone' => $s->getTipo()->icone(),
            'cor' => $cor,
            'fundo' => $fundo,
            'quem' => $s->getAutorNome(),
            'em' => $s->getCriadaEm()->format('d/m/Y'),
            'texto' => $s->getTexto(),
            'atendida' => $s->isAtendida(),
            'resposta' => $s->getResposta(),
        ];
    }
}
