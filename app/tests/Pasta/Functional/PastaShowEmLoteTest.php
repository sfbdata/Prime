<?php

declare(strict_types=1);

namespace App\Tests\Pasta\Functional;

use App\Controller\PastaController;
use App\Djen\Entity\PublicacaoDjen;
use App\Entity\Auth\User;
use App\Entity\Tarefa\Tarefa;
use App\Entity\Tarefa\TarefaMensagem;
use App\Entity\Tenant\Tenant;
use App\Pasta\Entity\AbaDaPasta;
use App\Pasta\Entity\AbaOcultaDaPasta;
use App\Pasta\Entity\NivelDeAcesso;
use App\Pasta\Entity\Pasta;
use App\Pasta\Service\VisibilidadeDasAbasDaPasta;
use App\Processo\Entity\Processo;
use App\Tarefa\Repository\TarefaRepository;
use App\Tests\Functional\JusPrimeWebTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Component\DomCrawler\Crawler;

/**
 * A tela da pasta (GET /pasta/{id}) lê de uma vez os responsáveis e os despachos de todas as metas, e não repete as
 * leituras de aba oculta, andamento, Push e equipe do cadeado (PR 4b da revisão de queries). Trava duas coisas: o
 * número de consultas não cresce com o número de metas, e a tela mostra exatamente o mesmo de antes para o primário,
 * para o colaborador e para quem o cadeado restringe.
 */
#[CoversClass(PastaController::class)]
#[CoversClass(TarefaRepository::class)]
#[CoversClass(VisibilidadeDasAbasDaPasta::class)]
final class PastaShowEmLoteTest extends JusPrimeWebTestCase
{
    use CriaCenarioDoCadeadoFuncionalTrait;

    private const PERMISSOES = [
        'modules.expediente.view', 'modules.pastas.view', 'modules.tarefas.view', 'modules.djen.view',
        'modules.processos.view', 'resources.pasta.view', 'resources.pasta.edit', 'resources.processo.view',
    ];

    private int $codigo = 0;

    #[TestDox('a tela da pasta faz o mesmo número de consultas com 1 e com 8 metas cheias')]
    public function testConsultasNaoCrescemComAsMetas(): void
    {
        $this->client = static::createClient();

        // Dois escritórios iguais, um com 1 meta na pasta e outro com 8: mesmas pessoas, mesmo papel.
        [$primarioUm, $tenantUm, $anaUm, $pastaUm]       = $this->escritorioComMetas(1);
        [$primarioOito, $tenantOito, $anaOito, $pastaOito] = $this->escritorioComMetas(8);

        foreach ([[$primarioUm, $primarioOito], [$anaUm, $anaOito]] as [$quemUm, $quemOito]) {
            // Aquecimento: a primeira requisição de cada pessoa guarda na sessão o que é de uma vez só.
            $this->contarConsultas($quemUm, $tenantUm, $pastaUm);
            $this->contarConsultas($quemOito, $tenantOito, $pastaOito);

            $comUma  = $this->contarConsultas($quemUm, $tenantUm, $pastaUm);
            $comOito = $this->contarConsultas($quemOito, $tenantOito, $pastaOito);

            self::assertSame(1, $comUma['metas']);
            self::assertSame(8, $comOito['metas']);
            self::assertSame($comUma['consultas'], $comOito['consultas'], 'A tela da pasta voltou a consultar por meta.');
        }
    }

    #[TestDox('a tela mostra as mesmas abas, metas, responsáveis, exclusão, andamento e histórico, e respeita o cadeado')]
    public function testTelaMostraOMesmoConteudo(): void
    {
        $this->client = static::createClient();
        [$primario, $tenant] = $this->criarEscritorio();
        $ana    = $this->criarColaborador($tenant, 'Ana Equipe', self::PERMISSOES);
        $bruno  = $this->criarColaborador($tenant, 'Bruno Leitor', self::PERMISSOES);
        $carlos = $this->criarColaborador($tenant, 'Carlos De Fora', self::PERMISSOES);

        $pasta = $this->pastaCheia($tenant, $primario, $ana, $bruno);
        // Abas ocultas: o Financeiro para toda a equipe e o Push só para a Ana (Bruno continua vendo o Push).
        $this->em()->persist(AbaOcultaDaPasta::paraTodaAEquipe($tenant, $pasta, AbaDaPasta::Financeiro, $primario, new \DateTimeImmutable()));
        $this->em()->persist(AbaOcultaDaPasta::paraAlguns($tenant, $pasta, AbaDaPasta::Push, [(int) $ana->getId()], $primario, new \DateTimeImmutable()));
        $this->em()->flush();

        $livre = $this->esperadoDaPastaLivre();
        self::assertSame($livre['primario'], $this->tela($primario, $tenant, $pasta), 'primário');
        self::assertSame($livre['colaborador'], $this->tela($ana, $tenant, $pasta), 'colaborador com permissão, sem Financeiro e sem Push');
        self::assertSame($livre['colaboradorComPush'], $this->tela($bruno, $tenant, $pasta), 'colaborador com permissão, sem Financeiro e com Push');

        // Cadeado por pessoa: Bruno em Visualizar; Ana e Carlos fora da lista (Oculto).
        $pasta    = $this->em()->find(Pasta::class, $pasta->getId());
        $primario = $this->em()->find(User::class, $primario->getId());
        $bruno    = $this->em()->find(User::class, $bruno->getId());
        $this->trancar($pasta, $primario, [[$bruno, NivelDeAcesso::Visualizar]]);
        $trancada = $this->esperadoDaPastaTrancada();
        self::assertSame($trancada['primario'], $this->tela($primario, $tenant, $pasta), 'primário com cadeado');
        self::assertSame($trancada['visualizar'], $this->tela($bruno, $tenant, $pasta), 'colaborador em Visualizar');
        self::assertSame($trancada['fora'], $this->tela($carlos, $tenant, $pasta), 'colaborador fora do cadeado');

        // Outro escritório: a mesma pasta não abre.
        [$outroPrimario, $outroTenant] = $this->criarEscritorio();
        self::assertSame(['status' => 404], $this->tela($outroPrimario, $outroTenant, $pasta), 'outro escritório');
    }

