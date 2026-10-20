<?php

declare(strict_types=1);

namespace App\Tests\Tenant\Functional;

use App\Entity\Auth\User;
use App\Entity\Auth\UserTenant;
use App\Entity\Tenant\Tenant;
use App\Tenant\EventListener\BarreiraDoEscritorioInativoNaRota;
use App\Tenant\Service\AvisoDeContaSuspensa;
use App\Tests\Functional\JusPrimeWebTestCase;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\Routing\Route;
use Symfony\Component\Routing\RouterInterface;

/**
 * Escritório suspenso ou excluído nas rotas que recebem o escritório pela URL (spec
 * escritorio-inativo-nas-rotas-por-url). Cenário do defeito: o admin do escritório A, que também tem o
 * escritório B, entra com B na sessão e chama as rotas `/tenant/{A}/...` com A suspenso.
 *
 * As rotas vêm do roteador (toda rota com `{tenantId}` e as do R2): uma rota nova entra sozinha no teste.
 */
#[CoversClass(BarreiraDoEscritorioInativoNaRota::class)]
#[Group('tenant')]
final class BarreiraDoEscritorioInativoNaRotaTest extends JusPrimeWebTestCase
{
    private KernelBrowser $client;
    private EntityManagerInterface $em;
    private Tenant $escritorioA;
    private Tenant $escritorioB;
    private User $adminDeA;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->client->disableReboot();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);

        $this->escritorioA = $this->criarEscritorio('Escritório A suspenso');
        $this->escritorioB = $this->criarEscritorio('Escritório B ativo');
        $this->adminDeA = $this->criarUsuario('Admin de A', ['ROLE_USER'], [$this->escritorioA, $this->escritorioB]);
    }

    #[TestDox('Escritório da URL suspenso: cada rota recusa quem não é Master (tela 403, API 403 JSON) com o aviso de conta suspensa')]
    public function testTodasAsRotasRecusamComEscritorioSuspenso(): void
    {
        $this->escritorioA->setIsActive(false);
        $this->em->flush();
        $this->logarComTenant($this->client, $this->adminDeA, $this->escritorioB);

        $rotas = $this->rotasComEscritorio();
        self::assertGreaterThanOrEqual(42, count($rotas), 'as 42 rotas do levantamento de 20/10');

        $falhas = [];
        foreach ($rotas as $nome => $rota) {
            $falha = $this->conferirRecusa($nome, $rota, AvisoDeContaSuspensa::para('Escritório A suspenso'));
            if ($falha !== null) {
                $falhas[] = $falha;
            }
        }

        self::assertSame([], $falhas);
    }

    #[TestDox('Escritório da URL ativo: nenhuma rota passa pela recusa (controle)')]
    public function testEscritorioAtivoNaoERecusado(): void
    {
        $this->logarComTenant($this->client, $this->adminDeA, $this->escritorioB);

        $recusadas = [];
        foreach ($this->rotasComEscritorio() as $nome => $rota) {
            $this->chamar($rota, $nome);
            if ($this->foiRecusadaPelaBarreira()) {
                $recusadas[] = $nome;
            }
        }

        self::assertSame([], $recusadas);
    }

    #[TestDox('Escritório excluído (em quarentena): recusado com o aviso de conta excluída')]
    public function testEscritorioExcluidoERecusado(): void
    {
        $this->escritorioA->setIsActive(false);
        $this->escritorioA->setExcluidoEm(new \DateTimeImmutable('-1 day'));
        $this->em->flush();
        $this->logarComTenant($this->client, $this->adminDeA, $this->escritorioB);

        $this->client->request('POST', "/tenant/{$this->escritorioA->getId()}/user/{$this->adminDeA->getId()}/ponto/add");

        self::assertResponseStatusCodeSame(403);
        self::assertSame(['erro' => AvisoDeContaSuspensa::paraExcluida('Escritório A suspenso')], $this->json());
    }

    #[TestDox('O Master passa com o escritório suspenso (é quem suspende e reativa)')]
    public function testMasterPassa(): void
    {
        $master = $this->criarUsuario('Master do Sistema', ['ROLE_SUPER_ADMIN'], []);
        $this->escritorioA->setIsActive(false);
        $this->em->flush();
        $this->client->loginUser($master);
        $this->marcarTermosAceitos($this->client);

        $this->client->request('GET', "/tenant/{$this->escritorioA->getId()}/users");

        self::assertResponseIsSuccessful();
        self::assertFalse($this->foiRecusadaPelaBarreira());
    }

    #[TestDox('Sair do escritório suspenso continua liberado: tirar o próprio acesso nunca trava')]
    public function testSairContinuaLiberado(): void
    {
        $this->escritorioA->setIsActive(false);
        $this->em->flush();
        $this->logarComTenant($this->client, $this->adminDeA, $this->escritorioB);

        $this->client->request('POST', "/escritorio/{$this->escritorioA->getId()}/sair");

        self::assertFalse($this->foiRecusadaPelaBarreira());
    }

    /** @return array<string, Route> */
    private function rotasComEscritorio(): array
    {
        $rotas = [];
        foreach (static::getContainer()->get(RouterInterface::class)->getRouteCollection()->all() as $nome => $rota) {
            if (str_contains($rota->getPath(), '{tenantId}') || in_array($nome, BarreiraDoEscritorioInativoNaRota::ROTAS_COM_ID_DO_ESCRITORIO, true)) {
                $rotas[$nome] = $rota;
            }
        }
        ksort($rotas);

        return $rotas;
    }

    private function chamar(Route $rota, string $nome): string
    {
        $metodo = $rota->getMethods()[0] ?? 'GET';
        $parametros = [];
        foreach ($rota->compile()->getPathVariables() as $variavel) {
            $parametros[$variavel] = (string) $this->adminDeA->getId();
        }
        $parametros[in_array($nome, BarreiraDoEscritorioInativoNaRota::ROTAS_COM_ID_DO_ESCRITORIO, true) ? 'id' : 'tenantId'] = (string) $this->escritorioA->getId();
        $url = static::getContainer()->get(RouterInterface::class)->generate($nome, $parametros);

        $this->client->request($metodo, $url);

        return $metodo;
    }

    private function conferirRecusa(string $nome, Route $rota, string $aviso): ?string
    {
        $metodo = $this->chamar($rota, $nome);
        $resposta = $this->client->getResponse();
        if ($resposta->getStatusCode() !== 403) {
            return sprintf('%s (%s): status %d', $nome, $metodo, $resposta->getStatusCode());
        }

        if ($metodo === 'GET') {
            $mensagem = $this->client->getCrawler()->filter('[data-escritorio-indisponivel] .js-escritorio-indisponivel-mensagem');

            return $mensagem->count() === 1 && $mensagem->text() === $aviso ? null : sprintf('%s (GET): sem a página de recusa', $nome);
        }

        return $this->json() === ['erro' => $aviso] ? null : sprintf('%s (%s): JSON diferente', $nome, $metodo);
    }

    private function foiRecusadaPelaBarreira(): bool
    {
        $conteudo = (string) $this->client->getResponse()->getContent();
        $erro = (string) ($this->json()['erro'] ?? '');

        return str_contains($conteudo, 'data-escritorio-indisponivel')
            || str_starts_with($erro, 'A conta do escritório Escritório A suspenso');
    }

    /** @return array<string, mixed> */
    private function json(): array
    {
        return (array) json_decode((string) $this->client->getResponse()->getContent(), true);
    }

    private function criarEscritorio(string $nome): Tenant
    {
        $tenant = new Tenant();
        $tenant->setName($nome);
        $this->em->persist($tenant);
        $this->em->flush();

        return $tenant;
    }

    /**
     * @param list<string> $papeis
     * @param list<Tenant> $escritorios
     */
    private function criarUsuario(string $nome, array $papeis, array $escritorios): User
    {
        $user = new User();
        $user->setEmail('barreira_' . uniqid() . '@test.com');
        $user->setFullName($nome);
        $user->setRoles($papeis);
        $user->setIsActive(true);
        $user->setPassword('x');
        $this->em->persist($user);
        foreach ($escritorios as $escritorio) {
            $this->em->persist(new UserTenant($user, $escritorio));
        }
        $this->em->flush();

        return $user;
    }
}
