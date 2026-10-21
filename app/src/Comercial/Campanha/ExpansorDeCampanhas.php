<?php

declare(strict_types=1);

namespace App\Comercial\Campanha;

use App\Comercial\Entity\CampanhaDeEmail;
use App\Comercial\Envio\CarteiroInterface;
use App\Comercial\Envio\MensagemDeEmail;
use App\Comercial\Exception\CampanhaRecusada;
use App\Comercial\DTO\DestinatarioDaCampanha;
use App\Comercial\DTO\FiltroDoPublico;
use App\Comercial\Repository\CampanhaDeEmailRepository;
use App\Comercial\Repository\DestinoDaCampanhaRepository;
use App\Comercial\Repository\EnvioDeEmailRepository;
use Psr\Log\LoggerInterface;

/**
 * O trabalho de cada volta do expansor (spec `comercial-e-emails.md` §8.1 e §8.5), chamado pelo
 * {@see \App\Comercial\MessageHandler\ExpandirCampanhasHandler}:
 *   1. a chave da instalação: desligada, toda campanha ativa vira `cancelada` (parada de emergência);
 *   2. materializa a execução devida em `comercial_campanha_destino`, com o público resolvido de novo (B1 e descadastro
 *      reaplicados). Nada é recuperado: a única que acordou mais de 24 h atrasada é cancelada sem enviar; a recorrente
 *      que perdeu a vez pula para a próxima data futura;
 *   3. libera um lote numa transação da conexão (trava as linhas com `SKIP LOCKED`, entrega ao Carteiro, que vira
 *      savepoint, e grava o `envio_id`): queda no meio = nada gravado, nada duplicado;
 *   4. a única com o último destinatário entregue vira `enviada`.
 *
 * Vazão própria (R4): lote de até 10 por volta (uma por minuto), teto diário das campanhas (1.000 por padrão, abaixo dos
 * 1.500 globais) e contrapressão (o lote só sai com menos de um lote de envios de campanha ainda na fila do Carteiro).
 * Logs só com ids, nunca o endereço.
 *
 * @phpstan-import-type Campanha from CampanhaDeEmailRepository
 */
final class ExpansorDeCampanhas
{
    public const LOTE = 10;
    public const TOLERANCIA_DO_ATRASO = '-24 hours';
    public const MOTIVO_DO_ATRASO = 'Acordou com mais de 24 h de atraso; nada foi enviado';

    public function __construct(
        private readonly CampanhaDeEmailRepository $campanhas,
        private readonly DestinoDaCampanhaRepository $destinos,
        private readonly EnvioDeEmailRepository $envios,
        private readonly PublicosDaCampanha $publicos,
        private readonly ComposicaoDaCampanha $composicao,
        private readonly CarteiroInterface $carteiro,
        private readonly PoliticaDeDestinatarios $politica,
        private readonly ChaveDasCampanhas $chave,
        private readonly LoggerInterface $logger,
        private readonly int $tetoDiarioDeCampanhas,
    ) {
    }

    /**
     * A parada de emergência (§8.1.2). Devolve se a chave está desligada (e então nada mais roda nesta volta). Cada
     * campanha cancelada ganha a linha `camp_canc` na trilha, na mesma transação.
     */
    public function pararSeDesligada(\DateTimeImmutable $agora): bool
    {
        if ($this->chave->ligada()) {
            return false;
        }
        $motivo = ChaveDasCampanhas::MOTIVO_DO_CANCELAMENTO;
        $canceladas = $this->campanhas->emTransacao(function () use ($motivo, $agora): int {
            $linhas = $this->campanhas->cancelarAtivas($motivo);
            foreach ($linhas as $linha) {
                $this->auditarCancelamento($linha['id'], $motivo, $linha['total'], $agora);
            }

            return \count($linhas);
        });
        if ($canceladas > 0) {
            $this->logger->warning('Comercial: chave das campanhas desligada; campanhas ativas canceladas.', ['canceladas' => $canceladas]);
        }

        return true;
    }

    /** Cada campanha devida isolada: uma falha não segura as outras. Devolve quantas tiveram a vez tratada. */
    public function materializarDevidas(\DateTimeImmutable $agora): int
    {
        $tratadas = 0;
        foreach ($this->campanhas->devidas($agora) as $campanha) {
            try {
                $this->materializar($campanha, $agora);
                ++$tratadas;
            } catch (\Throwable $erro) {
                $this->logger->error('Comercial: execução da campanha não materializada.', ['campanhaId' => $campanha['id'], 'erro' => $erro::class]);
            }
        }

        return $tratadas;
    }

    /** Entrega ao Carteiro um lote dentro de uma transação. Devolve quantos destinatários saíram para a fila. */
    public function liberarLote(\DateTimeImmutable $agora): int
    {
        $tamanho = $this->tamanhoDoLote($agora);
        if ($tamanho === 0) {
            return 0;
        }

        return $this->destinos->emTransacao(function () use ($tamanho): int {
            $mensagens = [];
            $liberados = 0;
            foreach ($this->destinos->reservarPendentes($tamanho) as $destino) {
                $email = $this->mensagem($destino, $mensagens);
                if ($email === null) {
                    $this->destinos->descartar($destino['id']);
                    continue;
                }
                $this->destinos->gravarEnvio($destino['id'], $this->carteiro->enviar($email)->envioId);
                ++$liberados;
            }

            return $liberados;
        });
    }

    public function concluir(): int
    {
        return $this->campanhas->concluirEnviadas();
    }

