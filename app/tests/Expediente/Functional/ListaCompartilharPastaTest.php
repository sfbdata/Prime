<?php

declare(strict_types=1);

namespace App\Tests\Expediente\Functional;

use App\Entity\Auth\User;
use App\Entity\Auth\UserTenant;
use App\Entity\Tenant\Tenant;
use App\Expediente\Entity\Marcador;
use App\Expediente\Controller\ExpedienteController;
use App\Pasta\Entity\Pasta;
use App\Pasta\Entity\PastaCompartilhamento;
use App\Pasta\Entity\PermissoesDoCompartilhamento;
use App\Tests\Functional\JusPrimeWebTestCase;
use App\Tests\Pasta\Functional\CriaCenarioDoCadeadoFuncionalTrait;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;

/**
 * Fatia 5b da spec `docs/specs/acesso-restrito-e-compartilhar.md` (§3.2, L1): o ícone "Compartilhar pasta" na linha
 * da Lista do Expediente, com a contagem de colaboradores. O restrito não vê o ícone; quem recebeu a pasta vê só o
 * ícone verde (sem clique); a linha de pasta oculta pelo cadeado não leva o ícone.
 */
#[CoversClass(ExpedienteController::class)]
final class ListaCompartilharPastaTest extends JusPrimeWebTestCase
{
    use CriaCenarioDoCadeadoFuncionalTrait;

    private const PERMISSOES = ['modules.expediente.view', 'modules.pastas.view', 'resources.pasta.view', 'resources.pasta.edit'];
    private const DICA_SEM = 'Compartilhar pasta (equipe ou outra conta)';

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

    #[TestDox('L1: com colaboradores o ícone fica verde, com a contagem e a dica "Compartilhada com N colaborador(es)"')]
    public function testIconeComContagem(): void
    {
        $this->compartilharCom($this->restrito);
        $this->compartilharCom($this->comum);
        $this->entrar($this->primario, $this->tenant);

        $nup = $this->celulaDoNup($this->pasta);

        $botao = $nup->filter('td[data-col="nup"] > button.js-compartilhar.exp-rp.exp-rp--tem');
        self::assertCount(1, $botao, 'o ícone é filho direto da célula do NUP');
        self::assertSame('Compartilhada com 2 colaborador(es) · clique para gerenciar', $botao->attr('data-tip'));
        self::assertSame((string) $this->pasta->getId(), $botao->attr('data-pasta-id'));
        self::assertSame('2', trim($botao->filter('span.exp-rp-n')->text()));
        self::assertCount(1, $botao->filter('i.bi-arrow-repeat'));
    }

    #[TestDox('L1: sem colaborador o ícone é cinza, sem contagem, com a dica "Compartilhar pasta (equipe ou outra conta)"')]
    public function testIconeSemColaborador(): void
    {
        $this->entrar($this->primario, $this->tenant);

        $botao = $this->celulaDoNup($this->pasta)->filter('td[data-col="nup"] > button.js-compartilhar.exp-rp');

        self::assertCount(1, $botao);
        self::assertStringNotContainsString('exp-rp--tem', (string) $botao->attr('class'));
        self::assertSame(self::DICA_SEM, $botao->attr('data-tip'));
        self::assertCount(0, $botao->filter('.exp-rp-n'));
    }

    #[TestDox('L1: encerrado ou vencido não conta')]
    public function testSoContaQuemColaboraAgora(): void
    {
        $vencido = $this->compartilharCom($this->restrito);
        (new \ReflectionProperty(PastaCompartilhamento::class, 'validoAte'))->setValue($vencido, new \DateTimeImmutable('yesterday'));
        $this->em()->flush();
        $this->entrar($this->primario, $this->tenant);

        $botao = $this->celulaDoNup($this->pasta)->filter('td[data-col="nup"] > button.js-compartilhar');

        self::assertSame(self::DICA_SEM, $botao->attr('data-tip'));
    }

