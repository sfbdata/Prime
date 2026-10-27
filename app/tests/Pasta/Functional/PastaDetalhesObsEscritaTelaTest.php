<?php

declare(strict_types=1);

namespace App\Tests\Pasta\Functional;

use App\Controller\PastaController;
use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Pasta\Entity\Pasta;
use App\Pasta\Entity\PastaObservacaoDetalhes;
use App\Pasta\Service\JanelaDeEdicaoDeComentario;
use App\Tests\Functional\JusPrimeWebTestCase;
use App\Tests\Support\CatalogoPtBr;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;

/**
 * Aba Detalhes ("Relatório inicial de Atendimento"), Ciclo 6:
 *
 * - C6-PAS-02: a escrita que o servidor recusa não aparece (convenção PAS-014/PAS-025c). Enviar,
 *   responder, editar e excluir exigem EDIT na pasta, e na lápide todo POST é recusado. Quem só
 *   vê a pasta, e a lápide, não recebem compositor, Responder (botão e item do ⋮), Citar, lápis
 *   nem lixeira — nem no cartão montado em JS.
 * - C6-PAS-03: na linha do cartão só o lápis, com os minutos na dica; a lixeira fica escondida
 *   (o ⋮ a clica) e o "· N min" sai.
 * - C6-PAS-04: a dica do "editado" diz "Publicado às … · última edição … às …".
 * - C6-PAS-05: o cartão leva `data-janela-ate` (epoch NUMÉRICO) e `data-do-autor`, e o ⋮ recalcula
 *   os minutos a cada abertura.
 */
#[CoversClass(PastaController::class)]
#[Group('pasta')]
final class PastaDetalhesObsEscritaTelaTest extends JusPrimeWebTestCase
{
    use CriaFixturesPushDaPastaTrait;

    private function criarObs(Pasta $pasta, Tenant $tenant, User $autor, string $quando = 'now'): PastaObservacaoDetalhes
    {
        $obs = new PastaObservacaoDetalhes();
        $obs->setPasta($pasta);
        $obs->setTenant($tenant);
        $obs->setAutor($autor);
        $obs->setConteudo('Relato do cliente sobre o acidente.');
        (new \ReflectionProperty(PastaObservacaoDetalhes::class, 'criadaEm'))->setValue($obs, new \DateTimeImmutable($quando));
        $this->em()->persist($obs);
        $this->em()->flush();

        return $obs;
    }

    /** O trecho do script que monta o cartão de uma observação recém-enviada. */
    private function montador(KernelBrowser $client): string
    {
        $html = (string) $client->getResponse()->getContent();
        $ini  = strpos($html, 'function inserirItem(data)');
        self::assertNotFalse($ini);
        $fim = strpos($html, 'obsLista.insertAdjacentHTML', $ini);
        self::assertNotFalse($fim);

        return substr($html, $ini, $fim - $ini);
    }

