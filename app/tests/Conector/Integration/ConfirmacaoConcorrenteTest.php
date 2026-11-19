<?php

declare(strict_types=1);

namespace App\Tests\Conector\Integration;

use App\Conector\DTO\ChamadaDoConector;
use App\Conector\DTO\ContextoDoConector;
use App\Conector\Repository\PropostaDoConectorRepository;
use App\Conector\Service\GuardaDaAcaoDoConector;
use App\Conector\Service\PropostasDoConector;
use App\Conector\Service\SegredoOpaco;
use App\Conector\UseCase\ConfirmarPropostaDoConectorUseCase;
use App\Entity\Auth\User;
use App\Entity\Auth\UserTenant;
use App\Entity\Tenant\Tenant;
use App\Tests\Conector\Support\ExecutorDePropostaDeTeste;
use App\Tests\Conector\Support\TravaDeLinhaDoConectorTrait;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\Clock;

/**
 * Duas `confirmar_acao` ao mesmo tempo gravam uma vez (spec mcp-remoto-oauth 6.7, item 4, e 12.20): a confirmação lê a
 * proposta com `SELECT ... FOR UPDATE`. Prova com duas conexões de verdade, fora do DAMA: a de fora segura a linha da
 * proposta e a confirmação, na conexão do teste com `lock_timeout` curto, tem de parar esperando por ela antes de
 * chamar o executor.
 */
#[CoversClass(ConfirmarPropostaDoConectorUseCase::class)]
final class ConfirmacaoConcorrenteTest extends KernelTestCase
{
    use TravaDeLinhaDoConectorTrait;

    protected function setUp(): void
    {
        self::bootKernel();
    }

    #[TestDox('12.20: com a proposta travada por outra confirmação, esta espera na leitura travada e não chega ao executor')]
    public function testConfirmacaoEsperaATrava(): void
    {
        $cadeia = $this->cadeiaConfirmadaPorFora();
        $fora = $cadeia['conexao'];
        $id = (new SegredoOpaco())->gerar();
        $resumo = 'Excluir o documento rg.jpg';
        $agora = \DateTimeImmutable::createFromInterface(Clock::get()->now());
        $consentimentoId = (int) $fora->fetchOne('SELECT id FROM conector_ia_consentimento WHERE user_id = ?', [$cadeia['userId']]);
        $fora->insert('conector_ia_proposta', [
            'identificador_hash' => $id['hash'],
            'user_id' => $cadeia['userId'],
            'tenant_id' => $cadeia['tenantId'],
            'consentimento_id' => $consentimentoId,
            'cliente_id' => $cadeia['clienteId'],
            'ferramenta' => 'atualizar_pasta',
            'acao' => 'editar_dados',
            'dados' => '{"documentos":[7]}',
            'dados_hash' => PropostasDoConector::hashDosDados(['documentos' => [7]]),
            'resumo' => $resumo,
            'estado' => 'pendente',
            'criada_em' => $agora->format('Y-m-d H:i:s'),
            'expira_em' => $agora->modify('+10 minutes')->format('Y-m-d H:i:s'),
        ]);

        $em = static::getContainer()->get('doctrine.orm.entity_manager');
        $executor = new ExecutorDePropostaDeTeste($em);
        $pessoa = $em->find(User::class, $cadeia['userId']);
        $escritorio = $em->find(Tenant::class, $cadeia['tenantId']);
        \assert($pessoa instanceof User && $escritorio instanceof Tenant);
        $chamada = new ChamadaDoConector(new ContextoDoConector($pessoa, $escritorio, new UserTenant($pessoa, $escritorio)), $cadeia['concessaoId'], $consentimentoId, 'claude.ai', ['mcp:leitura', 'mcp:escrita'], $agora->modify('+1 day'));
        $useCase = new ConfirmarPropostaDoConectorUseCase(
            static::getContainer()->get(PropostaDoConectorRepository::class),
            static::getContainer()->get(GuardaDaAcaoDoConector::class),
            new SegredoOpaco(),
            $em,
            Clock::get(),
            [$executor],
        );

        $this->assertEsperaATrava($cadeia, 'conector_ia_proposta', $id['hash'], static fn () => $useCase->executar($id['valor'], $resumo, $chamada));
        self::assertSame([], $executor->reconferidas, 'nada foi conferido nem executado antes de a trava sair');
        self::assertSame([], $executor->executadas);
    }
}
