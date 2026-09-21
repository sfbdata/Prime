<?php

declare(strict_types=1);

namespace App\Tests\Ponto\Unit;

use App\Entity\Auth\User;
use App\Ponto\Entity\JornadaColaborador;
use App\Ponto\Entity\RegistroPonto;
use App\Ponto\Repository\JustificativaPontoRepository;
use App\Ponto\Repository\LancamentoHorasPagasRepository;
use App\Ponto\Repository\RegistroPontoRepository;
use App\Ponto\Service\BatidasEscolhidas;
use App\Ponto\Service\CalculadoraJornada;
use App\Ponto\Service\EscolhaDasBatidasDoDia;
use App\Ponto\Service\FolhaPontoBuilder;
use App\Ponto\Service\JornadaResolver;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Os 64 padrões de batida da produção em que a escolha da folha poderia mudar o resultado, e o que
 * a folha mostra para cada um HOJE. É a prova permanente de que mudar a regra de escolha das batidas
 * não recalcula o passado (decisão do dono de 21/09/2026, `docs/specs/ponto-folha-uma-batida-por-tipo.md`
 * §7.1 e §9.4).
 *
 * Origem: produção, 01/04 a 21/09/2026. São os dias com tipo repetido ou com retorno antes do repouso;
 * nos outros 819 dias a escolha não tem o que decidir. Sem identificação: só tipos e horários, sem
 * usuário e sem a data real (todos entram num mesmo dia passado, porque minuto trabalhado não depende
 * do dia da semana).
 *
 * Os valores esperados foram medidos com o `buildRows` real antes de qualquer mudança. Se este teste
 * cair, a regra mudou o resultado de um dia que já aconteceu — e isso exige decisão humana, não ajuste
 * de expectativa.
 */
#[CoversClass(FolhaPontoBuilder::class)]
final class FolhaPontoPadroesHistoricosTest extends TestCase
{
    /** Um dia passado qualquer: a folha não apura dia futuro. */
    private const DIA = '2026-04-15';

