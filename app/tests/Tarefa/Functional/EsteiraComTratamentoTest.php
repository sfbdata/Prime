<?php

declare(strict_types=1);

namespace App\Tests\Tarefa\Functional;

use App\Controller\TarefaController;
use App\Entity\Auth\User;
use App\Entity\Auth\UserTenant;
use App\Entity\Tenant\Tenant;
use App\Entity\Tenant\TenantRole;
use App\Profile\Entity\UserProfile;
use App\Profile\Enum\Genero;
use App\Tarefa\Entity\CaixaDaEsteira;
use App\Tarefa\Enum\EtapaDaPasta;
use App\Tests\Functional\JusPrimeWebTestCase;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestDox;

/**
 * "Dr./Dra." na lista de avisados da esteira de Minhas Metas (desenho 04 de 22/10, trat(); spec genero-no-cadastro,
 * regra 4): advogado (OAB no cadastro) com gênero declarado ganha o título; os demais aparecem só com o nome.
 */
#[CoversClass(TarefaController::class)]
final class EsteiraComTratamentoTest extends JusPrimeWebTestCase
{
    #[TestDox('Avisados: advogada "Dra. Nome", advogado "Dr. Nome"; sem OAB, sem gênero ou com o legado, só o nome; a dica segue igual')]
    public function testTratamentoDosAvisados(): void
    {
        $client = static::createClient();
        $em     = static::getContainer()->get(EntityManagerInterface::class);
        $tenant = (new Tenant())->setName('Escritório Esteira ' . uniqid());
        $em->persist($tenant);
        $eu = $this->pessoa($em, $tenant, 'Quem Olha', null, Genero::Feminino);

        $caixa = new CaixaDaEsteira($tenant, EtapaDaPasta::Juridico);
        $caixa->configurar(null, null, [
            $this->pessoa($em, $tenant, 'Ana Maria Lima', '12345', Genero::Feminino),
            $this->pessoa($em, $tenant, 'Bruno de Souza', '54321', Genero::Masculino),
            $this->pessoa($em, $tenant, 'Carla Dias', null, Genero::Feminino),
            $this->pessoa($em, $tenant, 'Davi Rocha', '22222', null),
            $this->pessoa($em, $tenant, 'Eduardo Melo', '33333', Genero::NaoInformado),
        ]);
        $em->persist($caixa);
        $em->flush();
        $this->logarComTenant($client, $eu, $tenant);

        $crawler = $client->request('GET', '/tarefas/minhas');

        self::assertResponseIsSuccessful();
        $avisados = $crawler->filter('.mm-caixa-avisados');
        self::assertCount(1, $avisados);
        $esperado = 'Dra. Ana Lima, Dr. Bruno Souza, Carla Dias, Davi Rocha, Eduardo Melo';
        self::assertSame($esperado, trim($avisados->filter('span')->last()->text()));
        self::assertSame('Avisados quando chega demanda: ' . $esperado, $avisados->attr('title'));
    }

    /**
     * Um avisado por caso de gênero, com o arranjo do desenho 04 (dc L61) preso por filho direto: o nome fica no
     * `span` dentro de `.mm-caixa-avisados`, que mora no link da caixa, dentro da célula da esteira.
     *
     * @return iterable<string, array{0: ?Genero, 1: string}>
     */
    public static function generos(): iterable
    {
        yield 'masculino' => [Genero::Masculino, 'Dr. Bruno Souza'];
        yield 'feminino'  => [Genero::Feminino, 'Dra. Bruna Souza'];
        yield 'vazio'     => [null, 'Bruno Souza'];
    }

    #[DataProvider('generos')]
    #[TestDox('Avisado com OAB pelo gênero informado: aparece como "$esperado" no lugar do desenho')]
    public function testTratamentoPeloGeneroNoArranjoDoDesenho(?Genero $genero, string $esperado): void
    {
        $client = static::createClient();
        $em     = static::getContainer()->get(EntityManagerInterface::class);
        $tenant = (new Tenant())->setName('Escritório Esteira ' . uniqid());
        $em->persist($tenant);
        $eu = $this->pessoa($em, $tenant, 'Quem Olha', null, Genero::Feminino);

        $nome  = $genero === Genero::Feminino ? 'Bruna de Souza' : 'Bruno de Souza';
        $caixa = new CaixaDaEsteira($tenant, EtapaDaPasta::Juridico);
        $caixa->configurar(null, null, [$this->pessoa($em, $tenant, $nome, '12345', $genero)]);
        $em->persist($caixa);
        $em->flush();
        $this->logarComTenant($client, $eu, $tenant);

        $crawler = $client->request('GET', '/tarefas/minhas');

        self::assertResponseIsSuccessful();
        $nomes = $crawler->filter(
            '.mm-esteira > .mm-esteira-caixas > .mm-caixa-celula > .mm-caixa > a.mm-caixa-link > .mm-caixa-avisados > span',
        );
        self::assertCount(1, $nomes);
        self::assertSame($esperado, trim($nomes->text()));
        self::assertSame(
            'Avisados quando chega demanda: ' . $esperado,
            $crawler->filter('a.mm-caixa-link > .mm-caixa-avisados')->attr('title'),
        );
        // O sino vem antes do nome, também como filho direto.
        self::assertCount(1, $crawler->filter('.mm-caixa-avisados > i.bi-bell:first-child'));
    }

    private function pessoa(EntityManagerInterface $em, Tenant $tenant, string $nome, ?string $oab, ?Genero $genero): User
    {
        $user = (new User())->setEmail(uniqid('esteira_') . '@test.com')->setFullName($nome)->setRoles(['ROLE_USER'])->setPassword('x');
        $user->setIsActive(true);
        $user->setOabNumero($oab);
        $em->persist($user);
        if ($genero !== null) {
            $perfil = new UserProfile($user);
            $perfil->setGenero($genero);
            $user->setProfile($perfil);
            $em->persist($perfil);
        }
        // Papel isSystem: acesso a Metas sem configurar permissões.
        $papel = (new TenantRole())->setTenant($tenant)->setName('Admin ' . uniqid())->setIsSystem(true);
        $em->persist($papel);
        $vinculo = new UserTenant($user, $tenant);
        $vinculo->setTenantRole($papel);
        $em->persist($vinculo);

        return $user;
    }
}
