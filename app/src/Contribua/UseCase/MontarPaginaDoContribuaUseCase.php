<?php

declare(strict_types=1);

namespace App\Contribua\UseCase;

use App\Contribua\DTO\RetratoDoEspaco;
use App\Contribua\DTO\Solicitante;
use App\Contribua\Entity\MotivoDeNaoApoiar;
use App\Contribua\Entity\Plano;
use App\Contribua\Entity\Sugestao;
use App\Contribua\Enum\FaixaDoEspaco;
use App\Contribua\Repository\ApoioRepository;
use App\Contribua\Repository\MotivoDeNaoApoiarRepository;
use App\Contribua\Repository\PlanoRepository;
use App\Contribua\Repository\SugestaoRepository;
use App\Contribua\Service\CodigoPixEstatico;
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
    ) {
    }

    /** @return array<string, mixed> */
    public function executar(Solicitante $quem): array
    {
        $retrato = $this->situacao->doEscritorio($quem->tenantId());
        $faz30Dias = new \DateTimeImmutable('-30 days');
        $apoiou = $this->apoios->apoiouDesde($quem->tenantId(), $faz30Dias);
        $respondeu = $this->motivos->respondeuDesde($quem->tenantId(), $faz30Dias);
        $txid = CodigoPixEstatico::txidDaConta($quem->numeroDaConta());

        return [
            'escritorio' => $quem->escritorio->getName(),
            'ehMaster' => $quem->ehMaster,
            'podePedirEspaco' => $quem->ehPrimario || $quem->ehMaster,
            'espaco' => self::cabecalhoDoEspaco($retrato),
            'pacotes' => self::pacotes($this->planos->ativosEmOrdem(), $retrato),
            'pix' => ['conta' => $quem->numeroDaConta(), 'txid' => $txid, 'codigo' => CodigoPixEstatico::gerar($txid, self::VALOR_PADRAO_CENTAVOS)],
            'apoiouNoMes' => $apoiou,
            'perguntarMotivo' => !$apoiou && !$respondeu,
            'motivoRespondido' => !$apoiou && $respondeu,
            'motivos' => MotivoDeNaoApoiar::MOTIVOS,
            ...$this->sugestoesENumeros($quem),
        ];
    }

    /** @return array<string, mixed> */
    private static function cabecalhoDoEspaco(RetratoDoEspaco $r): array
    {
        $pct = $r->percentual ?? 0;
        // "Seu espaço acabou: novos envios estão pausados" só quando é verdade: bloqueio ligado e faixa
        // Bloqueio atingida. Com o bloqueio desligado, acima de 100% os envios continuam passando.
        $cheio = $r->bloqueioAtivo && $r->faixa === FaixaDoEspaco::Bloqueio;
        $alto = $pct >= 80;

        return [
            'titulo' => $cheio ? 'Seu espaço acabou: novos envios estão pausados' : ($alto ? 'Seu escritório está com ' . $pct . '% do espaço ocupado' : 'Mais espaço quando o escritório crescer'),
            'subtitulo' => $alto ? 'Amplie agora e continue anexando documentos sem interrupção. Nada do que você já guardou é apagado.' : 'O BlueJus continua gratuito. Amplie o espaço só quando precisar, mês a mês.',
            'icone' => $alto ? 'bi-exclamation-triangle' : 'bi-hdd-stack',
            'fundo' => $cheio ? '#fdf1f2' : ($alto ? '#fff8e6' : '#f8f9fa'),
            'cor' => $cheio ? '#b02a37' : ($pct >= 90 ? '#c08a12' : '#0a6fa0'),
            'uso' => RetratoDoEspaco::formatarGb($r->bytesUsados),
            'limite' => $r->bytesLimite === null ? 'sem limite' : RetratoDoEspaco::formatarGb($r->bytesLimite),
            'plano' => $r->planoNome,
            'percentual' => $pct,
            'largura' => min(100, $pct) . '%',
        ];
    }

    /**
     * @param list<Plano> $planos
     *
     * @return list<array<string, mixed>>
     */
    private static function pacotes(array $planos, RetratoDoEspaco $r): array
    {
        $limiteGb = $r->bytesLimite === null ? PHP_INT_MAX : intdiv($r->bytesLimite, RetratoDoEspaco::BYTES_POR_GB);
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
            'botao' => $atual ? 'Seu plano atual' : ($p->ehGratuito() ? 'Incluído' : ($menor ? 'Menor que o atual' : 'Quero +' . $ganho . ' GB')),
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
