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
use App\Pasta\DTO\ResultadoDoResumoSemanal;
use App\Pasta\Entity\Pasta;
use App\Pasta\Repository\EnvioAoClienteRepository;
use App\Pasta\Service\EnvioAutomaticoAoCliente;
use App\Pasta\Service\GruposDoEnvioAutomatico;
use App\Pasta\Service\TextoDoEnvioAutomatico;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;

/**
 * "Resumo semanal" do envio automático ao cliente (spec push-envio-automatico §3), pelo comando
 * `app:push:resumo-semanal-cliente` (cron da segunda-feira, um escritório por vez).
 *
 * História: o escritório ligou, para um cliente, o resumo semanal; toda segunda o cliente recebe, por
 * pasta, as movimentações da semana anterior, sem precisar perguntar.
 *
 *   1. a semana anterior é de segunda a domingo, contada do relógio (rodar na quarta manda a mesma
 *      semana que a segunda mandaria);
 *   2. as publicações do escritório vinculadas a processo e disponibilizadas nessa semana, agrupadas
 *      por pasta viva, com o cliente principal e as chaves dele (padrão: desligadas, D1);
 *   3. pasta sem movimentação na semana não recebe nada; cada movimentação aparece uma vez por cliente
 *      (o mesmo processo em duas pastas dele); quem já recebeu o resumo desta semana da pasta
 *      (histórico `auto` com a mesma descrição) não recebe de novo; bloqueios da D7 (processo pausado
 *      ou baixado, pasta não ativa ou trancada);
 *   4. um e-mail por e-mail ativo do cliente, cópia da Controladoria na primeira, uma linha no
 *      histórico ("resumo semanal de dd/mm a dd/mm"); segredo de justiça pela D4;
 *   5. sem Controladoria: envia e avisa os primários UMA vez nesta execução.
 * Na simulação, conta as pastas e os e-mails e não envia nem grava nada.
 */
final class EnviarResumoSemanalAoClienteUseCase
{
    /** O resumo leva o texto de cada movimentação até este tamanho (o e-mail junta a semana inteira). */
    public const LIMITE_DO_TEXTO = 600;