    #[TestDox('a regra de aba oculta gravada na mesma requisição vale na hora, mesmo depois de lida e guardada')]
    public function testRegraDeAbaGravadaEsqueceAMemoria(): void
    {
        $this->client = static::createClient();
        [$primario, $tenant] = $this->criarEscritorio();
        $ana   = $this->criarColaborador($tenant, 'Ana Equipe', self::PERMISSOES);
        $pasta = $this->criarPasta($tenant, 'CLIENTE ABA');
        $abas  = static::getContainer()->get(VisibilidadeDasAbasDaPasta::class);

        self::assertSame([], $abas->abasOcultasPara($pasta, $ana));

        $regra = AbaOcultaDaPasta::paraTodaAEquipe($tenant, $pasta, AbaDaPasta::Financeiro, $primario, new \DateTimeImmutable());
        $this->em()->persist($regra);
        $this->em()->flush();
        self::assertSame([AbaDaPasta::Financeiro], $abas->abasOcultasPara($pasta, $ana), 'gravar a regra esquece a memória');

        $this->em()->remove($regra);
        $this->em()->flush();
        self::assertSame([], $abas->abasOcultasPara($pasta, $ana), 'apagar a regra esquece a memória');
    }

    /** @return array{0: User, 1: Tenant, 2: User, 3: Pasta} */
    private function escritorioComMetas(int $quantas): array
    {
        [$primario, $tenant] = $this->criarEscritorio();
        $ana   = $this->criarColaborador($tenant, 'Ana Equipe', self::PERMISSOES);
        $pasta = $this->criarPasta($tenant, 'CLIENTE CONTAGEM');
        $this->processo($pasta, $tenant);
        for ($i = 0; $i < $quantas; ++$i) {
            $meta = $this->meta($pasta, $tenant, $primario, 'Meta ' . $i, [$primario, $ana], new \DateTimeImmutable('+' . ($i + 1) . ' days'));
            $this->despacho($meta, $tenant, $ana, true);
        }
        $this->em()->flush();

        return [$primario, $tenant, $ana, $pasta];
    }

    /** A pasta do teste de conteúdo: metas em todos os estados, com e sem responsável, despacho, processo e Push. */
    private function pastaCheia(Tenant $tenant, User $primario, User $ana, User $bruno): Pasta
    {
        $pasta = $this->criarPasta($tenant, 'CLIENTE CHEIO');
        $pasta->setNup('SHOW-LOTE');
        $numero = $this->processo($pasta, $tenant);

        $this->meta($pasta, $tenant, $primario, 'Petição inicial', [$ana, $primario], new \DateTimeImmutable('+3 days'));
        $this->meta($pasta, $tenant, $ana, 'Juntar documentos', [$bruno], new \DateTimeImmutable('-2 days'));
        $comDespacho = $this->meta($pasta, $tenant, $primario, 'Despacho registrado', [$ana], new \DateTimeImmutable('+10 days'));
        $this->despacho($comDespacho, $tenant, $ana, true);
        $comNota = $this->meta($pasta, $tenant, $ana, 'Nota sem código', [], null);
        $this->despacho($comNota, $tenant, $ana, false);
        $concluida = $this->meta($pasta, $tenant, $primario, 'Meta concluída', [$primario], new \DateTimeImmutable('-5 days'));
        $concluida->setStatus(Tarefa::STATUS_CONCLUIDA);
        $concluida->setDataConclusao(new \DateTimeImmutable('-6 days'));

        $pub = new PublicacaoDjen();
        $pub->setTenant($tenant);
        $pub->setDjenId((string) random_int(100000000, 999999999));
        $pub->setNumeroProcesso(preg_replace('/\D/', '', $numero) ?? $numero);
        $pub->setSiglaTribunal('TJDFT');
        $pub->setTipoComunicacao('Intimação');
        $pub->setTipoDocumento('Sentença');
        $pub->setDataDisponibilizacao(new \DateTimeImmutable('-1 day'));
        $this->em()->persist($pub);
        $this->em()->flush();

        return $pasta;
    }

    /**
     * @param list<User> $responsaveis
     */
    private function meta(Pasta $pasta, Tenant $tenant, User $criador, string $titulo, array $responsaveis, ?\DateTimeImmutable $prazo): Tarefa
    {
        $meta = new Tarefa();
        $meta->setTitulo($titulo);
        $meta->setDescricao('Meta do teste');
        $meta->setPasta($pasta);
        $meta->setTenant($tenant);
        $meta->setCriadoPor($criador);
        $meta->setPrazo($prazo?->setTime(0, 0));
        foreach ($responsaveis as $responsavel) {
            $meta->addResponsavel($responsavel);
        }
        $this->em()->persist($meta);
        $this->em()->flush();

        return $meta;
    }

