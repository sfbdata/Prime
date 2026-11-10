<?php

declare(strict_types=1);

namespace App\Tests\Ponto\Functional;

use App\Controller\TenantController;
use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Ponto\Controller\PontoController;
use App\Ponto\Entity\LancamentoHorasPagas;
use App\Ponto\Entity\RegistroPonto;
use App\Tests\Functional\JusPrimeWebTestCase;
use App\Tests\Ponto\Support\DesenhoDaFolhaEspiao;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;

/**
 * E17, rodada 3: a folha usa a mesma jornada em toda parte (a da /ponto e do banco, `FolhaPontoBuilder::jornadaDaFolha`)
 * e o total "Saldo do mês" da /ponto é a conta do banco para a competência. Pessoa sem jornada pessoal, num escritório
 * sem jornada própria (vale a padrão, 8h de segunda a sexta): até aqui o PDF, o XLSX e a ficha não contavam dia nenhum,
 * e a /ponto deixava fora do total as faltas depois da última batida (o dia sem batida não aparece nela).
 *
 * O mês é o passado, inteiro: batidas no dia 1º e no dia 10, faltas nos dias úteis depois do dia 10.
 */
#[Group('ponto')]
#[CoversClass(PontoController::class)]
#[CoversClass(TenantController::class)]
final class FolhaMesmaJornadaEmTodaParteTest extends JusPrimeWebTestCase
{
    use CenarioDeJustificativaTrait;

