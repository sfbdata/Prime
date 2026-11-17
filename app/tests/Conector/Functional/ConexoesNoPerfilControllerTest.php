<?php

declare(strict_types=1);

namespace App\Tests\Conector\Functional;

use App\Conector\Controller\ConexoesNoPerfilController;
use App\Conector\Entity\ConsentimentoDoConector;
use App\Conector\UseCase\ListarConexoesDaPessoaUseCase;
use App\Conector\UseCase\RevogarConexaoUseCase;
use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Profile\Controller\ProfileController;
use App\Tests\Conector\Support\CenarioDoServidorDeAutorizacaoTrait;
use App\Tests\Functional\JusPrimeWebTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;

/**
 * "Conexões de I.A." no Meu Perfil pelo HTTP (`docs/specs/mcp-remoto-oauth.md`, 13.1 linha do PR 8, e 14.e): o cartão
 * lista as conexões da pessoa com o "Revogar" e o modal de confirmação; o POST do modal revoga só a conexão da pessoa
 * logada, com o token do formulário.
 */
#[CoversClass(ConexoesNoPerfilController::class)]
#[CoversClass(ProfileController::class)]
#[CoversClass(ListarConexoesDaPessoaUseCase::class)]
#[CoversClass(RevogarConexaoUseCase::class)]
final class ConexoesNoPerfilControllerTest extends JusPrimeWebTestCase
{
    use CenarioDoServidorDeAutorizacaoTrait;

