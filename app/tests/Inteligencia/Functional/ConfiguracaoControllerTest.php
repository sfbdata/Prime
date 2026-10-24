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

    #[TestDox('bloco "Consumo do mês": análises e limite, gasto em US$ só deste escritório, orçamento, modo e a nota; entre o estado e a configuração')]
    public function testBlocoDeConsumoDoMes(): void
    {
        $client = static::createClient();
        [$admin, $tenant] = $this->criarAdmin();
        $configuracao = $this->ligarIaNoTenant($tenant, $admin, limiteMensal: 300);
        $configuracao->definirCobranca(\App\Inteligencia\Enum\ModoDeCobrancaDaIa::Adicional, 1250);
        // 100 + 30 tokens do modelo falso (fora do enum: preço do Opus) = 1 centavo, arredondado para cima.
        $this->concluirAnalise($this->criarAnalisePendente($tenant, $admin, $this->criarPasta($tenant)));
        [$outroAdmin, $outro] = $this->criarAdmin();
        $analiseDeOutro = $this->criarAnalisePendente($outro, $outroAdmin, $this->criarPasta($outro));
        $this->concluirAnalise($analiseDeOutro);
        $this->em()->getConnection()->executeStatement('UPDATE inteligencia_analise SET tokens_entrada = 90000000 WHERE id = ?', [(int) $analiseDeOutro->getId()]);
        $this->logarComTenant($client, $admin, $tenant);

        $crawler = $client->request('GET', '/admin/inteligencia');

        self::assertResponseIsSuccessful();
        $blocos = $crawler->filter('section.content > div.container-fluid > div.card');
        self::assertSame(['ia-estado', 'ia-consumo', 'ia-configuracao'], $blocos->each(static fn ($b): ?string => $b->attr('id')));
        $consumo = $crawler->filter('section.content > div.container-fluid > div.card#ia-consumo');
        self::assertSame('Consumo do mês', trim($consumo->filter('div.card#ia-consumo > div.card-header > strong')->text()));
        $dl = $consumo->filter('div.card#ia-consumo > div.card-body > dl.row');
        self::assertSame(['Análises no mês', 'Gasto estimado', 'Orçamento mensal', 'Cobrança'], $dl->filter('dl.row > dt')->each(static fn ($c): string => trim($c->text())));
        self::assertSame('1 de 300', trim($dl->filter('dl.row > dd[data-ia-consumo="analises"]')->text()));
        self::assertSame('US$ 0,01', trim($dl->filter('dl.row > dd[data-ia-consumo="gasto"]')->text()), 'o gasto do outro escritório não entra');
        self::assertSame('US$ 12,50', trim($dl->filter('dl.row > dd[data-ia-consumo="orcamento"]')->text()));
        self::assertSame('Adicional pago', trim($dl->filter('dl.row > dd[data-ia-consumo="modo"]')->text()));
        self::assertSame('Valores estimados pelo preço de tabela da Anthropic.', trim($consumo->filter('div.card#ia-consumo > div.card-body > p#ia-consumo-nota')->text()));
        self::assertStringNotContainsString('—', $consumo->text());
        self::assertStringNotContainsString('–', $consumo->text());
    }

    #[TestDox('bloco "Consumo do mês" sem orçamento: a linha do orçamento sai, o modo padrão aparece')]
    public function testBlocoDeConsumoSemOrcamento(): void
    {
        $client = static::createClient();
        [$admin, $tenant] = $this->criarAdmin();
        $this->ligarIaNoTenant($tenant, $admin);
        $this->logarComTenant($client, $admin, $tenant);

        $crawler = $client->request('GET', '/admin/inteligencia');

        self::assertResponseIsSuccessful();
        $dl = $crawler->filter('section.content > div.container-fluid > div.card#ia-consumo > div.card-body > dl.row');
        self::assertSame(0, $dl->filter('dl.row > dd[data-ia-consumo="orcamento"]')->count());
        self::assertSame('US$ 0,00', trim($dl->filter('dl.row > dd[data-ia-consumo="gasto"]')->text()));
        self::assertSame('Incluso no plano', trim($dl->filter('dl.row > dd[data-ia-consumo="modo"]')->text()));
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
