<?php

declare(strict_types=1);

namespace App\Tests\Inteligencia\Functional;

use App\Inteligencia\Controller\AnaliseDaPastaController;
use App\Inteligencia\Controller\AnalisePushController;
use App\Inteligencia\Enum\Agente;
use App\Pasta\Entity\Pasta;
use App\Tests\Functional\JusPrimeWebTestCase;
use App\Tests\Inteligencia\Support\CriaFixturesInteligenciaTrait;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\DomCrawler\Crawler;

/**
 * O ⋮ das análises da BlueJus IA — no Push (`_analises_push`) e no drawer dos agentes
 * (`_analises_agente`):
 *
 * - C6-IA-03: "Criar tarefa da providência" só para quem EDITA a pasta e com a pasta viva — a
 *   regra do menu do Push (FPN-014c). Quem só vê e a lápide não recebem o item. O cartão da
 *   análise continua na tela nos dois casos: é ele o recurso irmão que prova que a ausência do
 *   item é a guarda, não a lista vazia.
 * - C6-IA-04: "Marcar como lido" / "Marcar como não lido" (dc L5151 `lidoTxt`) e, no ⋮, o
 *   `bi-check2-all` nos dois estados (dc L2446); na linha de ações o "não lido" segue com o
 *   `bi-envelope` (dc `lidoIc`).
 */
#[CoversClass(AnalisePushController::class)]
#[CoversClass(AnaliseDaPastaController::class)]
#[Group('pasta')]
final class IaMenuEscritaEDesenhoTelaTest extends JusPrimeWebTestCase
{
    use CriaFixturesInteligenciaTrait;

    private function cliente(): KernelBrowser
    {
        $client = static::createClient();
        $client->disableReboot();

        return $client;
    }

    /** Cartão do Push e cartão do agente Gestor, ambos concluídos e com uma providência. @return array{int, int} */
    private function criarAnalisesConcluidas(Pasta $pasta, \App\Entity\Auth\User $por): array
    {
        $tenant = $pasta->getTenant();
        self::assertNotNull($tenant);
        $push = $this->criarAnalisePendente($tenant, $por, $pasta);
        $this->concluirAnalise($push, 'Sentença publicada.', [['tipo' => 'providencia', 'texto' => 'Protocolar apelação.']], 'Advogado');
        $agente = $this->criarAnaliseDoAgentePendente($tenant, $por, $pasta, Agente::Gestor);
        $this->concluirAnalise($agente, 'Pasta parada.', [['tipo' => 'providencia', 'texto' => 'Protocolar réplica.']], 'Dra. Ana', 'Texto.');

        return [(int) $push->getId(), (int) $agente->getId()];
    }

    /** @return array{Crawler, Crawler} o cartão do Push e o do agente, pelas rotas de recarga */
    private function cartoes(KernelBrowser $client, int $pastaId, int $pushId, int $agenteId): array
    {
        $crawler = $client->request('GET', "/pasta/{$pastaId}/ia/push/analises");
        self::assertResponseIsSuccessful();
        $push = $crawler->filter('.ps-ia-lista > .ps-ia-cartao[data-ia-analise="' . $pushId . '"]');

        $crawler = $client->request('GET', "/pasta/{$pastaId}/ia/agentes/gestor/analises");
        self::assertResponseIsSuccessful();
        $agente = $crawler->filter('.ia-ag-lista > .ia-cartao[data-ia-analise="' . $agenteId . '"]');

        self::assertCount(1, $push, 'o cartão do Push está na tela (recurso irmão)');
        self::assertCount(1, $agente, 'o cartão do agente está na tela (recurso irmão)');

        return [$push, $agente];
    }

    #[TestDox('C6-IA-03: quem edita recebe "Criar tarefa da providência" no ⋮ do Push e "Criar tarefa" embaixo do resultado do agente (F5)')]
    public function testQuemEditaRecebeCriarTarefa(): void
    {
        $client = $this->cliente();
        [$admin, $tenant] = $this->criarAdmin();
        [$pasta] = $this->criarPastaComPublicacao($tenant);
        [$pushId, $agenteId] = $this->criarAnalisesConcluidas($pasta, $admin);

        $this->logarComTenant($client, $admin, $tenant);
        [$push, $agente] = $this->cartoes($client, (int) $pasta->getId(), $pushId, $agenteId);

        self::assertCount(1, $push->filter('.ps-ia-menu > [data-ia-criar-meta]'));
        self::assertCount(1, $agente->filter('.ia-corpo > .ia-res-acoes > [data-ia-criar-meta]'));
    }

