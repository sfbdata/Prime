<?php

declare(strict_types=1);

namespace App\Tests\Ponto\Functional;

use App\Entity\Auth\User;
use App\Entity\Auth\UserTenant;
use App\Entity\Tenant\Tenant;
use App\Profile\EventListener\IdiomaDaRequisicaoListener;
use App\Tests\Functional\JusPrimeWebTestCase;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Security\Csrf\TokenStorage\ClearableTokenStorageInterface;

/**
 * Spec idioma-do-sistema §30: o texto fixo que o controller do Ponto devolve sai no idioma da pessoa (o da sessão,
 * posto pelo `IdiomaDaRequisicaoListener`). Uma resposta JSON (home office) e um flash (abonar da equipe), em en e em pt_BR.
 */
final class TextoDoPhpDoPontoEmInglesTest extends JusPrimeWebTestCase
{
    /** @return iterable<string, array{0: string, 1: string}> idioma da sessão => texto esperado */
    public static function idiomas(): iterable
    {
        yield 'en' => ['en', 'The start date of the validity period cannot be after the end date.'];
        yield 'pt-BR' => ['pt-BR', 'A data inicial da vigência não pode ser maior que a final.'];
    }

    #[DataProvider('idiomas')]
    #[TestDox('JSON de erro do home office no idioma da pessoa ($idioma)')]
    public function testJsonNoIdiomaDaPessoa(string $idioma, string $esperado): void
    {
        $client = $this->clientePreparado();
        $tenant = $this->criarTenant();
        $admin = $this->criarUsuario($tenant, ['ROLE_SUPER_ADMIN']);
        $alvo = $this->criarUsuario($tenant, ['ROLE_USER']);
        $this->logarNoIdioma($client, $admin, $tenant, $idioma);

        $client->request(
            'POST',
            sprintf('/tenant/%d/user/%d/home-office', $tenant->getId(), $alvo->getId()),
            [],
            [],
            ['CONTENT_TYPE' => 'application/json', 'HTTP_X_CSRF_TOKEN' => 'TOKEN_ajax'],
            (string) json_encode(['diasSemana' => [1], 'vigenciaInicio' => '2026-07-31', 'vigenciaFim' => '2026-07-01']),
        );

        self::assertResponseStatusCodeSame(422);
        $json = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertSame($esperado, $json['erro']);
    }

    /** @return iterable<string, array{0: string, 1: string}> */
    public static function idiomasDoFlash(): iterable
    {
        yield 'en' => ['en', 'Select at least one justification.'];
        yield 'pt-BR' => ['pt-BR', 'Selecione ao menos uma justificativa.'];
    }

    #[DataProvider('idiomasDoFlash')]
    #[TestDox('Flash do abonar da equipe no idioma da pessoa ($idioma)')]
    public function testFlashNoIdiomaDaPessoa(string $idioma, string $esperado): void
    {
        $client = $this->clientePreparado();
        $tenant = $this->criarTenant();
        $admin = $this->criarUsuario($tenant, ['ROLE_SUPER_ADMIN']);
        $this->logarNoIdioma($client, $admin, $tenant, $idioma);

        $client->request('POST', '/ponto/equipe/abonar', ['_token' => 'TOKEN_ponto_equipe_abonar']);

        self::assertResponseRedirects();
        self::assertSame([$esperado], $client->getRequest()->getSession()->getFlashBag()->peek('warning'));
    }

    /** @return iterable<string, array{0: string, 1: string, 2: string}> idioma => [mensagem do 404, mensagem do campo] */
    public static function idiomasDaRecusa(): iterable
    {
        yield 'en' => ['en', 'Holiday not found.', 'Enter the date and the name of the holiday.'];
        yield 'pt-BR' => ['pt-BR', 'Feriado não encontrado.', 'Informe a data e o nome do feriado.'];
    }

    #[DataProvider('idiomasDaRecusa')]
    #[TestDox('JSON de recusa da exceção de domínio (feriado inexistente e feriado sem nome) no idioma da pessoa ($idioma)')]
    public function testJsonDaRecusaDeDominioNoIdiomaDaPessoa(string $idioma, string $naoEncontrado, string $faltaDado): void
    {
        $client = $this->clientePreparado();
        $tenant = $this->criarTenant();
        $admin = $this->criarUsuario($tenant, ['ROLE_SUPER_ADMIN']);
        $this->logarNoIdioma($client, $admin, $tenant, $idioma);
        $cabecalhos = ['CONTENT_TYPE' => 'application/x-www-form-urlencoded', 'HTTP_X_CSRF_TOKEN' => 'TOKEN_ajax'];

        $client->request('POST', '/ponto/configuracoes/feriados/999999999/remover', [], [], $cabecalhos);
        self::assertResponseStatusCodeSame(404);
        $json = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertSame($naoEncontrado, $json['mensagem']);

        $client->request('POST', '/ponto/configuracoes/feriados', ['data' => '', 'nome' => ''], [], $cabecalhos);
        self::assertResponseStatusCodeSame(422);
        $json = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertSame($faltaDado, $json['mensagem']);
        self::assertSame($faltaDado, array_values($json['erros'])[0]);
    }

