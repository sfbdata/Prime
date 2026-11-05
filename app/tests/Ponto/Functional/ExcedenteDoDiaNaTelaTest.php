<?php

declare(strict_types=1);

namespace App\Tests\Ponto\Functional;

use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Ponto\Controller\PontoController;
use App\Ponto\Entity\BlocoJornadaColaborador;
use App\Ponto\Entity\Feriado;
use App\Ponto\Entity\JornadaColaborador;
use App\Tests\Central\Support\CentralHttpTrait;
use App\Tests\Functional\JusPrimeWebTestCase;
use App\Tests\Inteligencia\Support\CriaFixturesInteligenciaTrait;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\DomCrawler\Crawler;

/**
 * Contador de horas excedentes (pedido do Samuel, 05/11/2026): na tela /ponto, logo abaixo do relógio
 * do tempo trabalhado, um número verde conta ao vivo quanto a pessoa já passou da jornada do dia
 * ("+00:37"). Some abaixo da meta e com meta 0 (feriado ou dia fora da escala); pausa no intervalo
 * junto com o relógio; depois da saída fica parado no valor final.
 *
 * A meta é a do `JornadaResolver::resolverMetaDia`, a mesma do alerta de "hora de ir embora" (já sem o
 * almoço). Não é o `duracaoJornadaDiariaMinutos`, que mede saída menos entrada da escala e conta o almoço.
 *
 * O servidor entrega a meta no atributo `data-meta-dia-minutos`; a conta ao vivo é do JS da página
 * (smoke do dono). Aqui se travam o lugar, a meta e as regras escritas no JS.
 */
#[Group('ponto')]
#[CoversClass(PontoController::class)]
final class ExcedenteDoDiaNaTelaTest extends JusPrimeWebTestCase
{
    use CriaFixturesInteligenciaTrait;
    use CentralHttpTrait;

    private const EXCEDENTE = '.card-primary > .card-body > #excedente-hoje';

    private KernelBrowser $client;

    protected function setUp(): void
    {
        parent::setUp();
        $this->client = $this->cliente();
    }

    /**
     * @param list<int> $dias
     *
     * @return array{0: User, 1: Tenant}
     */
    private function colaboradorComBloco(array $dias, int $minutos): array
    {
        [, $tenant] = $this->criarAdmin();
        $ana = $this->criarUsuarioComPermissoes($tenant, ['modules.ponto.view'], 'ana');
        $jornada = (new JornadaColaborador())->setUser($ana)->setTenant($tenant);
        $jornada->addBloco((new BlocoJornadaColaborador())->setDiasSemana($dias)->setEntrada('08:00')
            ->setRepouso('12:00')->setRetorno('13:00')->setSaida('17:00')->setMinutosBloco($minutos));
        $this->em()->persist($jornada);
        $this->em()->flush();

        return [$ana, $tenant];
    }

    private function tela(User $quem, Tenant $tenant): Crawler
    {
        $this->logarComTenant($this->client, $quem, $tenant);
        $tela = $this->client->request('GET', '/ponto/');
        self::assertResponseIsSuccessful();

        return $tela;
    }

    private function hoje(): int
    {
        return (int) (new \DateTimeImmutable())->format('N');
    }

    #[TestDox('Dia da escala: o contador fica no card do relógio, escondido até passar da meta, com a meta do bloco (sem o almoço)')]
    public function testMetaDoDia(): void
    {
        [$ana, $tenant] = $this->colaboradorComBloco([1, 2, 3, 4, 5, 6, 7], 480);

        $tela = $this->tela($ana, $tenant);

        self::assertCount(1, $tela->filter(self::EXCEDENTE), 'abaixo do relógio, no card de registrar ponto');
        self::assertSame('480', $tela->filter(self::EXCEDENTE)->attr('data-meta-dia-minutos'));
        self::assertStringContainsString('d-none', (string) $tela->filter(self::EXCEDENTE)->attr('class'), 'só aparece quando passa da meta');
    }

    #[TestDox('Dia fora da escala: meta 0, o contador nunca aparece')]
    public function testForaDaEscala(): void
    {
        $outrosDias = array_values(array_diff([1, 2, 3, 4, 5, 6, 7], [$this->hoje()]));
        [$ana, $tenant] = $this->colaboradorComBloco($outrosDias, 480);

        $tela = $this->tela($ana, $tenant);

        self::assertSame('0', $tela->filter(self::EXCEDENTE)->attr('data-meta-dia-minutos'));
    }

    #[TestDox('Feriado hoje: meta 0, como na folha')]
    public function testFeriado(): void
    {
        [$ana, $tenant] = $this->colaboradorComBloco([1, 2, 3, 4, 5, 6, 7], 480);
        $feriado = new Feriado();
        $feriado->setNome('Feriado de teste');
        $feriado->setData(new \DateTime('today'));
        $feriado->setRecorrente(false);
        $feriado->setTenant($tenant);
        $this->em()->persist($feriado);
        $this->em()->flush();

        $tela = $this->tela($ana, $tenant);

        self::assertSame('0', $tela->filter(self::EXCEDENTE)->attr('data-meta-dia-minutos'));
    }

    #[TestDox('O JS conta trabalhado (como na folha) menos meta, só com meta positiva, e mostra +HH:MM em verde')]
    public function testRegrasDoJs(): void
    {
        $dir = static::getContainer()->getParameter('kernel.project_dir');
        $tela = (string) file_get_contents($dir . '/templates/ponto/index.html.twig');

        self::assertStringContainsString('const excedente = segundosComoNaFolha(agora) - metaDiaMinutos * 60;', $tela);
        // Revisão: segue a folha, não o relógio. Retorno sem repouso não dobra; sem intervalo válido, span inteiro.
        self::assertStringContainsString('if (tRepouso && tRetorno && tRetorno > tRepouso)', $tela);
        self::assertStringContainsString('if (!excedenteEl || !excedenteValorEl) return;', $tela);
        self::assertStringContainsString('if (metaDiaMinutos > 0 && excedente >= 60)', $tela);
        self::assertStringContainsString("'+' + formatarHorasMinutos(excedente)", $tela);
        self::assertStringContainsString('color:#1e7b45', $tela, 'verde do desenho do Expediente');
    }
}
