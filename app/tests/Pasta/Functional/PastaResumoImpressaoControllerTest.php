<?php

declare(strict_types=1);

namespace App\Tests\Pasta\Functional;

use App\Entity\Tarefa\Tarefa;
use App\Pasta\Controller\PastaResumoController;
use App\Pasta\Entity\MotivoDesativacaoChecklist;
use App\Pasta\Entity\PastaChecklistItem;
use App\Pasta\Entity\PastaDocumento;
use App\Tests\Functional\JusPrimeWebTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\TestDox;

/**
 * Folha "Resumo da pasta" (menu ⋮ → Imprimir resumo). O guarda é o da tela da pasta: dono da
 * pasta = escritório da sessão (404) e permissão de VER a pasta (403).
 *
 * O cross-tenant usa um SUPER_ADMIN de propósito: ele passa por `canAccessResource` em qualquer
 * pasta, então o 404 só pode vir da conferência de dono — é ela que este teste prova, e não a
 * permissão (que tem teste próprio, com usuário comum do MESMO escritório).
 */
#[CoversClass(PastaResumoController::class)]
#[Group('pasta')]
final class PastaResumoImpressaoControllerTest extends JusPrimeWebTestCase
{
    use CriaFixturesPushDaPastaTrait;

    private const NUMERO = '07011345720258070007';

    private function url(int $pastaId): string
    {
        return "/pasta/{$pastaId}/resumo/imprimir";
    }

    #[TestDox('Quem vê a pasta recebe a folha: dados, processo, metas abertas, movimentações, financeiro e o window.print()')]
    public function testFolhaParaQuemVe(): void
    {
        $client          = static::createClient();
        [$user, $tenant] = $this->criarAdmin();
        $pasta           = $this->criarPasta($tenant);
        $pasta->setNomeCliente('CONDOMÍNIO DO RESUMO');
        $pasta->setValorCausa('12860.00');
        $processo = $this->criarProcesso($tenant, self::NUMERO);
        $this->vincular($pasta, $processo);
        $this->criarPublicacao($tenant, '40000001', self::NUMERO, '2026-09-20', $processo);

        $meta = new Tarefa();
        $meta->setTitulo('Protocolar réplica');
        $meta->setDescricao('');
        $meta->setStatus(Tarefa::STATUS_PENDENTE);
        $meta->setPrazo(new \DateTimeImmutable('+10 days'));
        $meta->setPasta($pasta);
        $meta->setTenant($tenant);
        $this->em()->persist($meta);
        $feita = new Tarefa();
        $feita->setTitulo('Meta já concluída');
        $feita->setDescricao('');
        $feita->setStatus(Tarefa::STATUS_CONCLUIDA);
        $feita->setPasta($pasta);
        $feita->setTenant($tenant);
        $this->em()->persist($feita);
        $this->em()->flush();
        $pastaId = (int) $pasta->getId();
        // A coleção `tarefas` da pasta em memória não conhece as metas recém-criadas.
        $this->em()->clear();

        $this->logarComTenant($client, $user, $tenant);
        $crawler = $client->request('GET', $this->url($pastaId));

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1.rp-titulo', 'Resumo da pasta ' . $pasta->getNup());
        self::assertStringContainsString('CONDOMÍNIO DO RESUMO', $crawler->filter('[data-secao="dados"]')->text());
        self::assertStringContainsString('0701134-57.2025.8.07.0007', $crawler->filter('[data-secao="processos"]')->text());

        $metas = $crawler->filter('[data-secao="metas"]')->text();
        self::assertStringContainsString('Metas abertas (1)', $metas);
        self::assertStringContainsString('Protocolar réplica', $metas);
        self::assertStringNotContainsString('Meta já concluída', $metas, 'Meta concluída não é meta aberta.');

        self::assertStringContainsString('Intimação', $crawler->filter('[data-secao="movimentacoes"]')->text());
        self::assertStringContainsString('R$ 12.860,00', $crawler->filter('[data-secao="financeiro"]')->text());

        $html = (string) $client->getResponse()->getContent();
        self::assertStringContainsString('window.print()', $html);
        self::assertStringContainsString('@media print', $html);
        self::assertStringContainsString('size: A4', $html);
    }

    #[TestDox('Usuário comum com permissão de ver a pasta também recebe a folha — e o financeiro, como na aba Financeiro')]
    public function testUsuarioComPermissaoDeVer(): void
    {
        $client       = static::createClient();
        [, $tenant]   = $this->criarAdmin();
        $usuario      = $this->criarUsuarioSemPermissaoDoModulo($tenant); // tem resources.pasta.view
        $pasta        = $this->criarPasta($tenant);
        $pasta->setValorCausa('500.00');
        $this->em()->flush();

        $this->logarComTenant($client, $usuario, $tenant);
        $crawler = $client->request('GET', $this->url((int) $pasta->getId()));

        self::assertResponseIsSuccessful();
        self::assertCount(1, $crawler->filter('[data-secao="financeiro"]'));
    }

