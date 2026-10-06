<?php

declare(strict_types=1);

namespace App\Tests\Pasta\Functional;

use App\Controller\PastaController;
use App\Entity\Auth\User;
use App\Entity\Auth\UserTenant;
use App\Entity\Notificacao;
use App\Entity\Permission\ResourceAccess;
use App\Entity\Tenant\Tenant;
use App\Pasta\Entity\Pasta;
use App\Pasta\Entity\PastaMensagem;
use App\Pasta\UseCase\EnviarMensagemPastaUseCase;
use App\Tests\Functional\JusPrimeWebTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\Security\Csrf\TokenStorage\ClearableTokenStorageInterface;

/**
 * Item 20: responder no Registro da pasta notifica (sino) o autor do comentário respondido.
 * Pelo endpoint real `POST /pasta/{id}/mensagem` com `resposta_a`.
 */
#[CoversClass(PastaController::class)]
#[CoversClass(EnviarMensagemPastaUseCase::class)]
#[Group('pasta')]
final class PastaRespostaNotificaAutorTest extends JusPrimeWebTestCase
{
    use CriaFixturesPushDaPastaTrait;

    private function membro(Tenant $tenant, string $nome): User
    {
        $user = new User();
        $user->setEmail('resp_' . uniqid() . '@test.com');
        $user->setFullName($nome);
        $user->setRoles(['ROLE_SUPER_ADMIN']);
        $user->setIsActive(true);
        $this->em()->persist($user);
        $this->em()->persist(new UserTenant($user, $tenant));
        $this->em()->flush();

        return $user;
    }

    private function comentario(Pasta $pasta, User $autor, Tenant $tenant, string $texto = 'Ligar para o cliente'): PastaMensagem
    {
        $msg = (new PastaMensagem())->setPasta($pasta)->setAutor($autor)->setTenant($tenant)->setConteudo($texto);
        $this->em()->persist($msg);
        $this->em()->flush();

        return $msg;
    }

    private function instalarCsrfStorage(): void
    {
        $storage = new class implements ClearableTokenStorageInterface {
            public function getToken(string $tokenId): string
            {
                return 'TOKEN_' . $tokenId;
            }

            public function setToken(string $tokenId, string $token): void {}

            public function removeToken(string $tokenId): ?string
            {
                return null;
            }

            public function hasToken(string $tokenId): bool
            {
                return true;
            }

            public function clear(): void {}
        };

        static::getContainer()->set('security.csrf.token_storage', $storage);
    }

    private function responder(KernelBrowser $client, Pasta $pasta, PastaMensagem $alvo, string $conteudo = 'Já liguei'): void
    {
        $client->request('POST', "/pasta/{$pasta->getId()}/mensagem", [
            '_token'     => 'TOKEN_pasta_mensagem_' . $pasta->getId(),
            'conteudo'   => $conteudo,
            'resposta_a' => (string) $alvo->getId(),
        ]);
    }

    /** @return Notificacao[] */
    private function notificacoesDe(User $usuario): array
    {
        return $this->em()->createQuery(
            'SELECT n FROM ' . Notificacao::class . ' n WHERE n.usuario = :u AND n.tipo = :t ORDER BY n.id ASC'
        )
            ->setParameter('u', $usuario)
            ->setParameter('t', Notificacao::TIPO_PASTA_RESPOSTA_REGISTRO)
            ->getResult();
    }

    #[TestDox('Responder o comentário de outra pessoa notifica o autor, no escritório da pasta, com link para o comentário')]
    public function testNotificaOAutorDoComentario(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $this->instalarCsrfStorage();
        $tenant = $this->criarTenant();
        $dono   = $this->membro($tenant, 'Bruno Lima');
        $quem   = $this->membro($tenant, 'Ana Paula Souza');
        $pasta  = $this->criarPasta($tenant);
        $raiz   = $this->comentario($pasta, $dono, $tenant);

        $this->logarComTenant($client, $quem, $tenant);
        $this->responder($client, $pasta, $raiz, '<p>Já liguei, ele <strong>confirmou</strong></p>');
        self::assertResponseStatusCodeSame(201);

        $notificacoes = $this->notificacoesDe($dono);
        self::assertCount(1, $notificacoes);
        $n = $notificacoes[0];
        self::assertSame('Ana respondeu seu comentário', $n->getTitulo());
        self::assertStringStartsWith('"Já liguei, ele confirmou" · em Dados da Pasta ' . $pasta->getNup() . ' · ', (string) $n->getMensagem());
        self::assertStringEndsWith('Seu comentário: "Ligar para o cliente"', (string) $n->getMensagem());
        self::assertSame($tenant->getId(), $n->getTenant()?->getId());
        self::assertFalse($n->isLida());
        self::assertSame('/pasta/' . $pasta->getId() . '#pasta-msg-' . $raiz->getId(), $n->getUrl());
        self::assertSame('bi-person-check text-warning', $n->getIcone());
        self::assertCount(0, $this->notificacoesDe($quem), 'quem respondeu não recebe nada');

        // O link abre a pasta para o destinatário, e a âncora existe na página.
        $this->logarComTenant($client, $dono, $tenant);
        $crawler = $client->request('GET', (string) strtok((string) $n->getUrl(), '#'));
        self::assertResponseIsSuccessful();
        self::assertCount(1, $crawler->filter('#pasta-msg-' . $raiz->getId()));
    }