    #[TestDox('C6-PAS-02: quem só vê a pasta não recebe compositor, Responder, Citar, lápis nem lixeira — nem na observação que ele mesmo escreveu')]
    public function testLeitorNaoRecebeEscrita(): void
    {
        $client           = static::createClient();
        [$admin, $tenant] = $this->criarAdmin();
        $leitor           = $this->criarUsuarioSemPermissaoDoModulo($tenant);
        $pasta            = $this->criarPasta($tenant);
        $alheia           = $this->criarObs($pasta, $tenant, $admin, '-3 minutes');
        // Escrita por ele quando ainda podia editar, e dentro da janela: nem assim (a rota exige EDIT).
        $propria          = $this->criarObs($pasta, $tenant, $leitor, '-2 minutes');

        $this->logarComTenant($client, $leitor, $tenant);
        $crawler = $client->request('GET', '/pasta/' . $pasta->getId());
        self::assertResponseIsSuccessful();

        $painel = $crawler->filter('#detalhes > details.mdl-relatorio > .mdl-relatorio-corpo > .ps-grade > .ps-detalhes-obs');
        self::assertCount(1, $painel);
        self::assertCount(0, $crawler->filter('#formDetalhesObservacao, #detalhesObsConteudo, #btnEnviarObsDetalhes'));
        self::assertCount(0, $crawler->filter('#detalhesObsSomenteLeitura'), 'pasta viva: sem nota inventada (a da lápide é outro caso)');
        self::assertCount(0, $crawler->filter('#detalhesObsLista .btn-responder-obs-det, #detalhesObsLista .js-obs-responder'));
        self::assertCount(0, $crawler->filter('#detalhesObsLista .js-obs-citar'), 'sem compositor, não há onde citar');
        self::assertCount(0, $crawler->filter('#detalhesObsLista .ps-anotacao-acoes, #detalhesObsLista .btn-editar-obs-det, #detalhesObsLista .btn-excluir-obs-det'));
        self::assertCount(0, $crawler->filter('#detalhesObsLista .js-obs-editar, #detalhesObsLista .js-obs-excluir'), 'nenhum Editar/Excluir que acione algo');

        // O ⋮ fica só com o que é leitura: sem Editar/Excluir, nem bloqueados (o motivo seria permissão).
        // Encaminhar via Chat I.A (D:6869) é leitura: quem vê a pasta encaminha (o servidor relê e confere).
        foreach ([$alheia, $propria] as $obs) {
            $id    = $obs->getId();
            $itens = $crawler->filter("#detalhes-obs-{$id} > .ps-anotacao-topo > #psObsMenu{$id} > .ps-processo-menu-item")->each(
                static fn ($i): string => trim(preg_replace('/\s+/u', ' ', $i->text()) ?? ''),
            );
            self::assertSame(['Copiar texto', 'Encaminhar via Chat I.A', 'Enviar no Chat I.A', 'Imprimir'], $itens);
            self::assertCount(0, $crawler->filter("#psObsMenu{$id} > .ps-processo-menu-sep"));
        }

        // O cartão montado em JS também nasce sem escrita.
        $montador = $this->montador($client);
        foreach (['btn-responder-obs-det', 'js-obs-responder', 'js-obs-citar', 'btn-editar-obs-det', 'btn-excluir-obs-det', 'js-obs-editar', 'js-obs-excluir'] as $classe) {
            self::assertStringNotContainsString($classe, $montador, $classe . ' no cartão do JS');
        }
    }

    #[TestDox('C6-PAS-02: sem observação, quem só vê a pasta não lê "Registre acima…"')]
    public function testLeitorVazioSemConviteParaRegistrar(): void
    {
        $client           = static::createClient();
        [, $tenant]       = $this->criarAdmin();
        $leitor           = $this->criarUsuarioSemPermissaoDoModulo($tenant);
        $pasta            = $this->criarPasta($tenant);

        $this->logarComTenant($client, $leitor, $tenant);
        $crawler = $client->request('GET', '/pasta/' . $pasta->getId());
        self::assertResponseIsSuccessful();

        self::assertCount(1, $crawler->filter('#detalhesObsLista > #detalhesObsVazio > .ps-vazio-titulo'));
        self::assertCount(0, $crawler->filter('#detalhesObsLista > #detalhesObsVazio > .ps-vazio-nota'));
    }

