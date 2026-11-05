<?php

declare(strict_types=1);

namespace App\Tests\Tarefa\Functional;

use App\Cliente\Entity\ClientePF;
use App\Controller\TarefaController;
use App\Entity\Auth\User;
use App\Entity\Tarefa\Tarefa;
use App\Entity\Tenant\Tenant;
use App\Pasta\Entity\NivelDeAcesso;
use App\Pasta\Entity\Pasta;
use App\Tarefa\Entity\CaixaDaEsteira;
use App\Tarefa\Enum\EtapaDaPasta;
use App\Tarefa\Repository\TarefaRepository;
use App\Tarefa\UseCase\ListarMinhasMetasUseCase;
use App\Tests\Functional\JusPrimeWebTestCase;
use App\Tests\Pasta\Functional\CriaCenarioDoCadeadoFuncionalTrait;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Component\DomCrawler\Crawler;

/**
 * Minhas Metas (GET /tarefas/minhas) lê de uma vez a pasta, o criador, os responsáveis e os clientes das metas da
 * lista, e as caixas da esteira uma vez só (PR 4c da revisão de queries). Trava duas coisas: o número de consultas
 * não cresce com o número de metas (abas Sou responsável, Acompanhando, Todas e a lista da esteira com `?etapa=`),
 * e a tela mostra exatamente o mesmo de antes para o primário, o colaborador, quem só acompanha meta de pasta que
 * não vê, quem está fora do cadeado e a esteira vista por quem não vê a pasta.
 */
#[CoversClass(TarefaController::class)]
#[CoversClass(ListarMinhasMetasUseCase::class)]
#[CoversClass(TarefaRepository::class)]
final class MinhasMetasEmLoteTest extends JusPrimeWebTestCase
{
    use CriaCenarioDoCadeadoFuncionalTrait;

    private const PERMISSOES = [
        'modules.tarefas.view', 'modules.pastas.view', 'modules.expediente.view',
        'resources.pasta.view', 'resources.pasta.edit',
    ];

    private const URLS = [
        'responsavel'  => '/tarefas/minhas?aba=responsavel',
        'acompanhando' => '/tarefas/minhas?aba=acompanhando',
        'todas'        => '/tarefas/minhas?aba=todas',
        'esteira'      => '/tarefas/minhas?aba=todas&etapa=protocolo',
    ];

    private int $cpf = 0;

    #[TestDox('Minhas Metas faz o mesmo número de consultas com 1 e com 8 metas cheias, em cada aba e na esteira')]
    public function testConsultasNaoCrescemComAsMetas(): void
    {
        $this->client = static::createClient();

        // Dois escritórios iguais, um com 1 meta e outro com 8: mesmas pessoas, mesmo papel.
        [$primarioUm, $tenantUm, $anaUm]       = $this->escritorioComMetas(1);
        [$primarioOito, $tenantOito, $anaOito] = $this->escritorioComMetas(8);

        foreach ([[$primarioUm, $primarioOito], [$anaUm, $anaOito]] as [$quemUm, $quemOito]) {
            foreach (self::URLS as $nome => $url) {
                // Aquecimento: a primeira requisição de cada pessoa guarda na sessão o que é de uma vez só.
                $this->contarConsultas($quemUm, $tenantUm, $url);
                $this->contarConsultas($quemOito, $tenantOito, $url);

                $comUma  = $this->contarConsultas($quemUm, $tenantUm, $url);
                $comOito = $this->contarConsultas($quemOito, $tenantOito, $url);

                self::assertSame(1, $comUma['metas'], $nome);
                self::assertSame(8, $comOito['metas'], $nome);
                self::assertSame($comUma['consultas'], $comOito['consultas'], sprintf('Minhas Metas (%s, %s) voltou a consultar por meta.', $nome, $quemUm->getFullName()));
            }
        }
    }

    #[TestDox('a tela mostra as mesmas metas, abas, contagens, KPIs, trilho e esteira, e respeita o cadeado')]
    public function testTelaMostraOMesmoConteudo(): void
    {
        $this->client = static::createClient();
        [$paula, $tenant] = $this->criarEscritorio();
        $ana    = $this->criarColaborador($tenant, 'Ana Equipe', self::PERMISSOES);
        $bruno  = $this->criarColaborador($tenant, 'Bruno Criador', self::PERMISSOES);
        $carlos = $this->criarColaborador($tenant, 'Carlos Sem Ver', ['modules.tarefas.view']);
        $diego  = $this->criarColaborador($tenant, 'Diego De Fora', self::PERMISSOES);
        $this->em()->flush();

        $this->cenarioCheio($tenant, $paula, $ana, $bruno, $carlos, $diego);

        $retrato = [];
        foreach (['primario' => $paula, 'colaborador' => $ana, 'soAcompanha' => $carlos, 'foraDoCadeado' => $diego] as $quem => $pessoa) {
            foreach (self::URLS as $nome => $url) {
                $retrato[$quem][$nome] = $this->tela($pessoa, $tenant, $url);
            }
        }

        foreach ($this->esperado() as $quem => $telas) {
            foreach ($telas as $nome => $tela) {
                self::assertSame($tela, $retrato[$quem][$nome], $quem . ' / ' . $nome);
            }
        }
    }

    /** @return array{0: User, 1: Tenant, 2: User} */
    private function escritorioComMetas(int $quantas): array
    {
        [$primario, $tenant] = $this->criarEscritorio();
        $ana   = $this->criarColaborador($tenant, 'Ana Equipe', self::PERMISSOES);
        $bruno = $this->criarColaborador($tenant, 'Bruno Criador', self::PERMISSOES);
        $this->em()->flush();

        for ($i = 0; $i < $quantas; ++$i) {
            $pasta = $this->pasta($tenant, 'CONT-' . $i, ['CLIENTE CONTAGEM ' . $i]);
            $meta  = $this->meta($pasta, $tenant, $bruno, 'Meta ' . $i, [$ana, $primario], EtapaDaPasta::Protocolo, new \DateTimeImmutable('+' . ($i + 1) . ' days'));
            $meta->alternarAcompanhamento($ana);
            $meta->alternarAcompanhamento($primario);
        }
        $this->em()->flush();

        return [$primario, $tenant, $ana];
    }