    #[TestDox('Responder o próprio comentário não gera notificação')]
    public function testNaoNotificaASiMesmo(): void
    {
        $client = static::createClient();
        $this->instalarCsrfStorage();
        $tenant = $this->criarTenant();
        $eu     = $this->membro($tenant, 'Ana');
        $pasta  = $this->criarPasta($tenant);
        $raiz   = $this->comentario($pasta, $eu, $tenant);

        $this->logarComTenant($client, $eu, $tenant);
        $this->responder($client, $pasta, $raiz);

        self::assertResponseStatusCodeSame(201);
        self::assertCount(0, $this->notificacoesDe($eu));
    }

    #[TestDox('Autor do comentário sem acesso à pasta não é notificado; com acesso por ResourceAccess, é')]
    public function testAutorSemAcessoNaoENotificado(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $this->instalarCsrfStorage();
        $tenant   = $this->criarTenant();
        $quem     = $this->membro($tenant, 'Ana');
        $semAcesso = $this->criarUsuarioSemNenhumaPermissao($tenant);
        $comAcesso = $this->criarUsuarioSemNenhumaPermissao($tenant);
        $pasta    = $this->criarPasta($tenant);
        $raizSem  = $this->comentario($pasta, $semAcesso, $tenant);
        $raizCom  = $this->comentario($pasta, $comAcesso, $tenant);

        // Controle: o mesmo perfil, com acesso específico a ESTA pasta, recebe.
        $ra = (new ResourceAccess())
            ->setUser($comAcesso)
            ->setTenant($tenant)
            ->setResourceType(ResourceAccess::RESOURCE_PASTA)
            ->setResourceId((int) $pasta->getId())
            ->setCanView(true);
        $this->em()->persist($ra);
        $this->em()->flush();

        $this->logarComTenant($client, $quem, $tenant);
        $this->responder($client, $pasta, $raizSem);
        self::assertResponseStatusCodeSame(201);
        $this->responder($client, $pasta, $raizCom);
        self::assertResponseStatusCodeSame(201);

        self::assertCount(0, $this->notificacoesDe($semAcesso));
        self::assertCount(1, $this->notificacoesDe($comAcesso));
    }

    #[TestDox('Ex-colaborador (vínculo inativo), mesmo super-admin, não é notificado')]
    public function testExColaboradorNaoENotificado(): void
    {
        $client = static::createClient();
        $this->instalarCsrfStorage();
        $tenant = $this->criarTenant();
        $saiu   = $this->membro($tenant, 'Bruno');
        $quem   = $this->membro($tenant, 'Ana');
        $pasta  = $this->criarPasta($tenant);
        $raiz   = $this->comentario($pasta, $saiu, $tenant);

        $vinculo = $this->em()->getRepository(UserTenant::class)->findOneBy(['user' => $saiu, 'tenant' => $tenant]);
        (new \ReflectionProperty(UserTenant::class, 'isActive'))->setValue($vinculo, false);
        $this->em()->flush();

        $this->logarComTenant($client, $quem, $tenant);
        $this->responder($client, $pasta, $raiz);

        self::assertResponseStatusCodeSame(201);
        self::assertCount(0, $this->notificacoesDe($saiu));
    }

    #[TestDox('Comentário de outro escritório: a resposta é recusada e o autor de lá nunca é notificado')]
    public function testOutroTenantNuncaNotifica(): void
    {
        $client  = static::createClient();
        $this->instalarCsrfStorage();
        $tenantA = $this->criarTenant();
        $tenantB = $this->criarTenant();
        $userA   = $this->membro($tenantA, 'Ana');
        $userB   = $this->membro($tenantB, 'Bruno');
        $pastaA  = $this->criarPasta($tenantA);
        $pastaB  = $this->criarPasta($tenantB);
        $msgB    = $this->comentario($pastaB, $userB, $tenantB);

        $this->logarComTenant($client, $userA, $tenantA);
        $this->responder($client, $pastaA, $msgB);

        self::assertResponseStatusCodeSame(422);
        self::assertCount(0, $this->notificacoesDe($userB));
    }

    #[TestDox('Segunda resposta da mesma pessoa ao mesmo comentário não duplica enquanto a anterior não foi lida')]
    public function testSemDuplicataEnquantoNaoLida(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $this->instalarCsrfStorage();
        $tenant = $this->criarTenant();
        $dono   = $this->membro($tenant, 'Bruno');
        $quem   = $this->membro($tenant, 'Ana');
        $outra  = $this->membro($tenant, 'Carla');
        $pasta  = $this->criarPasta($tenant);
        $raiz   = $this->comentario($pasta, $dono, $tenant);

        $this->logarComTenant($client, $quem, $tenant);
        $this->responder($client, $pasta, $raiz, 'Primeira');
        $this->responder($client, $pasta, $raiz, 'Segunda');
        self::assertResponseStatusCodeSame(201);
        self::assertCount(1, $this->notificacoesDe($dono));

        // Outra pessoa respondendo é outra notificação.
        $this->logarComTenant($client, $outra, $tenant);
        $this->responder($client, $pasta, $raiz, 'Da Carla');
        self::assertCount(2, $this->notificacoesDe($dono));

        // Lida a da Ana, a próxima resposta dela volta a notificar.
        $this->notificacoesDe($dono)[0]->setLida(true);
        $this->em()->flush();
        $this->logarComTenant($client, $quem, $tenant);
        $this->responder($client, $pasta, $raiz, 'Terceira');
        self::assertCount(3, $this->notificacoesDe($dono));
    }
}