    #[TestDox('C6-PAS-02: quem edita a pasta continua recebendo compositor, Responder, Citar e o lápis')]
    public function testQuemEditaRecebeEscrita(): void
    {
        $client          = static::createClient();
        [$user, $tenant] = $this->criarAdmin();
        $pasta           = $this->criarPasta($tenant);
        $obs             = $this->criarObs($pasta, $tenant, $user, '-5 minutes');

        $this->logarComTenant($client, $user, $tenant);
        $crawler = $client->request('GET', '/pasta/' . $pasta->getId());
        self::assertResponseIsSuccessful();

        $id = $obs->getId();
        self::assertCount(1, $crawler->filter('.ps-detalhes-obs > .ps-compositor > #formDetalhesObservacao'));
        self::assertCount(0, $crawler->filter('#detalhesObsSomenteLeitura'));
        self::assertCount(1, $crawler->filter("#detalhes-obs-{$id} > .ps-anotacao-topo > .btn-responder-obs-det"));
        self::assertCount(1, $crawler->filter("#psObsMenu{$id} > .js-obs-responder"));
        self::assertCount(1, $crawler->filter("#psObsMenu{$id} > .js-obs-citar"));
        self::assertCount(1, $crawler->filter("#detalhes-obs-{$id} > .ps-anotacao-topo > .ps-anotacao-acoes > .btn-editar-obs-det"));

        $montador = $this->montador($client);
        foreach (['btn-responder-obs-det', 'js-obs-responder', 'js-obs-citar', 'btn-editar-obs-det', 'btn-excluir-obs-det', 'js-obs-editar', 'js-obs-excluir'] as $classe) {
            self::assertStringContainsString($classe, $montador, $classe . ' no cartão do JS');
        }
    }

    #[TestDox('C6-PAS-02: na lápide nem o autor dentro da janela recebe compositor, lápis, lixeira, Responder ou Citar')]
    public function testLapideSemEscrita(): void
    {
        $client          = static::createClient();
        [$user, $tenant] = $this->criarAdmin();
        $pasta           = $this->criarPasta($tenant);
        $obs             = $this->criarObs($pasta, $tenant, $user, '-5 minutes');
        $pasta->marcarExcluida($user, new \DateTimeImmutable());
        $this->em()->flush();

        $this->logarComTenant($client, $user, $tenant);
        $crawler = $client->request('GET', '/pasta/' . $pasta->getId());
        self::assertResponseIsSuccessful();

        self::assertCount(0, $crawler->filter('#formDetalhesObservacao'));
        $nota = $crawler->filter('.ps-detalhes-obs > .ps-compositor--leitura#detalhesObsSomenteLeitura');
        self::assertCount(1, $nota);
        self::assertStringContainsString('Pasta excluída', $nota->text());
        $id = $obs->getId();
        self::assertCount(0, $crawler->filter("#detalhes-obs-{$id} .ps-anotacao-acoes, #detalhes-obs-{$id} .btn-editar-obs-det, #detalhes-obs-{$id} .btn-excluir-obs-det"));
        self::assertCount(0, $crawler->filter("#detalhes-obs-{$id} .btn-responder-obs-det, #detalhes-obs-{$id} .js-obs-responder, #detalhes-obs-{$id} .js-obs-citar"));
    }

    #[TestDox('C6-PAS-02 (isolamento): usuário de outro escritório não abre a pasta nem vê as observações dela')]
    public function testOutroEscritorioNaoVe(): void
    {
        $client             = static::createClient();
        [$userA, $tenantA]  = $this->criarAdmin();
        [$userB, $tenantB]  = $this->criarAdmin();
        $pastaA             = $this->criarPasta($tenantA);
        $obs                = $this->criarObs($pastaA, $tenantA, $userA, '-2 minutes');
        $idPasta            = (int) $pastaA->getId();
        $idObs              = (int) $obs->getId();

        $this->logarComTenant($client, $userB, $tenantB);
        // Sem o clear() a pasta fica no cache do Doctrine e o TenantFilter não tem o que filtrar.
        $this->em()->clear();
        $client->request('GET', '/pasta/' . $idPasta);

        self::assertResponseStatusCodeSame(404);
        $corpo = (string) $client->getResponse()->getContent();
        self::assertStringNotContainsString('detalhes-obs-' . $idObs, $corpo);
        self::assertStringNotContainsString('Relato do cliente sobre o acidente.', $corpo);
    }