    private function despacho(Tarefa $meta, Tenant $tenant, User $autor, bool $registrado): void
    {
        $mensagem = (new TarefaMensagem())->setUsuario($autor)->setTenant($tenant)->setMensagem('<p>Despacho</p>');
        $meta->addMensagem($mensagem);
        if ($registrado) {
            $mensagem->registrar(sprintf('2026.%04d', ++$this->codigo + random_int(0, 8000)), (string) $autor->getFullName(), null);
        }
        $this->em()->persist($mensagem);
        $this->em()->flush();
    }

    private function processo(Pasta $pasta, Tenant $tenant): string
    {
        $processo = new Processo();
        $processo->setTenant($tenant);
        $processo->setNumeroProcesso(sprintf('07%05d-10.2026.8.07.0001', random_int(0, 99999)));
        $processo->setAssuntoProcessual('Indenização');
        $this->em()->persist($processo);
        $pasta->vincularProcesso($processo);
        $this->em()->flush();

        return (string) $processo->getNumeroProcesso();
    }

    /** @return array{consultas: int, metas: int} */
    private function contarConsultas(User $quem, Tenant $tenant, Pasta $pasta): array
    {
        $this->logarComTenant($this->client, $quem, $tenant);
        $this->em()->clear();
        $this->client->enableProfiler();
        $crawler = $this->client->request('GET', '/pasta/' . $pasta->getId());
        self::assertResponseIsSuccessful();

        $perfil = $this->client->getProfile();
        self::assertNotFalse($perfil);
        /** @var \Doctrine\Bundle\DoctrineBundle\DataCollector\DoctrineDataCollector $db */
        $db = $perfil->getCollector('db');

        return ['consultas' => $db->getQueryCount(), 'metas' => $crawler->filter('.ps-metas-lista article.ps-meta')->count()];
    }

    /**
     * O que a tela mostra das partes tocadas pela leitura em lote, com os ids trocados pela posição.
     *
     * @return array<string, mixed>
     */
    private function tela(User $quem, Tenant $tenant, Pasta $pasta): array
    {
        $this->logarComTenant($this->client, $quem, $tenant);
        $this->em()->clear();
        $crawler = $this->client->request('GET', '/pasta/' . $pasta->getId());
        $status  = $this->client->getResponse()->getStatusCode();
        if ($status !== 200) {
            return ['status' => $status];
        }
        if ($crawler->filter('#pastaTabs')->count() === 0) {
            return ['status' => $status, 'trancada' => $crawler->filter('[data-cad-area]')->count() > 0];
        }

        return [
            'status'    => $status,
            'abas'      => $crawler->filter('#pastaTabs button.ps-aba')->each(static fn (Crawler $b): string => (string) $b->attr('id')),
            'cadeado'   => [
                'selo'   => self::texto($crawler->filter('.js-cadeado-selo')),
                'faixa'  => self::texto($crawler->filter('div[role="status"]')->reduce(static fn (Crawler $d): bool => $d->filter('.bi-lock-fill')->count() > 0)),
                'modo'   => $crawler->filter('#psCadeadoModal')->count() > 0 ? (string) $crawler->filter('#psCadeadoModal')->attr('data-modo-inicial') : '',
                'equipe' => $crawler->filter('.js-cadeado-pessoa')->each(static fn (Crawler $p): string => $p->attr('data-nivel') . ' ' . self::texto($p)),
            ],
            'metas'     => $crawler->filter('.ps-metas-lista article.ps-meta')->each(static fn (Crawler $m): array => [
                'titulo'   => self::texto($m->filter('.ps-meta-titulo-tx')),
                'estado'   => (string) $m->attr('data-meta-estado'),
                'linha'    => self::texto($m->filter('.ps-meta-linha')),
                'status'   => self::texto($m->filter('.ps-meta-status')),
                'excluir'  => $m->filter('form.ps-meta-excluir')->count(),
                'despacho' => $m->filter('form.ps-meta-excluir')->count() > 0 ? (string) $m->filter('form.ps-meta-excluir')->attr('data-meta-despacho') : '',
                'selo'     => $m->filter('.com-selo-sem-prazo')->count(),
            ]),
            'relatorio' => $crawler->filter('.ps-mrel-meta')->each(static fn (Crawler $r): string => self::texto($r->filter('.ps-mrel-corpo'))),
            'trilho'    => self::texto($crawler->filter('#tarefas .ps-trilho')),
            'prazos'    => self::texto($crawler->filter('[data-trilho="prazos"]')),
            'andamento' => [
                'atual'      => (string) $crawler->filter('.ps-and')->attr('data-atual'),
                'fases'      => self::texto($crawler->filter('.ps-and-rol')),
                'historico'  => $crawler->filter('.ps-and-hist-item')->each(static fn (Crawler $h): string => $h->attr('data-aba') . ' ' . self::texto($h)),
                'sugestoes'  => $crawler->filter('.ps-and-sug')->count() > 0
                    ? array_map(static fn (array $s): string => $s['nome'] . ' ' . $s['indice'] . ' ' . self::normalizar($s['motivo']), json_decode((string) $crawler->filter('.ps-and-sug')->attr('data-sugestoes'), true))
                    : [],
            ],
        ];
    }

    private static function texto(Crawler $no): string
    {
        if ($no->count() === 0) {
            return '';
        }

        return self::normalizar($no->text(''));
    }

    /** Datas e horas variam com o dia em que o teste roda: saem do retrato. */
    private static function normalizar(string $texto): string
    {
        $texto = preg_replace('/\s+/u', ' ', trim($texto));

        return (string) preg_replace(['#\d{2}/\d{2}(/\d{2,4})?#', '/\d{2}:\d{2}/', '/CAD[0-9A-Z]{6}/'], ['DATA', 'HORA', 'NUP'], (string) $texto);
    }

