<?php

declare(strict_types=1);

namespace App\Tests\Voto\Functional;

use App\Entity\Auth\UserTenant;
use App\Entity\Tenant\Tenant;
use App\Entity\Tenant\TenantRole;
use App\Master\Entity\RecursoEstado;
use App\Tests\Factory\Auth\UserFactory;
use App\Tests\Factory\Tenant\TenantFactory;
use App\Tests\Factory\Voto\AssembleiaFactory;
use App\Tests\Factory\Voto\PautaDaAssembleiaFactory;
use App\Tests\Factory\Voto\UnidadeDaAssembleiaFactory;
use App\Tests\Functional\JusPrimeWebTestCase;
use App\Voto\Controller\AssembleiaController;
use App\Voto\DTO\PautaNoResumo;
use App\Voto\DTO\ResumoDoResultado;
use App\Voto\Entity\Assembleia;
use App\Voto\Entity\LiberacaoDoVoto;
use App\Voto\Enum\CenarioDaAssembleia;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\DomCrawler\Crawler;
use Twig\Environment;
use Zenstruck\Foundry\Test\Factories;

/**
 * Cenário (tipo de entidade) no passo 1 da assembleia e nos documentos (W2-1): botões da categoria como o desenho,
 * só em rascunho, rótulo da entidade e linhas de assinatura da ata e do PDF do resumo.
 */
#[CoversClass(AssembleiaController::class)]
final class CenarioDaAssembleiaTest extends JusPrimeWebTestCase
{
    use Factories;

