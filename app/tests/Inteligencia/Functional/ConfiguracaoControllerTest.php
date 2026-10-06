<?php

declare(strict_types=1);

namespace App\Tests\Inteligencia\Functional;

use App\Entity\Audit\AuditLog;
use App\Inteligencia\Controller\ConfiguracaoController;
use App\Inteligencia\Entity\ConfiguracaoDeInteligencia;
use App\Tests\Functional\JusPrimeWebTestCase;
use App\Tests\Inteligencia\Support\CriaFixturesInteligenciaTrait;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;

#[CoversClass(ConfiguracaoController::class)]
final class ConfiguracaoControllerTest extends JusPrimeWebTestCase
{
    use CriaFixturesInteligenciaTrait;

    private const FORM = 'configuracao_de_inteligencia';

    #[TestDox('sem admin.inteligencia.manage → 403')]
    public function testSemPermissao(): void
    {
        $client = static::createClient();
        [, $tenant] = $this->criarAdmin();
        $user = $this->criarUsuarioComPermissoes($tenant, ['resources.pasta.view', 'modules.inteligencia.view']);
        $this->logarComTenant($client, $user, $tenant);

        $client->request('GET', '/admin/inteligencia');

        self::assertResponseStatusCodeSame(403);
    }

    #[TestDox('quem tem admin.inteligencia.manage (sem ser admin do escritório) abre o painel')]
    public function testComPermissaoEspecifica(): void
    {
        $client = static::createClient();
        [, $tenant] = $this->criarAdmin();
        $user = $this->criarUsuarioComPermissoes($tenant, ['admin.inteligencia.manage']);
        $this->logarComTenant($client, $user, $tenant);

        $crawler = $client->request('GET', '/admin/inteligencia');

        self::assertResponseIsSuccessful();
        self::assertSame(1, $crawler->filter('#ia-configuracao form')->count());
        self::assertSame(1, $crawler->filter('#ia-estado')->count());
    }

    #[TestDox('admin liga a IA e muda as cotas: linha criada, consentimento registrado e audit_log com create')]
    public function testLigaEMudaLimite(): void
    {
        $client = static::createClient();
        [$admin, $tenant] = $this->criarAdmin();
        $this->logarComTenant($client, $admin, $tenant);

        $crawler = $client->request('GET', '/admin/inteligencia');
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('BlueJus IA', (string) $client->getResponse()->getContent());

        $form = $crawler->selectButton('Salvar')->form();
        $form[self::FORM . '[habilitada]']->tick();
        $form[self::FORM . '[limiteDiario]'] = '7';
        $form[self::FORM . '[limiteMensal]'] = '70';
        $client->submit($form);

        self::assertResponseRedirects('/admin/inteligencia');
        $client->followRedirect();
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('Configuração da BlueJus IA salva.', (string) $client->getResponse()->getContent());
        self::assertStringContainsString('Aceite registrado em', (string) $client->getResponse()->getContent());

        $this->em()->clear();
        $configuracao = $this->em()->getRepository(ConfiguracaoDeInteligencia::class)->findOneBy(['tenant' => $tenant->getId()]);
        self::assertNotNull($configuracao);
        self::assertTrue($configuracao->isHabilitada());
        self::assertSame(7, $configuracao->getLimiteDiario());
        self::assertSame(70, $configuracao->getLimiteMensal());
        self::assertTrue($configuracao->isMascararDadosPessoais());
        self::assertNotNull($configuracao->getConsentimentoEnvioExternoEm());
        self::assertSame($admin->getId(), $configuracao->getConsentimentoPor()?->getId());

        $criacoes = array_filter(
            $this->em()->getRepository(AuditLog::class)->findBy(['entityClass' => ConfiguracaoDeInteligencia::class, 'action' => 'create']),
            static fn (AuditLog $log): bool => $log->getEntityId() === (string) $configuracao->getId(),
        );
        self::assertCount(1, $criacoes);
        $log = array_values($criacoes)[0];
        self::assertSame($tenant->getId(), $log->getTenantId());
        self::assertSame($admin->getId(), $log->getActorUserId());
    }

    #[TestDox('desligar depois de ligada: a linha é atualizada (audit update com habilitada) e o consentimento fica como histórico')]
    public function testDesliga(): void
    {
        $client = static::createClient();
        [$admin, $tenant] = $this->criarAdmin();
        $configuracao = $this->ligarIaNoTenant($tenant, $admin, limiteDiario: 5);
        $aceiteEm = $configuracao->getConsentimentoEnvioExternoEm();
        $this->logarComTenant($client, $admin, $tenant);

        $crawler = $client->request('GET', '/admin/inteligencia');
        $form = $crawler->selectButton('Salvar')->form();
        self::assertSame('5', $form[self::FORM . '[limiteDiario]']->getValue(), 'o formulário nasce com o valor gravado');
        $form[self::FORM . '[habilitada]']->untick();
        $client->submit($form);
        self::assertResponseRedirects('/admin/inteligencia');

        $this->em()->clear();
        $relida = $this->em()->find(ConfiguracaoDeInteligencia::class, $configuracao->getId());
        self::assertNotNull($relida);
        self::assertFalse($relida->isHabilitada());
        self::assertEquals($aceiteEm, $relida->getConsentimentoEnvioExternoEm(), 'desligar não apaga o registro do aceite');

        $atualizacoes = array_filter(
            $this->em()->getRepository(AuditLog::class)->findBy(['entityClass' => ConfiguracaoDeInteligencia::class, 'action' => 'update']),
            static fn (AuditLog $log): bool => $log->getEntityId() === (string) $configuracao->getId()
                && str_contains((string) json_encode($log->getChanges()), 'habilitada'),
        );
        self::assertCount(1, $atualizacoes);
    }

    #[TestDox('limite negativo é recusado pelo formulário, nada gravado')]
    public function testLimiteNegativoRecusado(): void
    {
        $client = static::createClient();
        [$admin, $tenant] = $this->criarAdmin();
        $this->logarComTenant($client, $admin, $tenant);

        $crawler = $client->request('GET', '/admin/inteligencia');
        $form = $crawler->selectButton('Salvar')->form();
        $form[self::FORM . '[limiteDiario]'] = '-1';
        $client->submit($form);

        self::assertResponseStatusCodeSame(200, 'volta ao formulário com erro, sem redirecionar');
        self::assertStringContainsString('is-invalid', (string) $client->getResponse()->getContent());
        self::assertSame(0, $this->contarNoBanco('SELECT count(*) FROM inteligencia_configuracao WHERE tenant_id = :t', ['t' => $tenant->getId()]));
    }
}