    #[TestDox('C6-PAS-03: na linha do autor só o lápis, com "Editável por mais 10 minutos"; a lixeira existe escondida e o "· N min" saiu')]
    public function testLinhaSoComOLapis(): void
    {
        $client          = static::createClient();
        [$user, $tenant] = $this->criarAdmin();
        $pasta           = $this->criarPasta($tenant);
        $obs             = $this->criarObs($pasta, $tenant, $user, '-5 minutes');

        $this->logarComTenant($client, $user, $tenant);
        $crawler = $client->request('GET', '/pasta/' . $pasta->getId());

        $acoes = $crawler->filter('#detalhes-obs-' . $obs->getId() . ' > .ps-anotacao-topo > .ps-anotacao-acoes');
        self::assertCount(1, $acoes);
        self::assertSame('Editável por mais 10 minutos', $acoes->filter('.ps-anotacao-acoes > .btn-editar-obs-det')->attr('title'));
        $lixeira = $acoes->filter('.ps-anotacao-acoes > .btn-excluir-obs-det[data-url][data-csrf]');
        self::assertCount(1, $lixeira, 'o ⋮ clica a lixeira: ela continua no cartão');
        self::assertNotNull($lixeira->attr('hidden'));
        self::assertCount(0, $crawler->filter('#detalhesObsLista .ps-anotacao-janela'));

        $montador = $this->montador($client);
        self::assertStringNotContainsString('ps-anotacao-janela', $montador);
        self::assertStringContainsString('title="${esc(editavelDica(15))}" aria-label="${esc(BJ_T.t(\'pasta_detalhes.js.editar\'))}"', $montador);
        $catalogo = CatalogoPtBr::carregar()['pasta_detalhes']['js'];
        self::assertSame('Editável por mais {minutos} minutos', $catalogo['editavel_varios']);
        self::assertSame('Editar observação', $catalogo['editar']);
        self::assertMatchesRegularExpression('/<button type="button" hidden\s+class="ps-anotacao-acao ps-anotacao-acao--perigo btn-excluir-obs-det"/', $montador);
    }

    #[TestDox('C6-PAS-04: a dica do "editado" diz "Publicado às HH:MM · última edição dd/mm/aaaa às HH:MM"')]
    public function testDicaDoEditado(): void
    {
        $client          = static::createClient();
        [$user, $tenant] = $this->criarAdmin();
        $pasta           = $this->criarPasta($tenant);
        $obs             = $this->criarObs($pasta, $tenant, $user, '-2 hours');
        $obs->setEditadaEm(new \DateTimeImmutable('-1 hour'));
        $this->em()->flush();

        $this->logarComTenant($client, $user, $tenant);
        $crawler = $client->request('GET', '/pasta/' . $pasta->getId());

        // Desenho 02 (dc L2458): autor, tag fixa "Observação" e só então "editado".
        $editado = $crawler->filter('#detalhes-obs-' . $obs->getId() . ' > .ps-anotacao-topo > .ps-anotacao-autor + .detalhes-obs-tag + .ps-anotacao-editado');
        self::assertCount(1, $editado);
        $ed = $obs->getEditadaEm();
        self::assertNotNull($ed);
        self::assertSame(
            'Publicado às ' . $obs->getCriadaEm()->format('H:i') . ' · última edição ' . $ed->format('d/m/Y') . ' às ' . $ed->format('H:i'),
            $editado->attr('title'),
        );
        self::assertStringNotContainsString('Editado em', (string) $client->getResponse()->getContent());

        // Ao salvar uma edição sem recarregar, o JS põe a MESMA dica.
        $html = (string) $client->getResponse()->getContent();
        self::assertStringContainsString('marcarEditadoObs(item, data.editadaEm);', $html);
        $fn = substr($html, (int) strpos($html, 'function marcarEditadoObs(artigo, editadaEm) {'), 2500);
        self::assertStringContainsString("BJ_T.t('pasta_detalhes.js.editado_dica', { hora: publicado, quando: edicao })", $fn);
        self::assertStringContainsString("BJ_T.t('pasta_detalhes.js.editado_dica_sem_hora', { quando: edicao })", $fn);
        self::assertStringContainsString("BJ_T.t('pasta_detalhes.js.editado_quando', { data: partes[0], hora: partes[1] })", $fn);
        $catalogo = CatalogoPtBr::carregar()['pasta_detalhes']['js'];
        self::assertSame('Publicado às {hora} · última edição {quando}', $catalogo['editado_dica']);
        self::assertSame('última edição {quando}', $catalogo['editado_dica_sem_hora']);
        self::assertSame('{data} às {hora}', $catalogo['editado_quando']);
        // Ordem do desenho 02 (dc L2458): autor, tag "Observação", editado. A âncora é a tag e, sem ela, o autor.
        self::assertMatchesRegularExpression(
            "/const ancora = topo\\.querySelector\\(':scope > \\.detalhes-obs-tag'\\) \\|\\| topo\\.querySelector\\(':scope > \\.ps-anotacao-autor'\\);\\s*if \\(ancora\\) \\{ ancora\\.after\\(marca\\); \\}/",
            $fn,
        );
        self::assertStringNotContainsString('autor.after(marca)', $fn);
    }

