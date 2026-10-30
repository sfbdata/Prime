<?php

declare(strict_types=1);

namespace App\Tests\ChatIa\Functional;

use App\Central\Entity\Conversa;
use App\Central\Entity\Mensagem;
use App\Central\Entity\Participante;
use App\ChatIa\Controller\ChatIaConversasController;
use App\ChatIa\UseCase\ImportarHistoricoDaCentralUseCase;
use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Tests\Central\Support\CentralHttpTrait;
use App\Tests\Functional\JusPrimeWebTestCase;
use App\Tests\Inteligencia\Support\CriaFixturesInteligenciaTrait;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;

/** O histórico antigo da conversa "BlueJus IA" da Central vira UMA conversa do Chat I.A, uma vez, só do dono. */
#[CoversClass(ChatIaConversasController::class)]
#[CoversClass(ImportarHistoricoDaCentralUseCase::class)]
final class ImportarHistoricoDaCentralTest extends JusPrimeWebTestCase
{
    use CriaFixturesInteligenciaTrait;
    use CentralHttpTrait;

    /** @return array{0: \Symfony\Bundle\FrameworkBundle\KernelBrowser, 1: Tenant, 2: User, 3: User} */
    private function cenario(): array
    {
        $client = $this->cliente();
        [$admin, $tenant] = $this->criarAdmin();
        $this->ligarIaNoTenant($tenant, $admin);
        $ana = $this->criarUsuarioComPermissoes($tenant, ['modules.inteligencia.view'], 'ana');
        $bruno = $this->criarUsuarioComPermissoes($tenant, ['modules.inteligencia.view'], 'bruno');

        return [$client, $tenant, $ana, $bruno];
    }

    private function historicoAntigo(Tenant $tenant, User $dono): void
    {
        $t0 = new \DateTimeImmutable('2026-10-20 10:00:00');
        $conversa = Conversa::comAIa($tenant, $dono, $t0);
        $this->em()->persist($conversa);
        $this->em()->persist(new Participante($tenant, $conversa, $dono, $t0));
        $this->em()->flush();
        $this->em()->persist(new Mensagem($tenant, $conversa, $dono, 'Qual o prazo do recurso?', $t0));
        $this->em()->persist(Mensagem::daIA($tenant, $conversa, $dono, 'Depende do tipo de recurso.', $t0->modify('+1 minute')));
        $this->em()->flush();
    }

    private function importar($client): array
    {
        $this->post($client, '/chat-ia/importar-central', []);
        self::assertResponseIsSuccessful();

        return $this->json($client);
    }

    #[TestDox('Importa o histórico antigo como uma conversa (de_ia vira assistente) e repetir não duplica')]
    public function testImportaUmaVez(): void
    {
        [$client, $tenant, $ana] = $this->cenario();
        $this->historicoAntigo($tenant, $ana);
        $this->logarComTenant($client, $ana, $tenant);

        $primeira = $this->importar($client);
        $segunda = $this->importar($client);

        self::assertNotNull($primeira['id']);
        self::assertSame($primeira['id'], $segunda['id'], 'idempotente');
        self::assertSame(1, $this->contarNoBanco("SELECT count(*) FROM chat_ia_conversa WHERE origem = 'importada'"));
        self::assertSame(['usuario', 'assistente'], array_column($this->mensagensDaImportada(), 'papel'));
        self::assertSame(ImportarHistoricoDaCentralUseCase::TITULO, $this->tituloDaImportada());
        self::assertSame(2, $this->contarNoBanco('SELECT count(*) FROM central_mensagem'), 'nada é apagado na Central');
    }

    #[TestDox('Sem histórico antigo não cria nada')]
    public function testSemHistorico(): void
    {
        [$client, $tenant, $ana] = $this->cenario();
        $this->logarComTenant($client, $ana, $tenant);

        self::assertNull($this->importar($client)['id']);
        self::assertSame(0, $this->contarNoBanco('SELECT count(*) FROM chat_ia_conversa'));
    }

    #[TestDox('O histórico é de quem o teve: outra pessoa do escritório não recebe a conversa de Ana')]
    public function testSoDoDono(): void
    {
        [$client, $tenant, $ana, $bruno] = $this->cenario();
        $this->historicoAntigo($tenant, $ana);
        $this->logarComTenant($client, $bruno, $tenant);

        self::assertNull($this->importar($client)['id']);
        self::assertSame(0, $this->contarNoBanco('SELECT count(*) FROM chat_ia_conversa'));
    }

    #[TestDox('Sem CSRF recusa; criar conversa pelo mini grava origem central')]
    public function testCsrfEOrigemCentral(): void
    {
        [$client, $tenant, $ana] = $this->cenario();
        $this->logarComTenant($client, $ana, $tenant);

        $this->post($client, '/chat-ia/conversas', ['origem' => 'central']);
        self::assertResponseStatusCodeSame(201);
        self::assertSame(1, $this->contarNoBanco("SELECT count(*) FROM chat_ia_conversa WHERE origem = 'central'"));

        $this->post($client, '/chat-ia/conversas', ['origem' => 'importada']);
        self::assertSame(0, $this->contarNoBanco("SELECT count(*) FROM chat_ia_conversa WHERE origem = 'importada'"), 'o cliente não escolhe importada');
    }

    /** @return list<array{papel: string}> */
    private function mensagensDaImportada(): array
    {
        return $this->em()->getConnection()->fetchAllAssociative("SELECT m.papel FROM chat_ia_mensagem m JOIN chat_ia_conversa c ON c.id = m.conversa_id WHERE c.origem = 'importada' ORDER BY m.id");
    }

    private function tituloDaImportada(): ?string
    {
        return $this->em()->getConnection()->fetchOne("SELECT titulo FROM chat_ia_conversa WHERE origem = 'importada'") ?: null;
    }
}
