<?php

declare(strict_types=1);

namespace App\Tests\Backup\Functional;

use App\Agenda\Service\PermissoesDaAgenda;
use App\Backup\Coletor\ColetorDaAgenda;
use App\Backup\Coletor\ColetorDaAssinatura;
use App\Backup\Coletor\ColetorDaCobranca;
use App\Backup\Coletor\ColetorDeCalculos;
use App\Backup\Coletor\ColetorDeClientes;
use App\Backup\Coletor\ColetorDeKanban;
use App\Backup\Coletor\ColetorDePastas;
use App\Backup\Coletor\ColetorDeProcessos;
use App\Backup\Coletor\ColetorDeTarefas;
use App\Backup\Coletor\ColetorDoBackup;
use App\Backup\Coletor\ColetorDoPerfil;
use App\Backup\Coletor\ColetorDoPonto;
use App\Backup\Coletor\ColetorDoServiceDesk;
use App\Backup\Coletor\ColetorDoTrabalhoExterno;
use App\Backup\Service\DadosDaPastaParaBackup;
use App\Backup\Service\ZipDoBackup;
use App\Backup\UseCase\MontarBackupUseCase;
use App\Calculo\Entity\CalculoEmitido;
use App\Entity\Auth\User;
use App\Entity\Tarefa\Tarefa;
use App\Entity\Tenant\Tenant;
use App\Pasta\Service\AcessoAPasta;
use App\Pasta\Service\EscopoDePastas;
use App\Pasta\Service\VisibilidadeDasAbasDaPasta;
use App\Ponto\Entity\RegistroPonto;
use App\Ponto\Repository\RegistroPontoRepository;
use App\Repository\EventoRepository;
use App\Service\PermissionChecker;
use App\ServiceDesk\Entity\ChamadoDeSuporte;
use App\ServiceDesk\Enum\PrioridadeDoChamado;
use App\ServiceDesk\Enum\TipoDeChamado;
use App\Tarefa\Service\AcessoAMeta;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * O MontarBackupUseCase com TODOS os coletores reais, lote 1 em todos e um `clear()` forçado ENTRE eles: nenhum
 * coletor (nem o `finalizar`) recebe User/Tenant destacado. A pessoa tem dados em vários módulos.
 */
#[CoversClass(MontarBackupUseCase::class)]
final class MontarBackupComTodosOsColetoresTest extends KernelTestCase
{
    use MontaBackupDeTesteTrait;

    private Tenant $t1;
    private User $a;

    protected function setUp(): void
    {
        $this->iniciarBackupDeTeste();
        [$this->a, $this->t1] = $this->criarEscritorio();

        $pasta = $this->criarPasta($this->t1, 'PASTA DE TODOS OS COLETORES');
        $pasta->setResponsavel($this->a);
        $meta  = (new Tarefa())->setTitulo('META DE TODOS')->setDescricao('D')->setPasta($pasta)->setTenant($this->t1)->setCriadoPor($this->a);
        $meta->addResponsavel($this->a);
        $this->em()->persist($meta);
        $this->em()->persist(new CalculoEmitido($this->t1, $this->a, 'CALC-TODOS', new \DateTimeImmutable('2026-11-01'), new \DateTimeImmutable('2026-10-31'), '10.00', hash('sha256', 'x')));
        $this->em()->persist((new RegistroPonto())->setUser($this->a)->setTenant($this->t1)->setDataHora(new \DateTime('2026-10-05 09:00'))->setTipo('entrada')->setObservacao('BATIDA-DE-TODOS')->setNsr(1));
        $this->em()->persist(ChamadoDeSuporte::abrir($this->t1, 1, $this->a, TipoDeChamado::Suporte, PrioridadeDoChamado::Media, 'CHAMADO-DE-TODOS', 'D', null, null, null));
        $this->em()->flush();
    }

    /** @return list<ColetorDoBackup> */
    private function coletores(int $lote): array
    {
        $c = static::getContainer();
        $permissao = $c->get(PermissionChecker::class);
        $acesso    = $c->get(AcessoAPasta::class);
        $escopo    = $c->get(EscopoDePastas::class);
        $abas      = $c->get(VisibilidadeDasAbasDaPasta::class);
        $em        = $this->em();
        $reais     = [
            new ColetorDePastas($permissao, $acesso, $escopo, $abas, new DadosDaPastaParaBackup($em), $em, $lote),
            new ColetorDeClientes($permissao, $escopo, $acesso, $abas, $em, $lote),
            new ColetorDeProcessos($permissao, $acesso, $abas, $em, $lote),
            new ColetorDeTarefas($permissao, $acesso, $c->get(AcessoAMeta::class), $em, $lote),
            new ColetorDeKanban($permissao, $em, $lote),
            new ColetorDaAgenda($permissao, new PermissoesDaAgenda($permissao), $acesso, $c->get(EventoRepository::class), $em),
            new ColetorDaCobranca($permissao, $em, $lote),
            new ColetorDaAssinatura($permissao, $em, $lote),
            new ColetorDeCalculos($permissao, $em, $lote),
            new ColetorDoPonto($permissao, $c->get(RegistroPontoRepository::class), $em, $lote),
            new ColetorDoTrabalhoExterno($permissao, $em, $lote),
            new ColetorDoServiceDesk($permissao, $em, $lote),
            new ColetorDoPerfil($em),
        ];

        // Solta o EntityManager antes de cada coletor real: quem receber a entidade antiga quebra.
        $comClear = [];
        foreach ($reais as $coletor) {
            $comClear[] = new class ($em) implements ColetorDoBackup {
                public function __construct(private readonly EntityManagerInterface $em)
                {
                }

                public function coletar(User $usuario, Tenant $tenant, ZipDoBackup $zip): void
                {
                    $this->em->clear();
                }
            };
            $comClear[] = $coletor;
        }

        return $comClear;
    }

    #[TestDox('Todos os coletores, lote 1 e clear entre eles: sem erro e com o conteúdo de cada módulo')]
    public function testTodosOsColetoresComLoteUm(): void
    {
        $zip  = $this->backupDe($this->a, $this->t1, 1);
        $tudo = self::tudo($zip);

        foreach (['PASTA DE TODOS OS COLETORES', 'META DE TODOS', 'CALC-TODOS', 'BATIDA-DE-TODOS', 'CHAMADO-DE-TODOS', 'Paula Primária'] as $esperado) {
            self::assertStringContainsString($esperado, $tudo, $esperado);
        }
        self::assertArrayHasKey('manifesto.json', $zip);
    }
}