    #[TestDox('L1: o restrito não vê o ícone nem na própria pasta; quem recebeu a pasta vê só o ícone verde, sem clique')]
    public function testRestritoNaoVeOIcone(): void
    {
        $minha = $this->criarPasta($this->tenant, 'CLIENTE DA RITA');
        $minha->setResponsavel($this->restrito);
        $this->em()->flush();
        $this->compartilharCom($this->restrito);
        /* O restrito não abre o acervo geral: a Lista dele vem pelo painel de um marcador. */
        $marcador = new Marcador('Do restrito ' . uniqid(), $this->tenant, $this->primario);
        $this->em()->persist($marcador);
        $minha->addMarcador($marcador);
        $this->pasta->addMarcador($marcador);
        $this->em()->flush();
        $this->entrar($this->restrito, $this->tenant);

        $c = $this->client->xmlHttpRequest('GET', '/expediente/marcador/' . $marcador->getId() . '/pastas');

        self::assertCount(0, $c->filter('#tabelaPastas button.js-compartilhar'), 'nenhum botão para o restrito');
        self::assertCount(0, $c->filter('#tabelaPastas tr[data-compartilhar]'), 'a linha não oferece o menu de compartilhar');
        $propria = $c->filter('#tabelaPastas tbody > tr[data-pasta-id="' . $minha->getId() . '"]');
        self::assertCount(1, $propria);
        self::assertCount(0, $propria->filter('td[data-col="nup"] > i.exp-rp-recebida'));
        self::assertNull($propria->attr('data-compartilhada-comigo'));

        $recebida = $c->filter('#tabelaPastas tbody > tr[data-pasta-id="' . $this->pasta->getId() . '"]');
        self::assertSame('1', $recebida->attr('data-compartilhada-comigo'));
        self::assertCount(1, $recebida->filter('td[data-col="nup"] > i.exp-rp-recebida'));
    }

    #[TestDox('L1: a linha de pasta oculta pelo cadeado não leva o ícone')]
    public function testPastaOcultaSemIcone(): void
    {
        $this->trancar($this->pasta, $this->primario);
        $this->entrar($this->comum, $this->tenant);

        $linha = $this->client->xmlHttpRequest('GET', '/expediente/painel/acervo-geral')
            ->filter('#tabelaPastas tbody > tr[data-pasta-id="' . $this->pasta->getId() . '"]');

        self::assertSame('1', $linha->attr('data-oculta'));
        self::assertCount(0, $linha->filter('button.js-compartilhar'));
        self::assertNull($linha->attr('data-compartilhar'));
    }

    #[TestDox('L1: quem vê a pasta mas não a edita não recebe o ícone')]
    public function testSemEditarSemIcone(): void
    {
        $leitor = $this->criarColaborador($this->tenant, 'Lia Leitora', ['modules.expediente.view', 'modules.pastas.view', 'resources.pasta.view']);
        $this->entrar($leitor, $this->tenant);

        $linha = $this->client->xmlHttpRequest('GET', '/expediente/painel/acervo-geral')
            ->filter('#tabelaPastas tbody > tr[data-pasta-id="' . $this->pasta->getId() . '"]');

        self::assertCount(1, $linha);
        self::assertCount(0, $linha->filter('button.js-compartilhar'));
        self::assertNull($linha->attr('data-compartilhar'));
    }

    private function celulaDoNup(Pasta $pasta): \Symfony\Component\DomCrawler\Crawler
    {
        $linha = $this->client->xmlHttpRequest('GET', '/expediente/painel/acervo-geral')
            ->filter('#tabelaPastas tbody > tr[data-pasta-id="' . $pasta->getId() . '"]');
        self::assertCount(1, $linha);
        self::assertSame('1', $linha->attr('data-compartilhar'));

        return $linha;
    }

    private function compartilharCom(User $destino): PastaCompartilhamento
    {
        $c = PastaCompartilhamento::interno($this->pasta, $this->primario, $destino, new PermissoesDoCompartilhamento(), null, null, new \DateTimeImmutable('-2 days'));
        $this->em()->persist($c);
        $this->em()->flush();

        return $c;
    }
}
