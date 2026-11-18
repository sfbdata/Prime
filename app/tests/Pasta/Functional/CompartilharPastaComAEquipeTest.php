<?php

declare(strict_types=1);

namespace App\Tests\Pasta\Functional;

use App\Entity\Auth\User;
use App\Entity\Auth\UserTenant;
use App\Entity\Permission\AccessRequest;
use App\Entity\Tenant\Tenant;
use App\Pasta\Controller\PastaCompartilhamentoController;
use App\Pasta\Entity\Pasta;
use App\Pasta\Entity\PastaCompartilhamento;
use App\Pasta\Entity\PermissoesDoCompartilhamento;
use App\Pasta\EventListener\BarreiraDoCadeadoListener;
use App\Pasta\Exception\CompartilhamentoRecusadoException;
use App\Service\PermissionChecker;
use App\Service\Tenant\TenantContext;
use App\Tests\Functional\JusPrimeWebTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;

/**
 * Fatia 5 da spec `docs/specs/acesso-restrito-e-compartilhar.md` (§4.4): compartilhar a pasta com a equipe. Sem
 * aceite, a pasta entra no escopo do restrito na hora; as caixas do compartilhamento limitam o que ele faz nela;
 * encerrar ou vencer devolve o 404. Para quem não é restrito, vale como permissão de ver a pasta.
 */
#[CoversClass(PastaCompartilhamentoController::class)]
final class CompartilharPastaComAEquipeTest extends JusPrimeWebTestCase
{
    use CriaCenarioDoCadeadoFuncionalTrait;

    private const PERMISSOES = ['modules.expediente.view', 'modules.pastas.view', 'resources.pasta.view', 'resources.pasta.edit'];

    private Tenant $tenant;
    private User $primario;
    private User $restrito;
    private User $comum;
    private Pasta $pasta;

    protected function setUp(): void
    {
        parent::setUp();
        $this->prepararCliente();
        [$this->primario, $this->tenant] = $this->criarEscritorio();
        $this->restrito = $this->criarColaborador($this->tenant, 'Rita Restrita', self::PERMISSOES);
        $this->comum    = $this->criarColaborador($this->tenant, 'Carlos Comum', self::PERMISSOES);
        $vinculo = $this->em()->getRepository(UserTenant::class)->findOneBy(['user' => $this->restrito, 'tenant' => $this->tenant]);
        $vinculo?->getTenantRole()?->restringirAsPastasSobResponsabilidade('Parceiro');
        $this->pasta = $this->criarPasta($this->tenant, 'CLIENTE DO PRIMARIO');
        $this->pasta->setResponsavel($this->primario);
        $this->em()->flush();
    }

    #[TestDox('compartilhar com o restrito: a pasta abre na hora; encerrar devolve o 404')]
    public function testCompartilharEEncerrar(): void
    {
        $this->entrar($this->restrito, $this->tenant);
        $this->client->request('GET', $this->urlDaPasta());
        self::assertResponseStatusCodeSame(404);

        $this->entrar($this->primario, $this->tenant);
        $resposta = $this->compartilhar(['destinoId' => $this->restrito->getId(), 'baixarDocumentos' => true]);
        self::assertResponseIsSuccessful();
        self::assertSame('Pasta compartilhada com Rita Restrita. Ela já aparece para ele, inclusive no acesso restrito.', $resposta['mensagem']);

        $this->entrar($this->restrito, $this->tenant);
        $this->client->request('GET', $this->urlDaPasta());
        self::assertResponseIsSuccessful();

        $this->entrar($this->primario, $this->tenant);
        $janela = $this->janela();
        self::assertCount(1, $janela['colaboradores']);
        self::assertSame('colaborando', $janela['colaboradores'][0]['estado']);
        $this->postar($this->urlDaPasta() . '/compartilhamento/' . $janela['colaboradores'][0]['id'] . '/encerrar', [], $janela['token']);
        self::assertResponseIsSuccessful();

        $this->entrar($this->restrito, $this->tenant);
        $this->client->request('GET', $this->urlDaPasta());
        self::assertResponseStatusCodeSame(404);
    }

