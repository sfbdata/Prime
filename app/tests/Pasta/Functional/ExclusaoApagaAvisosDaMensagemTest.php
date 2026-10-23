<?php

declare(strict_types=1);

namespace App\Tests\Pasta\Functional;

use Doctrine\ORM\EntityManagerInterface;
use App\Tests\Tenant\Support\DesligaEdicaoSemPrazo;
use App\Controller\PastaController;
use App\Entity\Auth\User;
use App\Entity\Auth\UserTenant;
use App\Entity\Notificacao;
use App\Entity\Tenant\Tenant;
use App\Pasta\UseCase\ExcluirMensagemPastaUseCase;
use App\Processo\Controller\NotaTecnicaController;
use App\Processo\UseCase\ExcluirNotaTecnicaUseCase;
use App\Repository\NotificacaoRepository;
use App\Tests\Functional\JusPrimeWebTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\Security\Csrf\TokenStorage\ClearableTokenStorageInterface;

/**
 * NOT-01 — excluir a mensagem do Registro (ou a nota técnica) apaga os avisos de menção/resposta
 * que ela gerou: senão a prévia do texto apagado ficava no sino e na Timeline de quem recebeu.
 *
 * Os avisos que somem são os GERADOS DE VERDADE pelo envio (menção e resposta pela rota real).
 * O que tem de ficar é provado ao lado, no mesmo pedido: o aviso de OUTRA mensagem, o de um link
 * que só COMEÇA igual (`#pasta-msg-<id>9`, o caso 12 × 123) e o de OUTRO escritório com o mesmo
 * link. Sem os três, o teste passaria com um DELETE que apagasse demais.
 */
#[CoversClass(PastaController::class)]
#[CoversClass(NotaTecnicaController::class)]
#[CoversClass(ExcluirMensagemPastaUseCase::class)]
#[CoversClass(ExcluirNotaTecnicaUseCase::class)]
#[CoversClass(NotificacaoRepository::class)]
#[Group('pasta')]
final class ExclusaoApagaAvisosDaMensagemTest extends JusPrimeWebTestCase
{
    use CriaFixturesPushDaPastaTrait;

    private function membro(Tenant $tenant, string $nome): User
    {
        $user = new User();
        $user->setEmail('not01_' . uniqid() . '@test.com');
        $user->setFullName($nome);
        $user->setRoles(['ROLE_SUPER_ADMIN']);
        $user->setIsActive(true);
        $this->em()->persist($user);
        $this->em()->persist(new UserTenant($user, $tenant));
        $this->em()->flush();

        return $user;
    }

    private function instalarCsrfStorage(): void
    {
        static::getContainer()->set('security.csrf.token_storage', new class implements ClearableTokenStorageInterface {
            public function getToken(string $tokenId): string { return 'TOKEN_' . $tokenId; }
            public function setToken(string $tokenId, string $token): void {}
            public function removeToken(string $tokenId): ?string { return null; }
            public function hasToken(string $tokenId): bool { return true; }
            public function clear(): void {}
        });
    }

    /** @return array<string, mixed> */
    private function json(KernelBrowser $client): array
    {
        return (array) json_decode((string) $client->getResponse()->getContent(), true);
    }

    /** Aviso gravado à mão — só para o que tem de SOBRAR (o que some é gerado pela rota real). */
    private function avisoAvulso(User $usuario, Tenant $tenant, string $tipo, string $url): int
    {
        $em      = $this->em();
        $usuario = $em->contains($usuario) ? $usuario : $em->getReference(User::class, $usuario->getId());
        $tenant  = $em->contains($tenant) ? $tenant : $em->getReference(Tenant::class, $tenant->getId());

        $aviso = new Notificacao();
        $aviso->setUsuario($usuario);
        $aviso->setTenant($tenant);
        $aviso->setTipo($tipo);
        $aviso->setTitulo('Aviso avulso NOT-01');
        $aviso->setUrl($url);
        $em->persist($aviso);
        $em->flush();

        return (int) $aviso->getId();
    }

    /** @return list<int> ids dos avisos do usuário com aquele link (sem TenantFilter: é SQL cru) */
    private function avisos(User $usuario, string $url): array
    {
        return array_map('intval', $this->em()->getConnection()->fetchFirstColumn(
            'SELECT id FROM notificacao WHERE usuario_id = :u AND url = :url ORDER BY id',
            ['u' => $usuario->getId(), 'url' => $url],
        ));
    }

    private function existe(int $avisoId): bool
    {
        return (int) $this->em()->getConnection()->fetchOne('SELECT COUNT(*) FROM notificacao WHERE id = :id', ['id' => $avisoId]) === 1;
    }

