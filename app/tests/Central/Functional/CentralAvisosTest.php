<?php

declare(strict_types=1);

namespace App\Tests\Central\Functional;

use App\Central\Controller\CentralController;
use App\Central\Service\AvisosDaCentral;
use App\Central\Service\ContadorDeAvisos;
use App\Central\UseCase\ListarAvisosDaCentralUseCase;
use App\Central\UseCase\MarcarAvisoUseCase;
use App\Entity\Auth\User;
use App\Entity\Notificacao;
use App\Entity\Tenant\Tenant;
use App\Tarefa\UseCase\AlertarResponsavelDaMetaUseCase;
use App\Tests\Central\Support\CentralHttpTrait;
use App\Tests\Functional\JusPrimeWebTestCase;
use App\Tests\Inteligencia\Support\CriaFixturesInteligenciaTrait;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;

/**
 * Avisos do sino na Caixa da Central (F1, `docs/specs/central-comunicacao-f1-avisos.md`): só os da pessoa neste
 * escritório, sem gestão e sem os da própria Central, com o acesso à pasta rechecado na leitura e na contagem.
 */
#[CoversClass(CentralController::class)]
#[CoversClass(ListarAvisosDaCentralUseCase::class)]
#[CoversClass(MarcarAvisoUseCase::class)]
#[CoversClass(AvisosDaCentral::class)]
#[CoversClass(ContadorDeAvisos::class)]
final class CentralAvisosTest extends JusPrimeWebTestCase
{
    use CriaFixturesInteligenciaTrait;
    use CentralHttpTrait;

    private function aviso(User $para, Tenant $tenant, string $tipo, string $titulo, ?string $url = null, bool $lida = false): Notificacao
    {
        $n = (new Notificacao())->setUsuario($para)->setTenant($tenant)->setTipo($tipo)->setTitulo($titulo)->setLida($lida)->setUrl($url);
        $this->em()->persist($n);
        $this->em()->flush();

        return $n;
    }

    #[TestDox('Lista: só os meus neste escritório, sem gestão e sem os da Central, no vocabulário do desenho, recentes primeiro')]
    public function testLista(): void
    {
        $client = $this->cliente();
        [, $tenant] = $this->criarAdmin();
        [, $outro] = $this->criarAdmin();
        $ana = $this->colega($tenant, 'ana');
        $bia = $this->colega($tenant, 'bia');

        $this->aviso($ana, $tenant, Notificacao::TIPO_TAREFA_CRIADA, 'Meta nova da Ana');
        $this->aviso($ana, $tenant, AlertarResponsavelDaMetaUseCase::TIPO_NOTIFICACAO, 'Alerta da Ana');
        $this->aviso($ana, $tenant, Notificacao::TIPO_DJEN_PUBLICACAO, 'Publicação da Ana', '/push-processual');
        $this->aviso($ana, $tenant, 'ponto_justificativa_abonada', 'Ponto da Ana');
        $this->aviso($ana, $tenant, Notificacao::TIPO_PONTO_JUSTIFICATIVA_ENVIADA, 'GESTAO-NAO-ENTRA');
        $this->aviso($ana, $tenant, Notificacao::TIPO_CENTRAL_MENCAO, 'CENTRAL-NAO-ENTRA');
        $this->aviso($ana, $outro, Notificacao::TIPO_TAREFA_CRIADA, 'OUTRO-ESCRITORIO');
        $this->aviso($bia, $tenant, Notificacao::TIPO_TAREFA_CRIADA, 'DA-BIA');

        $this->logarComTenant($client, $ana, $tenant);
        $client->request('GET', '/central/avisos');
        self::assertResponseIsSuccessful();
        $avisos = $this->json($client)['avisos'];

        self::assertSame(['Ponto da Ana', 'Publicação da Ana', 'Alerta da Ana', 'Meta nova da Ana'], array_column($avisos, 'titulo'));
        $porTitulo = array_column($avisos, null, 'titulo');
        self::assertSame(['metas', 'Nova meta', 2], [$porTitulo['Meta nova da Ana']['grupo'], $porTitulo['Meta nova da Ana']['rotulo'], $porTitulo['Meta nova da Ana']['nivel']]);
        self::assertSame(['urgente', 'Alerta', 3, '#e8590c'], [$porTitulo['Alerta da Ana']['grupo'], $porTitulo['Alerta da Ana']['rotulo'], $porTitulo['Alerta da Ana']['nivel'], $porTitulo['Alerta da Ana']['cor']]);
        self::assertSame(['atencao', '/push-processual'], [$porTitulo['Publicação da Ana']['grupo'], $porTitulo['Publicação da Ana']['url']]);
        self::assertNull($porTitulo['Ponto da Ana']['grupo'], 'sem par no desenho: só em "Tudo"');
        $bruto = (string) $client->getResponse()->getContent();
        foreach (['GESTAO-NAO-ENTRA', 'CENTRAL-NAO-ENTRA', 'OUTRO-ESCRITORIO', 'DA-BIA'] as $fora) {
            self::assertStringNotContainsString($fora, $bruto);
        }
    }