    /**
     * @return array<string, array{0: list<string>, 1: int, 2: string, 3: string, 4: string, 5: string, 6: ?int, 7: bool}>
     *         [batidas "tipo@HH:MM:SS", minutos, entrada, repouso, retorno, saída exibidos, minutos de intervalo,
     *         registro incompleto]
     */
    public static function padroes(): array
    {
        return [
            'padrão 01' => [['entrada@10:14:21', 'repouso@12:43:00', 'repouso@12:43:24', 'retorno@13:57:51', 'saida@18:09:26', 'saida@18:09:36'], 399, '10:14:21', '12:43:00', '13:57:51', '18:09:36', 74, false],
            'padrão 02' => [['entrada@08:56:13', 'entrada@08:56:41', 'repouso@12:19:30', 'retorno@13:19:00', 'saida@18:07:01'], 491, '08:56:13', '12:19:30', '13:19:00', '18:07:01', 59, false],
            'padrão 03' => [['entrada@07:30:00', 'repouso@12:00:00', 'retorno@13:00:00', 'entrada@14:51:09', 'saida@17:09:13'], 519, '07:30:00', '12:00:00', '13:00:00', '17:09:13', 60, false],
            'padrão 04' => [['entrada@07:45:29', 'repouso@12:00:00', 'retorno@13:00:00', 'entrada@13:11:29', 'saida@17:55:34'], 549, '07:45:29', '12:00:00', '13:00:00', '17:55:34', 60, false],
            'padrão 05' => [['entrada@08:04:42', 'entrada@08:05:00', 'entrada@08:08:14', 'repouso@12:07:45', 'retorno@13:07:34', 'saida@18:28:40'], 564, '08:04:42', '12:07:45', '13:07:34', '18:28:40', 59, false],
            'padrão 06' => [['entrada@09:36:17', 'repouso@13:52:29', 'retorno@14:52:42', 'retorno@19:32:26', 'saida@19:32:38'], 535, '09:36:17', '13:52:29', '14:52:42', '19:32:38', 60, false],
            'padrão 07' => [['entrada@07:30:39', 'repouso@12:12:51', 'repouso@12:13:35', 'retorno@13:12:59', 'saida@16:27:00'], 476, '07:30:39', '12:12:51', '13:12:59', '16:27:00', 60, false],
            'padrão 08' => [['entrada@07:41:53', 'repouso@12:14:35', 'repouso@12:14:56', 'repouso@12:15:44', 'repouso@12:16:49', 'retorno@13:14:46', 'saida@17:43:09'], 540, '07:41:53', '12:14:35', '13:14:46', '17:43:09', 60, false],
            'padrão 09' => [['entrada@08:12:07', 'repouso@13:00:00', 'entrada@13:38:55', 'retorno@14:00:00', 'saida@21:37:36'], 744, '08:12:07', '13:00:00', '14:00:00', '21:37:36', 60, false],
            'padrão 10' => [['entrada@08:18:39', 'entrada@14:35:40', 'repouso@14:36:07', 'retorno@15:39:50', 'saida@18:03:17'], 520, '08:18:39', '14:36:07', '15:39:50', '18:03:17', 63, false],
            'padrão 11' => [['entrada@08:30:00', 'repouso@12:02:00', 'retorno@13:03:00', 'saida@19:50:20', 'saida@19:50:38'], 619, '08:30:00', '12:02:00', '13:03:00', '19:50:38', 61, false],
            'padrão 12' => [['entrada@09:03:00', 'repouso@12:03:30', 'retorno@13:02:00', 'repouso@13:04:03', 'saida@19:27:07'], 565, '09:03:00', '12:03:30', '13:02:00', '19:27:07', 58, false],
            'padrão 13 — saída repetida em 68 s' => [['entrada@07:10:00', 'repouso@12:01:00', 'retorno@13:03:42', 'saida@17:18:53', 'saida@17:20:01'], 547, '07:10:00', '12:01:00', '13:03:42', '17:20:01', 62, false],
            'padrão 14' => [['entrada@13:00:00', 'entrada@17:48:08', 'saida@18:00:00'], 300, '13:00:00', '', '', '18:00:00', null, false],
            'padrão 15' => [['entrada@09:03:05', 'entrada@12:00:04', 'repouso@12:00:17', 'retorno@13:00:38', 'saida@19:25:00'], 561, '09:03:05', '12:00:17', '13:00:38', '19:25:00', 60, false],
            'padrão 16' => [['entrada@13:17:37', 'saida@18:15:56', 'saida@18:16:21'], 298, '13:17:37', '', '', '18:16:21', null, false],
            'padrão 17' => [['entrada@09:06:00', 'entrada@10:22:27', 'repouso@12:00:08', 'retorno@13:09:42', 'saida@19:44:14'], 568, '09:06:00', '12:00:08', '13:09:42', '19:44:14', 69, false],
            'padrão 18' => [['entrada@07:53:46', 'saida@13:29:45', 'saida@18:00:00'], 606, '07:53:46', '', '', '18:00:00', null, false],
            'padrão 19' => [['entrada@08:00:00', 'entrada@08:27:29', 'repouso@13:34:08', 'retorno@15:00:05', 'saida@20:44:00'], 677, '08:00:00', '13:34:08', '15:00:05', '20:44:00', 85, false],
            'padrão 20' => [['entrada@09:18:06', 'entrada@12:20:27', 'repouso@12:22:00', 'retorno@13:57:19', 'saida@20:19:10'], 564, '09:18:06', '12:22:00', '13:57:19', '20:19:10', 95, false],
            'padrão 21' => [['entrada@07:28:49', 'entrada@12:12:52', 'repouso@12:13:48', 'retorno@13:13:26', 'saida@17:34:42'], 545, '07:28:49', '12:13:48', '13:13:26', '17:34:42', 59, false],
            'padrão 22' => [['entrada@08:47:58', 'entrada@12:23:00', 'repouso@12:23:00', 'retorno@13:23:00', 'saida@18:28:51'], 520, '08:47:58', '12:23:00', '13:23:00', '18:28:51', 60, false],
            'padrão 23' => [['entrada@08:55:21', 'repouso@12:36:00', 'retorno@13:36:00', 'retorno@13:36:24', 'saida@18:57:58'], 541, '08:55:21', '12:36:00', '13:36:00', '18:57:58', 60, false],
            'padrão 24' => [['entrada@07:38:18', 'entrada@13:16:38', 'saida@19:21:00'], 702, '07:38:18', '', '', '19:21:00', null, false],
            'padrão 25 — dois repousos a 62 min um do outro (o caso ambíguo)' => [['entrada@08:32:00', 'repouso@12:28:42', 'repouso@13:30:55', 'retorno@14:56:00', 'saida@19:20:00'], 500, '08:32:00', '12:28:42', '14:56:00', '19:20:00', 147, false],
            'padrão 26' => [['entrada@09:00:04', 'repouso@12:02:32', 'retorno@13:08:24', 'retorno@18:08:36', 'saida@18:09:00'], 482, '09:00:04', '12:02:32', '13:08:24', '18:09:00', 65, false],
            'padrão 27' => [['entrada@07:41:04', 'repouso@12:25:40', 'retorno@13:34:14', 'retorno@13:35:09', 'saida@17:51:36'], 541, '07:41:04', '12:25:40', '13:34:14', '17:51:36', 68, false],
            'padrão 28' => [['entrada@09:52:23', 'entrada@09:52:30', 'repouso@13:10:31', 'retorno@14:10:22', 'saida@19:15:00'], 502, '09:52:23', '13:10:31', '14:10:22', '19:15:00', 59, false],
            'padrão 29' => [['entrada@07:57:27', 'repouso@12:02:03', 'repouso@12:02:20', 'retorno@13:11:49', 'saida@17:40:05'], 512, '07:57:27', '12:02:03', '13:11:49', '17:40:05', 69, false],
            'padrão 30' => [['entrada@08:22:37', 'repouso@09:32:34', 'retorno@11:26:03', 'retorno@11:26:23', 'saida@16:56:08'], 399, '08:22:37', '09:32:34', '11:26:03', '16:56:08', 113, false],
            'padrão 31' => [['entrada@08:53:00', 'entrada@12:05:45', 'repouso@12:06:00', 'retorno@13:12:41', 'saida@17:45:51'], 466, '08:53:00', '12:06:00', '13:12:41', '17:45:51', 66, false],
            'padrão 32' => [['entrada@07:10:00', 'repouso@12:26:01', 'repouso@12:26:50', 'repouso@12:31:42', 'saida@17:38:58'], 628, '07:10:00', '12:26:01', '', '17:38:58', null, true],
            'padrão 33' => [['entrada@09:13:29', 'repouso@13:36:55', 'retorno@14:36:00', 'retorno@14:36:15', 'saida@19:20:44'], 547, '09:13:29', '13:36:55', '14:36:00', '19:20:44', 59, false],
            'padrão 34' => [['entrada@07:39:18', 'repouso@12:13:05', 'retorno@13:13:30', 'retorno@17:18:24', 'saida@17:18:48'], 518, '07:39:18', '12:13:05', '13:13:30', '17:18:48', 60, false],
            'padrão 35' => [['entrada@09:06:02', 'entrada@12:00:34', 'repouso@12:01:13', 'retorno@13:10:24', 'saida@19:03:31'], 528, '09:06:02', '12:01:13', '13:10:24', '19:03:31', 69, false],
            'padrão 36' => [['entrada@09:43:26', 'repouso@13:20:27', 'retorno@14:20:29', 'retorno@14:20:34', 'saida@18:07:12'], 443, '09:43:26', '13:20:27', '14:20:29', '18:07:12', 60, false],
            'padrão 37' => [['entrada@07:28:56', 'repouso@12:42:42', 'retorno@14:12:28', 'saida@23:27:35', 'saida@23:57:14'], 897, '07:28:56', '12:42:42', '14:12:28', '23:57:14', 89, false],
            'padrão 38' => [['entrada@09:13:24', 'repouso@12:03:04', 'repouso@12:03:34', 'retorno@13:04:05', 'saida@19:17:46'], 542, '09:13:24', '12:03:04', '13:04:05', '19:17:46', 61, false],
            'padrão 39' => [['entrada@07:17:57', 'repouso@12:50:28', 'retorno@13:50:24', 'retorno@17:12:23', 'saida@17:30:00'], 551, '07:17:57', '12:50:28', '13:50:24', '17:30:00', 59, false],
            'padrão 40' => [['entrada@07:08:42', 'repouso@12:49:41', 'repouso@12:50:02', 'retorno@13:50:19', 'saida@17:00:54'], 530, '07:08:42', '12:49:41', '13:50:19', '17:00:54', 60, false],
            'padrão 41' => [['entrada@08:52:48', 'repouso@13:55:56', 'retorno@14:56:12', 'saida@15:10:12', 'saida@17:00:00'], 426, '08:52:48', '13:55:56', '14:56:12', '17:00:00', 60, false],
            'padrão 42' => [['entrada@08:23:00', 'repouso@14:17:32', 'retorno@19:42:58', 'saida@23:03:22', 'saida@23:03:41'], 554, '08:23:00', '14:17:32', '19:42:58', '23:03:41', 325, false],
            'padrão 43' => [['entrada@09:15:30', 'entrada@14:12:38', 'repouso@14:19:12', 'retorno@15:19:09', 'saida@18:43:46'], 507, '09:15:30', '14:19:12', '15:19:09', '18:43:46', 59, false],
            'padrão 44 — retorno às 04:02, antes da entrada e do repouso' => [['retorno@04:02:00', 'entrada@09:32:26', 'repouso@13:00:00', 'saida@19:20:00'], 587, '09:32:26', '13:00:00', '04:02:00', '19:20:00', 538, false],
            'padrão 45' => [['entrada@09:01:30', 'repouso@12:43:50', 'retorno@13:53:00', 'repouso@13:55:54', 'saida@18:35:00'], 504, '09:01:30', '12:43:50', '13:53:00', '18:35:00', 69, false],
            'padrão 46' => [['entrada@08:25:20', 'entrada@08:25:54', 'repouso@13:04:44', 'retorno@14:05:03', 'saida@17:51:19'], 505, '08:25:20', '13:04:44', '14:05:03', '17:51:19', 60, false],
            'padrão 47' => [['repouso@12:48:34', 'retorno@13:51:27', 'retorno@13:55:10', 'saida@17:07:52'], 0, '', '12:48:34', '13:51:27', '17:07:52', 62, true],
            'padrão 48' => [['entrada@09:25:35', 'repouso@14:25:57', 'retorno@15:28:29', 'saida@18:48:00', 'saida@18:48:13'], 499, '09:25:35', '14:25:57', '15:28:29', '18:48:13', 62, false],
            'padrão 49' => [['entrada@08:22:21', 'repouso@13:30:03', 'retorno@14:30:00', 'saida@17:01:17', 'saida@17:01:29'], 458, '08:22:21', '13:30:03', '14:30:00', '17:01:29', 59, false],
            'padrão 50' => [['entrada@09:04:51', 'repouso@12:00:33', 'retorno@13:06:01', 'retorno@18:51:03', 'saida@18:51:12'], 520, '09:04:51', '12:00:33', '13:06:01', '18:51:12', 65, false],
            'padrão 51' => [['entrada@08:34:59', 'repouso@13:28:00', 'retorno@15:02:18', 'retorno@15:02:29', 'saida@18:50:56'], 521, '08:34:59', '13:28:00', '15:02:18', '18:50:56', 94, false],
            'padrão 52 — repouso da aprovação 12:00 e repouso GPS 12:05:04 (304 s)' => [['entrada@08:39:21', 'repouso@12:00:00', 'repouso@12:05:04', 'retorno@13:06:00', 'saida@17:42:06'], 476, '08:39:21', '12:00:00', '13:06:00', '17:42:06', 66, false],
            'padrão 53' => [['entrada@08:35:21', 'retorno@12:15:37', 'repouso@12:16:03', 'repouso@12:16:22', 'repouso@12:26:04', 'saida@17:03:08'], 507, '08:35:21', '12:16:03', '12:15:37', '17:03:08', 0, false],
            'padrão 54' => [['entrada@09:05:53', 'repouso@12:04:43', 'repouso@12:05:02', 'retorno@13:08:53', 'saida@19:08:00'], 537, '09:05:53', '12:04:43', '13:08:53', '19:08:00', 64, false],
            'padrão 55' => [['entrada@07:24:53', 'repouso@12:07:04', 'retorno@13:05:00', 'retorno@13:06:58', 'saida@16:19:54', 'saida@17:10:00'], 527, '07:24:53', '12:07:04', '13:05:00', '17:10:00', 57, false],
            'padrão 56' => [['entrada@07:18:59', 'repouso@13:18:31', 'repouso@13:18:50', 'repouso@13:19:03', 'retorno@14:18:00', 'saida@18:06:33', 'saida@18:06:58'], 587, '07:18:59', '13:18:31', '14:18:00', '18:06:58', 59, false],
            'padrão 57' => [['entrada@08:29:05', 'repouso@12:42:11', 'retorno@13:40:00', 'retorno@13:45:42', 'saida@17:10:00'], 463, '08:29:05', '12:42:11', '13:40:00', '17:10:00', 57, false],
            'padrão 58' => [['entrada@07:04:00', 'entrada@07:04:12', 'repouso@12:46:07', 'retorno@13:58:30', 'saida@18:22:59'], 606, '07:04:00', '12:46:07', '13:58:30', '18:22:59', 72, false],
            'padrão 59' => [['entrada@09:07:26', 'repouso@12:01:53', 'retorno@13:09:42', 'retorno@13:09:48', 'saida@19:41:24'], 565, '09:07:26', '12:01:53', '13:09:42', '19:41:24', 67, false],
            'padrão 60' => [['entrada@09:19:58', 'entrada@13:51:00', 'repouso@13:51:38'], 0, '09:19:58', '13:51:38', '', '', null, true],
            'padrão 61 — saída repetida em 67 s' => [['entrada@08:33:23', 'repouso@11:56:40', 'retorno@12:58:00', 'saida@16:53:01', 'saida@16:54:08'], 439, '08:33:23', '11:56:40', '12:58:00', '16:54:08', 61, false],
            'padrão 62' => [['entrada@08:19:10', 'entrada@12:10:47', 'repouso@12:17:00', 'retorno@13:18:33', 'saida@17:12:01'], 470, '08:19:10', '12:17:00', '13:18:33', '17:12:01', 61, false],
            'padrão 63' => [['entrada@08:37:01', 'entrada@11:55:40', 'repouso@12:00:00', 'retorno@13:00:00', 'saida@17:46:00'], 488, '08:37:01', '12:00:00', '13:00:00', '17:46:00', 60, false],
            'padrão 64' => [['entrada@08:59:46', 'repouso@12:01:25', 'entrada@13:09:55', 'retorno@13:10:02', 'saida@19:16:05'], 547, '08:59:46', '12:01:25', '13:10:02', '19:16:05', 68, false],
        ];
    }