    /**
     * O retrato tirado do código de ANTES da leitura em lote (este mesmo teste, rodado no master): a tela tem de
     * continuar igual.
     *
     * @return array<string, array<string, mixed>>
     */
    private function esperadoDaPastaLivre(): array
    {
        return [
            'primario' => [
                'status' => 200,
                'abas' => [
                    'dados-tab',
                    'tarefas-tab',
                    'processo-tab',
                    'financeiro-tab',
                    'detalhes-tab',
                    'documentos-tab',
                    'push-tab',
                ],
                'cadeado' => [
                    'selo' => 'Livre',
                    'faixa' => '',
                    'modo' => 'por_pessoa',
                    'equipe' => [
                        'editar Ana Equipe',
                        'editar Bruno Leitor',
                        'editar Carlos De Fora',
                    ],
                ],
                'metas' => [
                    [
                        'titulo' => 'Petição inicial',
                        'estado' => 'aberta',
                        'linha' => 'Paula Primária Ana Equipe, Paula Primária vence DATA',
                        'status' => 'Pendente',
                        'excluir' => 1,
                        'despacho' => '',
                        'selo' => 0,
                    ],
                    [
                        'titulo' => 'Juntar documentos',
                        'estado' => 'atrasada',
                        'linha' => 'Ana Equipe Bruno Leitor 2 dias em atraso',
                        'status' => 'Atrasada',
                        'excluir' => 1,
                        'despacho' => '',
                        'selo' => 1,
                    ],
                    [
                        'titulo' => 'Despacho registrado',
                        'estado' => 'aberta',
                        'linha' => 'Paula Primária Ana Equipe vence DATA',
                        'status' => 'Pendente',
                        'excluir' => 1,
                        'despacho' => '1',
                        'selo' => 1,
                    ],
                    [
                        'titulo' => 'Nota sem código',
                        'estado' => 'aberta',
                        'linha' => 'Ana Equipe Não informado',
                        'status' => 'Pendente',
                        'excluir' => 1,
                        'despacho' => '',
                        'selo' => 1,
                    ],
                    [
                        'titulo' => 'Meta concluída',
                        'estado' => 'concluida',
                        'linha' => 'Paula Primária Paula Primária concluída no prazo DATA',
                        'status' => 'Concluída',
                        'excluir' => 1,
                        'despacho' => '',
                        'selo' => 0,
                    ],
                ],
                'relatorio' => [
                    'Pendente Petição inicial Vence DATA Criada por Paula Primária Responsáveis Ana Equipe, Paula Primária Prazo DATA Última modificação Sem alterações Pasta SHOW-LOTE Situação Pendente Histórico Meta criada por Paula Primária para Ana Equipe, Paula Primária DATA Prazo definido para DATA DATA Outras metas desta pasta',
                    'Atrasada Juntar documentos 2 dias em atraso · prazo DATA Criada por Ana Equipe Responsáveis Bruno Leitor Prazo DATA Última modificação Sem alterações Pasta SHOW-LOTE Situação Atrasada Histórico Meta criada por Ana Equipe para Bruno Leitor DATA Prazo definido para DATA DATA 2 dias em atraso até hoje DATA Outras metas desta pasta',
                    'Pendente Despacho registrado Vence DATA Criada por Paula Primária Responsáveis Ana Equipe Prazo DATA Última modificação Sem alterações Pasta SHOW-LOTE Situação Pendente Histórico Meta criada por Paula Primária para Ana Equipe DATA Prazo definido para DATA DATA Outras metas desta pasta',
                    'Pendente Nota sem código Criada por Ana Equipe Responsáveis Não informado Prazo Sem prazo Última modificação Sem alterações Pasta SHOW-LOTE Situação Pendente Histórico Meta criada por Ana Equipe DATA Outras metas desta pasta',
                    'Concluída Meta concluída Concluída no prazo DATA Criada por Paula Primária Responsáveis Paula Primária Prazo DATA Última modificação DATA Pasta SHOW-LOTE Situação Concluída Histórico Meta criada por Paula Primária para Paula Primária DATA Prazo definido para DATA DATA Concluída DATA Outras metas desta pasta',
                ],
                'trilho' => 'Andamento das metas 1/5 concluídas 1 concluída 3 pendentes 1 atrasada Precisa de atenção Juntar documentos prazo DATA · 2 dias em atraso · Bruno Leitor Metas atrasadas continuam contando dias até serem concluídas. Responsáveis nas metas AE Ana Equipe 2 metas PP Paula Primária 2 metas BL Bruno Leitor 1 meta',
                'prazos' => 'Próximos prazos 3 Juntar documentos Bruno Leitor · DATA · Meta Vencido há 2 dias Juntar documentos Ver nas metas Encaminhar via Chat I.A Alertar Bruno Adicionar à agenda (.ics) Copiar Petição inicial Ana Equipe · DATA · Meta Faltam 3 dias Petição inicial Ver nas metas Encaminhar via Chat I.A Alertar Ana Adicionar à agenda (.ics) Copiar Despacho registrado Ana Equipe · DATA · Meta Faltam 10 dias Despacho registrado Ver nas metas Encaminhar via Chat I.A Alertar Ana Adicionar à agenda (.ics) Copiar',
                'andamento' => [
                    'atual' => '5',
                    'fases' => 'Abertura Documentação Contrato Distribuído Protocolo Em andamento Decisão Sentença Cumprimento Finalizada',
                    'historico' => [
                        'tarefas DATAMeta aberta',
                        'tarefas DATAMeta aberta',
                        'tarefas DATAMeta aberta',
                        'tarefas DATAMeta aberta',
                        'push DATAIntimação · Push',
                        'tarefas DATAMeta respondida',
                    ],
                    'sugestoes' => [
                        'Sentença 7 Push: sentença em DATA',
                    ],
                ],
            ],
            'colaborador' => [
                'status' => 200,
                'abas' => [
                    'dados-tab',
                    'tarefas-tab',
                    'processo-tab',
                    'detalhes-tab',
                    'documentos-tab',
                ],
                'cadeado' => [
                    'selo' => '',
                    'faixa' => '',
                    'modo' => '',
                    'equipe' => [],
                ],
                'metas' => [
                    [
                        'titulo' => 'Petição inicial',
                        'estado' => 'aberta',
                        'linha' => 'Paula Primária Ana Equipe, Paula Primária vence DATA',
                        'status' => 'Pendente',
                        'excluir' => 0,
                        'despacho' => '',
                        'selo' => 0,
                    ],
                    [
                        'titulo' => 'Juntar documentos',
                        'estado' => 'atrasada',
                        'linha' => 'Ana Equipe Bruno Leitor 2 dias em atraso',
                        'status' => 'Atrasada',
                        'excluir' => 1,
                        'despacho' => '',
                        'selo' => 0,
                    ],
                    [
                        'titulo' => 'Despacho registrado',
                        'estado' => 'aberta',
                        'linha' => 'Paula Primária Ana Equipe vence DATA',
                        'status' => 'Pendente',
                        'excluir' => 0,
                        'despacho' => '',
                        'selo' => 0,
                    ],
                    [
                        'titulo' => 'Nota sem código',
                        'estado' => 'aberta',
                        'linha' => 'Ana Equipe Não informado',
                        'status' => 'Pendente',
                        'excluir' => 1,
                        'despacho' => '',
                        'selo' => 0,
                    ],
                    [
                        'titulo' => 'Meta concluída',
                        'estado' => 'concluida',
                        'linha' => 'Paula Primária Paula Primária concluída no prazo DATA',
                        'status' => 'Concluída',
                        'excluir' => 0,
                        'despacho' => '',
                        'selo' => 0,
                    ],
                ],
                'relatorio' => [
                    'Pendente Petição inicial Vence DATA Criada por Paula Primária Responsáveis Ana Equipe, Paula Primária Prazo DATA Última modificação Sem alterações Pasta SHOW-LOTE Situação Pendente Histórico Meta criada por Paula Primária para Ana Equipe, Paula Primária DATA Prazo definido para DATA DATA Outras metas desta pasta',
                    'Atrasada Juntar documentos 2 dias em atraso · prazo DATA Criada por Ana Equipe Responsáveis Bruno Leitor Prazo DATA Última modificação Sem alterações Pasta SHOW-LOTE Situação Atrasada Histórico Meta criada por Ana Equipe para Bruno Leitor DATA Prazo definido para DATA DATA 2 dias em atraso até hoje DATA Outras metas desta pasta',
                    'Pendente Despacho registrado Vence DATA Criada por Paula Primária Responsáveis Ana Equipe Prazo DATA Última modificação Sem alterações Pasta SHOW-LOTE Situação Pendente Histórico Meta criada por Paula Primária para Ana Equipe DATA Prazo definido para DATA DATA Outras metas desta pasta',
                    'Pendente Nota sem código Criada por Ana Equipe Responsáveis Não informado Prazo Sem prazo Última modificação Sem alterações Pasta SHOW-LOTE Situação Pendente Histórico Meta criada por Ana Equipe DATA Outras metas desta pasta',
                    'Concluída Meta concluída Concluída no prazo DATA Criada por Paula Primária Responsáveis Paula Primária Prazo DATA Última modificação DATA Pasta SHOW-LOTE Situação Concluída Histórico Meta criada por Paula Primária para Paula Primária DATA Prazo definido para DATA DATA Concluída DATA Outras metas desta pasta',
                ],
                'trilho' => 'Andamento das metas 1/5 concluídas 1 concluída 3 pendentes 1 atrasada Precisa de atenção Juntar documentos prazo DATA · 2 dias em atraso · Bruno Leitor Metas atrasadas continuam contando dias até serem concluídas. Responsáveis nas metas AE Ana Equipe 2 metas PP Paula Primária 2 metas BL Bruno Leitor 1 meta',
                'prazos' => 'Próximos prazos 3 Juntar documentos Bruno Leitor · DATA · Meta Vencido há 2 dias Juntar documentos Ver nas metas Encaminhar via Chat I.A Alertar Bruno Adicionar à agenda (.ics) Copiar Petição inicial Ana Equipe · DATA · Meta Faltam 3 dias Petição inicial Ver nas metas Encaminhar via Chat I.A Alertar Paula Adicionar à agenda (.ics) Copiar Despacho registrado Ana Equipe · DATA · Meta Faltam 10 dias Despacho registrado Ver nas metas Encaminhar via Chat I.A Adicionar à agenda (.ics) Copiar',
                'andamento' => [
                    'atual' => '5',
                    'fases' => 'Abertura Documentação Contrato Distribuído Protocolo Em andamento Decisão Sentença Cumprimento Finalizada',
                    'historico' => [
                        'tarefas DATAMeta aberta',
                        'tarefas DATAMeta aberta',
                        'tarefas DATAMeta aberta',
                        'tarefas DATAMeta aberta',
                        'tarefas DATAMeta respondida',
                    ],
                    'sugestoes' => [
                        'Sentença 7 Push: sentença em DATA',
                    ],
                ],
            ],
            'colaboradorComPush' => [
                'status' => 200,
                'abas' => [
                    'dados-tab',
                    'tarefas-tab',
                    'processo-tab',
                    'detalhes-tab',
                    'documentos-tab',
                    'push-tab',
                ],
                'cadeado' => [
                    'selo' => '',
                    'faixa' => '',
                    'modo' => '',
                    'equipe' => [],
                ],
                'metas' => [
                    [
                        'titulo' => 'Petição inicial',
                        'estado' => 'aberta',
                        'linha' => 'Paula Primária Ana Equipe, Paula Primária vence DATA',
                        'status' => 'Pendente',
                        'excluir' => 0,
                        'despacho' => '',
                        'selo' => 0,
                    ],
                    [
                        'titulo' => 'Juntar documentos',
                        'estado' => 'atrasada',
                        'linha' => 'Ana Equipe Bruno Leitor 2 dias em atraso',
                        'status' => 'Atrasada',
                        'excluir' => 0,
                        'despacho' => '',
                        'selo' => 0,
                    ],
                    [
                        'titulo' => 'Despacho registrado',
                        'estado' => 'aberta',
                        'linha' => 'Paula Primária Ana Equipe vence DATA',
                        'status' => 'Pendente',
                        'excluir' => 0,
                        'despacho' => '',
                        'selo' => 0,
                    ],
                    [
                        'titulo' => 'Nota sem código',
                        'estado' => 'aberta',
                        'linha' => 'Ana Equipe Não informado',
                        'status' => 'Pendente',
                        'excluir' => 0,
                        'despacho' => '',
                        'selo' => 0,
                    ],
                    [
                        'titulo' => 'Meta concluída',
                        'estado' => 'concluida',
                        'linha' => 'Paula Primária Paula Primária concluída no prazo DATA',
                        'status' => 'Concluída',
                        'excluir' => 0,
                        'despacho' => '',
                        'selo' => 0,
                    ],
                ],
                'relatorio' => [
                    'Pendente Petição inicial Vence DATA Criada por Paula Primária Responsáveis Ana Equipe, Paula Primária Prazo DATA Última modificação Sem alterações Pasta SHOW-LOTE Situação Pendente Histórico Meta criada por Paula Primária para Ana Equipe, Paula Primária DATA Prazo definido para DATA DATA Outras metas desta pasta',
                    'Atrasada Juntar documentos 2 dias em atraso · prazo DATA Criada por Ana Equipe Responsáveis Bruno Leitor Prazo DATA Última modificação Sem alterações Pasta SHOW-LOTE Situação Atrasada Histórico Meta criada por Ana Equipe para Bruno Leitor DATA Prazo definido para DATA DATA 2 dias em atraso até hoje DATA Outras metas desta pasta',
                    'Pendente Despacho registrado Vence DATA Criada por Paula Primária Responsáveis Ana Equipe Prazo DATA Última modificação Sem alterações Pasta SHOW-LOTE Situação Pendente Histórico Meta criada por Paula Primária para Ana Equipe DATA Prazo definido para DATA DATA Outras metas desta pasta',
                    'Pendente Nota sem código Criada por Ana Equipe Responsáveis Não informado Prazo Sem prazo Última modificação Sem alterações Pasta SHOW-LOTE Situação Pendente Histórico Meta criada por Ana Equipe DATA Outras metas desta pasta',
                    'Concluída Meta concluída Concluída no prazo DATA Criada por Paula Primária Responsáveis Paula Primária Prazo DATA Última modificação DATA Pasta SHOW-LOTE Situação Concluída Histórico Meta criada por Paula Primária para Paula Primária DATA Prazo definido para DATA DATA Concluída DATA Outras metas desta pasta',
                ],
                'trilho' => 'Andamento das metas 1/5 concluídas 1 concluída 3 pendentes 1 atrasada Precisa de atenção Juntar documentos prazo DATA · 2 dias em atraso · Bruno Leitor Metas atrasadas continuam contando dias até serem concluídas. Responsáveis nas metas AE Ana Equipe 2 metas PP Paula Primária 2 metas BL Bruno Leitor 1 meta',
                'prazos' => 'Próximos prazos 3 Juntar documentos Bruno Leitor · DATA · Meta Vencido há 2 dias Juntar documentos Ver nas metas Encaminhar via Chat I.A Adicionar à agenda (.ics) Copiar Petição inicial Ana Equipe · DATA · Meta Faltam 3 dias Petição inicial Ver nas metas Encaminhar via Chat I.A Alertar Ana Alertar Paula Adicionar à agenda (.ics) Copiar Despacho registrado Ana Equipe · DATA · Meta Faltam 10 dias Despacho registrado Ver nas metas Encaminhar via Chat I.A Alertar Ana Adicionar à agenda (.ics) Copiar',
                'andamento' => [
                    'atual' => '5',
                    'fases' => 'Abertura Documentação Contrato Distribuído Protocolo Em andamento Decisão Sentença Cumprimento Finalizada',
                    'historico' => [
                        'tarefas DATAMeta aberta',
                        'tarefas DATAMeta aberta',
                        'tarefas DATAMeta aberta',
                        'tarefas DATAMeta aberta',
                        'push DATAIntimação · Push',
                        'tarefas DATAMeta respondida',
                    ],
                    'sugestoes' => [
                        'Sentença 7 Push: sentença em DATA',
                    ],
                ],
            ],
        ];
    }

