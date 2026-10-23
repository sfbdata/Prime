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
#[CoversClass(\App\Central\UseCase\ResolverAvisoUseCase::class)]
#[CoversClass(\App\Central\UseCase\MarcarTudoComoLidoUseCase::class)]
#[CoversClass(\App\Central\UseCase\LimparAvisosLidosUseCase::class)]
#[CoversClass(\App\Central\UseCase\ExcluirAvisoUseCase::class)]
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

    #[TestDox('Baixa (F3): resolver marca lido e aparece na lista; reabrir desfaz; alheio ou de gestão é 404; sem CSRF é 403')]
    public function testResolverEReabrir(): void
    {
        $client = $this->cliente();
        [, $tenant] = $this->criarAdmin();
        $ana = $this->colega($tenant, 'ana');
        $bia = $this->colega($tenant, 'bia');
        $meu = (int) $this->aviso($ana, $tenant, Notificacao::TIPO_TAREFA_CRIADA, 'Meu')->getId();
        $daBia = (int) $this->aviso($bia, $tenant, Notificacao::TIPO_TAREFA_CRIADA, 'Da Bia')->getId();
        $gestao = (int) $this->aviso($ana, $tenant, Notificacao::TIPO_SERVICEDESK_NOVO, 'Gestão')->getId();
        $baixas = fn (int $id): int => (int) $this->em()->getConnection()->fetchOne('SELECT COUNT(*) FROM central_aviso_resolvido WHERE notificacao_id = ?', [$id]);

        $this->logarComTenant($client, $ana, $tenant);
        $this->post($client, "/central/avisos/$meu/resolver", []);
        self::assertResponseIsSuccessful();
        $this->post($client, "/central/avisos/$meu/resolver", []);   // idempotente
        self::assertSame(1, $baixas($meu));
        self::assertTrue((bool) $this->em()->getConnection()->fetchOne('SELECT lida FROM notificacao WHERE id = ?', [$meu]));
        $client->request('GET', '/central/avisos');
        self::assertNotNull($this->json($client)['avisos'][0]['resolvidoEm']);

        $this->post($client, "/central/avisos/$meu/reabrir", []);
        self::assertResponseIsSuccessful();
        self::assertSame(0, $baixas($meu));
        foreach ([$daBia, $gestao] as $id) {
            $this->post($client, "/central/avisos/$id/resolver", []);
            self::assertResponseStatusCodeSame(404);
            self::assertSame(0, $baixas($id));
        }
        $client->request('POST', "/central/avisos/$meu/resolver", [], [], ['CONTENT_TYPE' => 'application/json'], '{}');
        self::assertResponseStatusCodeSame(403);
        self::assertSame(0, $baixas($meu));
    }

    #[TestDox('Marcar todas como lidas (F3): avisos da Caixa e conversas da pessoa; gestão, colega e outro escritório intactos')]
    public function testMarcarTudo(): void
    {
        $client = $this->cliente();
        [, $tenant] = $this->criarAdmin();
        [, $outro] = $this->criarAdmin();
        $ana = $this->colega($tenant, 'ana');
        $bia = $this->colega($tenant, 'bia');
        $this->aviso($ana, $tenant, Notificacao::TIPO_TAREFA_CRIADA, 'Caixa');
        $gestao = (int) $this->aviso($ana, $tenant, Notificacao::TIPO_SERVICEDESK_NOVO, 'Gestão')->getId();
        $deOutro = (int) $this->aviso($ana, $outro, Notificacao::TIPO_TAREFA_CRIADA, 'Outro')->getId();
        $daBia = (int) $this->aviso($bia, $tenant, Notificacao::TIPO_TAREFA_CRIADA, 'Da Bia')->getId();

        // Bia escreve para Ana (não lidas da Ana); Ana escreve uma (não lida da Bia).
        $this->logarComTenant($client, $bia, $tenant);
        $conversa = $this->abrir($client, $ana);
        $this->enviar($client, $conversa, 'um');
        $this->enviar($client, $conversa, 'dois');
        $this->logarComTenant($client, $ana, $tenant);
        $this->enviar($client, $conversa, 'resposta');

        $this->post($client, '/central/ler-tudo', []);
        self::assertResponseIsSuccessful();
        $client->request('GET', '/central/novidades');
        $d = $this->json($client);
        self::assertSame([0, 0], [$d['naoLidas'], $d['avisosNaoLidos']]);
        $lida = fn (int $id): bool => (bool) $this->em()->getConnection()->fetchOne('SELECT lida FROM notificacao WHERE id = ?', [$id]);
        self::assertFalse($lida($gestao), 'gestão não é da Caixa');
        self::assertFalse($lida($deOutro), 'outro escritório');
        self::assertFalse($lida($daBia), 'aviso da colega');

        $this->logarComTenant($client, $bia, $tenant);
        $client->request('GET', '/central/novidades');
        self::assertSame(1, $this->json($client)['naoLidas'], 'as não lidas da Bia continuam');
    }

    #[TestDox('Limpar lidas (F3): apaga só as lidas da Caixa da pessoa neste escritório; não lidas, gestão, colega e outro escritório ficam')]
    public function testLimparLidos(): void
    {
        $client = $this->cliente();
        [, $tenant] = $this->criarAdmin();
        [, $outro] = $this->criarAdmin();
        $ana = $this->colega($tenant, 'ana');
        $bia = $this->colega($tenant, 'bia');
        $lida = (int) $this->aviso($ana, $tenant, Notificacao::TIPO_TAREFA_CRIADA, 'Lida', null, true)->getId();
        $resolvida = (int) $this->aviso($ana, $tenant, Notificacao::TIPO_TAREFA_CONCLUIDA, 'Resolvida', null, true)->getId();
        $ficam = [
            (int) $this->aviso($ana, $tenant, Notificacao::TIPO_TAREFA_CRIADA, 'Não lida')->getId(),
            (int) $this->aviso($ana, $tenant, Notificacao::TIPO_SERVICEDESK_NOVO, 'Gestão lida', null, true)->getId(),
            (int) $this->aviso($ana, $outro, Notificacao::TIPO_TAREFA_CRIADA, 'Outro lida', null, true)->getId(),
            (int) $this->aviso($bia, $tenant, Notificacao::TIPO_TAREFA_CRIADA, 'Bia lida', null, true)->getId(),
        ];
        $this->logarComTenant($client, $ana, $tenant);
        $this->post($client, "/central/avisos/$resolvida/resolver", []);

        $this->post($client, '/central/avisos/limpar-lidos', []);
        self::assertResponseIsSuccessful();
        self::assertSame(['apagadas' => 2], $this->json($client));
        $existe = fn (int $id): bool => (int) $this->em()->getConnection()->fetchOne('SELECT COUNT(*) FROM notificacao WHERE id = ?', [$id]) === 1;
        self::assertFalse($existe($lida));
        self::assertFalse($existe($resolvida));
        self::assertSame(0, (int) $this->em()->getConnection()->fetchOne('SELECT COUNT(*) FROM central_aviso_resolvido'), 'a baixa cai junto (CASCADE)');
        foreach ($ficam as $id) {
            self::assertTrue($existe($id));
        }
    }

    #[TestDox('Excluir um aviso (botão direito): apaga só a MINHA notificação, mesmo não lida; alheio, de gestão ou de outro escritório é 404; sem CSRF é 403')]
    public function testExcluirUm(): void
    {
        $client = $this->cliente();
        [, $tenant] = $this->criarAdmin();
        [, $outro] = $this->criarAdmin();
        $ana = $this->colega($tenant, 'ana');
        $bia = $this->colega($tenant, 'bia');
        $meu = (int) $this->aviso($ana, $tenant, Notificacao::TIPO_TAREFA_CONCLUIDA, 'Meu, não lido')->getId();
        $outroMeu = (int) $this->aviso($ana, $tenant, Notificacao::TIPO_TAREFA_CRIADA, 'Fica')->getId();
        $intocaveis = [
            (int) $this->aviso($bia, $tenant, Notificacao::TIPO_TAREFA_CONCLUIDA, 'Da Bia')->getId(),
            (int) $this->aviso($ana, $tenant, Notificacao::TIPO_SERVICEDESK_NOVO, 'Gestão')->getId(),
            (int) $this->aviso($ana, $outro, Notificacao::TIPO_TAREFA_CRIADA, 'Outro escritório')->getId(),
        ];
        $existe = fn (int $id): bool => (int) $this->em()->getConnection()->fetchOne('SELECT COUNT(*) FROM notificacao WHERE id = ?', [$id]) === 1;

        $this->logarComTenant($client, $ana, $tenant);
        $this->post($client, "/central/avisos/$meu/resolver", []);
        $client->request('POST', "/central/avisos/$meu/excluir", [], [], ['CONTENT_TYPE' => 'application/json'], '{}');
        self::assertResponseStatusCodeSame(403);
        self::assertTrue($existe($meu));

        $this->post($client, "/central/avisos/$meu/excluir", []);
        self::assertResponseIsSuccessful();
        self::assertFalse($existe($meu));
        self::assertTrue($existe($outroMeu));
        self::assertSame(0, (int) $this->em()->getConnection()->fetchOne('SELECT COUNT(*) FROM central_aviso_resolvido'), 'a baixa cai junto (CASCADE)');
        $client->request('GET', '/central/avisos');
        self::assertSame([$outroMeu], array_column($this->json($client)['avisos'], 'id'));

        $this->post($client, "/central/avisos/$meu/excluir", []);
        self::assertResponseStatusCodeSame(404, 'já excluído');
        foreach ($intocaveis as $id) {
            $this->post($client, "/central/avisos/$id/excluir", []);
            self::assertResponseStatusCodeSame(404);
            self::assertTrue($existe($id));
        }
    }

    #[TestDox('Aviso de META com pasta sem acesso some da lista e da contagem; se a pessoa é responsável pela meta, aparece')]
    public function testMetaDePastaSemAcesso(): void
    {
        $client = $this->cliente();
        [, $tenant] = $this->criarAdmin();
        $ana = $this->colega($tenant, 'ana');   // sem permissão de pasta
        $pasta = \App\Tests\Factory\Pasta\PastaFactory::createOne(['tenant' => $tenant])->_real();
        $meta = \App\Tests\Factory\Tarefa\TarefaFactory::createOne(['pasta' => $pasta, 'tenant' => $tenant])->_real();
        $this->em()->persist((new Notificacao())->setUsuario($ana)->setTenant($tenant)->setTipo(Notificacao::TIPO_TAREFA_CRIADA)
            ->setTitulo('META-DA-PASTA')->setTarefa($meta));
        $this->em()->flush();

        $this->logarComTenant($client, $ana, $tenant);
        $client->request('GET', '/central/avisos');
        self::assertSame([], array_column($this->json($client)['avisos'], 'titulo'));
        $client->request('GET', '/central/novidades');
        self::assertSame(0, $this->json($client)['avisosNaoLidos']);

        // Responsável pela meta: vê o aviso dela (com o link da meta) mesmo sem a pasta.
        $this->em()->getConnection()->executeStatement('INSERT INTO tarefa_responsaveis (tarefa_id, user_id) VALUES (?, ?)', [$meta->getId(), $ana->getId()]);
        $this->em()->clear();
        $client->request('GET', '/central/avisos');
        $avisos = $this->json($client)['avisos'];
        self::assertSame(['META-DA-PASTA'], array_column($avisos, 'titulo'));
        self::assertSame('/tarefas/' . $meta->getId(), $avisos[0]['url']);
        $client->request('GET', '/central/novidades');
        self::assertSame(1, $this->json($client)['avisosNaoLidos']);
    }

    #[TestDox('Todo não lido aparece na lista, mesmo mais antigo que os 100 mais recentes (a contagem e a lista fecham)')]
    public function testNaoLidoAntigoAparece(): void
    {
        $client = $this->cliente();
        [, $tenant] = $this->criarAdmin();
        $ana = $this->colega($tenant, 'ana');
        $this->aviso($ana, $tenant, Notificacao::TIPO_TAREFA_CRIADA, 'ANTIGO-NAO-LIDO');
        for ($i = 0; $i < 105; $i++) {
            $this->em()->persist((new Notificacao())->setUsuario($ana)->setTenant($tenant)->setTipo(Notificacao::TIPO_TAREFA_CONCLUIDA)->setTitulo('lido ' . $i)->setLida(true));
        }
        $this->em()->flush();

        $this->logarComTenant($client, $ana, $tenant);
        $client->request('GET', '/central/avisos');
        $avisos = $this->json($client)['avisos'];
        self::assertCount(100, $avisos);
        self::assertContains('ANTIGO-NAO-LIDO', array_column($avisos, 'titulo'));
        $client->request('GET', '/central/novidades');
        self::assertSame(1, $this->json($client)['avisosNaoLidos']);
    }

    #[TestDox('O maior id do polling ignora gestão e os avisos da Central (senão a lista recarregaria à toa)')]
    public function testUltimoAvisoIgnoraForaDaCaixa(): void
    {
        $client = $this->cliente();
        [, $tenant] = $this->criarAdmin();
        $ana = $this->colega($tenant, 'ana');
        $daCaixa = (int) $this->aviso($ana, $tenant, Notificacao::TIPO_TAREFA_CRIADA, 'Caixa')->getId();
        $this->aviso($ana, $tenant, Notificacao::TIPO_SERVICEDESK_NOVO, 'Gestão depois');
        $this->aviso($ana, $tenant, Notificacao::TIPO_CENTRAL_MENCAO, 'Menção depois');

        $this->logarComTenant($client, $ana, $tenant);
        $client->request('GET', '/central/novidades');
        self::assertSame($daCaixa, $this->json($client)['ultimoAviso']);
    }
}