    #[TestDox('Registro: excluir a mensagem apaga a menção e a resposta que ela gerou; ficam o aviso de outra mensagem, o de link com o mesmo começo e o de outro escritório')]
    public function testExcluirMensagemApagaSeusAvisos(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $this->instalarCsrfStorage();
        $tenantA = $this->criarTenant();
        $tenantB = $this->criarTenant();
        $ana     = $this->membro($tenantA, 'Ana Paula Souza');
        $bruno   = $this->membro($tenantA, 'Bruno Lima');
        $carla   = $this->membro($tenantB, 'Carla de Fora');
        $pasta   = $this->criarPasta($tenantA);
        $pastaId = (int) $pasta->getId();
        $base    = '/pasta/' . $pastaId . '#pasta-msg-';

        // Ana escreve a raiz e outra mensagem mencionando Bruno; Bruno responde à raiz (aviso de
        // resposta para Ana) e escreve uma mensagem mencionando Ana (aviso de menção para ela).
        $this->logarComTenant($client, $ana, $tenantA);
        $client->request('POST', '/pasta/' . $pastaId . '/mensagem', ['_token' => 'TOKEN_pasta_mensagem_' . $pastaId, 'conteudo' => '<p>Raiz</p>']);
        self::assertResponseStatusCodeSame(201);
        $raizId = (int) $this->json($client)['id'];

        $client->request('POST', '/pasta/' . $pastaId . '/mensagem', [
            '_token'   => 'TOKEN_pasta_mensagem_' . $pastaId,
            'conteudo' => sprintf('<p>Outra para @[Bru](user:%d)</p>', $bruno->getId()),
        ]);
        self::assertResponseStatusCodeSame(201);
        $outraId = (int) $this->json($client)['id'];

        $this->logarComTenant($client, $bruno, $tenantA);
        $client->request('POST', '/pasta/' . $pastaId . '/mensagem', [
            '_token'     => 'TOKEN_pasta_mensagem_' . $pastaId,
            'conteudo'   => '<p>Respondendo</p>',
            'resposta_a' => (string) $raizId,
        ]);
        self::assertResponseStatusCodeSame(201);
        $respostaId = (int) $this->json($client)['id'];

        // Bruno ainda menciona Ana numa mensagem própria, que será a excluída junto com a resposta.
        $client->request('POST', '/pasta/' . $pastaId . '/mensagem', [
            '_token'   => 'TOKEN_pasta_mensagem_' . $pastaId,
            'conteudo' => sprintf('<p>Veja @[Ana](user:%d)</p>', $ana->getId()),
        ]);
        self::assertResponseStatusCodeSame(201);
        $mencaoId = (int) $this->json($client)['id'];

        $avisoDaResposta = $this->avisos($ana, $base . $respostaId);
        $avisoDaMencao   = $this->avisos($ana, $base . $mencaoId);
        $avisoDaOutra    = $this->avisos($bruno, $base . $outraId);
        self::assertCount(1, $avisoDaResposta, 'o envio real gerou o aviso de resposta');
        self::assertCount(1, $avisoDaMencao, 'o envio real gerou o aviso de menção');
        self::assertCount(1, $avisoDaOutra);

        // O que tem de sobrar: mesmo começo de link (12 × 123) e mesmo link em OUTRO escritório.
        $mesmoComeco   = $this->avisoAvulso($ana, $tenantA, Notificacao::TIPO_PASTA_MENCAO_REGISTRO, $base . $mencaoId . '9');
        $outroEscrit   = $this->avisoAvulso($carla, $tenantB, Notificacao::TIPO_PASTA_MENCAO_REGISTRO, $base . $mencaoId);
        $outroEscritR  = $this->avisoAvulso($carla, $tenantB, Notificacao::TIPO_PASTA_RESPOSTA_REGISTRO, $base . $respostaId);

        foreach ([$respostaId, $mencaoId] as $msgId) {
            $client->request('POST', '/pasta/' . $pastaId . '/mensagem/' . $msgId . '/excluir', ['_token' => 'TOKEN_pasta_mensagem_excluir_' . $msgId]);
            self::assertResponseIsSuccessful();
            self::assertTrue($this->json($client)['sucesso'] ?? false);
        }

        self::assertFalse($this->existe($avisoDaResposta[0]), 'a resposta excluída leva o aviso de resposta');
        self::assertFalse($this->existe($avisoDaMencao[0]), 'a mensagem excluída leva o aviso de menção');
        self::assertTrue($this->existe($avisoDaOutra[0]), 'o aviso de OUTRA mensagem fica');
        self::assertTrue($this->existe($mesmoComeco), '#pasta-msg-<id>9 não é a mensagem excluída');
        self::assertTrue($this->existe($outroEscrit), 'o aviso de outro escritório fica, mesmo com o mesmo link');
        self::assertTrue($this->existe($outroEscritR));
    }