    /**
     * Metas em pastas com e sem cliente, responsáveis gravados fora da ordem do id, meta atrasada, concluída, de pasta
     * trancada, de pasta que o Carlos não vê (uma ele só acompanha, a outra ele cuida) e a caixa Protocolo renomeada.
     */
    private function cenarioCheio(Tenant $tenant, User $paula, User $ana, User $bruno, User $carlos, User $diego): void
    {
        $p1 = $this->pasta($tenant, 'LOTE-1', ['MARIA LOTE']);
        $p2 = $this->pasta($tenant, 'LOTE-2', ['ZECA PRIMEIRO', 'ALICE SEGUNDA']);
        $p3 = $this->pasta($tenant, 'LOTE-3', ['TRANCADO CLIENTE']);
        $p4 = $this->pasta($tenant, 'LOTE-4', []);

        $this->meta($p1, $tenant, $bruno, 'Petição inicial', [$diego, $ana, $paula], EtapaDaPasta::Protocolo, new \DateTimeImmutable('+3 days'));
        $this->meta($p2, $tenant, $paula, 'Juntar documentos', [$ana], EtapaDaPasta::Juridico, new \DateTimeImmutable('-2 days'));
        $this->meta($p3, $tenant, $paula, 'Meta trancada', [$diego, $ana], EtapaDaPasta::Protocolo, new \DateTimeImmutable('+5 days'));
        $acompanhada = $this->meta($p4, $tenant, $paula, 'Meta só acompanhada', [$paula], EtapaDaPasta::Protocolo, null);
        $acompanhada->alternarAcompanhamento($carlos);
        $acompanhada->alternarAcompanhamento($ana);
        $this->meta($p4, $tenant, $paula, 'Meta do Carlos', [$carlos], EtapaDaPasta::Protocolo, new \DateTimeImmutable('-1 day'));
        $concluida = $this->meta($p1, $tenant, $bruno, 'Meta concluída', [$ana, $diego], EtapaDaPasta::Juridico, new \DateTimeImmutable('-4 days'));
        $concluida->setStatus(Tarefa::STATUS_CONCLUIDA);
        $concluida->setDataConclusao(new \DateTimeImmutable('-1 day'));

        $caixa = new CaixaDaEsteira($tenant, EtapaDaPasta::Protocolo);
        $caixa->configurar('Caixa do protocolo', 'Protocolar já', [$ana]);
        $this->em()->persist($caixa);
        $this->em()->flush();

        // Cadeado por pessoa na pasta 3: Ana edita; Diego (responsável da meta trancada) fica de fora.
        $this->trancar($p3, $paula, [[$ana, NivelDeAcesso::Editar]]);
    }

    /** @param list<string> $clientes */
    private function pasta(Tenant $tenant, string $nup, array $clientes): Pasta
    {
        $pasta = $this->criarPasta($tenant, 'TEXTO LIVRE ' . $nup);
        $pasta->setNup($nup);
        foreach ($clientes as $nome) {
            $pasta->addCliente($this->cliente($tenant, $nome));
        }
        $this->em()->flush();

        return $pasta;
    }

    private function cliente(Tenant $tenant, string $nome): ClientePF
    {
        $cliente = new ClientePF();
        $cliente->setTenant($tenant);
        $cliente->setNomeCompleto($nome);
        $cliente->setCpf(sprintf('%011d', 70000000000 + ++$this->cpf + random_int(0, 999999) * 1000));
        $cliente->setRg('');
        $cliente->setRgOrgaoExpedidor('');
        $cliente->setEmail('cli_' . uniqid() . '@test.com');
        $cliente->setCep('');
        $cliente->setEndereco('');
        $cliente->setCidade('');
        $cliente->setEstado('');
        $this->em()->persist($cliente);
        $this->em()->flush();

        return $cliente;
    }

    /** @param list<User> $responsaveis */
    private function meta(Pasta $pasta, Tenant $tenant, User $criador, string $titulo, array $responsaveis, EtapaDaPasta $etapa, ?\DateTimeImmutable $prazo): Tarefa
    {
        $meta = new Tarefa();
        $meta->setTitulo($titulo);
        $meta->setDescricao('Meta do teste');
        $meta->setPasta($pasta);
        $meta->setTenant($tenant);
        $meta->setCriadoPor($criador);
        $meta->setEtapa($etapa);
        $meta->setPrazo($prazo?->setTime(0, 0));
        foreach ($responsaveis as $responsavel) {
            $meta->addResponsavel($responsavel);
        }
        $this->em()->persist($meta);
        $this->em()->flush();

        return $meta;
    }

    /** @return array{consultas: int, metas: int} */
    private function contarConsultas(User $quem, Tenant $tenant, string $url): array
    {
        $this->logarComTenant($this->client, $quem, $tenant);
        $this->em()->clear();
        $this->client->enableProfiler();
        $crawler = $this->client->request('GET', $url);
        self::assertResponseIsSuccessful();

        $perfil = $this->client->getProfile();
        self::assertNotFalse($perfil);
        /** @var \Doctrine\Bundle\DoctrineBundle\DataCollector\DoctrineDataCollector $db */
        $db = $perfil->getCollector('db');

        return ['consultas' => $db->getQueryCount(), 'metas' => $crawler->filter('article.mm-meta')->count()];
    }

    /**
     * O que a tela mostra das partes tocadas pela leitura em lote.
     *
     * @return array<string, mixed>
     */
    private function tela(User $quem, Tenant $tenant, string $url): array
    {
        $this->logarComTenant($this->client, $quem, $tenant);
        $this->em()->clear();
        $crawler = $this->client->request('GET', $url);
        self::assertResponseIsSuccessful();

        return [
            'abas'    => $crawler->filter('.mm-abas a.mm-aba')->each(static fn (Crawler $a): string => $a->attr('data-aba') . ' ' . self::texto($a->filter('.mm-aba-badge'))),
            'kpis'    => $crawler->filter('.mm-kpis a.mm-kpi')->each(static fn (Crawler $k): string => self::texto($k)),
            'esteira' => $crawler->filter('.mm-esteira .mm-caixa')->each(static fn (Crawler $c): string => self::texto($c)),
            'chip'    => self::texto($crawler->filter('.mm-esteira-chip')),
            'grupos'  => $crawler->filter('section.mm-grupo')->each(static fn (Crawler $g): array => [
                'cabeca' => self::texto($g->filter('.mm-grupo-cabeca')),
                'metas'  => $g->filter('article.mm-meta')->each(static fn (Crawler $m): array => [
                    'texto'        => self::texto($m),
                    'pasta'        => (string) $m->filter('.mm-meta-pasta')->attr('title'),
                    'concluir'     => $m->filter('.mp-cartao-concluir')->count(),
                    'acompanhando' => (string) $m->filter('.mm-marcador')->attr('aria-pressed'),
                ]),
            ]),
            'trilho'  => self::texto($crawler->filter('.mm-trilho')),
        ];
    }

    private static function texto(Crawler $no): string
    {
        if ($no->count() === 0) {
            return '';
        }

        return self::normalizar($no->text(''));
    }

    /** Datas variam com o dia em que o teste roda: saem do retrato. */
    private static function normalizar(string $texto): string
    {
        $texto = preg_replace('/\s+/u', ' ', trim($texto));

        return (string) preg_replace(['#\d{2}/\d{2}(/\d{2,4})?#', '/\d{2}:\d{2}/'], ['DATA', 'HORA'], (string) $texto);
    }