    /**
     * @param list<string> $batidas
     */
    #[DataProvider('padroes')]
    public function testPadraoHistoricoMantemOResultadoDaFolha(
        array $batidas,
        int $minutos,
        string $entrada,
        string $repouso,
        string $retorno,
        string $saida,
        ?int $intervalo,
        bool $incompleto,
    ): void {
        $this->assertLinhaIgual($this->linhaDaFolha($batidas, self::DIA), $minutos, $entrada, $repouso, $retorno, $saida, $intervalo, $incompleto);
    }

    /**
     * O mesmo padrão apurado pela regra ÚNICA (vigência injetada antes do dia) dá exatamente o mesmo
     * resultado: a regra nova não reinterpreta nenhum dia que já existe.
     *
     * @param list<string> $batidas
     */
    #[DataProvider('padroes')]
    public function testPadraoHistoricoDaOMesmoResultadoPelaRegraUnica(
        array $batidas,
        int $minutos,
        string $entrada,
        string $repouso,
        string $retorno,
        string $saida,
        ?int $intervalo,
        bool $incompleto,
    ): void {
        $this->assertLinhaIgual(
            $this->linhaDaFolha($batidas, self::DIA, new EscolhaDasBatidasDoDia('2026-04-01')),
            $minutos, $entrada, $repouso, $retorno, $saida, $intervalo, $incompleto
        );
    }

