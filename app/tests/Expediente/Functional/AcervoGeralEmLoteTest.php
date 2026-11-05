<?php

declare(strict_types=1);

namespace App\Tests\Expediente\Functional;

use App\Cliente\Entity\ClientePF;
use App\Entity\Auth\User;
use App\Entity\Tarefa\Tarefa;
use App\Entity\Tenant\Tenant;
use App\Expediente\Controller\ExpedienteController;
use App\Expediente\Entity\Marcador;
use App\Pasta\Entity\Pasta;
use App\Pasta\Repository\PastaRepository;
use App\Processo\Entity\Processo;
use App\Tests\Functional\JusPrimeWebTestCase;
use App\Tests\Pasta\Functional\CriaCenarioDoCadeadoFuncionalTrait;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;

/**
 * O painel "Acervo geral" do expediente carrega clientes, marcadores, metas e o nome de todas as pastas da
 * página de uma vez (PR 4a da revisão de queries). Trava duas coisas: o número de consultas não cresce com o
 * número de pastas, e a Lista mostra exatamente o mesmo que mostrava quando cada linha consultava sozinha.
 */
#[CoversClass(ExpedienteController::class)]
#[CoversClass(PastaRepository::class)]
final class AcervoGeralEmLoteTest extends JusPrimeWebTestCase
{
    use CriaCenarioDoCadeadoFuncionalTrait;

    private const PERMISSOES = ['modules.expediente.view', 'modules.pastas.view', 'resources.pasta.view', 'resources.pasta.edit'];

    #[TestDox('o acervo geral faz o mesmo número de consultas com 1 e com 8 pastas cheias')]
    public function testConsultasNaoCrescemComAsPastas(): void
    {
        $this->client = static::createClient();

        // Dois escritórios iguais, um com 1 pasta e outro com 8: mesmas pessoas, mesmo papel.
        [$primarioUm, $tenantUm]   = $this->criarEscritorio();
        [$primarioOito, $tenantOito] = $this->criarEscritorio();
        $this->encherPasta($tenantUm, $primarioUm, 0);
        for ($i = 0; $i < 8; ++$i) {
            $this->encherPasta($tenantOito, $primarioOito, $i);
        }

        // Aquecimento: a primeira requisição de cada pessoa guarda na sessão o que é de uma vez só (o idioma).
        $this->contarConsultas($primarioUm, $tenantUm);
        $this->contarConsultas($primarioOito, $tenantOito);

        $comUma  = $this->contarConsultas($primarioUm, $tenantUm);
        $comOito = $this->contarConsultas($primarioOito, $tenantOito);

        self::assertSame(1, $comUma['linhas']);
        self::assertSame(8, $comOito['linhas']);
        // Sobra UMA consulta por pasta, e só ela: o cliente principal (`Pasta::$clientePrincipal`). Cliente tem
        // herança (PF/PJ), e o Doctrine não adia nem agrupa a carga de um "para um" com subclasses; ela sai na
        // própria busca paginada, pasta a pasta. Clientes, marcadores, metas, processos e o nome da pasta não
        // crescem mais. Se este número cair para zero, troque por assertSame($comUma, $comOito).
        $porPastaComCliente = 1;
        self::assertSame(
            $comUma['consultas'] + 7 * $porPastaComCliente,
            $comOito['consultas'],
            'O acervo geral voltou a consultar por pasta.',
        );
    }

