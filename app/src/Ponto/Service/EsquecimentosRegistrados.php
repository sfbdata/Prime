<?php

declare(strict_types=1);

namespace App\Ponto\Service;

use App\Entity\Auth\User;
use App\Ponto\Repository\JustificativaPontoRepository;

/**
 * Os esquecimentos de batida já pedidos (pendente) ou deferidos (abonado) da competência, por "dia|tipo": a condição 8
 * do "+" da folha (`ponto-atalho-esquecimento-registro.md`), para não gerar fila duplicada no gestor. 'abonado' entra
 * porque a aprovação em lote não cria o RegistroPonto: a célula segue vazia com a justificativa já deferida. Um lugar
 * só: a folha da tela do ponto e o "Regularizar ponto de ontem" do atalho do cabeçalho.
 */
final class EsquecimentosRegistrados
{
    public function __construct(
        private readonly JustificativaPontoRepository $justificativas,
    ) {
    }

    /** @return array<string, true> chave "AAAA-MM-DD|tipo" */
    public function daCompetencia(User $user, int $ano, int $mes): array
    {
        return self::dasJustificativas($this->justificativas->findByUserAndCompetencia($user, $ano, $mes));
    }

    /**
     * @param iterable<\App\Ponto\Entity\JustificativaPonto> $justificativas
     *
     * @return array<string, true>
     */
    public static function dasJustificativas(iterable $justificativas): array
    {
        $registrados = [];
        foreach ($justificativas as $justificativa) {
            if ($justificativa->getTipo() !== 'esquecimento_registro' || !in_array($justificativa->getStatus(), ['pendente', 'abonado'], true)) {
                continue;
            }
            $tipo = $justificativa->getTipoRegistroEsquecido();
            $data = $justificativa->getData();
            if ($tipo === null || $data === null) {
                continue;
            }
            $registrados[$data->format('Y-m-d') . '|' . $tipo] = true;
        }

        return $registrados;
    }
}