    #[TestDox('sem "Abrir e movimentar" o restrito só vê; "Baixar" e "Enviar documentos" valem por rota')]
    public function testCaixasDoCompartilhamento(): void
    {
        $this->gravarCompartilhamento($this->restrito, new PermissoesDoCompartilhamento(false, false, false));
        $this->entrar($this->restrito, $this->tenant);
        $checker = static::getContainer()->get(PermissionChecker::class);
        self::assertTrue($checker->canAccessResource($this->restrito, $this->tenant, AccessRequest::RESOURCE_PASTA, (int) $this->pasta->getId(), AccessRequest::ACTION_VIEW));
        self::assertFalse($checker->canAccessResource($this->restrito, $this->tenant, AccessRequest::RESOURCE_PASTA, (int) $this->pasta->getId(), AccessRequest::ACTION_EDIT));

        $this->xhr('POST', $this->urlDaPasta() . '/documentos/zip');
        self::assertResponseStatusCodeSame(403);
        self::assertSame(BarreiraDoCadeadoListener::MENSAGEM_COMPARTILHAMENTO, $this->erro());
        $this->xhr('POST', $this->urlDaPasta() . '/peticionar/texto');
        self::assertResponseStatusCodeSame(403);
        self::assertSame(BarreiraDoCadeadoListener::MENSAGEM_COMPARTILHAMENTO, $this->erro());
        $this->xhr('POST', $this->urlDaPasta() . '/prioridade');
        self::assertResponseStatusCodeSame(403);
        self::assertSame(BarreiraDoCadeadoListener::MENSAGEM_COMPARTILHAMENTO, $this->erro());
    }

    #[TestDox('com "Abrir e movimentar" o restrito edita a pasta compartilhada; com "Baixar", o ZIP passa da barreira')]
    public function testCaixasMarcadas(): void
    {
        $this->gravarCompartilhamento($this->restrito, new PermissoesDoCompartilhamento(true, true, true));
        $this->entrar($this->restrito, $this->tenant);
        $checker = static::getContainer()->get(PermissionChecker::class);
        self::assertTrue($checker->canAccessResource($this->restrito, $this->tenant, AccessRequest::RESOURCE_PASTA, (int) $this->pasta->getId(), AccessRequest::ACTION_EDIT));

        $this->xhr('POST', $this->urlDaPasta() . '/documentos/zip');
        self::assertNotSame(BarreiraDoCadeadoListener::MENSAGEM_COMPARTILHAMENTO, $this->erro());
    }

    #[TestDox('validade vencida: a pasta volta ao 404 na hora, e compartilhar de novo encerra o vencido e vale')]
    public function testValidadeVencida(): void
    {
        $this->gravarCompartilhamento($this->restrito, new PermissoesDoCompartilhamento(), new \DateTimeImmutable('yesterday'));
        $this->entrar($this->restrito, $this->tenant);
        $this->client->request('GET', $this->urlDaPasta());
        self::assertResponseStatusCodeSame(404);

        $this->entrar($this->primario, $this->tenant);
        $estados = array_column($this->janela()['colaboradores'], 'estado');
        self::assertSame(['expirada'], $estados);
        $this->compartilhar(['destinoId' => $this->restrito->getId(), 'validoAte' => (new \DateTimeImmutable('+5 days'))->format('Y-m-d')]);
        self::assertResponseIsSuccessful();
        self::assertSame(['colaborando', 'encerrada'], array_column($this->janela()['colaboradores'], 'estado'));

        $this->entrar($this->restrito, $this->tenant);
        $this->client->request('GET', $this->urlDaPasta());
        self::assertResponseIsSuccessful();
    }

    #[TestDox('recusas da janela: sem usuário, já compartilhada, o responsável e o restrito que tenta compartilhar')]
    public function testRecusas(): void
    {
        $minha = $this->criarPasta($this->tenant, 'CLIENTE DA RITA');
        $minha->setResponsavel($this->restrito);
        $this->em()->flush();
        $this->entrar($this->primario, $this->tenant);
        self::assertSame(CompartilhamentoRecusadoException::ESCOLHA_O_USUARIO, $this->compartilhar([])['erro']);
        self::assertResponseStatusCodeSame(422);
        $this->entrar($this->comum, $this->tenant);
        self::assertSame(CompartilhamentoRecusadoException::DESTINO_RESPONSAVEL, $this->compartilhar(['destinoId' => $this->primario->getId()])['erro'] ?? null);
        $this->entrar($this->primario, $this->tenant);

        $this->compartilhar(['destinoId' => $this->comum->getId()]);
        self::assertResponseIsSuccessful();
        self::assertSame(CompartilhamentoRecusadoException::JA_COMPARTILHADA, $this->compartilhar(['destinoId' => $this->comum->getId()])['erro']);
        self::assertResponseStatusCodeSame(422);

        $this->entrar($this->restrito, $this->tenant);
        $janela = $this->janela($minha);
        self::assertFalse($janela['podeCompartilhar']);
        self::assertSame([], $janela['equipe']);
        $this->postar('/pasta/' . $minha->getId() . '/compartilhamento', ['destinoId' => $this->comum->getId()], $janela['token']);
        self::assertResponseStatusCodeSame(403);
    }

