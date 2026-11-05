<?php

declare(strict_types=1);

namespace App\Tests\Pasta\Functional;

use App\Controller\PastaController;
use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Pasta\Entity\Pasta;
use App\Pasta\UseCase\BuscarProcessosParaVincularUseCase;
use App\Processo\Entity\Processo;
use App\Tests\Functional\JusPrimeWebTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;

/**
 * Achado 3.04 da auditoria (regra K3 do cadeado): o processo cujas pastas são TODAS ocultas para a pessoa não
 * abre para ela. Vincular esse processo a uma pasta que ela edita daria a ele uma pasta visível e o abriria;
 * por isso o vincular recusa (o mesmo 422 "Processo não encontrado." do id inexistente, por id e pelo número
 * do CNJ já cadastrado) e a lista do buscar não o oferece. Primário e Master seguem vendo e vinculando.
 */
#[CoversClass(PastaController::class)]
#[CoversClass(BuscarProcessosParaVincularUseCase::class)]
final class VincularProcessoCadeadoControllerTest extends JusPrimeWebTestCase
{
    use CriaCenarioDoCadeadoFuncionalTrait;

    private const XHR = ['HTTP_X_REQUESTED_WITH' => 'XMLHttpRequest'];

    protected function setUp(): void
    {
        parent::setUp();
        $this->prepararCliente();
    }

    /**
     * Ana edita a pasta A (livre); a pasta B está trancada para todos e o processo P só está em B. O processo Q
     * está numa pasta livre (visível para Ana).
     *
     * @return array{primario: User, tenant: Tenant, ana: User, a: Pasta, oculto: Processo, visivel: Processo}
     */
    private function cenario(): array
    {
        [$primario, $tenant] = $this->criarEscritorio();
        $ana = $this->criarColaborador($tenant);
        $a = $this->criarPasta($tenant, 'CLIENTE LIVRE');
        $b = $this->criarPasta($tenant, 'CLIENTE TRANCADO');
        $outra = $this->criarPasta($tenant, 'CLIENTE OUTRA');
        $oculto = $this->processo($tenant, '07000441120268070001', 'CLASSE SIGILOSA');
        $visivel = $this->processo($tenant, '07000551120268070001', 'CLASSE ABERTA');
        $b->vincularProcesso($oculto);
        $outra->vincularProcesso($visivel);
        $this->em()->flush();
        $this->trancar($b, $primario);

        return ['primario' => $primario, 'tenant' => $tenant, 'ana' => $ana, 'a' => $a, 'oculto' => $oculto, 'visivel' => $visivel];
    }

    private function processo(Tenant $tenant, string $numero, string $classe): Processo
    {
        $processo = new Processo();
        $processo->setTenant($tenant);
        $processo->setNumeroProcesso($numero);
        $processo->setClasseProcessual($classe);
        $this->em()->persist($processo);
        $this->em()->flush();

        return $processo;
    }

    /** @param array<string, mixed> $dados */
    private function pedirVinculo(Pasta $pasta, array $dados): void
    {
        $this->client->request('POST', '/pasta/' . $pasta->getId() . '/processo/vincular', ['_token' => 'TOKEN_vincular_processo_' . $pasta->getId(), ...$dados], [], self::XHR);
    }

    private function vinculos(Pasta $pasta, Processo $processo): int
    {
        return (int) $this->em()->getConnection()->fetchOne(
            'SELECT count(*) FROM pasta_processo WHERE pasta_id = :p AND processo_id = :pr',
            ['p' => $pasta->getId(), 'pr' => $processo->getId()],
        );
    }

    /** @return list<int> */
    private function idsDoBuscar(Pasta $pasta, string $termo = ''): array
    {
        $this->client->request('GET', '/pasta/' . $pasta->getId() . '/processo/buscar?q=' . urlencode($termo), [], [], self::XHR);
        self::assertResponseIsSuccessful();

        return array_column(json_decode((string) $this->client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR)['results'], 'id');
    }

