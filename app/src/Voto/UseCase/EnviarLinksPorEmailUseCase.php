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
 * "Enviar links por e-mail" (spec voto-assembleia-condominio, seção 1.6): um lote de até 25 unidades que têm e-mail e
 * ainda não receberam o link atual, enviado na hora (síncrono). Cada envio aceito marca a unidade; a falha do
 * transporte só conta e a unidade fica para o próximo lote. Devolve o "faltam N".
 *
 * Com `unidadeId`, é o "Reenviar" daquela unidade (mesmo que já tenha recebido). Só depois de publicada.
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
        $lote = null === $unidadeId ? $this->unidades->listarParaEnvioDoLink($assembleia, self::LOTE) : [$this->unidadeComEmail($assembleia, $unidadeId)];
        $enviados = 0;
        foreach ($lote as $unidade) {
            $enviados += $this->enviar($unidade) ? 1 : 0;
        }
        $this->assembleias->salvar($assembleia, flush: true);

        return new ResultadoDoEnvioDeLinks($enviados, \count($lote) - $enviados, $this->unidades->contarParaEnvioDoLink($assembleia));
    }

    private function enviar(UnidadeDaAssembleia $unidade): bool
    {
        try {
            $this->mailer->enviar($unidade);
        } catch (\RuntimeException) {
            return false;
        }
        $unidade->registrarEnvioDoLink();
        $this->unidades->salvar($unidade);

        return true;
    }

    private function unidadeComEmail(Assembleia $assembleia, int $unidadeId): UnidadeDaAssembleia
    {
        $unidade = $this->unidades->buscarDaAssembleia($unidadeId, $assembleia) ?? throw new AssembleiaNaoEncontradaException();
        if (null === $unidade->getEmail() || '' === $unidade->getEmail()) {
            throw new OperacaoInvalidaNaAssembleiaException('Esta unidade não tem e-mail.');
        }

        return $unidade;
    }
}
