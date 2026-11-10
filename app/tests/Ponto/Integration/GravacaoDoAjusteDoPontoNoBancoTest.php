<?php

declare(strict_types=1);

namespace App\Tests\Ponto\Integration;

use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Ponto\Entity\JustificativaPonto;
use App\Ponto\Entity\PontoAjuste;
use App\Ponto\Entity\RegistroPonto;
use App\Ponto\Enum\TipoDoAjuste;
use App\Ponto\Exception\AjusteDeBatidaRecusadoException;
use App\Ponto\Model\AlvoDoAjuste;
use App\Ponto\Repository\RegistroPontoRepository;
use App\Ponto\Service\DetalheDaTrilhaDoPonto;
use App\Ponto\Service\GravacaoDoAjusteDoPonto;
use App\Ponto\Service\HorarioFuturoDaBatida;
use App\Ponto\Service\TrilhaDoPonto;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use Psr\Clock\ClockInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\Test\ClockSensitiveTrait;

/**
 * A gravação dos ajustes de batida no banco (E10, plano-e10-ponto §6): a linhagem presa à cabeça, a cabeça que o
 * repositório devolve depois de cada passo, o evento da trilha no mesmo COMMIT, a trava 4210 por pessoa e as recusas
 * que não gravam nada (nem na trilha).
 *
 * O relógio fica parado em 22/10/2026 12:00 (horário futuro previsível). Cada operação roda dentro de
 * `TrilhaDoPonto::emTransacao`, como nos UseCases.
 */
#[CoversClass(GravacaoDoAjusteDoPonto::class)]
#[CoversClass(PontoAjuste::class)]
#[CoversClass(DetalheDaTrilhaDoPonto::class)]
final class GravacaoDoAjusteDoPontoNoBancoTest extends KernelTestCase
{
    use ClockSensitiveTrait;

    private EntityManagerInterface $em;
    private RegistroPontoRepository $registros;
    private TrilhaDoPonto $trilha;
    private GravacaoDoAjusteDoPonto $gravacao;
    private Tenant $escritorio;
    private User $pessoa;
    private User $gestora;

    protected function setUp(): void
    {
        self::bootKernel();
        self::mockTime(new \DateTimeImmutable('2026-10-22 12:00:00', new \DateTimeZone(date_default_timezone_get())));
        $c = static::getContainer();
        $this->em = $c->get(EntityManagerInterface::class);
        $this->registros = $c->get(RegistroPontoRepository::class);
        $this->trilha = $c->get(TrilhaDoPonto::class);
        $this->gravacao = new GravacaoDoAjusteDoPonto($this->em, $this->trilha, $c->get(HorarioFuturoDaBatida::class), $c->get(ClockInterface::class));

        $this->escritorio = (new Tenant())->setName('Escritório do ajuste ' . uniqid());
        $this->em->persist($this->escritorio);
        $this->pessoa = $this->usuario('Ana Colaboradora');
        $this->gestora = $this->usuario('Gestora do Ajuste');
        $this->em->flush();
    }

    #[TestDox('Incluir: a inclusão é a raiz e a cabeça do alvo a{id}; evento batida_incluida com o detalhe do ajuste; autor e nome retratados')]
    public function testIncluir(): void
    {
        $inclusao = $this->emTransacao(fn (): PontoAjuste => $this->gravacao->incluir(
            $this->escritorio,
            $this->pessoa,
            'saida',
            new \DateTimeImmutable('2026-10-21 18:00:00'),
            '  Esqueceu de bater a saída.  ',
            $this->gestora,
            null,
        ));

        self::assertSame(TipoDoAjuste::Inclusao, $inclusao->getTipo());
        self::assertSame('Esqueceu de bater a saída.', $inclusao->getMotivo(), 'o motivo sem os espaços das pontas');
        self::assertSame('Gestora do Ajuste', $inclusao->getAutorNome());
        self::assertSame('2026-10-22 12:00:00', $inclusao->getCriadoEm()->format('Y-m-d H:i:s'), 'o momento do relógio');
        $alvo = $this->alvo('a' . $inclusao->getId());
        self::assertSame($inclusao->getId(), $alvo->cabecaId());
        self::assertSame(['saida', '2026-10-21 18:00:00'], [$alvo->tipoAtual, $alvo->dataHoraAtual?->format('Y-m-d H:i:s')]);

        $evento = $this->ultimoEvento();
        self::assertSame('batida_incluida', $evento['acao']);
        self::assertSame('Gestora do Ajuste', $evento['autor_nome']);
        self::assertSame($inclusao->getId(), $evento['detalhe']['ajuste_id']);
        self::assertSame('a' . $inclusao->getId(), $evento['detalhe']['alvo']);
        self::assertArrayNotHasKey('motivo', $evento['detalhe'], 'o motivo é texto livre: fica fora da trilha');
        self::assertSame('Ana Colaboradora: Saída às 18:00:00 de 21/10/2026 incluída', DetalheDaTrilhaDoPonto::texto($evento['acao'], $evento['detalhe']));
    }