    #[TestDox('C6-IA-03: quem só VÊ a pasta (com a IA) não recebe "Criar tarefa da providência" — nem no Push, nem no agente')]
    public function testQuemSoVeNaoRecebeCriarTarefa(): void
    {
        $client = $this->cliente();
        [$admin, $tenant] = $this->criarAdmin();
        [$pasta] = $this->criarPastaComPublicacao($tenant);
        [$pushId, $agenteId] = $this->criarAnalisesConcluidas($pasta, $admin);
        $leitor = $this->criarUsuarioComPermissoes($tenant, ['resources.pasta.view', 'modules.inteligencia.view'], 'leitor');

        $this->logarComTenant($client, $leitor, $tenant);
        [$push, $agente] = $this->cartoes($client, (int) $pasta->getId(), $pushId, $agenteId);

        self::assertCount(0, $push->filter('[data-ia-criar-meta]'));
        self::assertCount(0, $agente->filter('[data-ia-criar-meta]'));
        self::assertStringNotContainsString('Criar tarefa da providência', $push->text());
        self::assertStringNotContainsString('Criar tarefa', $agente->text());
        // O resto do ⋮ (leitura e lida) continua: só a escrita na pasta sai.
        self::assertCount(1, $push->filter('.ps-ia-menu form[data-ia-acao="lida"]'));
        self::assertCount(1, $agente->filter('.ia-menu form[data-ia-acao="lida"]'));
    }

    #[TestDox('C6-IA-03: na pasta excluída (lápide) nem quem edita recebe "Criar tarefa da providência"')]
    public function testLapideNaoRecebeCriarTarefa(): void
    {
        $client = $this->cliente();
        [$admin, $tenant] = $this->criarAdmin();
        [$pasta] = $this->criarPastaComPublicacao($tenant);
        [$pushId, $agenteId] = $this->criarAnalisesConcluidas($pasta, $admin);
        $pasta->marcarExcluida($admin, new \DateTimeImmutable());
        $this->em()->flush();

        $this->logarComTenant($client, $admin, $tenant);
        [$push, $agente] = $this->cartoes($client, (int) $pasta->getId(), $pushId, $agenteId);

        self::assertCount(0, $push->filter('[data-ia-criar-meta]'));
        self::assertCount(0, $agente->filter('[data-ia-criar-meta]'));
    }

    #[TestDox('C6-IA-04: "Marcar como lido" (não "lida") no ⋮ e na linha de ações, com bi-check2-all')]
    public function testMarcarComoLidoNaoLida(): void
    {
        $client = $this->cliente();
        [$admin, $tenant] = $this->criarAdmin();
        [$pasta] = $this->criarPastaComPublicacao($tenant);
        [$pushId, $agenteId] = $this->criarAnalisesConcluidas($pasta, $admin);

        $this->logarComTenant($client, $admin, $tenant);
        [$push, $agente] = $this->cartoes($client, (int) $pasta->getId(), $pushId, $agenteId);

        foreach ([[$push, 'ps-ia'], [$agente, 'ia']] as [$cartao, $pref]) {
            self::assertSame('Marcar como lido', trim($cartao->filter(".{$pref}-menu form[data-ia-acao=\"lida\"] button")->text()));
            self::assertSame('bi bi-check2-all', $cartao->filter(".{$pref}-menu form[data-ia-acao=\"lida\"] button > i")->attr('class'));
            self::assertSame('Marcar como lido', trim($cartao->filter(".{$pref}-acoes form[data-ia-acao=\"lida\"] button")->text()));
            self::assertStringNotContainsString('Marcar como lida', $cartao->text());
        }
    }

    #[TestDox('C6-IA-04: lida, o item do ⋮ é "Marcar como não lido" com bi-check2-all; na linha de ações o bi-envelope fica')]
    public function testMarcarComoNaoLidoNoMenu(): void
    {
        $client = $this->cliente();
        [$admin, $tenant] = $this->criarAdmin();
        [$pasta] = $this->criarPastaComPublicacao($tenant);
        $push = $this->criarAnalisePendente($tenant, $admin, $pasta);
        $this->concluirAnalise($push);
        $push->marcarLida($admin);
        $agente = $this->criarAnaliseDoAgentePendente($tenant, $admin, $pasta, Agente::Gestor);
        $this->concluirAnalise($agente, 'Resumo.', [], null, 'Texto.');
        $agente->marcarLida($admin);
        $this->em()->flush();

        $this->logarComTenant($client, $admin, $tenant);
        [$cartaoPush, $cartaoAgente] = $this->cartoes($client, (int) $pasta->getId(), (int) $push->getId(), (int) $agente->getId());

        foreach ([[$cartaoPush, 'ps-ia'], [$cartaoAgente, 'ia']] as [$cartao, $pref]) {
            $item = $cartao->filter(".{$pref}-menu form[data-ia-acao=\"nao-lida\"] button");
            self::assertSame('Marcar como não lido', trim($item->text()));
            self::assertSame('bi bi-check2-all', $item->filter('i')->attr('class'), 'dc L2446: o item do ⋮ é sempre bi-check2-all');
            self::assertSame('bi bi-envelope', $cartao->filter(".{$pref}-acoes form[data-ia-acao=\"nao-lida\"] button > i")->attr('class'), 'dc lidoIc: a linha de ações mantém o envelope');
        }
    }
}
