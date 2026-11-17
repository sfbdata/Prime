<?php

declare(strict_types=1);

namespace App\Tests\Conector\Integration;

use App\Agenda\DTO\CompromissoOutput;
use App\Agenda\Entity\Evento;
use App\Agenda\UseCase\ListarCompromissosUseCase;
use App\ChatIa\DTO\ConsultaDePrazosInput;
use App\ChatIa\Enum\PeriodoDaConsulta;
use App\ChatIa\Exception\SemAcessoAsMetasNoChatException;
use App\ChatIa\UseCase\ConsultarPrazosEMetasUseCase;
use App\Conector\DTO\RespostaDaFerramenta;
use App\Conector\Entity\EscritorioDoConector;
use App\Conector\Exception\ErroDaFerramenta;
use App\Conector\Ferramenta\DespachoDasFerramentas;
use App\Conector\Ferramenta\LerAgendaFerramenta;
use App\Conector\Service\ModoDoConector;
use App\Conector\Service\PortaDoConectorNaConsultaDePrazos;
use App\Entity\Auth\User;
use App\Entity\Auth\UserTenant;
use App\Entity\Tarefa\Tarefa;
use App\Entity\Tenant\Tenant;
use App\Inteligencia\Entity\ConfiguracaoDeInteligencia;
use App\Pasta\Entity\CadeadoDaPasta;
use App\Pasta\Entity\ModoDoCadeado;
use App\Pasta\Entity\Pasta;
use App\Pasta\Entity\PastaPrazo;
use App\Pasta\Entity\PastaProcesso;
use App\Processo\DTO\DadosManuaisDoProcesso;
use App\Processo\Entity\Processo;
use App\Profile\Entity\UserProfile;
use App\Tests\Conector\Support\CenarioDoConectorTrait;
use App\Tests\Conector\Support\DublesDoMcpTrait;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * `ler_agenda` com o banco (spec mcp-remoto-oauth 6.4, 12.10 e 12.26, e a decisão 5 do PR 6), chamada como o despacho
 * chama, com o modo do conector ligado para a pessoa e sem o filtro de sessão do Doctrine: o escritório é só o da chamada.
 *
 * Agenda: compromisso de pasta em segredo e de pasta trancada some e é contado; o prazo de pasta em segredo também;
 * aniversário da equipe fica fora; sem o módulo Pastas, o `pasta_id` some; outro escritório da mesma pessoa não aparece.
 * Minhas metas: a porta do conector (módulo Tarefas e liberação do conector) no lugar do aceite da BlueJus I.A.; o chat
 * continua exigindo o aceite.
 */
#[CoversClass(LerAgendaFerramenta::class)]
#[CoversClass(PortaDoConectorNaConsultaDePrazos::class)]
final class LerAgendaFerramentaTest extends KernelTestCase
{
    use CenarioDoConectorTrait;
    use DublesDoMcpTrait;

    private const TUDO = ['modules.agenda.view', 'modules.pastas.view', 'modules.tarefas.view', 'resources.agenda.ver_prazos'];