    private const CARTAO = 'section.mp-card[data-screen-label="Conexões de I.A."]';

    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = static::createClient();
    }

    /** @return array{pessoa: User, escritorio: Tenant, consentimento: ConsentimentoDoConector} */
    private function conexaoLogada(): array
    {
        $conexao = $this->conexaoAutorizada();
        $this->logarComTenant($this->client, $conexao['pessoa'], $conexao['escritorio']);

        return $conexao;
    }

    private function url(int $id): string
    {
        return '/perfil/conexoes-de-ia/' . $id . '/revogar';
    }

    /**
     * Token CSRF da intenção, na sessão do navegador (molde: `CobrancaWebTestCase::tokenCsrf`). Precisa de uma
     * requisição antes.
     */
    private function tokenCsrf(string $intencao): string
    {
        $requestStack = static::getContainer()->get(RequestStack::class);
        $request = $this->client->getRequest();
        $requestStack->push($request);
        try {
            $token = (string) static::getContainer()->get(CsrfTokenManagerInterface::class)->getToken($intencao);
            $request->getSession()->save();

            return $token;
        } finally {
            $requestStack->pop();
        }
    }

    private function revogadoEm(ConsentimentoDoConector $consentimento): mixed
    {
        return $this->em()->getConnection()->fetchOne('SELECT revogado_em FROM conector_ia_consentimento WHERE id = ?', [$consentimento->getId()]);
    }

    private function concessoesAbertas(ConsentimentoDoConector $consentimento): int
    {
        return (int) $this->em()->getConnection()->fetchOne('SELECT COUNT(*) FROM conector_ia_concessao WHERE consentimento_id = ? AND revogada_em IS NULL', [$consentimento->getId()]);
    }

    #[TestDox('O Meu Perfil mostra o cartão com a conexão (host do app, escritório, permissões) e o Revogar abre o modal com Cancelar, Revogar e o token')]
    public function testPerfilMostraOCartao(): void
    {
        $conexao = $this->conexaoLogada();
        $id = (int) $conexao['consentimento']->getId();

        $tela = $this->client->request('GET', '/perfil');

        self::assertResponseIsSuccessful();
        $cartao = $tela->filter(self::CARTAO);
        self::assertCount(1, $cartao);
        self::assertSame('Conexões de I.A.', trim($cartao->filter('.mp-card-topo b')->text()));
        $linha = $cartao->filter('.mp-seguranca[data-conexao-de-ia="' . $id . '"]');
        self::assertStringContainsString('App: claude.ai', $linha->text());
        self::assertStringContainsString((string) $conexao['escritorio']->getName(), $linha->text());
        self::assertStringContainsString('Permissões: Ler, Manter conectado', $linha->text());
        self::assertStringContainsString('Último uso: ainda não usado', $linha->text());
        self::assertCount(0, $linha->filter('[data-vinculo-encerrado]'), 'vínculo aprovado: sem aviso');
        self::assertSame('Revogar', trim($linha->filter('button.mp-btn-contorno[data-bs-target="#modalRevogarConexao' . $id . '"]')->text()));

        $modal = $tela->filter('#modalRevogarConexao' . $id . '.mp-modal');
        self::assertSame('Revogar a conexão', trim($modal->filter('.mp-modal-topo > b')->text()));
        self::assertStringContainsString('O app claude.ai perde o acesso ao escritório ' . $conexao['escritorio']->getName(), $modal->text());
        $form = $modal->filter('form[data-form-revogar-conexao="' . $id . '"]');
        self::assertSame($this->url($id), $form->attr('action'));
        self::assertSame(['Cancelar', 'Revogar'], $form->filter('.mp-modal-rodape > button')->each(static fn ($b): string => trim($b->text())));
        self::assertCount(1, $form->filter('input[name="_token"]'));
    }

    #[TestDox('Sem conexão: o cartão aparece com o aviso de vazio e sem modal')]
    public function testPerfilSemConexao(): void
    {
        $escritorio = $this->escritorio();
        $pessoa = $this->pessoa();
        $this->vinculo($pessoa, $escritorio);
        $this->logarComTenant($this->client, $pessoa, $escritorio);

        $tela = $this->client->request('GET', '/perfil');

        self::assertResponseIsSuccessful();
        self::assertSame('Nenhum app de I.A. conectado à sua conta.', trim($tela->filter(self::CARTAO . ' .mp-seguranca')->text()));
        self::assertCount(0, $tela->filter('[id^="modalRevogarConexao"]'));
    }

    #[TestDox('Caminho feliz: o modal envia, a conexão e as concessões caem, volta ao perfil com o aviso e a conexão sai da lista')]
    public function testRevogaPeloModal(): void
    {
        $conexao = $this->conexaoLogada();
        $id = (int) $conexao['consentimento']->getId();
        $tela = $this->client->request('GET', '/perfil');

        $this->client->submit($tela->filter('form[data-form-revogar-conexao="' . $id . '"]')->form());

        self::assertResponseRedirects('/perfil');
        $depois = $this->client->followRedirect();
        self::assertStringContainsString('A conexão com claude.ai foi revogada.', $depois->filter('body')->text());
        self::assertCount(0, $depois->filter('[data-conexao-de-ia="' . $id . '"]'));
        self::assertNotNull($this->revogadoEm($conexao['consentimento']));
        self::assertSame(0, $this->concessoesAbertas($conexao['consentimento']));
    }

    #[TestDox('Com a sessão em outro escritório da pessoa: a conexão aparece com o escritório dela e revogar derruba as concessões (o filtro do escritório da sessão não as esconde)')]
    public function testRevogaConexaoDeOutroEscritorio(): void
    {
        $conexao = $this->conexaoAutorizada();
        $id = (int) $conexao['consentimento']->getId();
        $daSessao = $this->escritorio('Sessão');
        $this->vinculo($conexao['pessoa'], $daSessao);
        $this->logarComTenant($this->client, $conexao['pessoa'], $daSessao);
        $tela = $this->client->request('GET', '/perfil');
        self::assertStringContainsString((string) $conexao['escritorio']->getName(), $tela->filter('[data-conexao-de-ia="' . $id . '"]')->text());

        $this->client->submit($tela->filter('form[data-form-revogar-conexao="' . $id . '"]')->form());

        self::assertResponseRedirects('/perfil');
        self::assertNotNull($this->revogadoEm($conexao['consentimento']));
        self::assertSame(0, $this->concessoesAbertas($conexao['consentimento']));
    }

    #[TestDox('Token CSRF inválido: recusa com aviso e nada muda')]
    public function testCsrfInvalido(): void
    {
        $conexao = $this->conexaoLogada();
        $id = (int) $conexao['consentimento']->getId();

        $this->client->request('POST', $this->url($id), ['_token' => 'errado']);

        self::assertResponseRedirects('/perfil');
        self::assertStringContainsString('Token CSRF inválido. Recarregue a página.', $this->client->followRedirect()->filter('body')->text());
        self::assertNull($this->revogadoEm($conexao['consentimento']));
        self::assertSame(1, $this->concessoesAbertas($conexao['consentimento']));
    }

    #[TestDox('Conexão de outra pessoa, mesmo com o token válido para o id: não encontrada, e nada muda')]
    public function testIdAlheioNaoRevoga(): void
    {
        $alheia = $this->conexaoAutorizada();
        $id = (int) $alheia['consentimento']->getId();
        $pessoa = $this->pessoa();
        $this->vinculo($pessoa, $alheia['escritorio']);
        $this->logarComTenant($this->client, $pessoa, $alheia['escritorio']);
        $this->client->request('GET', '/perfil');

        $this->client->request('POST', $this->url($id), ['_token' => $this->tokenCsrf('conector_revogar_' . $id)]);

        self::assertResponseRedirects('/perfil');
        self::assertStringContainsString('Conexão não encontrada.', $this->client->followRedirect()->filter('body')->text());
        self::assertNull($this->revogadoEm($alheia['consentimento']));
        self::assertSame(1, $this->concessoesAbertas($alheia['consentimento']));
    }

    #[TestDox('Sem estar logado, vai para o login; GET não existe')]
    public function testSemLogin(): void
    {
        $this->client->request('POST', $this->url(1));
        self::assertResponseRedirects();
        self::assertStringContainsString('login', (string) $this->client->getResponse()->headers->get('Location'));

        $conexao = $this->conexaoLogada();
        $this->client->request('GET', $this->url((int) $conexao['consentimento']->getId()));
        self::assertResponseStatusCodeSame(405);
    }
}
