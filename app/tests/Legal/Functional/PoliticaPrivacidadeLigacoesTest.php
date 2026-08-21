<?php

declare(strict_types=1);

namespace App\Tests\Legal\Functional;

use App\Entity\Auth\Invitation;
use App\Entity\Auth\User;
use App\Entity\Auth\UserTenant;
use App\Entity\Tenant\Tenant;
use App\Legal\Controller\PoliticaPrivacidadeController;
use App\Tests\Functional\JusPrimeWebTestCase;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * A página existir não adianta nada se ninguém chegar nela.
 *
 * Estes testes cobrem os pontos de entrada combinados, um por método. O rodapé do login é
 * o quinto e mora em {@see \App\Tests\Auth\Functional\LoginTelaTest}, junto com o resto
 * daquela tela.
 *
 * Duas distinções que os testes travam de propósito:
 *
 * 1. **ciência, não aceite.** No cadastro e no convite a Política aparece FORA do "Li e
 *    aceito", porque o item 1.3 dela diz que o cadastro já caracteriza ciência. Colocar
 *    dentro criaria um segundo aceite que ninguém decidiu colher — e mudaria o que o
 *    usuário está assinando;
 * 2. **o link da tela de aceite precisa sair de lá.** O `TermoAceiteListener` devolve para
 *    `termo_aceite` toda rota fora da lista branca: sem as rotas da Política nessa lista, o
 *    link viraria um beco sem saída que nenhum teste de "o link existe" pegaria.
 */
#[CoversClass(PoliticaPrivacidadeController::class)]
final class PoliticaPrivacidadeLigacoesTest extends JusPrimeWebTestCase
{
    private const SELETOR = 'a[href="/politica-de-privacidade"]';

    #[TestDox('O cadastro público oferece a Política — fora do "Li e aceito"')]
    public function testCadastroPublicoLevaAPolitica(): void
    {
        $client  = static::createClient();
        $crawler = $client->request('GET', '/cadastro');

        self::assertResponseIsSuccessful();
        self::assertCount(1, $crawler->filter(self::SELETOR));

        // O link não pode estar dentro do bloco do aceite: lá dentro ele vira parte do que
        // o usuário declara aceitar, e a decisão foi que a Política é só leitura.
        self::assertCount(0, $crawler->filter('.form-check ' . self::SELETOR));
    }

    #[TestDox('A tela do convite oferece a Política — também fora do aceite')]
    public function testConviteLevaAPolitica(): void
    {
        $client = static::createClient();
        $em     = static::getContainer()->get(EntityManagerInterface::class);

        $convite = new Invitation(
            email: 'convidado_' . uniqid() . '@adv.com',
            token: bin2hex(random_bytes(16)),
            type: 'plataforma',
            expiresAt: new \DateTimeImmutable('+7 days'),
        );
        $em->persist($convite);
        $em->flush();

        $crawler = $client->request('GET', '/convite/' . $convite->getToken());

        self::assertResponseIsSuccessful();
        self::assertCount(1, $crawler->filter(self::SELETOR));
        self::assertCount(0, $crawler->filter('.form-check ' . self::SELETOR));
    }

    #[TestDox('Quem está sendo parado para aceitar os Termos consegue ler a Política e sair de lá')]
    public function testTelaDeAceiteLevaAPoliticaESaiDeLa(): void
    {
        $client = static::createClient();
        // Usuário COMUM, de propósito. A primeira versão deste teste usava ROLE_SUPER_ADMIN
        // "só para atravessar o TenantContextValidatorListener" — e foi exatamente esse atalho
        // que escondeu um link quebrado: o super admin é o único que o listener deixa passar
        // sem escritório. Sem termos aceitos, é o portão do aceite que barra (prioridade 7,
        // antes do de tenant), e `termo_aceite` já está liberado nos dois listeners.
        $client->loginUser($this->criarUsuario());

        // Controle: sem isto o teste passaria mesmo que portão nenhum existisse.
        $client->request('GET', '/perfil');
        self::assertResponseRedirects('/termos/aceitar');

        $crawler = $client->request('GET', '/termos/aceitar');
        self::assertResponseIsSuccessful();
        self::assertCount(1, $crawler->filter(self::SELETOR));

        // A prova que importa: seguir o link não devolve para a tela de aceite.
        $client->request('GET', '/politica-de-privacidade');
        self::assertResponseIsSuccessful();
        self::assertSelectorExists('h1.pp-titulo');
    }

    #[TestDox('Usuário comum SEM escritório selecionado consegue abrir a Política')]
    public function testSemEscritorioSelecionadoAindaAbreAPolitica(): void
    {
        $client = static::createClient();
        $client->loginUser($this->criarUsuario());
        // Termos aceitos para isolar o portão de TENANT: é ele que está sob teste aqui.
        $this->marcarTermosAceitos($client);

        // Controle: este usuário é de fato barrado nas rotas normais. Sem esta asserção o
        // teste abaixo passaria mesmo com o listener desligado, e não provaria nada.
        $client->request('GET', '/perfil');
        self::assertResponseRedirects('/escritorio/selecionar');

        // E é este o caso que faltava: quem está parado em /escritorio/selecionar vê o link
        // no menu e precisa conseguir LER a Política, não voltar para a mesma tela.
        $client->request('GET', '/politica-de-privacidade');
        self::assertResponseIsSuccessful();
        self::assertSelectorExists('h1.pp-titulo');

        $client->request('GET', '/politica-de-privacidade.pdf');
        self::assertResponseIsSuccessful();
    }

    #[TestDox('Dentro do sistema, a Política fica no menu do usuário')]
    public function testMenuDoUsuarioLevaAPolitica(): void
    {
        $client = static::createClient();
        $em     = static::getContainer()->get(EntityManagerInterface::class);

        $tenant = new Tenant();
        $tenant->setName('Escritório Política ' . uniqid());
        $em->persist($tenant);

        $user = $this->criarUsuario();
        $em->persist(new UserTenant($user, $tenant));
        $em->flush();

        $this->logarComTenant($client, $user, $tenant);

        $crawler = $client->request('GET', '/perfil');

        self::assertResponseIsSuccessful();
        self::assertCount(1, $crawler->filter('.usuario-dropdown ' . self::SELETOR));
    }

    /** @param list<string> $papeis */
    private function criarUsuario(array $papeis = ['ROLE_USER']): User
    {
        $em     = static::getContainer()->get(EntityManagerInterface::class);
        $hasher = static::getContainer()->get(UserPasswordHasherInterface::class);

        $user = new User();
        $user->setEmail('politica_' . uniqid() . '@adv.com');
        $user->setFullName('Dra. Privacidade');
        $user->setRoles($papeis);
        $user->setIsActive(true);
        $user->setPassword($hasher->hashPassword($user, 'senha-de-teste-123'));

        $em->persist($user);
        $em->flush();

        return $user;
    }
}
