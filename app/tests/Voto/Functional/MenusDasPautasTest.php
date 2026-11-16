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
use App\Tests\Factory\Voto\OpcaoDaPautaFactory;
use App\Tests\Factory\Voto\PautaDaAssembleiaFactory;
use App\Tests\Factory\Voto\UnidadeDaAssembleiaFactory;
use App\Tests\Functional\JusPrimeWebTestCase;
use App\Voto\Controller\PautasDaAssembleiaController;
use App\Voto\Entity\Assembleia;
use App\Voto\Entity\LiberacaoDoVoto;
use App\Voto\Entity\PautaDaAssembleia;
use App\Voto\Enum\TipoDaPauta;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\DomCrawler\Crawler;
use Zenstruck\Foundry\Test\Factories;

/**
 * Passo "Cargos e candidaturas" (desenho 18, L263): menu de três pontos por cargo e por candidatura, o recolhido
 * com descrição, propostas, composição e unidade do candidato, e as rotas novas de mover e duplicar a pauta (só em
 * rascunho, com CSRF, e do próprio escritório). O arranjo é conferido por filho direto.
 */
#[CoversClass(PautasDaAssembleiaController::class)]
final class MenusDasPautasTest extends JusPrimeWebTestCase
{
    use Factories;

    private KernelBrowser $client;
    private EntityManagerInterface $em;
    private Tenant $escritorio;
    private Assembleia $assembleia;
    private PautaDaAssembleia $eleicao;
    private PautaDaAssembleia $contas;
    private PautaDaAssembleia $obra;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->client->disableReboot();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        $this->escritorio = TenantFactory::createOne(['name' => 'Condomínio dos Menus ' . uniqid()])->_real();
        $estado = $this->em->getRepository(RecursoEstado::class)->findOneBy(['chave' => 'voto']);
        if (null !== $estado) {
            $estado->reativar(null, new \DateTimeImmutable());
        }
        $liberacao = new LiberacaoDoVoto($this->escritorio);
        $liberacao->liberar();
        $this->em->persist($liberacao);
        $this->em->flush();
        $this->entrar();

