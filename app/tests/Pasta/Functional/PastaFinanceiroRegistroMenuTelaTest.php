<?php

declare(strict_types=1);

namespace App\Tests\Pasta\Functional;

use App\Controller\PastaController;
use App\Entity\Auth\User;
use App\Entity\Auth\UserTenant;
use App\Entity\Tenant\Tenant;
use App\Pasta\Entity\Pasta;
use App\Pasta\Entity\PastaObservacaoFinanceira;
use App\Tests\Functional\JusPrimeWebTestCase;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * FPN-006: botão direito no registro do Relatório financeiro (desenho 1.2.3, dc L.1795 e o menu
 * de dc L.5958). O menu é HTML do servidor que o script do `_financeiro.html.twig` preenche com o
 * registro clicado; "Editar" e "Excluir" aparecem só quando o cartão traz os botões da janela de
 * 15 min e clicam ESSES botões. Aqui se prova a marcação dos dois lados do contrato: o menu e os
 * gatilhos que cada cartão expõe (ou não) conforme o autor e o prazo.
 */
#[CoversClass(PastaController::class)]
#[Group('pasta')]
final class PastaFinanceiroRegistroMenuTelaTest extends JusPrimeWebTestCase
{
    private function em(): EntityManagerInterface
    {
        return static::getContainer()->get(EntityManagerInterface::class);
    }

    private function criarUsuario(Tenant $tenant, string $nome): User
    {
        $hasher = static::getContainer()->get(UserPasswordHasherInterface::class);

        $user = new User();
        $user->setEmail('fin_reg_menu_' . uniqid() . '@test.com');
        $user->setFullName($nome);
        $user->setRoles(['ROLE_SUPER_ADMIN']);
        $user->setIsActive(true);
        $user->setPassword($hasher->hashPassword($user, 'senha123'));
        $this->em()->persist($user);
        $this->em()->persist(new UserTenant($user, $tenant));
        $this->em()->flush();

        return $user;
    }

    /** @return array{User, Tenant, Pasta} */
    private function cenario(): array
    {
        $tenant = new Tenant();
        $tenant->setName('Tenant Registro Fin ' . uniqid());
        $this->em()->persist($tenant);
        $this->em()->flush();

        $user = $this->criarUsuario($tenant, 'Autora do Registro');

        $pasta = new Pasta();
        $pasta->setNup('FIN-REG-' . uniqid());
        $pasta->setTenant($tenant);
        $this->em()->persist($pasta);
        $this->em()->flush();

        return [$user, $tenant, $pasta];
    }

    private function criarRegistro(Pasta $pasta, User $autor, Tenant $tenant, string $conteudo, ?\DateTimeImmutable $criadaEm = null): PastaObservacaoFinanceira
    {
        $obs = (new PastaObservacaoFinanceira())
            ->setPasta($pasta)
            ->setAutor($autor)
            ->setTenant($tenant)
            ->setConteudo($conteudo);
        if ($criadaEm !== null) {
            (new \ReflectionProperty(PastaObservacaoFinanceira::class, 'criadaEm'))->setValue($obs, $criadaEm);
        }
        $this->em()->persist($obs);
        $this->em()->flush();

        return $obs;
    }

