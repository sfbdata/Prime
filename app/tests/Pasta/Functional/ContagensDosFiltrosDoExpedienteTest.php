<?php

declare(strict_types=1);

namespace App\Tests\Pasta\Functional;

use App\Entity\Auth\User;
use App\Entity\Tarefa\Tarefa;
use App\Entity\Tarefa\TarefaMensagem;
use App\Entity\Tenant\Tenant;
use App\Pasta\Entity\NivelDeAcesso;
use App\Pasta\Entity\Pasta;
use App\Pasta\Entity\PastaMensagem;
use App\Pasta\Entity\PastaPrazo;
use App\Pasta\Repository\PastaRepository;
use App\Tests\Functional\JusPrimeWebTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;

/**
 * As 6 contagens da seção EXPEDIENTES do trilho saem numa consulta só e batem, filtro por filtro, com
 * `countByFilters(['expediente' => $filtro])`, o caminho de antes (uma consulta por filtro), para cada perfil:
 * Master, primário, colaborador liberado no cadeado, colaborador fora do cadeado e gente de outro escritório.
 */
#[CoversClass(PastaRepository::class)]
final class ContagensDosFiltrosDoExpedienteTest extends JusPrimeWebTestCase
{
    use CriaCenarioDoCadeadoFuncionalTrait;

    #[TestDox('Uma consulta e o mesmo número de antes em cada filtro, para cada perfil; o cadeado continua valendo')]
    public function testIgualAoDeAntesPorPerfil(): void
    {
        self::bootKernel();
        [$primario, $tenant] = $this->criarEscritorio();
        $dentro = $this->criarColaborador($tenant, 'Dentro do Cadeado');
        $fora = $this->criarColaborador($tenant, 'Fora do Cadeado');
        $master = $this->novoUsuario('Mestre Master');
        $master->setRoles(['ROLE_USER', 'ROLE_SUPER_ADMIN']);
        [$deOutro, $outro] = $this->criarEscritorio();

        $this->povoar($tenant, $primario);
        $trancada = $this->povoar($tenant, $primario);
        $this->trancar($trancada, $primario, [[$dentro, NivelDeAcesso::Visualizar]]);
        $this->povoar($outro, $deOutro);
        $this->em()->flush();

        $repo = static::getContainer()->get(PastaRepository::class);
        $porPerfil = [];
        foreach (['master' => $master, 'primário' => $primario, 'dentro' => $dentro, 'fora' => $fora, 'outro escritório' => $deOutro] as $perfil => $quem) {
            $antes = [];
            foreach (PastaRepository::FILTROS_EXPEDIENTE as $filtro) {
                $antes[$filtro] = $repo->countByFilters(['expediente' => $filtro], $tenant, $quem);
            }

            $coletor = static::getContainer()->get('doctrine.debug_data_holder');
            $coletor->reset();
            $agora = $repo->contagensDosFiltrosDoExpediente($tenant, $quem);
            $sqls = array_column($coletor->getData()['default'] ?? [], 'sql');

            self::assertSame($antes, $agora, $perfil);
            self::assertCount(1, array_filter($sqls, static fn (string $s): bool => str_contains($s, 'FROM pasta p0_')), $perfil . ': ' . implode("\n", $sqls));
            $porPerfil[$perfil] = $agora;
        }

        // O cenário exercita todos os filtros e o cadeado: a pasta trancada conta para quem está dentro, não para quem está fora.
        foreach (PastaRepository::FILTROS_EXPEDIENTE as $filtro) {
            self::assertGreaterThan(0, $porPerfil['dentro'][$filtro], $filtro);
            self::assertSame($porPerfil['dentro'][$filtro] - 1, $porPerfil['fora'][$filtro], $filtro . ': a trancada some para quem está fora');
        }
        // A excluída (que casa com tudo) não conta: nos filtros que só a completa e a excluída cumprem, quem está fora
        // do cadeado vê 1 (a completa dele).
        foreach (['findou', 'aud', 'prazo', 'protocolar'] as $filtro) {
            self::assertSame(1, $porPerfil['fora'][$filtro], $filtro);
        }
    }