    public function haAtivas(): bool
    {
        return $this->campanhas->haAtivas();
    }

    /** @param Campanha $campanha */
    private function materializar(array $campanha, \DateTimeImmutable $agora): void
    {
        $limite = $agora->modify(self::TOLERANCIA_DO_ATRASO);
        $proxima = new \DateTimeImmutable((string) $campanha['proxima_execucao']);
        if ($campanha['estado'] !== CampanhaDeEmail::RECORRENTE) {
            $proxima < $limite ? $this->cancelarAtrasada($campanha, $agora) : $this->executar($campanha, $agora, null);

            return;
        }
        $ancora = new \DateTimeImmutable($campanha['agendada_para'] ?? (string) $campanha['proxima_execucao']);
        if ($proxima < $limite) {
            $proxima = (CalendarioDaCampanha::primeiraDepois($campanha['frequencia'], $ancora, $proxima, $limite) ?? $agora);
            if ($proxima > $agora) {
                $this->campanhas->adiar($campanha['id'], $proxima);

                return;
            }
        }
        $this->executar($campanha, $agora, CalendarioDaCampanha::primeiraDepois($campanha['frequencia'], $ancora, $proxima, $agora));
    }

    /**
     * A execução: trava a campanha (se ela mudou desde a leitura, outra volta já tratou), resolve o público e grava os
     * destinatários, o total, o estado e a próxima data, numa transação.
     *
     * @param Campanha $campanha
     */
    private function executar(array $campanha, \DateTimeImmutable $agora, ?\DateTimeImmutable $seguinte): void
    {
        $this->campanhas->emTransacao(function () use ($campanha, $agora, $seguinte): void {
            if ($this->campanhas->travarDevida($campanha['id'], $campanha['estado'], $campanha['execucoes']) === null) {
                return;
            }
            $informados = array_map(static fn (array $d): DestinatarioDaCampanha => new DestinatarioDaCampanha($d['email'], $d['nome']), $campanha['publico_parametros']);
            // O filtro congelado (§9) volta a valer, com a data desta execução (idade e aniversário).
            $publico = $this->publicos->resolver($campanha['publico'], $informados, FiltroDoPublico::deArray($campanha['publico_filtro']), $agora);
            $execucao = $campanha['execucoes'] + 1;
            $this->destinos->materializar($campanha['id'], $execucao, $publico->destinatarios, $agora);
            $recorrente = $campanha['estado'] === CampanhaDeEmail::RECORRENTE;
            $estado = $recorrente ? CampanhaDeEmail::RECORRENTE : ($publico->total() > 0 ? CampanhaDeEmail::ENVIANDO : CampanhaDeEmail::ENVIADA);
            $this->campanhas->registrarExecucao($campanha['id'], $execucao, $publico->total(), $estado, $recorrente ? $seguinte : null);
        });
    }

    /** @param Campanha $campanha */
    private function cancelarAtrasada(array $campanha, \DateTimeImmutable $agora): void
    {
        $this->campanhas->emTransacao(function () use ($campanha, $agora): void {
            $total = $this->campanhas->cancelar($campanha['id'], $campanha['estado'], self::MOTIVO_DO_ATRASO);
            if ($total !== null) {
                $this->auditarCancelamento($campanha['id'], self::MOTIVO_DO_ATRASO, $total, $agora);
            }
        });
    }

    /** A linha manual da trilha do cancelamento automático (R6): `camp_canc`, `{motivo, total}`, sem autor. */
    private function auditarCancelamento(int $id, string $motivo, int $total, \DateTimeImmutable $agora): void
    {
        $this->campanhas->auditar($id, CampanhaDeEmailRepository::CANCELADA, ['motivo' => $motivo, 'total' => $total], null, null, $agora);
    }

    /** Contrapressão e teto diário das campanhas (§8.5): 0 = este lote espera. */
    private function tamanhoDoLote(\DateTimeImmutable $agora): int
    {
        if ($this->envios->contarDeCampanhaNaFila() >= self::LOTE) {
            return 0;
        }
        $restante = $this->tetoDiarioDeCampanhas - $this->envios->contarDeCampanhaDesde($agora->setTime(0, 0));

        return max(0, min(self::LOTE, $restante));
    }

    /**
     * O e-mail do destinatário, com a campanha descongelada uma vez por lote. Null se não vira e-mail (o conteúdo ou o
     * endereço deixou de valer): o destinatário sai da execução em vez de travar o lote.
     *
     * @param array{id: int, campanha_id: int, email: string, nome: ?string} $destino
     * @param array<int, \App\Comercial\DTO\MensagemDaCampanha|null>          $mensagens
     */
    private function mensagem(array $destino, array &$mensagens): ?MensagemDeEmail
    {
        $id = $destino['campanha_id'];
        if (!\array_key_exists($id, $mensagens)) {
            $campanha = $this->campanhas->porId($id);
            try {
                $mensagens[$id] = $campanha === null ? null : $this->composicao->descongelar($campanha);
            } catch (CampanhaRecusada) {
                $mensagens[$id] = null;
            }
        }
        try {
            return $mensagens[$id] === null ? null : $this->composicao->mensagemPara($mensagens[$id], $destino['email'], $destino['nome'], 'campanha:' . $id, true, $this->politica->replyToDaCampanha());
        } catch (CampanhaRecusada | \InvalidArgumentException) {
            $this->logger->error('Comercial: destinatário de campanha descartado (não vira e-mail).', ['campanhaId' => $id, 'destinoId' => $destino['id']]);

            return null;
        }
    }
}