    public function testOsDoisDiasAmbiguosDaProducaoSaoMarcadosNasDuasRegras(): void
    {
        $padroes = self::padroes();
        foreach ([null, new EscolhaDasBatidasDoDia('2026-04-01')] as $escolha) {
            foreach ([
                'padrão 25 — dois repousos a 62 min um do outro (o caso ambíguo)',
                'padrão 52 — repouso da aprovação 12:00 e repouso GPS 12:05:04 (304 s)',
            ] as $nome) {
                self::assertSame(
                    [BatidasEscolhidas::MARCA_REPOUSOS_DISTINTOS],
                    $this->linhaDaFolha($padroes[$nome][0], self::DIA, $escolha)['aConferir'],
                    $nome
                );
            }
            self::assertSame([], $this->linhaDaFolha($padroes['padrão 01'][0], self::DIA, $escolha)['aConferir'], 'repetição em 24 s não marca');
        }
    }

    private function assertLinhaIgual(
        array $linha,
        int $minutos,
        string $entrada,
        string $repouso,
        string $retorno,
        string $saida,
        ?int $intervalo,
        bool $incompleto,
    ): void {
        self::assertSame($minutos, $linha['minutosTrabalhadosDia'], 'minutos trabalhados do dia');
        self::assertSame(
            [$entrada, $repouso, $retorno, $saida],
            [$linha['entrada'], $linha['repouso'], $linha['retorno'], $linha['saida']],
            'batidas exibidas nas quatro células'
        );
        self::assertSame($intervalo, $linha['minutosIntervalo'], 'minutos de intervalo (indicador do PDF/XLSX)');
        self::assertSame($incompleto, $linha['registroIncompleto'], 'registro incompleto');
    }