    private int $numero = 0;
    private \DateTimeImmutable $amanha;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->amanha = new \DateTimeImmutable('tomorrow');
    }

    #[TestDox('agenda: compromisso de pasta em segredo e de pasta trancada some e é contado; o prazo em segredo também; sem pasta e de pasta comum fica, com o pasta_id')]
    public function testSegredoECadeado(): void
    {
        $escritorio = $this->escritorio();
        $pessoa = $this->pessoa();
        $vinculo = $this->vinculo($pessoa, $escritorio, permissoes: self::TUDO);
        $comum = $this->pasta($escritorio);
        $secreta = $this->pasta($escritorio, nivelSigilo: 1);
        $trancada = $this->pasta($escritorio, trancada: true);
        $this->evento($escritorio, $pessoa, 'Reunião sem pasta');
        $daComum = $this->evento($escritorio, $pessoa, 'Reunião da pasta comum', $comum);
        $this->evento($escritorio, $pessoa, 'Reunião sigilosa', $secreta);
        $this->evento($escritorio, $pessoa, 'Reunião trancada', $trancada);
        $this->prazo($escritorio, $comum, 'Contestação');
        $this->prazo($escritorio, $secreta, 'Prazo sigiloso');
        self::assertFalse($this->em()->getFilters()->isEnabled('tenant'), 'premissa: sem o filtro de sessão');

        $resposta = $this->agenda($pessoa, $vinculo, ['incluir' => ['compromissos', 'prazos']]);
        $dados = $resposta->dados;

        $titulos = array_column($dados['itens'], 'titulo');
        sort($titulos);
        self::assertSame(['Contestação · Pasta ' . $comum->getNup(), 'Reunião da pasta comum', 'Reunião sem pasta'], $titulos);
        self::assertSame(2, $dados['omitidos_por_segredo_de_justica'], 'o compromisso e o prazo da pasta em segredo');
        self::assertSame(1, $dados['omitidos_por_pasta_trancada']);
        self::assertSame(['itens' => 3, 'em_segredo' => 2, 'trancados' => 1], $resposta->contagens);
        self::assertSame(['pasta' => [$comum->getId()]], $resposta->ids);
        self::assertArrayNotHasKey('datas_do_sistema', $dados, 'só as partes pedidas');

        $doEvento = self::itemDe($dados['itens'], 'Reunião da pasta comum');
        self::assertSame('compromisso', $doEvento['tipo']);
        self::assertSame($daComum->getId(), $doEvento['evento_id']);
        self::assertSame($comum->getId(), $doEvento['pasta_id']);
        self::assertSame($comum->getNup(), $doEvento['pasta_numero']);
        self::assertTrue($doEvento['meu']);

        $json = (string) json_encode($dados, \JSON_UNESCAPED_UNICODE);
        foreach (['sigilos', 'Reunião trancada', (string) $secreta->getNup(), (string) $trancada->getNup()] as $proibido) {
            self::assertStringNotContainsString($proibido, $json);
        }
    }

    #[TestDox('agenda: o aniversário da equipe no período fica fora; as datas do sistema vêm no padrão')]
    public function testAniversarioFora(): void
    {
        $escritorio = $this->escritorio();
        $pessoa = $this->pessoa();
        $vinculo = $this->vinculo($pessoa, $escritorio, permissoes: self::TUDO);
        $perfil = new UserProfile($pessoa);
        $perfil->setDataNascimento(new \DateTime('1990-' . $this->amanha->format('m-d')));
        $this->em()->persist($perfil);
        $this->em()->flush();
        $this->evento($escritorio, $pessoa, 'Audiência de conciliação');
        $daTela = static::getContainer()->get(ListarCompromissosUseCase::class)
            ->executar($escritorio, $pessoa, new \DateTimeImmutable('today'), $this->amanha->modify('+5 days'), true, true, true);
        self::assertContains(CompromissoOutput::ORIGEM_ANIV, array_map(static fn (CompromissoOutput $c): string => $c->origem, $daTela), 'premissa: a tela mostraria o aniversário');

        $dados = $this->agenda($pessoa, $vinculo, [])->dados;

        self::assertSame(['Audiência de conciliação'], array_column($dados['itens'], 'titulo'));
        self::assertSame(['compromisso'], array_column($dados['itens'], 'tipo'));
        self::assertArrayHasKey('datas_do_sistema', $dados);
        self::assertArrayNotHasKey('lembretes', $dados, 'lembrete só quando pedido');
        self::assertStringNotContainsString('Aniversário', (string) json_encode($dados, \JSON_UNESCAPED_UNICODE));
    }

    #[TestDox('agenda sem o módulo Pastas: o pasta_id some do compromisso e do prazo, e o número da pasta some do compromisso')]
    public function testSemModuloPastas(): void
    {
        $escritorio = $this->escritorio();
        $pessoa = $this->pessoa();
        $vinculo = $this->vinculo($pessoa, $escritorio, permissoes: ['modules.agenda.view', 'resources.agenda.ver_prazos']);
        $pasta = $this->pasta($escritorio);
        $secreta = $this->pasta($escritorio, manual: true);
        $this->evento($escritorio, $pessoa, 'Reunião com o cliente', $pasta);
        $this->evento($escritorio, $pessoa, 'Reunião sigilosa', $secreta);
        $this->prazo($escritorio, $pasta, 'Réplica');

        $dados = $this->agenda($pessoa, $vinculo, ['incluir' => ['compromissos', 'prazos']])->dados;

        self::assertCount(2, $dados['itens']);
        self::assertSame([null, null], array_column($dados['itens'], 'pasta_id'));
        self::assertNull(self::itemDe($dados['itens'], 'Reunião com o cliente')['pasta_numero']);
        self::assertSame(1, $dados['omitidos_por_segredo_de_justica'], 'sem o módulo, o segredo continua tirando o compromisso');
    }

    #[TestDox('agenda: com a pessoa em dois escritórios, o compromisso e o prazo do outro não aparecem')]
    public function testOutroEscritorio(): void
    {
        $a = $this->escritorio('Escritório A');
        $b = $this->escritorio('Escritório B');
        $pessoa = $this->pessoa();
        $vinculoA = $this->vinculo($pessoa, $a, permissoes: self::TUDO);
        $this->vinculo($pessoa, $b, permissoes: self::TUDO);
        $this->evento($a, $pessoa, 'Reunião em A');
        $this->evento($b, $pessoa, 'Reunião em B', $this->pasta($b));
        $this->prazo($b, $this->pasta($b), 'Prazo em B');

        $dados = $this->agenda($pessoa, $vinculoA, ['so_minha' => false, 'incluir' => ['compromissos', 'prazos']])->dados;

        self::assertSame(['Reunião em A'], array_column($dados['itens'], 'titulo'));
    }

    #[TestDox('agenda: lembretes pedidos seguem o mesmo filtro (o da pasta em segredo some e é contado)')]
    public function testLembretes(): void
    {
        $escritorio = $this->escritorio();
        $pessoa = $this->pessoa();
        $vinculo = $this->vinculo($pessoa, $escritorio, permissoes: self::TUDO);
        $daqui = (new \DateTimeImmutable())->modify('+2 hours');
        $this->evento($escritorio, $pessoa, 'Ligar para o perito', $this->pasta($escritorio), $daqui);
        $this->evento($escritorio, $pessoa, 'Ligar sobre o sigilo', $this->pasta($escritorio, nivelSigilo: 1), $daqui);

        $dados = $this->agenda($pessoa, $vinculo, ['incluir' => ['lembretes']])->dados;

        self::assertSame([], $dados['itens'], 'sem compromissos pedidos');
        self::assertSame(['Ligar para o perito'], array_column($dados['lembretes'], 'titulo'));
        self::assertSame(1, $dados['omitidos_por_segredo_de_justica']);
    }

    #[TestDox('agenda longa: a lista se corta pelo tamanho (até 100 itens), a página seguinte em proxima_pagina; datas do sistema só na primeira')]
    public function testPaginacao(): void
    {
        $escritorio = $this->escritorio();
        $pessoa = $this->pessoa();
        $vinculo = $this->vinculo($pessoa, $escritorio, permissoes: self::TUDO);
        for ($n = 1; $n <= 120; ++$n) {
            $inicio = $this->amanha->setTime(8, 0)->modify('+' . $n . ' minutes');
            $this->em()->persist((new Evento())->setTitulo('Reunião ' . $n)->setDataInicio($inicio)->setDataFim($inicio->modify('+1 minute'))->setCriador($pessoa)->setTenant($escritorio));
        }
        $this->em()->flush();

        $primeira = $this->agenda($pessoa, $vinculo, []);
        $segunda = $this->agenda($pessoa, $vinculo, ['pagina' => 2]);
        $alem = $this->agenda($pessoa, $vinculo, ['pagina' => 3]);

        $naPrimeira = \count($primeira->dados['itens']);
        self::assertGreaterThan(0, $naPrimeira);
        self::assertLessThanOrEqual(LerAgendaFerramenta::ITENS_POR_PAGINA, $naPrimeira);
        self::assertLessThanOrEqual(LerAgendaFerramenta::ORCAMENTO_POR_PAGINA, mb_strlen((string) json_encode($primeira->dados['itens'], \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES)));
        self::assertSame(2, $primeira->proximaPagina);
        self::assertArrayHasKey('datas_do_sistema', $primeira->dados);
        self::assertSame(120, $naPrimeira + \count($segunda->dados['itens']));
        self::assertNull($segunda->proximaPagina);
        self::assertArrayNotHasKey('datas_do_sistema', $segunda->dados);
        self::assertSame(120, $segunda->dados['total_de_itens']);
        self::assertSame('Reunião ' . ($naPrimeira + 1), $segunda->dados['itens'][0]['titulo'], 'a página 2 continua de onde a 1 parou');
        self::assertSame([], $alem->dados['itens']);
        self::assertNull($alem->proximaPagina);
    }

    #[TestDox('agenda sem o módulo Agenda: sem permissão')]
    public function testSemModuloAgenda(): void
    {
        $escritorio = $this->escritorio();
        $pessoa = $this->pessoa();
        $vinculo = $this->vinculo($pessoa, $escritorio, permissoes: ['modules.pastas.view']);

        self::assertSame(ErroDaFerramenta::MOTIVO_SEM_PERMISSAO, $this->recusa($pessoa, $vinculo, [])->motivo);
    }

    #[TestDox('minhas_metas com o conector liberado: só as metas da pessoa neste escritório, sem a da pasta em segredo (contada), sem gravar nada')]
    public function testMinhasMetas(): void
    {
        $a = $this->escritorio('Escritório A');
        $b = $this->escritorio('Escritório B');
        $pessoa = $this->pessoa();
        $vinculo = $this->vinculo($pessoa, $a, permissoes: self::TUDO);
        $this->vinculo($pessoa, $b, permissoes: self::TUDO);
        $this->liberarConector($a);
        $this->meta($a, $this->pasta($a), $pessoa, 'Contestação de hoje');
        $this->meta($a, $this->pasta($a, nivelSigilo: 1), $pessoa, 'Meta sigilosa');
        $this->meta($b, $this->pasta($b), $pessoa, 'Meta do outro escritório');
        $this->meta($a, $this->pasta($a), $this->pessoa(), 'Meta de um colega');
        $antes = $this->contarMetas();

        $resposta = $this->executar($pessoa, $vinculo, ['acao' => 'minhas_metas', 'periodo' => 'hoje']);
        $dados = $resposta->dados;

        self::assertSame(['Contestação de hoje'], array_column($dados['metas'], 'titulo'));
        self::assertSame(1, $dados['total']);
        self::assertSame(1, $dados['omitidas_por_segredo_de_justica']);
        self::assertSame(['hoje', 'responsavel'], [$dados['periodo'], $dados['papel']]);
        self::assertSame(['metas' => 1, 'em_segredo' => 1], $resposta->contagens);
        self::assertSame($antes, $this->contarMetas(), 'nada é gravado');
    }

    #[TestDox('minhas_metas sem o módulo Tarefas: sem permissão')]
    public function testMetasSemModuloTarefas(): void
    {
        $escritorio = $this->escritorio();
        $pessoa = $this->pessoa();
        $vinculo = $this->vinculo($pessoa, $escritorio, permissoes: ['modules.agenda.view']);
        $this->liberarConector($escritorio);

        self::assertSame(ErroDaFerramenta::MOTIVO_SEM_PERMISSAO, $this->recusa($pessoa, $vinculo, ['acao' => 'minhas_metas', 'periodo' => 'hoje'])->motivo);
    }

    #[TestDox('minhas_metas com o aceite da BlueJus I.A. e sem a liberação do conector: recusa pela porta do conector; o chat, com o aceite, passa')]
    public function testMetasComAceiteDaIaSemConector(): void
    {
        $escritorio = $this->escritorio();
        $pessoa = $this->pessoa();
        $vinculo = $this->vinculo($pessoa, $escritorio, permissoes: self::TUDO);
        $this->aceiteDaIa($escritorio, $pessoa);
        $this->meta($escritorio, $this->pasta($escritorio), $pessoa, 'Contestação de hoje');

        $erro = $this->recusa($pessoa, $vinculo, ['acao' => 'minhas_metas', 'periodo' => 'hoje']);

        self::assertSame(ErroDaFerramenta::MOTIVO_SEM_PERMISSAO, $erro->motivo);
        $peloChat = static::getContainer()->get(ConsultarPrazosEMetasUseCase::class)->executar($pessoa, $escritorio, new ConsultaDePrazosInput(PeriodoDaConsulta::Hoje));
        self::assertSame(1, $peloChat->total, 'o chat segue a guarda dele, sem a porta do conector');
    }

    #[TestDox('minhas_metas com o conector liberado e sem o aceite da I.A.: o conector passa; o chat continua exigindo o aceite')]
    public function testChatContinuaExigindoOAceite(): void
    {
        $escritorio = $this->escritorio();
        $pessoa = $this->pessoa();
        $vinculo = $this->vinculo($pessoa, $escritorio, permissoes: self::TUDO);
        $this->liberarConector($escritorio);
        $this->meta($escritorio, $this->pasta($escritorio), $pessoa, 'Contestação de hoje');

        self::assertSame(1, $this->executar($pessoa, $vinculo, ['acao' => 'minhas_metas', 'periodo' => 'hoje'])->dados['total']);

        $this->expectException(SemAcessoAsMetasNoChatException::class);
        static::getContainer()->get(ConsultarPrazosEMetasUseCase::class)->executar($pessoa, $escritorio, new ConsultaDePrazosInput(PeriodoDaConsulta::Hoje));
    }

    #[TestDox('o container registra a ler_agenda no despacho')]
    public function testRegistrada(): void
    {
        $nomes = array_map(static fn ($f): string => $f->nome(), static::getContainer()->get(DespachoDasFerramentas::class)->registradas());

        self::assertContains(LerAgendaFerramenta::NOME, $nomes);
    }

    /** @param array<string, mixed> $argumentos */
    private function agenda(User $pessoa, UserTenant $vinculo, array $argumentos): RespostaDaFerramenta
    {
        $argumentos += ['de' => (new \DateTimeImmutable('today'))->format('Y-m-d'), 'ate' => $this->amanha->modify('+5 days')->format('Y-m-d')];

        return $this->executar($pessoa, $vinculo, $argumentos);
    }

    /**
     * Com o modo do conector ligado para o vínculo, como o portão faz.
     *
     * @param array<string, mixed> $argumentos
     */
    private function executar(User $pessoa, UserTenant $vinculo, array $argumentos): RespostaDaFerramenta
    {
        $modo = static::getContainer()->get(ModoDoConector::class);
        $modo->ligar($vinculo);
        try {
            return static::getContainer()->get(LerAgendaFerramenta::class)
                ->executar($argumentos, self::chamada(['mcp:leitura'], $pessoa, $vinculo->getTenant(), $vinculo));
        } finally {
            $modo->reset();
        }
    }

    /** @param array<string, mixed> $argumentos */
    private function recusa(User $pessoa, UserTenant $vinculo, array $argumentos): ErroDaFerramenta
    {
        try {
            $this->executar($pessoa, $vinculo, $argumentos);
        } catch (ErroDaFerramenta $erro) {
            return $erro;
        }
        self::fail('devia recusar');
    }

    /**
     * @param list<array<string, mixed>> $itens
     *
     * @return array<string, mixed>
     */
    private static function itemDe(array $itens, string $titulo): array
    {
        foreach ($itens as $item) {
            if ($item['titulo'] === $titulo) {
                return $item;
            }
        }
        self::fail('sem o item ' . $titulo);
    }

    private function evento(Tenant $escritorio, User $criador, string $titulo, ?Pasta $pasta = null, ?\DateTimeImmutable $inicio = null): Evento
    {
        $inicio ??= $this->amanha->setTime(10, 0);
        $evento = (new Evento())
            ->setTitulo($titulo)
            ->setDataInicio($inicio)
            ->setDataFim($inicio->modify('+1 hour'))
            ->setCriador($criador)
            ->setTenant($escritorio)
            ->setPasta($pasta)
            ->setLembreteMinutos(30);
        $this->em()->persist($evento);
        $this->em()->flush();

        return $evento;
    }

    private function prazo(Tenant $escritorio, Pasta $pasta, string $titulo): PastaPrazo
    {
        $prazo = new PastaPrazo($escritorio, $pasta, null);
        $prazo->definir(PastaPrazo::TIPO_PRAZO, $titulo, $this->amanha, null, null);
        $this->em()->persist($prazo);
        $this->em()->flush();

        return $prazo;
    }

    private function meta(Tenant $escritorio, Pasta $pasta, User $responsavel, string $titulo): Tarefa
    {
        $meta = new Tarefa();
        $meta->setTenant($escritorio);
        $meta->setPasta($pasta);
        $meta->setTitulo($titulo);
        $meta->setDescricao('');
        $meta->setPrazo(new \DateTimeImmutable('today'));
        $meta->addResponsavel($responsavel);
        $this->em()->persist($meta);
        $this->em()->flush();

        return $meta;
    }

    private function contarMetas(): int
    {
        return (int) $this->em()->getConnection()->fetchOne('SELECT COUNT(*) FROM tarefa');
    }

    /** Uma pasta do escritório com um processo público (ou em segredo), ou trancada para todos fora o primário. */
    private function pasta(Tenant $escritorio, int $nivelSigilo = 0, bool $manual = false, bool $trancada = false): Pasta
    {
        $pasta = (new Pasta())->setNup('AG-' . uniqid());
        $pasta->setTenant($escritorio);
        $this->em()->persist($pasta);
        $processo = new Processo();
        $processo->setNumeroProcesso(sprintf('0701134572025807%04d', ++$this->numero) . uniqid());
        $processo->setSiglaTribunal('TJDFT');
        $processo->setClasseProcessual('Procedimento Comum');
        $processo->setAssuntoProcessual('Assunto');
        $processo->setTenant($escritorio);
        $processo->setNivelSigilo($nivelSigilo);
        if ($manual) {
            $processo->definirDadosManuais(new DadosManuaisDoProcesso(segredo: true), $this->pessoa());
        }
        $this->em()->persist($processo);
        $vinculo = new PastaProcesso($pasta, $processo);
        $this->em()->persist($vinculo);
        $pasta->getPastaProcessos()->add($vinculo);
        if ($trancada) {
            $this->em()->persist(new CadeadoDaPasta($escritorio, $pasta, ModoDoCadeado::Todos, null, new \DateTimeImmutable()));
        }
        $this->em()->flush();

        return $pasta;
    }

    private function liberarConector(Tenant $escritorio): void
    {
        $doConector = new EscritorioDoConector($escritorio);
        $doConector->liberarPeloMaster();
        $doConector->ligar($this->pessoa(), new \DateTimeImmutable());
        $this->em()->persist($doConector);
        $this->em()->flush();
    }

    private function aceiteDaIa(Tenant $escritorio, User $por): void
    {
        $configuracao = new ConfiguracaoDeInteligencia($escritorio);
        $configuracao->liberarPeloMaster();
        $configuracao->atualizar(true, 50, 500, true, $por);
        $this->em()->persist($configuracao);
        $this->em()->flush();
    }
}
