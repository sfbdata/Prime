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
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * A tela do ponto não mostrava NENHUMA das batidas do dia. O controller já calculava `pontoHoje`
 * com os quatro horários, mas ele só alimentava o relógio em JavaScript: nada era renderizado.
 *
 * Depois de bater, a confirmação verde sumia junto com o recarregamento da página, e a única
 * forma de conferir era rolar até a folha do mês, que no celular fica ABAIXO deste card porque
 * `col-md-8` empilha sob `col-md-4`. Foi o que sustentou o relato do dono: *"parece que registrou,
 * mas quando vai conferir fica registro incompleto"*.
 *
 * Spec: `docs/specs/ponto-batida-nao-se-perde-no-navegador.md` (Frente 3).
 *
 * Este é o lado testável de verdade da frente: o bloco vem do servidor, então PHPUnit lê o HTML e
 * confere o que está escrito. O comportamento de JavaScript da mesma tela é coberto, no que dá,
 * por `App\Tests\Ponto\Unit\BatidaNaoTravaNoGpsTest`.
 */
#[CoversClass(PontoController::class)]
final class BatidasDeHojeNaTelaTest extends JusPrimeWebTestCase
{
    #[TestDox('a tela lista o horário de cada batida já registrada hoje')]
    public function testMostraOsHorariosDasBatidasDeHoje(): void
    {
        $client = static::createClient();
        $tenant = $this->criarTenant();
        $user   = $this->criarUsuario($tenant);

        $this->criarBatida($user, $tenant, 'entrada', '08:12:03');
        $this->criarBatida($user, $tenant, 'repouso', '12:01:44');

        $this->logarComTenant($client, $user, $tenant);
        $client->request('GET', '/ponto/');

        self::assertResponseIsSuccessful();

        $bloco = $this->blocoDeHoje($client);
        self::assertStringContainsString('08:12:03', $bloco, 'a entrada de hoje deveria aparecer na tela');
        self::assertStringContainsString('12:01:44', $bloco, 'o repouso de hoje deveria aparecer na tela');
    }

    #[TestDox('a batida que ainda não aconteceu aparece marcada como não registrada')]
    public function testMarcaOQueAindaNaoFoiRegistrado(): void
    {
        $client = static::createClient();
        $tenant = $this->criarTenant();
        $user   = $this->criarUsuario($tenant);

        $this->criarBatida($user, $tenant, 'entrada', '08:12:03');

        $this->logarComTenant($client, $user, $tenant);
        $crawler = $client->request('GET', '/ponto/');

        self::assertResponseIsSuccessful();

        // O estado vem do atributo, não do texto: rótulo muda, invariante não.
        self::assertCount(
            1,
            $crawler->filter('#batidas-de-hoje .batida-de-hoje[data-tipo="entrada"][data-registrada="1"]'),
            'a entrada batida deveria estar marcada como registrada'
        );
        self::assertCount(
            1,
            $crawler->filter('#batidas-de-hoje .batida-de-hoje[data-tipo="saida"][data-registrada="0"]'),
            'a saída que ainda não aconteceu deveria estar marcada como NÃO registrada'
        );
        self::assertStringContainsString(
            'ainda não registrada',
            $this->blocoDeHoje($client),
            'a pessoa precisa ler que falta bater, não deduzir de um espaço em branco'
        );
    }

    #[TestDox('batida de ontem não entra no bloco de hoje')]
    public function testNaoMisturaBatidaDeOutroDia(): void
    {
        $client = static::createClient();
        $tenant = $this->criarTenant();
        $user   = $this->criarUsuario($tenant);

        $outroDia = $this->outroDiaDoMesmoMes()->setTime(9, 47, 11);
        $this->criarBatidaEm($user, $tenant, 'entrada', $outroDia);

        $this->logarComTenant($client, $user, $tenant);
        $crawler = $client->request('GET', '/ponto/');

        self::assertResponseIsSuccessful();
        self::assertStringNotContainsString(
            '09:47:11',
            $this->blocoDeHoje($client),
            'o bloco é do DIA de hoje: batida de ontem aqui faria a pessoa achar que já bateu'
        );
        self::assertCount(
            1,
            $crawler->filter('#batidas-de-hoje .batida-de-hoje[data-tipo="entrada"][data-registrada="0"]'),
            'sem batida hoje, a entrada tem que aparecer como não registrada'
        );
    }

