<?php

declare(strict_types=1);

namespace App\Tests\Produto\Functional;

use App\Inteligencia\Entity\ConfiguracaoDeInteligencia;
use App\Inteligencia\Enum\ModoDeCobrancaDaIa;
use App\Produto\Controller\FerramentasDoEscritorioController;
use App\Produto\Entity\Contratacao;
use App\Produto\Enum\OrigemDaContratacao;
use App\Produto\Enum\Produto;
use App\Produto\Service\ContratacoesDoEscritorio;
use App\Produto\UseCase\DesligarFerramentaUseCase;
use App\Produto\UseCase\LigarFerramentaUseCase;
use App\Tests\Functional\JusPrimeWebTestCase;
use App\Voto\Entity\LiberacaoDoVoto;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Zenstruck\Foundry\Test\Factories;

/**
 * `POST /perfil/ferramentas/{produto}` (spec docs/specs/multiproduto-contratacao-e-voto.md, §9 e §12 "PR 8"): o dono liga
 * e desliga as ferramentas do escritório da sessão. JSON com CSRF no header.
 */
#[CoversClass(FerramentasDoEscritorioController::class)]
#[CoversClass(LigarFerramentaUseCase::class)]
#[CoversClass(DesligarFerramentaUseCase::class)]
#[CoversClass(ContratacoesDoEscritorio::class)]
final class FerramentasDoEscritorioControllerTest extends JusPrimeWebTestCase
{
    use Factories;
    use ComEscritoriosPorProduto;
    use ComFerramentasNoPerfil;

    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->client->disableReboot();
        $this->instalarCsrfFixo();
    }

    #[TestDox('o dono desliga o Kanban num escritório sem linhas (materializa as outras sete) e liga de novo')]
    public function testDonoDesligaELiga(): void
    {
        $tenant = $this->escritorio();
        $this->logarComTenant($this->client, $this->dono($tenant), $tenant);

        $this->postarFerramenta('kanban', ['ligar' => false]);

        self::assertResponseIsSuccessful();
        self::assertSame(['ok' => true, 'ligada' => false], $this->respostaJson());
        self::assertCount(7, $this->ativosDo($tenant));
        self::assertNotContains('kanban', $this->ativosDo($tenant));

        $this->postarFerramenta('kanban', ['ligar' => true]);

        self::assertResponseIsSuccessful();
        self::assertSame(['ok' => true, 'ligada' => true], $this->respostaJson());
        self::assertCount(8, $this->ativosDo($tenant), 'as oito ferramentas ligadas de novo');
        self::assertCount(8, $this->linhasDo($tenant), 'as sete materializadas e a nova do Kanban, sem duplicar nenhuma');
    }

    #[TestDox('quem não é o dono (mesmo o primário) leva 403 e nada é gravado')]
    public function testNaoDono403(): void
    {
        $tenant = $this->escritorio();
        $this->dono($tenant);
        $this->logarComTenant($this->client, $this->primario($tenant), $tenant);

        $this->postarFerramenta('kanban', ['ligar' => false]);

        self::assertResponseStatusCodeSame(403);
        self::assertArrayHasKey('erro', $this->respostaJson());
        self::assertSame([], $this->linhasDo($tenant));
    }

    #[TestDox('CSRF inválido: 403 e nada é gravado')]
    public function testCsrfInvalido(): void
    {
        $tenant = $this->escritorio();
        $this->logarComTenant($this->client, $this->dono($tenant), $tenant);

        $this->postarFerramenta('kanban', ['ligar' => false], 'token-errado');

        self::assertResponseStatusCodeSame(403);
        self::assertSame([], $this->linhasDo($tenant));
    }

    #[TestDox('`completo` e valor fora da lista: 404; corpo sem `ligar` booleano: 422')]
    public function testProdutoECorpoInvalidos(): void
    {
        $tenant = $this->escritorio();
        $this->logarComTenant($this->client, $this->dono($tenant), $tenant);

        $this->postarFerramenta('completo', ['ligar' => false]);
        self::assertResponseStatusCodeSame(404);
        $this->postarFerramenta('inexistente', ['ligar' => false]);
        self::assertResponseStatusCodeSame(404);
        $this->postarFerramenta('kanban', ['ligar' => 'sim']);
        self::assertResponseStatusCodeSame(422);
        $this->postarFerramenta('kanban', 'não é json');
        self::assertResponseStatusCodeSame(422);
        self::assertSame([], $this->linhasDo($tenant));
    }

    #[TestDox('sem escritório na sessão: 404')]
    public function testSemEscritorio(): void
    {
        $tenant = $this->escritorio();
        $this->client->loginUser($this->dono($tenant));
        $this->marcarTermosAceitos($this->client);

        $this->postarFerramenta('kanban', ['ligar' => false]);

        self::assertResponseStatusCodeSame(404);
        self::assertSame([], $this->linhasDo($tenant));
    }

    #[TestDox('travada pelo Master (D16): o dono leva 422 com a mensagem do suporte e o produto segue desligado')]
    public function testTravadaPeloMaster(): void
    {
        $tenant = $this->escritorio(Produto::Expediente);
        $marcador = Contratacao::ativar($tenant, Produto::Voto, OrigemDaContratacao::Master, null, new \DateTimeImmutable('-1 hour'));
        $marcador->encerrar(OrigemDaContratacao::Master, null, new \DateTimeImmutable('-1 hour'));
        $this->em()->persist($marcador);
        $this->em()->flush();
        $this->logarComTenant($this->client, $this->dono($tenant), $tenant);

        $this->postarFerramenta('voto', ['ligar' => true]);

        self::assertResponseStatusCodeSame(422);
        self::assertSame('Esta ferramenta foi desligada pelo BlueJus. Fale com o suporte.', $this->respostaJson()['erro']);
        self::assertSame(['expediente'], $this->ativosDo($tenant));
    }

    #[TestDox('ligar o Voto num escritório sem linhas: não grava linha (já é completo) e cria a liberação liberada')]
    public function testLigaOVotoSemLinhas(): void
    {
        $tenant = $this->escritorio();
        $this->logarComTenant($this->client, $this->dono($tenant), $tenant);

        $this->postarFerramenta('voto', ['ligar' => true]);

        self::assertResponseIsSuccessful();
        self::assertSame([], $this->linhasDo($tenant));
        self::assertTrue($this->em()->getRepository(LiberacaoDoVoto::class)->findOneBy(['tenant' => $tenant->getId()])?->isLiberado());
    }

    #[TestDox('ligar a I.A.: contratação, liberada e `Incluso` com a reserva de 500, sem aceite; a auditoria fica no escritório')]
    public function testLigaAIa(): void
    {
        $tenant = $this->escritorio(Produto::Expediente);
        $this->logarComTenant($this->client, $this->dono($tenant), $tenant);

        $this->postarFerramenta('ia', ['ligar' => true]);

        self::assertResponseIsSuccessful();
        self::assertSame(['expediente', 'ia'], $this->ativosDo($tenant));
        $configuracao = $this->em()->getRepository(ConfiguracaoDeInteligencia::class)->findOneBy(['tenant' => $tenant->getId()]);
        self::assertTrue($configuracao?->isLiberadaPeloMaster());
        self::assertFalse($configuracao?->isHabilitada());
        self::assertSame(ModoDeCobrancaDaIa::Incluso, $configuracao?->getModoDeCobranca());
        self::assertSame(LigarFerramentaUseCase::TETO_PADRAO_DE_RESERVA, $configuracao?->getOrcamentoMensalCentavos());
        $auditoria = (int) $this->em()->getConnection()->fetchOne(
            'SELECT COUNT(*) FROM audit_log WHERE entity_class = ? AND tenant_id = ?',
            [Contratacao::class, $tenant->getId()],
        );
        self::assertGreaterThan(0, $auditoria, 'a linha nova da contratação entra na trilha do escritório');
    }
}