    /** @return array<string, array<string, mixed>> */
    private function esperadoDaPastaTrancada(): array
    {
        return [
            'primario' => [
                'status' => 200,
                'abas' => [
                    'dados-tab',
                    'tarefas-tab',
                    'processo-tab',
                    'financeiro-tab',
                    'detalhes-tab',
                    'documentos-tab',
                    'push-tab',
                ],
                'cadeado' => [
                    'selo' => 'Restrito',
                    'faixa' => '',
                    'modo' => 'por_pessoa',
                    'equipe' => [
                        'oculto Ana Equipe',
                        'visualizar Bruno Leitor',
                        'oculto Carlos De Fora',
                    ],
                ],
                'metas' => [
                    [
                        'titulo' => 'Petição inicial',
                        'estado' => 'aberta',
                        'linha' => 'Paula Primária Ana Equipe, Paula Primária vence DATA',
                        'status' => 'Pendente',
                        'excluir' => 1,
                        'despacho' => '',
                        'selo' => 0,
                    ],
                    [
                        'titulo' => 'Juntar documentos',
                        'estado' => 'atrasada',
                        'linha' => 'Ana Equipe Bruno Leitor 2 dias em atraso',
                        'status' => 'Atrasada',
                        'excluir' => 1,
                        'despacho' => '',
                        'selo' => 1,
                    ],
                    [
                        'titulo' => 'Despacho registrado',
                        'estado' => 'aberta',
                        'linha' => 'Paula Primária Ana Equipe vence DATA',
                        'status' => 'Pendente',
                        'excluir' => 1,
                        'despacho' => '1',
                        'selo' => 1,
                    ],
                    [
                        'titulo' => 'Nota sem código',
                        'estado' => 'aberta',
                        'linha' => 'Ana Equipe Não informado',
                        'status' => 'Pendente',
                        'excluir' => 1,
                        'despacho' => '',
                        'selo' => 1,
                    ],
                    [
                        'titulo' => 'Meta concluída',
                        'estado' => 'concluida',
                        'linha' => 'Paula Primária Paula Primária concluída no prazo DATA',
                        'status' => 'Concluída',
                        'excluir' => 1,
                        'despacho' => '',
                        'selo' => 0,
                    ],
                ],
                'relatorio' => [
                    'Pendente Petição inicial Vence DATA Criada por Paula Primária Responsáveis Ana Equipe, Paula Primária Prazo DATA Última modificação Sem alterações Pasta SHOW-LOTE Situação Pendente Histórico Meta criada por Paula Primária para Ana Equipe, Paula Primária DATA Prazo definido para DATA DATA Outras metas desta pasta',
                    'Atrasada Juntar documentos 2 dias em atraso · prazo DATA Criada por Ana Equipe Responsáveis Bruno Leitor Prazo DATA Última modificação Sem alterações Pasta SHOW-LOTE Situação Atrasada Histórico Meta criada por Ana Equipe para Bruno Leitor DATA Prazo definido para DATA DATA 2 dias em atraso até hoje DATA Outras metas desta pasta',
                    'Pendente Despacho registrado Vence DATA Criada por Paula Primária Responsáveis Ana Equipe Prazo DATA Última modificação Sem alterações Pasta SHOW-LOTE Situação Pendente Histórico Meta criada por Paula Primária para Ana Equipe DATA Prazo definido para DATA DATA Outras metas desta pasta',
                    'Pendente Nota sem código Criada por Ana Equipe Responsáveis Não informado Prazo Sem prazo Última modificação Sem alterações Pasta SHOW-LOTE Situação Pendente Histórico Meta criada por Ana Equipe DATA Outras metas desta pasta',
                    'Concluída Meta concluída Concluída no prazo DATA Criada por Paula Primária Responsáveis Paula Primária Prazo DATA Última modificação DATA Pasta SHOW-LOTE Situação Concluída Histórico Meta criada por Paula Primária para Paula Primária DATA Prazo definido para DATA DATA Concluída DATA Outras metas desta pasta',
                ],
                'trilho' => 'Andamento das metas 1/5 concluídas 1 concluída 3 pendentes 1 atrasada Precisa de atenção Juntar documentos prazo DATA · 2 dias em atraso · Bruno Leitor Metas atrasadas continuam contando dias até serem concluídas. Responsáveis nas metas AE Ana Equipe 2 metas PP Paula Primária 2 metas BL Bruno Leitor 1 meta',
                'prazos' => 'Próximos prazos 3 Juntar documentos Bruno Leitor · DATA · Meta Vencido há 2 dias Juntar documentos Ver nas metas Encaminhar via Chat I.A Alertar Bruno Adicionar à agenda (.ics) Copiar Petição inicial Ana Equipe · DATA · Meta Faltam 3 dias Petição inicial Ver nas metas Encaminhar via Chat I.A Alertar Ana Adicionar à agenda (.ics) Copiar Despacho registrado Ana Equipe · DATA · Meta Faltam 10 dias Despacho registrado Ver nas metas Encaminhar via Chat I.A Alertar Ana Adicionar à agenda (.ics) Copiar',
                'andamento' => [
                    'atual' => '5',
                    'fases' => 'Abertura Documentação Contrato Distribuído Protocolo Em andamento Decisão Sentença Cumprimento Finalizada',
                    'historico' => [
                        'tarefas DATAMeta aberta',
                        'tarefas DATAMeta aberta',
                        'tarefas DATAMeta aberta',
                        'tarefas DATAMeta aberta',
                        'push DATAIntimação · Push',
                        'tarefas DATAMeta respondida',
                    ],
                    'sugestoes' => [
                        'Sentença 7 Push: sentença em DATA',
                    ],
                ],
            ],
            'visualizar' => [
                'status' => 200,
                'abas' => [
                    'dados-tab',
                    'tarefas-tab',
                    'processo-tab',
                    'detalhes-tab',
                    'documentos-tab',
                    'push-tab',
                ],
                'cadeado' => [
                    'selo' => '',
                    'faixa' => 'Pasta trancada pelo usuário primário: você pode visualizar, mas não alterar. Pedir para editar',
                    'modo' => '',
                    'equipe' => [],
                ],
                'metas' => [
                    [
                        'titulo' => 'Petição inicial',
                        'estado' => 'aberta',
                        'linha' => 'Paula Primária Ana Equipe, Paula Primária vence DATA',
                        'status' => 'Pendente',
                        'excluir' => 0,
                        'despacho' => '',
                        'selo' => 0,
                    ],
                    [
                        'titulo' => 'Juntar documentos',
                        'estado' => 'atrasada',
                        'linha' => 'Ana Equipe Bruno Leitor 2 dias em atraso',
                        'status' => 'Atrasada',
                        'excluir' => 0,
                        'despacho' => '',
                        'selo' => 0,
                    ],
                    [
                        'titulo' => 'Despacho registrado',
                        'estado' => 'aberta',
                        'linha' => 'Paula Primária Ana Equipe vence DATA',
                        'status' => 'Pendente',
                        'excluir' => 0,
                        'despacho' => '',
                        'selo' => 0,
                    ],
                    [
                        'titulo' => 'Nota sem código',
                        'estado' => 'aberta',
                        'linha' => 'Ana Equipe Não informado',
                        'status' => 'Pendente',
                        'excluir' => 0,
                        'despacho' => '',
                        'selo' => 0,
                    ],
                    [
                        'titulo' => 'Meta concluída',
                        'estado' => 'concluida',
                        'linha' => 'Paula Primária Paula Primária concluída no prazo DATA',
                        'status' => 'Concluída',
                        'excluir' => 0,
                        'despacho' => '',
                        'selo' => 0,
                    ],
                ],
                'relatorio' => [
                    'Pendente Petição inicial Vence DATA Criada por Paula Primária Responsáveis Ana Equipe, Paula Primária Prazo DATA Última modificação Sem alterações Pasta SHOW-LOTE Situação Pendente Histórico Meta criada por Paula Primária para Ana Equipe, Paula Primária DATA Prazo definido para DATA DATA Outras metas desta pasta',
                    'Atrasada Juntar documentos 2 dias em atraso · prazo DATA Criada por Ana Equipe Responsáveis Bruno Leitor Prazo DATA Última modificação Sem alterações Pasta SHOW-LOTE Situação Atrasada Histórico Meta criada por Ana Equipe para Bruno Leitor DATA Prazo definido para DATA DATA 2 dias em atraso até hoje DATA Outras metas desta pasta',
                    'Pendente Despacho registrado Vence DATA Criada por Paula Primária Responsáveis Ana Equipe Prazo DATA Última modificação Sem alterações Pasta SHOW-LOTE Situação Pendente Histórico Meta criada por Paula Primária para Ana Equipe DATA Prazo definido para DATA DATA Outras metas desta pasta',
                    'Pendente Nota sem código Criada por Ana Equipe Responsáveis Não informado Prazo Sem prazo Última modificação Sem alterações Pasta SHOW-LOTE Situação Pendente Histórico Meta criada por Ana Equipe DATA Outras metas desta pasta',
                    'Concluída Meta concluída Concluída no prazo DATA Criada por Paula Primária Responsáveis Paula Primária Prazo DATA Última modificação DATA Pasta SHOW-LOTE Situação Concluída Histórico Meta criada por Paula Primária para Paula Primária DATA Prazo definido para DATA DATA Concluída DATA Outras metas desta pasta',
                ],
                'trilho' => 'Andamento das metas 1/5 concluídas 1 concluída 3 pendentes 1 atrasada Precisa de atenção Juntar documentos prazo DATA · 2 dias em atraso · Bruno Leitor Metas atrasadas continuam contando dias até serem concluídas. Responsáveis nas metas AE Ana Equipe 2 metas PP Paula Primária 2 metas BL Bruno Leitor 1 meta',
                'prazos' => 'Próximos prazos 3 Juntar documentos Bruno Leitor · DATA · Meta Vencido há 2 dias Juntar documentos Ver nas metas Encaminhar via Chat I.A Adicionar à agenda (.ics) Copiar Petição inicial Ana Equipe · DATA · Meta Faltam 3 dias Petição inicial Ver nas metas Encaminhar via Chat I.A Adicionar à agenda (.ics) Copiar Despacho registrado Ana Equipe · DATA · Meta Faltam 10 dias Despacho registrado Ver nas metas Encaminhar via Chat I.A Adicionar à agenda (.ics) Copiar',
                'andamento' => [
                    'atual' => '5',
                    'fases' => 'Abertura Documentação Contrato Distribuído Protocolo Em andamento Decisão Sentença Cumprimento Finalizada',
                    'historico' => [
                        'tarefas DATAMeta aberta',
                        'tarefas DATAMeta aberta',
                        'tarefas DATAMeta aberta',
                        'tarefas DATAMeta aberta',
                        'push DATAIntimação · Push',
                        'tarefas DATAMeta respondida',
                    ],
                    'sugestoes' => [],
                ],
            ],
            'fora' => [
                'status' => 200,
                'trancada' => true,
            ],
        ];
    }
}