        $this->assembleia = AssembleiaFactory::createOne(['tenant' => $this->escritorio])->_real();
        $this->eleicao = PautaDaAssembleiaFactory::createOne(['assembleia' => $this->assembleia, 'ordem' => 1, 'titulo' => 'Síndico(a)', 'tipo' => TipoDaPauta::EleicaoDeCargo])->_real();
        $ana = OpcaoDaPautaFactory::createOne(['pauta' => $this->eleicao, 'numero' => 1, 'rotulo' => 'Ana Souza'])->_real();
        $ana->detalhar('Moradora do 101', 'Mais jardim', "Presidente: Ana\nVice: Bia", '101');
        OpcaoDaPautaFactory::createOne(['pauta' => $this->eleicao, 'numero' => 2, 'rotulo' => 'Caio Lima']);
        OpcaoDaPautaFactory::new(['pauta' => $this->eleicao])->abstencao()->create();
        $this->contas = PautaDaAssembleiaFactory::new(['assembleia' => $this->assembleia, 'ordem' => 2, 'titulo' => 'Contas'])->comOpcoesPadrao()->create()->_real();
        $this->obra = PautaDaAssembleiaFactory::new(['assembleia' => $this->assembleia, 'ordem' => 3, 'titulo' => 'Obra'])->comOpcoesPadrao()->create()->_real();
        $this->em->flush();
    }

    private function entrar(): void
    {
        $user = UserFactory::createOne(['fullName' => 'Síndica Teste', 'roles' => ['ROLE_USER']])->_real();
        $papel = (new TenantRole())->setTenant($this->escritorio)->setName('Perfil do Voto ' . uniqid())->setIsSystem(true);
        $this->em->persist($papel);
        $this->em->persist((new UserTenant($user, $this->escritorio))->setTenantRole($papel));
        $this->em->flush();
        $this->logarComTenant($this->client, $user, $this->escritorio);
    }

    private function url(string $fim = ''): string
    {
        return '/voto/assembleias/' . $this->assembleia->getId() . '/pautas' . $fim;
    }

    /** @return list<string> */
    private static function rotulos(Crawler $itens): array
    {
        return $itens->each(static fn (Crawler $c): string => trim($c->text()));
    }

    /** @return list<string> os títulos pela ordem gravada */
    private function ordemNoBanco(): array
    {
        return $this->em->getConnection()->fetchFirstColumn('SELECT titulo FROM voto.assembleia_pauta WHERE assembleia_id = ? ORDER BY ordem, id', [$this->assembleia->getId()]);
    }

    private function token(Crawler $tela): string
    {
        return (string) $tela->filter('form[data-voto-pauta-form="existente"] input[name="_token"]')->first()->attr('value');
    }

    #[TestDox('Menu do cargo: Numerar, Duplicar, Mover e Remover conforme o tipo e a posição, como filho direto da linha de campos')]
    public function testMenuPorCargo(): void
    {
        $tela = $this->client->request('GET', $this->url());
        self::assertResponseIsSuccessful();

        $menu = static fn (int $id): Crawler => $tela->filter('.voto-pauta-bloco > form#pauta-' . $id . ' > .voto-pauta-campos > .vKw > .vKm > button[type="button"]');
        self::assertSame(['Numerar em sequência', 'Duplicar', 'Mover para baixo', 'Remover cargo'], self::rotulos($menu((int) $this->eleicao->getId())), 'a primeira não sobe; eleição diz cargo');
        self::assertSame(['Numerar em sequência', 'Duplicar', 'Mover para cima', 'Mover para baixo', 'Remover pauta'], self::rotulos($menu((int) $this->contas->getId())));
        self::assertSame(['Numerar em sequência', 'Duplicar', 'Mover para cima', 'Remover pauta'], self::rotulos($menu((int) $this->obra->getId())), 'a última não desce');

        $contas = $menu((int) $this->contas->getId());
        self::assertSame('/voto/assembleias/' . $this->assembleia->getId() . '/pautas/' . $this->contas->getId() . '/duplicar', $contas->eq(1)->attr('data-voto-envia'));
        self::assertSame('/voto/assembleias/' . $this->assembleia->getId() . '/pautas/' . $this->contas->getId() . '/mover/cima', $contas->eq(2)->attr('data-voto-envia'));
        self::assertSame('/voto/assembleias/' . $this->assembleia->getId() . '/pautas/' . $this->contas->getId() . '/mover/baixo', $contas->eq(3)->attr('data-voto-envia'));
        self::assertNotSame('', (string) $contas->eq(1)->attr('data-voto-token'));
        self::assertSame('vKd', $contas->eq(4)->attr('class'), 'remover em vermelho');
        self::assertNotNull($contas->eq(4)->attr('data-voto-descarta'));
        self::assertSame('', (string) $contas->eq(0)->attr('data-voto-envia'), 'Numerar é só do cliente');
        self::assertNotNull($contas->eq(0)->attr('data-voto-numerar'));

        // O antigo botão Numerar solto e a lixeira ao lado da caixa saíram: tudo mora no menu.
        self::assertCount(0, $tela->filter('.voto-pauta-campos > button[data-voto-numerar], .voto-pauta-remover'));
        // A pauta nova ainda não gravada só tem o Numerar (nada a duplicar, mover ou remover).
        self::assertSame(['Numerar em sequência'], self::rotulos($tela->filter('form#pauta-nova > .voto-pauta-campos > .vKw > .vKm > button')));
    }

    #[TestDox('Candidatura: Nº e nome na grade, unidade do candidato, <details> recolhido com descrição, propostas e composição, e o menu à direita')]
    public function testLinhaDaCandidatura(): void
    {
        $tela = $this->client->request('GET', $this->url());
        $linhas = $tela->filter('form#pauta-' . $this->eleicao->getId() . ' > .voto-opcoes > .voto-opcao.vCandR');
        self::assertCount(2, $linhas);
        $ana = $linhas->eq(0);

        self::assertCount(1, $ana->filter('.vCandR > .vCandF > input[name="opcao_numero[]"][placeholder="Nº"]'));
        self::assertSame('Ana Souza', $ana->filter('.vCandF > input[name="opcao_rotulo[]"]')->attr('value'));
        $unidade = $ana->filter('.vCandF > input[name="opcao_unidade[]"]');
        self::assertSame('Unidade do candidato (opcional, confere com a lista)', $unidade->attr('placeholder'));
        self::assertSame('101', $unidade->attr('value'));
        self::assertNull($unidade->attr('hidden'));

        $detalhes = $ana->filter('.vCandF > details.vDet');
        self::assertCount(1, $detalhes);
        self::assertNull($detalhes->attr('open'), 'recolhido, como no desenho');
        self::assertSame('Descrição, propostas e composição da chapa', trim($detalhes->filter('summary')->text()));
        $descricao = $detalhes->filter('.voto-opcao-detalhes > input[name="opcao_descricao[]"]');
        self::assertSame('Descrição (aparece na urna abaixo do nome)', $descricao->attr('placeholder'));
        self::assertSame('Moradora do 101', $descricao->attr('value'));
        self::assertSame('Mais jardim', trim($detalhes->filter('.voto-opcao-detalhes > textarea[name="opcao_propostas[]"]')->text()));
        $composicao = $detalhes->filter('.voto-opcao-detalhes > textarea[name="opcao_composicao[]"]');
        self::assertSame('Composição da chapa (um cargo por linha, ex.: Presidente: Nome)', $composicao->attr('placeholder'));
        self::assertSame("Presidente: Ana\nVice: Bia", trim((string) $composicao->getNode(0)?->textContent));

        // Menu à direita do .vCandF; Subir some na primeira linha e Descer na última; "Remover foto" é de outra etapa.
        self::assertSame(['Subir', 'Descer', 'Remover candidatura'], self::rotulos($ana->filter('.vCandR > .vKw > .vKm > button')));
        self::assertNotNull($ana->filter('.vKm > button[data-voto-opcao-mover="-1"]')->attr('hidden'));
        self::assertNull($ana->filter('.vKm > button[data-voto-opcao-mover="1"]')->attr('hidden'));
        self::assertNotNull($ana->filter('.vKm > button.vKd[data-voto-opcao-remover]')->attr('data-voto-opcao-remover'));
        self::assertNotNull($linhas->eq(1)->filter('.vKm > button[data-voto-opcao-mover="1"]')->attr('hidden'));
        self::assertStringNotContainsString('Remover foto', $tela->html());
    }

    #[TestDox('Pauta de deliberação: só a descrição da opção; unidade, propostas e composição ficam escondidas')]
    public function testLinhaDaOpcaoDeDeliberacao(): void
    {
        $tela = $this->client->request('GET', $this->url());
        $linha = $tela->filter('form#pauta-' . $this->contas->getId() . ' > .voto-opcoes > .voto-opcao')->first();

        self::assertSame('Descrição da opção', trim($linha->filter('.vCandF > details.vDet > summary')->text()));
        self::assertCount(3, $linha->filter('[data-voto-so-pessoa][hidden]'), 'unidade, propostas e composição');
        self::assertCount(1, $linha->filter('input[name="opcao_descricao[]"]:not([hidden])'));
        self::assertSame(['Subir', 'Descer', 'Remover opção'], self::rotulos($linha->filter('.vCandR > .vKw > .vKm > button')));
    }

    #[TestDox('Responsivo abaixo de 560px: o campo vai para a linha cheia e o menu fica à direita (CSS do desenho)')]
    public function testCssResponsivo(): void
    {
        $css = (string) file_get_contents(__DIR__ . '/../../../public/css/voto.css');

        self::assertStringContainsString('@media (max-width: 560px) { .voto-tela .voto-opcao .vCandF { order: 3; flex-basis: 100%; } .voto-tela .voto-opcao > .vKw { margin-left: auto; } }', $css);
        self::assertStringContainsString('.voto-tela .voto-opcao .vCandF { flex: 1 1 280px; min-width: 0; display: grid; grid-template-columns: 76px minmax(0, 1fr); gap: 7px; }', $css);
    }

    #[TestDox('Salvar a pauta grava descrição, propostas, composição e unidade; a tela devolve o que foi gravado')]
    public function testSalvarGravaOsDadosDaCandidatura(): void
    {
        $tela = $this->client->request('GET', $this->url());
        $this->client->request('POST', $this->url('/' . $this->eleicao->getId() . '/editar'), [
            '_token' => $this->token($tela), 'tipo' => 'eleicao_cargo', 'titulo' => 'Síndico(a)', 'descricao' => '',
            'opcao_numero' => ['1', '2'], 'opcao_rotulo' => ['Ana Souza', 'Caio Lima'],
            'opcao_descricao' => ['Nova descrição', ''], 'opcao_propostas' => ['Propostas novas', ''],
            'opcao_composicao' => ["Presidente: Ana\nTesoureiro: Caio", ''], 'opcao_unidade' => ['202', ''],
        ]);
        self::assertResponseRedirects();

        $linhas = $this->em->getConnection()->fetchAllAssociative('SELECT rotulo, descricao, propostas, composicao, unidade_candidato FROM voto.assembleia_opcao WHERE pauta_id = ? AND abstencao = false ORDER BY numero', [$this->eleicao->getId()]);
        self::assertSame(['Ana Souza', 'Nova descrição', 'Propostas novas', "Presidente: Ana\nTesoureiro: Caio", '202'], array_values($linhas[0]));
        self::assertSame(['Caio Lima', null, null, null, null], array_values($linhas[1]));
        $tela = $this->client->request('GET', $this->url());
        self::assertSame('Nova descrição', $tela->filter('form#pauta-' . $this->eleicao->getId() . ' input[name="opcao_descricao[]"]')->first()->attr('value'));
    }

    #[TestDox('Mover: troca com a vizinha, renumera e o redirecionamento volta à pauta')]
    public function testMover(): void
    {
        $token = $this->token($this->client->request('GET', $this->url()));

        $this->client->request('POST', $this->url('/' . $this->contas->getId() . '/mover/cima'), ['_token' => $token]);
        self::assertResponseRedirects($this->url() . '#pauta-' . $this->contas->getId());
        self::assertSame(['Contas', 'Síndico(a)', 'Obra'], $this->ordemNoBanco());
        self::assertSame([1, 2, 3], array_map('intval', $this->em->getConnection()->fetchFirstColumn('SELECT ordem FROM voto.assembleia_pauta WHERE assembleia_id = ? ORDER BY ordem', [$this->assembleia->getId()])));

        $this->client->xmlHttpRequest('POST', $this->url('/' . $this->contas->getId() . '/mover/baixo'), ['_token' => $token]);
        self::assertResponseStatusCodeSame(200);
        self::assertSame(['Síndico(a)', 'Contas', 'Obra'], $this->ordemNoBanco());
    }

    #[TestDox('Duplicar: a cópia entra logo abaixo, com as candidaturas e os dados, e sem voto')]
    public function testDuplicar(): void
    {
        $token = $this->token($this->client->request('GET', $this->url()));

        $this->client->request('POST', $this->url('/' . $this->eleicao->getId() . '/duplicar'), ['_token' => $token]);
        self::assertResponseRedirects();
        self::assertSame(['Síndico(a)', 'Síndico(a)', 'Contas', 'Obra'], $this->ordemNoBanco());
        $copia = $this->em->getConnection()->fetchAssociative('SELECT id, tipo FROM voto.assembleia_pauta WHERE assembleia_id = ? AND ordem = 2', [$this->assembleia->getId()]);
        self::assertSame('eleicao_cargo', $copia['tipo']);
        $opcoes = $this->em->getConnection()->fetchAllAssociative('SELECT rotulo, descricao, unidade_candidato, total_unidades FROM voto.assembleia_opcao WHERE pauta_id = ? ORDER BY numero', [$copia['id']]);
        self::assertSame(['Abstenção', 'Ana Souza', 'Caio Lima'], array_column($opcoes, 'rotulo'));
        self::assertSame(['Moradora do 101', '101', 0], [$opcoes[1]['descricao'], $opcoes[1]['unidade_candidato'], (int) $opcoes[1]['total_unidades']]);
    }

    #[TestDox('CSRF inválido: mover e duplicar não mexem em nada (403 no fetch)')]
    public function testCsrfInvalido(): void
    {
        $this->client->request('GET', $this->url());
        foreach (['/' . $this->contas->getId() . '/mover/cima', '/' . $this->contas->getId() . '/duplicar'] as $rota) {
            $this->client->xmlHttpRequest('POST', $this->url($rota), ['_token' => 'invalido']);
            self::assertResponseStatusCodeSame(403, $rota);
        }
        self::assertSame(['Síndico(a)', 'Contas', 'Obra'], $this->ordemNoBanco());
    }

    #[TestDox('Publicada: mover e duplicar são recusados (422) e nada muda')]
    public function testSoEmRascunho(): void
    {
        $token = $this->token($this->client->request('GET', $this->url()));
        $this->em->getConnection()->executeStatement("UPDATE voto.assembleia SET estado = 'publicada' WHERE id = ?", [$this->assembleia->getId()]);

        foreach (['/' . $this->contas->getId() . '/mover/cima', '/' . $this->contas->getId() . '/duplicar'] as $rota) {
            $this->client->xmlHttpRequest('POST', $this->url($rota), ['_token' => $token]);
            self::assertResponseStatusCodeSame(422, $rota);
        }
        self::assertSame(['Síndico(a)', 'Contas', 'Obra'], $this->ordemNoBanco());
    }

    #[TestDox('Assembleia de outro escritório: mover e duplicar dão 404 e nada muda')]
    public function testOutroEscritorioDa404(): void
    {
        $alheia = AssembleiaFactory::new()->create()->_real();
        $pauta = PautaDaAssembleiaFactory::new(['assembleia' => $alheia, 'titulo' => 'Alheia'])->comOpcoesPadrao()->create()->_real();
        UnidadeDaAssembleiaFactory::createOne(['assembleia' => $alheia]);
        $this->client->request('GET', $this->url());
        $sessao = $this->client->getRequest()->getSession();
        $sessao->set('_csrf/voto_assembleia_' . $alheia->getId(), 'token-de-teste');
        $sessao->save();

        foreach (['/mover/baixo', '/mover/cima', '/duplicar'] as $rota) {
            $this->client->request('POST', '/voto/assembleias/' . $alheia->getId() . '/pautas/' . $pauta->getId() . $rota, ['_token' => 'token-de-teste']);
            self::assertResponseStatusCodeSame(404, $rota);
        }
        // A pauta do meu escritório pelo id da assembleia alheia também não vale (guarda de posse).
        $this->client->request('POST', '/voto/assembleias/' . $alheia->getId() . '/pautas/' . $this->contas->getId() . '/duplicar', ['_token' => 'token-de-teste']);
        self::assertResponseStatusCodeSame(404);
        self::assertSame(1, (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM voto.assembleia_pauta WHERE assembleia_id = ?', [$alheia->getId()]));
        self::assertSame(['Síndico(a)', 'Contas', 'Obra'], $this->ordemNoBanco());
    }
}
