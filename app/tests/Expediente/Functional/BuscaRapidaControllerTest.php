<?php

declare(strict_types=1);

namespace App\Tests\Expediente\Functional;

use App\Cliente\Entity\ClientePF;
use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Expediente\Controller\BuscaRapidaController;
use App\Expediente\UseCase\BuscarRapidoUseCase;
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
            'trancada'  => false,
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

    #[TestDox('pasta trancada vem com trancada=true; quem não é administrador não a abre direto')]
    public function testTrancadaVemMarcada(): void
    {
        $client = static::createClient();
        $tenant = $this->criarTenant();
        $admin  = $this->criarAdministrador($tenant);
        $pessoa = $this->criarUsuarioComPermissoes($tenant, ['modules.pastas.view', 'resources.pasta.view']);
        $pasta  = $this->criarPasta($tenant);
        $pasta->trancar($admin, new \DateTimeImmutable());
        $this->em()->flush();
        $q = rawurlencode((string) $pasta->getNup());

        $this->logarComTenant($client, $pessoa, $tenant);
        $client->request('GET', self::URL . '?q=' . $q);
        self::assertResponseIsSuccessful();
        $json = json_decode((string) $client->getResponse()->getContent(), true);

        self::assertTrue($json['resultados'][0]['trancada']);
        self::assertFalse($json['abreTrancada']);

        $this->logarComTenant($client, $admin, $tenant);
        $client->request('GET', self::URL . '?q=' . $q);
        self::assertTrue(json_decode((string) $client->getResponse()->getContent(), true)['abreTrancada']);
    }

    // ----------------------------------------------------------------- helpers

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