    private KernelBrowser $client;
    private EntityManagerInterface $em;
    private Tenant $escritorio;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->client->disableReboot();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        $this->escritorio = TenantFactory::createOne(['name' => 'Escritório do Cenário ' . uniqid()])->_real();
        $estado = $this->em->getRepository(RecursoEstado::class)->findOneBy(['chave' => 'voto']);
        if (null !== $estado) {
            $estado->reativar(null, new \DateTimeImmutable());
            $this->em->flush();
        }
        $liberacao = new LiberacaoDoVoto($this->escritorio);
        $liberacao->liberar();
        $this->em->persist($liberacao);
        $this->em->flush();
        $user = UserFactory::createOne(['fullName' => 'Síndica Teste', 'roles' => ['ROLE_USER']])->_real();
        $papel = (new TenantRole())->setTenant($this->escritorio)->setName('Perfil do Voto ' . uniqid())->setIsSystem(true);
        $this->em->persist($papel);
        $this->em->persist((new UserTenant($user, $this->escritorio))->setTenantRole($papel));
        $this->em->flush();
        $this->logarComTenant($this->client, $user, $this->escritorio);
    }

    #[TestDox('Passo 1: 9 botões de categoria, condomínio marcado, rótulo "Condomínio" e o aviso do condomínio')]
    public function testFormNovaMostraOsCenarios(): void
    {
        $form = $this->client->request('GET', '/voto/assembleias/nova');
        self::assertResponseIsSuccessful();
        self::assertCount(9, $form->filter('.vCats > label.vCat'));
        self::assertSame('Assembleia de condomínio', trim($form->filter('.vCats > label.vCatOn')->text()));
        self::assertSame('condominio', $form->filter('.vCats input[name="cenario"][checked]')->attr('value'));
        self::assertSame('Condomínio', trim($form->filter('[data-voto-cenario-entidade]')->text()));
        self::assertStringStartsWith('Votos por fração ideal', trim($form->filter('[data-voto-cenario-ajuda]')->text()));
        self::assertSame('cooperativa', $form->filter('input[name="cenario"][value="cooperativa"]')->attr('value'));
        self::assertSame('Cooperativa', $form->filter('input[value="cooperativa"]')->attr('data-entidade'));
        self::assertSame('unidade', $form->filter('input[value="cooperativa"]')->attr('data-peso'));
    }

    #[TestDox('Criar com outro cenário grava, e o rascunho reabre com o rótulo e o botão do cenário')]
    public function testCriarGravaEMostraOCenario(): void
    {
        $form = $this->client->request('GET', '/voto/assembleias/nova')->selectButton('Continuar')->form(['titulo' => 'Eleição do sindicato', 'peso' => 'unidade', 'cenario' => 'sindicato']);
        $this->client->submit($form);
        self::assertResponseRedirects();
        $a = $this->em->getRepository(Assembleia::class)->findOneBy(['titulo' => 'Eleição do sindicato']);
        self::assertInstanceOf(Assembleia::class, $a);
        self::assertSame(CenarioDaAssembleia::Sindicato, $a->getCenario());

        $editar = $this->client->request('GET', '/voto/assembleias/' . $a->getId() . '/editar');
        self::assertSame('Sindicato', trim($editar->filter('.vCats > label.vCatOn')->text()));
        self::assertSame('Sindicato', trim($editar->filter('[data-voto-cenario-entidade]')->text()));
        self::assertSame('Confira no estatuto as regras de chapa e de quórum.', trim($editar->filter('[data-voto-cenario-ajuda]')->text()));
    }

    #[TestDox('Sem o campo cenário no POST, a assembleia fica como condomínio')]
    public function testPostSemCenarioFicaCondominio(): void
    {
        $form = $this->client->request('GET', '/voto/assembleias/nova')->selectButton('Continuar')->form(['titulo' => 'AGO sem campo', 'peso' => 'fracao']);
        $valores = $form->getPhpValues();
        unset($valores['cenario']);
        $this->client->request('POST', '/voto/assembleias/nova', $valores);
        $a = $this->em->getRepository(Assembleia::class)->findOneBy(['titulo' => 'AGO sem campo']);
        self::assertInstanceOf(Assembleia::class, $a);
        self::assertSame(CenarioDaAssembleia::Condominio, $a->getCenario());
    }

    #[TestDox('Editar troca o cenário do rascunho; na publicada o formulário recusa e o cenário não muda')]
    public function testEditarSoEmRascunho(): void
    {
        $rascunho = AssembleiaFactory::new(['tenant' => $this->escritorio, 'titulo' => 'Rascunho do cenário'])->create()->_real();
        $form = $this->client->request('GET', '/voto/assembleias/' . $rascunho->getId() . '/editar')->selectButton('Continuar')->form(['cenario' => 'ensino']);
        $this->client->submit($form);
        self::assertResponseRedirects();
        self::assertSame(CenarioDaAssembleia::Ensino, $this->recarregar($rascunho)->getCenario());

        $publicada = AssembleiaFactory::new(['tenant' => $this->escritorio, 'titulo' => 'Publicada do cenário'])->publicada()->create()->_real();
        $form = $this->client->request('GET', '/voto/assembleias/' . $publicada->getId() . '/editar')->selectButton('Continuar')->form(['cenario' => 'consulta']);
        $this->client->submit($form);
        self::assertResponseIsSuccessful();
        self::assertSame(CenarioDaAssembleia::Condominio, $this->recarregar($publicada)->getCenario());
    }

    #[TestDox('Ata: o rótulo da entidade e as duas assinaturas vêm do cenário; condomínio mantém as de antes')]
    public function testAtaComRotulosDoCenario(): void
    {
        $sociedade = $this->publicadaComPauta(CenarioDaAssembleia::Sociedade, 'Sociedade Exemplo S.A.');
        $ata = $this->client->request('GET', '/voto/assembleias/' . $sociedade->getId() . '/ata');
        self::assertResponseIsSuccessful();
        self::assertSame(['Presidência da reunião', 'Secretaria da reunião'], $ata->filter('.voto-ata-assinaturas > div')->each(static fn (Crawler $c): string => trim($c->text())));
        self::assertSame('Sociedade Exemplo S.A.', $this->campo($ata, 'Sociedade'));

        $condominio = $this->publicadaComPauta(CenarioDaAssembleia::Condominio, null);
        $ata = $this->client->request('GET', '/voto/assembleias/' . $condominio->getId() . '/ata');
        self::assertSame(['Presidência da assembleia', 'Secretaria da assembleia'], $ata->filter('.voto-ata-assinaturas > div')->each(static fn (Crawler $c): string => trim($c->text())));
        self::assertSame('Não informado', $this->campo($ata, 'Condomínio'));
    }

    #[TestDox('PDF do resumo: rótulo da entidade e linhas de assinatura do cenário')]
    public function testPdfDoResumoComRotulosDoCenario(): void
    {
        $twig = static::getContainer()->get(Environment::class);
        $html = $twig->render('voto/assembleia/resumo_resultado_pdf.html.twig', [
            'resumo' => new ResumoDoResultado(1, 'Consulta de 2026', 'https://bluejus.test/v/x', 'ABCD1234', new \DateTimeImmutable('2026-11-17'), str_repeat('a', 64), [new PautaNoResumo('Pergunta', '3 de 4', '17/11/2026', 'Quórum não exigido', 'Aprovada', [['rotulo' => '1  Sim', 'votos' => 3]])], CenarioDaAssembleia::Consulta, 'Cooperativa Alfa'),
            'qr' => 'data:image/png;base64,AAAA',
            'logo' => null,
        ]);

        self::assertStringContainsString('Organização', $html);
        self::assertStringContainsString('Cooperativa Alfa', $html);
        self::assertStringContainsString('Responsável pela consulta', $html);
        self::assertStringContainsString('Auditoria', $html);
        self::assertStringNotContainsString('Presidência da assembleia', $html);
    }

    private function publicadaComPauta(CenarioDaAssembleia $cenario, ?string $entidade): Assembleia
    {
        $a = AssembleiaFactory::new(['tenant' => $this->escritorio, 'cenario' => $cenario, 'entidadeNome' => $entidade])->publicada()->create()->_real();
        UnidadeDaAssembleiaFactory::createOne(['assembleia' => $a, 'identificacao' => 'U1']);
        PautaDaAssembleiaFactory::new(['assembleia' => $a, 'ordem' => 1, 'titulo' => 'Pauta única'])->comOpcoesPadrao()->create();
        $this->em->flush();
        $this->em->refresh($a);

        return $a;
    }

    private function recarregar(Assembleia $a): Assembleia
    {
        $id = $a->getId();
        $this->em->clear();

        return $this->em->find(Assembleia::class, $id) ?? throw new \LogicException('Assembleia sumiu.');
    }

    private function campo(Crawler $ata, string $rotulo): ?string
    {
        $achado = null;
        $ata->filter('dl.voto-ata-campos > div')->each(static function (Crawler $c) use ($rotulo, &$achado): void {
            if (trim($c->filter('dt')->text()) === $rotulo) {
                $achado ??= trim($c->filter('dd')->text());
            }
        });

        return $achado;
    }
}
