<?php

declare(strict_types=1);

namespace App\Pasta\UseCase;

use App\Cliente\Entity\Cliente;
use App\Djen\Entity\PublicacaoDjen;
use App\Djen\Repository\PublicacaoDjenRepository;
use App\Entity\Tenant\Tenant;
use App\Pasta\DTO\ChavesDoEnvioAutomatico;
use App\Pasta\DTO\MovimentacaoAoClienteOutput;
use App\Pasta\DTO\PedidoDeEnvioAutomatico;
use App\Pasta\Entity\Pasta;
use App\Pasta\Repository\EnvioAoClienteRepository;
use App\Pasta\Service\EnvioAutomaticoAoCliente;
use App\Pasta\Service\GruposDoEnvioAutomatico;
use App\Pasta\Service\TextoDoEnvioAutomatico;

/**
 * "Novas movimentações" e "Audiências e intimações" do envio automático ao cliente (spec
 * push-envio-automatico §3), ao fim de cada captação do DJEN, no mesmo ponto único do aviso do P4b.
 *
 * História: o escritório ligou, para um cliente, o aviso por e-mail do que chega no processo dele; o
 * cliente quer saber da movimentação (com o texto oficial) sem precisar ligar para o escritório. Quem
 * dispara é a captação (comando diário ou "Buscar agora"), sem sessão: o escritório é explícito.
 *
 *   1. as publicações NOVAS desta captação vinculadas a processo do escritório, agrupadas por pasta
 *      viva, com o cliente principal da pasta e as chaves dele (padrão: desligadas, D1);
 *   2. por pasta com alguma das duas chaves ligada: "Novas movimentações" leva todas; só "Audiências e
 *      intimações" leva as intimações e as designações de audiência. As duas ligadas: cada
 *      movimentação sai uma vez só;
 *   3. não repetir: sai fora a publicação já enviada a este cliente (histórico `auto` com a publicação
 *      em `referencia`, ou outra pasta do mesmo cliente nesta execução);
 *   4. um e-mail por e-mail ativo do cliente, com as movimentações da pasta (tipo, data, órgão e o
 *      texto oficial até 4.000 caracteres; segredo de justiça pela D4), cópia da Controladoria na
 *      primeira mensagem e uma linha no histórico por movimentação;
 *   5. sem Controladoria: envia e avisa os primários UMA vez nesta execução.
 * Falha de envio vai ao log e não derruba nada (o envio de cada destinatário é independente).
 */
final class EnviarMovimentacoesAoClienteUseCase
{
    public function __construct(
        private readonly PublicacaoDjenRepository $publicacoes,
        private readonly GruposDoEnvioAutomatico $grupos,
        private readonly EnvioAoClienteRepository $envios,
        private readonly EnvioAutomaticoAoCliente $envio,
    ) {
    }

    /**
     * @param list<string> $djenIdsNovos as publicações que esta captação criou
     *
     * @return int quantas pastas tiveram e-mail enviado ao cliente
     */
    public function executar(Tenant $tenant, array $djenIdsNovos): int
    {
        $grupos = $this->grupos->agrupar($tenant, $this->publicacoes->listarVinculadasPorDjenIdsDoTenant($tenant, $djenIdsNovos));
        $cc = null;
        $jaNestaExecucao = [];
        $enviadas = [];
        foreach ($grupos as $grupo) {
            $clienteId = (int) $grupo['cliente']->getId();
            $movimentacoes = $this->aEnviar($tenant, $grupo['cliente'], $grupo['chaves'], $grupo['publicacoes'], $jaNestaExecucao[$clienteId] ?? []);
            if ($movimentacoes === []) {
                continue;
            }
            $jaNestaExecucao[$clienteId] = array_merge($jaNestaExecucao[$clienteId] ?? [], array_map(static fn (MovimentacaoAoClienteOutput $m): int => $m->publicacaoId, $movimentacoes));
            $cc ??= $this->envio->copiaDaControladoria($tenant);
            if ($this->enviarDaPasta($tenant, $grupo['pasta'], $grupo['cliente'], $movimentacoes, $cc)) {
                $enviadas[] = $grupo['pasta'];
            }
        }
        if ($enviadas !== [] && $cc === []) {
            $this->envio->avisarPrimariosSemControladoria($tenant, $enviadas[0], \count($enviadas));
        }

        return \count($enviadas);
    }

    /**
     * As movimentações da pasta que este cliente deve receber agora, pelas chaves dele e sem repetir.
     *
     * @param non-empty-list<PublicacaoDjen> $publicacoes
     * @param list<int>                      $jaNestaExecucao
     *
     * @return list<MovimentacaoAoClienteOutput>
     */
    private function aEnviar(Tenant $tenant, Cliente $cliente, ChavesDoEnvioAutomatico $chaves, array $publicacoes, array $jaNestaExecucao): array
    {
        if (!$chaves->avisaNaCaptacao()) {
            return [];
        }

        $movimentacoes = array_values(array_filter(
            array_map(static fn (PublicacaoDjen $p): MovimentacaoAoClienteOutput => MovimentacaoAoClienteOutput::dePublicacao($p), $publicacoes),
            static fn (MovimentacaoAoClienteOutput $m): bool => $chaves->novasMovimentacoes || $m->natureza !== null,
        ));
        $ids = array_map(static fn (MovimentacaoAoClienteOutput $m): int => $m->publicacaoId, $movimentacoes);
        $jaEnviadas = array_merge($jaNestaExecucao, $this->envios->publicacoesJaEnviadasAoCliente($tenant, $cliente, $ids));

        return array_values(array_filter($movimentacoes, static fn (MovimentacaoAoClienteOutput $m): bool => !\in_array($m->publicacaoId, $jaEnviadas, true)));
    }

    /**
     * @param non-empty-list<MovimentacaoAoClienteOutput> $movimentacoes
     * @param list<string>                                $cc
     */
    private function enviarDaPasta(Tenant $tenant, Pasta $pasta, Cliente $cliente, array $movimentacoes, array $cc): bool
    {
        $destinatarios = $this->envio->destinatarios($pasta, $tenant);
        if ($destinatarios === []) {
            return false;
        }

        $pedido = new PedidoDeEnvioAutomatico(
            $tenant,
            $pasta,
            $cliente,
            TextoDoEnvioAutomatico::assuntoDasNovas($movimentacoes),
            TextoDoEnvioAutomatico::html($cliente->getNomeExibicao(), TextoDoEnvioAutomatico::introducaoDasNovas(), $movimentacoes),
            $cc,
            array_map(static fn (MovimentacaoAoClienteOutput $m): array => ['descricao' => $m->descricao(), 'referencia' => $m->publicacaoId], $movimentacoes),
        );

        return $this->envio->enviar($pedido, $destinatarios) !== [];
    }
}
