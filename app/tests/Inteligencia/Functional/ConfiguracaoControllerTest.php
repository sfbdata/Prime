<?php

declare(strict_types=1);

namespace App\Tests\Inteligencia\Functional;

use App\Entity\Audit\AuditLog;
use App\Inteligencia\Controller\ConfiguracaoController;
use App\Inteligencia\Entity\ConfiguracaoDeInteligencia;
use App\Inteligencia\Enum\SecaoDoContexto;
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

    #[TestDox('o aviso de envio lista CADA seção que os agentes leem (SecaoDoContexto), na ordem do enum, sem travessão')]
    public function testAvisoDeEnvioListaTodasAsSecoes(): void
    {
        $client = static::createClient();
        [$admin, $tenant] = $this->criarAdmin();
        $this->logarComTenant($client, $admin, $tenant);

        $crawler = $client->request('GET', '/admin/inteligencia');
        self::assertResponseIsSuccessful();

        $aviso = $crawler->filter('#ia-configuracao #ia-aviso-envio');
        self::assertSame(1, $aviso->count());
        $itens = $aviso->filter('#ia-aviso-envio-secoes > [data-ia-secao]');
        self::assertSame(\count(SecaoDoContexto::cases()), $itens->count(), 'uma entrada por seção do enum, nem mais nem menos');

        foreach (SecaoDoContexto::cases() as $indice => $secao) {
            self::assertSame($secao->value, $itens->eq($indice)->attr('data-ia-secao'));
            self::assertSame($secao->rotuloCurto(), trim($itens->eq($indice)->text()));
        }

        $texto = $aviso->text();
        // O que vai sempre, fora das seções: cabeçalho da pasta e dos processos.
        self::assertStringContainsString('nomes da equipe', $texto);
        self::assertStringContainsString('processos vinculados', $texto);
        self::assertStringContainsString('Anexo I', $texto);
        self::assertStringContainsString('segredo de justiça nunca são enviados', $texto);
        self::assertStringNotContainsString('—', $texto);
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

        // No `create` o id ainda não existe no onFlush (IDENTITY), então o rastro é reconhecido
        // pelo escritório e pelo conteúdo gravado — não pelo entity_id.
        $criacoes = array_filter(
            $this->em()->getRepository(AuditLog::class)->findBy([
                'entityClass' => ConfiguracaoDeInteligencia::class,
                'action' => 'create',
                'tenantId' => $tenant->getId(),
            ]),
            static fn (AuditLog $log): bool => (($log->getChanges()['diff']['after']['limiteDiario'] ?? null) === 7),
        );
        self::assertCount(1, $criacoes, 'ligar a IA tem de deixar exatamente um create no audit_log deste escritório');
        $log = array_values($criacoes)[0];
        self::assertSame($admin->getId(), $log->getActorUserId());
        self::assertTrue($log->getChanges()['diff']['after']['habilitada'] ?? false);
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
        // A coluna é TIMESTAMP(0): a comparação é no segundo, não no microssegundo.
        self::assertSame(
            $aceiteEm?->format('Y-m-d H:i:s'),
            $relida->getConsentimentoEnvioExternoEm()?->format('Y-m-d H:i:s'),
            'desligar não apaga o registro do aceite',
        );

        $atualizacoes = array_filter(
            $this->em()->getRepository(AuditLog::class)->findBy(['entityClass' => ConfiguracaoDeInteligencia::class, 'action' => 'update']),
            static fn (AuditLog $log): bool => $log->getEntityId() === (string) $configuracao->getId()
                && str_contains((string) json_encode($log->getChanges()), 'habilitada'),
        );
        self::assertCount(1, $atualizacoes);
    }

    #[TestDox('PR F: o aviso cita os documentos escolhidos; aceite antigo mostra o alerta e salvar ligada renova o aceite')]
    public function testAceiteAntigoRenovadoAoSalvar(): void
    {
        $client = static::createClient();
        [$admin, $tenant] = $this->criarAdmin();
        $configuracao = $this->ligarIaNoTenant($tenant, $admin);
        $this->envelhecerAceite($configuracao);
        $this->logarComTenant($client, $admin, $tenant);

        $crawler = $client->request('GET', '/admin/inteligencia');
        self::assertResponseIsSuccessful();
        $item = $crawler->filter('#ia-aviso-envio #ia-aviso-envio-documentos[data-ia-bloco="documentos_anexados"]');
        self::assertSame(1, $item->count());
        self::assertStringContainsString('documentos', $item->text());
        $alerta = $crawler->filter('#ia-aceite-documentos');
        self::assertSame(1, $alerta->count());
        self::assertStringNotContainsString('—', $alerta->text());

        $form = $crawler->selectButton('Salvar')->form();
        $client->submit($form);
        self::assertResponseRedirects('/admin/inteligencia');
        $crawler = $client->followRedirect();
        self::assertSame(0, $crawler->filter('#ia-aceite-documentos')->count(), 'renovado, o alerta some');

        $this->em()->clear();
        $relida = $this->em()->find(ConfiguracaoDeInteligencia::class, $configuracao->getId());
        self::assertNotNull($relida);
        self::assertTrue($relida->aceiteCobreDocumentos());
        self::assertSame($admin->getId(), $relida->getConsentimentoPor()?->getId());
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

        // Padrão do Symfony 7 (e do repo): `render()` com form submetido e inválido responde 422.
        self::assertResponseStatusCodeSame(422, 'volta ao formulário com erro, sem redirecionar');
        self::assertStringContainsString('is-invalid', (string) $client->getResponse()->getContent());
        self::assertSame(0, $this->contarNoBanco('SELECT count(*) FROM inteligencia_configuracao WHERE tenant_id = :t', ['t' => $tenant->getId()]));
    }
}