    #[TestDox('Incluir com a justificativa: evento batida_do_esquecimento, com o ajuste e o mesmo texto do esquecimento; a inclusão fica ligada à justificativa')]
    public function testIncluirDoEsquecimento(): void
    {
        $justificativa = $this->justificativa();

        $inclusao = $this->emTransacao(fn (): PontoAjuste => $this->gravacao->incluir(
            $this->escritorio,
            $this->pessoa,
            'saida',
            new \DateTimeImmutable('2026-10-21 18:00:00'),
            'Esquecimento abonado.',
            $this->gestora,
            $justificativa,
        ));

        self::assertSame($justificativa, $inclusao->getJustificativa());
        $evento = $this->ultimoEvento();
        self::assertSame('batida_do_esquecimento', $evento['acao']);
        self::assertSame($justificativa->getId(), $evento['detalhe']['justificativa_id']);
        self::assertSame($inclusao->getId(), $evento['detalhe']['ajuste_id']);
        self::assertSame('Ana Colaboradora: Saída às 18:00:00 de 21/10/2026 · pedida no esquecimento', DetalheDaTrilhaDoPonto::texto($evento['acao'], $evento['detalhe']));
    }

    #[TestDox('Alterar em cadeia: a primeira linha prende ao registro (anterior nulo), a segunda à primeira; a cabeça segue a última; evento batida_ajustada')]
    public function testAlterarEmCadeia(): void
    {
        $batida = $this->batida('saida', '2026-10-21 18:00:00');

        $primeira = $this->alterar($batida, 'saida', '2026-10-21 18:30:00');
        $segunda = $this->alterar($batida, 'retorno', '2026-10-21 13:00:00');

        self::assertSame([$batida, null], [$primeira->getRegistroPonto(), $primeira->getAnterior()]);
        self::assertSame(['saida', '2026-10-21 18:00:00'], [$primeira->getTipoMarcacaoAnterior(), $primeira->getDataHoraAnterior()?->format('Y-m-d H:i:s')]);
        self::assertSame([$batida, $primeira], [$segunda->getRegistroPonto(), $segunda->getAnterior()]);
        self::assertSame(['saida', '2026-10-21 18:30:00'], [$segunda->getTipoMarcacaoAnterior(), $segunda->getDataHoraAnterior()?->format('Y-m-d H:i:s')]);
        $alvo = $this->alvo('b' . $batida->getId());
        self::assertSame($segunda->getId(), $alvo->cabecaId());
        self::assertSame(['retorno', '2026-10-21 13:00:00'], [$alvo->tipoAtual, $alvo->dataHoraAtual?->format('Y-m-d H:i:s')]);
        self::assertSame('2026-10-21 18:00:00', $this->em->getConnection()->fetchOne('SELECT data_hora FROM registro_ponto WHERE id = ?', [$batida->getId()]), 'o registro original não muda');

        $evento = $this->ultimoEvento();
        self::assertSame('batida_ajustada', $evento['acao']);
        self::assertSame('Ana Colaboradora: Saída às 18:30:00 de 21/10/2026 passou para Retorno às 13:00:00 de 21/10/2026', DetalheDaTrilhaDoPonto::texto($evento['acao'], $evento['detalhe']));
    }