    #[TestDox('Usuário do MESMO escritório sem permissão de ver a pasta leva 403 e não vê nada dela')]
    public function testSemPermissaoDa403(): void
    {
        $client     = static::createClient();
        [, $tenant] = $this->criarAdmin();
        $semNada    = $this->criarUsuarioSemNenhumaPermissao($tenant);
        $pasta      = $this->criarPasta($tenant);
        $pasta->setNomeCliente('SEGREDO DO CASO');
        $this->em()->flush();

        $this->logarComTenant($client, $semNada, $tenant);
        $client->request('GET', $this->url((int) $pasta->getId()));

        self::assertResponseStatusCodeSame(403);
        self::assertStringNotContainsString('SEGREDO DO CASO', (string) $client->getResponse()->getContent());
    }

    #[TestDox('Pasta de OUTRO escritório dá 404, mesmo para quem passa em qualquer permissão')]
    public function testOutroEscritorioDa404(): void
    {
        $client           = static::createClient();
        [$userA, $tenantA] = $this->criarAdmin();
        [, $tenantB]       = $this->criarAdmin();
        $pastaB            = $this->criarPasta($tenantB);
        $pastaB->setNomeCliente('CLIENTE DO ESCRITÓRIO B');
        $this->em()->flush();

        $this->logarComTenant($client, $userA, $tenantA);
        $client->request('GET', $this->url((int) $pastaB->getId()));

        self::assertResponseStatusCodeSame(404);
        self::assertStringNotContainsString('CLIENTE DO ESCRITÓRIO B', (string) $client->getResponse()->getContent());
    }

    #[TestDox('A mesma pasta, aberta pelo próprio escritório, dá 200 — o 404 acima é isolamento, não rota quebrada')]
    public function testMesmaPastaNoProprioEscritorioDa200(): void
    {
        $client            = static::createClient();
        [$userB, $tenantB] = $this->criarAdmin();
        $pastaB            = $this->criarPasta($tenantB);

        $this->logarComTenant($client, $userB, $tenantB);
        $client->request('GET', $this->url((int) $pastaB->getId()));

        self::assertResponseIsSuccessful();
    }

    #[TestDox('Pendências da folha: item do checklist marcado sem anexo aparece, como na pasta_show; com o arquivo, some')]
    public function testPendenciaDeChecklistSemAnexo(): void
    {
        $client          = static::createClient();
        [$user, $tenant] = $this->criarAdmin();
        $semArquivo      = $this->criarPasta($tenant);
        $comArquivo      = $this->criarPasta($tenant);
        foreach ([$semArquivo, $comArquivo] as $pasta) {
            $item = (new PastaChecklistItem())
                ->setPasta($pasta)
                ->setTenant($tenant)
                ->setTitulo('Contrato de honorários')
                ->setConcluido(true);
            $this->em()->persist($item);
        }
        $doc = (new PastaDocumento())
            ->setTenant($tenant)
            ->setPasta($comArquivo)
            ->setTitulo('Contrato assinado')
            ->setCategoria(PastaDocumento::CATEGORIA_DEMAIS)
            ->setCaminhoArquivo(bin2hex(random_bytes(16)) . '.pdf')
            ->setNomeOriginal('arquivo.pdf')
            ->setMimeType('application/pdf')
            ->setTamanhoBytes(10);
        $this->em()->persist($doc);
        $this->em()->flush();
        $idSem = (int) $semArquivo->getId();
        $idCom = (int) $comArquivo->getId();
        $this->em()->clear();

        $this->logarComTenant($client, $user, $tenant);

        $crawler = $client->request('GET', $this->url($idSem));
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('1 item do checklist marcado sem anexo', $crawler->filter('[data-secao="pendencias"]')->text());

        $this->em()->clear();
        $crawler = $client->request('GET', $this->url($idCom));
        self::assertResponseIsSuccessful();
        self::assertStringNotContainsString('marcado sem anexo', (string) $client->getResponse()->getContent(), 'o filtro removeu tudo: sem pendência de Documentos');
    }

    #[TestDox('Pendências da folha: com o checklist DESATIVADO o item marcado sem anexo não vira pendência (mesma regra da pasta_show)')]
    public function testChecklistDesativadoNaoGeraPendencia(): void
    {
        $client          = static::createClient();
        [$user, $tenant] = $this->criarAdmin();
        $pasta           = $this->criarPasta($tenant);
        $this->em()->persist((new PastaChecklistItem())
            ->setPasta($pasta)
            ->setTenant($tenant)
            ->setTitulo('Contrato de honorários')
            ->setConcluido(true));
        $pasta->desativarChecklist(MotivoDesativacaoChecklist::Encerrada, $user, new \DateTimeImmutable());
        $this->em()->flush();
        $id = (int) $pasta->getId();
        $this->em()->clear();

        $this->logarComTenant($client, $user, $tenant);
        $client->request('GET', $this->url($id));

        self::assertResponseIsSuccessful();
        self::assertStringNotContainsString('marcado sem anexo', (string) $client->getResponse()->getContent());
    }
}