    #[TestDox('Acesso rechecado: aviso de pasta que a pessoa não vê não sai na lista nem conta; quem vê a pasta recebe')]
    public function testAcessoRechecado(): void
    {
        $client = $this->cliente();
        [$admin, $tenant] = $this->criarAdmin();
        $ana = $this->colega($tenant, 'ana');   // sem permissão de pasta
        $this->aviso($ana, $tenant, Notificacao::TIPO_PASTA_MENCAO_REGISTRO, 'PASTA-SEM-ACESSO', '/pasta/987654#registro');
        $this->aviso($ana, $tenant, Notificacao::TIPO_TAREFA_CRIADA, 'Livre');
        $this->aviso($admin, $tenant, Notificacao::TIPO_PASTA_MENCAO_REGISTRO, 'Pasta do admin', '/pasta/987654#registro');

        $this->logarComTenant($client, $ana, $tenant);
        $client->request('GET', '/central/avisos');
        self::assertSame(['Livre'], array_column($this->json($client)['avisos'], 'titulo'));
        $client->request('GET', '/central/novidades');
        self::assertSame(1, $this->json($client)['avisosNaoLidos'], 'o de pasta sem acesso não conta');

        $this->logarComTenant($client, $admin, $tenant);
        $client->request('GET', '/central/avisos');
        self::assertSame(['Pasta do admin'], array_column($this->json($client)['avisos'], 'titulo'));
    }

    #[TestDox('Marcar lido e não lido: só o próprio aviso da Caixa; alheio, de gestão ou de outro escritório é 404; sem CSRF é 403')]
    public function testMarcar(): void
    {
        $client = $this->cliente();
        [, $tenant] = $this->criarAdmin();
        [, $outro] = $this->criarAdmin();
        $ana = $this->colega($tenant, 'ana');
        $bia = $this->colega($tenant, 'bia');
        $meu = (int) $this->aviso($ana, $tenant, Notificacao::TIPO_TAREFA_CRIADA, 'Meu')->getId();
        $daBia = (int) $this->aviso($bia, $tenant, Notificacao::TIPO_TAREFA_CRIADA, 'Da Bia')->getId();
        $gestao = (int) $this->aviso($ana, $tenant, Notificacao::TIPO_SERVICEDESK_NOVO, 'Gestão')->getId();
        $deOutro = (int) $this->aviso($ana, $outro, Notificacao::TIPO_TAREFA_CRIADA, 'Outro')->getId();
        $lida = fn (int $id): bool => (bool) $this->em()->getConnection()->fetchOne('SELECT lida FROM notificacao WHERE id = ?', [$id]);

        $this->logarComTenant($client, $ana, $tenant);
        $this->post($client, "/central/avisos/$meu/lido", []);
        self::assertResponseIsSuccessful();
        self::assertTrue($lida($meu));
        $this->post($client, "/central/avisos/$meu/nao-lido", []);
        self::assertResponseIsSuccessful();
        self::assertFalse($lida($meu));

        foreach ([$daBia, $gestao, $deOutro] as $id) {
            $this->post($client, "/central/avisos/$id/lido", []);
            self::assertResponseStatusCodeSame(404);
            self::assertFalse($lida($id));
        }
        $client->request('POST', "/central/avisos/$meu/lido", [], [], ['CONTENT_TYPE' => 'application/json'], '{}');
        self::assertResponseStatusCodeSame(403);
        self::assertFalse($lida($meu));
    }

    #[TestDox('Novidades: não lidos, urgentes (alerta) e o maior id, só da Caixa e deste escritório')]
    public function testContagensNasNovidades(): void
    {
        $client = $this->cliente();
        [, $tenant] = $this->criarAdmin();
        [, $outro] = $this->criarAdmin();
        $ana = $this->colega($tenant, 'ana');
        $this->aviso($ana, $tenant, AlertarResponsavelDaMetaUseCase::TIPO_NOTIFICACAO, 'Urgente');
        $this->aviso($ana, $tenant, Notificacao::TIPO_TAREFA_CRIADA, 'Normal');
        $this->aviso($ana, $tenant, Notificacao::TIPO_TAREFA_CRIADA, 'Lida', null, true);
        $this->aviso($ana, $tenant, Notificacao::TIPO_PONTO_JUSTIFICATIVA_ENVIADA, 'Gestão');
        $this->aviso($ana, $outro, AlertarResponsavelDaMetaUseCase::TIPO_NOTIFICACAO, 'Outro');
        $ultimo = (int) $this->aviso($ana, $tenant, Notificacao::TIPO_TAREFA_CONCLUIDA, 'Concluída', null, true)->getId();

        $this->logarComTenant($client, $ana, $tenant);
        $client->request('GET', '/central/novidades');
        $d = $this->json($client);
        self::assertSame([2, 1, $ultimo], [$d['avisosNaoLidos'], $d['avisosUrgentes'], $d['ultimoAviso']]);
    }

    #[TestDox('Sem o TenantFilter, os avisos e a contagem do escritório A não alcançam os do B (mesma pessoa nos dois)')]
    public function testIsolamentoSemFiltro(): void
    {
        static::createClient();
        [, $escritorioA] = $this->criarAdmin();
        [, $escritorioB] = $this->criarAdmin();
        $ana = $this->colega($escritorioA, 'ana');
        $this->em()->persist(new \App\Entity\Auth\UserTenant($ana, $escritorioB));
        $this->aviso($ana, $escritorioB, AlertarResponsavelDaMetaUseCase::TIPO_NOTIFICACAO, 'DO-B');
        if ($this->em()->getFilters()->isEnabled('tenant')) {
            $this->em()->getFilters()->disable('tenant');
        }
        /** @var AvisosDaCentral $avisos */
        $avisos = static::getContainer()->get(AvisosDaCentral::class);

        self::assertSame([], $avisos->recentes($ana, $escritorioA, 100));
        self::assertSame([], $avisos->naoLidosAgrupados($ana, $escritorioA));
        self::assertSame(0, $avisos->ultimoId($ana, $escritorioA));
        self::assertCount(1, $avisos->recentes($ana, $escritorioB, 100), 'controle: no B aparece');
    }
}