    #[TestDox('Desconsiderar e restaurar: a desconsideração vira a cabeça (fora da conta); a restauração desfaz e traz de volta a forma de antes; eventos batida_desconsiderada e ajuste_desfeito')]
    public function testDesconsiderarERestaurar(): void
    {
        $batida = $this->batida('entrada', '2026-10-21 08:00:00');
        $alteracao = $this->alterar($batida, 'entrada', '2026-10-21 08:05:00');

        $desconsideracao = $this->emTransacao(fn (): PontoAjuste => $this->gravacao->desconsiderar($this->alvo('b' . $batida->getId()), 'Batida em duplicidade.', $this->gestora, null, null));

        self::assertSame($alteracao, $desconsideracao->getAnterior());
        self::assertSame(['entrada', '2026-10-21 08:05:00', null], [$desconsideracao->getTipoMarcacaoAnterior(), $desconsideracao->getDataHoraAnterior()?->format('Y-m-d H:i:s'), $desconsideracao->getDataHoraNova()]);
        $fora = $this->alvo('b' . $batida->getId());
        self::assertTrue($fora->desconsiderada());
        self::assertSame($desconsideracao->getId(), $fora->cabecaId());
        self::assertSame('batida_desconsiderada', $this->ultimoEvento()['acao']);
        self::assertSame('Ana Colaboradora: Entrada às 08:05:00 de 21/10/2026 desconsiderada', $this->textoDoUltimoEvento());

        $restauracao = $this->emTransacao(fn (): PontoAjuste => $this->gravacao->restaurar($desconsideracao, 'Não era duplicada.', $this->gestora));

        self::assertSame([$desconsideracao, $desconsideracao, $batida], [$restauracao->getAnterior(), $restauracao->getDesfaz(), $restauracao->getRegistroPonto()]);
        $volta = $this->alvo('b' . $batida->getId());
        self::assertSame($restauracao->getId(), $volta->cabecaId());
        self::assertSame(['entrada', '2026-10-21 08:05:00'], [$volta->tipoAtual, $volta->dataHoraAtual?->format('Y-m-d H:i:s')]);
        self::assertSame('ajuste_desfeito', $this->ultimoEvento()['acao']);
        self::assertSame('Ana Colaboradora: Entrada às 08:05:00 de 21/10/2026 restaurada', $this->textoDoUltimoEvento());
    }

    #[TestDox('Desconsiderar com o que desfaz (a inclusão do esquecimento ao voltar a justificativa): evento ajuste_desfeito com o texto do esquecimento')]
    public function testDesconsiderarDesfazendoAInclusao(): void
    {
        $justificativa = $this->justificativa();
        $inclusao = $this->emTransacao(fn (): PontoAjuste => $this->gravacao->incluir($this->escritorio, $this->pessoa, 'saida', new \DateTimeImmutable('2026-10-21 18:00:00'), 'Abono.', $this->gestora, $justificativa));

        $desconsideracao = $this->emTransacao(fn (): PontoAjuste => $this->gravacao->desconsiderar(
            $this->alvo('a' . $inclusao->getId()),
            'Justificativa voltou para análise.',
            $this->gestora,
            $justificativa,
            $inclusao,
        ));

        self::assertSame([$inclusao, $inclusao, $inclusao, $justificativa], [$desconsideracao->getInclusao(), $desconsideracao->getAnterior(), $desconsideracao->getDesfaz(), $desconsideracao->getJustificativa()]);
        $evento = $this->ultimoEvento();
        self::assertSame('ajuste_desfeito', $evento['acao']);
        self::assertSame($inclusao->getId(), $evento['detalhe']['desfaz_id']);
        self::assertSame('Ana Colaboradora: Saída às 18:00:00 de 21/10/2026, do esquecimento abonado, desconsiderada ao voltar a justificativa para análise', $this->textoDoUltimoEvento());
    }

    #[TestDox('Desconsiderar com um desfaz que não é a inclusão da raiz do alvo (outra inclusão, ou sem a justificativa dela) lança LogicException e não grava')]
    public function testDesfazQueNaoEARaizRecusa(): void
    {
        $justificativa = $this->justificativa();
        $inclusao = $this->emTransacao(fn (): PontoAjuste => $this->gravacao->incluir($this->escritorio, $this->pessoa, 'saida', new \DateTimeImmutable('2026-10-21 18:00:00'), 'Abono.', $this->gestora, $justificativa));
        $outra = $this->emTransacao(fn (): PontoAjuste => $this->gravacao->incluir($this->escritorio, $this->pessoa, 'entrada', new \DateTimeImmutable('2026-10-20 08:00:00'), 'Outra.', $this->gestora, null));
        $batida = $this->batida('saida', '2026-10-19 18:00:00');
        $antes = [$this->quantosAjustes(), $this->quantosEventos()];

        $casos = [
            'outra inclusão'        => fn () => $this->gravacao->desconsiderar($this->alvo('a' . $inclusao->getId()), 'Voltou.', $this->gestora, $justificativa, $outra),
            'sem a justificativa'   => fn () => $this->gravacao->desconsiderar($this->alvo('a' . $inclusao->getId()), 'Voltou.', $this->gestora, null, $inclusao),
            'alvo é batida gravada' => fn () => $this->gravacao->desconsiderar($this->alvo('b' . $batida->getId()), 'Voltou.', $this->gestora, $justificativa, $inclusao),
        ];
        foreach ($casos as $caso => $operacao) {
            try {
                $this->emTransacao($operacao);
                self::fail('Aceitou: ' . $caso);
            } catch (\LogicException $erro) {
                self::assertStringContainsString('só desfaz a inclusão do esquecimento', $erro->getMessage(), $caso);
            }
        }
        self::assertSame($antes, [$this->quantosAjustes(), $this->quantosEventos()]);
    }

