<?php

declare(strict_types=1);

namespace App\Master\UseCase;

use App\Master\DTO\DesligarConvenioInput;
use App\Master\Entity\Convenio;
use App\Master\Exception\AvisoDeConvenioNaoEnfileirado;
use App\Master\Exception\ConvenioRecusado;
use App\Master\Repository\ConvenioRepository;
use App\Master\Service\FimDoConvenio;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;

/**
 * Liga ou desliga um convênio (spec docs/specs/convenios.md, §6.1 "por convênio", §6.2 e §7.4), ou todos os de uma
 * instituição numa transação só (§7.5).
 *
 * Quem dispara: o Master, na lista de convênios do painel (ROLE_SUPER_ADMIN no controller).
 * Desligar: marca o fim na data pedida e avisa por e-mail os primários dos escritórios vinculados ({@see FimDoConvenio}:
 * nunca antes de hoje; 30 dias com benefício real; gratuidade pode ser hoje, e então desliga na hora). No dia, a tarefa
 * diária desliga e fecha os vínculos com `convenio_desligado`.
 * Ligar: com fim marcado, cancela o fim e avisa "o convênio continua"; desligado, liga de novo sem e-mail.
 * Tudo numa transação, com trava por convênio: falha ao enfileirar os avisos desfaz a mudança (500). Recusa: 422.
 */
final class DesligarConvenioUseCase
{
    public const DESLIGOU_AGORA = 'desligado';
    public const FIM_MARCADO = 'fim_marcado';
    public const FIM_CANCELADO = 'fim_cancelado';
    public const LIGADO = 'ligado';

    public function __construct(
        private readonly ConvenioRepository $convenios,
        private readonly FimDoConvenio $fim,
        private readonly SincronizarVinculosDeConvenioUseCase $vinculosDeConvenio,
        private readonly EntityManagerInterface $em,
        private readonly Connection $conexao,
        private readonly ClockInterface $relogio,
        private readonly LoggerInterface $logger,
    ) {
    }

    /** @return array{acao: string, avisados: int} */
    public function doConvenio(int $id, DesligarConvenioInput $input): array
    {
        $dia = $input->ligado ? null : FimDoConvenio::dia($input->encerraEm);
        [$resultado, $tenants] = $this->naTransacao([$id], fn (array $convenios): array => $this->aplicar($convenios[0], $dia, $input));
        $this->fecharVinculos($tenants);

        return $resultado;
    }

    /** @return array{acao: string, avisados: int} */
    public function daInstituicao(string $instituicaoChave, DesligarConvenioInput $input): array
    {
        $dia = FimDoConvenio::dia($input->encerraEm);
        $ids = array_map(static fn (Convenio $c): int => (int) $c->getId(), array_filter($this->convenios->daInstituicao($instituicaoChave), static fn (Convenio $c): bool => $c->isAtivo()));
        if ($ids === []) {
            throw new ConvenioRecusado('Nenhum convênio ligado nesta instituição.');
        }
        [$resultado, $tenants] = $this->naTransacao(array_values($ids), function (array $convenios) use ($dia, $input): array {
            $agora = $this->agora();
            foreach ($convenios as $convenio) {
                $this->fim->exigirDataPermitida($convenio, $dia, $agora);
            }
            $acao = self::FIM_MARCADO;
            $avisados = 0;
            $tenants = [];
            foreach ($convenios as $convenio) {
                [$parcial, $doConvenio] = $this->aplicar($convenio, $dia, $input);
                [$acao, $avisados, $tenants] = [$parcial['acao'], $avisados + $parcial['avisados'], array_merge($tenants, $doConvenio)];
            }

            return [['acao' => $acao, 'avisados' => $avisados], $tenants];
        });
        $this->fecharVinculos($tenants);

        return $resultado;
    }

    /**
     * @return array{0: array{acao: string, avisados: int}, 1: list<int>} o resultado e os escritórios a sincronizar
     */
    private function aplicar(Convenio $convenio, ?\DateTimeImmutable $dia, DesligarConvenioInput $input): array
    {
        $agora = $this->agora();
        if ($dia === null) {
            $ligado = !$convenio->isAtivo();
            $avisados = $this->fim->cancelarOuLigar($convenio, $input->ator, $agora);

            return [['acao' => $ligado ? self::LIGADO : self::FIM_CANCELADO, 'avisados' => $avisados], []];
        }
        $marcado = $this->fim->marcar($convenio, $dia, $input->motivo, $input->ator, $agora);

        return [
            ['acao' => $marcado['desligouAgora'] ? self::DESLIGOU_AGORA : self::FIM_MARCADO, 'avisados' => $marcado['avisados']],
            $marcado['desligouAgora'] ? $marcado['tenants'] : [],
        ];
    }

    /**
     * Trava os convênios (na ordem do id, contra impasse), relê do banco e aplica.
     *
     * @param list<int>                                                             $ids
     * @param callable(list<Convenio>): array{0: array{acao: string, avisados: int}, 1: list<int>} $acao
     *
     * @return array{0: array{acao: string, avisados: int}, 1: list<int>}
     */
    private function naTransacao(array $ids, callable $acao): array
    {
        sort($ids);
        try {
            return $this->conexao->transactional(function () use ($ids, $acao): array {
                $convenios = [];
                foreach ($ids as $id) {
                    $this->conexao->executeQuery("SELECT pg_advisory_xact_lock(hashtextextended('convenio:' || ?, 0))", [$id]);
                    $convenio = $this->convenios->find($id) ?? throw new ConvenioRecusado('Convênio não encontrado.');
                    $this->em->refresh($convenio);
                    $convenios[] = $convenio;
                }

                return $acao($convenios);
            });
        } catch (ConvenioRecusado $e) {
            throw $e;
        } catch (\Throwable $e) {
            $this->logger->error('Convênios: a mudança foi desfeita porque os avisos não entraram na fila.', ['convenios' => $ids, 'erro' => $e::class]);

            throw new AvisoDeConvenioNaoEnfileirado('Avisos não enfileirados.', 0, $e);
        }
    }

    /**
     * Desligou na hora: os vínculos fecham já (melhor esforço, depois do commit; a tarefa diária corrige o que falhar).
     *
     * @param list<int> $tenants
     */
    private function fecharVinculos(array $tenants): void
    {
        if ($tenants === []) {
            return;
        }
        try {
            $this->vinculosDeConvenio->dosEscritorios($tenants);
        } catch (\Throwable $e) {
            $this->logger->error('Convênios: os vínculos não fecharam junto com o convênio; a tarefa diária confere.', ['erro' => $e::class]);
        }
    }

    private function agora(): \DateTimeImmutable
    {
        return \DateTimeImmutable::createFromInterface($this->relogio->now());
    }
}
