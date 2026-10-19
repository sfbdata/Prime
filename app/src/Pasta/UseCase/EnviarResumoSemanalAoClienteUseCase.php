<?php

declare(strict_types=1);

namespace App\Pasta\UseCase;

use App\Cliente\Entity\Cliente;
use App\Djen\Entity\PublicacaoDjen;
use App\Djen\Repository\PublicacaoDjenRepository;
use App\Entity\Tenant\Tenant;
use App\Pasta\DTO\MovimentacaoAoClienteOutput;
use App\Pasta\DTO\PedidoDeEnvioAutomatico;
use App\Pasta\DTO\ResultadoDoResumoSemanal;
use App\Pasta\Entity\Pasta;
use App\Pasta\Repository\EnvioAoClienteRepository;
use App\Pasta\Service\EnvioAutomaticoAoCliente;
use App\Pasta\Service\GruposDoEnvioAutomatico;
use App\Pasta\Service\TextoDoEnvioAutomatico;
use Psr\Clock\ClockInterface;

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
 *   3. pasta sem movimentação na semana não recebe nada; pasta cujo resumo desta semana já saiu
 *      (histórico `auto` com a mesma descrição) não recebe de novo;
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
    ) {
    }

    public function executar(Tenant $tenant, bool $simular = false): ResultadoDoResumoSemanal
    {
        [$inicio, $fim] = self::semanaAnterior($this->relogio->now());
        $descricao = 'resumo semanal de ' . $inicio->format('d/m') . ' a ' . $fim->format('d/m');
        $cc = null;
        $enviadas = [];
        $emails = 0;
        foreach ($this->grupos->agrupar($tenant, $this->publicacoes->listarVinculadasDoPeriodoDoTenant($tenant, $inicio, $fim)) as $grupo) {
            $destinatarios = $this->destinatariosDoResumo($tenant, $grupo['pasta'], $grupo['chaves']->resumoSemanal, $descricao, $inicio);
            if ($destinatarios === []) {
                continue;
            }
            $cc ??= $this->envio->copiaDaControladoria($tenant);
            $saiu = $simular ? $destinatarios : $this->enviar($tenant, $grupo['pasta'], $grupo['cliente'], $grupo['publicacoes'], $inicio, $fim, $descricao, $cc, $destinatarios);
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

    /** @return list<string> a quem o resumo desta pasta vai (nada se a chave está desligada ou já saiu) */
    private function destinatariosDoResumo(Tenant $tenant, Pasta $pasta, bool $ligado, string $descricao, \DateTimeImmutable $inicio): array
    {
        if (!$ligado || $this->envios->existeEnvioAutomaticoDaPasta($tenant, $pasta, $descricao, $inicio)) {
            return [];
        }

        return $this->envio->destinatarios($pasta, $tenant);
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