    #[TestDox('a Lista mostra os mesmos nomes, metas abertas e marcadores, e respeita o cadeado e o escritório')]
    public function testListaMostraOMesmoConteudo(): void
    {
        $this->client = static::createClient();
        [$primario, $tenant] = $this->criarEscritorio();
        $ana = $this->criarColaborador($tenant, 'Ana Equipe', self::PERMISSOES);

        // A: dois clientes, dois marcadores, ação vinda do processo principal, metas pendente, em revisão,
        // concluída e uma excluída.
        $a = $this->criarPasta($tenant, 'TEXTO LIVRE A');
        $a->setNup('LOTE-A');
        $this->cliente($a, $tenant, 'Alice Primeira');
        $this->cliente($a, $tenant, 'Bruno Segundo');
        $this->marcar($a, $tenant, $primario, 'Urgente');
        $this->marcar($a, $tenant, $primario, 'Revisar');
        $this->processo($a, $tenant, 'Indenização por dano moral');
        $this->processo($a, $tenant, 'Apenso qualquer');
        $this->meta($a, $tenant, $primario, Tarefa::STATUS_PENDENTE);
        $this->meta($a, $tenant, $primario, Tarefa::STATUS_PENDENTE);
        $this->meta($a, $tenant, $primario, Tarefa::STATUS_EM_REVISAO);
        $this->meta($a, $tenant, $primario, Tarefa::STATUS_CONCLUIDA);
        $excluida = $this->meta($a, $tenant, $primario, Tarefa::STATUS_PENDENTE);
        $this->em()->remove($excluida);

        // B: sem cliente cadastrado (vale o texto livre), ação escrita (vence o processo), um marcador, só meta concluída.
        $b = $this->criarPasta($tenant, 'TEXTO LIVRE B');
        $b->setNup('LOTE-B');
        $b->setNomeAcao('Ação escrita à mão');
        $this->marcar($b, $tenant, $primario, 'Arquivo');
        $this->processo($b, $tenant, 'Não aparece');
        $this->meta($b, $tenant, $primario, Tarefa::STATUS_CONCLUIDA);

        // C: cheia, mas oculta para todos pelo cadeado.
        $c = $this->criarPasta($tenant, 'TEXTO LIVRE C');
        $c->setNup('LOTE-C');
        $this->cliente($c, $tenant, 'Carla Oculta');
        $this->marcar($c, $tenant, $primario, 'Segredo');
        $this->processo($c, $tenant, 'Assunto oculto');
        $this->meta($c, $tenant, $primario, Tarefa::STATUS_PENDENTE);
        $this->em()->flush();
        $this->trancar($c, $primario);

        // Outro escritório, com o mesmo tipo de pasta: nunca aparece.
        [$outroPrimario, $outroTenant] = $this->criarEscritorio();
        $x = $this->criarPasta($outroTenant, 'DE FORA');
        $x->setNup('LOTE-X');
        $this->cliente($x, $outroTenant, 'Xavier De Fora');
        $this->marcar($x, $outroTenant, $outroPrimario, 'Alheio');
        $this->meta($x, $outroTenant, $outroPrimario, Tarefa::STATUS_PENDENTE);
        $this->em()->flush();

        $cheiaA = ['nome' => 'ALICE PRIMEIRA', 'oculta' => '0', 'cliente' => 'ALICE PRIMEIRA', 'acao' => 'INDENIZAÇÃO POR DANO MORAL', 'metas' => '3', 'marcadores' => ['Urgente', 'Revisar']];
        $cheiaB = ['nome' => 'TEXTO LIVRE B', 'oculta' => '0', 'cliente' => 'TEXTO LIVRE B', 'acao' => 'AÇÃO ESCRITA À MÃO', 'metas' => '', 'marcadores' => ['Arquivo']];

        self::assertSame([
            'LOTE-C' => ['nome' => 'CARLA OCULTA', 'oculta' => '0', 'cliente' => 'CARLA OCULTA', 'acao' => 'ASSUNTO OCULTO', 'metas' => '1', 'marcadores' => ['Segredo']],
            'LOTE-B' => $cheiaB,
            'LOTE-A' => $cheiaA,
        ], $this->linhas($primario, $tenant));

        self::assertSame([
            'LOTE-C' => ['nome' => 'CARLA OCULTA', 'oculta' => '1', 'cliente' => 'CARLA OCULTA', 'acao' => '', 'metas' => '', 'marcadores' => []],
            'LOTE-B' => $cheiaB,
            'LOTE-A' => $cheiaA,
        ], $this->linhas($ana, $tenant));
    }

    /** @return array{consultas: int, linhas: int} */
    private function contarConsultas(User $quem, Tenant $tenant): array
    {
        $this->logarComTenant($this->client, $quem, $tenant);
        $this->em()->clear();
        $this->client->enableProfiler();
        $crawler = $this->client->xmlHttpRequest('GET', '/expediente/painel/acervo-geral');
        self::assertResponseIsSuccessful();

        $perfil = $this->client->getProfile();
        self::assertNotFalse($perfil);
        /** @var \Doctrine\Bundle\DoctrineBundle\DataCollector\DoctrineDataCollector $db */
        $db = $perfil->getCollector('db');

        return ['consultas' => $db->getQueryCount(), 'linhas' => $crawler->filter('tbody tr[data-pasta-id]')->count()];
    }

