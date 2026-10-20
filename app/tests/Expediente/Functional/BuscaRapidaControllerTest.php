<?php

declare(strict_types=1);

namespace App\Tests\Expediente\Functional;

use App\Cliente\Entity\ClientePF;
use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Expediente\Controller\BuscaRapidaController;
use App\Expediente\UseCase\BuscarRapidoUseCase;
use App\Pasta\Entity\NivelDeAcesso;
use App\Pasta\Entity\Pasta;
use App\Processo\Entity\Processo;
use App\Tests\Factory\Cliente\ClientePFFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Zenstruck\Foundry\Test\Factories;

#[CoversClass(BuscaRapidaController::class)]
#[CoversClass(BuscarRapidoUseCase::class)]
final class BuscaRapidaControllerTest extends BuscaRapidaWebTestCase
{
    use Factories;

    private const URL = '/expediente/busca-rapida';

    #[TestDox('sem login: redireciona para o login')]
    public function testSemLoginRedireciona(): void
    {
        $client = static::createClient();
        $client->request('GET', self::URL . '?q=x');

        self::assertResponseRedirects();
        self::assertStringContainsString('/login', (string) $client->getResponse()->headers->get('Location'));
    }

    #[TestDox('sem o módulo Pastas: 403')]
    public function testSemModuloPastasDa403(): void
    {
        $client = static::createClient();
        $tenant = $this->criarTenant();
        $user   = $this->criarUsuarioComPermissoes($tenant, ['resources.pasta.view']);

        $this->logarComTenant($client, $user, $tenant);
        $client->request('GET', self::URL . '?q=x');

        self::assertResponseStatusCodeSame(403);
    }

    #[TestDox('devolve os três grupos no formato combinado')]
    public function testDevolveOsTresGrupos(): void
    {
        $client = static::createClient();
        $tenant = $this->criarTenant();
        $admin  = $this->criarAdministrador($tenant);
        $pasta  = $this->criarPasta($tenant, 'Acao Quintessencial');
        $pasta->addCliente($this->clientePF($tenant, 'Quintessencial Silva', '318.774.902-15'));
        $pasta->vincularProcesso($this->processo($tenant, '07122841920258070001'));
        $this->em()->flush();

        $porNumero = $this->buscar($client, $admin, $tenant, 'Pasta ' . $pasta->getNup());
        self::assertSame([
            'grupo'     => 'Pasta',
            'titulo'    => 'Pasta ' . $pasta->getNup(),
            'subtitulo' => 'ACAO QUINTESSENCIAL',
            'pastaId'   => $pasta->getId(),
            'nup'       => $pasta->getNup(),
            'url'       => '/pasta/' . $pasta->getId(),
            'restrito'  => false,
        ], $porNumero[0]);

        $porCliente = $this->buscar($client, $admin, $tenant, 'quintessencial');
        self::assertSame(['Cliente'], array_column($porCliente, 'grupo'), 'a ação não entra na busca do grupo Pasta');
        self::assertSame('QUINTESSENCIAL SILVA', $porCliente[0]['titulo']);
        self::assertSame('CPF 318.774.902-15 · Pasta ' . $pasta->getNup(), $porCliente[0]['subtitulo']);

        $porProcesso = $this->buscar($client, $admin, $tenant, '0712284-19');
        self::assertSame('Processo', $porProcesso[0]['grupo']);
        self::assertSame('0712284-19.2025.8.07.0001', $porProcesso[0]['titulo']);
        self::assertSame('ACAO QUINTESSENCIAL · Pasta ' . $pasta->getNup(), $porProcesso[0]['subtitulo']);
    }

    #[TestDox('pasta de outro escritório nunca aparece')]
    public function testOutroEscritorioNuncaAparece(): void
    {
        $client = static::createClient();
        $meu    = $this->criarTenant();
        $alheio = $this->criarTenant();
        $admin  = $this->criarAdministrador($meu);
        $dele   = $this->criarPasta($alheio, nomeCliente: 'Zigurate Alheio');

        self::assertSame([], $this->buscar($client, $admin, $meu, (string) $dele->getNup()));
        self::assertSame([], $this->buscar($client, $admin, $meu, 'zigurate'));
        self::assertSame([], $this->buscar($client, $admin, $meu, ''));
    }

