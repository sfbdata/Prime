<?php

declare(strict_types=1);

namespace App\Dashboard\UseCase;

use App\Cobranca\Service\ResolvedorIdentificadorDaPasta;
use App\Dashboard\DTO\RelatorioDoColaboradorOutput;
use App\Dashboard\Exception\ColaboradorForaDoEscritorioException;
use App\Dashboard\Periodo\PeriodoDoDashboard;
use App\Dashboard\Preferencia\ColunasExtrasDoDashboard as Extras;
use App\Dashboard\Repository\DashboardZeramentoRepository;
use App\Dashboard\Repository\MetricasExtrasDoDashboardRepository;
use App\Dashboard\Zeramento\CortesDoDashboard;
use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Inteligencia\Service\VisibilidadeDoFinanceiroDaPasta;
use App\Pasta\Entity\AbaDaPasta;
use App\Pasta\Entity\Pasta;
use App\Pasta\Repository\PastaRepository;
use App\Pasta\Service\VisibilidadeDasAbasDaPasta;
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
        private readonly PastaRepository $pastaRepository,
        private readonly VisibilidadeDasAbasDaPasta $visibilidadeDasAbas,
        private readonly VisibilidadeDoFinanceiroDaPasta $visibilidadeDoFinanceiro,
        private readonly ResolvedorIdentificadorDaPasta $identificador,
    ) {
    }

    /** @var array<string, bool> pasta + aba => a pessoa logada vê a aba (uma decisão por pasta e aba) */
    private array $abaVisivel = [];

    /**
     * A CONTAGEM e a lista incluem as três abas (paridade com a célula); o TEXTO do registro só aparece onde
     * `$quemOlha` vê a aba daquela pasta. Onde não vê, a linha e o total ficam e o trecho vira `oculto`.
     *
     * @param array<string, mixed> $filtrosDoPainel data_de e data_ate do painel
     * @param list<int>            $pastasOcultas   pastas ocultas pelo cadeado para quem olha
     */
    public function executar(Tenant $tenant, int $userId, array $filtrosDoPainel, array $pastasOcultas, User $quemOlha): RelatorioDoColaboradorOutput
    {
        $this->abaVisivel = []; // a decisão vale só nesta execução (o serviço é compartilhado entre requisições)
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
            grupos:    $this->agruparPorPasta($registros, $quemOlha),
            corte:     $corte?->format(\DateTimeInterface::ATOM),
        );
    }

    /**
     * @param list<array{id: int, aba: string, pastaId: int, pastaNup: ?string, cliente: ?string, criadaEm: \DateTimeImmutable, conteudo: string}> $registros do mais novo ao mais antigo
     *
     * @return list<array{pastaId: int, titulo: string, cliente: ?string, ancora: string, itens: list<array{id: int, aba: string, data: string, hora: string, trecho: string, oculto: bool}>}>
     */
    private function agruparPorPasta(array $registros, User $quemOlha): array
    {
        $grupos = [];
        foreach ($registros as $r) {
            $visivel = $this->vePasta($quemOlha, $r['pastaId'], $r['aba']);
            $grupos[$r['pastaId']] ??= ['pastaId' => $r['pastaId'], 'titulo' => $r['pastaNup'] ?? '', 'cliente' => $this->nomeDaPasta($r['pastaId'], $r['cliente']), 'abas' => [], 'itens' => []];
            $grupos[$r['pastaId']]['abas'][$visivel ? $r['aba'] : '#'] = true;
            $grupos[$r['pastaId']]['itens'][] = [
                'id'     => $r['id'],
                'aba'    => $r['aba'],
                'data'   => $r['criadaEm']->format('d/m/Y'),
                'hora'   => $r['criadaEm']->format('H:i'),
                'trecho' => $visivel ? self::trecho($r['conteudo']) : '',
                'oculto' => !$visivel,
            ];
        }

        return array_values(array_map(static function (array $g): array {
            // Âncora da aba quando todos os itens são da mesma aba e ela é visível; senão Dados.
            $abas = array_keys($g['abas']);
            $g['ancora'] = count($abas) === 1 && $abas[0] !== '#' ? $abas[0] : 'dados';
            unset($g['abas']);

            return $g;
        }, $grupos));
    }

    /** A pessoa que olha vê esta aba nesta pasta? Decide uma vez por pasta e aba. Na dúvida (pasta não achada), não vê. */
    /** O nome que a tela mostra para a pasta (derivado da carteira ou cliente principal); o gravado é a reserva. */
    private function nomeDaPasta(int $pastaId, ?string $gravado): ?string
    {
        $pasta = $this->pastaRepository->find($pastaId);

        return $pasta instanceof Pasta ? ($this->identificador->para($pasta) ?? $gravado) : $gravado;
    }

    private function vePasta(User $quemOlha, int $pastaId, string $aba): bool
    {
        $chave = $pastaId . ':' . $aba;
        if (isset($this->abaVisivel[$chave])) {
            return $this->abaVisivel[$chave];
        }
        $pasta = $this->pastaRepository->find($pastaId);

        return $this->abaVisivel[$chave] = $pasta instanceof Pasta && $this->decidirAba($quemOlha, $pasta, $aba);
    }

    private function decidirAba(User $quemOlha, Pasta $pasta, string $aba): bool
    {
        $tenant = $pasta->getTenant();
        if ($aba === AbaDaPasta::Financeiro->value) {
            // A mesma pergunta da aba Financeiro da tela (permissão de ler a pasta + aba não oculta para a pessoa).
            return $tenant !== null && $this->visibilidadeDoFinanceiro->podeVer($quemOlha, $tenant, $pasta);
        }

        return !$this->visibilidadeDasAbas->ocultaPara($pasta, AbaDaPasta::from($aba), $quemOlha);
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