    #[TestDox('a janela lista a equipe sem o responsável e sem quem pede; o destino deixa de colaborar')]
    public function testJanelaEDeixarDeColaborar(): void
    {
        $this->entrar($this->comum, $this->tenant);
        $nomes = array_column($this->janela()['equipe'], 'nome');
        self::assertContains('Rita Restrita', $nomes);
        self::assertNotContains('Carlos Comum', $nomes);
        self::assertNotContains((string) $this->primario->getFullName(), $nomes);

        $id = $this->gravarCompartilhamento($this->restrito, new PermissoesDoCompartilhamento());
        $this->entrar($this->restrito, $this->tenant);
        $janela = $this->janela();
        self::assertTrue($janela['colaboradores'][0]['podeEncerrar']);
        $this->postar($this->urlDaPasta() . '/compartilhamento/' . $id . '/encerrar', [], $janela['token']);
        self::assertResponseIsSuccessful();
        $this->client->request('GET', $this->urlDaPasta());
        self::assertResponseStatusCodeSame(404);
    }

    #[TestDox('para quem não é restrito, o compartilhamento vale como permissão de ver a pasta')]
    public function testComumSemPermissaoGanhaVer(): void
    {
        $semVer = $this->criarColaborador($this->tenant, 'Sergio Sem Ver', ['modules.expediente.view']);
        $vinculo = $this->em()->getRepository(UserTenant::class)->findOneBy(['user' => $semVer, 'tenant' => $this->tenant]);
        self::assertNotNull($vinculo);
        static::getContainer()->get(TenantContext::class)->fixarSemSessao($this->tenant, $vinculo);
        $checker = static::getContainer()->get(PermissionChecker::class);
        $pastaId = (int) $this->pasta->getId();
        self::assertFalse($checker->canAccessResource($semVer, $this->tenant, AccessRequest::RESOURCE_PASTA, $pastaId, AccessRequest::ACTION_VIEW));

        $this->gravarCompartilhamento($semVer, new PermissoesDoCompartilhamento());
        $checker->reset();
        self::assertTrue($checker->canAccessResource($semVer, $this->tenant, AccessRequest::RESOURCE_PASTA, $pastaId, AccessRequest::ACTION_VIEW));
        self::assertSame([$pastaId], $checker->idsPermitidosComVinculo($semVer, $vinculo, AccessRequest::RESOURCE_PASTA, [$pastaId], AccessRequest::ACTION_VIEW));
        self::assertFalse($checker->canAccessResource($semVer, $this->tenant, AccessRequest::RESOURCE_PASTA, $pastaId, AccessRequest::ACTION_EDIT));
    }

    private function gravarCompartilhamento(User $destino, PermissoesDoCompartilhamento $permissoes, ?\DateTimeImmutable $validoAte = null): int
    {
        $c = PastaCompartilhamento::interno($this->pasta, $this->primario, $destino, $permissoes, null, null, new \DateTimeImmutable('-2 days'));
        if ($validoAte !== null) {
            (new \ReflectionProperty(PastaCompartilhamento::class, 'validoAte'))->setValue($c, $validoAte);
        }
        $this->em()->persist($c);
        $this->em()->flush();

        return (int) $c->getId();
    }

    private function urlDaPasta(?Pasta $pasta = null): string
    {
        return '/pasta/' . ($pasta ?? $this->pasta)->getId();
    }

    /** @return array<string, mixed> */
    private function janela(?Pasta $pasta = null): array
    {
        $this->client->request('GET', $this->urlDaPasta($pasta) . '/compartilhamento');
        self::assertResponseIsSuccessful();

        return json_decode($this->conteudo(), true, flags: JSON_THROW_ON_ERROR);
    }

    /**
     * @param array<string, mixed> $dados
     *
     * @return array<string, mixed>
     */
    private function compartilhar(array $dados): array
    {
        $token = $this->janela()['token'];
        $this->postar($this->urlDaPasta() . '/compartilhamento', $dados, $token);

        return json_decode($this->conteudo(), true, flags: JSON_THROW_ON_ERROR);
    }

    /** @param array<string, mixed> $dados */
    private function postar(string $url, array $dados, string $token): void
    {
        $this->client->request('POST', $url, server: [
            'CONTENT_TYPE' => 'application/json', 'HTTP_X_CSRF_TOKEN' => $token, 'HTTP_X_REQUESTED_WITH' => 'XMLHttpRequest',
        ], content: json_encode($dados, JSON_THROW_ON_ERROR));
    }

    private function xhr(string $metodo, string $url): void
    {
        $this->client->request($metodo, $url, server: ['HTTP_X_REQUESTED_WITH' => 'XMLHttpRequest', 'HTTP_ACCEPT' => 'application/json']);
    }

    private function erro(): ?string
    {
        $json = json_decode($this->conteudo(), true);

        return is_array($json) && is_string($json['erro'] ?? null) ? $json['erro'] : null;
    }

    private function conteudo(): string
    {
        return (string) $this->client->getResponse()->getContent();
    }
}