    /** @return iterable<string, array{0: string, 1: list<string>}> idioma => [erros por campo da sede, em ordem de texto] */
    public static function idiomasDaSedeInvalida(): iterable
    {
        yield 'en' => ['en', [
            'Allowed radius must be between 10 and 500 meters.',
            'Enter the office name.',
            'Latitude must be between -90 and 90.',
            'Longitude must be a number.',
        ]];
        yield 'pt-BR' => ['pt-BR', [
            'Informe o nome da sede.',
            'Latitude deve estar entre -90 e 90.',
            'Longitude deve ser um número.',
            'Raio permitido deve estar entre 10 e 500 metros.',
        ]];
    }

    /** @param list<string> $esperados */
    #[DataProvider('idiomasDaSedeInvalida')]
    #[TestDox('Sede inválida: os erros por campo da RestricoesDaSede (passados pelo validador) saem no idioma da pessoa ($idioma), sem 500')]
    public function testErrosDaSedeNoIdiomaDaPessoa(string $idioma, array $esperados): void
    {
        $client = $this->clientePreparado();
        $tenant = $this->criarTenant();
        $admin = $this->criarUsuario($tenant, ['ROLE_SUPER_ADMIN']);
        $this->logarNoIdioma($client, $admin, $tenant, $idioma);

        $client->request('POST', '/ponto/configuracoes/sedes', ['nome' => '', 'latitude' => '100', 'longitude' => 'abc', 'raio' => '5'], [], [
            'CONTENT_TYPE' => 'application/x-www-form-urlencoded',
            'HTTP_X_CSRF_TOKEN' => 'TOKEN_ajax',
        ]);

        self::assertResponseStatusCodeSame(422);
        $json = json_decode((string) $client->getResponse()->getContent(), true);
        $erros = array_values($json['erros']);
        sort($erros);
        self::assertSame($esperados, $erros);
        self::assertSame(implode(' ', array_values($json['erros'])), $json['mensagem']);
    }

    /** @return iterable<string, array{0: string, 1: string}> */
    public static function idiomasDoFlashDeHorasPagas(): iterable
    {
        yield 'en' => ['en', 'The period cannot be in the future.'];
        yield 'pt-BR' => ['pt-BR', 'A competência não pode ser futura.'];
    }

    #[DataProvider('idiomasDoFlashDeHorasPagas')]
    #[TestDox('Flash da recusa de horas pagas (competência futura) no idioma da pessoa ($idioma)')]
    public function testFlashDeHorasPagasInvalidaNoIdiomaDaPessoa(string $idioma, string $esperado): void
    {
        $client = $this->clientePreparado();
        $tenant = $this->criarTenant();
        $admin = $this->criarUsuario($tenant, ['ROLE_SUPER_ADMIN']);
        $colaborador = $this->criarUsuario($tenant, ['ROLE_USER']);
        $this->logarNoIdioma($client, $admin, $tenant, $idioma);
        $futuro = new \DateTimeImmutable('first day of next month');

        $client->request('POST', sprintf('/tenant/%d/users/%d/horas-pagas', $tenant->getId(), $colaborador->getId()), [
            '_token' => 'TOKEN_horas_pagas_lancar_' . $colaborador->getId(),
            'lancamento_horas_pagas' => [
                'ano' => (int) $futuro->format('Y'),
                'mes' => (int) $futuro->format('n'),
                'operacao' => 'descontar',
                'horas' => 1,
                'minutos' => 0,
                'motivo' => 'Horas pagas na folha',
            ],
        ]);

        self::assertResponseRedirects();
        self::assertSame([$esperado], $client->getRequest()->getSession()->getFlashBag()->peek('error'));
    }

    private function logarNoIdioma(KernelBrowser $client, User $user, Tenant $tenant, string $idioma): void
    {
        $this->logarComTenant($client, $user, $tenant);
        $sessao = $client->getSession();
        $sessao->set(IdiomaDaRequisicaoListener::CHAVE_DA_SESSAO, ['usuario' => $user->getId(), 'idioma' => $idioma]);
        $sessao->save();
    }

    private function clientePreparado(): KernelBrowser
    {
        $client = static::createClient();
        $client->disableReboot();
        $storage = new class () implements ClearableTokenStorageInterface {
            public function getToken(string $tokenId): string
            {
                return 'TOKEN_' . $tokenId;
            }
            public function setToken(string $tokenId, string $token): void
            {
            }
            public function removeToken(string $tokenId): ?string
            {
                return null;
            }
            public function hasToken(string $tokenId): bool
            {
                return true;
            }
            public function clear(): void
            {
            }
        };
        static::getContainer()->set('security.csrf.token_storage', $storage);

        return $client;
    }

    private function criarTenant(): Tenant
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $tenant = new Tenant();
        $tenant->setName('Tenant idioma ponto ' . uniqid());
        $em->persist($tenant);
        $em->flush();

        return $tenant;
    }

    /** @param string[] $roles */
    private function criarUsuario(Tenant $tenant, array $roles): User
    {
        $container = static::getContainer();
        $em = $container->get(EntityManagerInterface::class);
        $user = new User();
        $user->setEmail('idioma_ponto_' . uniqid() . '@test.com');
        $user->setFullName('User Idioma');
        $user->setRoles($roles);
        $user->setIsActive(true);
        $user->setPassword($container->get(UserPasswordHasherInterface::class)->hashPassword($user, 'senha123'));
        $em->persist($user);
        $em->persist(new UserTenant($user, $tenant));
        $em->flush();

        return $user;
    }
}
