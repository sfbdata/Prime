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
use App\Voto\Controller\UnidadesDaAssembleiaController;
use App\Voto\Entity\Assembleia;
use App\Voto\Entity\LiberacaoDoVoto;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Zenstruck\Foundry\Test\Factories;

/**
 * Lista de aptos (B2): prévia da planilha com os erros por linha antes de gravar, adicionar e editar unidade, aviso da
 * soma das frações. Guarda e posse como os vizinhos: 403 sem liberação, 404 de outro escritório, CSRF e trava na abertura.
 */
#[CoversClass(UnidadesDaAssembleiaController::class)]
final class UnidadesDaAssembleiaControllerTest extends JusPrimeWebTestCase
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
        $this->escritorio = TenantFactory::createOne(['name' => 'Condomínio das Unidades ' . uniqid()])->_real();
        $estado = $this->em->getRepository(RecursoEstado::class)->findOneBy(['chave' => 'voto']);
        if (null !== $estado) {
            $estado->reativar(null, new \DateTimeImmutable());
            $this->em->flush();
        }
    }

    #[TestDox('Sem liberação do escritório: prévia, confirmar, adicionar e editar dão 403')]
    public function testSemLiberacaoRecebe403(): void
    {
        $this->entrar();
        $a = AssembleiaFactory::new(['tenant' => $this->escritorio])->create()->_real();
        $u = UnidadeDaAssembleiaFactory::createOne(['assembleia' => $a])->_real();
        $base = '/voto/assembleias/' . $a->getId() . '/unidades';

        $this->client->request('GET', $base . '/' . $u->getId() . '/editar');
        self::assertResponseStatusCodeSame(403);
        foreach (['/importar', '/importar/confirmar', '/adicionar', '/' . $u->getId() . '/editar'] as $acao) {
            $this->client->request('POST', $base . $acao, ['lista' => '999;Z', 'identificacao' => '999']);
            self::assertResponseStatusCodeSame(403, $acao);
        }
        self::assertSame(1, $this->contarUnidades($a));
    }

    #[TestDox('Outro escritório: prévia, confirmar, adicionar, editar (tela e ação) dão 404 e nada muda')]
    public function testOutroEscritorioDa404(): void
    {
        $this->liberar();
        $this->entrar();
        $alheia = AssembleiaFactory::new()->create()->_real();
        $u = UnidadeDaAssembleiaFactory::createOne(['assembleia' => $alheia, 'identificacao' => '101'])->_real();
        $base = '/voto/assembleias/' . $alheia->getId() . '/unidades';
        $token = $this->tokenValido('voto_assembleia_' . $alheia->getId());

        $this->client->request('GET', $base . '/' . $u->getId() . '/editar');
        self::assertResponseStatusCodeSame(404);
        foreach (['/importar', '/importar/confirmar', '/adicionar', '/' . $u->getId() . '/editar'] as $acao) {
            $this->client->request('POST', $base . $acao, ['_token' => $token, 'lista' => '999;Z', 'identificacao' => '999']);
            self::assertResponseStatusCodeSame(404, $acao);
        }
        self::assertSame(1, $this->contarUnidades($alheia));
        self::assertSame('101', $this->em->getConnection()->fetchOne('SELECT identificacao FROM voto.assembleia_unidade WHERE id = ?', [$u->getId()]));
    }

    #[TestDox('Unidade de outra assembleia do mesmo escritório: editar dá 404')]
    public function testUnidadeDeOutraAssembleiaDa404(): void
    {
        $this->liberar();
        $this->entrar();
        $a = AssembleiaFactory::new(['tenant' => $this->escritorio])->create()->_real();
        $b = AssembleiaFactory::new(['tenant' => $this->escritorio])->create()->_real();
        $deB = UnidadeDaAssembleiaFactory::createOne(['assembleia' => $b])->_real();

        $this->client->request('GET', '/voto/assembleias/' . $a->getId() . '/unidades/' . $deB->getId() . '/editar');
        self::assertResponseStatusCodeSame(404);
    }

    #[TestDox('Prévia da planilha enviada: mostra as linhas lidas e o erro por linha, e não grava até confirmar')]
    public function testPreviaDoArquivoNaoGrava(): void
    {
        [$a] = $this->cenario();
        $csv = tempnam(sys_get_temp_dir(), 'apt') . '.csv';
        file_put_contents($csv, "Unidade;Bloco;Situação;Fração;Responsável;E-mail\n201;A;;0,5;Ana;ana@exemplo.com\n;A;;0,1;;\n202;A;;abc;;\n201;A;;0,5;;\n");
        try {
            $tela = $this->client->request('GET', $this->base($a));
            $this->client->submit($tela->selectButton('Importar lista')->form());
            self::assertResponseRedirects($this->base($a), null, 'sem arquivo nem texto não há o que mostrar');
            self::assertSame('Nenhuma linha válida na lista.', trim($this->client->followRedirect()->filter('.voto-toast')->text()));
            $this->client->request('POST', $this->base($a) . '/importar', ['_token' => $this->tokenDe($a)], ['arquivo' => new UploadedFile($csv, 'aptos.csv', 'text/csv', null, true)]);
        } finally {
            @unlink($csv);
        }
        self::assertResponseIsSuccessful();
        $previa = $this->client->getCrawler();
        self::assertSame(
            ['Linha 3: Linha sem a unidade.', 'Linha 4: Fração inválida.', 'Duplicadas: linhas 5.'],
            $previa->filter('[data-voto-previa-erros] > div')->each(static fn (Crawler $c): string => trim($c->text())),
        );
        self::assertCount(1, $previa->filter('[data-voto-previa-linha]'));
        self::assertStringContainsString('201 · A', $previa->filter('[data-voto-previa-linha]')->text());
        self::assertSame(0, $this->contarUnidades($a), 'a prévia não grava');
        self::assertSame([], glob(sys_get_temp_dir() . '/apt*.csv') ?: [], 'o arquivo enviado não ficou solto');

        $this->client->submit($previa->selectButton('Confirmar 1 unidade')->form());
        self::assertResponseRedirects();
        self::assertSame(1, $this->contarUnidades($a), 'confirmar grava');
    }

    #[TestDox('Prévia só com erros: não há botão de confirmar')]
    public function testPreviaSemValidas(): void
    {
        [$a] = $this->cenario();
        $this->client->request('POST', $this->base($a) . '/importar', ['_token' => $this->tokenDe($a), 'lista' => ";A;;0,1\n301;A;;x"]);

        $previa = $this->client->getCrawler();
        self::assertCount(2, $previa->filter('[data-voto-previa-erros] > div'));
        self::assertCount(0, $previa->filter('[data-voto-previa] button[type="submit"]'));
    }

    #[TestDox('Prévia e confirmar sem CSRF não leem nem gravam')]
    public function testSemCsrf(): void
    {
        [$a] = $this->cenario();
        foreach (['/importar', '/importar/confirmar', '/adicionar'] as $acao) {
            $this->client->request('POST', $this->base($a) . $acao, ['_token' => 'errado', 'lista' => "401;A;;0,5\n", 'identificacao' => '401', 'fracao' => '0,5']);
            self::assertResponseRedirects($this->base($a), null, $acao);
        }
        self::assertSame(0, $this->contarUnidades($a));
    }

    #[TestDox('Adicionar unidade: entra na lista com o aviso; erro mostra o motivo e não grava')]
    public function testAdicionar(): void
    {
        [$a] = $this->cenario();
        $token = $this->tokenDe($a);

        $this->client->request('POST', $this->base($a) . '/adicionar', ['_token' => $token, 'identificacao' => '501', 'bloco' => 'B', 'situacao' => 'inadimplente', 'fracao' => '0,25', 'responsavel' => 'Caio', 'email' => 'caio@exemplo.com']);
        $tela = $this->client->followRedirect();
        self::assertSame('Unidade adicionada à lista.', trim($tela->filter('.voto-toast')->text()));
        self::assertStringContainsString('501 · B', $tela->filter('.voto-unidade')->text());
        self::assertStringContainsString('Não apto', $tela->filter('.voto-unidade')->text());

        $this->client->request('POST', $this->base($a) . '/adicionar', ['_token' => $token, 'identificacao' => '501', 'bloco' => 'b', 'fracao' => '0,25']);
        $tela = $this->client->followRedirect();
        self::assertSame('Esta unidade já está na lista.', trim($tela->filter('.voto-toast')->text()));
        self::assertSame(1, $this->contarUnidades($a));
    }

    #[TestDox('Editar unidade: a tela traz os campos (sem telefone); salvar atualiza; erro volta com 422 e os valores digitados')]
    public function testEditar(): void
    {
        [$a] = $this->cenario();
        $u = UnidadeDaAssembleiaFactory::createOne(['assembleia' => $a, 'identificacao' => '601', 'bloco' => 'A', 'fracao' => '0.500000', 'telefone' => '11999990000'])->_real();
        $url = $this->base($a) . '/' . $u->getId() . '/editar';

        $tela = $this->client->request('GET', $url);
        self::assertResponseIsSuccessful();
        self::assertSame('601', $tela->filter('input[name="identificacao"]')->attr('value'));
        self::assertSame('0,5', $tela->filter('input[name="fracao"]')->attr('value'));
        self::assertCount(0, $tela->filter('input[name="telefone"]'));
        self::assertStringNotContainsString('11999990000', (string) $this->client->getResponse()->getContent());

        $this->client->submit($tela->selectButton('Salvar')->form(['fracao' => 'zero']));
        self::assertResponseStatusCodeSame(422);
        self::assertSame('Fração inválida.', trim($this->client->getCrawler()->filter('[data-voto-erro]')->text()));
        self::assertSame('zero', $this->client->getCrawler()->filter('input[name="fracao"]')->attr('value'));

        $this->client->submit($this->client->getCrawler()->selectButton('Salvar')->form(['fracao' => '0,4', 'responsavel' => 'Nova']));
        $lista = $this->client->followRedirect();
        self::assertSame('Unidade atualizada.', trim($lista->filter('.voto-toast')->text()));
        self::assertSame('0.400000', $this->em->getConnection()->fetchOne('SELECT fracao FROM voto.assembleia_unidade WHERE id = ?', [$u->getId()]));
        self::assertSame('11999990000', $this->em->getConnection()->fetchOne('SELECT telefone FROM voto.assembleia_unidade WHERE id = ?', [$u->getId()]), 'o telefone não muda');
    }

    #[TestDox('Depois da abertura da primeira pauta: sem botões, e prévia, adicionar e editar são recusados')]
    public function testTravadoDepoisDePublicar(): void
    {
        [$a] = $this->cenario();
        $u = UnidadeDaAssembleiaFactory::createOne(['assembleia' => $a, 'identificacao' => '701'])->_real();
        PautaDaAssembleiaFactory::new(['assembleia' => $a])->comOpcoesPadrao()->aberta()->create();
        $this->em->clear();
        $token = $this->tokenDe($a);

        $tela = $this->client->request('GET', $this->base($a));
        self::assertCount(0, $tela->filter('[data-voto-adicionar-unidade]'));
        self::assertCount(0, $tela->filter('a[href*="/unidades/"][href$="/editar"]'));
        $this->client->request('POST', $this->base($a) . '/importar', ['_token' => $token, 'lista' => '702;A']);
        self::assertResponseRedirects($this->base($a));
        self::assertSame('A lista fica bloqueada depois da abertura da votação.', trim($this->client->followRedirect()->filter('.voto-toast')->text()));
        $this->client->request('POST', $this->base($a) . '/adicionar', ['_token' => $token, 'identificacao' => '702']);
        self::assertResponseRedirects($this->base($a));
        $this->client->request('POST', $this->base($a) . '/' . $u->getId() . '/editar', ['_token' => $token, 'identificacao' => '703']);
        self::assertResponseStatusCodeSame(422);
        self::assertSame('701', $this->em->getConnection()->fetchOne('SELECT identificacao FROM voto.assembleia_unidade WHERE id = ?', [$u->getId()]));
        self::assertSame(1, $this->contarUnidades($a));
    }

    #[TestDox('Soma das frações: avisa na lista e na conferência quando não fecha 1 nem 100%; fechando, não avisa')]
    public function testSomaDasFracoes(): void
    {
        $this->liberar();
        $this->entrar();
        $a = AssembleiaFactory::new(['tenant' => $this->escritorio])->porFracao()->create()->_real();
        UnidadeDaAssembleiaFactory::createOne(['assembleia' => $a, 'identificacao' => '1', 'fracao' => '0.600000']);
        UnidadeDaAssembleiaFactory::createOne(['assembleia' => $a, 'identificacao' => '2', 'fracao' => '0.300000', 'situacao' => 'inadimplente']);

        $lista = $this->client->request('GET', $this->base($a));
        self::assertSame('A soma das frações da lista é 0,9; o esperado é 1 (ou 100%). Confira a planilha.', trim($lista->filter('[data-voto-soma-fracoes]')->text()));
        $conferencia = $this->client->request('GET', '/voto/assembleias/' . $a->getId() . '/publicar');
        self::assertSame('A soma das frações da lista é 0,9; o esperado é 1 (ou 100%). Confira a planilha.', trim($conferencia->filter('[data-voto-soma-fracoes]')->text()));

        $this->client->request('POST', $this->base($a) . '/adicionar', ['_token' => $this->tokenDe($a), 'identificacao' => '3', 'fracao' => '0,1']);
        $lista = $this->client->followRedirect();
        self::assertCount(0, $lista->filter('[data-voto-soma-fracoes]'), 'a lista inteira (aptas e não aptas) fecha 1');
    }

    #[TestDox('Peso por unidade: não há aviso de soma')]
    public function testSemAvisoNoPesoPorUnidade(): void
    {
        [$a] = $this->cenario();
        UnidadeDaAssembleiaFactory::createOne(['assembleia' => $a, 'fracao' => '0.100000']);

        $lista = $this->client->request('GET', $this->base($a));
        self::assertCount(0, $lista->filter('[data-voto-soma-fracoes]'));
    }

    #[TestDox('Ordem do desenho: a unidade e depois o bloco')]
    public function testOrdemUnidadeBloco(): void
    {
        [$a] = $this->cenario();
        UnidadeDaAssembleiaFactory::createOne(['assembleia' => $a, 'identificacao' => 'A-101', 'bloco' => 'Bloco A']);

        $lista = $this->client->request('GET', $this->base($a));
        self::assertSame('A-101 · Bloco A', trim($lista->filter('.voto-unidade-dados > b')->text()));
    }

    /** @return array{Assembleia} */
    private function cenario(): array
    {
        $this->liberar();
        $this->entrar();
        $a = AssembleiaFactory::new(['tenant' => $this->escritorio])->create()->_real();

        return [$a];
    }

    private function base(Assembleia $a): string
    {
        return '/voto/assembleias/' . $a->getId() . '/unidades';
    }

    private function contarUnidades(Assembleia $a): int
    {
        return (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM voto.assembleia_unidade WHERE assembleia_id = ?', [$a->getId()]);
    }

    private function tokenDe(Assembleia $a): string
    {
        return $this->tokenValido('voto_assembleia_' . $a->getId());
    }

    private function tokenValido(string $id): string
    {
        $this->client->request('GET', '/voto?aba=ele');
        $sessao = $this->client->getRequest()->getSession();
        $sessao->set('_csrf/' . $id, 'token-de-teste-' . $id);
        $sessao->save();

        return 'token-de-teste-' . $id;
    }

    private function liberar(): void
    {
        $liberacao = new LiberacaoDoVoto($this->escritorio);
        $liberacao->liberar();
        $this->em->persist($liberacao);
        $this->em->flush();
    }

    private function entrar(): void
    {
        $user = $this->usuario();
        $this->logarComTenant($this->client, $user, $this->escritorio);
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
