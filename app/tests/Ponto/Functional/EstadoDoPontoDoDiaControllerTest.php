<?php

declare(strict_types=1);

namespace App\Tests\Ponto\Functional;

use App\Ponto\Controller\PontoController;
use App\Ponto\Entity\RegistroPonto;
use App\Ponto\UseCase\ObterEstadoDoPontoDoDiaUseCase;
use App\Tests\Central\Support\CentralHttpTrait;
use App\Tests\Functional\JusPrimeWebTestCase;
use App\Tests\Inteligencia\Support\CriaFixturesInteligenciaTrait;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;

/**
 * `GET /ponto/estado-do-dia` (spec `presenca-e-ponto-no-cabecalho.md` §3): só leitura, as mesmas guardas da batida, e
 * só o ponto de quem pede, neste escritório.
 */
#[CoversClass(PontoController::class)]
#[CoversClass(ObterEstadoDoPontoDoDiaUseCase::class)]
final class EstadoDoPontoDoDiaControllerTest extends JusPrimeWebTestCase
{
    use CriaFixturesInteligenciaTrait;
    use CentralHttpTrait;

    #[TestDox('Com o módulo ponto: o estado de hoje da própria pessoa (entrada batida, em jornada), sem as batidas de um colega')]
    public function testEstadoDaPropriaPessoa(): void
    {
        $client = $this->cliente();
        [, $tenant] = $this->criarAdmin();
        $ana = $this->criarUsuarioComPermissoes($tenant, ['modules.ponto.view'], 'ana');
        $bia = $this->criarUsuarioComPermissoes($tenant, ['modules.ponto.view'], 'bia');
        foreach ([[$ana, RegistroPonto::TIPO_ENTRADA, '08:02'], [$bia, RegistroPonto::TIPO_ENTRADA, '07:00'], [$bia, RegistroPonto::TIPO_SAIDA, '07:30']] as [$quem, $tipo, $hora]) {
            $r = new RegistroPonto();
            $r->setUser($quem);
            $r->setTenant($tenant);
            $r->setTipo($tipo);
            $r->setDataHora(new \DateTime('today ' . $hora));
            $this->em()->persist($r);
        }
        $this->em()->flush();
        $this->logarComTenant($client, $ana, $tenant);

        $client->request('GET', '/ponto/estado-do-dia');
        self::assertResponseIsSuccessful();
        $estado = $this->json($client);
        self::assertSame((new \DateTimeImmutable())->format('Y-m-d'), $estado['dia']);
        self::assertSame('em_jornada', $estado['estado']);
        self::assertSame(['entrada' => '08:02', 'repouso' => null, 'retorno' => null, 'saida' => null], $estado['hoje']);
        self::assertTrue($estado['interjornadaLigada'], 'sem configuração de jornada a validação vale, como no servidor');
        self::assertSame(['dia', 'estado', 'hoje', 'homeOffice', 'sedes', 'interjornadaLigada'], array_keys($estado));
    }

    #[TestDox('Sem o módulo ponto: 403, como a batida; sem sessão: não responde o estado')]
    public function testGuardas(): void
    {
        $client = $this->cliente();
        [, $tenant] = $this->criarAdmin();
        $semPonto = $this->colega($tenant, 'semponto');

        $client->request('GET', '/ponto/estado-do-dia');
        self::assertResponseRedirects(null, null, 'sem sessão a plataforma manda ao login antes do controller (o 401 do controller é a segunda linha)');
        self::assertStringContainsString('/login', (string) $client->getResponse()->headers->get('Location'));

        $this->logarComTenant($client, $semPonto, $tenant);
        $client->request('GET', '/ponto/estado-do-dia');
        self::assertResponseStatusCodeSame(403);
        self::assertSame('Sem permissão para registrar ponto.', $this->json($client)['message']);
    }
}
