<?php

declare(strict_types=1);

namespace App\Contribua\UseCase;

use App\Contribua\DTO\RetratoDoEspaco;
use App\Contribua\DTO\Solicitante;
use App\Contribua\Entity\MotivoDeNaoApoiar;
use App\Contribua\Entity\Plano;
use App\Contribua\Entity\Sugestao;
use App\Contribua\Enum\FaixaDoEspaco;
use App\Contribua\Enum\OrigemDoLimite;
use App\Contribua\Enum\TipoDeSugestao;
use App\Contribua\Repository\ApoioRepository;
use App\Contribua\Repository\MotivoDeNaoApoiarRepository;
use App\Contribua\Repository\PlanoRepository;
use App\Contribua\Repository\SugestaoRepository;
use App\Contribua\Service\CodigoPixDaConta;
use App\Contribua\Service\LiberacaoDasOpcoesDeEspaco;
use App\Contribua\Service\SituacaoDoEspaco;
use App\Contribua\Service\VisaoDoMaster;
use App\Master\Service\ChaveDoConvenio;

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
        private readonly ChaveDoConvenio $convenio,
    ) {
    }

    /** @return array<string, mixed> */
    public function executar(Solicitante $quem): array
    {
        $retrato = $this->situacao->doEscritorio($quem->tenantId());
        $faz30Dias = new \DateTimeImmutable('-30 days');
        $apoiou = $this->apoios->apoiouDesde($quem->tenantId(), $faz30Dias);
        $respondeu = $this->motivos->respondeuDesde($quem->tenantId(), $faz30Dias);
        // D2: quem já paga um plano continua vendo e gerindo o próprio plano; os demais só com as opções liberadas.
        $pagaPlano = $retrato->planoCodigo !== $this->planos->free()->getCodigo();
        // Chave geral do Convênio OAB (M2.1): desligada, somem a seção, o selo do plano gratuito e o modal. A cobrança não muda.
        $convenioAtivo = $this->convenio->ativo();
        $pacotes = self::pacotes($planos = $this->planos->ativosEmOrdem(), $retrato);
        if (!$convenioAtivo) {
            $pacotes = array_map(static fn (array $pacote): array => ['oab' => false] + $pacote, $pacotes);
        }

        return [
            'escritorio' => $quem->escritorio->getName(),
            'ehMaster' => $quem->ehMaster,
            'podePedirEspaco' => $quem->ehPrimario || $quem->ehMaster,
            'espaco' => self::cabecalhoDoEspaco($retrato, $planos, $retrato->opcoesLiberadas || $pagaPlano),
            'pacotes' => $pacotes,
            'convenioAtivo' => $convenioAtivo,
            // N5: os cartões só existem na tela com as opções liberadas (ou plano pago); o Master os vê em "prévia".
            'pacotesVisiveis' => $retrato->opcoesLiberadas || $pagaPlano,
            'liberadaPor' => $retrato->liberadaPor?->value,
            'previaDoMaster' => $quem->ehMaster && !$retrato->opcoesLiberadas && !$pagaPlano,
            'limiarDaLiberacao' => LiberacaoDasOpcoesDeEspaco::LIMIAR_EM_PERCENTUAL,
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
    private static function cabecalhoDoEspaco(RetratoDoEspaco $r, array $planos, bool $comLiberacao): array
    {
        $limite = $r->bytesLimite;
        $pct = $r->percentual;
        if ($r->semLimite()) {
            $cotas = array_map(static fn (Plano $p): int => $p->getCotaGb(), array_filter($planos, static fn (Plano $p): bool => $p->getCodigo() === $r->planoCodigo));
            $limite = $cotas === [] || reset($cotas) <= 0 ? null : reset($cotas) * RetratoDoEspaco::BYTES_POR_GB;
            $pct = $limite === null ? null : (int) round($r->bytesUsados / $limite * 100);
        }
        // "Novos envios estão pausados" só quando é verdade (D6): bloqueio ligado e faixa Bloqueio atingida. Com o
        // bloqueio desligado, a partir de 100% o texto diz só que o espaço está cheio, sem prometer pausa.
        $cheio = !$r->semLimite() && $r->bloqueioAtivo && $r->faixa === FaixaDoEspaco::Bloqueio;
        $alto = ($pct ?? 0) >= 80;
        $lotado = ($pct ?? 0) >= 100;

        return [
            'titulo' => $cheio ? 'Seu espaço está cheio: novos envios estão pausados' : ($lotado ? 'Seu espaço está cheio' : ($alto ? 'Seu escritório está com ' . $pct . '% do espaço ocupado' : 'Seu espaço de armazenamento')),
            'subtitulo' => $comLiberacao
                ? 'O BlueJus continua gratuito. O espaço extra é opcional: escolha um pacote só se precisar. Nada do que você guardou é apagado.'
                : 'O BlueJus é gratuito. Se o espaço ficar pequeno, solicite mais espaço. Nada do que você guardou é apagado.',
            'pausado' => $cheio,
            'lotado' => $lotado,
            'comLiberacao' => $comLiberacao,
            'icone' => $alto ? 'bi-exclamation-triangle' : 'bi-hdd-stack',
            // Cores pelos limiares literais do desenho (100 vermelho, 90 âmbar). Acima de 100% com o bloqueio
            // desligado o texto é o de uso alto (decisão da coordenação, 19/10).
            'fundo' => $cheio || $pct >= 100 ? '#fdf1f2' : ($alto ? '#fff8e6' : '#f8f9fa'),
            'cor' => $cheio || $pct >= 100 ? '#b02a37' : ($pct >= 90 ? '#c08a12' : '#0a6fa0'),
            'uso' => RetratoDoEspaco::formatarGb($r->bytesUsados),
            // Nulo só no sem limite com plano fora do catálogo: a tela mostra o uso sem barra.
            'limite' => $limite === null ? null : RetratoDoEspaco::formatarGb($limite),
            'plano' => $r->planoNome,
            'extraGb' => self::extraLiberadoEmGb($r, $planos),
            // "Inclui 5 GB de bônus até dd/mm/aaaa": só com bônus vigente (o texto vem por tradução).
            'bonusGb' => $r->bytesBonus > 0 ? intdiv($r->bytesBonus, RetratoDoEspaco::BYTES_POR_GB) : null,
            'bonusAte' => $r->bytesBonus > 0 ? $r->bonusAte?->format('d/m/Y') : null,
            'percentual' => $pct,
            'largura' => $pct === null ? null : min(100, $pct) . '%',
        ];
    }

    /**
     * D5: o "+N GB liberados" é o extra que o sistema já tem: bônus de indicação vigente mais o que o limite próprio dado
     * pelo Master passa da cota do plano. Nulo sem extra (e sem limite, que ignora o bônus).
     *
     * @param list<Plano> $planos
     */
    private static function extraLiberadoEmGb(RetratoDoEspaco $r, array $planos): ?string
    {
        if ($r->semLimite()) {
            return null;
        }
        $extra = $r->bytesBonus;
        if ($r->origemDoLimite === OrigemDoLimite::Master) {
            foreach ($planos as $plano) {
                if ($plano->getCodigo() === $r->planoCodigo) {
                    $extra += max(0, (int) $r->bytesLimite - $r->bytesBonus - $plano->getCotaGb() * RetratoDoEspaco::BYTES_POR_GB);
                }
            }
        }

        return $extra > 0 ? substr(RetratoDoEspaco::formatarGb($extra), 0, -3) : null;
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
        $maior = self::maiorPago($planos);

        return array_map(
            static fn (Plano $p): array => ['maior' => $maior === $p->getCodigo()] + self::pacote($p, $r->planoCodigo === $p->getCodigo(), $limiteGb, $recomendado === $p->getCodigo(), $r->bytesUsados),
            $planos,
        );
    }

    /**
     * O maior pacote pago: só ele tem os botões + e − no checkout para dobrar o espaço e o valor (desenho 08 VS 03.11.26).
     *
     * @param list<Plano> $planos
     */
    private static function maiorPago(array $planos): ?string
    {
        $maior = null;
        foreach ($planos as $plano) {
            if (!$plano->ehGratuito() && ($maior === null || $plano->getCotaGb() > $maior->getCotaGb())) {
                $maior = $plano;
            }
        }

        return $maior?->getCodigo();
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
    private static function pacote(Plano $p, bool $atual, int $limiteGb, bool $recomendado, int $bytesUsados = 0): array
    {
        $gb = $p->getCotaGb();
        // A4: também é "menor" o pacote que não comporta o uso de hoje.
        $naoComporta = $gb * RetratoDoEspaco::BYTES_POR_GB < $bytesUsados;
        $menor = ($gb <= $limiteGb || $naoComporta) && !$atual;
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
            'botao' => $atual ? 'Seu plano atual' : ($p->ehGratuito() ? 'Incluído' : ($menor ? ($naoComporta ? 'Não comporta seu uso' : 'Menor que o atual') : 'Quero +' . $ganho . ' GB')),
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