    /**
     * Uma pasta que casa com os 6 filtros, mais as que não devem contar: excluída (com tudo), arquivada e
     * administrativa (fora do "A protocolar"), com mensagem antiga, prazo concluído e meta respondida.
     */
    private function povoar(Tenant $tenant, User $por): Pasta
    {
        $tenant = $this->em()->getReference(Tenant::class, $tenant->getId());
        $completa = $this->criarPasta($tenant);
        $this->tudo($completa, $por);

        $excluida = $this->criarPasta($tenant);
        $this->tudo($excluida, $por);
        $excluida->marcarExcluida($por, new \DateTimeImmutable('-1 day'));

        $arquivada = $this->criarPasta($tenant);
        $arquivada->setSituacao(Pasta::SITUACAO_ARQUIVADA);
        $administrativa = $this->criarPasta($tenant);
        $administrativa->setAdministrativa(true);
        $this->mensagem($arquivada, $por, '-30 days');
        $respondida = $this->meta($administrativa, $por, new \DateTimeImmutable('-3 days'));
        $this->responder($respondida, $por, '-1 day');
        $this->meta($administrativa, $por, new \DateTimeImmutable('+5 days'), Tarefa::STATUS_CONCLUIDA);
        $this->em()->flush();

        return $completa;
    }

    /**
     * resp10 (mensagem de 5 dias), findou (meta vencida há 2 dias, sem atividade depois), prazo e metas (meta
     * futura), aud (audiência daqui a 7 dias). Metas e audiência nascem há 20 dias: criar conta como atividade.
     */
    private function tudo(Pasta $pasta, User $por): void
    {
        $this->mensagem($pasta, $por, '-5 days');
        $this->meta($pasta, $por, new \DateTimeImmutable('-2 days'));
        $this->meta($pasta, $por, new \DateTimeImmutable('+5 days'));
        $audiencia = new PastaPrazo($pasta->getTenant(), $pasta, $por);
        $audiencia->definir(PastaPrazo::TIPO_AUDIENCIA, 'Audiência', new \DateTimeImmutable('+7 days'), null, null);
        $this->em()->persist($audiencia);
        $this->em()->flush();
        $antigo = (new \DateTimeImmutable('-20 days'))->format('Y-m-d H:i:s');
        $conexao = $this->em()->getConnection();
        $conexao->executeStatement('UPDATE tarefa SET data_criacao = ? WHERE pasta_id = ?', [$antigo, $pasta->getId()]);
        $conexao->executeStatement('UPDATE pasta_prazo SET criado_em = ? WHERE pasta_id = ?', [$antigo, $pasta->getId()]);
    }

    private function meta(Pasta $pasta, User $por, \DateTimeImmutable $prazo, string $status = Tarefa::STATUS_PENDENTE): Tarefa
    {
        $meta = new Tarefa();
        $meta->setTitulo('Meta das contagens');
        $meta->setDescricao('');
        $meta->setPrazo($prazo);
        $meta->setStatus($status);
        $meta->setPasta($pasta);
        $meta->setTenant($pasta->getTenant());
        $meta->setCriadoPor($por);
        $this->em()->persist($meta);
        $this->em()->flush();

        return $meta;
    }

    private function responder(Tarefa $meta, User $por, string $quando): void
    {
        $resposta = new TarefaMensagem();
        $resposta->setTarefa($meta);
        $resposta->setTenant($meta->getTenant());
        $resposta->setUsuario($por);
        $resposta->setMensagem('Resposta');
        $resposta->setCriadoEm(new \DateTimeImmutable($quando));
        $this->em()->persist($resposta);
        $this->em()->flush();
    }

    private function mensagem(Pasta $pasta, User $por, string $quando): void
    {
        $mensagem = new PastaMensagem();
        $mensagem->setPasta($pasta);
        $mensagem->setAutor($por);
        $mensagem->setTenant($pasta->getTenant());
        $mensagem->setConteudo('Comentário');
        $this->em()->persist($mensagem);
        $this->em()->flush();
        $this->em()->getConnection()->executeStatement('UPDATE pasta_mensagem SET criada_em = ? WHERE id = ?', [(new \DateTimeImmutable($quando))->format('Y-m-d H:i:s'), $mensagem->getId()]);
    }
}