    #[TestDox('Recusas não gravam nada (nem na trilha): motivo vazio, motivo acima de 2000, horário futuro, alteração igual, ajuste em batida fora da conta')]
    public function testRecusasNaoGravam(): void
    {
        $batida = $this->batida('saida', '2026-10-21 18:00:00');
        $alvo = $this->alvo('b' . $batida->getId());
        $antes = [$this->quantosAjustes(), $this->quantosEventos()];

        $casos = [
            AjusteDeBatidaRecusadoException::SEM_MOTIVO => fn () => $this->gravacao->alterar($alvo, 'saida', new \DateTimeImmutable('2026-10-21 18:30:00'), "  \n ", $this->gestora),
            'motivo longo'                              => fn () => $this->gravacao->incluir($this->escritorio, $this->pessoa, 'saida', new \DateTimeImmutable('2026-10-21 18:30:00'), str_repeat('a', 2001), $this->gestora, null),
            AjusteDeBatidaRecusadoException::FUTURO     => fn () => $this->gravacao->incluir($this->escritorio, $this->pessoa, 'saida', new \DateTimeImmutable('2026-10-22 12:00:01'), 'Futuro.', $this->gestora, null),
            'futuro na alteração'                       => fn () => $this->gravacao->alterar($alvo, 'saida', new \DateTimeImmutable('2026-10-23 08:00:00'), 'Futuro.', $this->gestora),
            AjusteDeBatidaRecusadoException::NADA_MUDOU => fn () => $this->gravacao->alterar($alvo, 'saida', new \DateTimeImmutable('2026-10-21 18:00:00.400000'), 'Igual.', $this->gestora),
        ];
        $motivos = [];
        foreach ($casos as $caso => $operacao) {
            $motivos[$caso] = $this->motivoDaRecusa($operacao);
        }

        self::assertSame([
            AjusteDeBatidaRecusadoException::SEM_MOTIVO => AjusteDeBatidaRecusadoException::SEM_MOTIVO,
            'motivo longo'                              => AjusteDeBatidaRecusadoException::MOTIVO_LONGO,
            AjusteDeBatidaRecusadoException::FUTURO     => AjusteDeBatidaRecusadoException::FUTURO,
            'futuro na alteração'                       => AjusteDeBatidaRecusadoException::FUTURO,
            AjusteDeBatidaRecusadoException::NADA_MUDOU => AjusteDeBatidaRecusadoException::NADA_MUDOU,
        ], $motivos);
        self::assertSame($antes, [$this->quantosAjustes(), $this->quantosEventos()]);
    }

    #[TestDox('Ajustar ou desconsiderar a batida que já está fora da conta é recusado como "mudou" e não grava')]
    public function testBatidaForaDaContaRecusa(): void
    {
        $batida = $this->batida('saida', '2026-10-21 18:00:00');
        $this->emTransacao(fn (): PontoAjuste => $this->gravacao->desconsiderar($this->alvo('b' . $batida->getId()), 'Fora.', $this->gestora, null, null));
        $fora = $this->alvo('b' . $batida->getId());
        $antes = [$this->quantosAjustes(), $this->quantosEventos()];

        self::assertSame(AjusteDeBatidaRecusadoException::MUDOU, $this->motivoDaRecusa(fn () => $this->gravacao->desconsiderar($fora, 'De novo.', $this->gestora, null, null)));
        self::assertSame(AjusteDeBatidaRecusadoException::MUDOU, $this->motivoDaRecusa(fn () => $this->gravacao->alterar($fora, 'saida', new \DateTimeImmutable('2026-10-21 18:30:00'), 'Mudar.', $this->gestora)));
        self::assertSame($antes, [$this->quantosAjustes(), $this->quantosEventos()]);
    }

    #[TestDox('travar sem transação lança LogicException; dentro dela é reentrante e segura a pessoa contra outra conexão (outra pessoa fica livre)')]
    public function testTravar(): void
    {
        try {
            $this->gravacao->travar($this->pessoa);
            self::fail('Travou sem transação.');
        } catch (\LogicException $erro) {
            self::assertStringContainsString('transação', $erro->getMessage());
        }

        $outra = DriverManager::getConnection($this->em->getConnection()->getParams());
        try {
            $resultado = $this->emTransacao(function () use ($outra): array {
                $this->gravacao->travar($this->pessoa);
                $this->gravacao->travar($this->pessoa);   // reentrante: não trava a si mesma

                return [$this->conseguiuATrava($outra, $this->pessoa), $this->conseguiuATrava($outra, $this->gestora)];
            });
        } finally {
            $outra->close();
        }

        self::assertSame([false, true], $resultado, 'a mesma pessoa espera o COMMIT; outra pessoa não');
    }