    #[TestDox('Arranjo: um menu só, filho direto do Relatório financeiro, escondido, com Responder, Copiar texto, Editar e Excluir')]
    public function testMenuDoRegistro(): void
    {
        $client = static::createClient();
        [$user, $tenant, $pasta] = $this->cenario();
        $this->logarComTenant($client, $user, $tenant);

        $crawler = $client->request('GET', "/pasta/{$pasta->getId()}");
        self::assertResponseIsSuccessful();

        $menu = $crawler->filter('#financeiro #financeiroRelatorio > #financeiroObsMenu.ps-fin-menu[role="menu"][hidden]');
        self::assertCount(1, $menu);
        self::assertCount(1, $menu->filter('#financeiroObsMenu > .ps-fin-menu-titulo'));

        $itens = $menu->filter('#financeiroObsMenu > button.ps-fin-menu-item[role="menuitem"]');
        // Desenho (dc L.5958 / D:6305): Responder, Copiar texto, Encaminhar via Chat I.A (com a Central),
        // separador, Editar, Excluir comentário (FPN-005).
        self::assertSame(['Responder', 'Copiar texto', 'Encaminhar via Chat I.A', 'Editar', 'Excluir comentário'], $itens->each(static fn ($b): string => trim($b->text())));
        self::assertSame(
            ['bi bi-reply-fill', 'bi bi-clipboard', 'bi bi-send', 'bi bi-pencil', 'bi bi-trash3'],
            $itens->each(static fn ($b): ?string => $b->filter('i')->attr('class')),
        );
        self::assertStringContainsString('js-fin-obs-responder', (string) $itens->eq(0)->attr('class'));
        self::assertStringContainsString('js-fin-obs-copiar', (string) $itens->eq(1)->attr('class'));
        self::assertStringContainsString('js-fin-obs-encaminhar', (string) $itens->eq(2)->attr('class'));
        self::assertStringContainsString('js-fin-obs-editar js-fin-obs-gerenciar', (string) $itens->eq(3)->attr('class'));
        self::assertStringContainsString('ps-fin-menu-item--perigo js-fin-obs-excluir js-fin-obs-gerenciar', (string) $itens->eq(4)->attr('class'));
        // Desenho (dc L.6454-6466, `menuObs`/`extrasObs`): "Imprimir" mora só no ⋮ da observação
        // de Detalhes; o menu do Relatório financeiro não tem o item.
        self::assertCount(0, $menu->filter('#financeiroObsMenu .js-fin-obs-imprimir, #financeiroObsMenu .js-obs-imprimir, #financeiroObsMenu .bi-printer'));
        self::assertStringNotContainsString('Imprimir', $menu->text());
        // FPN-006b: Editar e Excluir ficam sempre no menu (bloqueados quando não dá), então nada
        // nasce escondido.
        self::assertCount(1, $menu->filter('#financeiroObsMenu > .ps-fin-menu-sep'));
        self::assertCount(0, $menu->filter('#financeiroObsMenu > [hidden]'));

        // Sem lastro no Financeiro: não aparece.
        self::assertStringNotContainsString('Destacar', $menu->text());
    }

    #[TestDox('O registro da autora, dentro da janela, expõe os gatilhos do menu: texto, autor, hora e os botões de editar e excluir')]
    public function testRegistroDaAutoraExpoeOsGatilhos(): void
    {
        $client = static::createClient();
        [$user, $tenant, $pasta] = $this->cenario();
        $obs = $this->criarRegistro($pasta, $user, $tenant, 'Honorário combinado em 30%.');
        $this->logarComTenant($client, $user, $tenant);

        $crawler = $client->request('GET', "/pasta/{$pasta->getId()}");

        $cartao = $crawler->filter('#financeiroObsLista > article.ps-anotacao#financeiro-obs-' . $obs->getId());
        self::assertCount(1, $cartao);
        self::assertSame('Honorário combinado em 30%.', trim($cartao->filter('.obs-conteudo .ql-editor')->text()), '"Copiar texto" lê daqui');
        self::assertSame('Autora do Registro', trim($cartao->filter('.ps-anotacao-topo > .ps-anotacao-autor')->text()));
        self::assertMatchesRegularExpression('#^\d{2}/\d{2}/\d{4} \d{2}:\d{2}$#', (string) $cartao->filter('.ps-anotacao-topo > .ps-anotacao-data')->attr('title'));
        self::assertCount(1, $cartao->filter('.ps-anotacao-acoes > .btn-editar-obs'));
        self::assertCount(1, $cartao->filter('.ps-anotacao-acoes > .btn-excluir-obs'));
        self::assertMatchesRegularExpression('/\d+ min/', $cartao->filter('.ps-anotacao-acoes > .ps-anotacao-janela')->text());
    }

    #[TestDox('Registro de OUTRO autor não traz os botões: o menu fica só com Copiar texto')]
    public function testRegistroDeOutroAutorSemGatilhos(): void
    {
        $client = static::createClient();
        [$user, $tenant, $pasta] = $this->cenario();
        $outro = $this->criarUsuario($tenant, 'Outro Autor');
        $obs   = $this->criarRegistro($pasta, $outro, $tenant, 'Registro de outra pessoa.');
        $this->logarComTenant($client, $user, $tenant);

        $crawler = $client->request('GET', "/pasta/{$pasta->getId()}");

        $cartao = $crawler->filter('#financeiroObsLista > #financeiro-obs-' . $obs->getId());
        self::assertCount(1, $cartao);
        self::assertCount(0, $cartao->filter('.btn-editar-obs, .btn-excluir-obs'));
        self::assertCount(1, $cartao->filter('.obs-conteudo .ql-editor'), 'copiar continua possível');
        self::assertSame('0', $cartao->attr('data-obs-do-autor'), 'motivo do bloqueio: não é o autor');
    }

