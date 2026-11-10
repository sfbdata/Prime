<?php

declare(strict_types=1);

namespace App\Tests\Ponto\Support;

use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Master\Entity\RecursoEstado;
use App\Ponto\Entity\ConfiguracaoDoPonto;
use App\Ponto\Repository\AceiteDaFolhaDoPontoRepository;
use App\Ponto\Repository\FeriadoRepository;
use App\Ponto\Repository\FolhaEmitidaDoPontoRepository;
use App\Ponto\Repository\JornadaColaboradorRepository;
use App\Ponto\Repository\JustificativaPontoRepository;
use App\Ponto\Repository\RegistroPontoRepository;
use App\Ponto\Repository\TrilhaDoPontoRepository;
use App\Ponto\Service\ChavesDoHmacDoPonto;
use App\Ponto\Service\CodigoDaFolha;
use App\Ponto\Service\ConteudoDaFolhaDoMes;
use App\Ponto\Service\DadosDaFolhaExportada;
use App\Ponto\Service\EmissaoDaFolha;
use App\Ponto\Service\EstadoDoAceiteDaFolha;
use App\Ponto\Service\FolhaPontoBuilder;
use App\Ponto\Service\FuncaoDoAceite;
use App\Ponto\Service\FuncoesDoPonto;
use App\Ponto\Service\InicioContagemResolver;
use App\Ponto\Service\LinhasDaFolhaImpressa;
use App\Ponto\Service\RelogioDaFolha;
use App\Ponto\Service\SeloDaFolha;
use App\Ponto\Service\SeloDaFolhaHmac;
use App\Ponto\Service\TrilhaDoPonto;
use App\Ponto\UseCase\AssinarFolhaDoPontoUseCase;
use App\Ponto\UseCase\EmitirFolhaDoPontoUseCase;
use App\Repository\UserTenantRepository;
use Doctrine\Persistence\ManagerRegistry;
use Psr\Clock\ClockInterface;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorage;

/**
 * Cenário da folha do mês da E16 (emissão com código BJF e aceite mensal): a pessoa com jornada e batidas de outubro
 * de 2026 num escritório, o segredo do HMAC ligado ou não, as camadas da função `ponto-aceite`, a trilha que falha e
 * a leitura do que ficou gravado (folhas, aceites e eventos da trilha) direto no banco.
 *
 * Quem usa liga o relógio (`ClockSensitiveTrait::mockTime`) e o segredo antes de pedir os serviços. Os serviços da E16
 * são montados aqui à mão: até o bloco D ligá-los a um controller, o container compilado os remove por falta de uso.
 */
trait CenarioDaFolhaDoMesTrait
{
    use CenarioDeJornadaPorEscritorioTrait;

    private function emitirFolha(?TrilhaDoPonto $trilha = null): EmitirFolhaDoPontoUseCase
    {
        return new EmitirFolhaDoPontoUseCase(
            $this->conteudoDaFolhaDoMes(),
            $this->estadoDoAceite(),
            $this->emissaoDaFolha(),
            $this->seloDaFolha(),
            $trilha ?? static::getContainer()->get(TrilhaDoPonto::class),
            $this->em(),
            $this->relogioDaFolha(),
        );
    }

    private function assinarFolha(?TrilhaDoPonto $trilha = null, ?AceiteDaFolhaDoPontoRepository $aceites = null): AssinarFolhaDoPontoUseCase
    {
        return new AssinarFolhaDoPontoUseCase(
            $this->conteudoDaFolhaDoMes(),
            $this->estadoDoAceite($aceites),
            $this->emissaoDaFolha(),
            $aceites ?? static::getContainer()->get(AceiteDaFolhaDoPontoRepository::class),
            $this->seloDaFolha(),
            $trilha ?? static::getContainer()->get(TrilhaDoPonto::class),
            $this->em(),
            $this->relogioDaFolha(),
        );
    }

    private function estadoDoAceite(?AceiteDaFolhaDoPontoRepository $aceites = null): EstadoDoAceiteDaFolha
    {
        $container = static::getContainer();

        return new EstadoDoAceiteDaFolha(
            $aceites ?? $container->get(AceiteDaFolhaDoPontoRepository::class),
            $this->seloDaFolha(),
            new FuncaoDoAceite($container->get(FuncoesDoPonto::class), $this->seloDaFolha()),
            $container->get(InicioContagemResolver::class),
            $this->conteudoDaFolhaDoMes(),
            $this->relogioDaFolha(),
        );
    }

    private function conteudoDaFolhaDoMes(): ConteudoDaFolhaDoMes
    {
        $container = static::getContainer();

        return new ConteudoDaFolhaDoMes(
            $container->get(RegistroPontoRepository::class),
            $container->get(FeriadoRepository::class),
            $container->get(JustificativaPontoRepository::class),
            $container->get(JornadaColaboradorRepository::class),
            $container->get(UserTenantRepository::class),
            $container->get(InicioContagemResolver::class),
            $container->get(FolhaPontoBuilder::class),
            $container->get(DadosDaFolhaExportada::class),
            new LinhasDaFolhaImpressa(),
        );
    }

    private function emissaoDaFolha(): EmissaoDaFolha
    {
        $folhas = static::getContainer()->get(FolhaEmitidaDoPontoRepository::class);

        return new EmissaoDaFolha($this->seloDaFolha(), new CodigoDaFolha($folhas), $folhas);
    }

