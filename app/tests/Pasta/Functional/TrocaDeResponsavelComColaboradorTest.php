<?php

declare(strict_types=1);

namespace App\Tests\Pasta\Functional;

use App\Entity\Auth\User;
use App\Entity\Auth\UserTenant;
use App\Entity\Tenant\Tenant;
use App\Pasta\Entity\Pasta;
use App\Pasta\Entity\PastaCompartilhamento;
use App\Pasta\Service\ColaboradorNaTrocaDeResponsavel;
use App\Pasta\Twig\AcessoRestritoExtension;
use App\Pasta\UseCase\AlterarResponsavelDaPastaUseCase;
use App\Tenant\Enum\SituacaoNaEquipe;
use App\Tests\Functional\JusPrimeWebTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;

/**
 * Fatia 7 da spec acesso-restrito-e-compartilhar (regra 5, L8 do desenho 03): a troca de responsável desvincula o
 * anterior; com "Manter como colaborador", ele ganha o compartilhamento interno da pasta (1 clique: movimentar e
 * enviar documentos, sem baixar) e, se for restrito, continua vendo a pasta.
 */
#[CoversClass(ColaboradorNaTrocaDeResponsavel::class)]
#[CoversClass(AlterarResponsavelDaPastaUseCase::class)]
#[CoversClass(AcessoRestritoExtension::class)]
final class TrocaDeResponsavelComColaboradorTest extends JusPrimeWebTestCase
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
        $vinculo?->getTenantRole()?->restringirAsPastasSobResponsabilidade('Prospectador');

        $this->pasta = $this->criarPasta($this->tenant, 'CLIENTE DA RITA');
        $this->pasta->setResponsavel($this->restrito);
        $this->em()->flush();
    }

    #[TestDox('com "Manter como colaborador", o anterior ganha o compartilhamento interno de 1 clique e o restrito segue vendo a pasta')]
    public function testManterComoColaborador(): void
    {
        $resposta = $this->trocar($this->comum, true);

        self::assertTrue($resposta['colaboradorMantido']);
        $ativos = $this->compartilhamentosDe($this->restrito);
        self::assertCount(1, $ativos);
        $c = $ativos[0];
        self::assertSame($this->primario->getId(), $c->getOrigem()->getId());
        self::assertSame(ColaboradorNaTrocaDeResponsavel::MENSAGEM, $c->getMensagem());
        self::assertNull($c->getValidoAte());
        self::assertTrue($c->isInterno());
        self::assertTrue($c->permissoes()->movimentar);
        self::assertTrue($c->permissoes()->enviarDocumentos);
        self::assertFalse($c->permissoes()->baixarDocumentos);
        self::assertSame($this->comum->getId(), $this->em()->find(Pasta::class, $this->pasta->getId())?->getResponsavel()?->getId());

        $this->entrar($this->restrito, $this->tenant);
        $this->client->request('GET', '/pasta/' . $this->pasta->getId());
        self::assertResponseIsSuccessful();
    }

    #[TestDox('sem marcar, o anterior é desvinculado: nenhum compartilhamento e o restrito deixa de ver a pasta')]
    public function testSemManterDesvincula(): void
    {
        $resposta = $this->trocar($this->comum, false);

        self::assertFalse($resposta['colaboradorMantido']);
        self::assertSame([], $this->compartilhamentosDe($this->restrito));

        $this->entrar($this->restrito, $this->tenant);
        $this->client->request('GET', '/pasta/' . $this->pasta->getId());
        self::assertResponseStatusCodeSame(404);
    }

    #[TestDox('quem já colabora na pasta não ganha um segundo compartilhamento; ainda conta como mantido')]
    public function testJaColaboraNaoDuplica(): void
    {
        $this->trocar($this->comum, true);
        $this->em()->clear();
        $pasta = $this->em()->find(Pasta::class, $this->pasta->getId());
        $restrito = $this->em()->find(User::class, $this->restrito->getId());
        $pasta?->setResponsavel($restrito);
        $this->em()->flush();

        $resposta = $this->trocar($this->primario, true);

        self::assertTrue($resposta['colaboradorMantido']);
        self::assertCount(1, $this->compartilhamentosDe($this->restrito));
    }

    #[TestDox('quem passa a própria pasta adiante segue vendo a pasta: conta como mantido, sem compartilhamento')]
    public function testAutorEhOAnterior(): void
    {
        $this->pasta->setResponsavel($this->primario);
        $this->em()->flush();

        $resposta = $this->trocar($this->comum, true);

        self::assertTrue($resposta['colaboradorMantido']);
        self::assertSame([], $this->compartilhamentosDe($this->primario));
    }

    #[TestDox('anterior sem vínculo ativo no escritório: a troca vale, ninguém é mantido')]
    public function testAnteriorSemVinculoAtivo(): void
    {
        $vinculo = $this->em()->getRepository(UserTenant::class)->findOneBy(['user' => $this->restrito, 'tenant' => $this->tenant]);
        $vinculo?->alterarSituacao(SituacaoNaEquipe::Suspenso);
        $this->em()->flush();

        $resposta = $this->trocar($this->comum, true);

        self::assertTrue($resposta['mudou']);
        self::assertFalse($resposta['colaboradorMantido']);
        self::assertSame([], $this->compartilhamentosDe($this->restrito));
        self::assertSame($this->comum->getId(), $this->em()->find(Pasta::class, $this->pasta->getId())?->getResponsavel()?->getId());
    }

    #[TestDox('pasta sem responsável: troca direto, "manter" não cria nada')]
    public function testSemResponsavelAnterior(): void
    {
        $this->pasta->setResponsavel(null);
        $this->em()->flush();

        $resposta = $this->trocar($this->comum, true);

        self::assertTrue($resposta['mudou']);
        self::assertFalse($resposta['colaboradorMantido']);
        self::assertSame([], $this->em()->getRepository(PastaCompartilhamento::class)->findBy(['pasta' => $this->pasta->getId()]));
    }

    #[TestDox('o restrito pedindo "manter" continua recusado (403) e nada é compartilhado')]
    public function testRestritoNaoTrocaNemComManter(): void
    {
        $this->entrar($this->restrito, $this->tenant);
        $this->client->request('PATCH', '/pasta/' . $this->pasta->getId() . '/responsavel', [
            '_token' => 'TOKEN_pasta_responsavel_' . $this->pasta->getId(),
            'responsavel_id' => (string) $this->comum->getId(),
            'manter_colaborador' => '1',
        ]);

        self::assertResponseStatusCodeSame(403);
        self::assertSame([], $this->em()->getRepository(PastaCompartilhamento::class)->findBy(['pasta' => $this->pasta->getId()]));
    }

    #[TestDox('o chip leva o NUP da confirmação e, só no restrito, a marca que troca a L8 pelo aviso do desenho')]
    public function testChipDoRestrito(): void
    {
        $this->entrar($this->restrito, $this->tenant);
        $crawler = $this->client->request('GET', '/pasta/' . $this->pasta->getId());
        $chip = $crawler->filter('.pasta-resp-chip');
        self::assertSame('1', $chip->attr('data-restrito'));
        self::assertSame((string) $this->pasta->getNup(), $chip->attr('data-nup'));
        self::assertStringContainsString('resp-troca-fundo', (string) $this->client->getResponse()->getContent());

        $this->entrar($this->comum, $this->tenant);
        $crawler = $this->client->request('GET', '/pasta/' . $this->pasta->getId());
        self::assertNull($crawler->filter('.pasta-resp-chip')->attr('data-restrito'));
    }

    /** @return array<string, mixed> */
    private function trocar(User $novo, bool $manter): array
    {
        $this->entrar($this->primario, $this->tenant);
        $campos = ['_token' => 'TOKEN_pasta_responsavel_' . $this->pasta->getId(), 'responsavel_id' => (string) $novo->getId()];
        if ($manter) {
            $campos['manter_colaborador'] = '1';
        }
        $this->client->request('PATCH', '/pasta/' . $this->pasta->getId() . '/responsavel', $campos);
        self::assertResponseIsSuccessful();
        $this->em()->clear();

        return json_decode((string) $this->client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
    }

    /** @return list<PastaCompartilhamento> */
    private function compartilhamentosDe(User $destino): array
    {
        return $this->em()->getRepository(PastaCompartilhamento::class)->findBy([
            'pasta' => $this->pasta->getId(), 'destino' => $destino->getId(), 'encerradoEm' => null,
        ]);
    }
}
