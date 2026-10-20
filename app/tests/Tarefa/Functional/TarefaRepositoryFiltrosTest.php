<?php

declare(strict_types=1);

namespace App\Tests\Tarefa\Functional;

use App\Entity\Tarefa\Tarefa;
use App\Pasta\Entity\PrioridadePasta;
use App\Tarefa\Enum\AbaMetas;
use App\Tarefa\Repository\TarefaRepository;
use App\Tests\Factory\Auth\UserFactory;
use App\Tests\Factory\Pasta\PastaFactory;
use App\Tests\Factory\Tarefa\TarefaFactory;
use App\Tests\Factory\Tenant\TenantFactory;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Zenstruck\Foundry\Test\Factories;

/**
 * Facetas da barra de filtro, exercitadas na aba "Sou responsável".
 *
 * Antes da frente `minhas-metas-abas` o método sob teste era `findByResponsavelComFiltros`,
 * que misturava responsável e criador numa lista só. O escopo por papel agora é do enum
 * `AbaMetas`; o que estes testes garantem é que a FACETA continua valendo dentro da aba.
 */
#[CoversClass(TarefaRepository::class)]
#[Group('tarefa')]
final class TarefaRepositoryFiltrosTest extends KernelTestCase
{
    use Factories;

    private TarefaRepository $repo;
    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->repo = static::getContainer()->get(TarefaRepository::class);
        $this->em   = static::getContainer()->get(EntityManagerInterface::class);
    }

    #[TestDox('Sem filtros retorna só as tarefas do usuário (responsável), sem vazar as de colegas')]
    public function testBaseEscopadaAoUsuario(): void
    {
        $tenant = TenantFactory::createOne()->_real();
        $pasta  = PastaFactory::createOne(['tenant' => $tenant])->_real();
        $eu     = UserFactory::createOne()->_real();
        $colega = UserFactory::createOne()->_real();

        $minha = TarefaFactory::createOne(['pasta' => $pasta])->_real();
        $minha->addResponsavel($eu);
        $doColega = TarefaFactory::createOne(['pasta' => $pasta])->_real();
        $doColega->addResponsavel($colega);
        $this->em->flush();

        $resultado = $this->repo->findParaMinhasMetas($eu, AbaMetas::RESPONSAVEL, []);

        self::assertCount(1, $resultado);
        self::assertSame($minha->getId(), $resultado[0]->getId());
    }

    #[TestDox('Busca filtra por título, mantendo o escopo do usuário')]
    public function testBuscaPorTitulo(): void
    {
        $tenant = TenantFactory::createOne()->_real();
        $pasta  = PastaFactory::createOne(['tenant' => $tenant])->_real();
        $eu     = UserFactory::createOne()->_real();

        $achavel = TarefaFactory::createOne(['pasta' => $pasta, 'titulo' => 'Protocolar recurso urgente'])->_real();
        $achavel->addResponsavel($eu);
        $outra = TarefaFactory::createOne(['pasta' => $pasta, 'titulo' => 'Agendar reunião'])->_real();
        $outra->addResponsavel($eu);
        $this->em->flush();

        $resultado = $this->repo->findParaMinhasMetas($eu, AbaMetas::RESPONSAVEL, ['busca' => 'recurso']);

        self::assertCount(1, $resultado);
        self::assertSame($achavel->getId(), $resultado[0]->getId());
    }

    #[TestDox('Busca ignora acento e ç: "peticao" acha "Petição"')]
    public function testBuscaIgnoraAcento(): void
    {
        $tenant = TenantFactory::createOne()->_real();
        $pasta  = PastaFactory::createOne(['tenant' => $tenant])->_real();
        $eu     = UserFactory::createOne()->_real();

        $achavel = TarefaFactory::createOne(['pasta' => $pasta, 'titulo' => 'Petição inicial'])->_real();
        $achavel->addResponsavel($eu);
        $outra = TarefaFactory::createOne(['pasta' => $pasta, 'titulo' => 'Agendar reunião'])->_real();
        $outra->addResponsavel($eu);
        $this->em->flush();

        // termo sem ç, sem ~ e minúsculo deve casar com "Petição"
        $resultado = $this->repo->findParaMinhasMetas($eu, AbaMetas::RESPONSAVEL, ['busca' => 'peticao']);

        self::assertCount(1, $resultado);
        self::assertSame($achavel->getId(), $resultado[0]->getId());
    }

    #[TestDox('Faceta de status estreita para o status pedido')]
    public function testFacetaStatus(): void
    {
        $tenant = TenantFactory::createOne()->_real();
        $pasta  = PastaFactory::createOne(['tenant' => $tenant])->_real();
        $eu     = UserFactory::createOne()->_real();

        $pendente = TarefaFactory::createOne(['pasta' => $pasta, 'status' => Tarefa::STATUS_PENDENTE])->_real();
        $pendente->addResponsavel($eu);
        $concluida = TarefaFactory::createOne(['pasta' => $pasta, 'status' => Tarefa::STATUS_CONCLUIDA])->_real();
        $concluida->addResponsavel($eu);
        // A tela corta concluídas com mais de 30 dias, e usa `dataConclusao` como referência.
        // O fluxo real (ExcluirTarefa/concluir) sempre grava essa data; a factory não.
        $concluida->setDataConclusao(new \DateTimeImmutable('-1 day'));
        $this->em->flush();

        self::assertCount(1, $this->repo->findParaMinhasMetas($eu, AbaMetas::RESPONSAVEL, ['status' => Tarefa::STATUS_CONCLUIDA]));
        self::assertCount(1, $this->repo->findParaMinhasMetas($eu, AbaMetas::RESPONSAVEL, ['status' => Tarefa::STATUS_PENDENTE]));
    }

    #[TestDox('Faceta de prioridade filtra pela prioridade da pasta vinculada')]
    public function testFacetaPrioridadeDaPasta(): void
    {
        $tenant   = TenantFactory::createOne()->_real();
        $urgente  = PastaFactory::createOne(['tenant' => $tenant, 'prioridade' => PrioridadePasta::Urgente])->_real();
        $normal   = PastaFactory::createOne(['tenant' => $tenant, 'prioridade' => PrioridadePasta::Normal])->_real();
        $eu       = UserFactory::createOne()->_real();

        $tUrg = TarefaFactory::createOne(['pasta' => $urgente])->_real();
        $tUrg->addResponsavel($eu);
        $tNor = TarefaFactory::createOne(['pasta' => $normal])->_real();
        $tNor->addResponsavel($eu);
        $this->em->flush();

        $resultado = $this->repo->findParaMinhasMetas($eu, AbaMetas::RESPONSAVEL, ['prioridade' => 'urgente']);

        self::assertCount(1, $resultado);
        self::assertSame($tUrg->getId(), $resultado[0]->getId());
    }

    #[TestDox('Faceta de prazo separa vencidas, próximas e sem prazo')]
    public function testFacetaPrazo(): void
    {
        $tenant = TenantFactory::createOne()->_real();
        $pasta  = PastaFactory::createOne(['tenant' => $tenant])->_real();
        $eu     = UserFactory::createOne()->_real();

        $ontem   = new \DateTimeImmutable('-1 day');
        $em3dias = new \DateTimeImmutable('+3 days');

        $vencida = TarefaFactory::createOne(['pasta' => $pasta, 'status' => Tarefa::STATUS_PENDENTE, 'prazo' => $ontem])->_real();
        $vencida->addResponsavel($eu);
        $proxima = TarefaFactory::createOne(['pasta' => $pasta, 'status' => Tarefa::STATUS_PENDENTE, 'prazo' => $em3dias])->_real();
        $proxima->addResponsavel($eu);
        $semPrazo = TarefaFactory::createOne(['pasta' => $pasta, 'status' => Tarefa::STATUS_PENDENTE, 'prazo' => null])->_real();
        $semPrazo->addResponsavel($eu);
        $this->em->flush();

        $vencidas = $this->repo->findParaMinhasMetas($eu, AbaMetas::RESPONSAVEL, ['prazo' => 'vencidas']);
        self::assertCount(1, $vencidas);
        self::assertSame($vencida->getId(), $vencidas[0]->getId());

        $proximas = $this->repo->findParaMinhasMetas($eu, AbaMetas::RESPONSAVEL, ['prazo' => 'proximas']);
        self::assertCount(1, $proximas);
        self::assertSame($proxima->getId(), $proximas[0]->getId());

        $sem = $this->repo->findParaMinhasMetas($eu, AbaMetas::RESPONSAVEL, ['prazo' => 'sem']);
        self::assertCount(1, $sem);
        self::assertSame($semPrazo->getId(), $sem[0]->getId());
    }

    #[TestDox('Busca acha a meta pelo nº da pasta (desenho de 19/10: "Buscar por título, pasta ou cliente")')]
    public function testBuscaPorNumeroDaPasta(): void
    {
        $tenant = TenantFactory::createOne()->_real();
        $alvo   = PastaFactory::createOne(['tenant' => $tenant, 'nup' => 'PASTA-1361A'])->_real();
        $outra  = PastaFactory::createOne(['tenant' => $tenant, 'nup' => 'PASTA-0828'])->_real();
        $eu     = UserFactory::createOne()->_real();

        $achavel = TarefaFactory::createOne(['pasta' => $alvo, 'titulo' => 'Juntar procuração', 'descricao' => ''])->_real();
        $achavel->addResponsavel($eu);
        $fora = TarefaFactory::createOne(['pasta' => $outra, 'titulo' => 'Agendar reunião', 'descricao' => ''])->_real();
        $fora->addResponsavel($eu);
        $this->em->flush();

        $resultado = $this->repo->findParaMinhasMetas($eu, AbaMetas::RESPONSAVEL, ['busca' => '1361a']);

        self::assertSame([$achavel->getId()], array_map(static fn (Tarefa $t): ?int => $t->getId(), $resultado));
    }

    #[TestDox('Busca acha a meta pelo nome do cliente da pasta, sem acento e em minúsculas')]
    public function testBuscaPorClienteSemAcento(): void
    {
        $tenant = TenantFactory::createOne()->_real();
        $alvo   = PastaFactory::createOne(['tenant' => $tenant, 'nomeCliente' => 'José do Egito Alves de Souza'])->_real();
        $outra  = PastaFactory::createOne(['tenant' => $tenant, 'nomeCliente' => 'Condomínio Rio Negro'])->_real();
        $eu     = UserFactory::createOne()->_real();

        $achavel = TarefaFactory::createOne(['pasta' => $alvo, 'titulo' => 'Contrato', 'descricao' => ''])->_real();
        $achavel->addResponsavel($eu);
        $fora = TarefaFactory::createOne(['pasta' => $outra, 'titulo' => 'Análise de pasta', 'descricao' => ''])->_real();
        $fora->addResponsavel($eu);
        $this->em->flush();

        $resultado = $this->repo->findParaMinhasMetas($eu, AbaMetas::RESPONSAVEL, ['busca' => 'jose do egito']);

        self::assertSame([$achavel->getId()], array_map(static fn (Tarefa $t): ?int => $t->getId(), $resultado));
    }

    #[TestDox('Busca pela pasta não duplica linhas: duas metas da mesma pasta voltam uma vez cada, e a que casa por título e por pasta volta uma vez só')]
    public function testBuscaPelaPastaNaoDuplica(): void
    {
        $tenant = TenantFactory::createOne()->_real();
        $pasta  = PastaFactory::createOne(['tenant' => $tenant, 'nup' => 'RECURSO-77', 'nomeCliente' => 'Recurso Ltda'])->_real();
        $eu     = UserFactory::createOne()->_real();
        $colega = UserFactory::createOne()->_real();

        $a = TarefaFactory::createOne(['pasta' => $pasta, 'titulo' => 'Protocolar recurso', 'descricao' => 'recurso'])->_real();
        $a->addResponsavel($eu);
        $a->addResponsavel($colega);
        $b = TarefaFactory::createOne(['pasta' => $pasta, 'titulo' => 'Conferir custas', 'descricao' => ''])->_real();
        $b->addResponsavel($eu);
        $this->em->flush();

        $ids = array_map(static fn (Tarefa $t): ?int => $t->getId(), $this->repo->findParaMinhasMetas($eu, AbaMetas::RESPONSAVEL, ['busca' => 'recurso']));
        sort($ids);
        $esperado = [$a->getId(), $b->getId()];
        sort($esperado);

        self::assertSame($esperado, $ids);
    }

    #[TestDox('Busca pela pasta combinada com a faceta de prioridade usa um join só e respeita as duas')]
    public function testBuscaPelaPastaComPrioridade(): void
    {
        $tenant  = TenantFactory::createOne()->_real();
        $urgente = PastaFactory::createOne(['tenant' => $tenant, 'prioridade' => PrioridadePasta::Urgente, 'nomeCliente' => 'Francisca Maria'])->_real();
        $normal  = PastaFactory::createOne(['tenant' => $tenant, 'prioridade' => PrioridadePasta::Normal, 'nomeCliente' => 'Francisca Maria'])->_real();
        $outro   = PastaFactory::createOne(['tenant' => $tenant, 'prioridade' => PrioridadePasta::Urgente, 'nomeCliente' => 'Wellington'])->_real();
        $eu      = UserFactory::createOne()->_real();

        $certa = TarefaFactory::createOne(['pasta' => $urgente, 'titulo' => 'Meta um', 'descricao' => ''])->_real();
        $certa->addResponsavel($eu);
        foreach ([$normal, $outro] as $p) {
            TarefaFactory::createOne(['pasta' => $p, 'titulo' => 'Meta outra', 'descricao' => ''])->_real()->addResponsavel($eu);
        }
        $this->em->flush();

        $resultado = $this->repo->findParaMinhasMetas($eu, AbaMetas::RESPONSAVEL, ['busca' => 'francisca', 'prioridade' => 'urgente']);

        self::assertSame([$certa->getId()], array_map(static fn (Tarefa $t): ?int => $t->getId(), $resultado));
    }

    #[TestDox('Busca pelo título continua achando a meta cuja pasta e cliente não casam')]
    public function testBuscaPeloTituloSemCasarAPasta(): void
    {
        $tenant = TenantFactory::createOne()->_real();
        $pasta  = PastaFactory::createOne(['tenant' => $tenant, 'nup' => 'PASTA-1', 'nomeCliente' => null])->_real();
        $eu     = UserFactory::createOne()->_real();

        $meta = TarefaFactory::createOne(['pasta' => $pasta, 'titulo' => 'Revisar petição inicial', 'descricao' => ''])->_real();
        $meta->addResponsavel($eu);
        $this->em->flush();

        $resultado = $this->repo->findParaMinhasMetas($eu, AbaMetas::RESPONSAVEL, ['busca' => 'peticao inicial']);

        self::assertSame([$meta->getId()], array_map(static fn (Tarefa $t): ?int => $t->getId(), $resultado));
    }

    #[TestDox('Busca escapa os curingas do LIKE: "%" e "_" procuram o caractere, não casam tudo')]
    public function testBuscaEscapaCuringas(): void
    {
        $tenant = TenantFactory::createOne()->_real();
        $pasta  = PastaFactory::createOne(['tenant' => $tenant, 'nup' => 'PASTA-9', 'nomeCliente' => 'Fulano'])->_real();
        $eu     = UserFactory::createOne()->_real();

        $comPorcento = TarefaFactory::createOne(['pasta' => $pasta, 'titulo' => 'Reajuste de 10% no aluguel', 'descricao' => ''])->_real();
        $comPorcento->addResponsavel($eu);
        $comSublinhado = TarefaFactory::createOne(['pasta' => $pasta, 'titulo' => 'Arquivo peca_final', 'descricao' => ''])->_real();
        $comSublinhado->addResponsavel($eu);
        $comum = TarefaFactory::createOne(['pasta' => $pasta, 'titulo' => 'Agendar reunião', 'descricao' => ''])->_real();
        $comum->addResponsavel($eu);
        $this->em->flush();

        $ids = static fn (array $r): array => array_map(static fn (Tarefa $t): ?int => $t->getId(), $r);

        self::assertSame([$comPorcento->getId()], $ids($this->repo->findParaMinhasMetas($eu, AbaMetas::RESPONSAVEL, ['busca' => '%'])));
        self::assertSame([$comSublinhado->getId()], $ids($this->repo->findParaMinhasMetas($eu, AbaMetas::RESPONSAVEL, ['busca' => '_'])));
        self::assertSame([], $this->repo->findParaMinhasMetas($eu, AbaMetas::RESPONSAVEL, ['busca' => 'a\\']), 'a barra também é literal');
    }
}