    /** O selo com o segredo que o teste ligou ({@see segredo()}). */
    private function seloDaFolha(): SeloDaFolha
    {
        return new SeloDaFolhaHmac(static::getContainer()->get(ChavesDoHmacDoPonto::class));
    }

    private function relogioDaFolha(): RelogioDaFolha
    {
        return new RelogioDaFolha(static::getContainer()->get(ClockInterface::class));
    }

    /** Segunda-feira, 5, e terça, 6, de outubro de 2026, com as quatro batidas da jornada das 8h às 17h. */
    private function pessoaComOutubro(Tenant $escritorio, string $nome = 'Maria da Silva'): User
    {
        $pessoa = $this->pessoa($nome);
        $this->vincular($pessoa, $escritorio);
        $this->jornadaPessoal($pessoa, $escritorio, [[[1, 2, 3, 4, 5], '08:00', '12:00', '13:00', '17:00']]);
        foreach (['2026-10-05', '2026-10-06'] as $dia) {
            $this->diaCompleto($pessoa, $escritorio, $dia);
        }

        return $pessoa;
    }

    private function diaCompleto(User $pessoa, Tenant $escritorio, string $dia): void
    {
        foreach (['entrada' => '08:00', 'repouso' => '12:00', 'retorno' => '13:00', 'saida' => '17:00'] as $tipo => $hora) {
            $this->registrarBatida($pessoa, $escritorio, $tipo, new \DateTimeImmutable($dia . ' ' . $hora . ':00'));
        }
    }

    /** O segredo do HMAC do Ponto: com ele, a folha leva código BJF e o aceite fica disponível (decisão 16). */
    private function segredo(?string $segredo): void
    {
        static::getContainer()->set(ChavesDoHmacDoPonto::class, new ChavesDoHmacDoPonto($segredo));
    }

    private function desligarNoGlobal(): void
    {
        $estado = new RecursoEstado(FuncoesDoPonto::ACEITE);
        $estado->desativar(null, null, null, new \DateTimeImmutable('2026-11-01'));
        $this->em()->persist($estado);
        $this->em()->flush();
    }

    private function desligarNoEscritorio(Tenant $escritorio, User $gestor): void
    {
        $configuracao = new ConfiguracaoDoPonto($escritorio);
        $configuracao->definir(FuncoesDoPonto::ACEITE, false, $gestor, new \DateTimeImmutable('2026-11-01'));
        $this->em()->persist($configuracao);
        $this->em()->flush();
    }

    /** Uma trilha que falha ao gravar o evento, como a de `AnalisarJustificativaUseCaseTest`. */
    private function trilhaQueFalha(): TrilhaDoPonto
    {
        $falha = new class (static::getContainer()->get(ManagerRegistry::class)) extends TrilhaDoPontoRepository {
            public function inserir(array $linha): void
            {
                throw new \RuntimeException('Falha simulada da trilha.');
            }
        };

        return new TrilhaDoPonto($falha, new ChavesDoHmacDoPonto(null), static::getContainer()->get(ClockInterface::class), new TokenStorage());
    }

    /** @return list<array<string, mixed>> as folhas emitidas da pessoa no escritório, da mais antiga à mais nova */
    private function folhasNoBanco(Tenant $escritorio, User $pessoa): array
    {
        return $this->em()->getConnection()->fetchAllAssociative(
            'SELECT id, codigo, emitida_por_id, emitida_em, impressao_registros, dados_assinados FROM ponto_folha_emitida WHERE tenant_id = ? AND user_id = ? ORDER BY id',
            [$escritorio->getId(), $pessoa->getId()],
        );
    }

    /** @return list<array<string, mixed>> os aceites da pessoa no escritório, pelo número */
    private function aceitesNoBanco(Tenant $escritorio, User $pessoa): array
    {
        return $this->em()->getConnection()->fetchAllAssociative(
            'SELECT id, numero, situacao, ressalva, aceito_em, ip, navegador, folha_emitida_id, dados_assinados FROM ponto_folha_aceite WHERE tenant_id = ? AND user_id = ? ORDER BY numero',
            [$escritorio->getId(), $pessoa->getId()],
        );
    }

    /** @return list<array{acao: string, autor_id: ?int, detalhe: array<string, mixed>}> os eventos da trilha do escritório, em ordem */
    private function trilhaNoBanco(Tenant $escritorio): array
    {
        $linhas = $this->em()->getConnection()->fetchAllAssociative(
            'SELECT acao, autor_id, detalhe FROM ponto_auditoria WHERE tenant_id = ? ORDER BY sequencia',
            [$escritorio->getId()],
        );

        return array_map(static fn (array $linha): array => [
            'acao'     => (string) $linha['acao'],
            'autor_id' => $linha['autor_id'] === null ? null : (int) $linha['autor_id'],
            'detalhe'  => json_decode((string) $linha['detalhe'], true, 512, \JSON_THROW_ON_ERROR),
        ], $linhas);
    }

    /** @return list<string> */
    private function acoesNaTrilha(Tenant $escritorio): array
    {
        return array_column($this->trilhaNoBanco($escritorio), 'acao');
    }
}