    #[TestDox('C6-PAS-05: o cartão leva data-janela-ate = criadaEm + 15 min (epoch) e data-do-autor; o ⋮ recalcula ao abrir')]
    public function testJanelaRecalculadaAoAbrir(): void
    {
        $client          = static::createClient();
        [$user, $tenant] = $this->criarAdmin();
        $outro           = $this->criarUsuarioSemPermissaoDoModulo($tenant);
        $pasta           = $this->criarPasta($tenant);
        $minha           = $this->criarObs($pasta, $tenant, $user, '-5 minutes');
        $alheia          = $this->criarObs($pasta, $tenant, $outro, '-5 minutes');

        $this->logarComTenant($client, $user, $tenant);
        $crawler = $client->request('GET', '/pasta/' . $pasta->getId());

        $cartao = $crawler->filter('#detalhesObsLista > article.detalhes-obs-item#detalhes-obs-' . $minha->getId());
        self::assertSame(
            (string) ($minha->getCriadaEm()->getTimestamp() + JanelaDeEdicaoDeComentario::MINUTOS * 60),
            $cartao->attr('data-janela-ate'),
        );
        self::assertSame('1', $cartao->attr('data-do-autor'));
        self::assertSame('0', $crawler->filter('#detalhes-obs-' . $alheia->getId())->attr('data-do-autor'));

        // Os dois itens da janela são marcados nos dois estados, para o JS repintar.
        $id = $minha->getId();
        self::assertCount(1, $crawler->filter("#psObsMenu{$id} > .js-obs-editar[data-obs-janela=\"editar\"]"));
        self::assertCount(1, $crawler->filter("#psObsMenu{$id} > .js-obs-excluir[data-obs-janela=\"excluir\"]"));
        $idAlheia = $alheia->getId();
        // O admin daqui é o Master: a alheia abre pelo poder sem prazo, com o selo (spec edicao-sem-prazo).
        self::assertCount(1, $crawler->filter("#psObsMenu{$idAlheia} > .js-obs-editar.com-selo-sem-prazo[data-obs-janela=\"editar\"] > .selo-sem-prazo"));
        self::assertCount(1, $crawler->filter("#psObsMenu{$idAlheia} > .js-obs-excluir.com-selo-sem-prazo[data-obs-janela=\"excluir\"] > .selo-sem-prazo"));
        self::assertCount(0, $crawler->filter("#psObsMenu{$idAlheia} > [disabled]"));

        $html = (string) $client->getResponse()->getContent();
        // A abertura do ⋮ repinta; o cálculo é o do Registro (teto, 1..15, 0 = vencida).
        $abrir = substr($html, (int) strpos($html, 'function abrirMenuObs(gatilho, px, py) {'), 800);
        self::assertStringContainsString("pintarJanelaObs(menu, gatilho.closest('.detalhes-obs-item'));", $abrir);
        $minutos = substr($html, (int) strpos($html, 'function minutosRestantesObs(artigo) {'), 500);
        self::assertStringContainsString('Number(artigo ? artigo.dataset.janelaAte : 0) * 1000', $minutos);
        self::assertStringContainsString('Math.min(15, Math.max(1, Math.ceil(ms / 60000)))', $minutos);
        $pintar = substr($html, (int) strpos($html, 'function pintarJanelaObs(menu, artigo) {'), 2500);
        self::assertStringContainsString("BJ_T.t('pasta_detalhes.js.editar_bloqueado'), 'bi-lock', motivo", $pintar);
        self::assertStringContainsString("BJ_T.t('pasta_detalhes.js.editar_min', { minutos: resto })", $pintar);
        $catalogo = CatalogoPtBr::carregar()['pasta_detalhes']['js'];
        self::assertSame('Editar · bloqueado', $catalogo['editar_bloqueado']);
        self::assertSame('Editar · {minutos} min', $catalogo['editar_min']);
        // Item que ficou bloqueado com a página aberta não aciona o botão do cartão: avisa o motivo.
        $acionar = substr($html, (int) strpos($html, 'function acionarDoCartao(item, seletor) {'), 400);
        self::assertStringContainsString("if (item.getAttribute('aria-disabled') === 'true') { avisarBloqueadoObs(item); return; }", $acionar);

        // O cartão do JS nasce com epoch NUMÉRICO (nunca `Number()` de data formatada — o NaN do
        // C6-PAS-01), tirado do instante que o SERVIDOR gravou, não do relógio do navegador (C6-PAS-07).
        $montador = $this->montador($client);
        self::assertStringContainsString('const janelaAte  = Number(data.criadaEmEpoch) + 15 * 60;', $montador);
        self::assertStringNotContainsString('Math.floor(Date.now() / 1000)', $montador);
        self::assertStringContainsString('data-janela-ate="${janelaAte}" data-do-autor="1"', $montador);
        self::assertStringNotContainsString('Number(data.criadaEm)', $montador);
        self::assertStringNotContainsString('Number(data.criadaEmTs', $montador);
        self::assertStringContainsString('data-obs-janela="editar"', $montador);
        self::assertStringContainsString('data-obs-janela="excluir"', $montador);
    }

