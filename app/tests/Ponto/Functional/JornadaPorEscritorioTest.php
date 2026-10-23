<?php

declare(strict_types=1);

namespace App\Tests\Ponto\Functional;

use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Ponto\Controller\JornadaColaboradorController;
use App\Ponto\Repository\JornadaColaboradorRepository;
use App\Ponto\Service\FolhaPontoBuilder;
use App\Tests\Functional\JusPrimeWebTestCase;
use App\Tests\Ponto\Support\CenarioDeJornadaPorEscritorioTrait;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;

/**
 * Jornada por escritório (spec `docs/specs/seguranca-b-jornada.md` §4, regra 1; achado de auditoria interna F8/F11): um
 * escritório nunca lê nem altera a jornada pessoal do outro, e a folha de cada um usa a jornada dele.
 */
#[CoversClass(JornadaColaboradorController::class)]
#[CoversClass(JornadaColaboradorRepository::class)]
#[CoversClass(FolhaPontoBuilder::class)]
final class JornadaPorEscritorioTest extends JusPrimeWebTestCase
{
    use CenarioDeJornadaPorEscritorioTrait;

    private const SABADO_EM_B = [[6], '08:00', null, null, '12:00'];
    private const SEMANA_EM_A = [[1, 2, 3, 4, 5], '09:00', '12:00', '13:00', '18:00'];

    private KernelBrowser $client;
    private Tenant $a;
    private Tenant $b;
    private User $pessoa;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->client->disableReboot();
        $this->instalarCsrfFalso();
        $this->a = $this->escritorio('Escritório A');
        $this->b = $this->escritorio('Escritório B');
        $this->pessoa = $this->pessoa('Pessoa nos dois');
        $this->vincular($this->pessoa, $this->a);
        $this->vincular($this->pessoa, $this->b);
        $this->jornadaPessoal($this->pessoa, $this->b, [self::SABADO_EM_B]);
    }

    #[TestDox('O admin de A grava a jornada da pessoa em A, e a dela em B fica intacta')]
    public function testGravarEmANaoMexeEmB(): void
    {
        $this->entrarComoAdminDe($this->a);

        $resposta = $this->chamarJson($this->client, 'POST', $this->url($this->a), $this->corpoDaJornada([self::SEMANA_EM_A]));

        self::assertSame(['ok' => true], $resposta);
        $this->em()->clear();
        $jornadas = static::getContainer()->get(JornadaColaboradorRepository::class);
        $emA = $jornadas->findByUserETenant($this->recarregar($this->pessoa), $this->recarregar($this->a));
        $emB = $jornadas->findByUserETenant($this->recarregar($this->pessoa), $this->recarregar($this->b));
        self::assertSame('09:00', $emA?->getBlocos()->first()->getEntrada());
        self::assertSame('08:00', $emB?->getBlocos()->first()->getEntrada(), 'a jornada de B não pode mudar');
        self::assertCount(1, $emB->getBlocos());
    }

    #[TestDox('A leitura em B não mostra a jornada de A, e a em A não mostra a de B')]
    public function testLeituraFicaNoEscritorioDaUrl(): void
    {
        $this->jornadaPessoal($this->pessoa, $this->a, [self::SEMANA_EM_A]);

        $this->entrarComoAdminDe($this->b);
        $emB = $this->chamarJson($this->client, 'GET', $this->url($this->b));
        $this->entrarComoAdminDe($this->a);
        $emA = $this->chamarJson($this->client, 'GET', $this->url($this->a));

        self::assertSame(['08:00'], array_column($emB['blocos'], 'entrada'));
        self::assertSame(['09:00'], array_column($emA['blocos'], 'entrada'));
    }

    #[TestDox('O admin de A não apaga a jornada da pessoa em B: o DELETE em A só limpa a de A')]
    public function testApagarEmANaoApagaEmB(): void
    {
        $this->jornadaPessoal($this->pessoa, $this->a, [self::SEMANA_EM_A]);
        $this->entrarComoAdminDe($this->a);

        $this->chamarJson($this->client, 'DELETE', $this->url($this->a));

        self::assertResponseIsSuccessful();
        $this->em()->clear();
        $emB = static::getContainer()->get(JornadaColaboradorRepository::class)
            ->findByUserETenant($this->recarregar($this->pessoa), $this->recarregar($this->b));
        self::assertCount(1, $emB?->getBlocos() ?? []);
    }

    #[TestDox('A meta do dia na folha de B usa a jornada de B, não a de A')]
    public function testFolhaDeBUsaAJornadaDeB(): void
    {
        $this->jornadaPessoal($this->pessoa, $this->a, [self::SEMANA_EM_A]);
        // Janeiro de 2025 a partir do sábado 04: em B só os sábados (04, 11, 18 e 25) pedem 4 horas.
        $this->registrarBatida($this->pessoa, $this->b, 'entrada', new \DateTimeImmutable('2025-01-04 08:00:00'));
        $this->registrarBatida($this->pessoa, $this->b, 'saida', new \DateTimeImmutable('2025-01-04 12:00:00'));

        $saldo = static::getContainer()->get(FolhaPontoBuilder::class)
            ->calcularSaldoAteMes($this->pessoa, 2025, 1, [], null, new \DateTimeImmutable('2025-01-04'), $this->b);

        // O sábado 04 fecha em zero e os outros três sábados faltam 240 cada. Pela jornada de A seriam 19 dias úteis.
        self::assertSame(-720, $saldo);
    }

    private function entrarComoAdminDe(Tenant $tenant): void
    {
        $admin = $this->pessoa('Admin');
        $this->vincular($admin, $tenant, [], true);
        $this->logarComTenant($this->client, $admin, $tenant);
    }

    private function url(Tenant $tenant): string
    {
        return sprintf('/tenant/%d/user/%d/jornada-colaborador', $tenant->getId(), $this->pessoa->getId());
    }

    /**
     * @template T of object
     *
     * @param T $entidade
     *
     * @return T
     */
    private function recarregar(object $entidade): object
    {
        return $this->em()->find($entidade::class, $entidade->getId());
    }
}
