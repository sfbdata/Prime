<?php

declare(strict_types=1);

namespace App\Tests\Ponto\Functional;

use App\Entity\Auth\User;
use App\Entity\Auth\UserTenant;
use App\Entity\Tenant\Tenant;
use App\Ponto\Controller\PontoController;
use App\Ponto\Entity\RegistroPonto;
use App\Tests\Functional\JusPrimeWebTestCase;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Depois da batida, a tela do ponto não recarrega mais: ela busca o próprio HTML e troca só as regiões que a batida
 * muda (spec `docs/specs/ponto-atualiza-sem-recarregar.md`). Recarregar zerava a liberação de áudio do navegador e o
 * toque da volta do intervalo não tocava com a pessoa longe do computador.
 *
 * O lado do navegador (a troca) é lido no texto do script em `App\Tests\Ponto\Unit\TelaDoPontoSemRecarregarJsTest`.
 * Aqui fica o contrato do servidor: o HTML que a busca recebe traz cada região com o id que o script procura, e traz
 * a batida nova, a próxima sugestão e o estado do relógio. Some um id e a troca cai no plano B (recarregar) calada.
 */
#[CoversClass(PontoController::class)]
final class TelaDoPontoAtualizaSemRecarregarTest extends JusPrimeWebTestCase
{
    #[TestDox('a tela entrega as regiões que a atualização troca, com o dia e o estado no relógio')]
    public function testTelaEntregaAsRegioesDaTroca(): void
    {
        $client = static::createClient();
        $tenant = $this->criarTenant();
        $user   = $this->criarUsuario($tenant);

        $this->logarComTenant($client, $user, $tenant);
        $crawler = $this->abrirTela($client);

        // Desenho novo (E1, 09/11/2026): `#ponto-tipos` (os quatro tipos com a hora de cada um) substitui
        // `#batidas-de-hoje`, e `#tipo-registro` virou o campo escondido que os botões dos tipos preenchem.
        foreach (['#ponto-tipos', '#ponto-banco-horas', '#ponto-regiao-pendencias', '#tipo-registro'] as $regiao) {
            self::assertCount(1, $crawler->filter($regiao), 'a atualização procura ' . $regiao);
        }
        // Da folha troca só o card, filho direto: o modal de pré-visualização e o script dele ficam com os listeners.
        self::assertCount(1, $crawler->filter('#ponto-regiao-folha > .card'), 'o card da folha, filho direto da região');

        $relogio = $crawler->filter('#clock');
        self::assertCount(1, $relogio);
        self::assertSame((new \DateTimeImmutable('today'))->format('Y-m-d'), $relogio->attr('data-dia'));
        self::assertSame(
            ['entrada' => null, 'repouso' => null, 'retorno' => null, 'saida' => null],
            $this->pontoDoDia($relogio),
            'sem batida, o relógio começa sem horários'
        );
    }

    #[TestDox('depois da batida, o HTML que a atualização recebe traz a batida, a próxima sugestão e o estado novos')]
    public function testHtmlDepoisDaBatidaTrazOEstadoNovo(): void
    {
        $client = static::createClient();
        $tenant = $this->criarTenant();
        $user   = $this->criarUsuario($tenant);
        $this->logarComTenant($client, $user, $tenant);

        $antes = $this->abrirTela($client);
        self::assertSame('entrada', $antes->filter('#tipo-registro')->attr('value'));

        $this->criarBatida($user, $tenant, 'entrada', '08:12:03');
        $depois = $this->abrirTela($client);

        // Desenho novo (E1, P8): a hora da batida mora no botão do tipo, dentro de `#ponto-tipos`.
        self::assertCount(
            1,
            $depois->filter('#ponto-tipos > button[data-tipo="entrada"][data-registrada="1"]'),
            'a lista do dia vem com a entrada'
        );
        self::assertStringContainsString('08:12:03', $depois->filter('#ponto-tipos')->text());
        self::assertSame(
            'repouso',
            $depois->filter('#tipo-registro')->attr('value'),
            'a sugestão do próximo tipo vem do servidor'
        );
        self::assertSame('true', $depois->filter('#ponto-tipos > button[data-tipo="repouso"]')->attr('aria-checked'));

        $estado = $this->pontoDoDia($depois->filter('#clock'));
        self::assertArrayHasKey('entrada', $estado, 'o relógio recebe a entrada para contar a jornada');
        self::assertStringContainsString('08:12', (string) $estado['entrada']);
        self::assertSame(
            $antes->filter('#clock')->attr('data-dia'),
            $depois->filter('#clock')->attr('data-dia'),
            'mesmo dia: a troca segue sem recarregar'
        );
    }

    #[TestDox('o estado do relógio sai num atributo escapado, não num script com |raw')]
    public function testEstadoDoRelogioNaoVaiCruNoScript(): void
    {
        $client = static::createClient();
        $tenant = $this->criarTenant();
        $user   = $this->criarUsuario($tenant);
        $this->logarComTenant($client, $user, $tenant);

        $this->abrirTela($client);
        $html = (string) $client->getResponse()->getContent();

        self::assertStringContainsString('data-ponto-do-dia="', $html);
        self::assertStringNotContainsString('const pontoHoje =', $html, 'o estado mora no atributo, que a troca reescreve');
    }

    private function abrirTela(KernelBrowser $client): Crawler
    {
        $crawler = $client->request('GET', '/ponto/');
        self::assertResponseIsSuccessful();

        return $crawler;
    }

    /** @return array<string, mixed> */
    private function pontoDoDia(Crawler $relogio): array
    {
        $estado = json_decode((string) $relogio->attr('data-ponto-do-dia'), true, 512, \JSON_THROW_ON_ERROR);
        self::assertIsArray($estado);

        return $estado;
    }

    private function criarTenant(): Tenant
    {
        $em     = static::getContainer()->get(EntityManagerInterface::class);
        $tenant = new Tenant();
        $tenant->setName('Tenant SEM RECARGA ' . uniqid());
        $em->persist($tenant);
        $em->flush();

        return $tenant;
    }

    private function criarUsuario(Tenant $tenant): User
    {
        $container = static::getContainer();
        $em        = $container->get(EntityManagerInterface::class);
        $hasher    = $container->get(UserPasswordHasherInterface::class);

        $user = new User();
        $user->setEmail('sem_recarga_' . uniqid() . '@test.com');
        $user->setFullName('Colaborador Sem Recarga');
        $user->setRoles(['ROLE_SUPER_ADMIN']);
        $user->setIsActive(true);
        $user->setPassword($hasher->hashPassword($user, 'senha123'));
        $em->persist($user);
        $em->persist(new UserTenant($user, $tenant));
        $em->flush();

        return $user;
    }

    private function criarBatida(User $user, Tenant $tenant, string $tipo, string $hora): void
    {
        [$h, $m, $s] = array_map('intval', explode(':', $hora));
        $em          = static::getContainer()->get(EntityManagerInterface::class);

        $registro = new RegistroPonto();
        $registro->setUser($user);
        $registro->setTenant($tenant);
        $registro->setTipo($tipo);
        $registro->setDataHora((new \DateTime('today'))->setTime($h, $m, $s));
        $em->persist($registro);
        $em->flush();
    }
}