    #[TestDox('3.04: por id, o processo de pastas todas ocultas responde 422 "Processo não encontrado." e nada é gravado')]
    public function testPorIdRecusa(): void
    {
        $c = $this->cenario();
        $this->entrar($c['ana'], $c['tenant']);

        $this->pedirVinculo($c['a'], ['processo_id' => $c['oculto']->getId()]);

        self::assertResponseStatusCodeSame(422);
        self::assertSame('Processo não encontrado.', json_decode((string) $this->client->getResponse()->getContent(), true)['erro'] ?? null);
        self::assertSame(0, $this->vinculos($c['a'], $c['oculto']));

        $this->pedirVinculo($c['a'], ['processo_id' => $c['oculto']->getId(), 'modo' => 'apenso', 'tipo_apenso' => 'embargos']);
        self::assertResponseStatusCodeSame(422, 'o apensado recusa do mesmo jeito');
        self::assertSame(0, $this->vinculos($c['a'], $c['oculto']));
    }

    #[TestDox('3.04: pelo número do CNJ já cadastrado (jaExistia), o processo oculto também é recusado e nada é gravado')]
    public function testPeloNumeroRecusa(): void
    {
        $c = $this->cenario();
        $this->entrar($c['ana'], $c['tenant']);

        $this->pedirVinculo($c['a'], ['numeroProcesso' => '0700044-11.2026.8.07.0001']);

        self::assertResponseStatusCodeSame(422);
        self::assertSame('Processo não encontrado.', json_decode((string) $this->client->getResponse()->getContent(), true)['erro'] ?? null);
        self::assertSame(0, $this->vinculos($c['a'], $c['oculto']));
        self::assertSame(1, (int) $this->em()->getConnection()->fetchOne(
            'SELECT count(*) FROM processo WHERE tenant_id = :t AND numero_processo = :n',
            ['t' => $c['tenant']->getId(), 'n' => '07000441120268070001'],
        ), 'nenhum processo novo nasce com o mesmo número');
    }

    #[TestDox('3.04: o processo com pasta visível segue vinculando para a mesma pessoa')]
    public function testProcessoVisivelSegue(): void
    {
        $c = $this->cenario();
        $this->entrar($c['ana'], $c['tenant']);

        $this->pedirVinculo($c['a'], ['processo_id' => $c['visivel']->getId()]);

        self::assertResponseIsSuccessful();
        self::assertSame(1, $this->vinculos($c['a'], $c['visivel']));
    }

    #[TestDox('3.04: o buscar não lista o processo de pastas todas ocultas (nem por termo); lista o visível')]
    public function testBuscarNaoListaOOculto(): void
    {
        $c = $this->cenario();
        $this->entrar($c['ana'], $c['tenant']);

        $semTermo = $this->idsDoBuscar($c['a']);
        self::assertNotContains($c['oculto']->getId(), $semTermo);
        self::assertContains($c['visivel']->getId(), $semTermo);
        self::assertNotContains($c['oculto']->getId(), $this->idsDoBuscar($c['a'], '0700044'));
        self::assertStringNotContainsString('CLASSE SIGILOSA', (string) $this->client->getResponse()->getContent());
    }

    #[TestDox('3.04: o primário e o Master seguem listando e vinculando o processo da pasta trancada')]
    public function testPrimarioEMasterSeguem(): void
    {
        $c = $this->cenario();
        $master = $this->criarColaborador($c['tenant'], 'Master Geral', []);
        $master->setRoles(['ROLE_SUPER_ADMIN']);
        $this->em()->flush();
        $pastaDoMaster = $this->criarPasta($c['tenant'], 'CLIENTE DO MASTER');

        // Os dois listam enquanto o processo ainda está só na pasta trancada.
        $this->entrar($c['primario'], $c['tenant']);
        self::assertContains($c['oculto']->getId(), $this->idsDoBuscar($c['a']));
        $this->entrar($master, $c['tenant']);
        self::assertContains($c['oculto']->getId(), $this->idsDoBuscar($pastaDoMaster));

        $this->pedirVinculo($pastaDoMaster, ['processo_id' => $c['oculto']->getId()]);
        self::assertResponseIsSuccessful();
        self::assertSame(1, $this->vinculos($pastaDoMaster, $c['oculto']));

        $this->entrar($c['primario'], $c['tenant']);
        $this->pedirVinculo($c['a'], ['processo_id' => $c['oculto']->getId()]);
        self::assertResponseIsSuccessful();
        self::assertSame(1, $this->vinculos($c['a'], $c['oculto']));
    }
}