    #[TestDox('C7-FPN-02: "Editar/Excluir · bloqueado" do ⋮ de Detalhes é clicável e diz o motivo num aviso neutro (dc 5958, gmAviso)')]
    public function testBloqueadoClicavelAvisaOMotivo(): void
    {
        $client          = static::createClient();
        [$user, $tenant] = $this->criarAdmin();
        $pasta           = $this->criarPasta($tenant);
        $this->criarObs($pasta, $tenant, $user, '-20 minutes');

        $this->logarComTenant($client, $user, $tenant);
        $client->request('GET', '/pasta/' . $pasta->getId());
        self::assertResponseIsSuccessful();
        $html = (string) $client->getResponse()->getContent();

        // Repintar não desabilita: marca `aria-disabled` e `data-aviso` com o motivo.
        $pintar = substr($html, (int) strpos($html, 'function pintarItemObs(item, pode, rotulo, icone, motivo) {'), 500);
        self::assertStringContainsString('item.disabled = false;', $pintar);
        self::assertStringNotContainsString('item.disabled = !pode', $pintar);
        self::assertStringContainsString("item.setAttribute('data-aviso', motivo);", $pintar);

        // O clique num item bloqueado fecha o menu e avisa o motivo, sem o tom de erro.
        $avisar = substr($html, (int) strpos($html, 'function avisarBloqueadoObs(item) {'), 400);
        self::assertStringContainsString("item.getAttribute('data-aviso')", $avisar);
        self::assertStringContainsString('fecharMenuObs(false);', $avisar);
        self::assertStringContainsString('window.avisarPs(motivo);', $avisar);
        self::assertStringNotContainsString("'erro'", $avisar);

        // A delegação atende o bloqueado ANTES de Editar/Excluir.
        // O Financeiro tem uma delegação com a mesma assinatura e vem antes na página: ancora no bloco de Detalhes.
        $inicioDet = (int) strpos($html, 'function avisarBloqueadoObs(item) {');
        $clique = substr($html, (int) strpos($html, "obsLista.addEventListener('click', async function (e) {", $inicioDet), 3000);
        $posBloq   = strpos($clique, "e.target.closest('.ps-obs-menu [aria-disabled=\"true\"]')");
        $posEditar = strpos($clique, "e.target.closest('.js-obs-editar')");
        self::assertNotFalse($posBloq);
        self::assertNotFalse($posEditar);
        self::assertStringContainsString('avisarBloqueadoObs(itemBloqueado); return;', $clique);
        self::assertLessThan($posEditar, $posBloq);
    }