    #[TestDox('Gravar fora de uma transação é recusado antes de escrever (a trava exige a transação)')]
    public function testGravarSemTransacaoNaoEscreve(): void
    {
        $antes = $this->quantosAjustes();

        $this->expectException(\LogicException::class);
        try {
            $this->gravacao->incluir($this->escritorio, $this->pessoa, 'saida', new \DateTimeImmutable('2026-10-21 18:00:00'), 'Sem transação.', $this->gestora, null);
        } finally {
            self::assertSame($antes, $this->quantosAjustes());
        }
    }

    /**
     * @template T
     *
     * @param \Closure(): T $trabalho
     *
     * @return T
     */
    private function emTransacao(\Closure $trabalho): mixed
    {
        return $this->trilha->emTransacao($trabalho);
    }

    private function alterar(RegistroPonto $batida, string $tipo, string $quando): PontoAjuste
    {
        return $this->emTransacao(fn (): PontoAjuste => $this->gravacao->alterar(
            $this->alvo('b' . $batida->getId()),
            $tipo,
            new \DateTimeImmutable($quando),
            'Correção.',
            $this->gestora,
        ));
    }

    private function alvo(string $alvo): AlvoDoAjuste
    {
        return $this->registros->alvoDoAjuste($this->pessoa, $this->escritorio, $alvo) ?? throw new \LogicException('Alvo não achado: ' . $alvo);
    }

    private function motivoDaRecusa(\Closure $operacao): string
    {
        try {
            $this->emTransacao($operacao);
        } catch (AjusteDeBatidaRecusadoException $recusa) {
            return $recusa->motivo;
        }
        self::fail('A operação não foi recusada.');
    }

    private function conseguiuATrava(Connection $outra, User $pessoa): bool
    {
        $outra->beginTransaction();
        try {
            return (bool) $outra->fetchOne('SELECT pg_try_advisory_xact_lock(?, ?)', [GravacaoDoAjusteDoPonto::CLASSE_TRAVA, $pessoa->getId()]);
        } finally {
            $outra->rollBack();
        }
    }

    /** @return array{acao: string, autor_nome: ?string, detalhe: array<string, mixed>} */
    private function ultimoEvento(): array
    {
        $linha = $this->em->getConnection()->fetchAssociative(
            'SELECT acao, autor_nome, detalhe FROM ponto_auditoria WHERE tenant_id = ? ORDER BY sequencia DESC LIMIT 1',
            [$this->escritorio->getId()],
        ) ?: throw new \LogicException('Trilha vazia.');

        return ['acao' => (string) $linha['acao'], 'autor_nome' => $linha['autor_nome'], 'detalhe' => json_decode((string) $linha['detalhe'], true, flags: \JSON_THROW_ON_ERROR)];
    }

    private function textoDoUltimoEvento(): string
    {
        $evento = $this->ultimoEvento();

        return DetalheDaTrilhaDoPonto::texto($evento['acao'], $evento['detalhe']);
    }

    private function quantosAjustes(): int
    {
        return (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM ponto_ajuste WHERE tenant_id = ?', [$this->escritorio->getId()]);
    }

    private function quantosEventos(): int
    {
        return (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM ponto_auditoria WHERE tenant_id = ?', [$this->escritorio->getId()]);
    }

    private function batida(string $tipo, string $quando): RegistroPonto
    {
        $batida = (new RegistroPonto())->setTenant($this->escritorio)->setUser($this->pessoa)->setTipo($tipo)->setDataHora(new \DateTime($quando));
        $this->em->persist($batida);
        $this->em->flush();

        return $batida;
    }

    private function justificativa(): JustificativaPonto
    {
        $justificativa = (new JustificativaPonto())
            ->setTenant($this->escritorio)
            ->setUser($this->pessoa)
            ->setData(new \DateTime('2026-10-21'))
            ->setStatus('abonado')
            ->setTipo('esquecimento_registro');
        $this->em->persist($justificativa);
        $this->em->flush();

        return $justificativa;
    }

    private function usuario(string $nome): User
    {
        $usuario = (new User())->setEmail('ajuste_' . uniqid() . '@test.com')->setFullName($nome);
        $usuario->setRoles(['ROLE_USER']);
        $usuario->setPassword('x');
        $this->em->persist($usuario);

        return $usuario;
    }
}