    /**
     * O retrato tirado do código de ANTES da leitura em lote (este mesmo teste, rodado com os arquivos de `src` do
     * master): a tela tem de continuar igual.
     *
     * @return array<string, array<string, array<string, mixed>>>
     */
    private function esperado(): array
    {
        return [
            'primario' => [
                'responsavel' => [
                    'abas' => [
                        'responsavel 2',
                        'criei 4',
                        'acompanhando 0',
                        'todas 5',
                    ],
                    'kpis' => [
                        'Atrasadas sob sua responsabilidade 0',
                        'Vencem em 7 dias ainda dá tempo 1',
                        'Aguardando sua revisão criadas por você 0',
                        'Sem prazo abertas sem data definida 1',
                    ],
                    'esteira' => [
                        '0 Comercial Comercial CAIXA 1',
                        '0 Abertura e relatório Atendimento CAIXA 2',
                        '0 Controladoria Controladoria CAIXA 3',
                        '0 Documentação Secretaria CAIXA 4',
                        '1 Gargalo Jurídico Advogado(a) 1 atrasada CAIXA 5',
                        '4 Protocolar já Advogado(a) 1 atrasada Ana Equipe Caixa do protocolo',
                        '0 Acompanhamento Advogado(a) CAIXA 7',
                        '0 Encerramento Controladoria CAIXA 8',
                    ],
                    'chip' => '',
                    'grupos' => [
                        [
                            'cabeca' => 'Vencem em 7 dias 1',
                            'metas' => [
                                [
                                    'texto' => '1.Petição inicial LOTE-1 · MARIA LOTE de Bruno Criador vence em 3 dia(s) · DATA 6/8 · Protocolar já próximo: Acompanhamento · Advogado(a) Vence em breve criada DATA Concluir etapa',
                                    'pasta' => 'MARIA LOTE',
                                    'concluir' => 1,
                                    'acompanhando' => 'false',
                                ],
                            ],
                        ],
                        [
                            'cabeca' => 'Sem prazo 1',
                            'metas' => [
                                [
                                    'texto' => '1.Meta só acompanhada LOTE-4 · TEXTO LIVRE LOTE-4 de Paula Primária sem prazo definido 6/8 · Protocolar já próximo: Acompanhamento · Advogado(a) Sem prazo criada DATA Concluir etapa',
                                    'pasta' => 'TEXTO LIVRE LOTE-4',
                                    'concluir' => 1,
                                    'acompanhando' => 'false',
                                ],
                            ],
                        ],
                    ],
                    'trilho' => 'Seu andamento últimos 30 dias 0 de 5 concluídas 0 concluída(s) 2 atrasada(s) 0 em revisão Precisa de atenção Juntar documentos · Pasta LOTE-2 prazo DATA · 2 dia(s) atrasado · de Paula Primária Meta do Carlos · Pasta LOTE-4 prazo DATA · 1 dia(s) atrasado · de Paula Primária',
                ],
                'acompanhando' => [
                    'abas' => [
                        'responsavel 2',
                        'criei 4',
                        'acompanhando 0',
                        'todas 5',
                    ],
                    'kpis' => [
                        'Atrasadas sob sua responsabilidade 0',
                        'Vencem em 7 dias ainda dá tempo 1',
                        'Aguardando sua revisão criadas por você 0',
                        'Sem prazo abertas sem data definida 1',
                    ],
                    'esteira' => [
                        '0 Comercial Comercial CAIXA 1',
                        '0 Abertura e relatório Atendimento CAIXA 2',
                        '0 Controladoria Controladoria CAIXA 3',
                        '0 Documentação Secretaria CAIXA 4',
                        '1 Gargalo Jurídico Advogado(a) 1 atrasada CAIXA 5',
                        '4 Protocolar já Advogado(a) 1 atrasada Ana Equipe Caixa do protocolo',
                        '0 Acompanhamento Advogado(a) CAIXA 7',
                        '0 Encerramento Controladoria CAIXA 8',
                    ],
                    'chip' => '',
                    'grupos' => [],
                    'trilho' => 'Seu andamento últimos 30 dias 0 de 5 concluídas 0 concluída(s) 2 atrasada(s) 0 em revisão Precisa de atenção Juntar documentos · Pasta LOTE-2 prazo DATA · 2 dia(s) atrasado · de Paula Primária Meta do Carlos · Pasta LOTE-4 prazo DATA · 1 dia(s) atrasado · de Paula Primária',
                ],
                'todas' => [
                    'abas' => [
                        'responsavel 2',
                        'criei 4',
                        'acompanhando 0',
                        'todas 5',
                    ],
                    'kpis' => [
                        'Atrasadas sob sua responsabilidade 0',
                        'Vencem em 7 dias ainda dá tempo 1',
                        'Aguardando sua revisão criadas por você 0',
                        'Sem prazo abertas sem data definida 1',
                    ],
                    'esteira' => [
                        '0 Comercial Comercial CAIXA 1',
                        '0 Abertura e relatório Atendimento CAIXA 2',
                        '0 Controladoria Controladoria CAIXA 3',
                        '0 Documentação Secretaria CAIXA 4',
                        '1 Gargalo Jurídico Advogado(a) 1 atrasada CAIXA 5',
                        '4 Protocolar já Advogado(a) 1 atrasada Ana Equipe Caixa do protocolo',
                        '0 Acompanhamento Advogado(a) CAIXA 7',
                        '0 Encerramento Controladoria CAIXA 8',
                    ],
                    'chip' => '',
                    'grupos' => [
                        [
                            'cabeca' => 'Atrasadas 2',
                            'metas' => [
                                [
                                    'texto' => '1.Juntar documentos LOTE-2 · ZECA PRIMEIRO de Paula Primária 2 dia(s) atrasado 5/8 · Jurídico próximo: Protocolar já · Advogado(a) Atrasada criada DATA Concluir etapa',
                                    'pasta' => 'ZECA PRIMEIRO',
                                    'concluir' => 1,
                                    'acompanhando' => 'false',
                                ],
                                [
                                    'texto' => '2.Meta do Carlos LOTE-4 · TEXTO LIVRE LOTE-4 de Paula Primária 1 dia(s) atrasado 6/8 · Protocolar já próximo: Acompanhamento · Advogado(a) Atrasada criada DATA Concluir etapa',
                                    'pasta' => 'TEXTO LIVRE LOTE-4',
                                    'concluir' => 1,
                                    'acompanhando' => 'false',
                                ],
                            ],
                        ],
                        [
                            'cabeca' => 'Vencem em 7 dias 2',
                            'metas' => [
                                [
                                    'texto' => '1.Petição inicial LOTE-1 · MARIA LOTE de Bruno Criador vence em 3 dia(s) · DATA 6/8 · Protocolar já próximo: Acompanhamento · Advogado(a) Vence em breve criada DATA Concluir etapa',
                                    'pasta' => 'MARIA LOTE',
                                    'concluir' => 1,
                                    'acompanhando' => 'false',
                                ],
                                [
                                    'texto' => '2.Meta trancada LOTE-3 · TRANCADO CLIENTE de Paula Primária vence em 5 dia(s) · DATA 6/8 · Protocolar já próximo: Acompanhamento · Advogado(a) Vence em breve criada DATA Concluir etapa',
                                    'pasta' => 'TRANCADO CLIENTE',
                                    'concluir' => 1,
                                    'acompanhando' => 'false',
                                ],
                            ],
                        ],
                        [
                            'cabeca' => 'Sem prazo 1',
                            'metas' => [
                                [
                                    'texto' => '1.Meta só acompanhada LOTE-4 · TEXTO LIVRE LOTE-4 de Paula Primária sem prazo definido 6/8 · Protocolar já próximo: Acompanhamento · Advogado(a) Sem prazo criada DATA Concluir etapa',
                                    'pasta' => 'TEXTO LIVRE LOTE-4',
                                    'concluir' => 1,
                                    'acompanhando' => 'false',
                                ],
                            ],
                        ],
                    ],
                    'trilho' => 'Seu andamento últimos 30 dias 0 de 5 concluídas 0 concluída(s) 2 atrasada(s) 0 em revisão Precisa de atenção Juntar documentos · Pasta LOTE-2 prazo DATA · 2 dia(s) atrasado · de Paula Primária Meta do Carlos · Pasta LOTE-4 prazo DATA · 1 dia(s) atrasado · de Paula Primária',
                ],
                'esteira' => [
                    'abas' => [
                        'responsavel 2',
                        'criei 4',
                        'acompanhando 0',
                        'todas 5',
                    ],
                    'kpis' => [
                        'Atrasadas sob sua responsabilidade 0',
                        'Vencem em 7 dias ainda dá tempo 1',
                        'Aguardando sua revisão criadas por você 0',
                        'Sem prazo abertas sem data definida 1',
                    ],
                    'esteira' => [
                        '0 Comercial Comercial CAIXA 1',
                        '0 Abertura e relatório Atendimento CAIXA 2',
                        '0 Controladoria Controladoria CAIXA 3',
                        '0 Documentação Secretaria CAIXA 4',
                        '1 Gargalo Jurídico Advogado(a) 1 atrasada CAIXA 5',
                        '4 Protocolar já Advogado(a) 1 atrasada Ana Equipe Caixa do protocolo',
                        '0 Acompanhamento Advogado(a) CAIXA 7',
                        '0 Encerramento Controladoria CAIXA 8',
                    ],
                    'chip' => 'Só a etapa Protocolar já',
                    'grupos' => [
                        [
                            'cabeca' => 'Atrasadas 1',
                            'metas' => [
                                [
                                    'texto' => '1.Meta do Carlos LOTE-4 · TEXTO LIVRE LOTE-4 de Paula Primária 1 dia(s) atrasado 6/8 · Protocolar já próximo: Acompanhamento · Advogado(a) Atrasada criada DATA Concluir etapa',
                                    'pasta' => 'TEXTO LIVRE LOTE-4',
                                    'concluir' => 1,
                                    'acompanhando' => 'false',
                                ],
                            ],
                        ],
                        [
                            'cabeca' => 'Vencem em 7 dias 2',
                            'metas' => [
                                [
                                    'texto' => '1.Petição inicial LOTE-1 · MARIA LOTE de Bruno Criador vence em 3 dia(s) · DATA 6/8 · Protocolar já próximo: Acompanhamento · Advogado(a) Vence em breve criada DATA Concluir etapa',
                                    'pasta' => 'MARIA LOTE',
                                    'concluir' => 1,
                                    'acompanhando' => 'false',
                                ],
                                [
                                    'texto' => '2.Meta trancada LOTE-3 · TRANCADO CLIENTE de Paula Primária vence em 5 dia(s) · DATA 6/8 · Protocolar já próximo: Acompanhamento · Advogado(a) Vence em breve criada DATA Concluir etapa',
                                    'pasta' => 'TRANCADO CLIENTE',
                                    'concluir' => 1,
                                    'acompanhando' => 'false',
                                ],
                            ],
                        ],
                        [
                            'cabeca' => 'Sem prazo 1',
                            'metas' => [
                                [
                                    'texto' => '1.Meta só acompanhada LOTE-4 · TEXTO LIVRE LOTE-4 de Paula Primária sem prazo definido 6/8 · Protocolar já próximo: Acompanhamento · Advogado(a) Sem prazo criada DATA Concluir etapa',
                                    'pasta' => 'TEXTO LIVRE LOTE-4',
                                    'concluir' => 1,
                                    'acompanhando' => 'false',
                                ],
                            ],
                        ],
                    ],
                    'trilho' => 'Seu andamento últimos 30 dias 0 de 5 concluídas 0 concluída(s) 2 atrasada(s) 0 em revisão Precisa de atenção Juntar documentos · Pasta LOTE-2 prazo DATA · 2 dia(s) atrasado · de Paula Primária Meta do Carlos · Pasta LOTE-4 prazo DATA · 1 dia(s) atrasado · de Paula Primária',
                ],
            ],
            'colaborador' => [
                'responsavel' => [
                    'abas' => [
                        'responsavel 3',
                        'criei 0',
                        'acompanhando 1',
                        'todas 4',
                    ],
                    'kpis' => [
                        'Atrasadas sob sua responsabilidade 1',
                        'Vencem em 7 dias ainda dá tempo 2',
                        'Aguardando sua revisão criadas por você 0',
                        'Sem prazo abertas sem data definida 0',
                    ],
                    'esteira' => [
                        '0 Comercial Comercial CAIXA 1',
                        '0 Abertura e relatório Atendimento CAIXA 2',
                        '0 Controladoria Controladoria CAIXA 3',
                        '0 Documentação Secretaria CAIXA 4',
                        '1 Gargalo Jurídico Advogado(a) 1 atrasada CAIXA 5',
                        '4 Protocolar já Advogado(a) 1 atrasada Ana Equipe Caixa do protocolo',
                        '0 Acompanhamento Advogado(a) CAIXA 7',
                        '0 Encerramento Controladoria CAIXA 8',
                    ],
                    'chip' => '',
                    'grupos' => [
                        [
                            'cabeca' => 'Atrasadas 1',
                            'metas' => [
                                [
                                    'texto' => '1.Juntar documentos LOTE-2 · ZECA PRIMEIRO de Paula Primária 2 dia(s) atrasado 5/8 · Jurídico próximo: Protocolar já · Advogado(a) Atrasada criada DATA Concluir etapa',
                                    'pasta' => 'ZECA PRIMEIRO',
                                    'concluir' => 1,
                                    'acompanhando' => 'false',
                                ],
                            ],
                        ],
                        [
                            'cabeca' => 'Vencem em 7 dias 2',
                            'metas' => [
                                [
                                    'texto' => '1.Petição inicial LOTE-1 · MARIA LOTE de Bruno Criador vence em 3 dia(s) · DATA 6/8 · Protocolar já próximo: Acompanhamento · Advogado(a) Vence em breve criada DATA Concluir etapa',
                                    'pasta' => 'MARIA LOTE',
                                    'concluir' => 1,
                                    'acompanhando' => 'false',
                                ],
                                [
                                    'texto' => '2.Meta trancada LOTE-3 · TRANCADO CLIENTE de Paula Primária vence em 5 dia(s) · DATA 6/8 · Protocolar já próximo: Acompanhamento · Advogado(a) Vence em breve criada DATA Concluir etapa',
                                    'pasta' => 'TRANCADO CLIENTE',
                                    'concluir' => 1,
                                    'acompanhando' => 'false',
                                ],
                            ],
                        ],
                        [
                            'cabeca' => 'Concluídas 1',
                            'metas' => [
                                [
                                    'texto' => '1.Meta concluída LOTE-1 · MARIA LOTE de Bruno Criador concluída 5/8 · Jurídico Concluída criada DATA',
                                    'pasta' => 'MARIA LOTE',
                                    'concluir' => 0,
                                    'acompanhando' => 'false',
                                ],
                            ],
                        ],
                    ],
                    'trilho' => 'Seu andamento últimos 30 dias 1 de 5 concluídas 1 concluída(s) 1 atrasada(s) 0 em revisão Precisa de atenção Juntar documentos · Pasta LOTE-2 prazo DATA · 2 dia(s) atrasado · de Paula Primária',
                ],
                'acompanhando' => [
                    'abas' => [
                        'responsavel 3',
                        'criei 0',
                        'acompanhando 1',
                        'todas 4',
                    ],
                    'kpis' => [
                        'Atrasadas sob sua responsabilidade 1',
                        'Vencem em 7 dias ainda dá tempo 2',
                        'Aguardando sua revisão criadas por você 0',
                        'Sem prazo abertas sem data definida 0',
                    ],
                    'esteira' => [
                        '0 Comercial Comercial CAIXA 1',
                        '0 Abertura e relatório Atendimento CAIXA 2',
                        '0 Controladoria Controladoria CAIXA 3',
                        '0 Documentação Secretaria CAIXA 4',
                        '1 Gargalo Jurídico Advogado(a) 1 atrasada CAIXA 5',
                        '4 Protocolar já Advogado(a) 1 atrasada Ana Equipe Caixa do protocolo',
                        '0 Acompanhamento Advogado(a) CAIXA 7',
                        '0 Encerramento Controladoria CAIXA 8',
                    ],
                    'chip' => '',
                    'grupos' => [
                        [
                            'cabeca' => 'Sem prazo 1',
                            'metas' => [
                                [
                                    'texto' => '1.Meta só acompanhada LOTE-4 · TEXTO LIVRE LOTE-4 de Paula Primária sem prazo definido 6/8 · Protocolar já próximo: Acompanhamento · Advogado(a) Sem prazo criada DATA',
                                    'pasta' => 'TEXTO LIVRE LOTE-4',
                                    'concluir' => 0,
                                    'acompanhando' => 'true',
                                ],
                            ],
                        ],
                    ],
                    'trilho' => 'Seu andamento últimos 30 dias 1 de 5 concluídas 1 concluída(s) 1 atrasada(s) 0 em revisão Precisa de atenção Juntar documentos · Pasta LOTE-2 prazo DATA · 2 dia(s) atrasado · de Paula Primária',
                ],
                'todas' => [
                    'abas' => [
                        'responsavel 3',
                        'criei 0',
                        'acompanhando 1',
                        'todas 4',
                    ],
                    'kpis' => [
                        'Atrasadas sob sua responsabilidade 1',
                        'Vencem em 7 dias ainda dá tempo 2',
                        'Aguardando sua revisão criadas por você 0',
                        'Sem prazo abertas sem data definida 0',
                    ],
                    'esteira' => [
                        '0 Comercial Comercial CAIXA 1',
                        '0 Abertura e relatório Atendimento CAIXA 2',
                        '0 Controladoria Controladoria CAIXA 3',
                        '0 Documentação Secretaria CAIXA 4',
                        '1 Gargalo Jurídico Advogado(a) 1 atrasada CAIXA 5',
                        '4 Protocolar já Advogado(a) 1 atrasada Ana Equipe Caixa do protocolo',
                        '0 Acompanhamento Advogado(a) CAIXA 7',
                        '0 Encerramento Controladoria CAIXA 8',
                    ],
                    'chip' => '',
                    'grupos' => [
                        [
                            'cabeca' => 'Atrasadas 1',
                            'metas' => [
                                [
                                    'texto' => '1.Juntar documentos LOTE-2 · ZECA PRIMEIRO de Paula Primária 2 dia(s) atrasado 5/8 · Jurídico próximo: Protocolar já · Advogado(a) Atrasada criada DATA Concluir etapa',
                                    'pasta' => 'ZECA PRIMEIRO',
                                    'concluir' => 1,
                                    'acompanhando' => 'false',
                                ],
                            ],
                        ],
                        [
                            'cabeca' => 'Vencem em 7 dias 2',
                            'metas' => [
                                [
                                    'texto' => '1.Petição inicial LOTE-1 · MARIA LOTE de Bruno Criador vence em 3 dia(s) · DATA 6/8 · Protocolar já próximo: Acompanhamento · Advogado(a) Vence em breve criada DATA Concluir etapa',
                                    'pasta' => 'MARIA LOTE',
                                    'concluir' => 1,
                                    'acompanhando' => 'false',
                                ],
                                [
                                    'texto' => '2.Meta trancada LOTE-3 · TRANCADO CLIENTE de Paula Primária vence em 5 dia(s) · DATA 6/8 · Protocolar já próximo: Acompanhamento · Advogado(a) Vence em breve criada DATA Concluir etapa',
                                    'pasta' => 'TRANCADO CLIENTE',
                                    'concluir' => 1,
                                    'acompanhando' => 'false',
                                ],
                            ],
                        ],
                        [
                            'cabeca' => 'Sem prazo 1',
                            'metas' => [
                                [
                                    'texto' => '1.Meta só acompanhada LOTE-4 · TEXTO LIVRE LOTE-4 de Paula Primária sem prazo definido 6/8 · Protocolar já próximo: Acompanhamento · Advogado(a) Sem prazo criada DATA',
                                    'pasta' => 'TEXTO LIVRE LOTE-4',
                                    'concluir' => 0,
                                    'acompanhando' => 'true',
                                ],
                            ],
                        ],
                        [
                            'cabeca' => 'Concluídas 1',
                            'metas' => [
                                [
                                    'texto' => '1.Meta concluída LOTE-1 · MARIA LOTE de Bruno Criador concluída 5/8 · Jurídico Concluída criada DATA',
                                    'pasta' => 'MARIA LOTE',
                                    'concluir' => 0,
                                    'acompanhando' => 'false',
                                ],
                            ],
                        ],
                    ],
                    'trilho' => 'Seu andamento últimos 30 dias 1 de 5 concluídas 1 concluída(s) 1 atrasada(s) 0 em revisão Precisa de atenção Juntar documentos · Pasta LOTE-2 prazo DATA · 2 dia(s) atrasado · de Paula Primária',
                ],
                'esteira' => [
                    'abas' => [
                        'responsavel 3',
                        'criei 0',
                        'acompanhando 1',
                        'todas 4',
                    ],
                    'kpis' => [
                        'Atrasadas sob sua responsabilidade 1',
                        'Vencem em 7 dias ainda dá tempo 2',
                        'Aguardando sua revisão criadas por você 0',
                        'Sem prazo abertas sem data definida 0',
                    ],
                    'esteira' => [
                        '0 Comercial Comercial CAIXA 1',
                        '0 Abertura e relatório Atendimento CAIXA 2',
                        '0 Controladoria Controladoria CAIXA 3',
                        '0 Documentação Secretaria CAIXA 4',
                        '1 Gargalo Jurídico Advogado(a) 1 atrasada CAIXA 5',
                        '4 Protocolar já Advogado(a) 1 atrasada Ana Equipe Caixa do protocolo',
                        '0 Acompanhamento Advogado(a) CAIXA 7',
                        '0 Encerramento Controladoria CAIXA 8',
                    ],
                    'chip' => 'Só a etapa Protocolar já',
                    'grupos' => [
                        [
                            'cabeca' => 'Atrasadas 1',
                            'metas' => [
                                [
                                    'texto' => '1.Meta do Carlos LOTE-4 · TEXTO LIVRE LOTE-4 de Paula Primária 1 dia(s) atrasado 6/8 · Protocolar já próximo: Acompanhamento · Advogado(a) Atrasada criada DATA',
                                    'pasta' => 'TEXTO LIVRE LOTE-4',
                                    'concluir' => 0,
                                    'acompanhando' => 'false',
                                ],
                            ],
                        ],
                        [
                            'cabeca' => 'Vencem em 7 dias 2',
                            'metas' => [
                                [
                                    'texto' => '1.Petição inicial LOTE-1 · MARIA LOTE de Bruno Criador vence em 3 dia(s) · DATA 6/8 · Protocolar já próximo: Acompanhamento · Advogado(a) Vence em breve criada DATA Concluir etapa',
                                    'pasta' => 'MARIA LOTE',
                                    'concluir' => 1,
                                    'acompanhando' => 'false',
                                ],
                                [
                                    'texto' => '2.Meta trancada LOTE-3 · TRANCADO CLIENTE de Paula Primária vence em 5 dia(s) · DATA 6/8 · Protocolar já próximo: Acompanhamento · Advogado(a) Vence em breve criada DATA Concluir etapa',
                                    'pasta' => 'TRANCADO CLIENTE',
                                    'concluir' => 1,
                                    'acompanhando' => 'false',
                                ],
                            ],
                        ],
                        [
                            'cabeca' => 'Sem prazo 1',
                            'metas' => [
                                [
                                    'texto' => '1.Meta só acompanhada LOTE-4 · TEXTO LIVRE LOTE-4 de Paula Primária sem prazo definido 6/8 · Protocolar já próximo: Acompanhamento · Advogado(a) Sem prazo criada DATA',
                                    'pasta' => 'TEXTO LIVRE LOTE-4',
                                    'concluir' => 0,
                                    'acompanhando' => 'true',
                                ],
                            ],
                        ],
                    ],
                    'trilho' => 'Seu andamento últimos 30 dias 1 de 5 concluídas 1 concluída(s) 1 atrasada(s) 0 em revisão Precisa de atenção Juntar documentos · Pasta LOTE-2 prazo DATA · 2 dia(s) atrasado · de Paula Primária',
                ],
            ],
            'soAcompanha' => [
                'responsavel' => [
                    'abas' => [
                        'responsavel 1',
                        'criei 0',
                        'acompanhando 0',
                        'todas 1',
                    ],
                    'kpis' => [
                        'Atrasadas sob sua responsabilidade 1',
                        'Vencem em 7 dias ainda dá tempo 0',
                        'Aguardando sua revisão criadas por você 0',
                        'Sem prazo abertas sem data definida 0',
                    ],
                    'esteira' => [
                        '0 Comercial Comercial CAIXA 1',
                        '0 Abertura e relatório Atendimento CAIXA 2',
                        '0 Controladoria Controladoria CAIXA 3',
                        '0 Documentação Secretaria CAIXA 4',
                        '0 Jurídico Advogado(a) CAIXA 5',
                        '1 Gargalo Protocolar já Advogado(a) 1 atrasada Ana Equipe Caixa do protocolo',
                        '0 Acompanhamento Advogado(a) CAIXA 7',
                        '0 Encerramento Controladoria CAIXA 8',
                    ],
                    'chip' => '',
                    'grupos' => [
                        [
                            'cabeca' => 'Atrasadas 1',
                            'metas' => [
                                [
                                    'texto' => '1.Meta do Carlos LOTE-4 · TEXTO LIVRE LOTE-4 de Paula Primária 1 dia(s) atrasado 6/8 · Protocolar já próximo: Acompanhamento · Advogado(a) Atrasada criada DATA Concluir etapa',
                                    'pasta' => 'TEXTO LIVRE LOTE-4',
                                    'concluir' => 1,
                                    'acompanhando' => 'false',
                                ],
                            ],
                        ],
                    ],
                    'trilho' => 'Seu andamento últimos 30 dias 0 de 1 concluídas 0 concluída(s) 1 atrasada(s) 0 em revisão Precisa de atenção Meta do Carlos · Pasta LOTE-4 prazo DATA · 1 dia(s) atrasado · de Paula Primária',
                ],
                'acompanhando' => [
                    'abas' => [
                        'responsavel 1',
                        'criei 0',
                        'acompanhando 0',
                        'todas 1',
                    ],
                    'kpis' => [
                        'Atrasadas sob sua responsabilidade 1',
                        'Vencem em 7 dias ainda dá tempo 0',
                        'Aguardando sua revisão criadas por você 0',
                        'Sem prazo abertas sem data definida 0',
                    ],
                    'esteira' => [
                        '0 Comercial Comercial CAIXA 1',
                        '0 Abertura e relatório Atendimento CAIXA 2',
                        '0 Controladoria Controladoria CAIXA 3',
                        '0 Documentação Secretaria CAIXA 4',
                        '0 Jurídico Advogado(a) CAIXA 5',
                        '1 Gargalo Protocolar já Advogado(a) 1 atrasada Ana Equipe Caixa do protocolo',
                        '0 Acompanhamento Advogado(a) CAIXA 7',
                        '0 Encerramento Controladoria CAIXA 8',
                    ],
                    'chip' => '',
                    'grupos' => [],
                    'trilho' => 'Seu andamento últimos 30 dias 0 de 1 concluídas 0 concluída(s) 1 atrasada(s) 0 em revisão Precisa de atenção Meta do Carlos · Pasta LOTE-4 prazo DATA · 1 dia(s) atrasado · de Paula Primária',
                ],
                'todas' => [
                    'abas' => [
                        'responsavel 1',
                        'criei 0',
                        'acompanhando 0',
                        'todas 1',
                    ],
                    'kpis' => [
                        'Atrasadas sob sua responsabilidade 1',
                        'Vencem em 7 dias ainda dá tempo 0',
                        'Aguardando sua revisão criadas por você 0',
                        'Sem prazo abertas sem data definida 0',
                    ],
                    'esteira' => [
                        '0 Comercial Comercial CAIXA 1',
                        '0 Abertura e relatório Atendimento CAIXA 2',
                        '0 Controladoria Controladoria CAIXA 3',
                        '0 Documentação Secretaria CAIXA 4',
                        '0 Jurídico Advogado(a) CAIXA 5',
                        '1 Gargalo Protocolar já Advogado(a) 1 atrasada Ana Equipe Caixa do protocolo',
                        '0 Acompanhamento Advogado(a) CAIXA 7',
                        '0 Encerramento Controladoria CAIXA 8',
                    ],
                    'chip' => '',
                    'grupos' => [
                        [
                            'cabeca' => 'Atrasadas 1',
                            'metas' => [
                                [
                                    'texto' => '1.Meta do Carlos LOTE-4 · TEXTO LIVRE LOTE-4 de Paula Primária 1 dia(s) atrasado 6/8 · Protocolar já próximo: Acompanhamento · Advogado(a) Atrasada criada DATA Concluir etapa',
                                    'pasta' => 'TEXTO LIVRE LOTE-4',
                                    'concluir' => 1,
                                    'acompanhando' => 'false',
                                ],
                            ],
                        ],
                    ],
                    'trilho' => 'Seu andamento últimos 30 dias 0 de 1 concluídas 0 concluída(s) 1 atrasada(s) 0 em revisão Precisa de atenção Meta do Carlos · Pasta LOTE-4 prazo DATA · 1 dia(s) atrasado · de Paula Primária',
                ],
                'esteira' => [
                    'abas' => [
                        'responsavel 1',
                        'criei 0',
                        'acompanhando 0',
                        'todas 1',
                    ],
                    'kpis' => [
                        'Atrasadas sob sua responsabilidade 1',
                        'Vencem em 7 dias ainda dá tempo 0',
                        'Aguardando sua revisão criadas por você 0',
                        'Sem prazo abertas sem data definida 0',
                    ],
                    'esteira' => [
                        '0 Comercial Comercial CAIXA 1',
                        '0 Abertura e relatório Atendimento CAIXA 2',
                        '0 Controladoria Controladoria CAIXA 3',
                        '0 Documentação Secretaria CAIXA 4',
                        '0 Jurídico Advogado(a) CAIXA 5',
                        '1 Protocolar já Advogado(a) 1 atrasada Ana Equipe Caixa do protocolo',
                        '0 Acompanhamento Advogado(a) CAIXA 7',
                        '0 Encerramento Controladoria CAIXA 8',
                    ],
                    'chip' => 'Só a etapa Protocolar já',
                    'grupos' => [
                        [
                            'cabeca' => 'Atrasadas 1',
                            'metas' => [
                                [
                                    'texto' => '1.Meta do Carlos LOTE-4 · TEXTO LIVRE LOTE-4 de Paula Primária 1 dia(s) atrasado 6/8 · Protocolar já próximo: Acompanhamento · Advogado(a) Atrasada criada DATA Concluir etapa',
                                    'pasta' => 'TEXTO LIVRE LOTE-4',
                                    'concluir' => 1,
                                    'acompanhando' => 'false',
                                ],
                            ],
                        ],
                    ],
                    'trilho' => 'Seu andamento últimos 30 dias 0 de 1 concluídas 0 concluída(s) 1 atrasada(s) 0 em revisão Precisa de atenção Meta do Carlos · Pasta LOTE-4 prazo DATA · 1 dia(s) atrasado · de Paula Primária',
                ],
            ],
            'foraDoCadeado' => [
                'responsavel' => [
                    'abas' => [
                        'responsavel 1',
                        'criei 0',
                        'acompanhando 0',
                        'todas 1',
                    ],
                    'kpis' => [
                        'Atrasadas sob sua responsabilidade 0',
                        'Vencem em 7 dias ainda dá tempo 1',
                        'Aguardando sua revisão criadas por você 0',
                        'Sem prazo abertas sem data definida 0',
                    ],
                    'esteira' => [
                        '0 Comercial Comercial CAIXA 1',
                        '0 Abertura e relatório Atendimento CAIXA 2',
                        '0 Controladoria Controladoria CAIXA 3',
                        '0 Documentação Secretaria CAIXA 4',
                        '1 Gargalo Jurídico Advogado(a) 1 atrasada CAIXA 5',
                        '3 Protocolar já Advogado(a) 1 atrasada Ana Equipe Caixa do protocolo',
                        '0 Acompanhamento Advogado(a) CAIXA 7',
                        '0 Encerramento Controladoria CAIXA 8',
                    ],
                    'chip' => '',
                    'grupos' => [
                        [
                            'cabeca' => 'Vencem em 7 dias 1',
                            'metas' => [
                                [
                                    'texto' => '1.Petição inicial LOTE-1 · MARIA LOTE de Bruno Criador vence em 3 dia(s) · DATA 6/8 · Protocolar já próximo: Acompanhamento · Advogado(a) Vence em breve criada DATA Concluir etapa',
                                    'pasta' => 'MARIA LOTE',
                                    'concluir' => 1,
                                    'acompanhando' => 'false',
                                ],
                            ],
                        ],
                        [
                            'cabeca' => 'Concluídas 1',
                            'metas' => [
                                [
                                    'texto' => '1.Meta concluída LOTE-1 · MARIA LOTE de Bruno Criador concluída 5/8 · Jurídico Concluída criada DATA',
                                    'pasta' => 'MARIA LOTE',
                                    'concluir' => 0,
                                    'acompanhando' => 'false',
                                ],
                            ],
                        ],
                    ],
                    'trilho' => 'Seu andamento últimos 30 dias 1 de 2 concluídas 1 concluída(s) 0 atrasada(s) 0 em revisão Precisa de atenção',
                ],
                'acompanhando' => [
                    'abas' => [
                        'responsavel 1',
                        'criei 0',
                        'acompanhando 0',
                        'todas 1',
                    ],
                    'kpis' => [
                        'Atrasadas sob sua responsabilidade 0',
                        'Vencem em 7 dias ainda dá tempo 1',
                        'Aguardando sua revisão criadas por você 0',
                        'Sem prazo abertas sem data definida 0',
                    ],
                    'esteira' => [
                        '0 Comercial Comercial CAIXA 1',
                        '0 Abertura e relatório Atendimento CAIXA 2',
                        '0 Controladoria Controladoria CAIXA 3',
                        '0 Documentação Secretaria CAIXA 4',
                        '1 Gargalo Jurídico Advogado(a) 1 atrasada CAIXA 5',
                        '3 Protocolar já Advogado(a) 1 atrasada Ana Equipe Caixa do protocolo',
                        '0 Acompanhamento Advogado(a) CAIXA 7',
                        '0 Encerramento Controladoria CAIXA 8',
                    ],
                    'chip' => '',
                    'grupos' => [],
                    'trilho' => 'Seu andamento últimos 30 dias 1 de 2 concluídas 1 concluída(s) 0 atrasada(s) 0 em revisão Precisa de atenção',
                ],
                'todas' => [
                    'abas' => [
                        'responsavel 1',
                        'criei 0',
                        'acompanhando 0',
                        'todas 1',
                    ],
                    'kpis' => [
                        'Atrasadas sob sua responsabilidade 0',
                        'Vencem em 7 dias ainda dá tempo 1',
                        'Aguardando sua revisão criadas por você 0',
                        'Sem prazo abertas sem data definida 0',
                    ],
                    'esteira' => [
                        '0 Comercial Comercial CAIXA 1',
                        '0 Abertura e relatório Atendimento CAIXA 2',
                        '0 Controladoria Controladoria CAIXA 3',
                        '0 Documentação Secretaria CAIXA 4',
                        '1 Gargalo Jurídico Advogado(a) 1 atrasada CAIXA 5',
                        '3 Protocolar já Advogado(a) 1 atrasada Ana Equipe Caixa do protocolo',
                        '0 Acompanhamento Advogado(a) CAIXA 7',
                        '0 Encerramento Controladoria CAIXA 8',
                    ],
                    'chip' => '',
                    'grupos' => [
                        [
                            'cabeca' => 'Vencem em 7 dias 1',
                            'metas' => [
                                [
                                    'texto' => '1.Petição inicial LOTE-1 · MARIA LOTE de Bruno Criador vence em 3 dia(s) · DATA 6/8 · Protocolar já próximo: Acompanhamento · Advogado(a) Vence em breve criada DATA Concluir etapa',
                                    'pasta' => 'MARIA LOTE',
                                    'concluir' => 1,
                                    'acompanhando' => 'false',
                                ],
                            ],
                        ],
                        [
                            'cabeca' => 'Concluídas 1',
                            'metas' => [
                                [
                                    'texto' => '1.Meta concluída LOTE-1 · MARIA LOTE de Bruno Criador concluída 5/8 · Jurídico Concluída criada DATA',
                                    'pasta' => 'MARIA LOTE',
                                    'concluir' => 0,
                                    'acompanhando' => 'false',
                                ],
                            ],
                        ],
                    ],
                    'trilho' => 'Seu andamento últimos 30 dias 1 de 2 concluídas 1 concluída(s) 0 atrasada(s) 0 em revisão Precisa de atenção',
                ],
                'esteira' => [
                    'abas' => [
                        'responsavel 1',
                        'criei 0',
                        'acompanhando 0',
                        'todas 1',
                    ],
                    'kpis' => [
                        'Atrasadas sob sua responsabilidade 0',
                        'Vencem em 7 dias ainda dá tempo 1',
                        'Aguardando sua revisão criadas por você 0',
                        'Sem prazo abertas sem data definida 0',
                    ],
                    'esteira' => [
                        '0 Comercial Comercial CAIXA 1',
                        '0 Abertura e relatório Atendimento CAIXA 2',
                        '0 Controladoria Controladoria CAIXA 3',
                        '0 Documentação Secretaria CAIXA 4',
                        '1 Gargalo Jurídico Advogado(a) 1 atrasada CAIXA 5',
                        '3 Protocolar já Advogado(a) 1 atrasada Ana Equipe Caixa do protocolo',
                        '0 Acompanhamento Advogado(a) CAIXA 7',
                        '0 Encerramento Controladoria CAIXA 8',
                    ],
                    'chip' => 'Só a etapa Protocolar já',
                    'grupos' => [
                        [
                            'cabeca' => 'Atrasadas 1',
                            'metas' => [
                                [
                                    'texto' => '1.Meta do Carlos LOTE-4 · TEXTO LIVRE LOTE-4 de Paula Primária 1 dia(s) atrasado 6/8 · Protocolar já próximo: Acompanhamento · Advogado(a) Atrasada criada DATA',
                                    'pasta' => 'TEXTO LIVRE LOTE-4',
                                    'concluir' => 0,
                                    'acompanhando' => 'false',
                                ],
                            ],
                        ],
                        [
                            'cabeca' => 'Vencem em 7 dias 1',
                            'metas' => [
                                [
                                    'texto' => '1.Petição inicial LOTE-1 · MARIA LOTE de Bruno Criador vence em 3 dia(s) · DATA 6/8 · Protocolar já próximo: Acompanhamento · Advogado(a) Vence em breve criada DATA Concluir etapa',
                                    'pasta' => 'MARIA LOTE',
                                    'concluir' => 1,
                                    'acompanhando' => 'false',
                                ],
                            ],
                        ],
                        [
                            'cabeca' => 'Sem prazo 1',
                            'metas' => [
                                [
                                    'texto' => '1.Meta só acompanhada LOTE-4 · TEXTO LIVRE LOTE-4 de Paula Primária sem prazo definido 6/8 · Protocolar já próximo: Acompanhamento · Advogado(a) Sem prazo criada DATA',
                                    'pasta' => 'TEXTO LIVRE LOTE-4',
                                    'concluir' => 0,
                                    'acompanhando' => 'false',
                                ],
                            ],
                        ],
                    ],
                    'trilho' => 'Seu andamento últimos 30 dias 1 de 2 concluídas 1 concluída(s) 0 atrasada(s) 0 em revisão Precisa de atenção',
                ],
            ],
        ];
    }
}
