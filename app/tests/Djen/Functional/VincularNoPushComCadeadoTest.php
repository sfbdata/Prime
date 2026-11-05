<?php

declare(strict_types=1);

namespace App\Tests\Djen\Functional;

use App\Djen\Controller\PublicacaoPastaController;
use App\Djen\UseCase\VincularPublicacaoAPastaUseCase;
use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Pasta\Entity\NivelDeAcesso;
use App\Pasta\Entity\Pasta;
use App\Processo\Entity\ParteProcesso;
use App\Processo\Entity\Processo;
use App\Tests\Functional\JusPrimeWebTestCase;
use App\Tests\Pasta\Functional\CriaCenarioDoCadeadoFuncionalTrait;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\TestDox;

/**
 * "Vincular a uma pasta" e "Trocar a pasta vinculada" do Push com o cadeado (revisão do achado 3.04 da auditoria).
 * O processo cujas pastas são todas ocultas para a pessoa não existe para ela (K3): o vínculo é recusado como
 * "Processo não encontrado.", com o mesmo redirect de qualquer recusa do Push (nunca 403), e sem dado dele. A troca
 * confere a recusa antes de mexer no vínculo anterior e roda numa transação: nunca fica desvinculada pela metade.
 */
#[CoversClass(PublicacaoPastaController::class)]
#[CoversClass(VincularPublicacaoAPastaUseCase::class)]
#[Group('djen')]
final class VincularNoPushComCadeadoTest extends JusPrimeWebTestCase
{
    use CriaCenarioDoCadeadoFuncionalTrait;
    use CriaFixturesDjenTrait;

    private const NUMERO = '07055555520268070001';
    private const INEXISTENTE = '07066666620268070001';
    private const PERMISSOES = ['modules.djen.view', 'modules.processos.view', 'resources.pasta.view', 'resources.pasta.edit'];

    private User $primario;
    private User $ana;
    private Tenant $tenant;
    private Processo $processo;

    protected function setUp(): void
    {
        parent::setUp();
        $this->prepararCliente();
        [$this->primario, $this->tenant] = $this->criarEscritorio();
        $this->ana = $this->criarColaborador($this->tenant, 'Ana Equipe', self::PERMISSOES);
        $this->processo = new Processo();
        $this->processo->setTenant($this->tenant);
        $this->processo->setNumeroProcesso(self::NUMERO);
        $this->processo->setClasseProcessual('Procedimento Comum');
        $this->processo->setOrgaoJulgador('1ª Vara Cível');
        $parte = (new ParteProcesso())->setTipo('Autor')->setNome('AUTOR SIGILOSO')->setTenant($this->tenant);
        $this->processo->addParte($parte);
        $this->em()->persist($parte);
        $this->em()->persist($this->processo);
        $this->em()->flush();
    }

    /** Pasta B com o processo, trancada para todos (a Ana fica Oculta). */
    private function pastaTrancadaComOProcesso(): Pasta
    {
        $b = $this->criarPasta($this->tenant, 'CLIENTE TRANCADO');
        $b->vincularProcesso($this->processo);
        $this->em()->flush();
        $this->trancar($b, $this->primario);

        return $b;
    }

    /** @param array<string, mixed> $campos */
    private function postar(string $rota, array $campos): void
    {
        $this->client->request('POST', $rota, ['_token' => 'TOKEN_push_publicacoes'] + $campos);
    }

    /** @return array<string, list<string>> */
    private function flashes(): array
    {
        return $this->client->getRequest()->getSession()->getFlashBag()->peekAll();
    }

    private function vinculado(Pasta $pasta): bool
    {
        return (int) $this->em()->getConnection()->fetchOne(
            'SELECT count(*) FROM pasta_processo WHERE pasta_id = :p AND processo_id = :pr',
            ['p' => $pasta->getId(), 'pr' => $this->processo->getId()],
        ) === 1;
    }