    #[TestDox('Registro da própria autora FORA da janela de 15 min também não traz os botões')]
    public function testRegistroForaDaJanelaSemGatilhos(): void
    {
        $client = static::createClient();
        [$user, $tenant, $pasta] = $this->cenario();
        $obs = $this->criarRegistro($pasta, $user, $tenant, 'Registro antigo.', new \DateTimeImmutable('-20 minutes'));
        $this->logarComTenant($client, $user, $tenant);

        $crawler = $client->request('GET', "/pasta/{$pasta->getId()}");

        $cartao = $crawler->filter('#financeiroObsLista > #financeiro-obs-' . $obs->getId());
        self::assertCount(1, $cartao);
        self::assertCount(0, $cartao->filter('.btn-editar-obs, .btn-excluir-obs'));
        self::assertSame('1', $cartao->attr('data-obs-do-autor'), 'motivo do bloqueio: o prazo passou');
    }

    #[TestDox('FPN-005: a resposta fica recuada sob a raiz, com "Resposta a NOME"; a contagem é só das raízes')]
    public function testRespostaRecuadaSobARaiz(): void
    {
        $client = static::createClient();
        [$user, $tenant, $pasta] = $this->cenario();
        $outro    = $this->criarUsuario($tenant, 'Bruno Lima');
        $raiz     = $this->criarRegistro($pasta, $outro, $tenant, 'Honorário em 20%.', new \DateTimeImmutable('-30 minutes'));
        $resposta = $this->criarRegistro($pasta, $user, $tenant, 'Combinado.');
        $resposta->setRespostaA($raiz);
        $this->em()->flush();
        $this->logarComTenant($client, $user, $tenant);

        $crawler = $client->request('GET', "/pasta/{$pasta->getId()}");

        // Filho DIRETO da lista: a raiz; a resposta, dentro da conversa logo depois dela.
        self::assertCount(1, $crawler->filter('#financeiroObsLista > article.ps-anotacao#financeiro-obs-' . $raiz->getId()));
        self::assertCount(0, $crawler->filter('#financeiroObsLista > #financeiro-obs-' . $resposta->getId()));
        $cartao = $crawler->filter('#financeiroObsLista > .ps-respostas[data-resposta-de="' . $raiz->getId() . '"] > article.ps-anotacao.ps-anotacao--resposta#financeiro-obs-' . $resposta->getId());
        self::assertCount(1, $cartao);
        self::assertSame('Resposta a Bruno Lima', trim($cartao->filter('.ps-anotacao-resposta-rot > span')->text()));
        self::assertSame('1', trim($crawler->filter('#financeiro-obs-count')->text()), 'respostas não contam');

        // Responder: a raiz responde a si; a resposta responde à RAIZ, com o autor dela para o "@".
        $naRaiz = $crawler->filter('#financeiro-obs-' . $raiz->getId() . ' > .ps-anotacao-topo > .btn-responder-obs');
        self::assertCount(1, $naRaiz);
        self::assertSame((string) $raiz->getId(), $naRaiz->attr('data-resposta-a'));
        self::assertSame('Bruno Lima', $naRaiz->attr('data-resposta-nome'));
        self::assertNull($naRaiz->attr('data-resposta-autor-id'));
        $naResposta = $cartao->filter('.ps-anotacao-topo > .btn-responder-obs');
        self::assertSame((string) $raiz->getId(), $naResposta->attr('data-resposta-a'));
        self::assertSame((string) $user->getId(), $naResposta->attr('data-resposta-autor-id'));
        self::assertSame('Autora do Registro', $naResposta->attr('data-resposta-autor-nome'));
    }