    private KernelBrowser $client;
    private Tenant $escritorio;
    private User $eu;
    private User $gestor;
    private \DateTimeImmutable $mes;
    /** @var list<string> os dois dias com batida, 'AAAA-MM-DD' */
    private array $dias;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->client->disableReboot();
        $this->escritorio = $this->escritorio('Escritório Mesma Jornada');
        $this->eu = $this->pessoa('mesma_jornada');
        $this->vincularColaborador($this->eu, $this->escritorio);
        $this->gestor = $this->pessoa('gestor_mesma_jornada');
        $this->vincularPrimario($this->gestor, $this->escritorio);
        $this->mes = new \DateTimeImmutable('first day of last month midnight');
        $this->dias = [];
        foreach ([$this->mes, $this->mes->modify('+9 days')] as $dia) {
            $this->batida($dia->setTime(8, 0), RegistroPonto::TIPO_ENTRADA);
            $this->batida($dia->setTime(17, 0), RegistroPonto::TIPO_SAIDA);
            $this->dias[] = $dia->format('Y-m-d');
        }
        $this->horasPagas(60); // os totais do mês só aparecem na folha com horas pagas
    }

    #[TestDox('Sem jornada pessoal: mesmas linhas e mesmo total do mês na /ponto, na ficha do gestor e no XLSX')]
    public function testMesmasLinhasEMesmoTotal(): void
    {
        $this->logarComTenant($this->client, $this->eu, $this->escritorio);
        [$ponto, $totalPonto] = $this->folhaNaTela('/ponto/?competencia=' . $this->mes->format('Y-m'), '#ponto-regiao-folha');
        [$xlsx, $totalXlsx, $atualXlsx] = $this->xlsx();
        $this->logarComTenant($this->client, $this->gestor, $this->escritorio);
        [$ficha, $totalFicha] = $this->folhaNaTela(
            sprintf('/tenant/%d/user/%d/edit-role?tab=ponto&competencia=%s', $this->escritorio->getId(), $this->eu->getId(), $this->mes->format('Y-m')),
            '#tab-ponto',
        );

        self::assertSame($ponto, $ficha, 'as linhas da ficha');
        self::assertSame($ponto, $xlsx, 'as linhas do XLSX');
        self::assertLessThan(0, $totalPonto, 'controle: as faltas contam pela jornada do escritório');
        self::assertLessThan($ponto[$this->dias[1]], $totalPonto, 'as faltas depois da última batida entram no total da /ponto');
        self::assertSame($totalPonto, $totalFicha, 'o total da ficha');
        self::assertSame($totalPonto, $totalXlsx, 'a última linha do mês no XLSX');
        self::assertSame($totalPonto + 60, $atualXlsx, 'o banco do papel: nada antes, o mês e as horas pagas');
    }

    #[TestDox('Sem jornada pessoal: o PDF assinado traz o mesmo banco do XLSX, com as faltas')]
    public function testPdfTrazOMesmoBanco(): void
    {
        // O PDF é a página desenhada em imagem (E16): o que ele mostra é o conteúdo que chega ao desenho.
        $desenho = DesenhoDaFolhaEspiao::instalar(static::getContainer());
        $this->logarComTenant($this->client, $this->eu, $this->escritorio);
        [, , $atualXlsx] = $this->xlsx();
        $this->client->request('GET', sprintf('/ponto/exportar-folha-pdf?mes=%d&ano=%d', (int) $this->mes->format('n'), (int) $this->mes->format('Y')));
        self::assertResponseIsSuccessful();

        self::assertLessThan(0, $atualXlsx, 'controle');
        $absoluto = abs($atualXlsx);
        $valor = sprintf('%s%d:%02d', $atualXlsx < 0 ? '-' : '+', intdiv($absoluto, 60), $absoluto % 60);
        self::assertSame($valor, $desenho->ultimo()->resumo->saldoAtual, 'o "Saldo do Banco de Horas Atual" do PDF');
    }

    /**
     * O "Saldo mês" dos dias com batida e o total "Saldo do mês" de uma tela com a folha, em minutos.
     *
     * @return array{array<string, int>, int}
     */
    private function folhaNaTela(string $url, string $raiz): array
    {
        $this->em()->clear();
        $tela = $this->client->request('GET', $url);
        self::assertResponseIsSuccessful();
        $linhas = [];
        foreach ($this->dias as $dia) {
            $linhas[$dia] = $this->minutos($tela->filter(sprintf('%s tr.pe-lin[data-chave-dia="%s"] > td.pe-sm[data-r="Saldo mês"]', $raiz, $dia))->text());
        }
        $total = $tela->filter($raiz . ' div.pe-folha-totais > div.pe-folha-total:first-child > b.pe-folha-total-valor');
        self::assertCount(1, $total, 'o total "Saldo do mês" em ' . $raiz);

        return [$linhas, $this->minutos($total->text())];
    }

    /**
     * O "Saldo do Mês" (coluna I) dos dias com batida e do último dia do mês, e o "Saldo do Banco de Horas Atual".
     *
     * @return array{array<string, int>, int, int}
     */
    private function xlsx(): array
    {
        $this->em()->clear();
        $this->client->request('GET', sprintf('/ponto/exportar-folha-xlsx?mes=%d&ano=%d', (int) $this->mes->format('n'), (int) $this->mes->format('Y')));
        self::assertResponseIsSuccessful();
        $arquivo = (string) tempnam(sys_get_temp_dir(), 'folha_xlsx_');
        file_put_contents($arquivo, (string) $this->client->getInternalResponse()->getContent());
        $planilha = IOFactory::load($arquivo)->getActiveSheet();
        unlink($arquivo);

        $porDia = [];
        $texto = '';
        foreach ($planilha->toArray() as $linha) {
            $dia = (string) ($linha[0] ?? '');
            if (preg_match('/^\d{2}$/D', $dia) === 1 && (string) ($linha[2] ?? '') !== '' && !isset($porDia[$dia])) {
                $porDia[$dia] = (string) $linha[8];
            }
            $texto .= implode(' ', array_map('strval', $linha)) . "\n";
        }
        $linhas = [];
        foreach ($this->dias as $dia) {
            $linhas[$dia] = $this->minutos($porDia[substr($dia, 8, 2)] ?? '');
        }
        self::assertMatchesRegularExpression('/Saldo do Banco de Horas Atual:\s+([+-]\d+:\d{2})/', $texto);
        preg_match('/Saldo do Banco de Horas Atual:\s+([+-]\d+:\d{2})/', $texto, $atual);

        return [$linhas, $this->minutos($porDia[$this->mes->format('t')] ?? ''), $this->minutos($atual[1])];
    }

    /** "+1h00m", "-7h00m" e "0h" da tela; "+6:07" e "-0:00" das planilhas. Em minutos. */
    private function minutos(string $texto): int
    {
        $padrao = '/^([+-]?)(\d+)(?:h(?:(\d{2})m)?|:(\d{2}))$/D';
        self::assertMatchesRegularExpression($padrao, trim($texto));
        preg_match($padrao, trim($texto), $partes);
        $minutos = (int) $partes[2] * 60 + (int) (($partes[3] ?? '') . ($partes[4] ?? ''));

        return $partes[1] === '-' ? -$minutos : $minutos;
    }

    private function horasPagas(int $minutos): void
    {
        $lancamento = new LancamentoHorasPagas();
        $lancamento->setTenant($this->escritorio);
        $lancamento->setUser($this->eu);
        $lancamento->setAno((int) $this->mes->format('Y'));
        $lancamento->setMes((int) $this->mes->format('n'));
        $lancamento->setMinutos($minutos);
        $lancamento->setMotivo('Lançamento de teste');
        $lancamento->setCriadoPor($this->eu);
        $lancamento->setCriadoEm(new \DateTimeImmutable());
        $this->em()->persist($lancamento);
        $this->em()->flush();
    }

    private function batida(\DateTimeImmutable $quando, string $tipo): void
    {
        $registro = new RegistroPonto();
        $registro->setUser($this->eu);
        $registro->setTenant($this->escritorio);
        $registro->setTipo($tipo);
        $registro->setDataHora(new \DateTime($quando->format('Y-m-d H:i:s')));
        $registro->setSedeNomeSnapshot('Teste');
        $this->em()->persist($registro);
        $this->em()->flush();
    }
}