    #[TestDox('Registro: exclusão recusada (quem não é o autor) não apaga aviso nenhum')]
    public function testExclusaoRecusadaNaoApagaAvisos(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $this->instalarCsrfStorage();
        $tenant  = $this->criarTenant();
        $ana     = $this->membro($tenant, 'Ana Paula Souza');
        $bruno   = $this->membro($tenant, 'Bruno Lima');
        $pasta   = $this->criarPasta($tenant);
        $pastaId = (int) $pasta->getId();

        DesligaEdicaoSemPrazo::no(static::getContainer()->get(EntityManagerInterface::class), $tenant, static::getContainer(), $bruno);
        $this->logarComTenant($client, $ana, $tenant);
        $client->request('POST', '/pasta/' . $pastaId . '/mensagem', [
            '_token'   => 'TOKEN_pasta_mensagem_' . $pastaId,
            'conteudo' => sprintf('<p>Para @[Bru](user:%d)</p>', $bruno->getId()),
        ]);
        self::assertResponseStatusCodeSame(201);
        $msgId = (int) $this->json($client)['id'];
        $aviso = $this->avisos($bruno, '/pasta/' . $pastaId . '#pasta-msg-' . $msgId);
        self::assertCount(1, $aviso);

        $this->logarComTenant($client, $bruno, $tenant);
        $client->request('POST', '/pasta/' . $pastaId . '/mensagem/' . $msgId . '/excluir', ['_token' => 'TOKEN_pasta_mensagem_excluir_' . $msgId]);
        self::assertResponseStatusCodeSame(403);

        self::assertTrue($this->existe($aviso[0]));
    }

    #[TestDox('Nota técnica: excluir a nota apaga a menção que ela gerou; ficam o de link com o mesmo começo e o de outro escritório')]
    public function testExcluirNotaTecnicaApagaSeusAvisos(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $this->instalarCsrfStorage();
        $tenantA  = $this->criarTenant();
        $tenantB  = $this->criarTenant();
        $ana      = $this->membro($tenantA, 'Ana Paula Souza');
        $bruno    = $this->membro($tenantA, 'Bruno Lima');
        $carla    = $this->membro($tenantB, 'Carla de Fora');
        $pasta    = $this->criarPasta($tenantA);
        $processo = $this->criarProcesso($tenantA, '07011345720258070007');
        $this->vincular($pasta, $processo);
        $pastaId    = (int) $pasta->getId();
        $processoId = (int) $processo->getId();

        $this->logarComTenant($client, $ana, $tenantA);
        $notas = [];
        foreach (['<p>Primeira para @[Bru](user:%d)</p>', '<p>Segunda para @[Bru](user:%d)</p>'] as $texto) {
            $client->request('POST', '/processos/' . $processoId . '/nota-tecnica', [
                '_token'   => 'TOKEN_processo_nota_tecnica_' . $processoId,
                'pasta_id' => $pastaId,
                'conteudo' => sprintf($texto, $bruno->getId()),
            ]);
            self::assertResponseStatusCodeSame(201);
            $notas[] = (int) $this->json($client)['id'];
        }
        [$excluida, $outra] = $notas;

        $base          = '/pasta/' . $pastaId . '#nota-tecnica-';
        $avisoExcluida = $this->avisos($bruno, $base . $excluida);
        $avisoOutra    = $this->avisos($bruno, $base . $outra);
        self::assertCount(1, $avisoExcluida, 'a criação real gerou o aviso de menção');
        self::assertCount(1, $avisoOutra);
        $mesmoComeco = $this->avisoAvulso($bruno, $tenantA, Notificacao::TIPO_NOTA_TECNICA_MENCAO, $base . $excluida . '9');
        $outroEscrit = $this->avisoAvulso($carla, $tenantB, Notificacao::TIPO_NOTA_TECNICA_MENCAO, $base . $excluida);

        $client->request('POST', '/processos/' . $processoId . '/nota-tecnica/' . $excluida . '/excluir', [
            '_token' => 'TOKEN_processo_nota_tecnica_excluir_' . $excluida,
        ]);
        self::assertResponseIsSuccessful();

        self::assertFalse($this->existe($avisoExcluida[0]), 'a nota excluída leva o aviso de menção');
        self::assertTrue($this->existe($avisoOutra[0]), 'o aviso de OUTRA nota fica');
        self::assertTrue($this->existe($mesmoComeco), '#nota-tecnica-<id>9 não é a nota excluída');
        self::assertTrue($this->existe($outroEscrit), 'o aviso de outro escritório fica, mesmo com o mesmo link');
    }
}