    /** @return array<string, mixed> o JSON do `pasta_detalhes_observacao_enviar` (201) */
    private function enviarObs(KernelBrowser $client, Pasta $pasta): array
    {
        $crawler = $client->request('GET', '/pasta/' . $pasta->getId());
        $token   = $crawler->filter('#formDetalhesObservacao input[name="_token"]')->attr('value');

        $client->request('POST', '/pasta/' . $pasta->getId() . '/detalhes/observacao', [
            '_token'   => $token,
            'conteudo' => '<p>Observação nova</p>',
        ], [], ['HTTP_X-Requested-With' => 'XMLHttpRequest']);
        self::assertResponseStatusCodeSame(201);

        return json_decode((string) $client->getResponse()->getContent(), true);
    }

    #[TestDox('C6-PAS-07: o envio de Detalhes devolve `criadaEmEpoch` inteiro, o mesmo instante gravado, para o JS calcular a janela')]
    public function testEnvioDevolveOEpochDaCriacao(): void
    {
        $client          = static::createClient();
        [$user, $tenant] = $this->criarAdmin();
        $pasta           = $this->criarPasta($tenant);

        $this->logarComTenant($client, $user, $tenant);
        $antes = time();
        $data  = $this->enviarObs($client, $pasta);

        self::assertIsInt($data['criadaEmEpoch']);
        self::assertGreaterThanOrEqual($antes - 5, $data['criadaEmEpoch']);
        self::assertLessThanOrEqual(time() + 5, $data['criadaEmEpoch']);

        $obs = $this->em()->find(PastaObservacaoDetalhes::class, $data['id']);
        self::assertNotNull($obs);
        self::assertSame($obs->getCriadaEm()->getTimestamp(), $data['criadaEmEpoch'], 'a mesma base do `|date(\'U\')` do Twig');
    }

    #[TestDox('C6-PAS-07: o envio de Detalhes devolve a URL da foto do autor quando ele tem foto (rota da foto, como o Twig) e null quando não tem; o cartão do JS a usa escapada')]
    public function testEnvioDevolveAFotoDoAutor(): void
    {
        $client          = static::createClient();
        [$user, $tenant] = $this->criarAdmin();
        $pasta           = $this->criarPasta($tenant);

        $this->logarComTenant($client, $user, $tenant);
        self::assertNull($this->enviarObs($client, $pasta)['autorFotoUrl'], 'sem foto: o JS mostra as iniciais');

        // A requisição limpa o EntityManager: relê o autor antes de pendurar o perfil nele
        $autor   = $this->em()->find(\App\Entity\Auth\User::class, $user->getId());
        $profile = new \App\Profile\Entity\UserProfile($autor);
        $profile->setFotoUrl('foto-autor-det.jpg');
        $this->em()->persist($profile);
        $this->em()->flush();

        self::assertSame('/perfil/foto/foto-autor-det.jpg', $this->enviarObs($client, $pasta)['autorFotoUrl']);

        // O cartão montado em JS: com foto, a <img> (igual ao macro do Twig), com o src escapado; sem, as iniciais.
        $client->request('GET', '/pasta/' . $pasta->getId());
        self::assertStringContainsString(
            '<span class="ps-avatar ps-avatar--24">${data.autorFotoUrl ? `<img src="${esc(data.autorFotoUrl)}" alt="">` : esc(iniciaisDe(data.autorNome))}</span>',
            $this->montador($client),
        );
    }
}