    #[TestDox('sem resources.pasta.view, só aparece a pasta com acesso próprio de ver; can_view=false não conta')]
    public function testPastaSemAcessoNaoAparece(): void
    {
        $client   = static::createClient();
        $tenant   = $this->criarTenant();
        $pessoa   = $this->criarUsuarioComPermissoes($tenant, ['modules.pastas.view']);
        $liberada = $this->criarPasta($tenant, nomeCliente: 'Obelisco Teste');
        $semVer   = $this->criarPasta($tenant, nomeCliente: 'Obelisco Teste');
        $this->criarPasta($tenant, nomeCliente: 'Obelisco Teste');
        $this->darAcessoProprio($pessoa, $tenant, $liberada);
        $this->darAcessoProprio($pessoa, $tenant, $semVer, podeVer: false);

        $resultados = $this->buscar($client, $pessoa, $tenant, 'obelisco');

        self::assertSame([$liberada->getId()], array_column($resultados, 'pastaId'));
    }

    #[TestDox('60 pastas e acesso só a uma antiga: o corte vale sobre o visível, ela aparece no campo vazio e no termo comum')]
    public function testAcessoAUmaPastaAntigaEntreSessenta(): void
    {
        $client = static::createClient();
        $tenant = $this->criarTenant();
        $pessoa = $this->criarUsuarioComPermissoes($tenant, ['modules.pastas.view']);
        $antiga = $this->criarPasta($tenant, nomeCliente: 'Comum Sessenta');
        for ($i = 0; $i < 59; ++$i) {
            $this->criarPasta($tenant, nomeCliente: 'Comum Sessenta');
        }
        $this->darAcessoProprio($pessoa, $tenant, $antiga);

        self::assertSame([$antiga->getId()], array_column($this->buscar($client, $pessoa, $tenant, ''), 'pastaId'));
        self::assertSame([$antiga->getId()], array_column($this->buscar($client, $pessoa, $tenant, 'comum sessenta'), 'pastaId'));
    }

    #[TestDox('pasta oculta pelo cadeado: aparece por número, nome do cliente e CPF, sempre restrita e sem a ação no subtítulo')]
    public function testPastaOcultaCasaSoPorNumeroECliente(): void
    {
        $client = static::createClient();
        [$admin, $pessoa, $tenant, $oculta] = $this->cenarioComPastaOculta();

        $porNumero = $this->buscar($client, $pessoa, $tenant, (string) $oculta->getNup());
        self::assertSame([[
            'grupo'     => 'Pasta',
            'titulo'    => 'Pasta ' . $oculta->getNup(),
            'subtitulo' => 'CLARABELA OCULTA',
            'pastaId'   => $oculta->getId(),
            'nup'       => $oculta->getNup(),
            'url'       => '/pasta/' . $oculta->getId(),
            'restrito'  => true,
        ]], $porNumero);

        $porNome = $this->buscar($client, $pessoa, $tenant, 'clarabela oculta');
        self::assertSame(['Cliente'], array_column($porNome, 'grupo'));
        self::assertTrue($porNome[0]['restrito']);
        self::assertSame('CPF 318.774.902-15 · Pasta ' . $oculta->getNup(), $porNome[0]['subtitulo']);

        $porCpf = $this->buscar($client, $pessoa, $tenant, '31877490215');
        self::assertSame([$oculta->getId()], array_column($porCpf, 'pastaId'));
        self::assertSame([true], array_column($porCpf, 'restrito'));

        // O campo vazio também a lista, restrita e sem a ação.
        $vazio = array_values(array_filter($this->buscar($client, $pessoa, $tenant, ''), static fn (array $r): bool => $r['pastaId'] === $oculta->getId()));
        self::assertCount(1, $vazio);
        self::assertTrue($vazio[0]['restrito']);
        self::assertSame('CLARABELA OCULTA', $vazio[0]['subtitulo']);
    }

