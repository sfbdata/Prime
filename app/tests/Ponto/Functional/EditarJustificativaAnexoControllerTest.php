<?php

declare(strict_types=1);

namespace App\Tests\Ponto\Functional;

use App\Entity\Auth\User;
use App\Entity\Auth\UserTenant;
use App\Entity\Tenant\Tenant;
use App\Entity\Tenant\TenantRole;
use App\Ponto\Controller\PontoController;
use App\Ponto\Entity\JustificativaPonto;
use App\Shared\Service\ArquivoStorageInterface;
use App\Tests\Functional\JusPrimeWebTestCase;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Security\Csrf\TokenStorage\ClearableTokenStorageInterface;

/**
 * A rota de edição pela porta HTTP — a delegação do controller ao UseCase, que os testes do
 * Bloco A exercitavam só por dentro.
 */
#[CoversClass(PontoController::class)]
final class EditarJustificativaAnexoControllerTest extends JusPrimeWebTestCase
{
    #[TestDox('POST na edição com anexo novo troca o anexo do LOTE inteiro')]
    public function testEdicaoComAnexoTrocaOLoteInteiro(): void
    {
        [$client, $c] = $this->preparar(3);
        $primeiro     = $c['lote'][0];

        $client->request(
            'POST',
            '/ponto/justificativa/' . $primeiro->getId() . '/editar',
            ['_token' => 'TOKEN_editar_justificativa_' . $primeiro->getId(), 'tipo' => 'atestado_medico'],
            ['anexo' => $this->upload()],
        );

        self::assertResponseRedirects();

        $em = static::getContainer()->get(EntityManagerInterface::class);
        $em->clear();

        $anexos = [];
        foreach ($c['lote'] as $registro) {
            $anexos[] = $em->find(JustificativaPonto::class, $registro->getId())->getAnexoPath();
        }

        self::assertCount(1, array_unique($anexos), 'os 3 dias deveriam apontar para o mesmo anexo');
        self::assertNotSame($c['anexoAntigo'], $anexos[0], 'o anexo deveria ter mudado');
    }

    #[TestDox('POST com anexo inválido não grava NADA — nem os outros campos da edição')]
    public function testAnexoInvalidoNaoGravaOsOutrosCampos(): void
    {
        [$client, $c] = $this->preparar(2);
        $primeiro     = $c['lote'][0];
        $tipoOriginal = $primeiro->getTipo();

        $client->request(
            'POST',
            '/ponto/justificativa/' . $primeiro->getId() . '/editar',
            ['_token' => 'TOKEN_editar_justificativa_' . $primeiro->getId(), 'tipo' => 'falta_nao_justificada'],
            ['anexo' => $this->uploadInvalido()],
        );

        self::assertResponseRedirects();

        $em = static::getContainer()->get(EntityManagerInterface::class);
        $em->clear();
        $recarregado = $em->find(JustificativaPonto::class, $primeiro->getId());

        // O ponto do teste: a mensagem diz que o arquivo foi recusado, então a edição inteira tem
        // de ter sido descartada. Salvar o tipo e recusar o anexo deixaria a folha diferente do
        // que a tela informou ao usuário.
        self::assertSame($tipoOriginal, $recarregado->getTipo(), 'o tipo não podia ter sido salvo');
        self::assertSame($c['anexoAntigo'], $recarregado->getAnexoPath(), 'o anexo não podia ter mudado');
    }

    #[TestDox('POST sem anexo continua salvando os demais campos')]
    public function testEdicaoSemAnexoSalvaOsCampos(): void
    {
        [$client, $c] = $this->preparar(1);
        $primeiro     = $c['lote'][0];

        $client->request(
            'POST',
            '/ponto/justificativa/' . $primeiro->getId() . '/editar',
            ['_token' => 'TOKEN_editar_justificativa_' . $primeiro->getId(), 'tipo' => 'atestado_medico'],
        );

        self::assertResponseRedirects();

        $em = static::getContainer()->get(EntityManagerInterface::class);
        $em->clear();

        self::assertSame(
            'atestado_medico',
            $em->find(JustificativaPonto::class, $primeiro->getId())->getTipo(),
        );
    }

    // ------------------------------------------------------------------ helpers

    private function upload(): UploadedFile
    {
        $origem = sys_get_temp_dir() . '/rota-ok-' . bin2hex(random_bytes(6)) . '.pdf';
        file_put_contents($origem, "%PDF-1.4\n% novo\n");

        return new UploadedFile($origem, 'novo.pdf', 'application/pdf', null, true);
    }

    private function uploadInvalido(): UploadedFile
    {
        $origem = sys_get_temp_dir() . '/rota-ruim-' . bin2hex(random_bytes(6)) . '.txt';
        file_put_contents($origem, 'nao e pdf nem imagem');

        return new UploadedFile($origem, 'nota.txt', 'text/plain', null, true);
    }

    /** @return array{0: KernelBrowser, 1: array<string,mixed>} */
    private function preparar(int $dias): array
    {
        $client = static::createClient();
        $client->disableReboot();

        $storage = new class implements ClearableTokenStorageInterface {
            public function getToken(string $tokenId): string { return 'TOKEN_' . $tokenId; }
            public function setToken(string $tokenId, string $token): void {}
            public function removeToken(string $tokenId): ?string { return null; }
            public function hasToken(string $tokenId): bool { return true; }
            public function clear(): void {}
        };
        static::getContainer()->set('security.csrf.token_storage', $storage);

        $em     = static::getContainer()->get(EntityManagerInterface::class);
        $hasher = static::getContainer()->get(UserPasswordHasherInterface::class);

        $tenant = new Tenant();
        $tenant->setName('Tenant EDIT ANEXO ' . uniqid());
        $em->persist($tenant);

        $user = new User();
        $user->setEmail('edit_anexo_' . uniqid() . '@test.com');
        $user->setFullName('Colaborador Edit');
        $user->setRoles(['ROLE_USER']);
        $user->setIsActive(true);
        $user->setPassword($hasher->hashPassword($user, 'senha123'));
        $em->persist($user);

        $role = new TenantRole();
        $role->setTenant($tenant);
        $role->setName('Gestor ' . uniqid());
        $role->setIsSystem(true);
        $em->persist($role);

        $vinculo = new UserTenant($user, $tenant);
        $vinculo->setTenantRole($role);
        $em->persist($vinculo);

        $anexoAntigo = static::getContainer()->get(ArquivoStorageInterface::class)->salvarConteudo(
            '%PDF-1.4 antigo',
            (string) static::getContainer()->getParameter('justificativas_uploads_dir'),
            'pdf',
        );

        $batchId = bin2hex(random_bytes(12));
        $lote    = [];
        for ($i = 0; $i < $dias; $i++) {
            $j = new JustificativaPonto();
            $j->setUser($user);
            $j->setTenant($tenant);
            $j->setData(new \DateTime('2026-04-' . str_pad((string) ($i + 1), 2, '0', \STR_PAD_LEFT)));
            $j->setAnexoPath($anexoAntigo);
            $j->setStatus('pendente');
            $j->setBatchId($batchId);
            $j->setTipo('licenca');
            $em->persist($j);
            $lote[] = $j;
        }

        $em->flush();

        $this->logarComTenant($client, $user, $tenant);

        return [$client, ['tenant' => $tenant, 'user' => $user, 'lote' => $lote, 'anexoAntigo' => $anexoAntigo]];
    }
}