    #[TestDox('FPN-005: a resposta cuja original foi excluída fica solta, com "Resposta a uma mensagem excluída"')]
    public function testRespostaOrfa(): void
    {
        $client = static::createClient();
        [$user, $tenant, $pasta] = $this->cenario();
        $raiz     = $this->criarRegistro($pasta, $user, $tenant, 'Original.');
        $resposta = $this->criarRegistro($pasta, $user, $tenant, 'Resposta.');
        $resposta->setRespostaA($raiz);
        $this->em()->flush();
        $this->em()->remove($raiz);
        $this->em()->flush();
        $this->em()->clear();
        $this->logarComTenant($client, $user, $tenant);

        $crawler = $client->request('GET', "/pasta/{$pasta->getId()}");

        $cartao = $crawler->filter('#financeiroObsLista > article.ps-anotacao--resposta#financeiro-obs-' . $resposta->getId());
        self::assertCount(1, $cartao);
        self::assertSame('Resposta a uma mensagem excluída', trim($cartao->filter('.ps-anotacao-resposta-rot > span')->text()));
        // Sem a raiz, responder a uma órfã liga a ela mesma.
        self::assertSame((string) $resposta->getId(), $cartao->filter('.btn-responder-obs')->attr('data-resposta-a'));
    }

    #[TestDox('FPN-005: na lápide não há Responder — nem no menu, nem no cartão')]
    public function testLapideSemResponder(): void
    {
        $client = static::createClient();
        [$user, $tenant, $pasta] = $this->cenario();
        $obs = $this->criarRegistro($pasta, $user, $tenant, 'Registro.');
        $pasta->marcarExcluida($user, new \DateTimeImmutable());
        $this->em()->flush();
        $this->logarComTenant($client, $user, $tenant);

        $crawler = $client->request('GET', "/pasta/{$pasta->getId()}");
        self::assertResponseIsSuccessful();

        self::assertCount(1, $crawler->filter('#financeiro-obs-' . $obs->getId()));
        self::assertCount(0, $crawler->filter('#financeiroObsMenu .js-fin-obs-responder'));
        self::assertCount(0, $crawler->filter('#financeiroObsLista .btn-responder-obs'));
    }

    #[TestDox('EDT-010r: o compositor do Relatório financeiro liga a @menção e o registro mostra a menção em destaque')]
    public function testMencaoNaTela(): void
    {
        $client = static::createClient();
        [$user, $tenant, $pasta] = $this->cenario();
        $obs = $this->criarRegistro($pasta, $user, $tenant, '<p>Ver com @[Autora do Registro](user:' . $user->getId() . ')</p>');
        $this->logarComTenant($client, $user, $tenant);

        $crawler = $client->request('GET', "/pasta/{$pasta->getId()}");

        // C6-SEC-02b: a URL do compositor do financeiro leva a origem — a lista fica com quem vê o financeiro.
        $caixa = $crawler->filter('#formFinanceiroObservacao > .ps-compositor-caixa[data-mencionaveis-url="/pasta/' . $pasta->getId() . '/mencionaveis?origem=financeiro"] > textarea#financeiroObsConteudo');
        self::assertCount(1, $caixa);
        self::assertCount(1, $crawler->filter('#financeiro-obs-' . $obs->getId() . ' .obs-conteudo .ql-editor > p > span.ps-mencao[data-user-id="' . $user->getId() . '"]'));

        // O script manda o token: o compositor, a resposta e a edição passam pelo paraEnvio.
        $html   = (string) $client->getResponse()->getContent();
        $script = substr($html, (int) strpos($html, "const urlEnviarObs = '"), 40000);
        self::assertStringContainsString("fdEnvio.set('conteudo', comMencoes(obsConteudo, conteudo));", $script);
        self::assertStringContainsString("fd.append('conteudo', comMencoes(campo, paragrafosDe(texto)));", $script);
        self::assertStringContainsString("campo._mencoes = [{ id: autorId, nome: primeiro }];", $script);
        self::assertStringContainsString("fd.append('conteudo', comMencoes(textarea, textarea.value));", $script);
        self::assertStringContainsString('PastaMencoes.ligar(textarea, edicao.mencoes);', $script);
        // FPN-005: a caixa de resposta DESTE script manda o id da raiz.
        $caixaResp = substr($html, (int) strpos($html, 'function abrirCaixaRespostaFin(botao) {'), 6000);
        self::assertStringContainsString("fd.append('resposta_a', raizId);", $caixaResp);
    }
}
