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

        $painel = $crawler->filter('#detalhes > .ps-grade > .ps-detalhes-obs');
        self::assertCount(1, $painel);
        self::assertCount(0, $crawler->filter('#formDetalhesObservacao, #detalhesObsConteudo, #btnEnviarObsDetalhes'));
        self::assertCount(1, $painel->filter('.ps-detalhes-obs > .ps-compositor--leitura#detalhesObsSomenteLeitura'), 'no lugar do compositor, a nota de só leitura');
        self::assertCount(0, $crawler->filter('#detalhesObsLista .btn-responder-obs-det, #detalhesObsLista .js-obs-responder'));
        self::assertCount(0, $crawler->filter('#detalhesObsLista .js-obs-citar'), 'sem compositor, não há onde citar');
        self::assertCount(0, $crawler->filter('#detalhesObsLista .ps-anotacao-acoes, #detalhesObsLista .btn-editar-obs-det, #detalhesObsLista .btn-excluir-obs-det'));
        self::assertCount(0, $crawler->filter('#detalhesObsLista .js-obs-editar, #detalhesObsLista .js-obs-excluir'), 'nenhum Editar/Excluir que acione algo');

        // O ⋮ fica com o que é só leitura; Editar/Excluir à vista e bloqueados (como no desenho).
        foreach ([$alheia, $propria] as $obs) {
            $id    = $obs->getId();
            $itens = $crawler->filter("#detalhes-obs-{$id} > .ps-anotacao-topo > #psObsMenu{$id} > .ps-processo-menu-item")->each(
                static fn ($i): string => trim(preg_replace('/\s+/u', ' ', $i->text()) ?? ''),
            );
            self::assertSame(['Copiar texto', 'Imprimir', 'Editar · bloqueado', 'Excluir · bloqueado'], $itens);
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
        self::assertStringContainsString('title="Editável por mais 15 minutos" aria-label="Editar observação"', $montador);
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

        $editado = $crawler->filter('#detalhes-obs-' . $obs->getId() . ' > .ps-anotacao-topo > .ps-anotacao-autor + .ps-anotacao-editado');
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
        self::assertStringContainsString("'Publicado às ' + publicado + ' · '", $fn);
        self::assertStringContainsString("'última edição ' + edicao", $fn);
        self::assertStringContainsString("partes[0] + ' às ' + partes[1]", $fn);
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
        self::assertCount(1, $crawler->filter("#psObsMenu{$idAlheia} > [data-obs-janela=\"editar\"][disabled]"));
        self::assertCount(1, $crawler->filter("#psObsMenu{$idAlheia} > [data-obs-janela=\"excluir\"][disabled]"));

        $html = (string) $client->getResponse()->getContent();
        // A abertura do ⋮ repinta; o cálculo é o do Registro (teto, 1..15, 0 = vencida).
        $abrir = substr($html, (int) strpos($html, 'function abrirMenuObs(gatilho, px, py) {'), 800);
        self::assertStringContainsString("pintarJanelaObs(menu, gatilho.closest('.detalhes-obs-item'));", $abrir);
        $minutos = substr($html, (int) strpos($html, 'function minutosRestantesObs(artigo) {'), 500);
        self::assertStringContainsString('Number(artigo ? artigo.dataset.janelaAte : 0) * 1000', $minutos);
        self::assertStringContainsString('Math.min(15, Math.max(1, Math.ceil(ms / 60000)))', $minutos);
        $pintar = substr($html, (int) strpos($html, 'function pintarJanelaObs(menu, artigo) {'), 2500);
        self::assertStringContainsString("'Editar · bloqueado', 'bi-lock', motivo", $pintar);
        self::assertStringContainsString("'Editar · ' + resto + ' min'", $pintar);
        // Item que ficou bloqueado com a página aberta não aciona o botão do cartão.
        $acionar = substr($html, (int) strpos($html, 'function acionarDoCartao(item, seletor) {'), 300);
        self::assertStringContainsString('if (item.disabled) { return; }', $acionar);

        // O cartão do JS nasce com epoch NUMÉRICO (nunca `Number()` de data formatada — o NaN do C6-PAS-01).
        $montador = $this->montador($client);
        self::assertStringContainsString('const janelaAte  = Math.floor(Date.now() / 1000) + 15 * 60;', $montador);
        self::assertStringContainsString('data-janela-ate="${janelaAte}" data-do-autor="1"', $montador);
        self::assertStringNotContainsString('Number(data.criadaEm', $montador);
        self::assertStringContainsString('data-obs-janela="editar"', $montador);
        self::assertStringContainsString('data-obs-janela="excluir"', $montador);
    }
}
