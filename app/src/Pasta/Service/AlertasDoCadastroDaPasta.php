<?php

declare(strict_types=1);

namespace App\Pasta\Service;

use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Pasta\DTO\AlertaDaPasta;
use App\Pasta\Entity\Pasta;
use App\Pasta\Entity\PastaReu;
use App\Pasta\Repository\PastaRepository;
use App\Pasta\Repository\PastaReuRepository;

/**
 * Alertas da Controladoria que dependem do Cadastro da pasta (fatia B da spec central-documentos-controladoria):
 * mesmo contrato ou unidade em outra pasta, titular do extrato diferente do réu, prescrição próxima e cadastro do réu
 * incompleto. Unidade e titular valem o que a advogada digitou ou, na falta, o que a Cobrança sabe.
 */
final class AlertasDoCadastroDaPasta
{
    public function __construct(
        private readonly PastaRepository $pastas,
        private readonly PastaReuRepository $reus,
        private readonly DadosDaCobrancaNaPasta $cobranca,
        private readonly AcessoAPasta $acesso,
    ) {
    }

    /** @return list<AlertaDaPasta> */
    public function montar(Pasta $pasta, Tenant $tenant, User $pessoa, \DateTimeImmutable $hoje): array
    {
        $reu = $this->reus->daPasta($pasta, $tenant)[0] ?? null;
        $daCobranca = $this->cobranca->da($pasta, $tenant);
        $ocultas = $this->acesso->idsDasPastasOcultas($pessoa, $tenant);

        return array_values(array_filter([
            $this->repetida('contrato', ['no mesmo contrato', 'usa o mesmo contrato', 'usam o mesmo contrato'], AlertaDaPasta::ALTA, 'Confira se não é duplicidade e una ou arquive uma delas.', $pasta, $tenant, $pasta->getContrato(), $ocultas),
            $this->repetida('unidade', ['na mesma unidade', 'é da mesma unidade', 'são da mesma unidade'], AlertaDaPasta::MEDIA, 'Confira se tratam do mesmo débito ou período.', $pasta, $tenant, $pasta->getUnidade() ?? $daCobranca['unidade'], $ocultas),
            $this->titularDiferente($pasta->getTitularExtrato() ?? $daCobranca['titular'], $reu),
            $this->prescricao($pasta, $hoje),
            $this->cadastroIncompleto($reu),
        ]));
    }

    /**
     * @param array{0: string, 1: string, 2: string} $frases título, verbo no singular e verbo no plural
     * @param list<int>                              $ocultas
     */
    private function repetida(string $campo, array $frases, string $nivel, string $sugestao, Pasta $pasta, Tenant $tenant, ?string $valor, array $ocultas): ?AlertaDaPasta
    {
        if ($valor === null || $valor === '') {
            return null;
        }
        $outras = $this->pastas->outrasComOMesmo($campo, $pasta, $tenant, $valor, $ocultas);
        if ($outras === []) {
            return null;
        }
        $nups = array_map(static fn (array $o): string => $o['nup'], $outras);
        $quem = count($nups) === 1 ? 'A Pasta ' . $nups[0] . ' ' . $frases[1] : 'As Pastas ' . implode(', ', $nups) . ' ' . $frases[2];

        return new AlertaDaPasta($nivel, 'Duas pastas ' . $frases[0], $quem . ' (' . $valor . ').', $sugestao, $campo);
    }

    private function titularDiferente(?string $titular, ?PastaReu $reu): ?AlertaDaPasta
    {
        if ($titular === null || $reu === null || self::normalizar($titular) === self::normalizar($reu->getNome())) {
            return null;
        }

        return new AlertaDaPasta(AlertaDaPasta::ALTA, 'Titular do extrato diferente do réu', 'Extrato em nome de "' . $titular . '", réu cadastrado "' . $reu->getNome() . '".', 'Confirme a legitimidade passiva antes de prosseguir.', 'titular');
    }

    private function prescricao(Pasta $pasta, \DateTimeImmutable $hoje): ?AlertaDaPasta
    {
        $limite = $pasta->getPrescricaoEm();
        if ($limite === null) {
            return null;
        }
        $dias = (int) $hoje->setTime(0, 0)->diff($limite->setTime(0, 0))->format('%r%a');
        if ($dias > AlertasDaPasta::PRESCRICAO_AVISA_ATE_DIAS) {
            return null;
        }
        $quando = $dias < 0 ? ' (' . -$dias . ' dias atrás)' : ' (faltam ' . $dias . ' dias)';

        return new AlertaDaPasta(
            $dias <= AlertasDaPasta::PRESCRICAO_ALTA_ATE_DIAS ? AlertaDaPasta::ALTA : AlertaDaPasta::MEDIA,
            $dias < 0 ? 'Prescrição possivelmente ocorrida' : 'Prescrição próxima',
            'Data-limite estimada: ' . $limite->format('d/m/Y') . $quando . '.',
            'Revisão jurídica: confira o marco inicial e as causas de interrupção ou suspensão.',
            'prescricao',
        );
    }

    private function cadastroIncompleto(?PastaReu $reu): ?AlertaDaPasta
    {
        $falta = [];
        if ($reu?->getDocumento() === null) {
            $falta[] = 'CPF';
        }
        if ($reu?->getQualificacao() === null) {
            $falta[] = 'qualificação';
        }
        if ($falta === []) {
            return null;
        }

        return new AlertaDaPasta(AlertaDaPasta::BAIXA, 'Cadastro do réu incompleto', 'Falta: ' . implode(' e ', $falta) . '.', 'Complete em "Cadastro da pasta" quando tiver os dados.', 'cadastro');
    }

    private static function normalizar(string $texto): string
    {
        $semAcento = transliterator_transliterate('NFD; [:Nonspacing Mark:] Remove; NFC', $texto) ?: $texto;

        return trim((string) preg_replace('/\s+/u', ' ', mb_strtolower($semAcento)));
    }
}
