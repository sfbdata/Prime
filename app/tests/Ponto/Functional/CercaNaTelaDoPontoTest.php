<?php

declare(strict_types=1);

namespace App\Tests\Ponto\Functional;

use App\Entity\Auth\User;
use App\Entity\Auth\UserTenant;
use App\Entity\Tenant\Sede;
use App\Entity\Tenant\Tenant;
use App\Ponto\Controller\PontoController;
use App\Tests\Functional\JusPrimeWebTestCase;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Decisão do dono em 11/09/2026: a cerca **continua bloqueando**, e o botão deve ficar desabilitado
 * para quem está fora do raio. Isso não existia: o botão só travava quando o navegador não entregava
 * posição **nenhuma**, então quem estava fora conseguia apertar e levava um erro depois.
 *
 * Para a tela saber dizer "você está fora da área" antes do toque, ela precisa das sedes do
 * escritório. É dado novo saindo do servidor para o navegador, e por isso o que se prova aqui é o
 * ESCOPO: chega a sede do escritório ativo, e não chega a de mais ninguém.
 *
 * 🔑 O teste de isolamento usa um **recurso irmão** — uma sede do outro escritório, com o mesmo
 * usuário vinculado aos dois — para provar o guarda certo. Sem isso, um teste verde poderia estar
 * apenas provando que o usuário não tem acesso ao outro escritório, e não que o escopo da consulta
 * funciona (ver `feedback_teste_de_isolamento_pode_provar_outra_barreira`).
 *
 * Spec: `docs/specs/ponto-batida-nao-se-perde-no-navegador.md`.
 */
#[CoversClass(PontoController::class)]
final class CercaNaTelaDoPontoTest extends JusPrimeWebTestCase
{
    #[TestDox('a tela recebe a sede do escritório ativo, com raio e coordenadas')]
    public function testTelaRecebeASedeDoEscritorioAtivo(): void
    {
        $client = static::createClient();
        $tenant = $this->criarTenant();
        $user   = $this->criarUsuario($tenant);
        $this->criarSede($tenant, 'Sede Principal QND', '-15.81100086', '-48.06479935', 100);

        $this->logarComTenant($client, $user, $tenant);
        $client->request('GET', '/ponto/');

        self::assertResponseIsSuccessful();
        $sedes = $this->sedesEntreguesATela($client);

        self::assertCount(1, $sedes, 'a tela deveria receber exatamente a sede do escritório ativo');
        self::assertSame('Sede Principal QND', $sedes[0]['nome']);
        self::assertSame(-15.81100086, $sedes[0]['latitude'], 'sem a coordenada a tela não sabe calcular a distância');
        self::assertSame(-48.06479935, $sedes[0]['longitude']);
        self::assertSame(100, $sedes[0]['raio'], 'sem o raio a tela não sabe onde é a fronteira');
    }

    #[TestDox('a sede de outro escritório NÃO chega à tela, mesmo com o usuário vinculado aos dois')]
    public function testNaoVazaSedeDeOutroEscritorio(): void
    {
        $client  = static::createClient();
        $tenantA = $this->criarTenant();
        $tenantB = $this->criarTenant();
        $user    = $this->criarUsuario($tenantA);
        $this->vincular($user, $tenantB);

        $this->criarSede($tenantA, 'Sede DO ESCRITORIO ATIVO', '-15.81100086', '-48.06479935', 100);
        $this->criarSede($tenantB, 'Sede DO OUTRO ESCRITORIO', '-9.65807923', '-35.70226113', 100);

        // Controle positivo e negativo na mesma renderização: com o usuário vinculado aos DOIS, o
        // que separa é o escopo da consulta, não a falta de acesso ao outro escritório.
        $this->logarComTenant($client, $user, $tenantA);
        $client->request('GET', '/ponto/');

        self::assertResponseIsSuccessful();
        $nomes = array_column($this->sedesEntreguesATela($client), 'nome');

        self::assertContains('Sede DO ESCRITORIO ATIVO', $nomes, 'controle positivo: a sede do escritório ativo tem que chegar');
        self::assertNotContains('Sede DO OUTRO ESCRITORIO', $nomes, 'a sede do outro escritório não pode vazar para a tela');
        self::assertCount(1, $nomes, 'só a sede do escritório ativo pode chegar à tela');
    }

