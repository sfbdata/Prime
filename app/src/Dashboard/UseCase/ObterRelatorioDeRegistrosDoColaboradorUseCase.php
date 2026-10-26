<?php

declare(strict_types=1);

namespace App\Dashboard\UseCase;

use App\Dashboard\DTO\RelatorioDoColaboradorOutput;
use App\Dashboard\Exception\ColaboradorForaDoEscritorioException;
use App\Dashboard\Periodo\PeriodoDoDashboard;
use App\Dashboard\Preferencia\ColunasExtrasDoDashboard as Extras;
use App\Dashboard\Repository\DashboardZeramentoRepository;
use App\Dashboard\Repository\MetricasExtrasDoDashboardRepository;
use App\Dashboard\Zeramento\CortesDoDashboard;
use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Repository\UserRepository;

/**
 * "Ver relatório de X" (spec dashboard-parte-1, PR-D): os registros que o número da coluna Relatório
 * conta, um a um. Usa o MESMO critério da contagem (RegistroDePastaNoPeriodo, via
 * MetricasExtrasDoDashboardRepository::listarRegistrosDoAutor), o período do painel e, se a célula
 * tem "Zerar" ativo, o mesmo corte; por isso `total` = número da célula (regra B12).
 *
 * A pessoa tem de ser colaboradora ATIVA do escritório corrente: fora disso,
 * ColaboradorForaDoEscritorioException (IDOR: o id chega pela URL).
 */
final class ObterRelatorioDeRegistrosDoColaboradorUseCase
{
    /** Teto de linhas devolvidas; o resto vira "e mais N" e continua somando no total. */
    public const LIMITE_DE_LINHAS = 200;

    private const TRECHO_MAXIMO = 120;

    public function __construct(
        private readonly UserRepository $userRepository,
        private readonly MetricasExtrasDoDashboardRepository $metricasExtras,
        private readonly DashboardZeramentoRepository $zeramentos,
    ) {
    }

    /**
     * @param array<string, mixed> $filtrosDoPainel data_de e data_ate do painel
     * @param list<int>            $pastasOcultas   pastas ocultas pelo cadeado para quem olha
     */
    public function executar(Tenant $tenant, int $userId, array $filtrosDoPainel, array $pastasOcultas = []): RelatorioDoColaboradorOutput
    {
        $pessoa = $this->colaborador($tenant, $userId);
        $cortes = CortesDoDashboard::deZeramentos($this->zeramentos->listarAtivos($tenant));
        $corte  = $cortes->corte(Extras::RELATORIO, $userId);

        $filtros = PeriodoDoDashboard::datasDoPainel($filtrosDoPainel);
        if ($corte !== null) {
            $filtros['contar_desde'] = $corte;
        }

        $lista = $this->metricasExtras->listarRegistrosDoAutor($tenant, $userId, $filtros, $pastasOcultas, self::LIMITE_DE_LINHAS);

        $registros = $lista['registros'];
        usort($registros, static fn (array $a, array $b): int => [$b['criadaEm'], $b['id']] <=> [$a['criadaEm'], $a['id']]);
        $registros = array_slice($registros, 0, self::LIMITE_DE_LINHAS);

        return new RelatorioDoColaboradorOutput(
            userId:    $userId,
            nome:      $pessoa->getFullName(),
            total:     $lista['total'],
            restantes: max(0, $lista['total'] - count($registros)),
            grupos:    self::agruparPorPasta($registros),
            corte:     $corte?->format(\DateTimeInterface::ATOM),
        );
    }

    /**
     * @param list<array{id: int, aba: string, pastaId: int, pastaNup: ?string, cliente: ?string, criadaEm: \DateTimeImmutable, conteudo: string}> $registros do mais novo ao mais antigo
     *
     * @return list<array{pastaId: int, titulo: string, cliente: ?string, itens: list<array{id: int, aba: string, data: string, hora: string, trecho: string}>}>
     */
    private static function agruparPorPasta(array $registros): array
    {
        $grupos = [];
        foreach ($registros as $r) {
            $grupos[$r['pastaId']] ??= [
                'pastaId' => $r['pastaId'],
                'titulo'  => $r['pastaNup'] ?? '',
                'cliente' => $r['cliente'],
                'itens'   => [],
            ];
            $grupos[$r['pastaId']]['itens'][] = [
                'id'     => $r['id'],
                'aba'    => $r['aba'],
                'data'   => $r['criadaEm']->format('d/m/Y'),
                'hora'   => $r['criadaEm']->format('H:i'),
                'trecho' => self::trecho($r['conteudo']),
            ];
        }

        return array_values($grupos);
    }

    /** Texto puro e curto: sem HTML do usuário (tags e entidades saem), espaços colapsados, truncado. */
    public static function trecho(string $conteudo): string
    {
        $texto = html_entity_decode(strip_tags(str_replace(['</p>', '<br>', '<br/>', '<br />', '</li>'], ' ', $conteudo)), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $texto = trim((string) preg_replace('/\s+/u', ' ', $texto));

        return mb_strlen($texto) > self::TRECHO_MAXIMO ? rtrim(mb_substr($texto, 0, self::TRECHO_MAXIMO)) . '…' : $texto;
    }

    private function colaborador(Tenant $tenant, int $userId): User
    {
        foreach ($this->userRepository->findColaboradoresAtivosPorTenant($tenant) as $u) {
            if ($u->getId() === $userId) {
                return $u;
            }
        }

        throw new ColaboradorForaDoEscritorioException('Colaborador não encontrado neste escritório.');
    }
}