    #[TestDox('pasta oculta não aparece pelo número do processo nem pelo texto da ação, e o JSON não vaza nenhum dos dois')]
    public function testPastaOcultaNaoCasaPorProcessoNemPelaAcao(): void
    {
        $client = static::createClient();
        [, $pessoa, $tenant] = $this->cenarioComPastaOculta();

        self::assertSame([], $this->buscar($client, $pessoa, $tenant, '0733311-27.2025'));
        self::assertSame([], $this->buscar($client, $pessoa, $tenant, '07333112720258070001'));
        self::assertSame([], $this->buscar($client, $pessoa, $tenant, '733311'));
        self::assertSame([], $this->buscar($client, $pessoa, $tenant, 'acao sigilosa'));

        foreach (['', 'clarabela', '31877490215', 'pasta'] as $q) {
            $this->buscar($client, $pessoa, $tenant, $q);
            $json = (string) $client->getResponse()->getContent();
            self::assertStringNotContainsStringIgnoringCase('sigilosa', $json, 'a ação da pasta oculta não sai ("' . $q . '")');
            self::assertStringNotContainsString('0733311', $json, 'o processo da pasta oculta não sai ("' . $q . '")');
            self::assertStringNotContainsString('Procedimento comum', $json, 'a classe do processo não sai ("' . $q . '")');
        }
    }

    #[TestDox('abrir a pasta oculta pela URL do resultado cai na tela "Pasta trancada", com o pedido de acesso')]
    public function testAbrirPastaOcultaCaiNaTelaTrancada(): void
    {
        $client = static::createClient();
        [, $pessoa, $tenant, $oculta] = $this->cenarioComPastaOculta();

        $url     = $this->buscar($client, $pessoa, $tenant, (string) $oculta->getNup())[0]['url'];
        $crawler = $client->request('GET', $url);

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('Pasta trancada', $crawler->text());
        self::assertCount(1, $crawler->filter('form[action="/pasta/' . $oculta->getId() . '/acesso/pedidos"]'));
        self::assertStringNotContainsStringIgnoringCase('sigilosa', (string) $client->getResponse()->getContent());
    }

    #[TestDox('pasta oculta fora do escopo da pessoa (ela não a veria nem livre) não aparece por nada')]
    public function testPastaOcultaForaDoEscopoNaoAparece(): void
    {
        $client  = static::createClient();
        $tenant  = $this->criarTenant();
        $admin   = $this->criarAdministrador($tenant);
        $pessoa  = $this->criarUsuarioComPermissoes($tenant, ['modules.pastas.view']);
        $fora    = $this->criarPasta($tenant, 'Acao Fora', 'Escopo Alheio Teste');
        $dentro  = $this->criarPasta($tenant, 'Acao Dentro', 'Escopo Alheio Teste');
        $fora->addCliente($this->clientePF($tenant, 'Escopo Alheio Cliente', '529.982.247-25'));
        $this->em()->flush();
        $this->trancar($fora, $admin);
        $this->trancar($dentro, $admin);
        $this->darAcessoProprio($pessoa, $tenant, $dentro);

        foreach ([(string) $fora->getNup(), 'escopo alheio', '52998224725', ''] as $q) {
            self::assertNotContains($fora->getId(), array_column($this->buscar($client, $pessoa, $tenant, $q), 'pastaId'), 'termo "' . $q . '"');
        }
        // A que está no escopo (acesso próprio), mesmo oculta, aparece restrita.
        $achadas = $this->buscar($client, $pessoa, $tenant, 'escopo alheio');
        self::assertSame([$dentro->getId()], array_column($achadas, 'pastaId'));
        self::assertSame([true], array_column($achadas, 'restrito'));
    }

    #[TestDox('quem tem acesso à pasta com cadeado (primário, liberado na grade) a vê normal, com processo e ação, restrito=false')]
    public function testQuemTemAcessoVeNormal(): void
    {
        $client = static::createClient();
        // Cadeado por pessoa: `liberada` em Visualizar; `pessoa` fica oculta.
        [$admin, $pessoa, $tenant, $oculta, $liberada] = $this->cenarioComPastaOculta(porPessoa: true);

        foreach (['primário' => $admin, 'liberada' => $liberada] as $quem => $user) {
            $porNumero = $this->buscar($client, $user, $tenant, (string) $oculta->getNup());
            self::assertSame([false], array_column($porNumero, 'restrito'), $quem);
            self::assertSame('ACAO SIGILOSA', $porNumero[0]['subtitulo'], $quem);

            $porProcesso = $this->buscar($client, $user, $tenant, '0733311-27');
            self::assertSame([$oculta->getId()], array_column($porProcesso, 'pastaId'), $quem);
            self::assertSame(['Processo'], array_column($porProcesso, 'grupo'), $quem);
        }

        self::assertSame([true], array_column($this->buscar($client, $pessoa, $tenant, (string) $oculta->getNup()), 'restrito'), 'quem segue oculto na grade');
        self::assertSame([], $this->buscar($client, $pessoa, $tenant, '0733311-27'));
    }