    /**
     * Achado de auditoria interna F2 (spec `seguranca-b-ponto.md` §1.1): o nome com caracteres especiais chega
     * íntegro ao JSON e não aparece cru no HTML da página. A tela o põe no DOM só por `textContent`
     * (`App\Tests\Ponto\Unit\NomeDaSedeForaDoInnerHtmlJsTest`).
     */
    #[TestDox('nome de sede com caracteres especiais chega íntegro ao JSON e não sai cru no HTML')]
    public function testNomeComCaracteresEspeciaisChegaIntegro(): void
    {
        $client = static::createClient();
        $tenant = $this->criarTenant();
        $user   = $this->criarUsuario($tenant);
        $nome   = '<b>Sede "A" & \'B\'</b> </script>';
        $this->criarSede($tenant, $nome, '-15.81100086', '-48.06479935', 100);

        $this->logarComTenant($client, $user, $tenant);
        $client->request('GET', '/ponto/');

        self::assertResponseIsSuccessful();
        self::assertSame([$nome], array_column($this->sedesEntreguesATela($client), 'nome'));
        $html = (string) $client->getResponse()->getContent();
        self::assertStringNotContainsString('<b>Sede', $html, 'o nome não pode sair cru na página');
    }

    /**
     * 🪤 A outra exclusão do servidor, sede **sem coordenada**, não é testável e nem precisa ser:
     * `sede.latitude` é `NOT NULL` no banco (medido — o insert estoura com `23502`). O guard de null
     * em `batida()` e no `index()` é defesa em profundidade sobre estado que o banco não deixa
     * existir. Fica registrado aqui para ninguém gastar tempo montando o caso de novo.
     */
    #[TestDox('sede com raio não positivo fica de fora, como no servidor')]
    public function testEspelhaAsExclusoesDoServidor(): void
    {
        $client = static::createClient();
        $tenant = $this->criarTenant();
        $user   = $this->criarUsuario($tenant);

        $this->criarSede($tenant, 'Sede VALIDA', '-15.81100086', '-48.06479935', 100);
        $this->criarSede($tenant, 'Sede COM RAIO ZERO', '-15.81781327', '-48.06682437', 0);

        $this->logarComTenant($client, $user, $tenant);
        $client->request('GET', '/ponto/');

        self::assertResponseIsSuccessful();
        $nomes = array_column($this->sedesEntreguesATela($client), 'nome');

        self::assertContains('Sede VALIDA', $nomes);
        // `batida()` pula essa sede no servidor. Mandá-la para a tela faria o navegador bloquear por
        // uma cerca que o servidor não aplica — a tela ficaria MAIS rígida que a regra.
        self::assertNotContains('Sede COM RAIO ZERO', $nomes, 'raio não positivo é ignorado pelo servidor');
    }

    /**
     * As sedes que a tela efetivamente recebeu, decodificadas.
     *
     * 🪤 Não dá para procurar o nome cru no HTML: o valor sai por `|e('js')`, que troca tudo fora de
     * `[a-zA-Z0-9,._]` por `\uXXXX`. "Sede Principal" vira `Sede\u0020Principal`, e um
     * `assertStringContainsString` com o nome legível falha mesmo com o dado certo na página.
     *
     * @return list<array{nome: string, latitude: float, longitude: float, raio: int}>
     */
    private function sedesEntreguesATela(object $client): array
    {
        $html = (string) $client->getResponse()->getContent();

        self::assertSame(
            1,
            preg_match("/const SEDES = JSON\\.parse\\('(.*?)'\\);/", $html, $captura),
            'não achei a lista de sedes na tela do ponto'
        );

        // O literal da `|e('js')` só tem `\uXXXX`, `\\` e `\/` como escapes, e os três valem também numa
        // string JSON. Ler o literal como string JSON devolve exatamente o texto que o navegador recebe,
        // inclusive num nome com aspas ou barra invertida (achado de auditoria interna F2).
        $json = json_decode('"' . $captura[1] . '"');
        self::assertIsString($json, 'o literal da lista de sedes deveria ser uma string JS válida');

        $sedes = json_decode($json, true);
        self::assertIsArray($sedes, 'a lista de sedes da tela deveria ser JSON válido');

        return $sedes;
    }

    private function criarTenant(): Tenant
    {
        $em     = static::getContainer()->get(EntityManagerInterface::class);
        $tenant = new Tenant();
        $tenant->setName('Tenant CERCA ' . uniqid());
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
        $user->setEmail('cerca_' . uniqid() . '@test.com');
        $user->setFullName('Colaborador Cerca');
        $user->setRoles(['ROLE_SUPER_ADMIN']);
        $user->setIsActive(true);
        $user->setPassword($hasher->hashPassword($user, 'senha123'));
        $em->persist($user);
        $em->persist(new UserTenant($user, $tenant));
        $em->flush();

        return $user;
    }

    private function vincular(User $user, Tenant $tenant): void
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $em->persist(new UserTenant($user, $tenant));
        $em->flush();
    }

    private function criarSede(Tenant $tenant, string $nome, string $lat, string $lon, int $raio): Sede
    {
        $em   = static::getContainer()->get(EntityManagerInterface::class);
        $sede = new Sede();
        $sede->setNome($nome);
        $sede->setLatitude($lat);
        $sede->setLongitude($lon);
        $sede->setRaioPermitido($raio);
        $sede->setTimezone('America/Sao_Paulo');
        $sede->setTenant($tenant);
        $em->persist($sede);
        $em->flush();

        return $sede;
    }
}
