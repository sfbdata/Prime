<?php

declare(strict_types=1);

namespace App\Tests\Ponto\Functional;

use App\Entity\Auth\User;
use App\Entity\Tenant\Sede;
use App\Entity\Tenant\Tenant;
use App\Ponto\Controller\PontoController;
use App\Repository\SedeRepository;
use App\Tests\Functional\JusPrimeWebTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;

/**
 * A sede encerrada (Ponto E10, removida quando já tinha batida) não vale para batida nova: a regra é do servidor, em
 * `PontoController::batida()`, não só da cerca da tela.
 */
#[CoversClass(PontoController::class)]
#[CoversClass(SedeRepository::class)]
#[Group('ponto')]
final class BatidaNaSedeEncerradaTest extends JusPrimeWebTestCase
{
    use CenarioDeJustificativaTrait;

    private const DENTRO_DA_FECHADA = ['latitude' => -23.55, 'longitude' => -46.63, 'precisaoGps' => 10.0];
    private const DENTRO_DA_ABERTA = ['latitude' => -22.9068, 'longitude' => -43.1729, 'precisaoGps' => 10.0];

    private KernelBrowser $client;
    private Tenant $escritorio;
    private User $ana;

    protected function setUp(): void
    {
        if ((int) (new \DateTimeImmutable())->format('Hi') < 5) {
            self::markTestSkipped('Nos primeiros 5 minutos do dia as batidas "de hoje" cairiam em ontem.');
        }
        $this->client = static::createClient();
        $this->client->disableReboot();
        $this->instalarCsrfPrevisivel();
        $this->escritorio = $this->escritorio('Escritório Sede Encerrada');
        $this->vincularPrimario($this->pessoa('primario'), $this->escritorio);
        $this->ana = $this->pessoa('ana');
        $this->vincularColaborador($this->ana, $this->escritorio);
        $this->logarComTenant($this->client, $this->ana, $this->escritorio);
    }

    #[TestDox('Única sede encerrada: a batida dentro do raio dela é recusada como sem sede (403) e nada é gravado')]
    public function testUnicaSedeEncerradaRecusa(): void
    {
        $this->sede('Sede Fechada', '-23.55000000', '-46.63000000', encerrada: true);

        $resposta = $this->bater(self::DENTRO_DA_FECHADA);

        self::assertResponseStatusCodeSame(403);
        self::assertSame('Nenhuma sede configurada para o tenant.', $resposta['message']);
        self::assertSame(0, $this->batidas());
    }

    #[TestDox('Com outra sede ativa: dentro da encerrada é "fora da área" (403); dentro da ativa, a batida entra nela')]
    public function testSedeEncerradaNaoContaComOutraAtiva(): void
    {
        $this->sede('Sede Fechada', '-23.55000000', '-46.63000000', encerrada: true);
        $aberta = $this->sede('Sede Aberta', '-22.90680000', '-43.17290000', encerrada: false);

        $resposta = $this->bater(self::DENTRO_DA_FECHADA);
        self::assertResponseStatusCodeSame(403);
        self::assertSame('Você está fora da área permitida das sedes para registrar o ponto.', $resposta['message']);
        self::assertSame(0, $this->batidas());

        $resposta = $this->bater(self::DENTRO_DA_ABERTA);
        self::assertTrue($resposta['success'], (string) ($resposta['message'] ?? ''));
        self::assertSame(
            $aberta->getId(),
            (int) $this->emSemFiltro()->getConnection()->fetchOne('SELECT sede_id FROM registro_ponto WHERE user_id = ? ORDER BY id DESC LIMIT 1', [$this->ana->getId()]),
        );
    }

    private function sede(string $nome, string $latitude, string $longitude, bool $encerrada): Sede
    {
        $sede = (new Sede())
            ->setNome($nome)
            ->setLatitude($latitude)
            ->setLongitude($longitude)
            ->setRaioPermitido(100)
            ->setTimezone('America/Sao_Paulo')
            ->setTenant($this->escritorio);
        if ($encerrada) {
            $sede->encerrar(new \DateTimeImmutable('2026-11-01 10:00:00'));
        }
        $this->em()->persist($sede);
        $this->em()->flush();

        return $sede;
    }

    /**
     * @param array<string, mixed> $posicao
     *
     * @return array<string, mixed>
     */
    private function bater(array $posicao): array
    {
        $this->client->request(
            'POST',
            '/ponto/batida',
            server: ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json', 'HTTP_X_CSRF_TOKEN' => 'TOKEN_ajax'],
            content: (string) json_encode(['tipo' => 'entrada'] + $posicao),
        );

        return (array) json_decode((string) $this->client->getResponse()->getContent(), true);
    }

    private function batidas(): int
    {
        return (int) $this->emSemFiltro()->getConnection()->fetchOne('SELECT COUNT(*) FROM registro_ponto WHERE user_id = ?', [$this->ana->getId()]);
    }
}
