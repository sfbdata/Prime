<?php

declare(strict_types=1);

namespace App\Tests\Voto\Functional;

use App\Entity\Auth\User;
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
use App\Voto\Entity\Assembleia;
use App\Voto\Entity\LiberacaoDoVoto;
use App\Voto\Entity\ParticipacaoNaPauta;
use App\Voto\Enum\PesoDoVoto;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\DomCrawler\Crawler;
use Zenstruck\Foundry\Test\Factories;

/**
 * Assistente de 5 passos do cadastro da assembleia (desenho 18, L254-268): o trilho clicável (feito, atual), cada passo
 * gravando pelo caminho que já existia, a revisão com pendências, a urna do rascunho e o aviso de alterações não salvas.
 */
#[CoversClass(AssembleiaController::class)]
final class AssistenteDaAssembleiaTest extends JusPrimeWebTestCase
{
    use Factories;

    private const NOMES = ['Categoria', 'Cargos', 'Lista de aptos', 'Equipe', 'Revisar'];

    private KernelBrowser $client;
    private EntityManagerInterface $em;
    private Tenant $escritorio;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->client->disableReboot();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        $this->escritorio = TenantFactory::createOne(['name' => 'Condomínio do Assistente ' . uniqid()])->_real();
        $liberacao = new LiberacaoDoVoto($this->escritorio);
        $liberacao->liberar();
        $this->em->persist($liberacao);
        $estado = $this->em->getRepository(RecursoEstado::class)->findOneBy(['chave' => 'voto']);
        $estado?->reativar(null, new \DateTimeImmutable());
        $this->em->flush();
        $this->logarComTenant($this->client, $this->usuario(), $this->escritorio);
    }

    /** @return list<string> os textos do trilho, na ordem */
    private function trilho(Crawler $pagina): array
    {
        return $pagina->filter('.voto-assistente > .voto-assistente-corpo > .voto-trilho > .vPas')->each(static fn (Crawler $c): string => trim($c->text()));
    }

    private function rascunho(bool $pautas = true, bool $unidades = true): Assembleia
    {
        $assembleia = AssembleiaFactory::createOne(['tenant' => $this->escritorio, 'titulo' => 'AGO do Assistente', 'peso' => PesoDoVoto::Unidade])->_real();
        if ($pautas) {
            PautaDaAssembleiaFactory::new(['assembleia' => $assembleia, 'titulo' => 'Aprovação de contas'])->comOpcoesPadrao()->create();
        }
        if ($unidades) {
            UnidadeDaAssembleiaFactory::createOne(['assembleia' => $assembleia]);
        }

        return $assembleia;
    }

    #[TestDox('O trilho tem os 5 passos, em ordem, filhos diretos do assistente (Regras e Acesso foram para o passo 1)')]
    public function testTrilhoComOsCincoPassos(): void
    {
        $pagina = $this->client->request('GET', '/voto/assembleias/nova');
        self::assertResponseIsSuccessful();
        $esperado = array_map(static fn (string $n, int $i): string => ($i + 1) . '. ' . $n, self::NOMES, array_keys(self::NOMES));
        self::assertCount(5, self::NOMES);
        self::assertSame($esperado, $this->trilho($pagina));
        self::assertCount(1, $pagina->filter('.voto-trilho > .vPasAt'));
        self::assertSame('1. Categoria', trim($pagina->filter('.voto-trilho > .vPasAt')->text()));
        self::assertSame('Que tipo de votação você vai criar?', trim($pagina->filter('.voto-assistente-corpo .voto-etapa-titulo')->text()));
        self::assertCount(0, $pagina->filter('.nav-tabs, .wizard'), 'sem componente do Bootstrap');
    }

    #[TestDox('Cada passo abre com o trilho marcando os anteriores como feitos e o atual')]
    public function testCadaPassoAbreEMarcaFeitoEAtual(): void
    {
        $id = (int) $this->rascunho()->getId();
        $caminhos = [
            1 => '/voto/assembleias/' . $id . '/editar',
            2 => '/voto/assembleias/' . $id . '/pautas',
            3 => '/voto/assembleias/' . $id . '/unidades',
            4 => '/voto/assembleias/' . $id . '/passo/4',
            5 => '/voto/assembleias/' . $id . '/publicar',
        ];
        foreach ($caminhos as $passo => $caminho) {
            $pagina = $this->client->request('GET', $caminho);
            self::assertResponseIsSuccessful($caminho);
            self::assertCount(5, $pagina->filter('.voto-trilho > .vPas'), $caminho);
            self::assertCount($passo - 1, $pagina->filter('.voto-trilho > .vPasOk'), 'feitos até o passo ' . $passo);
            self::assertSame($passo . '. ' . self::NOMES[$passo - 1], trim($pagina->filter('.voto-trilho > .vPasAt')->text()), $caminho);
            self::assertSame('step', $pagina->filter('.voto-trilho > .vPasAt')->attr('aria-current'));
        }
    }

    #[TestDox('Nos passos que não são formulário o trilho é link para o passo; no formulário é botão que grava e segue')]
    public function testTrilhoClicavel(): void
    {
        $id = (int) $this->rascunho()->getId();
        $pagina = $this->client->request('GET', '/voto/assembleias/' . $id . '/passo/4');
        self::assertSame(
            ['/voto/assembleias/' . $id . '/editar', '/voto/assembleias/' . $id . '/pautas', '/voto/assembleias/' . $id . '/unidades', '/voto/assembleias/' . $id . '/passo/4', '/voto/assembleias/' . $id . '/publicar'],
            $pagina->filter('.voto-trilho > a.vPas')->each(static fn (Crawler $c): string => (string) $c->attr('href')),
        );
        $form = $this->client->request('GET', '/voto/assembleias/' . $id . '/editar');
        self::assertCount(5, $form->filter('form[data-voto-assistente-form] .voto-trilho > button.vPas[name="depois"]'));
        self::assertSame('passo-3', $form->filter('.voto-trilho > button.vPas')->eq(2)->attr('value'));
    }

    #[TestDox('Passo 1: criar grava pelo caminho existente; o peso do voto e o texto do acesso (antigos passos 2 e 3) estão aqui')]
    public function testPasso1GravaPeloCaminhoExistente(): void
    {
        $pagina = $this->client->request('GET', '/voto/assembleias/nova');
        self::assertCount(1, $pagina->filter('select[name="peso"]'), 'o peso do voto agora é do passo 1');
        self::assertCount(0, $pagina->filter('input[type="hidden"][name="peso"]'));
        self::assertCount(1, $pagina->filter('[data-voto-passo-texto]'), 'o texto de como o votante entra');
        $form = $pagina->selectButton('Continuar')->form(['titulo' => 'AGO nova do assistente']);
        $this->client->submit($form);
        self::assertSame(1, preg_match('#/voto/assembleias/(\d+)/pautas$#', (string) $this->client->getResponse()->headers->get('Location'), $m), 'Continuar leva ao passo 2 (Cargos)');
        $this->em->clear();
        $criada = $this->em->find(Assembleia::class, (int) $m[1]);
        self::assertSame('AGO nova do assistente', $criada->getTitulo());
        self::assertSame($this->escritorio->getId(), $criada->getTenant()->getId());
    }

    #[TestDox('Passo 1: clicar em um passo do trilho grava o formulário e abre o passo (edIr do desenho)')]
    public function testTrilhoNoFormularioGravaESegue(): void
    {
        $pagina = $this->client->request('GET', '/voto/assembleias/nova');
        $form = $pagina->filter('.voto-trilho > button[value="passo-3"]')->form(['titulo' => 'Pulou direto para a lista']);
        $this->client->submit($form);
        self::assertSame(1, preg_match('#/voto/assembleias/(\d+)/unidades$#', (string) $this->client->getResponse()->headers->get('Location'), $m));
        $this->em->clear();
        self::assertSame('Pulou direto para a lista', $this->em->find(Assembleia::class, (int) $m[1])->getTitulo());
    }

    #[TestDox('Passo 1: o peso grava pelo EditarAssembleia e o resumo das regras de cada pauta aparece abaixo da categoria')]
    public function testPasso1GravaOPeso(): void
    {
        $id = (int) AssembleiaFactory::new(['tenant' => $this->escritorio, 'titulo' => 'AGO do Assistente'])->porFracao()->create()->getId();
        PautaDaAssembleiaFactory::new(['assembleia' => $this->em->find(Assembleia::class, $id), 'titulo' => 'Aprovação de contas'])->comOpcoesPadrao()->create();
        $pagina = $this->client->request('GET', '/voto/assembleias/' . $id . '/editar');
        self::assertCount(1, $pagina->filter('select[name="peso"]'));
        self::assertSame('AGO do Assistente', $pagina->filter('input[name="titulo"]')->attr('value'));
        self::assertSame(['Aprovação de contas'], $pagina->filter('[data-voto-regra-da-pauta] > b')->each(static fn (Crawler $c): string => trim($c->text())));
        $this->client->submit($pagina->selectButton('Continuar')->form(['peso' => 'unidade']));
        self::assertResponseRedirects('/voto/assembleias/' . $id . '/pautas');
        $this->em->clear();
        self::assertSame(PesoDoVoto::Unidade, $this->em->find(Assembleia::class, $id)->getPeso());
    }

    #[TestDox('Passo 1: erro do servidor volta ao próprio passo, sem perder o que foi digitado')]
    public function testErroVoltaAoPassoQueEnviou(): void
    {
        $id = (int) $this->rascunho()->getId();
        $pagina = $this->client->request('GET', '/voto/assembleias/' . $id . '/editar');
        $form = $pagina->selectButton('Continuar')->form();
        $valores = $form->getPhpValues();
        $valores['titulo'] = '';
        $pagina = $this->client->request('POST', $form->getUri(), $valores);
        self::assertSame('1. Categoria', trim($pagina->filter('.voto-trilho > .vPasAt')->text()));
        self::assertCount(1, $pagina->filter('.voto-erro'));
    }

    #[TestDox('Passo 4 (Equipe) mostra o que vale hoje, com Voltar e Continuar para os vizinhos; os passos antigos 2, 3 e 6 não existem mais')]
    public function testPassoEquipe(): void
    {
        $id = (int) $this->rascunho()->getId();
        $quatro = $this->client->request('GET', '/voto/assembleias/' . $id . '/passo/4');
        self::assertSame('Quem conduz a votação', trim($quatro->filter('.voto-etapa-titulo')->text()));
        self::assertSame('/voto/assembleias/' . $id . '/unidades', $quatro->selectLink('Voltar')->attr('href'));
        self::assertSame('/voto/assembleias/' . $id . '/publicar', $quatro->selectLink('Continuar')->attr('href'));
        foreach ([2 => '/editar', 3 => '/editar', 6 => '/passo/4'] as $antigo => $destino) {
            $this->client->request('GET', '/voto/assembleias/' . $id . '/passo/' . $antigo);
            self::assertResponseStatusCodeSame(302, 'o endereço antigo /passo/' . $antigo . ' redireciona');
            self::assertResponseRedirects('/voto/assembleias/' . $id . $destino);
        }
    }

    #[TestDox('Passo 2: as pautas gravam pelo caminho existente e "Ver na urna" abre o treino sem token')]
    public function testPasso2PautasEVerNaUrna(): void
    {
        $id = (int) $this->rascunho()->getId();
        $pagina = $this->client->request('GET', '/voto/assembleias/' . $id . '/pautas');
        self::assertSame('Cargos e candidaturas', trim($pagina->filter('.voto-etapa-titulo')->text()));
        self::assertCount(1, $pagina->filter('.voto-pauta-bloco form[data-voto-pauta-form="existente"]'));
        self::assertStringContainsString('nenhum voto é registrado', $pagina->filter('.voto-assistente-corpo')->text());
        self::assertCount(0, $pagina->filter('[data-vc-cabine]'), 'a urna só abre depois de Ver na urna');
        $link = $pagina->filter('a[data-voto-ver-na-urna]');
        self::assertSame('Ver na urna', trim($link->text()));
        $urna = $this->client->request('GET', (string) $link->attr('href'));
        self::assertCount(1, $urna->filter('[data-voto-abrir-urna][data-vc-abrir-simulacao]'));
        self::assertCount(1, $urna->filter('[data-vc-cabine]'));
        $dados = json_decode((string) $urna->filter('[data-vc-cabine-dados]')->attr('data-vc-cabine-dados'), true, 512, \JSON_THROW_ON_ERROR);
        self::assertSame('', $dados['csrf'], 'sem token nem CSRF');
        self::assertSame('', $dados['urlVotar'], 'nada pode ser enviado');
        self::assertSame(['Aprovação de contas'], array_column($dados['simulacao'], 'titulo'));
        self::assertSame(0, $this->em->getRepository(ParticipacaoNaPauta::class)->count([]), 'nenhum voto registrado');
    }

    #[TestDox('Passo 2: "Salvar e sair" mostra a faixa de alterações não salvas escondida e o rodapé de passos')]
    public function testPasso2Rodape(): void
    {
        $id = (int) $this->rascunho()->getId();
        $pagina = $this->client->request('GET', '/voto/assembleias/' . $id . '/pautas');
        self::assertCount(1, $pagina->filter('.vH > [data-voto-sujo][hidden]'));
        self::assertSame('Alterações ainda não salvas', trim($pagina->filter('[data-voto-sujo]')->text()));
        self::assertSame('/voto/assembleias/' . $id . '/editar', $pagina->selectLink('Voltar')->attr('href'));
        self::assertSame('/voto/assembleias/' . $id . '/unidades', $pagina->selectLink('Continuar')->attr('href'));
    }

    #[TestDox('Passo 3: lista de aptos com o título do desenho, a tabela como forma principal e Continuar para a Equipe, sem o botão Publicar solto')]
    public function testPasso3Lista(): void
    {
        $id = (int) $this->rascunho(true, false)->getId();
        $pagina = $this->client->request('GET', '/voto/assembleias/' . $id . '/unidades');
        self::assertSame('Quem pode votar', trim($pagina->filter('.voto-etapa-titulo')->text()));
        self::assertCount(1, $pagina->filter('form[data-voto-aptos]'));
        self::assertCount(1, $pagina->filter('details[data-voto-aptos-avancada] form.voto-importar'));
        self::assertSame('/voto/assembleias/' . $id . '/passo/4', $pagina->selectLink('Continuar')->attr('href'));
        self::assertCount(0, $pagina->selectButton('Publicar votação'));
    }

    #[TestDox('Revisão sem pautas nem lista: pendência bloqueia a publicação e as linhas levam ao passo certo')]
    public function testRevisaoComPendencia(): void
    {
        $id = (int) $this->rascunho(false, false)->getId();
        $pagina = $this->client->request('GET', '/voto/assembleias/' . $id . '/publicar');
        self::assertSame('Inclua ao menos uma pauta.', trim($pagina->filter('[data-voto-pendencia]')->text()));
        self::assertCount(1, $pagina->filter('[data-voto-pendencias] > .voto-pendencia-bloqueio .bi-x-circle-fill'));
        self::assertCount(0, $pagina->filter('[data-voto-pronto]'));
        self::assertNotNull($pagina->filter('form[data-voto-confirmar-publicacao] button')->attr('disabled'));
        self::assertSame('Sem lista', trim($pagina->filter('.voto-revisao-quadro > .voto-revisao-linha:contains("Lista de aptos") > b')->text()));
        $alterar = fn (string $campo): string => (string) $pagina->filter('a[aria-label="Alterar ' . $campo . '"]')->attr('href');
        self::assertSame('/voto/assembleias/' . $id . '/editar', $alterar('Evento'));
        self::assertSame('/voto/assembleias/' . $id . '/editar', $alterar('Peso do voto'));
        self::assertSame('/voto/assembleias/' . $id . '/pautas', $alterar('Cargos'));
        self::assertSame('/voto/assembleias/' . $id . '/unidades', $alterar('Lista de aptos'));
        self::assertSame('5. Revisar', trim($pagina->filter('.voto-trilho > .vPasAt')->text()));
    }

    #[TestDox('Revisão pronta: "Tudo pronto para publicar." e o botão Publicar votação liberado')]
    public function testRevisaoPronta(): void
    {
        $id = (int) $this->rascunho()->getId();
        $pagina = $this->client->request('GET', '/voto/assembleias/' . $id . '/publicar');
        self::assertSame('Tudo pronto para publicar.', trim($pagina->filter('[data-voto-pronto]')->text()));
        self::assertCount(0, $pagina->filter('[data-voto-pendencia]'));
        self::assertSame('1 aptos, 0 não aptos', trim($pagina->filter('.voto-revisao-linha:contains("Lista de aptos") > b')->text()));
        self::assertNull($pagina->filter('form[data-voto-confirmar-publicacao] button')->attr('disabled'));
        self::assertSame('Publicar votação', trim($pagina->filter('form[data-voto-confirmar-publicacao] button')->text()));
    }

    #[TestDox('Revisão com peso por fração: soma fora do esperado vira aviso, sem bloquear')]
    public function testRevisaoComSomaDasFracoesForaDoEsperado(): void
    {
        $assembleia = AssembleiaFactory::new(['tenant' => $this->escritorio])->porFracao()->create()->_real();
        PautaDaAssembleiaFactory::new(['assembleia' => $assembleia])->comOpcoesPadrao()->create();
        UnidadeDaAssembleiaFactory::createOne(['assembleia' => $assembleia, 'fracao' => '0.300000']);
        $pagina = $this->client->request('GET', '/voto/assembleias/' . $assembleia->getId() . '/publicar');
        self::assertCount(1, $pagina->filter('[data-voto-pendencias] > .voto-pendencia-aviso[data-voto-soma-fracoes]'));
        self::assertCount(1, $pagina->filter('[data-voto-pronto]'));
    }

    #[TestDox('As sub-abas antigas (Pautas, Unidades e links, Conferência) saíram do rascunho: só Perfis (Administração), Painel, Assembleia e Avaliações (para quem decide)')]
    public function testSubAbasAntigasSairamDoRascunho(): void
    {
        $id = (int) $this->rascunho()->getId();
        foreach (['/editar', '/pautas', '/unidades', '/publicar', '/passo/4'] as $caminho) {
            $pagina = $this->client->request('GET', '/voto/assembleias/' . $id . $caminho);
            self::assertSame(['Perfis', 'Painel', 'Assembleia', 'Avaliações'], $pagina->filter('.voto-sub-abas > a.vTab')->each(static fn (Crawler $c): string => trim($c->text())), $caminho);
            self::assertSame('Assembleia', trim($pagina->filter('.voto-sub-abas > a.vTabOn')->text()), $caminho);
            self::assertCount(0, $pagina->filter('.voto-etapas'), 'as 6 etapas só aparecem depois de publicar');
        }
    }

    #[TestDox('Publicada: não há assistente; o passo 4 leva para a mesa e as sub-abas são Perfis (Administração), Painel, Pautas, Unidades, Avaliações e Ocorrências')]
    public function testPublicadaSemAssistente(): void
    {
        $assembleia = AssembleiaFactory::new(['tenant' => $this->escritorio])->publicada()->create()->_real();
        $this->client->request('GET', '/voto/assembleias/' . $assembleia->getId() . '/passo/4');
        self::assertResponseRedirects('/voto/assembleias/' . $assembleia->getId() . '/pautas');
        $pagina = $this->client->request('GET', '/voto/assembleias/' . $assembleia->getId() . '/pautas');
        self::assertSame(['Perfis', 'Painel', 'Pautas', 'Unidades e links', 'Avaliações', 'Ocorrências'], $pagina->filter('.voto-sub-abas > a.vTab')->each(static fn (Crawler $c): string => trim($c->text())));
        self::assertCount(0, $pagina->filter('.voto-trilho'));
        self::assertCount(1, $pagina->filter('.voto-etapas'));
    }

    #[TestDox('Rotas antigas guardadas: editar, pautas e unidades continuam abrindo; POST antigo sem passo segue para as pautas')]
    public function testRotasAntigasContinuamValendo(): void
    {
        $id = (int) $this->rascunho()->getId();
        foreach (['/editar', '/pautas', '/unidades', '/publicar'] as $caminho) {
            $this->client->request('GET', '/voto/assembleias/' . $id . $caminho);
            self::assertResponseIsSuccessful($caminho);
        }
        $pagina = $this->client->request('GET', '/voto/assembleias/' . $id . '/editar');
        $form = $pagina->selectButton('Continuar')->form();
        $valores = $form->getPhpValues();
        unset($valores['depois']);
        $this->client->request('POST', $form->getUri(), $valores);
        self::assertResponseRedirects('/voto/assembleias/' . $id . '/pautas');
    }

    #[TestDox('Aviso de alterações não salvas: script do assistente só nos passos de formulário; o do passo Cargos é o das pautas')]
    public function testAvisoDeAlteracoesNaoSalvas(): void
    {
        $id = (int) $this->rascunho()->getId();
        $pagina = $this->client->request('GET', '/voto/assembleias/' . $id . '/editar');
        self::assertCount(1, $pagina->filter('.vH > [data-voto-sujo][hidden]'));
        self::assertCount(1, $pagina->filter('script[src*="voto-assistente.js"]'));
        self::assertCount(1, $pagina->filter('form[data-voto-assistente-form]'));
    }

    #[TestDox('Assembleia de outro escritório: 404 nos passos novos e nenhuma gravação')]
    public function testOutroEscritorioDa404(): void
    {
        $outra = AssembleiaFactory::createOne()->_real();
        foreach (['/passo/4', '/passo/2', '/passo/6', '/editar', '/pautas?urna=1'] as $caminho) {
            $this->client->request('GET', '/voto/assembleias/' . $outra->getId() . $caminho);
            self::assertResponseStatusCodeSame(404, $caminho);
        }
        $this->client->request('POST', '/voto/assembleias/' . $outra->getId() . '/editar', ['titulo' => 'invadida', 'peso' => 'fracao', 'depois' => 'passo-2']);
        self::assertResponseStatusCodeSame(404);
        self::assertNotSame('invadida', $outra->getTitulo());
    }

    private function usuario(): User
    {
        $user = UserFactory::createOne(['fullName' => 'Síndica Teste', 'roles' => ['ROLE_USER']])->_real();
        $papel = (new TenantRole())->setTenant($this->escritorio)->setName('Perfil do Voto ' . uniqid())->setIsSystem(true);
        $this->em->persist($papel);
        $this->em->persist((new UserTenant($user, $this->escritorio))->setTenantRole($papel));
        $this->em->flush();

        return $user;
    }
}