    #[TestDox('o bloco fica dentro do card de registrar ponto, não perdido na página')]
    public function testBlocoFicaNoCardDoBotao(): void
    {
        $client = static::createClient();
        $tenant = $this->criarTenant();
        $user   = $this->criarUsuario($tenant);

        $this->logarComTenant($client, $user, $tenant);
        $crawler = $client->request('GET', '/ponto/');

        self::assertResponseIsSuccessful();

        // Combinador de filho direto: distingue "está no card do botão" de "existe em algum
        // lugar da página". No celular o card do botão é o que fica no alto; a folha vem depois.
        self::assertCount(
            1,
            $crawler->filter('.card-primary > .card-body > #batidas-de-hoje'),
            'o bloco precisa estar no corpo do card de registrar ponto'
        );
        self::assertCount(
            1,
            $crawler->filter('.card-primary > .card-body > #batida-aviso'),
            'o aviso de resultado precisa estar no mesmo card, onde a pessoa acabou de apertar'
        );
    }

    #[TestDox('duas batidas do mesmo tipo no dia aparecem como duplicata, não como uma só')]
    public function testMostraQuandoHaMaisDeUmaBatidaDoMesmoTipo(): void
    {
        $client = static::createClient();
        $tenant = $this->criarTenant();
        $user   = $this->criarUsuario($tenant);

        // O cenário real: a primeira batida gravou, a resposta não voltou, a pessoa apertou de novo.
        $this->criarBatida($user, $tenant, 'entrada', '08:12:03');
        $this->criarBatida($user, $tenant, 'entrada', '08:12:40');

        $this->logarComTenant($client, $user, $tenant);
        $crawler = $client->request('GET', '/ponto/');

        self::assertResponseIsSuccessful();

        // `pontoHoje` guarda uma só por tipo (a última vence). Sem a contagem, o card mostraria
        // "Entrada 08:12:40" e esconderia a duplicata — no lugar que existe para revelá-la, e para
        // o qual o aviso de "sem confirmação" manda a pessoa olhar antes de bater de novo.
        self::assertCount(
            1,
            $crawler->filter('#batidas-de-hoje .batida-de-hoje[data-tipo="entrada"][data-quantas="2"]'),
            'o card precisa dizer que existem DUAS entradas hoje'
        );
        self::assertStringContainsString(
            '2 registros',
            $this->blocoDeHoje($client),
            'a duplicata tem que estar legível, não só num atributo'
        );
    }

    /** Um dia do mês corrente que não é hoje: 'ontem' no dia 1º cai fora da competência exibida. */
    private function outroDiaDoMesmoMes(): \DateTimeImmutable
    {
        $hoje = new \DateTimeImmutable('today');

        return (int) $hoje->format('d') === 1 ? $hoje->modify('+1 day') : $hoje->modify('-1 day');
    }

    private function blocoDeHoje(object $client): string
    {
        $html  = (string) $client->getResponse()->getContent();
        $abre  = strpos($html, 'id="batidas-de-hoje"');
        self::assertIsInt($abre, 'o bloco de batidas de hoje deveria existir na tela');

        $fecha = strpos($html, 'Justificar Falta', $abre);
        self::assertIsInt($fecha, 'não achei o fim do bloco de batidas de hoje');

        return substr($html, $abre, $fecha - $abre);
    }

    private function criarTenant(): Tenant
    {
        $em     = static::getContainer()->get(EntityManagerInterface::class);
        $tenant = new Tenant();
        $tenant->setName('Tenant BATIDAS HOJE ' . uniqid());
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
        $user->setEmail('batidas_hoje_' . uniqid() . '@test.com');
        $user->setFullName('Colaborador Batidas Hoje');
        $user->setRoles(['ROLE_SUPER_ADMIN']);
        $user->setIsActive(true);
        $user->setPassword($hasher->hashPassword($user, 'senha123'));
        $em->persist($user);
        $em->persist(new UserTenant($user, $tenant));
        $em->flush();

        return $user;
    }

    private function criarBatida(User $user, Tenant $tenant, string $tipo, string $hora): RegistroPonto
    {
        [$h, $m, $s] = array_map('intval', explode(':', $hora));

        return $this->criarBatidaEm($user, $tenant, $tipo, (new \DateTimeImmutable('today'))->setTime($h, $m, $s));
    }

    private function criarBatidaEm(User $user, Tenant $tenant, string $tipo, \DateTimeImmutable $quando): RegistroPonto
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);

        $registro = new RegistroPonto();
        $registro->setUser($user);
        $registro->setTenant($tenant);
        $registro->setTipo($tipo);
        $registro->setDataHora(\DateTime::createFromImmutable($quando));
        $em->persist($registro);
        $em->flush();

        return $registro;
    }
}
