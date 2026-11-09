<?php

declare(strict_types=1);

namespace App\Voto\UseCase;

use App\Entity\Tenant\Tenant;
use App\Voto\DTO\ResultadoDoEnvioDeLinks;
use App\Voto\Entity\Assembleia;
use App\Voto\Entity\UnidadeDaAssembleia;
use App\Voto\Enum\EstadoDaAssembleia;
use App\Voto\Exception\AssembleiaNaoEncontradaException;
use App\Voto\Exception\OperacaoInvalidaNaAssembleiaException;
use App\Voto\Repository\AssembleiaRepository;
use App\Voto\Repository\UnidadeDaAssembleiaRepository;
use App\Voto\Service\LinkDaUnidadeMailer;

/**
 * "Enviar links por e-mail" (spec voto-assembleia-condominio, seção 1.6): um lote de até 25 unidades aptas que têm
 * e-mail e ainda não receberam o link atual, enviado na hora (síncrono). Cada envio aceito marca a unidade e é gravado
 * na hora (se algo falhar depois, quem já recebeu não recebe de novo); a falha do transporte ou o endereço recusado
 * pela RFC só conta, e a unidade fica para o próximo lote. Devolve o "faltam N".
 *
 * Com `unidadeId`, é o "Reenviar" daquela unidade apta (mesmo que já tenha recebido). Só depois de publicada.
 */
final class EnviarLinksPorEmailUseCase
{
    public const LOTE = 25;

    public function __construct(
        private readonly AssembleiaRepository $assembleias,
        private readonly UnidadeDaAssembleiaRepository $unidades,
        private readonly LinkDaUnidadeMailer $mailer,
    ) {
    }

    public function executar(Tenant $tenant, int $assembleiaId, ?int $unidadeId = null): ResultadoDoEnvioDeLinks
    {
        $assembleia = $this->assembleias->buscarDoEscritorio($assembleiaId, $tenant)
            ?? throw new AssembleiaNaoEncontradaException();
        if (EstadoDaAssembleia::Publicada !== $assembleia->getEstado()) {
            throw new OperacaoInvalidaNaAssembleiaException('Publique a assembleia antes de enviar os links.');
        }
        $lote = null === $unidadeId ? $this->unidades->listarParaEnvioDoLink($assembleia, self::LOTE) : [$this->unidadeParaReenvio($assembleia, $unidadeId)];
        $enviados = 0;
        foreach ($lote as $unidade) {
            $enviados += $this->enviar($unidade) ? 1 : 0;
        }

        return new ResultadoDoEnvioDeLinks($enviados, \count($lote) - $enviados, $this->unidades->contarParaEnvioDoLink($assembleia));
    }

    private function enviar(UnidadeDaAssembleia $unidade): bool
    {
        try {
            $this->mailer->enviar($unidade);
        } catch (\RuntimeException|\InvalidArgumentException) {
            // RuntimeException: o transporte recusou. InvalidArgumentException: endereço fora da RFC (Mime\Address).
            return false;
        }
        $unidade->registrarEnvioDoLink();
        $this->unidades->salvar($unidade, flush: true);

        return true;
    }

    private function unidadeParaReenvio(Assembleia $assembleia, int $unidadeId): UnidadeDaAssembleia
    {
        $unidade = $this->unidades->buscarDaAssembleia($unidadeId, $assembleia) ?? throw new AssembleiaNaoEncontradaException();
        if (!$unidade->isApta()) {
            throw new OperacaoInvalidaNaAssembleiaException('Unidade inapta não vota e não recebe o link.');
        }
        if (null === $unidade->getEmail() || '' === $unidade->getEmail()) {
            throw new OperacaoInvalidaNaAssembleiaException('Esta unidade não tem e-mail.');
        }

        return $unidade;
    }
}