    public function __construct(
        private readonly PublicacaoDjenRepository $publicacoes,
        private readonly GruposDoEnvioAutomatico $grupos,
        private readonly EnvioAoClienteRepository $envios,
        private readonly EnvioAutomaticoAoCliente $envio,
        private readonly ClockInterface $relogio,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function executar(Tenant $tenant, bool $simular = false): ResultadoDoResumoSemanal
    {
        [$inicio, $fim] = self::semanaAnterior($this->relogio->now());
        $descricao = 'resumo semanal de ' . $inicio->format('d/m') . ' a ' . $fim->format('d/m');
        $grupos = $this->grupos->agrupar($tenant, $this->publicacoes->listarVinculadasDoPeriodoDoTenant($tenant, $inicio, $fim));
        $cc = $grupos !== [] ? $this->envio->copiaDaControladoria($tenant) : [];
        $noResumo = [];
        $enviadas = [];
        $emails = 0;
        foreach ($grupos as $grupo) {
            $publicacoes = $this->publicacoesDoResumo($grupo, $noResumo);
            if ($publicacoes === []) {
                continue;
            }
            $saiu = $this->resumoDaPastaSemDerrubar($tenant, $grupo['pasta'], $grupo['cliente'], $publicacoes, [$inicio, $fim, $descricao], $cc, $simular);
            if ($saiu !== []) {
                $emails += \count($saiu);
                $enviadas[] = $grupo['pasta'];
            }
        }
        if (!$simular && $enviadas !== [] && $cc === []) {
            $this->envio->avisarPrimariosSemControladoria($tenant, $enviadas[0], \count($enviadas));
        }

        return new ResultadoDoResumoSemanal($inicio, $fim, \count($enviadas), $emails);
    }

    /**
     * A semana anterior à do instante, de segunda a domingo.
     *
     * @return array{0: \DateTimeImmutable, 1: \DateTimeImmutable}
     */
    public static function semanaAnterior(\DateTimeImmutable $agora): array
    {
        $segundaDestaSemana = $agora->setTime(0, 0)->modify('monday this week');

        return [$segundaDestaSemana->modify('-7 days'), $segundaDestaSemana->modify('-1 day')];
    }

    /**
     * As publicações desta pasta que entram no resumo: chave ligada e cada movimentação uma vez por
     * cliente (o mesmo processo em duas pastas do mesmo cliente não sai duas vezes). Marca as que
     * entram, mesmo que o resumo desta pasta já tenha saído antes (a ordem das pastas é estável).
     *
     * @param array{pasta: Pasta, cliente: Cliente, chaves: ChavesDoEnvioAutomatico, publicacoes: non-empty-list<PublicacaoDjen>} $grupo
     * @param array<int, list<int>>                                                                                               $noResumo publicações já no resumo, por cliente
     *
     * @return list<PublicacaoDjen>
     */
    private function publicacoesDoResumo(array $grupo, array &$noResumo): array
    {
        if (!$grupo['chaves']->resumoSemanal) {
            return [];
        }
        $clienteId = (int) $grupo['cliente']->getId();
        $publicacoes = array_values(array_filter($grupo['publicacoes'], static fn (PublicacaoDjen $p): bool => !\in_array((int) $p->getId(), $noResumo[$clienteId] ?? [], true)));
        $noResumo[$clienteId] = array_merge($noResumo[$clienteId] ?? [], array_map(static fn (PublicacaoDjen $p): int => (int) $p->getId(), $publicacoes));

        return $publicacoes;
    }

    /**
     * O resumo de uma pasta, só para quem ainda não recebeu o desta semana. Uma pasta que falha vai ao
     * log e não impede as outras do escritório.
     *
     * @param non-empty-list<PublicacaoDjen>                                  $publicacoes
     * @param array{0: \DateTimeImmutable, 1: \DateTimeImmutable, 2: string} $semana      início, fim e a descrição
     * @param list<string>                                                    $cc
     *
     * @return list<string> os que receberam (na simulação, os que receberiam)
     */
    private function resumoDaPastaSemDerrubar(Tenant $tenant, Pasta $pasta, Cliente $cliente, array $publicacoes, array $semana, array $cc, bool $simular): array
    {
        try {
            $jaRecebeu = $this->envios->destinatariosDoEnvioAutomaticoDaPasta($tenant, $pasta, $semana[2], $semana[0]);
            $destinatarios = array_values(array_diff($this->envio->destinatarios($pasta, $tenant), $jaRecebeu));
            if ($destinatarios === [] || $simular) {
                return $destinatarios;
            }

            return $this->enviar($tenant, $pasta, $cliente, $publicacoes, $semana[0], $semana[1], $semana[2], $cc, $destinatarios);
        } catch (\Throwable $e) {
            $this->logger->error('Resumo semanal ao cliente: falha na pasta {pasta} do tenant {tenant} ({classe}).', [
                'pasta' => $pasta->getId(), 'tenant' => $tenant->getId(), 'classe' => $e::class,
            ]);

            return [];
        }
    }

    /**
     * @param non-empty-list<PublicacaoDjen> $publicacoes
     * @param list<string>                   $cc
     * @param list<string>                   $destinatarios
     *
     * @return list<string> os que receberam
     */
    private function enviar(Tenant $tenant, Pasta $pasta, Cliente $cliente, array $publicacoes, \DateTimeImmutable $inicio, \DateTimeImmutable $fim, string $descricao, array $cc, array $destinatarios): array
    {
        $movimentacoes = array_map(static fn (PublicacaoDjen $p): MovimentacaoAoClienteOutput => MovimentacaoAoClienteOutput::dePublicacao($p, self::LIMITE_DO_TEXTO), $publicacoes);

        return $this->envio->enviar(new PedidoDeEnvioAutomatico(
            $tenant,
            $pasta,
            $cliente,
            TextoDoEnvioAutomatico::assuntoDoResumo($movimentacoes),
            TextoDoEnvioAutomatico::html($cliente->getNomeExibicao(), TextoDoEnvioAutomatico::introducaoDoResumo($inicio, $fim), $movimentacoes),
            $cc,
            [['descricao' => $descricao, 'referencia' => null]],
        ), $destinatarios);
    }
}