    #[TestDox('pasta com cadeado de outro escritório não aparece, nem restrita')]
    public function testCadeadoDeOutroEscritorioNaoAparece(): void
    {
        $client = static::createClient();
        [, , $tenant, $oculta] = $this->cenarioComPastaOculta();
        $outro  = $this->criarTenant();
        $alheia = $this->criarUsuarioComPermissoes($outro, ['modules.pastas.view', 'resources.pasta.view']);

        self::assertSame([], $this->buscar($client, $alheia, $outro, (string) $oculta->getNup()));
        self::assertSame([], $this->buscar($client, $alheia, $outro, 'clarabela'));
        self::assertSame([], $this->buscar($client, $alheia, $outro, ''));
    }

    // ----------------------------------------------------------------- helpers

    /**
     * Escritório com o primário, duas pessoas que veem todas as pastas (sem bypass) e uma pasta com ação,
     * cliente com CPF e processo, com cadeado: "Ocultar para todos" ou, com `$porPessoa`, a grade com
     * `liberada` em Visualizar e `pessoa` em Oculto.
     *
     * @return array{0: User, 1: User, 2: Tenant, 3: Pasta, 4: User}
     */
    private function cenarioComPastaOculta(bool $porPessoa = false): array
    {
        $tenant   = $this->criarTenant();
        $admin    = $this->criarAdministrador($tenant);
        $pessoa   = $this->criarUsuarioComPermissoes($tenant, ['modules.pastas.view', 'modules.expediente.view', 'resources.pasta.view']);
        $liberada = $this->criarUsuarioComPermissoes($tenant, ['modules.pastas.view', 'resources.pasta.view']);
        $oculta   = $this->criarPasta($tenant, 'Acao Sigilosa');
        $oculta->addCliente($this->clientePF($tenant, 'Clarabela Oculta', '318.774.902-15'));
        $oculta->vincularProcesso($this->processo($tenant, '07333112720258070001'));
        $this->em()->flush();
        $this->trancar($oculta, $admin, $porPessoa ? [[$liberada, NivelDeAcesso::Visualizar], [$pessoa, NivelDeAcesso::Oculto]] : []);

        return [$admin, $pessoa, $tenant, $oculta, $liberada];
    }

    /** @return list<array<string, mixed>> */
    private function buscar(KernelBrowser $client, User $user, Tenant $tenant, string $q): array
    {
        $this->logarComTenant($client, $user, $tenant);
        $client->request('GET', self::URL . '?q=' . rawurlencode($q));
        self::assertResponseIsSuccessful();
        self::assertResponseHeaderSame('Content-Type', 'application/json');

        return json_decode((string) $client->getResponse()->getContent(), true)['resultados'];
    }

    private function clientePF(Tenant $tenant, string $nome, string $cpf): ClientePF
    {
        return ClientePFFactory::createOne(['tenant' => $tenant, 'nomeCompleto' => $nome, 'cpf' => $cpf])->_real();
    }

    private function processo(Tenant $tenant, string $numero): Processo
    {
        $processo = new Processo();
        $processo->setNumeroProcesso($numero);
        $processo->setSiglaTribunal('TJDFT');
        $processo->setOrgaoJulgador('1a Vara');
        $processo->setClasseProcessual('Procedimento comum');
        $processo->setAssuntoProcessual('Teste');
        $processo->setSituacaoProcesso('Em Andamento');
        $processo->setInstancia('1a Instância');
        $processo->setTenant($tenant);
        $this->em()->persist($processo);

        return $processo;
    }
}