    /**
     * @param list<string> $batidas
     * @return array<string, mixed>
     */
    private function linhaDaFolha(array $batidas, string $dia, ?EscolhaDasBatidasDoDia $escolha = null): array
    {
        $registros = [];
        foreach ($batidas as $marca) {
            [$tipo, $hora] = explode('@', $marca);
            $registro = new RegistroPonto();
            $registro->setTipo($tipo);
            $registro->setDataHora(new \DateTimeImmutable("{$dia} {$hora}"));
            $registros[] = $registro;
        }

        $user = new User();
        $user->setEmail('padroes@teste.com')->setFullName('Padrões');
        $jornada = new JornadaColaborador();
        $jornada->setUser($user);
        $jornada->setDiasSemana([]);

        $data = new \DateTimeImmutable($dia);

        return $this->builder($escolha)->buildRows($data, $data, $registros, true, false, $jornada, [], [], null, new \DateTimeImmutable('2020-01-01'))[0];
    }

    private function builder(?EscolhaDasBatidasDoDia $escolha): FolhaPontoBuilder
    {
        return new FolhaPontoBuilder(
            new CalculadoraJornada(new JornadaResolver(), $escolha ?? new EscolhaDasBatidasDoDia()),
            $this->createStub(RegistroPontoRepository::class),
            $this->createStub(JustificativaPontoRepository::class),
            $this->createStub(LancamentoHorasPagasRepository::class),
        );
    }
}