    /**
     * O que a linha mostra, por número da pasta, na ordem da tela.
     *
     * @return array<string, array{nome: string, oculta: string, cliente: string, acao: string, metas: string, marcadores: list<string>}>
     */
    private function linhas(User $quem, Tenant $tenant): array
    {
        $this->logarComTenant($this->client, $quem, $tenant);
        $this->em()->clear();
        $crawler = $this->client->xmlHttpRequest('GET', '/expediente/painel/acervo-geral');
        self::assertResponseIsSuccessful();

        $linhas = [];
        $crawler->filter('tbody tr[data-pasta-id]')->each(function ($tr) use (&$linhas): void {
            $metas = $tr->filter('td[data-col="metas"] a[data-metas="abertas"]');
            $marc  = $tr->filter('td[data-col="marc"]');
            $nomes = [];
            if ($marc->count() > 0) {
                $primeiro = (string) $marc->attr('data-sort');
                if ($primeiro !== '') {
                    $nomes[] = $primeiro;
                }
                $extras = $marc->filter('[data-marcadores-json]');
                if ($extras->count() > 0) {
                    foreach (json_decode((string) $extras->attr('data-marcadores-json'), true) as $m) {
                        $nomes[] = $m['nome'];
                    }
                }
            }
            $linhas[(string) $tr->attr('data-nup')] = [
                'nome'       => (string) $tr->attr('data-nome'),
                'oculta'     => (string) $tr->attr('data-oculta'),
                'cliente'    => trim($tr->filter('td[data-col="cliente"] .js-nome-cliente')->text('')),
                'acao'       => trim($tr->filter('td[data-col="acao"]')->text('')),
                'metas'      => $metas->count() > 0 ? trim($metas->text()) : '',
                'marcadores' => array_map(static fn (string $n): string => preg_replace('/ [0-9a-f]{13}$/', '', $n) ?? $n, $nomes),
            ];
        });

        return $linhas;
    }

    private function encherPasta(Tenant $tenant, User $primario, int $i): void
    {
        $pasta = $this->criarPasta($tenant, 'PASTA ' . $i);
        $this->cliente($pasta, $tenant, 'Cliente Um ' . $i);
        $this->cliente($pasta, $tenant, 'Cliente Dois ' . $i);
        $this->marcar($pasta, $tenant, $primario, 'Marca Um');
        $this->marcar($pasta, $tenant, $primario, 'Marca Dois');
        $this->processo($pasta, $tenant, 'Assunto ' . $i);
        $this->meta($pasta, $tenant, $primario, Tarefa::STATUS_PENDENTE);
        $this->meta($pasta, $tenant, $primario, Tarefa::STATUS_EM_REVISAO);
        $this->meta($pasta, $tenant, $primario, Tarefa::STATUS_CONCLUIDA);
        $this->em()->flush();
    }

    private function cliente(Pasta $pasta, Tenant $tenant, string $nome): void
    {
        $cliente = new ClientePF();
        $cliente->setTenant($tenant);
        $cliente->setNomeCompleto($nome);
        $cliente->setCpf('111.222.333-44');
        $cliente->setRg('');
        $cliente->setRgOrgaoExpedidor('');
        $cliente->setEmail('cli_' . uniqid() . '@test.com');
        $cliente->setCep('');
        $cliente->setEndereco('');
        $cliente->setCidade('');
        $cliente->setEstado('');
        $this->em()->persist($cliente);
        $this->em()->flush();

        $pasta->addCliente($cliente);
    }

    private function processo(Pasta $pasta, Tenant $tenant, string $assunto): void
    {
        $processo = new Processo();
        $processo->setTenant($tenant);
        $processo->setNumeroProcesso(sprintf('07%05d-10.2026.8.07.0001', random_int(0, 99999)));
        $processo->setAssuntoProcessual($assunto);
        $this->em()->persist($processo);
        $pasta->vincularProcesso($processo);
        $this->em()->flush();
    }

    private function marcar(Pasta $pasta, Tenant $tenant, User $por, string $nome): void
    {
        // O sufixo deixa o nome único no escritório; a leitura da linha o tira de volta.
        $marcador = new Marcador($nome . ' ' . uniqid(), $tenant, $por);
        $this->em()->persist($marcador);
        $this->em()->flush();

        $pasta->addMarcador($marcador);
    }

    private function meta(Pasta $pasta, Tenant $tenant, User $por, string $status): Tarefa
    {
        $meta = new Tarefa();
        $meta->setTitulo('Meta ' . $status);
        $meta->setDescricao('Meta do teste');
        $meta->setPasta($pasta);
        $meta->setTenant($tenant);
        $meta->setCriadoPor($por);
        $meta->setStatus($status);
        $this->em()->persist($meta);
        $this->em()->flush();

        return $meta;
    }
}