    #[TestDox('Pelo número: o processo de pasta toda oculta vira "Processo não encontrado." no mesmo redirect do número inexistente, sem 403 e sem vínculo')]
    public function testPeloNumeroOcultoNaoDistingue(): void
    {
        $this->pastaTrancadaComOProcesso();
        $a = $this->criarPasta($this->tenant, 'CLIENTE LIVRE');
        $this->entrar($this->ana, $this->tenant);

        $this->postar('/push-processual/publicacoes/processo/vincular', ['numero' => self::NUMERO, 'pasta_id' => $a->getId()]);
        $oculto = $this->client->getResponse();
        self::assertResponseRedirects('/push-processual');
        self::assertSame(['Processo não encontrado.'], $this->flashes()['danger'] ?? null);
        self::assertStringNotContainsString('AUTOR SIGILOSO', (string) $oculto->getContent());
        self::assertFalse($this->vinculado($a));

        $this->postar('/push-processual/publicacoes/processo/vincular', ['numero' => self::INEXISTENTE, 'pasta_id' => $a->getId()]);
        self::assertSame($oculto->getStatusCode(), $this->client->getResponse()->getStatusCode(), 'o mesmo status do número inexistente');
        self::assertSame($oculto->headers->get('Location'), $this->client->getResponse()->headers->get('Location'), 'o mesmo destino');
    }

    #[TestDox('Pela publicação: o processo de pasta toda oculta vira "Processo não encontrado.", sem 403 e sem vínculo')]
    public function testPelaPublicacaoOculta(): void
    {
        $this->pastaTrancadaComOProcesso();
        $a = $this->criarPasta($this->tenant, 'CLIENTE LIVRE');
        $pub = $this->criarPublicacao($this->tenant, (string) random_int(900000000, 999999999), self::NUMERO);
        $this->entrar($this->ana, $this->tenant);

        $this->postar('/push-processual/publicacoes/' . $pub->getId() . '/vincular', ['pasta_id' => $a->getId(), 'numero' => self::NUMERO]);

        self::assertResponseRedirects('/push-processual');
        self::assertSame(['Processo não encontrado.'], $this->flashes()['danger'] ?? null);
        self::assertFalse($this->vinculado($a));
    }

    #[TestDox('Troca: processo na pasta anterior visível e na B oculta, trocado para outra visível, funciona (não recusa por ficar só em B no meio do caminho)')]
    public function testTrocaComPastaOcultaNoProcessoFunciona(): void
    {
        $b = $this->pastaTrancadaComOProcesso();
        $anterior = $this->criarPasta($this->tenant, 'CLIENTE ANTERIOR');
        $anterior->vincularProcesso($this->processo);
        $this->em()->flush();
        $nova = $this->criarPasta($this->tenant, 'CLIENTE NOVO');
        $this->entrar($this->ana, $this->tenant);

        $this->postar('/push-processual/publicacoes/processo/vincular', ['numero' => self::NUMERO, 'pasta_id' => $nova->getId(), 'trocar_da_pasta_id' => $anterior->getId()]);

        self::assertResponseRedirects('/push-processual');
        self::assertArrayNotHasKey('danger', $this->flashes());
        self::assertTrue($this->vinculado($nova));
        self::assertFalse($this->vinculado($anterior));
        self::assertTrue($this->vinculado($b));
    }

    #[TestDox('Troca recusada (a pessoa só visualiza a pasta nova) não desvincula nada da anterior')]
    public function testTrocaRecusadaNaoDesvincula(): void
    {
        $anterior = $this->criarPasta($this->tenant, 'CLIENTE ANTERIOR');
        $anterior->vincularProcesso($this->processo);
        $this->em()->flush();
        $nova = $this->criarPasta($this->tenant, 'CLIENTE NOVO');
        $this->trancar($nova, $this->primario, [[$this->ana, NivelDeAcesso::Visualizar]]);
        $this->entrar($this->ana, $this->tenant);

        $this->postar('/push-processual/publicacoes/processo/vincular', ['numero' => self::NUMERO, 'pasta_id' => $nova->getId(), 'trocar_da_pasta_id' => $anterior->getId()]);

        self::assertResponseRedirects('/push-processual');
        self::assertNotEmpty($this->flashes()['danger'] ?? []);
        self::assertTrue($this->vinculado($anterior));
        self::assertFalse($this->vinculado($nova));
    }

    #[TestDox('O primário vincula normalmente o processo da pasta trancada')]
    public function testPrimarioVincula(): void
    {
        $this->pastaTrancadaComOProcesso();
        $a = $this->criarPasta($this->tenant, 'CLIENTE LIVRE');
        $this->entrar($this->primario, $this->tenant);

        $this->postar('/push-processual/publicacoes/processo/vincular', ['numero' => self::NUMERO, 'pasta_id' => $a->getId()]);

        self::assertResponseRedirects('/push-processual');
        self::assertArrayNotHasKey('danger', $this->flashes());
        self::assertTrue($this->vinculado($a));
    }
}
